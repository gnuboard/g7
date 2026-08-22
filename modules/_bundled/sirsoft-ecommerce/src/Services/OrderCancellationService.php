<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\CancellationResult;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;

/**
 * 주문 취소 서비스
 *
 * 전체취소와 부분취소 실행을 통합 관리합니다.
 * 환불금액 계산은 OrderAdjustmentService에 위임합니다.
 */
class OrderCancellationService
{
    /**
     * @param  OrderAdjustmentService  $adjustmentService  환불금액 계산 서비스
     * @param  OrderOptionService  $orderOptionService  주문 옵션 서비스
     * @param  SequenceService  $sequenceService  채번 서비스
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 Repository
     * @param  OrderShippingRepositoryInterface  $orderShippingRepository  주문 배송 Repository
     * @param  OrderCancelRepositoryInterface  $orderCancelRepository  주문 취소 Repository
     * @param  OrderCancelOptionRepositoryInterface  $orderCancelOptionRepository  주문 취소 옵션 Repository
     * @param  OrderRefundRepositoryInterface  $orderRefundRepository  주문 환불 Repository
     * @param  OrderRefundOptionRepositoryInterface  $orderRefundOptionRepository  주문 환불 옵션 Repository
     * @param  OrderPaymentRepositoryInterface  $orderPaymentRepository  주문 결제 Repository
     * @param  RefundExecutionService  $refundExecutionService  환불 실행 서비스 (PG 환불·복원·현금영수증 공용)
     * @param  ClaimQuantityGuard  $claimQuantityGuard  클레임 수량 잠금 가드 (D15)
     */
    public function __construct(
        protected OrderAdjustmentService $adjustmentService,
        protected OrderOptionService $orderOptionService,
        protected SequenceService $sequenceService,
        protected EcommerceSettingsService $settingsService,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected OrderShippingRepositoryInterface $orderShippingRepository,
        protected OrderCancelRepositoryInterface $orderCancelRepository,
        protected OrderCancelOptionRepositoryInterface $orderCancelOptionRepository,
        protected OrderRefundRepositoryInterface $orderRefundRepository,
        protected OrderRefundOptionRepositoryInterface $orderRefundOptionRepository,
        protected OrderPaymentRepositoryInterface $orderPaymentRepository,
        protected RefundExecutionService $refundExecutionService,
        protected ClaimQuantityGuard $claimQuantityGuard,
    ) {}

    /**
     * 관리자가 취소 모달에서 입력한 환불 계좌를 결제행에 반영합니다.
     *
     * 미입력이면 기존 값(주문 시 입력분)을 그대로 둔다 — 취소 요청이 계좌를 지우지 않는다.
     * 가상계좌 입금완료 건의 미입력은 CancelOrderRequest 가 이미 422 로 막는다.
     *
     * @param  Order  $order  대상 주문
     * @param  array|null  $refundBankInfo  환불 계좌 (bank_code/account_number/holder)
     */
    protected function applyRefundBank(Order $order, ?array $refundBankInfo): void
    {
        if ($refundBankInfo === null) {
            return;
        }

        $order->loadMissing('payment');

        if (! $order->payment) {
            return;
        }

        $this->orderPaymentRepository->updateRefundBank(
            $order->payment,
            $refundBankInfo['bank_code'],
            $this->settingsService->resolveBankName($refundBankInfo['bank_code']),
            $refundBankInfo['account_number'],
            $refundBankInfo['holder'],
        );
    }

    /**
     * 취소 확정 후 현금영수증을 현재 금액에 맞춰 동기화합니다.
     *
     * 실행 규칙(재발급 방식·실패 삼킴·로그 레벨)은 RefundExecutionService::syncCashReceipt 에 있다.
     *
     * @param  Order  $order  취소가 반영된 주문
     * @param  string|null  $reason  취소 사유
     */
    protected function syncCashReceipt(Order $order, ?string $reason): void
    {
        $this->refundExecutionService->syncCashReceipt($order, $reason);
    }

    /**
     * 전체 주문을 취소합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string|null  $reason  취소 사유 코드
     * @param  string|null  $reasonDetail  상세 사유 (기타 선택 시)
     * @param  int|null  $cancelledBy  취소 요청자 ID
     * @param  bool  $cancelPg  PG 결제 취소 여부
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  array|null  $refundBankInfo  환불 계좌 (bank_code/account_number/holder, 미입력 시 null)
     * @return CancellationResult 취소 결과
     *
     * @throws \Exception 취소 불가 또는 PG 환불 실패 시
     */
    public function cancelOrder(
        Order $order,
        ?string $reason = null,
        ?string $reasonDetail = null,
        ?int $cancelledBy = null,
        bool $cancelPg = true,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
        ?array $refundBankInfo = null,
    ): CancellationResult {
        $order->loadMissing(['options', 'payment', 'shippings']);

        // 모든 활성 옵션을 취소 대상으로 구성
        $cancelItems = [];
        foreach ($order->options as $option) {
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }
            $cancelItems[] = [
                'order_option_id' => $option->id,
                'cancel_quantity' => $option->quantity,
            ];
        }

        return $this->executeCancellation(
            order: $order,
            cancelItems: $cancelItems,
            cancelType: CancelTypeEnum::FULL,
            reason: $reason,
            reasonDetail: $reasonDetail,
            cancelledBy: $cancelledBy,
            cancelPg: $cancelPg,
            refundPriority: $refundPriority,
            refundBankInfo: $refundBankInfo,
        );
    }

    /**
     * 부분 옵션 취소를 실행합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $cancelItems  취소 대상 [{order_option_id, cancel_quantity}]
     * @param  string|null  $reason  취소 사유 코드
     * @param  string|null  $reasonDetail  상세 사유
     * @param  int|null  $cancelledBy  취소 요청자 ID
     * @param  bool  $cancelPg  PG 결제 취소 여부
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  array|null  $refundBankInfo  환불 계좌 (bank_code/account_number/holder, 미입력 시 null)
     * @return CancellationResult 취소 결과
     *
     * @throws \Exception 취소 불가 또는 PG 환불 실패 시
     */
    public function cancelOrderOptions(
        Order $order,
        array $cancelItems,
        ?string $reason = null,
        ?string $reasonDetail = null,
        ?int $cancelledBy = null,
        bool $cancelPg = true,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
        ?array $refundBankInfo = null,
    ): CancellationResult {
        $order->loadMissing(['options', 'payment', 'shippings']);

        // 전량 취소 시 전체취소로 전환
        $cancelType = $this->shouldConvertToFullCancel($order, $cancelItems)
            ? CancelTypeEnum::FULL
            : CancelTypeEnum::PARTIAL;

        return $this->executeCancellation(
            order: $order,
            cancelItems: $cancelItems,
            cancelType: $cancelType,
            reason: $reason,
            reasonDetail: $reasonDetail,
            cancelledBy: $cancelledBy,
            cancelPg: $cancelPg,
            refundPriority: $refundPriority,
            refundBankInfo: $refundBankInfo,
        );
    }

    /**
     * 환불 예상금액을 미리 계산합니다. (DB 변경 없음)
     *
     * @param  Order  $order  대상 주문
     * @param  array  $cancelItems  취소 대상 [{order_option_id, cancel_quantity}]
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return AdjustmentResult 계산 결과
     */
    public function previewRefund(
        Order $order,
        array $cancelItems,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): AdjustmentResult {
        $adjustment = CancellationAdjustment::fromArray($cancelItems);

        return $this->adjustmentService->preview($order, $adjustment, $refundPriority);
    }

    /**
     * 취소를 실행합니다. (전체취소/부분취소 통합)
     *
     * @param  Order  $order  대상 주문
     * @param  array  $cancelItems  취소 대상
     * @param  CancelTypeEnum  $cancelType  취소 유형
     * @param  string|null  $reason  취소 사유 코드
     * @param  string|null  $reasonDetail  상세 사유
     * @param  int|null  $cancelledBy  요청자 ID
     * @param  bool  $cancelPg  PG 취소 여부
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  array|null  $refundBankInfo  환불 계좌 (bank_code/account_number/holder, 미입력 시 null)
     * @return CancellationResult 취소 결과
     *
     * @throws \Exception
     */
    protected function executeCancellation(
        Order $order,
        array $cancelItems,
        CancelTypeEnum $cancelType,
        ?string $reason,
        ?string $reasonDetail,
        ?int $cancelledBy,
        bool $cancelPg,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
        ?array $refundBankInfo = null,
    ): CancellationResult {
        // ① 검증
        $this->validateCancellable($order);
        $this->validateCancelItems($order, $cancelItems);
        $this->validateNotLockedByClaim($order, $cancelItems);

        // 환불 계좌 갱신 — PG 환불(executePgRefund)이 이 컬럼을 읽어 refundReceiveAccount 를
        // 구성하므로 환불 실행 전에 반영해야 한다. 관리자가 취소 모달에서 입력/수정한 값이다.
        $this->applyRefundBank($order, $refundBankInfo);

        // 결제 취소 전 본인인증(IDV) 정책 가드 지점 (관리자/사용자 — payment.cancel purpose).
        // EnforceIdentityPolicyListener 가 'sirsoft-ecommerce.payment.cancel' 정책이 활성이고
        // grace 만료 시 IdentityVerificationRequiredException(428) 을 throw 한다.
        HookManager::doAction('sirsoft-ecommerce.payment.before_cancel', $order, $cancelType->value, $cancelItems);

        // 취소 전 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_cancel', $order, $cancelType->value, $cancelItems);

        // 취소 스냅샷 캡처 (Listener에서 사용)
        $cancelSnapshot = [
            'cancel_type' => $cancelType->value,
            'cancel_items' => $cancelItems,
        ];

        // ② 환불금액 계산 (DB 변경 없음)
        $adjustment = CancellationAdjustment::fromArray($cancelItems);
        $adjustmentResult = $this->adjustmentService->calculate($order, $adjustment, $refundPriority);

        // ②-a. 재계산 결제금액이 원 결제금액을 초과하는지 검증
        // (쿠폰 조건 미달 등으로 할인 소멸 시 추가 결제가 필요한 경우 취소 불가)
        $this->validateRefundNotNegative($adjustmentResult, $cancelType);

        // ③ DB 트랜잭션
        $orderCancel = null;
        $orderRefund = null;

        DB::transaction(function () use (
            $order, $cancelItems, $cancelType, $reason, $reasonDetail,
            $cancelledBy, $cancelPg, $adjustmentResult,
            &$orderCancel, &$orderRefund,
        ) {
            $isPaid = ! $order->order_status->isBeforePayment();

            // ③-a. OrderCancel INSERT (pending)
            $orderCancel = $this->createOrderCancel(
                $order, $cancelType, $reason, $reasonDetail, $cancelledBy, $cancelItems
            );

            // ③-b. OrderCancelOption INSERT (pending, 옵션별)
            $this->createCancelOptions($orderCancel, $order, $cancelItems);

            // ③-c. OrderOption UPDATE (상태 변경 + 수량 분할)
            $this->updateOrderOptions($order, $cancelItems, $reason);

            // ③-c2. OrderOption 금액/프로모션 스냅샷 갱신 (재계산 결과 반영)
            $this->applyOptionUpdates($order, $adjustmentResult);

            // ③-d. OrderShipping UPDATE (배송비 재계산 반영)
            $this->updateShippings($adjustmentResult);

            // ③-e. Order UPDATE (합계 재계산)
            $this->updateOrderTotals($order, $cancelType, $adjustmentResult, $reason);

            // 결제 완료된 주문만 환불 처리
            if ($isPaid) {
                // ③-f. OrderRefund INSERT
                $orderRefund = $this->createOrderRefund(
                    $order, $orderCancel, $adjustmentResult
                );

                // ③-g. OrderRefundOption INSERT
                $this->createRefundOptions($orderRefund, $order, $cancelItems, $adjustmentResult);

                // ③-h. OrderPayment UPDATE
                $this->updatePayment($order, $orderCancel, $adjustmentResult);

                // ③-i. PG 환불 훅
                // PG사 선택이 필요한 결제수단(카드/가상계좌/계좌이체/휴대폰)만 PG 환불 훅에 진입한다.
                // 무통장입금(dbank)·포인트·예치금·무료는 PG 리스너가 인식하지 못해 success=false → 트랜잭션 롤백.
                // 이들은 PG 훅을 건너뛰고 운영자 수동 환불(APPROVED) 로 처리한다.
                if ($cancelPg && $order->payment && $adjustmentResult->refundAmount > 0
                    && $order->payment->needsPgProvider()) {
                    $this->executePgRefund($order, $orderRefund, $adjustmentResult, $reason);
                }
            }

            // ③-i. 쿠폰/마일리지/재고 복원 (결제 여부 무관)
            $this->restoreCoupons($order, $adjustmentResult);
            $this->restoreMileage($order, $adjustmentResult, $orderCancel);
            $this->restoreStock($order, $cancelItems);

            // ③-j. 상태 확정 (completed)
            $this->finalizeStatus($orderCancel, $orderRefund, $cancelledBy);
        });

        // ④ 현금영수증 동기화 — 취소가 DB 에 확정된 뒤에 수행한다.
        //
        // 트랜잭션 안에서 부르지 않는 이유:
        //   (a) 재발급 실패 이력(issue_status=FAILED)은 관리자 화면의 경고 배지 근거이자 국세청 신고
        //       누락을 막는 원장이다. 롤백에 휩쓸려 사라지면 안 된다.
        //   (b) 프로바이더 취소·재발급은 외부 API 2회 왕복이다. DB 잠금을 그동안 붙들 수 없고,
        //       실제 영수증은 이미 취소됐는데 트랜잭션만 롤백되면 DB 와 국세청 기록이 어긋난다.
        //
        // syncFromOrder 는 내부에서 실패를 삼키고 FAILED 이력으로 남기지만, 프로바이더 리스너가
        // 예외를 던질 가능성까지 감안해 방어한다 — 환불은 이미 끝났고 되돌릴 수 없다.
        $this->syncCashReceipt($order->fresh(), $reason);

        // ⑤ after_cancel 훅 (취소 스냅샷 전달)
        $hookName = $cancelType === CancelTypeEnum::FULL
            ? 'sirsoft-ecommerce.order.after_cancel'
            : 'sirsoft-ecommerce.order.after_partial_cancel';
        HookManager::doAction($hookName, $order->fresh(), $cancelSnapshot);

        $cancelledOptionIds = array_column($cancelItems, 'order_option_id');

        return new CancellationResult(
            order: $order->fresh(['options', 'payment', 'shippings']),
            orderCancel: $orderCancel->fresh(),
            orderRefund: $orderRefund?->fresh(),
            adjustmentResult: $adjustmentResult,
            cancellationType: $cancelType,
            cancelledOptionIds: $cancelledOptionIds,
        );
    }

    // ───────────────────────────────────────────────
    // 검증 메서드
    // ───────────────────────────────────────────────

    /**
     * 주문 취소 가능 여부를 검증합니다.
     *
     * @param  Order  $order  대상 주문
     *
     * @throws \Exception 취소 불가 시
     */
    protected function validateCancellable(Order $order): void
    {
        $cancellableStatuses = $this->settingsService->getSetting(
            'order_settings.cancellable_statuses',
            ['payment_complete']
        );

        if (! $order->isCancellable($cancellableStatuses)) {
            throw new OrderCancellationException($order->getCancelDeniedReason($cancellableStatuses));
        }
    }

    /**
     * 취소 대상 아이템을 검증합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     *
     * @throws \Exception 유효하지 않은 아이템
     */
    protected function validateCancelItems(Order $order, array $cancelItems): void
    {
        foreach ($cancelItems as $item) {
            $option = $order->options->find($item['order_option_id']);

            if (! $option) {
                throw new OrderCancellationException(__('sirsoft-ecommerce::exceptions.cancel_option_not_found'));
            }

            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                throw new OrderCancellationException(__('sirsoft-ecommerce::exceptions.cancel_option_already_cancelled'));
            }

            if ($item['cancel_quantity'] < 1 || $item['cancel_quantity'] > $option->quantity) {
                throw new OrderCancellationException(__('sirsoft-ecommerce::exceptions.cancel_quantity_invalid'));
            }
        }
    }

    /**
     * 취소 대상이 진행 중인 교환·반품에 묶여 있지 않은지 검증합니다. (D15)
     *
     * 취소 경로는 옵션 상태가 CANCELLED 인지만 보고 클레임 코스 상태를 보지 않는다.
     * 신청 가능 상태 목록은 운영자 설정이라 취소 가능 상태와 겹칠 수 있고, 겹치면 반품
     * 진행 중인 수량이 취소로도 빠져나가 같은 상품이 두 번 환불된다 — 예외도 경고도
     * 남지 않고 금액만 어긋나는 형태다.
     *
     * 판정은 ClaimQuantityGuard 단일 출처에 위임하고, 취소 경로의 응답 계약(422)을
     * 유지하기 위해 취소 예외로 변환한다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     *
     * @throws OrderCancellationException 진행 중인 클레임에 묶인 수량일 때
     */
    protected function validateNotLockedByClaim(Order $order, array $cancelItems): void
    {
        try {
            $this->claimQuantityGuard->assertNotLockedForCancellation($order, $cancelItems);
        } catch (OrderClaimException $e) {
            throw new OrderCancellationException($e->getMessage());
        }
    }

    /**
     * 부분취소로 고객이 추가 결제해야 하는 상황인지 검증합니다.
     *
     * 부분취소 시 쿠폰 조건 미달(최소 주문금액 등)로 할인이 소멸하면
     * 재계산 결제금액이 원 결제금액보다 높아질 수 있습니다.
     * 이 경우 고객에게 추가 결제를 요구해야 하므로 취소를 거부합니다.
     *
     * 차단 판정은 미리보기(previewRefund)와 동일한 AdjustmentResult::isCancelBlocked() 에
     * 위임하여, 미리보기 가능/실행 차단 같은 사용자 기만을 제거합니다.
     * 실결제 0원(미입금·운영자 0원 결제완료) 주문은 환불/추가청구 개념이 없어 차단하지 않습니다.
     *
     * @param  AdjustmentResult  $result  환불 계산 결과
     * @param  CancelTypeEnum  $cancelType  취소 유형
     *
     * @throws \Exception 재계산 결제금액 초과 시
     */
    protected function validateRefundNotNegative(AdjustmentResult $result, CancelTypeEnum $cancelType): void
    {
        // 전체취소는 모든 금액이 0이므로 검증 불필요
        if ($cancelType === CancelTypeEnum::FULL) {
            return;
        }

        if ($result->isCancelBlocked()) {
            throw new OrderCancellationException(__('sirsoft-ecommerce::exceptions.cancel_refund_negative'));
        }
    }

    /**
     * 전량 취소 시 전체취소로 전환해야 하는지 확인합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     * @return bool 전체취소 전환 여부
     */
    protected function shouldConvertToFullCancel(Order $order, array $cancelItems): bool
    {
        $cancelMap = [];
        foreach ($cancelItems as $item) {
            $cancelMap[$item['order_option_id']] = $item['cancel_quantity'];
        }

        foreach ($order->options as $option) {
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }

            $cancelQty = $cancelMap[$option->id] ?? 0;
            if ($option->quantity - $cancelQty > 0) {
                return false;
            }
        }

        return true;
    }

    // ───────────────────────────────────────────────
    // ③-a. OrderCancel 생성
    // ───────────────────────────────────────────────

    /**
     * 취소 이력 레코드를 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  CancelTypeEnum  $cancelType  취소 유형
     * @param  string|null  $reason  사유 코드
     * @param  string|null  $reasonDetail  상세 사유
     * @param  int|null  $cancelledBy  요청자 ID
     * @param  array  $cancelItems  취소 대상
     * @return OrderCancel 생성된 취소 레코드
     */
    protected function createOrderCancel(
        Order $order,
        CancelTypeEnum $cancelType,
        ?string $reason,
        ?string $reasonDetail,
        ?int $cancelledBy,
        array $cancelItems,
    ): OrderCancel {
        $itemsSnapshot = $this->buildItemsSnapshot($order, $cancelItems);
        $shippingSnapshot = $this->buildShippingSnapshot($order, $cancelItems);

        $cancelReasonType = $reason ?? 'etc';

        return $this->orderCancelRepository->create([
            'order_id' => $order->id,
            'cancel_number' => $this->sequenceService->generateCode(SequenceType::CANCEL),
            'cancel_type' => $cancelType,
            'cancel_status' => CancelStatusEnum::REQUESTED,
            'cancel_reason_type' => $cancelReasonType,
            'cancel_reason' => $reasonDetail,
            'items_snapshot' => $itemsSnapshot,
            'shipping_snapshot' => $shippingSnapshot,
            'cancelled_by' => $cancelledBy,
        ]);
    }

    /**
     * 취소 시점의 배송지(국가/우편번호) + 취소 대상 배송정책 스냅샷을 생성합니다 (B5).
     *
     * 주문 주소(ecommerce_order_addresses)는 사후 변경/삭제될 수 있으므로, 취소 "시점"의
     * 배송국가·우편번호와 취소 대상 옵션의 배송정책을 취소 레코드에 독립 복사한다. 이를 통해
     * 도서산간/국가별 환불 정책 판단을 주문 주소 상태와 무관하게 이력 기준으로 복원할 수 있다.
     * SSoT 는 주문 생성 시점에 보존된 order->shipping_policy_applied_snapshot['address'] 이다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상 (order_option_id 기준)
     * @return array 배송 스냅샷 (country_code/zipcode + 취소 대상 정책)
     */
    protected function buildShippingSnapshot(Order $order, array $cancelItems): array
    {
        $orderSnapshot = $order->shipping_policy_applied_snapshot ?? [];

        // 배송지 해석(스냅샷 우선 · 없으면 현재 배송주소 폴백)은 ShippingPolicySnapshot 단일
        // 출처에 위임한다 — 클레임 배송비의 도서산간 재판정이 같은 판정을 쓰므로, 두 벌로 두면
        // 한쪽만 고쳐질 때 같은 주문의 도서산간이 경로에 따라 갈린다.
        $address = ShippingPolicySnapshot::deliveryAddress($orderSnapshot, function () use ($order) {
            $shippingAddress = $order->shippingAddress;

            return [
                'country_code' => $shippingAddress?->recipient_country_code,
                'zipcode' => $shippingAddress?->zipcode ?: $shippingAddress?->intl_postal_code,
            ];
        });

        // 취소 대상 옵션의 product_option_id 기준으로 적용 정책만 추려 보존.
        $cancelOptionIds = [];
        foreach ($cancelItems as $item) {
            $option = $order->options->find($item['order_option_id']);
            if ($option) {
                $cancelOptionIds[$option->product_option_id] = true;
            }
        }

        // 형태 판별은 ShippingPolicySnapshot 단일 출처에 위임 (구형 혼합 배열도 흡수).
        $policies = ShippingPolicySnapshot::itemsForOptions($orderSnapshot, $cancelOptionIds);

        return [
            'country_code' => strtoupper((string) ($address['country_code'] ?? 'KR')),
            'zipcode' => $address['zipcode'] ?? null,
            'policies' => $policies,
        ];
    }

    /**
     * 취소 대상 아이템 스냅샷을 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     * @return array 스냅샷
     */
    protected function buildItemsSnapshot(Order $order, array $cancelItems): array
    {
        $snapshot = [];
        foreach ($cancelItems as $item) {
            $option = $order->options->find($item['order_option_id']);
            if ($option) {
                $snapshot[] = [
                    'option_id' => $option->id,
                    'product_name' => $option->product_name,
                    'option_name' => $option->option_name,
                    'option_value' => $option->option_value,
                    'quantity' => $item['cancel_quantity'],
                    'unit_price' => (float) $option->unit_price,
                ];
            }
        }

        return $snapshot;
    }

    // ───────────────────────────────────────────────
    // ③-b. OrderCancelOption 생성
    // ───────────────────────────────────────────────

    /**
     * 취소 옵션 상세 레코드를 생성합니다.
     *
     * @param  OrderCancel  $cancel  취소 레코드
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     */
    protected function createCancelOptions(OrderCancel $cancel, Order $order, array $cancelItems): void
    {
        foreach ($cancelItems as $item) {
            $option = $order->options->find($item['order_option_id']);
            if (! $option) {
                continue;
            }

            $this->orderCancelOptionRepository->create([
                'order_cancel_id' => $cancel->id,
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => CancelOptionStatusEnum::REQUESTED,
                'cancel_quantity' => $item['cancel_quantity'],
                'original_quantity' => $option->quantity,
                'unit_price' => (float) $option->unit_price,
                'subtotal_amount' => (float) $option->unit_price * $item['cancel_quantity'],
            ]);
        }
    }

    // ───────────────────────────────────────────────
    // ③-c. OrderOption 상태 변경 + 수량 분할
    // ───────────────────────────────────────────────

    /**
     * 주문 옵션 상태를 변경하고 수량을 분할합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     * @param  string|null  $reason  취소 사유
     */
    protected function updateOrderOptions(Order $order, array $cancelItems, ?string $reason): void
    {
        foreach ($cancelItems as $item) {
            $option = $order->options->find($item['order_option_id']);
            if (! $option) {
                continue;
            }

            // changeStatusWithQuantity 재사용 (분할 로직 포함).
            // restoreStockOnCancel: false — 취소 모달 경로는 아래 restoreStock() 이 재고 복원을 전담하므로
            // changeStatusWithQuantity 가 복원하면 이중 복원이 된다(재고 2배 복구 회귀 방지).
            $this->orderOptionService->changeStatusWithQuantity(
                $option,
                OrderStatusEnum::CANCELLED,
                $item['cancel_quantity'],
                [],
                restoreStockOnCancel: false
            );

            // 취소 수량/사유 누적
            $option->refresh();
            $option->update([
                'cancelled_quantity' => ($option->cancelled_quantity ?? 0) + $item['cancel_quantity'],
                'cancel_reason' => $reason,
            ]);
        }
    }

    // ───────────────────────────────────────────────
    // ③-c2. OrderOption 금액/프로모션 스냅샷 갱신
    // ───────────────────────────────────────────────

    /**
     * 재계산 결과의 옵션별 금액과 프로모션 스냅샷을 갱신합니다.
     *
     * 부분취소 후 남은 옵션의 할인 배분이 변경되므로,
     * subtotal_paid_amount, coupon_discount_amount, promotions_applied_snapshot 등을 업데이트합니다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     */
    protected function applyOptionUpdates(Order $order, AdjustmentResult $result): void
    {
        foreach ($result->optionUpdates as $optionId => $updates) {
            $option = $order->options->find($optionId);
            if ($option) {
                $this->orderOptionRepository->update($option, $updates);
            }
        }
    }

    // ───────────────────────────────────────────────
    // ③-d. OrderShipping 업데이트
    // ───────────────────────────────────────────────

    /**
     * 배송비를 재계산 결과에 따라 갱신합니다.
     *
     * @param  AdjustmentResult  $result  계산 결과
     */
    protected function updateShippings(AdjustmentResult $result): void
    {
        foreach ($result->shippingUpdates as $shippingId => $updates) {
            $this->orderShippingRepository->update($shippingId, $updates);
        }
    }

    // ───────────────────────────────────────────────
    // ③-e. Order 합계 업데이트
    // ───────────────────────────────────────────────

    /**
     * 주문 합계를 재계산 결과에 따라 갱신합니다.
     *
     * @param  Order  $order  주문
     * @param  CancelTypeEnum  $cancelType  취소 유형
     * @param  AdjustmentResult  $result  계산 결과
     * @param  string|null  $reason  취소 사유
     */
    protected function updateOrderTotals(
        Order $order,
        CancelTypeEnum $cancelType,
        AdjustmentResult $result,
        ?string $reason,
    ): void {
        $isFullCancel = $cancelType === CancelTypeEnum::FULL;
        $previousStatus = $order->order_status;

        $updateData = array_merge($result->orderUpdates, [
            'total_cancelled_amount' => (float) $order->total_cancelled_amount + $result->refundAmount,
            'cancellation_count' => ($order->cancellation_count ?? 0) + 1,
            // 취소일시 — 전체/부분취소 모두 기록(native 컬럼 SSoT). 최초 취소 시각을 보존하고 재취소 시 갱신하지 않는다.
            'cancelled_at' => $order->cancelled_at ?? Carbon::now(),
        ]);

        if ($isFullCancel) {
            // 전체취소는 주문 상태를 CANCELLED 로 확정한다.
            $updateData['order_status'] = OrderStatusEnum::CANCELLED;
            $updateData['order_meta'] = array_merge($order->order_meta ?? [], [
                'cancel_reason' => $reason,
                'previous_status' => $previousStatus->value,
            ]);
        }
        // 부분취소는 별도 주문 상태(partial_cancelled)를 두지 않는다 — 옵션은 이미 CANCELLED 로 전이됐고,
        // 주문 상태는 아래 syncParentOrderStatus 가 "취소 제외 잔여 활성 옵션" 기준으로 파생 결정한다.
        // (잔여 옵션이 모두 취소된 경우 syncParentOrderStatus 가 CANCELLED 로 전이.) — 2026-06-22

        $order->update($updateData);

        if (! $isFullCancel) {
            $this->orderOptionService->syncParentOrderStatus($order->id);
        }
    }

    // ───────────────────────────────────────────────
    // ③-f. OrderRefund 생성
    // ───────────────────────────────────────────────

    /**
     * 환불 이력 레코드를 생성합니다.
     *
     * 발생 출처는 polymorphic 축(refund_source_type/refund_source_id)에 기록하고,
     * order_cancel_id 에도 같은 값을 함께 남긴다(하위호환 이중 기록). 1.2.0 업그레이드의
     * 백필은 과거 행만 다루고 재실행되지 않으므로, 신규 행을 여기서 채우지 않으면
     * 그 시점 이후의 취소 발 환불이 출처 미상으로 굳는다.
     *
     * @param  Order  $order  주문
     * @param  OrderCancel  $cancel  취소 레코드
     * @param  AdjustmentResult  $result  계산 결과
     * @return OrderRefund 생성된 환불 레코드
     */
    protected function createOrderRefund(
        Order $order,
        OrderCancel $cancel,
        AdjustmentResult $result,
    ): OrderRefund {
        $refundMethod = $this->determineRefundMethod($order);

        return $this->orderRefundRepository->create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => RefundSourceTypeEnum::CANCEL,
            'refund_source_id' => $cancel->id,
            'refund_number' => $this->sequenceService->generateCode(SequenceType::REFUND),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => $refundMethod,
            'refund_amount' => $result->refundAmount,
            'refund_points_amount' => $result->refundPointsAmount,
            'refund_shipping_amount' => $result->shippingDifference,
            'mc_refund_amount' => $result->mcRefundAmount,
            'mc_refund_points_amount' => $result->mcRefundPointsAmount,
            'mc_refund_shipping_amount' => $result->mcRefundShippingAmount,
            'original_calculation_snapshot' => $result->originalSnapshot,
            'recalculated_snapshot' => $result->recalculatedSnapshot,
        ]);
    }

    /**
     * 환불 수단을 결정합니다.
     *
     * 판정 규칙은 취소와 클레임이 같아야 하므로 RefundExecutionService 가 SSoT 다.
     *
     * @param  Order  $order  주문
     * @return RefundMethodEnum 환불 수단
     */
    protected function determineRefundMethod(Order $order): RefundMethodEnum
    {
        return $this->refundExecutionService->determineRefundMethod($order);
    }

    // ───────────────────────────────────────────────
    // ③-g. OrderRefundOption 생성
    // ───────────────────────────────────────────────

    /**
     * 환불 옵션 상세 레코드를 생성합니다.
     *
     * @param  OrderRefund  $refund  환불 레코드
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     * @param  AdjustmentResult  $result  계산 결과
     */
    protected function createRefundOptions(
        OrderRefund $refund,
        Order $order,
        array $cancelItems,
        AdjustmentResult $result,
    ): void {
        // 안분 규칙(원 옵션 할인 비율 기반 차감)은 클레임 발 환불과 같아야 하므로
        // RefundExecutionService 가 SSoT 다. 여기서는 환불 레코드 소속만 붙인다.
        foreach ($this->refundExecutionService->buildRefundOptionRows($order, $cancelItems, $result) as $row) {
            $this->orderRefundOptionRepository->create(
                array_merge(['order_refund_id' => $refund->id], $row)
            );
        }
    }

    // ───────────────────────────────────────────────
    // ③-h. OrderPayment 업데이트
    // ───────────────────────────────────────────────

    /**
     * 결제 정보를 갱신합니다.
     *
     * @param  Order  $order  주문
     * @param  OrderCancel  $cancel  취소 레코드
     * @param  AdjustmentResult  $result  계산 결과
     */
    protected function updatePayment(Order $order, OrderCancel $cancel, AdjustmentResult $result): void
    {
        $payment = $order->payment;
        if (! $payment) {
            return;
        }

        $isFullCancel = $cancel->cancel_type === CancelTypeEnum::FULL;

        // 결제 통화(order_currency) 환산 환불액. base≠결제 통화일 때 PG 실환불·누적은 결제 통화로
        // 정합되어야 하므로 mc_cancelled_amount 를 결제 통화로 누적한다(base 누적 cancelled_amount 와 별개).
        $orderCurrency = $payment->currency ?: ($order->currency_snapshot['order_currency'] ?? null);
        $refundLocal = $this->resolveOrderCurrencyRefundAmount($result, $orderCurrency);

        // 취소 누적 컬럼(cancelled_amount / mc_cancelled_amount)의 규칙은 클레임 환불과 동일하므로
        // RefundExecutionService 가 SSoT 다. 여기서는 취소 고유 필드만 덧붙인다.
        $cancelledUpdates = $this->refundExecutionService->buildCancelledAmountUpdates($payment, $result);

        $cancelHistory = $payment->cancel_history ?? [];
        $cancelHistory[] = [
            'cancel_id' => $cancel->id,
            'cancel_number' => $cancel->cancel_number,
            'amount' => $result->refundAmount,
            'amount_local' => $refundLocal,
            'currency' => $orderCurrency,
            'points_amount' => $result->refundPointsAmount,
            'reason' => $cancel->cancel_reason_type,
            'date' => Carbon::now()->toIso8601String(),
        ];

        $payment->update(array_merge($cancelledUpdates, [
            'cancel_reason' => $cancel->cancel_reason_type,
            'cancel_history' => $cancelHistory,
            'cancelled_at' => Carbon::now(),
            'payment_status' => $isFullCancel
                ? PaymentStatusEnum::CANCELLED
                : PaymentStatusEnum::PARTIAL_CANCELLED,
        ]));
    }

    /**
     * 환불액을 결제 통화(order_currency)로 환산해 반환합니다.
     *
     * 계산 단계에서 스냅샷 환율로 산출한 mc_refund_amount[order_currency] 를 우선 사용하고,
     * 없으면(결제 통화 환율 누락 등) base refundAmount 로 폴백합니다.
     *
     * @param  AdjustmentResult  $result  환불 계산 결과
     * @param  string|null  $orderCurrency  결제 통화 코드
     * @return float 결제 통화 환산 환불액
     */
    protected function resolveOrderCurrencyRefundAmount(AdjustmentResult $result, ?string $orderCurrency): float
    {
        return $this->refundExecutionService->resolveOrderCurrencyRefundAmount($result, $orderCurrency);
    }

    // ───────────────────────────────────────────────
    // ③-i. 훅 실행 (PG 환불, 쿠폰/마일리지/재고 복원)
    //
    // 실행 본체는 RefundExecutionService 가 갖는다 (취소·클레임 공용). 여기 남은 메서드는
    // 취소 흐름이 부르는 이름을 유지하기 위한 위임이며, 취소 고유 규칙은 담지 않는다.
    // ───────────────────────────────────────────────

    /**
     * PG 환불을 실행합니다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불 레코드
     * @param  AdjustmentResult  $result  계산 결과
     * @param  string|null  $reason  사유
     *
     * @throws \Exception PG 환불 실패 시
     */
    protected function executePgRefund(
        Order $order,
        OrderRefund $refund,
        AdjustmentResult $result,
        ?string $reason,
    ): void {
        $this->refundExecutionService->executePgRefund($order, $refund, $result, $reason);
    }

    /**
     * 쿠폰을 복원합니다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     */
    protected function restoreCoupons(Order $order, AdjustmentResult $result): void
    {
        $this->refundExecutionService->restoreCoupons($order, $result);
    }

    /**
     * 마일리지를 복원합니다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     * @param  OrderCancel|null  $orderCancel  취소 레코드 (복원 멱등 기준)
     */
    protected function restoreMileage(Order $order, AdjustmentResult $result, ?OrderCancel $orderCancel = null): void
    {
        $this->refundExecutionService->restoreMileage($order, $result, $orderCancel);
    }

    /**
     * 재고를 복원합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     */
    protected function restoreStock(Order $order, array $cancelItems): void
    {
        $this->refundExecutionService->restoreStock($order, $cancelItems);
    }

    // ───────────────────────────────────────────────
    // ③-j. 상태 확정
    // ───────────────────────────────────────────────

    /**
     * 취소/환불 상태를 completed로 확정합니다.
     *
     * @param  OrderCancel  $cancel  취소 레코드
     * @param  OrderRefund|null  $refund  환불 레코드
     * @param  int|null  $processedBy  처리 관리자 ID
     */
    protected function finalizeStatus(OrderCancel $cancel, ?OrderRefund $refund, ?int $processedBy): void
    {
        $now = Carbon::now();

        // 취소 레코드 확정
        $cancel->update([
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancelled_at' => $now,
        ]);

        // 취소 옵션 확정
        $cancel->cancelOptions()->update([
            'option_status' => CancelOptionStatusEnum::COMPLETED,
            'completed_at' => $now,
            'processed_by' => $processedBy,
        ]);

        // 환불 레코드 확정 (PG 환불 이미 처리된 경우 제외)
        if ($refund && ! $refund->refund_status->isFinal()) {
            // PG 환불은 즉시 완료(COMPLETED), 무통장/포인트(BANK/POINTS) 는 운영자 수동 송금 대기로
            // APPROVED(환불 승인) 에 둔다. 취소 자체는 완료하되, 실제 송금 전에 "환불 완료"로 거짓 확정하지
            // 않는다. 운영자가 송금 후 직접 COMPLETED 로 전환한다.
            if ($refund->refund_method === RefundMethodEnum::PG) {
                $refund->update([
                    'refund_status' => RefundStatusEnum::COMPLETED,
                    'refunded_at' => $now,
                    'processed_by' => $processedBy,
                ]);
            } else {
                $refund->update([
                    'refund_status' => RefundStatusEnum::APPROVED,
                    'processed_by' => $processedBy,
                ]);
            }
        }

        // 환불 옵션 확정
        if ($refund) {
            $refund->refundOptions()->update([
                'option_status' => RefundOptionStatusEnum::COMPLETED,
                'completed_at' => $now,
                'processed_by' => $processedBy,
            ]);
        }
    }
}
