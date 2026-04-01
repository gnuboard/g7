<?php

namespace Tests\Unit\Events\Dashboard;

use App\Events\Dashboard\DashboardUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

/**
 * DashboardUpdated 이벤트 테스트
 */
class DashboardUpdatedTest extends TestCase
{
    /**
     * 이벤트가 private 채널로 브로드캐스트되는지 테스트합니다.
     */
    public function test_event_broadcasts_on_private_channel(): void
    {
        $event = new DashboardUpdated('stats', ['count' => 100]);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-admin.dashboard', $channels[0]->name);
    }

    /**
     * 이벤트가 올바른 브로드캐스트명을 가지는지 테스트합니다.
     */
    public function test_event_has_correct_broadcast_name(): void
    {
        $event = new DashboardUpdated('stats', ['count' => 100]);

        $this->assertEquals('dashboard.stats.updated', $event->broadcastAs());
    }

    /**
     * 이벤트에 타입과 데이터가 포함되는지 테스트합니다.
     */
    public function test_event_contains_type_and_data(): void
    {
        $data = ['count' => 100, 'trend' => 'up'];
        $event = new DashboardUpdated('stats', $data);

        $this->assertEquals('stats', $event->type);
        $this->assertEquals($data, $event->data);
    }

    /**
     * 다양한 타입의 이벤트가 올바른 브로드캐스트명을 생성하는지 테스트합니다.
     */
    public function test_different_types_generate_correct_broadcast_names(): void
    {
        $types = ['stats', 'resources', 'activities', 'modules', 'alerts'];

        foreach ($types as $type) {
            $event = new DashboardUpdated($type, []);
            $this->assertEquals("dashboard.{$type}.updated", $event->broadcastAs());
        }
    }
}
