<?php

namespace Tests\Feature\Testing\SchemaCache;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestingSchema\DataReset;
use Tests\Support\TestingSchema\RecordStore;
use Tests\Support\TestingSchema\TestingSchemaCache;
use Tests\TestCase;

/**
 * 재사용 스키마의 잔여 행 정리 — 커밋된 잔여 행을 지우고 마이그레이션 기준 행을 되돌린다.
 *
 * `migrations` 기록은 전체 재생 직후의 id·이름·batch 로 되돌리고, 빈 테이블의 자동 증가 번호는 유지하며, 도중에 실패해도 FK 검사를 다시 켠다.
 * 정리는 실제로 커밋하므로 트랜잭션 트레이트를 쓰지 않고, 스키마 판정만 받는다(needsTestingSchema). 끝나면 다음 테스트가 다시 판정하게 한다.
 */
class DataResetTest extends TestCase
{
    /**
     * 트랜잭션 트레이트 없이도 테스트 스키마 판정을 받습니다.
     *
     * @return bool 늘 true
     */
    protected function needsTestingSchema(): bool
    {
        return true;
    }

    /**
     * 다음 테스트가 스키마·데이터 상태를 다시 판정하게 표시합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TestingSchemaCache::markDirty();
        parent::tearDown();
    }

    /**
     * 현재 기록의 기준 행 스냅숏을 읽습니다.
     *
     * @return array<string, mixed> 기준 행
     */
    private function baseline(): array
    {
        $record = RecordStore::read(DB::connection()->getDatabaseName());
        $this->assertNotNull($record, '스키마 판정 뒤에는 기록이 있어야 한다');

        return (array) $record['baseline'];
    }

    /**
     * 커밋된 잔여 행을 지우고 기준 행(개인정보 처리방침 첫 판)을 되돌리며, migrations 기록 수는 그대로다.
     *
     * @scenario case=residual_rows
     *
     * @effects residual_rows_removed, baseline_rows_restored, migrations_record_count_kept
     *
     * @return void 반환값 없음
     */
    public function test_removes_residual_rows_and_restores_baseline(): void
    {
        $db = DB::connection()->getDatabaseName();
        $prefix = DB::getTablePrefix();
        $baseline = $this->baseline();
        $migrations = DB::table('migrations')->count();

        DB::table('roles')->insert(['identifier' => 'zz-residual-role', 'name' => json_encode(['ko' => '잔여'])]);
        foreach (array_keys($baseline) as $table) {
            DB::statement('DELETE FROM `'.$table.'`');
        }

        DataReset::run(DB::connection(), $db, $baseline);

        $this->assertSame(0, DB::table('roles')->where('identifier', 'zz-residual-role')->count());
        foreach ($baseline as $table => $snapshot) {
            $this->assertSame(count($snapshot['rows']), (int) DB::selectOne('SELECT COUNT(*) AS n FROM `'.$table.'`')->n, $table.' 기준 행');
        }
        $this->assertSame($migrations, DB::table('migrations')->count());
        $this->assertArrayHasKey($prefix.'gdpr_policy_versions', $baseline, '마이그레이션이 넣는 기준 행 테이블');
    }

    /**
     * 테스트가 바꿔 둔 `migrations` 의 batch 구성을 전체 재생 직후 상태로 되돌린다.
     *
     * 2026-11-03 실측: DDL 왕복 시험(`migrate:rollback --path` → `migrate --path`)이 그 마이그레이션을 batch 2 로 옮긴 채
     * 끝나면, 스키마를 재사용하는 다음 실행의 다른 왕복 시험은 rollback 이 마지막 batch(남의 마이그레이션)만 보고 아무것도
     * 되돌리지 않아 실패했다. 구조 지문·이름 집합은 batch 를 보지 않으므로 정리 단계가 되돌려야 한다.
     *
     * @scenario case=migration_batches
     *
     * @effects migration_batches_restored_to_fresh_layout
     *
     * @return void 반환값 없음
     */
    public function test_restores_migration_batches_to_fresh_layout(): void
    {
        $db = DB::connection()->getDatabaseName();
        $names = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        $lastId = (int) DB::table('migrations')->max('id');

        try {
            DB::table('migrations')->where('id', $lastId)->update(['batch' => 2]);

            DataReset::run(DB::connection(), $db, $this->baseline());

            // 전체 재생(migrate:fresh)은 모든 마이그레이션을 batch 1 하나로 기록한다
            $this->assertSame([1], DB::table('migrations')->distinct()->orderBy('batch')->pluck('batch')->map(fn ($b) => (int) $b)->all());
            $this->assertSame($names, DB::table('migrations')->orderBy('migration')->pluck('migration')->all());
        } finally {
            DB::table('migrations')->where('id', $lastId)->update(['batch' => 1]);
        }
    }

    /**
     * 비어 있는 테이블의 자동 증가 번호는 정리 뒤에도 이어진다(초기화하지 않는다).
     *
     * @scenario case=auto_increment
     *
     * @effects empty_table_auto_increment_kept
     *
     * @return void 반환값 없음
     */
    public function test_keeps_auto_increment_of_cleared_tables(): void
    {
        $db = DB::connection()->getDatabaseName();
        $id = DB::table('roles')->insertGetId(['identifier' => 'zz-ai-role', 'name' => json_encode(['ko' => '번호'])]);

        DataReset::run(DB::connection(), $db, $this->baseline());

        $next = DB::table('roles')->insertGetId(['identifier' => 'zz-ai-role-2', 'name' => json_encode(['ko' => '번호2'])]);
        $this->assertGreaterThan($id, $next);
        DB::table('roles')->whereIn('identifier', ['zz-ai-role', 'zz-ai-role-2'])->delete();
    }

    /**
     * 기준 행 복원이 도중에 실패해도 FOREIGN_KEY_CHECKS 는 1 로 돌아온다.
     *
     * @scenario case=failure
     *
     * @effects foreign_key_checks_restored_on_failure
     *
     * @return void 반환값 없음
     */
    public function test_foreign_key_checks_restored_after_failure(): void
    {
        $db = DB::connection()->getDatabaseName();
        $broken = [DB::getTablePrefix().'roles' => ['columns' => ['no_such_column'], 'b64' => [], 'rows' => [['x']]]];

        try {
            DataReset::run(DB::connection(), $db, $broken);
            $this->fail('없는 컬럼 복원은 실패해야 한다');
        } catch (QueryException) {
            // 기대한 실패
        }

        $this->assertSame(1, (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS v')->v);
    }
}
