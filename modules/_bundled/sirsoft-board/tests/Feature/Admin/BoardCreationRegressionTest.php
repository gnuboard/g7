<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__ . '/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 보드 생성/삭제 회귀 테스트 (이슈 #269 / #12)
 *
 * 파티션 스키마 폐지(1.0.0-beta.3) 후 회귀 방지:
 * - 잔여 파티션 DDL 호출로 인한 보드 생성 실패가 다시 발생하지 않도록 방어
 * - BoardService의 create/delete 경로에서 파티션 관련 쿼리가 실행되지 않는지 검증
 * - Repository 인터페이스에서 파티션 메서드가 제거되었는지 검증
 */
class BoardCreationRegressionTest extends ModuleTestCase
{
    /**
     * DatabaseTransactions 비활성화 (수동 정리 경로 보존).
     */
    public function beginDatabaseTransaction(): void
    {
        // 수동 정리 모드
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('board_types')) {
            $this->artisan('migrate', [
                '--path' => $this->getModuleBasePath() . '/database/migrations',
                '--realpath' => true,
            ]);
        }

        BoardType::firstOrCreate(
            ['slug' => 'basic'],
            ['name' => ['ko' => '기본', 'en' => 'Basic'], 'is_active' => true, 'is_default' => true]
        );
    }

    protected function tearDown(): void
    {
        $slugs = ['regress-create', 'regress-create-delete'];

        foreach ($slugs as $slug) {
            $permIds = Permission::where('identifier', 'like', "sirsoft-board.{$slug}.%")->pluck('id');
            if ($permIds->isNotEmpty()) {
                DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
                Permission::whereIn('id', $permIds)->delete();
            }

            $roleIds = Role::where('identifier', 'like', "sirsoft-board.{$slug}.%")->pluck('id');
            if ($roleIds->isNotEmpty()) {
                DB::table('user_roles')->whereIn('role_id', $roleIds)->delete();
                Role::whereIn('id', $roleIds)->delete();
            }

            Board::where('slug', $slug)->forceDelete();
        }

        parent::tearDown();
    }

    /**
     * 클린 환경에서 보드 생성이 SQL 오류 없이 성공해야 합니다.
     *
     * beta.2 회귀 시나리오: ALTER TABLE ADD PARTITION 쿼리가 실행되어 실패 → 보드 롤백 → 생성 불가.
     * beta.3 기대 동작: 파티션 DDL 미실행, 보드 레코드/역할/권한 정상 생성.
     */
    public function test_create_board_succeeds_without_partition_ddl(): void
    {
        $service = app(BoardService::class);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $board = $service->createBoard([
            'slug' => 'regress-create',
            'name' => ['ko' => '회귀 테스트', 'en' => 'Regress Test'],
            'type' => 'basic',
        ]);

        $this->assertInstanceOf(Board::class, $board);
        $this->assertSame('regress-create', $board->slug);
        $this->assertDatabaseHas('boards', ['id' => $board->id, 'slug' => 'regress-create']);

        // 파티션 관련 DDL이 한 번도 실행되지 않았는지 확인
        $partitionQueries = array_filter(
            $queries,
            fn ($sql) => stripos($sql, 'PARTITION') !== false
        );
        $this->assertEmpty(
            $partitionQueries,
            '보드 생성 중 파티션 관련 DDL이 실행되었습니다: '.implode(' | ', $partitionQueries)
        );
    }

    /**
     * 보드 삭제도 파티션 DDL 없이 성공해야 합니다.
     */
    public function test_delete_board_succeeds_without_partition_ddl(): void
    {
        $service = app(BoardService::class);

        $board = $service->createBoard([
            'slug' => 'regress-create-delete',
            'name' => ['ko' => '삭제 회귀', 'en' => 'Delete Regress'],
            'type' => 'basic',
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $service->deleteBoard($board->id);

        $this->assertDatabaseMissing('boards', ['id' => $board->id]);

        $partitionQueries = array_filter(
            $queries,
            fn ($sql) => stripos($sql, 'PARTITION') !== false
        );
        $this->assertEmpty(
            $partitionQueries,
            '보드 삭제 중 파티션 관련 DDL이 실행되었습니다: '.implode(' | ', $partitionQueries)
        );
    }

    /**
     * BoardRepositoryInterface에서 파티션 메서드가 완전히 제거되었는지 확인합니다.
     */
    public function test_partition_methods_removed_from_repository_interface(): void
    {
        $reflection = new \ReflectionClass(BoardRepositoryInterface::class);

        $this->assertFalse(
            $reflection->hasMethod('addBoardPartitions'),
            'BoardRepositoryInterface에 addBoardPartitions 메서드가 남아있습니다.'
        );
        $this->assertFalse(
            $reflection->hasMethod('dropBoardPartitions'),
            'BoardRepositoryInterface에 dropBoardPartitions 메서드가 남아있습니다.'
        );
    }
}
