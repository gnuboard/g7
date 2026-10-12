<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 관리자 설치·업데이트·레이아웃 갱신 창 9개의 '고급 설정' 레이아웃 설치 소스(`layout_source`)가 그 요청 동안만
 * 설정을 덮고 끝나면 되돌아가는지 봉인한다 (#144 D10).
 *
 * 서비스는 mock 으로 바꿔 **서비스가 호출되는 순간의 설정값**을 잰다 — 서비스·매니저 시그니처는 그대로이고 설정이
 * 유일한 전달 경로다. 서비스가 예외를 던져도 설정이 복원돼야 한다(같은 앱 인스턴스를 쓰는 다음 요청에 새지 않게).
 */
class ExtensionLayoutSourceRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 9개 창의 API·서비스 메서드·요청 본문을 제공합니다.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: array<string, mixed>}> 데이터셋 이름 → [서비스, 메서드, URL, 본문]
     */
    public static function endpointProvider(): array
    {
        return [
            '템플릿 설치' => [TemplateService::class, 'installTemplate', '/api/admin/templates/install', ['template_name' => 'acme-tpl']],
            '템플릿 업데이트' => [TemplateService::class, 'performVersionUpdate', '/api/admin/templates/acme-tpl/update', []],
            '템플릿 레이아웃 갱신' => [TemplateService::class, 'refreshTemplateLayouts', '/api/admin/templates/refresh-layouts', ['template_name' => 'acme-tpl']],
            '모듈 설치' => [ModuleService::class, 'installModule', '/api/admin/modules/install', ['module_name' => 'acme-mod']],
            '모듈 업데이트' => [ModuleService::class, 'updateModule', '/api/admin/modules/acme-mod/update', []],
            '모듈 레이아웃 갱신' => [ModuleService::class, 'refreshModuleLayouts', '/api/admin/modules/refresh-layouts', ['module_name' => 'acme-mod']],
            '플러그인 설치' => [PluginService::class, 'installPlugin', '/api/admin/plugins/install', ['plugin_name' => 'acme-plg']],
            '플러그인 업데이트' => [PluginService::class, 'updatePlugin', '/api/admin/plugins/acme-plg/update', []],
            '플러그인 레이아웃 갱신' => [PluginService::class, 'refreshPluginLayouts', '/api/admin/plugins/refresh-layouts', ['plugin_name' => 'acme-plg']],
        ];
    }

    /**
     * 요청값이 서비스 호출 동안 설정에 실리고, 요청이 끝나면(서비스가 실패해도) 이전 값으로 돌아간다.
     *
     * @scenario case=layout_source_scoped_to_request
     *
     * @effects layout_source_reaches_service_and_is_restored_after_request
     *
     * @param  class-string  $serviceClass  서비스 클래스
     * @param  string  $method  서비스 메서드
     * @param  string  $url  API 경로
     * @param  array<string, mixed>  $body  기본 요청 본문
     * @return void 반환값 없음
     */
    #[DataProvider('endpointProvider')]
    public function test_layout_source_is_scoped_to_the_request(string $serviceClass, string $method, string $url, array $body): void
    {
        config(['template.layout_markup.install_source' => 'auto']);

        $seen = [];
        $service = Mockery::mock($serviceClass);
        $service->shouldReceive($method)->andReturnUsing(function () use (&$seen) {
            $seen[] = config('template.layout_markup.install_source');

            throw new \RuntimeException('probe');
        });
        $this->app->instance($serviceClass, $service);

        $admin = $this->adminWithPermissions(['core.templates.install', 'core.templates.activate', 'core.modules.install', 'core.modules.activate', 'core.plugins.install', 'core.plugins.activate']);

        $this->actingAs($admin)->postJson($url, $body + ['layout_source' => 'json']);
        $this->assertSame(['json'], $seen, '요청한 json 이 서비스 호출 시점의 설정에 실리지 않았습니다');
        $this->assertSame('auto', config('template.layout_markup.install_source'), '요청이 끝났는데 설정이 복원되지 않았습니다');

        // 값을 생략하면 덮지 않는다 — 설정(.env)을 그대로 따른다.
        config(['template.layout_markup.install_source' => 'json']);
        $this->actingAs($admin)->postJson($url, $body);
        $this->assertSame(['json', 'json'], $seen, 'layout_source 를 생략했는데 설정을 덮었습니다');

        $this->actingAs($admin)->postJson($url, $body + ['layout_source' => 'auto']);
        $this->assertSame(['json', 'json', 'auto'], $seen, '명시한 auto 가 설정보다 우선하지 않았습니다');
        $this->assertSame('json', config('template.layout_markup.install_source'));
    }

    /**
     * 허용 밖 값은 서비스까지 가지 않고 422 와 전용 문구로 거부된다.
     *
     * @scenario case=layout_source_invalid_rejected
     *
     * @effects invalid_layout_source_rejected_with_message
     *
     * @param  class-string  $serviceClass  서비스 클래스
     * @param  string  $method  서비스 메서드
     * @param  string  $url  API 경로
     * @param  array<string, mixed>  $body  기본 요청 본문
     * @return void 반환값 없음
     */
    #[DataProvider('endpointProvider')]
    public function test_unknown_layout_source_is_rejected(string $serviceClass, string $method, string $url, array $body): void
    {
        $service = Mockery::mock($serviceClass);
        $service->shouldNotReceive($method);
        $this->app->instance($serviceClass, $service);

        $admin = $this->adminWithPermissions(['core.templates.install', 'core.templates.activate', 'core.modules.install', 'core.modules.activate', 'core.plugins.install', 'core.plugins.activate']);
        $group = str_contains($url, '/templates/') ? 'templates' : (str_contains($url, '/modules/') ? 'modules' : 'plugins');

        $this->actingAs($admin)->postJson($url, $body + ['layout_source' => 'xml'])
            ->assertStatus(422)
            ->assertJsonPath('errors.layout_source.0', __("{$group}.errors.invalid_layout_source"));
    }

    /**
     * 설치·갱신 권한이 없는 관리자는 레이아웃 소스를 실어도 403 이다 (권한 미들웨어는 그대로).
     *
     * @scenario case=layout_source_permission_denied
     *
     * @effects layout_source_request_keeps_permission_gate
     *
     * @param  class-string  $serviceClass  서비스 클래스
     * @param  string  $method  서비스 메서드
     * @param  string  $url  API 경로
     * @param  array<string, mixed>  $body  기본 요청 본문
     * @return void 반환값 없음
     */
    #[DataProvider('endpointProvider')]
    public function test_admin_without_permission_is_forbidden(string $serviceClass, string $method, string $url, array $body): void
    {
        $service = Mockery::mock($serviceClass);
        $service->shouldNotReceive($method);
        $this->app->instance($serviceClass, $service);

        $readOnly = $this->adminWithPermissions(['core.templates.read', 'core.modules.read', 'core.plugins.read']);

        $this->actingAs($readOnly)->postJson($url, $body + ['layout_source' => 'json'])->assertStatus(403);
        $this->assertNotSame('json', config('template.layout_markup.install_source'));
    }

    /**
     * 주어진 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 관리자
     */
    private function adminWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        $ids = [];
        foreach ($permissions as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'layout_source_'.uniqid(),
            'name' => json_encode(['ko' => '시험', 'en' => 'Test']),
            'description' => json_encode(['ko' => '시험', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($ids);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
