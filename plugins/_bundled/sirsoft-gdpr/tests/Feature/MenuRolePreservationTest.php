<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Models\Menu;
use App\Models\Plugin as PluginRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\MenuService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Plugins\Sirsoft\Gdpr\Plugin;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

// audit:allow test-extension-base-class reason: 메뉴·역할·플러그인 행(코어 테이블)만 쓰는 생명주기 테스트라 트랜잭션 격리로 충분하다 — PluginTestCase 의 RefreshDatabase(migrate:fresh)는 로컬 MySQL OOM 규율(mem:migrate-fresh-ddl-crashes-local-mysql)에 걸린다

/**
 * GDPR 플러그인 비활성화·재활성화 시 관리자 메뉴 역할 보존 (#708)
 *
 * 비활성화가 메뉴 행을 지워 운영자가 붙인 역할이 사라지고, 재활성화가 운영자가 뗀 privacy
 * 역할을 다시 붙이던 결함을 잠근다. 메뉴·역할·플러그인 행만 쓰므로 트랜잭션으로 격리한다.
 *
 * 시나리오 SSoT: tests/scenarios/plugin-menu-role-preservation.yaml
 */
class MenuRolePreservationTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    private Plugin $plugin;

    private Role $privacy;

    /**
     * 플러그인 인스턴스·privacy 역할·플러그인 행을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole();
        $this->privacy = Role::firstOrCreate(
            ['identifier' => 'sirsoft-gdpr.privacy'],
            ['name' => ['ko' => '개인정보 담당', 'en' => 'Privacy'], 'is_active' => true]
        );
        PluginRecord::query()->updateOrCreate(
            ['identifier' => 'sirsoft-gdpr'],
            ['vendor' => 'sirsoft', 'name' => ['ko' => 'GDPR', 'en' => 'GDPR'], 'version' => '1.1.0', 'status' => 'active']
        );
        $this->plugin = new Plugin;
    }

    /**
     * 이 플러그인 소속 메뉴.
     *
     * @return Collection<int, Menu> 메뉴 목록
     */
    private function menus()
    {
        return Menu::where('extension_type', ExtensionOwnerType::Plugin)
            ->where('extension_identifier', 'sirsoft-gdpr')
            ->orderBy('id')
            ->get();
    }

    /**
     * 플러그인 상태 행을 바꿉니다.
     *
     * @param  string  $status  상태 (active 활성 · inactive 비활성)
     * @return void 반환값 없음
     */
    private function setStatus(string $status): void
    {
        PluginRecord::where('identifier', 'sirsoft-gdpr')->update(['status' => $status]);
    }

    /**
     * 껐다 켜도 운영자가 붙인 역할이 남고, 꺼져 있는 동안은 사이드바에서 빠진다. 운영자가 privacy
     * 역할을 뗀 메뉴에는 다시 켜도 privacy 가 붙지 않는다.
     *
     * @scenario plugin=gdpr, transition=deactivate_activate
     *
     * @effects roles_preserved_across_reactivation, menus_hidden_while_inactive, privacy_role_not_regranted_after_operator_removal
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_activate_preserves_operator_roles_and_respects_removed_privacy(): void
    {
        $this->plugin->activate();
        $menus = $this->menus();
        $this->assertNotEmpty($menus);
        $first = $menus->first();

        $operatorRole = $this->roleWith([]);
        // 운영자: 첫 메뉴에 자기 역할을 붙이고 privacy 역할은 뗀다 (메뉴 수정 경로 — user_overrides 기록)
        $keep = $first->roles()->pluck('roles.id')->reject(fn ($id) => (int) $id === $this->privacy->id)->push($operatorRole->id)->all();
        app(MenuService::class)->updateMenu($first->fresh(), ['roles' => $keep]);

        $member = User::factory()->create();
        $member->roles()->attach($operatorRole->id, ['assigned_at' => now()]);

        $this->plugin->deactivate();
        $this->setStatus('inactive');

        $this->assertSame($menus->pluck('id')->all(), $this->menus()->pluck('id')->all());
        $this->assertContains($operatorRole->identifier, $this->roleIdentifiersOf($first));
        $sidebar = app(MenuService::class)->getAccessibleNavigationMenus($member->fresh())->pluck('id')->all();
        $this->assertNotContains($first->id, $sidebar);

        $this->setStatus('active');
        $this->plugin->activate();

        $this->assertContains($operatorRole->identifier, $this->roleIdentifiersOf($first));
        $this->assertNotContains('sirsoft-gdpr.privacy', $this->roleIdentifiersOf($first));
        $sidebar = app(MenuService::class)->getAccessibleNavigationMenus($member->fresh())->pluck('id')->all();
        $this->assertContains($first->id, $sidebar);

        // 운영자가 손대지 않은 다른 메뉴에는 privacy 가 그대로 붙어 있다
        foreach ($menus->slice(1) as $other) {
            $this->assertContains('sirsoft-gdpr.privacy', $this->roleIdentifiersOf($other));
        }
    }

    /**
     * 제거(uninstall)는 메뉴와 역할 연결을 지운다.
     *
     * @scenario plugin=gdpr, transition=uninstall
     *
     * @effects menus_removed_on_uninstall
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_removes_menus(): void
    {
        $this->plugin->activate();
        $ids = $this->menus()->pluck('id')->all();
        $this->assertNotEmpty($ids);

        $this->plugin->uninstall();

        $this->assertCount(0, $this->menus());
        $this->assertDatabaseMissing('role_menus', ['menu_id' => $ids[0], 'permission_type' => MenuPermissionType::Read->value]);
    }
}
