<?php

namespace Tests\Feature\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Events\GenericBroadcastEvent;
use App\Jobs\Analytics\AnonymizeLedgerJob;
use App\Jobs\Analytics\ArchiveRangeJob;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Services\Analytics\ArchiveService;
use App\Services\Analytics\DiagnosticsService;
use App\Services\Analytics\StaleRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * 끝을 알리지 못하고 멈춘 실행 이력 — 진단 화면의 수동 집계 버튼이 풀리는가.
 *
 * 워커가 실행 도중 죽으면 그 실행 이력 행은 「실행 중」으로 남는다. 진단 화면은 실행 중 행이 있으면
 * 「지금 집계」·「어제 확정」을 잠그고 진행률을 따라가므로, 그 행 하나가 정리 배치가 지울 때까지
 * 버튼을 잠갔다(#682 S3 라운드 13). 세 갈래를 각각 잰다 — 진단의 판정, 매시 정리, 잡의 실패 훅.
 */
class StaleRunServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 방송 드라이버를 켜고 이벤트를 가로챕니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => 'localhost']);
        Event::fake([GenericBroadcastEvent::class]);
    }

    /**
     * 「실행 중」 행을 만듭니다.
     *
     * @param  int  $minutesAgo  몇 분 전에 시작했는가
     * @param  string  $runType  실행 유형
     * @param  int|null  $triggeredBy  요청한 관리자 ID
     * @return AnalyticsArchiveRun 만들어진 행
     */
    private function runningRow(int $minutesAgo, string $runType = 'reaggregate', ?int $triggeredBy = null): AnalyticsArchiveRun
    {
        return AnalyticsArchiveRun::factory()->create([
            'run_type' => $runType,
            'trigger' => $triggeredBy === null ? 'scheduled' : 'manual',
            'status' => ArchiveRunStatus::Running,
            'progress_percent' => 40,
            'started_at' => AnalyticsClock::now()->subMinutes($minutesAgo),
            'finished_at' => null,
            'triggered_by' => $triggeredBy,
        ]);
    }

    /**
     * 기준 시간을 넘긴 「실행 중」은 진단이 진행 중으로 치지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=archive_runs, row_age=beyond, dry_run=false, retention_source=setting
     *
     * @effects stale_running_rows_do_not_lock_the_diagnostics_buttons
     */
    public function test_diagnostics_ignores_a_run_that_stopped_long_ago(): void
    {
        $this->runningRow(StaleRunService::STALE_AFTER_SECONDS / 60 + 5);
        $diagnostics = app(DiagnosticsService::class);

        $this->assertNull(
            $diagnostics->diagnostics(AnalyticsConfig::fromSettings())['archive']['running_run_id'],
            '멈춘 행 하나가 수동 집계 버튼을 잠그면 안 된다',
        );

        $fresh = $this->runningRow(3);
        $this->assertSame(
            $fresh->id,
            $diagnostics->diagnostics(AnalyticsConfig::fromSettings())['archive']['running_run_id'],
            '지금 도는 실행은 여전히 진행 중으로 보인다',
        );
    }

    /**
     * 매시 집계가 멈춘 행만 실패로 닫고 방송하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=archive_runs, row_age=beyond, dry_run=false, retention_source=setting
     *
     * @effects stale_running_rows_are_closed_by_the_hourly_archive
     */
    public function test_the_hourly_archive_closes_stale_runs_only(): void
    {
        $stale = $this->runningRow(StaleRunService::STALE_AFTER_SECONDS / 60 + 5, ArchiveRunType::Anonymize->value, 7);
        $fresh = $this->runningRow(3);

        $this->artisan('analytics:archive', ['--period' => 'hour', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(ArchiveRunStatus::Running, $stale->fresh()->status, '마른 실행은 이력을 바꾸지 않는다');

        $this->artisan('analytics:archive', ['--period' => 'hour'])->assertSuccessful();

        $stale->refresh();
        $this->assertSame(ArchiveRunStatus::Failed, $stale->status);
        $this->assertNotNull($stale->finished_at);
        $this->assertSame(__('analytics.archive.stale_run'), $stale->error);
        $this->assertSame(ArchiveRunStatus::Running, $fresh->fresh()->status, '지금 도는 실행은 건드리지 않는다');

        Event::assertDispatched(
            GenericBroadcastEvent::class,
            static fn (GenericBroadcastEvent $event): bool => $event->channel === ArchiveService::PROGRESS_CHANNEL
                && ($event->payload['data']['id'] ?? null) === $stale->id
                && ($event->payload['data']['status'] ?? null) === ArchiveRunStatus::Failed->value,
        );
    }

    /**
     * 실패한 재집계·익명화 잡이 자기가 시작한 「실행 중」만 닫는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=archive_runs, row_age=within, dry_run=false, retention_source=setting
     *
     * @effects failed_jobs_close_the_runs_they_started
     */
    public function test_a_failed_job_closes_only_the_runs_it_started(): void
    {
        $before = $this->runningRow(10, 'reaggregate', 7);

        $this->travel(1)->minutes();
        $reaggregate = new ArchiveRangeJob('2026-09-01', '2026-09-03', false, 7);
        $anonymize = new AnonymizeLedgerJob(1, false, null, null, 7);
        $this->travel(1)->minutes();

        $own = $this->runningRow(0, 'reaggregate', 7);
        $otherUser = $this->runningRow(0, 'reaggregate', 8);
        $anonymizeRun = $this->runningRow(0, 'anonymize', 7);

        $reaggregate->failed(new RuntimeException('워커 제한시간 초과'));

        $this->assertSame(ArchiveRunStatus::Failed, $own->fresh()->status);
        $this->assertStringContainsString('워커 제한시간 초과', (string) $own->fresh()->error);
        $this->assertSame(ArchiveRunStatus::Running, $otherUser->fresh()->status, '다른 관리자의 실행');
        $this->assertSame(ArchiveRunStatus::Running, $before->fresh()->status, '잡을 올리기 전에 시작한 실행');
        $this->assertSame(ArchiveRunStatus::Running, $anonymizeRun->fresh()->status, '다른 유형의 실행');

        $anonymize->failed(new RuntimeException('메모리 초과'));
        $this->assertSame(ArchiveRunStatus::Failed, $anonymizeRun->fresh()->status);
    }
}
