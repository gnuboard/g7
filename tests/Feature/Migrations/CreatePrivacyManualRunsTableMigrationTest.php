<?php

namespace Tests\Feature\Migrations;

use App\Contracts\Repositories\PrivacyManualRunRepositoryInterface;
use App\Enums\PrivacyManualRunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * privacy_manual_runs 마이그레이션 테스트 (이슈 #636 후속 — 개인정보 조치 직권 실행 기록).
 *
 * 테이블·컬럼 생성, up→down→up 왕복, 그리고 이 테이블을 읽는 저장소 호출(진행 중 기록 조회·대상별 최신 기록)이
 * 새로 만든 스키마 위에서 그대로 동작하는지를 고정한다 — 스키마만 맞고 저장소 쿼리가 깨지는 유형을 막는다.
 *
 * @scenario target_kind=retention_handler, toggle=off, actor=runner
 *
 * @effects run_record_captures_count_and_failure
 */
class CreatePrivacyManualRunsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** 마이그레이션 파일 경로 */
    private const MIGRATION = 'database/migrations/2026_09_25_000001_create_privacy_manual_runs_table.php';

    /**
     * 테이블과 컬럼이 만들어진다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=retention_handler, toggle=off, actor=runner
     *
     * @effects run_record_captures_count_and_failure
     */
    public function test_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('privacy_manual_runs'));
        $this->assertTrue(Schema::hasColumns('privacy_manual_runs', [
            'id', 'target', 'status', 'skip_waiting', 'requested_by', 'processed_count', 'remaining_count',
            'mode', 'error', 'queued_at', 'started_at', 'finished_at', 'created_at', 'updated_at',
        ]));
    }

    /**
     * down 은 테이블을 지우고 up 은 다시 만들며, 두 번 up 해도 실패하지 않는다. 다시 만든 스키마에서 저장소 조회가 동작한다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=retention_handler, toggle=off, actor=runner
     *
     * @effects run_record_captures_count_and_failure
     */
    public function test_down_then_up_roundtrip_keeps_repository_queries_working(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertFalse(Schema::hasTable('privacy_manual_runs'));

        $migration->up();
        $migration->up(); // 멱등 — 이미 있으면 건너뛴다
        $this->assertTrue(Schema::hasTable('privacy_manual_runs'));

        $repository = app(PrivacyManualRunRepositoryInterface::class);
        $run = $repository->create([
            'target' => 'retention:user-withdrawn',
            'status' => PrivacyManualRunStatus::Queued->value,
            'skip_waiting' => false,
            'queued_at' => now(),
        ]);

        $this->assertSame($run->id, $repository->findActiveByTarget('retention:user-withdrawn')?->id);
        $this->assertTrue($repository->updateIfStatus($run->id, PrivacyManualRunStatus::Queued->value, ['status' => PrivacyManualRunStatus::Completed->value, 'processed_count' => 5]));
        $this->assertFalse($repository->updateIfStatus($run->id, PrivacyManualRunStatus::Queued->value, ['status' => PrivacyManualRunStatus::Failed->value]), '이미 닫힌 기록은 다시 열지 않는다');
        $this->assertNull($repository->findActiveByTarget('retention:user-withdrawn'));
        $this->assertSame(5, $repository->latestByTargets(['retention:user-withdrawn'])['retention:user-withdrawn']->processed_count);
    }
}
