<?php

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 마이그레이션 11표 — up/down 왕복·컬럼 주석·인덱스명·파티션 0.
 *
 * 이 테스트는 트랜잭션 격리(RefreshDatabase)를 쓰지 않는다 — DDL 은 MySQL 에서 암묵
 * 커밋이라 격리가 깨진다. 대신 마이그레이션 객체를 직접 불러 내렸다 올리므로 끝나면
 * 표가 그대로 남고, 다른 테스트의 데이터는 각자의 트랜잭션이 이미 되돌렸다.
 */
class MigrationSmokeTest extends TestCase
{
    /** 계획서 §4.3 순서의 표 이름 (접두 없음) */
    private const TABLES = [
        'analytics_action_names', 'analytics_visits', 'analytics_actions', 'analytics_conversions',
        'analytics_bot_hits', 'analytics_stats_hourly', 'analytics_stats_daily', 'analytics_dimension_stats',
        'analytics_salts', 'analytics_archive_runs', 'analytics_collect_failures',
    ];

    /** 계획서 §4.3 의 인덱스명 (PRIMARY 제외) */
    private const INDEXES = [
        'analytics_action_names' => ['uq_analytics_action_names_hash'],
        'analytics_visits' => [
            'idx_analytics_visits_visitor_last', 'idx_analytics_visits_date_type', 'idx_analytics_visits_last_action',
            'idx_analytics_visits_user', 'idx_analytics_visits_ip', 'idx_analytics_visits_ip_hash',
        ],
        'analytics_actions' => ['idx_analytics_actions_visit', 'idx_analytics_actions_name', 'idx_analytics_actions_occurred', 'idx_analytics_actions_type'],
        'analytics_conversions' => ['idx_analytics_conversions_goal', 'idx_analytics_conversions_visit', 'idx_analytics_conversions_ext'],
        'analytics_bot_hits' => ['idx_analytics_bot_hits_occurred', 'idx_analytics_bot_hits_name'],
        'analytics_stats_hourly' => ['uq_analytics_stats_hourly'],
        'analytics_stats_daily' => ['uq_analytics_stats_daily', 'idx_analytics_stats_daily_date'],
        'analytics_dimension_stats' => ['uq_analytics_dimension_stats', 'idx_analytics_dimension_stats_dim'],
        'analytics_salts' => [],
        'analytics_archive_runs' => ['idx_analytics_archive_runs_type', 'idx_analytics_archive_runs_status'],
        'analytics_collect_failures' => ['idx_analytics_collect_failures_occurred', 'idx_analytics_collect_failures_reason'],
    ];

    /**
     * 마이그레이션 파일 11개를 순서대로 돌려줍니다.
     *
     * @return array<int, string> 파일 경로
     */
    private function migrationFiles(): array
    {
        $files = glob(database_path('migrations/2026_09_16_0000*_create_analytics_*_table.php'));
        sort($files);

        return $files;
    }

    /**
     * 11표를 역순으로 내리고 순서대로 다시 올려도 같은 표가 남는다.
     *
     * @scenario visit_state=new
     *
     * @effects migrations_roll_back_and_forward_idempotently
     *
     * @return void 없음
     */
    public function test_up_down_up_round_trip(): void
    {
        $files = $this->migrationFiles();
        $this->assertCount(11, $files);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' 이 시작 시점에 없다');
        }

        foreach (array_reverse($files) as $file) {
            (require $file)->down();
        }

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' 이 down() 뒤에도 남아 있다');
        }

        foreach ($files as $file) {
            (require $file)->up();
        }

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' 이 up() 뒤에 없다');
        }

        // hasTable 가드 — 이미 있는 표에 up() 을 다시 불러도 예외가 없다.
        foreach ($files as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('analytics_visits'));
    }

    /**
     * 11표의 모든 컬럼에 주석이 있다.
     *
     * @scenario visit_state=new
     *
     * @effects migrations_roll_back_and_forward_idempotently
     *
     * @return void 없음
     */
    public function test_every_column_has_a_comment(): void
    {
        $prefix = DB::getTablePrefix();

        // 쿼리 빌더는 테이블 접두를 붙이므로 information_schema 는 원문 SQL 로 읽는다.
        $missing = collect(DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? AND COLUMN_COMMENT = ?',
            [$prefix.'analytics\_%', ''],
        ))->map(fn ($row) => $row->TABLE_NAME.'.'.$row->COLUMN_NAME)->all();

        $this->assertSame([], $missing, '주석 없는 컬럼');

        $total = (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
            [$prefix.'analytics\_%'],
        )->n;

        $this->assertGreaterThanOrEqual(140, $total, '11표 컬럼 모집단이 비어 있지 않다');
    }

    /**
     * 계획서의 인덱스명이 전부 실제 인덱스로 존재한다.
     *
     * @scenario visit_state=new
     *
     * @effects migrations_roll_back_and_forward_idempotently
     *
     * @return void 없음
     */
    public function test_index_names_match_plan(): void
    {
        $prefix = DB::getTablePrefix();

        foreach (self::INDEXES as $table => $expected) {
            $actual = collect(DB::select('SHOW INDEX FROM `'.$prefix.$table.'`'))
                ->pluck('Key_name')
                ->unique()
                ->reject(fn ($name) => $name === 'PRIMARY')
                ->values()
                ->all();

            $this->assertEqualsCanonicalizing($expected, $actual, $table.' 인덱스');
        }
    }

    /**
     * 파티션 DDL 이 마이그레이션에도 실제 표에도 없다.
     *
     * @scenario visit_state=new
     *
     * @effects migrations_roll_back_and_forward_idempotently
     *
     * @return void 없음
     */
    public function test_no_partition_sql(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $this->assertStringNotContainsStringIgnoringCase('PARTITION', file_get_contents($file), basename($file));
        }

        $prefix = DB::getTablePrefix();
        $partitioned = (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? AND PARTITION_NAME IS NOT NULL',
            [$prefix.'analytics\_%'],
        )->n;

        $this->assertSame(0, $partitioned);
    }
}
