<?php

namespace Tests\Feature\Testing\SchemaCache;

use Illuminate\Support\Facades\DB;
use Tests\Support\TestingSchema\StructureChecksum;
use Tests\TestCase;

/**
 * DB 실물 구조 지문 — 구조가 바뀌면 달라지고, 행·자동 증가 번호만 바뀌면 그대로다.
 *
 * 검사용 테이블(`zz_scc_*`)을 테스트 DB 에 만들고 `finally` 에서 지워, 끝난 뒤 구조는 시작 전과 같다(마지막에 단언).
 * DDL 을 쓰므로 트랜잭션 트레이트를 쓰지 않는다.
 */
class StructureChecksumTest extends TestCase
{
    /**
     * 지금 테스트 DB 의 구조 지문을 계산합니다.
     *
     * @return string 구조 지문
     */
    private function checksum(): string
    {
        return StructureChecksum::compute(DB::connection(), DB::connection()->getDatabaseName());
    }

    /**
     * 검사용 부모·자식 테이블을 만들고 본문을 실행한 뒤 반드시 지웁니다.
     *
     * @param  callable(string $parent, string $child): void  $body  검사 본문(접두어 포함 테이블 이름을 받는다)
     * @return void 반환값 없음
     */
    private function withTables(callable $body): void
    {
        $prefix = DB::getTablePrefix();
        $parent = $prefix.'zz_scc_parent';
        $child = $prefix.'zz_scc_child';
        DB::statement("DROP TABLE IF EXISTS `{$child}`");
        DB::statement("DROP TABLE IF EXISTS `{$parent}`");
        DB::statement("CREATE TABLE `{$parent}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL COMMENT '이름') ENGINE=InnoDB");
        DB::statement("CREATE TABLE `{$child}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id BIGINT UNSIGNED NOT NULL, CONSTRAINT `{$child}_fk` FOREIGN KEY (parent_id) REFERENCES `{$parent}` (id) ON DELETE CASCADE) ENGINE=InnoDB");

        try {
            $body($parent, $child);
        } finally {
            DB::statement("DROP TABLE IF EXISTS `{$child}`");
            DB::statement("DROP TABLE IF EXISTS `{$parent}`");
        }
    }

    /**
     * 두 번 계산하면 같고, 행을 넣고 롤백해 자동 증가 번호만 바뀌어도 같다. 끝난 뒤 구조는 시작 전과 같다.
     *
     * @scenario case=stable
     *
     * @effects checksum_ignores_rows_and_auto_increment
     *
     * @return void 반환값 없음
     */
    public function test_stable_across_rows_and_auto_increment(): void
    {
        $start = $this->checksum();

        $this->withTables(function (string $parent): void {
            $first = $this->checksum();
            $this->assertSame($first, $this->checksum(), '두 번 계산');

            DB::beginTransaction();
            DB::table(substr($parent, strlen(DB::getTablePrefix())))->insert(['name' => 'x']);
            DB::rollBack();

            $this->assertSame($first, $this->checksum(), '자동 증가 번호만 바뀜');
        });

        $this->assertSame($start, $this->checksum(), '검사용 테이블을 지운 뒤 구조가 시작 전과 같다');
    }

    /**
     * 컬럼 추가·인덱스 추가·FK ON DELETE 규칙·컬럼 주석 변경을 각각 잡는다.
     *
     * @scenario case=detects_changes
     *
     * @effects checksum_detects_schema_drift
     *
     * @return void 반환값 없음
     */
    public function test_detects_column_index_fk_and_comment_changes(): void
    {
        $this->withTables(function (string $parent, string $child): void {
            $seen = [$this->checksum()];

            DB::statement("ALTER TABLE `{$parent}` ADD COLUMN extra INT NULL");
            $seen[] = $this->checksum();

            DB::statement("ALTER TABLE `{$parent}` ADD INDEX `{$parent}_name_idx` (name)");
            $seen[] = $this->checksum();

            DB::statement("ALTER TABLE `{$child}` DROP FOREIGN KEY `{$child}_fk`");
            DB::statement("ALTER TABLE `{$child}` ADD CONSTRAINT `{$child}_fk` FOREIGN KEY (parent_id) REFERENCES `{$parent}` (id) ON DELETE RESTRICT");
            $seen[] = $this->checksum();

            DB::statement("ALTER TABLE `{$parent}` MODIFY name VARCHAR(50) NOT NULL COMMENT '바뀐 이름'");
            $seen[] = $this->checksum();

            $this->assertCount(5, array_unique($seen), '변경마다 지문이 달라야 한다: '.implode(',', $seen));
        });
    }

    /**
     * 결과가 1024자를 넘어도 두 번 계산하면 같고, 1024자 뒤의 한 글자 차이도 잡는다(문자열 집계 절단이 없다).
     *
     * @scenario case=long_definitions
     *
     * @effects checksum_untruncated_beyond_1024_chars
     *
     * @return void 반환값 없음
     */
    public function test_results_beyond_1024_chars_are_stable_and_untruncated(): void
    {
        $this->withTables(function (string $parent): void {
            $long = str_repeat('가', 1000);
            DB::statement("ALTER TABLE `{$parent}` ADD COLUMN c1 INT NULL COMMENT '{$long}', ADD COLUMN c2 INT NULL COMMENT '{$long}'");

            $first = $this->checksum();
            $this->assertSame($first, $this->checksum(), '1024자 초과 결과를 두 번 계산');

            DB::statement("ALTER TABLE `{$parent}` MODIFY c2 INT NULL COMMENT '".str_repeat('가', 999)."나'");
            $this->assertNotSame($first, $this->checksum(), '1024자 뒤 마지막 한 글자 차이');
        });
    }
}
