<?php

namespace Tests\Feature\Scheduling;

use App\Console\RuntimeRoleCommandGuard;
use App\Scheduling\ScheduleRunState;
use App\Scheduling\WebCronPolicy;
use App\Scheduling\WebCronTokenStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 서버별 스케줄 스위치(`G7_SCHEDULER`)의 진입점 — CLI(`g7:schedule-run`)와 웹 크론 주소 (#888).
 *
 * 공유 설정 `system.cron_mode` 는 서버마다 다르게 둘 수 없어, 여러 서버 중 한 대만 스케줄을 돌리게 할 때 이 스위치를 쓴다.
 * 꺼진 서버의 CLI 진입점은 아무것도 돌리지 않고 안내 후 종료 0(cron 을 그대로 두어도 된다), 웹 크론 주소는 실행 없이
 * `reason: scheduler_disabled_on_server` 로 답한다. 켜진 서버는 종전과 같다(회귀).
 *
 * @scenario-manifest tests/scenarios/runtime-role-stage.yaml
 */
class SchedulerSwitchEntryPointTest extends TestCase
{
    use DatabaseTransactions;

    /** 케이스 전용 상태 파일 */
    private string $statePath;

    /** 케이스 전용 토큰 파일 */
    private string $tokenPath;

    /** @var array<int, string> 실행 기록 */
    private array $executed = [];

    /**
     * 예약 하나만 있는 스케줄과 케이스 전용 상태·토큰 저장소로 갈아 끼웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->statePath = storage_path('framework/testing/scheduler-switch-state-'.$suffix.'.json');
        $this->tokenPath = storage_path('framework/testing/scheduler-switch-token-'.$suffix.'.json');
        $this->executed = [];

        File::ensureDirectoryExists(dirname($this->statePath));

        $this->app->instance(ScheduleRunState::class, new ScheduleRunState($this->statePath));
        $this->app->instance(WebCronTokenStore::class, new WebCronTokenStore($this->tokenPath));

        app(ConsoleKernelContract::class)->bootstrap();
        $this->app->instance(Schedule::class, new Schedule('UTC'));

        app(Schedule::class)
            ->call(function () {
                $this->executed[] = 'every-minute';
            })
            ->name('every-minute')
            ->cron('* * * * *');

        // 명령 시작 가드는 막을 때 프로세스를 끝낸다 — 시험에서는 종료 대신 기록만 한다
        RuntimeRoleCommandGuard::$exitUsing = static function (int $code): void {};
        config(['core.runtime_role' => 'live', 'core.settings.system.cron_mode' => 'auto']);
    }

    /**
     * 상태·토큰 파일과 종료 가로채기를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([$this->statePath, $this->tokenPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        RuntimeRoleCommandGuard::$exitUsing = null;

        parent::tearDown();
    }

    /**
     * 꺼진 서버의 `g7:schedule-run` 은 아무것도 돌리지 않고 안내 후 종료 0, 켜진 서버는 종전처럼 돌린다.
     *
     * @scenario case=scheduler_switch
     *
     * @effects scheduler_switch_disables_web_triggers
     *
     * @return void 반환값 없음
     */
    public function test_cli_entry_point_runs_nothing_when_scheduler_is_off(): void
    {
        config(['core.scheduler.enabled' => false]);

        $this->artisan('g7:schedule-run')
            ->expectsOutputToContain((string) __('common.console.runtime_role.schedule_disabled'))
            ->assertExitCode(0);

        $this->assertSame([], $this->executed, '스케줄이 꺼진 서버는 예약을 하나도 돌리지 않는다');
        $state = app(ScheduleRunState::class);
        $state->refresh();
        $this->assertNull($state->lastInvokedAt(), '꺼진 서버는 호출 기록도 남기지 않는다');

        // 회귀 — 켜진 서버는 종전처럼 돌린다
        config(['core.scheduler.enabled' => true]);
        $this->artisan('g7:schedule-run')->assertExitCode(0);
        $this->assertSame(['every-minute'], $this->executed);
    }

    /**
     * 꺼진 서버의 웹 크론 주소는 실행 없이 `reason: scheduler_disabled_on_server` 로 답하고, 켜진 서버는 종전처럼 돌린다.
     *
     * @scenario case=scheduler_switch
     *
     * @effects scheduler_switch_disables_web_triggers
     *
     * @return void 반환값 없음
     */
    public function test_web_cron_reports_scheduler_disabled_on_server(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();
        config(['core.scheduler.enabled' => false]);

        $this->getJson('/api/system/cron/run?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.ran', false)
            ->assertJsonPath('data.reason', 'scheduler_disabled_on_server');
        $this->assertSame([], $this->executed);

        // 회귀 — 켜진 서버는 실행한다
        config(['core.scheduler.enabled' => true]);
        $this->getJson('/api/system/cron/run?token='.$token)->assertOk()->assertJsonPath('data.ran', true);
        $this->assertSame(['every-minute'], $this->executed);
    }

    /**
     * 웹 크론 주소의 거절 문구는 사유마다 다르다 — 서버 스위치(`G7_SCHEDULER=off`)·검수 서버를 「서버 크론 전용」으로 안내하지 않는다
     *.
     *
     * 종전에는 사유(`reason`)는 갈렸지만 문구는 늘 `schedule.cron.disabled`(「서버 크론 전용으로 설정되어 있어」)라, 실행 방식이
     * 「자동」인 사이트에서도 외부 크론 로그를 보는 운영자가 엉뚱한 설정(`system.cron_mode`)을 고치게 됐다.
     *
     * @scenario case=scheduler_switch
     *
     * @effects web_cron_refusal_message_matches_reason
     *
     * @return void 반환값 없음
     */
    public function test_web_cron_refusal_message_matches_reason(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();

        config(['core.scheduler.enabled' => false, 'core.runtime_role' => 'live']);
        $this->getJson('/api/system/cron/run?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.reason', 'scheduler_disabled_on_server')
            ->assertJsonPath('message', (string) __('schedule.cron.disabled_on_server'));

        config(['core.scheduler.enabled' => true, 'core.runtime_role' => 'stage']);
        $this->getJson('/api/system/cron/run?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.reason', 'scheduler_disabled_on_server')
            ->assertJsonPath('message', (string) __('schedule.cron.disabled_stage'));

        // 실행 방식 「서버 크론만」 은 종전 문구
        config(['core.runtime_role' => 'live']);
        config(['g7_settings.core.system.cron_mode' => WebCronPolicy::MODE_CRON]);
        $this->getJson('/api/system/cron/run?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.reason', 'web_call_disabled')
            ->assertJsonPath('message', (string) __('schedule.cron.disabled'));

        $this->assertNotSame((string) __('schedule.cron.disabled'), (string) __('schedule.cron.disabled_on_server'));
        $this->assertSame([], $this->executed);
    }
}
