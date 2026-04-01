<?php

namespace Tests\Unit\Listeners\Dashboard;

use App\Events\Dashboard\DashboardUpdated;
use App\Listeners\Dashboard\DashboardModuleListener;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * DashboardModuleListener 테스트
 */
class DashboardModuleListenerTest extends TestCase
{
    /**
     * 리스너가 모듈 업데이트 시 통계를 브로드캐스트하는지 테스트합니다.
     */
    public function test_listener_broadcasts_stats_on_module_change(): void
    {
        Event::fake([DashboardUpdated::class]);

        $mockService = Mockery::mock(DashboardService::class);
        $mockService->shouldReceive('getStats')->once()->andReturn([
            'installed_modules' => ['total' => 5, 'active' => 3],
        ]);

        $listener = new DashboardModuleListener($mockService);
        $listener->handle();

        Event::assertDispatched(DashboardUpdated::class, function ($event) {
            return $event->type === 'stats';
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
