<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\MileageEarnTriggerEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 마일리지 거래 리스너 (적립/사용/복원/환불지급/회수 통합 진입점)
 *
 * 모든 처리는 UserMileageService 에 위임하며, 잔액 캐시 재계산은 Service 내부에서
 * 동일 트랜잭션으로 수행됩니다 (리스너는 캐시를 직접 다루지 않음).
 * mileage.earn(결제완료) 훅은 구독하지 않습니다 — 트리거는 구매확정/배송완료(이중 적립 방지).
 */
class MileageTransactionListener implements HookListenerInterface
{
    /**
     * 의존성을 주입받습니다.
     *
     * @param  UserMileageService  $mileageService  마일리지 서비스
     * @param  EcommerceSettingsService  $settings  환경설정 서비스
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  orderOptionRepository 값
     */
    public function __construct(
        private UserMileageService $mileageService,
        private EcommerceSettingsService $settings,
        private OrderOptionRepositoryInterface $orderOptionRepository,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 마일리지 차감/복원은 호출자(주문 생성·취소) 트랜잭션 안에서 실행되어야 한다.
            // Action 훅 기본값은 큐 작업 래핑 + afterCommit 이라, 그대로 두면 주문이 커밋된
            // 뒤에 차감이 돌아 잔액 부족으로 실패해도 주문을 되돌릴 수 없다.
            'sirsoft-ecommerce.mileage.use' => ['method' => 'handleUse', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.mileage.restore' => ['method' => 'handleRestore', 'priority' => 10, 'sync' => true],
            // 환불 현금분 마일리지 지급 — 금전 훅이라 환불 실행 트랜잭션 안에서 돌아야 한다(D1·D2).
            'sirsoft-ecommerce.mileage.refund_credit' => ['method' => 'handleRefundCredit', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.order-option.after_confirm' => ['method' => 'handleAfterConfirm', 'priority' => 10],
            'sirsoft-ecommerce.order_option.after_status_change' => ['method' => 'handleAfterStatusChange', 'priority' => 10],
            'sirsoft-ecommerce.order_option.after_bulk_status_change' => ['method' => 'handleAfterBulkStatusChange', 'priority' => 10],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // method 매핑을 사용하므로 직접 호출되지 않음
    }

    /**
     * 마일리지 사용 차감 (주문생성 트랜잭션 내) — 실패 시 예외로 주문 롤백
     *
     * 재활성(취소 주문·상품을 되살림)은 `context.reason=reactivation` 으로 부른다 — 그 거래는 재활성
     * 표시를 남겨 회수 가능액 계산에서 빠진다(두 번 빼지 않음, Q14). 기존 2인수 구독자는 세 번째 인수를
     * 무시한다.
     *
     * @param  int  $usePoints  사용 마일리지
     * @param  Order  $order  주문
     * @param  array<string, mixed>  $context  호출 맥락 (reason)
     * @return void 반환값 없음
     */
    public function handleUse(int $usePoints, Order $order, array $context = []): void
    {
        if ($usePoints <= 0 || $order->user_id === null) {
            return;
        }

        $currency = $this->mileageService->baseCurrencyForOrder($order);

        if (($context['reason'] ?? null) === 'reactivation') {
            $this->mileageService->deductForReactivation($order->user_id, $usePoints, $currency, $order);

            return;
        }

        $this->mileageService->deductFifo($order->user_id, $usePoints, $currency, $order);
    }

    /**
     * 사용 복원 (취소/환불) — 취소는 order_cancel_id, 클레임 환불 실행은 order_refund_id 멱등, 신규 lot
     *
     * @param  float  $amount  복원액
     * @param  Order  $order  주문
     * @param  int|null  $orderCancelId  취소 레코드 ID (취소 발 복원 멱등 축)
     * @param  int|null  $orderRefundId  환불 레코드 ID (클레임 환불 실행 멱등 축 — S6b)
     * @return void 반환값 없음
     */
    public function handleRestore(float $amount, Order $order, ?int $orderCancelId = null, ?int $orderRefundId = null): void
    {
        // 멱등 축(취소 ID 또는 환불 ID)이 하나도 없으면 복원하지 않는다 — 축 없는 복원은
        // 재시도마다 새 lot 을 발행한다.
        if ($amount <= 0 || $order->user_id === null || ($orderCancelId === null && $orderRefundId === null)) {
            return;
        }

        $currency = $this->mileageService->baseCurrencyForOrder($order);

        if ($orderCancelId !== null) {
            $this->mileageService->restoreForCancel($order->user_id, $order->id, $orderCancelId, $amount, $currency);

            return;
        }

        // 클레임 환불 실행(S6b) — order_refund_id 멱등 축으로 사용 마일리지를 환급한다.
        $this->mileageService->restoreForRefund($order->user_id, $order->id, $orderRefundId, $amount, $currency);
    }

    /**
     * 환불 현금분 마일리지 지급 (클레임 환불 실행 트랜잭션 내) — order_refund_id 멱등, 무기한 lot
     *
     * 실패는 삼키지 않는다 — 예외가 환불 실행 전체를 롤백한다(D2).
     *
     * @param  float  $amount  지급액 (기준 통화 현금 환불액)
     * @param  Order  $order  주문
     * @param  int  $orderRefundId  환불 레코드 ID (멱등 축)
     * @return void 반환값 없음
     *
     * @throws MileageValidationException 비회원 주문 (서버 가드가 앞서 막는다 — 여기까지 오면 계약 위반)
     */
    public function handleRefundCredit(float $amount, Order $order, int $orderRefundId): void
    {
        if ($amount <= 0) {
            return;
        }

        // 조용히 넘기면 돈이 사라진다(refund 274) — 비회원은 지급할 원장이 없으니 실패로 알린다.
        if ($order->user_id === null) {
            throw new MileageValidationException(__('sirsoft-ecommerce::exceptions.claim_refund_points_requires_member'));
        }

        $currency = $this->mileageService->baseCurrencyForOrder($order);
        $this->mileageService->creditForRefund($order->user_id, $order->id, $orderRefundId, $amount, $currency);
    }

    /**
     * 구매확정 후 적립 (trigger=confirmed + delay=0 즉시 적립, delay>0 은 스케줄러 담당)
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문옵션
     * @return void 반환값 없음
     */
    public function handleAfterConfirm(Order $order, OrderOption $option): void
    {
        if ($this->earnTrigger() !== MileageEarnTriggerEnum::CONFIRMED) {
            return;
        }

        if ($this->earnDelayDays() > 0) {
            // 지연 적립은 스케줄러(earn-mileage)가 담당
            return;
        }

        $this->earn($order, $option);
    }

    /**
     * 상태 변경 후 (배송완료 진입 적립 / 취소 전이 시 적립 회수)
     *
     * @param  OrderOption  $option  주문옵션 (변경 전 원본)
     * @param  OrderStatusEnum  $newStatus  변경된 상태
     * @param  OrderOption|null  $split  분할 레코드
     * @return void 반환값 없음
     */
    public function handleAfterStatusChange(OrderOption $option, OrderStatusEnum $newStatus, ?OrderOption $split = null): void
    {
        // 분할이 발생한 경우 새 상태는 분할 레코드가 보유
        $target = $split ?? $option;
        $this->processStatusChange($target, $newStatus);
    }

    /**
     * 일괄 상태 변경 후 (id 배열 재조회 후 개별 처리)
     *
     * @param  array  $results  변경 결과 [{order_option_id, split_order_option_id, ...}]
     * @param  OrderStatusEnum  $newStatus  변경된 상태
     * @return void 반환값 없음
     */
    public function handleAfterBulkStatusChange(array $results, OrderStatusEnum $newStatus): void
    {
        $targetIds = [];
        foreach ($results as $row) {
            $targetId = $row['split_order_option_id'] ?? $row['order_option_id'] ?? null;
            if ($targetId !== null) {
                $targetIds[] = $targetId;
            }
        }

        if (empty($targetIds)) {
            return;
        }

        $options = $this->orderOptionRepository->findByIdsKeyed($targetIds);
        foreach ($options as $option) {
            $this->processStatusChange($option, $newStatus);
        }
    }

    /**
     * 상태 변경에 따른 적립/회수 분기 처리.
     *
     * @param  OrderOption  $option  대상 주문옵션
     * @param  OrderStatusEnum  $newStatus  변경된 상태
     * @return void 반환값 없음
     */
    private function processStatusChange(OrderOption $option, OrderStatusEnum $newStatus): void
    {
        $order = $option->order;
        if ($order === null) {
            return;
        }

        // 배송완료 진입: trigger=delivered + delay=0 즉시 적립
        if ($newStatus === OrderStatusEnum::DELIVERED
            && $this->earnTrigger() === MileageEarnTriggerEnum::DELIVERED
            && $this->earnDelayDays() === 0) {
            $this->earn($order, $option);

            return;
        }

        // 취소 전이: 기적립 건 회수.
        // 취소는 옵션 단위로 처리되므로 회수도 옵션(CANCELLED) 기준이다 — 부분취소든 전체취소든
        // 취소된 옵션마다 이 분기를 타 개별 회수된다(별도 partial_cancelled 상태 불요). 2026-06-22
        if ($newStatus === OrderStatusEnum::CANCELLED) {
            $this->mileageService->cancelEarnForOption($order, $option);
        }
    }

    /**
     * 적립 처리.
     *
     * mileage.enabled 가 꺼져 있으면 즉시 적립도 발생시키지 않는다 — 지연 적립(스케줄러
     * EarnMileageCommand)이 enabled 게이트로 막히는 것과 미지급 기준으로 통일한다.
     * (기능 비활성 시 계산 완료건도 지급하지 않음 — 즉시/지연 경로 일관성 확보.)
     * 저장 적립액이 0 이면 Service 가 자연 no-op.
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문옵션
     * @return void 반환값 없음
     */
    private function earn(Order $order, OrderOption $option): void
    {
        if (! $this->earnEnabled()) {
            return;
        }

        try {
            $this->mileageService->earnForOrderOption($order, $option, MileageTransactionTypeEnum::PURCHASE_EARN);
        } catch (\Throwable $e) {
            // 적립 실패가 상태 전이를 막지 않게 하되 경고 로그로 흘리지 않고 보고한다(F4). 재시도는 스케줄러가 맡는다 —
            // 적립 대상 조회가 트리거 이후 상태까지 잡으므로(`MileageEarnTriggerEnum::eligibleStatuses()`), 실패 뒤 상품이
            // 구매확정으로 넘어가도 다음 실행에서 적립된다. 큐 기본값이 sync 드라이버면 다시 던질 경우 이미 커밋된 전이
            // 요청이 500 으로 끝나므로 던지지 않는다.
            report($e);
            Log::error('마일리지 즉시 적립 실패 — 스케줄러가 다시 적립', [
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 마일리지 기능 활성화 여부를 반환합니다.
     *
     * 스케줄러(EarnMileageCommand)의 enabled 게이트와 동일 기준 — 즉시/지연 적립 미지급 통일.
     *
     * @return bool 활성화 여부
     */
    private function earnEnabled(): bool
    {
        return (bool) $this->settings->getSetting('mileage.enabled', false);
    }

    /**
     * 설정된 적립 시점을 반환합니다.
     *
     * @return MileageEarnTriggerEnum 적립 시점
     */
    private function earnTrigger(): MileageEarnTriggerEnum
    {
        $value = (string) $this->settings->getSetting('mileage.earn_trigger', MileageEarnTriggerEnum::CONFIRMED->value);

        return MileageEarnTriggerEnum::tryFrom($value) ?? MileageEarnTriggerEnum::CONFIRMED;
    }

    /**
     * 설정된 적립 지연 일수를 반환합니다.
     *
     * @return int 지연 일수
     */
    private function earnDelayDays(): int
    {
        return (int) $this->settings->getSetting('mileage.earn_delay_days', 0);
    }
}
