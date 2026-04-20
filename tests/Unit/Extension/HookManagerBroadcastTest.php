<?php

namespace Tests\Unit\Extension;

use App\Events\GenericBroadcastEvent;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * HookManager::broadcast() 테스트
 *
 * HookManager를 통한 WebSocket 브로드캐스트가 올바르게 동작하는지 검증합니다.
 */
class HookManagerBroadcastTest extends TestCase
{
    /**
     * broadcast() 호출 시 GenericBroadcastEvent가 dispatch되는지 테스트합니다.
     */
    public function test_broadcast_dispatches_generic_broadcast_event(): void
    {
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);
        Event::fake([GenericBroadcastEvent::class]);

        HookManager::broadcast('core.admin.dashboard', 'dashboard.stats.updated', [
            'type' => 'stats',
            'data' => ['users' => 100],
        ]);

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) {
            return $event->channel === 'core.admin.dashboard'
                && $event->eventName === 'dashboard.stats.updated'
                && $event->payload === ['type' => 'stats', 'data' => ['users' => 100]];
        });
    }

    /**
     * broadcast()에 빈 payload가 허용되는지 테스트합니다.
     */
    public function test_broadcast_allows_empty_payload(): void
    {
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);
        Event::fake([GenericBroadcastEvent::class]);

        HookManager::broadcast('core.user.notifications.123', 'notification.received');

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) {
            return $event->channel === 'core.user.notifications.123'
                && $event->eventName === 'notification.received'
                && $event->payload === [];
        });
    }

    /**
     * 드라이버가 null이면 브로드캐스트를 건너뛰는지 테스트합니다.
     */
    public function test_broadcast_skips_when_driver_is_null(): void
    {
        config(['broadcasting.default' => 'null']);
        Event::fake([GenericBroadcastEvent::class]);

        HookManager::broadcast('core.admin.dashboard', 'dashboard.stats.updated', ['type' => 'stats']);

        Event::assertNotDispatched(GenericBroadcastEvent::class);
    }

    /**
     * 드라이버가 log이면 브로드캐스트를 건너뛰는지 테스트합니다.
     */
    public function test_broadcast_skips_when_driver_is_log(): void
    {
        config(['broadcasting.default' => 'log']);
        Event::fake([GenericBroadcastEvent::class]);

        HookManager::broadcast('core.admin.dashboard', 'dashboard.stats.updated', ['type' => 'stats']);

        Event::assertNotDispatched(GenericBroadcastEvent::class);
    }

    /**
     * 호스트가 미설정이면 브로드캐스트를 건너뛰는지 테스트합니다.
     */
    public function test_broadcast_skips_when_host_is_empty(): void
    {
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => null]);
        Event::fake([GenericBroadcastEvent::class]);

        HookManager::broadcast('core.admin.dashboard', 'dashboard.stats.updated', ['type' => 'stats']);

        Event::assertNotDispatched(GenericBroadcastEvent::class);
    }

    /**
     * broadcast() 실패 시 예외가 전파되지 않고 로그만 기록되는지 테스트합니다.
     *
     * 테스트 환경에서는 reverb 자격증명이 비어 있어 Laravel BroadcastManager가
     * Pusher 인스턴스 생성 단계에서 예외를 던지며, HookManager의 catch 블록이
     * 이를 잡아 Log::warning으로 기록하는 동작을 검증합니다.
     */
    public function test_broadcast_failure_does_not_propagate_exception(): void
    {
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);
        // 자격증명은 비워 두어 Pusher 생성 시 예외가 발생하도록 함
        config(['broadcasting.connections.reverb.key' => null]);
        config(['broadcasting.connections.reverb.secret' => null]);
        config(['broadcasting.connections.reverb.app_id' => null]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === '브로드캐스트 실패 (Reverb 미실행 가능)'
                    && $context['channel'] === 'core.admin.dashboard'
                    && $context['event'] === 'dashboard.stats.updated'
                    && ! empty($context['error']);
            });

        // 예외가 전파되지 않아야 함 — 전파되면 아래 assertion 없이 테스트가 예외로 종료됨
        HookManager::broadcast('core.admin.dashboard', 'dashboard.stats.updated', [
            'type' => 'stats',
        ]);

        $this->assertTrue(true, '예외가 호출자로 전파되지 않음');
    }
}
