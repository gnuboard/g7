<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 주문 상태전이 알림 매핑 리스너 (A35 / A36 / D9 / D10)
 *
 * 주문 상태를 전이시키는 모든 경로(관리자 단건/일괄 수정, 옵션 동기화, 사용자 구매확정)가
 * 단일 전이 훅 `sirsoft-ecommerce.order.after_status_change` 를 발화하면, 이 리스너가
 * 현재 상태 → 구체 알림 훅으로 매핑 발화한다. 전이 상태가 추가되면 매핑 한 곳만 수정하면 된다.
 *
 * 옵션 레벨의 동일 패턴(order_option.after_status_change → MileageTransactionListener)을
 * 주문 레벨로 올린 것이다. `order.after_*`(마침표) 알림 훅은 알림 추출 필터만 구독하는
 * 순수 알림 훅이므로, 관리자 경로에서 발화시켜도 마일리지/재고 이중실행 위험이 없다.
 *
 * 결제완료(PAYMENT_COMPLETE)는 completePayment/finalizeZeroAmountPayment 가 이미
 * `order.after_confirm` 을 직접 발화하므로, 이 리스너는 관리자 상태변경 경로에서만 발화한다
 * (이중발화 회피 책임은 발화 측 — PG/0원 경로는 after_status_change 를 발화하지 않음).
 */
class OrderStatusNotificationListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.order.after_status_change' => ['method' => 'handleStatusChange', 'priority' => 10],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void
    {
        // method 매핑(handleStatusChange)을 사용하므로 직접 호출되지 않음
    }

    /**
     * 주문 상태전이 → 구체 알림 훅 매핑 발화.
     *
     * 큐 지연 안전성(N1): 이 리스너는 HookListenerInterface 자동발견으로 큐 디스패치된다
     * (DispatchHookListenerJob). 큐 워커가 잡 실행 시점에 $order 를 PK 로 DB 재조회하므로
     * (HookArgumentSerializer), 큐 적체 지연 중 주문이 또 전이되면 $order->order_status 가
     * "현재값"으로 읽혀 엉뚱한 알림 훅이 발화된다. 따라서 매핑 기준은 재로드된 모델 상태가 아니라
     * 발화 측이 전이 시점에 캡처해 넘긴 $targetStatus(직렬화 안전 스칼라)를 사용한다.
     * $targetStatus 가 null 인 레거시 호출은 모델 상태로 폴백한다.
     *
     * @param  Order  $order  전이된 주문 (데이터 추출용 — 재로드될 수 있음)
     * @param  string|null  $previousStatus  전이 전 order_status 값 (미전이 판정용)
     * @param  string|null  $targetStatus  전이 시점에 캡처한 목표 order_status 값 (알림 매핑 기준)
     */
    public function handleStatusChange(Order $order, ?string $previousStatus = null, ?string $targetStatus = null): void
    {
        // 알림 매핑 기준은 전이 시점 목표 상태(스칼라). 큐 재로드된 모델 상태가 아님 (N1).
        $current = $targetStatus ?? $order->order_status?->value;

        // 동일 상태(미전이) 재호출 시 미발화 — 중복 알림 방지
        if ($current === null || $current === $previousStatus) {
            return;
        }

        // 현재 상태 → 구체 알림 훅 매핑 (전이 상태 추가 시 이 한 곳만 수정)
        $hook = match ($current) {
            OrderStatusEnum::PAYMENT_COMPLETE->value => 'sirsoft-ecommerce.order.after_confirm',   // D9 — 결제완료 알림(order_confirmed)
            OrderStatusEnum::SHIPPING->value => 'sirsoft-ecommerce.order.after_ship',               // order_shipped
            OrderStatusEnum::DELIVERED->value => 'sirsoft-ecommerce.order.after_deliver',           // D3 신설 order_delivered
            OrderStatusEnum::CONFIRMED->value => 'sirsoft-ecommerce.order.after_complete',          // A36 order_completed
            default => null,
        };

        if ($hook !== null) {
            HookManager::doAction($hook, $order);
        }
    }
}
