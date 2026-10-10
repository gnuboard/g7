<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Notifications;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 취소 알림의 「취소 사유」는 고른 사유의 이름을 싣는다 (S3-D8, 2026-10-07 실측)
 *
 * 취소 기록은 고른 사유를 코드(`cancel_reason_type`)로, 「기타」의 직접 입력만 `cancel_reason` 에 남긴다.
 * 알림 데이터가 `cancel_reason` 만 읽어, 「단순 변심」 등 목록 사유로 취소한 주문의 메일이
 * 「취소 사유: 」 빈칸 뒤에 다음 문장이 붙은 채 나갔다(실측: 주문 20261006-1421322946 메일 본문).
 */
class OrderCancelledReasonNotificationTest extends ModuleTestCase
{
    /**
     * 사유 카탈로그에 「단순 변심」을 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        ClaimReason::firstOrCreate(
            ['type' => 'refund', 'code' => 'changed_mind'],
            [
                'name' => ['ko' => '단순 변심', 'en' => 'Changed mind'],
                'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );
    }

    /**
     * 취소 기록을 만든다.
     *
     * @param  int  $orderId  주문 ID
     * @param  string|null  $detail  직접 입력 사유
     * @return void 반환값 없음
     */
    private function cancel(int $orderId, ?string $detail): void
    {
        OrderCancel::create([
            'order_id' => $orderId,
            'cancel_number' => 'CN-REASON-'.$orderId,
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'changed_mind',
            'cancel_reason' => $detail,
            'items_snapshot' => [],
        ]);
    }

    /**
     * 알림 데이터를 뽑는다.
     *
     * @param  mixed  $order  주문
     * @return array 알림 데이터
     */
    private function extract($order): array
    {
        return app(EcommerceNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'order_cancelled',
            [$order],
        )['data'];
    }

    /**
     * 목록 사유로 취소하면 그 사유 이름이 실린다.
     *
     * @scenario case=order_cancelled_notification
     *
     * @effects order_cancelled_notification_names_the_reason
     *
     * @return void 반환값 없음
     */
    public function test_listed_reason_name_is_sent_when_no_detail(): void
    {
        $order = OrderFactory::new()->create();
        $this->cancel($order->id, null);

        $this->assertSame('단순 변심', $this->extract($order)['cancel_reason']);
    }

    /**
     * 직접 입력한 사유가 있으면 사유 이름과 함께 실린다.
     *
     * @scenario case=order_cancelled_notification
     *
     * @effects order_cancelled_notification_names_the_reason
     *
     * @return void 반환값 없음
     */
    public function test_detail_is_appended_to_reason_name(): void
    {
        $order = OrderFactory::new()->create();
        $this->cancel($order->id, '색상이 마음에 들지 않음');

        $this->assertSame('단순 변심 (색상이 마음에 들지 않음)', $this->extract($order)['cancel_reason']);
    }
}
