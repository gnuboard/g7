<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Migrations;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * boards.secret_reply_visibility 마이그레이션 왕복 테스트 (#789 D19)
 *
 * 모듈 삭제(delete_data)·업데이트 재실행에서 up → down → up 이 깨지지 않아야 하고, 기존 게시판은
 * 기본값 thread_path 로 채워져야 한다. ADD/DROP COLUMN(DDL)은 암묵 커밋이라 이 클래스는 트랜잭션을
 * 열지 않고, 만든 행을 finally 에서 지우며 마지막에 반드시 up 상태로 되돌린다.
 *
 * @scenario link=board-secret-thread-visibility
 */
class SecretReplyVisibilityMigrationTest extends ModuleTestCase
{
    private const MIGRATION = 'modules/_bundled/sirsoft-board/database/migrations/2026_10_15_000001_add_secret_reply_visibility_to_boards_table.php';

    /**
     * DDL 이 암묵 커밋하므로 이 클래스는 트랜잭션을 열지 않는다.
     *
     * @return void 반환값 없음
     */
    public function beginDatabaseTransaction(): void {}

    /**
     * up → down → up 왕복과 기존 행 기본값 채움을 확인합니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects secret_reply_visibility_migration_round_trips
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function migration_round_trips_and_backfills_default(): void
    {
        $migration = require base_path(self::MIGRATION);
        $boardId = null;

        try {
            $this->assertTrue(Schema::hasColumn('boards', 'secret_reply_visibility'));

            $migration->down();
            $this->assertFalse(Schema::hasColumn('boards', 'secret_reply_visibility'));

            // 컬럼이 없던 시절의 게시판
            $boardId = DB::table('boards')->insertGetId([
                'name' => json_encode(['ko' => '마이그레이션 프로브', 'en' => 'probe']),
                'slug' => 'srv-migration-probe',
                'type' => 'basic',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration->up();
            $this->assertTrue(Schema::hasColumn('boards', 'secret_reply_visibility'));
            $this->assertSame('thread_path', DB::table('boards')->where('id', $boardId)->value('secret_reply_visibility'));

            // 이미 있으면 up 은 건너뛴다 (재실행 안전)
            $migration->up();
        } finally {
            if ($boardId !== null) {
                DB::table('boards')->where('id', $boardId)->delete();
            }
            if (! Schema::hasColumn('boards', 'secret_reply_visibility')) {
                $migration->up();
            }
        }
    }
}
