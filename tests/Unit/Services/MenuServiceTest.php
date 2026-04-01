<?php

namespace Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\Menu;
use App\Models\Role;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * MenuService 삭제 테스트
 *
 * 메뉴 삭제 시 관계 레코드 명시적 삭제 및 훅 실행을 검증합니다.
 */
class MenuServiceTest extends TestCase
{
    use RefreshDatabase;

    private MenuService $menuService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->menuService = app(MenuService::class);
        HookManager::resetAll();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    // ========================================================================
    // deleteMenu() - 관계 레코드 명시적 삭제 검증
    // ========================================================================

    /**
     * 메뉴 삭제 시 역할 연결이 해제되는지 확인
     */
    public function test_delete_menu_detaches_roles(): void
    {
        $menu = Menu::create([
            'slug' => 'test-menu-detach',
            'name' => ['ko' => '테스트 메뉴', 'en' => 'Test Menu'],
            'url' => '/test',
            'sort_order' => 1,
        ]);

        $role = Role::create([
            'identifier' => 'test-role-for-menu',
            'name' => ['ko' => '테스트 역할', 'en' => 'Test Role'],
        ]);

        $menu->roles()->attach($role->id);

        $this->assertDatabaseHas('role_menus', ['menu_id' => $menu->id, 'role_id' => $role->id]);

        $this->menuService->deleteMenu($menu);

        $this->assertDatabaseMissing('role_menus', ['menu_id' => $menu->id]);
    }

    // ========================================================================
    // deleteMenu() - 자식 메뉴 검증
    // ========================================================================

    /**
     * 자식 메뉴가 있는 경우 삭제 시 예외 발생
     */
    public function test_delete_menu_with_children_throws_exception(): void
    {
        $parentMenu = Menu::create([
            'slug' => 'parent-menu',
            'name' => ['ko' => '부모 메뉴', 'en' => 'Parent Menu'],
            'url' => '/parent',
            'sort_order' => 1,
        ]);

        Menu::create([
            'slug' => 'child-menu',
            'name' => ['ko' => '자식 메뉴', 'en' => 'Child Menu'],
            'url' => '/child',
            'parent_id' => $parentMenu->id,
            'sort_order' => 1,
        ]);

        $this->expectException(ValidationException::class);

        $this->menuService->deleteMenu($parentMenu);
    }

    // ========================================================================
    // deleteMenu() - 훅 실행 검증
    // ========================================================================

    /**
     * 메뉴 삭제 시 before_delete/after_delete 훅이 호출되는지 확인
     */
    public function test_delete_menu_fires_hooks(): void
    {
        $menu = Menu::create([
            'slug' => 'test-menu-hooks',
            'name' => ['ko' => '훅 테스트', 'en' => 'Hook Test'],
            'url' => '/hook-test',
            'sort_order' => 1,
        ]);

        $beforeCalled = false;
        $afterCalled = false;

        HookManager::addAction('core.menu.before_delete', function ($m) use (&$beforeCalled, $menu) {
            $beforeCalled = true;
            $this->assertEquals($menu->id, $m->id);
        });

        HookManager::addAction('core.menu.after_delete', function ($menuId) use (&$afterCalled, $menu) {
            $afterCalled = true;
            $this->assertEquals($menu->id, $menuId);
        });

        $this->menuService->deleteMenu($menu);

        $this->assertTrue($beforeCalled);
        $this->assertTrue($afterCalled);
    }
}
