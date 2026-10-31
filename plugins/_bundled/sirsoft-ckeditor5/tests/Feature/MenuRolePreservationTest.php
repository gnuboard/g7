<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature;

use App\Enums\ExtensionOwnerType;
use App\Models\Menu;
use App\Models\Plugin as PluginRecord;
use App\Models\User;
use App\Services\MenuService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Plugins\Sirsoft\Ckeditor5\Plugin;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

// audit:allow test-extension-base-class reason: 메뉴·역할·플러그인 행(코어 테이블)만 쓰는 생명주기 테스트라 트랜잭션 격리로 충분하다 — PluginTestCase 의 RefreshDatabase(migrate:fresh)는 로컬 MySQL OOM 규율(mem:migrate-fresh-ddl-crashes-local-mysql)에 걸린다

/**
 * CKEditor5 플러그인 비활성화·재활성화 시 관리자 메뉴 역할 보존 (#708)
 *
 * 비활성화가 「에디터 업로드 이미지」 메뉴 행을 지워, 다시 켜면 운영자가 붙인 역할이 사라지고
 * 관리자 역할만 남던 결함을 잠근다. 메뉴·역할·플러그인 행만 쓰므로 트랜잭션으로 격리한다.
 *
 * 시나리오 SSoT: tests/scenarios/plugin-menu-role-preservation.yaml
 */
class MenuRolePreservationTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    private Plugin $plugin;

    /**
     * 플러그인 인스턴스와 활성 플러그인 행을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole();
        PluginRecord::query()->updateOrCreate(
            ['identifier' => 'sirsoft-ckeditor5'],
            ['vendor' => 'sirsoft', 'name' => ['ko' => 'CKEditor5', 'en' => 'CKEditor5'], 'version' => '1.1.0', 'status' => 'active']
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
            ->where('extension_identifier', 'sirsoft-ckeditor5')
            ->orderBy('id')
            ->get();
    }

    /**
     * 껐다 켜도 운영자가 붙인 역할이 남고, 꺼져 있는 동안은 사이드바에서 빠진다.
     *
     * @scenario plugin=ckeditor5, transition=deactivate_activate
     *
     * @effects roles_preserved_across_reactivation, menus_hidden_while_inactive
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_activate_preserves_operator_roles(): void
    {
        $this->plugin->activate();
        $menu = $this->menus()->first();
        $this->assertNotNull($menu);

        $operatorRole = $this->roleWith([]);
        $menu->roles()->attach($operatorRole->id, ['permission_type' => 'read']);
        $member = User::factory()->create();
        $member->roles()->attach($operatorRole->id, ['assigned_at' => now()]);

        $this->plugin->deactivate();
        PluginRecord::where('identifier', 'sirsoft-ckeditor5')->update(['status' => 'inactive']);

        $this->assertSame([$menu->id], $this->menus()->pluck('id')->all());
        $this->assertNotContains($menu->id, app(MenuService::class)->getAccessibleNavigationMenus($member->fresh())->pluck('id')->all());

        PluginRecord::where('identifier', 'sirsoft-ckeditor5')->update(['status' => 'active']);
        $this->plugin->activate();

        $this->assertContains($operatorRole->identifier, $this->roleIdentifiersOf($menu));
        $this->assertContains($menu->id, app(MenuService::class)->getAccessibleNavigationMenus($member->fresh())->pluck('id')->all());
    }

    /**
     * 제거(uninstall)는 메뉴를 지운다.
     *
     * @scenario plugin=ckeditor5, transition=uninstall
     *
     * @effects menus_removed_on_uninstall
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_removes_menus(): void
    {
        $this->plugin->activate();
        $this->assertNotEmpty($this->menus());

        $this->plugin->uninstall();

        $this->assertCount(0, $this->menus());
    }
}
