<?php

namespace Database\Seeders;

use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Extension\HookManager;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class CoreAdminMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('코어 관리자 메뉴 생성을 시작합니다.');

        // 기존 데이터 삭제
        $this->deleteExistingCoreMenus();

        // 관리자 사용자 찾기 (첫 번째 사용자를 관리자로 가정)
        $admin = User::first();

        // 새 데이터 생성
        $this->createCoreMenus($admin);

        $this->command->info('코어 관리자 메뉴가 성공적으로 생성되었습니다.');
    }

    /**
     * 기존 코어 메뉴를 삭제합니다.
     */
    private function deleteExistingCoreMenus(): void
    {
        $deletedCount = Menu::where('extension_type', ExtensionOwnerType::Core)->delete();

        if ($deletedCount > 0) {
            $this->command->info("기존 코어 메뉴 {$deletedCount}개가 삭제되었습니다.");
        }
    }

    /**
     * 코어 관리자 메뉴를 생성합니다.
     * 정의는 config/core.php의 menus에서 읽습니다.
     *
     * @param  User|null  $admin  관리자 사용자
     */
    private function createCoreMenus(?User $admin): void
    {
        $coreMenus = config('core.menus');

        // 언어팩 시스템: 활성 코어 언어팩의 seed/menus.json 으로 다국어 키 병합 (§9 필터 주입)
        $coreMenus = HookManager::applyFilters('core.menus.config', $coreMenus);

        $createdMenus = [];
        foreach ($coreMenus as $menuData) {
            $createdMenus[] = Menu::create(array_merge($menuData, [
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'created_by' => null,
            ]));
        }

        // 관리자 역할에 모든 코어 메뉴 read 권한 부여
        $this->grantAdminRoleToMenus($createdMenus);

        // 매니저 역할에 대시보드 메뉴 read 권한 부여
        $this->grantManagerDashboardMenu($createdMenus);
    }

    /**
     * 관리자 역할에 모든 코어 메뉴의 read 권한을 부여합니다.
     *
     * @param  array<Menu>  $menus  생성된 메뉴 목록
     */
    private function grantAdminRoleToMenus(array $menus): void
    {
        $adminRole = Role::where('identifier', 'admin')->first();

        if (! $adminRole) {
            $this->command->warn('관리자 역할(admin)을 찾을 수 없어 메뉴 권한 부여를 건너뜁니다.');

            return;
        }

        foreach ($menus as $menu) {
            $adminRole->menus()->attach($menu->id, [
                'permission_type' => MenuPermissionType::Read->value,
            ]);
        }

        $this->command->info('관리자 역할에 '.count($menus).'개의 코어 메뉴 권한을 부여했습니다.');
    }

    /**
     * 매니저 역할에 대시보드 메뉴의 read 권한을 부여합니다.
     *
     * @param  array<Menu>  $menus  생성된 메뉴 목록
     */
    private function grantManagerDashboardMenu(array $menus): void
    {
        $managerRole = Role::where('identifier', 'manager')->first();

        if (! $managerRole) {
            $this->command->warn('매니저 역할(manager)을 찾을 수 없어 대시보드 메뉴 권한 부여를 건너뜁니다.');

            return;
        }

        $dashboardMenu = collect($menus)->first(fn (Menu $menu) => $menu->slug === 'admin-dashboard');

        if (! $dashboardMenu) {
            $this->command->warn('대시보드 메뉴(admin-dashboard)를 찾을 수 없어 매니저 메뉴 권한 부여를 건너뜁니다.');

            return;
        }

        $managerRole->menus()->attach($dashboardMenu->id, [
            'permission_type' => MenuPermissionType::Read->value,
        ]);

        $this->command->info('매니저 역할에 대시보드 메뉴 권한을 부여했습니다.');
    }
}
