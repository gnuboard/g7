<?php

namespace Tests\Feature\Analytics;

use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\VisitorType;
use App\Enums\Analytics\VisitSource;
use App\Extension\HookManager;
use App\Jobs\Analytics\ArchiveRangeJob;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\ArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * 기간 재집계 잡 — 날짜를 하나씩 돌리고, 하루라도 실패하면 잡이 실패한다.
 *
 * 서비스는 하루가 실패해도 다음 날로 넘어가고 예외를 올리지 않는다. 잡이 그것을 그대로
 * 두면 90일 중 30일이 실패해도 큐에는 성공으로 남아 `failed_jobs` 가 비고, 큐만 보는
 * 운영자는 재집계가 멀쩡히 끝났다고 읽는다. 그래서 잡은 실패 일수를 세어 신호를 낸다.
 */
class ArchiveRangeJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비 — 집계 경계를 UTC 로 고정합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'UTC']);
    }

    /**
     * 방문 한 건을 심습니다.
     *
     * @param  string  $date  방문 일자 (Y-m-d)
     * @return void 없음
     */
    private function visit(string $date): void
    {
        $at = Carbon::parse($date.' 01:00:00', 'UTC');

        AnalyticsVisit::factory()->create([
            'visit_date' => $date,
            'first_action_at' => $at,
            'last_action_at' => $at->copy()->addMinutes(3),
            'visitor_type' => VisitorType::Guest,
            'source' => VisitSource::Beacon,
            'actions_count' => 1,
            'pageviews_count' => 1,
            'total_time_seconds' => 60,
            'is_bounce' => true,
            'is_returning' => false,
        ]);
    }

    /**
     * 기간의 날짜마다 재집계 이력이 남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=range, data=all_types, trigger=manual
     *
     * @effects range_job_reports_failed_days
     */
    public function test_each_day_in_the_range_gets_its_own_run(): void
    {
        $this->visit('2026-09-17');
        $this->visit('2026-09-18');

        (new ArchiveRangeJob('2026-09-17', '2026-09-18', true, null))->handle(app(ArchiveService::class));

        $runs = AnalyticsArchiveRun::where('run_type', ArchiveRunType::Reaggregate->value)->get();

        $this->assertCount(2, $runs, '기간의 날짜 수만큼 이력이 남아야 한다');
        $this->assertSame(
            [ArchiveRunStatus::Success->value, ArchiveRunStatus::Success->value],
            $runs->map(fn (AnalyticsArchiveRun $run): string => $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status)->all(),
        );
        $this->assertSame('manual', (string) $runs->first()->trigger);
    }

    /**
     * 하루라도 실패하면 잡이 예외로 끝나는지 확인합니다.
     *
     * 이력에는 실패가 남지만 큐가 성공으로 끝나면 아무도 모른다.
     *
     * @return void 없음
     *
     * @scenario period=range, data=all_types, trigger=manual
     *
     * @effects range_job_reports_failed_days
     */
    public function test_a_failed_day_makes_the_job_fail(): void
    {
        $this->visit('2026-09-17');

        HookManager::addAction('core.analytics.archive.day', function (): void {
            throw new RuntimeException('집계 중 확장이 던진 예외');
        });

        try {
            (new ArchiveRangeJob('2026-09-17', '2026-09-17', true, null))->handle(app(ArchiveService::class));
            $this->fail('실패한 날이 있으면 잡은 예외로 끝나야 한다');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1일이 실패했다', $e->getMessage());
        }

        $this->assertDatabaseHas('analytics_archive_runs', [
            'run_type' => ArchiveRunType::Reaggregate->value,
            'status' => ArchiveRunStatus::Failed->value,
        ]);
    }

    /**
     * 잡이 요청자와 「확정된 날 건너뛰기」를 서비스까지 그대로 넘기는지 확인합니다.
     *
     * 서비스 단위 테스트는 옵션을 직접 넣으므로, 잡이 생성자 인자를 빠뜨리거나 뒤바꿔도
     * 거기서는 드러나지 않는다 — 이력의 요청자가 비고 확정된 날이 조용히 다시 계산된다.
     *
     * @return void 없음
     *
     * @scenario period=range, data=all_types, trigger=manual
     *
     * @effects range_job_passes_requester_and_force_to_the_service
     */
    public function test_job_passes_requester_and_force_to_the_service(): void
    {
        $this->visit('2026-09-17');
        $this->visit('2026-09-18');
        app(ArchiveService::class)->archiveDay('2026-09-17', ['is_final' => true]);

        (new ArchiveRangeJob('2026-09-17', '2026-09-18', false, 7))->handle(app(ArchiveService::class));

        $runs = AnalyticsArchiveRun::where('run_type', ArchiveRunType::Reaggregate->value)
            ->orderBy('period_start')
            ->get()
            ->map(fn (AnalyticsArchiveRun $run): array => [
                'status' => $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status,
                'triggered_by' => $run->triggered_by,
            ])
            ->all();

        $this->assertSame([
            ['status' => ArchiveRunStatus::Skipped->value, 'triggered_by' => 7],
            ['status' => ArchiveRunStatus::Success->value, 'triggered_by' => 7],
        ], $runs, '확정된 17일은 건너뛰고 18일만 계산하며, 두 이력 모두 요청자를 남긴다');
    }
}
