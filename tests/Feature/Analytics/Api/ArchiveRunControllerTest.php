<?php

namespace Tests\Feature\Analytics\Api;

use App\Analytics\AnalyticsClock;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\ConversionRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Jobs\Analytics\ArchiveRangeJob;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsConversion;
use App\Services\Analytics\ArchiveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * 집계 실행 API — 화면의 「지금 집계」 버튼과 그 진행률.
 *
 * 몇 달치 재집계는 요청 하나가 몇 분씩 걸린다. 요청을 붙잡아 두는지 워커에게 넘기는지가
 * 화면이 멈추는지 아닌지를 가른다.
 *
 * @scenario endpoint=archive_runs, role=update, confirm=given
 */
class ArchiveRunControllerTest extends AnalyticsApiTestCase
{
    /**
     * 매시 집계가 그 자리에서 돌고 이력 번호를 돌려주는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=archive_runs, role=update, confirm=given
     *
     * @effects manual_archive_returns_accepted
     */
    public function test_an_hourly_run_returns_its_run_id(): void
    {
        $this->visit();

        $response = $this->actingAs($this->updateAdmin())
            ->postJson('/api/admin/analytics/archive-runs', ['run_type' => ArchiveRunType::Hour->value]);

        $response->assertStatus(202);
        $this->assertNotNull($response->json('data.run_id'));
        $this->assertFalse($response->json('data.queued'));
        $this->assertDatabaseHas('analytics_archive_runs', ['trigger' => 'manual']);
    }

    /**
     * 다른 집계가 도는 중이면 건너뛴 사실을 응답이 알리는지 확인합니다.
     *
     * 이력에는 「건너뜀」이 남는데 응답이 이력 번호만 주면 화면은 성공과 구별하지 못해
     * 「집계를 실행했습니다」를 띄운다(브라우저 실측 M7-T10).
     *
     * @return void 없음
     *
     * @scenario endpoint=archive_runs, role=update, confirm=given
     *
     * @effects manual_archive_reports_skipped_when_locked
     */
    public function test_a_locked_run_reports_that_it_was_skipped(): void
    {
        $this->visit();

        $lock = Cache::lock(ArchiveService::lockKey(AnalyticsClock::today()), 30);
        $this->assertTrue($lock->get(), '자물쇠를 먼저 잡아야 건너뜀 경로를 탄다');

        try {
            $response = $this->actingAs($this->updateAdmin())
                ->postJson('/api/admin/analytics/archive-runs', ['run_type' => ArchiveRunType::Hour->value]);

            $response->assertStatus(202);
            $this->assertSame(ArchiveRunStatus::Skipped->value, $response->json('data.status'));
            $this->assertNotNull($response->json('data.run_id'));
        } finally {
            $lock->release();
        }
    }

    /**
     * 기간 재집계가 워커로 넘어가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=archive_runs, role=update, period=valid, confirm=given
     *
     * @effects reaggregation_is_handed_to_a_worker
     */
    public function test_a_reaggregation_is_handed_to_a_worker(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->updateAdmin())->postJson('/api/admin/analytics/archive-runs', [
            'run_type' => ArchiveRunType::Reaggregate->value,
            'from' => '2026-08-01',
            'to' => '2026-08-05',
        ]);

        $response->assertStatus(202);
        $this->assertTrue($response->json('data.queued'), '몇 분짜리 작업으로 요청을 붙잡아 두면 화면이 멈춘다');

        Queue::assertPushed(ArchiveRangeJob::class);
    }

    /**
     * 재집계에 기간이 빠지면 거절되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=archive_runs, role=update, period=inverted
     *
     * @effects manual_archive_returns_accepted
     */
    public function test_a_reaggregation_without_a_range_is_rejected(): void
    {
        $admin = $this->updateAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/analytics/archive-runs', ['run_type' => ArchiveRunType::Reaggregate->value])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/api/admin/analytics/archive-runs', ['run_type' => 'whatever'])
            ->assertStatus(422);
    }

    /**
     * 진행률을 단건 조회로 물어볼 수 있는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=archive_runs, role=read
     *
     * @effects archive_run_progress_can_be_polled
     */
    public function test_progress_can_be_polled(): void
    {
        $run = AnalyticsArchiveRun::factory()->create(['progress_percent' => 42]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/archive-runs/'.$run->id);

        $response->assertOk();
        $this->assertSame(42, $response->json('data.progress_percent'));
    }

    /**
     * 없는 이력 번호가 404 인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=archive_runs, role=read
     *
     * @effects unknown_archive_run_is_not_found
     */
    public function test_an_unknown_run_is_not_found(): void
    {
        $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/archive-runs/999999')
            ->assertStatus(404);
    }

    /**
     * 이력 목록이 종류·상태로 걸러지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=archive_runs, role=read, period=valid
     *
     * @effects archive_run_progress_can_be_polled
     */
    public function test_the_history_can_be_filtered(): void
    {
        AnalyticsArchiveRun::factory()->create(['run_type' => ArchiveRunType::Hour->value]);
        AnalyticsArchiveRun::factory()->create(['run_type' => ArchiveRunType::Day->value]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/archive-runs?run_type='.ArchiveRunType::Day->value);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    /**
     * 건너뛴 실행 이력이 그 사유를 보는 사람의 언어로 싣는지 확인합니다.
     *
     * 재집계가 다른 집계에 밀려 건너뛰면 화면이 그 사실을 알려야 한다. 사유는 기록 당시 언어로
     * 저장돼 있어, 그대로 내보내면 영어 화면에 한국어 사유가 찍히고 화면은 어떤 사유인지 가릴
     * 수 없다(7.1.0 실측 O-E5 — 「건너뜀」 배지만 보이고 사유는 어디에도 없었다).
     *
     * @return void 없음
     *
     * @scenario endpoint=archive_runs, role=read
     *
     * @effects skipped_run_carries_its_reason_in_the_viewers_language
     */
    public function test_a_skipped_run_carries_its_reason_in_the_viewers_language(): void
    {
        $run = AnalyticsArchiveRun::factory()->create([
            'run_type' => ArchiveRunType::Reaggregate,
            'trigger' => 'manual',
            'status' => ArchiveRunStatus::Skipped,
            'progress_percent' => 0,
            'error' => __('analytics.archive.skipped_locked', [], 'ko'),
        ]);

        $admin = $this->readAdmin();
        $admin->forceFill(['language' => 'en'])->save();

        $row = collect(
            $this->actingAs($admin)
                ->withHeaders(['Accept-Language' => 'en'])
                ->getJson('/api/admin/analytics/archive-runs')
                ->assertOk()
                ->json('data.data'),
        )->firstWhere('id', $run->id);

        $this->assertSame('locked', $row['skip_reason'] ?? null, '화면이 사유를 고를 수 있게 사유 코드를 싣는다');
        $this->assertSame(__('analytics.archive.skipped_locked', [], 'en'), $row['error'], '사유는 보는 사람의 언어로');
    }

    /**
     * 실행 이력과 전환의 생성 시각이 다른 원장처럼 UTC 로 찍히는지 확인합니다.
     *
     * 두 표만 생성 시각을 DB 기본값(`CURRENT_TIMESTAMP`)에 맡겨, DB 세션 시간대가 서울인 서버에서
     * 같은 행의 `started_at`·`occurred_at`(UTC)과 9시간 어긋난 벽시계가 남았다(7.1.0 실측 O-E11).
     * 다른 원장 표(방문·액션·봇·실패)는 앱이 UTC 로 직접 찍는다.
     *
     * @return void 없음
     *
     * @scenario endpoint=archive_runs, role=update
     *
     * @effects ledger_created_at_is_stamped_in_utc
     */
    public function test_runs_and_conversions_stamp_created_at_in_utc(): void
    {
        $now = AnalyticsClock::now()->startOfSecond();

        $run = app(ArchiveRunRepositoryInterface::class)->create([
            'run_type' => ArchiveRunType::Hour->value,
            'period_start' => $now,
            'period_end' => $now,
            'trigger' => 'manual',
            'status' => ArchiveRunStatus::Running->value,
            'progress_percent' => 0,
            'started_at' => $now,
        ]);

        $visit = $this->visit();
        $conversion = app(ConversionRepositoryInterface::class)->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'goal_key' => 'core.user_logged_in',
            'occurred_at' => $now,
        ]);

        $storedRun = AnalyticsArchiveRun::query()->findOrFail($run->id);
        $storedConversion = AnalyticsConversion::query()->findOrFail($conversion->id);

        $this->assertLessThan(
            120,
            abs($storedRun->created_at->getTimestamp() - $storedRun->started_at->getTimestamp()),
            '실행 이력 생성 시각이 시작 시각과 같은 UTC 여야 한다',
        );
        $this->assertLessThan(
            120,
            abs($storedConversion->created_at->getTimestamp() - $storedConversion->occurred_at->getTimestamp()),
            '전환 생성 시각이 발생 시각과 같은 UTC 여야 한다',
        );
    }
}
