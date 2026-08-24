<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\DTO\ReturnAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
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
 * ## 주문 상태는 건드리지 않는다
 *
 * 코스 4종은 `OrderStatusEnum::syncExcludedStatuses()` 에 속해 주문 상태 동기화 대상이
 * 아니다. 전량 반품 신청에서 `OrderOptionService::syncParentOrderStatus()` 를 부르면 활성
 * 옵션이 0건이 되어 **주문이 취소로 전이**되므로 이 서비스는 그 동기화를 부르지 않는다.
 *
 * ## 완료 처리와 환불신청서 접수는 분리돼 있다 (D26)
 *
 * `completeReturn()` 은 `ClaimCompletionOptions` 를 필수 인자로 받는다. 설정
 * (`claim.complete_defaults.*`)은 모달의 초기 선택값일 뿐이며 이 서비스는 읽지 않는다 —
 * 읽으면 "요청에 없으면 설정대로" 라는 숨은 자동 실행이 되살아난다.
 */
class OrderReturnService
{
    /**
     * @param  OrderAdjustmentService  $adjustmentService  환불금액 재계산 서비스
     * @param  ClaimShippingFeeService  $claimShippingFeeService  클레임 배송비 산정 서비스
     * @param  ClaimQuantityGuard  $claimQuantityGuard  클레임 수량 잠금 가드 (D15)
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
     * @return array{shipping_fee: ClaimShippingFee, adjustment: AdjustmentResult, fault_type: ClaimReasonFaultTypeEnum, charge_method: ClaimChargeMethodEnum|null, total_charge_amount: float, requires_payment: bool} 미리보기 결과
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
    ): array {
        $reason = $this->resolveReason($reasonCode);

        // 신청과 같은 자격 가드(상태·수량·잠금·기간) — 신청할 수 없는 조합의 금액을 보여 주지 않는다.
        $resolved = $this->claimQuantityGuard->resolveClaimItems(
            $order,
            $this->normalizeItemQuantities($items),
            ClaimTypeEnum::RETURN,
            $this->requestableStatusValues(),
            null,
            $excludeReturnId,
        );
        $this->assertWithinRequestPeriod($resolved);
        $claimItems = $this->toClaimItemsFromResolved($resolved);

        $shippingFee = $this->claimShippingFeeService->calculate(
            $order,
            ClaimTypeEnum::RETURN,
            $claimItems,
            $reason->fault_type,
        );

        $adjustment = new ReturnAdjustment(
            returnItems: $claimItems,
            collectionFee: $shippingFee->fee,
            chargeMethod: $chargeMethod,
        );

        $result = $this->adjustmentService->preview($order, $adjustment, $refundPriority);

        return [
            'shipping_fee' => $shippingFee,
            'adjustment' => $result,
            'fault_type' => $reason->fault_type,
            'charge_method' => $chargeMethod,
            'total_charge_amount' => (float) $shippingFee->fee,
            'requires_payment' => $shippingFee->fee > 0 && $chargeMethod?->requiresPayment() === true,
        ];
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
        $reason = $this->resolveReason((string) ($payload['reason_code'] ?? ''));
        $chargeMethod = $this->normalizeChargeMethod($payload['charge_method'] ?? null);
        $refundPriority = $payload['refund_priority'] ?? RefundPriorityEnum::PG_FIRST;
        $requestedBy = $payload['requested_by'] ?? null;

        $orderReturn = DB::transaction(function () use ($order, $payload, $reason, $chargeMethod, $refundPriority, $requestedBy) {
            // 잠금은 잔여 수량을 읽기 전에 잡는다 — 읽고 나서 잠그면 이미 두 요청이 같은 값을 봤다.
            $lockedOrder = $this->orderRepository->findForUpdate($order->id) ?? $order;
            $lockedOrder->load(['options', 'shippings', 'payment']);

            $resolved = $this->claimQuantityGuard->resolveClaimItems(
                $lockedOrder,
                $payload['items'] ?? [],
                ClaimTypeEnum::RETURN,
                $this->requestableStatusValues(),
            );

            $this->assertWithinRequestPeriod($resolved);

            $claimItems = $this->toClaimItemsFromResolved($resolved);

            $shippingFee = $this->claimShippingFeeService->calculate(
                $lockedOrder,
                ClaimTypeEnum::RETURN,
                $claimItems,
                $reason->fault_type,
            );

            $this->assertChargeMethodUsable($lockedOrder, $claimItems, $shippingFee, $chargeMethod, $refundPriority);

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
                'shipping_fee_snapshot' => $shippingFee->toArray(),
                'total_charge_amount' => $shippingFee->fee,
                'mc_total_charge_amount' => $shippingFee->mcFee ?: null,
                'currency_code' => $this->resolveBaseCurrency($lockedOrder),
                'items_snapshot' => $this->buildItemsSnapshot($resolved),
                'return_address_snapshot' => $this->resolveReturnAddressSnapshot($lockedOrder, $resolved),
                'requested_by' => $requestedBy,
            ]);

            foreach ($resolved as $entry) {
                $this->createReturnOption($return, $lockedOrder, $entry['option'], $entry['quantity']);
            }

            return $return->fresh(['returnOptions']);
        });

        HookManager::doAction('sirsoft-ecommerce.claim.return.requested', $orderReturn, $order->fresh());

        // 청구액 0원 자동 승인 (§4-2 전이표 자동 열). 접수 훅을 먼저 발화한 뒤 전이하므로
        // 알림·로그 리스너는 '신청 → 승인' 두 사건을 순서대로 받는다. 전이는 transition() 에
        // 위임해 게이트·타임스탬프·상품 상태 동기화·훅이 수동 승인과 같은 경로를 타게 한다.
        if ($this->shouldAutoApproveZeroCharge($orderReturn)) {
            return $this->transition($orderReturn, ReturnStatusEnum::APPROVED);
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
        if ((int) $return->total_charge_amount > 0) {
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
     * @return OrderReturn 철회된 신청서
     *
     * @throws OrderClaimException 철회 불가 상태
     */
    public function withdraw(OrderReturn $return): OrderReturn
    {
        if (! $return->return_status->isWithdrawable()) {
            throw OrderClaimException::notWithdrawable();
        }

        return $this->transition($return, ReturnStatusEnum::WITHDRAWN);
    }

    /**
     * 반품 신청서 상태를 전이합니다.
     *
     * 완료(COMPLETED)는 이 경로로 갈 수 없다 — 완료는 재고 복구 여부와 환불신청서 접수
     * 여부를 운영자가 고르는 절차(D26)이므로 `completeReturn()` 만이 진입점이다.
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

        $current = $return->return_status;

        if (! $current->canTransitionTo($target)) {
            throw OrderClaimException::transitionNotAllowed($current->label(), $target->label());
        }

        if ($this->revertsOrderOptions($target)) {
            $this->assertLivingItemsCanTransitionTo($return, $target);
        }

        $updated = $this->applyTransition($return, $target, $context);

        $this->fireStatusHooks($updated, $current, $target);

        return $updated;
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
     *
     * @throws OrderClaimException 앞서 있는 상품이 있을 때
     */
    protected function assertLivingItemsCanTransitionTo(OrderReturn $return, ReturnStatusEnum $target): void
    {
        $return->loadMissing('returnOptions');

        foreach ($return->returnOptions as $item) {
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
     * `transition()` 이 게이트 → 이 메서드 → 훅 순으로 부르는 본체다. 송장 등록처럼 다른 쓰기와
     * 전이를 한 트랜잭션에 묶어야 하는 호출부는 이 메서드를 바깥 트랜잭션 안에서 부르고 훅은
     * 커밋 뒤에 직접 발화한다 — 훅을 트랜잭션 안에서 발화하면 롤백된 전이의 알림이 나간다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태 (게이트 통과 전제)
     * @param  array  $context  부가 정보 (processed_by / reject_reason / admin_memo)
     * @return OrderReturn 전이된 신청서
     */
    protected function applyTransition(OrderReturn $return, ReturnStatusEnum $target, array $context): OrderReturn
    {
        $processedBy = $context['processed_by'] ?? null;

        return DB::transaction(function () use ($return, $target, $context, $processedBy) {
            $updates = ['return_status' => $target];

            if ($processedBy !== null) {
                $updates['processed_by'] = $processedBy;
            }

            if (array_key_exists('admin_memo', $context)) {
                $updates['admin_memo'] = $context['admin_memo'];
            }

            $updates += $this->stampFor($target, $context);

            $fresh = $this->returnRepository->update($return, $updates);

            $this->syncOptionStatuses($fresh, $target, $processedBy);

            // 반려·철회는 상품옵션을 신청 직전 상태로 되돌린다 (PO 확정 (a)).
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
     * @param  OrderReturn  $return  반품 신청서
     * @param  array<int, int>  $itemIds  신청 상품 행 ID 목록 (빈 배열이면 전체)
     * @param  ReturnStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보 (processed_by)
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>} 전이 결과
     *
     * @throws OrderClaimException 완료 전이를 시도한 경우
     */
    public function transitionOption(
        OrderReturn $return,
        array $itemIds,
        ReturnStatusEnum $target,
        array $context = [],
    ): array {
        if ($target === ReturnStatusEnum::COMPLETED) {
            throw OrderClaimException::completionRequiresCompletionFlow();
        }

        $processedBy = $context['processed_by'] ?? null;
        $items = $this->resolveItems($return, $itemIds);

        $succeeded = [];
        $skipped = [];

        DB::transaction(function () use ($items, $target, $processedBy, &$succeeded, &$skipped) {
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

                $updatedItem = $this->returnOptionRepository->update($item, [
                    'option_status' => $target,
                    'processed_by' => $processedBy ?? $item->processed_by,
                ]);

                // 반려·철회된 상품은 그 즉시 원 주문옵션을 되돌린다 — 신청서 단위 반려가
                // 하는 일을 상품 단위로 적용하는 것이다(2026-08-22 PO 확정 ⑤). 미루면 그
                // 상품은 어디로도 못 간다: 취소·관리자 상태 변경은 클레임 가드가 막고,
                // 되돌려 줄 신청서는 이미 종결이라 복귀를 부를 경로가 남지 않는다.
                if ($this->revertsOrderOptions($target)) {
                    $this->revertOrderOptionFor($updatedItem->loadMissing('orderOption'));
                }

                $succeeded[] = $item->id;
            }
        });

        $this->syncHeaderFromOptions($return->fresh(['returnOptions']), $processedBy);

        return ['succeeded' => $succeeded, 'skipped' => $skipped];
    }

    // ───────────────────────────────────────────────
    // 회수 송장 / 금액 수정
    // ───────────────────────────────────────────────

    /**
     * 회수 운송장을 기록하고 회수중으로 전이합니다.
     *
     * 송장이 입력되면 회수가 시작된 것이므로 회수준비중 → 회수중 전이를 함께 수행한다
     * (§4-2 "송장 입력 시 자동 collecting"). 이미 회수중 이후면 송장만 갱신한다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  int|null  $carrierId  회수 택배사 ID
     * @param  string|null  $trackingNumber  회수 운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderReturn 갱신된 신청서
     *
     * @throws OrderClaimException 송장 정보 불완전
     */
    public function registerCollectionTracking(
        OrderReturn $return,
        ?int $carrierId,
        ?string $trackingNumber,
        ?int $processedBy = null,
    ): OrderReturn {
        $trackingNumber = $trackingNumber !== null && trim($trackingNumber) !== ''
            ? trim($trackingNumber)
            : null;

        if (($carrierId === null) !== ($trackingNumber === null)) {
            throw OrderClaimException::trackingIncomplete();
        }

        $previous = $return->return_status;
        $startsCollecting = $previous === ReturnStatusEnum::COLLECTION_READY && $trackingNumber !== null;

        // 송장 저장과 회수중 전환은 한 묶음이다 — 따로 두면 전환이 실패했을 때 "송장은 있는데
        // 회수준비중" 이 남는다. 훅은 커밋 뒤에 발화한다.
        $updated = DB::transaction(function () use ($return, $carrierId, $trackingNumber, $processedBy, $startsCollecting) {
            $tracked = $this->returnRepository->update($return, [
                'collection_carrier_id' => $carrierId,
                'collection_tracking_number' => $trackingNumber,
                'processed_by' => $processedBy ?? $return->processed_by,
            ]);

            return $startsCollecting
                ? $this->applyTransition($tracked, ReturnStatusEnum::COLLECTING, ['processed_by' => $processedBy])
                : $tracked;
        });

        if ($startsCollecting) {
            $this->fireStatusHooks($updated, $previous, ReturnStatusEnum::COLLECTING);
        }

        return $updated;
    }

    /**
     * 관리자가 청구 금액을 수정합니다. (D18 금액 수정의 클레임 청구액 축)
     *
     * 배송비를 수정하면 총 청구액과 다통화 표기가 함께 움직여야 한다 — 한쪽만 고치면
     * 화면의 청구액과 실제 청구 근거가 갈린다. 산정 근거 스냅샷에는 수정 사실을 남긴다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  array  $amounts  수정값 (claim_shipping_fee / admin_memo)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderReturn 갱신된 신청서
     *
     * @throws OrderClaimException 금액이 음수일 때
     */
    public function updateAmounts(OrderReturn $return, array $amounts, ?int $processedBy = null): OrderReturn
    {
        $updates = [];

        if (array_key_exists('claim_shipping_fee', $amounts)) {
            $fee = (float) $amounts['claim_shipping_fee'];

            if ($fee < 0) {
                throw OrderClaimException::amountInvalid();
            }

            // 청구 방식이 없는 신청서(접수 시 청구 0원)의 청구액을 양수로 올리면 결제할 수 없는 청구가
            // 남는다 — 청구 방식을 함께 정하는 경로(금액 수정 API)가 아니면 거절한다.
            if ($fee > 0 && $return->charge_method === null) {
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

        if ($processedBy !== null) {
            $updates['processed_by'] = $processedBy;
        }

        if ($updates === []) {
            return $return;
        }

        return $this->returnRepository->update($return, $updates);
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
     * 환불신청서는 신청서당 한 건이다. 반품 헤더의 `refund_id` 가 선점액 집계(D19 ③ 축)에서
     * 그 반품을 빼는 근거이므로, 두 건이 생기면 한쪽이 집계에서 누락돼 이중 계상이 된다.
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
        if ($options->createRefundRequest) {
            $this->assertRefundRequestNotYetCreated($return);
        }

        $targets = array_values(array_filter(
            $this->resolveItems($return, $itemIds),
            fn (OrderReturnOption $item) => $item->option_status->canTransitionTo(ReturnStatusEnum::COMPLETED)
                && ! $item->option_status->isFinal(),
        ));

        if ($targets === []) {
            throw OrderClaimException::completionItemsInvalid();
        }

        $previousStatus = $return->return_status;

        $completed = DB::transaction(function () use ($return, $targets, $options, $processedBy) {
            $order = $this->orderRepository->findForUpdate($return->order_id) ?? $return->order;
            $order->load(['options', 'shippings', 'payment']);

            // 잠금을 잡은 뒤 접수 여부를 **다시** 판정한다. 위 사전 검사는 호출부가 들고 온
            // 모델을 보므로, 두 처리자가 각자 접수 전 상태를 읽은 뒤 차례로 저장되면 둘 다
            // 통과해 환불신청서가 두 건 생긴다. 헤더 `refund_id` 는 나중 것만 남아 앞 건이
            // 취소 가능 금액의 선점액 집계에서 빠지고, 그만큼 한도가 넓게 계산된다.
            if ($options->createRefundRequest) {
                $this->assertRefundRequestNotYetCreated(
                    $this->returnRepository->findById($return->id) ?? $return
                );
            }

            $restoredAny = false;

            foreach ($targets as $item) {
                $this->returnOptionRepository->update($item, [
                    'option_status' => ReturnStatusEnum::COMPLETED,
                    'completed_at' => now(),
                    'processed_by' => $processedBy ?? $item->processed_by,
                ]);

                $orderOption = $order->options->firstWhere('id', $item->order_option_id);

                if ($orderOption) {
                    $this->markOrderOptionReturned($orderOption, (int) $item->return_quantity);

                    if ($options->shouldRestoreStock($item->id)) {
                        $this->restoreStockForItem($orderOption, (int) $item->return_quantity);
                        $restoredAny = true;
                    }

                    // 적립예정/기적립 회수. 분할 레코드에는 적립 lot 이 없어 자연 no-op 이며,
                    // 이는 부분취소가 이미 갖는 특성과 같다(적립 lot 은 원 옵션에 남는다).
                    $this->mileageService->cancelEarnForOption($order, $orderOption->fresh());
                }
            }

            // 재계산 기준은 **이 신청서에서 지금까지 완료된 상품 전부**다. 이번 라운드 몫만
            // 넘기면 여러 라운드로 나눠 완료할 때 마지막 라운드의 환불신청서가 앞 라운드 상품을
            // 담지 못한다(환불신청서는 신청서당 한 건이다).
            $return->load('returnOptions');
            $claimItems = $this->completedClaimItems($return);

            $adjustment = new ReturnAdjustment(
                returnItems: $claimItems,
                collectionFee: (int) round((float) $return->claim_shipping_fee),
                chargeMethod: $return->charge_method,
            );

            $result = $this->adjustmentService->calculate($order, $adjustment);

            $isFullyCompleted = $this->allItemsCompleted($return);

            // 쿠폰 복원은 신청서당 한 번, 전건 완료 시점에만 수행한다. 라운드마다 부르면
            // 같은 쿠폰이 여러 번 복원돼 고객이 쓰지 않은 쿠폰을 얻는다.
            if ($isFullyCompleted) {
                $this->refundExecutionService->restoreCoupons($order, $result);
            }

            $refund = null;

            if ($options->createRefundRequest) {
                $refund = $this->createRefundRequest($order, $return, $claimItems, $result, $processedBy);
            }

            $updates = [
                'processed_by' => $processedBy ?? $return->processed_by,
            ];

            if ($restoredAny) {
                $updates['is_stock_restored'] = true;
            }

            if ($refund) {
                $updates['refund_id'] = $refund->id;
            }

            // 모든 신청 상품이 완료됐을 때만 헤더가 완료된다 — 일부만 완료한 라운드에서
            // 헤더까지 완료로 넘기면 남은 상품의 회수·완료 경로가 사라진다.
            if ($isFullyCompleted) {
                $updates['return_status'] = ReturnStatusEnum::COMPLETED;
                $updates['completed_at'] = now();
            }

            return $this->returnRepository->update($return, $updates)->fresh(['returnOptions']);
        });

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
        $this->assertRefundRequestNotYetCreated($return);

        $return->loadMissing('returnOptions');

        $claimItems = $this->completedClaimItems($return);

        if ($claimItems === []) {
            throw OrderClaimException::completionItemsInvalid();
        }

        return DB::transaction(function () use ($return, $claimItems, $processedBy) {
            $order = $this->orderRepository->findForUpdate($return->order_id) ?? $return->order;
            $order->load(['options', 'shippings', 'payment']);

            // 완료 경로와 같은 이유로 잠금 안에서 재판정한다 — 이 경로도 호출부가 들고 온
            // 모델만 보면 동시 접수 두 건을 통과시킨다.
            $this->assertRefundRequestNotYetCreated(
                $this->returnRepository->findById($return->id) ?? $return
            );

            $adjustment = new ReturnAdjustment(
                returnItems: $claimItems,
                collectionFee: (int) round((float) $return->claim_shipping_fee),
                chargeMethod: $return->charge_method,
            );

            $result = $this->adjustmentService->calculate($order, $adjustment);
            $refund = $this->createRefundRequest($order, $return, $claimItems, $result, $processedBy);

            $this->returnRepository->update($return, ['refund_id' => $refund->id]);

            return $refund;
        });
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
     */
    protected function createReturnOption(OrderReturn $return, Order $order, OrderOption $option, int $quantity): void
    {
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
     * 주문의 기준통화 코드를 반환합니다.
     *
     * @param  Order  $order  주문
     * @return string 기준통화 코드
     */
    protected function resolveBaseCurrency(Order $order): string
    {
        return (string) ($order->currency_snapshot['base_currency'] ?? $order->currency ?? 'KRW');
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
            ReturnStatusEnum::WITHDRAWN => ['withdrawn_at' => now()],
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
     */
    protected function syncOptionStatuses(OrderReturn $return, ReturnStatusEnum $target, ?int $processedBy): void
    {
        $return->loadMissing('returnOptions');

        foreach ($return->returnOptions as $item) {
            if ($item->option_status === $target || $item->option_status->isFinal()) {
                continue;
            }

            if (! $item->option_status->canTransitionTo($target)) {
                continue;
            }

            $this->returnOptionRepository->update($item, [
                'option_status' => $target,
                'processed_by' => $processedBy ?? $item->processed_by,
            ]);
        }
    }

    /**
     * 살아 있는 신청 상품이 모두 같은 상태에 도달했으면 헤더를 그 상태로 옮깁니다.
     *
     * 판정에서 **끝난 상품(반려·철회·완료)은 뺀다**(2026-08-22 PO 확정 ②). 포함해서 세면
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
     */
    protected function syncHeaderFromOptions(OrderReturn $return, ?int $processedBy): void
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
            $updates = ['return_status' => $step] + $this->stampFor($step, []);

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
     * 주문옵션이 어긋나는 형태다. 교환도 이 목록을 그대로 쓴다(2026-08-22 PO 확정 ④).
     *
     * @param  OrderReturn  $return  반품 신청서
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
     */
    protected function revertOrderOptionFor(OrderReturnOption $item): void
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
     */
    protected function fireStatusHooks(OrderReturn $return, ReturnStatusEnum $previous, ReturnStatusEnum $target): void
    {
        HookManager::doAction(
            'sirsoft-ecommerce.claim.return.status_changed',
            $return,
            $previous->value,
            $target->value,
        );

        $specific = match ($target) {
            ReturnStatusEnum::APPROVED => 'sirsoft-ecommerce.claim.return.approved',
            ReturnStatusEnum::REJECTED => 'sirsoft-ecommerce.claim.return.rejected',
            ReturnStatusEnum::WITHDRAWN => 'sirsoft-ecommerce.claim.return.withdrawn',
            ReturnStatusEnum::COMPLETED => 'sirsoft-ecommerce.claim.return.completed',
            default => null,
        };

        if ($specific !== null) {
            HookManager::doAction($specific, $return);
        }
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼 — 완료
    // ───────────────────────────────────────────────

    /**
     * 원 주문옵션을 반품완료(터미널)로 전이합니다.
     *
     * @param  OrderOption  $orderOption  원 주문옵션
     * @param  int  $quantity  완료 수량
     */
    protected function markOrderOptionReturned(OrderOption $orderOption, int $quantity): void
    {
        if ($orderOption->option_status === OrderStatusEnum::RETURNED) {
            return;
        }

        $this->orderOptionService->changeStatusWithQuantity(
            $orderOption,
            OrderStatusEnum::RETURNED,
            min($quantity, (int) $orderOption->quantity),
            [],
            restoreStockOnCancel: false,
            mergeSiblings: false,
        );
    }

    /**
     * 환불신청서가 아직 접수되지 않았음을 단언합니다.
     *
     * 환불신청서는 신청서당 한 건이다 — `refund_id` 가 D19 ③축(선점액)에서 그 반품을 빼는
     * 근거이므로 두 건이 생기면 한쪽이 집계에서 누락돼 이중 계상이 된다.
     *
     * @param  OrderReturn  $return  판정 대상 신청서 (잠금 안에서는 재조회한 행을 넘긴다)
     *
     * @throws OrderClaimException 이미 접수된 경우
     */
    protected function assertRefundRequestNotYetCreated(OrderReturn $return): void
    {
        if ($return->refund_id === null) {
            return;
        }

        $existing = $this->refundRepository->findById($return->refund_id);

        throw OrderClaimException::refundRequestAlreadyExists(
            $existing?->refund_number ?? (string) $return->refund_id
        );
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
     * @param  Order  $order  주문
     * @param  OrderReturn  $return  반품 신청서
     * @param  array  $claimItems  대상 아이템 [{order_option_id, cancel_quantity}]
     * @param  AdjustmentResult  $result  계산 결과
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderRefund 생성된 환불 신청서
     */
    protected function createRefundRequest(
        Order $order,
        OrderReturn $return,
        array $claimItems,
        AdjustmentResult $result,
        ?int $processedBy,
    ): OrderRefund {
        $refund = $this->refundRepository->create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::RETURN,
            'refund_source_id' => $return->id,
            'refund_number' => $this->sequenceService->generateCode(SequenceType::REFUND),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => $this->refundExecutionService->determineRefundMethod($order),
            'reason_code' => $return->reason_code,
            'reason_detail' => $return->reason_detail,
            'fault_type' => $return->fault_type,
            'requested_by' => $return->requested_by,
            'refund_amount' => $result->refundAmount,
            'refund_points_amount' => $result->refundPointsAmount,
            'refund_shipping_amount' => $result->shippingDifference,
            'mc_refund_amount' => $result->mcRefundAmount,
            'mc_refund_points_amount' => $result->mcRefundPointsAmount,
            'mc_refund_shipping_amount' => $result->mcRefundShippingAmount,
            'original_calculation_snapshot' => $result->originalSnapshot,
            'recalculated_snapshot' => $result->recalculatedSnapshot,
            'processed_by' => $processedBy,
        ]);

        foreach ($this->refundExecutionService->buildRefundOptionRows($order, $claimItems, $result) as $row) {
            $this->refundOptionRepository->create(
                array_merge(['order_refund_id' => $refund->id], $row)
            );
        }

        return $refund->fresh();
    }

    /**
     * 완료된 신청 상품을 계산기 공용 아이템 형태로 반환합니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return array [{order_option_id, cancel_quantity}]
     */
    protected function completedClaimItems(OrderReturn $return): array
    {
        return $return->returnOptions
            ->filter(fn (OrderReturnOption $item) => $item->option_status === ReturnStatusEnum::COMPLETED)
            ->map(fn (OrderReturnOption $item) => [
                'order_option_id' => $item->order_option_id,
                'cancel_quantity' => (int) $item->return_quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * 모든 신청 상품이 완료됐는지 반환합니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return bool 전건 완료면 true
     */
    protected function allItemsCompleted(OrderReturn $return): bool
    {
        return $return->returnOptions->isNotEmpty()
            && $return->returnOptions->every(
                fn (OrderReturnOption $item) => $item->option_status === ReturnStatusEnum::COMPLETED
            );
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
     * @param  OrderReturn  $return  반품 신청서
     * @param  array  $payload  수정 페이로드 (reason_code? / reason_detail? / charge_method?)
     * @return OrderReturn 수정된 신청서
     *
     * @throws OrderClaimException 수정 불가 상태 / 사유 부재 / 청구 방식 부재·불허·차감 불가
     */
    public function updateRequest(OrderReturn $return, array $payload): OrderReturn
    {
        if (! $return->return_status->isWithdrawable()) {
            throw OrderClaimException::notEditable();
        }

        return DB::transaction(function () use ($return, $payload) {
            $locked = $this->returnRepository->findForUpdate($return->id) ?? $return;
            $locked->load(['returnOptions', 'order.options', 'order.shippings', 'order.payment']);
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

                $this->assertChargeMethodUsable($order, $claimItems, $shippingFee, $chargeMethod, RefundPriorityEnum::PG_FIRST);

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

            return $this->returnRepository->update($locked, $updates)->fresh(['returnOptions']);
        });
    }
}
