<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\MessageBizppurio\Listeners\GuestPhoneExtractListener;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * GuestPhoneExtractListener — 비회원 주문 알림 data 에 수신 전화번호 주입 검증(D1).
 */
class GuestPhoneExtractListenerTest extends PluginTestCase
{
    private GuestPhoneExtractListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new GuestPhoneExtractListener;
    }

    /**
     * isGuestOrder 판정과 배송지 orderer_phone 을 지정할 수 있는 Order 더블을 만듭니다.
     *
     * 리스너가 이커머스 관행대로 `$order->shippingAddress?->orderer_phone` 를 직접 읽으므로,
     * shippingAddress 관계 접근자와 그 orderer_phone 속성을 함께 stub 한다. Eloquent 이벤트
     * 재인스턴스화(new static)와 충돌하지 않도록 서브클래스 대신 Mockery partial mock 을 쓴다.
     */
    private function orderDouble(bool $guest, ?string $phone): Order
    {
        $order = \Mockery::mock(Order::class)->makePartial();
        $order->shouldReceive('isGuestOrder')->andReturn($guest);

        // 배송지 관계 접근(getAttribute('shippingAddress'))이 orderer_phone 을 가진 객체를 반환
        $address = $phone === null ? null : (object) ['orderer_phone' => $phone];
        $order->shouldReceive('getAttribute')->with('shippingAddress')->andReturn($address);

        return $order;
    }

    private function makeResult(array $data = []): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => $data, 'context' => []];
    }

    public function test_비회원_주문알림에_전화번호가_주입된다(): void
    {
        $order = $this->orderDouble(guest: true, phone: '010-1234-5678');

        $out = $this->listener->injectGuestPhone(
            $this->makeResult(['name' => '홍길동']),
            'order_confirmed',
            [$order],
        );

        $this->assertSame('010-1234-5678', $out['data'][SmsChannelDriver::RECIPIENT_PHONE_KEY]);
        $this->assertSame('홍길동', $out['data']['name'], '기존 data 는 보존되어야 한다.');
    }

    public function test_회원_주문은_전화번호를_주입하지_않는다(): void
    {
        $order = $this->orderDouble(guest: false, phone: '010-1234-5678');

        $out = $this->listener->injectGuestPhone($this->makeResult(), 'order_confirmed', [$order]);

        $this->assertArrayNotHasKey(SmsChannelDriver::RECIPIENT_PHONE_KEY, $out['data']);
    }

    public function test_주문알림이_아니면_원본을_통과시킨다(): void
    {
        $order = $this->orderDouble(guest: true, phone: '010-1234-5678');

        $out = $this->listener->injectGuestPhone($this->makeResult(['x' => 1]), 'inquiry_replied', [$order]);

        $this->assertArrayNotHasKey(SmsChannelDriver::RECIPIENT_PHONE_KEY, $out['data']);
    }

    public function test_전화번호가_없으면_주입하지_않는다(): void
    {
        $order = $this->orderDouble(guest: true, phone: null);

        $out = $this->listener->injectGuestPhone($this->makeResult(), 'order_shipped', [$order]);

        $this->assertArrayNotHasKey(SmsChannelDriver::RECIPIENT_PHONE_KEY, $out['data']);
    }

    public function test_구독_훅과_filter_타입을_선언한다(): void
    {
        $hooks = GuestPhoneExtractListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.notification.extract_data', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.notification.extract_data']['type']);
    }
}
