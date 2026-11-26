<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CouponAutoIssueService;

/**
 * 첫구매 쿠폰 자동발급·회수 리스너
 *
 * 주문 상태가 바뀔 때마다 그 주문을 다시 판정한다. 목표 상태가 취소·반품완료면 회수, 그 밖이면 발급 판정이다.
 * 큐로 돈다(기본) — 판정은 언제나 지금의 DB 상태로 하므로 큐가 늦게 돌아도 취소된 주문에 쿠폰이 남지 않는다.
 */
class FirstPurchaseCouponListener implements HookListenerInterface
{
    /**
     * @param  CouponAutoIssueService  $autoIssueService  쿠폰 자동발급 서비스
     */
    public function __construct(
        protected CouponAutoIssueService $autoIssueService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.order.after_status_change' => ['method' => 'handleStatusChange', 'priority' => 40],
            'sirsoft-ecommerce.order.after_payment_complete' => ['method' => 'handleOrder', 'priority' => 40],
            'sirsoft-ecommerce.order.after_purchase_confirmed' => ['method' => 'handleOrder', 'priority' => 40],
            'sirsoft-ecommerce.order.after_cancel' => ['method' => 'handleCancel', 'priority' => 40],
        ];
    }

    /**
     * 기본 핸들러 (method 매핑 사용 — 직접 호출되지 않음)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // method 매핑을 사용
    }

    /**
     * 주문 상태 변경 후 판정 (order.after_status_change)
     *
     * @param  mixed  $order  주문
     * @param  mixed  $previousStatus  이전 상태
     * @param  mixed  $newStatus  새 상태
     * @return void 반환값 없음
     */
    public function handleStatusChange(mixed $order = null, mixed $previousStatus = null, mixed $newStatus = null): void
    {
        $orderId = $this->orderId($order);
        if ($orderId === null) {
            return;
        }

        $target = $newStatus instanceof \BackedEnum ? $newStatus->value : (string) $newStatus;
        if (in_array($target, [OrderStatusEnum::CANCELLED->value, OrderStatusEnum::RETURNED->value], true)) {
            $this->run(fn () => $this->autoIssueService->revokeFirstPurchase($orderId), $orderId);

            return;
        }

        $this->run(fn () => $this->autoIssueService->evaluateFirstPurchase($orderId), $orderId);
    }

    /**
     * 결제완료·구매확정 후 판정 (order.after_payment_complete · after_purchase_confirmed)
     *
     * @param  mixed  $order  주문
     * @return void 반환값 없음
     */
    public function handleOrder(mixed $order = null): void
    {
        $orderId = $this->orderId($order);
        if ($orderId !== null) {
            $this->run(fn () => $this->autoIssueService->evaluateFirstPurchase($orderId), $orderId);
        }
    }

    /**
     * 전체 취소 후 회수 (order.after_cancel)
     *
     * @param  mixed  $order  주문
     * @return void 반환값 없음
     */
    public function handleCancel(mixed $order = null): void
    {
        $orderId = $this->orderId($order);
        if ($orderId !== null) {
            $this->run(fn () => $this->autoIssueService->revokeFirstPurchase($orderId), $orderId);
        }
    }

    /**
     * 판정을 실행합니다. 실패해도 주문 흐름에 영향을 주지 않는다.
     *
     * @param  \Closure  $callback  실행할 판정
     * @param  int  $orderId  주문 ID
     * @return void 반환값 없음
     */
    private function run(\Closure $callback, int $orderId): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('첫구매 쿠폰 판정 실패', [
                'order_id' => $orderId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 훅 인수에서 주문 ID 를 꺼냅니다.
     *
     * @param  mixed  $order  주문 모델 또는 ID
     * @return int|null 주문 ID
     */
    private function orderId(mixed $order): ?int
    {
        if ($order instanceof Order) {
            return (int) $order->id;
        }

        return is_numeric($order) ? (int) $order : null;
    }
}
