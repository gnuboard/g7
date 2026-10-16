<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_2_1;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.2.1 신고 관리 권한 범위 정리 업그레이드 테스트 (#789)
 *
 * 1.2.1 부터 신고 관리 권한에는 범위가 없다. 역할에 저장해 둔 「본인만」·「소유역할」 값은 실제로는 전체로
 * 동작하므로 비운다(삭제 아님). 다른 권한의 범위와 범위 없는 부여 행은 건드리지 않고, 다시 돌려도 0건이다.
 */
#[Group('board')]
#[Group('upgrade')]
class ReportPermissionScopeClearUpgradeTest extends ModuleTestCase
{
    /**
     * 신고 관리 권한의 self·role 부여 값은 비워지고 행은 남는다. 다른 권한의 범위는 그대로다. 재실행 0건.
     *
     * @scenario case=upgrade_clears_report_permission_scopes
     *
     * @effects upgrade_clears_report_permission_scopes
     *
     * @return void 반환값 없음
     */
    public function test_upgrade_clears_report_scope_values_only(): void
    {
        $view = Permission::updateOrCreate(['identifier' => 'sirsoft-board.reports.view'], ['name' => ['ko' => '신고 조회', 'en' => 'View'], 'type' => 'admin']);
        $manage = Permission::updateOrCreate(['identifier' => 'sirsoft-board.reports.manage'], ['name' => ['ko' => '신고 관리', 'en' => 'Manage'], 'type' => 'admin']);
        $scraps = Permission::updateOrCreate(['identifier' => 'sirsoft-board.scraps.view'], ['name' => ['ko' => '스크랩 조회', 'en' => 'Scraps'], 'type' => 'admin', 'resource_route_key' => 'scrap', 'owner_key' => 'user_id']);

        $selfRole = Role::firstOrCreate(['identifier' => 'report-scope-upgrade-self'], ['name' => ['ko' => '본인만', 'en' => 'Self']]);
        $roleRole = Role::firstOrCreate(['identifier' => 'report-scope-upgrade-role'], ['name' => ['ko' => '소유역할', 'en' => 'Role']]);
        $fullRole = Role::firstOrCreate(['identifier' => 'report-scope-upgrade-full'], ['name' => ['ko' => '전체', 'en' => 'Full']]);

        $selfRole->permissions()->syncWithoutDetaching([
            $view->id => ['scope_type' => 'self'],
            $manage->id => ['scope_type' => 'self'],
            $scraps->id => ['scope_type' => 'self'],
        ]);
        $roleRole->permissions()->syncWithoutDetaching([$view->id => ['scope_type' => 'role']]);
        $fullRole->permissions()->syncWithoutDetaching([$view->id => ['scope_type' => null]]);

        $this->runUpgrade();

        foreach ([[$selfRole, $view], [$selfRole, $manage], [$roleRole, $view], [$fullRole, $view]] as [$role, $permission]) {
            $this->assertDatabaseHas('role_permissions', [
                'role_id' => $role->id,
                'permission_id' => $permission->id,
                'scope_type' => null,
            ]);
        }

        // 다른 권한의 범위는 건드리지 않는다
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $selfRole->id,
            'permission_id' => $scraps->id,
            'scope_type' => 'self',
        ]);

        // 멱등 — 다시 돌려도 바뀌는 행이 없다
        $before = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id', 'scope_type'])->toArray();
        $this->runUpgrade();
        $after = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id', 'scope_type'])->toArray();
        $this->assertEquals($before, $after);
    }

    /**
     * 1.2.1 업그레이드 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_2_1)->run(new UpgradeContext('1.2.0', '1.2.1', '1.2.1', 'extension-upgrade'));
    }
}
