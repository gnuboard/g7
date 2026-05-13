<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\StockService;

/**
 * 주문 취소 시 재고 복원 리스너
 *
 * 주문이 취소되면 해당 주문의 상품 재고를 복원합니다.
 */
class StockRestoreListener implements HookListenerInterface
{
    public function __construct(
        protected StockService $stockService
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 주문 취소 후 재고 복원
            'sirsoft-ecommerce.order.after_cancel' => [
                'method' => 'restoreStock',
                'priority' => 5, // 높은 우선순위로 빠르게 처리
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param mixed ...$args 훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * 주문 취소 시 재고 복원
     *
     * @param Order $order 취소된 주문
     * @return void
     */
    public function restoreStock(Order $order): void
    {
        try {
            // 옵션 관계 로드 확인
            if (! $order->relationLoaded('options')) {
                $order->load('options');
            }

            // 복원 대상 카운트 (차감된 옵션만 복원됨)
            $deductedCount = $order->options->where('is_stock_deducted', true)->count();

            // 재고 복원
            $this->stockService->restoreStock($order);

            Log::info('StockRestoreListener: 주문 취소 재고 복원 완료', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'option_count' => $order->options->count(),
                'restored_count' => $deductedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('StockRestoreListener: 재고 복원 실패', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
