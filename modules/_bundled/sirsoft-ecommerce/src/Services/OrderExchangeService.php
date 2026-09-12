<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\DTO\ExchangeAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductAdditionalOptionValueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyClaimAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Rules\ExchangeRedeliveryCountryUnchanged;
use Modules\Sirsoft\Ecommerce\Services\Concerns\AppliesRefundBankAccount;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ResolvesAdditionalOptions;

/**
 * 교환 클레임 서비스
 *
 * 교환 신청서(`ecommerce_order_exchanges`)의 신청·철회·상태 전이·회수/재발송 송장·금액 수정·
 * 완료 처리를 담당한다. 반품(`OrderReturnService`)과 같은 뼈대 위에 교환에만 있는 네 축이 얹힌다:
 *
 *  1. **옵션 변경과 차액** — 신청 상품마다 같은 상품의 다른 옵션(또는 동일 옵션 재발송)을 고르고,
 *     `(신규 단가 − 원 단가) × 수량` 을 부호 그대로 기록한다(양수 = 추가 청구, 음수 = 차액 환불 D4).
 *  2. **신규 옵션 재고** — 승인 시점에 재발송할 옵션의 재고를 차감하고(D7), 재고 부족은 승인
 *     전이를 막는다. 원 옵션 재고 복구는 완료 처리에서 운영자가 건별로 고른다(D26).
 *  3. **재발송 라이프사이클** — 회수 3단계 뒤에 재발송 3단계가 이어지며, 재발송이 시작되는
 *     순간 원 주문옵션을 승계하는 **후속 주문옵션**(`source_type=exchange`)을 만든다.
 *  4. **재발송지 변경**(D14) — 신청 시 재발송지를 바꿀 수 있고 그 주소로 도서산간을 재판정한다.
 *     국가 변경은 막는다(교환 해외배송 미지원).
 *
 * ## 코스 상태는 신청 접수 시점에 들어간다
 *
 * 원 주문옵션은 신청이 접수되는 순간 `EXCHANGE_IN_PROGRESS` 로 전이한다(부분 수량이면 분할).
 * 반려·철회 시 되돌리는 근거는 `OrderExchangeOption::original_option_status` 이며 복귀는
 * **상품옵션 단위**다. 전이 시 `restoreStockOnCancel: false` 와 `mergeSiblings: false` 를 명시하는
 * 이유는 반품 서비스 docblock 과 같다 — 접수 순간의 자동 재고 복원과, 신청 이력을 cascade 로
 * 지우는 형제 병합을 막는다.
 *
 * ## 후속 주문옵션은 금액을 승계하고 정체만 바꾼다
 *
 * 재발송으로 생성되는 주문옵션은 원 행의 금액 컬럼(단가·소계·할인·결제액·적립·세액)과 스냅샷
 * 가격을 **그대로 복사**하고 상품옵션 정체(옵션 ID·옵션명·SKU·무게)만 바꾼다. 옵션 차액은 주문
 * 금액이 아니라 클레임 정산(추가결제 `ecommerce_claim_payments` / 교환 발 환불신청서)에 속하기
 * 때문이다. 후속 행에 신규 단가를 실으면 주문의 재계산 기준선(`total_paid_amount`)과 잔여 합계가
 * 차액만큼 어긋나, 교환 뒤 다른 상품을 부분취소할 때 환불액이 차액만큼 틀어진다.
 * 신규 옵션의 실제 단가는 교환 신청 상품 행(`new_unit_price` / `new_option_snapshot`)이 갖는다.
 *
 * ## 주문 상태는 건드리지 않는다
 *
 * 코스 4종은 주문 상태 동기화 대상이 아니다 — `syncParentOrderStatus()` 를 부르지 않는다
 * (반품 서비스와 같은 이유: 전량 교환 신청에서 부르면 주문이 취소로 전이한다).
 *
 * ## 완료 처리와 환불신청서 접수는 분리돼 있다 (D26)
 *
 * `completeExchange()` 는 `ClaimCompletionOptions` 를 필수로 받는다. 환불신청서는 저가 교환(차액
 * 환불분이 있는 건)에서만 접수할 수 있으며, 금액은 신청 시점에 동결한 차액(`price_difference`)에서
 * 온다 — 완료 시점에는 원 옵션이 `EXCHANGED` 로 닫히고 후속 옵션이 생겨 있어 `ExchangeAdjustment`
 * 로 다시 계산하면 차액이 두 번 반영된다.
 *
 * ## 환불신청서는 완료 라운드마다 접수할 수 있다
 *
 * 한 신청서의 상품이 여러 라운드로 나뉘어 완료되면, 각 라운드는 **그때까지 완료됐지만 아직
 * 살아 있는 환불신청서에 담기지 않은** 저가 상품을 모아 접수한다(신청서당 N건). 담긴 상품은
 * `refund_source_type=exchange` 인 환불신청서의 상품 행(`order_option_id`)으로 되짚고, 반려·철회된
 * 환불신청서의 상품은 다시 접수할 수 있다. '환불금에서 차감' 방식의 왕복 배송비는 신청서 전체에서
 * 한 번만 뺀다 — 앞선 접수가 이미 뺀 몫은 제외한다. 취소 가능 금액(D19 ③)의 교환 선점액도 같은
 * 축으로 이미 접수된 금액을 뺀다(`CancellableAmountCalculator`).
 */
class OrderExchangeService
{
    use AppliesRefundBankAccount;
    use ResolvesAdditionalOptions;

    /**
     * @param  OrderAdjustmentService  $adjustmentService  금액 재계산 서비스 (미리보기·청구 방식 검증)
     * @param  ClaimShippingFeeService  $claimShippingFeeService  클레임 배송비 산정 서비스
     * @param  ClaimQuantityGuard  $claimQuantityGuard  클레임 수량 잠금 가드 (D15)
     * @param  OrderOptionService  $orderOptionService  주문 옵션 서비스 (코스 상태 전이·수량 분할)
     * @param  RefundExecutionService  $refundExecutionService  환불 실행 서비스 (환불수단 판정)
     * @param  UserMileageService  $mileageService  마일리지 서비스 (적립 회수)
     * @param  StockService  $stockService  재고 서비스
     * @param  SequenceService  $sequenceService  채번 서비스
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     * @param  CurrencyConversionService  $currencyService  다통화 변환 서비스
     * @param  OrderRepositoryInterface  $orderRepository  주문 Repository
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 Repository
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 신청서 Repository
     * @param  OrderExchangeOptionRepositoryInterface  $exchangeOptionRepository  교환 신청 상품 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불 신청서 Repository
     * @param  OrderRefundOptionRepositoryInterface  $refundOptionRepository  환불 상세 Repository
     * @param  ClaimReasonRepositoryInterface  $claimReasonRepository  클레임 사유 Repository
     * @param  ShippingPolicyClaimAddressRepositoryInterface  $claimAddressRepository  배송정책 회수지 Repository
     * @param  ProductOptionRepositoryInterface  $productOptionRepository  상품 옵션 Repository
     * @param  OrderShippingRepositoryInterface  $orderShippingRepository  주문 배송 Repository (후속 옵션 배송 행 복제)
     * @param  ProductAdditionalOptionValueRepositoryInterface  $additionalOptionValueRepository  추가옵션 선택지 Repository (교환 추가옵션 재조회·동결)
     */
    public function __construct(
        protected OrderAdjustmentService $adjustmentService,
        protected ClaimShippingFeeService $claimShippingFeeService,
        protected ClaimQuantityGuard $claimQuantityGuard,
        protected OrderOptionService $orderOptionService,
        protected RefundExecutionService $refundExecutionService,
        protected UserMileageService $mileageService,
        protected StockService $stockService,
        protected SequenceService $sequenceService,
        protected EcommerceSettingsService $settingsService,
        protected CurrencyConversionService $currencyService,
        protected OrderRepositoryInterface $orderRepository,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected OrderExchangeRepositoryInterface $exchangeRepository,
        protected OrderExchangeOptionRepositoryInterface $exchangeOptionRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderRefundOptionRepositoryInterface $refundOptionRepository,
        protected ClaimReasonRepositoryInterface $claimReasonRepository,
        protected ShippingPolicyClaimAddressRepositoryInterface $claimAddressRepository,
        protected ProductOptionRepositoryInterface $productOptionRepository,
        protected OrderShippingRepositoryInterface $orderShippingRepository,
        protected ProductAdditionalOptionValueRepositoryInterface $additionalOptionValueRepository,
    ) {}

    // ───────────────────────────────────────────────
    // 신청 / 미리보기
    // ───────────────────────────────────────────────

    /**
     * 교환 신청 결과를 미리 계산합니다. (DB 변경 없음)
     *
     * 신청 화면이 옵션·수량·사유·재발송지를 바꿀 때마다 부르는 경로이며, 실제 신청
     * (`requestExchange`)이 기록할 값과 같은 산식을 쓴다.
     *
     * 신청 자격(상태·수량·잠금·기간) 가드는 신청과 같은 규칙으로 적용한다(`resolvePreviewItems()`) —
     * 고객이 신청할 수 없는 조합의 금액을 본 뒤 [신청하기] 에서야 거절되지 않게 한다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $items  신청 대상 [{order_option_id, quantity, new_product_option_id?, custom_options?}]
     * @param  string  $reasonCode  교환 사유 코드
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식 (청구 0원이면 null)
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  array|null  $redeliveryAddress  재발송지 (D14) [{country_code?, zipcode?, ...}]
     * @param  int|null  $excludeExchangeId  수량 잠금 집계에서 뺄 교환 신청서 ID (수정 화면의 미리보기)
     * @return array{shipping_fee: ClaimShippingFee, adjustment: AdjustmentResult, items: array, price_difference: float, additional_product_amount: float, refund_product_amount: float, total_charge_amount: float, mc_total_charge_amount: array|null, fault_type: ClaimReasonFaultTypeEnum, charge_method: ClaimChargeMethodEnum|null, requires_payment: bool} 미리보기 결과
     *
     * @throws OrderClaimException 사유 부재 / 대상 부재 / 신규 옵션 무효
     */
    public function previewExchange(
        Order $order,
        array $items,
        string $reasonCode,
        ?ClaimChargeMethodEnum $chargeMethod = null,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
        ?array $redeliveryAddress = null,
        ?int $excludeExchangeId = null,
    ): array {
        $reason = $this->resolveReason($reasonCode);
        $plans = $this->planItems($this->resolvePreviewItems($order, $items, $excludeExchangeId), $items);
        $claimItems = $this->toClaimItemsFromPlans($plans);

        $shippingFee = $this->claimShippingFeeService->calculate(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $claimItems,
            $reason->fault_type,
            $this->toFeeLegAddress($redeliveryAddress),
        );

        $priceDifference = $this->sumPriceDifference($plans);
        [$additional, $refund] = $this->splitPriceDifference($priceDifference);
        $totalCharge = $shippingFee->fee + $additional;

        $adjustment = ExchangeAdjustment::fromShippingFee(
            $claimItems,
            $shippingFee,
            (int) round($priceDifference),
            $chargeMethod,
            $this->buildSubstitutionsFromPlans($plans),
        );

        $result = $this->adjustmentService->preview($order, $adjustment, $refundPriority);

        return [
            'shipping_fee' => $shippingFee,
            'adjustment' => $result,
            'items' => $this->describePlans($plans),
            'price_difference' => $priceDifference,
            'additional_product_amount' => $additional,
            'refund_product_amount' => $refund,
            // 차액 3줄의 보조통화 — 형제 금액 줄(`mc_total_charge_amount`·`shipping_fee.mc_fee`)과 같은
            // 재료·같은 환율이다. 이 값이 없으면 요약과 목록의 '옵션 차액 / 추가·환불 상품금액' 만
            // 기준 통화 한 줄로 남아, 바로 위아래 줄과 표기 규약이 갈린다(S17 트랙 A F-1).
            // 접수 뒤에는 같은 값이 `order_exchange_options.mc_price_difference` 로 박제되고 상세가
            // 그것을 병기하므로, 예고 경로만 비어 있으면 같은 금액이 화면마다 다르게 보인다.
            'mc_price_difference' => $this->toMultiCurrency($priceDifference, $order->currency_snapshot ?? []),
            'mc_additional_product_amount' => $this->toMultiCurrency($additional, $order->currency_snapshot ?? []),
            'mc_refund_product_amount' => $this->toMultiCurrency($refund, $order->currency_snapshot ?? []),
            'total_charge_amount' => $totalCharge,
            // 접수 시 신청서에 박제되는 값(`mc_total_charge_amount`)과 같은 재료·같은 환율로 낸다.
            // 미리보기에 이 값이 없으면 보조통화를 쓰는 구매자는 신청 화면에서 자기 통화의 청구액을
            // 볼 수 없고, 결제 단계에 가서야 실제 금액을 알게 된다.
            'mc_total_charge_amount' => $this->toMultiCurrency($totalCharge, $order->currency_snapshot ?? []),
            'fault_type' => $reason->fault_type,
            'charge_method' => $chargeMethod,
            'requires_payment' => $totalCharge > 0 && $chargeMethod?->requiresPayment() === true,
        ];
    }

    /**
     * 교환을 신청합니다.
     *
     * 주문을 배타적으로 잠근 뒤 수량 잠금(D15)을 검증하고, 신청서·신청 상품·코스 상태 전이를
     * 한 트랜잭션에서 기록한다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $payload  신청 페이로드
     *                          - items: [{order_option_id, quantity, new_product_option_id?, custom_options?}]
     *                          - reason_code: 사유 코드
     *                          - reason_detail: 상세 사유 (선택)
     *                          - charge_method: ClaimChargeMethodEnum|string|null
     *                          - requested_by: 신청자 ID (비회원이면 null)
     *                          - refund_priority: RefundPriorityEnum (선택)
     *                          - redelivery_address: 재발송지 (선택, D14) {recipient_name, phone, zipcode, address1, address2, country_code, changed_by_user}
     * @return OrderExchange 생성된 교환 신청서
     *
     * @throws OrderClaimException 신청 자격·수량·사유·청구 방식·신규 옵션·재발송지 위반
     */
    public function requestExchange(Order $order, array $payload): OrderExchange
    {
        $reason = $this->resolveReason((string) ($payload['reason_code'] ?? ''));
        $chargeMethod = $this->normalizeChargeMethod($payload['charge_method'] ?? null);
        $refundPriority = $payload['refund_priority'] ?? RefundPriorityEnum::PG_FIRST;
        $requestedBy = $payload['requested_by'] ?? null;
        $items = $payload['items'] ?? [];

        $exchange = DB::transaction(function () use ($order, $payload, $items, $reason, $chargeMethod, $refundPriority, $requestedBy) {
            // 잠금은 잔여 수량을 읽기 전에 잡는다 — 읽고 나서 잠그면 이미 두 요청이 같은 값을 봤다.
            $lockedOrder = $this->orderRepository->findForUpdate($order->id) ?? $order;
            $lockedOrder->load(['options', 'shippings', 'payment', 'shippingAddress']);

            $resolved = $this->claimQuantityGuard->resolveClaimItems(
                $lockedOrder,
                $items,
                ClaimTypeEnum::EXCHANGE,
                $this->requestableStatusValues(),
            );

            $this->assertWithinRequestPeriod($resolved);

            $redelivery = $this->buildRedeliveryAddressSnapshot($lockedOrder, $payload['redelivery_address'] ?? null);
            $this->assertRedeliveryCountryUnchanged($lockedOrder, $redelivery);

            $plans = $this->planItems($resolved, $items);
            $this->assertTargetStockAvailable($plans);

            $claimItems = $this->toClaimItemsFromPlans($plans);

            $shippingFee = $this->claimShippingFeeService->calculate(
                $lockedOrder,
                ClaimTypeEnum::EXCHANGE,
                $claimItems,
                $reason->fault_type,
                $this->toFeeLegAddress($redelivery),
            );

            $priceDifference = $this->sumPriceDifference($plans);
            [$additional, $refund] = $this->splitPriceDifference($priceDifference);
            $totalCharge = $shippingFee->fee + $additional;

            $this->assertChargeMethodUsable(
                $lockedOrder,
                $claimItems,
                $shippingFee,
                $priceDifference,
                $totalCharge,
                $chargeMethod,
                $refundPriority,
            );

            $currencySnapshot = $lockedOrder->currency_snapshot ?? [];

            // 접수 시점 주문금액 비교 스냅샷(전/후) — 미리보기와 같은 재료(대상·배송비·차액·청구 방식·치환)로
            // 한 번 더 계산해 동결한다. `assertChargeMethodUsable()` 의 baseline 은 치환 없이 계산한 뒤
            // 버려지므로 여기서 다시 낸다. 상세가 나중에 재계산하면 이후 클레임·쿠폰 변경이 섞인다.
            $frozen = $this->adjustmentService->preview(
                $lockedOrder,
                ExchangeAdjustment::fromShippingFee(
                    $claimItems,
                    $shippingFee,
                    (int) round($priceDifference),
                    $chargeMethod,
                    $this->buildSubstitutionsFromPlans($plans),
                ),
                $refundPriority,
            );

            $created = $this->exchangeRepository->create([
                'order_id' => $lockedOrder->id,
                'exchange_number' => $this->sequenceService->generateCode(SequenceType::EXCHANGE),
                'exchange_status' => ExchangeStatusEnum::REQUESTED,
                'reason_code' => $reason->code,
                'reason_detail' => $payload['reason_detail'] ?? null,
                'fault_type' => $reason->fault_type,
                'charge_method' => $totalCharge > 0 ? $chargeMethod : null,
                'shipping_fee_mode' => $shippingFee->mode,
                'claim_shipping_fee' => $shippingFee->fee,
                'mc_claim_shipping_fee' => $shippingFee->mcFee ?: null,
                'shipping_fee_snapshot' => $shippingFee->toArray(),
                'additional_product_amount' => $additional,
                'refund_product_amount' => $refund,
                'total_charge_amount' => $totalCharge,
                'mc_total_charge_amount' => $this->toMultiCurrency($totalCharge, $currencySnapshot),
                'currency_code' => $this->resolveBaseCurrency($lockedOrder),
                'items_snapshot' => $this->buildItemsSnapshot($plans),
                'return_address_snapshot' => $this->resolveReturnAddressSnapshot($lockedOrder, $resolved),
                'redelivery_address_snapshot' => $redelivery,
                'requested_by' => $requestedBy,
            ] + $this->frozenCalculationSnapshots($frozen) + $this->refundBankColumns($payload['refund_bank'] ?? null));

            foreach ($plans as $plan) {
                $this->createExchangeOption($created, $lockedOrder, $plan, $currencySnapshot);
            }

            return $created->fresh(['exchangeOptions']);
        });

        HookManager::doAction('sirsoft-ecommerce.claim.exchange.requested', $exchange, $order->fresh());

        // 청구액 0원 자동 승인 (§4-2 전이표 자동 열). 접수 훅을 먼저 발화한 뒤 전이하므로
        // 알림·로그 리스너는 '신청 → 승인' 두 사건을 순서대로 받는다.
        if ($this->shouldAutoApproveZeroCharge($exchange)) {
            try {
                return $this->transition($exchange, ExchangeStatusEnum::APPROVED);
            } catch (OrderClaimException $e) {
                // 접수는 이미 커밋됐고 접수 훅도 나갔다. 승인 시점의 재고 차감이 실패하면(접수와 거의
                // 동시에 다른 주문이 마지막 재고를 가져간 경우) 자동 승인만 건너뛰고 '교환신청' 으로
                // 접수를 마친다 — 예외를 그대로 올리면 신청서는 남아 있는데 고객은 실패 응답을 받는다.
                // 승인은 운영자가 재고를 확보한 뒤 수동으로 한다.
                Log::info('[claim.exchange] 청구 0원 자동 승인 건너뜀 — 신청 상태로 접수', [
                    'exchange_number' => $exchange->exchange_number,
                    'reason' => $e->getMessageKey(),
                ]);

                return $exchange->fresh(['exchangeOptions']);
            }
        }

        return $exchange;
    }

    /**
     * 청구액 0원 신청서를 자동 승인해야 하는지 판정합니다.
     *
     * @param  OrderExchange  $exchange  방금 접수된 교환 신청서
     * @return bool 자동 승인 대상 여부
     */
    protected function shouldAutoApproveZeroCharge(OrderExchange $exchange): bool
    {
        if ((float) $exchange->total_charge_amount > 0) {
            return false;
        }

        return (bool) $this->settingsService->getSetting('claim.auto_approve_zero_charge', false);
    }

    // ───────────────────────────────────────────────
    // 철회 / 상태 전이
    // ───────────────────────────────────────────────

    /**
     * 신청자가 교환 신청을 철회합니다.
     *
     * 철회자 ID 를 받지 않는다 — 행위자 기록은 활동 로그 소관이며(반품 서비스와 같은 판정),
     * 이 메서드가 발화하는 `claim.exchange.withdrawn`·`status_changed` 훅이 그 입력이다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @return OrderExchange 철회된 신청서
     *
     * @throws OrderClaimException 철회 불가 상태
     */
    public function withdraw(OrderExchange $exchange): OrderExchange
    {
        if (! $exchange->exchange_status->isWithdrawable()) {
            throw OrderClaimException::notWithdrawable();
        }

        return $this->transition($exchange, ExchangeStatusEnum::WITHDRAWN);
    }

    /**
     * 교환 신청서 상태를 전이합니다.
     *
     * 완료(COMPLETED)는 이 경로로 갈 수 없다 — 원 옵션 재고 복구 여부와 환불신청서 접수 여부를
     * 운영자가 고르는 절차(D26)이므로 `completeExchange()` 만이 진입점이다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보 (processed_by / reject_reason / admin_memo)
     * @return OrderExchange 전이된 신청서
     *
     * @throws OrderClaimException 전이 불가 / 승인 시 신규 옵션 재고 부족
     */
    public function transition(OrderExchange $exchange, ExchangeStatusEnum $target, array $context = []): OrderExchange
    {
        if ($target === ExchangeStatusEnum::COMPLETED) {
            throw OrderClaimException::completionRequiresCompletionFlow();
        }

        $current = $exchange->exchange_status;

        if (! $current->canTransitionTo($target)) {
            throw OrderClaimException::transitionNotAllowed($current->label(), $target->label());
        }

        if ($this->revertsOrderOptions($target)) {
            $this->assertLivingItemsCanTransitionTo($exchange, $target);
        }

        $updated = $this->applyTransition($exchange, $target, $context);

        $this->fireStatusHooks($updated, $current, $target);

        return $updated;
    }

    /**
     * 살아 있는 신청 상품이 모두 목표 상태로 갈 수 있는지 검증합니다. (신청서 단위 반려·철회의 전제)
     *
     * 상품 단위 전이로 일부 상품이 헤더보다 앞서 있으면(승인·회수중 등) 헤더만 반려·철회로 닫을 수 없다 —
     * 닫으면 그 상품은 살아 있는데 신청서는 종결이라 어디로도 못 가고, 앞선 상품의 재고 차감·후속 옵션은
     * 되돌릴 근거를 잃는다. 그런 신청서는 상품 단위(`transitionOption`)로만 처리한다. 끝난 상품은 헤더
     * 판정에서 빠지므로(`syncHeaderFromOptions` 와 같은 규칙) 여기서도 보지 않는다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $target  목표 상태 (반려·철회)
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 앞서 있는 상품이 있을 때
     */
    protected function assertLivingItemsCanTransitionTo(OrderExchange $exchange, ExchangeStatusEnum $target): void
    {
        $exchange->loadMissing('exchangeOptions');

        foreach ($exchange->exchangeOptions as $item) {
            if ($item->option_status->isFinal() || $item->option_status === $target) {
                continue;
            }

            if (! $item->option_status->canTransitionTo($target)) {
                throw OrderClaimException::transitionNotAllowed($item->option_status->label(), $target->label());
            }
        }
    }

    /**
     * 상태 전이의 DB 변경분을 한 트랜잭션으로 수행합니다. (게이트·훅 제외)
     *
     * 송장 등록처럼 다른 쓰기와 전이를 한 트랜잭션에 묶어야 하는 호출부는 이 메서드를 바깥
     * 트랜잭션 안에서 부르고 훅은 커밋 뒤에 직접 발화한다 — 훅을 트랜잭션 안에서 발화하면
     * 롤백된 전이의 알림이 나간다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $target  목표 상태 (게이트 통과 전제)
     * @param  array  $context  부가 정보
     * @return OrderExchange 전이된 신청서
     */
    protected function applyTransition(OrderExchange $exchange, ExchangeStatusEnum $target, array $context): OrderExchange
    {
        $processedBy = $context['processed_by'] ?? null;

        return DB::transaction(function () use ($exchange, $target, $context, $processedBy) {
            $updates = ['exchange_status' => $target];

            if ($processedBy !== null) {
                $updates['processed_by'] = $processedBy;
            }

            if (array_key_exists('admin_memo', $context)) {
                $updates['admin_memo'] = $context['admin_memo'];
            }

            $updates += $this->stampFor($target, $context);

            $fresh = $this->exchangeRepository->update($exchange, $updates);

            $this->syncOptionStatuses($fresh, $target, $processedBy);

            return $fresh->fresh(['exchangeOptions']);
        });
    }

    /**
     * 여러 교환 신청서의 상태를 일괄 전이합니다.
     *
     * 전이 불가 건은 건너뛰고 사유와 함께 보고한다.
     *
     * @param  array<int, int>  $exchangeIds  교환 신청서 ID 목록
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, exchange_number: string|null, message: string}>} 전이 결과
     */
    public function bulkTransition(array $exchangeIds, ExchangeStatusEnum $target, array $context = []): array
    {
        $succeeded = [];
        $skipped = [];

        foreach ($exchangeIds as $exchangeId) {
            $exchange = $this->exchangeRepository->findById((int) $exchangeId, ['exchangeOptions']);

            if (! $exchange) {
                $skipped[] = [
                    'id' => (int) $exchangeId,
                    'exchange_number' => null,
                    'message' => __('sirsoft-ecommerce::exceptions.claim_not_found'),
                ];

                continue;
            }

            try {
                $this->transition($exchange, $target, $context);
                $succeeded[] = $exchange->id;
            } catch (OrderClaimException $e) {
                $skipped[] = [
                    'id' => $exchange->id,
                    'exchange_number' => $exchange->exchange_number,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return ['succeeded' => $succeeded, 'skipped' => $skipped];
    }

    /**
     * 신청 상품 단위로 상태를 전이합니다. (D25 체크 후 일괄 변경)
     *
     * 헤더 상태는 살아 있는 상품이 모두 같은 상태에 도달했을 때만 따라간다(`syncHeaderFromOptions`).
     * 반려·철회로 전이된 상품은 그 자리에서 원 주문옵션을 되돌리고, 승인·재발송 단계의
     * 부수효과(재고 차감·후속 옵션 생성·도착 처리)도 상품마다 그 자리에서 일어난다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array<int, int>  $itemIds  신청 상품 행 ID 목록 (빈 배열이면 전체)
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보 (processed_by)
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>} 전이 결과
     *
     * @throws OrderClaimException 완료 전이를 시도한 경우
     */
    public function transitionOption(
        OrderExchange $exchange,
        array $itemIds,
        ExchangeStatusEnum $target,
        array $context = [],
    ): array {
        if ($target === ExchangeStatusEnum::COMPLETED) {
            throw OrderClaimException::completionRequiresCompletionFlow();
        }

        $processedBy = $context['processed_by'] ?? null;
        $items = $this->resolveItems($exchange, $itemIds);

        $succeeded = [];
        $skipped = [];
        $changes = [];

        DB::transaction(function () use ($exchange, $items, $target, $processedBy, &$succeeded, &$skipped, &$changes) {
            foreach ($items as $item) {
                // 끝난 상품(반려·철회·완료)은 전이 게이트가 막지 못한다 — 자기 전이는 언제나 참이라
                // 이미 반려된 상품에 반려를 다시 지시하면 그 상품의 부수효과가 한 번 더 돈다.
                // 부수효과는 모두 멱등이지만 처리자·상태가 다시 쓰이므로 여기서 건너뛰고 보고한다.
                if ($item->option_status->isFinal()) {
                    $skipped[] = [
                        'id' => $item->id,
                        'message' => __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                            'from' => $item->option_status->label(),
                            'to' => $target->label(),
                        ]),
                    ];

                    continue;
                }

                if (! $item->option_status->canTransitionTo($target)) {
                    $skipped[] = [
                        'id' => $item->id,
                        'message' => __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                            'from' => $item->option_status->label(),
                            'to' => $target->label(),
                        ]),
                    ];

                    continue;
                }

                $previousStatus = $item->option_status;

                try {
                    $this->transitionItem($exchange, $item, $target, $processedBy);
                } catch (OrderClaimException $e) {
                    // 재고 부족 같은 상품 단위 실패는 그 상품만 건너뛴다 — 같은 TX 안의 다른
                    // 상품은 전이 자체엔 문제가 없고, 저장점(savepoint)이 실패분만 되돌린다.
                    $skipped[] = ['id' => $item->id, 'message' => $e->getMessage()];

                    continue;
                }

                $changes[] = [
                    'option_id' => $item->id,
                    'previous' => $previousStatus->value,
                    'new' => $target->value,
                ];

                $succeeded[] = $item->id;
            }
        });

        $fresh = $exchange->fresh(['exchangeOptions']);

        // 상품 단위 전이는 헤더 전이와 다른 사실이다 — 상품 상태가 갈리면 헤더는 움직이지
        // 않으므로(`syncHeaderFromOptions` 는 살아 있는 상품이 한 상태로 모일 때만 옮긴다)
        // 이 훅이 없으면 운영자가 한 상태 변경이 아무 데도 남지 않는다. 헤더가 따라 움직이면
        // 그 전이는 `status_changed` 가 따로 낸다(이중 기록이 아니라 층이 다른 두 사실).
        if ($changes !== []) {
            HookManager::doAction(
                'sirsoft-ecommerce.claim.exchange.option_status_changed',
                $fresh,
                $changes,
                $target->value,
                $processedBy,
            );
        }

        $this->syncHeaderFromOptions($fresh, $processedBy);

        return ['succeeded' => $succeeded, 'skipped' => $skipped];
    }

    // ───────────────────────────────────────────────
    // 회수 / 재발송 송장, 금액 수정
    // ───────────────────────────────────────────────

    /**
     * 회수 운송장을 기록하고 회수중으로 전이합니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  int|null  $carrierId  회수 택배사 ID
     * @param  string|null  $trackingNumber  회수 운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange 갱신된 신청서
     *
     * @throws OrderClaimException 송장 정보 불완전
     */
    public function registerCollectionTracking(
        OrderExchange $exchange,
        ?int $carrierId,
        ?string $trackingNumber,
        ?int $processedBy = null,
    ): OrderExchange {
        return $this->registerTracking(
            $exchange,
            ['carrier' => 'collection_carrier_id', 'tracking' => 'collection_tracking_number'],
            ExchangeStatusEnum::COLLECTION_READY,
            ExchangeStatusEnum::COLLECTING,
            $carrierId,
            $trackingNumber,
            $processedBy,
        );
    }

    /**
     * 재발송 운송장을 기록하고 재발송중으로 전이합니다.
     *
     * 재발송이 시작되는 순간 후속 주문옵션이 생긴다(`transitionItem` 의 REDELIVERING 부수효과).
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  int|null  $carrierId  재발송 택배사 ID
     * @param  string|null  $trackingNumber  재발송 운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange 갱신된 신청서
     *
     * @throws OrderClaimException 송장 정보 불완전
     */
    public function registerRedeliveryTracking(
        OrderExchange $exchange,
        ?int $carrierId,
        ?string $trackingNumber,
        ?int $processedBy = null,
    ): OrderExchange {
        return $this->registerTracking(
            $exchange,
            ['carrier' => 'redelivery_carrier_id', 'tracking' => 'redelivery_tracking_number'],
            ExchangeStatusEnum::REDELIVERY_READY,
            ExchangeStatusEnum::REDELIVERING,
            $carrierId,
            $trackingNumber,
            $processedBy,
        );
    }

    /**
     * 송장을 기록하고, 준비 상태였으면 진행 상태로 전이합니다. (회수·재발송 공용)
     *
     * 송장 저장과 전이는 한 트랜잭션이다 — 따로 두면 전이가 실패했을 때 "송장은 있는데
     * 준비중" 이 남는다. 훅은 커밋 뒤에 발화한다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array{carrier: string, tracking: string}  $columns  기록할 컬럼명
     * @param  ExchangeStatusEnum  $readyStatus  준비 상태
     * @param  ExchangeStatusEnum  $movingStatus  진행 상태
     * @param  int|null  $carrierId  택배사 ID
     * @param  string|null  $trackingNumber  운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange 갱신된 신청서
     *
     * @throws OrderClaimException 송장 정보 불완전
     */
    protected function registerTracking(
        OrderExchange $exchange,
        array $columns,
        ExchangeStatusEnum $readyStatus,
        ExchangeStatusEnum $movingStatus,
        ?int $carrierId,
        ?string $trackingNumber,
        ?int $processedBy,
    ): OrderExchange {
        $trackingNumber = $trackingNumber !== null && trim($trackingNumber) !== ''
            ? trim($trackingNumber)
            : null;

        if (($carrierId === null) !== ($trackingNumber === null)) {
            throw OrderClaimException::trackingIncomplete();
        }

        $previous = $exchange->exchange_status;
        $startsMoving = $previous === $readyStatus && $trackingNumber !== null;

        $updated = DB::transaction(function () use ($exchange, $columns, $carrierId, $trackingNumber, $processedBy, $startsMoving, $movingStatus) {
            $tracked = $this->exchangeRepository->update($exchange, [
                $columns['carrier'] => $carrierId,
                $columns['tracking'] => $trackingNumber,
                'processed_by' => $processedBy ?? $exchange->processed_by,
            ]);

            return $startsMoving
                ? $this->applyTransition($tracked, $movingStatus, ['processed_by' => $processedBy])
                : $tracked;
        });

        if ($startsMoving) {
            $this->fireStatusHooks($updated, $previous, $movingStatus);
        }

        return $updated;
    }

    /**
     * 관리자가 청구 금액을 수정합니다. (D18 의 클레임 청구액 축)
     *
     * 왕복 배송비를 수정하면 총 청구액(배송비 + 추가 차액)과 다통화 표기가 함께 움직인다.
     * 옵션 차액은 신청 시점 단가 차이라 여기서 고치지 않는다. 접수 시 청구 0원이던 신청서의
     * 상향은 청구 방식(`charge_method`)을 함께 실어야 한다 — 허용 목록
     * (`claim.allowed_charge_methods`) 검증을 동반한다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array  $amounts  수정값 (claim_shipping_fee / charge_method / admin_memo)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange 갱신된 신청서
     *
     * @throws OrderClaimException 종결 신청서 / 금액 음수 / 청구 방식 부재·불허
     */
    public function updateAmounts(OrderExchange $exchange, array $amounts, ?int $processedBy = null): OrderExchange
    {
        // 종결(완료·반려·철회) 신청서의 금액은 이미 정산 근거로 소비됐다 — 수정하면 장부와 어긋난다.
        if ($exchange->isFinal()) {
            throw OrderClaimException::amountsNotEditable();
        }

        $updates = [];

        // 청구 방식을 함께 받는다 (D18 — 접수 시 청구 0원이던 신청서의 상향 경로).
        $chargeMethod = $exchange->charge_method;

        if (array_key_exists('charge_method', $amounts) && $amounts['charge_method'] !== null && $amounts['charge_method'] !== '') {
            $chargeMethod = $this->normalizeChargeMethod($amounts['charge_method']);
            $this->assertChargeMethodAllowedForAmounts($chargeMethod);
            $updates['charge_method'] = $chargeMethod;
        }

        if (array_key_exists('claim_shipping_fee', $amounts)) {
            $fee = (float) $amounts['claim_shipping_fee'];

            if ($fee < 0) {
                throw OrderClaimException::amountInvalid();
            }

            $snapshot = $exchange->shipping_fee_snapshot ?? [];
            $snapshot['admin_adjusted'] = true;
            $snapshot['admin_adjusted_from'] = (float) $exchange->claim_shipping_fee;

            $totalCharge = $fee + (float) $exchange->additional_product_amount;

            // 청구 방식이 없는 신청서(접수 시 청구 0원)의 청구액을 양수로 올리면 결제할 수 없는 청구가
            // 남는다 — 이 요청이 청구 방식을 함께 실었으면 그 값으로 판정한다.
            if ($totalCharge > 0 && $chargeMethod === null) {
                throw OrderClaimException::chargeMethodRequired();
            }

            $exchange->loadMissing('order');
            $currencySnapshot = $exchange->order?->currency_snapshot ?? [];

            $updates['claim_shipping_fee'] = $fee;
            $updates['total_charge_amount'] = $totalCharge;
            $updates['shipping_fee_snapshot'] = $snapshot;
            $updates['mc_claim_shipping_fee'] = $this->toMultiCurrency($fee, $currencySnapshot);
            $updates['mc_total_charge_amount'] = $this->toMultiCurrency($totalCharge, $currencySnapshot);

            // 청구액이 0 이 되면 청구 방식은 의미를 잃는다 — 남겨 두면 결제할 수 없는 청구가 생긴다.
            if ($totalCharge <= 0) {
                $updates['charge_method'] = null;
            }
        }

        if (array_key_exists('admin_memo', $amounts)) {
            $updates['admin_memo'] = $amounts['admin_memo'];
        }

        // 빈 페이로드 판정은 처리자를 넣기 전에 한다 — 뒤에 두면 관리자 경로가 항상
        // processed_by 를 실으므로 이 가드가 사문이 되어 무의미한 UPDATE 가 나간다.
        if ($updates === []) {
            return $exchange;
        }

        if ($processedBy !== null) {
            $updates['processed_by'] = $processedBy;
        }

        return $this->exchangeRepository->update($exchange, $updates);
    }

    /**
     * 금액 수정이 실은 청구 방식이 운영자 허용 목록에 있는지 검증합니다. (반품과 동형)
     *
     * 신청 경로의 `assertChargeMethodUsable` 과 같은 허용 목록(`claim.allowed_charge_methods`)을
     * 읽는다 — 목록 밖 방식이 금액 수정으로 들어오면 신청 화면이 감춘 방식이 우회로가 된다.
     * 차감 가능 여부(deduct) 판정은 신청 시점 검증 소관이라 여기서 반복하지 않는다.
     *
     * @param  ClaimChargeMethodEnum  $chargeMethod  청구 방식
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 허용 목록 밖 방식
     */
    protected function assertChargeMethodAllowedForAmounts(ClaimChargeMethodEnum $chargeMethod): void
    {
        $allowed = (array) $this->settingsService->getSetting(
            'claim.allowed_charge_methods',
            ClaimChargeMethodEnum::values(),
        );

        if (! in_array($chargeMethod->value, $allowed, true)) {
            throw OrderClaimException::chargeMethodNotAllowed();
        }
    }

    // ───────────────────────────────────────────────
    // 완료 처리 (D26)
    // ───────────────────────────────────────────────

    /**
     * 교환을 완료 처리합니다. (D26)
     *
     * 한 트랜잭션에서 ① 신청 상품 완료 전이 ② 원 주문옵션 EXCHANGED 전이 ③ 건별 원 옵션 재고 복구
     * ④ 원 옵션 적립 회수(후속 옵션이 구매확정 시 다시 적립한다) ⑤ (선택) 차액 환불신청서 접수를
     * 수행한다.
     *
     * 반품 완료와 달리 주문 재계산·쿠폰 복원은 하지 않는다 — 후속 주문옵션이 원 행의 금액을 그대로
     * 승계해 주문의 잔여 합계가 변하지 않으므로 복원할 쿠폰 조건의 변화가 없다(반품은 상품이 주문에서
     * 빠져 재계산이 필요하다). 회귀 기준은 교환 뒤 다른 상품의 부분취소 환불액이 차액에 오염되지 않는
     * 것이다(`OrderAdjustmentService::buildRecalcInput` 의 제외 규칙).
     *
     * 환불신청서는 저가 교환(차액 환불분 > 0)에서만 접수할 수 있다. 완료를 여러 라운드로 나누면
     * 라운드마다 그때까지 완료됐지만 아직 접수되지 않은 저가 상품을 모아 접수한다(클래스 docblock
     * "환불신청서는 완료 라운드마다 접수할 수 있다"). 접수를 요구했는데 접수할 것이 없으면 완료
     * 전체를 되돌린다 — 조용히 건너뛰면 운영자는 접수됐다고 알게 된다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array<int, int>  $itemIds  완료할 신청 상품 행 ID 목록 (빈 배열이면 전체)
     * @param  ClaimCompletionOptions  $options  완료 처리 옵션 (모달 선택 결과)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange 완료 처리된 신청서
     *
     * @throws OrderClaimException 완료 가능 상품 부재 / 환불 대상 없음 / 접수할 상품이 전부 이미 접수됨
     */
    public function completeExchange(
        OrderExchange $exchange,
        array $itemIds,
        ClaimCompletionOptions $options,
        ?int $processedBy = null,
    ): OrderExchange {
        $targets = array_values(array_filter(
            $this->resolveItems($exchange, $itemIds),
            fn (OrderExchangeOption $item) => $item->option_status->canTransitionTo(ExchangeStatusEnum::COMPLETED)
                && ! $item->option_status->isFinal(),
        ));

        if ($targets === []) {
            throw OrderClaimException::completionItemsInvalid();
        }

        $previousStatus = $exchange->exchange_status;

        $completionChanges = [];

        $completed = DB::transaction(function () use ($exchange, $targets, $options, $processedBy, &$completionChanges) {
            $order = $this->orderRepository->findForUpdate($exchange->order_id) ?? $exchange->order;
            $order->load(['options', 'shippings', 'payment']);

            $restoredAny = false;

            foreach ($targets as $item) {
                $completionChanges[] = [
                    'option_id' => (int) $item->id,
                    'previous' => $item->option_status->value,
                    'new' => ExchangeStatusEnum::COMPLETED->value,
                ];

                $this->exchangeOptionRepository->update($item, [
                    'option_status' => ExchangeStatusEnum::COMPLETED,
                    'completed_at' => now(),
                    'processed_by' => $processedBy ?? $item->processed_by,
                ]);

                $orderOption = $order->options->firstWhere('id', $item->order_option_id);

                if ($orderOption) {
                    $this->markOrderOptionExchanged($orderOption, (int) $item->exchange_quantity);

                    if ($options->shouldRestoreStock($item->id)) {
                        $this->restoreStockForItem($orderOption, (int) $item->exchange_quantity);
                        $restoredAny = true;
                    }

                    // 원 옵션에 남은 적립 lot 을 회수한다. 같은 구매의 적립은 후속 옵션이 구매확정될 때
                    // 다시 발생하므로, 회수하지 않으면 한 구매에 두 번 적립된다.
                    $this->mileageService->cancelEarnForOption($order, $orderOption->fresh());
                }
            }

            $exchange->load('exchangeOptions');

            // 교환 헤더에는 반품의 refund_id 같은 역참조가 없다 — 이미 접수된 상품은 발생 출처 축
            // (refund_source_type=exchange) 의 환불신청서 상품 행으로 되짚는다. 주문 잠금 안에서
            // 판정하므로 동시 완료 두 건이 같은 상품을 두 번 접수하지 못한다.
            if ($options->createRefundRequest) {
                $this->createRefundRequest($order, $exchange, $processedBy);
            }

            $updates = [
                'processed_by' => $processedBy ?? $exchange->processed_by,
            ];

            if ($restoredAny) {
                $updates['is_stock_restored'] = true;
            }

            if ($this->allItemsCompleted($exchange)) {
                $updates['exchange_status'] = ExchangeStatusEnum::COMPLETED;
                $updates['completed_at'] = now();
            }

            return $this->exchangeRepository->update($exchange, $updates)->fresh(['exchangeOptions']);
        });

        // 부분 완료 라운드는 헤더가 움직이지 않는다 — 그때 이 줄이 없으면 **어떤 줄도
        // 남지 않아** 운영자가 무엇을 완료 처리했는지 흔적이 사라진다(C3).
        if ($completionChanges !== []) {
            HookManager::doAction(
                'sirsoft-ecommerce.claim.exchange.option_status_changed',
                $completed,
                $completionChanges,
                ExchangeStatusEnum::COMPLETED->value,
                $processedBy,
            );
        }

        if ($completed->exchange_status === ExchangeStatusEnum::COMPLETED && $previousStatus !== ExchangeStatusEnum::COMPLETED) {
            $this->fireStatusHooks($completed, $previousStatus, ExchangeStatusEnum::COMPLETED);
        }

        return $completed;
    }

    /**
     * 완료된 저가 교환에 차액 환불신청서를 뒤늦게 접수합니다. (D26 — 완료 시 접수하지 않은 건)
     *
     * 완료됐지만 아직 살아 있는 환불신청서에 담기지 않은 저가 상품을 모아 한 건으로 접수한다.
     * 그런 상품이 없으면 — 전부 접수됐으면 `claim_refund_request_exists`, 애초에 환불 대상이
     * 없으면 `claim_refund_not_applicable` — 로 거절한다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderRefund 생성된 환불 신청서
     *
     * @throws OrderClaimException 완료된 상품이 없거나 환불 대상이 없거나 전부 이미 접수된 경우
     */
    public function createRefundRequestForCompleted(OrderExchange $exchange, ?int $processedBy = null): OrderRefund
    {
        $exchange->loadMissing('exchangeOptions');

        if ($this->completedItems($exchange) === []) {
            throw OrderClaimException::completionItemsInvalid();
        }

        return DB::transaction(function () use ($exchange, $processedBy) {
            $order = $this->orderRepository->findForUpdate($exchange->order_id) ?? $exchange->order;
            $order->load(['options', 'payment']);

            return $this->createRefundRequest($order, $exchange, $processedBy);
        });
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 신청
    // ───────────────────────────────────────────────

    /**
     * 교환 사유를 해석합니다.
     *
     * @param  string  $reasonCode  사유 코드
     * @param  bool  $userSelectableOnly  사용자 선택 가능 사유(`is_user_selectable`)로 제한할지 여부 (관리자 경로는 false)
     * @return ClaimReason 사유 모델
     *
     * @throws OrderClaimException 사유 부재·비활성
     */
    protected function resolveReason(string $reasonCode, bool $userSelectableOnly = true): ClaimReason
    {
        $reason = $this->claimReasonRepository->findByCode($reasonCode, ClaimTypeEnum::EXCHANGE->value);

        // 사용자 경로는 화면에 노출되지 않는 사유(`is_user_selectable=false`)를 고를 수 없어야 한다.
        // 접수 FormRequest 는 그 조건까지 게이트하는데 수정 요청은 신청서 유형을 몰라 규칙을 걸
        // 수 없어, 판정을 여기로 모은다 — 없으면 판매자 귀책 전용 사유를 수정으로 지정해
        // 반품·교환 배송비를 0 으로 만들 수 있다. 관리자 경로가 생기면 인자로 명시해 푼다.
        if (! $reason || ! $reason->is_active || ($userSelectableOnly && ! $reason->is_user_selectable)) {
            throw OrderClaimException::reasonUnavailable();
        }

        return $reason;
    }

    /**
     * 청구 방식 입력을 Enum 으로 정규화합니다.
     *
     * @param  mixed  $chargeMethod  청구 방식 입력
     * @return ClaimChargeMethodEnum|null 청구 방식
     *
     * @throws OrderClaimException 알 수 없는 값
     */
    protected function normalizeChargeMethod(mixed $chargeMethod): ?ClaimChargeMethodEnum
    {
        if ($chargeMethod === null || $chargeMethod === '') {
            return null;
        }

        if ($chargeMethod instanceof ClaimChargeMethodEnum) {
            return $chargeMethod;
        }

        $resolved = ClaimChargeMethodEnum::tryFrom((string) $chargeMethod);

        if ($resolved === null) {
            throw OrderClaimException::chargeMethodNotAllowed();
        }

        return $resolved;
    }

    /**
     * 미리보기의 신청 대상을 해석합니다. (신청과 같은 자격 가드 — 상태·수량·잠금·기간)
     *
     * `requestExchange()` 가 주문을 잠근 뒤 하는 판정을 잠금 없이 같은 규칙으로 수행한다 — 고객이 신청할
     * 수 없는 조합의 금액을 본 뒤 [신청하기] 에서야 거절되는 일을 막는다. 수정 화면의 미리보기는 자기
     * 신청서가 잡아 둔 수량을 빼고 본다(`$excludeExchangeId`).
     *
     * @param  Order  $order  주문
     * @param  array  $items  신청 대상 [{order_option_id, quantity}]
     * @param  int|null  $excludeExchangeId  집계에서 뺄 교환 신청서 ID (수정 중인 자기 자신)
     * @return array<int, array{option: OrderOption, quantity: int}> 주문옵션 ID => 해석 결과
     *
     * @throws OrderClaimException 대상 부재·상태 불가·수량 오류·수량 잠금·기간 경과
     */
    protected function resolvePreviewItems(Order $order, array $items, ?int $excludeExchangeId = null): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $normalized[] = [
                'order_option_id' => (int) ($item['order_option_id'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? $item['cancel_quantity'] ?? 0),
            ] + $item;
        }

        $resolved = $this->claimQuantityGuard->resolveClaimItems(
            $order,
            $normalized,
            ClaimTypeEnum::EXCHANGE,
            $this->requestableStatusValues(),
            $excludeExchangeId,
        );

        $this->assertWithinRequestPeriod($resolved);

        return $resolved;
    }

    /**
     * 해석된 대상에 신규 옵션·차액 정보를 붙여 신청 계획을 만듭니다.
     *
     * @param  array<int, array{option: OrderOption, quantity: int}>  $resolved  해석된 대상
     * @param  array  $items  원 페이로드 (new_product_option_id / custom_options 출처)
     * @return array<int, array{option: OrderOption, quantity: int, target: ProductOption, is_option_change: bool, custom_options: array|null, unit_price: float, new_unit_price: float, price_difference: float}> 신청 계획
     *
     * @throws OrderClaimException 신규 옵션 무효
     */
    protected function planItems(array $resolved, array $items): array
    {
        $inputByOption = [];

        foreach ($items as $item) {
            $inputByOption[(int) ($item['order_option_id'] ?? 0)] = $item;
        }

        $plans = [];

        foreach ($resolved as $optionId => $entry) {
            $option = $entry['option'];
            $input = $inputByOption[$optionId] ?? [];
            $newProductOptionId = isset($input['new_product_option_id']) && $input['new_product_option_id'] !== ''
                ? (int) $input['new_product_option_id']
                : null;

            [$target, $isOptionChange] = $this->resolveTargetProductOption($option, $newProductOptionId);

            $unitPrice = (float) $option->unit_price;
            $newUnitPrice = $isOptionChange ? $target->getFinalPrice() : $unitPrice;
            $customOptions = isset($input['custom_options']) && is_array($input['custom_options']) && $input['custom_options'] !== []
                ? $input['custom_options']
                : null;

            // 추가옵션은 클라이언트가 보낸 ID 만 신뢰하고 값·가격은 서버에서 다시 읽는다
            // (주문 체크아웃과 같은 규칙 — `ResolvesAdditionalOptions`). 그 상품 소속이 아닌
            // 선택지는 제외되므로 금액에도 표기에도 실리지 않는다.
            [$newAdditionalTotal, $newAdditionalSnapshot] = $this->resolveAdditionalOptions(
                (int) $target->product_id,
                $this->normalizeClaimCustomOptions($customOptions)
            );

            // 주문의 소계 규칙(D6)은 `(옵션가 + 추가옵션 단위합계) × 수량` 이다. 교환 차액도 같은
            // 식이어야 한다 — 추가옵션 항을 양쪽에서 빼면 유료 추가옵션을 골라도 청구가 0 이 되고,
            // 원 주문의 추가옵션을 빼고 교환해도 그만큼이 돌아오지 않는다.
            $originalAdditionalTotal = (int) ($option->additional_options_total ?? 0);

            $plans[$optionId] = [
                'option' => $option,
                'quantity' => (int) $entry['quantity'],
                'target' => $target,
                'is_option_change' => $isOptionChange,
                'custom_options' => $customOptions,
                'new_additional_options_total' => $newAdditionalTotal,
                'new_additional_options_snapshot' => $newAdditionalSnapshot,
                'unit_price' => $unitPrice,
                'new_unit_price' => $newUnitPrice,
                'price_difference' => (($newUnitPrice + $newAdditionalTotal) - ($unitPrice + $originalAdditionalTotal))
                    * (int) $entry['quantity'],
            ];
        }

        return $plans;
    }

    /**
     * 재발송할 상품옵션을 해석합니다.
     *
     * 지정하지 않았거나 원 옵션과 같으면 동일상품 교환이다(D29 기본값). 옵션을 바꾸면
     * **같은 상품의 판매 중인 옵션**이어야 한다 — 다른 상품은 배송정책·과세·적립 정책이 달라
     * 차액 하나로 정산할 수 없다.
     *
     * @param  OrderOption  $option  원 주문옵션
     * @param  int|null  $newProductOptionId  교환 대상 상품옵션 ID
     * @return array{0: ProductOption, 1: bool} [재발송 옵션, 옵션 변경 여부]
     *
     * @throws OrderClaimException 옵션 무효
     */
    protected function resolveTargetProductOption(OrderOption $option, ?int $newProductOptionId): array
    {
        $isOptionChange = $newProductOptionId !== null && $newProductOptionId !== (int) $option->product_option_id;

        // 옵션을 바꾸면 신규 단가(`getFinalPrice()` = 상품 판매가 + 옵션 조정액)를 읽어야 하므로
        // 상품까지 한 번에 싣는다 — 관계를 불러올지는 Repository 가 정하고, 모델 안에서 지연 로드하지 않는다.
        $target = $isOptionChange
            ? $this->productOptionRepository->findByIdWithProduct($newProductOptionId)
            : $this->productOptionRepository->findById((int) $option->product_option_id);

        if (! $target) {
            throw OrderClaimException::newOptionInvalid();
        }

        if ($isOptionChange && (! $target->is_active || (int) $target->product_id !== (int) $option->product_id)) {
            throw OrderClaimException::newOptionInvalid();
        }

        return [$target, $isOptionChange];
    }

    /**
     * 재발송할 옵션의 재고가 신청 수량을 감당하는지 검증합니다.
     *
     * 차감은 승인 시점이지만 부족을 신청 시점에 알려야 고객이 다른 옵션을 고를 수 있다.
     * 같은 옵션을 두 상품 행이 고르면 합산해 판정한다.
     *
     * @param  array  $plans  신청 계획
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 재고 부족
     */
    protected function assertTargetStockAvailable(array $plans): void
    {
        $required = [];

        foreach ($plans as $plan) {
            $required[$plan['target']->id] = ($required[$plan['target']->id] ?? 0) + $plan['quantity'];
        }

        foreach ($plans as $plan) {
            if ((int) $plan['target']->stock_quantity < $required[$plan['target']->id]) {
                throw OrderClaimException::newOptionOutOfStock();
            }
        }
    }

    /**
     * 재발송지 스냅샷을 만듭니다. (D14)
     *
     * 입력이 없으면 주문 배송지를 그대로 담고 `changed_by_user = false` 로 둔다. 입력이 있으면
     * 주소 필드를 입력으로 덮고, `changed_by_user` 는 **화면이 보낸 신호**를 그대로 저장한다 —
     * 값이 같아도 고객이 손댔다는 사실을 관리자 화면이 구분해야 한다. 신호 없이 주소가 왔으면
     * 주문 배송지와의 차이로 판정한다.
     *
     * @param  Order  $order  주문
     * @param  array|null  $input  재발송지 입력
     * @param  array|null  $current  직전 재발송지 스냅샷 (승인 전 수정 — 보내지 않은 필드의 base)
     * @return array 재발송지 스냅샷
     */
    protected function buildRedeliveryAddressSnapshot(Order $order, ?array $input, ?array $current = null): array
    {
        $orderAddress = $this->orderShippingAddressSnapshot($order);

        // 보내지 않은 필드의 base 는 **직전 스냅샷**이다. 주문 배송지를 base 로 쓰면 2회 연속
        // 부분 수정에서 앞 수정이 조용히 되돌아간다(보낸 필드만 반영하는 계약이 깨진다).
        $base = $orderAddress;

        if ($current !== null) {
            foreach (array_keys($orderAddress) as $field) {
                if (array_key_exists($field, $current)) {
                    $base[$field] = $current[$field];
                }
            }
        }

        if (empty($input)) {
            return $base + ['changed_by_user' => false];
        }

        $snapshot = $base;

        foreach (['recipient_name', 'phone', 'zipcode', 'address1', 'address2', 'country_code'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                $snapshot[$field] = $field === 'country_code'
                    ? strtoupper(trim((string) $input[$field]))
                    : $input[$field];
            }
        }

        // 손댔는지는 언제나 **주문 배송지**와의 차이로 판정한다 — 직전 스냅샷과 비교하면
        // 이미 바꾼 주소를 그대로 둔 수정이 '손대지 않음' 으로 뒤집힌다.
        $snapshot['changed_by_user'] = array_key_exists('changed_by_user', $input)
            ? (bool) $input['changed_by_user']
            : $snapshot !== $orderAddress;

        return $snapshot;
    }

    /**
     * 재발송지가 주문 배송지와 다른지 판정합니다. (D14 — 상세 화면 '배송지 다름' 뱃지)
     *
     * 두 뱃지는 서로 다른 사실을 말한다 — 이쪽은 "최종 재발송지가 주문 배송지와 다른가"이고,
     * `changed_by_user` 는 "고객이 신청서에서 손댔는가"다. 운영자가 나중에 주소를 되돌리면
     * 값은 같아지지만 손댄 사실은 남으므로 둘을 한 뱃지로 합치지 않는다.
     *
     * 판정은 저장된 두 주소의 비교로 도출한다 — 별도 컬럼을 만들지 않는다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @return bool 주문 배송지와 다르면 true (재발송지 스냅샷·주문이 없으면 false)
     */
    public function redeliveryAddressDiffersFromOrder(OrderExchange $exchange): bool
    {
        $snapshot = $exchange->redelivery_address_snapshot;
        $order = $exchange->order;

        if (! is_array($snapshot) || $order === null) {
            return false;
        }

        foreach ($this->orderShippingAddressSnapshot($order) as $field => $value) {
            if (($snapshot[$field] ?? null) !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * 주문 배송지를 재발송지 스냅샷 형태로 반환합니다.
     *
     * @param  Order  $order  주문
     * @return array{recipient_name: string|null, phone: string|null, zipcode: string|null, address1: string|null, address2: string|null, country_code: string} 배송지
     */
    protected function orderShippingAddressSnapshot(Order $order): array
    {
        $address = $order->shippingAddress;

        return [
            'recipient_name' => $address?->recipient_name,
            'phone' => $address?->recipient_phone,
            'zipcode' => $address?->zipcode ?: $address?->intl_postal_code,
            'address1' => $address?->address ?: $address?->address_line_1,
            'address2' => $address?->address_detail ?: $address?->address_line_2,
            'country_code' => ExchangeRedeliveryCountryUnchanged::orderShippingCountry($order),
        ];
    }

    /**
     * 재발송지 국가가 주문 배송지와 같은지 검증합니다. (교환 해외배송 미지원)
     *
     * FormRequest 의 같은 규칙(`ExchangeRedeliveryCountryUnchanged`)과 **같은 기준 국가**를 읽는다.
     * 검증을 거치지 않는 내부·훅 호출까지 막는 2차 방어다.
     *
     * @param  Order  $order  주문
     * @param  array  $redelivery  재발송지 스냅샷
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 국가가 다를 때
     */
    protected function assertRedeliveryCountryUnchanged(Order $order, array $redelivery): void
    {
        $expected = ExchangeRedeliveryCountryUnchanged::orderShippingCountry($order);
        $actual = strtoupper((string) ($redelivery['country_code'] ?? $expected));

        if ($actual !== $expected) {
            throw OrderClaimException::redeliveryCountryChangeNotAllowed();
        }
    }

    /**
     * 재발송지 스냅샷을 배송비 산정기의 구간 주소 형태로 바꿉니다.
     *
     * @param  array|null  $redelivery  재발송지
     * @return array{country_code: string|null, zipcode: string|null}|null 구간 주소
     */
    protected function toFeeLegAddress(?array $redelivery): ?array
    {
        if (empty($redelivery)) {
            return null;
        }

        return [
            'country_code' => $redelivery['country_code'] ?? null,
            'zipcode' => $redelivery['zipcode'] ?? null,
        ];
    }

    /**
     * 청구 방식이 사용 가능한지 검증합니다.
     *
     * - 청구액(왕복 배송비 + 추가 차액)이 있으면 방식이 필요하고, 운영자 허용 목록 안에 있어야 한다.
     * - '환불금에서 차감'은 차액 환불이 발생하는 저가 교환에서, 차감하고도 환불액이 남을 때만 성립한다.
     *
     * @param  Order  $order  주문
     * @param  array  $claimItems  대상 아이템 [{order_option_id, cancel_quantity}]
     * @param  ClaimShippingFee  $shippingFee  산정된 왕복 배송비
     * @param  float  $priceDifference  옵션 차액 합계 (부호 유지)
     * @param  float  $totalCharge  총 청구액
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 청구 방식 부재·불허·차감 불가
     */
    protected function assertChargeMethodUsable(
        Order $order,
        array $claimItems,
        ClaimShippingFee $shippingFee,
        float $priceDifference,
        float $totalCharge,
        ?ClaimChargeMethodEnum $chargeMethod,
        RefundPriorityEnum $refundPriority,
    ): void {
        if ($totalCharge <= 0) {
            return;
        }

        if ($chargeMethod === null) {
            throw OrderClaimException::chargeMethodRequired();
        }

        $allowed = (array) $this->settingsService->getSetting(
            'claim.allowed_charge_methods',
            ClaimChargeMethodEnum::values(),
        );

        if (! in_array($chargeMethod->value, $allowed, true)) {
            throw OrderClaimException::chargeMethodNotAllowed();
        }

        if ($chargeMethod !== ClaimChargeMethodEnum::DEDUCT_FROM_REFUND) {
            return;
        }

        // 차감 전(청구 방식 없음) 환불액과 청구액을 비교한다 — 차감 후로 판정하면 clamp 에 가려
        // "차감했는데 환불이 0" 과 "원래 환불이 0" 이 구분되지 않는다.
        $baseline = $this->adjustmentService->preview(
            $order,
            ExchangeAdjustment::fromShippingFee($claimItems, $shippingFee, (int) round($priceDifference)),
            $refundPriority,
        );

        if ($baseline->getTotalRefundAmount() < $totalCharge) {
            throw OrderClaimException::chargeMethodNotAllowed();
        }
    }

    /**
     * 신청 가능 기간을 검증합니다. (배송완료 → 확정 → 마지막 변경 시각 순의 기산점)
     *
     * @param  array<int, array{option: OrderOption, quantity: int}>  $resolved  해석된 대상
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 기간 경과
     */
    protected function assertWithinRequestPeriod(array $resolved): void
    {
        $days = (int) $this->settingsService->getSetting('claim.request_period_days', 7);

        if ($days <= 0) {
            return;
        }

        foreach ($resolved as $entry) {
            $option = $entry['option'];

            // 기산점은 배송완료 시각이 정본이지만, `배송중 → 구매확정` 직접 전이가 허용되므로
            // `delivered_at` 이 영영 null 인 옵션이 실재한다. 그것을 "판정 불가" 로 넘기면 기한 게이트가
            // 그 옵션에 한해 영구히 열린 채가 된다. 확정 시각으로, 그마저 없으면(두 컬럼이 생기기 전
            // 주문) 마지막 변경 시각으로 내려간다 — 어느 경우에도 창이 닫히고, 정상 신청은 막지 않는다.
            $baseline = $option->delivered_at ?? $option->confirmed_at ?? $option->updated_at;

            if ($baseline === null) {
                continue;
            }

            if ($baseline->copy()->addDays($days)->isPast()) {
                throw OrderClaimException::requestPeriodExpired($days);
            }
        }
    }

    /**
     * 신청 가능 주문옵션 상태 값 목록을 반환합니다.
     *
     * @return array<string> 상태 값 목록
     */
    protected function requestableStatusValues(): array
    {
        return array_values((array) $this->settingsService->getSetting(
            'claim.exchange_requestable_statuses',
            [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value],
        ));
    }

    /**
     * 신청 상품 행을 만들고 원 주문옵션을 교환진행중으로 전이합니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  Order  $order  주문
     * @param  array  $plan  신청 계획 항목
     * @param  array  $currencySnapshot  주문 통화 스냅샷
     * @return void 반환값 없음
     */
    protected function createExchangeOption(OrderExchange $exchange, Order $order, array $plan, array $currencySnapshot): void
    {
        $option = $plan['option'];

        // 감사용 값은 전이 **전에** 캡처한다 — 전이는 원 행의 수량·상태를 그 자리에서 바꾼다.
        $originalStatus = $option->option_status;
        $originalQuantity = (int) $option->quantity;

        $transition = $this->orderOptionService->changeStatusWithQuantity(
            $option,
            OrderStatusEnum::EXCHANGE_IN_PROGRESS,
            $plan['quantity'],
            [],
            restoreStockOnCancel: false,
            mergeSiblings: false,
        );

        // 신청 상품 행이 참조할 주문옵션은 코스 상태를 실제로 갖게 된 행이다.
        $courseOption = $transition['split'] ?? $transition['original'];

        $this->exchangeOptionRepository->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $courseOption->id,
            'option_status' => ExchangeStatusEnum::REQUESTED,
            'exchange_quantity' => $plan['quantity'],
            'original_quantity' => $originalQuantity,
            'original_option_status' => $originalStatus,
            'unit_price' => $plan['unit_price'],
            'new_product_option_id' => $plan['is_option_change'] ? $plan['target']->id : null,
            'new_option_snapshot' => $this->buildNewOptionSnapshot($plan),
            'new_unit_price' => $plan['new_unit_price'],
            'price_difference' => $plan['price_difference'],
            'mc_price_difference' => $this->toMultiCurrency($plan['price_difference'], $currencySnapshot),
        ]);
    }

    /**
     * 신규 옵션 스냅샷을 만듭니다. (옵션 변경이 없고 커스텀 입력도 없으면 null)
     *
     * @param  array  $plan  신청 계획 항목
     * @return array|null 스냅샷
     */
    protected function buildNewOptionSnapshot(array $plan): ?array
    {
        $snapshot = [];

        if ($plan['is_option_change']) {
            $snapshot = $plan['target']->toSnapshotArray();
            $snapshot['option_value'] = $this->buildOptionValueSummary($plan['target']);
        }

        if ($plan['custom_options'] !== null) {
            // 보낸 원문(그룹ID→값ID)도 남긴다 — 수정 화면이 선택 상태를 되살릴 때 읽는다.
            $snapshot['custom_options'] = $plan['custom_options'];
        }

        // 화면이 그릴 재료는 **이름과 추가금이 동결된 스냅샷**이다. 예전에는 ID 만 남아
        // 이름을 복원할 방법이 없었고(운영자가 선택지를 고치거나 지우면 더더욱), 그래서
        // 신청 후 화면에 추가옵션이 아예 나오지 않았다. 주문의 `additional_options_snapshot`
        // 과 같은 형태·같은 해석기를 쓴다.
        if (($plan['new_additional_options_snapshot'] ?? []) !== []) {
            $snapshot['additional_options_snapshot'] = $plan['new_additional_options_snapshot'];
            $snapshot['additional_options_total'] = (int) ($plan['new_additional_options_total'] ?? 0);
        }

        return $snapshot === [] ? null : $snapshot;
    }

    /**
     * 클레임 화면이 보내는 추가옵션 선택을 주문 해석기의 입력 형태로 바꿉니다.
     *
     * 화면은 그룹 단위로 `{selections: {그룹ID: 값ID}, custom_texts: {그룹ID: 텍스트}}` 를 보내고,
     * 해석기는 `[{value_id, custom_text}]` 를 받는다. 두 형태를 잇는 곳이 여기 하나여야
     * 주문과 교환이 같은 규칙 위에 선다.
     *
     * @param  array|null  $customOptions  화면이 보낸 원문
     * @return array<int, array{value_id: int, custom_text: string}> 해석기 입력
     */
    protected function normalizeClaimCustomOptions(?array $customOptions): array
    {
        if ($customOptions === null) {
            return [];
        }

        $selections = is_array($customOptions['selections'] ?? null) ? $customOptions['selections'] : [];
        $customTexts = is_array($customOptions['custom_texts'] ?? null) ? $customOptions['custom_texts'] : [];

        $normalized = [];

        foreach ($selections as $groupId => $valueId) {
            $valueId = (int) $valueId;

            if ($valueId <= 0) {
                continue;
            }

            $normalized[] = [
                'value_id' => $valueId,
                'custom_text' => (string) ($customTexts[$groupId] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * 신청 대상 스냅샷을 만듭니다.
     *
     * @param  array  $plans  신청 계획
     * @return array 스냅샷
     */
    protected function buildItemsSnapshot(array $plans): array
    {
        $snapshot = [];

        foreach ($plans as $plan) {
            $option = $plan['option'];

            $snapshot[] = [
                'option_id' => $option->id,
                'product_name' => $option->product_name,
                'option_name' => $option->option_name,
                'option_value' => $option->option_value,
                'quantity' => $plan['quantity'],
                'unit_price' => $plan['unit_price'],
                'option_status' => $option->option_status instanceof OrderStatusEnum
                    ? $option->option_status->value
                    : $option->option_status,
                'new_product_option_id' => $plan['is_option_change'] ? $plan['target']->id : null,
                'new_option_name' => $plan['is_option_change'] ? $plan['target']->option_name : null,
                'new_unit_price' => $plan['new_unit_price'],
                'price_difference' => $plan['price_difference'],
            ];
        }

        return $snapshot;
    }

    /**
     * 신청 계획을 미리보기 응답의 상품별 내역으로 바꿉니다. (D29 상품별 병기)
     *
     * @param  array  $plans  신청 계획
     * @return array<int, array{order_option_id: int, quantity: int, new_product_option_id: int|null, is_option_change: bool, unit_price: float, new_unit_price: float, price_difference: float}> 상품별 내역
     */
    protected function describePlans(array $plans): array
    {
        $rows = [];

        foreach ($plans as $plan) {
            $rows[] = [
                'order_option_id' => $plan['option']->id,
                'quantity' => $plan['quantity'],
                'new_product_option_id' => $plan['is_option_change'] ? $plan['target']->id : null,
                'is_option_change' => $plan['is_option_change'],
                'unit_price' => $plan['unit_price'],
                'new_unit_price' => $plan['new_unit_price'],
                'price_difference' => $plan['price_difference'],
            ];
        }

        return $rows;
    }

    /**
     * 회수지 주소 스냅샷을 만듭니다. (정책×교환 유형의 회수지를 신청 시점에 복사)
     *
     * @param  Order  $order  주문
     * @param  array<int, array{option: OrderOption, quantity: int}>  $resolved  해석된 대상
     * @return array|null 회수지 스냅샷 (정책에 회수지가 없으면 null)
     */
    protected function resolveReturnAddressSnapshot(Order $order, array $resolved): ?array
    {
        $shippingByOption = $order->shippings->keyBy('order_option_id');

        foreach ($resolved as $entry) {
            $policyId = $shippingByOption->get($entry['option']->id)?->shipping_policy_id;

            if (! $policyId) {
                continue;
            }

            $address = $this->claimAddressRepository->findByPolicyAndType(
                (int) $policyId,
                ClaimTypeEnum::EXCHANGE->value,
            );

            if ($address) {
                return [
                    'shipping_policy_id' => (int) $policyId,
                    'recipient_name' => $address->recipient_name,
                    'phone' => $address->phone,
                    'zipcode' => $address->zipcode,
                    'address1' => $address->address1,
                    'address2' => $address->address2,
                    'country_code' => $address->country_code,
                ];
            }
        }

        return null;
    }

    /**
     * 주문의 기준통화 코드를 반환합니다.
     *
     * @param  Order  $order  주문
     * @return string 기준통화 코드
     */
    protected function resolveBaseCurrency(Order $order): string
    {
        return (string) ($order->currency_snapshot['base_currency'] ?? $order->currency ?? 'KRW');
    }

    /**
     * 금액을 주문 통화 스냅샷의 동결 환율로 다통화 변환합니다. (스냅샷이 없으면 null)
     *
     * @param  float  $amount  기준통화 금액
     * @param  array  $currencySnapshot  주문 통화 스냅샷
     * @return array|null 통화코드 → 금액
     */
    protected function toMultiCurrency(float $amount, array $currencySnapshot): ?array
    {
        if (empty($currencySnapshot)) {
            return null;
        }

        $converted = $this->currencyService->convertToMultiCurrencyWithSnapshot(
            $amount == (int) $amount ? (int) $amount : $amount,
            $currencySnapshot,
        );

        return $converted ?: null;
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 전이
    // ───────────────────────────────────────────────

    /**
     * 상태별 시각·부가 컬럼 갱신값을 반환합니다.
     *
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보
     * @return array<string, mixed> 갱신값
     */
    protected function stampFor(ExchangeStatusEnum $target, array $context): array
    {
        return match ($target) {
            ExchangeStatusEnum::APPROVED => ['approved_at' => now()],
            ExchangeStatusEnum::ON_HOLD => ['on_hold_at' => now()],
            ExchangeStatusEnum::REJECTED => [
                'rejected_at' => now(),
                'reject_reason' => $context['reject_reason'] ?? null,
            ],
            ExchangeStatusEnum::WITHDRAWN => ['withdrawn_at' => now()],
            ExchangeStatusEnum::COLLECTED => ['collection_completed_at' => now()],
            ExchangeStatusEnum::REDELIVERED => ['redelivered_at' => now()],
            default => [],
        };
    }

    /**
     * 신청 상품 상태를 헤더 상태에 맞춥니다.
     *
     * 목표 상태를 받아들일 수 없는 상품(이미 그 지점을 지났거나 종결)은 그대로 둔다.
     * 승인·재발송 단계의 부수효과는 상품마다 `transitionItem` 이 수행한다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return void 반환값 없음
     */
    protected function syncOptionStatuses(OrderExchange $exchange, ExchangeStatusEnum $target, ?int $processedBy): void
    {
        $exchange->loadMissing('exchangeOptions');

        $changes = [];

        foreach ($exchange->exchangeOptions as $item) {
            if ($item->option_status === $target || $item->option_status->isFinal()) {
                continue;
            }

            if (! $item->option_status->canTransitionTo($target)) {
                continue;
            }

            $previous = $item->option_status->value;

            $this->transitionItem($exchange, $item, $target, $processedBy);

            $changes[] = [
                'option_id' => (int) $item->id,
                'previous' => $previous,
                'new' => $target->value,
            ];
        }

        // 헤더 전이가 끌고 간 상품 전이도 상품 축 훅으로 남긴다(C1·C2). 예전에는
        // `transitionOption()` 한 경로만 훅을 쏴, 운영자가 신청서 단위로 승인하면 상품 행은
        // 조용히 함께 옮겨지고 그 사실이 활동로그 어디에도 없었다 — 헤더 줄만 보고는 어느
        // 상품이 몇 건 따라 움직였는지 알 수 없다.
        if ($changes !== []) {
            HookManager::doAction(
                'sirsoft-ecommerce.claim.exchange.option_status_changed',
                $exchange->fresh(['exchangeOptions']),
                $changes,
                $target->value,
                $processedBy,
            );
        }
    }

    /**
     * 신청 상품 한 건의 상태를 바꾸고 그 상태의 부수효과를 수행합니다.
     *
     * 신청서 단위 전이(`syncOptionStatuses`)와 상품 단위 전이(`transitionOption`)가 **같은 이
     * 메서드**를 쓴다 — 부수효과를 경로마다 두면 한쪽만 고쳐졌을 때 승인은 됐는데 재고가 차감되지
     * 않거나, 재발송중인데 후속 옵션이 없는 상태가 조용히 생긴다.
     *
     *  - 승인: 재발송할 옵션의 재고를 차감한다(D7). 부족하면 이 상품의 전이를 거절한다.
     *  - 반려·철회: 원 주문옵션을 신청 직전 상태로 되돌린다. 게이트상 승인 뒤에는 도달할 수
     *    없으므로 되돌릴 신규 재고는 없다.
     *  - 재발송중: 후속 주문옵션을 만든다(멱등).
     *  - 재발송도착: 후속 주문옵션을 배송완료로 옮긴다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @param  ExchangeStatusEnum  $target  목표 상태 (게이트 통과 전제)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 승인 시 재고 부족
     */
    protected function transitionItem(
        OrderExchange $exchange,
        OrderExchangeOption $item,
        ExchangeStatusEnum $target,
        ?int $processedBy,
    ): void {
        DB::transaction(function () use ($exchange, $item, $target, $processedBy) {
            if ($target === ExchangeStatusEnum::APPROVED) {
                $this->deductNewOptionStock($item);
            }

            $updated = $this->exchangeOptionRepository->update($item, [
                'option_status' => $target,
                'processed_by' => $processedBy ?? $item->processed_by,
            ]);

            if ($this->revertsOrderOptions($target)) {
                $this->revertOrderOptionFor($updated->loadMissing('orderOption'));
            }

            if ($target === ExchangeStatusEnum::REDELIVERING) {
                $this->ensureNewOrderOption($exchange, $updated->loadMissing('orderOption'));
            }

            if ($target === ExchangeStatusEnum::REDELIVERED) {
                $this->ensureNewOrderOption($exchange, $updated->loadMissing('orderOption'));
                $this->markNewOrderOptionDelivered($updated->fresh());
            }
        });
    }

    /**
     * 살아 있는 신청 상품이 모두 같은 상태에 도달했으면 헤더를 그 상태로 옮깁니다.
     *
     * 판정 규칙은 반품 서비스와 같다 — 끝난 상품(반려·철회·완료)은 빼고, 살아 있는 상품이
     * 없으면 끝난 상태가 하나로 모일 때 그것을 따라가며, 한 단계 이상 앞서 있으면 건너뛴 중간
     * 단계를 차례로 거친다(`headerPathTo`). 교환은 14종이라 경로가 길다(회수 3 + 재발송 3).
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return void 반환값 없음
     */
    protected function syncHeaderFromOptions(OrderExchange $exchange, ?int $processedBy): void
    {
        $exchange->loadMissing('exchangeOptions');

        $items = $exchange->exchangeOptions;

        if ($items->isEmpty()) {
            return;
        }

        $living = $items->reject(fn (OrderExchangeOption $item) => $item->option_status->isFinal());

        $statuses = ($living->isEmpty() ? $items : $living)
            ->map(fn (OrderExchangeOption $item) => $item->option_status->value)
            ->unique()
            ->values();

        if ($statuses->count() !== 1) {
            return;
        }

        $target = ExchangeStatusEnum::from($statuses->first());

        if ($target === $exchange->exchange_status || $target === ExchangeStatusEnum::COMPLETED) {
            return;
        }

        $path = $this->headerPathTo($exchange->exchange_status, $target);

        if ($path === []) {
            return;
        }

        $current = $exchange;

        foreach ($path as $step) {
            $previous = $current->exchange_status;
            $updates = ['exchange_status' => $step] + $this->stampFor($step, []);

            if ($processedBy !== null) {
                $updates['processed_by'] = $processedBy;
            }

            $current = $this->exchangeRepository->update($current, $updates);

            $this->fireStatusHooks($current, $previous, $step);
        }
    }

    /**
     * 헤더가 현재 상태에서 목표 상태까지 거쳐야 할 단계 목록을 전이 게이트로 찾습니다. (BFS)
     *
     * @param  ExchangeStatusEnum  $from  현재 헤더 상태
     * @param  ExchangeStatusEnum  $to  상품들이 모인 상태
     * @return array<int, ExchangeStatusEnum> 거쳐야 할 상태 목록 (목표 포함, 현재 제외)
     */
    protected function headerPathTo(ExchangeStatusEnum $from, ExchangeStatusEnum $to): array
    {
        if ($from === $to) {
            return [];
        }

        $queue = [[$from, []]];
        $visited = [$from->value => true];

        while ($queue !== []) {
            [$node, $path] = array_shift($queue);

            foreach ($node->allowedTransitions() as $next) {
                if (isset($visited[$next->value])) {
                    continue;
                }

                $nextPath = [...$path, $next];

                if ($next === $to) {
                    return $nextPath;
                }

                $visited[$next->value] = true;
                $queue[] = [$next, $nextPath];
            }
        }

        return [];
    }

    /**
     * 신청 상품 행을 해석합니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array<int, int>  $itemIds  신청 상품 행 ID 목록 (빈 배열이면 전체)
     * @return array<int, OrderExchangeOption> 신청 상품 행 목록
     */
    protected function resolveItems(OrderExchange $exchange, array $itemIds): array
    {
        $exchange->loadMissing('exchangeOptions');

        if ($itemIds === []) {
            return $exchange->exchangeOptions->all();
        }

        $ids = array_map('intval', $itemIds);

        return $exchange->exchangeOptions
            ->filter(fn (OrderExchangeOption $item) => in_array($item->id, $ids, true))
            ->values()
            ->all();
    }

    /**
     * 그 상태에 도달하면 원 주문옵션을 되돌려야 하는지 판정합니다.
     *
     * 반품 서비스와 같은 모양의 판정을 이 서비스에 둔다 — Enum 타입이 달라 공유할 수 없다.
     *
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @return bool 복귀 대상 여부
     */
    protected function revertsOrderOptions(ExchangeStatusEnum $target): bool
    {
        return in_array($target, [ExchangeStatusEnum::REJECTED, ExchangeStatusEnum::WITHDRAWN], true);
    }

    /**
     * 신청 상품 한 건의 원 주문옵션을 신청 직전 상태로 되돌립니다.
     *
     * 복귀 대상인지의 판정은 `OrderStatusEnum::revertibleClaimCourseStatuses()` 단일 출처를
     * 읽는다 — 여기에 `!== EXCHANGE_IN_PROGRESS` 같은 비교를 새로 넣으면 반품과 판정이 두 벌이 된다.
     *
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @return void 반환값 없음
     */
    protected function revertOrderOptionFor(OrderExchangeOption $item): void
    {
        $orderOption = $item->orderOption;

        if (! $orderOption || ! in_array($orderOption->option_status, OrderStatusEnum::revertibleClaimCourseStatuses(), true)) {
            return;
        }

        $target = $item->original_option_status;

        if (! $target instanceof OrderStatusEnum) {
            return;
        }

        $this->orderOptionService->changeStatusWithQuantity(
            $orderOption,
            $target,
            (int) $orderOption->quantity,
            [],
            restoreStockOnCancel: false,
            mergeSiblings: false,
        );
    }

    /**
     * 상태 전이 훅을 발화합니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $previous  직전 상태
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @return void 반환값 없음
     */
    protected function fireStatusHooks(OrderExchange $exchange, ExchangeStatusEnum $previous, ExchangeStatusEnum $target): void
    {
        HookManager::doAction(
            'sirsoft-ecommerce.claim.exchange.status_changed',
            $exchange,
            $previous->value,
            $target->value,
        );

        $specific = match ($target) {
            ExchangeStatusEnum::APPROVED => 'sirsoft-ecommerce.claim.exchange.approved',
            ExchangeStatusEnum::REJECTED => 'sirsoft-ecommerce.claim.exchange.rejected',
            ExchangeStatusEnum::WITHDRAWN => 'sirsoft-ecommerce.claim.exchange.withdrawn',
            ExchangeStatusEnum::COMPLETED => 'sirsoft-ecommerce.claim.exchange.completed',
            default => null,
        };

        if ($specific !== null) {
            HookManager::doAction($specific, $exchange);
        }
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 신규 옵션 재고 / 후속 주문옵션
    // ───────────────────────────────────────────────

    /**
     * 재발송할 옵션의 재고를 차감합니다. (승인 시점, 멱등)
     *
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 재고 부족
     */
    protected function deductNewOptionStock(OrderExchangeOption $item): void
    {
        if ($item->is_new_stock_deducted) {
            return;
        }

        $productOptionId = $this->targetProductOptionId($item->loadMissing('orderOption'));

        if ($productOptionId === null) {
            throw OrderClaimException::newOptionInvalid();
        }

        try {
            $this->stockService->deductOptionStock($productOptionId, (int) $item->exchange_quantity);
        } catch (InsufficientStockException) {
            throw OrderClaimException::newOptionOutOfStock();
        }

        $this->exchangeOptionRepository->update($item, ['is_new_stock_deducted' => true]);
    }

    /**
     * 재발송할 상품옵션 ID 를 반환합니다. (옵션 변경이면 신규, 아니면 원 옵션)
     *
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @return int|null 상품옵션 ID
     */
    protected function targetProductOptionId(OrderExchangeOption $item): ?int
    {
        if ($item->new_product_option_id !== null) {
            return (int) $item->new_product_option_id;
        }

        $productOptionId = $item->orderOption?->product_option_id;

        return $productOptionId === null ? null : (int) $productOptionId;
    }

    /**
     * 후속 주문옵션을 만듭니다. (재발송 시작 시점, 멱등)
     *
     * 원 행의 금액 컬럼과 스냅샷 가격을 그대로 승계하고 상품옵션 정체만 바꾼다 — 클래스 docblock
     * "후속 주문옵션은 금액을 승계하고 정체만 바꾼다" 참조. 배송 행도 원 행의 정책을 복사해
     * 붙인다(후속 옵션에 대한 2차 클레임이 배송정책을 찾을 수 있어야 한다).
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  OrderExchangeOption  $item  신청 상품 행 (orderOption 로드됨)
     * @return void 반환값 없음
     */
    protected function ensureNewOrderOption(OrderExchange $exchange, OrderExchangeOption $item): void
    {
        if ($item->new_order_option_id !== null) {
            return;
        }

        $original = $item->orderOption;

        if (! $original) {
            return;
        }

        $target = $item->new_product_option_id !== null
            ? $this->productOptionRepository->findById((int) $item->new_product_option_id)
            : null;

        $successor = $original->replicate([
            'parent_option_id', 'cancelled_quantity', 'cancel_reason', 'confirmed_at', 'delivered_at',
            'external_option_id', 'external_meta',
        ]);

        $successor->option_status = OrderStatusEnum::SHIPPING;
        $successor->is_stock_deducted = true;
        $successor->source_type = OrderOptionSourceTypeEnum::EXCHANGE;
        $successor->source_option_id = $original->id;
        $successor->parent_option_id = null;
        $successor->cancelled_quantity = 0;
        $successor->quantity = (int) $item->exchange_quantity;

        if ($target) {
            $successor->product_option_id = $target->id;
            $successor->sku = $target->sku;
            $successor->product_option_name = $target->option_name ?? [];
            $successor->option_name = $target->option_name ?? [];
            $successor->option_value = $this->buildOptionValueSummary($target);
            $successor->unit_weight = $target->weight ?? 0;
            $successor->unit_volume = $target->volume ?? 0;
            $successor->subtotal_weight = ($target->weight ?? 0) * $successor->quantity;
            $successor->subtotal_volume = ($target->volume ?? 0) * $successor->quantity;

            // 스냅샷의 정체는 신규 옵션, 가격은 원 행 — 재계산이 원 금액을 재현해야 한다.
            $originalSnapshot = $original->option_snapshot ?? [];
            $successor->option_snapshot = array_merge($target->toSnapshotArray(), [
                'price_adjustment' => $originalSnapshot['price_adjustment'] ?? $target->price_adjustment,
                'list_price' => $originalSnapshot['list_price'] ?? $target->list_price,
                'selling_price' => $originalSnapshot['selling_price'] ?? $target->selling_price,
            ]);
        }

        $this->orderOptionRepository->save($successor);

        // 후속 주문상품이 `shipping` 으로 **새로 생긴다**(A6). 이 경로는 저장소에 직접 저장해
        // `OrderOptionService` 의 전이 훅을 지나지 않으므로, 훅을 붙이지 않으면 주문에 배송중
        // 상품이 하나 늘어난 사실이 활동로그 어디에도 남지 않는다 — 재발송이 언제 나갔는지
        // 되짚을 근거가 사라진다. 전이 전 상태는 없다(신규 행)이므로 null 로 싣는다.
        HookManager::doAction(
            'sirsoft-ecommerce.order_option.after_sync_status',
            [[
                'order_option_id' => (int) $successor->id,
                'order_id' => (int) $successor->order_id,
                'previous' => null,
                'new' => OrderStatusEnum::SHIPPING->value,
            ]],
            OrderStatusEnum::SHIPPING->value,
            'exchange_redelivery',
        );

        $this->replicateShippingRow($exchange, $original, $successor);

        $this->exchangeOptionRepository->update($item, ['new_order_option_id' => $successor->id]);
    }

    /**
     * 원 주문옵션의 배송 행을 후속 옵션에 복사합니다. (정책 승계 + 재발송 송장)
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  OrderOption  $original  원 주문옵션
     * @param  OrderOption  $successor  후속 주문옵션
     * @return void 반환값 없음
     */
    protected function replicateShippingRow(OrderExchange $exchange, OrderOption $original, OrderOption $successor): void
    {
        $shipping = $this->orderShippingRepository->findFirstByOrderOptionId($original->id);

        if (! $shipping) {
            return;
        }

        $this->orderShippingRepository->replicateForOrderOption($shipping, $successor->id, [
            'shipping_status' => ShippingStatusEnum::SHIPPED,
            'carrier_id' => $exchange->redelivery_carrier_id,
            'tracking_number' => $exchange->redelivery_tracking_number,
            'shipped_at' => now(),
        ]);
    }

    /**
     * 후속 주문옵션을 배송완료로 옮깁니다. (재발송도착)
     *
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @return void 반환값 없음
     */
    protected function markNewOrderOptionDelivered(OrderExchangeOption $item): void
    {
        $successor = $item->new_order_option_id !== null
            ? $this->orderOptionRepository->findOrFail((int) $item->new_order_option_id)
            : null;

        if (! $successor || $successor->option_status === OrderStatusEnum::DELIVERED) {
            return;
        }

        $this->orderOptionService->changeStatusWithQuantity(
            $successor,
            OrderStatusEnum::DELIVERED,
            (int) $successor->quantity,
            [],
            restoreStockOnCancel: false,
            mergeSiblings: false,
        );
    }

    /**
     * 상품옵션의 옵션값 요약을 만듭니다. (주문 생성 시점의 `option_value` 와 같은 형태)
     *
     * @param  ProductOption  $productOption  상품옵션
     * @return array 로케일 → "키: 값, …"
     */
    protected function buildOptionValueSummary(ProductOption $productOption): array
    {
        $values = $productOption->option_values;

        if (empty($values)) {
            return [];
        }

        if (isset($values[0]['key'])) {
            $locales = config('app.supported_locales', ['ko', 'en']);
            $fallback = config('app.fallback_locale', 'ko');
            $result = [];

            foreach ($locales as $locale) {
                $parts = [];

                foreach ($values as $entry) {
                    $key = $entry['key'] ?? [];
                    $value = $entry['value'] ?? [];

                    $localizedKey = is_array($key) ? ($key[$locale] ?? $key[$fallback] ?? array_values($key)[0] ?? '') : $key;
                    $localizedValue = is_array($value) ? ($value[$locale] ?? $value[$fallback] ?? array_values($value)[0] ?? '') : $value;

                    if ($localizedKey !== '' && $localizedValue !== '') {
                        $parts[] = $localizedKey.': '.$localizedValue;
                    }
                }

                $result[$locale] = implode(', ', $parts);
            }

            return $result;
        }

        $parts = [];

        foreach ($values as $key => $value) {
            $parts[] = $key.': '.$value;
        }

        return ['ko' => implode(', ', $parts)];
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 완료
    // ───────────────────────────────────────────────

    /**
     * 원 주문옵션을 교환완료(터미널)로 전이합니다.
     *
     * @param  OrderOption  $orderOption  원 주문옵션
     * @param  int  $quantity  완료 수량
     * @return void 반환값 없음
     */
    protected function markOrderOptionExchanged(OrderOption $orderOption, int $quantity): void
    {
        if ($orderOption->option_status === OrderStatusEnum::EXCHANGED) {
            return;
        }

        $this->orderOptionService->changeStatusWithQuantity(
            $orderOption,
            OrderStatusEnum::EXCHANGED,
            min($quantity, (int) $orderOption->quantity),
            [],
            restoreStockOnCancel: false,
            mergeSiblings: false,
        );
    }

    /**
     * 회수된 원 옵션의 재고를 복구합니다. (클레임 전용 경로 — 취소 행 플래그를 건드리지 않는다)
     *
     * @param  OrderOption  $orderOption  원 주문옵션
     * @param  int  $quantity  복구 수량
     * @return void 반환값 없음
     */
    protected function restoreStockForItem(OrderOption $orderOption, int $quantity): void
    {
        $this->stockService->restoreOptionStockForClaim($orderOption, $quantity);
        $this->orderOptionRepository->update($orderOption, ['is_stock_deducted' => false]);
    }

    /**
     * 환불신청서 접수 시 실릴 금액 구성을 계산합니다. (접수·미리보기 공용 산식)
     *
     * 접수액은 신청 시점에 동결한 순액(`refund_product_amount`)을 넘지 못한다. 고가·저가가 섞인
     * 신청서는 차액을 **합계로 상계**해(D29) 청구·환불 중 한쪽 컬럼만 채우므로, 상품별 음수 차액만
     * 더하면 상계로 사라진 양수 차액만큼 더 나간다 — 순추가 신청서(동결 순액 0)는 고객이 이미
     * 그 차액을 청구받았으므로 접수 자체가 성립하지 않는다. 앞선 라운드가 접수한 몫을 빼서
     * 라운드 합계도 순액을 넘지 않게 한다.
     *
     * @param  Order  $order  주문
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array<int, OrderExchangeOption>  $eligible  접수 대상 상품 행
     * @param  Collection<int, OrderRefund>  $liveRefunds  살아 있는 환불신청서 목록
     * @return array{raw_refundable: float, refundable: float, deducted_fee: float, refund_amount: float, pg_refund: float, points_refund: float}|null 구성 (환불액이 0 이하면 null — 다음 라운드 합산 대상)
     */
    protected function composeRefundRequestAmounts(
        Order $order,
        OrderExchange $exchange,
        array $eligible,
        Collection $liveRefunds,
    ): ?array {
        $rawRefundable = 0.0;

        foreach ($eligible as $item) {
            $rawRefundable += -(float) $item->price_difference;
        }

        $netRemaining = max(0.0, (float) $exchange->refund_product_amount - $this->filedRefundTotal($liveRefunds));
        $refundable = min($rawRefundable, $netRemaining);

        $feeRemaining = $exchange->charge_method === ClaimChargeMethodEnum::DEDUCT_FROM_REFUND
            ? max(0.0, (float) $exchange->claim_shipping_fee - $this->feeAlreadyDeducted($liveRefunds))
            : 0.0;
        $deductedFee = min($feeRemaining, $refundable);
        $refundAmount = $refundable - $deductedFee;

        if ($refundAmount <= 0) {
            return null;
        }

        [$pgRefund, $pointsRefund] = $this->splitRefundByPaymentCapacity($order, $refundAmount);

        return [
            'raw_refundable' => $rawRefundable,
            'refundable' => $refundable,
            'deducted_fee' => $deductedFee,
            'refund_amount' => $refundAmount,
            'pg_refund' => $pgRefund,
            'points_refund' => $pointsRefund,
        ];
    }

    /**
     * 완료 처리·수동 접수 전에 환불신청서에 실릴 금액을 미리 계산합니다. (완료 모달 미리보기 — DB 변경 없음)
     *
     * `$assumeCompletedItemIds` 는 아직 완료되지 않았지만 이번 완료 라운드에 포함될 상품 행이다 —
     * 모달은 실행 전에 "지금 접수하면 얼마가 실리는가" 를 보여야 하므로 그 상품들이 완료됐다고
     * 가정하고 접수 산식(`composeRefundRequestAmounts`)을 그대로 돌린다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array<int, int>  $assumeCompletedItemIds  완료됐다고 가정할 신청 상품 행 ID 목록
     * @return array{refundable: bool, blocked_reason: string|null, refund_amount: float, refund_points_amount: float, deducted_shipping_fee: float} 미리보기 결과
     */
    public function previewRefundRequest(OrderExchange $exchange, array $assumeCompletedItemIds = []): array
    {
        $exchange->loadMissing(['exchangeOptions', 'order']);
        $order = $exchange->order;

        $liveRefunds = $this->liveRefundRequests($exchange);
        $eligible = $this->refundableItemsNotYetRequested($exchange, $liveRefunds, $assumeCompletedItemIds);

        $blocked = fn (string $reason) => [
            'refundable' => false,
            'blocked_reason' => $reason,
            'refund_amount' => 0.0,
            'refund_points_amount' => 0.0,
            'deducted_shipping_fee' => 0.0,
        ];

        if ($order === null) {
            return $blocked('claim_refund_not_applicable');
        }

        if ($eligible === []) {
            // 접수·수동 접수 경로의 두 거절 사유와 같은 구분 — 화면이 구분해 안내한다 (S5 인계 9).
            return $blocked($liveRefunds->isNotEmpty() ? 'claim_refund_request_exists' : 'claim_refund_not_applicable');
        }

        $composition = $this->composeRefundRequestAmounts($order, $exchange, $eligible, $liveRefunds);

        if ($composition === null) {
            return $blocked('claim_refund_not_applicable');
        }

        return [
            'refundable' => true,
            'blocked_reason' => null,
            'refund_amount' => $composition['pg_refund'],
            'refund_points_amount' => $composition['points_refund'],
            'deducted_shipping_fee' => $composition['deducted_fee'],
        ];
    }

    /**
     * 이 교환에서 접수된 환불신청서 중 살아 있는 것(반려·철회 제외)을 반환합니다.
     *
     * 교환은 `refund_id` 역참조가 없어 발생 출처 축(`refund_source_type=exchange`)으로 되짚는다.
     * 환불 완료된 신청서도 "살아 있다" — 그 상품은 이미 환불됐으므로 다시 접수하면 안 된다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @return Collection<int, OrderRefund> 환불신청서 목록 (refundOptions 로드됨, ID 오름차순)
     */
    protected function liveRefundRequests(OrderExchange $exchange): Collection
    {
        return $this->refundRepository
            ->listBySource(RefundSourceTypeEnum::EXCHANGE->value, $exchange->id, ['refundOptions'])
            ->reject(fn (OrderRefund $refund) => in_array(
                $refund->refund_status,
                [RefundStatusEnum::REJECTED, RefundStatusEnum::WITHDRAWN],
                true,
            ))
            ->values();
    }

    /**
     * 완료됐지만 아직 살아 있는 환불신청서에 담기지 않은 저가 상품 행을 반환합니다.
     *
     * `$assumeCompletedItemIds` 가 있으면 그 상품 행이 완료됐다고 가정하고 후보에 합친다 —
     * 완료 모달 미리보기(`previewRefundRequest`)가 실행 전에 "지금 접수하면 무엇이 실리는가" 를
     * 물을 때만 쓰며, 완료 가능 판정(전이 게이트·비종결)은 완료 경로와 같은 조건이다.
     *
     * @param  OrderExchange  $exchange  교환 신청서 (exchangeOptions 로드됨)
     * @param  Collection<int, OrderRefund>  $liveRefunds  살아 있는 환불신청서 목록
     * @param  array<int, int>  $assumeCompletedItemIds  완료됐다고 가정할 신청 상품 행 ID 목록
     * @return array<int, OrderExchangeOption> 접수 대상 상품 행
     */
    protected function refundableItemsNotYetRequested(
        OrderExchange $exchange,
        Collection $liveRefunds,
        array $assumeCompletedItemIds = [],
    ): array {
        $requestedOptionIds = $liveRefunds
            ->flatMap(fn (OrderRefund $refund) => $refund->refundOptions->pluck('order_option_id'))
            ->map(fn ($id) => (int) $id)
            ->all();

        $candidates = $this->completedItems($exchange);

        if ($assumeCompletedItemIds !== []) {
            $assumeIds = array_map('intval', $assumeCompletedItemIds);

            foreach ($exchange->exchangeOptions as $item) {
                if (in_array((int) $item->id, $assumeIds, true)
                    && $item->option_status !== ExchangeStatusEnum::COMPLETED
                    && $item->option_status->canTransitionTo(ExchangeStatusEnum::COMPLETED)
                    && ! $item->option_status->isFinal()) {
                    $candidates[] = $item;
                }
            }
        }

        return array_values(array_filter(
            $candidates,
            fn (OrderExchangeOption $item) => (float) $item->price_difference < 0
                && ! in_array((int) $item->order_option_id, $requestedOptionIds, true),
        ));
    }

    /**
     * 살아 있는 환불신청서들이 이미 차감한 왕복 배송비 합계를 반환합니다.
     *
     * @param  Collection<int, OrderRefund>  $liveRefunds  살아 있는 환불신청서 목록
     * @return float 이미 차감된 배송비
     */
    protected function feeAlreadyDeducted(Collection $liveRefunds): float
    {
        return (float) $liveRefunds->sum(fn (OrderRefund $refund) => max(0.0, -(float) $refund->refund_shipping_amount));
    }

    /**
     * 살아 있는 환불신청서들이 이미 접수한 차액 총액을 반환합니다.
     *
     * 교환 헤더의 `refund_product_amount` 와 **같은 단위**(배송비 차감 전 총액)로 돌려준다 —
     * 환불신청서의 `refund_amount` 는 차감 배송비를 뺀 순액이므로 그 몫을 다시 더한다.
     *
     * @param  Collection<int, OrderRefund>  $liveRefunds  살아 있는 환불신청서 목록
     * @return float 이미 접수된 차액 총액
     */
    protected function filedRefundTotal(Collection $liveRefunds): float
    {
        $net = (float) $liveRefunds->sum(
            fn (OrderRefund $refund) => (float) $refund->refund_amount + (float) $refund->refund_points_amount
        );

        return $net + $this->feeAlreadyDeducted($liveRefunds);
    }

    /**
     * 상계로 줄어든 접수액을 신청 상품 행에 안분합니다. (합계 보존)
     *
     * 상계가 없으면(접수액 = 음수 차액 합) 상품별 차액을 그대로 싣는다. 상계가 있으면 차액 비율로
     * 나누고 잔여를 마지막 행에 몰아 **행 합계가 신청서 금액과 정확히 같게** 한다 — 어긋나면
     * 관리자 화면의 상품별 내역 합과 환불 금액이 달라진다.
     *
     * @param  array<int, OrderExchangeOption>  $items  접수 대상 상품 행 (0부터 연속 인덱스)
     * @param  float  $refundable  안분할 접수액
     * @param  float  $rawRefundable  상계 전 음수 차액 합
     * @return array<int, float> 인덱스 => 상품별 접수액
     */
    protected function allocateItemRefunds(array $items, float $refundable, float $rawRefundable): array
    {
        if ($rawRefundable <= 0) {
            return array_fill(0, count($items), 0.0);
        }

        if ($refundable >= $rawRefundable) {
            return array_map(fn (OrderExchangeOption $item) => -(float) $item->price_difference, $items);
        }

        $allocated = [];
        $assigned = 0.0;
        $last = count($items) - 1;

        foreach ($items as $index => $item) {
            if ($index === $last) {
                $allocated[$index] = round($refundable - $assigned, 2);

                break;
            }

            $share = round($refundable * (-(float) $item->price_difference) / $rawRefundable, 2);
            $allocated[$index] = $share;
            $assigned += $share;
        }

        return $allocated;
    }

    /**
     * 저가 교환의 차액 환불신청서를 생성합니다. (완료 라운드마다 1건 — 클래스 docblock 참조)
     *
     * 금액은 **신청 시점에 동결한 차액**에서 온다 — 아직 접수되지 않은 완료 상품의 음수 차액 합에서,
     * 청구 방식이 '환불금에서 차감' 이면 왕복 배송비 중 아직 차감되지 않은 몫을 뺀다(신청서 전체에서
     * 한 번). 완료 시점에는 원 옵션이 `EXCHANGED` 로 닫히고 후속 옵션이 생겨 있어 `ExchangeAdjustment`
     * 로 재계산하면 차액이 두 번 반영된다.
     *
     * 그 합은 신청서가 동결한 순액(`refund_product_amount`)을 **상한으로 갖는다** — 고가·저가가
     * 섞인 신청서는 차액을 합계로 상계하므로(D29) 음수 차액만 더하면 상계로 사라진 양수 차액만큼
     * 더 나간다. 상한을 넘는 몫은 상품 행에도 차액 비율로 안분한다(`allocateItemRefunds`).
     *
     * 호출부는 주문 행을 잠근 뒤 부른다 — 같은 상품의 이중 접수를 막는 직렬화 지점이다.
     *
     * @param  Order  $order  주문
     * @param  OrderExchange  $exchange  교환 신청서 (exchangeOptions 로드됨)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderRefund 생성된 환불 신청서
     *
     * @throws OrderClaimException 접수할 상품이 전부 이미 접수됨 / 환불 대상이 없을 때
     */
    protected function createRefundRequest(Order $order, OrderExchange $exchange, ?int $processedBy): OrderRefund
    {
        $liveRefunds = $this->liveRefundRequests($exchange);
        $completed = $this->refundableItemsNotYetRequested($exchange, $liveRefunds);

        if ($completed === []) {
            $latest = $liveRefunds->last();

            throw $latest !== null
                ? OrderClaimException::refundRequestAlreadyExists($latest->refund_number ?? (string) $latest->id)
                : OrderClaimException::refundNotApplicable();
        }

        $composition = $this->composeRefundRequestAmounts($order, $exchange, $completed, $liveRefunds);

        // 이번 라운드 차액이 남은 배송비에 못 미치면 접수하지 않는다 — 그 상품은 접수되지 않은 채로
        // 남아 다음 라운드에 합산되고, 배송비는 그때 한 번에 차감된다.
        if ($composition === null) {
            throw OrderClaimException::refundNotApplicable();
        }

        [
            'raw_refundable' => $rawRefundable,
            'refundable' => $refundable,
            'deducted_fee' => $deductedFee,
            'pg_refund' => $pgRefund,
            'points_refund' => $pointsRefund,
        ] = $composition;

        $currencySnapshot = $order->currency_snapshot ?? [];

        $refund = $this->refundRepository->create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::EXCHANGE,
            'refund_source_id' => $exchange->id,
            // 구매자가 교환 신청서에 적어 낸 계좌를 환불 레코드가 이어받는다 - 저가 교환의 차액
            // 환불이 실제로 나갈 때 이 값으로 결제행을 갱신한다.
            'refund_bank_code' => $exchange->refund_bank_code,
            'refund_bank_account' => $exchange->refund_bank_account,
            'refund_bank_holder' => $exchange->refund_bank_holder,
            'refund_number' => $this->sequenceService->generateCode(SequenceType::REFUND),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => $this->refundExecutionService->determineRefundMethod($order),
            'reason_code' => $exchange->reason_code,
            'reason_detail' => $exchange->reason_detail,
            'fault_type' => $exchange->fault_type,
            'requested_by' => $exchange->requested_by,
            'refund_amount' => $pgRefund,
            'refund_points_amount' => $pointsRefund,
            'refund_shipping_amount' => -$deductedFee,
            'mc_refund_amount' => $this->toMultiCurrency($pgRefund, $currencySnapshot),
            'mc_refund_points_amount' => $pointsRefund > 0 ? $this->toMultiCurrency($pointsRefund, $currencySnapshot) : null,
            'mc_refund_shipping_amount' => $deductedFee > 0 ? $this->toMultiCurrency(-$deductedFee, $currencySnapshot) : null,
            'original_calculation_snapshot' => [
                'exchange_id' => $exchange->id,
                'exchange_number' => $exchange->exchange_number,
                'refund_product_amount' => $refundable,
                'offset_by_higher_priced_items' => $rawRefundable - $refundable,
                'deducted_shipping_fee' => $deductedFee,
                'exchange_option_ids' => array_map(fn (OrderExchangeOption $item) => $item->id, $completed),
            ],
            'recalculated_snapshot' => null,
            'processed_by' => $processedBy,
        ]);

        $itemRefunds = $this->allocateItemRefunds($completed, $refundable, $rawRefundable);

        foreach ($completed as $index => $item) {
            $itemRefund = $itemRefunds[$index];

            $this->refundOptionRepository->create([
                'order_refund_id' => $refund->id,
                'order_id' => $order->id,
                'order_option_id' => $item->order_option_id,
                'option_status' => RefundOptionStatusEnum::REQUESTED,
                'quantity' => (int) $item->exchange_quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal_amount' => (float) $item->unit_price * (int) $item->exchange_quantity,
                'discount_amount' => 0,
                'refund_amount' => $itemRefund,
            ]);
        }

        // 반품·교환 발 환불신청서도 접수 훅을 쏜다(B8·B9). 예전에는 `OrderRefundRequestService::request()`
        // 한 곳만 발행해, 완료 처리가 만든 환불신청서는 **어떤 이력도 없이** 목록에 나타났다 —
        // 운영자는 그 신청서가 언제 어떤 경로로 생겼는지 되짚을 수 없었다.
        HookManager::doAction('sirsoft-ecommerce.claim.refund.requested', $refund->fresh(), $order->fresh());

        // 신청상품 행 생성도 상품 축에 남긴다(§7-1 C11) — 헤더 줄만 남기면 그 환불이 어떤
        // 상품 몇 개를 담고 생겼는지 이력에서 되짚을 수 없다.
        app(OrderRefundRequestService::class)->announceRefundOptionsCreated($refund->fresh());

        return $refund->fresh();
    }

    /**
     * 환불액을 주문이 실제로 낸 돈에 맞춰 PG 환불액과 마일리지 환불액으로 나눕니다.
     *
     * `OrderAdjustmentService::calculate()` 의 배분 규칙(PG 우선 — 결제수단 잔여 한도를 먼저 채우고
     * 남는 몫을 마일리지 잔여 한도에서)과 같다. 반품의 환불신청서가 그 계산 결과를 싣는 것과 같은
     * 분할을 **동결 차액**에 적용한다 — 전액 마일리지 결제 주문의 차액을 PG 칸에 실으면 환불 수단은
     * 마일리지인데 금액은 PG 환불액인 어긋난 신청서가 된다. 두 잔여 한도가 환불액에 못 미치는 몫은
     * PG 칸에 남긴다 — 잘라내면 헤더와 상품 행 합계가 어긋나고, PG 칸이 운영자가 수정하는 자리다(D18).
     *
     * @param  Order  $order  주문
     * @param  float  $refundAmount  나눌 환불액 (차감 배송비 제외)
     * @return array{0: float, 1: float} [PG 환불액, 마일리지 환불액]
     */
    protected function splitRefundByPaymentCapacity(Order $order, float $refundAmount): array
    {
        $remainingPg = max(0.0, (float) $order->total_paid_amount - (float) $order->total_refunded_amount);
        $remainingPoints = max(0.0, (float) $order->total_points_used_amount - (float) $order->total_refunded_points_amount);

        $pg = min($refundAmount, $remainingPg);
        $points = min($refundAmount - $pg, $remainingPoints);
        $pg += $refundAmount - $pg - $points;

        return [$pg, $points];
    }

    /**
     * 완료된 신청 상품 행을 반환합니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @return array<int, OrderExchangeOption> 완료된 상품 행
     */
    protected function completedItems(OrderExchange $exchange): array
    {
        return $exchange->exchangeOptions
            ->filter(fn (OrderExchangeOption $item) => $item->option_status === ExchangeStatusEnum::COMPLETED)
            ->values()
            ->all();
    }

    /**
     * 모든 신청 상품이 완료됐는지 반환합니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @return bool 전건 완료면 true
     */
    protected function allItemsCompleted(OrderExchange $exchange): bool
    {
        return $exchange->exchangeOptions->isNotEmpty()
            && $exchange->exchangeOptions->every(
                fn (OrderExchangeOption $item) => $item->option_status === ExchangeStatusEnum::COMPLETED
            );
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 금액 / 아이템 정규화
    // ───────────────────────────────────────────────

    /**
     * 신청 계획의 옵션 차액 합계를 반환합니다. (부호 유지)
     *
     * @param  array  $plans  신청 계획
     * @return float 차액 합계
     */
    protected function sumPriceDifference(array $plans): float
    {
        $sum = 0.0;

        foreach ($plans as $plan) {
            $sum += (float) $plan['price_difference'];
        }

        return $sum;
    }

    /**
     * 차액 합계를 추가 청구분과 환불분으로 나눕니다.
     *
     * 한 신청서에 고가·저가 상품이 섞이면 **합계**로 상계한다(D29 "선택 상품별 합계") —
     * 상품별로 따로 청구·환불하면 고객이 같은 신청서에서 내고 돌려받는다.
     *
     * @param  float  $priceDifference  차액 합계
     * @return array{0: float, 1: float} [추가 청구 차액, 환불 차액]
     */
    protected function splitPriceDifference(float $priceDifference): array
    {
        return $priceDifference >= 0
            ? [$priceDifference, 0.0]
            : [0.0, -$priceDifference];
    }

    /**
     * 신청 계획을 계산기 공용 아이템 형태로 변환합니다.
     *
     * `cancel_quantity` 키는 `OrderAdjustmentService` / `ClaimShippingFeeService` 가 공유하는 형태다 —
     * 클레임 전용 키를 새로 만들면 두 계산기의 안분·조건부무료 재판정 경로를 다시 뚫어야 한다.
     *
     * @param  array  $plans  신청 계획
     * @return array [{order_option_id, cancel_quantity}]
     */
    protected function toClaimItemsFromPlans(array $plans): array
    {
        $normalized = [];

        foreach ($plans as $plan) {
            $normalized[] = [
                'order_option_id' => $plan['option']->id,
                'cancel_quantity' => $plan['quantity'],
            ];
        }

        return $normalized;
    }

    /**
     * 신청 계획을 재계산 엔진의 옵션 치환 입력으로 바꿉니다. ("교환 후" 열의 표시용 2-패스)
     *
     * 옵션을 바꾸는 교환은 신규 옵션 스냅샷(정가·조정액·적립 규칙·옵션값 요약)을, 같은 옵션에서
     * 커스텀·추가옵션만 바꾸는 교환은 **원 옵션 스냅샷 위에** 커스텀 입력·추가옵션 스냅샷을 얹는다 —
     * 신규 옵션 스냅샷만 넘기면 후자에서 적립 규칙·정가가 비어 기본값으로 떨어진다.
     * 단가는 차액 산식이 쓰는 `new_unit_price` 그대로다.
     *
     * @param  array  $plans  신청 계획 (`planItems()`)
     * @return array<int, array{quantity: int, product_option_id: int|null, unit_price: int, option_snapshot: array, additional_options_snapshot: array}> 주문옵션 ID → 치환 입력
     */
    protected function buildSubstitutionsFromPlans(array $plans): array
    {
        $substitutions = [];

        foreach ($plans as $plan) {
            $option = $plan['option'];
            $newSnapshot = $this->buildNewOptionSnapshot($plan) ?? [];

            $substitutions[(int) $option->id] = [
                'quantity' => (int) $plan['quantity'],
                'product_option_id' => $plan['is_option_change'] ? (int) $plan['target']->id : null,
                'unit_price' => (int) round((float) $plan['new_unit_price']),
                'option_snapshot' => $plan['is_option_change']
                    ? $newSnapshot
                    : array_merge($option->option_snapshot ?? [], $newSnapshot),
                'additional_options_snapshot' => $plan['new_additional_options_snapshot'] ?? [],
            ];
        }

        return $substitutions;
    }

    /**
     * 접수된 신청 상품 행에서 옵션 치환 입력을 복원합니다. (수정 화면의 스냅샷 갱신용)
     *
     * 접수 뒤에는 계획 배열이 없고 `order_exchange_options` 행이 그 사실을 들고 있다 —
     * `buildSubstitutionsFromPlans()` 와 같은 형태로 되돌린다.
     *
     * @param  OrderExchange  $exchange  교환 신청서 (`exchangeOptions` 로드)
     * @param  Order  $order  주문 (`options` 로드)
     * @return array<int, array> 주문옵션 ID → 치환 입력
     */
    protected function buildSubstitutionsFromExchangeOptions(OrderExchange $exchange, Order $order): array
    {
        $substitutions = [];

        foreach ($exchange->exchangeOptions as $item) {
            $orderOption = $order->options->find($item->order_option_id);
            $newSnapshot = is_array($item->new_option_snapshot) ? $item->new_option_snapshot : [];

            $substitutions[(int) $item->order_option_id] = [
                'quantity' => (int) $item->exchange_quantity,
                'product_option_id' => $item->new_product_option_id ? (int) $item->new_product_option_id : null,
                'unit_price' => (int) round((float) $item->new_unit_price),
                'option_snapshot' => $item->new_product_option_id
                    ? $newSnapshot
                    : array_merge($orderOption?->option_snapshot ?? [], $newSnapshot),
                'additional_options_snapshot' => $newSnapshot['additional_options_snapshot'] ?? [],
            ];
        }

        return $substitutions;
    }

    /**
     * 접수 시점에 동결할 주문금액 비교 스냅샷 컬럼을 만듭니다.
     *
     * 치환 근거 행(`substituted_items`)은 컬럼을 늘리지 않고 재계산 스냅샷 안에 `_substituted_items` 로
     * 함께 넣는다 — `ClaimResource` 가 꺼내 `calculation_comparison.substituted_items` 로 내보낸다.
     *
     * @param  AdjustmentResult  $result  접수 재료로 계산한 결과
     * @return array{original_calculation_snapshot: array, recalculated_snapshot: array} 저장 컬럼
     */
    protected function frozenCalculationSnapshots(AdjustmentResult $result): array
    {
        return [
            'original_calculation_snapshot' => $result->originalSnapshot,
            'recalculated_snapshot' => $result->recalculatedSnapshot + ['_substituted_items' => $result->substitutedItems],
        ];
    }

    /**
     * 승인 전 신청 내용을 수정합니다. (D13 사유·상세 사유 / D14 재발송지)
     *
     * 수정 가능 경계는 철회 경계와 같다(신청·결제대기·보류). 사유(귀책)나 재발송지(우편번호·국가)가 바뀌면
     * 왕복 배송비를 접수와 같은 산식으로 재산정해 청구액을 갱신한다 — 옵션 차액은 상품이 불변이라 그대로다.
     * 청구액이 0 에서 양수가 되면 청구 방식이 필요하므로 페이로드의 `charge_method` 를 함께 받는다.
     *
     * 이미 받은 추가결제와의 **차액 정산은 여기서 하지 않는다** — 호출부가 갱신된 신청서로
     * `ClaimPaymentService::settleChargeDifference()` 를 부른다(결제 서비스가 이 서비스를 전이에 쓰므로
     * 역방향 의존을 두지 않는다).
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array  $payload  수정 페이로드 (reason_code? / reason_detail? / redelivery_address? / charge_method?)
     * @return OrderExchange 수정된 신청서
     *
     * @throws OrderClaimException 수정 불가 상태 / 사유 부재 / 국가 변경 / 청구 방식 부재·불허·차감 불가
     */
    public function updateRequest(OrderExchange $exchange, array $payload): OrderExchange
    {
        if (! $exchange->exchange_status->isWithdrawable()) {
            throw OrderClaimException::notEditable();
        }

        return DB::transaction(function () use ($exchange, $payload) {
            $locked = $this->exchangeRepository->findForUpdate($exchange->id) ?? $exchange;
            $locked->load(['exchangeOptions', 'order.options', 'order.shippings', 'order.payment', 'order.shippingAddress']);
            $order = $locked->order;

            $updates = [];
            $reason = null;

            if (($payload['reason_code'] ?? null) !== null && $payload['reason_code'] !== '' && $payload['reason_code'] !== $locked->reason_code) {
                $reason = $this->resolveReason((string) $payload['reason_code']);
                $updates['reason_code'] = $reason->code;
                $updates['fault_type'] = $reason->fault_type;
            }

            if (array_key_exists('reason_detail', $payload)) {
                $updates['reason_detail'] = $payload['reason_detail'];
            }

            // 환불 계좌 — D13 이 여는 축이다(2026-09-01 확정: 수정 화면에서 편집 가능,
            // 단 수정이 허용되는 신청 단계에서만 — 이 메서드는 그 단계에서만 진입한다).
            // 값이 세 개 다 찬 경우에만 페이로드에 실리므로(UpdateClaimRequest), 손대지 않은
            // 저장이 기록된 계좌를 지우지 않는다. 실제 송금 대상(주문 결제행)은 여기서 바꾸지
            // 않는다 — 그 갱신은 환불을 실행하는 시점의 책임이다(AppliesRefundBankAccount).
            $updates += $this->refundBankColumns($payload['refund_bank'] ?? null);

            $redelivery = null;

            if (! empty($payload['redelivery_address']) && is_array($payload['redelivery_address'])) {
                $redelivery = $this->buildRedeliveryAddressSnapshot(
                    $order,
                    $payload['redelivery_address'],
                    $locked->redelivery_address_snapshot,
                );
                $this->assertRedeliveryCountryUnchanged($order, $redelivery);
                $updates['redelivery_address_snapshot'] = $redelivery;
            }

            $chargeMethod = array_key_exists('charge_method', $payload)
                ? $this->normalizeChargeMethod($payload['charge_method'])
                : $locked->charge_method;

            if ($reason !== null || $redelivery !== null) {
                $claimItems = [];

                foreach ($locked->exchangeOptions as $item) {
                    $claimItems[] = [
                        'order_option_id' => (int) $item->order_option_id,
                        'cancel_quantity' => (int) $item->exchange_quantity,
                    ];
                }

                $shippingFee = $this->claimShippingFeeService->calculate(
                    $order,
                    ClaimTypeEnum::EXCHANGE,
                    $claimItems,
                    $reason?->fault_type ?? $locked->fault_type,
                    $this->toFeeLegAddress($redelivery ?? $locked->redelivery_address_snapshot),
                );

                // 옵션 차액은 상품이 불변이라 접수값 그대로다 — 부호는 한쪽 컬럼만 채워져 있어 차로 복원된다.
                $priceDifference = (float) $locked->additional_product_amount - (float) $locked->refund_product_amount;
                $totalCharge = $shippingFee->fee + (float) $locked->additional_product_amount;

                $this->assertChargeMethodUsable(
                    $order,
                    $claimItems,
                    $shippingFee,
                    $priceDifference,
                    $totalCharge,
                    $chargeMethod,
                    RefundPriorityEnum::PG_FIRST,
                );

                $currencySnapshot = $order->currency_snapshot ?? [];

                // 배송비가 다시 계산됐으므로 접수 시점 비교 스냅샷도 같은 재료로 갱신한다(치환은 신청 상품 행에서 복원).
                $updates += $this->frozenCalculationSnapshots($this->adjustmentService->preview(
                    $order,
                    ExchangeAdjustment::fromShippingFee(
                        $claimItems,
                        $shippingFee,
                        (int) round($priceDifference),
                        $chargeMethod,
                        $this->buildSubstitutionsFromExchangeOptions($locked, $order),
                    ),
                    RefundPriorityEnum::PG_FIRST,
                ));

                $updates['shipping_fee_mode'] = $shippingFee->mode;
                $updates['claim_shipping_fee'] = $shippingFee->fee;
                $updates['mc_claim_shipping_fee'] = $shippingFee->mcFee ?: null;
                $updates['shipping_fee_snapshot'] = $shippingFee->toArray();
                $updates['total_charge_amount'] = $totalCharge;
                $updates['mc_total_charge_amount'] = $this->toMultiCurrency($totalCharge, $currencySnapshot);
                $updates['charge_method'] = $totalCharge > 0 ? $chargeMethod : null;
            } elseif (array_key_exists('charge_method', $payload) && (float) $locked->total_charge_amount > 0) {
                $this->assertChargeMethodAllowed($chargeMethod);
                $updates['charge_method'] = $chargeMethod;
            }

            if ($updates === []) {
                return $locked;
            }

            return $this->exchangeRepository->update($locked, $updates)->fresh(['exchangeOptions']);
        });
    }

    /**
     * 청구 방식이 존재하고 운영자 허용 목록 안에 있는지만 검증합니다. (차감 성립성 제외)
     *
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 부재·불허
     */
    protected function assertChargeMethodAllowed(?ClaimChargeMethodEnum $chargeMethod): void
    {
        if ($chargeMethod === null) {
            throw OrderClaimException::chargeMethodRequired();
        }

        $allowed = (array) $this->settingsService->getSetting(
            'claim.allowed_charge_methods',
            ClaimChargeMethodEnum::values(),
        );

        if (! in_array($chargeMethod->value, $allowed, true)) {
            throw OrderClaimException::chargeMethodNotAllowed();
        }
    }
}
