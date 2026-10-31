<?php

namespace Tests\Unit\Jobs;

use App\Exceptions\RetryHookListenerLaterException;
use App\Extension\HookArgumentSerializer;
use App\Extension\HookContextCapture;
use App\Jobs\DispatchHookListenerJob;
use App\Models\User;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * DispatchHookListenerJob 테스트
 *
 * 훅 리스너 큐 Job의 실행과 표시 이름을 검증합니다.
 */
class DispatchHookListenerJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Job이 리스너 메서드를 정상 호출하는지 검증합니다.
     *
     * @scenario driver=queued, payload=current
     *
     * @effects listener_method_invoked
     *
     * @return void 반환값 없음
     */
    public function test_job_calls_listener_method(): void
    {
        StubJobListener::$receivedArgs = null;

        $args = HookArgumentSerializer::serialize(['hello', 42]);
        $job = new DispatchHookListenerJob(
            StubJobListener::class,
            'handleTest',
            $args
        );

        $job->handle();

        $this->assertEquals(['hello', 42], StubJobListener::$receivedArgs);
    }

    /**
     * displayName이 리스너 클래스@메서드 형식인지 검증합니다.
     *
     * @scenario driver=queued, payload=current
     *
     * @effects display_name_formatted
     *
     * @return void 반환값 없음
     */
    public function test_display_name_format(): void
    {
        $job = new DispatchHookListenerJob(
            StubJobListener::class,
            'handleTest',
            []
        );

        $this->assertStringContainsString('StubJobListener@handleTest', $job->displayName());
    }

    /**
     * Job의 기본 속성을 검증합니다.
     *
     * @scenario driver=queued, payload=current
     *
     * @effects legacy_defaults_preserved
     *
     * @return void 반환값 없음
     */
    public function test_job_default_properties(): void
    {
        $job = new DispatchHookListenerJob('SomeClass', 'someMethod', []);

        $this->assertEquals(3, $job->tries);
        $this->assertTrue($job->afterCommit);
        // 큐 옵션을 선언하지 않은 잡은 종전 값 그대로 (#139)
        $this->assertNull($job->timeout);
        $this->assertNull($job->backoff);
        $this->assertNull($job->retryUntil);
        $this->assertNull($job->maxExceptions);
        $this->assertFalse($job->failOnTimeout);
        $this->assertNull($job->connection);
        $this->assertNull($job->queue);
    }

    /**
     * 이벤트 ID 는 디스패치 때 한 번 만들어지고 직렬화를 거쳐도 같다 (#139).
     *
     * @scenario payload=current
     *
     * @effects event_id_stable_across_retries
     *
     * @return void 반환값 없음
     */
    public function test_event_id_is_generated_once_and_survives_serialization(): void
    {
        $job = new DispatchHookListenerJob(StubJobListener::class, 'handleTest', []);
        $copy = unserialize(serialize($job));

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $job->eventId);
        $this->assertSame($job->eventId, $copy->eventId);
        $this->assertNotSame($job->eventId, (new DispatchHookListenerJob(StubJobListener::class, 'handleTest', []))->eventId);
    }

    /**
     * 업데이트 전에 쌓인 옛 payload(이벤트 ID 없음)는 큐 잡 uuid 를 이벤트 ID 로 쓴다 (#139).
     *
     * @scenario payload=legacy
     *
     * @effects event_id_stable_across_retries
     *
     * @return void 반환값 없음
     */
    public function test_legacy_payload_without_event_id_falls_back_to_queue_job_uuid(): void
    {
        EventIdStubListener::$seen = null;
        $job = new DispatchHookListenerJob(EventIdStubListener::class, 'capture', []);
        $job->eventId = null;
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('uuid')->andReturn('legacy-queue-uuid');
        $job->setJob($queueJob);

        $job->handle();

        $this->assertSame('legacy-queue-uuid', EventIdStubListener::$seen);
    }

    /**
     * 리스너 실행 중에는 이벤트 ID 가 바인딩되고, 끝나면 이전 값으로 돌아간다 (#139).
     *
     * @scenario payload=current
     *
     * @effects event_id_bound_during_listener
     *
     * @return void 반환값 없음
     */
    public function test_event_id_is_bound_during_listener_and_restored_after(): void
    {
        EventIdStubListener::$seen = null;
        $job = new DispatchHookListenerJob(EventIdStubListener::class, 'capture', []);

        $job->handle();
        $this->assertSame($job->eventId, EventIdStubListener::$seen);
        $this->assertNull(HookContextCapture::eventId(), '바인딩이 없던 프로세스에 이벤트 ID 가 남았다');

        app()->instance(HookContextCapture::EVENT_ID_BINDING, 'outer-event');
        $job->handle();
        $this->assertSame('outer-event', HookContextCapture::eventId(), '이전 이벤트 ID 로 되돌리지 않았다');
        app()->forgetInstance(HookContextCapture::EVENT_ID_BINDING);
    }

    /**
     * 리스너가 RetryHookListenerLaterException 을 던지면 큐 잡을 요청한 지연으로 되돌린다 (#139).
     *
     * @scenario driver=queued
     *
     * @effects retry_later_releases_job
     *
     * @return void 반환값 없음
     */
    public function test_retry_later_exception_releases_job_with_requested_delay(): void
    {
        $job = new DispatchHookListenerJob(EventIdStubListener::class, 'retryLater', []);
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('release')->once()->with(42);
        $job->setJob($queueJob);

        $job->handle();

        $this->addToAssertionCount(1);
    }

    /**
     * sync 드라이버(큐 잡 없음)에서는 재실행 요청을 경고로만 남기고 예외를 올리지 않는다 (#139).
     *
     * @scenario driver=sync
     *
     * @effects retry_later_logged_under_sync
     *
     * @return void 반환값 없음
     */
    public function test_retry_later_exception_under_sync_driver_is_logged_not_rethrown(): void
    {
        Log::spy();
        $job = new DispatchHookListenerJob(EventIdStubListener::class, 'retryLater', []);

        $job->handle();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => ($context['delay_seconds'] ?? null) === 42)->once();
    }

    /**
     * handle() 진입 시 캡처된 컨텍스트(Auth/Locale/Request)가 복원되는지 검증합니다.
     *
     * 큐 워커처럼 빈 컨텍스트에서 시작하여 Job 실행 후 리스너 내부에서
     * Auth::id(), App::getLocale(), request()->ip()가 캡처 값과 일치하는지 확인합니다.
     *
     * @scenario driver=queued, payload=current
     *
     * @effects captured_context_restored
     *
     * @return void 반환값 없음
     */
    public function test_job_restores_captured_context_before_listener(): void
    {
        ContextAwareStubListener::reset();

        // Auth::onceUsingId()는 실제 사용자를 DB에서 로드하므로 factory로 생성
        $user = User::factory()->create();

        // 현재 컨텍스트를 비워 큐 워커 환경 시뮬레이션
        Auth::logout();
        App::setLocale('en');

        $job = new DispatchHookListenerJob(
            ContextAwareStubListener::class,
            'capture',
            HookArgumentSerializer::serialize([]),
            [
                'user_id' => $user->id,
                'ip_address' => '198.51.100.7',
                'user_agent' => 'WorkerAgent/1.0',
                'locale' => 'ko',
                'path' => 'api/admin/settings',
            ]
        );

        $job->handle();

        $this->assertEquals($user->id, ContextAwareStubListener::$capturedUserId);
        $this->assertEquals('ko', ContextAwareStubListener::$capturedLocale);
        $this->assertEquals('198.51.100.7', ContextAwareStubListener::$capturedIp);
        $this->assertEquals('api/admin/settings', ContextAwareStubListener::$capturedPath);
    }

    /**
     * Job 실행 후 원래 요청 바인딩이 되돌려지는지 검증합니다.
     *
     * 큐 드라이버가 sync 면 이 Job 은 원 HTTP 요청과 같은 컨테이너에서 실행됩니다.
     * 컨텍스트 복원이 재구성 Request 를 바인딩한 채 끝나면, 그 뒤로 이어지는 같은 요청의
     * 코드가 헤더·입력이 지워진 가짜 GET 요청을 보게 됩니다 — 뒤 순위 훅 리스너가 전송
     * 헤더를 읽지 못해 조용히 건너뛰는 사고가 여기서 납니다.
     *
     * @scenario driver=queued, payload=current
     *
     * @effects request_binding_restored
     *
     * @return void 반환값 없음
     */
    public function test_job_restores_original_request_binding_after_handle(): void
    {
        ContextAwareStubListener::reset();

        $original = Request::create(
            '/api/auth/login',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_CART_KEY' => 'ck_'.str_repeat('a', 32)],
        );
        app()->instance('request', $original);

        $job = new DispatchHookListenerJob(
            ContextAwareStubListener::class,
            'capture',
            HookArgumentSerializer::serialize([]),
            [
                'ip_address' => '198.51.100.7',
                'user_agent' => 'WorkerAgent/1.0',
                'locale' => 'ko',
                'path' => 'api/auth/login',
            ]
        );

        $job->handle();

        $this->assertSame($original, app('request'), 'Job 이 원 요청 바인딩을 되돌리지 않았습니다.');
        $this->assertSame(
            'ck_'.str_repeat('a', 32),
            request()->header('X-Cart-Key'),
            '큐 리스너 실행 후 원 요청의 헤더가 사라졌습니다 — 뒤 순위 리스너가 전송 헤더를 읽지 못합니다.'
        );
        $this->assertSame('POST', request()->getMethod());
    }
}

/**
 * 이벤트 ID·재실행 요청 확인용 스텁 리스너 (#139)
 */
class EventIdStubListener
{
    /** @var string|null 리스너 실행 중에 본 이벤트 ID */
    public static ?string $seen = null;

    /**
     * 실행 중 이벤트 ID 를 남깁니다.
     *
     * @return void 반환값 없음
     */
    public function capture(): void
    {
        self::$seen = HookContextCapture::eventId();
    }

    /**
     * 42초 뒤 다시 실행해 달라고 요청합니다.
     *
     * @return void 반환값 없음
     *
     * @throws RetryHookListenerLaterException 항상
     */
    public function retryLater(): void
    {
        throw new RetryHookListenerLaterException(42);
    }
}

class StubJobListener
{
    public static ?array $receivedArgs = null;

    /**
     * 테스트 리스너 메서드 — 받은 인자를 기록합니다.
     *
     * @param  string  $message  메시지
     * @param  int  $number  숫자
     * @return void 반환값 없음
     */
    public function handleTest(string $message, int $number): void
    {
        self::$receivedArgs = [$message, $number];
    }
}

class ContextAwareStubListener
{
    public static ?int $capturedUserId = null;

    public static ?string $capturedLocale = null;

    public static ?string $capturedIp = null;

    public static ?string $capturedPath = null;

    /**
     * 기록을 비웁니다.
     *
     * @return void 반환값 없음
     */
    public static function reset(): void
    {
        self::$capturedUserId = null;
        self::$capturedLocale = null;
        self::$capturedIp = null;
        self::$capturedPath = null;
    }

    /**
     * 실행 시점의 컨텍스트를 기록합니다.
     *
     * @return void 반환값 없음
     */
    public function capture(): void
    {
        self::$capturedUserId = Auth::id();
        self::$capturedLocale = App::getLocale();
        self::$capturedIp = request()->ip();
        self::$capturedPath = request()->path();
    }
}
