<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 레이아웃 갱신 API 3종이 빈 자리로 등록한 조각 참조 경고(`layout_warnings`)를 같은 자리에 싣는지 검증 (#712)
 *
 * 서비스는 세 확장 모두 갱신 결과에 `layout_warnings` 를 돌려주지만, 응답을 만드는 방식이 셋 다 달랐다 —
 * 모듈은 `ModuleResource` 가 필드를 버리고, 플러그인은 컨트롤러가 응답 배열을 손으로 짜며 빠뜨리고,
 * 템플릿만 `TemplateResource` 로 실렸다. 개발자 문서는 갱신 결과에 경고가 실린다고 약속한다.
 */
class LayoutRefreshWarningsResponseTest extends TestCase
{
    use DatabaseTransactions;

    /** 서비스가 돌려줄 경고 한 건 */
    private const WARNING = [
        'file' => 'modules/probe/resources/layouts/admin/host.json',
        'partial' => 'partials/does_not_exist.json',
        'reason' => 'include_file_not_found',
        'message' => 'probe',
    ];

    private string $token;

    /**
     * 테스트 준비 — 세 확장의 활성화 권한을 가진 관리자 토큰을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'admin_refresh_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'is_active' => true,
        ]);

        foreach (['core.modules.activate', 'core.plugins.activate', 'core.templates.activate'] as $identifier) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'description' => ['ko' => $identifier, 'en' => $identifier],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '관리자', 'en' => 'Administrator'],
            'is_active' => true,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id, $admin->id]);

        $this->token = $user->createToken('refresh-warnings')->plainTextToken;
    }

    /**
     * 모듈 레이아웃 갱신 응답이 `data.layout_warnings` 를 싣는다.
     *
     * @scenario case=refresh_response_warnings
     *
     * @effects refresh_layouts_response_carries_layout_warnings
     *
     * @return void 반환값 없음
     */
    public function test_module_refresh_response_carries_layout_warnings(): void
    {
        $this->mock(ModuleService::class, function ($mock): void {
            $mock->shouldReceive('refreshModuleLayouts')->andReturn([
                'identifier' => 'probe-module',
                'status' => 'active',
                'layout_warnings' => [self::WARNING],
            ]);
        });

        $this->withToken($this->token)
            ->postJson('/api/admin/modules/refresh-layouts', ['module_name' => 'probe-module'])
            ->assertOk()
            ->assertJsonPath('data.layout_warnings.0.reason', 'include_file_not_found');
    }

    /**
     * 플러그인 레이아웃 갱신 응답이 `data.layout_warnings` 를 싣는다.
     *
     * @scenario case=refresh_response_warnings
     *
     * @effects refresh_layouts_response_carries_layout_warnings
     *
     * @return void 반환값 없음
     */
    public function test_plugin_refresh_response_carries_layout_warnings(): void
    {
        $this->mock(PluginService::class, function ($mock): void {
            $mock->shouldReceive('refreshPluginLayouts')->andReturn([
                'success' => true,
                'layouts_refreshed' => 1,
                'created' => 0,
                'updated' => 1,
                'deleted' => 0,
                'unchanged' => 0,
                'layout_warnings' => [self::WARNING],
            ]);
        });

        $this->withToken($this->token)
            ->postJson('/api/admin/plugins/refresh-layouts', ['plugin_name' => 'probe-plugin'])
            ->assertOk()
            ->assertJsonPath('data.layout_warnings.0.reason', 'include_file_not_found');
    }

    /**
     * 템플릿 레이아웃 갱신 응답이 `data.layout_warnings` 를 싣는다.
     *
     * @scenario case=refresh_response_warnings
     *
     * @effects refresh_layouts_response_carries_layout_warnings
     *
     * @return void 반환값 없음
     */
    public function test_template_refresh_response_carries_layout_warnings(): void
    {
        $this->mock(TemplateService::class, function ($mock): void {
            $mock->shouldReceive('refreshTemplateLayouts')->andReturn([
                'identifier' => 'probe-template',
                'status' => 'active',
                'layout_warnings' => [self::WARNING],
            ]);
        });

        $this->withToken($this->token)
            ->postJson('/api/admin/templates/refresh-layouts', ['template_name' => 'probe-template'])
            ->assertOk()
            ->assertJsonPath('data.layout_warnings.0.reason', 'include_file_not_found');
    }
}
