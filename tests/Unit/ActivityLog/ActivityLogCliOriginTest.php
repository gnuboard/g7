<?php

namespace Tests\Unit\ActivityLog;

use App\ActivityLog\ActivityLogProcessor;
use App\Enums\ActivityLogType;
use App\Extension\HookContextCapture;
use App\Http\Resources\ActivityLogResource;
use App\Jobs\DispatchHookListenerJob;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * 활동 로그 "시스템(CLI)" 행위자 표기 (#138)
 *
 * CLI(Artisan 커맨드·스케줄러)에서 남은 로그는 properties.via = 'cli' 로 표시하고, 관리자 화면은
 * 회원이 없는 로그를 "시스템(CLI)" 로 보여 준다. 활동 로그 리스너는 기본이 큐 실행이라 실제 기록은
 * 큐 워커(콘솔 프로세스)에서 일어난다 — 그래서 진입점은 디스패치 시점에 캡처해 워커로 넘긴다.
 * 이 캡처가 없으면 어드민 화면에서 한 작업까지 전부 CLI 로 찍힌다.
 */
class ActivityLogCliOriginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 진입점 바인딩을 비워 테스트 간 누수를 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->forgetInstance(HookContextCapture::ORIGIN_BINDING);

        parent::tearDown();
    }

    // ─── 진입점 판정·캡처·복원 ───

    /**
     * 캡처는 디스패치 시점의 진입점을 싣고, 복원은 워커 프로세스 대신 그 값을 판정 근거로 삼는다.
     *
     * @scenario entry_point=cli, execution=queued_worker
     *
     * @effects origin_captured_at_dispatch_and_restored_in_worker
     *
     * @return void 반환값 없음
     */
    public function test_origin_is_captured_at_dispatch_and_restored_in_worker(): void
    {
        // PHPUnit 은 요청 시뮬레이션이라 기본 판정은 http
        $this->assertSame('http', HookContextCapture::origin());

        // CLI 프로세스에서 디스패치한 상황
        app()->instance(HookContextCapture::ORIGIN_BINDING, 'cli');
        $captured = HookContextCapture::capture();
        $this->assertSame('cli', $captured['origin']);

        // 워커(바인딩 없음)에서 복원
        app()->forgetInstance(HookContextCapture::ORIGIN_BINDING);
        HookContextCapture::restore($captured);
        $this->assertSame('cli', HookContextCapture::origin());
    }

    /**
     * 데몬 워커는 컨테이너를 작업 간에 재사용한다 — 한 작업이 복원한 진입점이 다음 작업으로 새지 않는다.
     *
     * @scenario entry_point=http, execution=queued_worker
     *
     * @effects restored_origin_does_not_leak_to_next_job
     *
     * @return void 반환값 없음
     */
    public function test_restored_origin_does_not_leak_to_next_job(): void
    {
        $job = new DispatchHookListenerJob(
            OriginProbeListener::class,
            'handle',
            [],
            ['origin' => 'cli'],
        );

        $job->handle();

        $this->assertSame('cli', OriginProbeListener::$seen, '작업 실행 중에는 원 진입점으로 판정');
        $this->assertFalse(app()->bound(HookContextCapture::ORIGIN_BINDING), '작업이 끝나면 복원값을 되돌린다');
        $this->assertSame('http', HookContextCapture::origin());
    }

    /**
     * 데몬 워커에서 회원 작업 다음에 온 시스템(CLI) 작업이 앞 작업의 로그인 사용자를 물려받지 않는다.
     *
     * 실측(2026-09-29, database 큐): 어드민 UI 설치 작업 뒤에 처리된 CLI 제거 작업이 user_id=1(관리자)로
     * 기록됐다. restore() 는 user_id 가 비어 있으면 인증을 건드리지 않아, 앞 작업이 setUser() 한 사용자가 남았다.
     *
     * @scenario entry_point=cli, execution=queued_worker
     *
     * @effects restored_user_does_not_leak_to_next_job
     *
     * @return void 반환값 없음
     */
    public function test_restored_user_does_not_leak_to_next_job(): void
    {
        $member = User::factory()->create();

        (new DispatchHookListenerJob(OriginProbeListener::class, 'handle', [], ['user_id' => $member->id, 'origin' => 'http']))->handle();
        $this->assertSame($member->id, OriginProbeListener::$seenUserId, '회원 작업 실행 중에는 그 회원으로 복원');

        (new DispatchHookListenerJob(OriginProbeListener::class, 'handle', [], ['user_id' => null, 'origin' => 'cli']))->handle();
        $this->assertNull(OriginProbeListener::$seenUserId, '다음 시스템 작업은 앞 작업의 사용자를 물려받지 않는다');
        $this->assertNull(Auth::id(), '작업이 끝나면 복원한 사용자를 되돌린다');
    }

    // ─── Processor ───

    // @scenario entry_point=http, execution=sync
    /**
     * CLI 진입점 로그에만 properties.via = 'cli' 를 남기고, 웹 로그의 properties 는 건드리지 않는다.
     *
     * @scenario entry_point=cli, execution=sync
     *
     * @effects cli_log_marked_via_cli, http_log_properties_untouched
     *
     * @return void 반환값 없음
     */
    public function test_processor_marks_only_cli_logs(): void
    {
        $processor = new ActivityLogProcessor;

        $web = $processor($this->record(['properties' => ['key' => 'value']]));
        $this->assertSame(['key' => 'value'], $web->context['properties']);

        $webNull = $processor($this->record());
        $this->assertArrayNotHasKey('properties', $webNull->context);

        app()->instance(HookContextCapture::ORIGIN_BINDING, 'cli');

        $cli = $processor($this->record(['properties' => ['key' => 'value']]));
        $this->assertSame(['key' => 'value', 'via' => 'cli'], $cli->context['properties']);

        $cliNull = $processor($this->record());
        $this->assertSame(['via' => 'cli'], $cliNull->context['properties']);

        // 명시 전달값은 덮어쓰지 않는다 (security:ip-restriction 의 'console' 선례)
        $explicit = $processor($this->record(['properties' => ['via' => 'console']]));
        $this->assertSame('console', $explicit->context['properties']['via']);
    }

    // ─── 모델·리소스 ───

    /**
     * 회원이 없는 로그의 행위자 라벨은 진입점에 따라 "시스템" 또는 "시스템(CLI)" 이다.
     *
     * @scenario entry_point=cli, execution=sync
     *
     * @effects system_log_actor_shows_origin_label
     *
     * @return void 반환값 없음
     */
    public function test_actor_name_distinguishes_cli_system_logs(): void
    {
        $cli = $this->makeLog(['via' => 'cli']);
        $console = $this->makeLog(['via' => 'console']);
        $web = $this->makeLog(null);

        $this->assertSame(__('common.system_cli'), $cli->actor_name);
        $this->assertSame(__('common.system_cli'), $console->actor_name);
        $this->assertSame(__('common.system'), $web->actor_name);
        $this->assertNotSame(__('common.system'), __('common.system_cli'));
    }

    /**
     * 회원이 없는 로그의 user 폴백은 uuid 를 null 로 명시한다 — 화면이 회원 메뉴를 그리지 않는 근거.
     *
     * @scenario entry_point=cli, execution=sync
     *
     * @effects system_log_actor_has_no_member_menu, system_log_actor_shows_origin_label
     *
     * @return void 반환값 없음
     */
    public function test_resource_user_fallback_has_null_uuid_and_origin_label(): void
    {
        $log = $this->makeLog(['via' => 'cli']);

        $resource = (new ActivityLogResource($log))->toArray(Request::create('/api/admin/activity-logs', 'GET'));

        $this->assertArrayHasKey('uuid', $resource['user']);
        $this->assertNull($resource['user']['uuid']);
        $this->assertSame(__('common.system_cli'), $resource['user']['name']);
        $this->assertSame(__('common.system_cli'), $resource['actor_name']);
    }

    /**
     * 테스트용 LogRecord 를 만듭니다.
     *
     * @param  array<string, mixed>  $context  로그 컨텍스트
     * @return LogRecord 레코드
     */
    private function record(array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'activity',
            level: Level::Info,
            message: 'module.install',
            context: $context,
        );
    }

    /**
     * 회원 없는 활동 로그를 만듭니다.
     *
     * @param  array<string, mixed>|null  $properties  properties 값
     * @return ActivityLog 생성된 로그
     */
    private function makeLog(?array $properties): ActivityLog
    {
        return ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'module.install',
            'properties' => $properties,
        ]);
    }
}

/**
 * 큐 작업 실행 중의 진입점 판정을 기록하는 테스트 리스너.
 */
class OriginProbeListener
{
    /**
     * 마지막으로 관찰한 진입점.
     */
    public static ?string $seen = null;

    /**
     * 마지막으로 관찰한 로그인 사용자 ID.
     */
    public static ?int $seenUserId = null;

    /**
     * 실행 시점의 진입점을 기록합니다.
     *
     * @return void 반환값 없음
     */
    public function handle(): void
    {
        self::$seen = HookContextCapture::origin();
        self::$seenUserId = Auth::id();
    }
}
