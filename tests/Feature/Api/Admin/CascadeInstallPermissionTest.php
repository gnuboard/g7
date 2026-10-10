<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Extension\CascadeInstallAuthorizer;
use App\Services\ModuleService;
use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * 확장 설치 창 동반 설치 항목별 권한 검증 (R2).
 *
 * 설치 창은 본 확장의 install 권한으로 열리지만, 함께 고른 의존 확장은 설치·활성화까지,
 * 번들 언어팩은 설치·자동 활성화까지 수행된다. 대상 종류의 권한이 없으면 그 항목은
 * 서버가 403 으로 거부하고 아무것도 설치하지 않아야 한다.
 */
class CascadeInstallPermissionTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /**
     * 테스트 종료 시 Mockery 를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 주어진 관리자 권한만 가진 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 생성된 사용자
     */
    private function createAdminWith(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
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

        $role = Role::create([
            'identifier' => 'cascade_test_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'description' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 미설치 의존 플러그인을 돌려주는 PluginService 목을 등록합니다.
     *
     * @param  bool  $isInstalled  설치 여부
     * @return void 반환값 없음
     */
    private function mockPluginInfo(bool $isInstalled): void
    {
        $pluginService = Mockery::mock(PluginService::class)->makePartial();
        $pluginService->shouldReceive('getPluginInfo')
            ->with('dep-plugin')
            ->andReturn([
                'is_installed' => $isInstalled,
                'status' => $isInstalled ? ExtensionStatus::Inactive->value : 'uninstalled',
            ]);
        $pluginService->shouldNotReceive('installPlugin');
        $pluginService->shouldNotReceive('activatePlugin');
        $this->app->instance(PluginService::class, $pluginService);
    }

    /**
     * 모듈 설치 권한만 가진 운영자는 의존 플러그인을 함께 설치할 수 없다 — 403, 본 모듈도 설치하지 않음
     *
     * @scenario endpoint=modules.install,selection=dependency_plugin,permission=module_install_only
     *
     * @effects cascade_dependency_without_permission_returns_403_and_installs_nothing
     *
     * @return void 반환값 없음
     */
    public function test_module_install_rejects_dependency_plugin_without_plugin_permissions(): void
    {
        $this->mockPluginInfo(false);

        $moduleService = Mockery::mock(ModuleService::class)->makePartial();
        $moduleService->shouldNotReceive('installModule');
        $this->app->instance(ModuleService::class, $moduleService);

        $user = $this->createAdminWith(['core.modules.read', 'core.modules.install']);

        $response = $this->actingAs($user)->postJson('/api/admin/modules/install', [
            'module_name' => 'sirsoft-board',
            'dependencies' => [['type' => 'plugin', 'identifier' => 'dep-plugin']],
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('dep-plugin', (string) $response->json('message'));
    }

    /**
     * 설치된(꺼진) 의존 플러그인은 활성화 권한만 있으면 고를 수 있고, 없으면 403 이다
     *
     * @scenario endpoint=modules.install,selection=dependency_plugin_installed_inactive,permission=module_install_only
     *
     * @effects cascade_activation_without_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_module_install_rejects_activation_of_installed_dependency_without_activate_permission(): void
    {
        $this->mockPluginInfo(true);

        $moduleService = Mockery::mock(ModuleService::class)->makePartial();
        $moduleService->shouldNotReceive('installModule');
        $this->app->instance(ModuleService::class, $moduleService);

        // 플러그인 설치 권한은 있지만 활성화 권한이 없다
        $user = $this->createAdminWith(['core.modules.read', 'core.modules.install', 'core.plugins.install']);

        $this->actingAs($user)->postJson('/api/admin/modules/install', [
            'module_name' => 'sirsoft-board',
            'dependencies' => [['type' => 'plugin', 'identifier' => 'dep-plugin']],
        ])->assertStatus(403);
    }

    /**
     * 언어팩 권한이 없으면 번들 언어팩을 함께 설치할 수 없다 — 403
     *
     * @scenario endpoint=modules.install,selection=language_pack,permission=module_install_only
     *
     * @effects cascade_language_pack_without_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_module_install_rejects_language_pack_without_language_pack_permissions(): void
    {
        $moduleService = Mockery::mock(ModuleService::class)->makePartial();
        $moduleService->shouldNotReceive('installModule');
        $this->app->instance(ModuleService::class, $moduleService);

        $user = $this->createAdminWith(['core.modules.read', 'core.modules.install', 'core.language_packs.install']);

        $this->actingAs($user)->postJson('/api/admin/modules/install', [
            'module_name' => 'sirsoft-board',
            'language_packs' => ['g7-module-sirsoft-board-ja'],
        ])->assertStatus(403);
    }

    /**
     * 플러그인·템플릿 설치 API 도 같은 판정으로 403 을 낸다
     *
     * @scenario endpoint=plugins.install|templates.install,selection=dependency_plugin,permission=own_install_only
     *
     * @effects cascade_dependency_without_permission_returns_403_on_every_install_endpoint
     *
     * @return void 반환값 없음
     */
    public function test_plugin_and_template_install_reject_unauthorized_dependency(): void
    {
        $this->mockPluginInfo(false);

        $pluginUser = $this->createAdminWith(['core.plugins.read', 'core.plugins.install']);
        $this->actingAs($pluginUser)->postJson('/api/admin/plugins/install', [
            'plugin_name' => 'sirsoft-daum_postcode',
            'language_packs' => ['g7-plugin-sirsoft-daum_postcode-ja'],
        ])->assertStatus(403);

        $templateUser = $this->createAdminWith(['core.templates.read', 'core.templates.install']);
        $this->actingAs($templateUser)->postJson('/api/admin/templates/install', [
            'template_name' => 'sirsoft-basic',
            'dependencies' => [['type' => 'plugin', 'identifier' => 'dep-plugin']],
        ])->assertStatus(403);
    }

    /**
     * 판정 기준: 미설치 = install+activate, 설치됨 = activate, 언어팩 = install+manage
     *
     * @scenario unit=CascadeInstallAuthorizer,selection=all_kinds,permission=varied
     *
     * @effects can_select_matches_required_permissions
     *
     * @return void 반환값 없음
     */
    public function test_authorizer_requires_permissions_of_target_kind(): void
    {
        $authorizer = app(CascadeInstallAuthorizer::class);

        $full = $this->createAdminWith([
            'core.plugins.install', 'core.plugins.activate',
            'core.language_packs.install', 'core.language_packs.manage',
        ]);
        $this->assertTrue($authorizer->canSelectDependency('plugin', false, $full));
        $this->assertTrue($authorizer->canSelectDependency('plugin', true, $full));
        $this->assertFalse($authorizer->canSelectDependency('module', true, $full));
        $this->assertTrue($authorizer->canSelectLanguagePack($full));

        $activateOnly = $this->createAdminWith(['core.modules.activate']);
        $this->assertFalse($authorizer->canSelectDependency('module', false, $activateOnly));
        $this->assertTrue($authorizer->canSelectDependency('module', true, $activateOnly));
        $this->assertFalse($authorizer->canSelectLanguagePack($activateOnly));
        $this->assertFalse($authorizer->canSelectDependency('unknown', true, $full));
    }
}
