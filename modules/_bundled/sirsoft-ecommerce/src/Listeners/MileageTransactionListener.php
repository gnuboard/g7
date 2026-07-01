<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\MileageEarnTriggerEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 마일리지 거래 리스너 (적립/사용/복원/회수 통합 진입점)
 *
 * 모든 처리는 UserMileageService 에 위임하며, 잔액 캐시 재계산은 Service 내부에서
 * 동일 트랜잭션으로 수행됩니다 (리스너는 캐시를 직접 다루지 않음).
 * mileage.earn(결제완료) 훅은 구독하지 않습니다 — 트리거는 구매확정/배송완료(이중 적립 방지).
 */
class MileageTransactionListener implements HookListenerInterface
{
    /**
     * @param  UserMileageService  $mileageService  마일리지 서비스
     * @param  EcommerceSettingsService  $settings  환경설정 서비스
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
            'sirsoft-ecommerce.mileage.use' => ['method' => 'handleUse', 'priority' => 10],
            'sirsoft-ecommerce.mileage.restore' => ['method' => 'handleRestore', 'priority' => 10],
            'sirsoft-ecommerce.order-option.after_confirm' => ['method' => 'handleAfterConfirm', 'priority' => 10],
            'sirsoft-ecommerce.order_option.after_status_change' => ['method' => 'handleAfterStatusChange', 'priority' => 10],
            'sirsoft-ecommerce.order_option.after_bulk_status_change' => ['method' => 'handleAfterBulkStatusChange', 'priority' => 10],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void
    {
        // method 매핑을 사용하므로 직접 호출되지 않음
    }

    /**
     * 마일리지 사용 차감 (주문생성 트랜잭션 내) — 실패 시 예외로 주문 롤백
     *
     * @param  int  $usePoints  사용 마일리지
     * @param  Order  $order  주문
     */
    public function handleUse(int $usePoints, Order $order): void
    {
        if ($usePoints <= 0 || $order->user_id === null) {
            return;
        }

        $currency = $this->mileageService->baseCurrencyForOrder($order);
        $this->mileageService->deductFifo($order->user_id, $usePoints, $currency, $order);
    }

    /**
     * 사용 복원 (취소/환불) — order_cancel_id 멱등, 신규 lot
     *
     * @param  float  $amount  복원액
     * @param  Order  $order  주문
     * @param  int|null  $orderCancelId  취소 레코드 ID
     */
    public function handleRestore(float $amount, Order $order, ?int $orderCancelId = null): void
    {
        if ($amount <= 0 || $order->user_id === null || $orderCancelId === null) {
            return;
        }

        $currency = $this->mileageService->baseCurrencyForOrder($order);
        $this->mileageService->restoreForCancel($order->user_id, $order->id, $orderCancelId, $amount, $currency);
    }

    /**
     * 구매확정 후 적립 (trigger=confirmed + delay=0 즉시 적립, delay>0 은 스케줄러 담당)
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문옵션
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
        // 취소된 옵션마다 이 분기를 타 개별 회수된다(별도 partial_cancelled 상태 불요). PO 2026-06-22
        if ($newStatus === OrderStatusEnum::CANCELLED) {
            $this->mileageService->cancelEarnForOption($order, $option);
        }
    }

    /**
     * 적립 처리.
     *
     * mileage.enabled 토글은 주문 당시 적립액 "계산"(OrderCalculationService) 단계에서만 작용한다.
     * 리스너는 이미 계산·저장된 subtotal_earned_points_amount 를 실행할 뿐이므로 enabled 를 재검사하지 않는다.
     * (저장값이 0 이면 Service 가 자연 no-op — PO 확정: 계산 완료된 적립은 토글로 막지 않음)
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문옵션
     */
    private function earn(Order $order, OrderOption $option): void
    {
        try {
            $this->mileageService->earnForOrderOption($order, $option, MileageTransactionTypeEnum::PURCHASE_EARN);
        } catch (\Throwable $e) {
            // 적립 실패가 상태 전이를 막지 않도록 로깅 후 흡수 (스케줄러가 후속 재시도)
            Log::warning('마일리지 적립 실패', [
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'error' => $e->getMessage(),
            ]);
        }
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
