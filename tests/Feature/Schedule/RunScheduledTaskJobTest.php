<?php

namespace Tests\Feature\Schedule;

use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Extension\HookContextCapture;
use App\Jobs\RunScheduledTaskJob;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Models\User;
use App\Services\Schedule\ScheduleDispatcher;
use App\Services\Schedule\ScheduleExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 큐 실행 잡 — payload 계약(K5), 실행 직전 재확인, 워커 사망 시 이력 마감, 상주 워커에서의 실행자 문맥
 */
class RunScheduledTaskJobTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 생성자·tries·표시 이름·직렬화 왕복이 계약대로다(스케줄 ID 만 싣는다).
     *
     * @scenario dispatch_mode=queue
     *
     * @effects queue_dispatch_pushes_run_job
     *
     * @return void 반환값 없음
     */
    public function test_payload_contract(): void
    {
        $schedule = Schedule::factory()->create(['name' => 'nightly-report-'.uniqid()]);
        $job = new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Manual, 9);

        $this->assertSame(1, $job->tries);
        $this->assertSame("schedule:{$schedule->id} {$schedule->name}", $job->displayName());
        $this->assertSame('schedule:'.PHP_INT_MAX, (new RunScheduledTaskJob(PHP_INT_MAX, ScheduleTriggerType::Scheduled))->displayName());

        $restored = unserialize(serialize($job));
        $this->assertSame($schedule->id, $restored->scheduleId);
        $this->assertSame(ScheduleTriggerType::Manual, $restored->trigger);
        $this->assertSame(9, $restored->userId);
    }

    /**
     * 예약 실행 잡은 투입 뒤 비활성으로 바뀐 행·조건 미충족 행을 건너뛰고, 수동 실행 잡은 조건과 무관하게 실행한다.
     *
     * @scenario trigger=queue_worker, condition=unmet, trigger=manual
     *
     * @effects queued_run_rechecks_state_before_running
     *
     * @return void 반환값 없음
     */
    public function test_handle_rechecks_state_before_running(): void
    {
        config(['g7_settings.core.seo.sitemap_enabled' => false]);
        $schedule = Schedule::factory()->create([
            'enabled_condition' => [['source' => 'core_setting', 'key' => 'seo.sitemap_enabled', 'operator' => 'truthy', 'default' => true]],
        ]);
        $inactive = Schedule::factory()->create(['is_active' => false]);

        $executor = Mockery::mock(ScheduleExecutor::class);
        $executor->shouldReceive('execute')
            ->once()
            ->withArgs(fn (Schedule $s, ScheduleTriggerType $t) => $s->id === $schedule->id && $t === ScheduleTriggerType::Manual)
            ->andReturnNull();
        $this->app->instance(ScheduleExecutor::class, $executor);

        app()->call([new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Scheduled), 'handle']);
        app()->call([new RunScheduledTaskJob($inactive->id, ScheduleTriggerType::Scheduled), 'handle']);
        app()->call([new RunScheduledTaskJob(PHP_INT_MAX, ScheduleTriggerType::Scheduled), 'handle']);
        app()->call([new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Manual), 'handle']);

        $this->addToAssertionCount(1);
    }

    /**
     * 워커가 잡을 실패 처리하면 running 이력이 failed 로 마감된다.
     *
     * @scenario trigger=queue_worker, outcome=failure
     *
     * @effects abandoned_queue_run_is_closed_as_failed
     *
     * @return void 반환값 없음
     */
    public function test_failed_closes_running_history(): void
    {
        $schedule = Schedule::factory()->create();
        $running = ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now(),
            'status' => ScheduleResultStatus::Running,
            'trigger_type' => ScheduleTriggerType::Scheduled,
        ]);

        (new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Scheduled))
            ->failed(new RuntimeException('has timed out'));

        $this->assertSame(ScheduleResultStatus::Failed, $running->fresh()->status);
        $this->assertSame('has timed out', $running->fresh()->error_output);
        $this->assertSame(ScheduleResultStatus::Failed, $schedule->fresh()->last_result);
    }

    /**
     * 상주 워커가 다른 관리자의 작업을 먼저 처리한 뒤에도 큐로 보낸 「즉시 실행」의 활동 로그는 누른 관리자로 남는다.
     *
     * 앞 작업이 관리자 A 의 문맥을 복원한 채 되돌리지 않고 끝나도(확장 잡이 그렇게 끝나던 실측), 같은 워커가
     * 이어서 처리한 관리자 B 의 실행 로그에 A 의 사용자·IP·브라우저 정보가 들어가면 안 된다.
     *
     * @scenario dispatch_mode=queue, trigger=manual, outcome=success
     *
     * @effects queued_manual_run_logs_the_admin_who_pressed_it
     *
     * @return void 반환값 없음
     */
    public function test_queued_manual_run_logs_the_admin_who_pressed_it_after_another_admins_job(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->delete();

        $adminA = User::factory()->create();
        $adminB = User::factory()->create();
        $schedule = Schedule::factory()->create(['dispatch_mode' => 'queue']);

        // 앞 작업 — 관리자 A 의 문맥을 복원하고 되돌리지 않은 채 끝난다
        dispatch(new LeavesRestoredContextBehindJob([
            'user_id' => $adminA->id,
            'ip_address' => '198.51.100.22',
            'user_agent' => 'AdminA-Browser/1.0',
            'locale' => 'ko',
            'path' => 'api/admin/exports',
            'origin' => 'http',
        ]));

        // 관리자 B 가 화면에서 「즉시 실행」 — 디스패처가 그 요청 안에서 잡을 만든다
        $this->bindRequestAs($adminB, '203.0.113.23', 'AdminB-Browser/2.0', 'api/admin/schedules/'.$schedule->id.'/run');
        app(ScheduleDispatcher::class)->dispatch($schedule->fresh(), ScheduleTriggerType::Manual, $adminB->id);

        // 워커 프로세스는 사용자·요청 없이 시작한다
        $this->becomeWorkerProcess();
        // 워커 기본 메모리 한도(128MB)는 테스트 프로세스가 이미 넘어 첫 작업 뒤 멈춘다
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 4096]);

        $row = DB::table('activity_logs')
            ->where('action', 'schedule.run')
            ->where('loggable_id', $schedule->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, '큐로 보낸 즉시 실행이 활동 로그를 남기지 않았다');
        $this->assertSame($adminB->id, (int) $row->user_id, '활동 로그 실행자가 누른 관리자가 아니다 — 앞 작업의 사용자가 남았다');
        $this->assertSame('203.0.113.23', $row->ip_address, '활동 로그 IP 가 누른 관리자의 요청 값이 아니다');
        $this->assertSame('AdminB-Browser/2.0', $row->user_agent, '활동 로그 브라우저 정보가 누른 관리자의 요청 값이 아니다');
        $this->assertFalse(Auth::hasUser(), '워커가 작업을 마친 뒤에도 인증 사용자가 남았다');
    }

    /**
     * 실린 문맥으로 실행하고, 끝나면(예외여도) 잡 전 상태로 되돌린다. 예약 실행은 앞 사용자를 물려받지 않는다.
     *
     * @scenario trigger=queue_worker, trigger=manual, trigger=scheduled
     *
     * @effects queued_run_does_not_inherit_or_leave_worker_context
     *
     * @return void 반환값 없음
     */
    public function test_run_job_restores_carried_context_only_while_running(): void
    {
        $adminA = User::factory()->create();
        $adminB = User::factory()->create();
        $schedule = Schedule::factory()->create(['dispatch_mode' => 'queue']);

        $seen = [];
        $executor = Mockery::mock(ScheduleExecutor::class);
        $executor->shouldReceive('execute')->andReturnUsing(function () use (&$seen) {
            $seen[] = ['user' => Auth::id(), 'ip' => request()->ip(), 'origin' => HookContextCapture::origin()];

            return null;
        });
        $this->app->instance(ScheduleExecutor::class, $executor);

        $this->becomeWorkerProcess();
        $workerRequest = app('request');

        // 수동 실행 — 누른 관리자의 문맥으로 실행하고 끝나면 비운다
        $context = ['user_id' => $adminB->id, 'ip_address' => '203.0.113.23', 'user_agent' => 'AdminB-Browser/2.0', 'locale' => 'ko', 'path' => 'api/admin/schedules/1/run', 'origin' => 'http'];
        app()->call([new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Manual, $adminB->id, $context), 'handle']);

        $this->assertSame(['user' => $adminB->id, 'ip' => '203.0.113.23', 'origin' => 'http'], $seen[0]);
        $this->assertFalse(Auth::hasUser(), '실행이 끝난 뒤 관리자 B 가 워커에 남았다');
        $this->assertSame($workerRequest, app('request'), '실행이 끝난 뒤 요청 바인딩이 되돌아오지 않았다');
        $this->assertFalse(app()->bound(HookContextCapture::ORIGIN_BINDING), '실행이 끝난 뒤 진입점 복원값이 남았다');

        // 직렬화 왕복 뒤에도 문맥이 실린다 (워커는 payload 에서 잡을 되살린다)
        $restored = unserialize(serialize(new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Manual, $adminB->id, $context)));
        $this->assertSame($context, $restored->context);

        // 예약 실행 — 앞 잡이 남긴 사용자가 있어도 사용자 없이 실행한다
        Auth::setUser($adminA);
        app()->call([new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Scheduled), 'handle']);
        $this->assertNull($seen[1]['user'], '예약 실행이 앞 잡이 남긴 사용자로 돌았다');
    }

    /**
     * 워커가 잡을 집을 때마다 인증 사용자·요청·로케일·시간대를 워커 기본 상태로 돌리고, sync 연결 잡은 건드리지 않는다.
     *
     * @scenario trigger=queue_worker
     *
     * @effects queued_run_does_not_inherit_or_leave_worker_context
     *
     * @return void 반환값 없음
     */
    public function test_worker_resets_leftover_context_before_each_job(): void
    {
        $adminA = User::factory()->create();
        $defaultLocale = (string) config('app.locale');
        $otherLocale = $defaultLocale === 'en' ? 'ko' : 'en';

        // sync 연결 잡 — 웹 요청 안이므로 그 요청의 사용자를 그대로 둔다
        Auth::setUser($adminA);
        $this->startJob('sync');
        $this->assertSame($adminA->id, Auth::id(), 'sync 연결 잡이 웹 요청의 사용자를 비웠다');

        // 앞 잡이 남긴 사용자·요청·로케일·시간대
        $this->bindRequestAs($adminA, '198.51.100.22', 'AdminA-Browser/1.0', 'api/admin/exports');
        App::setLocale($otherLocale);
        app()->instance('user_timezone', 'Pacific/Auckland');
        app()->instance(HookContextCapture::ORIGIN_BINDING, 'http');

        $this->startJob('database');

        $this->assertFalse(Auth::hasUser(), '워커가 앞 잡의 사용자를 다음 잡에 넘겼다');
        $this->assertNotSame('198.51.100.22', request()->ip(), '워커가 앞 잡의 요청을 다음 잡에 넘겼다');
        $this->assertNotSame('AdminA-Browser/1.0', \Illuminate\Support\Facades\Request::userAgent(), 'Request 파사드가 앞 잡의 요청을 붙들고 있다');
        $this->assertSame($defaultLocale, App::getLocale(), '워커가 앞 잡의 로케일을 다음 잡에 넘겼다');
        $this->assertFalse(app()->bound('user_timezone'), '워커가 앞 잡의 시간대를 다음 잡에 넘겼다');
        $this->assertFalse(app()->bound(HookContextCapture::ORIGIN_BINDING), '워커가 앞 잡의 진입점을 다음 잡에 넘겼다');
    }

    /**
     * 사용자와 요청(IP·브라우저·경로)을 바인딩해 그 사람의 웹 요청 안인 것처럼 만듭니다.
     *
     * @param  User  $user  요청한 사용자
     * @param  string  $ip  요청 IP
     * @param  string  $userAgent  브라우저 정보
     * @param  string  $path  요청 경로
     * @return void 반환값 없음
     */
    private function bindRequestAs(User $user, string $ip, string $userAgent, string $path): void
    {
        $request = Request::create('/'.$path, 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent]);
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
        Auth::setUser($user);
    }

    /**
     * 사용자·요청이 없는 큐 워커 프로세스의 시작 상태로 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function becomeWorkerProcess(): void
    {
        Auth::forgetUser();
        (new SetRequestForConsole)->bootstrap(app());
        Facade::clearResolvedInstance('request');
        app()->forgetInstance(HookContextCapture::ORIGIN_BINDING);
    }

    /**
     * 큐 작업 시작 이벤트를 냅니다.
     *
     * @param  string  $connection  큐 연결 이름
     * @return void 반환값 없음
     */
    private function startJob(string $connection): void
    {
        $job = Mockery::mock(QueueJobContract::class);
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldIgnoreMissing();

        event(new JobProcessing($connection, $job));
    }
}

/**
 * 요청 문맥을 복원한 채 되돌리지 않고 끝나는 잡 — 되돌리기를 빠뜨린 확장 잡을 흉내낸다.
 */
class LeavesRestoredContextBehindJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * 복원할 요청 문맥을 싣는다.
     *
     * @param  array<string, mixed>  $context  복원할 문맥
     */
    public function __construct(public array $context) {}

    /**
     * 문맥을 복원만 하고 끝냅니다.
     *
     * @return void 반환값 없음
     */
    public function handle(): void
    {
        HookContextCapture::restore($this->context);
    }
}
