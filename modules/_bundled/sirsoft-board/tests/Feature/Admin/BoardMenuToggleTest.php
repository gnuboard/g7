<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__ . '/../../ModuleTestCase.php';

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 생성/수정 시 관리자 메뉴 토글 (이슈 #413-15)
 *
 * 배경: 신규 게시판 생성 시 관리자 메뉴가 등록되지 않고, 편집 화면 수동 버튼으로만
 * 추가 가능했음. 생성/수정 폼의 add_to_menu 토글로 메뉴 추가/제거가 가능해야 한다.
 *
 * - 생성 시 add_to_menu=true → 관리자 메뉴 1건 등록
 * - 생성 시 add_to_menu=false/미전송 → 메뉴 0건 (기존 동작 회귀 방지)
 * - 수정 시 토글 ON/OFF 변화분만 add/remove
 * - 이름 중복은 차단하지 않음 (slug/URL 기준 식별, 코어 메뉴 정책과 동일)
 */
class BoardMenuToggleTest extends ModuleTestCase
{
    protected User $adminUser;

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

        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.boards.read',
            'sirsoft-board.boards.create',
            'sirsoft-board.boards.update',
            'sirsoft-board.boards.delete',
        ]);
    }

    protected function tearDown(): void
    {
        $slugs = ['menu-on', 'menu-off', 'menu-update', 'menu-dup-a', 'menu-dup-b', 'menu-api', 'menu-form'];

        foreach ($slugs as $slug) {
            Menu::where('url', '/admin/board/' . $slug)->delete();

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

        // 부모 메뉴("게시판 관리") 정리 — 테스트에서 firstOrCreate 로 생성한 경우만
        Menu::where('slug', 'sirsoft-board')->whereNull('url')->delete();

        if (isset($this->adminUser) && $this->adminUser->exists) {
            $userId = $this->adminUser->id;
            DB::table('role_permissions')->where('granted_by', $userId)->delete();
            DB::table('user_roles')->where('user_id', $userId)->delete();
            $this->adminUser->delete();
        }

        parent::tearDown();
    }

    /**
     * 생성 시 add_to_menu=true 이면 관리자 메뉴가 1건 등록되어야 합니다.
     *
     * @scenario mode=create, toggle=on
     * @effects create_with_toggle_on_registers_menu
     */
    public function test_create_with_toggle_on_registers_admin_menu(): void
    {
        $service = app(BoardService::class);

        $board = $service->createBoard([
            'slug' => 'menu-on',
            'name' => ['ko' => '메뉴 온', 'en' => 'Menu On'],
            'type' => 'basic',
            'add_to_menu' => true,
        ]);

        $this->assertDatabaseHas('menus', ['url' => '/admin/board/menu-on']);
        $this->assertSame(1, Menu::where('url', '/admin/board/menu-on')->count());
        // add_to_menu 는 boards 컬럼이 아니므로 저장되지 않아야 함
        $this->assertDatabaseHas('boards', ['id' => $board->id, 'slug' => 'menu-on']);
    }

    /**
     * 생성 시 토글 미전송이면 메뉴가 등록되지 않아야 합니다 (기존 동작 회귀 방지).
     *
     * @scenario mode=create, toggle=off
     * @effects create_without_toggle_keeps_menu_empty
     */
    public function test_create_without_toggle_does_not_register_menu(): void
    {
        $service = app(BoardService::class);

        $service->createBoard([
            'slug' => 'menu-off',
            'name' => ['ko' => '메뉴 오프', 'en' => 'Menu Off'],
            'type' => 'basic',
        ]);

        $this->assertDatabaseMissing('menus', ['url' => '/admin/board/menu-off']);
    }

    /**
     * 수정 시 토글 OFF→ON 이면 메뉴를 추가하고, ON→ON(변화 없음)은 중복 생성하지 않습니다.
     *
     * @scenario mode=update, toggle=on
     * @effects update_off_to_on_adds_menu, update_no_change_does_not_duplicate
     */
    public function test_update_toggle_on_adds_menu_without_duplicate(): void
    {
        $service = app(BoardService::class);

        $board = $service->createBoard([
            'slug' => 'menu-update',
            'name' => ['ko' => '메뉴 수정', 'en' => 'Menu Update'],
            'type' => 'basic',
        ]);
        $this->assertDatabaseMissing('menus', ['url' => '/admin/board/menu-update']);

        // OFF → ON: 추가
        $service->updateBoard($board->id, ['add_to_menu' => true]);
        $this->assertSame(1, Menu::where('url', '/admin/board/menu-update')->count());

        // ON → ON (변화 없음): 중복 생성 없음
        $service->updateBoard($board->id, ['add_to_menu' => true]);
        $this->assertSame(1, Menu::where('url', '/admin/board/menu-update')->count());
    }

    /**
     * 수정 시 토글 ON→OFF 이면 메뉴를 제거해야 합니다.
     *
     * @scenario mode=update, toggle=off
     * @effects update_on_to_off_removes_menu
     */
    public function test_update_toggle_off_removes_menu(): void
    {
        $service = app(BoardService::class);

        $board = $service->createBoard([
            'slug' => 'menu-update',
            'name' => ['ko' => '메뉴 수정', 'en' => 'Menu Update'],
            'type' => 'basic',
            'add_to_menu' => true,
        ]);
        $this->assertSame(1, Menu::where('url', '/admin/board/menu-update')->count());

        // ON → OFF: 제거
        $service->updateBoard($board->id, ['add_to_menu' => false]);
        $this->assertSame(0, Menu::where('url', '/admin/board/menu-update')->count());
    }

    /**
     * 이름이 같고 slug가 다른 두 게시판을 모두 메뉴에 추가해도 차단되지 않아야 합니다.
     *
     * 코어 메뉴 정책(이름 중복 허용, 식별은 slug/URL)과 동일. 이슈 15-(2)는 차단하지 않음.
     *
     * @scenario mode=create, toggle=on
     * @effects same_name_boards_both_register_without_block
     */
    public function test_same_name_boards_both_register_menu_without_block(): void
    {
        $service = app(BoardService::class);

        $service->createBoard([
            'slug' => 'menu-dup-a',
            'name' => ['ko' => '중복이름', 'en' => 'Dup Name'],
            'type' => 'basic',
            'add_to_menu' => true,
        ]);
        $service->createBoard([
            'slug' => 'menu-dup-b',
            'name' => ['ko' => '중복이름', 'en' => 'Dup Name'],
            'type' => 'basic',
            'add_to_menu' => true,
        ]);

        $this->assertSame(1, Menu::where('url', '/admin/board/menu-dup-a')->count());
        $this->assertSame(1, Menu::where('url', '/admin/board/menu-dup-b')->count());
    }

    /**
     * 등록된 관리자 메뉴는 "게시판 관리"(slug=sirsoft-board) 메뉴의 하위로 배치되어야 합니다.
     *
     * @scenario mode=create, toggle=on
     * @effects menu_registered_under_board_management_parent, menu_name_has_board_suffix
     */
    public function test_registered_menu_is_child_of_board_management(): void
    {
        // 부모 메뉴("게시판 관리") 준비
        $parent = Menu::firstOrCreate(
            ['slug' => 'sirsoft-board'],
            [
                'name' => ['ko' => '게시판 관리', 'en' => 'Board Management'],
                'url' => null,
                'is_active' => true,
            ]
        );

        $service = app(BoardService::class);
        $service->createBoard([
            'slug' => 'menu-on',
            'name' => ['ko' => '메뉴 온', 'en' => 'Menu On'],
            'type' => 'basic',
            'add_to_menu' => true,
        ]);

        $menu = Menu::where('url', '/admin/board/menu-on')->first();
        $this->assertNotNull($menu);
        $this->assertSame($parent->id, $menu->parent_id);

        // 메뉴명에 "게시판" 접미사가 붙는다 (게시판명 + 접미사)
        $name = is_array($menu->name) ? $menu->name : json_decode($menu->name, true);
        $this->assertSame('메뉴 온 게시판', $name['ko']);
        $this->assertSame('Menu On Board', $name['en']);
    }

    /**
     * 생성 API(HTTP)에서 add_to_menu=true 전송 시 메뉴가 등록되어야 합니다.
     *
     * @scenario mode=create, toggle=on
     * @effects create_with_toggle_on_registers_menu
     */
    public function test_create_api_with_add_to_menu_registers_menu(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', [
                'name' => ['ko' => '메뉴 API', 'en' => 'Menu API'],
                'slug' => 'menu-api',
                'type' => 'basic',
                'show_view_count' => true,
                'use_report' => false,
                'board_manager_ids' => [$this->adminUser->uuid],
                'add_to_menu' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('menus', ['url' => '/admin/board/menu-api']);
    }

    /**
     * add_to_menu 가 boolean이 아니면 422 검증 오류여야 합니다.
     *
     * @scenario mode=create, toggle=off
     * @effects add_to_menu_non_boolean_rejected_422
     */
    public function test_create_api_rejects_non_boolean_add_to_menu(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', [
                'name' => ['ko' => '메뉴 API', 'en' => 'Menu API'],
                'slug' => 'menu-api',
                'type' => 'basic',
                'show_view_count' => true,
                'use_report' => false,
                'board_manager_ids' => [$this->adminUser->uuid],
                'add_to_menu' => 'not-a-bool',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('add_to_menu');
    }

    /**
     * form-data(HTTP)에서 등록된 게시판은 add_to_menu 초기값이 true여야 합니다.
     *
     * @scenario mode=update, toggle=on
     * @effects form_data_returns_current_menu_state_as_toggle_initial
     */
    public function test_form_data_returns_add_to_menu_true_when_registered(): void
    {
        $service = app(BoardService::class);

        $board = $service->createBoard([
            'slug' => 'menu-form',
            'name' => ['ko' => '메뉴 폼', 'en' => 'Menu Form'],
            'type' => 'basic',
            'add_to_menu' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?board_id=' . $board->id);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.add_to_menu'));
    }
}
