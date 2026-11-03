<?php

namespace Tests\Unit\Events;

use App\Events\GenericBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\PureTestCase;

/**
 * GenericBroadcastEvent 단위 테스트
 *
 * HookManager 내부 전용 브로드캐스트 이벤트의 채널/이벤트명/페이로드 정확성을 검증합니다.
 */
class GenericBroadcastEventTest extends PureTestCase
{
    /**
     * broadcastOn()이 생성자 채널명에 private- 접두사가 붙은 PrivateChannel 하나를 돌려준다.
     *
     * @scenario case=channel
     *
     * @effects broadcast_on_returns_single_private_channel
     *
     * @return void 반환값 없음
     */
    public function test_broadcast_on_returns_private_channel(): void
    {
        $event = new GenericBroadcastEvent('core.admin.dashboard', 'dashboard.stats.updated', []);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-core.admin.dashboard', $channels[0]->name);
    }

    /**
     * broadcastAs()가 생성자에 지정한 이벤트명을 그대로 돌려준다.
     *
     * @scenario case=event_name
     *
     * @effects broadcast_as_returns_given_event_name
     *
     * @return void 반환값 없음
     */
    public function test_broadcast_as_returns_event_name(): void
    {
        $event = new GenericBroadcastEvent('user.notifications.123', 'notification.received');

        $this->assertEquals('notification.received', $event->broadcastAs());
    }

    /**
     * broadcastWith()가 생성자에 넘긴 payload 배열을 그대로 돌려준다.
     *
     * @scenario case=payload
     *
     * @effects broadcast_with_returns_given_payload
     *
     * @return void 반환값 없음
     */
    public function test_broadcast_with_returns_payload(): void
    {
        $payload = ['type' => 'stats', 'data' => ['users' => 50]];
        $event = new GenericBroadcastEvent('core.admin.dashboard', 'dashboard.stats.updated', $payload);

        $this->assertEquals($payload, $event->broadcastWith());
    }

    /**
     * payload 를 생략하면 broadcastWith()가 빈 배열을 돌려준다.
     *
     * @scenario case=payload
     *
     * @effects omitted_payload_defaults_to_empty_array
     *
     * @return void 반환값 없음
     */
    public function test_broadcast_with_empty_payload(): void
    {
        $event = new GenericBroadcastEvent('test.channel', 'test.event');

        $this->assertEquals([], $event->broadcastWith());
    }
}
