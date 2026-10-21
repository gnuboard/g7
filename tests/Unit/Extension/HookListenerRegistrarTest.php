<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use App\Models\User;
use App\Services\Queue\QueueWorkerRegistry;
use App\Support\Queue\ThrottledQueueWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * HookListenerRegistrar 테스트
 *
 * 리스너 등록 시 큐/동기 분기와 filter 등록을 검증합니다.
 *
 * @effects cache_registered_action_dispatches_queue_job,
 *   cache_registered_sync_action_executes_immediately,
 *   cache_registered_filter_executes_synchronously_and_returns_value
 */
class HookListenerRegistrarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비를 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
        HookListenerRegistrar::clear();
    }

    /**
     * 테스트 뒤 정리를 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        parent::tearDown();
    }

    /**
     * 기본 Action 리스너가 큐 Job으로 디스패치되는지 검증합니다.
     *
     * @scenario declaration=none
     *
     * @effects action_listener_queued_by_default
     *
     * @return void 반환값 없음
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
     *
     * @scenario declaration=none
     *
     * @effects request_context_captured
     *
     * @return void 반환값 없음
     */
    public function test_dispatched_job_captures_request_context(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Auth::login($user);
        App::setLocale('ko');

        $request = Request::create('/api/admin/users', 'GET', [], [], [], [
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
     *
     * @scenario declaration=sync_only
     *
     * @effects sync_listener_runs_immediately
     *
     * @return void 반환값 없음
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
     *
     * @scenario declaration=filter_only
     *
     * @effects filter_listener_runs_synchronously
     *
     * @return void 반환값 없음
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
     * 동일 listener 를 동일 source 로 2회 등록해도 hook callback 이 1번만 추가되는지 검증합니다.
     *
     * 회귀 차단 (이슈 #204):
     * 테스트 환경에서 매 setUp 시 ServiceProvider boot 가 다시 호출되며 ModuleManager
     * loadModules → registerModuleHookListeners → HookListenerRegistrar::register 가
     * 매번 호출되어, HookManager::$hooks 에 동일 listener callback 이 누적됨.
     * 100+ 테스트 누적 시 hook 카운트 폭증으로 doAction 가 hang 발생.
     *
     * register() 가 process-wide idempotency 를 보장해야 한다.
     *
     * @scenario declaration=none
     *
     * @effects registration_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_register_is_idempotent_per_source_and_listener(): void
    {
        HookListenerRegistrar::clear();

        // 1차 등록
        HookListenerRegistrar::register(StubSyncListener::class, 'test-source');

        $countAfterFirst = $this->countRegisteredCallbacks('test.registrar.sync');
        $this->assertSame(1, $countAfterFirst, '첫 등록 후 callback 은 1개');

        // 동일 source + 동일 listener 로 2차 등록
        HookListenerRegistrar::register(StubSyncListener::class, 'test-source');

        $countAfterSecond = $this->countRegisteredCallbacks('test.registrar.sync');
        $this->assertSame(1, $countAfterSecond, '동일 source 로 재등록해도 callback 은 1개 (누적 차단)');

        // 동일 source + 동일 listener 로 100차 등록
        for ($i = 0; $i < 100; $i++) {
            HookListenerRegistrar::register(StubSyncListener::class, 'test-source');
        }

        $countAfter100 = $this->countRegisteredCallbacks('test.registrar.sync');
        $this->assertSame(1, $countAfter100, '100회 재등록해도 callback 은 1개');
    }

    /**
     * 다른 source 로 같은 listener 를 등록하면 별개로 등록되는지 검증합니다.
     *
     * 모듈/플러그인이 동일 listener 클래스를 공유하는 경우는 드물지만,
     * 가능성을 열어두기 위해 source 별로 idempotency cache 분리.
     *
     * @scenario declaration=none
     *
     * @effects registration_per_source
     *
     * @return void 반환값 없음
     */
    public function test_register_allows_same_listener_for_different_sources(): void
    {
        HookListenerRegistrar::clear();

        HookListenerRegistrar::register(StubSyncListener::class, 'source-a');
        HookListenerRegistrar::register(StubSyncListener::class, 'source-b');

        $count = $this->countRegisteredCallbacks('test.registrar.sync');
        $this->assertSame(2, $count, '서로 다른 source 는 별개로 등록');
    }

    /**
     * clear() 호출 후 다시 등록 가능한지 검증합니다.
     *
     * 모듈 install/uninstall 테스트 시나리오에서 listener 재등록을 허용하기 위함.
     *
     * @scenario declaration=none
     *
     * @effects clear_allows_reregistration
     *
     * @return void 반환값 없음
     */
    public function test_clear_allows_reregistration(): void
    {
        HookListenerRegistrar::clear();

        HookListenerRegistrar::register(StubSyncListener::class, 'test-source');
        HookListenerRegistrar::register(StubSyncListener::class, 'test-source');
        $this->assertSame(1, $this->countRegisteredCallbacks('test.registrar.sync'));

        // 캐시 클리어 + HookManager 도 클리어 (모듈 uninstall 시뮬)
        HookListenerRegistrar::clear();
        HookManager::resetAll();

        // 재등록 시 callback 이 다시 추가되어야 함
        HookListenerRegistrar::register(StubSyncListener::class, 'test-source');
        $this->assertSame(1, $this->countRegisteredCallbacks('test.registrar.sync'));
    }

    /**
     * HookManager::$hooks 에서 특정 hookName 의 callback 수를 합산합니다.
     *
     * @param  string  $hookName  훅 이름
     * @return int callback 수
     */
    private function countRegisteredCallbacks(string $hookName): int
    {
        $ref = new \ReflectionClass(HookManager::class);
        $hooksProp = $ref->getProperty('hooks');
        $hooksProp->setAccessible(true);
        $hooks = $hooksProp->getValue();

        if (! isset($hooks[$hookName])) {
            return 0;
        }

        $count = 0;
        foreach ($hooks[$hookName] as $perPriority) {
            $count += count($perPriority);
        }

        return $count;
    }

    /**
     * 우선순위가 HookManager에 정확히 전달되는지 검증합니다.
     *
     * @scenario declaration=none
     *
     * @effects priority_forwarded
     *
     * @return void 반환값 없음
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

    /**
     * 큐 옵션을 선언하지 않은 리스너의 잡은 종전 기본값 그대로다 (#139).
     *
     * @scenario declaration=none
     *
     * @effects legacy_defaults_preserved
     *
     * @return void 반환값 없음
     */
    public function test_listener_without_queue_options_keeps_legacy_job_defaults(): void
    {
        Queue::fake();

        HookListenerRegistrar::register(StubActionListener::class);
        HookManager::doAction('test.registrar.action', 'hello');

        Queue::assertPushed(DispatchHookListenerJob::class, function (DispatchHookListenerJob $job) {
            return $job->connection === null
                && $job->queue === null
                && $job->timeout === null
                && $job->backoff === null
                && $job->retryUntil === null
                && $job->maxExceptions === null
                && $job->delay === null
                && $job->tries === 3
                && $job->afterCommit === true
                && $job->failOnTimeout === false;
        });
    }

    /**
     * 선언한 10개 옵션이 모두 잡에 적용된다 (#139).
     *
     * @scenario declaration=all_keys
     *
     * @effects options_applied_to_job
     *
     * @return void 반환값 없음
     */
    public function test_queue_options_are_applied_to_dispatched_job(): void
    {
        Queue::fake();
        $this->registerQueues(['database'], ['database' => ['default', 'notifications']]);
        $this->travelTo(now()->startOfMinute());

        HookListenerRegistrar::register(StubQueueOptionsListener::class);
        HookManager::doAction('test.registrar.queue_options');

        Queue::assertPushed(DispatchHookListenerJob::class, function (DispatchHookListenerJob $job) {
            return $job->connection === 'database'
                && $job->queue === 'notifications'
                && $job->delay === 5
                && $job->afterCommit === false
                && $job->tries === 5
                && $job->timeout === 60
                && $job->backoff === [10, 20]
                && $job->retryUntil?->getTimestamp() === now()->addSeconds(600)->getTimestamp()
                && $job->maxExceptions === 2
                && $job->failOnTimeout === true;
        });
    }

    /**
     * 등록하지 않은 큐를 가리키면 그 연결의 기본 큐로 보내고 경고한다 (#139).
     *
     * @scenario placement=queue_unregistered
     *
     * @effects unregistered_queue_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_queue_falls_back_to_connection_default_queue_with_warning(): void
    {
        Queue::fake();
        Log::spy();
        $this->registerQueues(['database'], ['database' => ['default']]);

        HookListenerRegistrar::registerDynamicAction('test.registrar.unreg_queue', StubDynamicListener::class, 'run', queueOptions: ['queue' => 'nope']);
        HookManager::doAction('test.registrar.unreg_queue');

        Queue::assertPushed(DispatchHookListenerJob::class, fn (DispatchHookListenerJob $job) => $job->queue === null && $job->connection === null);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx) => in_array('queue_unregistered', $ctx['reasons'] ?? [], true))->once();
    }

    /**
     * 등록하지 않은 연결을 가리키면 기본 연결로 보내고 경고한다 (#139).
     *
     * @scenario placement=connection_unregistered
     *
     * @effects unregistered_connection_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_connection_falls_back_to_default_connection_with_warning(): void
    {
        Queue::fake();
        Log::spy();
        $this->registerQueues(['database'], ['database' => ['default']]);

        HookListenerRegistrar::registerDynamicAction('test.registrar.unreg_conn', StubDynamicListener::class, 'run', queueOptions: ['connection' => 'redis']);
        HookManager::doAction('test.registrar.unreg_conn');

        Queue::assertPushed(DispatchHookListenerJob::class, fn (DispatchHookListenerJob $job) => $job->connection === null);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx) => in_array('connection_unregistered', $ctx['reasons'] ?? [], true))->once();
    }

    /**
     * 등록된 연결·큐는 그대로 쓴다 (#139).
     *
     * @scenario placement=registered
     *
     * @effects registered_pair_honored
     *
     * @return void 반환값 없음
     */
    public function test_registered_connection_and_queue_are_honored(): void
    {
        Queue::fake();
        $this->registerQueues(['database', 'redis'], ['database' => ['default'], 'redis' => ['high']]);

        HookListenerRegistrar::registerDynamicAction('test.registrar.registered', StubDynamicListener::class, 'run', queueOptions: ['connection' => 'redis', 'queue' => 'high']);
        HookManager::doAction('test.registrar.registered');

        Queue::assertPushed(DispatchHookListenerJob::class, fn (DispatchHookListenerJob $job) => $job->connection === 'redis' && $job->queue === 'high');
    }

    /**
     * timeout 이 연결의 retry_after 이상이면 retry_after − 10 으로 줄이고 횟수를 센다 (#139).
     *
     * @scenario timeout=at_retry_after
     *
     * @effects timeout_clamped_and_counted
     *
     * @return void 반환값 없음
     */
    public function test_timeout_at_or_above_retry_after_is_clamped_and_counted(): void
    {
        Queue::fake();
        $this->registerQueues(['database'], ['database' => ['default']]);
        config(['queue.connections.database.retry_after' => 90]);

        HookListenerRegistrar::registerDynamicAction('test.registrar.clamp', StubDynamicListener::class, 'run', queueOptions: ['timeout' => 90]);
        HookManager::doAction('test.registrar.clamp');

        Queue::assertPushed(DispatchHookListenerJob::class, fn (DispatchHookListenerJob $job) => $job->timeout === 80);
        $report = app(QueueWorkerRegistry::class)->statusReport();
        $this->assertSame(1, $report['placement']['timeout_clamped']['count']);
        $this->assertSame(1, $report['rows'][0]['clamped_timeout_count']);
        $this->assertSame(80, $report['placement']['timeout_clamped']['last']['applied']);
    }

    /**
     * sync 구독에 큐 옵션을 적으면 경고하고 종전처럼 바로 실행한다 (#139).
     *
     * @scenario declaration=with_sync
     *
     * @effects options_ignored_for_sync_or_filter
     *
     * @return void 반환값 없음
     */
    public function test_sync_listener_with_queue_options_warns_and_runs_synchronously(): void
    {
        Queue::fake();
        Log::spy();
        StubSyncQueueOptionsListener::$executed = false;

        HookListenerRegistrar::register(StubSyncQueueOptionsListener::class);
        HookManager::doAction('test.registrar.sync_queue');

        $this->assertTrue(StubSyncQueueOptionsListener::$executed);
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * Filter 구독에 큐 옵션을 적으면 경고하고 필터로 동작한다 (#139).
     *
     * @scenario declaration=with_filter
     *
     * @effects options_ignored_for_sync_or_filter
     *
     * @return void 반환값 없음
     */
    public function test_filter_listener_with_queue_options_warns_and_stays_filter(): void
    {
        Queue::fake();
        Log::spy();

        HookListenerRegistrar::register(StubFilterQueueOptionsListener::class);
        $result = HookManager::applyFilters('test.registrar.filter_queue', 'a');

        $this->assertSame('a-filtered', $result);
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * 동적 구독은 마지막 인자로 큐 옵션을 받는다 — 기존 호출은 그대로 동작한다 (#139).
     *
     * @scenario declaration=dynamic
     *
     * @effects options_applied_to_job
     *
     * @return void 반환값 없음
     */
    public function test_dynamic_action_accepts_trailing_queue_options(): void
    {
        Queue::fake();

        HookListenerRegistrar::registerDynamicAction('test.registrar.dyn', StubDynamicListener::class, 'run', ['bound'], 10, false, ['tries' => 4]);
        HookListenerRegistrar::registerDynamicAction('test.registrar.dyn_legacy', StubDynamicListener::class, 'run', ['bound'], 10);
        HookManager::doAction('test.registrar.dyn');
        HookManager::doAction('test.registrar.dyn_legacy');

        Queue::assertPushed(DispatchHookListenerJob::class, 2);
        Queue::assertPushed(DispatchHookListenerJob::class, fn (DispatchHookListenerJob $job) => $job->tries === 4);
        Queue::assertPushed(DispatchHookListenerJob::class, fn (DispatchHookListenerJob $job) => $job->tries === 3 && $job->timeout === null);
    }

    /**
     * retry_until 은 디스패치 시점 기준으로 계산된다 (#139).
     *
     * @scenario declaration=retry_until
     *
     * @effects retry_until_at_dispatch
     *
     * @return void 반환값 없음
     */
    public function test_retry_until_is_computed_at_dispatch_time(): void
    {
        Queue::fake();
        HookListenerRegistrar::registerDynamicAction('test.registrar.retry_until', StubDynamicListener::class, 'run', queueOptions: ['retry_until' => 120]);

        $this->travelTo('2026-10-13 10:00:00');
        HookManager::doAction('test.registrar.retry_until');
        $this->travelTo('2026-10-13 11:00:00');
        HookManager::doAction('test.registrar.retry_until');

        $stamps = Queue::pushed(DispatchHookListenerJob::class)->map(fn (DispatchHookListenerJob $job) => $job->retryUntil?->format('H:i:s'))->values()->all();
        $this->assertSame(['10:02:00', '11:02:00'], $stamps);
    }

    /**
     * 같은 폴백 경고는 키마다 한 번만 남는다 (#139).
     *
     * @scenario placement=queue_unregistered
     *
     * @effects fallback_warning_throttled
     *
     * @return void 반환값 없음
     */
    public function test_fallback_warning_is_emitted_once_per_key(): void
    {
        Queue::fake();
        Log::spy();
        $this->registerQueues(['database'], ['database' => ['default']]);

        HookListenerRegistrar::registerDynamicAction('test.registrar.once', StubDynamicListener::class, 'run', queueOptions: ['queue' => 'nope']);
        HookManager::doAction('test.registrar.once');
        HookManager::doAction('test.registrar.once');
        HookManager::doAction('test.registrar.once');

        Queue::assertPushed(DispatchHookListenerJob::class, 3);
        Log::shouldHaveReceived('warning')->once();
        $this->assertSame(3, app(QueueWorkerRegistry::class)->statusReport()['placement']['fallback']['count']);
    }

    /**
     * 큐 드라이버를 database 로 두고 운영자 등록 연결·큐를 설정합니다.
     *
     * @param  array<int, string>  $connections  등록 연결
     * @param  array<string, array<int, string>>  $names  연결별 큐
     * @return void 반환값 없음
     */
    private function registerQueues(array $connections, array $names): void
    {
        config([
            'queue.default' => 'database',
            'g7_settings.core.drivers.queue_connections' => $connections,
            'g7_settings.core.drivers.queue_names' => $names,
        ]);
        ThrottledQueueWarning::reset();
    }
}

/**
 * 10개 큐 옵션을 모두 선언한 스텁 리스너 (#139)
 */
class StubQueueOptionsListener implements HookListenerInterface
{
    /**
     * 구독 훅을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 구독 훅
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.queue_options' => [
                'method' => 'handle',
                'queue' => [
                    'connection' => 'database',
                    'queue' => 'notifications',
                    'delay' => 5,
                    'after_commit' => false,
                    'tries' => 5,
                    'timeout' => 60,
                    'backoff' => [10, 20],
                    'retry_until' => 600,
                    'max_exceptions' => 2,
                    'fail_on_timeout' => true,
                ],
            ],
        ];
    }

    /**
     * 아무 일도 하지 않습니다.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}
}

/**
 * sync 와 큐 옵션을 함께 선언한 스텁 리스너 (#139)
 */
class StubSyncQueueOptionsListener implements HookListenerInterface
{
    /** @var bool 실행 여부 */
    public static bool $executed = false;

    /**
     * 구독 훅을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 구독 훅
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.sync_queue' => ['method' => 'run', 'sync' => true, 'queue' => ['tries' => 5]],
        ];
    }

    /**
     * 기본 핸들러.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 실행 여부를 남깁니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        self::$executed = true;
    }
}

/**
 * filter 와 큐 옵션을 함께 선언한 스텁 리스너 (#139)
 */
class StubFilterQueueOptionsListener implements HookListenerInterface
{
    /**
     * 구독 훅을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 구독 훅
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.filter_queue' => ['method' => 'filter', 'type' => 'filter', 'queue' => ['tries' => 5]],
        ];
    }

    /**
     * 기본 핸들러.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 값에 접미사를 붙입니다.
     *
     * @param  string  $value  값
     * @return string 접미사를 붙인 값
     */
    public function filter(string $value): string
    {
        return $value.'-filtered';
    }
}

/**
 * 동적 구독용 스텁 리스너 (#139)
 */
class StubDynamicListener implements HookListenerInterface
{
    /**
     * 구독 훅을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 구독 훅 (동적 등록이라 비어 있다)
     */
    public static function getSubscribedHooks(): array
    {
        return [];
    }

    /**
     * 기본 핸들러.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 동적 구독 진입점.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function run(...$args): void {}
}

// --- 테스트용 스텁 클래스 ---

class StubActionListener implements HookListenerInterface
{
    /**
     * 구독 훅 목록을 반환합니다.
     *
     * @return array 반환값
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.action' => ['method' => 'handleAction', 'priority' => 10],
        ];
    }

    /**
     * 기본 핸들러(사용하지 않음).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 액션 훅을 처리합니다.
     *
     * @param  string  $value  훅 값
     * @return void 반환값 없음
     */
    public function handleAction(string $value): void {}
}

class StubSyncListener implements HookListenerInterface
{
    public static bool $executed = false;

    /**
     * 구독 훅 목록을 반환합니다.
     *
     * @return array 반환값
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.sync' => ['method' => 'handleSync', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러(사용하지 않음).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 동기 액션 훅을 처리합니다.
     *
     * @return void 반환값 없음
     */
    public function handleSync(): void
    {
        self::$executed = true;
    }
}

class StubFilterListener implements HookListenerInterface
{
    /**
     * 구독 훅 목록을 반환합니다.
     *
     * @return array 반환값
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.filter' => ['method' => 'handleFilter', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    /**
     * 기본 핸들러(사용하지 않음).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 필터 훅을 처리합니다.
     *
     * @param  string  $value  필터 값
     * @return string 반환값
     */
    public function handleFilter(string $value): string
    {
        return $value.'_filtered';
    }
}

class StubPriorityHighListener implements HookListenerInterface
{
    public static array $order = [];

    /**
     * 구독 훅 목록을 반환합니다.
     *
     * @return array 반환값
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.priority' => ['method' => 'handlePriority', 'priority' => 5, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러(사용하지 않음).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 우선순위 검증용 훅을 처리합니다.
     *
     * @return void 반환값 없음
     */
    public function handlePriority(): void
    {
        self::$order[] = 'high';
    }
}

class StubPriorityLowListener implements HookListenerInterface
{
    public static array $order = [];

    /**
     * 구독 훅 목록을 반환합니다.
     *
     * @return array 반환값
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'test.registrar.priority' => ['method' => 'handlePriority', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러(사용하지 않음).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 우선순위 검증용 훅을 처리합니다.
     *
     * @return void 반환값 없음
     */
    public function handlePriority(): void
    {
        self::$order[] = 'low';
    }
}
