<?php

namespace Tests\Feature\Scheduling;

use App\Scheduling\ScheduleRunState;
use App\Scheduling\WebCronTokenStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 진입점 둘(웹 크론 · CLI 커맨드)이 같은 본체를 지나는지 (A-5).
 *
 * 두 진입점이 각자 러너를 들면 같은 사이트의 실행 이력이 어긋나고, 한쪽에만 넣은
 * 소급 규칙이 다른 쪽에서 조용히 빠진다. 그 어긋남은 오류로 드러나지 않는다 —
 * 예약이 두 번 돌거나 한 번도 안 돌 뿐이다.
 *
 * @scenario caller=panel, caller=external
 */
class ScheduleRunEntryPointParityTest extends TestCase
{
    // 러너가 실행 이력을 DB 에 남기므로 이 클래스도 격리가 필요하다 — 없으면
    // 커밋된 이력 행이 남아 뒤따르는 테스트의 모집단을 오염시킨다.
    use RefreshDatabase;

    /** 케이스 전용 상태 파일 */
    private string $statePath;

    /** 케이스 전용 토큰 파일 */
    private string $tokenPath;

    /** 실행 기록 */
    private array $executed = [];

    /**
     * 테스트 준비 — 예약 하나만 있는 스케줄로 갈아 끼운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->statePath = storage_path('framework/testing/entrypoint-state-'.$suffix.'.json');
        $this->tokenPath = storage_path('framework/testing/entrypoint-token-'.$suffix.'.json');
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
    }

    /**
     * 케이스 전용 상태 파일과 토큰 파일을 지운 뒤 부모 정리를 돌린다.
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

        parent::tearDown();
    }

    /**
     * CLI 진입점이 러너를 돌리고 요약을 출력한다.
     *
     * @scenario entry_point=cli, event_kind=callback_event
     *
     * @effects cli_entry_point_runs_the_same_runner
     *
     * @return void 반환값 없음
     */
    public function test_cli_entry_point_runs_the_schedule(): void
    {
        $this->artisan('g7:schedule-run')->assertExitCode(0);

        $this->assertSame(['every-minute'], $this->executed);
    }

    /**
     * CLI 진입점도 호출 시각을 기록한다 — 두 진입점이 같은 기록을 본다.
     *
     * 기록이 갈리면 "마지막 실행" 표시와 미실행 경고가 진입점마다 다른 값을 말한다.
     *
     * @scenario entry_point=cli, event_kind=callback_event
     *
     * @effects both_entry_points_share_the_run_record
     *
     * @return void 반환값 없음
     */
    public function test_cli_entry_point_records_the_invocation(): void
    {
        $this->artisan('g7:schedule-run')->assertExitCode(0);

        $state = app(ScheduleRunState::class);
        $state->refresh();

        $this->assertNotNull($state->lastInvokedAt());
        $this->assertNotNull($state->lastExecutedAt());
        $this->assertSame(1, $state->lastRunSummary()['executed'] ?? null);
    }

    /**
     * 웹 진입점도 같은 기록을 남긴다 (같은 상태 저장소).
     *
     * @scenario entry_point=web, caller=external, token=valid, token_channel=query
     *
     * @effects web_entry_point_records_the_same_state
     *
     * @return void 반환값 없음
     */
    public function test_web_entry_point_records_the_same_state(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();

        $this->getJson('/api/system/cron/run?token='.$token)->assertOk();

        $state = app(ScheduleRunState::class);
        $state->refresh();

        $this->assertSame(['every-minute'], $this->executed);
        $this->assertSame(1, $state->lastRunSummary()['executed'] ?? null);
    }

    /**
     * 실패한 작업이 있으면 CLI 진입점의 종료 코드가 0 이 아니다.
     *
     * 종료 코드가 언제나 0 이면 crontab 에 건 운영자가 실패를 알 방법이 없다.
     *
     * @scenario entry_point=cli, event_kind=callback_event
     *
     * @effects cli_exit_code_reflects_failures
     *
     * @return void 반환값 없음
     */
    public function test_cli_exit_code_reflects_failures(): void
    {
        app(Schedule::class)
            ->call(function () {
                throw new \RuntimeException('실패 주입');
            })
            ->name('failing')
            ->cron('* * * * *');

        $this->artisan('g7:schedule-run')->assertFailed();
    }
}
