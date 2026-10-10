<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 확장 목록·복구 경로의 abilities·권한 정렬 (G20·G24·core-admin-extensions #7·#8·#12).
 *
 * - 모듈 목록은 결과가 0건이어도 컬렉션 abilities 를 싣는다(0건이면 빠져 권한자에게도 버튼이 굳던 G24).
 * - 플러그인 행은 설정 화면 저장 권한(can_update = core.plugins.update)을 싣는다(G20).
 * - 세 목록 모두 언어팩 관리 화면으로 갈 수 있는지(can_view_language_packs = core.language_packs.read)를 싣는다(#8).
 * - 자동 비활성 확장의 복구·닫기는 확장 종류의 활성화 권한을 요구한다(#7).
 */
class ExtensionListAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 주어진 권한만 가진 운영자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 운영자
     */
    private function operatorWith(array $permissions): User
    {
        $ids = [];
        foreach ($permissions as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }
        $role = Role::create(['identifier' => 'ext_list_'.uniqid(), 'name' => ['ko' => 'r', 'en' => 'r'], 'is_active' => true]);
        $role->permissions()->sync($ids);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 검색 결과가 0건이어도 모듈 목록 응답에 abilities 가 있다 (G24)
     *
     * @scenario endpoint=admin.modules.index,result=empty,permission=install
     *
     * @effects module_list_abilities_present_when_empty
     *
     * @return void 반환값 없음
     */
    public function test_module_list_carries_abilities_when_empty(): void
    {
        $user = $this->operatorWith(['core.modules.read', 'core.modules.install']);

        $abilities = $this->actingAs($user)
            ->getJson('/api/admin/modules?search=__no_such_module_for_test__')
            ->assertOk()
            ->json('data.abilities');

        $this->assertIsArray($abilities);
        $this->assertTrue($abilities['can_install']);
        $this->assertFalse($abilities['can_activate']);
        $this->assertFalse($abilities['can_view_language_packs']);
    }

    /**
     * 플러그인·템플릿 목록 컬렉션 abilities 에 언어팩 관리 진입 권한이 실린다 (#8·#12)
     *
     * @scenario endpoint=admin.plugins.index|admin.templates.index,permission=read+language_packs.read
     *
     * @effects extension_list_carries_language_pack_ability
     *
     * @return void 반환값 없음
     */
    public function test_plugin_and_template_lists_carry_language_pack_ability(): void
    {
        $user = $this->operatorWith(['core.plugins.read', 'core.templates.read', 'core.language_packs.read']);

        $plugins = $this->actingAs($user)->getJson('/api/admin/plugins')->assertOk()->json('data.abilities');
        $this->assertTrue($plugins['can_view_language_packs']);
        $this->assertFalse($plugins['can_install']);
        $this->assertFalse($plugins['can_update']);

        $templates = $this->actingAs($user)->getJson('/api/admin/templates')->assertOk()->json('data.abilities');
        $this->assertTrue($templates['can_view_language_packs']);
        $this->assertFalse($templates['can_install']);
    }

    /**
     * 플러그인 행 abilities 에 설정 저장 권한(can_update)이 있다 — 「설정」 메뉴가 이 값으로 잠긴다 (G20)
     *
     * @scenario endpoint=admin.plugins.index,permission=read+update|read+activate
     *
     * @effects plugin_row_can_update_follows_plugins_update
     *
     * @return void 반환값 없음
     */
    public function test_plugin_rows_carry_can_update(): void
    {
        $updater = $this->operatorWith(['core.plugins.read', 'core.plugins.update']);
        $rows = $this->actingAs($updater)->getJson('/api/admin/plugins?per_page=100')->assertOk()->json('data.data');
        $this->assertNotEmpty($rows, '번들 플러그인 목록이 비어 있다');
        $this->assertTrue($rows[0]['abilities']['can_update']);
        $this->assertFalse($rows[0]['abilities']['can_activate']);
    }

    /**
     * 자동 비활성 확장의 복구·닫기는 그 종류의 활성화 권한을 요구한다 — 플러그인 활성화 권한만으로 모듈은 403 (#7)
     *
     * @scenario endpoint=admin.extensions.dismiss,type=module|plugin,permission=plugins.activate
     *
     * @effects extension_recovery_requires_type_activate_permission
     *
     * @return void 반환값 없음
     */
    public function test_recovery_routes_follow_extension_type_permission(): void
    {
        $pluginAdmin = $this->operatorWith(['core.plugins.activate']);

        $this->actingAs($pluginAdmin)->postJson('/api/admin/extensions/module/any-module/dismiss')->assertForbidden();
        $this->actingAs($pluginAdmin)->postJson('/api/admin/extensions/template/any-template/recover')->assertForbidden();

        $moduleAdmin = $this->operatorWith(['core.modules.activate']);
        $this->actingAs($moduleAdmin)->getJson('/api/admin/extensions/auto-deactivated')->assertOk();
        $this->assertNotSame(403, $this->actingAs($moduleAdmin)->postJson('/api/admin/extensions/module/any-module/dismiss')->status());
    }

    /**
     * 모듈·플러그인 상세 응답에도 abilities 가 실린다 — 상세 창이 목록 행 전역을 덮어써도 권한 값이 남는다 (backend#15)
     *
     * @scenario endpoint=admin.modules.show|admin.plugins.show,permission=read
     *
     * @effects extension_detail_carries_abilities
     *
     * @return void 반환값 없음
     */
    public function test_extension_detail_carries_abilities(): void
    {
        $user = $this->operatorWith(['core.modules.read', 'core.plugins.read', 'core.plugins.update']);

        $module = $this->actingAs($user)->getJson('/api/admin/modules?per_page=1')->assertOk()->json('data.data.0.identifier');
        $this->assertNotNull($module, '번들 모듈 목록이 비어 있다');
        $detail = $this->actingAs($user)->getJson("/api/admin/modules/{$module}")->assertOk()->json('data.abilities');
        $this->assertIsArray($detail);
        $this->assertFalse($detail['can_install']);

        $plugin = $this->actingAs($user)->getJson('/api/admin/plugins?per_page=1')->assertOk()->json('data.data.0.identifier');
        $this->assertNotNull($plugin, '번들 플러그인 목록이 비어 있다');
        $pluginDetail = $this->actingAs($user)->getJson("/api/admin/plugins/{$plugin}")->assertOk()->json('data.abilities');
        $this->assertTrue($pluginDetail['can_update']);
    }
}
