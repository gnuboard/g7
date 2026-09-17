<?php

namespace Tests\Feature\Analytics\Api;

use App\Enums\Analytics\ArchiveRunType;
use App\Jobs\Analytics\ArchiveRangeJob;
use App\Models\Analytics\AnalyticsArchiveRun;
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
}
