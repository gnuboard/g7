<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use BackedEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\DTO\ExchangeAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimItemCollectionStateEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
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
use Modules\Sirsoft\Ecommerce\Services\Concerns\FiresClaimHooksAfterCommit;
use Modules\Sirsoft\Ecommerce\Services\Concerns\GuardsClaimFilterResults;
use Modules\Sirsoft\Ecommerce\Services\Concerns\RecordsClaimTracking;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ReportsClaimRequestUpdate;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ResolvesAdditionalOptions;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemCollectionState;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemSpecs;
use Modules\Sirsoft\Ecommerce\Support\ClaimPhysicalGate;
use Modules\Sirsoft\Ecommerce\Support\ClaimTrackingGroups;
use Modules\Sirsoft\Ecommerce\Support\SystemActor;

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
    use FiresClaimHooksAfterCommit;
    use GuardsClaimFilterResults;
    use RecordsClaimTracking;
    use ReportsClaimRequestUpdate;
    use ResolvesAdditionalOptions;

    /** 선교환 헌 물건 반송 기한 기본 일수 (설정 기본값 `claim.advance_exchange_collection_due_days` 와 같다) */
    public const ADVANCE_EXCHANGE_DEFAULT_DUE_DAYS = 14;

    /**
     * 이번 요청에서 전이와 함께 쓴 행 송장 변경 (방향 값 → 변경 목록)
     *
     * 상품마다 저장점 안에서 쓰므로 모아 두었다가 커밋 뒤 `after_tracking_update` 로 한 번 알린다.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $trackingChangeLog = [];

    /**
     * @param  OrderAdjustmentService  $adjustmentService  금액 재계산 서비스 (미리보기·청구 방식 검증)
     * @param  ClaimShippingFeeService  $claimShippingFeeService  클레임 배송비 산정 서비스
     * @param  ClaimQuantityGuard  $claimQuantityGuard  클레임 수량 잠금 가드 (D15)
     * @param  ClaimItemSplitter  $claimItemSplitter  신청 상품 수량 분할기
     * @param  OrderOptionService  $orderOptionService  주문 옵션 서비스 (코스 상태 전이·수량 분할)
     * @param  RefundExecutionService  $refundExecutionService  환불 실행 서비스 (환불수단 판정)
     * @param  UserMileageService  $mileageService  마일리지 서비스 (적립 회수)
     * @param  PurchaseEligibilityService  $purchaseEligibility  구매 자격 판정 서비스 (교환 대상 상품의 구매등급 제한)
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
        protected ClaimItemSplitter $claimItemSplitter,
        protected OrderOptionService $orderOptionService,
        protected RefundExecutionService $refundExecutionService,
        protected UserMileageService $mileageService,
        protected PurchaseEligibilityService $purchaseEligibility,
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
     * @param  bool  $operator  운영자 직권 접수 미리보기 여부 (D5 — 기간·신청가능상태 대신 물리 게이트, 판매자 귀책 사유 허용)
     * @param  float|null  $shippingFeeOverride  운영자가 정한 클레임 배송비 (D7 — null 이면 산정값)
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
        bool $operator = false,
        ?float $shippingFeeOverride = null,
    ): array {
        $reason = $this->resolveReason($reasonCode, ! $operator);
        $plans = $this->planItems($this->resolvePreviewItems($order, $items, $excludeExchangeId, $operator), $items);
        $claimItems = $this->toClaimItemsFromPlans($plans);

        $shippingFee = $this->applyShippingFeeOverride($this->claimShippingFeeService->calculate(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $claimItems,
            $reason->fault_type,
            $this->toFeeLegAddress($redeliveryAddress),
        ), $shippingFeeOverride, $order->currency_snapshot ?? []);

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
     *                          - redelivery_address: 재발송지 (선택, D14) {recipient_name, phone, zipcode, address1, address2, city, state, country_code, changed_by_user}
     *                          - operator: bool (선택 — 운영자 직권 접수. 기간·신청가능상태 대신 물리 게이트, 판매자 귀책 사유 허용 — D5)
     *                          - requested_by_admin: 대리 접수 관리자 ID (선택 — 값이 있으면 판매자 접수, 고객 철회·수정 불가 — D8)
     *                          - shipping_fee_override: 운영자가 정한 클레임 배송비 (선택 — D7)
     * @return OrderExchange 생성된 교환 신청서
     *
     * 쓰기 전에 `claim.exchange.before_request`(막기) → `claim.exchange.filter_request_data`(입력 변형)를
     * 동기로 발화한다. 필터는 `CLAIM_FILTERABLE_REQUEST_KEYS`(대상 상품·사유·재발송지)만 바꿀 수 있고,
     * 바뀐 값은 아래의 사유 해석·잠금 안 수량 가드·재발송지 규칙을 고객 입력과 똑같이 다시 지난다.
     *
     * @throws OrderClaimException 신청 자격·수량·사유·청구 방식·신규 옵션·재발송지 위반
     */
    public function requestExchange(Order $order, array $payload): OrderExchange
    {
        HookManager::doAction('sirsoft-ecommerce.claim.exchange.before_request', $order, $payload);

        $payload = $this->acceptClaimFilterResult(
            HookManager::applyFilters('sirsoft-ecommerce.claim.exchange.filter_request_data', $payload, $order),
            $payload,
            self::CLAIM_FILTERABLE_REQUEST_KEYS,
        );

        $operator = (bool) ($payload['operator'] ?? false);
        $reason = $this->resolveReason((string) ($payload['reason_code'] ?? ''), ! $operator);
        $chargeMethod = $this->normalizeChargeMethod($payload['charge_method'] ?? null);
        $refundPriority = $payload['refund_priority'] ?? RefundPriorityEnum::PG_FIRST;
        $requestedBy = $payload['requested_by'] ?? null;
        $items = $payload['items'] ?? [];
        $feeOverride = isset($payload['shipping_fee_override']) ? (float) $payload['shipping_fee_override'] : null;

        $exchange = DB::transaction(function () use ($order, $payload, $items, $reason, $chargeMethod, $refundPriority, $requestedBy, $operator, $feeOverride) {
            // 잠금은 잔여 수량을 읽기 전에 잡는다 — 읽고 나서 잠그면 이미 두 요청이 같은 값을 봤다.
            $lockedOrder = $this->orderRepository->findForUpdateOrFail($order->id);
            $lockedOrder->load(['options', 'shippings', 'payment', 'shippingAddress']);

            $resolved = $this->claimQuantityGuard->resolveClaimItems(
                $lockedOrder,
                $items,
                ClaimTypeEnum::EXCHANGE,
                $this->requestStatusValuesFor($operator),
            );

            // 운영자 직권 접수는 신청 가능 기간을 무시한다(D5) — 물리 게이트는 위 상태 목록이 이미 건다.
            if (! $operator) {
                $this->assertWithinRequestPeriod($resolved);
            }

            $redelivery = $this->buildRedeliveryAddressSnapshot($lockedOrder, $payload['redelivery_address'] ?? null);
            $this->assertRedeliveryCountryUnchanged($lockedOrder, $redelivery);

            $plans = $this->planItems($resolved, $items);
            $this->assertTargetStockAvailable($plans);

            $claimItems = $this->toClaimItemsFromPlans($plans);

            $shippingFee = $this->applyShippingFeeOverride($this->claimShippingFeeService->calculate(
                $lockedOrder,
                ClaimTypeEnum::EXCHANGE,
                $claimItems,
                $reason->fault_type,
                $this->toFeeLegAddress($redelivery),
            ), $feeOverride, $lockedOrder->currency_snapshot ?? []);

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
                'shipping_fee_snapshot' => $shippingFee->toArray() + ($feeOverride !== null ? ['admin_adjusted' => true] : []),
                'additional_product_amount' => $additional,
                'refund_product_amount' => $refund,
                'total_charge_amount' => $totalCharge,
                'mc_total_charge_amount' => $this->toMultiCurrency($totalCharge, $currencySnapshot),
                'currency_code' => $this->resolveBaseCurrency($lockedOrder),
                // 접수 계산에 쓴 환불 우선순위 — 응답·수정 화면이 추측하지 않고 이 값을 읽는다.
                'refund_priority' => $refundPriority,
                'items_snapshot' => $this->buildItemsSnapshot($plans),
                'return_address_snapshot' => $this->resolveReturnAddressSnapshot($lockedOrder, $resolved),
                'redelivery_address_snapshot' => $redelivery,
                'requested_by' => $requestedBy,
                'requested_by_admin' => $payload['requested_by_admin'] ?? null,
            ] + $this->frozenCalculationSnapshots($frozen) + $this->refundBankColumns($payload['refund_bank'] ?? null));

            foreach ($plans as $plan) {
                $this->createExchangeOption($created, $lockedOrder, $plan, $currencySnapshot);
            }

            return $created->fresh(['exchangeOptions']);
        });

        // 운영자 직권 접수는 컨텍스트 `source = admin` 을 함께 싣는다(§3-11 — 새 훅 없이 기존 신청 훅).
        $this->fireAfterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.claim.exchange.requested', $exchange, $order->fresh(), ['source' => $operator ? 'admin' : 'customer']));

        // 운영자 직권 접수의 승인은 호출부(`AdminClaimRequestService`)가 「접수와 함께 승인」 선택으로 정한다.
        if ($operator) {
            return $exchange;
        }

        // 청구액 0원 자동 승인 (§4-2 전이표 자동 열). 접수 훅을 먼저 발화한 뒤 전이하므로
        // 알림·로그 리스너는 '신청 → 승인' 두 사건을 순서대로 받는다.
        if ($this->shouldAutoApproveZeroCharge($exchange)) {
            try {
                // 누른 사람이 없는 전이다 — 고객 요청 안에서 돌아도 작업자는 시스템이다(S1-D16).
                return SystemActor::run(fn () => $this->transition($exchange, ExchangeStatusEnum::APPROVED));
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
     * 철회 가능 판정을 지난 뒤 `claim.exchange.before_withdraw` 를 동기로 발화한다 — 확장이 예외로
     * 철회를 막을 수 있다. 이어지는 전이가 `before_status_change` 를 한 번 더 낸다(층이 다른 두 자리).
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  int|null  $withdrawnByAdmin  고객 요청으로 대신 철회하는 관리자 ID (S14 — null 이면 신청자 본인 철회)
     * @param  string|null  $withdrawReason  대리 철회 사유 (관리자 대리 철회에서 필수)
     * @return OrderExchange 철회된 신청서
     *
     * @throws OrderClaimException 철회 불가 상태
     */
    public function withdraw(OrderExchange $exchange, ?int $withdrawnByAdmin = null, ?string $withdrawReason = null): OrderExchange
    {
        if (! $exchange->exchange_status->isWithdrawable()) {
            throw OrderClaimException::notWithdrawable();
        }

        // 판매자가 접수한 신청은 고객이 철회할 수 없다(D8) — 운영자는 고객 요청으로 대신 철회할 수 있다(S14).
        if ($withdrawnByAdmin === null && $exchange->isAdminRequested()) {
            throw OrderClaimException::adminRequestedNotEditable();
        }

        $withdrawReason = $withdrawReason === null ? null : trim($withdrawReason);

        if ($withdrawnByAdmin !== null && ($withdrawReason === null || $withdrawReason === '')) {
            throw OrderClaimException::withdrawReasonRequired();
        }

        HookManager::doAction('sirsoft-ecommerce.claim.exchange.before_withdraw', $exchange);

        // 대리 철회는 철회자·사유를 신청서에 남긴다(S14 — 고객 알림이 「고객님 요청으로 판매자가 철회」 로 나간다).
        return $this->transition($exchange, ExchangeStatusEnum::WITHDRAWN, $withdrawnByAdmin === null ? [] : [
            'processed_by' => $withdrawnByAdmin,
            'withdrawn_by_admin' => $withdrawnByAdmin,
            'withdraw_reason' => $withdrawReason,
        ]);
    }

    /**
     * 교환 신청서 상태를 전이합니다.
     *
     * 완료(COMPLETED)는 이 경로로 갈 수 없다 — 원 옵션 재고 복구 여부와 환불신청서 접수 여부를
     * 운영자가 고르는 절차(D26)이므로 `completeExchange()` 만이 진입점이다.
     *
     * 잠금 전에 before·filter 훅을 동기로 낸다(`beforeStatusChange()`).
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

        $context = $this->beforeStatusChange($exchange, $target, $context);

        // 상태를 **행 잠금 아래에서 다시 읽어** 판정한다(S3-D16). 메모리의 모델 값으로
        // 판정하면 더블클릭·재시도로 동시에 들어온 두 요청이 **둘 다 가드를 통과**한다 —
        // 쓰기는 같은 값이라 상태는 1회처럼 보이지만 `fireStatusHooks()` 가 두 번 돌아
        // **알림이 채널마다 2통씩** 나간다. 구매자에게는 같은 메일·SMS 가 두 번 도착한다.
        // 잠금·판정·쓰기를 **한 트랜잭션**으로 묶는다(S3-D16). 판정만 잠금하고 밖에서 쓰면
        // 잠금이 판정 직후 풀려 **두 요청이 다시 나란히 통과**한다 — 쓰기는 같은 값이라
        // 상태는 1회처럼 보이지만 `fireStatusHooks()` 가 두 번 돌아 **알림이 채널마다 2통씩**
        // 나간다. 구매자에게는 같은 메일·SMS 가 두 번 도착한다.
        $this->trackingChangeLog = [];

        [$current, $updated] = DB::transaction(function () use ($exchange, $target, $context) {
            // 저장소가 이미 갖고 있는 잠금 조회를 쓴다 — 서비스가 모델을 직접 조회하지 않는다.
            $locked = $this->exchangeRepository->findForUpdate((int) $exchange->getKey());

            $current = ($locked ?? $exchange)->exchange_status;
            $mode = ($locked ?? $exchange)->mode();

            if (! $current->canTransitionTo($target, $mode)) {
                throw OrderClaimException::transitionNotAllowed($current->label(), $target->label());
            }

            // 호출층이 들고 있는 인스턴스도 잠금 시점의 값으로 맞춘다.
            $exchange->setAttribute('exchange_status', $current);
            $exchange->setAttribute('exchange_mode', $mode);

            if ($this->revertsOrderOptions($target)) {
                $this->assertLivingItemsCanTransitionTo($exchange, $target);
            }

            // 회수중·발송중은 대상 행 전부에 그 방향 송장이 있어야 들어간다(R6) — 지시에 실린 송장은
            // 상품마다 전이 저장점 안에서 그 행에 쓴다(`transitionItem`). 알림·교환품 배송 행이 행 송장을 읽는다.
            $tracking = [];

            if ($current !== $target) {
                $tracking = $this->prepareTransitionTracking($exchange, $target, $context, $this->headerTransitionTargets($exchange, $target));
            }

            $applied = $this->applyTransition($exchange, $target, $context, $tracking);

            // 선교환은 발송 단계 전이가 회수 기한의 기준을 바꾼다(발송도착 시각 — §3-5).
            $this->refreshCollectionDue($applied);

            return [$current, $applied->fresh(['exchangeOptions'])];
        });

        // 상태가 실제로 바뀜 때만 훅을 돌린다(S3-D16). 상태 기계는 **같은 상태로의
        // 재진입을 허용**하므로(`canTransitionTo` 가 self 를 true 로 낸다), 더블클릭·재시도로
        // 같은 전이가 두 번 들어오면 쓰기는 멱등해도 **훅은 두 번 돌아 알림이 채널마다
        // 2통씩** 나간다. 상태는 정상으로 보이고 응답도 200 이라 화면에는 흔적이 없다.
        if ($current !== $target) {
            $this->fireStatusHooks($updated, $current, $target);
        }

        $this->flushTrackingChangeLog($updated, $current !== $target ? $target : null);

        return $updated;
    }

    /**
     * 헤더 상태 전이 직전의 before·filter 훅을 동기로 발화합니다. (직접 전이·송장 등록 전이 공용)
     *
     * 잠금·쓰기 **전**에 돈다 — `before_status_change` 리스너가 던지면 아무것도 쓰이지 않는다.
     * `filter_status_change_data` 는 컨텍스트의 메모·반려 사유(`CLAIM_FILTERABLE_STATUS_CONTEXT_KEYS`)만
     * 바꿀 수 있다. 목표 상태는 넘기되 바꾸게 두지 않는다 — 호출층(`AdminClaimService::transition`)이
     * 미수납 게이트·종결 정산을 그 값으로 이미 판정했기 때문이다. `$from` 은 호출 시점(잠금 전)의
     * 값이며, 실제 전이 판정은 잠금 안에서 다시 읽은 상태로 한다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보
     * @return array 필터를 거친 부가 정보
     */
    protected function beforeStatusChange(OrderExchange $exchange, ExchangeStatusEnum $target, array $context): array
    {
        $from = $exchange->exchange_status?->value;

        HookManager::doAction('sirsoft-ecommerce.claim.exchange.before_status_change', $exchange, $from, $target->value, $context);

        return $this->acceptClaimFilterResult(
            HookManager::applyFilters('sirsoft-ecommerce.claim.exchange.filter_status_change_data', $context, $exchange, $from, $target->value),
            $context,
            self::CLAIM_FILTERABLE_STATUS_CONTEXT_KEYS,
        );
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
        $blocking = $this->firstItemBlockingHeaderTransition($exchange, $target);

        if ($blocking !== null) {
            throw OrderClaimException::transitionNotAllowed($blocking->option_status->label(), $target->label());
        }
    }

    /**
     * 신청서 단위 전이가 앞서 있는 상품 때문에 거절되는지 판정합니다. (상세 신청서 칸의 선택지 게이트)
     *
     * `transition()` 이 실제로 거절하는 조건(반려·철회 + 앞선 상품)과 **같은 판정**이다 — 화면이
     * 조건을 따로 적으면 선택지와 거절이 갈려, 운영자가 고른 「반려」 가 다시 422 로 돌아온다.
     * 반품 서비스와 같은 규칙이다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @return bool 거절되면 true
     */
    public function headerTransitionBlockedByItems(OrderExchange $exchange, ExchangeStatusEnum $target): bool
    {
        return $this->revertsOrderOptions($target)
            && $this->firstItemBlockingHeaderTransition($exchange, $target) !== null;
    }

    /**
     * 목표 상태로 갈 수 없는 첫 살아 있는 상품을 찾습니다. (끝난 상품·이미 목표 상태인 상품 제외)
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @return OrderExchangeOption|null 막는 상품 (없으면 null)
     */
    protected function firstItemBlockingHeaderTransition(OrderExchange $exchange, ExchangeStatusEnum $target): ?OrderExchangeOption
    {
        $exchange->loadMissing('exchangeOptions');
        $mode = $exchange->mode();

        foreach ($exchange->exchangeOptions as $item) {
            if ($item->option_status->isFinal() || $item->option_status === $target) {
                continue;
            }

            if (! $item->option_status->canTransitionTo($target, $mode)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 지금 완료할 수 있는 상품인지 판정합니다. (완료 절차의 대상 필터 · 상세 [완료 처리] 활성 판정 공용)
     *
     * 일반 교환은 발송중·발송도착(P3 — 오는 중이면 완료 가능). 선교환은 두 축을 모두 본다(§3-4):
     * 교환품이 발송중·발송도착이고, **헌 물건도 오는 중이거나 도착했어야** 한다(회수 진행
     * in_transit·collected). 헌 물건이 아직 출발하지 않은 행은 「미회수로 완료」 로만 끝낸다.
     *
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @param  ExchangeModeEnum|null  $mode  진행 방식 (null 이면 행의 신청서에서 읽는다)
     * @return bool 완료 가능하면 true
     */
    public function isCompletableItem(OrderExchangeOption $item, ?ExchangeModeEnum $mode = null): bool
    {
        if ($item->option_status->isFinal()) {
            return false;
        }

        $mode ??= $this->modeOfItem($item);

        if ($mode === ExchangeModeEnum::ADVANCE) {
            return in_array($item->option_status, [ExchangeStatusEnum::REDELIVERING, ExchangeStatusEnum::REDELIVERED], true)
                && ClaimItemCollectionState::for($item, $mode)->isOnTheWayOrArrived();
        }

        return $item->option_status->canTransitionTo(ExchangeStatusEnum::COMPLETED, $mode);
    }

    /**
     * 「미회수로 완료」 할 수 있는 상품인지 판정합니다. (D3 — 선교환, 교환품 발송 후 헌 물건 미출발)
     *
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @param  ExchangeModeEnum|null  $mode  진행 방식 (null 이면 행의 신청서에서 읽는다)
     * @return bool 미회수 완료 대상이면 true
     */
    public function isUnreturnedEligibleItem(OrderExchangeOption $item, ?ExchangeModeEnum $mode = null): bool
    {
        $mode ??= $this->modeOfItem($item);

        return $mode === ExchangeModeEnum::ADVANCE
            && ! $item->option_status->isFinal()
            && in_array($item->option_status, [ExchangeStatusEnum::REDELIVERING, ExchangeStatusEnum::REDELIVERED], true)
            && ClaimItemCollectionState::for($item, $mode) === ClaimItemCollectionStateEnum::PENDING;
    }

    /**
     * 상품 행이 속한 교환의 진행 방식을 반환합니다. (관계가 로드돼 있으면 그 값, 아니면 조회)
     *
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @return ExchangeModeEnum 진행 방식
     */
    protected function modeOfItem(OrderExchangeOption $item): ExchangeModeEnum
    {
        $exchange = $item->relationLoaded('orderExchange')
            ? $item->orderExchange
            : $this->exchangeRepository->findById((int) $item->order_exchange_id);

        return $exchange?->mode() ?? ExchangeModeEnum::STANDARD;
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
     * @param  array{direction?: ClaimTrackingDirectionEnum, carrier_id?: int|null, tracking_number?: string|null}  $tracking  대상 행에 쓸 송장 (`prepareTransitionTracking()`)
     * @return OrderExchange 전이된 신청서
     */
    protected function applyTransition(OrderExchange $exchange, ExchangeStatusEnum $target, array $context, array $tracking = []): OrderExchange
    {
        $processedBy = $context['processed_by'] ?? null;

        return DB::transaction(function () use ($exchange, $target, $context, $processedBy, $tracking) {
            $updates = ['exchange_status' => $target];

            if ($processedBy !== null) {
                $updates['processed_by'] = $processedBy;
            }

            if (array_key_exists('admin_memo', $context)) {
                $updates['admin_memo'] = $context['admin_memo'];
            }

            $updates += $this->stampFor($target, $context);

            $fresh = $this->exchangeRepository->update($exchange, $updates);

            $this->syncOptionStatuses($fresh, $target, $processedBy, $tracking);

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
     * 수량을 실은 지시(`[{id, quantity}]`)는 그 행을 **갈라서** 지정 수량만 전이한다 —
     * 잔여는 새 행으로 남아 신청 상태를 유지한다(`ClaimItemSplitter`). 수량을 싣지 않은
     * 지시는 종전대로 전량이다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array<int, int|array{id: int, quantity?: int|null}>  $items  전이 지시 (빈 배열이면 전체)
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보 (processed_by)
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>, split: array<int, array{id: int, quantity: int, remainder_id: int, remainder_quantity: int}>} 전이 결과
     *
     * @throws OrderClaimException 완료 전이를 시도한 경우
     */
    public function transitionOption(
        OrderExchange $exchange,
        array $items,
        ExchangeStatusEnum $target,
        array $context = [],
    ): array {
        if ($target === ExchangeStatusEnum::COMPLETED) {
            throw OrderClaimException::completionRequiresCompletionFlow();
        }

        $processedBy = $context['processed_by'] ?? null;
        // 상품 칸 반려의 사유 — 반려된 상품 행(`reject_reason`)에 남는다(S3).
        $rejectReason = $target === ExchangeStatusEnum::REJECTED ? trim((string) ($context['reject_reason'] ?? '')) : '';
        $specs = ClaimItemSpecs::normalize($items);
        $quantities = ClaimItemSpecs::quantities($specs);
        $itemIds = ClaimItemSpecs::ids($specs);

        // 상품 단위 전이의 before 훅 — 잠금·쓰기 전이라 리스너가 던지면 어떤 행도 옮겨지지 않는다.
        // 상품 단위 전용 필터는 두지 않는다: 대상 행·목표 상태는 운영자 판단이자 미수납 게이트가 이미 잰 값이고, 헤더가
        // 따라 움직이면 그 단계마다 `before_status_change` 가 난다. 자유 입력은 반려 사유 하나다(#599 후속) —
        // 그 사유만은 아래에서 헤더 경로와 같은 `filter_status_change_data` 를 거친다.
        HookManager::doAction('sirsoft-ecommerce.claim.exchange.before_option_status_change', $exchange, $specs, $target->value, $context);

        // 반려 사유는 신청서 한 칸(`reject_reason`)에 남는 자유 입력이라 헤더 경로와 **같은 필터**를 거친다
        // (#599 후속 운영 결정) — 어느 칸에서 적었느냐에 따라 확장이 손댈 수 있는지가 갈리면 금칙어 필터 같은
        // 확장이 상품 칸으로 우회된다. 받아들이는 키도 같다(처리자·목표 상태는 되돌린다).
        if ($rejectReason !== '') {
            $filterInput = ['reject_reason' => $rejectReason] + $context;
            $filtered = $this->acceptClaimFilterResult(
                HookManager::applyFilters('sirsoft-ecommerce.claim.exchange.filter_status_change_data', $filterInput, $exchange, $exchange->exchange_status?->value, $target->value),
                $filterInput,
                self::CLAIM_FILTERABLE_STATUS_CONTEXT_KEYS,
            );
            $rejectReason = trim((string) ($filtered['reject_reason'] ?? ''));
        }

        $succeeded = [];
        $skipped = [];
        $changes = [];
        $split = [];
        $headerBefore = null;
        $headerAfter = null;
        $this->trackingChangeLog = [];

        // 신청서 행을 잠그고 **잠금 안에서** 상품 행을 다시 읽어 판정한다. 헤더 동기화도 같은
        // 트랜잭션 안에서 한다 — 상품 전이를 커밋한 뒤 잠금 없이 헤더를 옮기면 그 사이에 들어온
        // 다른 전이와 헤더 판정이 겹친다(#599 validate P2 #19). 훅은 커밋 뒤에 나간다(#7).
        $fresh = DB::transaction(function () use ($exchange, $itemIds, $quantities, $target, $processedBy, $rejectReason, $context, &$succeeded, &$skipped, &$changes, &$split, &$headerBefore, &$headerAfter) {
            $locked = $this->exchangeRepository->findForUpdate((int) $exchange->getKey()) ?? $exchange;
            $mode = $locked->mode();
            $headerBefore = $locked->exchange_status;

            $items = $this->resolveItems($locked, $itemIds);

            // 상품 칸의 회수중·발송중도 신청서 전이와 같은 송장 규칙을 지난다(R6·R8). 지시에 실린 송장은
            // 상품마다 저장점 안에서 **쪼갠 행**에 쓴다 — 발송중이면 그 행 송장이 교환품의 새 배송 행에
            // 실리므로(`replicateShippingRow`) 상태를 바꾸기 전에 쓴다.
            $tracking = $this->prepareTransitionTracking($locked, $target, $context, array_values(array_filter(
                $items,
                fn (OrderExchangeOption $item) => ! $item->option_status->isFinal()
                    && $item->option_status !== $target
                    && $item->option_status->canTransitionTo($target, $mode),
            )));

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

                if (! $item->option_status->canTransitionTo($target, $mode)) {
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
                $previousQuantity = (int) $item->exchange_quantity;

                try {
                    $remainder = $this->transitionItem($locked, $item, $target, $processedBy, $quantities[$item->id] ?? null, $tracking);
                } catch (OrderClaimException $e) {
                    // 재고 부족 같은 상품 단위 실패는 그 상품만 건너뛴다 — 같은 TX 안의 다른
                    // 상품은 전이 자체엔 문제가 없고, 저장점(savepoint)이 실패분만 되돌린다.
                    $skipped[] = ['id' => $item->id, 'message' => $e->getMessage()];

                    continue;
                }

                $change = [
                    'option_id' => $item->id,
                    'previous' => $previousStatus->value,
                    'new' => $target->value,
                ];

                if ($remainder !== null) {
                    // 분할 사실은 계보 컬럼이 아니라 활동로그가 나른다 — 전/후 상태만으로는
                    // "3개 중 2개" 가 어디에도 남지 않아, 표가 한 줄 늘어난 것을 운영자가
                    // 스스로 해석해야 한다.
                    $change['quantity'] = (int) $item->fresh()->exchange_quantity;
                    $change['remainder_option_id'] = $remainder->id;
                    // 잔여 수량은 **발행 시점 값**으로 싣는다 — 활동 로그 리스너가 이 값을 먼저 읽고, 없을 때만
                    // 잔여 행을 다시 읽는다(다시 읽으면 그 사이 바뀐 값이 섞인다).
                    $change['remainder_quantity'] = $previousQuantity - $change['quantity'];

                    $split[] = [
                        'id' => $item->id,
                        'quantity' => $change['quantity'],
                        'remainder_id' => $remainder->id,
                        'remainder_quantity' => $previousQuantity - $change['quantity'],
                    ];
                }

                $changes[] = $change;

                $succeeded[] = $item->id;
            }

            // 상품 단위 반려 사유는 **그 상품 행**에 남긴다(S3). 헤더 사유는 신청서 전체가 반려로 모일 때만
            // 채워진다(`syncHeaderFromOptions` → `stampFor(REJECTED)` — 사유가 여럿이면 마지막 반려 사유).
            // 예전에는 헤더 한 칸이라 두 번째 반려가 첫 사유를 덮고, 나머지 상품이 승인돼도 구매자
            // 화면에 반려 사유가 남았다.
            if ($rejectReason !== '' && $succeeded !== []) {
                foreach ($locked->fresh(['exchangeOptions'])->exchangeOptions as $rejectedItem) {
                    if (in_array((int) $rejectedItem->id, array_map('intval', $succeeded), true)) {
                        $this->exchangeOptionRepository->update($rejectedItem, ['reject_reason' => $rejectReason]);
                    }
                }
            }

            $fresh = $locked->fresh(['exchangeOptions']);

            // 상품 단위 전이는 헤더 전이와 다른 사실이다 — 상품 상태가 갈리면 헤더는 움직이지
            // 않으므로(`syncHeaderFromOptions` 는 살아 있는 상품이 한 상태로 모일 때만 옮긴다)
            // 이 훅이 없으면 운영자가 한 상태 변경이 아무 데도 남지 않는다. 헤더가 따라 움직이면
            // 그 전이는 `status_changed` 가 따로 낸다(이중 기록이 아니라 층이 다른 두 사실).
            // 커밋 뒤 발화 순서는 등록 순서다 — 상품 축이 헤더 축보다 먼저 나간다.
            if ($changes !== []) {
                $this->fireAfterCommit(fn () => HookManager::doAction(
                    'sirsoft-ecommerce.claim.exchange.option_status_changed',
                    $fresh,
                    $changes,
                    $target->value,
                    $processedBy,
                ));
            }

            $this->syncHeaderFromOptions($fresh, $processedBy, $rejectReason !== '' ? ['reject_reason' => $rejectReason] : []);

            $after = $fresh->fresh(['exchangeOptions']);
            $this->refreshCollectionDue($after);
            $headerAfter = $after->exchange_status;

            return $after;
        });

        $this->flushTrackingChangeLog($fresh, $headerBefore !== $headerAfter ? $headerAfter : null);

        return ['succeeded' => $succeeded, 'skipped' => $skipped, 'split' => $split];
    }

    // ───────────────────────────────────────────────
    // 진행 방식 (일반 교환 · 선교환 — D1)
    // ───────────────────────────────────────────────

    /**
     * 교환의 진행 방식(일반·선교환)을 바꿀 수 있는지 판정합니다. (상세 「진행 방식」 줄의 버튼 게이트)
     *
     * `setExchangeMode()` 가 실제로 거절하는 조건과 **같은 판정**이다 — 화면이 조건을 따로 적으면
     * 버튼은 켜졌는데 누르면 422 가 된다.
     *
     * @param  OrderExchange  $exchange  교환 신청서 (상품 행 로드)
     * @param  ExchangeModeEnum  $to  바꿀 방식
     * @return string|null 막힌 이유의 예외 키 (바꿀 수 있으면 null)
     */
    public function exchangeModeBlockReason(OrderExchange $exchange, ExchangeModeEnum $to): ?string
    {
        $exchange->loadMissing('exchangeOptions');

        if ($exchange->mode() === $to) {
            return 'claim_exchange_mode_not_changeable';
        }

        if ($to === ExchangeModeEnum::ADVANCE && ! $this->advanceExchangeEnabled()) {
            return 'claim_advance_exchange_disabled';
        }

        $reviewStages = [
            ExchangeStatusEnum::REQUESTED,
            ExchangeStatusEnum::PAYMENT_PENDING,
            ExchangeStatusEnum::PAID,
            ExchangeStatusEnum::ON_HOLD,
            ExchangeStatusEnum::APPROVED,
        ];

        if (! in_array($exchange->exchange_status, $reviewStages, true)) {
            return 'claim_exchange_mode_not_changeable';
        }

        $living = $exchange->exchangeOptions->reject(fn (OrderExchangeOption $item) => $item->option_status->isFinal());

        foreach ($living as $item) {
            if (! in_array($item->option_status, $reviewStages, true)) {
                return 'claim_exchange_mode_not_changeable';
            }
        }

        foreach ($exchange->exchangeOptions as $item) {
            if ($item->redelivery_tracking_number !== null) {
                return 'claim_exchange_mode_not_changeable';
            }
        }

        if ($to === ExchangeModeEnum::STANDARD) {
            foreach ($exchange->exchangeOptions as $item) {
                if (ClaimItemCollectionState::hasCollectionTracking($item) || $item->collected_at !== null) {
                    return 'claim_exchange_mode_locked_by_collection';
                }
            }
        }

        return null;
    }

    /**
     * 교환의 진행 방식을 바꿉니다. (관리자 — 승인 전후 어느 때나, 교환품 발송 전까지)
     *
     * 선교환은 새 물건을 먼저 보내고 헌 물건은 기한 안에 받는다. 켜는 것은 운영자가 신청서마다
     * 정하고, 기능 자체는 쇼핑몰 설정 `claim.advance_exchange_enabled` 로 끈다(설정을 꺼도 이미
     * 선교환인 건은 되돌릴 수 있다). 선교환에서 되돌리면 회수 기한·지연 알림 기록을 비운다.
     *
     * 쓰기 전에 `claim.exchange.before_mode_change`(막기)를 동기로, 커밋 뒤
     * `claim.exchange.after_mode_change` 를 낸다. filter 는 두지 않는다 — 바꿀 수 있는 값이 방식
     * 하나뿐이고 그 값은 게이트가 판정한다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeModeEnum  $to  바꿀 방식
     * @param  int|null  $by  바꾼 관리자 ID
     * @return OrderExchange 갱신된 신청서
     *
     * @throws OrderClaimException 설정 꺼짐 / 바꿀 수 없는 단계 / 회수 송장이 있는 선교환
     */
    public function setExchangeMode(OrderExchange $exchange, ExchangeModeEnum $to, ?int $by = null): OrderExchange
    {
        $from = $exchange->mode();

        HookManager::doAction('sirsoft-ecommerce.claim.exchange.before_mode_change', $exchange, $from->value, $to->value);

        $updated = DB::transaction(function () use ($exchange, $to, $by) {
            $locked = $this->lockClaimForTracking($exchange);

            $blocked = $this->exchangeModeBlockReason($locked, $to);

            if ($blocked !== null) {
                throw match ($blocked) {
                    'claim_advance_exchange_disabled' => OrderClaimException::advanceExchangeDisabled(),
                    'claim_exchange_mode_locked_by_collection' => OrderClaimException::exchangeModeLockedByCollection(),
                    default => OrderClaimException::exchangeModeNotChangeable(),
                };
            }

            $updates = [
                'exchange_mode' => $to,
                'exchange_mode_set_by' => $by,
                'exchange_mode_set_at' => now(),
            ];

            if ($to === ExchangeModeEnum::STANDARD) {
                $updates['collection_due_at'] = null;
                $updates['collection_overdue_notified_at'] = null;
            }

            return $this->exchangeRepository->update($locked, $updates)->fresh(['exchangeOptions']);
        });

        $this->fireAfterCommit(fn () => HookManager::doAction(
            'sirsoft-ecommerce.claim.exchange.after_mode_change',
            $updated,
            $from->value,
            $to->value,
            $by,
        ));

        return $updated;
    }

    /**
     * 반송 기한을 넘긴 선교환에 회수 지연 관리자 알림을 한 번 보냅니다. (배치 — D2)
     *
     * 헌 물건이 아직 출발하지 않은(회수 대기) 행이 남은 건만 알린다. 알린 건은
     * `collection_overdue_notified_at` 을 적어 다시 알리지 않는다 — 기한이 다시 미래로 계산되면
     * `refreshCollectionDue()` 가 비워 다음 지연 때 한 번 더 알린다. 훅
     * `sirsoft-ecommerce.claim.exchange.collection_overdue` 는 커밋 뒤에 나간다.
     *
     * @param  int  $limit  한 번에 처리할 최대 건수
     * @param  bool  $dryRun  true 면 알림·기록 없이 대상만 센다
     * @return array{scanned: int, notified: int, skipped: int} 처리 결과
     */
    public function notifyOverdueCollections(int $limit = 200, bool $dryRun = false): array
    {
        $scanned = 0;
        $notified = 0;
        $skipped = 0;

        foreach ($this->exchangeRepository->listOverdueCollectionCandidates($limit) as $exchange) {
            $scanned++;

            $overdue = ClaimTrackingGroups::overdueOf($exchange);

            if (! $overdue['is_overdue']) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $notified++;

                continue;
            }

            DB::transaction(function () use ($exchange, $overdue) {
                $locked = $this->exchangeRepository->findForUpdate((int) $exchange->getKey()) ?? $exchange;

                // 두 배치가 겹쳐 돌아도 한 번만 알린다 — 잠금 안에서 다시 본다.
                if ($locked->collection_overdue_notified_at !== null) {
                    return;
                }

                $this->exchangeRepository->update($locked, ['collection_overdue_notified_at' => now()]);

                $this->fireAfterCommit(fn () => HookManager::doAction(
                    'sirsoft-ecommerce.claim.exchange.collection_overdue',
                    $locked->fresh(['exchangeOptions']),
                    (int) $overdue['days'],
                ));
            });

            $notified++;
        }

        return ['scanned' => $scanned, 'notified' => $notified, 'skipped' => $skipped];
    }

    /**
     * 선교환 기능이 켜져 있는지 반환합니다. (설정 `claim.advance_exchange_enabled`, 기본 꺼짐)
     *
     * @return bool 켜져 있으면 true
     */
    public function advanceExchangeEnabled(): bool
    {
        return filter_var(
            $this->settingsService->getSetting('claim.advance_exchange_enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    // ───────────────────────────────────────────────
    // 회수 / 재발송 송장 (상품 행 — `RecordsClaimTracking`), 금액 수정
    // ───────────────────────────────────────────────

    /**
     * 운송장 한 개의 행을 한꺼번에 도착 처리합니다. (배송 정보 탭 운송장 묶음의 [도착 처리] — R9)
     *
     * 같은 방향·같은 (택배사, 운송장)을 가진 이 신청서의 행 전부가 대상이다.
     *
     *  - 일반 교환: 회수도착·발송도착 전이(P2 건너뛰기 포함). 전이할 수 없는 행은 `skipped`.
     *  - 선교환 회수: 상태를 바꾸지 않고 행 `collected_at` 만 찍는다(선교환 상태 줄에는 회수 단계가 없다).
     *  - 선교환 재발송: 발송도착 전이 + 회수 기한 재계산(§3-5).
     *
     * 커밋 뒤 `claim.exchange.after_tracking_deliver` 를 낸다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  int  $carrierId  택배사 ID
     * @param  string  $trackingNumber  운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array{claim: OrderExchange, succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>} 처리 결과
     *
     * @throws OrderClaimException 그 운송장을 가진 행 없음
     */
    public function deliverTracking(
        OrderExchange $exchange,
        ClaimTrackingDirectionEnum $direction,
        int $carrierId,
        string $trackingNumber,
        ?int $processedBy = null,
    ): array {
        $exchange->loadMissing('exchangeOptions');
        $items = $this->itemsWithTracking($exchange, $direction, $carrierId, $trackingNumber);

        if ($items === []) {
            throw OrderClaimException::trackingNotFound();
        }

        $mode = $exchange->mode();
        $succeeded = [];
        $skipped = [];

        if ($mode === ExchangeModeEnum::ADVANCE && $direction === ClaimTrackingDirectionEnum::COLLECTION) {
            DB::transaction(function () use ($exchange, $items, $processedBy, &$succeeded, &$skipped) {
                $locked = $this->lockClaimForTracking($exchange);

                foreach ($items as $item) {
                    $item = $locked->exchangeOptions->firstWhere('id', $item->id) ?? $item;

                    if ($item->option_status->isFinal() || $item->collected_at !== null || $item->unreturned_at !== null) {
                        $skipped[] = ['id' => (int) $item->id, 'message' => __('sirsoft-ecommerce::exceptions.claim_tracking_already_arrived')];

                        continue;
                    }

                    $this->exchangeOptionRepository->update($item, [
                        'collected_at' => now(),
                        'processed_by' => $processedBy ?? $item->processed_by,
                    ]);

                    $succeeded[] = (int) $item->id;
                }
            });
        } else {
            $target = $direction === ClaimTrackingDirectionEnum::COLLECTION
                ? ExchangeStatusEnum::COLLECTED
                : ExchangeStatusEnum::REDELIVERED;

            $movable = [];

            foreach ($items as $item) {
                if ($item->option_status->isFinal() || $item->option_status === $target
                    || ! $item->option_status->canTransitionTo($target, $mode)) {
                    $skipped[] = [
                        'id' => (int) $item->id,
                        'message' => $item->option_status === $target || $this->itemArrived($item, $direction)
                            ? __('sirsoft-ecommerce::exceptions.claim_tracking_already_arrived')
                            : __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                                'from' => $item->option_status->label(),
                                'to' => $target->label(),
                            ]),
                    ];

                    continue;
                }

                $movable[] = (int) $item->id;
            }

            if ($movable !== []) {
                $result = $this->transitionOption($exchange, $movable, $target, ['processed_by' => $processedBy]);
                $succeeded = $result['succeeded'];
                $skipped = [...$skipped, ...$result['skipped']];
            }
        }

        $fresh = $exchange->fresh(['exchangeOptions']);

        $this->refreshCollectionDue($fresh);

        $this->fireAfterCommit(fn () => HookManager::doAction(
            'sirsoft-ecommerce.claim.exchange.after_tracking_deliver',
            $fresh,
            $direction->value,
            $carrierId,
            $trackingNumber,
            ['succeeded' => $succeeded, 'skipped' => $skipped],
        ));

        return ['claim' => $fresh->fresh(['exchangeOptions']), 'succeeded' => $succeeded, 'skipped' => $skipped];
    }

    /**
     * 행이 그 방향으로 이미 도착했는지 반환합니다.
     *
     * @param  OrderExchangeOption  $item  상품 행
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @return bool 도착했으면 true
     */
    protected function itemArrived(OrderExchangeOption $item, ClaimTrackingDirectionEnum $direction): bool
    {
        return $item->{$direction->arrivedAtColumn()} !== null;
    }

    /**
     * 전이 목표가 쓰는 송장 방향을 반환합니다. (회수중·회수도착 → 회수, 발송중·발송도착 → 재발송)
     *
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @return ClaimTrackingDirectionEnum|null 방향 (송장과 무관한 목표면 null)
     */
    protected function trackingDirectionForTarget(ExchangeStatusEnum $target): ?ClaimTrackingDirectionEnum
    {
        return match ($target) {
            ExchangeStatusEnum::COLLECTING, ExchangeStatusEnum::COLLECTED => ClaimTrackingDirectionEnum::COLLECTION,
            ExchangeStatusEnum::REDELIVERING, ExchangeStatusEnum::REDELIVERED => ClaimTrackingDirectionEnum::REDELIVERY,
            default => null,
        };
    }

    /**
     * 전이 지시에 실린 송장을 해석하고, 이동 대상 행이 송장을 갖출 수 있는지 검증합니다. (R6·R8)
     *
     * 회수중·발송중은 송장이 필수다 — 지시에 없으면 대상 행이 이미 자기 송장을 가져야 한다. 회수도착·
     * 발송도착은 송장이 선택이다(P2) — 지시에 실리면 그 행에 함께 쓴다.
     *
     * @param  OrderExchange  $exchange  교환 신청서 (상품 행 로드)
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @param  array  $context  전이 부가 정보 (carrier_id / tracking_number)
     * @param  array<int, OrderExchangeOption>  $targets  이동할 대상 행
     * @return array{direction?: ClaimTrackingDirectionEnum, carrier_id?: int|null, tracking_number?: string|null} 대상 행에 쓸 송장 (쓸 것이 없으면 빈 배열)
     *
     * @throws OrderClaimException 송장 불완전 / 송장 없음 / 회수지 섞임
     */
    protected function prepareTransitionTracking(OrderExchange $exchange, ExchangeStatusEnum $target, array $context, array $targets): array
    {
        $direction = $this->trackingDirectionForTarget($target);

        if ($direction === null) {
            return [];
        }

        [$carrierId, $trackingNumber] = $this->trackingFromContext($context);

        if (in_array($target, [ExchangeStatusEnum::COLLECTING, ExchangeStatusEnum::REDELIVERING], true)) {
            $this->assertTransitionTracking($exchange, $targets, $direction, $carrierId, $trackingNumber);
        } elseif ($trackingNumber !== null && $direction === ClaimTrackingDirectionEnum::COLLECTION) {
            $this->assertSameReturnAddress($exchange, $targets, $carrierId, $trackingNumber);
        }

        if ($trackingNumber === null) {
            return [];
        }

        return ['direction' => $direction, 'carrier_id' => $carrierId, 'tracking_number' => $trackingNumber];
    }

    /**
     * 신청서 단위 전이가 끌고 갈 상품 행을 고릅니다. (`syncOptionStatuses()` 와 같은 판정)
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @return array<int, OrderExchangeOption> 이동할 상품 행
     */
    protected function headerTransitionTargets(OrderExchange $exchange, ExchangeStatusEnum $target): array
    {
        $exchange->loadMissing('exchangeOptions');
        $mode = $exchange->mode();

        return $exchange->exchangeOptions
            ->filter(fn (OrderExchangeOption $item) => $item->option_status !== $target
                && ! $item->option_status->isFinal()
                && $item->option_status->canTransitionTo($target, $mode))
            ->values()
            ->all();
    }

    /**
     * 이번 요청에서 전이와 함께 쓴 송장 변경을 방향별로 알립니다. (`after_tracking_update`)
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum|null  $headerTransitionedTo  이번 요청에서 신청서가 옮겨 간 상태 (그대로면 null)
     * @return void 반환값 없음
     */
    protected function flushTrackingChangeLog(OrderExchange $exchange, ?ExchangeStatusEnum $headerTransitionedTo): void
    {
        $log = $this->trackingChangeLog;
        $this->trackingChangeLog = [];

        foreach ($log as $directionValue => $changes) {
            $this->announceTrackingUpdated($exchange, ClaimTrackingDirectionEnum::from($directionValue), $changes, [
                'source' => 'transition',
                'header_transitioned_to' => $headerTransitionedTo?->value,
            ]);
        }
    }

    // ── RecordsClaimTracking 구현 ──

    /**
     * {@inheritDoc}
     */
    protected function trackingItemsOf(Model $claim): iterable
    {
        return $claim->loadMissing('exchangeOptions')->exchangeOptions;
    }

    /**
     * {@inheritDoc}
     */
    protected function trackingDirectionsOf(Model $claim): array
    {
        return [ClaimTrackingDirectionEnum::COLLECTION, ClaimTrackingDirectionEnum::REDELIVERY];
    }

    /**
     * {@inheritDoc}
     *
     * 일반 교환 회수: 승인~회수중 · 일반 교환 재발송: 회수도착~발송중 · 선교환 회수: 승인~발송도착(종결 전
     * 언제나) · 선교환 재발송: 승인~발송중 (R5).
     */
    protected function trackingWritableStatuses(Model $claim, ClaimTrackingDirectionEnum $direction): array
    {
        $advance = $claim instanceof OrderExchange && $claim->mode() === ExchangeModeEnum::ADVANCE;

        if ($direction === ClaimTrackingDirectionEnum::COLLECTION) {
            return $advance
                ? [ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::REDELIVERY_READY, ExchangeStatusEnum::REDELIVERING, ExchangeStatusEnum::REDELIVERED]
                : [ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTION_READY, ExchangeStatusEnum::COLLECTING];
        }

        return $advance
            ? [ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::REDELIVERY_READY, ExchangeStatusEnum::REDELIVERING]
            : [ExchangeStatusEnum::COLLECTED, ExchangeStatusEnum::REDELIVERY_READY, ExchangeStatusEnum::REDELIVERING];
    }

    /**
     * {@inheritDoc}
     */
    protected function trackingMovingStatus(ClaimTrackingDirectionEnum $direction): ?BackedEnum
    {
        return $direction === ClaimTrackingDirectionEnum::COLLECTION
            ? ExchangeStatusEnum::COLLECTING
            : ExchangeStatusEnum::REDELIVERING;
    }

    /**
     * {@inheritDoc}
     */
    protected function updateTrackingItem(Model $item, array $updates): Model
    {
        return $this->exchangeOptionRepository->update($item, $updates);
    }

    /**
     * {@inheritDoc}
     */
    protected function splitTrackingItem(Model $item, int $quantity): array
    {
        $result = $this->claimItemSplitter->split($item, $quantity);
        $result['target']->refresh();

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * 재발송 송장이 바뀌면 그 행의 후속 주문옵션 배송 행도 같은 송장으로 고친다(R11) — 고객 주문 화면의
     * 배송 조회는 배송 행을 읽는다. 선교환이면 재발송 송장 등록이 회수 기한의 기준이 된다(§3-5).
     */
    protected function afterItemTrackingWritten(Model $claim, Model $item, ClaimTrackingDirectionEnum $direction): void
    {
        if ($direction !== ClaimTrackingDirectionEnum::REDELIVERY) {
            return;
        }

        if ($item->new_order_option_id !== null && $item->redelivery_tracking_number !== null) {
            $this->orderShippingRepository->updateTrackingByOrderOptionId((int) $item->new_order_option_id, [
                'carrier_id' => $item->redelivery_carrier_id,
                'tracking_number' => $item->redelivery_tracking_number,
            ]);
        }

        if ($claim instanceof OrderExchange) {
            $this->refreshCollectionDue($claim->fresh(['exchangeOptions']) ?? $claim);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function trackingHookType(): string
    {
        return ClaimTypeEnum::EXCHANGE->value;
    }

    /**
     * {@inheritDoc}
     */
    protected function lockClaimForTracking(Model $claim): Model
    {
        $locked = $this->exchangeRepository->findForUpdate((int) $claim->getKey()) ?? $claim;

        return $locked->load('exchangeOptions');
    }

    /**
     * 선교환의 헌 물건 반송 기한을 다시 계산합니다. (§3-5 — 선교환만)
     *
     * 기준은 행 재발송 도착 시각 중 가장 이른 값이고, 도착 기록이 없으면 행 재발송 송장 등록 시각 중
     * 가장 이른 값이다(운영 예시 「도착한 지 14일」 우선). 기한이 다시 미래가 되면 지연 알림 기록을 비워
     * 다음 지연 때 다시 한 번 알린다. 선교환이 아니면 기한을 비운다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @return void 반환값 없음
     */
    protected function refreshCollectionDue(OrderExchange $exchange): void
    {
        $exchange->loadMissing('exchangeOptions');

        $updates = [];

        if ($exchange->mode() !== ExchangeModeEnum::ADVANCE) {
            if ($exchange->collection_due_at !== null || $exchange->collection_overdue_notified_at !== null) {
                $updates = ['collection_due_at' => null, 'collection_overdue_notified_at' => null];
            }
        } else {
            $living = $exchange->exchangeOptions->reject(
                fn (OrderExchangeOption $item) => in_array($item->option_status, [ExchangeStatusEnum::REJECTED, ExchangeStatusEnum::WITHDRAWN], true)
            );

            $base = $living->pluck('redelivered_at')->filter()->min()
                ?? $living->pluck('redelivery_shipped_at')->filter()->min();

            $due = $base === null ? null : Carbon::parse($base)->addDays($this->advanceExchangeDueDays());
            $currentDue = $exchange->collection_due_at;

            if ($due?->getTimestamp() !== $currentDue?->getTimestamp()) {
                $updates['collection_due_at'] = $due;

                if (($due === null || $due->isFuture()) && $exchange->collection_overdue_notified_at !== null) {
                    $updates['collection_overdue_notified_at'] = null;
                }
            }
        }

        if ($updates !== []) {
            $this->exchangeRepository->update($exchange, $updates);
        }
    }

    /**
     * 선교환 헌 물건 반송 기한 일수를 반환합니다. (설정 `claim.advance_exchange_collection_due_days`)
     *
     * 상한·하한은 설정 저장 검증(`StoreEcommerceSettingsRequest`)이 판정한다. 여기서 다시 깎지 않는다 —
     * 한계 밖 값이 저장돼 있으면(직접 DB 수정 등) 기본값으로 돌아간다. 전환 확인 창의 안내 일수
     * (`AdminClaimService::exchangeModeAbilities`)도 이 값을 읽는다 — 안내와 실제 기한이 한 출처다.
     *
     * @return int 일수
     */
    public function advanceExchangeDueDays(): int
    {
        $days = (int) $this->settingsService->getSetting('claim.advance_exchange_collection_due_days', self::ADVANCE_EXCHANGE_DEFAULT_DUE_DAYS);
        $min = (int) config('sirsoft-ecommerce.limits.advance_exchange_collection_due_days_min', 1);
        $max = (int) config('sirsoft-ecommerce.limits.advance_exchange_collection_due_days_max', 90);

        return $days >= $min && $days <= $max ? $days : self::ADVANCE_EXCHANGE_DEFAULT_DUE_DAYS;
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

        // 결과 청구 방식이 「환불금에서 차감」 이면 신청 경로와 같은 비교를 한다(S4) — 교환 차액 환불이 왕복 배송비를
        // 덮지 못하면 부족분이 어디에도 청구되지 않는다.
        $finalMethod = array_key_exists('charge_method', $updates) ? $updates['charge_method'] : $exchange->charge_method;
        if ($finalMethod === ClaimChargeMethodEnum::DEDUCT_FROM_REFUND) {
            $exchange->loadMissing(['order', 'exchangeOptions']);
            $claimItems = [];
            foreach ($exchange->exchangeOptions as $item) {
                $claimItems[] = ['order_option_id' => (int) $item->order_option_id, 'cancel_quantity' => (int) $item->exchange_quantity];
            }

            $this->assertDeductCoversExchangeCharge(
                $exchange,
                $claimItems,
                (float) ($updates['claim_shipping_fee'] ?? $exchange->claim_shipping_fee),
                (float) ($updates['total_charge_amount'] ?? $exchange->total_charge_amount),
            );
        }

        return $this->exchangeRepository->update($exchange, $updates);
    }

    /**
     * 「환불금에서 차감」 청구를 교환 차액 환불이 덮는지 판정합니다. (S4 — 금액 수정·완료 공용)
     *
     * 덮지 못하면 부족분이 어디에도 청구되지 않는다 — 운영자는 청구 방식을 추가결제로 바꾸고 결제를 받는다.
     *
     * @param  OrderExchange  $exchange  교환 신청서 (order 로드)
     * @param  array<int, array{order_option_id: int, cancel_quantity: int}>  $claimItems  판정 대상 상품 행과 수량
     * @param  float  $fee  왕복 배송비
     * @param  float  $total  총 청구액
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 차액 환불이 청구액보다 작은 경우
     */
    protected function assertDeductCoversExchangeCharge(OrderExchange $exchange, array $claimItems, float $fee, float $total): void
    {
        if ($total <= 0 || $claimItems === []) {
            return;
        }

        $baseline = $this->adjustmentService->preview(
            $exchange->order,
            ExchangeAdjustment::fromShippingFee(
                $claimItems,
                new ClaimShippingFee(fee: (int) round($fee), claimType: ClaimTypeEnum::EXCHANGE),
                (int) round((float) $exchange->additional_product_amount - (float) $exchange->refund_product_amount),
            ),
            $this->refundPriorityOf($exchange),
        );

        if ($baseline->getTotalRefundAmount() < $total) {
            throw OrderClaimException::chargeDeductInsufficient();
        }
    }

    /**
     * 반려·철회되지 않은 상품으로 청구액을 다시 계산합니다. (S5 — 상품 단위 반려·철회 뒤)
     *
     * 옵션 차액(추가 상품금액·환불 상품금액)을 **살아 있는 상품**(반려·철회되지 않은 행 — 완료 포함)의
     * `price_difference` 합으로 다시 나눈다. 접수 때와 같은 분할 함수(`splitPriceDifference`)다. 왕복
     * 배송비는 살아 있는 상품이 하나라도 있으면 그대로 둔다 — 회수·재발송 1회는 여전히 일어난다.
     * 청구액이 0 이 되면 청구 방식을 비운다(결제할 수 없는 청구를 남기지 않는다).
     *
     * 결제 대기 건·기수납액과의 정산은 여기서 하지 않는다 — 호출부가 갱신된 신청서로
     * `ClaimPaymentService::settleChargeDifference()` 를 부른다(`updateRequest()` 와 같은 분담).
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @return OrderExchange|null 청구가 바뀐 신청서 (살아 있는 상품이 없거나 바뀐 것이 없으면 null)
     */
    public function recalculateChargeForLivingItems(OrderExchange $exchange): ?OrderExchange
    {
        $exchange->loadMissing(['exchangeOptions', 'order']);

        $living = $exchange->exchangeOptions->reject(fn (OrderExchangeOption $item) => in_array(
            $item->option_status,
            [ExchangeStatusEnum::REJECTED, ExchangeStatusEnum::WITHDRAWN],
            true,
        ));

        if ($living->isEmpty()) {
            return null;
        }

        $priceDifference = (float) $living->sum(fn (OrderExchangeOption $item) => (float) $item->price_difference);
        [$additional, $refund] = $this->splitPriceDifference($priceDifference);
        $totalCharge = (float) $exchange->claim_shipping_fee + $additional;

        if (abs($totalCharge - (float) $exchange->total_charge_amount) < 0.005
            && abs($additional - (float) $exchange->additional_product_amount) < 0.005
            && abs($refund - (float) $exchange->refund_product_amount) < 0.005) {
            return null;
        }

        $currencySnapshot = $exchange->order?->currency_snapshot ?? [];
        $updates = [
            'additional_product_amount' => $additional,
            'refund_product_amount' => $refund,
            'total_charge_amount' => $totalCharge,
            'mc_total_charge_amount' => $this->toMultiCurrency($totalCharge, $currencySnapshot),
        ];

        if ($totalCharge <= 0) {
            $updates['charge_method'] = null;
        }

        return $this->exchangeRepository->update($exchange, $updates);
    }

    /**
     * 금액 수정이 실은 청구 방식이 운영자 허용 목록에 있는지 검증합니다. (반품과 동형)
     *
     * 신청 경로의 `assertChargeMethodUsable` 과 같은 허용 목록(`claim.allowed_charge_methods`)을
     * 읽는다 — 목록 밖 방식이 금액 수정으로 들어오면 신청 화면이 감춘 방식이 우회로가 된다.
     * 차감 가능 여부(deduct) 판정은 여기서 하지 않고 금액 수정(`updateAmounts`)·완료가 `assertDeductCoversExchangeCharge()` 로 한다(S4).
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
     * ④ 원 옵션 적립 회수(후속 옵션은 원 행이 이미 받은 적립을 공제한 차액만 적립한다 — S1) ⑤ (선택) 차액 환불신청서 접수를
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
        // 완료의 before·filter 훅 — 주문 잠금·쓰기 전이다. 필터는 완료 옵션(재고 복구 행·차액
        // 환불신청서 접수 여부)만 바꿀 수 있다. 재고 복구는 잠금 안에서 **완료 대상 행에만** 적용되고,
        // 환불신청서 접수는 접수할 것이 없으면 완료 전체를 되돌리므로 바뀐 값도 같은 검증을 지난다.
        // 금액은 옵션에 없다 — 차액 환불액은 서버가 완료 행으로 계산한다.
        HookManager::doAction('sirsoft-ecommerce.claim.exchange.before_complete', $exchange, $itemIds, $options, $processedBy);

        $filteredOptions = HookManager::applyFilters('sirsoft-ecommerce.claim.exchange.filter_complete_options', $options, $exchange, $itemIds, $processedBy);
        $options = $filteredOptions instanceof ClaimCompletionOptions ? $filteredOptions : $options;

        $previousStatus = null;
        $completionChanges = [];

        $completed = DB::transaction(function () use ($exchange, $itemIds, $options, $processedBy, &$completionChanges, &$previousStatus) {
            $order = $this->orderRepository->findForUpdateOrFail($exchange->order_id);
            $order->load(['options', 'shippings', 'payment']);

            // 대상 필터·직전 상태를 **잠금 안에서 다시 읽은 값**으로 판정한다(#599 validate P2 #3).
            // 호출부가 들고 온 모델로 거르면, 두 처리자가 각자 완료 전 상태를 읽은 뒤 차례로
            // 들어올 때 둘 다 같은 상품을 대상으로 잡아 원 옵션 재고 복구·완료 훅이 두 번 돈다
            // (재고 복구에는 멱등 가드가 없다). 주문 행 잠금이 두 완료를 줄 세운다.
            $exchange = $this->exchangeRepository->findForUpdate((int) $exchange->getKey()) ?? $exchange;
            $previousStatus = $exchange->exchange_status;
            $mode = $exchange->mode();
            $requested = $this->resolveItems($exchange, $itemIds);

            // 미회수 완료 지시(D3)는 **선교환·발송 후·헌 물건 미출발** 행에만 받는다 — 다른 행에 붙이면
            // 회수된 물건의 재고가 복구되지 않은 채 「미회수」 로 남는다. 사유는 필수다(운영 기록).
            foreach (array_keys($options->unreturned) as $unreturnedId) {
                $candidate = collect($requested)->firstWhere('id', (int) $unreturnedId);

                if ($candidate === null || ! $this->isUnreturnedEligibleItem($candidate, $mode)) {
                    throw OrderClaimException::unreturnedNotEligible();
                }

                $reason = $options->unreturnedReason((int) $unreturnedId);

                if ($reason === '' || mb_strlen($reason) > 2000) {
                    throw OrderClaimException::unreturnedReasonRequired();
                }
            }

            $targets = array_values(array_filter(
                $requested,
                fn (OrderExchangeOption $item) => $this->isCompletableItem($item, $mode)
                    || ($options->isUnreturned((int) $item->id) && $this->isUnreturnedEligibleItem($item, $mode)),
            ));

            if ($targets === []) {
                throw OrderClaimException::completionItemsInvalid();
            }

            // 완료 시점의 차액 환불이 차감 청구액보다 작아졌으면(상품 일부 반려 뒤 재계산 등) 완료하지 않는다(S4) —
            // 반품 완료와 같은 422. 환불신청서를 접수하지 않는 완료도 막는다(부족분이 어디에도 청구되지 않는다).
            if ($exchange->charge_method === ClaimChargeMethodEnum::DEDUCT_FROM_REFUND) {
                $exchange->loadMissing(['order', 'exchangeOptions']);
                $living = [];
                foreach ($exchange->exchangeOptions as $item) {
                    if (in_array($item->option_status, [ExchangeStatusEnum::REJECTED, ExchangeStatusEnum::WITHDRAWN], true)) {
                        continue;
                    }
                    $living[] = ['order_option_id' => (int) $item->order_option_id, 'cancel_quantity' => (int) $item->exchange_quantity];
                }
                $this->assertDeductCoversExchangeCharge($exchange, $living, (float) $exchange->claim_shipping_fee, (float) $exchange->total_charge_amount);
            }

            $restoredAny = false;

            foreach ($targets as $item) {
                $itemUpdates = [
                    'option_status' => ExchangeStatusEnum::COMPLETED,
                    'completed_at' => now(),
                    'processed_by' => $processedBy ?? $item->processed_by,
                ];

                // 발송중인 교환품의 완료는 발송도착을 건너뛴다 — 발송도착이 하던 일(후속 주문상품 배송완료)을
                // 대신 하고, 행 도착 기록을 완료 시각으로 남긴다.
                if ($item->option_status === ExchangeStatusEnum::REDELIVERING) {
                    $this->markNewOrderOptionDelivered($item);

                    if ($item->redelivered_at === null) {
                        $itemUpdates['redelivered_at'] = now();
                    }
                }

                // 미회수 완료 — 헌 물건 재고는 늘리지 않는다(D3). 신청서·구매자 화면에 「미회수」 로 남는다.
                $unreturned = $options->isUnreturned((int) $item->id);

                if ($unreturned) {
                    $itemUpdates['unreturned_at'] = now();
                    $itemUpdates['unreturned_reason'] = $options->unreturnedReason((int) $item->id);
                }

                $completionChanges[] = [
                    'option_id' => (int) $item->id,
                    'previous' => $item->option_status->value,
                    'new' => ExchangeStatusEnum::COMPLETED->value,
                ] + ($unreturned ? ['unreturned' => true, 'unreturned_reason' => $itemUpdates['unreturned_reason']] : []);

                $this->exchangeOptionRepository->update($item, $itemUpdates);

                $orderOption = $order->options->firstWhere('id', $item->order_option_id);

                if ($orderOption) {
                    // 재고 플래그·적립 회수는 **실제로 교환완료가 된 행**에 적용한다. 부분 수량이면
                    // 완료분이 새 행으로 갈라져 나가고 원 행은 코스에 남으므로, 원 행에 적으면 아직
                    // 재고를 점유한 행이 "복구됨" 으로 보인다(#599 validate P2 #4).
                    $exchangedOption = $this->markOrderOptionExchanged($orderOption, (int) $item->exchange_quantity);

                    if (! $unreturned && $options->shouldRestoreStock($item->id)) {
                        $this->restoreStockForItem($exchangedOption, (int) $item->exchange_quantity);
                        // 무엇을 복구했는지는 상품 행이 말한다(S6) — 헤더는 「1개 이상 복구」 뜻으로만 남는다.
                        $this->exchangeOptionRepository->update($item, ['is_stock_restored' => true]);
                        $restoredAny = true;
                    }

                    // 원 옵션에 남은 적립 lot 을 회수한다(현재 정책은 회수하지 않는다 —
                    // `cancelEarnForOption()`). 후속 옵션의 적립은 원 행이 이미 받은 적립을 공제하고 남은 차액만 생긴다(`UserMileageService::earnForOrderOption` — S1).
                    $this->mileageService->cancelEarnForOption($order, $exchangedOption->fresh() ?? $exchangedOption);
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

                // 헤더 「발송 완료」 시각은 헤더가 그 단계를 지날 때만 찍힌다(S2). 발송도착을 건너뛰고
                // 완료로 끝났으면 여기서 채운다 — 부분 완료 회차가 찍으면 아직 가는 중인 교환품까지
                // 「배송 완료」 로 보인다. 상품별 도착은 행 `redelivered_at` 이 말한다.
                if ($exchange->redelivered_at === null) {
                    $updates['redelivered_at'] = now();
                }
            }

            return $this->exchangeRepository->update($exchange, $updates)->fresh(['exchangeOptions']);
        });

        // 부분 완료 라운드는 헤더가 움직이지 않는다 — 그때 이 줄이 없으면 **어떤 줄도
        // 남지 않아** 운영자가 무엇을 완료 처리했는지 흔적이 사라진다(C3).
        if ($completionChanges !== []) {
            $this->fireAfterCommit(fn () => HookManager::doAction(
                'sirsoft-ecommerce.claim.exchange.option_status_changed',
                $completed,
                $completionChanges,
                ExchangeStatusEnum::COMPLETED->value,
                $processedBy,
            ));
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
            $order = $this->orderRepository->findForUpdateOrFail($exchange->order_id);
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
     * @param  bool  $operator  운영자 직권 접수 여부 (물리 게이트 · 신청 기간 미적용 — D5)
     * @return array<int, array{option: OrderOption, quantity: int}> 주문옵션 ID => 해석 결과
     *
     * @throws OrderClaimException 대상 부재·상태 불가·수량 오류·수량 잠금·기간 경과
     */
    protected function resolvePreviewItems(Order $order, array $items, ?int $excludeExchangeId = null, bool $operator = false): array
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
            $this->requestStatusValuesFor($operator),
            $excludeExchangeId,
        );

        // 운영자 직권 접수는 신청 가능 기간을 무시한다(D5).
        if (! $operator) {
            $this->assertWithinRequestPeriod($resolved);
        }

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

        // 두 경로 모두 **상품까지 싣는다.** 동일옵션 경로가 상품 없이 옵션만 읽던 탓에 아래 상품
        // 단위 판정(판매·전시·구매제한)을 할 수 없었다. 신규 단가(`getFinalPrice()` = 상품 판매가 +
        // 옵션 조정액)도 상품을 필요로 한다 — 관계를 불러올지는 Repository 가 정하고, 모델 안에서
        // 지연 로드하지 않는다.
        $target = $isOptionChange
            ? $this->productOptionRepository->findByIdWithProduct($newProductOptionId)
            : $this->productOptionRepository->findByIdWithProduct((int) $option->product_option_id);

        if (! $target) {
            throw OrderClaimException::newOptionInvalid();
        }

        // 다른 상품의 옵션은 받지 않는다 — 배송정책·과세·적립 정책이 달라 차액 하나로 정산할 수 없다.
        if ($isOptionChange && (int) $target->product_id !== (int) $option->product_id) {
            throw OrderClaimException::newOptionInvalid();
        }

        $this->assertTargetOptionIsPurchasable($target, $option);

        return [$target, $isOptionChange];
    }

    /**
     * 교환받을 옵션이 **지금 살 수 있는 상태**인지 검사합니다.
     *
     * 교환은 그 옵션을 새로 한 번 더 내보내는 일이라, 지금 판매하지 않는 것을 보내 달라는 신청은
     * 받을 수 없다. 예전에는 옵션의 `is_active` 만, 그것도 **옵션을 바꾸는 경로에서만** 봤다 —
     * 그래서 ① 판매 중지한 옵션으로 **동일옵션 교환**이 접수되고(2026-09-22 #599 3차 실측 L-4 ·
     * T2-D11: `is_active=false` 인 원 옵션으로 낸 동일옵션 교환이 201 로 통과했다) ② 품절·판매중지·
     * 출시예정·미노출·구매등급제한 **상품**으로도 교환이 접수됐다.
     *
     * 재고는 여기서 보지 않는다 — 수량과 함께 판정해야 해서 `assertRedeliveryStock()` 이 따로 맡는다.
     *
     * @param  ProductOption  $target  교환받을 상품옵션 (상품 관계 포함)
     * @param  OrderOption  $option  원 주문옵션 (신청자 해석용)
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 지금 구매할 수 없는 옵션·상품
     */
    protected function assertTargetOptionIsPurchasable(ProductOption $target, OrderOption $option): void
    {
        if (! $target->is_active) {
            throw OrderClaimException::newOptionInvalid();
        }

        $product = $target->product;

        if (! $product) {
            throw OrderClaimException::newOptionInvalid();
        }

        // 판매 상태 — 판매중만 통과(품절·판매중지·출시예정 거절).
        // 모델이 enum 으로 캐스팅하지만 캐스트가 없는 경로에서도 같은 판정이 나오도록 값으로 비교한다.
        $salesStatus = $product->sales_status instanceof ProductSalesStatus
            ? $product->sales_status
            : ProductSalesStatus::tryFrom((string) $product->sales_status);

        if ($salesStatus !== ProductSalesStatus::ON_SALE) {
            throw OrderClaimException::newOptionInvalid();
        }

        // 전시 상태 — 숨긴 상품은 구매 자체가 막혀 있다.
        $displayStatus = $product->display_status instanceof ProductDisplayStatus
            ? $product->display_status
            : ProductDisplayStatus::tryFrom((string) $product->display_status);

        if ($displayStatus !== ProductDisplayStatus::VISIBLE) {
            throw OrderClaimException::newOptionInvalid();
        }

        // 구매 대상 제한 — 신청자가 살 수 없는 상품이면 교환도 받을 수 없다.
        // 신청자는 주문의 주인이다(비회원 주문은 null → 비회원 역할로 해석된다).
        $orderer = $option->order?->user;

        if (! $this->purchaseEligibility->isPurchasableBy($product, $this->purchaseEligibility->resolveRoleIds($orderer))) {
            throw OrderClaimException::newOptionInvalid();
        }
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

        foreach (['recipient_name', 'phone', 'zipcode', 'address1', 'address2', 'city', 'state', 'country_code'] as $field) {
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
            // 도시·주 칸이 생기기 전에 저장된 스냅샷에는 그 키가 없다 — 없는 칸은 비교하지 않는다.
            // 비교하면 해외 주문의 예전 신청서가 손대지 않았는데도 모두 '배송지 다름' 이 된다.
            if (! array_key_exists($field, $snapshot)) {
                continue;
            }

            if ($snapshot[$field] !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * 주문 배송지를 재발송지 스냅샷 형태로 반환합니다.
     *
     * 해외 주문은 국내 칸(우편번호·주소·상세주소)이 빈 문자열이라 해외 칸(우편번호·거리 주소 두 줄)으로
     * 채우고, 도시·주는 `city`·`state` 로 따로 담는다. 국내 주문은 두 칸이 null 이다.
     *
     * @param  Order  $order  주문
     * @return array{recipient_name: string|null, phone: string|null, zipcode: string|null, address1: string|null, address2: string|null, city: string|null, state: string|null, country_code: string} 배송지
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
            'city' => $address?->intl_city ?: null,
            'state' => $address?->intl_state ?: null,
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
        // 회수지는 **상품마다** 자기 배송정책에서 해석한다(S4) — 분할 전 원 행의 배송 행이 정책을 가리킨다.
        $returnAddress = $this->resolveReturnAddressSnapshot($order, [['option' => $option]]);

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
            'return_address_snapshot' => $returnAddress,
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
     * 신청서에 저장된 환불 우선순위를 반환합니다. (저장값이 없으면 종전 기본값 PG 우선)
     *
     * 접수 뒤의 재계산(완료·수동 접수·완료 미리보기·신청 수정의 동결 스냅샷)이 고객이 고른
     * 우선순위를 그대로 이어받게 한다. 예전에는 저장 컬럼이 없어 이 경로들이 PG 우선으로 고정돼,
     * 마일리지 우선을 고른 신청서의 환불신청서가 PG 환불로 먼저 채워졌다(#599 validate P2-A 후속).
     *
     * @param  OrderExchange  $claim  신청서
     * @return RefundPriorityEnum 환불 우선순위
     */
    protected function refundPriorityOf(OrderExchange $claim): RefundPriorityEnum
    {
        $value = $claim->refund_priority;

        if ($value instanceof RefundPriorityEnum) {
            return $value;
        }

        return RefundPriorityEnum::tryFrom((string) $value) ?? RefundPriorityEnum::PG_FIRST;
    }

    /**
     * 주문의 기준통화 코드를 반환합니다.
     *
     * 판정은 클레임 서비스 공용 헬퍼(`CurrencyConversionService::resolveOrderBaseCurrency()`)가
     * 소유한다 — 스냅샷 → 설정 기본 통화. 예전의 `orders.currency` 폴백은 **결제 통화**라 기본
     * 통화와 다른 주문에서 신청서 통화가 틀렸다(#599 validate P2 #14).
     *
     * @param  Order  $order  주문
     * @return string 기준통화 코드
     */
    protected function resolveBaseCurrency(Order $order): string
    {
        return $this->currencyService->resolveOrderBaseCurrency($order);
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
            ExchangeStatusEnum::WITHDRAWN => ['withdrawn_at' => now()] + (isset($context['withdrawn_by_admin'])
                ? ['withdrawn_by_admin' => (int) $context['withdrawn_by_admin'], 'withdraw_reason' => $context['withdraw_reason'] ?? null]
                : []),
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
     * @param  array  $tracking  대상 행에 쓸 송장 (`prepareTransitionTracking()` — 없으면 빈 배열)
     * @return void 반환값 없음
     */
    protected function syncOptionStatuses(OrderExchange $exchange, ExchangeStatusEnum $target, ?int $processedBy, array $tracking = []): void
    {
        $exchange->loadMissing('exchangeOptions');
        $mode = $exchange->mode();

        $changes = [];

        foreach ($exchange->exchangeOptions as $item) {
            if ($item->option_status === $target || $item->option_status->isFinal()) {
                continue;
            }

            if (! $item->option_status->canTransitionTo($target, $mode)) {
                continue;
            }

            $previous = $item->option_status->value;

            $this->transitionItem($exchange, $item, $target, $processedBy, null, $tracking);

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
            $this->fireAfterCommit(fn () => HookManager::doAction(
                'sirsoft-ecommerce.claim.exchange.option_status_changed',
                $exchange->fresh(['exchangeOptions']),
                $changes,
                $target->value,
                $processedBy,
            ));
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
     *  - 재발송중: 후속 주문옵션을 만든다(멱등). 그 배송 행은 **이 행의** 재발송 송장을 싣는다(R11).
     *  - 재발송도착: 후속 주문옵션을 배송완료로 옮긴다.
     *  - 회수도착·발송도착: 이 행의 도착 시각(`collected_at`·`redelivered_at`)을 찍는다(비어 있을 때만).
     *  - 송장: 지시에 송장이 실려 있으면 쪼갠 뒤·상태를 바꾸기 전에 이 행에 쓴다(R4·R6).
     *
     *  - 수량 분할: 지시에 수량이 실려 있으면 **맨 앞에서** 행을 가른다. 순서가 계약이다 —
     *    분할이 먼저여야 승인의 재고 차감이 줄어든 수량만 차감하고, 반려의 원 주문옵션 복귀가
     *    전이분만 되돌린다. 분할은 이 저장점 안에서 일어나므로, 전이가 실패하면 분할도 함께
     *    되돌아간다(밖에서 나누면 "갈라졌는데 전이는 실패한" 행이 남는다).
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @param  ExchangeStatusEnum  $target  목표 상태 (게이트 통과 전제)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  int|null  $quantity  이번에 전이할 수량 (null 이면 전량)
     * @param  array  $tracking  이 행에 쓸 송장 (`prepareTransitionTracking()` — 없으면 빈 배열)
     * @return OrderExchangeOption|null 분할로 생긴 잔여 행 (분할하지 않았으면 null)
     *
     * @throws OrderClaimException 승인 시 재고 부족 / 나눌 수 없는 행
     */
    protected function transitionItem(
        OrderExchange $exchange,
        OrderExchangeOption $item,
        ExchangeStatusEnum $target,
        ?int $processedBy,
        ?int $quantity = null,
        array $tracking = [],
    ): ?OrderExchangeOption {
        return DB::transaction(function () use ($exchange, $item, $target, $processedBy, $quantity, $tracking) {
            $remainder = null;

            if ($quantity !== null) {
                ['remainder' => $remainder] = $this->claimItemSplitter->split($item, $quantity);
                $item->refresh();
            }

            if (($tracking['direction'] ?? null) instanceof ClaimTrackingDirectionEnum) {
                $change = $this->applyItemTracking(
                    $exchange,
                    $item,
                    $tracking['direction'],
                    $tracking['carrier_id'] ?? null,
                    $tracking['tracking_number'] ?? null,
                    $processedBy,
                );

                if ($change !== null) {
                    $this->trackingChangeLog[$tracking['direction']->value][] = $change;
                }

                $item->refresh();
            }

            if ($target === ExchangeStatusEnum::APPROVED) {
                $this->deductNewOptionStock($item);
            }

            $updates = [
                'option_status' => $target,
                'processed_by' => $processedBy ?? $item->processed_by,
            ];

            if ($target === ExchangeStatusEnum::COLLECTED && $item->collected_at === null) {
                $updates['collected_at'] = now();
            }

            if ($target === ExchangeStatusEnum::REDELIVERED && $item->redelivered_at === null) {
                $updates['redelivered_at'] = now();
            }

            $updated = $this->exchangeOptionRepository->update($item, $updates);

            if ($this->revertsOrderOptions($target)) {
                $this->revertOrderOptionFor($updated->loadMissing('orderOption'), (int) $updated->exchange_quantity);
            }

            if ($target === ExchangeStatusEnum::REDELIVERING) {
                $this->ensureNewOrderOption($exchange, $updated->loadMissing('orderOption'));
            }

            if ($target === ExchangeStatusEnum::REDELIVERED) {
                $this->ensureNewOrderOption($exchange, $updated->loadMissing('orderOption'));
                $this->markNewOrderOptionDelivered($updated->fresh());
            }

            return $remainder;
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
     * @param  array  $stampContext  단계 시각 외 부가 컬럼 (상품 칸 반려의 `reject_reason` — 헤더가 반려로 따라갈 때 사유를 지우지 않게)
     * @return void 반환값 없음
     */
    protected function syncHeaderFromOptions(OrderExchange $exchange, ?int $processedBy, array $stampContext = []): void
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

        $path = $this->headerPathTo($exchange->exchange_status, $target, $exchange->mode());

        if ($path === []) {
            return;
        }

        $current = $exchange;

        foreach ($path as $step) {
            $previous = $current->exchange_status;

            // 상품이 끌고 간 헤더 전이도 전이다 — 단계마다 before 훅을 쓰기 전에 낸다. 이 자리는 상품
            // 전이 트랜잭션 **안**이라 리스너가 던지면 이번 상품 전이까지 함께 되돌아간다(헤더만 막고
            // 상품만 옮기면 헤더와 상품이 갈린다). 필터는 없다 — 목표는 상품 상태가 정한 값이다.
            HookManager::doAction(
                'sirsoft-ecommerce.claim.exchange.before_status_change',
                $current,
                $previous->value,
                $step->value,
                ['processed_by' => $processedBy, 'source' => 'option_sync'],
            );

            $updates = ['exchange_status' => $step] + $this->stampFor($step, $stampContext);

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
     * 간선은 진행 방식의 전이표다 — 선교환은 승인에서 곧장 발송 단계로 간다.
     *
     * @param  ExchangeStatusEnum  $from  현재 헤더 상태
     * @param  ExchangeStatusEnum  $to  상품들이 모인 상태
     * @param  ExchangeModeEnum  $mode  진행 방식
     * @return array<int, ExchangeStatusEnum> 거쳐야 할 상태 목록 (목표 포함, 현재 제외)
     */
    protected function headerPathTo(ExchangeStatusEnum $from, ExchangeStatusEnum $to, ExchangeModeEnum $mode = ExchangeModeEnum::STANDARD): array
    {
        if ($from === $to) {
            return [];
        }

        $queue = [[$from, []]];
        $visited = [$from->value => true];

        while ($queue !== []) {
            [$node, $path] = array_shift($queue);

            foreach ($node->allowedTransitionsIn($mode) as $next) {
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
     * @param  int|null  $quantity  되돌릴 수량 (null 이면 그 주문옵션 전량)
     * @return void 반환값 없음
     */
    protected function revertOrderOptionFor(OrderExchangeOption $item, ?int $quantity = null): void
    {
        $orderOption = $item->orderOption;

        if (! $orderOption || ! in_array($orderOption->option_status, OrderStatusEnum::revertibleClaimCourseStatuses(), true)) {
            return;
        }

        $target = $item->original_option_status;

        if (! $target instanceof OrderStatusEnum) {
            return;
        }

        // 상품 단위 반려·철회는 **그 행의 수량만** 되돌린다. 전량으로 되돌리면 같은 주문옵션에
        // 남아 있는 다른 진행분까지 코스 밖으로 빠져나가고, 그 수량은 이후 취소가 코스 가드를
        // 통과해 **같은 상품이 두 번 환불된다**. 주문옵션 수량으로 클램프하는 것은 분할 이후
        // 행 수량이 주문옵션 수량보다 클 수 없기 때문이 아니라, 커질 수 있는 경로(잔여 병합·
        // 데이터 보정)가 생겼을 때 조용히 초과 전이하지 않게 하기 위해서다.
        $this->orderOptionService->changeStatusWithQuantity(
            $orderOption,
            $target,
            min($quantity ?? (int) $orderOption->quantity, (int) $orderOption->quantity),
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
        $this->fireAfterCommit(fn () => HookManager::doAction(
            'sirsoft-ecommerce.claim.exchange.status_changed',
            $exchange,
            $previous->value,
            $target->value,
        ));

        $specific = match ($target) {
            ExchangeStatusEnum::APPROVED => 'sirsoft-ecommerce.claim.exchange.approved',
            ExchangeStatusEnum::REJECTED => 'sirsoft-ecommerce.claim.exchange.rejected',
            ExchangeStatusEnum::WITHDRAWN => 'sirsoft-ecommerce.claim.exchange.withdrawn',
            ExchangeStatusEnum::COMPLETED => 'sirsoft-ecommerce.claim.exchange.completed',
            default => null,
        };

        if ($specific !== null) {
            $this->fireAfterCommit(fn () => HookManager::doAction($specific, $exchange));
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
        $this->fireAfterCommit(fn () => HookManager::doAction(
            'sirsoft-ecommerce.order_option.after_sync_status',
            [[
                'order_option_id' => (int) $successor->id,
                'order_id' => (int) $successor->order_id,
                'previous' => null,
                'new' => OrderStatusEnum::SHIPPING->value,
            ]],
            OrderStatusEnum::SHIPPING->value,
            'exchange_redelivery',
        ));

        $this->replicateShippingRow($item, $original, $successor);

        $this->exchangeOptionRepository->update($item, ['new_order_option_id' => $successor->id]);
    }

    /**
     * 원 주문옵션의 배송 행을 후속 옵션에 복사합니다. (정책 승계 + **이 행의** 재발송 송장 — R11)
     *
     * 송장은 신청서가 아니라 행의 것이다 — 두 상자로 나눠 보낸 교환품은 행마다 다른 운송장을 갖고,
     * 구매자 주문 화면의 배송 조회가 그 행의 운송장을 읽어야 한다(S9). 송장 없이 곧장 발송도착한
     * 행은 송장 칸이 비고, 이어지는 배송완료 처리가 상태를 옮긴다.
     *
     * @param  OrderExchangeOption  $item  신청 상품 행
     * @param  OrderOption  $original  원 주문옵션
     * @param  OrderOption  $successor  후속 주문옵션
     * @return void 반환값 없음
     */
    protected function replicateShippingRow(OrderExchangeOption $item, OrderOption $original, OrderOption $successor): void
    {
        $shipping = $this->orderShippingRepository->findFirstByOrderOptionId($original->id);

        if (! $shipping) {
            return;
        }

        // 배송비는 원 행에 남긴다 — 이 재발송은 고객이 낸 배송이 아니다. 금액째 복제하면 교환품 줄이
        // 「유료배송 · 배송비」 로 보이고 구매자 「배송 현황」 에 같은 배송비가 한 줄 더 생긴다(S1-D11, 수량 분할 복제본과 같은 규칙 S3-D9).
        $this->orderShippingRepository->replicateForOrderOption($shipping, $successor->id, [
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'total_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'return_shipping_amount' => 0,
            'mc_base_shipping_amount' => null,
            'mc_extra_shipping_amount' => null,
            'mc_total_shipping_amount' => null,
            'mc_shipping_discount_amount' => null,
            'mc_return_shipping_amount' => null,
            'shipping_status' => ShippingStatusEnum::SHIPPED,
            'carrier_id' => $item->redelivery_carrier_id,
            'tracking_number' => $item->redelivery_tracking_number,
            'shipped_at' => $item->redelivery_shipped_at ?? now(),
        ]);
    }

    /**
     * 후속 주문옵션과 그 배송 행을 배송완료로 옮깁니다. (재발송도착 · 발송중 완료)
     *
     * 후속 주문옵션은 `shipping` 으로 생겨 구매자가 발송중에 구매확정할 수 있다. 확정은 받았다는
     * 뜻이므로 상태는 그대로 두고 배송 행만 도착으로 옮긴다 — 배송완료로 되돌리면 구매확정 → 배송완료
     * 역전이를 전이 가드가 거절해 도착·완료 처리가 통째로 막힌다.
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

        if ($successor->option_status !== OrderStatusEnum::CONFIRMED) {
            $this->orderOptionService->changeStatusWithQuantity(
                $successor,
                OrderStatusEnum::DELIVERED,
                (int) $successor->quantity,
                [],
                restoreStockOnCancel: false,
                mergeSiblings: false,
            );
        }

        // 배송 행도 배송완료로 옮긴다 — 구매자 주문상세 「배송 현황」 카드는 배송 행을 읽으므로, 상품만 옮기면
        // 상품 줄은 배송완료인데 카드는 배송중으로 남았다(#599 후속 실주문 실측). 상태 변경이 송장을 싣지 않아
        // 주문 쪽 동기화(`applyShippingTracking`)를 타지 않으므로 여기서 직접 옮긴다.
        $this->orderShippingRepository->updateTrackingByOrderOptionId((int) $successor->id, [
            'shipping_status' => ShippingStatusEnum::DELIVERED->value,
            'delivered_at' => now(),
        ]);
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
     * 원 주문옵션을 교환완료(터미널)로 전이하고, 실제로 전이된 행을 돌려줍니다.
     *
     * 부분 수량이면 `changeStatusWithQuantity()` 가 완료분을 **새 행(split)** 으로 갈라 교환완료로
     * 옮기고 원 행은 잔여로 코스에 남긴다. 호출부는 재고 플래그·적립 회수를 그 새 행에 적용해야
     * 한다(#599 validate P2 #4).
     *
     * @param  OrderOption  $orderOption  원 주문옵션
     * @param  int  $quantity  완료 수량
     * @return OrderOption 교환완료가 된 행 (분할이면 새 행, 전량이면 원 행)
     */
    protected function markOrderOptionExchanged(OrderOption $orderOption, int $quantity): OrderOption
    {
        if ($orderOption->option_status === OrderStatusEnum::EXCHANGED) {
            return $orderOption;
        }

        $result = $this->orderOptionService->changeStatusWithQuantity(
            $orderOption,
            OrderStatusEnum::EXCHANGED,
            min($quantity, (int) $orderOption->quantity),
            [],
            restoreStockOnCancel: false,
            mergeSiblings: false,
        );

        return $result['split'] ?? $result['merged_into'] ?? $orderOption;
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

        // 나누는 순서는 접수 때 고객이 고른 우선순위다(저장값 없으면 종전 PG 우선).
        [$pgRefund, $pointsRefund] = $this->splitRefundByPaymentCapacity($order, $refundAmount, $this->refundPriorityOf($exchange));

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
                    && $this->isCompletableItem($item, $exchange->mode())) {
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
            // 돌려줄 현금이 없는 회원 주문은 결제수단과 무관하게 「마일리지」다(결함 4).
            'refund_method' => $this->refundExecutionService->refundMethodForClaim($order),
            'reason_code' => $exchange->reason_code,
            'reason_detail' => $exchange->reason_detail,
            'fault_type' => $exchange->fault_type,
            'requested_by' => $exchange->requested_by,
            'refund_amount' => $pgRefund,
            'refund_points_amount' => $pointsRefund,
            // 이 환불액을 나눈 우선순위 — 교환 접수 때 고른 값이 그대로 이어진다.
            'refund_priority' => $this->refundPriorityOf($exchange),
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
                'option_status' => RefundStatusEnum::REQUESTED,
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
        $this->fireAfterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.claim.refund.requested', $refund->fresh(), $order->fresh()));

        // 신청상품 행 생성도 상품 축에 남긴다(§7-1 C11) — 헤더 줄만 남기면 그 환불이 어떤
        // 상품 몇 개를 담고 생겼는지 이력에서 되짚을 수 없다.
        app(OrderRefundRequestService::class)->announceRefundOptionsCreated($refund->fresh());

        return $refund->fresh();
    }

    /**
     * 환불액을 주문이 실제로 낸 돈에 맞춰 PG 환불액과 마일리지 환불액으로 나눕니다.
     *
     * `OrderAdjustmentService::calculate()` 의 배분 규칙과 같다 — PG 우선이면 결제수단 잔여 한도를 먼저
     * 채우고 남는 몫을 마일리지 잔여 한도에서, 마일리지 우선이면 그 반대 순서로 채운다. 반품의 환불신청서가 그 계산 결과를 싣는 것과 같은
     * 분할을 **동결 차액**에 적용한다 — 전액 마일리지 결제 주문의 차액을 PG 칸에 실으면 환불 수단은
     * 마일리지인데 금액은 PG 환불액인 어긋난 신청서가 된다. 두 잔여 한도가 환불액에 못 미치는 몫은
     * PG 칸에 남긴다 — 잘라내면 헤더와 상품 행 합계가 어긋나고, PG 칸이 운영자가 수정하는 자리다(D18).
     *
     * @param  Order  $order  주문
     * @param  float  $refundAmount  나눌 환불액 (차감 배송비 제외)
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위 (교환 접수 때 고른 값)
     * @return array{0: float, 1: float} [PG 환불액, 마일리지 환불액]
     */
    protected function splitRefundByPaymentCapacity(
        Order $order,
        float $refundAmount,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): array {
        $remainingPg = max(0.0, (float) $order->total_paid_amount - (float) $order->total_refunded_amount);
        $remainingPoints = max(0.0, (float) $order->total_points_used_amount - (float) $order->total_refunded_points_amount);

        if ($refundPriority === RefundPriorityEnum::POINTS_FIRST) {
            $points = min($refundAmount, $remainingPoints);
            $pg = min($refundAmount - $points, $remainingPg);
        } else {
            $pg = min($refundAmount, $remainingPg);
            $points = min($refundAmount - $pg, $remainingPoints);
        }

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
        // 「살아 있는 상품 0 그리고 완료 상품 ≥ 1」(S8) — 반려·철회된 상품이 섞여도 나머지가 모두
        // 완료되면 신청서는 완료다. 예전 판정(모든 행 완료)은 반려 행 하나가 헤더를 영원히 묶었다.
        $items = $exchange->exchangeOptions;

        return $items->contains(fn (OrderExchangeOption $item) => $item->option_status === ExchangeStatusEnum::COMPLETED)
            && ! $items->contains(fn (OrderExchangeOption $item) => ! $item->option_status->isFinal());
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
     * 실제로 바뀐 항목은 커밋 뒤 `claim.exchange.request_updated` 로 전/후와 함께 나간다 — 운영자가 고객 대신
     * 사유를 바꾸면 왕복비를 누가 내는지가 달라지므로 활동 로그가 그 흔적을 남긴다(`ReportsClaimRequestUpdate`).
     * 재발송지는 개인정보라 바뀐 사실만 싣는다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array  $payload  수정 페이로드 (reason_code? / reason_detail? / redelivery_address? / charge_method?)
     * @param  bool  $operator  운영자 수정 여부 (S15 — 판매자 귀책 사유 허용, 판매자 접수 건도 수정)
     * @return OrderExchange 수정된 신청서
     *
     * @throws OrderClaimException 수정 불가 상태 / 사유 부재 / 국가 변경 / 청구 방식 부재·불허·차감 불가
     */
    public function updateRequest(OrderExchange $exchange, array $payload, bool $operator = false): OrderExchange
    {
        // 판매자가 접수한 신청은 고객이 수정할 수 없다(D8). 운영자 수정(S15)은 통과한다.
        if (! $operator && $exchange->isAdminRequested()) {
            throw OrderClaimException::adminRequestedNotEditable();
        }

        // 수정의 before·filter 훅 — 잠금·쓰기 전이다. 필터는 사유·재발송지
        // (`CLAIM_FILTERABLE_UPDATE_KEYS`)만 바꿀 수 있고, 바뀐 값은 아래 사유 해석·국가 불변 규칙·
        // 배송비 재산정을 고객 입력과 똑같이 지난다. 청구 방식·환불 계좌는 돈의 경로라 되돌린다.
        HookManager::doAction('sirsoft-ecommerce.claim.exchange.before_update_request', $exchange, $payload);

        $payload = $this->acceptClaimFilterResult(
            HookManager::applyFilters('sirsoft-ecommerce.claim.exchange.filter_update_request_data', $payload, $exchange),
            $payload,
            self::CLAIM_FILTERABLE_UPDATE_KEYS,
        );

        return DB::transaction(function () use ($exchange, $payload, $operator) {
            $locked = $this->exchangeRepository->findForUpdate($exchange->id) ?? $exchange;

            // 수정 가능 여부는 **잠금 안에서 다시 읽은 상태**로 판정한다(#599 validate P2 #8).
            // 호출부 모델로 판정하면 그 사이 승인된 신청서의 사유·재발송지·청구가 수정된다.
            if (! $locked->exchange_status->isWithdrawable()) {
                throw OrderClaimException::notEditable();
            }

            $locked->load(['exchangeOptions', 'order.options', 'order.shippings', 'order.payment', 'order.shippingAddress']);
            $order = $locked->order;
            $before = $this->claimRequestUpdateSnapshot($locked);

            $updates = [];
            $reason = null;

            if (($payload['reason_code'] ?? null) !== null && $payload['reason_code'] !== '' && $payload['reason_code'] !== $locked->reason_code) {
                $reason = $this->resolveReason((string) $payload['reason_code'], ! $operator);
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

            if (array_key_exists('redelivery_address', $payload) && $payload['redelivery_address'] === null) {
                // 명시적 null = 바꿔 둔 재발송지를 버리고 원 배송지로 되돌린다(수정 화면의
                // [원 배송지로 되돌리기]). 키가 없는 것("손대지 않음")과 구분해야 화면이 보여 주는
                // 주소와 신청서에 남는 주소가 갈리지 않는다. 직전 스냅샷을 base 로 쓰지 않는다.
                $redelivery = $this->buildRedeliveryAddressSnapshot($order, null);
                $updates['redelivery_address_snapshot'] = $redelivery;
            } elseif (! empty($payload['redelivery_address']) && is_array($payload['redelivery_address'])) {
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
                    $this->refundPriorityOf($locked),
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
                    // 동결 스냅샷은 접수 때와 같은 우선순위로 다시 낸다(저장값 없으면 종전 PG 우선).
                    $this->refundPriorityOf($locked),
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

            $updated = $this->exchangeRepository->update($locked, $updates)->fresh(['exchangeOptions']);

            // 같은 값으로 다시 저장한 수정은 알리지 않는다 — 활동 로그에 빈 이력이 쌓이면 실제로 바뀐 줄이 묻힌다.
            $changes = $this->claimRequestUpdateChanges($before, $this->claimRequestUpdateSnapshot($updated));

            if ($changes !== []) {
                $context = $this->claimRequestUpdateContext($operator);
                $this->fireAfterCommit(fn () => HookManager::doAction(
                    'sirsoft-ecommerce.claim.exchange.request_updated',
                    $updated,
                    $changes,
                    $context,
                ));
            }

            return $updated;
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

    /**
     * 신청 자격 상태 목록을 반환합니다. (운영자 직권 접수면 물리 게이트 — D5)
     *
     * @param  bool  $operator  운영자 직권 접수 여부
     * @return array<string> 상태 값 목록
     */
    protected function requestStatusValuesFor(bool $operator): array
    {
        return $operator ? ClaimPhysicalGate::statuses(ClaimTypeEnum::EXCHANGE) : $this->requestableStatusValues();
    }

    /**
     * 운영자가 정한 클레임 배송비로 산정 결과를 덮습니다. (D7 — 직권 접수 창의 배송비 수정·무료)
     *
     * 산정 근거(그룹·모드)는 그대로 두고 금액만 바꾼다 — 운영자가 고친 사실은 신청서 스냅샷의
     * `admin_adjusted` 표시로 남는다(접수가 산정 근거 스냅샷에 싣는다 — 금액 수정 경로와 같은 표시).
     *
     * @param  ClaimShippingFee  $shippingFee  산정 결과
     * @param  float|null  $override  운영자가 정한 배송비 (null 이면 산정값 그대로)
     * @param  array  $currencySnapshot  주문 통화 스냅샷
     * @return ClaimShippingFee 덮은 결과
     */
    protected function applyShippingFeeOverride(ClaimShippingFee $shippingFee, ?float $override, array $currencySnapshot): ClaimShippingFee
    {
        if ($override === null) {
            return $shippingFee;
        }

        $fee = (int) round(max(0.0, $override));

        return new ClaimShippingFee(
            fee: $fee,
            mode: $shippingFee->mode,
            claimType: $shippingFee->claimType,
            fault: $shippingFee->fault,
            charged: $fee > 0,
            reason: $shippingFee->reason,
            groups: $shippingFee->groups,
            extraFee: 0,
            mcFee: $fee > 0 ? ($this->toMultiCurrency((float) $fee, $currencySnapshot) ?? []) : [],
            redeliveryChanged: $shippingFee->redeliveryChanged,
        );
    }
}
