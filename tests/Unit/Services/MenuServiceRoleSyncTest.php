<?php

namespace Tests\Unit\Services;

use App\Extension\HookManager;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

/**
 * MenuService 역할 동기화 — 관리자 역할 상시 포함과 무변화 시 훅 미발화 (#708)
 *
 * 메뉴 수정에서 역할을 전부 빼고 저장하면 관리자 역할까지 빠져 관리자 사이드바에서도 메뉴가
 * 사라졌다(화면은 「모든 역할」 로 표기). 수정 경로가 관리자 역할을 항상 남기는지 잠근다.
 *
 * 시나리오 SSoT: tests/scenarios/role-menu-bulk-assignment.yaml
 */
#[Group('core')]
class MenuServiceRoleSyncTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    /**
     * 역할을 비워 수정하면 관리자 역할만 남는다.
     *
     * @effects update_menu_keeps_admin_role
     *
     * @return void 반환값 없음
     */
    public function test_update_menu_with_empty_roles_keeps_admin_role(): void
    {
        $other = $this->roleWith([]);
        $menu = $this->menu([], [$other]);

        app(MenuService::class)->updateMenu($menu, ['roles' => []]);

        $this->assertSame(['admin'], $this->roleIdentifiersOf($menu));
    }

    /**
     * 관리자 역할 없이 다른 역할만 골라 수정해도 관리자 역할이 함께 남는다.
     *
     * @effects update_menu_keeps_admin_role
     *
     * @return void 반환값 없음
     */
    public function test_update_menu_without_admin_in_roles_adds_admin(): void
    {
        $other = $this->roleWith([]);
        $menu = $this->menu();

        app(MenuService::class)->updateMenu($menu, ['roles' => [$other->id]]);

        $this->assertEqualsCanonicalizing(['admin', $other->identifier], $this->roleIdentifiersOf($menu));
    }

    /**
     * roles 키를 보내지 않은 수정은 역할을 건드리지 않는다.
     *
     * @effects omitted_field_keeps_assignment
     *
     * @return void 반환값 없음
     */
    public function test_update_menu_without_roles_key_keeps_roles(): void
    {
        $other = $this->roleWith([]);
        $menu = $this->menu([], [$other]);

        app(MenuService::class)->updateMenu($menu, ['icon' => 'fas fa-star']);

        $this->assertEqualsCanonicalizing(['admin', $other->identifier], $this->roleIdentifiersOf($menu));
    }

    /**
     * 이미 가진 역할을 다시 더하는 일괄 요청은 after_sync_roles 를 발화하지 않는다.
     *
     * @effects activity_log_per_menu
     *
     * @return void 반환값 없음
     */
    public function test_bulk_add_of_existing_role_fires_no_hook(): void
    {
        $this->actingAs($this->actor('super_admin'));
        $role = $this->roleWith([]);
        $menu = $this->menu([], [$role]);

        $fired = 0;
        $counter = function () use (&$fired) {
            $fired++;
        };
        HookManager::addAction('core.menu.after_sync_roles', $counter);
        try {
            $result = app(MenuService::class)->bulkUpdateRoles([$menu->id], [$role->id], 'add');
        } finally {
            HookManager::removeAction('core.menu.after_sync_roles', $counter);
        }

        $this->assertSame(0, $fired);
        $this->assertSame(0, $result['updated_count']);
    }
}
