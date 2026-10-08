<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\CancellationResult;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\AppliesRefundBankAccount;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;

/**
 * 주문 취소 서비스
 *
 * 전체취소와 부분취소 실행을 통합 관리합니다.
 * 환불금액 계산은 OrderAdjustmentService에 위임합니다.
 */
class OrderCancellationService
{
    use AppliesRefundBankAccount;

    /**
     * 운영자·시스템 발 취소의 정본 사유 코드.
     *
     * 클레임 사유는 코드가 정본이다(귀책·배송비 규칙이 코드에 걸린다). 호출부가 자유 문장을
     * 넘기면 이 코드로 접수하고 원문은 상세 사유로 남긴다 — `ClaimReasonSeeder` 가 `type=refund`
     * `is_user_selectable=false` 로 시드하므로 고객 신청 화면에는 나오지 않는다.
     */
    public const OPERATOR_CANCEL_REASON_CODE = 'admin_cancel';

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
     * @param  OrderRepositoryInterface  $orderRepository  주문 Repository
     * @param  OrderShippingAmountWriter  $shippingAmountWriter  배송 행 금액 기록기 (S-Q26-b)
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
        protected OrderRepositoryInterface $orderRepository,
        protected OrderShippingAmountWriter $shippingAmountWriter,
    ) {}

    /**
     * 취소 확정 후 현금영수증을 현재 금액에 맞춰 동기화합니다.
     *
     * 실행 규칙(재발급 방식·실패 삼킴·로그 레벨)은 RefundExecutionService::syncCashReceipt 에 있다.
     *
     * @param  Order  $order  취소가 반영된 주문
     * @param  string|null  $reason  취소 사유
     * @return void 반환값 없음
     */
    protected function syncCashReceipt(Order $order, ?string $reason): void
    {
        $this->refundExecutionService->syncCashReceipt($order, $reason);
    }

    /**
     * 전체 주문을 취소합니다.
     *
     * **클레임 접수 경로로 흐른다.** 이 메서드는 공개 표면을 지키는 어댑터이고, 실제 취소·환불은
     * `OrderRefundRequestService::request()` 한 입구가 수행한다 — 돈이 수납됐으면 환불신청서를
     * 만들고 `REQUESTED → APPROVED → COMPLETED` 를 실제로 거치며, 취소신청서는 환불이 집행된
     * 순간에만 생긴다(D1·D2·D3). 예전에는 이 자리에 두 번째 파이프라인이 있어 최종 상태를 바로
     * 기입했고, 그 경로의 전이는 활동로그에 한 줄도 남지 않았다.
     *
     * @param  Order  $order  대상 주문
     * @param  string|null  $reason  취소 사유 코드 (알 수 없는 값이면 `admin_cancel` 로 접수하고 원문은 상세 사유로 남긴다)
     * @param  string|null  $reasonDetail  상세 사유 (기타 선택 시)
     * @param  int|null  $cancelledBy  취소 요청자 ID
     * @param  bool  $cancelPg  PG 환불을 코어가 수행할지 여부. `false` 는 **바깥에서 이미 환불을
     *                          끝냈다**는 뜻이다(결제 플러그인의 가상계좌 환불 화면). 장부·훅·로그는 같은 경로로 남기고
     *                          PG 왕복만 건너뛴다 — "PG 없이 장부만 취소" 를 뜻하지 않는다(P2 에서 그 용법은 없앴다)
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  array|null  $refundBankInfo  환불 계좌 (bank_code/account_number/holder, 미입력 시 null)
     * @param  bool  $buyerRequest  구매자(회원·비회원) 본인이 낸 요청인지. true 면 고객 축으로 접수한다 —
     *                              출처 `DIRECT`(본인이 철회·수정 가능) · 분기는 상품 상태가 정함 ·
     *                              자격은 고객 창구 설정 · 사유는 화면에 노출되는 코드만. 기본값 false 는
     *                              운영자·시스템 축이며 확장이 쓰는 기존 계약을 그대로 지킨다
     * @return CancellationResult 취소 결과 (`orderCancel` 은 환불 미집행 시 null — DTO 독블록 참조)
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
        bool $buyerRequest = false,
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

        return $this->cancelThroughClaimIntake(
            order: $order,
            cancelItems: $cancelItems,
            cancelType: CancelTypeEnum::FULL,
            reason: $reason,
            reasonDetail: $reasonDetail,
            cancelledBy: $cancelledBy,
            cancelPg: $cancelPg,
            refundPriority: $refundPriority,
            refundBankInfo: $refundBankInfo,
            buyerRequest: $buyerRequest,
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
     * @param  bool  $buyerRequest  구매자(회원·비회원) 본인이 낸 요청인지. true 면 고객 축으로 접수한다 —
     *                              출처 `DIRECT`(본인이 철회·수정 가능) · 분기는 상품 상태가 정함 ·
     *                              자격은 고객 창구 설정 · 사유는 화면에 노출되는 코드만. 기본값 false 는
     *                              운영자·시스템 축이며 확장이 쓰는 기존 계약을 그대로 지킨다
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
        bool $buyerRequest = false,
    ): CancellationResult {
        $order->loadMissing(['options', 'payment', 'shippings']);

        // 전량 취소 시 전체취소로 전환
        $cancelType = $this->shouldConvertToFullCancel($order, $cancelItems)
            ? CancelTypeEnum::FULL
            : CancelTypeEnum::PARTIAL;

        return $this->cancelThroughClaimIntake(
            order: $order,
            cancelItems: $cancelItems,
            cancelType: $cancelType,
            reason: $reason,
            reasonDetail: $reasonDetail,
            cancelledBy: $cancelledBy,
            cancelPg: $cancelPg,
            refundPriority: $refundPriority,
            refundBankInfo: $refundBankInfo,
            buyerRequest: $buyerRequest,
        );
    }

    /**
     * 환불 예상금액을 미리 계산합니다. (DB 변경 없음)
     *
     * 클레임 접수(`cancelThroughClaimIntake`)와 같은 수량 잠금 가드를 기본 적용한다 —
     * 미리보기는 통과하고 실행만 422 가 되면 운영자·구매자는 보이는 금액을 근거로 실행을
     * 눌러야 거절을 안다(미리보기 가능/실행 차단 비대칭 해소). 취소·환불 신청 경로
     * (`OrderRefundRequestService`)는 자기 신청서 제외까지 반영한 같은 가드를 이미 통과한 뒤
     * 이 메서드를 부르므로 `$guardClaimLock=false` 로 재검사를 끈다 — 여기서 다시 걸면
     * 수정 미리보기가 자기 신청서의 잠금에 막힌다.
     *
     * 입금이 결제행에 기록된 결제 전 주문은 받은 돈을 헤더에 올린 사본으로 계산한다(S3-D7) — 접수
     * (`OrderRefundRequestService::request`)가 같은 값을 헤더에 저장한 뒤 산정하므로, 미리보기만 받은 돈을
     * 빼면 취소 창은 마일리지만 돌려준다고 예고하고 실제로는 받은 돈까지 돌려주는 신청서가 생긴다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $cancelItems  취소 대상 [{order_option_id, cancel_quantity}]
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  bool  $guardClaimLock  클레임 수량 잠금 가드 적용 여부
     * @return AdjustmentResult 계산 결과
     *
     * @throws OrderCancellationException 클레임 코스·수량 잠금 위반 (가드 적용 시)
     */
    public function previewRefund(
        Order $order,
        array $cancelItems,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
        bool $guardClaimLock = true,
    ): AdjustmentResult {
        if ($guardClaimLock) {
            $order->loadMissing('options');
            $this->validateNotLockedByClaim($order, $cancelItems);
        }

        $adjustment = CancellationAdjustment::fromArray($cancelItems);

        return $this->adjustmentService->preview($this->withRecordedDepositHeader($order), $adjustment, $refundPriority);
    }

    /**
     * 입금이 결제행에 기록된 결제 전 주문이면 받은 돈을 헤더에 올린 **사본**을 돌려줍니다. (S3-D7 — 저장하지 않음)
     *
     * 미리보기는 DB 를 바꾸지 않으므로 원본 모델을 고치지 않는다 — 원본에 값을 얹으면 호출부가 뒤이어
     * `update()` 할 때 얹은 값까지 함께 저장된다. 헤더 값의 정본은 접수가 저장하는 값과 같은
     * `OrderProcessingService::settledPaymentHeaderUpdates()` 다.
     *
     * @param  Order  $order  대상 주문
     * @return Order 받은 돈이 헤더에 오른 사본 (맞출 것이 없으면 원본)
     */
    protected function withRecordedDepositHeader(Order $order): Order
    {
        if (! $this->refundExecutionService->hasRecordedDepositBeforePayment($order)) {
            return $order;
        }

        $updates = app(OrderProcessingService::class)->settledPaymentHeaderUpdates($order);

        if ($updates === null) {
            return $order;
        }

        $view = clone $order;
        $view->forceFill($updates);

        return $view;
    }

    /**
     * 취소 요청을 **클레임 접수 경로**로 흘려보냅니다. (일원화 — §3·§4)
     *
     * 예전에는 이 자리에 두 번째 취소 파이프라인(`executeCancellation`)이 있었다. 그 경로는
     * 환불 행을 직접 만들고 최종 상태를 바로 기입해서:
     *
     *   - `REQUESTED → APPROVED → COMPLETED` 를 거치지 않았고(§4-2 가 금지한 단계 건너뛰기),
     *   - 그 전이들이 클레임 활동로그에 **한 줄도 남지 않았으며**(§7-1 B2·B5·B6·B7·C9·C10),
     *   - PG 수단이면 PG 호출 여부와 무관하게 `COMPLETED` 를 찍었다(P2).
     *
     * 같은 주문이 어느 창구로 들어왔는지에 따라 다른 규칙을 받던 것이 그 결과다. 이제 입구는
     * 하나이고, 이 메서드는 공개 표면(`cancelOrder`·`cancelOrderOptions`)을 지키는 어댑터다.
     *
     * 운영자 축 두 가지는 여기서 지킨다 — 클레임 접수는 고객용 신청 창구만 알기 때문이다:
     *   ⓐ 취소 자격(`order_settings.cancellable_statuses`) — 구매자 발 요청은 제외
     *   ⓑ 결제 취소 전 본인인증(IDV) 정책 훅
     *
     * ## 구매자 발과 운영자 발을 같은 계약으로 취급하지 않는다
     *
     * 이 어댑터는 운영자·시스템 창구(만료 자동취소·관리자·결제 플러그인)와 **구매자 창구**
     * (회원·비회원 주문취소 API)를 함께 받는다. 한때 호출부를 구분하지 않고 운영자 축을
     * 못박아, 구매자가 부른 경우에도 ⒜ 출처가 `ADMIN` 이 되어 **본인이 철회·수정할 수 없고**
     * ⒝ 고객 창구 설정 대신 운영자 목록으로 자격을 재고 ⒞ 화면에 노출되지 않는 판매자 귀책
     * 사유 코드가 통과했다(귀책·배송비 규칙이 그 코드에 걸려 금액이 달라진다).
     * `$buyerRequest` 가 그 둘을 가른다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $cancelItems  취소 대상 [{order_option_id, cancel_quantity}]
     * @param  CancelTypeEnum  $cancelType  취소 유형
     * @param  string|null  $reason  취소 사유 코드
     * @param  string|null  $reasonDetail  상세 사유
     * @param  int|null  $cancelledBy  취소 요청자 ID
     * @param  bool  $cancelPg  코어가 PG 환불을 수행할지 여부 (`false` = 바깥에서 이미 집행됨)
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  array|null  $refundBankInfo  환불 계좌
     * @param  bool  $buyerRequest  구매자(회원·비회원) 본인이 낸 요청인지 (true = 고객 축 접수)
     * @return CancellationResult 취소 결과
     *
     * @throws OrderCancellationException 취소 불가 또는 PG 환불 실패
     * @throws OrderClaimException 신청 자격·사유 위반
     */
    protected function cancelThroughClaimIntake(
        Order $order,
        array $cancelItems,
        CancelTypeEnum $cancelType,
        ?string $reason,
        ?string $reasonDetail,
        ?int $cancelledBy,
        bool $cancelPg,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
        ?array $refundBankInfo = null,
        bool $buyerRequest = false,
    ): CancellationResult {
        // ⓐ 운영자 축 자격. 클레임 접수는 `claim.instant_cancel_statuses` 만 알므로 여기서 지킨다.
        // 구매자 발 요청은 이 게이트를 타지 않는다 — 고객 자격은 클레임 접수가 고객 창구
        // 설정으로 재고, 여기서 운영자 목록으로 겹쳐 재면 두 기준이 갈린다.
        if (! $buyerRequest) {
            $this->validateCancellable($order);
        }

        // ⓑ 결제 취소 전 본인인증(IDV) 정책 가드 지점 (payment.cancel purpose).
        // EnforceIdentityPolicyListener 가 정책이 활성이고 grace 만료 시 428 을 throw 한다.
        HookManager::doAction('sirsoft-ecommerce.payment.before_cancel', $order, $cancelType->value, $cancelItems);
        HookManager::doAction('sirsoft-ecommerce.order.before_cancel', $order, $cancelType->value, $cancelItems);

        $reasonCode = $this->resolveIntakeReasonCode($reason);

        $outcome = $this->asCancellationFailure(fn (): array => app(OrderRefundRequestService::class)->request($order, [
            'items' => $this->toIntakeItems($cancelItems),
            'reason_code' => $reasonCode,
            // 사유 코드로 해석되지 않은 원문은 잃지 않고 상세 사유로 남긴다 — 결제 플러그인은
            // 취소 사유를 자유 문장으로 넘긴다(PG 콘솔에 그대로 적히는 값이다).
            'reason_detail' => $reasonDetail ?? ($reasonCode === $reason ? null : $reason),
            'requested_by' => $cancelledBy,
            'processed_by' => $buyerRequest ? null : $cancelledBy,
            'refund_priority' => $refundPriority,
            'refund_bank' => $refundBankInfo,
            'pg_already_settled' => ! $cancelPg,
            // 구매자 발 요청은 **고객 축 기본값**을 그대로 쓴다 — `request()` 의 기본값이
            // 정확히 고객 창구다: 출처 `DIRECT`(본인이 철회·수정 가능) · 분기는 상품 상태가
            // 정함 · 자격은 고객 창구 설정(`claim.instant_cancel_statuses`/`refund_request_statuses`) ·
            // 사유는 화면에 노출되는 것만. 그래서 네 키를 **싣지 않는 것**이 고객 축이다.
            //
            // 운영자·시스템 발은 아래 네 키로 운영자 축을 못박는다.
            ...($buyerRequest ? [] : [
                // 운영자·시스템이 낸 접수다. 고객이 철회·수정할 수 없어야 한다(§4-3-1).
                'refund_source_type' => RefundSourceTypeEnum::ADMIN,
                // 이 창구는 언제나 즉시 확정을 뜻한다 — 옛 파이프라인이 그랬고, 호출부(만료 자동취소·
                // 결제 플러그인)는 사람이 승인을 눌러 줄 것을 기대하지 않는다.
                'branch' => OrderRefundRequestService::BRANCH_AUTO,
                'gate_statuses' => $this->operatorCancellableStatusValues($order),
                'user_selectable_reason_only' => false,
            ]),
        ]));

        // 환불이 집행됐으면 취소신청서는 그 환불이 가리킨다(N12) — 접수 결과에는 실려 오지 않는다.
        $refund = $outcome['refund']?->fresh();
        $cancel = $outcome['cancel'] ?? $refund?->orderCancel;

        return new CancellationResult(
            order: $order->fresh(['options', 'payment', 'shippings']),
            orderCancel: $cancel?->fresh(),
            orderRefund: $refund?->fresh(),
            adjustmentResult: $outcome['adjustment'],
            cancellationType: $cancel?->cancel_type ?? $cancelType,
            cancelledOptionIds: array_column($cancelItems, 'order_option_id'),
            refundAmount: (float) ($refund->refund_amount ?? 0),
            refundPointsAmount: (float) ($refund->refund_points_amount ?? 0),
        );
    }

    /**
     * 클레임 접수의 도메인 거절을 **취소 실패**로 바꿔 올립니다.
     *
     * `cancelOrder()`·`cancelOrderOptions()` 는 확장이 부르는 공개 표면이고, 그 계약은
     * `OrderCancellationException` 이다(독블록·플러그인 호출부가 그것을 잡는다). 내부를 클레임
     * 접수로 옮기면서 거절 예외 타입이 바뀌면, 그 예외를 잡던 호출부가 **잡지 못해 500 으로
     * 새어 나간다** — 결제 플러그인의 가상계좌 환불이 그 경로다(PG 환불은 이미 끝난 뒤다).
     *
     * 사유 문구는 그대로 옮긴다 — 운영자에게 보이는 것은 "왜 취소가 안 되는가" 이고, 그 답은
     * 클레임 쪽이 더 정확하게 안다(수량 잠금·상태 자격 등).
     *
     * @template T
     *
     * @param  \Closure(): T  $intake  접수 실행
     * @return T 접수 결과
     *
     * @throws OrderCancellationException 클레임 접수가 거절한 경우
     */
    protected function asCancellationFailure(\Closure $intake)
    {
        try {
            return $intake();
        } catch (OrderClaimException $e) {
            // 키·치환 파라미터를 그대로 옮긴다(`validateNotLockedByClaim()` 과 같은 형태). 종전에는
            // 이미 번역된 문장을 키 자리에 넣어, 키로 응답을 조립하는 면이 키 대신 문장을 받고
            // 치환 파라미터(:type·:status 등)는 잃었다(#599 validate P2 #4).
            throw new OrderCancellationException($e->getMessage(), $e->getMessageKey(), $e->getMessageParams());
        }
    }

    /**
     * 취소 아이템을 클레임 신청 아이템 형태로 바꿉니다.
     *
     * 두 축이 키 이름만 다르다 — 취소 파이프라인은 `cancel_quantity`, 클레임 접수는 `quantity`.
     *
     * @param  array  $cancelItems  [{order_option_id, cancel_quantity}]
     * @return array<int, array{order_option_id: int, quantity: int}> 신청 아이템
     */
    protected function toIntakeItems(array $cancelItems): array
    {
        return array_map(
            fn (array $item): array => [
                'order_option_id' => (int) $item['order_option_id'],
                'quantity' => (int) ($item['cancel_quantity'] ?? $item['quantity'] ?? 0),
            ],
            $cancelItems,
        );
    }

    /**
     * 취소 사유를 클레임 사유 **코드**로 해석합니다.
     *
     * 옛 파이프라인은 사유 자리에 자유 문장을 받아도 그대로 저장했다. 클레임 접수는 코드를
     * 요구하므로(사유별 귀책·배송비 규칙이 거기 걸린다), 코드로 해석되지 않는 값은 운영자 발
     * 취소의 정본 코드로 접수하고 원문은 호출부가 상세 사유로 넘긴다.
     *
     * 이렇게 하지 않으면 결제 플러그인의 가상계좌 환불처럼 문장을 넘기는 호출부가 접수 단계에서
     * 422 로 막혀, **PG 환불은 이미 나갔는데 장부에는 아무것도 남지 않는다**.
     *
     * @param  string|null  $reason  호출부가 넘긴 사유 (코드 또는 자유 문장)
     * @return string 클레임 사유 코드
     */
    protected function resolveIntakeReasonCode(?string $reason): string
    {
        $code = trim((string) $reason);

        if ($code === '') {
            return self::OPERATOR_CANCEL_REASON_CODE;
        }

        $known = app(ClaimReasonRepositoryInterface::class)
            ->findByCode($code, ClaimTypeEnum::REFUND->value);

        return ($known && $known->is_active) ? $code : self::OPERATOR_CANCEL_REASON_CODE;
    }

    /**
     * 운영자가 취소할 수 있는 **주문상품 상태** 목록을 돌려줍니다. (§12-B B4)
     *
     * 운영자의 권한 축은 `order_settings.cancellable_statuses` 이고, 미결제는 그 목록과 무관하게
     * 항상 허용된다(`Order::isCancellable()` 과 같은 규율). 여기에 주문의 **현재 상품 상태**를
     * 더한다 — 취소 모달은 그 주문의 상품을 보여 주고 운영자가 그중에서 고르므로, 목록에 없는
     * 상태의 상품이 모달에 서 있으면 고를 수는 있는데 실행만 거절되는 막다른 길이 된다.
     *
     * 이미 종결된 상태(취소·반품완료·교환완료)는 제외한다 — 그 수량은 이미 빠져 있다.
     *
     * 이 판정의 SSoT 는 여기다. 관리자 컨트롤러도 이 메서드를 쓴다 — 두 벌로 두면 화면이 거는
     * 게이트와 서비스가 거는 게이트가 갈린다.
     *
     * @param  Order  $order  대상 주문
     * @return array<int, string> 자격 판정에 쓸 상태 값 목록
     */
    public function operatorCancellableStatusValues(Order $order): array
    {
        $configured = (array) $this->settingsService->getSetting('order_settings.cancellable_statuses', []);

        $order->loadMissing('options');

        $current = $order->options
            ->reject(fn ($option) => in_array(
                $option->option_status instanceof OrderStatusEnum
                    ? $option->option_status->value
                    : (string) $option->option_status,
                OrderStatusEnum::syncExcludedValues(),
                true,
            ))
            ->map(fn ($option) => $option->option_status instanceof OrderStatusEnum
                ? $option->option_status->value
                : (string) $option->option_status)
            ->all();

        return array_values(array_unique(array_merge(
            [OrderStatusEnum::PENDING_ORDER->value, OrderStatusEnum::PENDING_PAYMENT->value],
            array_map(fn ($value) => (string) $value, $configured),
            $current,
        )));
    }

    // ───────────────────────────────────────────────
    // 취소신청서 기록 — 주문 장부 확정의 단일 지점 (#599 후속 §4-3 · D3)
    // ───────────────────────────────────────────────

    /**
     * 이 주문을 지금 취소할 수 있는지 판정합니다. (관리자 취소 경로의 권한 축 — §12-B)
     *
     * 새 모델에서 관리자 [주문취소]는 **환불신청서 접수 경로**로 흐른다. 그 경로는 고객용
     * 신청 자격만 알고 `order_settings.cancellable_statuses` 는 모르므로, 운영자의 권한 축을
     * 여기서 따로 지켜 준다 — 없으면 배송중 주문처럼 설정이 막아야 하는 상태가 통과한다.
     *
     * 판정의 SSoT 는 `Order::isCancellable()` 이다(미결제는 목록과 무관하게 항상 허용).
     *
     * @param  Order  $order  대상 주문
     * @return void
     *
     * @throws OrderCancellationException 취소 불가 상태
     */
    public function assertCancellable(Order $order): void
    {
        $this->validateCancellable($order);
    }

    /**
     * 취소 확정 뒤 현금영수증을 동기화합니다. (**반드시 트랜잭션 밖에서 부른다**)
     *
     * `recordCancellation()` 안에서 부르지 않는 이유는 두 가지다:
     *
     *   ⓐ 재발급 실패 이력(`issue_status=FAILED`)은 관리자 화면의 경고 배지 근거이자 국세청
     *      신고 누락을 막는 원장이다. 롤백에 휩쓸려 사라지면 안 된다.
     *   ⓑ 프로바이더 취소·재발급은 외부 API 2회 왕복이다. DB 잠금을 그동안 붙들 수 없고,
     *      실제 영수증은 이미 취소됐는데 트랜잭션만 롤백되면 DB 와 국세청 기록이 어긋난다.
     *
     * @param  Order  $order  취소가 반영된 주문 (fresh)
     * @param  string|null  $reason  취소 사유 코드
     * @return void
     */
    public function syncCashReceiptAfterCancellation(Order $order, ?string $reason): void
    {
        $this->syncCashReceipt($order, $reason);
        $this->warnIfTaxInvoiceStale($order);
    }

    /**
     * 발급된 세금계산서가 있는 주문의 취소를 이력에 남깁니다. (P15)
     *
     * **세금계산서는 취소·부분취소에 전혀 반응하지 않는다** — 현금영수증은 프로바이더를 통해
     * 취소·재발급되는데 세금계산서에는 그 경로가 없다. 국세청 연동 자동 취소·재발행은 외부
     * 연동이라 별도 설계·테스트가 필요하므로 이번 범위 밖이다(§12-A).
     *
     * 대신 **누락을 드러낸다** — 발급된 계산서가 있는 주문이 취소되면 이력에 한 줄을 남기고,
     * 관리자 주문상세가 같은 조건으로 경고 배지를 세운다. 아무 표시도 없으면 운영자는 그
     * 계산서가 이미 정리된 줄 알고 넘어가고, 그 어긋남은 신고 시점에야 드러난다.
     *
     * @param  Order  $order  취소가 반영된 주문
     * @return void
     */
    protected function warnIfTaxInvoiceStale(Order $order): void
    {
        $issued = $this->orderRepository->listIssuedTaxInvoices($order->id);

        if ($issued->isEmpty()) {
            return;
        }

        HookManager::doAction(
            'sirsoft-ecommerce.order.tax_invoice_stale_after_cancel',
            $order,
            $issued->map(fn ($invoice): array => [
                'id' => (int) $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total_amount' => (float) $invoice->total_amount,
            ])->all(),
        );
    }

    /**
     * 취소신청서를 기록하고 주문 장부를 확정합니다. (**주문 장부 확정의 단일 지점** — D3)
     *
     * 취소신청서는 "주문이 취소된 사실" 이 아니라 **"환불이 집행되어 주문에서 금액이 빠진
     * 사실"** 의 주문 단위 기록이다(D3). 그래서 만들어지는 시점은 신청 접수가 아니라
     * **환불완료** 이며, 환불할 것이 없는 주문(미결제·0원)만 즉시 만들어진다.
     *
     * 이 메서드 밖에서 `OrderCancel` 을 만드는 곳은 없어야 한다 — 만드는 지점이 둘이면
     * 주문 합계 재계산·누적 컬럼 갱신이 경로마다 갈리고, 한쪽만 고쳐질 때 같은 주문의
     * 취소가 경로에 따라 다른 장부를 남긴다.
     *
     * ## 상품 상태는 출처가 정한다 (D13)
     *
     * 취소·환불 신청과 관리자 주문취소는 상품을 `CANCELLED` 로 전이시키지만, 반품·교환은
     * 상품 상태를 `returned`·`exchanged` 로 **유지**한다 — 반품된 상품을 "취소됨" 으로
     * 적으면 반품 탭과 취소 탭이 같은 상품에 대해 다른 사실을 말한다.
     *
     * ## 잔여 한도 축을 두 번 깎지 않는다 (#599 후속 §12-B)
     *
     * `OrderAdjustmentService` 의 잔여 환불 한도는 `total_paid_amount − total_refunded_amount`
     * 다(`OrderAdjustmentService.php:105`). 환불이 집행된 경로는 `RefundExecutionService`
     * 가 이미 `total_refunded_amount` 를 누적했으므로, 여기서 `total_paid_amount` 까지
     * 재계산값으로 덮어쓰면 같은 금액이 두 번 빠진다 — 10만원 주문의 6만원 환불 뒤
     * 잔여가 `4만 − 6만 = 0` 이 되어 **2차 부분환불이 0원으로 실행된다**. 그래서
     * `$recalculatePaidAmount=false` 로 그 두 컬럼만 건너뛴다. 나머지 금액 축
     * (소계·배송비·할인·세액·프로모션 스냅샷)은 D12 대로 모든 경로에서 재계산한다.
     *
     * @param  Order  $order  주문 (options/payment/shippings 로드 상태)
     * @param  array  $cancelItems  취소 대상 [{order_option_id, cancel_quantity}]
     * @param  AdjustmentResult|null  $adjustment  재계산 결과 (null 이면 금액 재계산 없이 누적 축만 갱신 — 교환 차액 환불)
     * @param  OrderRefund|null  $refund  이 취소를 일으킨 환불신청서 (없으면 미결제·0원 취소)
     * @param  CancelTypeEnum|null  $cancelType  취소 유형 (null 이면 잔여 옵션으로 판정)
     * @param  string|null  $reason  취소 사유 **코드**
     * @param  string|null  $reasonDetail  상세 사유
     * @param  int|null  $cancelledBy  취소 요청자 ID
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  bool  $transitionOptions  주문상품을 `CANCELLED` 로 여기서 전이할지 (호출부가 이미 옮겼으면 false)
     * @param  bool|null  $syncOrderStatus  주문 상태를 이 취소로 파생시킬지 (null 이면 `$transitionOptions` 를 따른다)
     * @param  bool  $accumulatePayment  결제행의 취소 누적을 여기서 더할지 (환불 실행이 이미 더했으면 false)
     * @param  bool  $recalculatePaidAmount  `total_paid_amount`·`total_points_used_amount` 를 재계산값으로 덮어쓸지
     * @param  bool  $restoreCouponsAndStock  쿠폰·재고 복원을 여기서 할지 (호출부가 이미 했으면 false)
     * @param  bool  $paymentSettled  이 환불이 결제행의 수단에서 실제 취소로 일어났는지 (false = 다른 수단 — 마일리지·계좌 송금 — 으로
     *                                지급: 결제행 상태·취소 시각을 바꾸지 않고 이력에만 남긴다 · Q16)
     * @return OrderCancel 생성·확정된 취소신청서
     */
    public function recordCancellation(
        Order $order,
        array $cancelItems,
        ?AdjustmentResult $adjustment = null,
        ?OrderRefund $refund = null,
        ?CancelTypeEnum $cancelType = null,
        ?string $reason = null,
        ?string $reasonDetail = null,
        ?int $cancelledBy = null,
        ?int $processedBy = null,
        bool $transitionOptions = true,
        ?bool $syncOrderStatus = null,
        bool $accumulatePayment = true,
        bool $recalculatePaidAmount = true,
        bool $restoreCouponsAndStock = true,
        bool $paymentSettled = true,
    ): OrderCancel {
        $order->loadMissing(['options', 'payment', 'shippings']);

        // 변경 **전** 주문 속성 — 활동로그 화면은 `changes` 에 실린 항목만 "변경 전 → 변경 후"
        // 표로 그린다(§7-1 D). 취소 줄은 남는데 그 표가 없으면 운영자는 이 취소로 주문 금액이
        // 얼마에서 얼마가 됐는지를 로그에서 읽을 수 없다.
        $orderSnapshot = $order->toArray();

        // 주문 상태를 파생시킬지는 **상품이 주문에서 빠지는가** 로 정한다. 여기서 전이시켰는지
        // 여부와는 다른 축이다 — 취소·환불 신청은 환불 실행(`cancelOptionQuantitiesForClaim`)이
        // 이미 상품을 `CANCELLED` 로 옮겨 두고 오므로 `$transitionOptions` 는 false 인데,
        // 그 주문은 여전히 취소로 확정되어야 한다.
        $syncOrderStatus ??= $transitionOptions;

        $cancelType ??= $this->shouldConvertToFullCancel($order, $cancelItems)
            ? CancelTypeEnum::FULL
            : CancelTypeEnum::PARTIAL;

        // 누적 컬럼을 읽어 더하기 전에 주문·결제 행을 잠그고 커밋된 값으로 되읽는다.
        $this->lockAccumulatorRows($order);

        // 입금 전 주문(E2) — 받은 돈이 없다. 재계산된 남은 결제액은 받을 돈(due)이고, 결제행은 입금 대기로 남는다.
        $beforePayment = $order->order_status instanceof OrderStatusEnum && $order->order_status->isBeforePayment();

        $cancel = $this->createOrderCancel(
            $order, $cancelType, $reason, $reasonDetail, $cancelledBy, $cancelItems
        );

        // 두 문서를 서로 묶는다.
        //
        // 링크는 환불 쪽 컬럼(`order_cancel_id`)이 갖는다 — `OrderCancel::refund()` 가 그
        // 컬럼으로 걸린 `hasOne` 이다. 채우지 않으면 관계가 늘 비고, 관리자 취소 상세의
        // "환불 상태·환불 금액" 이 영영 `-` 로 남는다(리소스가 그 관계에서 값을 읽는다) —
        // 운영자는 취소 문서만 보고는 **얼마가 어떻게 돌아갔는지 알 수 없어** 환불 탭에서
        // 같은 주문의 환불을 손으로 찾아야 한다.
        //
        // D6 는 두 문서가 서로를 **대표하지** 않는다고 했지 **가리키지** 않는다고 하지
        // 않았다 — 별개 문서이기 때문에 오히려 링크가 필요하다.
        if ($refund !== null && $refund->order_cancel_id === null) {
            $this->orderRefundRepository->update($refund, ['order_cancel_id' => $cancel->id]);
            $refund->setRelation('cancel', $cancel);
            $cancel->setRelation('refund', $refund);
        }

        $this->createCancelOptions($cancel, $order, $cancelItems);

        // 취소 축 활동로그 — 신청서 생성이 곧 접수다(생성 시점이 환불완료이므로 접수와
        // 완료 사이에 사람이 개입할 틈은 없지만, 두 단계를 모두 남기는 것이 D16 의 요구다).
        HookManager::doAction('sirsoft-ecommerce.claim.cancel.requested', $cancel, $order);

        if ($transitionOptions) {
            $this->updateOrderOptions($order, $cancelItems, $reason);
        }

        if ($adjustment !== null) {
            $this->applyOptionUpdates($order, $adjustment);
            // 분할(updateOrderOptions) 뒤라 이번 취소로 생긴 분할 행의 배송 행까지 다시 읽어 맞춘다 (S-Q26-b)
            $this->shippingAmountWriter->apply($order, $adjustment->shippingShares);
        }

        $this->updateOrderTotals(
            $order,
            $cancelType,
            $adjustment,
            $reason,
            recalculatePaidAmount: $recalculatePaidAmount,
            // 상품이 주문에서 빠지지 않는 출처(반품·교환)는 주문 상태도 건드리지 않는다.
            syncOrderStatus: $syncOrderStatus,
            // 누적할 취소액은 **실제로 집행된 환불액**이다(§3). 운영자가 실행 시점에 금액을
            // 고쳤으면 그 값이며, 재계산 결과가 아니다 — 둘이 갈리면 주문의 취소 합계가
            // 실제로 나간 돈과 어긋난다. 환불이 없는 취소(미결제·0원)는 재계산 결과를 쓴다.
            cancelledAmount: $refund !== null
                ? (float) $refund->refund_amount + (float) $refund->refund_points_amount
                : null,
            beforePayment: $beforePayment,
        );

        // P4 — 미입금 취소도 결제행을 정리한다. 정리하지 않으면 취소된 주문이 이후 PG
        // 입금통보·관리자 [입금확인]으로 `PAYMENT_COMPLETE` 로 되살아나 재고·마일리지가
        // 재차감된다. 누적(`cancelled_amount`)은 환불 실행이 이미 더했으면 건너뛴다.
        if ($order->payment) {
            $this->updatePayment(
                $order,
                $cancel,
                $adjustment,
                accumulate: $accumulatePayment,
                refund: $refund,
                paymentSettled: $paymentSettled,
                beforePayment: $beforePayment,
            );
        }

        if ($restoreCouponsAndStock && $adjustment !== null) {
            $this->restoreCoupons($order, $adjustment);
            $this->restoreStock($order, $cancelItems);

            // 환불 없이 끝나는 취소(미결제·0원)도 선차감 마일리지를 돌려준다. 환불이 있는 취소는
            // 환불 실행(`RefundExecutionService::restoreMileage`)이 이미 돌려줬으므로 다시 하지 않는다.
            // 이 분기가 없으면 「주문할 때」 차감 시점의 상점에서 입금 기한 만료·결제 미성립 주문을
            // 정리할 때 사용 마일리지가 영영 돌아오지 않는다(취소는 되는데 오류도 로그도 없다).
            // 복원은 취소신청서 id 로 멱등하다. 복원이 실패하면 취소 전체를 되돌린다(D2) — 「취소는
            // 됐는데 마일리지는 안 돌아온」 상태보다 「취소가 실패로 보이고 다시 할 수 있는」 상태가 낫다.
            if ($refund === null) {
                $this->refundExecutionService->restoreMileage($order, $adjustment, $cancel);
            }
        }

        $this->finalizeCancellation($cancel, $processedBy);

        // 주문 축 취소 훅 — 취소가 확정되는 **모든** 경로가 여기를 지난다.
        //
        // 예전에는 구형 파이프라인에서만 발화했다. 그래서 관리자 [주문취소]와 고객 신청이
        // 클레임 경로로 옮겨간 뒤에는 ⑴ 주문 활동로그에 취소 줄이 남지 않았고 ⑵ 이 훅에
        // 걸린 확장(`CouponRestoreListener` 등)이 그 경로에서 통째로 건너뛰어졌다.
        // 장부 확정의 단일 지점이 여기이므로 훅도 여기가 제자리다.
        //
        // 쿠폰 복원이 두 번 도는 것은 걱정하지 않아도 된다 — 리스너가 `USED` 인 건만 되돌리는
        // 멱등 구현이다(쿠폰 복원 리스너의 restoreIssues 참조).
        $hookName = $cancelType === CancelTypeEnum::FULL
            ? 'sirsoft-ecommerce.order.after_cancel'
            : 'sirsoft-ecommerce.order.after_partial_cancel';

        HookManager::doAction(
            $hookName,
            $order->fresh(),
            ['cancel_type' => $cancelType->value, 'cancel_items' => $cancelItems],
            $orderSnapshot,
        );

        return $cancel->fresh();
    }

    /**
     * 취소신청서를 `requested → completed` 로 전이시킵니다. (단계를 건너뛰지 않는다 — D16)
     *
     * 환불완료 시점에 만들더라도 최종 상태를 바로 INSERT 하지 않고 전이시킨다 — 그래야
     * 취소 축 활동로그가 "접수" 와 "완료" 두 줄로 남는다. 한 줄만 남으면 운영자는 이
     * 취소가 어떤 경로로 확정됐는지 되짚을 수 없다.
     *
     * @param  OrderCancel  $cancel  취소신청서
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return void
     */
    protected function finalizeCancellation(OrderCancel $cancel, ?int $processedBy): void
    {
        $now = Carbon::now();
        $previous = $cancel->cancel_status instanceof CancelStatusEnum
            ? $cancel->cancel_status->value
            : (string) $cancel->cancel_status;

        $optionRows = $this->orderCancelOptionRepository->listByCancelId($cancel->id);

        $cancel->update([
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancelled_at' => $now,
        ]);

        $this->orderCancelOptionRepository->bulkUpdateStatus(
            $cancel->id,
            CancelOptionStatusEnum::COMPLETED->value,
            $processedBy,
            $now,
        );

        HookManager::doAction(
            'sirsoft-ecommerce.claim.cancel.status_changed',
            $cancel->fresh(),
            $previous,
            CancelStatusEnum::COMPLETED->value,
        );

        $optionChanges = [];
        foreach ($optionRows as $row) {
            $optionChanges[] = [
                'option_id' => (int) $row->id,
                'previous' => $row->option_status instanceof CancelOptionStatusEnum
                    ? $row->option_status->value
                    : (string) $row->option_status,
                'new' => CancelOptionStatusEnum::COMPLETED->value,
            ];
        }

        if ($optionChanges !== []) {
            HookManager::doAction(
                'sirsoft-ecommerce.claim.cancel.option_status_changed',
                $cancel->fresh(),
                $optionChanges,
                CancelOptionStatusEnum::COMPLETED->value,
                $processedBy,
            );
        }
    }

    // ───────────────────────────────────────────────
    // 검증 메서드
    // ───────────────────────────────────────────────

    /**
     * 주문 취소 가능 여부를 검증합니다.
     *
     * @param  Order  $order  대상 주문
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     *
     * @throws OrderCancellationException 진행 중인 클레임에 묶인 수량일 때
     */
    protected function validateNotLockedByClaim(Order $order, array $cancelItems): void
    {
        try {
            $this->claimQuantityGuard->assertNotLockedForCancellation($order, $cancelItems);
        } catch (OrderClaimException $e) {
            // 키·치환 파라미터를 함께 넘긴다 — 버리면 미리보기 422 가 회원·비회원 면에서
            // 사유를 잃고 뭉뚱그린 안내만 남는다 (실행 경로는 관리자 면이라 원문을 쓴다).
            throw new OrderCancellationException($e->getMessage(), $e->getMessageKey(), $e->getMessageParams());
        }
    }

    /**
     * 이 취소가 전체취소인지 부분취소인지 판정합니다.
     *
     * **수량이 아직 옮겨지기 전에** 불러야 한다. 클레임 경로는 환불 실행 시점에
     * `cancelOptionQuantitiesForClaim()` 으로 수량을 먼저 빼고 나서 취소를 기록하는데, 그 뒤에
     * 판정하면 남은 수량이 이미 0 이라 **부분취소가 전체취소로 기록된다**. 그러면 취소 문서의
     * 유형이 틀릴 뿐 아니라 주문 축 훅도 `after_cancel` 로 나가, 그 훅에 걸린 쿠폰 복원 리스너가
     * 스냅샷의 쿠폰을 **조건과 무관하게 전부** 되살린다(부분취소로 조건이 유지되는 쿠폰까지).
     *
     * @param  Order  $order  주문 (수량 이동 전 상태)
     * @param  array  $cancelItems  취소 대상 [{order_option_id, cancel_quantity}]
     * @return CancelTypeEnum 취소 유형
     */
    public function resolveCancelType(Order $order, array $cancelItems): CancelTypeEnum
    {
        return $this->shouldConvertToFullCancel($order, $cancelItems)
            ? CancelTypeEnum::FULL
            : CancelTypeEnum::PARTIAL;
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
     * @return void 반환값 없음
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
     * 클레임(직접 환불신청) 실행이 확정한 수량을 취소 상태로 분할·전이합니다. (S6b)
     *
     * 직접 환불신청서(`refund_source_type=direct`)는 접수 시점에 주문옵션을 판매 상태 그대로
     * 두고 수량만 선점한다(`ClaimQuantityGuard`) — 환불 실행 시점에 그 수량이 판매 흐름에서
     * 실제로 빠져나가야 하며, 분할·상태 전이 규칙은 취소 경로와 같은 한 벌이어야 한다.
     * 그래서 이 서비스의 분할 로직(`updateOrderOptions`)을 그대로 재사용한다.
     *
     * 장부 확정 지점(`recordCancellation`)과 달리 주문 합계·배송행 재계산과 취소신청서 기록은
     * 하지 않는다 — 클레임 환불은 `total_paid_amount` 를 덮어쓰지 않고 환불 누적
     * (`RefundExecutionService::execute`)으로만 장부를 맞추는 회계 모델이다(§4-3-1 S2a 역기재).
     *
     * @param  Order  $order  대상 주문 (options 로드 상태)
     * @param  array  $cancelItems  대상 [{order_option_id, cancel_quantity}]
     * @param  string|null  $reason  취소 사유 코드
     * @return void 반환값 없음
     */
    public function cancelOptionQuantitiesForClaim(Order $order, array $cancelItems, ?string $reason): void
    {
        $order->loadMissing('options');

        $this->updateOrderOptions($order, $cancelItems, $reason);
    }

    /**
     * 주문 옵션 상태를 변경하고 수량을 분할합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     * @param  string|null  $reason  취소 사유
     * @return void 반환값 없음
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

            // 이력 카운터 — 이 행에서 떼어 낸 취소 수량의 누적. 매출 집계는 이 값을 빼지 않는다
            // (quantity 가 이미 잔여다). 02c 재활성이 되돌린다.
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
     * @return void 반환값 없음
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
    // ③-e. Order 합계 업데이트
    // ───────────────────────────────────────────────

    /**
     * 누적 컬럼을 가진 주문·결제 행을 잠그고 커밋된 값으로 되읽습니다.
     *
     * 관계(options/shippings 등)는 이 트랜잭션 안에서 이미 사용 중이므로 건드리지 않고,
     * 누적 판단에 쓰이는 속성만 교체한다. 잠금은 커밋까지 유지되어 뒤따르는 취소 요청이
     * 앞선 커밋을 본 뒤에 진행한다.
     *
     * @param  Order  $order  대상 주문 (속성이 최신 값으로 갱신됨)
     * @return void
     */
    protected function lockAccumulatorRows(Order $order): void
    {
        $locked = $this->orderRepository->findForUpdate($order->id);

        if ($locked !== null) {
            // 관계는 유지한 채 속성만 커밋된 값으로 교체
            $order->setRawAttributes($locked->getAttributes(), true);
        }

        $lockedPayment = $this->orderPaymentRepository->findByOrderIdForUpdate($order->id);

        if ($lockedPayment !== null && $order->relationLoaded('payment') && $order->payment !== null) {
            $order->payment->setRawAttributes($lockedPayment->getAttributes(), true);
        }
    }

    /**
     * 주문 합계를 재계산 결과에 따라 갱신합니다.
     *
     * **주문 합계를 재계산하는 지점은 이 메서드 하나다**(#599 후속 §4-3). 취소 발이든
     * 환불완료 발이든 `recordCancellation()` 을 거쳐 여기로 모이므로 같은 취소가 두 번
     * 반영되지 않는다.
     *
     * `$result` 가 null 이면 누적 축(`total_cancelled_amount`·`cancellation_count`·
     * `cancelled_at`)만 갱신한다 — 교환 차액 환불처럼 **상품이 주문에 남는** 경우다.
     * 남은 상품을 빼고 재계산하면 고객이 가진 물건의 값이 주문에서 사라진다.
     *
     * `$recalculatePaidAmount` 가 false 면 `total_paid_amount`·`total_points_used_amount`
     * 두 컬럼만 건너뛴다. 근거는 `recordCancellation()` 의 「잔여 한도 축을 두 번 깎지
     * 않는다」 절에 있다.
     *
     * @param  Order  $order  주문
     * @param  CancelTypeEnum  $cancelType  취소 유형
     * @param  AdjustmentResult|null  $result  계산 결과 (null 이면 누적 축만)
     * @param  string|null  $reason  취소 사유
     * @param  bool  $recalculatePaidAmount  실결제·사용마일리지 재계산값을 덮어쓸지 여부
     * @param  bool  $syncOrderStatus  주문 상태를 이 취소로 파생시킬지 여부 (반품·교환은 false)
     * @param  float|null  $cancelledAmount  누적할 취소액 (null 이면 재계산 결과를 쓴다)
     * @param  bool  $beforePayment  입금 전 주문의 취소인지 (E2 — 재계산 결제액을 받을 돈으로 옮긴다)
     * @return void 반환값 없음
     */
    protected function updateOrderTotals(
        Order $order,
        CancelTypeEnum $cancelType,
        ?AdjustmentResult $result,
        ?string $reason,
        bool $recalculatePaidAmount = true,
        bool $syncOrderStatus = true,
        ?float $cancelledAmount = null,
        bool $beforePayment = false,
    ): void {
        // 주문 상태를 파생시키지 않는 출처(반품·교환)는 전체취소로 승격하지 않는다.
        //
        // 반품이 전량이면 남은 활성 상품이 없어 `shouldConvertToFullCancel()` 이 FULL 을
        // 돌려주는데, 그대로 두면 **전량 반품된 주문이 `CANCELLED` 로 뒤집힌다** — 상품은
        // `returned` 인데 주문만 취소로 적혀 두 층이 어긋난다(D13 이 막으려는 방향 그 자체).
        // 그 주문의 상태는 반품·교환 서비스가 자기 어휘로 소유한다.
        $isFullCancel = $syncOrderStatus && $cancelType === CancelTypeEnum::FULL;
        $previousStatus = $order->order_status;

        $orderUpdates = $result?->orderUpdates ?? [];

        if (! $recalculatePaidAmount) {
            unset($orderUpdates['total_paid_amount'], $orderUpdates['total_points_used_amount']);
        }

        // 입금 전 주문은 받은 돈이 없다 — 재계산된 「남은 결제액」은 받을 돈(due)이지 받은 돈(paid)이 아니다(E2).
        // paid 에 쓰면 미결제 주문이 결제된 것처럼 보이고, due 가 원래 금액으로 남아 입금확인 금액 검증
        // (validatePaymentAmount 1단계: 구성요소 합 = due)이 어떤 금액으로도 통과하지 못한다. 전체취소는 아래에서
        // due 를 0 으로 덮는다(S1-D17).
        if ($beforePayment && array_key_exists('total_paid_amount', $orderUpdates)) {
            $orderUpdates['total_due_amount'] = $orderUpdates['total_paid_amount'];
            unset($orderUpdates['total_paid_amount']);
        }

        $updateData = array_merge($orderUpdates, [
            'total_cancelled_amount' => (float) $order->total_cancelled_amount
                + (float) ($cancelledAmount ?? $result?->refundAmount ?? 0),
            'cancellation_count' => ($order->cancellation_count ?? 0) + 1,
            // 취소일시 — 전체/부분취소 모두 기록(native 컬럼 SSoT). 최초 취소 시각을 보존하고 재취소 시 갱신하지 않는다.
            'cancelled_at' => $order->cancelled_at ?? Carbon::now(),
        ]);

        // 최초 취소 직전의 쿠폰·사용 포인트 조건을 한 번만 남긴다 (Q26 재활성 복원 입력).
        // 부분취소 재계산이 promotions_applied_snapshot 을 「적용된 쿠폰만」으로 바꿔 버려, 조건을 잃고
        // 빠진 쿠폰의 규칙은 이 값에만 남는다. 입금 전 취소는 사용 포인트 헤더도 다시 써 버린다.
        // 이후 취소·재활성에서 덮지 않는다 — 원 주문 조건이다.
        $meta = $order->order_meta ?? [];
        if ($result !== null && ! isset($meta['pre_cancel_snapshot'])) {
            $meta['pre_cancel_snapshot'] = [
                'promotions_applied_snapshot' => $order->promotions_applied_snapshot,
                'total_points_used_amount' => (float) $order->total_points_used_amount,
                'captured_at' => Carbon::now()->toIso8601String(),
            ];
            $updateData['order_meta'] = $meta;
        }

        if ($isFullCancel) {
            // 전체취소는 주문 상태를 CANCELLED 로 확정한다.
            $updateData['order_status'] = OrderStatusEnum::CANCELLED;

            // S1-D17: 취소된 주문은 받을 돈이 없다 — 미수금을 함께 내린다.
            // `total_due_amount` 를 0 으로 내리는 곳은 종전에 `completePayment()` 뿐이라
            // (`OrderProcessingService`), **결제 전에 취소된 주문**은 그 경로를 지나지 않아
            // 결제예정금액이 그대로 남았다. 돌려줄 돈이 없어 환불신청서도 안 생기므로
            // 재계산 경로도 거치지 않는다 — 주문은 `cancelled` 인데 장부만 계속 「받을 돈이 있다」고
            // 말하는 상태가 된다(실측: 무통장 미입금 취소 주문만 due 가 남고 나머지 취소 주문은 전부 0).
            $updateData['total_due_amount'] = 0;
            $updateData['order_meta'] = array_merge($updateData['order_meta'] ?? ($order->order_meta ?? []), [
                'cancel_reason' => $reason,
                'previous_status' => $previousStatus->value,
            ]);
        }
        // 부분취소는 별도 주문 상태(partial_cancelled)를 두지 않는다 — 옵션은 이미 CANCELLED 로 전이됐고,
        // 주문 상태는 아래 syncParentOrderStatus 가 "취소 제외 잔여 활성 옵션" 기준으로 파생 결정한다.
        // (잔여 옵션이 모두 취소된 경우 syncParentOrderStatus 가 CANCELLED 로 전이.) — 2026-06-22

        $order->update($updateData);

        if (! $isFullCancel && $syncOrderStatus) {
            $this->orderOptionService->syncParentOrderStatus($order->id);
        }
    }

    // ───────────────────────────────────────────────
    // ③-h. OrderPayment 업데이트
    // ───────────────────────────────────────────────

    /**
     * 결제 정보를 갱신합니다.
     *
     * **미결제 주문도 여기를 지난다**(P4). 결제행의 `payment_status` 를 취소로 정리하지
     * 않으면, 이후 PG 입금통보나 관리자 [입금확인]이 그 행을 "아직 입금 대기" 로 읽고
     * 통과시켜 **취소된 주문이 `PAYMENT_COMPLETE` 로 되살아난다** — 재고와 마일리지가
     * 다시 차감되고, 고객은 취소한 주문의 상품을 받는다.
     *
     * `$accumulate` 가 false 면 취소 누적(`cancelled_amount`)은 더하지 않는다 — 환불
     * 실행(`RefundExecutionService::prepareLedger`)이 이미 더한 경우다.
     * 두 번 더하면 결제수단 잔여 한도(D19 2축)가 두 배로 깎여 정당한 2차 환불이 막힌다.
     *
     * ## 취소 이력에 싣는 금액은 «실제로 집행된 값»이다 (E1)
     *
     * 환불신청서가 있으면 그 저장값(운영자 수정·우선순위 배분 반영)이 정본이다. 예전에는 재계산
     * 결과(`$result`)를 실어, 반품·교환 발 환불은 `$result=null` 이라 0 원이 찍히고 직접 환불은
     * 운영자가 고친 금액 대신 재계산값이 찍혔다.
     *
     * ## 결제수단과 다른 수단으로 돌려준 환불은 결제행 상태를 바꾸지 않는다 (Q16)
     *
     * 카드 주문을 계좌 송금·마일리지로 돌려줬다면 카드 거래는 취소되지 않았다. 그때 결제행을
     * `cancelled` 로 바꾸면 「누적은 0 인데 상태는 취소」인 모순이 생긴다(주문 1622 실측). 그래서
     * 상태·취소 시각·사유는 그대로 두고, 이력에만 수단과 `settled_on_payment=false` 로 남긴다.
     *
     * @param  Order  $order  주문
     * @param  OrderCancel  $cancel  취소 레코드
     * @param  AdjustmentResult|null  $result  계산 결과 (null 이면 금액 축 없이 상태만 정리)
     * @param  bool  $accumulate  취소 누적 컬럼을 여기서 더할지 여부
     * @param  OrderRefund|null  $refund  이 취소를 일으킨 환불신청서 (이력 금액의 정본)
     * @param  bool  $paymentSettled  그 결제수단에서 실제 취소가 일어났는지 (false 면 상태 유지)
     * @param  bool  $beforePayment  입금 전 주문의 취소인지 (E2 — 누적 없음, 부분취소면 입금 대기 유지)
     * @return void
     */
    protected function updatePayment(
        Order $order,
        OrderCancel $cancel,
        ?AdjustmentResult $result,
        bool $accumulate = true,
        ?OrderRefund $refund = null,
        bool $paymentSettled = true,
        bool $beforePayment = false,
    ): void {
        $payment = $order->payment;
        if (! $payment) {
            return;
        }

        $isFullCancel = $cancel->cancel_type === CancelTypeEnum::FULL;

        // 결제 통화(order_currency) 환산 환불액. base≠결제 통화일 때 PG 실환불·누적은 결제 통화로
        // 정합되어야 하므로 mc_cancelled_amount 를 결제 통화로 누적한다(base 누적 cancelled_amount 와 별개).
        $orderCurrency = $payment->currency ?: ($order->currency_snapshot['order_currency'] ?? null);

        // 이력에 싣는 금액은 «실제로 집행된 값» — 환불신청서가 있으면 그 저장값이 정본이다.
        $executed = $refund !== null
            ? new AdjustmentResult(
                refundAmount: (float) $refund->refund_amount,
                refundPointsAmount: (float) $refund->refund_points_amount,
                mcRefundAmount: $refund->mc_refund_amount ?? [],
            )
            : $result;

        // 취소 누적 컬럼(cancelled_amount / mc_cancelled_amount)의 규칙은 클레임 환불과 동일하므로
        // RefundExecutionService 가 SSoT 다. 여기서는 취소 고유 필드만 덧붙인다.
        // 입금 전 주문은 결제행에 받은 돈이 없어 누적할 취소액도 없다(E2).
        $cancelledUpdates = $accumulate && $result !== null && ! $beforePayment
            ? $this->refundExecutionService->buildCancelledAmountUpdates($payment, $result)
            : [];

        $refundMethod = $refund?->refund_method;

        $cancelHistory = $payment->cancel_history ?? [];
        $cancelHistory[] = [
            'cancel_id' => $cancel->id,
            'cancel_number' => $cancel->cancel_number,
            'refund_id' => $refund?->id,
            'refund_number' => $refund?->refund_number,
            'refund_method' => $refundMethod instanceof \BackedEnum ? $refundMethod->value : $refundMethod,
            // 이 결제행의 cancelled_amount 에 합산됐는가 — 환불 없는 취소는 실제로 누적했는지로 본다.
            'settled_on_payment' => $refund !== null ? $paymentSettled : ($cancelledUpdates !== []),
            'amount' => (float) ($executed?->refundAmount ?? 0),
            'amount_local' => $executed !== null ? $this->resolveOrderCurrencyRefundAmount($executed, $orderCurrency) : 0.0,
            'currency' => $orderCurrency,
            'points_amount' => (float) ($executed?->refundPointsAmount ?? 0),
            'reason' => $cancel->cancel_reason_type,
            'date' => Carbon::now()->toIso8601String(),
        ];

        $updates = array_merge($cancelledUpdates, ['cancel_history' => $cancelHistory]);

        // 결제행 상태를 바꾸지 않는 두 경우
        // - 다른 수단으로 지급한 환불(Q16) — 그 결제는 취소되지 않았다.
        // - 입금 전 부분취소(E2) — 남은 상품의 입금을 받아야 하므로 입금 대기로 둔다. 바꾸면 [입금확인] 버튼이
        //   사라지고 API 도 `deposit_not_pending` 으로 거절한다. 전체취소는 P4 대로 취소로 정리한다.
        $keepStatus = ($refund !== null && ! $paymentSettled)
            || ($beforePayment && ! $isFullCancel && $payment->payment_status?->isAwaitingDeposit() === true);

        if (! $keepStatus) {
            $updates += [
                'cancel_reason' => $cancel->cancel_reason_type,
                'cancelled_at' => Carbon::now(),
                'payment_status' => $isFullCancel || $this->paymentCashFullyCancelled($payment, $cancelledUpdates)
                    ? PaymentStatusEnum::CANCELLED
                    : PaymentStatusEnum::PARTIAL_CANCELLED,
            ];
        }

        $payment->update($updates);
    }

    /**
     * 이 결제행이 받은 돈을 전부 돌려줬는지 판정합니다. (결제행 상태는 돈의 상태다 — C11 매트릭스)
     *
     * 취소 유형(`FULL`)만으로는 판정하지 못한다 — 반품을 나눠 받으면 앞 회차에서 반품으로 닫힌 상품이
     * `shouldConvertToFullCancel()` 에서 「남은 상품」으로 세어져 마지막 회차도 부분취소가 되고, 받은 돈을
     * 모두 돌려준 결제행이 「부분취소」로 남는다. 누적 취소액이 수납액에 닿았는지로 함께 본다.
     *
     * @param  OrderPayment  $payment  결제행
     * @param  array<string, mixed>  $cancelledUpdates  이번 취소가 더할 누적 컬럼 (비었으면 이미 더해진 값)
     * @return bool 수납액 전부를 돌려줬으면 true
     */
    protected function paymentCashFullyCancelled(OrderPayment $payment, array $cancelledUpdates): bool
    {
        $paid = (float) $payment->paid_amount_base;
        $cancelled = (float) ($cancelledUpdates['cancelled_amount'] ?? $payment->cancelled_amount);

        return $paid > 0
            && bccomp(number_format($cancelled, 4, '.', ''), number_format($paid, 4, '.', ''), 4) >= 0;
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
     * 쿠폰을 복원합니다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     * @return void 반환값 없음
     */
    protected function restoreCoupons(Order $order, AdjustmentResult $result): void
    {
        $this->refundExecutionService->restoreCoupons($order, $result);
    }

    /**
     * 재고를 복원합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  취소 대상
     * @return void 반환값 없음
     */
    protected function restoreStock(Order $order, array $cancelItems): void
    {
        $this->refundExecutionService->restoreStock($order, $cancelItems);
    }

    // ───────────────────────────────────────────────
    // ③-j. 상태 확정
    // ───────────────────────────────────────────────

}
