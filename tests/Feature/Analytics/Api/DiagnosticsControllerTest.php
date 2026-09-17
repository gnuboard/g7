<?php

namespace Tests\Feature\Analytics\Api;

use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\CollectFailureReason;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsCollectFailure;

/**
 * 진단 API — 「왜 아무것도 안 세어지는가」에 답하는 화면.
 *
 * 수집 실패는 전부 조용하다. 물어볼 곳이 없으면 운영자는 기능이 고장 난 줄만 알기 때문에,
 * 이 응답에 무엇이 들어 있는지가 곧 진단의 범위다.
 *
 * @scenario endpoint=diagnostics, role=read
 */
class DiagnosticsControllerTest extends AnalyticsApiTestCase
{
    /**
     * 진단이 수집·큐·집계·저장·환경을 함께 보고하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects diagnostics_report_queue_archive_and_proxy_state
     */
    public function test_diagnostics_cover_every_silent_failure_path(): void
    {
        $this->visit();

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/diagnostics');

        $response->assertOk();
        $data = $response->json('data');

        foreach (['collection', 'queue', 'archive', 'storage', 'geoip', 'trusted_proxy', 'broadcasting', 'cloud_ranges'] as $section) {
            $this->assertArrayHasKey($section, $data, "진단에 {$section} 이 빠지면 그 실패를 물어볼 곳이 없다");
        }

        $this->assertArrayHasKey('failures_24h', $data);
        $this->assertArrayHasKey('worker_hint', $data['queue']);
        $this->assertArrayHasKey('backlogged', $data['queue']);
        $this->assertArrayHasKey('lag_hours', $data['archive']);
        $this->assertArrayHasKey('excluded_today', $data['collection']);
        $this->assertSame(1, $data['collection']['tracked_today']);
    }

    /**
     * 집계가 돌았으면 그 시각이 보고되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects diagnostics_report_queue_archive_and_proxy_state
     */
    public function test_archive_progress_is_reported(): void
    {
        AnalyticsArchiveRun::factory()->create([
            'run_type' => ArchiveRunType::Day->value,
            'status' => ArchiveRunStatus::Success->value,
            'finished_at' => now('UTC')->subHours(3),
        ]);

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/diagnostics');

        $response->assertOk();
        $this->assertNotNull($response->json('data.archive.last_day_run'));
        $this->assertSame(3, $response->json('data.archive.lag_hours'), '집계가 밀리면 화면 숫자가 그 시점에 멈춘다');
    }

    /**
     * 적재 실패 목록이 사유로 걸러지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=collect_failures, role=read, period=valid
     *
     * @effects collect_failures_can_be_filtered_by_reason
     */
    public function test_collect_failures_can_be_filtered(): void
    {
        AnalyticsCollectFailure::factory()->create(['reason' => CollectFailureReason::Validation->value]);
        AnalyticsCollectFailure::factory()->create(['reason' => CollectFailureReason::DbError->value]);

        $admin = $this->readAdmin();

        $all = $this->actingAs($admin)->getJson('/api/admin/analytics/collect-failures');
        $all->assertOk();
        $this->assertSame(2, $all->json('data.pagination.total'));

        $filtered = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/collect-failures?reason='.CollectFailureReason::Validation->value);
        $this->assertSame(1, $filtered->json('data.pagination.total'));

        $this->actingAs($admin)
            ->getJson('/api/admin/analytics/collect-failures?reason=there_is_no_such_reason')
            ->assertStatus(422);
    }
}
