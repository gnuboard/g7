<?php

namespace Tests\Feature\Testing\SchemaCache;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Depends;
use Tests\Support\TestingSchema\TestingSchemaCache;
use Tests\TestCase;

/**
 * 트랜잭션 테스트 안의 DDL 감지 — MySQL 은 DDL 에서 열린 트랜잭션을 암묵 커밋해 그 테스트의 행이 롤백되지 않고 남는다.
 *
 * 테스트 베이스가 tearDown 에서 "트랜잭션 수준은 남았는데 PDO 는 트랜잭션 밖" 을 보고 표시해, 다음 테스트 전에 스키마·데이터를
 * 다시 판정(잔여 행 정리)한다. 두 번째 테스트가 판정이 다시 일어났음(상태 토큰 갱신)을 확인한다.
 */
class DirtyDetectionTest extends TestCase
{
    use DatabaseTransactions;

    /** 첫 테스트가 끝날 때의 상태 토큰 */
    private static string $tokenAfterDdl = '';

    /**
     * 트랜잭션 안에서 행을 넣고 DDL 을 실행해 암묵 커밋을 일으킨다(검사용 테이블은 finally 에서 지운다).
     *
     * @scenario case=ddl_in_transaction
     *
     * @effects implicit_commit_marks_schema_dirty
     *
     * @return void 반환값 없음
     */
    public function test_ddl_inside_transaction_commits_implicitly(): void
    {
        DB::table('roles')->insert(['identifier' => 'zz-dirty-role', 'name' => json_encode(['ko' => '암묵 커밋'])]);
        $table = DB::getTablePrefix().'zz_scc_dirty';
        DB::statement("CREATE TABLE `{$table}` (id INT PRIMARY KEY)");

        try {
            $this->assertFalse(DB::connection()->getPdo()->inTransaction(), 'DDL 이 트랜잭션을 닫았다');
            self::$tokenAfterDdl = TestingSchemaCache::stateForTesting()['stateToken'];
        } finally {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /**
     * 다음 테스트 전에 다시 판정해 상태 토큰이 바뀌었고, 암묵 커밋으로 남았던 행이 정리됐다.
     *
     * @scenario case=next_test_redecides
     *
     * @effects implicit_commit_marks_schema_dirty, residual_rows_removed
     *
     * @return void 반환값 없음
     */
    #[Depends('test_ddl_inside_transaction_commits_implicitly')]
    public function test_next_test_redecides_and_cleans_committed_rows(): void
    {
        $this->assertNotSame('', self::$tokenAfterDdl);
        $this->assertNotSame(self::$tokenAfterDdl, TestingSchemaCache::stateForTesting()['stateToken']);
        $this->assertSame(0, DB::table('roles')->where('identifier', 'zz-dirty-role')->count());
    }
}
