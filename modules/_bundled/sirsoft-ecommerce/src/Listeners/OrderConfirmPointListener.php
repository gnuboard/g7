<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;

/**
 * 구매확정 시 포인트 적립 리스너 (더미)
 *
 * 구매확정 완료 시 적립 포인트를 지급합니다.
 * 현재는 로그만 남기며, 향후 포인트 모듈에서 실제 적립 처리를 구현합니다.
 */
class OrderConfirmPointListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.order-option.after_confirm' => [
                'method' => 'handlePointReward',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks에서 method 매핑 사용)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void
    {
        // getSubscribedHooks()에서 method 매핑을 사용하므로 직접 호출되지 않음
    }

    /**
     * 구매확정 후 포인트 적립 처리 (더미)
     *
     * @param Order $order 주문 모델
     * @param OrderOption $option 확정된 주문 옵션
     * @return void
     */
    public function handlePointReward(Order $order, OrderOption $option): void
    {
        $earnedPoints = $option->subtotal_earned_points_amount ?? 0;

        Log::info('[포인트 적립 더미] 구매확정 포인트 적립 예정', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'option_id' => $option->id,
            'user_id' => $order->user_id,
            'earned_points' => $earnedPoints,
        ]);

        // TODO: 포인트 모듈 구현 시 실제 적립 로직으로 교체
        // PointService::credit($order->user_id, $earnedPoints, '구매확정 포인트 적립', $option);
    }
}
