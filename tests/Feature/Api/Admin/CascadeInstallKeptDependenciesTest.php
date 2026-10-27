<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * 관리자 설치 창에서 의존 확장을 함께 설치한 뒤 본 확장 설치가 실패하면, 실패 안내에 남겨 둔 의존 확장을 함께 보인다(D14).
 *
 * 회귀: 본 확장 설치가 검증 예외(의존 버전 미달 등 — 422)로 실패하는 분기는 안내를 붙이지 않았다.
 * 실측: 이커머스 번들 버전을 제약 미달로 바꾸고 토스페이먼츠를 설치하면 이커머스는 설치·활성화된 채 남는데
 * 응답은 「…요구 버전(>=1.3.0)을 충족하지 않습니다.」만 냈다.
 */
class CascadeInstallKeptDependenciesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 확장 설치·활성화 권한을 가진 관리자를 만든다 (공용 행은 firstOrCreate — 트랜잭션 격리라 기존 데이터와 공존).
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
        foreach (['modules', 'plugins', 'templates'] as $kind) {
            foreach (['install', 'activate'] as $action) {
                $identifier = "core.{$kind}.{$action}";
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
        }
        $role->permissions()->syncWithoutDetaching($ids);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 의존 확장 단계는 「acme-shop 을 설치·활성화했다」고 돌려주고, 본 확장 설치는 검증 예외로 실패하게 한다.
     *
     * @param  \Throwable  $failure  본 확장 설치가 던질 예외
     * @return void 반환값 없음
     */
    private function dependencyKeptThenMainFails(\Throwable $failure): void
    {
        $orchestrator = Mockery::mock(CascadeInstallOrchestrator::class)->shouldIgnoreMissing();
        $orchestrator->shouldReceive('orderSelectedDependencies')->andReturnUsing(fn ($type, $id, array $selected) => $selected);
        $orchestrator->shouldReceive('installDependencies')->andReturn(['installed' => ['acme-shop'], 'activated' => ['acme-shop'], 'skipped' => []]);
        $this->app->instance(CascadeInstallOrchestrator::class, $orchestrator);

        $modules = Mockery::mock(ModuleManagerInterface::class)->shouldIgnoreMissing();
        $modules->shouldReceive('installModule')->andThrow($failure);
        $this->app->instance(ModuleManagerInterface::class, $modules);

        $plugins = Mockery::mock(PluginManagerInterface::class)->shouldIgnoreMissing();
        $plugins->shouldReceive('installPlugin')->andThrow($failure);
        $this->app->instance(PluginManagerInterface::class, $plugins);

        $templates = Mockery::mock(TemplateManagerInterface::class)->shouldIgnoreMissing();
        $templates->shouldReceive('getTemplateInfo')->andReturn(['identifier' => 'acme-theme']);
        $templates->shouldReceive('installTemplate')->andThrow($failure);
        $this->app->instance(TemplateManagerInterface::class, $templates);
        // TemplateService 는 싱글톤이라 부팅 중 실제 매니저로 이미 만들어져 있다 — 가짜 매니저로 다시 만든다.
        $this->app->forgetInstance(TemplateService::class);
    }

    /**
     * 모듈·플러그인·템플릿 설치가 검증 예외로 실패해도 남겨 둔 의존 확장(acme-shop)을 실패 안내에 붙인다.
     *
     * @effects cascade_kept_dependencies_listed_on_validation_failure
     *
     * @return void 반환값 없음
     */
    public function test_validation_failure_lists_kept_dependencies(): void
    {
        $reason = '의존하는 "acme-shop" 의 설치 버전(1.2.9)이 요구 버전(>=1.3.0)을 충족하지 않습니다.';
        $this->dependencyKeptThenMainFails(ValidationException::withMessages(['name' => [$reason]]));
        $admin = $this->admin();
        $kept = CascadeInstallOrchestrator::keptDependenciesNotice(['installed' => ['acme-shop']]);
        $dependencies = [['type' => 'module', 'identifier' => 'acme-shop']];

        $calls = [
            'module' => ['/api/admin/modules/install', ['module_name' => 'acme-core', 'dependencies' => $dependencies]],
            'plugin' => ['/api/admin/plugins/install', ['plugin_name' => 'acme-pay', 'dependencies' => $dependencies]],
            'template' => ['/api/admin/templates/install', ['template_name' => 'acme-theme', 'dependencies' => $dependencies]],
        ];

        foreach ($calls as $label => [$uri, $payload]) {
            $response = $this->actingAs($admin, 'sanctum')->postJson($uri, $payload);
            $body = $response->getContent();

            $this->assertGreaterThanOrEqual(400, $response->status(), "{$label}: {$body}");
            $this->assertStringContainsString($reason, (string) $response->json('message'), "{$label}: 실패 사유가 없다 — {$body}");
            $this->assertStringContainsString((string) $kept, (string) $response->json('message'), "{$label}: 남긴 의존 확장 안내가 없다 — {$body}");
        }
    }
}
