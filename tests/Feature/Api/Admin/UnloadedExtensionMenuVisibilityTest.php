<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Models\Menu;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

/**
 * 이 서버가 불러오지 못한(파일이 없는) 확장의 메뉴는 관리자 사이드바·메뉴 관리 목록에 보이지 않는다 (#888).
 *
 * 같은 DB 를 쓰는 다른 서버(검수 서버)에 새 확장을 설치하면 공유 DB 에는 그 확장이 활성으로, 메뉴가 그 확장 소유로 들어간다.
 * 이 서버에는 그 확장의 파일이 없어 라우트·화면이 없는데 메뉴 활성 판정이 DB 상태만 봐서 사이드바에 메뉴가 보이고 누르면 404 였다.
 * 메뉴는 DB 활성 확장 가운데 이 서버가 불러온 확장의 것만 보인다(교집합). 실행 역할과 무관하다.
 *
 * @scenario-manifest tests/scenarios/unloaded-extension-menu-hidden.yaml
 */
class UnloadedExtensionMenuVisibilityTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    /**
     * DB 에는 활성으로 있지만 이 서버에 파일이 없는 모듈과 그 메뉴를 만듭니다.
     *
     * @return Menu 그 모듈 소유 메뉴
     */
    private function unloadedModuleMenu(): Menu
    {
        $identifier = 'stgonly-'.Str::lower(Str::random(8));
        Module::factory()->create(['identifier' => $identifier, 'vendor' => 'stgonly', 'status' => 'active']);
        $this->assertNull(app(ModuleManager::class)->getModule($identifier), '전제 — 이 서버가 불러오지 못한 모듈');

        return $this->menu(['extension_type' => ExtensionOwnerType::Module, 'extension_identifier' => $identifier]);
    }

    /**
     * DB 에는 활성으로 있지만 이 서버에 파일이 없는 플러그인과 그 메뉴를 만듭니다.
     *
     * @return Menu 그 플러그인 소유 메뉴
     */
    private function unloadedPluginMenu(): Menu
    {
        $identifier = 'stgonly-'.Str::lower(Str::random(8));
        Plugin::query()->create([
            'identifier' => $identifier,
            'vendor' => 'stgonly',
            'name' => ['ko' => '검수 서버 전용', 'en' => 'Stage only'],
            'version' => '1.0.0',
            'status' => 'active',
        ]);

        return $this->menu(['extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => $identifier]);
    }

    /**
     * 사이드바 응답의 최상위 메뉴 ID 목록.
     *
     * @param  User  $actor  요청자
     * @return array<int, int> 메뉴 ID
     */
    private function sidebarIds(User $actor): array
    {
        $response = $this->actingAs($actor)->getJson('/api/admin/menus/active');
        $response->assertOk();

        return collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * 메뉴 관리 목록 응답의 최상위 메뉴 ID 목록.
     *
     * @param  User  $actor  요청자
     * @return array<int, int> 메뉴 ID
     */
    private function managementIds(User $actor): array
    {
        /** @var TestResponse $response */
        $response = $this->actingAs($actor)->getJson('/api/admin/menus');
        $response->assertOk();

        return collect($response->json('data.data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * 사이드바는 이 서버가 불러오지 못한 모듈·플러그인의 메뉴를 숨기고 코어 메뉴는 종전대로 보인다.
     *
     * @scenario surface=sidebar, owner=unloaded_module, owner=unloaded_plugin, owner=core
     *
     * @effects unloaded_extension_menu_hidden_from_sidebar
     *
     * @return void 반환값 없음
     */
    public function test_sidebar_hides_menus_of_unloaded_extensions(): void
    {
        $actor = $this->actor('super_admin');
        $core = $this->menu();
        $module = $this->unloadedModuleMenu();
        $plugin = $this->unloadedPluginMenu();

        $ids = $this->sidebarIds($actor);

        $this->assertContains($core->id, $ids, '코어 메뉴는 종전대로 보인다');
        $this->assertNotContains($module->id, $ids, '이 서버에 없는 모듈의 메뉴가 사이드바에 보인다 — 누르면 404');
        $this->assertNotContains($plugin->id, $ids, '이 서버에 없는 플러그인의 메뉴가 사이드바에 보인다 — 누르면 404');
    }

    /**
     * 메뉴 관리 목록도 이 서버가 불러오지 못한 확장의 메뉴를 빼고 코어 메뉴는 종전대로 보인다.
     *
     * @scenario surface=management, owner=unloaded_module, owner=unloaded_plugin, owner=core
     *
     * @effects unloaded_extension_menu_hidden_from_management
     *
     * @return void 반환값 없음
     */
    public function test_management_list_hides_menus_of_unloaded_extensions(): void
    {
        $actor = $this->actor('super_admin');
        $core = $this->menu();
        $module = $this->unloadedModuleMenu();
        $plugin = $this->unloadedPluginMenu();

        $ids = $this->managementIds($actor);

        $this->assertContains($core->id, $ids);
        $this->assertNotContains($module->id, $ids, '이 서버에 없는 모듈의 메뉴가 메뉴 관리 목록에 보인다');
        $this->assertNotContains($plugin->id, $ids, '이 서버에 없는 플러그인의 메뉴가 메뉴 관리 목록에 보인다');
    }

    /**
     * 사이드바 응답에서 최상위 메뉴의 자식 메뉴 ID 목록.
     *
     * @param  User  $actor  요청자
     * @param  int  $parentId  부모 메뉴 ID
     * @return array<int, int> 자식 메뉴 ID
     */
    private function sidebarChildIds(User $actor, int $parentId): array
    {
        $response = $this->actingAs($actor)->getJson('/api/admin/menus/active');
        $response->assertOk();
        $parent = collect($response->json('data'))->firstWhere('id', $parentId);
        $this->assertNotNull($parent, '전제 — 부모 메뉴가 사이드바에 있다');

        return collect($parent['children'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * 메뉴 관리 목록 응답에서 최상위 메뉴의 자식 메뉴 ID 목록.
     *
     * @param  User  $actor  요청자
     * @param  int  $parentId  부모 메뉴 ID
     * @return array<int, int> 자식 메뉴 ID
     */
    private function managementChildIds(User $actor, int $parentId): array
    {
        $response = $this->actingAs($actor)->getJson('/api/admin/menus');
        $response->assertOk();
        $parent = collect($response->json('data.data'))->firstWhere('id', $parentId);
        $this->assertNotNull($parent, '전제 — 부모 메뉴가 메뉴 관리 목록에 있다');

        return collect($parent['children'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * 코어 부모 메뉴 밑에 붙은, 이 서버가 불러오지 못한 모듈의 자식 메뉴도 사이드바·메뉴 관리 목록에서 숨긴다.
     *
     * 확장은 메뉴를 코어 메뉴(예: 설정) 밑에 자식으로 붙이기도 한다. 부모가 코어라 최상위 판정만으로는 걸러지지 않으므로 자식
     * 목록에도 같은 판정을 적용해야 한다. 같은 부모 밑의 코어 자식은 종전대로 보인다.
     *
     * @scenario surface=sidebar, surface=management, owner=unloaded_module_child_of_core, owner=core
     *
     * @effects unloaded_extension_child_menu_hidden_under_core_parent
     *
     * @return void 반환값 없음
     */
    public function test_unloaded_module_child_under_core_parent_is_hidden(): void
    {
        $actor = $this->actor('super_admin');
        $parent = $this->menu();
        $coreChild = $this->menu(['parent_id' => $parent->id]);
        $unloaded = $this->unloadedModuleMenu();
        $unloaded->update(['parent_id' => $parent->id]);

        $sidebar = $this->sidebarChildIds($actor, $parent->id);
        $this->assertContains($coreChild->id, $sidebar, '같은 부모 밑의 코어 자식 메뉴는 종전대로 보인다');
        $this->assertNotContains($unloaded->id, $sidebar, '코어 부모 밑의 이 서버에 없는 모듈 자식 메뉴가 사이드바에 보인다 — 누르면 404');

        $management = $this->managementChildIds($actor, $parent->id);
        $this->assertContains($coreChild->id, $management);
        $this->assertNotContains($unloaded->id, $management, '코어 부모 밑의 이 서버에 없는 모듈 자식 메뉴가 메뉴 관리 목록에 보인다');
    }

    /**
     * 모듈·플러그인 소유가 아닌 메뉴(운영자가 만든 사용자 정의 메뉴, 소유 타입 없음)는 확장 로드 여부와 무관하게 종전대로 보인다.
     *
     * @scenario surface=sidebar, surface=management, owner=user_defined
     *
     * @effects non_extension_menu_unaffected
     *
     * @return void 반환값 없음
     */
    public function test_user_defined_menu_stays_visible(): void
    {
        $actor = $this->actor('super_admin');
        $userDefined = $this->menu(['extension_type' => null, 'extension_identifier' => null]);
        $this->unloadedModuleMenu();

        $this->assertContains($userDefined->id, $this->sidebarIds($actor), '사용자 정의 메뉴가 사이드바에서 사라진다');
        $this->assertContains($userDefined->id, $this->managementIds($actor), '사용자 정의 메뉴가 메뉴 관리 목록에서 사라진다');
    }

    /**
     * 이 서버가 불러온 활성 모듈의 메뉴는 사이드바·메뉴 관리 목록에 종전대로 보인다 (회귀 축).
     *
     * @scenario surface=sidebar, surface=management, owner=loaded_module
     *
     * @effects loaded_extension_menu_still_visible
     *
     * @return void 반환값 없음
     */
    public function test_menus_of_loaded_active_module_stay_visible(): void
    {
        $identifier = 'sirsoft-board';
        $this->assertNotNull(app(ModuleManager::class)->getModule($identifier), '전제 — 이 서버가 불러온 모듈');

        $row = Module::query()->where('identifier', $identifier)->first();
        if ($row === null) {
            Module::factory()->create(['identifier' => $identifier, 'status' => 'active']);
        } elseif ($row->status !== 'active') {
            Module::query()->whereKey($row->id)->update(['status' => 'active']);
        }

        $actor = $this->actor('super_admin');
        $menu = $this->menu(['extension_type' => ExtensionOwnerType::Module, 'extension_identifier' => $identifier]);

        $this->assertContains($menu->id, $this->sidebarIds($actor), '불러온 활성 모듈의 메뉴는 사이드바에 보여야 한다');
        $this->assertContains($menu->id, $this->managementIds($actor), '불러온 활성 모듈의 메뉴는 메뉴 관리 목록에 보여야 한다');
    }
}
