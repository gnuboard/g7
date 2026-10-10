<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\DTO\ReturnAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyClaimAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\AppliesRefundBankAccount;
use Modules\Sirsoft\Ecommerce\Services\Concerns\FiresClaimHooksAfterCommit;
use Modules\Sirsoft\Ecommerce\Services\Concerns\GuardsClaimFilterResults;
use Modules\Sirsoft\Ecommerce\Services\Concerns\RecordsClaimTracking;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ReportsClaimRequestUpdate;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemSpecs;
use Modules\Sirsoft\Ecommerce\Support\ClaimPhysicalGate;
use Modules\Sirsoft\Ecommerce\Support\SystemActor;

/**
 * 반품 클레임 서비스
 *
 * 반품 신청서(`ecommerce_order_returns`)의 신청·철회·상태 전이·회수 송장·금액 수정·완료
 * 처리를 담당한다. 금액 재계산은 `OrderAdjustmentService`(+`ReturnAdjustment`)에, 회수
 * 배송비 산정은 `ClaimShippingFeeService` 에, 환불 실행은 `RefundExecutionService` 에
 * 위임하고, 이 서비스는 **반품 라이프사이클의 기록과 순서**만 소유한다.
 *
 * ## 코스 상태는 신청 접수 시점에 들어간다
 *
 * 원 주문옵션은 신청이 접수되는 순간 `RETURN_IN_PROGRESS` 로 전이한다(부분 수량이면 분할).
 * 승인 시점으로 미루면 접수~승인 사이에 같은 수량이 취소·다른 클레임으로 빠져나갈 수 있고,
 * 반려·철회 시 되돌릴 대상(신청 직전 상태)도 생기지 않는다. 되돌리기는
 * `OrderReturnOption::original_option_status` 를 근거로 **상품옵션 단위**로 수행한다.
 *
 * 이때 두 인자를 반드시 명시한다:
 *  - `restoreStockOnCancel: false` — 판매→코스 전이는 취소 전이로 분류되어 기본값이면 신청
 *    접수 순간 재고가 자동 복원된다. 재고 복구의 유일한 주체는 완료 처리(운영자 선택)다.
 *  - `mergeSiblings: false` — 병합은 피흡수 주문옵션 행을 삭제하는데 신청 상품 행이 그 행을
 *    FK(cascade)로 참조하므로 신청 이력이 함께 사라지고, 합쳐진 행은 어느 신청서 몫인지
 *    구분되지 않아 신청서 단위 복귀·완료가 불가능해진다.
 *
 * ## 주문 상태는 진행 중에는 건드리지 않는다
 *
 * 코스 4종은 `OrderStatusEnum::syncExcludedStatuses()` 에 속해 주문 상태 동기화 대상이
 * 아니다. 신청·진행 단계는 주문 상태를 바꾸지 않는다. 완료 처리 끝에서만, 살아 있는 상품이
 * 하나도 남지 않으면 주문을 반품완료로 바꾼다(`OrderOptionService::syncFullyReturnedOrderStatus()`) —
 * 부분 반품은 주문 상태를 그대로 둔다.
 *
 * ## 완료 처리와 환불신청서 접수는 분리돼 있다 (D26)
 *
 * `completeReturn()` 은 `ClaimCompletionOptions` 를 필수 인자로 받는다. 설정
 * (`claim.complete_defaults.*`)은 모달의 초기 선택값일 뿐이며 이 서비스는 읽지 않는다 —
 * 읽으면 "요청에 없으면 설정대로" 라는 숨은 자동 실행이 되살아난다.
 */
class OrderReturnService
{
    use AppliesRefundBankAccount;
    use FiresClaimHooksAfterCommit;
    use GuardsClaimFilterResults;
    use RecordsClaimTracking;
    use ReportsClaimRequestUpdate;

    /**
     * 이번 요청에서 전이와 함께 쓴 행 송장 변경 (방향 값 → 변경 목록)
     *
     * 상품마다 저장점 안에서 쓰므로 모아 두었다가 커밋 뒤 `after_tracking_update` 로 한 번 알린다.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $trackingChangeLog = [];

    /**
     * @param  OrderAdjustmentService  $adjustmentService  환불금액 재계산 서비스
     * @param  ClaimShippingFeeService  $claimShippingFeeService  클레임 배송비 산정 서비스
     * @param  ClaimQuantityGuard  $claimQuantityGuard  클레임 수량 잠금 가드 (D15)
     * @param  ClaimItemSplitter  $claimItemSplitter  신청 상품 수량 분할기
     * @param  OrderOptionService  $orderOptionService  주문 옵션 서비스 (코스 상태 전이·수량 분할)
     * @param  RefundExecutionService  $refundExecutionService  환불 실행 서비스 (환불수단·상세행·쿠폰 복원)
     * @param  UserMileageService  $mileageService  마일리지 서비스 (적립 회수)
     * @param  StockService  $stockService  재고 서비스
     * @param  SequenceService  $sequenceService  채번 서비스
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     * @param  OrderRepositoryInterface  $orderRepository  주문 Repository
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 신청서 Repository
     * @param  OrderReturnOptionRepositoryInterface  $returnOptionRepository  반품 신청 상품 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불 신청서 Repository
     * @param  OrderRefundOptionRepositoryInterface  $refundOptionRepository  환불 상세 Repository
     * @param  ClaimReasonRepositoryInterface  $claimReasonRepository  클레임 사유 Repository
     * @param  ShippingPolicyClaimAddressRepositoryInterface  $claimAddressRepository  배송정책 회수지 Repository
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스 (다통화 금액 병기)
     */
    public function __construct(
        protected OrderAdjustmentService $adjustmentService,
        protected ClaimShippingFeeService $claimShippingFeeService,
        protected ClaimQuantityGuard $claimQuantityGuard,
        protected ClaimItemSplitter $claimItemSplitter,
        protected OrderOptionService $orderOptionService,
        protected RefundExecutionService $refundExecutionService,
        protected UserMileageService $mileageService,
        protected StockService $stockService,
        protected SequenceService $sequenceService,
        protected EcommerceSettingsService $settingsService,
        protected CurrencyConversionService $currencyService,
        protected OrderRepositoryInterface $orderRepository,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderReturnOptionRepositoryInterface $returnOptionRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderRefundOptionRepositoryInterface $refundOptionRepository,
        protected ClaimReasonRepositoryInterface $claimReasonRepository,
        protected ShippingPolicyClaimAddressRepositoryInterface $claimAddressRepository,
    ) {}

    // ───────────────────────────────────────────────
    // 신청 / 미리보기
    // ───────────────────────────────────────────────

    /**
     * 반품 신청 결과를 미리 계산합니다. (DB 변경 없음)
     *
     * 신청 화면이 사유·수량·청구 방식을 바꿀 때마다 부르는 경로이며, 실제 신청
     * (`requestReturn`)이 기록할 값과 같은 산식을 쓴다 — 미리보기와 신청 결과가 갈리면
     * 고객이 본 금액과 청구된 금액이 달라진다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $items  신청 대상 [{order_option_id, quantity}]
     * @param  string  $reasonCode  반품 사유 코드
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식 (청구 0원이면 null)
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  int|null  $excludeReturnId  수량 잠금 집계에서 뺄 반품 신청서 ID (수정 화면의 미리보기)
     * @param  bool  $operator  운영자 직권 접수 미리보기 여부 (D5 — 기간·신청가능상태 대신 물리 게이트, 판매자 귀책 사유 허용)
     * @param  float|null  $shippingFeeOverride  운영자가 정한 클레임 배송비 (D7 — null 이면 산정값)
     * @return array{shipping_fee: ClaimShippingFee, adjustment: AdjustmentResult, fault_type: ClaimReasonFaultTypeEnum, charge_method: ClaimChargeMethodEnum|null, total_charge_amount: float, mc_total_charge_amount: array|null, deducted_shipping_fee: float, refund_before_deduction: float, mc_refund_before_deduction: array|null, requires_payment: bool} 미리보기 결과
     *
     * @throws OrderClaimException 사유 부재 / 신청 자격 위반
     */
    public function previewReturn(
        Order $order,
        array $items,
        string $reasonCode,
        ?ClaimChargeMethodEnum $chargeMethod = null,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
        ?int $excludeReturnId = null,
        bool $operator = false,
        ?float $shippingFeeOverride = null,
    ): array {
        $reason = $this->resolveReason($reasonCode, ! $operator);

        // 신청과 같은 자격 가드(상태·수량·잠금·기간) — 신청할 수 없는 조합의 금액을 보여 주지 않는다.
        $resolved = $this->claimQuantityGuard->resolveClaimItems(
            $order,
            $this->normalizeItemQuantities($items),
            ClaimTypeEnum::RETURN,
            $this->requestStatusValuesFor($operator),
            null,
            $excludeReturnId,
        );

        if (! $operator) {
            $this->assertWithinRequestPeriod($resolved);
        }

        $claimItems = $this->toClaimItemsFromResolved($resolved);

        $shippingFee = $this->applyShippingFeeOverride($this->claimShippingFeeService->calculate(
            $order,
            ClaimTypeEnum::RETURN,
            $claimItems,
            $reason->fault_type,
        ), $shippingFeeOverride, $order->currency_snapshot ?? []);

        $adjustment = new ReturnAdjustment(
            returnItems: $claimItems,
            collectionFee: $shippingFee->fee,
            chargeMethod: $chargeMethod,
        );

        $result = $this->adjustmentService->preview($order, $adjustment, $refundPriority);

        $refundBeforeDeduction = $this->refundBeforeDeduction($order, $claimItems, $adjustment, $result, $refundPriority);

        return [
            'shipping_fee' => $shippingFee,
            'adjustment' => $result,
            'fault_type' => $reason->fault_type,
            'charge_method' => $chargeMethod,
            'total_charge_amount' => (float) $shippingFee->fee,
            // 접수 시 신청서에 박제되는 값(`mc_total_charge_amount`)과 같은 출처를 쓴다 — 반품의
            // 청구액은 회수 배송비 그 자체이므로 배송비 산정이 이미 낸 다통화 값을 그대로 옮긴다.
            'mc_total_charge_amount' => $shippingFee->mcFee ?: null,
            // 환불금 차감 방식은 `adjustment.refund_total` 이 **이미 회수비를 뺀 뒤**의 금액이다
            // (`ReturnAdjustment::getAdditionalCharges()`). 그래서 화면이 그 값만 받으면 §6-3 이
            // 반품 요약에 요구하는 세 줄(환불예정 / 회수비 / 최종) 가운데 첫 줄을 세울 수 없고,
            // 화면이 스스로 되더하면 예고와 청구의 근거가 갈린다. 차감액과 차감 전 금액을 서버가
            // 함께 낸다 — 차감하지 않는 방식에서는 차감액이 0 이고 두 값이 같아진다.
            'deducted_shipping_fee' => (float) $adjustment->getAdditionalCharges(),
            'refund_before_deduction' => $refundBeforeDeduction,
            // 다통화 표기도 형제 줄과 같은 규약이다(§5-4). 환율은 현재 설정이 아니라 주문
            // currency_snapshot 의 동결 환율을 쓴다 — 화면이 환산하면 예고와 청구의 근거가 갈린다.
            'mc_refund_before_deduction' => $this->toMultiCurrencyOrNull(
                $refundBeforeDeduction,
                $order->currency_snapshot ?? [],
            ),
            // 차감액 줄도 같은 규약이다 — 반품 요약 3줄(차감 전 · 차감 · 최종) 중 가운데만
            // 기준 통화 한 줄로 남으면 위아래와 표기가 갈린다(S17 트랙 A F-1).
            'mc_deducted_shipping_fee' => $this->toMultiCurrencyOrNull(
                (float) $adjustment->getAdditionalCharges(),
                $order->currency_snapshot ?? [],
            ),
            'requires_payment' => $shippingFee->fee > 0 && $chargeMethod?->requiresPayment() === true,
        ];
    }

    /**
     * 회수 배송비를 빼기 전의 환불 예정액을 돌려줍니다. (§6-3 반품 요약 첫 줄)
     *
     * 차감하지 않는 방식에서는 차감이 없으므로 확정된 환불액이 곧 차감 전 금액이다.
     *
     * 차감 방식에서는 **회수비를 0 으로 둔 같은 산식**을 한 번 더 돌려 얻는다. 확정 환불액에
     * 회수비를 되더하는 방법은 두 경우에 실제보다 큰 금액을 내놓는다 — ① 결제 여력
     * (`total_paid_amount - total_refunded_amount`)이 상한이 되어 환불액이 이미 잘린 주문
     * (앞선 클레임이 일부를 환불한 경우) ② 회수비가 환불 대상 금액 전부를 먹어 환불액이
     * 0 으로 바닥친 경우. 두 경우 모두 세 줄의 뺄셈은 맞아떨어지므로 화면만 보고는 첫 줄이
     * 부풀었다는 것을 알 수 없다.
     *
     * 재계산을 한 번 더 하는 비용은 차감 방식에서만 든다.
     *
     * @param  Order  $order  주문
     * @param  array  $claimItems  신청 상품 [{order_option_id, quantity}]
     * @param  ReturnAdjustment  $adjustment  차감 방식이 반영된 조정 DTO
     * @param  AdjustmentResult  $result  그 조정의 계산 결과
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return float 차감 전 환불 예정액
     */
    protected function refundBeforeDeduction(
        Order $order,
        array $claimItems,
        ReturnAdjustment $adjustment,
        AdjustmentResult $result,
        RefundPriorityEnum $refundPriority,
    ): float {
        if ($adjustment->getAdditionalCharges() <= 0) {
            return (float) $result->getTotalRefundAmount();
        }

        $withoutDeduction = $this->adjustmentService->preview(
            $order,
            new ReturnAdjustment(returnItems: $claimItems, collectionFee: 0, chargeMethod: null),
            $refundPriority,
        );

        return (float) $withoutDeduction->getTotalRefundAmount();
    }

    /**
     * 금액을 주문 통화 스냅샷의 동결 환율로 다통화 표기로 변환합니다.
     *
     * 스냅샷이 없는 주문은 `null` 을 돌려준다 — 그 줄은 화면에 서지 않는다. 현재 상점 설정의
     * 환율로 대신 환산하면 거래 시점 통화로 동결한다는 규약이 깨진다.
     *
     * @param  float  $amount  기준 통화 금액
     * @param  array  $currencySnapshot  주문 통화 스냅샷
     * @return array|null 다통화 표기 (스냅샷 부재 시 null)
     */
    protected function toMultiCurrencyOrNull(float $amount, array $currencySnapshot): ?array
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

    /**
     * 반품을 신청합니다.
     *
     * 주문을 배타적으로 잠근 뒤 수량 잠금(D15)을 검증하고, 신청서·신청 상품·코스 상태
     * 전이를 한 트랜잭션에서 기록한다. 두 요청이 같은 잔여 수량을 보고 각각 통과하는
     * 경합을 잠금과 트랜잭션 경계로 직렬화한다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $payload  신청 페이로드
     *                          - items: [{order_option_id, quantity}]
     *                          - reason_code: 사유 코드
     *                          - reason_detail: 상세 사유 (선택)
     *                          - charge_method: ClaimChargeMethodEnum|string|null
     *                          - requested_by: 신청자 ID (비회원이면 null)
     *                          - refund_priority: RefundPriorityEnum (선택)
     * @return OrderReturn 생성된 반품 신청서
     *
     * @throws OrderClaimException 신청 자격·수량·사유·청구 방식 위반
     */
    public function requestReturn(Order $order, array $payload): OrderReturn
    {
        // 접수의 before·filter 훅 — 주문 잠금·쓰기 전이다. 필터는 대상 상품·사유
        // (`CLAIM_FILTERABLE_REQUEST_KEYS`)만 바꿀 수 있고, 바뀐 값은 아래 사유 해석·잠금 안 수량
        // 가드를 고객 입력과 똑같이 지난다. 신청자·청구 방식·환불 우선순위·계좌는 되돌린다.
        HookManager::doAction('sirsoft-ecommerce.claim.return.before_request', $order, $payload);

        $payload = $this->acceptClaimFilterResult(
            HookManager::applyFilters('sirsoft-ecommerce.claim.return.filter_request_data', $payload, $order),
            $payload,
            self::CLAIM_FILTERABLE_REQUEST_KEYS,
        );

        $operator = (bool) ($payload['operator'] ?? false);
        $reason = $this->resolveReason((string) ($payload['reason_code'] ?? ''), ! $operator);
        $chargeMethod = $this->normalizeChargeMethod($payload['charge_method'] ?? null);
        $refundPriority = $payload['refund_priority'] ?? RefundPriorityEnum::PG_FIRST;
        $requestedBy = $payload['requested_by'] ?? null;
        $feeOverride = isset($payload['shipping_fee_override']) ? (float) $payload['shipping_fee_override'] : null;

        $orderReturn = DB::transaction(function () use ($order, $payload, $reason, $chargeMethod, $refundPriority, $requestedBy, $operator, $feeOverride) {
            // 잠금은 잔여 수량을 읽기 전에 잡는다 — 읽고 나서 잠그면 이미 두 요청이 같은 값을 봤다.
            $lockedOrder = $this->orderRepository->findForUpdateOrFail($order->id);
            $lockedOrder->load(['options', 'shippings', 'payment']);

            $resolved = $this->claimQuantityGuard->resolveClaimItems(
                $lockedOrder,
                $payload['items'] ?? [],
                ClaimTypeEnum::RETURN,
                $this->requestStatusValuesFor($operator),
            );

            // 운영자 직권 접수는 신청 가능 기간을 무시한다(D5) — 물리 게이트는 위 상태 목록이 이미 건다.
            if (! $operator) {
                $this->assertWithinRequestPeriod($resolved);
            }

            $claimItems = $this->toClaimItemsFromResolved($resolved);

            $shippingFee = $this->applyShippingFeeOverride($this->claimShippingFeeService->calculate(
                $lockedOrder,
                ClaimTypeEnum::RETURN,
                $claimItems,
                $reason->fault_type,
            ), $feeOverride, $lockedOrder->currency_snapshot ?? []);

            $this->assertChargeMethodUsable($lockedOrder, $claimItems, $shippingFee, $chargeMethod, $refundPriority);

            // 접수 시점 주문금액 비교 스냅샷(전/후) — 미리보기(`previewReturn`)와 같은 재료로 한 번 더
            // 계산해 동결한다. 상세가 나중에 재계산하면 이후 클레임·쿠폰 변경이 섞여 접수 당시와 다른 표가 된다.
            $frozen = $this->adjustmentService->preview(
                $lockedOrder,
                new ReturnAdjustment(returnItems: $claimItems, collectionFee: $shippingFee->fee, chargeMethod: $chargeMethod),
                $refundPriority,
            );

            $return = $this->returnRepository->create([
                'order_id' => $lockedOrder->id,
                'return_number' => $this->sequenceService->generateCode(SequenceType::ORDER_RETURN),
                'return_status' => ReturnStatusEnum::REQUESTED,
                'reason_code' => $reason->code,
                'reason_detail' => $payload['reason_detail'] ?? null,
                'fault_type' => $reason->fault_type,
                'charge_method' => $shippingFee->fee > 0 ? $chargeMethod : null,
                'shipping_fee_mode' => $shippingFee->mode,
                'claim_shipping_fee' => $shippingFee->fee,
                'mc_claim_shipping_fee' => $shippingFee->mcFee ?: null,
                'shipping_fee_snapshot' => $shippingFee->toArray() + ($feeOverride !== null ? ['admin_adjusted' => true] : []),
                'total_charge_amount' => $shippingFee->fee,
                'mc_total_charge_amount' => $shippingFee->mcFee ?: null,
                'currency_code' => $this->resolveBaseCurrency($lockedOrder),
                // 접수 계산에 쓴 환불 우선순위 — 응답·수정 화면이 추측하지 않고 이 값을 읽는다.
                'refund_priority' => $refundPriority,
                'items_snapshot' => $this->buildItemsSnapshot($resolved),
                'return_address_snapshot' => $this->resolveReturnAddressSnapshot($lockedOrder, $resolved),
                'requested_by' => $requestedBy,
                'requested_by_admin' => $payload['requested_by_admin'] ?? null,
                'original_calculation_snapshot' => $frozen->originalSnapshot,
                'recalculated_snapshot' => $frozen->recalculatedSnapshot,
            ] + $this->refundBankColumns($payload['refund_bank'] ?? null));

            foreach ($resolved as $entry) {
                $this->createReturnOption($return, $lockedOrder, $entry['option'], $entry['quantity']);
            }

            return $return->fresh(['returnOptions']);
        });

        // 운영자 직권 접수는 컨텍스트 `source = admin` 을 함께 싣는다(§3-11 — 새 훅 없이 기존 신청 훅).
        $this->fireAfterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.claim.return.requested', $orderReturn, $order->fresh(), ['source' => $operator ? 'admin' : 'customer']));

        // 운영자 직권 접수의 승인은 호출부(`AdminClaimRequestService`)가 「접수와 함께 승인」 선택으로 정한다.
        if ($operator) {
            return $orderReturn;
        }

        // 청구액 0원 자동 승인 (§4-2 전이표 자동 열). 접수 훅을 먼저 발화한 뒤 전이하므로
        // 알림·로그 리스너는 '신청 → 승인' 두 사건을 순서대로 받는다. 전이는 transition() 에
        // 위임해 게이트·타임스탬프·상품 상태 동기화·훅이 수동 승인과 같은 경로를 타게 한다.
        if ($this->shouldAutoApproveZeroCharge($orderReturn)) {
            try {
                // 누른 사람이 없는 전이다 — 고객 요청 안에서 돌아도 작업자는 시스템이다(S1-D16).
                return SystemActor::run(fn () => $this->transition($orderReturn, ReturnStatusEnum::APPROVED));
            } catch (OrderClaimException $e) {
                // 접수는 이미 커밋됐고 접수 훅도 나갔다. 자동 승인만 실패했으면(접수 직후 다른 요청이
                // 상태를 바꾼 경우 등) 그 전이만 건너뛰고 접수를 마친다 — 예외를 그대로 올리면 신청서는
                // 남아 있는데 고객은 실패 응답을 받는다(교환과 동형 — #599 validate P2 #20).
                Log::info('[claim.return] 청구 0원 자동 승인 건너뜀 — 신청 상태로 접수', [
                    'return_number' => $orderReturn->return_number,
                    'reason' => $e->getMessageKey(),
                ]);

                return $orderReturn->fresh(['returnOptions']);
            }
        }

        return $orderReturn;
    }

    /**
     * 청구액 0원 신청서를 자동 승인해야 하는지 판정합니다.
     *
     * 판매자 귀책이나 무료 반품처럼 받을 돈이 없는 신청은 승인을 기다릴 이유가 없다는 것이
     * 운영자 설정(`claim.auto_approve_zero_charge`)의 취지다. 청구액이 남아 있으면 결제가
     * 선행되어야 하므로(§4-5-1·2) 설정과 무관하게 자동 승인 대상이 아니다.
     *
     * @param  OrderReturn  $return  방금 접수된 반품 신청서
     * @return bool 자동 승인 대상 여부
     */
    protected function shouldAutoApproveZeroCharge(OrderReturn $return): bool
    {
        // 정수 절사로 비교하면 소수 통화의 0.5 청구가 0 으로 읽혀 청구가 남은 채 자동 승인된다
        // (교환과 같은 float 비교 — #599 validate P2 #5).
        if ((float) $return->total_charge_amount > 0) {
            return false;
        }

        return (bool) $this->settingsService->getSetting('claim.auto_approve_zero_charge', false);
    }

    // ───────────────────────────────────────────────
    // 철회 / 상태 전이
    // ───────────────────────────────────────────────

    /**
     * 신청자가 반품 신청을 철회합니다.
     *
     * 철회자 ID 를 받지 않는다. 이 표의 행위자 축은 `requested_by`(신청자)와
     * `processed_by`(처리 관리자) 둘뿐이고, 상태별 시각 5종 어느 것도 짝이 되는 `*_by`
     * 컬럼을 갖지 않는다 — 형제 테이블(`ecommerce_order_refunds`)도 `withdrawn_at` 만
     * 갖는다. 철회는 유저 주체(§4-2 전이표)라 그 값은 사실상 `requested_by` 와 같고,
     * 비회원이면 어느 쪽이든 NULL 이다.
     *
     * "누가 언제 무엇을 바꿨는가" 는 활동 로그 소관이다 — `ClaimActivityLogListener`(S7)가
     * 이 메서드가 발화하는 `claim.return.withdrawn`·`status_changed` 훅을 받아 기록하고,
     * 행위자는 코어 `ActivityLogProcessor` 가 요청 인증 주체로 자동 주입한다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  int|null  $withdrawnByAdmin  고객 요청으로 대신 철회하는 관리자 ID (S14 — null 이면 신청자 본인 철회)
     * @param  string|null  $withdrawReason  대리 철회 사유 (관리자 대리 철회에서 필수)
     * @return OrderReturn 철회된 신청서
     *
     * @throws OrderClaimException 철회 불가 상태
     */
    public function withdraw(OrderReturn $return, ?int $withdrawnByAdmin = null, ?string $withdrawReason = null): OrderReturn
    {
        if (! $return->return_status->isWithdrawable()) {
            throw OrderClaimException::notWithdrawable();
        }

        // 판매자가 접수한 신청은 고객이 철회할 수 없다(D8) — 운영자는 고객 요청으로 대신 철회할 수 있다(S14).
        if ($withdrawnByAdmin === null && $return->isAdminRequested()) {
            throw OrderClaimException::adminRequestedNotEditable();
        }

        $withdrawReason = $withdrawReason === null ? null : trim($withdrawReason);

        if ($withdrawnByAdmin !== null && ($withdrawReason === null || $withdrawReason === '')) {
            throw OrderClaimException::withdrawReasonRequired();
        }

        // 철회 가능 판정 뒤·쓰기 전 — 확장이 예외로 철회를 막을 수 있다. 이어지는 전이가
        // `before_status_change` 를 한 번 더 낸다(층이 다른 두 자리).
        HookManager::doAction('sirsoft-ecommerce.claim.return.before_withdraw', $return);

        // 대리 철회는 철회자·사유를 신청서에 남긴다(S14 — 고객 알림이 「고객님 요청으로 판매자가 철회」 로 나간다).
        return $this->transition($return, ReturnStatusEnum::WITHDRAWN, $withdrawnByAdmin === null ? [] : [
            'processed_by' => $withdrawnByAdmin,
            'withdrawn_by_admin' => $withdrawnByAdmin,
            'withdraw_reason' => $withdrawReason,
        ]);
    }

    /**
     * 반품 신청서 상태를 전이합니다.
     *
     * 완료(COMPLETED)는 이 경로로 갈 수 없다 — 완료는 재고 복구 여부와 환불신청서 접수
     * 여부를 운영자가 고르는 절차(D26)이므로 `completeReturn()` 만이 진입점이다.
     *
     * 잠금 전에 before·filter 훅을 동기로 낸다(`beforeStatusChange()`).
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보 (processed_by / reject_reason / admin_memo)
     * @return OrderReturn 전이된 신청서
     *
     * @throws OrderClaimException 전이 불가
     */
    public function transition(OrderReturn $return, ReturnStatusEnum $target, array $context = []): OrderReturn
    {
        if ($target === ReturnStatusEnum::COMPLETED) {
            throw OrderClaimException::completionRequiresCompletionFlow();
        }

        $context = $this->beforeStatusChange($return, $target, $context);

        // 상태를 **행 잠금 아래에서 다시 읽어** 판정하고, 잠금·판정·쓰기를 **한 트랜잭션**으로
        // 묶는다(S3-D16). 메모리의 모델 값으로 판정하거나 판정만 잠금하고 밖에서 쓰면,
        // 더블클릭·재시도로 동시에 들어온 두 요청이 **둘 다 가드를 통과**한다 — 쓰기는 같은
        // 값이라 상태는 1회처럼 보이지만 `fireStatusHooks()` 가 두 번 돌아 **알림이 채널마다
        // 2통씩** 나간다. 구매자에게는 같은 메일·SMS 가 두 번 도착한다.
        $this->trackingChangeLog = [];

        [$current, $updated] = DB::transaction(function () use ($return, $target, $context) {
            // 저장소가 이미 갖고 있는 잠금 조회를 쓴다 — 서비스가 모델을 직접 조회하지 않는다.
            $locked = $this->returnRepository->findForUpdate((int) $return->getKey());

            $current = ($locked ?? $return)->return_status;

            if (! $current->canTransitionTo($target)) {
                throw OrderClaimException::transitionNotAllowed($current->label(), $target->label());
            }

            // 호출층이 들고 있는 인스턴스도 잠금 시점의 값으로 맞춘다.
            $return->setAttribute('return_status', $current);

            if ($this->revertsOrderOptions($target)) {
                $this->assertLivingItemsCanTransitionTo($return, $target);
            }

            // 회수중은 대상 행 전부에 회수 송장이 있어야 들어간다(R6) — 지시에 실린 송장은 상품 행에 쓴다.
            $tracking = [];

            if ($current !== $target) {
                $tracking = $this->prepareTransitionTracking($return, $target, $context, $this->headerTransitionTargets($return, $target));
            }

            return [$current, $this->applyTransition($return, $target, $context, $tracking)];
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
     * 값이며, 실제 전이 판정은 잠금 안에서 다시 읽은 상태로 한다. 교환 서비스와 같은 규칙이다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보
     * @return array 필터를 거친 부가 정보
     */
    protected function beforeStatusChange(OrderReturn $return, ReturnStatusEnum $target, array $context): array
    {
        $from = $return->return_status?->value;

        HookManager::doAction('sirsoft-ecommerce.claim.return.before_status_change', $return, $from, $target->value, $context);

        return $this->acceptClaimFilterResult(
            HookManager::applyFilters('sirsoft-ecommerce.claim.return.filter_status_change_data', $context, $return, $from, $target->value),
            $context,
            self::CLAIM_FILTERABLE_STATUS_CONTEXT_KEYS,
        );
    }

    /**
     * 살아 있는 신청 상품이 모두 목표 상태로 갈 수 있는지 검증합니다. (신청서 단위 반려·철회의 전제)
     *
     * 상품 단위 전이로 일부 상품이 헤더보다 앞서 있으면(승인·회수중 등) 헤더만 반려·철회로 닫을 수 없다 —
     * `syncOptionStatuses` 는 그 상품을 건너뛰지만 `revertOrderOptions` 는 전 상품을 되돌리므로, 상품 행은
     * 회수중인데 주문옵션은 코스를 벗어난 어긋남이 생긴다. 그런 신청서는 상품 단위(`transitionOption`)로만
     * 처리한다. 끝난 상품은 헤더 판정에서 빠지므로(`syncHeaderFromOptions` 와 같은 규칙) 여기서도 보지 않는다.
     * 교환 서비스와 같은 규칙이다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태 (반려·철회)
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 앞서 있는 상품이 있을 때
     */
    protected function assertLivingItemsCanTransitionTo(OrderReturn $return, ReturnStatusEnum $target): void
    {
        $blocking = $this->firstItemBlockingHeaderTransition($return, $target);

        if ($blocking !== null) {
            throw OrderClaimException::transitionNotAllowed($blocking->option_status->label(), $target->label());
        }
    }

    /**
     * 신청서 단위 전이가 앞서 있는 상품 때문에 거절되는지 판정합니다. (상세 신청서 칸의 선택지 게이트)
     *
     * `transition()` 이 실제로 거절하는 조건(반려·철회 + 앞선 상품)과 **같은 판정**이다 — 화면이
     * 조건을 따로 적으면 선택지와 거절이 갈려, 운영자가 고른 「반려」 가 다시 422 로 돌아온다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태
     * @return bool 거절되면 true
     */
    public function headerTransitionBlockedByItems(OrderReturn $return, ReturnStatusEnum $target): bool
    {
        return $this->revertsOrderOptions($target)
            && $this->firstItemBlockingHeaderTransition($return, $target) !== null;
    }

    /**
     * 목표 상태로 갈 수 없는 첫 살아 있는 상품을 찾습니다. (끝난 상품·이미 목표 상태인 상품 제외)
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태
     * @return OrderReturnOption|null 막는 상품 (없으면 null)
     */
    protected function firstItemBlockingHeaderTransition(OrderReturn $return, ReturnStatusEnum $target): ?OrderReturnOption
    {
        $return->loadMissing('returnOptions');

        foreach ($return->returnOptions as $item) {
            if ($item->option_status->isFinal() || $item->option_status === $target) {
                continue;
            }

            if (! $item->option_status->canTransitionTo($target)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 지금 완료할 수 있는 상품인지 판정합니다. (완료 절차의 대상 필터 · 상세 [완료 처리] 활성 판정 공용)
     *
     * @param  OrderReturnOption  $item  신청 상품 행
     * @return bool 완료 가능하면 true
     */
    public function isCompletableItem(OrderReturnOption $item): bool
    {
        return $item->option_status->canTransitionTo(ReturnStatusEnum::COMPLETED)
            && ! $item->option_status->isFinal();
    }

    /**
     * 상태 전이의 DB 변경분을 한 트랜잭션으로 수행합니다. (게이트·훅 제외)
     *
     * `transition()` 이 게이트 → 이 메서드 → 훅 순으로 부르는 본체다. 송장 등록처럼 다른 쓰기와
     * 전이를 한 트랜잭션에 묶어야 하는 호출부는 이 메서드를 바깥 트랜잭션 안에서 부르고 훅은
     * 커밋 뒤에 직접 발화한다 — 훅을 트랜잭션 안에서 발화하면 롤백된 전이의 알림이 나간다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태 (게이트 통과 전제)
     * @param  array  $context  부가 정보 (processed_by / reject_reason / admin_memo)
     * @param  array  $tracking  대상 행에 쓸 송장 (`prepareTransitionTracking()` — 없으면 빈 배열)
     * @return OrderReturn 전이된 신청서
     */
    protected function applyTransition(OrderReturn $return, ReturnStatusEnum $target, array $context, array $tracking = []): OrderReturn
    {
        $processedBy = $context['processed_by'] ?? null;

        return DB::transaction(function () use ($return, $target, $context, $processedBy, $tracking) {
            $updates = ['return_status' => $target];

            if ($processedBy !== null) {
                $updates['processed_by'] = $processedBy;
            }

            if (array_key_exists('admin_memo', $context)) {
                $updates['admin_memo'] = $context['admin_memo'];
            }

            $updates += $this->stampFor($target, $context);

            $fresh = $this->returnRepository->update($return, $updates);

            $this->syncOptionStatuses($fresh, $target, $processedBy, $tracking);

            // 반려·철회는 상품옵션을 신청 직전 상태로 되돌린다 (확정 (a)).
            if ($this->revertsOrderOptions($target)) {
                $this->revertOrderOptions($fresh);
            }

            return $fresh->fresh(['returnOptions']);
        });
    }

    /**
     * 여러 반품 신청서의 상태를 일괄 전이합니다.
     *
     * 전이 불가 건은 건너뛰고 사유와 함께 보고한다 — 하나가 막혔다고 나머지를 되돌리면
     * 운영자가 같은 작업을 여러 번 반복해야 한다.
     *
     * @param  array<int, int>  $returnIds  반품 신청서 ID 목록
     * @param  ReturnStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, return_number: string|null, message: string}>} 전이 결과
     */
    public function bulkTransition(array $returnIds, ReturnStatusEnum $target, array $context = []): array
    {
        $succeeded = [];
        $skipped = [];

        foreach ($returnIds as $returnId) {
            $return = $this->returnRepository->findById((int) $returnId, ['returnOptions']);

            if (! $return) {
                // 사유는 '신청서를 찾지 못했다' 이지 '완료할 상품이 없다' 가 아니다.
                // 완료 키를 돌려쓰면 승인 일괄 전이를 누른 운영자가 완료 안내를 받는다 —
                // 행위와 원인이 모두 어긋난 문구가 화면에 그대로 나간다.
                $skipped[] = [
                    'id' => (int) $returnId,
                    'return_number' => null,
                    'message' => __('sirsoft-ecommerce::exceptions.claim_not_found'),
                ];

                continue;
            }

            try {
                $this->transition($return, $target, $context);
                $succeeded[] = $return->id;
            } catch (OrderClaimException $e) {
                $skipped[] = [
                    'id' => $return->id,
                    'return_number' => $return->return_number,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return ['succeeded' => $succeeded, 'skipped' => $skipped];
    }

    /**
     * 신청 상품 단위로 상태를 전이합니다. (D25 체크 후 일괄 변경)
     *
     * 헤더 상태는 **살아 있는 상품이 모두 같은 상태에 도달했을 때만** 따라간다(끝난 상품은
     * 판정에서 뺀다 — `syncHeaderFromOptions`). 혼재 상태에서 헤더를 움직이면 아직 회수되지
     * 않은 상품이 회수 완료로 보인다.
     *
     * 반려·철회로 전이된 상품은 그 자리에서 원 주문옵션을 신청 직전 상태로 되돌린다 —
     * 신청서 단위 반려가 하는 일과 같으며, 미루면 그 상품이 반품 코스에 갇힌다.
     *
     * 수량을 실은 지시(`[{id, quantity}]`)는 그 행을 **갈라서** 지정 수량만 전이한다 —
     * 잔여는 새 행으로 남아 신청 상태를 유지한다(`ClaimItemSplitter`). 수량을 싣지 않은
     * 지시는 종전대로 전량이다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  array<int, int|array{id: int, quantity?: int|null}>  $items  전이 지시 (빈 배열이면 전체)
     * @param  ReturnStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보 (processed_by)
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>, split: array<int, array{id: int, quantity: int, remainder_id: int, remainder_quantity: int}>} 전이 결과
     *
     * @throws OrderClaimException 완료 전이를 시도한 경우
     */
    public function transitionOption(
        OrderReturn $return,
        array $items,
        ReturnStatusEnum $target,
        array $context = [],
    ): array {
        if ($target === ReturnStatusEnum::COMPLETED) {
            throw OrderClaimException::completionRequiresCompletionFlow();
        }

        $processedBy = $context['processed_by'] ?? null;
        // 상품 칸 반려의 사유 — 반려된 상품 행(`reject_reason`)에 남는다(S3).
        $rejectReason = $target === ReturnStatusEnum::REJECTED ? trim((string) ($context['reject_reason'] ?? '')) : '';
        $specs = ClaimItemSpecs::normalize($items);
        $quantities = ClaimItemSpecs::quantities($specs);
        $itemIds = ClaimItemSpecs::ids($specs);

        // 상품 단위 전이의 before 훅 — 잠금·쓰기 전이라 리스너가 던지면 어떤 행도 옮겨지지 않는다.
        // 상품 단위 전용 필터는 두지 않는다(교환과 같은 판단 — 대상·목표는 게이트가 잰 값). 자유 입력인
        // 반려 사유만 아래에서 헤더 경로와 같은 `filter_status_change_data` 를 거친다(#599 후속).
        HookManager::doAction('sirsoft-ecommerce.claim.return.before_option_status_change', $return, $specs, $target->value, $context);

        // 반려 사유는 신청서 한 칸(`reject_reason`)에 남는 자유 입력이라 헤더 경로와 **같은 필터**를 거친다
        // (#599 후속 운영 결정) — 어느 칸에서 적었느냐에 따라 확장이 손댈 수 있는지가 갈리면 금칙어 필터 같은
        // 확장이 상품 칸으로 우회된다. 받아들이는 키도 같다(처리자·목표 상태는 되돌린다).
        if ($rejectReason !== '') {
            $filterInput = ['reject_reason' => $rejectReason] + $context;
            $filtered = $this->acceptClaimFilterResult(
                HookManager::applyFilters('sirsoft-ecommerce.claim.return.filter_status_change_data', $filterInput, $return, $return->return_status?->value, $target->value),
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
        $fresh = DB::transaction(function () use ($return, $itemIds, $quantities, $target, $processedBy, $rejectReason, $context, &$succeeded, &$skipped, &$changes, &$split, &$headerBefore, &$headerAfter) {
            $locked = $this->returnRepository->findForUpdate((int) $return->getKey()) ?? $return;
            $headerBefore = $locked->return_status;

            $items = $this->resolveItems($locked, $itemIds);

            // 상품 칸의 회수중도 신청서 전이와 같은 송장 규칙을 지난다(R6·R8). 지시에 실린 송장은
            // 상품마다 저장점 안에서 **쪼갠 행**에 쓴다.
            $tracking = $this->prepareTransitionTracking($locked, $target, $context, array_values(array_filter(
                $items,
                fn (OrderReturnOption $item) => ! $item->option_status->isFinal()
                    && $item->option_status !== $target
                    && $item->option_status->canTransitionTo($target),
            )));

            foreach ($items as $item) {
                // 끝난 상품(반려·철회·완료)은 전이 게이트가 막지 못한다 — 자기 전이는 언제나 참이라
                // 이미 반려된 상품에 반려를 다시 지시하면 상태·처리자가 다시 쓰인다. 건너뛰고 보고한다
                // (교환 서비스와 같은 규칙).
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
                $previousQuantity = (int) $item->return_quantity;

                try {
                    $remainder = $this->transitionItem($item, $target, $processedBy, $quantities[$item->id] ?? null, $locked, $tracking);
                } catch (OrderClaimException $e) {
                    // 나눌 수 없는 행 같은 상품 단위 실패는 그 상품만 건너뛴다 — 저장점이
                    // 실패분만 되돌리고 같은 TX 안의 다른 상품은 그대로 간다(교환과 같은 규칙).
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
                    // "3개 중 2개" 가 어디에도 남지 않는다.
                    $change['quantity'] = (int) $item->fresh()->return_quantity;
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
                foreach ($locked->fresh(['returnOptions'])->returnOptions as $rejectedItem) {
                    if (in_array((int) $rejectedItem->id, array_map('intval', $succeeded), true)) {
                        $this->returnOptionRepository->update($rejectedItem, ['reject_reason' => $rejectReason]);
                    }
                }
            }

            $fresh = $locked->fresh(['returnOptions']);

            // 상품 단위 전이는 헤더 전이와 다른 사실이다 — 상품 상태가 갈리면 헤더는 움직이지
            // 않으므로(`syncHeaderFromOptions` 는 살아 있는 상품이 한 상태로 모일 때만 옮긴다)
            // 이 훅이 없으면 운영자가 한 상태 변경이 아무 데도 남지 않는다. 헤더가 따라 움직이면
            // 그 전이는 `status_changed` 가 따로 낸다(이중 기록이 아니라 층이 다른 두 사실).
            // 커밋 뒤 발화 순서는 등록 순서다 — 상품 축이 헤더 축보다 먼저 나간다.
            if ($changes !== []) {
                $this->fireAfterCommit(fn () => HookManager::doAction(
                    'sirsoft-ecommerce.claim.return.option_status_changed',
                    $fresh,
                    $changes,
                    $target->value,
                    $processedBy,
                ));
            }

            $this->syncHeaderFromOptions($fresh, $processedBy, $rejectReason !== '' ? ['reject_reason' => $rejectReason] : []);

            $after = $fresh->fresh(['returnOptions']);
            $headerAfter = $after->return_status;

            return $after;
        });

        $this->flushTrackingChangeLog($fresh, $headerBefore !== $headerAfter ? $headerAfter : null);

        return ['succeeded' => $succeeded, 'skipped' => $skipped, 'split' => $split];
    }

    /**
     * 신청 상품 한 건의 상태를 바꾸고 그 상태의 부수효과를 수행합니다.
     *
     * 교환(`OrderExchangeService::transitionItem`)과 같은 형태다 — 상품별 **저장점**이 되어,
     * 분할·전이·부수효과 중 하나가 실패하면 그 상품만 통째로 되돌아간다.
     *
     *  - 수량 분할: 지시에 수량이 실려 있으면 **맨 앞에서** 행을 가른다. 순서가 계약이다 —
     *    분할이 먼저여야 반려의 원 주문옵션 복귀가 전이분만 되돌린다.
     *  - 반려·철회: 원 주문옵션을 신청 직전 상태로 그 수량만큼 되돌린다. 신청서 단위 반려가
     *    하는 일을 상품 단위로 적용하는 것이다(2026-08-22 확정 ⑤). 미루면 그 상품은 어디로도
     *    못 간다: 취소·관리자 상태 변경은 클레임 가드가 막고, 되돌려 줄 신청서는 이미
     *    종결이라 복귀를 부를 경로가 남지 않는다.
     *
     * @param  OrderReturnOption  $item  신청 상품 행
     * @param  ReturnStatusEnum  $target  목표 상태 (게이트 통과 전제)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  int|null  $quantity  이번에 전이할 수량 (null 이면 전량)
     * @param  OrderReturn|null  $return  반품 신청서 (송장을 쓸 때 — 잠금 인스턴스)
     * @param  array  $tracking  이 행에 쓸 송장 (`prepareTransitionTracking()` — 없으면 빈 배열)
     * @return OrderReturnOption|null 분할로 생긴 잔여 행 (분할하지 않았으면 null)
     *
     * @throws OrderClaimException 나눌 수 없는 행
     */
    protected function transitionItem(
        OrderReturnOption $item,
        ReturnStatusEnum $target,
        ?int $processedBy,
        ?int $quantity = null,
        ?OrderReturn $return = null,
        array $tracking = [],
    ): ?OrderReturnOption {
        return DB::transaction(function () use ($item, $target, $processedBy, $quantity, $return, $tracking) {
            $remainder = null;

            if ($quantity !== null) {
                ['remainder' => $remainder] = $this->claimItemSplitter->split($item, $quantity);
                $item->refresh();
            }

            // 지시에 실린 송장은 쪼갠 행(전이분)에만 쓴다(R4). 회수도착이면 행 도착 시각을 찍는다.
            $arrival = $return !== null
                ? $this->applyTransitionTrackingTo($return, $item, $target, $tracking, $processedBy)
                : ($target === ReturnStatusEnum::COLLECTED && $item->collected_at === null ? ['collected_at' => now()] : []);

            $updated = $this->returnOptionRepository->update($item, [
                'option_status' => $target,
                'processed_by' => $processedBy ?? $item->processed_by,
            ] + $arrival);

            if ($this->revertsOrderOptions($target)) {
                $this->revertOrderOptionFor($updated->loadMissing('orderOption'), (int) $updated->return_quantity);
            }

            return $remainder;
        });
    }

    // ───────────────────────────────────────────────
    // 회수 송장 / 금액 수정
    // ───────────────────────────────────────────────

    /**
     * 운송장 한 개의 행을 한꺼번에 회수도착 처리합니다. (배송 정보 탭 운송장 묶음의 [도착 처리] — R9)
     *
     * 같은 (택배사, 운송장)을 가진 이 신청서의 행 전부를 회수도착으로 옮긴다(P2 건너뛰기 포함).
     * 전이할 수 없는 행은 `skipped` 로 보고한다. 커밋 뒤 `claim.return.after_tracking_deliver` 를 낸다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ClaimTrackingDirectionEnum  $direction  방향 (반품은 회수만)
     * @param  int  $carrierId  택배사 ID
     * @param  string  $trackingNumber  운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array{claim: OrderReturn, succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>} 처리 결과
     *
     * @throws OrderClaimException 재발송 방향 / 그 운송장을 가진 행 없음
     */
    public function deliverTracking(
        OrderReturn $return,
        ClaimTrackingDirectionEnum $direction,
        int $carrierId,
        string $trackingNumber,
        ?int $processedBy = null,
    ): array {
        $this->assertTrackingDirectionAllowed($return, $direction);

        $return->loadMissing('returnOptions');
        $items = $this->itemsWithTracking($return, $direction, $carrierId, $trackingNumber);

        if ($items === []) {
            throw OrderClaimException::trackingNotFound();
        }

        $succeeded = [];
        $skipped = [];
        $movable = [];

        foreach ($items as $item) {
            if ($item->option_status->isFinal() || $item->option_status === ReturnStatusEnum::COLLECTED
                || ! $item->option_status->canTransitionTo(ReturnStatusEnum::COLLECTED)) {
                $skipped[] = [
                    'id' => (int) $item->id,
                    'message' => $item->option_status === ReturnStatusEnum::COLLECTED || $item->collected_at !== null
                        ? __('sirsoft-ecommerce::exceptions.claim_tracking_already_arrived')
                        : __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                            'from' => $item->option_status->label(),
                            'to' => ReturnStatusEnum::COLLECTED->label(),
                        ]),
                ];

                continue;
            }

            $movable[] = (int) $item->id;
        }

        if ($movable !== []) {
            $result = $this->transitionOption($return, $movable, ReturnStatusEnum::COLLECTED, ['processed_by' => $processedBy]);
            $succeeded = $result['succeeded'];
            $skipped = [...$skipped, ...$result['skipped']];
        }

        $fresh = $return->fresh(['returnOptions']);

        $this->fireAfterCommit(fn () => HookManager::doAction(
            'sirsoft-ecommerce.claim.return.after_tracking_deliver',
            $fresh,
            $direction->value,
            $carrierId,
            $trackingNumber,
            ['succeeded' => $succeeded, 'skipped' => $skipped],
        ));

        return ['claim' => $fresh, 'succeeded' => $succeeded, 'skipped' => $skipped];
    }

    /**
     * 전이 지시에 실린 회수 송장을 해석하고, 이동 대상 행이 송장을 갖출 수 있는지 검증합니다. (R6·R8)
     *
     * 회수중은 송장이 필수다 — 지시에 없으면 대상 행이 이미 자기 송장을 가져야 한다. 회수도착은 송장이
     * 선택이다(P2) — 지시에 실리면 그 행에 함께 쓴다.
     *
     * @param  OrderReturn  $return  반품 신청서 (상품 행 로드)
     * @param  ReturnStatusEnum  $target  목표 상태
     * @param  array  $context  전이 부가 정보 (carrier_id / tracking_number)
     * @param  array<int, OrderReturnOption>  $targets  이동할 대상 행
     * @return array{direction?: ClaimTrackingDirectionEnum, carrier_id?: int|null, tracking_number?: string|null} 대상 행에 쓸 송장 (쓸 것이 없으면 빈 배열)
     *
     * @throws OrderClaimException 송장 불완전 / 송장 없음 / 회수지 섞임
     */
    protected function prepareTransitionTracking(OrderReturn $return, ReturnStatusEnum $target, array $context, array $targets): array
    {
        if (! in_array($target, [ReturnStatusEnum::COLLECTING, ReturnStatusEnum::COLLECTED], true)) {
            return [];
        }

        $direction = ClaimTrackingDirectionEnum::COLLECTION;
        [$carrierId, $trackingNumber] = $this->trackingFromContext($context);

        if ($target === ReturnStatusEnum::COLLECTING) {
            $this->assertTransitionTracking($return, $targets, $direction, $carrierId, $trackingNumber);
        } elseif ($trackingNumber !== null) {
            $this->assertSameReturnAddress($return, $targets, $carrierId, $trackingNumber);
        }

        if ($trackingNumber === null) {
            return [];
        }

        return ['direction' => $direction, 'carrier_id' => $carrierId, 'tracking_number' => $trackingNumber];
    }

    /**
     * 신청서 단위 전이가 끌고 갈 상품 행을 고릅니다. (`syncOptionStatuses()` 와 같은 판정)
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태
     * @return array<int, OrderReturnOption> 이동할 상품 행
     */
    protected function headerTransitionTargets(OrderReturn $return, ReturnStatusEnum $target): array
    {
        $return->loadMissing('returnOptions');

        return $return->returnOptions
            ->filter(fn (OrderReturnOption $item) => $item->option_status !== $target
                && ! $item->option_status->isFinal()
                && $item->option_status->canTransitionTo($target))
            ->values()
            ->all();
    }

    /**
     * 한 행에 전이 지시의 송장을 쓰고, 회수도착이면 행 도착 시각을 찍을 값을 돌려줍니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  OrderReturnOption  $item  상품 행 (쪼갠 뒤의 전이분)
     * @param  ReturnStatusEnum  $target  목표 상태
     * @param  array  $tracking  쓸 송장 (`prepareTransitionTracking()`)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array<string, mixed> 상태와 함께 쓸 행 갱신값 (도착 시각)
     */
    protected function applyTransitionTrackingTo(OrderReturn $return, OrderReturnOption $item, ReturnStatusEnum $target, array $tracking, ?int $processedBy): array
    {
        if (($tracking['direction'] ?? null) instanceof ClaimTrackingDirectionEnum) {
            $change = $this->applyItemTracking(
                $return,
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

        return $target === ReturnStatusEnum::COLLECTED && $item->collected_at === null
            ? ['collected_at' => now()]
            : [];
    }

    /**
     * 이번 요청에서 전이와 함께 쓴 송장 변경을 알립니다. (`after_tracking_update`)
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum|null  $headerTransitionedTo  이번 요청에서 신청서가 옮겨 간 상태 (그대로면 null)
     * @return void 반환값 없음
     */
    protected function flushTrackingChangeLog(OrderReturn $return, ?ReturnStatusEnum $headerTransitionedTo): void
    {
        $log = $this->trackingChangeLog;
        $this->trackingChangeLog = [];

        foreach ($log as $directionValue => $changes) {
            $this->announceTrackingUpdated($return, ClaimTrackingDirectionEnum::from($directionValue), $changes, [
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
        return $claim->loadMissing('returnOptions')->returnOptions;
    }

    /**
     * {@inheritDoc}
     *
     * 반품은 회수만 있다 — 교환품을 다시 보내지 않는다(R1).
     */
    protected function trackingDirectionsOf(Model $claim): array
    {
        return [ClaimTrackingDirectionEnum::COLLECTION];
    }

    /**
     * {@inheritDoc}
     *
     * 반품 회수: 승인~회수중 (R5).
     */
    protected function trackingWritableStatuses(Model $claim, ClaimTrackingDirectionEnum $direction): array
    {
        return [ReturnStatusEnum::APPROVED, ReturnStatusEnum::COLLECTION_READY, ReturnStatusEnum::COLLECTING];
    }

    /**
     * {@inheritDoc}
     */
    protected function trackingMovingStatus(ClaimTrackingDirectionEnum $direction): ?BackedEnum
    {
        return $direction === ClaimTrackingDirectionEnum::COLLECTION ? ReturnStatusEnum::COLLECTING : null;
    }

    /**
     * {@inheritDoc}
     */
    protected function updateTrackingItem(Model $item, array $updates): Model
    {
        return $this->returnOptionRepository->update($item, $updates);
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
     * 반품 행 송장에는 딸린 후속 기록이 없다.
     */
    protected function afterItemTrackingWritten(Model $claim, Model $item, ClaimTrackingDirectionEnum $direction): void {}

    /**
     * {@inheritDoc}
     */
    protected function trackingHookType(): string
    {
        return ClaimTypeEnum::RETURN->value;
    }

    /**
     * {@inheritDoc}
     */
    protected function lockClaimForTracking(Model $claim): Model
    {
        $locked = $this->returnRepository->findForUpdate((int) $claim->getKey()) ?? $claim;

        return $locked->load('returnOptions');
    }

    /**
     * 관리자가 청구 금액을 수정합니다. (D18 금액 수정의 클레임 청구액 축)
     *
     * 배송비를 수정하면 총 청구액과 다통화 표기가 함께 움직여야 한다 — 한쪽만 고치면
     * 화면의 청구액과 실제 청구 근거가 갈린다. 산정 근거 스냅샷에는 수정 사실을 남긴다.
     * 접수 시 청구 0원이던 신청서의 상향은 청구 방식(`charge_method`)을 함께 실어야 한다 —
     * 허용 목록(`claim.allowed_charge_methods`) 검증을 동반한다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  array  $amounts  수정값 (claim_shipping_fee / charge_method / admin_memo)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderReturn 갱신된 신청서
     *
     * @throws OrderClaimException 종결 신청서 / 금액 음수 / 청구 방식 부재·불허
     */
    public function updateAmounts(OrderReturn $return, array $amounts, ?int $processedBy = null): OrderReturn
    {
        // 종결(완료·반려·철회) 신청서의 금액은 이미 정산 근거로 소비됐다 — 수정하면 장부와 어긋난다.
        if ($return->isFinal()) {
            throw OrderClaimException::amountsNotEditable();
        }

        $updates = [];

        // 청구 방식을 함께 받는다 (D18 — 접수 시 청구 0원이던 신청서의 상향 경로).
        $chargeMethod = $return->charge_method;

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

            // 청구 방식이 없는 신청서(접수 시 청구 0원)의 청구액을 양수로 올리면 결제할 수 없는 청구가
            // 남는다 — 이 요청이 청구 방식을 함께 실었으면 그 값으로 판정한다.
            if ($fee > 0 && $chargeMethod === null) {
                throw OrderClaimException::chargeMethodRequired();
            }

            $snapshot = $return->shipping_fee_snapshot ?? [];
            $snapshot['admin_adjusted'] = true;
            $snapshot['admin_adjusted_from'] = (float) $return->claim_shipping_fee;

            $updates['claim_shipping_fee'] = $fee;
            $updates['total_charge_amount'] = $fee;
            $updates['shipping_fee_snapshot'] = $snapshot;

            // 다통화 표기도 함께 움직인다 — 환율은 현재 설정이 아니라 주문 currency_snapshot 의
            // 동결 환율이며, 접수 시점(ClaimShippingFeeService)과 같은 변환기를 쓴다. 여기를 빼면
            // 기준 통화 청구액은 바뀌었는데 표시 통화 금액은 수정 전에 고정된다.
            $return->loadMissing('order');
            $currencySnapshot = $return->order?->currency_snapshot ?? [];
            $mcFee = ! empty($currencySnapshot)
                ? $this->currencyService->convertToMultiCurrencyWithSnapshot(
                    $fee == (int) $fee ? (int) $fee : $fee,
                    $currencySnapshot
                )
                : [];

            $updates['mc_claim_shipping_fee'] = $mcFee ?: null;
            $updates['mc_total_charge_amount'] = $mcFee ?: null;

            // 청구액이 0 이 되면 청구 방식은 의미를 잃는다 — 남겨 두면 결제 대기 판정
            // (requiresAdditionalPayment)이 참인 채로 남아 결제할 수 없는 청구가 생긴다.
            if ($fee <= 0) {
                $updates['charge_method'] = null;
            }
        }

        if (array_key_exists('admin_memo', $amounts)) {
            $updates['admin_memo'] = $amounts['admin_memo'];
        }

        // 빈 페이로드 판정은 처리자를 넣기 전에 한다 — 뒤에 두면 관리자 경로가 항상
        // processed_by 를 실으므로 이 가드가 사문이 되어 무의미한 UPDATE 가 나간다.
        if ($updates === []) {
            return $return;
        }

        if ($processedBy !== null) {
            $updates['processed_by'] = $processedBy;
        }

        // 결과 청구 방식이 「환불금에서 차감」 이면 신청 경로와 같은 비교를 한다(S4) — 허용 목록만 보면 환불액
        // 2,000원인 반품에 회수비 6,000원을 차감으로 걸 수 있고, 부족분 4,000원은 어디에도 청구되지 않는다.
        $finalMethod = array_key_exists('charge_method', $updates) ? $updates['charge_method'] : $return->charge_method;
        $finalFee = (float) ($updates['claim_shipping_fee'] ?? $return->claim_shipping_fee);
        $return->loadMissing(['order', 'returnOptions']);
        $this->assertDeductCoversCharge(
            $return->order,
            $this->liveReturnItems($return),
            $finalFee,
            $finalMethod instanceof ClaimChargeMethodEnum ? $finalMethod : null,
            $this->refundPriorityOf($return),
        );

        return $this->returnRepository->update($return, $updates);
    }

    /**
     * 「환불금에서 차감」 이 환불액으로 청구액을 덮는지 확인합니다. (S4)
     *
     * 차감 전(청구 방식 없음) 환불액과 청구액을 비교한다 — 차감 후로 판정하면 0 으로 잘려 「차감했는데 환불이 0」 과
     * 「원래 환불이 0」 이 구분되지 않는다(신청 경로 `assertChargeMethodUsable` 와 같은 식).
     *
     * @param  Order  $order  주문
     * @param  array<int, array{order_option_id: int, cancel_quantity: int}>  $claimItems  환불 대상 행과 수량
     * @param  float  $fee  청구액
     * @param  ClaimChargeMethodEnum|null  $method  청구 방식
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 환불액이 청구액보다 적을 때
     */
    protected function assertDeductCoversCharge(Order $order, array $claimItems, float $fee, ?ClaimChargeMethodEnum $method, RefundPriorityEnum $refundPriority): void
    {
        if ($method !== ClaimChargeMethodEnum::DEDUCT_FROM_REFUND || $fee <= 0 || $claimItems === []) {
            return;
        }

        $baseline = $this->adjustmentService->preview($order, new ReturnAdjustment(returnItems: $claimItems), $refundPriority);

        if ($baseline->getTotalRefundAmount() < $fee) {
            throw OrderClaimException::chargeDeductInsufficient();
        }
    }

    /**
     * 반려·철회되지 않은 반품 상품 행을 환불 대상 형태로 모읍니다.
     *
     * @param  OrderReturn  $return  반품 신청서 (returnOptions 로드)
     * @return array<int, array{order_option_id: int, cancel_quantity: int}> 대상 행과 수량
     */
    protected function liveReturnItems(OrderReturn $return): array
    {
        $items = [];
        foreach ($return->returnOptions as $item) {
            if (in_array($item->option_status, [ReturnStatusEnum::REJECTED, ReturnStatusEnum::WITHDRAWN], true)) {
                continue;
            }
            $items[] = ['order_option_id' => (int) $item->order_option_id, 'cancel_quantity' => (int) $item->return_quantity];
        }

        return $items;
    }

    /**
     * 금액 수정이 실은 청구 방식이 운영자 허용 목록에 있는지 검증합니다. (교환과 동형)
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
     * 반품을 완료 처리합니다. (D26)
     *
     * 한 트랜잭션에서 ① 신청 상품 완료 전이 ② 원 주문옵션 RETURNED 전이 ③ 건별 재고 복구
     * ④ 적립예정 회수 ⑤ 쿠폰 복원 ⑥ (선택) 환불신청서 접수를 수행한다.
     *
     * 환불신청서는 `$options->createRefundRequest` 가 참일 때만 만든다. 접수하지 않은 건은
     * 나중에 관리자가 수동 접수하거나 주문상세 환불 모달로 처리한다 — 완료 처리와 환불신청서
     * 생성을 분리하는 것이 D26 의 핵심이다.
     *
     * 환불신청서는 **완료 회차마다** 접수할 수 있다(S7) — 그 회차에 환불이 연결되지 않은 완료
     * 상품을 담고, 담긴 상품 행이 `refund_id` 로 그 환불신청서를 가리킨다. 선점액 집계(D19 ③ 축)는
     * 이 연결로 반품의 예정 몫과 환불신청서 몫을 나눈다. 예전에는 헤더 한 칸(`refund_id`)이라
     * 2회차 접수가 `claim_refund_request_exists` 로 막혔다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  array<int, int>  $itemIds  완료할 신청 상품 행 ID 목록 (빈 배열이면 전체)
     * @param  ClaimCompletionOptions  $options  완료 처리 옵션 (모달 선택 결과)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderReturn 완료 처리된 신청서
     *
     * @throws OrderClaimException 완료 가능 상품 부재 / 환불신청서 중복 접수
     */
    public function completeReturn(
        OrderReturn $return,
        array $itemIds,
        ClaimCompletionOptions $options,
        ?int $processedBy = null,
    ): OrderReturn {
        // 완료의 before·filter 훅 — 주문 잠금·쓰기 전이다. 필터는 완료 옵션(재고 복구 행·환불신청서
        // 접수 여부)만 바꿀 수 있고, 바뀐 값은 아래 중복 접수 가드와 잠금 안 대상 판정을 그대로 지난다.
        // 금액은 옵션에 없다 — 환불액은 서버가 완료 행으로 계산한다.
        HookManager::doAction('sirsoft-ecommerce.claim.return.before_complete', $return, $itemIds, $options, $processedBy);

        $filteredOptions = HookManager::applyFilters('sirsoft-ecommerce.claim.return.filter_complete_options', $options, $return, $itemIds, $processedBy);
        $options = $filteredOptions instanceof ClaimCompletionOptions ? $filteredOptions : $options;

        $previousStatus = null;
        $completionChanges = [];

        $completed = DB::transaction(function () use ($return, $itemIds, $options, $processedBy, &$completionChanges, &$previousStatus) {
            $order = $this->orderRepository->findForUpdateOrFail($return->order_id);
            $order->load(['options', 'shippings', 'payment']);

            // 대상 필터·직전 상태·접수 여부를 **잠금 안에서 다시 읽은 값**으로 판정한다
            // (#599 validate P2 #3). 호출부가 들고 온 모델로 거르면, 두 처리자가 각자 완료 전
            // 상태를 읽은 뒤 차례로 들어올 때 둘 다 같은 상품을 대상으로 잡아 재고 복구·쿠폰
            // 복원·완료 훅이 두 번 돈다(재고 복구에는 멱등 가드가 없다). 주문 행 잠금이 두 완료를
            // 줄 세우므로, 뒤선 요청은 여기서 이미 끝난 상품을 보고 대상에서 뺀다.
            $return = $this->returnRepository->findForUpdate((int) $return->getKey()) ?? $return;
            $previousStatus = $return->return_status;

            $targets = array_values(array_filter(
                $this->resolveItems($return, $itemIds),
                fn (OrderReturnOption $item) => $this->isCompletableItem($item),
            ));

            if ($targets === []) {
                throw OrderClaimException::completionItemsInvalid();
            }

            $restoredAny = false;

            foreach ($targets as $item) {
                $completionChanges[] = [
                    'option_id' => (int) $item->id,
                    'previous' => $item->option_status->value,
                    'new' => ReturnStatusEnum::COMPLETED->value,
                ];

                $itemUpdates = [
                    'option_status' => ReturnStatusEnum::COMPLETED,
                    'completed_at' => now(),
                    'processed_by' => $processedBy ?? $item->processed_by,
                ];

                // 회수중 상품의 완료(선환불)는 회수도착을 건너뛴다 — 행 도착 기록을 완료 시각으로 남긴다.
                if ($item->option_status === ReturnStatusEnum::COLLECTING && $item->collected_at === null) {
                    $itemUpdates['collected_at'] = now();
                }

                $this->returnOptionRepository->update($item, $itemUpdates);

                $orderOption = $order->options->firstWhere('id', $item->order_option_id);

                if ($orderOption) {
                    // 재고 플래그·적립 회수는 **실제로 반품완료가 된 행**에 적용한다. 부분 수량이면
                    // 완료분이 새 행으로 갈라져 나가고 원 행은 코스에 남으므로, 원 행에 적으면 아직
                    // 재고를 점유한 행이 "복구됨" 으로 보인다(#599 validate P2 #4).
                    $returnedOption = $this->markOrderOptionReturned($orderOption, (int) $item->return_quantity);

                    if ($options->shouldRestoreStock($item->id)) {
                        $this->restoreStockForItem($returnedOption, (int) $item->return_quantity);
                        // 무엇을 복구했는지는 상품 행이 말한다(S6) — 헤더는 「1개 이상 복구」 뜻으로만 남는다.
                        $this->returnOptionRepository->update($item, ['is_stock_restored' => true]);
                        $restoredAny = true;
                    }

                    // 적립예정/기적립 회수 (현재 정책은 회수하지 않는다 — `cancelEarnForOption()`).
                    $this->mileageService->cancelEarnForOption($order, $returnedOption->fresh() ?? $returnedOption);
                }
            }

            // 재계산 기준은 **이 신청서에서 지금까지 완료된 상품 전부**다. 이번 라운드 몫만
            // 넘기면 여러 라운드로 나눠 완료할 때 마지막 라운드의 환불신청서가 앞 라운드 상품을
            // 담지 못한다(환불신청서는 신청서당 한 건이다).
            $return->load('returnOptions.refund');
            $claimItems = $this->completedClaimItems($return);

            // 완료 시점의 환불액이 차감 청구액보다 작아졌으면(상품 반려·일부 수량) 완료하지 않는다(S4) — 운영자는
            // 청구 방식을 추가결제로 바꾸고 결제를 받은 뒤 완료한다.
            $ownCompleted = [];
            foreach ($return->returnOptions as $item) {
                if ($item->option_status === ReturnStatusEnum::COMPLETED) {
                    $ownCompleted[] = ['order_option_id' => (int) $item->order_option_id, 'cancel_quantity' => (int) $item->return_quantity];
                }
            }
            // 나눠 처리한 행은 같은 주문옵션을 가리킨다 — 합치지 않으면 비교 환불액이 한 행 몫만큼 작게 잡힌다.
            $ownCompleted = $this->mergeClaimItemQuantities($ownCompleted, []);
            $this->assertDeductCoversCharge($order, $ownCompleted, (float) $return->claim_shipping_fee, $return->charge_method, $this->refundPriorityOf($return));
            // 이미 환불된 몫 — 다른 신청서의 완료분과 **이 신청서의 앞 회차에서 환불신청서에 담긴
            // 완료분**(S7). 재계산 기준선에는 들어가지만 이번 환불신청서에는 들어가면 안 된다(중복
            // 환불). 차분의 뺄 값으로만 쓴다.
            $priorItems = $this->mergeClaimItemQuantities(
                $this->otherReturnsCompletedClaimItems($return),
                $this->linkedCompletedClaimItems($return),
            );
            $ownItems = $this->ownCompletedClaimItems($claimItems, $priorItems);

            $adjustment = new ReturnAdjustment(
                returnItems: $claimItems,
                collectionFee: (float) $return->claim_shipping_fee,
                chargeMethod: $return->charge_method,
            );

            // 환불액을 나누는 우선순위는 접수 때 고객이 고른 값이다(저장값 없으면 종전 PG 우선).
            $refundPriority = $this->refundPriorityOf($return);
            $result = $this->adjustmentService->calculate($order, $adjustment, $refundPriority);
            $priorResult = $this->priorReturnsAdjustment($order, $priorItems, $refundPriority);
            // 차분의 나누는 값도 같은 기준(상한 없음)이어야 이중 차감이 안 난다.
            $grossResult = $this->uncappedAdjustment(
                $order,
                $claimItems,
                (float) $return->claim_shipping_fee,
                $return->charge_method,
                $result,
                $priorItems !== [],
                $refundPriority,
            );

            $isFullyCompleted = $this->allItemsCompleted($return);

            // 쿠폰 복원은 신청서당 한 번, 전건 완료 시점에만 수행한다. 라운드마다 부르면
            // 같은 쿠폰이 여러 번 복원돼 고객이 쓰지 않은 쿠폰을 얻는다.
            if ($isFullyCompleted) {
                $this->refundExecutionService->restoreCoupons($order, $result);
            }

            if ($options->createRefundRequest) {
                // 회차마다 **환불이 연결되지 않은 완료 상품**으로 접수한다(S7). 이번 회차 대상이 전부
                // 이미 접수됐으면 기존 거절 사유를 그대로 낸다.
                $this->assertUnlinkedCompletedRowsExist($return);

                $refund = $this->createRefundRequest($order, $return, $ownItems, $result, $processedBy, $priorResult, $grossResult);
                $this->linkRefundToUnlinkedCompletedRows($return, $refund);
            }

            $updates = [
                'processed_by' => $processedBy ?? $return->processed_by,
            ];

            if ($restoredAny) {
                $updates['is_stock_restored'] = true;
            }

            // 살아 있는 상품이 없고 완료된 상품이 있을 때만 헤더가 완료된다(S8) — 일부만 완료한 라운드에서
            // 헤더까지 완료로 넘기면 남은 상품의 회수·완료 경로가 사라진다.
            // 반품완료 행이 모두 기록된 뒤 — 살아 있는 상품이 하나도 없으면 주문도 반품완료다(부분 반품·진행 중 클레임은 그대로).
            $this->orderOptionService->syncFullyReturnedOrderStatus((int) $order->id);

            if ($isFullyCompleted) {
                $updates['return_status'] = ReturnStatusEnum::COMPLETED;
                $updates['completed_at'] = now();

                // 헤더 「회수 완료」 시각은 헤더가 그 단계를 지날 때만 찍힌다(S2). 회수도착을 건너뛰고
                // 완료로 끝났으면 여기서 채운다 — 부분 완료 회차가 찍으면 아직 오는 중인 상품까지
                // 「회수 완료」 로 보인다. 상품별 도착은 행 `collected_at` 이 말한다.
                if ($return->collection_completed_at === null) {
                    $updates['collection_completed_at'] = now();
                }
            }

            return $this->returnRepository->update($return, $updates)->fresh(['returnOptions']);
        });

        // 부분 완료 라운드는 헤더가 움직이지 않는다 — 그때 이 줄이 없으면 **어떤 줄도
        // 남지 않아** 운영자가 무엇을 완료 처리했는지 흔적이 사라진다(C4).
        if ($completionChanges !== []) {
            $this->fireAfterCommit(fn () => HookManager::doAction(
                'sirsoft-ecommerce.claim.return.option_status_changed',
                $completed,
                $completionChanges,
                ReturnStatusEnum::COMPLETED->value,
                $processedBy,
            ));
        }

        if ($completed->return_status === ReturnStatusEnum::COMPLETED && $previousStatus !== ReturnStatusEnum::COMPLETED) {
            $this->fireStatusHooks($completed, $previousStatus, ReturnStatusEnum::COMPLETED);
        }

        return $completed;
    }

    /**
     * 완료된 반품에 환불신청서를 뒤늦게 접수합니다. (D26 — 완료 시 접수하지 않은 건)
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderRefund 생성된 환불 신청서
     *
     * @throws OrderClaimException 완료된 상품이 없거나 이미 접수된 경우
     */
    public function createRefundRequestForCompleted(OrderReturn $return, ?int $processedBy = null): OrderRefund
    {
        $return->loadMissing('returnOptions');

        if ($return->returnOptions->every(fn (OrderReturnOption $item) => $item->option_status !== ReturnStatusEnum::COMPLETED)) {
            throw OrderClaimException::completionItemsInvalid();
        }

        return DB::transaction(function () use ($return, $processedBy) {
            $order = $this->orderRepository->findForUpdateOrFail($return->order_id);
            $order->load(['options', 'shippings', 'payment']);

            // 완료 경로와 같은 이유로 잠금 안에서 재판정한다 — 이 경로도 호출부가 들고 온
            // 모델만 보면 동시 접수 두 건을 통과시킨다. 판정 대상은 **환불이 연결되지 않은 완료
            // 상품**이다(S7 — 회차마다 접수).
            $return = $this->returnRepository->findForUpdate((int) $return->getKey()) ?? $return;
            $return->load('returnOptions.refund');
            $this->assertUnlinkedCompletedRowsExist($return);

            $claimItems = $this->completedClaimItems($return);

            // 완료 경로와 같은 차분 규약을 쓴다 — 이 경로만 누적을 실으면 같은 결함이 남는다.
            $priorItems = $this->mergeClaimItemQuantities(
                $this->otherReturnsCompletedClaimItems($return),
                $this->linkedCompletedClaimItems($return),
            );
            $ownItems = $this->ownCompletedClaimItems($claimItems, $priorItems);

            $adjustment = new ReturnAdjustment(
                returnItems: $claimItems,
                collectionFee: (float) $return->claim_shipping_fee,
                chargeMethod: $return->charge_method,
            );

            // 환불액을 나누는 우선순위는 접수 때 고객이 고른 값이다(저장값 없으면 종전 PG 우선).
            $refundPriority = $this->refundPriorityOf($return);
            $result = $this->adjustmentService->calculate($order, $adjustment, $refundPriority);
            $priorResult = $this->priorReturnsAdjustment($order, $priorItems, $refundPriority);
            // 차분의 나누는 값도 같은 기준(상한 없음)이어야 이중 차감이 안 난다.
            $grossResult = $this->uncappedAdjustment(
                $order,
                $claimItems,
                (float) $return->claim_shipping_fee,
                $return->charge_method,
                $result,
                $priorItems !== [],
                $refundPriority,
            );
            $refund = $this->createRefundRequest($order, $return, $ownItems, $result, $processedBy, $priorResult, $grossResult);

            $this->linkRefundToUnlinkedCompletedRows($return, $refund);

            return $refund;
        });
    }

    /**
     * 완료 처리 시 접수될 환불신청서 금액을 미리 계산합니다. (완료 모달 미리보기 — DB 변경 없음)
     *
     * 재계산 기준은 완료 실행(`completeReturn`)과 동일하다 — "이미 완료된 상품 + 이번 라운드
     * 대상 상품" 전체다. 이번 라운드 몫만 계산하면 분할 완료에서 모달 금액과 실제 접수액이 갈린다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  array<int, int>  $itemIds  이번 라운드 완료 대상 신청 상품 행 ID 목록 (빈 배열이면 전체)
     * @return array{completable_item_ids: array<int, int>, already_filed: bool, blocked_reason: string|null, refund_amount: float, refund_points_amount: float, deducted_shipping_fee: float, coupon_restore: bool, fully_completed: bool} 미리보기 결과
     */
    public function previewCompletion(OrderReturn $return, array $itemIds = []): array
    {
        $return->loadMissing(['returnOptions.refund', 'order']);
        $order = $return->order;
        $order?->loadMissing(['options', 'shippings', 'payment']);

        $targets = array_values(array_filter(
            $this->resolveItems($return, $itemIds),
            fn (OrderReturnOption $item) => $this->isCompletableItem($item),
        ));

        $claimItems = $this->completedClaimItems($return);

        foreach ($targets as $item) {
            $claimItems[] = [
                'order_option_id' => $item->order_option_id,
                'cancel_quantity' => (int) $item->return_quantity,
            ];
        }

        // 수량을 나눠 처리하면 같은 주문옵션을 가리키는 행이 둘 이상이 된다 — 재계산 입력은 주문옵션 단위 맵이라
        // 그대로 넘기면 뒤 행이 앞 행을 덮어 그 몫이 환불 예고에서 빠진다(실제 접수 `completedClaimItems()` 는 합산).
        $claimItems = $this->mergeClaimItemQuantities($claimItems, []);

        // 「이미 접수됨」 은 이번에 접수할 몫이 하나도 없다는 뜻이다 — 완료 대상도 없고 환불이 연결되지
        // 않은 완료 상품도 없는데 환불이 연결된 완료 상품은 있는 경우(S7 — 회차마다 접수).
        $alreadyFiled = $targets === []
            && $this->unlinkedCompletedRows($return) === []
            && $this->linkedCompletedClaimItems($return) !== [];

        if ($order === null || $claimItems === []) {
            // 접수할 상품이 없다 — 교환 미리보기와 같은 두 사유 구분을 반품도 낸다(S5 인계 9).
            // 사유를 비워 두면 응답이 `applicable: true` + `refund_amount: 0` 이 되어 모달은
            // '0원 접수' 를 예고하는데 실제 접수는 completionItemsInvalid() 로 422 가 된다.
            return [
                'completable_item_ids' => array_map(fn (OrderReturnOption $item) => (int) $item->id, $targets),
                'already_filed' => $alreadyFiled,
                'blocked_reason' => $alreadyFiled ? 'claim_refund_request_exists' : 'claim_refund_not_applicable',
                'refund_amount' => 0.0,
                'refund_points_amount' => 0.0,
                'refund_product_amount' => 0.0,
                'refund_shipping_amount' => 0.0,
                'deducted_shipping_fee' => 0.0,
                'coupon_restore' => false,
                'fully_completed' => false,
            ];
        }

        $adjustment = new ReturnAdjustment(
            returnItems: $claimItems,
            collectionFee: (float) $return->claim_shipping_fee,
            chargeMethod: $return->charge_method,
        );

        // 실제 접수와 같은 우선순위로 예고한다 — 갈리면 모달이 예고한 PG·마일리지 구성과 접수 구성이 다르다.
        $refundPriority = $this->refundPriorityOf($return);
        $result = $this->adjustmentService->calculate($order, $adjustment, $refundPriority);
        // 실제 접수(`createRefundRequest`)와 같은 차분 규약으로 예고한다 — 여기만 누적을
        // 보여 주면 모달이 예고한 금액과 접수된 금액이 갈린다.
        $priorItems = $this->mergeClaimItemQuantities(
            $this->otherReturnsCompletedClaimItems($return),
            $this->linkedCompletedClaimItems($return),
        );
        $priorResult = $this->priorReturnsAdjustment($order, $priorItems, $refundPriority);
        $grossResult = $this->uncappedAdjustment(
            $order,
            $claimItems,
            (float) $return->claim_shipping_fee,
            $return->charge_method,
            $result,
            $priorItems !== [],
            $refundPriority,
        );
        $delta = $this->clampDeltaToRemaining($order, $this->refundDeltaAgainstPrior($grossResult, $priorResult, $order->currency_snapshot ?? []));
        // 상품 환불 몫의 기준선 — 앞 신청서가 이미 빼 간 상태의 소계에서 출발해야 한다.
        $priorResultSnapshot = $priorResult?->recalculatedSnapshot ?? $result->originalSnapshot;

        // 가정 완료 후 전건 완료 여부 — 살아 있는(비종결) 상품이 전부 대상에 들어왔는가.
        $targetIds = array_map(fn (OrderReturnOption $item) => (int) $item->id, $targets);
        $fullyCompleted = $return->returnOptions->every(
            fn (OrderReturnOption $item) => $item->option_status === ReturnStatusEnum::COMPLETED
                || $item->option_status->isFinal()
                || in_array((int) $item->id, $targetIds, true)
        );

        // 차감 배송비는 재계산 결과의 배송비 차이(shippingDifference — 배송비 재판정 축)가 아니라
        // 신청서가 동결한 회수배송비다 — '환불금 차감' 방식일 때 그 몫이 환불액에서 이미 빠져 있다.
        $deductedFee = $return->charge_method === ClaimChargeMethodEnum::DEDUCT_FROM_REFUND
            ? (float) $return->claim_shipping_fee
            : 0.0;

        return [
            'completable_item_ids' => $targetIds,
            'already_filed' => $alreadyFiled,
            'blocked_reason' => $alreadyFiled ? 'claim_refund_request_exists' : null,
            'refund_amount' => $delta['refund_amount'],
            'refund_points_amount' => $delta['refund_points_amount'],
            // 환불 예정액의 가산 두 축 — 합계만 내려주면 화면이 '왜 이 금액인가' 를 말할 수
            // 없고, 화면이 역산하면 같은 금액의 두 번째 출처가 된다. 재계산이 이미 만든 값을
            // 그대로 싣는다 (상품 = 재계산 전후 소계 차 / 배송비 = 배송비 재판정 차).
            // 두 축도 합계와 같은 차분 기준이어야 한다 — 합계만 차분이면 두 축의 합이 합계를 넘는다.
            'refund_product_amount' => (float) (($priorResultSnapshot['subtotal_amount'] ?? ($result->originalSnapshot['subtotal_amount'] ?? 0))
                - ($result->recalculatedSnapshot['subtotal_amount'] ?? 0)),
            'refund_shipping_amount' => $delta['refund_shipping_amount'],
            'deducted_shipping_fee' => $deductedFee,
            'coupon_restore' => $fullyCompleted && $result->restoredCouponIssueIds !== [],
            'fully_completed' => $fullyCompleted,
        ];
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 신청
    // ───────────────────────────────────────────────

    /**
     * 반품 사유를 해석합니다.
     *
     * @param  string  $reasonCode  사유 코드
     * @param  bool  $userSelectableOnly  사용자 선택 가능 사유(`is_user_selectable`)로 제한할지 여부 (관리자 경로는 false)
     * @return ClaimReason 사유 모델
     *
     * @throws OrderClaimException 사유 부재·비활성
     */
    protected function resolveReason(string $reasonCode, bool $userSelectableOnly = true): ClaimReason
    {
        $reason = $this->claimReasonRepository->findByCode($reasonCode, ClaimTypeEnum::RETURN->value);

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
     * 청구 방식이 사용 가능한지 검증합니다.
     *
     * - 청구액이 있으면 방식이 필요하고, 운영자가 허용한 목록 안에 있어야 한다.
     * - '환불금에서 차감'은 차감하고도 환불액이 남을 때만 성립한다 — 남지 않으면 차감분이
     *   조용히 사라져 배송비를 받지 못한 채 반품이 끝난다.
     *
     * @param  Order  $order  주문
     * @param  array  $claimItems  대상 아이템 [{order_option_id, cancel_quantity}]
     * @param  ClaimShippingFee  $shippingFee  산정된 배송비
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
        ?ClaimChargeMethodEnum $chargeMethod,
        RefundPriorityEnum $refundPriority,
    ): void {
        if ($shippingFee->fee <= 0) {
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

        // 차감 전(추가 비용 0) 환불액과 배송비를 비교한다. 차감 후 결과로 판정하면
        // max(0, ...) clamp 에 가려 "차감했는데 환불이 0" 과 "원래 환불이 0" 이 구분되지 않는다.
        $baseline = $this->adjustmentService->preview(
            $order,
            new ReturnAdjustment(returnItems: $claimItems),
            $refundPriority,
        );

        if ($baseline->getTotalRefundAmount() < $shippingFee->fee) {
            throw OrderClaimException::chargeMethodNotAllowed();
        }
    }

    /**
     * 신청 가능 기간을 검증합니다.
     *
     * 기산점은 배송완료 시각(`delivered_at`)이 정본이고, 없으면 확정 시각(`confirmed_at`) →
     * 마지막 변경 시각(`updated_at`) 순으로 내려간다. 세 시각이 모두 없을 때만 판정하지
     * 않는다 — 상세한 이유는 아래 기산점 주석 참조.
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
            'claim.return_requestable_statuses',
            [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value],
        ));
    }

    /**
     * 신청 상품 행을 만들고 원 주문옵션을 반품진행중으로 전이합니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  Order  $order  주문
     * @param  OrderOption  $option  원 주문옵션
     * @param  int  $quantity  신청 수량
     * @return void 반환값 없음
     */
    protected function createReturnOption(OrderReturn $return, Order $order, OrderOption $option, int $quantity): void
    {
        // 회수지는 **상품마다** 자기 배송정책에서 해석한다(S4) — 분할 전 원 행의 배송 행이 정책을 가리킨다.
        $returnAddress = $this->resolveReturnAddressSnapshot($order, [['option' => $option]]);

        // 전이는 원 행의 수량·상태를 그 자리에서 바꾼다(부분이면 잔여만 남는다).
        // 감사용 값은 전이 **전에** 캡처해야 신청 직전의 사실이 남는다.
        $originalStatus = $option->option_status;
        $originalQuantity = (int) $option->quantity;
        $unitPrice = (float) $option->unit_price;

        $transition = $this->orderOptionService->changeStatusWithQuantity(
            $option,
            OrderStatusEnum::RETURN_IN_PROGRESS,
            $quantity,
            [],
            restoreStockOnCancel: false,
            mergeSiblings: false,
        );

        // 신청 상품 행이 참조할 주문옵션은 **코스 상태를 실제로 갖게 된 행**이다.
        // 부분 수량이면 분할된 새 행이 그 대상이고, 전량이면 원 행 자신이다.
        $courseOption = $transition['split'] ?? $transition['original'];

        $this->returnOptionRepository->create([
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $courseOption->id,
            'option_status' => ReturnStatusEnum::REQUESTED,
            'return_address_snapshot' => $returnAddress,
            'return_quantity' => $quantity,
            'original_quantity' => $originalQuantity,
            'original_option_status' => $originalStatus,
            'unit_price' => $unitPrice,
            'subtotal_amount' => $unitPrice * $quantity,
        ]);
    }

    /**
     * 신청 대상 스냅샷을 만듭니다.
     *
     * @param  array<int, array{option: OrderOption, quantity: int}>  $resolved  해석된 대상
     * @return array 스냅샷
     */
    protected function buildItemsSnapshot(array $resolved): array
    {
        $snapshot = [];

        foreach ($resolved as $entry) {
            $option = $entry['option'];

            $snapshot[] = [
                'option_id' => $option->id,
                'product_name' => $option->product_name,
                'option_name' => $option->option_name,
                'option_value' => $option->option_value,
                'quantity' => $entry['quantity'],
                'unit_price' => (float) $option->unit_price,
                'option_status' => $option->option_status instanceof OrderStatusEnum
                    ? $option->option_status->value
                    : $option->option_status,
            ];
        }

        return $snapshot;
    }

    /**
     * 회수지 주소 스냅샷을 만듭니다.
     *
     * 배송정책별로 회수지가 다르므로 신청 대상 상품이 실제로 적용받은 정책의 회수지를 쓴다.
     * 신청 시점에 복사해 두는 이유는 정책 변경과 격리하기 위해서다 — 운영자가 이후 회수지를
     * 바꿔도 이미 접수된 반품의 회수지가 따라 움직이면 고객이 받은 안내와 달라진다.
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
                ClaimTypeEnum::RETURN->value,
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
     * @param  OrderReturn  $claim  신청서
     * @return RefundPriorityEnum 환불 우선순위
     */
    protected function refundPriorityOf(OrderReturn $claim): RefundPriorityEnum
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

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 전이
    // ───────────────────────────────────────────────

    /**
     * 상태별 시각·부가 컬럼 갱신값을 반환합니다.
     *
     * @param  ReturnStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보
     * @return array<string, mixed> 갱신값
     */
    protected function stampFor(ReturnStatusEnum $target, array $context): array
    {
        return match ($target) {
            ReturnStatusEnum::APPROVED => ['approved_at' => now()],
            ReturnStatusEnum::ON_HOLD => ['on_hold_at' => now()],
            ReturnStatusEnum::REJECTED => [
                'rejected_at' => now(),
                'reject_reason' => $context['reject_reason'] ?? null,
            ],
            ReturnStatusEnum::WITHDRAWN => ['withdrawn_at' => now()] + (isset($context['withdrawn_by_admin'])
                ? ['withdrawn_by_admin' => (int) $context['withdrawn_by_admin'], 'withdraw_reason' => $context['withdraw_reason'] ?? null]
                : []),
            ReturnStatusEnum::COLLECTED => ['collection_completed_at' => now()],
            default => [],
        };
    }

    /**
     * 신청 상품 상태를 헤더 상태에 맞춥니다.
     *
     * 상품 단위 전이(transitionOption)는 앞서 나가기만 하므로 상품이 헤더보다 뒤처지지
     * 않는다. 목표 상태를 받아들일 수 없는 상품(이미 그 지점을 지났거나 종결)은 그대로 둔다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  array  $tracking  대상 행에 쓸 송장 (`prepareTransitionTracking()` — 없으면 빈 배열)
     * @return void 반환값 없음
     */
    protected function syncOptionStatuses(OrderReturn $return, ReturnStatusEnum $target, ?int $processedBy, array $tracking = []): void
    {
        $return->loadMissing('returnOptions');

        $changes = [];

        foreach ($return->returnOptions as $item) {
            if ($item->option_status === $target || $item->option_status->isFinal()) {
                continue;
            }

            if (! $item->option_status->canTransitionTo($target)) {
                continue;
            }

            $changes[] = [
                'option_id' => (int) $item->id,
                'previous' => $item->option_status->value,
                'new' => $target->value,
            ];

            $arrival = $this->applyTransitionTrackingTo($return, $item, $target, $tracking, $processedBy);

            $this->returnOptionRepository->update($item, [
                'option_status' => $target,
                'processed_by' => $processedBy ?? $item->processed_by,
            ] + $arrival);
        }

        // 헤더 전이가 끌고 간 상품 전이도 상품 축 훅으로 남긴다(C2). 예전에는
        // `transitionOption()` 한 경로만 훅을 쏴, 운영자가 신청서 단위로 승인하면 상품 행은
        // 조용히 함께 옮겨지고 그 사실이 활동로그 어디에도 없었다.
        if ($changes !== []) {
            $this->fireAfterCommit(fn () => HookManager::doAction(
                'sirsoft-ecommerce.claim.return.option_status_changed',
                $return->fresh(['returnOptions']),
                $changes,
                $target->value,
                $processedBy,
            ));
        }
    }

    /**
     * 살아 있는 신청 상품이 모두 같은 상태에 도달했으면 헤더를 그 상태로 옮깁니다.
     *
     * 판정에서 **끝난 상품(반려·철회·완료)은 뺀다**(2026-08-22 확정 ②). 포함해서 세면
     * 상품 하나가 반려된 신청서는 나머지가 모두 같은 단계에 도달해도 상태 집합이 영원히
     * 2종이라 헤더가 신청 상태에 묶이고, 운영자가 신청서 상태를 매번 손으로 옮겨야 한다.
     *
     * 살아 있는 상품이 없으면(전량 반려 등) 끝난 상태들이 하나로 모일 때 그것을 따라간다 —
     * 전량 반려된 신청서가 신청 상태로 남지 않게 한다. 끝난 상태가 섞여 있으면(반려 1 +
     * 철회 1) 어느 쪽으로도 단정할 수 없으므로 그대로 둔다.
     *
     * 상품들이 헤더보다 한 단계 이상 앞서 있으면 건너뛴 중간 단계를 차례로 거쳐 따라간다
     * (`headerPathTo`). 게이트가 막는 조합(예: 승인 상태에서 상품이 전량 반려)은 그대로 둔다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  array  $stampContext  단계 시각 외 부가 컬럼 (상품 칸 반려의 `reject_reason` — 헤더가 반려로 따라갈 때 사유를 지우지 않게)
     * @return void 반환값 없음
     */
    protected function syncHeaderFromOptions(OrderReturn $return, ?int $processedBy, array $stampContext = []): void
    {
        $return->loadMissing('returnOptions');

        $items = $return->returnOptions;

        if ($items->isEmpty()) {
            return;
        }

        $living = $items->reject(fn (OrderReturnOption $item) => $item->option_status->isFinal());

        $statuses = ($living->isEmpty() ? $items : $living)
            ->map(fn (OrderReturnOption $item) => $item->option_status->value)
            ->unique()
            ->values();

        if ($statuses->count() !== 1) {
            return;
        }

        $target = ReturnStatusEnum::from($statuses->first());

        if ($target === $return->return_status || $target === ReturnStatusEnum::COMPLETED) {
            return;
        }

        // 상품들이 한 단계 이상 앞서 있으면 헤더는 건너뛴 중간 단계를 차례로 거친다 — 직접 전이만
        // 허용하면 A 가 회수중까지 간 뒤 B 가 뒤따라 회수중이 되는 순간 승인 → 회수중 게이트에 막혀
        // 헤더가 영구히 승인에 남고, 회수 단계 훅(알림)이 영영 나가지 않는다.
        // 단계마다 타임스탬프와 훅을 정상 발화한다.
        $path = $this->headerPathTo($return->return_status, $target);

        if ($path === []) {
            return;
        }

        $current = $return;

        foreach ($path as $step) {
            $previous = $current->return_status;

            // 상품이 끌고 간 헤더 전이도 전이다 — 단계마다 before 훅을 쓰기 전에 낸다. 상품 전이
            // 트랜잭션 **안**이라 리스너가 던지면 이번 상품 전이까지 함께 되돌아간다(헤더와 상품이
            // 갈리지 않게). 필터는 없다 — 목표는 상품 상태가 정한 값이다.
            HookManager::doAction(
                'sirsoft-ecommerce.claim.return.before_status_change',
                $current,
                $previous->value,
                $step->value,
                ['processed_by' => $processedBy, 'source' => 'option_sync'],
            );

            $updates = ['return_status' => $step] + $this->stampFor($step, $stampContext);

            if ($processedBy !== null) {
                $updates['processed_by'] = $processedBy;
            }

            $current = $this->returnRepository->update($current, $updates);

            $this->fireStatusHooks($current, $previous, $step);
        }
    }

    /**
     * 헤더가 현재 상태에서 목표 상태까지 거쳐야 할 단계 목록을 전이 게이트로 찾습니다.
     *
     * `allowedTransitions()` 만을 간선으로 쓰는 최단 경로다 — 단계를 리터럴로 나열하지 않으므로
     * 전이표가 바뀌어도 여기를 함께 고칠 필요가 없다. 도달 불가면 빈 배열이다.
     *
     * @param  ReturnStatusEnum  $from  현재 헤더 상태
     * @param  ReturnStatusEnum  $to  상품들이 모인 상태
     * @return array<int, ReturnStatusEnum> 거쳐야 할 상태 목록 (목표 포함, 현재 제외)
     */
    protected function headerPathTo(ReturnStatusEnum $from, ReturnStatusEnum $to): array
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
     * @param  OrderReturn  $return  반품 신청서
     * @param  array<int, int>  $itemIds  신청 상품 행 ID 목록 (빈 배열이면 전체)
     * @return array<int, OrderReturnOption> 신청 상품 행 목록
     */
    protected function resolveItems(OrderReturn $return, array $itemIds): array
    {
        $return->loadMissing('returnOptions');

        if ($itemIds === []) {
            return $return->returnOptions->all();
        }

        $ids = array_map('intval', $itemIds);

        return $return->returnOptions
            ->filter(fn (OrderReturnOption $item) => in_array($item->id, $ids, true))
            ->values()
            ->all();
    }

    /**
     * 반려·철회 시 원 주문옵션을 신청 직전 상태로 되돌립니다.
     *
     * 복귀는 **상품옵션 단위**다. 한 신청서에 상품이 여러 건이고 상품마다 신청 직전 상태가
     * 다를 수 있어(배송중/배송완료 혼재) 신청서 단위 단일 스냅샷으로는 복원이 불가능하다.
     *
     * 재고는 신청 접수 시점에 복원한 적이 없으므로 되돌릴 것이 없다. 재차감 판정
     * (isReactivationTransition)이 발화하더라도 `is_stock_deducted` 멱등 가드가 막는다.
     *
     * 복귀 대상인지의 판정은 `OrderStatusEnum::revertibleClaimCourseStatuses()` **단일 출처**를
     * 읽는다. 리터럴 비교(`!== RETURN_IN_PROGRESS`)로 두면 교환이 붙을 때 같은 판정이 두 벌로
     * 갈리고, 그때 한쪽만 고치면 복귀가 조용히 누락된다 — 오류도 로그도 없이 신청서와
     * 주문옵션이 어긋나는 형태다. 교환도 이 목록을 그대로 쓴다(2026-08-22 확정 ④).
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return void 반환값 없음
     */
    protected function revertOrderOptions(OrderReturn $return): void
    {
        $return->loadMissing(['returnOptions.orderOption']);

        foreach ($return->returnOptions as $item) {
            $this->revertOrderOptionFor($item);
        }
    }

    /**
     * 신청 상품 한 건의 원 주문옵션을 신청 직전 상태로 되돌립니다.
     *
     * 신청서 단위 복귀(`revertOrderOptions`)와 상품 단위 반려·철회(`transitionOption`)가
     * **같은 이 메서드**를 쓴다. 판정을 두 벌로 두면 한쪽만 고쳐졌을 때 복귀가 조용히
     * 누락되고, 그 상품은 어디로도 못 간다 — 취소는 클레임 코스 가드가, 관리자 상태
     * 변경은 D15 후단이 막고, 되돌려 줄 신청서는 이미 종결이기 때문이다.
     *
     * 이미 코스를 벗어난 행은 건너뛰므로 신청서 단위 복귀가 뒤따라도 이중 전이가 없다.
     *
     * @param  OrderReturnOption  $item  신청 상품 행
     * @param  int|null  $quantity  되돌릴 수량 (null 이면 그 주문옵션 전량)
     * @return void 반환값 없음
     */
    protected function revertOrderOptionFor(OrderReturnOption $item, ?int $quantity = null): void
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
     * 그 상태에 도달하면 원 주문옵션을 되돌려야 하는지 판정합니다.
     *
     * 반려·철회는 "반품하지 않기로 했다" 는 뜻이므로 원 상품이 신청 직전 상태로 돌아가야
     * 한다. 신청서 단위 전이와 상품 단위 전이가 이 판정을 공유한다 — 교환(S4)은 Enum 타입이
     * 달라 같은 형태의 판정을 자기 서비스에 둔다.
     *
     * @param  ReturnStatusEnum  $target  목표 상태
     * @return bool 복귀 대상 여부
     */
    protected function revertsOrderOptions(ReturnStatusEnum $target): bool
    {
        return in_array($target, [ReturnStatusEnum::REJECTED, ReturnStatusEnum::WITHDRAWN], true);
    }

    /**
     * 상태 전이 훅을 발화합니다.
     *
     * `status_changed` 는 모든 전이에서 발화하고, 고객 가시 분기(승인·반려·철회·완료)는
     * 전용 훅을 함께 발화한다 — 알림·활동 로그 리스너가 분기 조건을 각자 재구현하지 않게 한다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $previous  직전 상태
     * @param  ReturnStatusEnum  $target  목표 상태
     * @return void 반환값 없음
     */
    protected function fireStatusHooks(OrderReturn $return, ReturnStatusEnum $previous, ReturnStatusEnum $target): void
    {
        $this->fireAfterCommit(fn () => HookManager::doAction(
            'sirsoft-ecommerce.claim.return.status_changed',
            $return,
            $previous->value,
            $target->value,
        ));

        $specific = match ($target) {
            ReturnStatusEnum::APPROVED => 'sirsoft-ecommerce.claim.return.approved',
            ReturnStatusEnum::REJECTED => 'sirsoft-ecommerce.claim.return.rejected',
            ReturnStatusEnum::WITHDRAWN => 'sirsoft-ecommerce.claim.return.withdrawn',
            ReturnStatusEnum::COMPLETED => 'sirsoft-ecommerce.claim.return.completed',
            default => null,
        };

        if ($specific !== null) {
            $this->fireAfterCommit(fn () => HookManager::doAction($specific, $return));
        }
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 완료
    // ───────────────────────────────────────────────

    /**
     * 원 주문옵션을 반품완료(터미널)로 전이하고, 실제로 전이된 행을 돌려줍니다.
     *
     * 부분 수량이면 `changeStatusWithQuantity()` 가 완료분을 **새 행(split)** 으로 갈라 반품완료로
     * 옮기고 원 행은 잔여로 코스에 남긴다. 호출부는 재고 플래그·적립 회수를 그 새 행에 적용해야
     * 한다(#599 validate P2 #4).
     *
     * @param  OrderOption  $orderOption  원 주문옵션
     * @param  int  $quantity  완료 수량
     * @return OrderOption 반품완료가 된 행 (분할이면 새 행, 전량이면 원 행)
     */
    protected function markOrderOptionReturned(OrderOption $orderOption, int $quantity): OrderOption
    {
        if ($orderOption->option_status === OrderStatusEnum::RETURNED) {
            return $orderOption;
        }

        $result = $this->orderOptionService->changeStatusWithQuantity(
            $orderOption,
            OrderStatusEnum::RETURNED,
            min($quantity, (int) $orderOption->quantity),
            [],
            restoreStockOnCancel: false,
            mergeSiblings: false,
        );

        return $result['split'] ?? $result['merged_into'] ?? $orderOption;
    }

    /**
     * 환불이 연결되지 않은 완료 상품이 있는지 단언합니다. (회차마다 접수 — S7)
     *
     * 환불신청서는 신청서당 한 건이 아니다 — 부분 완료 회차마다 그때 환불이 연결되지 않은 완료
     * 상품으로 접수한다. 전부 이미 살아 있는 환불신청서에 담겼으면 `claim_refund_request_exists`,
     * 완료 상품 자체가 없으면 `claim_refund_not_applicable` 로 거절한다. 호출부는 신청서를 잠근 뒤
     * 부른다 — 동시 접수 두 건을 줄 세우는 지점이다.
     *
     * @param  OrderReturn  $return  반품 신청서 (returnOptions.refund 로드)
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 접수할 완료 상품 없음
     */
    protected function assertUnlinkedCompletedRowsExist(OrderReturn $return): void
    {
        if ($this->unlinkedCompletedRows($return) !== []) {
            return;
        }

        $latest = $return->returnOptions
            ->filter(fn (OrderReturnOption $item) => $this->isLinkedToLiveRefund($item))
            ->sortByDesc('refund_id')
            ->first();

        if ($latest !== null) {
            throw OrderClaimException::refundRequestAlreadyExists(
                $latest->refund?->refund_number ?? (string) $latest->refund_id
            );
        }

        throw OrderClaimException::refundNotApplicable();
    }

    /**
     * 환불이 연결되지 않은 이 신청서의 완료 상품 행을 반환합니다.
     *
     * 반려·철회된 환불신청서에 연결된 행은 연결되지 않은 것으로 본다 — 그 돈은 나가지 않았으므로
     * 다시 접수할 수 있어야 한다(교환의 살아 있는 환불신청서 판정과 같은 축).
     *
     * @param  OrderReturn  $return  반품 신청서 (returnOptions 로드)
     * @return array<int, OrderReturnOption> 완료·미연결 상품 행
     */
    protected function unlinkedCompletedRows(OrderReturn $return): array
    {
        return $return->returnOptions
            ->filter(fn (OrderReturnOption $item) => $item->option_status === ReturnStatusEnum::COMPLETED
                && ! $this->isLinkedToLiveRefund($item))
            ->values()
            ->all();
    }

    /**
     * 상품 행이 살아 있는 환불신청서(반려·철회 제외)에 연결돼 있는지 반환합니다.
     *
     * @param  OrderReturnOption  $item  상품 행
     * @return bool 연결돼 있으면 true
     */
    protected function isLinkedToLiveRefund(OrderReturnOption $item): bool
    {
        return $item->hasLiveRefund();
    }

    /**
     * 이 신청서에서 이미 환불신청서에 담긴 완료 상품을 계산기 공용 아이템 형태로 반환합니다. (차분의 뺄 값)
     *
     * @param  OrderReturn  $return  반품 신청서 (returnOptions 로드)
     * @return array<int, array{order_option_id: int, cancel_quantity: int}> 이미 접수된 완료 몫
     */
    protected function linkedCompletedClaimItems(OrderReturn $return): array
    {
        $quantities = [];

        foreach ($return->returnOptions as $item) {
            if ($item->option_status !== ReturnStatusEnum::COMPLETED || ! $this->isLinkedToLiveRefund($item)) {
                continue;
            }

            $optionId = (int) $item->order_option_id;
            $quantities[$optionId] = ($quantities[$optionId] ?? 0) + (int) $item->return_quantity;
        }

        $items = [];

        foreach ($quantities as $optionId => $quantity) {
            $items[] = ['order_option_id' => $optionId, 'cancel_quantity' => $quantity];
        }

        return $items;
    }

    /**
     * 두 아이템 목록을 주문옵션별 수량 합으로 합칩니다.
     *
     * @param  array<int, array{order_option_id: int, cancel_quantity: int}>  $a  목록 1
     * @param  array<int, array{order_option_id: int, cancel_quantity: int}>  $b  목록 2
     * @return array<int, array{order_option_id: int, cancel_quantity: int}> 합친 목록
     */
    protected function mergeClaimItemQuantities(array $a, array $b): array
    {
        $quantities = [];

        foreach ([...$a, ...$b] as $item) {
            $optionId = (int) $item['order_option_id'];
            $quantities[$optionId] = ($quantities[$optionId] ?? 0) + (int) $item['cancel_quantity'];
        }

        $merged = [];

        foreach ($quantities as $optionId => $quantity) {
            $merged[] = ['order_option_id' => $optionId, 'cancel_quantity' => $quantity];
        }

        return $merged;
    }

    /**
     * 방금 접수한 환불신청서를 환불이 연결되지 않은 완료 상품 행에 잇습니다. (S7)
     *
     * 선점액 집계(D19 ③ 축)는 이 연결로 반품의 환불 예정 몫과 환불신청서 몫을 나눈다 — 잇지 않으면
     * 같은 상품이 두 쪽에서 세어진다.
     *
     * @param  OrderReturn  $return  반품 신청서 (returnOptions 로드)
     * @param  OrderRefund  $refund  접수된 환불신청서
     * @return void 반환값 없음
     */
    protected function linkRefundToUnlinkedCompletedRows(OrderReturn $return, OrderRefund $refund): void
    {
        foreach ($this->unlinkedCompletedRows($return) as $item) {
            $this->returnOptionRepository->update($item, ['refund_id' => $refund->id]);
        }

        $return->load('returnOptions.refund');
    }

    /**
     * 회수 도착 상품의 재고를 복구합니다.
     *
     * 복구 후 `is_stock_deducted` 를 내려 그 행이 더 이상 재고를 점유하지 않음을 남긴다 —
     * 재고 수치만 올리고 플래그를 두면 같은 행이 다시 복구 대상으로 보인다.
     *
     * 취소용 `restoreOptionStockForOrderOption()` 이 아니라 클레임 전용 경로를 쓴다. 취소용은
     * 같은 상품옵션의 CANCELLED 행 **전부**의 플래그를 내리므로, 재고를 복원하지 않고 취소된
     * 행까지 "복원됨" 으로 만들어 그 취소를 되살릴 때 재고가 이중 차감된다.
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
     * 반품 발 환불신청서를 생성합니다.
     *
     * 발생 출처는 polymorphic 축(refund_source_type/refund_source_id)에 기록한다. 취소 발
     * 환불과 달리 order_cancel_id 는 채우지 않는다 — 그 컬럼은 취소 이력을 가리키는 자리다.
     *
     * 금액은 **이 신청서 몫**만 싣는다. 재계산(`$result`)은 같은 주문에서 앞 신청서가 이미
     * 완료한 몫까지 누적한 값이라, 그대로 실으면 앞 신청서가 이미 환불한 금액을 다시 담아
     * 과다 환불이 된다(2026-09-21 실측 · 세션B S-B-D7). `$priorResult` 가 주어지면 그만큼
     * 뺀 차분을 싣고, 상품 행(`$claimItems`)도 호출부가 이 신청서 몫만 넘긴다.
     *
     * @param  Order  $order  주문
     * @param  OrderReturn  $return  반품 신청서
     * @param  array  $claimItems  **이 신청서 몫** 아이템 [{order_option_id, cancel_quantity}]
     * @param  AdjustmentResult  $result  누적 계산 결과 (스냅샷·쿠폰 축의 SSoT)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  AdjustmentResult|null  $priorResult  다른 신청서 몫만의 기준선 (차분의 뺄 값)
     * @param  AdjustmentResult|null  $grossResult  기왕 환불액 상한을 끈 누적 결과 (차분의 나누는 값). 없으면 `$result`
     * @return OrderRefund 생성된 환불 신청서
     */
    protected function createRefundRequest(
        Order $order,
        OrderReturn $return,
        array $claimItems,
        AdjustmentResult $result,
        ?int $processedBy,
        ?AdjustmentResult $priorResult = null,
        ?AdjustmentResult $grossResult = null,
    ): OrderRefund {
        $delta = $this->clampDeltaToRemaining($order, $this->refundDeltaAgainstPrior($grossResult ?? $result, $priorResult, $order->currency_snapshot ?? []));

        $refund = $this->refundRepository->create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::RETURN,
            'refund_source_id' => $return->id,
            'refund_number' => $this->sequenceService->generateCode(SequenceType::REFUND),
            'refund_status' => RefundStatusEnum::REQUESTED,
            // 돌려줄 현금이 없는 회원 주문은 결제수단과 무관하게 「마일리지」다(결함 4).
            'refund_method' => $this->refundExecutionService->refundMethodForClaim($order),
            'reason_code' => $return->reason_code,
            'reason_detail' => $return->reason_detail,
            'fault_type' => $return->fault_type,
            'requested_by' => $return->requested_by,
            'refund_amount' => $delta['refund_amount'],
            'refund_points_amount' => $delta['refund_points_amount'],
            // 이 환불액을 나눈 우선순위 — 반품 접수 때 고른 값이 그대로 이어진다.
            'refund_priority' => $result->refundPriority,
            'refund_shipping_amount' => $delta['refund_shipping_amount'],
            'mc_refund_amount' => $delta['mc_refund_amount'],
            'mc_refund_points_amount' => $delta['mc_refund_points_amount'],
            'mc_refund_shipping_amount' => $delta['mc_refund_shipping_amount'],
            'original_calculation_snapshot' => $result->originalSnapshot,
            'recalculated_snapshot' => $result->recalculatedSnapshot,
            'processed_by' => $processedBy,
            // 구매자가 반품 신청서에 적어 낸 계좌를 환불 레코드가 이어받는다 — 실제 송금은 환불을
            // 실행하는 시점에 이 값으로 결제행을 갱신한 뒤 나간다. 이어받지 않으면 신청서에만
            // 남고 송금은 주문 당시 계좌로 간다.
            'refund_bank_code' => $return->refund_bank_code,
            'refund_bank_account' => $return->refund_bank_account,
            'refund_bank_holder' => $return->refund_bank_holder,
            // 교환으로 받은 상품을 반품하면 그 교환 때 낸 상위 옵션 차액도 낸 수단 그대로 돌려준다(S2·Q9)
            'exchange_difference_refunds' => app(ExchangeDifferenceRefundPlanner::class)->plan($order, $claimItems) ?: null,
        ]);

        foreach ($this->refundExecutionService->buildRefundOptionRows($order, $claimItems, $result) as $row) {
            $this->refundOptionRepository->create(
                array_merge(['order_refund_id' => $refund->id], $row)
            );
        }

        // 반품·교환 발 환불신청서도 접수 훅을 쏜다(B8·B9). 예전에는 `OrderRefundRequestService::request()`
        // 한 곳만 발행해, 완료 처리가 만든 환불신청서는 **어떤 이력도 없이** 목록에 나타났다 —
        // 운영자는 그 신청서가 언제 어떤 경로로 생겼는지 되짚을 수 없었다.
        $this->fireAfterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.claim.refund.requested', $refund->fresh(), $order->fresh()));

        // 신청상품 행 생성도 상품 축에 남긴다(§7-1 C12) — 헤더 줄만 남기면 그 환불이 어떤
        // 상품 몇 개를 담고 생겼는지 이력에서 되짚을 수 없다.
        app(OrderRefundRequestService::class)->announceRefundOptionsCreated($refund->fresh());

        return $refund->fresh();
    }

    /**
     * 완료된 신청 상품을 계산기 공용 아이템 형태로 반환합니다.
     *
     * **이 신청서의 완료분 + 같은 주문의 다른 신청서 완료분**을 함께 싣는다(S2-D5).
     *
     * 재계산(`OrderAdjustmentService::buildRecalcInput()`)은 **반품완료(`RETURNED`) 행을
     * 잔여로 센다** — 교환과 달리 승계 행이 없어서 그렇게 설계돼 있고, 그 독블록이 그렇게
     * 선언한다. 그래서 「이번에 빠지는 것」만 넘기면 재계산은 **원 주문 기준 단건
     * 시뮬레이션**이 되고, 앞 회차에서 이미 반품된 상품이 여전히 남아 있다고 계산한다.
     *
     * 그 결과가 쿠폰이다. 순차 부분반품에서 상품을 전부 반품해도 재계산 소계가 원 주문
     * 그대로라 **쿠폰 조건이 한 번도 깨지지 않고**, `detectRestoredCoupons()` 의
     * `array_diff(원 스냅샷, 재계산 적용)` 이 항상 비어 복원 훅이 발화하지 않는다.
     * 고객은 전액 환불받고도 쿠폰을 잃는다(#599 세션2 2-A 회차 ④·⑤ 실측 — 주문 517 에서
     * 6행 전건 반품·환불 5건 완료 후에도 쿠폰 3장이 끝까지 `used` 였다).
     *
     * **공용 경로를 고치지 않는 이유**: `buildRecalcInput()` 은 취소·환불·반품·교환이 모두
     * 타는 단일 경로다. 거기서 `RETURNED` 를 빼면 세 세션의 금액이 동시에 움직인다.
     * 반품이 자기 입력을 누적으로 넘기면 **공용 계약을 건드리지 않고** 같은 결과가 된다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return array [{order_option_id, cancel_quantity}] 주문 옵션별 누적 완료 수량
     */
    protected function completedClaimItems(OrderReturn $return): array
    {
        $quantities = [];

        foreach ($this->completedReturnOptionsForOrder($return) as $item) {
            $optionId = (int) $item->order_option_id;
            $quantities[$optionId] = ($quantities[$optionId] ?? 0) + (int) $item->return_quantity;
        }

        $items = [];

        foreach ($quantities as $optionId => $quantity) {
            $items[] = [
                'order_option_id' => $optionId,
                'cancel_quantity' => $quantity,
            ];
        }

        return $items;
    }

    /**
     * 같은 주문에서 완료된 반품 신청 상품 행을 모읍니다. (이 신청서 + 다른 신청서)
     *
     * 다른 신청서 몫은 DB 에서 다시 읽는다 — 이 신청서의 `returnOptions` 만 보면 앞 회차가
     * 보이지 않는다. 철회·반려된 신청서는 `option_status` 가 `COMPLETED` 가 아니므로
     * 자연히 빠진다.
     *
     * @param  OrderReturn  $return  기준 반품 신청서
     * @return Collection<int, OrderReturnOption> 완료된 신청 상품 행
     */
    protected function completedReturnOptionsForOrder(OrderReturn $return): Collection
    {
        $own = $return->returnOptions
            ->filter(fn (OrderReturnOption $item) => $item->option_status === ReturnStatusEnum::COMPLETED)
            ->values();

        $others = $this->returnOptionRepository->listCompletedByOrderId(
            (int) $return->order_id,
            excludeReturnId: (int) $return->id,
        );

        return $own->concat($others);
    }

    /**
     * 같은 주문의 **다른 신청서**에서 이미 완료된 상품 행만 모읍니다.
     *
     * 재계산 기준선(`completedClaimItems()`)은 주문 전체 누적이어야 쿠폰 탈락·배송비 재판정이
     * 맞는다. 그러나 그 누적 결과를 신규 환불신청서의 금액·상품 행으로 그대로 쓰면 앞 신청서가
     * 이미 환불한 몫을 다시 담아 **과다 환불**이 된다(실측 2026-09-21: 15,000원이어야 할
     * 환불신청서가 60,000원으로 생성, 앞 회차 상품 행이 중복 포함). 그래서 이 메서드가 내는
     * "다른 신청서 몫"으로 기준선을 한 번 더 계산해 **차분**을 내고, 그 차분만 신규 환불신청서에
     * 싣는다. 이 신청서 안에서 여러 라운드로 나눠 완료하는 경우는 차분에서 빠지지 않으므로
     * 「환불신청서는 신청서당 한 건」이라는 기존 계약도 그대로 유지된다.
     *
     * @param  OrderReturn  $return  기준 반품 신청서
     * @return array<int, array{order_option_id: int, cancel_quantity: int}> 다른 신청서의 완료 상품 몫
     */
    protected function otherReturnsCompletedClaimItems(OrderReturn $return): array
    {
        $quantities = [];

        foreach ($this->returnOptionRepository->listCompletedByOrderId(
            (int) $return->order_id,
            excludeReturnId: (int) $return->id,
        ) as $item) {
            $optionId = (int) $item->order_option_id;
            $quantities[$optionId] = ($quantities[$optionId] ?? 0) + (int) $item->return_quantity;
        }

        $items = [];

        foreach ($quantities as $optionId => $quantity) {
            $items[] = [
                'order_option_id' => $optionId,
                'cancel_quantity' => $quantity,
            ];
        }

        return $items;
    }

    /**
     * 누적 목록에서 다른 신청서 몫을 빼 **이 신청서 몫**만 남깁니다. (환불신청서 상품 행용)
     *
     * 같은 상품옵션을 두 신청서가 나눠 반품한 경우가 있으므로 수량 단위로 뺀다 — 옵션 ID 로
     * 통째 제외하면 이 신청서 몫까지 사라진다.
     *
     * @param  array<int, array{order_option_id: int, cancel_quantity: int}>  $all  주문 전체 누적 몫
     * @param  array<int, array{order_option_id: int, cancel_quantity: int}>  $prior  다른 신청서 몫
     * @return array<int, array{order_option_id: int, cancel_quantity: int}> 이 신청서 몫
     */
    protected function ownCompletedClaimItems(array $all, array $prior): array
    {
        $priorQuantities = [];

        foreach ($prior as $item) {
            $optionId = (int) $item['order_option_id'];
            $priorQuantities[$optionId] = ($priorQuantities[$optionId] ?? 0) + (int) $item['cancel_quantity'];
        }

        $items = [];

        foreach ($all as $item) {
            $optionId = (int) $item['order_option_id'];
            $quantity = (int) $item['cancel_quantity'] - ($priorQuantities[$optionId] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $items[] = [
                'order_option_id' => $optionId,
                'cancel_quantity' => $quantity,
            ];
        }

        return $items;
    }

    /**
     * 다른 신청서 몫만으로 재계산한 기준선을 냅니다. (차분의 뺄 값)
     *
     * 회수배송비는 **이 신청서**의 것이므로 기준선 계산에서는 0 으로 둔다 — 앞 신청서의
     * 회수비는 그 신청서의 환불신청서에서 이미 차감됐다.
     *
     * @param  Order  $order  주문
     * @param  array<int, array{order_option_id: int, cancel_quantity: int}>  $priorItems  다른 신청서 완료 몫
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위 (차분의 나누는 값과 같은 값이어야 PG·마일리지 차분이 맞는다)
     * @return AdjustmentResult|null 앞 신청서가 없으면 null
     */
    protected function priorReturnsAdjustment(
        Order $order,
        array $priorItems,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): ?AdjustmentResult {
        if ($priorItems === []) {
            return null;
        }

        return $this->adjustmentService->calculateWithoutPriorRefundCap($order, new ReturnAdjustment(
            returnItems: $priorItems,
            collectionFee: 0,
            chargeMethod: null,
        ), $refundPriority);
    }

    /**
     * 누적 재계산 결과를 **상한 없이** 다시 냅니다. (차분의 나누는 값)
     *
     * 앞 신청서가 없으면 상한도 걸리지 않으므로 원 결과를 그대로 쓴다.
     *
     * @param  Order  $order  주문
     * @param  array<int, array{order_option_id: int, cancel_quantity: int}>  $claimItems  주문 전체 누적 몫
     * @param  int|float  $collectionFee  이 신청서의 회수배송비 (소수 자릿수 통화는 소수 보존)
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  이 신청서의 청구 방식
     * @param  AdjustmentResult  $fallback  앞 신청서가 없을 때 쓸 원 결과
     * @param  bool  $hasPrior  앞 신청서 존재 여부
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위 (원 결과와 같은 값)
     * @return AdjustmentResult 상한 없는 누적 결과
     */
    protected function uncappedAdjustment(
        Order $order,
        array $claimItems,
        int|float $collectionFee,
        ?ClaimChargeMethodEnum $chargeMethod,
        AdjustmentResult $fallback,
        bool $hasPrior,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): AdjustmentResult {
        if (! $hasPrior) {
            return $fallback;
        }

        return $this->adjustmentService->calculateWithoutPriorRefundCap($order, new ReturnAdjustment(
            returnItems: $claimItems,
            collectionFee: $collectionFee,
            chargeMethod: $chargeMethod,
        ), $refundPriority);
    }

    /**
     * 누적 재계산 결과에서 앞 신청서 몫을 뺀 금액을 냅니다.
     *
     * @param  AdjustmentResult  $full  이 신청서까지 포함한 누적 결과
     * @param  AdjustmentResult|null  $prior  다른 신청서 몫만의 기준선
     * @param  array  $currencySnapshot  주문 통화 스냅샷 (통화별 소수 자릿수 — 맵 차분의 끝자리 정리)
     * @return array{refund_amount: float, refund_points_amount: float, refund_shipping_amount: float, mc_refund_amount: array|null, mc_refund_points_amount: array|null, mc_refund_shipping_amount: array|null} 이 신청서 몫
     */
    protected function refundDeltaAgainstPrior(AdjustmentResult $full, ?AdjustmentResult $prior, array $currencySnapshot = []): array
    {
        if (! $prior instanceof AdjustmentResult) {
            return [
                'refund_amount' => (float) $full->refundAmount,
                'refund_points_amount' => (float) $full->refundPointsAmount,
                'refund_shipping_amount' => (float) $full->shippingDifference,
                'mc_refund_amount' => $full->mcRefundAmount,
                'mc_refund_points_amount' => $full->mcRefundPointsAmount,
                'mc_refund_shipping_amount' => $full->mcRefundShippingAmount,
            ];
        }

        return [
            'refund_amount' => max(0.0, (float) $full->refundAmount - (float) $prior->refundAmount),
            'refund_points_amount' => max(0.0, (float) $full->refundPointsAmount - (float) $prior->refundPointsAmount),
            'refund_shipping_amount' => (float) $full->shippingDifference - (float) $prior->shippingDifference,
            'mc_refund_amount' => $this->subtractMoneyMap($full->mcRefundAmount, $prior->mcRefundAmount, currencySnapshot: $currencySnapshot),
            'mc_refund_points_amount' => $this->subtractMoneyMap($full->mcRefundPointsAmount, $prior->mcRefundPointsAmount, currencySnapshot: $currencySnapshot),
            'mc_refund_shipping_amount' => $this->subtractMoneyMap($full->mcRefundShippingAmount, $prior->mcRefundShippingAmount, allowNegative: true, currencySnapshot: $currencySnapshot),
        ];
    }

    /**
     * 차분 결과를 **실제 지급 가능한 잔여**에 맞춥니다. (합계는 유지하고 배분만 옮긴다)
     *
     * `OrderAdjustmentService::calculateWithoutPriorRefundCap()` 은 차분을 내기 위해 기왕 환불액 상한을 껐다. 그 상태의 배분은
     * 마일리지 몫을 "이 주문에서 원래 쓴 만큼" 까지 잡으므로, 앞 회차가 이미 마일리지를
     * 전액 돌려준 뒤에는 **낼 수 없는 마일리지 환불액**이 남는다.
     *
     * 실측(주문 1507 · 2026-09-22): 마일리지 10,000원을 쓴 주문에서 앞 회차가 10,000원을 모두
     * 복원한 뒤, 마지막 회차의 환불신청서가 `refund_points_amount = 2,500` 으로 만들어져
     * 실행이 422 「마일리지 환불액(2,500원)이 그 수단의 잔여(0원)를 초과합니다」로 막혔다.
     *
     * 그래서 총액(현금 + 마일리지)은 그대로 두고 **마일리지를 잔여까지만 잡은 뒤 나머지를
     * 현금으로 옮긴다.** 총액까지 잘라 내면 이번 회차가 덜 환불되어 잔여가 0 으로 떨어지지 않는다.
     *
     * @param  Order  $order  주문 (기왕 환불액의 SSoT)
     * @param  array{refund_amount: float, refund_points_amount: float, refund_shipping_amount: float, mc_refund_amount: array|null, mc_refund_points_amount: array|null, mc_refund_shipping_amount: array|null}  $delta  차분 결과
     * @return array 잔여에 맞춘 차분 결과
     */
    protected function clampDeltaToRemaining(Order $order, array $delta): array
    {
        $remainingPoints = max(0.0, (float) $order->total_points_used_amount - (float) $order->total_refunded_points_amount);
        $remainingCash = max(0.0, (float) $order->total_paid_amount - (float) $order->total_refunded_amount);

        $total = (float) $delta['refund_amount'] + (float) $delta['refund_points_amount'];
        $points = min((float) $delta['refund_points_amount'], $remainingPoints);
        $cash = min($total - $points, $remainingCash);

        if ($points === (float) $delta['refund_points_amount'] && $cash === (float) $delta['refund_amount']) {
            return $delta;
        }

        $delta['refund_amount'] = $cash;
        $delta['refund_points_amount'] = $points;

        // 통화별 맵은 기준통화 배분을 따라간다 — 마일리지 몫이 0 이 되면 그 맵도 0 이어야
        // 화면(다통화 병기)과 실행 게이트가 같은 값을 본다.
        if ($points <= 0.0 && is_array($delta['mc_refund_points_amount'])) {
            $delta['mc_refund_points_amount'] = array_map(fn () => 0.0, $delta['mc_refund_points_amount']);
        }

        // 현금 맵도 옮긴 뒤의 현금액으로 다시 낸다 — 기준 통화 현금액만 바꾸면 통화별 맵은 옮기기 전 몫을 말해,
        // 고객 화면에 23,000원 옆으로 13,000원어치 외화가 찍힌다(S3-D13). 환율은 주문 currency_snapshot 의 동결 환율이다.
        $currencySnapshot = $order->currency_snapshot ?? [];
        if ($currencySnapshot !== []) {
            $delta['mc_refund_amount'] = $this->currencyService->convertToMultiCurrencyWithSnapshot($cash, $currencySnapshot);
            if ($points > 0.0) {
                $delta['mc_refund_points_amount'] = $this->currencyService->convertToMultiCurrencyWithSnapshot($points, $currencySnapshot);
            }
        }

        return $delta;
    }

    /**
     * 통화별 금액 맵끼리 뺍니다.
     *
     * @param  array<string, float|int>|null  $full  빼일 값
     * @param  array<string, float|int>|null  $prior  뺄 값
     * @param  bool  $allowNegative  음수를 허용할지 (배송비 차액은 음수가 뜻을 가진다)
     * @param  array  $currencySnapshot  주문 통화 스냅샷 (통화별 소수 자릿수)
     * @return array<string, float>|null 차분 맵 (원본이 null 이면 null)
     */
    protected function subtractMoneyMap(?array $full, ?array $prior, bool $allowNegative = false, array $currencySnapshot = []): ?array
    {
        if ($full === null) {
            return null;
        }

        $out = [];

        foreach ($full as $code => $amount) {
            // 두 맵은 이미 통화 자릿수로 끊긴 값이다 — 빼기만 하면 이진 부동소수 꼬리(17.08 − 7.42 = 9.659999999999998)가
            // 그대로 저장된다(S3-D13 · EC-486 과 같은 꼴). 그 통화의 소수 자릿수로 다시 끊는다.
            $delta = round(
                (float) $amount - (float) ($prior[$code] ?? 0),
                $this->currencyService->getDecimalPlaces((string) $code, $currencySnapshot ?: null),
            );
            $out[$code] = $allowNegative ? $delta : max(0.0, $delta);
        }

        return $out;
    }

    /**
     * 모든 신청 상품이 완료됐는지 반환합니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return bool 전건 완료면 true
     */
    protected function allItemsCompleted(OrderReturn $return): bool
    {
        // 「살아 있는 상품 0 그리고 완료 상품 ≥ 1」(S8) — 반려·철회된 상품이 섞여도 나머지가 모두
        // 완료되면 신청서는 완료다. 예전 판정(모든 행 완료)은 반려 행 하나가 헤더를 영원히 묶었다.
        $items = $return->returnOptions;

        return $items->contains(fn (OrderReturnOption $item) => $item->option_status === ReturnStatusEnum::COMPLETED)
            && ! $items->contains(fn (OrderReturnOption $item) => ! $item->option_status->isFinal());
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 아이템 정규화
    // ───────────────────────────────────────────────

    /**
     * 신청 페이로드의 수량 키를 가드가 읽는 `quantity` 로 정규화합니다. (`cancel_quantity` 호환)
     *
     * @param  array  $items  [{order_option_id, quantity|cancel_quantity}]
     * @return array [{order_option_id, quantity}]
     */
    protected function normalizeItemQuantities(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $normalized[] = [
                'order_option_id' => (int) ($item['order_option_id'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? $item['cancel_quantity'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * 해석된 대상을 계산기 공용 아이템 형태로 변환합니다.
     *
     * `cancel_quantity` 키는 `OrderAdjustmentService` / `ClaimShippingFeeService` 가 공유하는 형태다 —
     * 클레임 전용 키를 새로 만들면 두 계산기의 안분·조건부무료 재판정 경로를 다시 뚫어야 한다.
     *
     * @param  array<int, array{option: OrderOption, quantity: int}>  $resolved  해석된 대상
     * @return array [{order_option_id, cancel_quantity}]
     */
    protected function toClaimItemsFromResolved(array $resolved): array
    {
        $normalized = [];

        foreach ($resolved as $entry) {
            $normalized[] = [
                'order_option_id' => $entry['option']->id,
                'cancel_quantity' => $entry['quantity'],
            ];
        }

        return $normalized;
    }

    /**
     * 승인 전 신청 내용을 수정합니다. (D13 사유·상세 사유)
     *
     * 수정 가능 경계는 철회 경계와 같다(신청·결제대기·보류). 사유(귀책)가 바뀌면 회수 배송비를 접수와 같은
     * 산식으로 재산정해 청구액을 갱신한다. 청구액이 0 에서 양수가 되면 청구 방식이 필요하므로 페이로드의
     * `charge_method` 를 함께 받는다. 이미 받은 추가결제와의 차액 정산은 호출부가
     * `ClaimPaymentService::settleChargeDifference()` 로 한다(교환과 같은 분담).
     *
     * 실제로 바뀐 항목은 커밋 뒤 `claim.return.request_updated` 로 전/후와 함께 나간다 — 운영자가 고객 대신
     * 사유를 바꾸면 회수비를 누가 내는지가 달라지므로 활동 로그가 그 흔적을 남긴다(`ReportsClaimRequestUpdate`).
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  array  $payload  수정 페이로드 (reason_code? / reason_detail? / charge_method?)
     * @param  bool  $operator  운영자 수정 여부 (S15 — 판매자 귀책 사유 허용, 판매자 접수 건도 수정)
     * @return OrderReturn 수정된 신청서
     *
     * @throws OrderClaimException 수정 불가 상태 / 사유 부재 / 청구 방식 부재·불허·차감 불가
     */
    public function updateRequest(OrderReturn $return, array $payload, bool $operator = false): OrderReturn
    {
        // 판매자가 접수한 신청은 고객이 수정할 수 없다(D8). 운영자 수정(S15)은 통과한다.
        if (! $operator && $return->isAdminRequested()) {
            throw OrderClaimException::adminRequestedNotEditable();
        }

        // 수정의 before·filter 훅 — 잠금·쓰기 전이다. 필터는 사유(`CLAIM_FILTERABLE_UPDATE_KEYS`)만
        // 바꿀 수 있고, 바뀐 값은 아래 사유 해석·회수비 재산정을 고객 입력과 똑같이 지난다.
        // 청구 방식·환불 계좌는 돈의 경로라 되돌린다.
        HookManager::doAction('sirsoft-ecommerce.claim.return.before_update_request', $return, $payload);

        $payload = $this->acceptClaimFilterResult(
            HookManager::applyFilters('sirsoft-ecommerce.claim.return.filter_update_request_data', $payload, $return),
            $payload,
            self::CLAIM_FILTERABLE_UPDATE_KEYS,
        );

        return DB::transaction(function () use ($return, $payload, $operator) {
            $locked = $this->returnRepository->findForUpdate($return->id) ?? $return;

            // 수정 가능 여부는 **잠금 안에서 다시 읽은 상태**로 판정한다(#599 validate P2 #8).
            // 호출부 모델로 판정하면 그 사이 승인된 신청서의 사유·청구가 수정된다.
            if (! $locked->return_status->isWithdrawable()) {
                throw OrderClaimException::notEditable();
            }

            $locked->load(['returnOptions', 'order.options', 'order.shippings', 'order.payment']);
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

            $chargeMethod = array_key_exists('charge_method', $payload)
                ? $this->normalizeChargeMethod($payload['charge_method'])
                : $locked->charge_method;

            if ($reason !== null) {
                $claimItems = [];

                foreach ($locked->returnOptions as $item) {
                    $claimItems[] = [
                        'order_option_id' => (int) $item->order_option_id,
                        'cancel_quantity' => (int) $item->return_quantity,
                    ];
                }

                $shippingFee = $this->claimShippingFeeService->calculate(
                    $order,
                    ClaimTypeEnum::RETURN,
                    $claimItems,
                    $reason->fault_type,
                );

                // 동결 스냅샷은 접수 때와 같은 우선순위로 다시 낸다(저장값 없으면 종전 PG 우선).
                $refundPriority = $this->refundPriorityOf($locked);
                $this->assertChargeMethodUsable($order, $claimItems, $shippingFee, $chargeMethod, $refundPriority);

                // 회수비가 다시 계산됐으므로 접수 시점 비교 스냅샷도 같은 재료로 갱신한다.
                $frozen = $this->adjustmentService->preview(
                    $order,
                    new ReturnAdjustment(returnItems: $claimItems, collectionFee: $shippingFee->fee, chargeMethod: $chargeMethod),
                    $refundPriority,
                );
                $updates['original_calculation_snapshot'] = $frozen->originalSnapshot;
                $updates['recalculated_snapshot'] = $frozen->recalculatedSnapshot;

                $updates['shipping_fee_mode'] = $shippingFee->mode;
                $updates['claim_shipping_fee'] = $shippingFee->fee;
                $updates['mc_claim_shipping_fee'] = $shippingFee->mcFee ?: null;
                $updates['shipping_fee_snapshot'] = $shippingFee->toArray();
                $updates['total_charge_amount'] = $shippingFee->fee;
                $updates['mc_total_charge_amount'] = $shippingFee->mcFee ?: null;
                $updates['charge_method'] = $shippingFee->fee > 0 ? $chargeMethod : null;
            } elseif (array_key_exists('charge_method', $payload) && (float) $locked->total_charge_amount > 0) {
                if ($chargeMethod === null) {
                    throw OrderClaimException::chargeMethodRequired();
                }

                $allowed = (array) $this->settingsService->getSetting('claim.allowed_charge_methods', ClaimChargeMethodEnum::values());

                if (! in_array($chargeMethod->value, $allowed, true)) {
                    throw OrderClaimException::chargeMethodNotAllowed();
                }

                $updates['charge_method'] = $chargeMethod;
            }

            if ($updates === []) {
                return $locked;
            }

            $updated = $this->returnRepository->update($locked, $updates)->fresh(['returnOptions']);

            // 같은 값으로 다시 저장한 수정은 알리지 않는다 — 활동 로그에 빈 이력이 쌓이면 실제로 바뀐 줄이 묻힌다.
            $changes = $this->claimRequestUpdateChanges($before, $this->claimRequestUpdateSnapshot($updated));

            if ($changes !== []) {
                $context = $this->claimRequestUpdateContext($operator);
                $this->fireAfterCommit(fn () => HookManager::doAction(
                    'sirsoft-ecommerce.claim.return.request_updated',
                    $updated,
                    $changes,
                    $context,
                ));
            }

            return $updated;
        });
    }

    /**
     * 신청 자격 상태 목록을 반환합니다. (운영자 직권 접수면 물리 게이트 — D5)
     *
     * @param  bool  $operator  운영자 직권 접수 여부
     * @return array<string> 상태 값 목록
     */
    protected function requestStatusValuesFor(bool $operator): array
    {
        return $operator ? ClaimPhysicalGate::statuses(ClaimTypeEnum::RETURN) : $this->requestableStatusValues();
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
            mcFee: $fee > 0 ? ($this->toMultiCurrencyOrNull((float) $fee, $currencySnapshot) ?? []) : [],
            redeliveryChanged: $shippingFee->redeliveryChanged,
        );
    }
}
