<?php

namespace Tests\Unit\Jobs;

use App\Extension\HookArgumentSerializer;
use App\Jobs\DispatchHookListenerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
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
     */
    public function test_job_default_properties(): void
    {
        $job = new DispatchHookListenerJob('SomeClass', 'someMethod', []);

        $this->assertEquals(3, $job->tries);
        $this->assertTrue($job->afterCommit);
    }

    /**
     * handle() 진입 시 캡처된 컨텍스트(Auth/Locale/Request)가 복원되는지 검증합니다.
     *
     * 큐 워커처럼 빈 컨텍스트에서 시작하여 Job 실행 후 리스너 내부에서
     * Auth::id(), App::getLocale(), request()->ip()가 캡처 값과 일치하는지 확인합니다.
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
}

class StubJobListener
{
    public static ?array $receivedArgs = null;

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

    public static function reset(): void
    {
        self::$capturedUserId = null;
        self::$capturedLocale = null;
        self::$capturedIp = null;
        self::$capturedPath = null;
    }

    public function capture(): void
    {
        self::$capturedUserId = Auth::id();
        self::$capturedLocale = App::getLocale();
        self::$capturedIp = request()->ip();
        self::$capturedPath = request()->path();
    }
}
