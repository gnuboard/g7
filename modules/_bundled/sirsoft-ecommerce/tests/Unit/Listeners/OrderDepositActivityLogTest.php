<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\ActivityLog;
use Modules\Sirsoft\Ecommerce\Listeners\OrderActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 입금 기록 활동 로그
 *
 * 관리자 주문 상세 [입금확인] 창에서 「결제완료로 처리」 없이 입금만 기록하면 활동 로그가 남지 않았다. 결제 이후 단계 주문이면
 * 주문의 결제금액, 미수금, 결제일이 함께 채워지는데도 누가 언제 채웠는지 활동 로그에서 찾을 수 없었다.
 */
class OrderDepositActivityLogTest extends ModuleTestCase
{
    /**
     * 입금 기록은 「입금 기록」 활동 로그를 남기고, 바뀐 주문 결제금액을 변경 내역으로 싣는다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_deposit_record_is_logged_with_changes
     *
     * @return void 반환값 없음
     */
    public function test_deposit_recorded_is_logged_with_changes(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-DEP-001', 'total_paid_amount' => 0, 'total_due_amount' => 53000]);
        $snapshot = $order->toArray();
        $order->update(['total_paid_amount' => 53000, 'total_due_amount' => 0]);

        app(OrderActivityLogListener::class)->handleOrderAfterDepositRecorded($order->fresh(), 53000.0, $snapshot);

        $log = ActivityLog::where('action', 'order.deposit_record')->where('loggable_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('sirsoft-ecommerce::activity_log.description.order_deposit_recorded', $log->description_key);
        $this->assertSame('ORD-DEP-001', $log->properties['order_number'] ?? null);
        $this->assertContains('total_paid_amount', array_column($log->changes ?? [], 'field'));
    }

    /**
     * 활동 로그 리스너가 입금 기록 훅을 구독한다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_deposit_record_is_logged_with_changes
     *
     * @return void 반환값 없음
     */
    public function test_listener_subscribes_to_deposit_recorded_hook(): void
    {
        $hooks = OrderActivityLogListener::getSubscribedHooks();

        $this->assertSame('handleOrderAfterDepositRecorded', $hooks['sirsoft-ecommerce.order.after_deposit_recorded']['method'] ?? null);
    }
}
