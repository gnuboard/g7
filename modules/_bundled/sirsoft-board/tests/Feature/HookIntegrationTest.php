<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 모듈 훅 시스템 통합 테스트
 *
 * 서비스 레이어를 직접 호출하여 훅 실행 및 데이터 변형을 검증합니다.
 */
class HookIntegrationTest extends ModuleTestCase
{
    private User $adminUser;
    private BoardService $boardService;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 이전 실행에서 남은 test-board-* 잔여 데이터 정리 (DatabaseTransactions 비활성 환경 호환)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('permissions')->where('identifier', 'like', 'sirsoft-board.test-board-%')->delete();
        DB::table('boards')->where('slug', 'like', 'test-board-%')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // 이전 테스트의 훅 정리 (정적 속성은 테스트 간 유지됨)
        $this->clearHooks();

        // 관리자 사용자 생성 (faker 이메일 사용: 하드코딩 시 DDL 커밋으로 다음 테스트와 충돌)
        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
        ]);

        $this->actingAs($this->adminUser);

        // BoardService 인스턴스 생성
        $this->boardService = app(BoardService::class);
    }

    /**
     * 테스트 종료 후 훅 정리
     */
    protected function tearDown(): void
    {
        $this->clearHooks();
        parent::tearDown();
    }

    /**
     * HookManager의 정적 속성과 Laravel Event 리스너를 초기화합니다.
     */
    private function clearHooks(): void
    {
        // 리플렉션으로 HookManager의 정적 속성 초기화
        $reflection = new \ReflectionClass(HookManager::class);

        $hooksProperty = $reflection->getProperty('hooks');
        $hooksProperty->setAccessible(true);
        $hooksProperty->setValue(null, []);

        $filtersProperty = $reflection->getProperty('filters');
        $filtersProperty->setAccessible(true);
        $filtersProperty->setValue(null, []);

        // Laravel 이벤트 시스템에서도 훅 관련 리스너 제거
        Event::forget('hook.sirsoft-board.board.before_create');
        Event::forget('hook.sirsoft-board.board.filter_create_data');
        Event::forget('hook.sirsoft-board.board.after_create');
        Event::forget('hook.sirsoft-board.permissions.after_create');
        Event::forget('hook.sirsoft-board.board.before_update');
        Event::forget('hook.sirsoft-board.board.filter_update_data');
        Event::forget('hook.sirsoft-board.board.after_update');
        Event::forget('hook.sirsoft-board.board.before_delete');
        Event::forget('hook.sirsoft-board.board.after_delete');
        Event::forget('hook.sirsoft-board.permissions.after_delete');
        Event::forget('hook.sirsoft-board.board.before_copy');
        Event::forget('hook.sirsoft-board.board.filter_copy_data');
    }

    /**
     * HookManager의 정적 배열에만 직접 훅을 등록합니다.
     * (Laravel Event 중복 실행 방지용)
     */
    private function addHookDirectly(string $hookName, callable $callback, int $priority = 10): void
    {
        $reflection = new \ReflectionClass(HookManager::class);
        $hooksProperty = $reflection->getProperty('hooks');
        $hooksProperty->setAccessible(true);

        $hooks = $hooksProperty->getValue(null) ?? [];

        if (!isset($hooks[$hookName])) {
            $hooks[$hookName] = [];
        }

        if (!isset($hooks[$hookName][$priority])) {
            $hooks[$hookName][$priority] = [];
        }

        $hooks[$hookName][$priority][] = $callback;
        $hooksProperty->setValue(null, $hooks);
    }

    /**
     * 게시판 생성 전 before_create 훅이 실행되는지 테스트
     */
    public function test_before_create_hook_is_called(): void
    {
        $hookCalled = false;

        HookManager::addAction('sirsoft-board.board.before_create', function ($data) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertIsArray($data);
            $this->assertArrayHasKey('slug', $data);
            $this->assertArrayHasKey('name', $data);
        });

        $board = $this->boardService->createBoard([
            'slug' => 'test-board-1',
            'name' => ['ko' => '테스트 게시판 1', 'en' => 'Test Board 1'],
            'type' => 'default',
        ]);

        $this->assertInstanceOf(Board::class, $board);
        $this->assertTrue($hookCalled, 'before_create 훅이 호출되지 않았습니다.');
    }

    /**
     * 게시판 생성 시 filter_create_data 훅으로 데이터를 변형할 수 있는지 테스트
     */
    public function test_filter_create_data_hook_modifies_data(): void
    {
        // 필터 훅: 모든 게시판 이름에 [테스트] prefix 추가
        HookManager::addFilter('sirsoft-board.board.filter_create_data', function ($data) {
            $data['name']['ko'] = '[테스트] '.$data['name']['ko'];
            $data['name']['en'] = '[Test] '.$data['name']['en'];

            return $data;
        });

        $board = $this->boardService->createBoard([
            'slug' => 'test-board-2',
            'name' => ['ko' => '원본 이름', 'en' => 'Original Name'],
            'type' => 'default',
        ]);

        // 필터가 적용되었는지 확인
        $this->assertNotNull($board);
        $this->assertEquals('[테스트] 원본 이름', $board->name['ko']);
        $this->assertEquals('[Test] Original Name', $board->name['en']);
    }

    /**
     * 게시판 생성 후 after_create 훅이 실행되는지 테스트
     */
    public function test_after_create_hook_is_called(): void
    {
        $hookCalled = false;
        $createdBoardId = null;

        HookManager::addAction('sirsoft-board.board.after_create', function ($board, $data) use (&$hookCalled, &$createdBoardId) {
            $hookCalled = true;
            $createdBoardId = $board->id;
            $this->assertInstanceOf(Board::class, $board);
            $this->assertIsArray($data);
        });

        $board = $this->boardService->createBoard([
            'slug' => 'test-board-3',
            'name' => ['ko' => '테스트 게시판 3', 'en' => 'Test Board 3'],
            'type' => 'default',
        ]);

        $this->assertInstanceOf(Board::class, $board);
        $this->assertTrue($hookCalled, 'after_create 훅이 호출되지 않았습니다.');
        $this->assertNotNull($createdBoardId);
    }

    /**
     * 권한 생성 후 permissions.after_create 훅이 실행되는지 테스트
     */
    public function test_permissions_after_create_hook_is_called(): void
    {
        $hookCalled = false;

        HookManager::addAction('sirsoft-board.permissions.after_create', function ($board) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertInstanceOf(Board::class, $board);

            // 권한이 실제로 생성되었는지 확인 (board_permission_definitions 키 수 = 16개)
            $permissions = Permission::where('identifier', 'like', "sirsoft-board.{$board->slug}.%")->get();
            $this->assertCount(16, $permissions);
        });

        $board = $this->boardService->createBoard([
            'slug' => 'test-board-5',
            'name' => ['ko' => '테스트 게시판 5', 'en' => 'Test Board 5'],
            'type' => 'default',
        ]);

        $this->assertInstanceOf(Board::class, $board);
        $this->assertTrue($hookCalled, 'permissions.after_create 훅이 호출되지 않았습니다.');
    }

    /**
     * 게시판 수정 시 before_update 훅이 실행되는지 테스트
     */
    public function test_before_update_hook_is_called(): void
    {
        $board = $this->createTestBoard('test-board-6');

        $hookCalled = false;

        HookManager::addAction('sirsoft-board.board.before_update', function ($boardInstance, $data) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertInstanceOf(Board::class, $boardInstance);
            $this->assertIsArray($data);
        });

        $updatedBoard = $this->boardService->updateBoard($board->id, [
            'name' => ['ko' => '수정된 이름', 'en' => 'Modified Name'],
        ]);

        $this->assertInstanceOf(Board::class, $updatedBoard);
        $this->assertTrue($hookCalled, 'before_update 훅이 호출되지 않았습니다.');
    }

    /**
     * 게시판 수정 시 filter_update_data 훅으로 데이터를 변형할 수 있는지 테스트
     */
    public function test_filter_update_data_hook_modifies_data(): void
    {
        $board = $this->createTestBoard('test-board-7');

        // 필터 훅: 수정 시 자동으로 suffix 추가
        HookManager::addFilter('sirsoft-board.board.filter_update_data', function ($data) {
            if (isset($data['name'])) {
                $data['name']['ko'] .= ' (수정됨)';
                $data['name']['en'] .= ' (Modified)';
            }

            return $data;
        }, 10);

        $updatedBoard = $this->boardService->updateBoard($board->id, [
            'name' => ['ko' => '새 이름', 'en' => 'New Name'],
        ]);

        $this->assertEquals('새 이름 (수정됨)', $updatedBoard->name['ko']);
        $this->assertEquals('New Name (Modified)', $updatedBoard->name['en']);
    }

    /**
     * 게시판 수정 후 after_update 훅이 실행되는지 테스트
     */
    public function test_after_update_hook_is_called(): void
    {
        $board = $this->createTestBoard('test-board-8');

        $hookCalled = false;

        HookManager::addAction('sirsoft-board.board.after_update', function ($boardInstance, $data) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertInstanceOf(Board::class, $boardInstance);
            $this->assertIsArray($data);
        });

        $updatedBoard = $this->boardService->updateBoard($board->id, [
            'name' => ['ko' => '수정된 이름', 'en' => 'Modified Name'],
        ]);

        $this->assertInstanceOf(Board::class, $updatedBoard);
        $this->assertTrue($hookCalled, 'after_update 훅이 호출되지 않았습니다.');
    }

    /**
     * 게시판 삭제 전 before_delete 훅이 실행되는지 테스트
     */
    public function test_before_delete_hook_is_called(): void
    {
        $board = $this->createTestBoard('test-board-9');

        $hookCalled = false;

        HookManager::addAction('sirsoft-board.board.before_delete', function ($boardInstance) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertInstanceOf(Board::class, $boardInstance);
        });

        $this->boardService->deleteBoard($board->id);

        $this->assertTrue($hookCalled, 'before_delete 훅이 호출되지 않았습니다.');
    }

    /**
     * 게시판 삭제 전 before_delete 훅에서 예외를 던져 삭제를 차단할 수 있는지 테스트
     */
    public function test_before_delete_hook_can_prevent_deletion(): void
    {
        $board = $this->createTestBoard('test-board-10');

        // 삭제 차단 훅
        HookManager::addAction('sirsoft-board.board.before_delete', function () {
            throw new \Exception('삭제가 차단되었습니다.');
        });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('삭제가 차단되었습니다.');

        $this->boardService->deleteBoard($board->id);
    }

    /**
     * 권한 삭제 후 permissions.after_delete 훅이 실행되는지 테스트
     */
    public function test_permissions_after_delete_hook_is_called(): void
    {
        $board = $this->createTestBoard('test-board-11');

        $hookCalled = false;
        $deletedSlug = null;

        HookManager::addAction('sirsoft-board.permissions.after_delete', function ($slug) use (&$hookCalled, &$deletedSlug) {
            $hookCalled = true;
            $deletedSlug = $slug;
            $this->assertIsString($slug);

            // 권한이 실제로 삭제되었는지 확인
            $permissions = Permission::where('identifier', 'like', "sirsoft-board.{$slug}.%")->get();
            $this->assertCount(0, $permissions);
        });

        $this->boardService->deleteBoard($board->id);

        $this->assertTrue($hookCalled, 'permissions.after_delete 훅이 호출되지 않았습니다.');
        $this->assertEquals('test-board-11', $deletedSlug);
    }

    /**
     * 게시판 삭제 후 after_delete 훅이 실행되는지 테스트
     */
    public function test_after_delete_hook_is_called(): void
    {
        $board = $this->createTestBoard('test-board-13');

        $hookCalled = false;

        HookManager::addAction('sirsoft-board.board.after_delete', function ($boardInstance) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertInstanceOf(Board::class, $boardInstance);
        });

        $this->boardService->deleteBoard($board->id);

        $this->assertTrue($hookCalled, 'after_delete 훅이 호출되지 않았습니다.');
    }

    /**
     * 게시판 복사 전 before_copy 훅이 실행되는지 테스트
     */
    public function test_before_copy_hook_is_called(): void
    {
        $board = $this->createTestBoard('test-board-14');

        $hookCalled = false;

        HookManager::addAction('sirsoft-board.board.before_copy', function ($originalBoard) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertInstanceOf(Board::class, $originalBoard);
        });

        $copyData = $this->boardService->copyBoard($board->id);

        $this->assertIsArray($copyData);
        $this->assertTrue($hookCalled, 'before_copy 훅이 호출되지 않았습니다.');
    }

    /**
     * 게시판 복사 시 filter_copy_data 훅으로 데이터를 변형할 수 있는지 테스트
     */
    public function test_filter_copy_data_hook_modifies_data(): void
    {
        $board = $this->createTestBoard('test-board-15');

        // 필터 훅: 복사 데이터에 메타 정보 추가
        HookManager::addFilter('sirsoft-board.board.filter_copy_data', function ($copyData) {
            $copyData['description'] = ['ko' => '복사된 게시판입니다.', 'en' => 'This is a copied board.'];

            return $copyData;
        }, 10, 2);

        $copyData = $this->boardService->copyBoard($board->id);

        $this->assertArrayHasKey('description', $copyData);
        $this->assertEquals('복사된 게시판입니다.', $copyData['description']['ko']);
        $this->assertEquals('This is a copied board.', $copyData['description']['en']);
    }

    /**
     * 여러 훅이 우선순위에 따라 순차적으로 실행되는지 테스트
     *
     * 참고: HookManager::addAction은 정적 배열과 Laravel Event 양쪽에 등록하여
     * doAction에서 2번 실행되므로, 이 테스트에서는 정적 배열에만 직접 등록합니다.
     */
    public function test_multiple_hooks_execute_in_priority_order(): void
    {
        $executionOrder = [];

        // 우선순위가 다른 3개의 훅을 정적 배열에만 직접 등록 (중복 실행 방지)
        $this->addHookDirectly('sirsoft-board.board.after_create', function () use (&$executionOrder) {
            $executionOrder[] = 'priority-20';
        }, 20);

        $this->addHookDirectly('sirsoft-board.board.after_create', function () use (&$executionOrder) {
            $executionOrder[] = 'priority-5';
        }, 5);

        $this->addHookDirectly('sirsoft-board.board.after_create', function () use (&$executionOrder) {
            $executionOrder[] = 'priority-10';
        }, 10);

        $this->boardService->createBoard([
            'slug' => 'test-board-16',
            'name' => ['ko' => '우선순위 테스트', 'en' => 'Priority Test'],
            'type' => 'default',
        ]);

        // 우선순위가 낮은 순서대로 실행되어야 함 (5 -> 10 -> 20)
        $this->assertEquals(['priority-5', 'priority-10', 'priority-20'], $executionOrder);
    }

    /**
     * 테스트용 게시판 생성 헬퍼 메서드
     */
    private function createTestBoard(string $slug): Board
    {
        return $this->boardService->createBoard([
            'slug' => $slug,
            'name' => ['ko' => "테스트 {$slug}", 'en' => "Test {$slug}"],
            'type' => 'default',
        ]);
    }
}
