<?php

namespace Tests\Feature\Testing\SchemaCache;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Depends;
use Tests\Support\TestingSchema\RecordStore;
use Tests\TestCase;

/**
 * 확장 「데이터까지 삭제」 제거처럼 정규 마이그레이션의 표와 기록을 지운 채 끝나는 테스트 뒤에도 스키마를 재사용한다.
 *
 * 그런 테스트 뒤 다음 판정이 migrations 이름 집합 불일치로 전체 재생(약 60초)을 하던 결함(2026-11-03)의 회귀 시험이다.
 * 첫 테스트가 Hello 모듈 메모 표를 지우고 그 기록을 뺀다. 두 번째 테스트는 표·기록이 돌아왔고, 판정이 전체 재생 없이
 * 재사용(기록 파일 생성 시각 불변)으로 끝났음을 확인한다. 세 번째·네 번째 테스트는 게시판 제거처럼 되돌릴 수 없는
 * 마이그레이션의 기록만 남은 경우다.
 */
class RemovedMigrationRestoreTest extends TestCase
{
    use RefreshDatabase;

    /** 지웠다 되돌릴 정규 마이그레이션 */
    private const MIGRATION = '2026_04_21_000001_create_gnuboard7_hello_module_memos_table';

    /** 그 마이그레이션이 만드는 표 */
    private const TABLE = 'gnuboard7_hello_module_memos';

    /** 게시판 마이그레이션이 만들어 남기는 표(`board_mail_templates` 는 뒤 마이그레이션이 지운다) */
    private const BOARD_TABLES = [
        'board_attachments', 'board_comments', 'board_post_scraps', 'board_posts',
        'board_reaction_types', 'board_reactions', 'board_stats', 'board_types', 'board_user_notification_settings',
        'boards', 'boards_report_logs', 'boards_reports',
    ];

    /** 게시판 제거 때 `down()` 이 예외를 던져 기록이 남는 마이그레이션 */
    private const BOARD_IRREVERSIBLE = '2026_04_17_000001_remove_partitions_from_board_tables';

    /** 첫 테스트 시점 기록 파일의 생성 시각 */
    private static string $createdAt = '';

    /**
     * 확장 제거(데이터 삭제)처럼 표를 지우고 마이그레이션 기록을 뺀 채 끝낸다.
     *
     * @scenario case=removed_extension_migration
     *
     * @effects removed_migrations_restored_after_test
     *
     * @return void 반환값 없음
     */
    public function test_removes_extension_table_and_migration_record(): void
    {
        $database = (string) ((array) DB::selectOne('SELECT DATABASE() AS d'))['d'];
        self::$createdAt = (string) (RecordStore::read($database)['createdAt'] ?? '');

        Schema::dropIfExists(self::TABLE);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $this->assertNotSame('', self::$createdAt);
        $this->assertFalse(Schema::hasTable(self::TABLE));
    }

    /**
     * 다음 테스트 전에 지워진 마이그레이션만 다시 올려, 전체 재생 없이 재사용했다.
     *
     * @scenario case=removed_extension_migration
     *
     * @effects removed_migrations_restored_after_test, matching_schema_is_reused
     *
     * @return void 반환값 없음
     */
    #[Depends('test_removes_extension_table_and_migration_record')]
    public function test_next_test_reuses_schema_with_restored_migration(): void
    {
        $database = (string) ((array) DB::selectOne('SELECT DATABASE() AS d'))['d'];

        $this->assertTrue(Schema::hasTable(self::TABLE), '지워진 표가 다시 만들어졌다');
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertSame(self::$createdAt, (string) (RecordStore::read($database)['createdAt'] ?? ''), '전체 재생 없이 재사용했다(기록 생성 시각 불변)');
    }

    /**
     * 게시판 「데이터까지 삭제」 제거처럼 표는 모두 지우고, 되돌릴 수 없는 마이그레이션(파티션 제거)의 기록만 남긴다.
     *
     * 빠진 것만 다시 올리면 표가 파티션 상태로 만들어진 뒤 파티션 제거를 건너뛰어 FULLTEXT 추가가 실패하던 상태다.
     *
     * @scenario case=removed_extension_with_irreversible_migration
     *
     * @effects removed_migrations_restored_after_test
     *
     * @return void 반환값 없음
     */
    #[Depends('test_next_test_reuses_schema_with_restored_migration')]
    public function test_removes_board_tables_keeping_irreversible_migration_record(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::BOARD_TABLES as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        DB::table('migrations')
            ->where('migration', '!=', self::BOARD_IRREVERSIBLE)
            ->whereIn('migration', array_map(
                static fn (string $file): string => basename($file, '.php'),
                glob(base_path('modules/_bundled/sirsoft-board/database/migrations/*.php')) ?: []
            ))
            ->delete();

        $this->assertFalse(Schema::hasTable('board_posts'));
        $this->assertSame(1, DB::table('migrations')->where('migration', self::BOARD_IRREVERSIBLE)->count());
    }

    /**
     * 확장 디렉터리를 처음부터 다시 올려, 전체 재생 없이 재사용했다.
     *
     * @scenario case=removed_extension_with_irreversible_migration
     *
     * @effects removed_migrations_restored_after_test, matching_schema_is_reused
     *
     * @return void 반환값 없음
     */
    #[Depends('test_removes_board_tables_keeping_irreversible_migration_record')]
    public function test_next_test_reuses_schema_with_rebuilt_extension(): void
    {
        $database = (string) ((array) DB::selectOne('SELECT DATABASE() AS d'))['d'];

        foreach (self::BOARD_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} 표가 다시 만들어졌다");
        }
        $this->assertSame(self::$createdAt, (string) (RecordStore::read($database)['createdAt'] ?? ''), '전체 재생 없이 재사용했다(기록 생성 시각 불변)');
    }
}
