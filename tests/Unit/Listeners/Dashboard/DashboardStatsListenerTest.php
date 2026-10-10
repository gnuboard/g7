<?php

namespace Tests\Unit\Listeners\Dashboard;

use App\Events\GenericBroadcastEvent;
use App\Extension\HookManager;
use App\Listeners\Dashboard\DashboardStatsListener;
use App\Models\User;
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
     *
     * @scenario trigger=admin_user_change
     *
     * @effects dashboard_stats_broadcast
     *
     * @return void 반환값 없음
     */
    public function test_listener_broadcasts_stats_update(): void
    {
        // HookManager::broadcast 는 null/log 드라이버 또는 host 미설정 시 dispatch 스킵
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);

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
     * 회원이 스스로 가입해도 대시보드 통계가 갱신 방송된다.
     *
     * 셀프 가입은 core.user.after_create 가 아니라 core.auth.after_register 로 발화한다 — 관리자가 만든 회원만
     * 실시간 반영되던 결함(#138 후속 전수 점검 11번 ①). 핸들러를 직접 부르지 않고 실제 발화 이름으로 태운다.
     *
     * @scenario trigger=self_registration
     *
     * @effects dashboard_stats_broadcast
     *
     * @return void 반환값 없음
     */
    public function test_self_registration_broadcasts_stats_update(): void
    {
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);

        Event::fake([GenericBroadcastEvent::class]);

        $mockService = Mockery::mock(DashboardService::class);
        $mockService->shouldReceive('getStats')->andReturn(['total_users' => ['count' => 1]]);
        $this->app->instance(DashboardService::class, $mockService);

        HookManager::doAction('core.auth.after_register', new User, ['registration_data' => []]);

        Event::assertDispatched(GenericBroadcastEvent::class, fn ($event) => $event->eventName === 'dashboard.stats.updated');
    }

    /**
     * 리스너가 DashboardService에서 데이터를 가져오는지 테스트합니다.
     *
     * @scenario trigger=admin_user_change
     *
     * @effects dashboard_stats_payload_from_service
     *
     * @return void 반환값 없음
     */
    public function test_listener_fetches_data_from_dashboard_service(): void
    {
        // HookManager::broadcast 는 null/log 드라이버 또는 host 미설정 시 dispatch 스킵
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);

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

    /**
     * 목 기대값을 검증하고 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
