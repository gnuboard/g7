<?php

namespace Tests\Unit\Events;

use App\Events\GenericBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

/**
 * GenericBroadcastEvent 단위 테스트
 *
 * HookManager 내부 전용 브로드캐스트 이벤트의 채널/이벤트명/페이로드 정확성을 검증합니다.
 */
class GenericBroadcastEventTest extends TestCase
{
    /**
     * broadcastOn()이 올바른 Private 채널을 반환하는지 테스트합니다.
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
     * broadcastAs()가 지정한 이벤트명을 반환하는지 테스트합니다.
     */
    public function test_broadcast_as_returns_event_name(): void
    {
        $event = new GenericBroadcastEvent('user.notifications.123', 'notification.received');

        $this->assertEquals('notification.received', $event->broadcastAs());
    }

    /**
     * broadcastWith()가 payload를 정확히 반환하는지 테스트합니다.
     */
    public function test_broadcast_with_returns_payload(): void
    {
        $payload = ['type' => 'stats', 'data' => ['users' => 50]];
        $event = new GenericBroadcastEvent('core.admin.dashboard', 'dashboard.stats.updated', $payload);

        $this->assertEquals($payload, $event->broadcastWith());
    }

    /**
     * 빈 payload가 빈 배열로 반환되는지 테스트합니다.
     */
    public function test_broadcast_with_empty_payload(): void
    {
        $event = new GenericBroadcastEvent('test.channel', 'test.event');

        $this->assertEquals([], $event->broadcastWith());
    }
}
