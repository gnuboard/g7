<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 모듈 getDynamicTables() 테스트
 *
 * Phase 8 단일 테이블 전환 후, board_posts/board_comments/board_attachments는
 * 마이그레이션으로 관리되는 고정 테이블입니다.
 * getDynamicTables()는 더 이상 오버라이드할 필요가 없으며,
 * AbstractModule 기본값인 빈 배열 []을 반환합니다.
 */
class CleanupDynamicDataTest extends ModuleTestCase
{
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new Module;
    }

    /**
     * 단일 테이블 전환 후 getDynamicTables()는 빈 배열을 반환합니다.
     *
     * board_posts/board_comments/board_attachments는 마이그레이션으로 관리되는
     * 고정 테이블이므로 동적 테이블이 아닙니다.
     */
    public function test_get_dynamic_tables_returns_empty_after_single_table_migration(): void
    {
        $tables = $this->module->getDynamicTables();

        $this->assertIsArray($tables);
        $this->assertEmpty($tables, '단일 테이블 전환 후 동적 테이블은 없어야 합니다.');
    }

    /**
     * getDynamicTables()는 게시판 생성 유무와 관계없이 빈 배열을 반환합니다.
     */
    public function test_get_dynamic_tables_returns_empty_regardless_of_boards(): void
    {
        // 게시판이 없는 상태
        $tablesEmpty = $this->module->getDynamicTables();
        $this->assertEmpty($tablesEmpty);

        // 게시판을 생성해도 동일하게 빈 배열
        \Modules\Sirsoft\Board\Models\Board::factory()->create(['slug' => 'test-cleanup']);
        $tablesWithBoard = $this->module->getDynamicTables();

        $this->assertEmpty($tablesWithBoard);
        $this->assertEquals($tablesEmpty, $tablesWithBoard);
    }

    /**
     * board_posts/board_comments/board_attachments 테이블이 존재하는지 확인합니다.
     *
     * 이 테이블들은 동적 테이블이 아닌 마이그레이션으로 생성된 고정 테이블입니다.
     */
    public function test_single_tables_exist_as_migration_tables(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('board_posts'),
            'board_posts 테이블이 마이그레이션으로 존재해야 합니다.'
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('board_comments'),
            'board_comments 테이블이 마이그레이션으로 존재해야 합니다.'
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('board_attachments'),
            'board_attachments 테이블이 마이그레이션으로 존재해야 합니다.'
        );
    }
}
