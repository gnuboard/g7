<?php

namespace Tests\Unit\Listeners\Dashboard;

use App\Events\GenericBroadcastEvent;
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
        // HookManager::broadcast 는 broadcasting.default 가 null/log 이면 dispatch 스킵.
        // 테스트에서 이벤트 발행 경로를 검증하려면 활성 드라이버 + host 설정 필요.
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);

        Event::fake([GenericBroadcastEvent::class]);

        $mockService = Mockery::mock(DashboardService::class);
        $mockService->shouldReceive('getStats')->once()->andReturn([
            'installed_modules' => ['total' => 5, 'active' => 3],
        ]);

        $listener = new DashboardModuleListener($mockService);
        $listener->handleModuleUpdate();

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) {
            return $event->channel === 'core.admin.dashboard'
                && $event->eventName === 'dashboard.stats.updated'
                && $event->payload['type'] === 'stats';
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
