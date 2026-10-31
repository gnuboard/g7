<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_1_0\Migrations\BackfillAdminRoleOnMenus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

/**
 * 7.1.0 업그레이드 — 관리자 역할이 빠진 메뉴에 관리자 역할 백필 (#708)
 *
 * 7.0.x 는 메뉴 역할을 전부 빼고 저장할 수 있었고 화면은 그 메뉴를 「모든 역할」 로 표기했지만,
 * 실제로는 관리자 사이드바에서도 사라졌다. 업그레이드가 관리자 역할을 채우는지, 두 번 돌려도
 * 같은지, 다른 역할은 건드리지 않는지 잠근다.
 *
 * 시나리오 SSoT: tests/scenarios/role-menu-bulk-assignment.yaml
 */
#[Group('core')]
#[Group('upgrade')]
class Upgrade710AdminRoleBackfillTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    /**
     * 업그레이드 데이터 파일을 불러옵니다(autoload 대상이 아니다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.0/migrations/05_BackfillAdminRoleOnMenus.php');
    }

    /**
     * 업그레이드 마이그레이션을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runBackfill(): void
    {
        (new BackfillAdminRoleOnMenus)->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
    }

    /**
     * 역할이 하나도 없는 메뉴와 다른 역할만 있는 메뉴에 관리자 역할을 붙이고, 다른 역할은 그대로 둔다.
     * 두 번째 실행은 아무것도 바꾸지 않는다.
     *
     * @effects empty_role_menus_backfilled
     *
     * @return void 반환값 없음
     */
    public function test_backfills_admin_role_idempotently_without_touching_other_roles(): void
    {
        $other = $this->roleWith([]);
        $empty = $this->menu();
        $otherOnly = $this->menu([], [$other]);
        $alreadyAdmin = $this->menu([], [$other]);
        DB::table('role_menus')->whereIn('menu_id', [$empty->id, $otherOnly->id])->where('role_id', $this->adminRole()->id)->delete();

        $this->runBackfill();

        $this->assertSame(['admin'], $this->roleIdentifiersOf($empty));
        $this->assertEqualsCanonicalizing(['admin', $other->identifier], $this->roleIdentifiersOf($otherOnly));
        $this->assertEqualsCanonicalizing(['admin', $other->identifier], $this->roleIdentifiersOf($alreadyAdmin));

        $before = DB::table('role_menus')->count();
        $this->runBackfill();
        $this->assertSame($before, DB::table('role_menus')->count());
    }
}
