<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * HookListenerRegistrar 테스트
 *
 * 리스너 등록 시 큐/동기 분기와 filter 등록을 검증합니다.
 */
class HookListenerRegistrarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * 기본 Action 리스너가 큐 Job으로 디스패치되는지 검증합니다.
     */
    public function test_action_listener_dispatches_job_by_default(): void
    {
        Queue::fake();

        HookListenerRegistrar::register(StubActionListener::class);
        HookManager::doAction('test.registrar.action', 'hello');

        Queue::assertPushed(DispatchHookListenerJob::class, function ($job) {
            return $job->listenerClass === StubActionListener::class
                && $job->method === 'handleAction';
        });
    }

    /**
     * 디스패치된 Job이 현재 요청 컨텍스트(Auth/Locale/IP)를 함께 캡처하는지 검증합니다.
     *
     * 큐 워커는 별도 프로세스라 Auth가 사라지므로, 캡처 시점에 스냅샷을 함께
     * Job 페이로드로 전달해야 워커에서 복원 가능합니다.
     */
    public function test_dispatched_job_captures_request_context(): void
    {
        Queue::fake();

        $user = \App\Models\User::factory()->create();
        \Illuminate\Support\Facades\Auth::login($user);
        \Illuminate\Support\Facades\App::setLocale('ko');

        $request = \Illuminate\Http\Request::create('/api/admin/users', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);
        app()->instance('request', $request);

        HookListenerRegistrar::register(StubActionListener::class);
        HookManager::doAction('test.registrar.action', 'hello');

        Queue::assertPushed(DispatchHookListenerJob::class, function ($job) use ($user) {
            return $job->context['user_id'] === $user->id
                && $job->context['locale'] === 'ko'
                && $job->context['ip_address'] === '203.0.113.10'
                && $job->context['user_agent'] === 'TestAgent/1.0'
                && $job->context['path'] === 'api/admin/users';
        });
    }

    /**
     * sync: true 리스너가 동기로 실행되는지 검증합니다.
     */
    public function test_sync_listener_executes_immediately(): void
    {
        Queue::fake();

        HookListenerRegistrar::register(StubSyncListener::class);
        HookManager::doAction('test.registrar.sync');

        // Job이 디스패치되지 않아야 함
        Queue::assertNotPushed(DispatchHookListenerJob::class);
        // 동기 실행 확인
        $this->assertTrue(StubSyncListener::$executed);
    }

    /**
     * Filter 리스너가 동기로 실행되고 반환값이 정상 전달되는지 검증합니다.
     */
    public function test_filter_listener_executes_synchronously(): void
    {
        Queue::fake();

        HookListenerRegistrar::register(StubFilterListener::class);
        $result = HookManager::applyFilters('test.registrar.filter', 'original');

        $this->assertEquals('original_filtered', $result);
        Queue::assertNotPushed(DispatchHookListenerJob::class);
    }

    /**
     * 우선순위가 HookManager에 정확히 전달되는지 검증합니다.
     */
    public function test_priority_is_passed_to_hook_manager(): void
    {
        Queue::fake();
        $order = [];

        // 우선순위 5, 20인 두 리스너를 등록하여 순서 확인
        // StubPriorityListener는 sync: true로 실행 순서를 즉시 확인 가능
        HookListenerRegistrar::register(StubPriorityHighListener::class);
        HookListenerRegistrar::register(StubPriorityLowListener::class);

        StubPriorityHighListener::$order = &$order;
        StubPriorityLowListener::$order = &$order;

        HookManager::doAction('test.registrar.priority');

        $this->assertEquals(['high', 'low'], $order);
    }
}

// --- 테스트용 스텁 클래스 ---

class StubActionListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.action' => ['method' => 'handleAction', 'priority' => 10],
        ];
    }

    public function handle(...$args): void {}

    public function handleAction(string $value): void {}
}

class StubSyncListener implements HookListenerInterface
{
    public static bool $executed = false;

    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.sync' => ['method' => 'handleSync', 'priority' => 10, 'sync' => true],
        ];
    }

    public function handle(...$args): void {}

    public function handleSync(): void
    {
        self::$executed = true;
    }
}

class StubFilterListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.filter' => ['method' => 'handleFilter', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    public function handle(...$args): void {}

    public function handleFilter(string $value): string
    {
        return $value.'_filtered';
    }
}

class StubPriorityHighListener implements HookListenerInterface
{
    public static array $order = [];

    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.priority' => ['method' => 'handlePriority', 'priority' => 5, 'sync' => true],
        ];
    }

    public function handle(...$args): void {}

    public function handlePriority(): void
    {
        self::$order[] = 'high';
    }
}

class StubPriorityLowListener implements HookListenerInterface
{
    public static array $order = [];

    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.priority' => ['method' => 'handlePriority', 'priority' => 20, 'sync' => true],
        ];
    }

    public function handle(...$args): void {}

    public function handlePriority(): void
    {
        self::$order[] = 'low';
    }
}
