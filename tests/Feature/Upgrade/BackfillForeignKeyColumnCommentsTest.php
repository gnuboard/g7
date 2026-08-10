<?php

namespace Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_0_6\Migrations\BackfillForeignKeyColumnComments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * 외래키 컬럼 comment 회귀 — 소스 교정(신규 설치)과 백필(기설치본) 양쪽.
 *
 * 결함 시나리오 (2026-07-29 실측으로 입증):
 *   `$table->foreignId('created_by')->constrained('users')->nullOnDelete()->comment('등록자 ID')`
 *   형태는 comment 가 컬럼이 아니라 외래키 정의에 부착되어 DB 에 방출되지 않는다.
 *   `ForeignIdColumnDefinition::constrained()` 가 `references()->on()`, 즉 `ForeignKeyDefinition`
 *   을 반환하기 때문이며, MySQL grammar 의 compileForeign 은 comment 를 다루지 않는다.
 *
 *   실측 (g7_3, 교정 전):
 *     g7_menus.name       → [메뉴 이름 (다국어 JSON)]
 *     g7_menus.created_by → []            ← 규정("전 컬럼 한국어 comment") 위반
 *
 * 본 테스트는 두 축을 함께 잠근다:
 *   1. 신규 설치 — 마이그레이션이 만든 스키마에 외래키 컬럼 comment 가 실재하는가
 *      (교정 전이라면 실패한다)
 *   2. 기설치본 — 백필 스텝이 빈 comment 만 채우고 운영자가 넣은 값은 보존하는가
 */
class BackfillForeignKeyColumnCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 클래스는 AbstractUpgradeStep::dataMigrations() 가 동적으로 require
        // 하므로 일반 autoload 대상이 아님 — 테스트에서는 명시적으로 require_once.
        require_once base_path('upgrades/data/7.0.6/migrations/03_BackfillForeignKeyColumnComments.php');
    }

    /**
     * 테스트 시작 전 MySQL 계열이 아니면 스킵 대신 조기 종료 판단에 쓰는 플래그.
     *
     * @return bool MySQL 계열이면 true
     */
    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * 백필 스텝이 동결해 둔 대상 목록을 꺼냅니다.
     *
     * 테스트가 대상 목록을 따로 옮겨 적지 않게 하여, 스텝에 대상이 추가되면
     * 본 테스트의 검증 범위도 자동으로 함께 넓어지도록 합니다.
     *
     * @return array<string, array<string, string>> 테이블 => [컬럼 => 설명]
     */
    private function targets(): array
    {
        return (new ReflectionClass(BackfillForeignKeyColumnComments::class))
            ->getConstant('TARGETS');
    }

    /**
     * 컬럼의 현재 comment 를 조회합니다.
     *
     * @param  string  $table  프리픽스 없는 테이블명
     * @param  string  $column  컬럼명
     * @return string|null comment (컬럼 부재 시 null)
     */
    private function commentOf(string $table, string $column): ?string
    {
        $rows = DB::select(
            'SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getTablePrefix().$table, $column],
        );

        return isset($rows[0]) ? (string) $rows[0]->COLUMN_COMMENT : null;
    }

    /**
     * 컬럼 comment 를 강제로 지정합니다 (기설치본 상태 재현용).
     *
     * @param  string  $table  프리픽스 없는 테이블명
     * @param  string  $column  컬럼명
     * @param  string  $comment  설정할 comment (빈 문자열이면 교정 전 상태 재현)
     */
    private function forceComment(string $table, string $column, string $comment): void
    {
        $prefixed = DB::getTablePrefix().$table;
        $meta = DB::select(
            'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$prefixed, $column],
        )[0];

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY COLUMN `%s` %s %s COMMENT %s',
            $prefixed,
            $column,
            $meta->COLUMN_TYPE,
            $meta->IS_NULLABLE === 'YES' ? 'NULL' : 'NOT NULL',
            DB::connection()->getPdo()->quote($comment),
        ));
    }

    /**
     * 백필 스텝을 실행합니다.
     */
    private function runBackfill(): void
    {
        (new BackfillForeignKeyColumnComments)->run(
            new UpgradeContext(fromVersion: '7.0.5', toVersion: '7.0.6'),
        );
    }

    /**
     * 마이그레이션이 만든 스키마에 외래키 컬럼 comment 가 실재해야 한다.
     *
     * 교정 전 소스(`->constrained()->comment()`)라면 이 단언이 전부 실패한다.
     */
    public function test_migrations_emit_comments_on_foreign_key_columns(): void
    {
        if (! $this->isMysql()) {
            $this->markTestSkipped('컬럼 comment 검증은 MySQL 계열에서만 의미가 있습니다.');
        }

        $missing = [];

        foreach ($this->targets() as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $comment = $this->commentOf($table, $column);

                if ($comment === null) {
                    continue; // 해당 테이블이 없는 구성 — 검증 대상 아님
                }

                if (trim($comment) === '') {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'FK 컬럼 comment 누락 — ->comment() 가 ->constrained() 뒤에 체인되었는지 확인: '
                .implode(', ', $missing),
        );
    }

    /**
     * 기설치본 재현 — 비어 있는 comment 를 백필이 채워야 한다.
     */
    public function test_backfill_fills_empty_comment(): void
    {
        if (! $this->isMysql()) {
            $this->markTestSkipped('컬럼 comment 검증은 MySQL 계열에서만 의미가 있습니다.');
        }

        $this->forceComment('menus', 'created_by', '');
        $this->assertSame('', $this->commentOf('menus', 'created_by'));

        $this->runBackfill();

        $this->assertSame('등록자 ID', $this->commentOf('menus', 'created_by'));
    }

    /**
     * 운영자가 직접 넣어 둔 comment 는 덮어쓰지 않아야 한다.
     */
    public function test_backfill_preserves_operator_written_comment(): void
    {
        if (! $this->isMysql()) {
            $this->markTestSkipped('컬럼 comment 검증은 MySQL 계열에서만 의미가 있습니다.');
        }

        $this->forceComment('menus', 'created_by', '운영자가 직접 적어 둔 설명');

        $this->runBackfill();

        $this->assertSame('운영자가 직접 적어 둔 설명', $this->commentOf('menus', 'created_by'));
    }

    /**
     * 재실행해도 결과가 같아야 한다 (멱등).
     */
    public function test_backfill_is_idempotent(): void
    {
        if (! $this->isMysql()) {
            $this->markTestSkipped('컬럼 comment 검증은 MySQL 계열에서만 의미가 있습니다.');
        }

        $this->forceComment('menus', 'created_by', '');

        $this->runBackfill();
        $first = $this->commentOf('menus', 'created_by');

        $this->runBackfill();
        $second = $this->commentOf('menus', 'created_by');

        $this->assertSame('등록자 ID', $first);
        $this->assertSame($first, $second);
    }

    /**
     * 설명 외의 컬럼 속성(자료형·NULL 허용)은 바뀌지 않아야 한다.
     */
    public function test_backfill_does_not_alter_column_definition(): void
    {
        if (! $this->isMysql()) {
            $this->markTestSkipped('컬럼 comment 검증은 MySQL 계열에서만 의미가 있습니다.');
        }

        $before = DB::select(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getTablePrefix().'menus', 'created_by'],
        )[0];

        $this->forceComment('menus', 'created_by', '');
        $this->runBackfill();

        $after = DB::select(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getTablePrefix().'menus', 'created_by'],
        )[0];

        $this->assertEquals($before->COLUMN_TYPE, $after->COLUMN_TYPE);
        $this->assertEquals($before->IS_NULLABLE, $after->IS_NULLABLE);
        $this->assertEquals($before->COLUMN_DEFAULT, $after->COLUMN_DEFAULT);
        $this->assertEquals($before->EXTRA, $after->EXTRA);
    }

    /**
     * 외래키 제약이 백필 이후에도 유지되어야 한다.
     */
    public function test_backfill_keeps_foreign_key_constraint(): void
    {
        if (! $this->isMysql()) {
            $this->markTestSkipped('컬럼 comment 검증은 MySQL 계열에서만 의미가 있습니다.');
        }

        $countFk = fn (): int => (int) DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [DB::getTablePrefix().'menus', 'created_by'],
        )[0]->c;

        $before = $countFk();
        $this->forceComment('menus', 'created_by', '');
        $this->runBackfill();

        $this->assertSame($before, $countFk());
    }
}
