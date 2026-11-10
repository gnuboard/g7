<?php

namespace Tests\Feature\Testing\SchemaCache;

use Illuminate\Support\Facades\DB;
use Tests\Support\TestingSchema\ColumnOrder;
use Tests\Support\TestingSchema\StructureChecksum;
use Tests\TestCase;

/**
 * 컬럼 순서 복원 — down()·up() 재실행으로 자리가 바뀐 컬럼을 정의 그대로 원래 자리로 옮겨 구조 지문을 되돌린다.
 *
 * 검사용 테이블(`zz_scc_order`)을 만들고 `finally` 에서 지운다. DDL 을 쓰므로 트랜잭션 트레이트를 쓰지 않는다.
 */
class ColumnOrderRestoreTest extends TestCase
{
    /**
     * 자리가 바뀐 컬럼(기본값·주석·NOT NULL·시각 기본값 포함)을 원래 순서로 되돌리면 구조 지문이 처음과 같다.
     *
     * @scenario case=column_order_restore
     *
     * @effects column_order_restored_with_same_definition
     *
     * @return void 반환값 없음
     */
    public function test_restores_original_order_and_definition(): void
    {
        $db = DB::connection()->getDatabaseName();
        $table = DB::getTablePrefix().'zz_scc_order';
        DB::statement("DROP TABLE IF EXISTS `{$table}`");
        DB::statement("CREATE TABLE `{$table}` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            a VARCHAR(20) NOT NULL DEFAULT 'x' COMMENT '첫째',
            b TINYINT(1) NOT NULL DEFAULT 0 COMMENT '둘째',
            c TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '셋째',
            d TEXT NULL
        ) ENGINE=InnoDB");

        try {
            $before = StructureChecksum::compute(DB::connection(), $db);
            $order = ColumnOrder::capture(DB::connection(), ['zz_scc_order']);

            DB::statement("ALTER TABLE `{$table}` MODIFY b TINYINT(1) NOT NULL DEFAULT 0 COMMENT '둘째' AFTER d");
            $this->assertNotSame($before, StructureChecksum::compute(DB::connection(), $db), '자리를 옮기면 구조 지문이 달라진다');

            $this->assertSame([$table], ColumnOrder::restore(DB::connection(), $order));
            $this->assertSame($before, StructureChecksum::compute(DB::connection(), $db));
            $this->assertSame([], ColumnOrder::restore(DB::connection(), $order), '이미 같은 순서면 손대지 않는다');
        } finally {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
