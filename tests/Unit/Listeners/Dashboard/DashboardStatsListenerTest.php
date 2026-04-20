<?php

namespace Tests\Unit\Listeners\Dashboard;

use App\Events\GenericBroadcastEvent;
use App\Listeners\Dashboard\DashboardStatsListener;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * DashboardStatsListener 테스트
 */
class DashboardStatsListenerTest extends TestCase
{
    /**
     * 리스너가 통계 업데이트를 브로드캐스트하는지 테스트합니다.
     */
    public function test_listener_broadcasts_stats_update(): void
    {
        Event::fake([GenericBroadcastEvent::class]);

        $mockService = Mockery::mock(DashboardService::class);
        $mockService->shouldReceive('getStats')->once()->andReturn([
            'total_users' => ['count' => 100],
        ]);

        $listener = new DashboardStatsListener($mockService);
        $listener->handleStatsUpdate();

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) {
            return $event->channel === 'core.admin.dashboard'
                && $event->eventName === 'dashboard.stats.updated'
                && $event->payload['type'] === 'stats';
        });
    }

    /**
     * 리스너가 DashboardService에서 데이터를 가져오는지 테스트합니다.
     */
    public function test_listener_fetches_data_from_dashboard_service(): void
    {
        Event::fake([GenericBroadcastEvent::class]);

        $expectedData = [
            'total_users' => ['count' => 50, 'trend' => 'up'],
            'installed_modules' => ['total' => 3, 'active' => 2],
        ];

        $mockService = Mockery::mock(DashboardService::class);
        $mockService->shouldReceive('getStats')->once()->andReturn($expectedData);

        $listener = new DashboardStatsListener($mockService);
        $listener->handleStatsUpdate();

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) use ($expectedData) {
            return $event->payload['type'] === 'stats'
                && $event->payload['data'] === $expectedData;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
