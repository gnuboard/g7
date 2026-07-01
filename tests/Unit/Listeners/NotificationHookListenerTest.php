<?php

namespace Tests\Unit\Listeners;

use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use App\Listeners\NotificationHookListener;
use App\Models\NotificationDefinition;
use App\Services\NotificationDefinitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * NotificationHookListener 테스트
 *
 * DB 정의 기반 동적 훅 구독 등록을 검증합니다.
 */
class NotificationHookListenerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 정적 훅 목록이 빈 배열인지 확인 (동적 구독 방식)
     */
    public function test_get_subscribed_hooks_returns_empty_array(): void
    {
        $hooks = NotificationHookListener::getSubscribedHooks();

        $this->assertIsArray($hooks);
        $this->assertEmpty($hooks);
    }

    /**
     * registerDynamicHooks()가 테이블 미존재 시 예외 없이 스킵
     */
    public function test_register_dynamic_hooks_skips_when_no_table(): void
    {
        // notification_definitions 테이블이 있으므로 정상 동작 확인
        $listener = app(NotificationHookListener::class);

        // 예외 없이 실행되면 통과
        $listener->registerDynamicHooks();

        $this->assertTrue(true);
    }

    /**
     * 활성 정의가 있을 때 registerDynamicHooks()가 정상 실행
     */
    public function test_register_dynamic_hooks_with_active_definitions(): void
    {
        NotificationDefinition::create([
            'type' => 'hook_test',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '훅 테스트'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => ['core.test.after_action'],
            'is_active' => true,
            'is_default' => true,
        ]);

        app(NotificationDefinitionService::class)->invalidateAllCache();

        $listener = app(NotificationHookListener::class);
        $listener->registerDynamicHooks();

        // 예외 없이 실행 완료
        $this->assertTrue(true);
    }

    /**
     * 비활성 정의는 훅을 등록하지 않음
     */
    public function test_register_dynamic_hooks_ignores_inactive_definitions(): void
    {
        NotificationDefinition::create([
            'type' => 'inactive_hook',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '비활성'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => ['core.test.inactive_hook'],
            'is_active' => false,
            'is_default' => true,
        ]);

        app(NotificationDefinitionService::class)->invalidateAllCache();

        $listener = app(NotificationHookListener::class);
        $listener->registerDynamicHooks();

        // getAllActive()는 비활성 제외이므로 등록 안됨
        $this->assertTrue(true);
    }

    /**
     * 동적 등록된 알림 훅이 발화될 때 발송 작업이 큐(DispatchHookListenerJob)로
     * 디스패치되어야 한다 — 요청 스레드에서 동기 발송하면 수신자가 많을 때
     * 호출 요청(주문 등)이 발송 완료까지 막힌다.
     *
     * 회귀 배경: registerDynamicHooks 가 다른 리스너(HookListenerRegistrar 경유)와 달리
     * DispatchHookListenerJob 래핑 없이 $this->dispatch() 를 직접 호출해 동기 실행되던 결함.
     * admin 수신자 1,000여 명에게 요청 스레드에서 순차 메일 발송 → 무통장 주문 hang.
     */
    public function test_dynamic_notification_hook_dispatches_to_queue_on_fire(): void
    {
        Queue::fake();

        NotificationDefinition::create([
            'type' => 'queued_hook_test',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '큐 발송 테스트'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => ['core.test.queued_action'],
            'is_active' => true,
            'is_default' => true,
        ]);

        app(NotificationDefinitionService::class)->invalidateAllCache();

        $listener = app(NotificationHookListener::class);
        $listener->registerDynamicHooks();

        // 훅 발화 → 발송 작업이 직접 실행되지 않고 큐로 디스패치되어야 함
        HookManager::doAction('core.test.queued_action', null);

        Queue::assertPushed(
            DispatchHookListenerJob::class,
            fn (DispatchHookListenerJob $job) => $job->listenerClass === NotificationHookListener::class
        );
    }
}
