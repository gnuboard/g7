<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * 의존 확장 관련 응답 문구 (#138 실측 ① 후속 — 의존 조회가 살아나며 처음 드러난 문구)
 *
 * - 제거 실패: Service 가 이미 「모듈 제거에 실패했습니다: …」로 감싼 사유를 컨트롤러가 같은 문구로 한 번 더 감싸
 *   「모듈 제거에 실패했습니다: 모듈 제거에 실패했습니다: …」가 토스트에 나왔다.
 * - 비활성화 경고: 의존하는 것이 플러그인·모듈이어도 「의존하는 활성 **템플릿**이 있습니다」라고 답했다
 *   (의존 조회가 늘 비어 있던 동안에는 이 경고가 뜰 일이 없어 드러나지 않았다).
 */
class ExtensionDependentsResponseMessageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 모듈·플러그인 관리 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function admin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'description' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $ids = [];
        foreach (['core.modules.uninstall', 'core.modules.activate', 'core.plugins.uninstall', 'core.plugins.activate', 'core.templates.uninstall'] as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            )->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 모듈·플러그인 제거 실패 문구는 「…제거에 실패했습니다:」를 한 번만 담는다.
     *
     * @scenario case=uninstall_failure_message
     *
     * @effects uninstall_failure_prefix_appears_once
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_failure_message_is_not_wrapped_twice(): void
    {
        $blocked = __('modules.errors.uninstall_blocked_by_dependents', ['module' => 'acme-core', 'extensions' => 'acme-pay']);
        $moduleReason = __('modules.uninstallation_failed', ['error' => $blocked]);
        $modules = Mockery::mock(ModuleService::class);
        $modules->shouldReceive('uninstallModule')->andThrow(ValidationException::withMessages(['module_name' => [$moduleReason]]));
        $this->app->instance(ModuleService::class, $modules);

        $pluginReason = __('plugins.uninstallation_failed', ['error' => 'boom']);
        $plugins = Mockery::mock(PluginService::class);
        $plugins->shouldReceive('uninstallPlugin')->andThrow(ValidationException::withMessages(['plugin_name' => [$pluginReason]]));
        $this->app->instance(PluginService::class, $plugins);

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/modules/uninstall', ['module_name' => 'acme-core'])
            ->assertStatus(422)
            ->assertJsonPath('message', $moduleReason);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/plugins/uninstall', ['plugin_name' => 'acme-pay'])
            ->assertStatus(422)
            ->assertJsonPath('message', $pluginReason);
    }

    /**
     * 템플릿 제거 실패 문구도 「템플릿 제거에 실패했습니다:」를 한 번만 담고 치환 자리표시자를 남기지 않는다.
     *
     * Service 가 자리표시자를 채우지 않고 사유를 이어 붙인 뒤(「…실패했습니다: :error: 사유」) 컨트롤러가 같은 문구로
     * 한 번 더 감싸고 있었다 — 모듈·플러그인만 고쳐지고 템플릿이 남아 있던 같은 결함.
     *
     * @scenario case=template_uninstall_failure_message
     *
     * @effects uninstall_failure_prefix_appears_once
     *
     * @return void 반환값 없음
     */
    public function test_template_uninstall_failure_message_is_wrapped_once(): void
    {
        $manager = Mockery::mock(TemplateManagerInterface::class)->shouldIgnoreMissing();
        $manager->shouldReceive('getTemplateInfo')->andReturn(['identifier' => 'acme-theme']);
        $manager->shouldReceive('uninstallTemplate')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(TemplateManagerInterface::class, $manager);

        $expected = __('templates.errors.uninstallation_failed', ['error' => 'boom']);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/admin/templates/uninstall', ['template_name' => 'acme-theme'])
            ->assertStatus(422)
            ->assertJsonPath('message', $expected);
    }

    /**
     * 모듈 비활성화 경고는 의존하는 것이 템플릿이 아니어도 맞는 문구(「활성화된 확장」)로 답한다.
     *
     * @scenario case=deactivate_warning_message
     *
     * @effects module_deactivate_warning_names_extensions_not_only_templates
     *
     * @return void 반환값 없음
     */
    public function test_module_deactivate_warning_names_extensions(): void
    {
        $modules = Mockery::mock(ModuleService::class);
        $modules->shouldReceive('deactivateModule')->andReturn([
            'success' => false,
            'warning' => true,
            'dependent_templates' => [],
            'dependent_modules' => [],
            'dependent_plugins' => [['identifier' => 'acme-pay', 'name' => 'Acme Pay']],
            'message' => __('modules.errors.has_dependents'),
        ]);
        $this->app->instance(ModuleService::class, $modules);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/modules/deactivate', ['module_name' => 'acme-core'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('modules.deactivate_warning'));

        $this->assertStringNotContainsString('템플릿', __('modules.deactivate_warning', [], 'ko'));
        $this->assertStringNotContainsString('template', strtolower(__('modules.deactivate_warning', [], 'en')));
    }
}
