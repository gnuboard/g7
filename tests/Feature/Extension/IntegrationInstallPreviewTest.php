<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\LanguagePackScope;
use App\Extension\Helpers\IntegrationNotices;
use App\Extension\TemplateManager;
use App\Http\Controllers\Concerns\OrchestratesCascadeInstall;
use App\Http\Requests\Template\InstallTemplateRequest;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Extension\CascadeInstallAuthorizer;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use App\Services\ModuleService;
use App\Services\PluginService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

/**
 * 관리자 설치 창 「함께 쓰면 연동되는 확장(선택)」 (#822 P12·D9)
 *
 * 설치 미리보기는 연동 대상을 `integrations[]` 로 내고 미설치·비활성 항목을 기본 체크한다(템플릿 대상은 고를 수 없음).
 * 고른 연동은 의존 다음·본체 이전에 설치·활성화하고, 실패해도 본체 설치는 계속하며 실패는 `integration_notices` 로 알린다.
 * 권한은 의존 동반 설치와 같은 판정을 쓴다.
 */
class IntegrationInstallPreviewTest extends TestCase
{
    use DatabaseTransactions;
    use MockeryPHPUnitIntegration;

    private string $fixturePath;

    /**
     * 픽스처 템플릿 경로를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = base_path('templates/test-integration_preview');
        File::deleteDirectory($this->fixturePath);
    }

    /**
     * 픽스처 템플릿을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixturePath);
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 미리보기는 연동 대상의 상태·요구 버전·기본 체크를 내고, 템플릿 대상과 권한 없는 대상은 고를 수 없다.
     *
     * @scenario case=install_preview
     *
     * @effects install_preview_lists_integrations_preselected
     *
     * @return void 반환값 없음
     */
    public function test_preview_lists_integrations_with_default_selection(): void
    {
        Module::factory()->create(['identifier' => 'acme-board', 'version' => '1.0.0', 'status' => ExtensionStatus::Inactive->value]);
        Module::factory()->create(['identifier' => 'acme-page', 'version' => '1.2.0', 'status' => ExtensionStatus::Active->value]);
        $this->createFixtureTemplate([
            'modules' => ['acme-board' => '>=1.2.1', 'acme-page' => '>=1.0.0', 'acme-shop' => '*'],
            'plugins' => ['acme-postcode'],
            'templates' => ['acme-other_template'],
        ]);

        // 모듈만 함께 처리할 권한 — 플러그인은 고를 수 없다
        $this->mock(CascadeInstallAuthorizer::class, function ($mock): void {
            $mock->shouldReceive('canSelectDependency')->andReturnUsing(fn (string $type) => $type === 'module');
            $mock->shouldReceive('canSelectLanguagePack')->andReturn(false);
        });

        $preview = app(ExtensionInstallPreviewBuilder::class)->build(LanguagePackScope::Template, 'test-integration_preview');
        $byId = collect($preview['integrations'])->keyBy('identifier');

        $this->assertSame(['acme-board', 'acme-page', 'acme-shop', 'acme-postcode', 'acme-other_template'], $byId->keys()->all());

        $this->assertSame('inactive', $byId['acme-board']['status']);
        $this->assertSame('>=1.2.1', $byId['acme-board']['required_version']);
        $this->assertSame('1.0.0', $byId['acme-board']['installed_version']);
        $this->assertFalse($byId['acme-board']['is_met'], '설치됐지만 버전 미달');
        $this->assertTrue($byId['acme-board']['default_selected']);

        $this->assertSame('active', $byId['acme-page']['status']);
        $this->assertFalse($byId['acme-page']['default_selected'], '이미 켜진 연동은 기본 체크하지 않는다');

        $this->assertSame('not_installed', $byId['acme-shop']['status']);
        $this->assertSame('*', $byId['acme-shop']['required_version']);
        $this->assertTrue($byId['acme-shop']['default_selected']);

        $this->assertFalse($byId['acme-postcode']['can_select'], '플러그인 권한 없음');
        $this->assertFalse($byId['acme-postcode']['default_selected']);

        $this->assertSame('template', $byId['acme-other_template']['type']);
        $this->assertFalse($byId['acme-other_template']['can_select'], '템플릿 대상은 표시만 한다');
        $this->assertFalse($byId['acme-other_template']['default_selected']);

        $this->assertSame([], $preview['dependencies'], '연동은 의존 목록에 섞이지 않는다');
    }

    /**
     * 고른 연동은 상태별로 설치·활성화하고(활성은 건너뜀), 한 항목이 실패해도 나머지를 계속하고 사유를 돌려준다.
     *
     * @scenario case=cascade_best_effort
     *
     * @effects selected_integrations_installed_best_effort
     *
     * @return void 반환값 없음
     */
    public function test_selected_integrations_are_installed_best_effort(): void
    {
        $moduleService = Mockery::mock(ModuleService::class);
        $moduleService->shouldReceive('getModuleInfo')->with('acme-shop')->andReturn(['is_installed' => false, 'status' => 'uninstalled']);
        $moduleService->shouldReceive('getModuleInfo')->with('acme-page')->andReturn(['is_installed' => true, 'status' => ExtensionStatus::Active->value]);
        $moduleService->shouldReceive('getModuleInfo')->with('acme-broken')->andReturn(['is_installed' => true, 'status' => ExtensionStatus::Inactive->value]);
        $moduleService->shouldReceive('installModule')->once()->with('acme-shop', Mockery::any(), false, Mockery::any())->andReturn(['identifier' => 'acme-shop']);
        $moduleService->shouldReceive('activateModule')->once()->with('acme-shop')->andReturn(['success' => true]);
        $moduleService->shouldReceive('activateModule')->once()->with('acme-broken')->andReturn(['success' => false, 'warning' => true, 'message' => '필수 의존이 꺼져 있습니다']);
        $moduleService->shouldNotReceive('activateModule')->with('acme-page');
        $this->app->instance(ModuleService::class, $moduleService);

        $pluginService = Mockery::mock(PluginService::class);
        $pluginService->shouldReceive('getPluginInfo')->with('acme-sms')->andReturn(['is_installed' => true, 'status' => ExtensionStatus::Inactive->value]);
        $pluginService->shouldReceive('activatePlugin')->once()->with('acme-sms')->andThrow(new RuntimeException('플러그인 오류'));
        $this->app->instance(PluginService::class, $pluginService);

        $failures = $this->harness()->installSelectedIntegrations([
            ['type' => 'module', 'identifier' => 'acme-broken'],
            ['type' => 'plugin', 'identifier' => 'acme-sms'],
            ['type' => 'module', 'identifier' => 'acme-shop'],
            ['type' => 'module', 'identifier' => 'acme-page'],
            ['type' => 'template', 'identifier' => 'acme-ignored'],
        ]);

        $this->assertSame(['module:acme-broken' => '필수 의존이 꺼져 있습니다', 'plugin:acme-sms' => '플러그인 오류'], $failures);
    }

    /**
     * 함께 설치하다 실패한 연동은 본체 설치 뒤 안내 문구에 실패 사유를 싣는다.
     *
     * @scenario case=cascade_failure_notice
     *
     * @effects integration_failure_reported_as_notice
     *
     * @return void 반환값 없음
     */
    public function test_failed_integration_reason_reaches_notice(): void
    {
        app()->setLocale('ko');
        $this->mock(ModuleManagerInterface::class, function ($mock): void {
            $mock->shouldReceive('getModule')->with('acme-main')->andReturnUsing(function () {
                $instance = Mockery::mock(ModuleInterface::class);
                $instance->shouldReceive('getIntegrationConstraints')->andReturn(['modules' => ['acme-shop' => '*'], 'plugins' => [], 'templates' => []]);

                return $instance;
            });
        });

        $notices = $this->harness()->buildIntegrationNotices('module', 'acme-main', ['module:acme-shop' => '내려받기 실패']);

        $this->assertCount(1, $notices);
        $this->assertSame('not_installed', $notices[0]['state']);
        $this->assertStringContainsString('내려받기 실패', $notices[0]['message']);
    }

    /**
     * 함께 설치하다 실패한 연동 안내는 사유를 따로 싣는다 — 설치 창 토스트가 문장 전체가 아니라 이름·상태 뒤에 사유를 붙여 보인다.
     *
     * 실패하지 않은 안내는 사유가 null 이다. 사유가 마침표로 끝나도 완료 문구 뒤에 붙는 요약 한 줄에 마침표가 겹치지 않는다
     * (「…다시 시도하세요.. 설치·활성화·업데이트하면…」 회귀).
     *
     * @scenario case=cascade_failure_notice
     *
     * @effects integration_failure_reason_exposed_for_screen
     *
     * @return void 반환값 없음
     */
    public function test_failed_integration_notice_carries_reason_without_double_period(): void
    {
        app()->setLocale('ko');
        $this->mock(ModuleManagerInterface::class, function ($mock): void {
            $mock->shouldReceive('getModule')->with('acme-main')->andReturnUsing(function () {
                $instance = Mockery::mock(ModuleInterface::class);
                $instance->shouldReceive('getIntegrationConstraints')->andReturn(['modules' => ['acme-shop' => '*', 'acme-other' => '*'], 'plugins' => [], 'templates' => []]);

                return $instance;
            });
        });

        $reason = 'Acme 확장을 쓰려면 서버에 PHP 확장 acme 이(가) 필요합니다. 설치 후 다시 시도하세요.';
        $notices = collect($this->harness()->buildIntegrationNotices('module', 'acme-main', ['module:acme-shop' => $reason]))->keyBy('identifier');

        $this->assertSame($reason, $notices['acme-shop']['failure_reason']);
        $this->assertArrayHasKey('failure_reason', $notices['acme-other']);
        $this->assertNull($notices['acme-other']['failure_reason'], '실패하지 않은 안내는 사유가 없다');

        $summary = IntegrationNotices::appendToMessage('설치했습니다.', $notices->values()->all());
        $this->assertStringContainsString('다시 시도하세요', $summary);
        $this->assertStringNotContainsString('..', $summary);
    }

    /**
     * 연동 선택은 모듈·플러그인만 받는다 — 템플릿은 유형마다 하나만 켜므로 함께 고를 수 없다.
     *
     * @scenario case=install_request
     *
     * @effects install_request_validates_integrations
     *
     * @return void 반환값 없음
     */
    public function test_install_request_accepts_only_module_and_plugin_integrations(): void
    {
        $rules = (new InstallTemplateRequest)->rules();

        $ok = Validator::make(['template_name' => 'sirsoft-basic', 'integrations' => [['type' => 'module', 'identifier' => 'sirsoft-board']]], $rules);
        $template = Validator::make(['template_name' => 'sirsoft-basic', 'integrations' => [['type' => 'template', 'identifier' => 'sirsoft-admin_basic']]], $rules);
        $badId = Validator::make(['template_name' => 'sirsoft-basic', 'integrations' => [['type' => 'module', 'identifier' => '../evil']]], $rules);

        $this->assertFalse($ok->fails());
        $this->assertTrue($template->errors()->has('integrations.0.type'));
        $this->assertTrue($badId->errors()->has('integrations.0.identifier'));
    }

    /**
     * 설치 API 는 연동 대상도 권한을 먼저 확인한다 — 권한이 없으면 403 이고 본체도 설치하지 않는다.
     *
     * @scenario case=install_api_permission
     *
     * @effects integration_selection_requires_cascade_permission
     *
     * @return void 반환값 없음
     */
    public function test_install_api_rejects_integration_without_permission(): void
    {
        $pluginService = Mockery::mock(PluginService::class)->makePartial();
        $pluginService->shouldReceive('getPluginInfo')->with('acme-sms')->andReturn(['is_installed' => false, 'status' => 'uninstalled']);
        $pluginService->shouldNotReceive('installPlugin');
        $this->app->instance(PluginService::class, $pluginService);

        $moduleService = Mockery::mock(ModuleService::class)->makePartial();
        $moduleService->shouldNotReceive('installModule');
        $this->app->instance(ModuleService::class, $moduleService);

        $response = $this->actingAs($this->createAdminWith(['core.modules.read', 'core.modules.install']))
            ->postJson('/api/admin/modules/install', [
                'module_name' => 'sirsoft-board',
                'integrations' => [['type' => 'plugin', 'identifier' => 'acme-sms']],
            ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('acme-sms', (string) $response->json('message'));
    }

    /**
     * 고른 연동은 본체보다 먼저 켜지고, 연동이 실패해도 본체 설치는 성공하며 응답에 `integration_notices` 가 실린다.
     *
     * @scenario case=install_api_order
     *
     * @effects integration_installed_before_main_and_never_blocks
     *
     * @return void 반환값 없음
     */
    public function test_install_api_activates_integrations_before_main_and_never_blocks(): void
    {
        $calls = [];

        $pluginService = Mockery::mock(PluginService::class)->makePartial();
        $pluginService->shouldReceive('getPluginInfo')->andReturnUsing(fn (string $id) => ['is_installed' => true, 'status' => ExtensionStatus::Inactive->value]);
        $pluginService->shouldReceive('activatePlugin')->andReturnUsing(function (string $id) use (&$calls) {
            $calls[] = 'integration:'.$id;
            if ($id === 'acme-broken') {
                throw new RuntimeException('켤 수 없음');
            }

            return ['success' => true];
        });
        $this->app->instance(PluginService::class, $pluginService);

        $moduleService = Mockery::mock(ModuleService::class)->makePartial();
        $moduleService->shouldReceive('installModule')->once()->andReturnUsing(function (string $id) use (&$calls) {
            $calls[] = 'main:'.$id;

            return ['identifier' => $id, 'name' => $id, 'status' => ExtensionStatus::Inactive->value];
        });
        $this->app->instance(ModuleService::class, $moduleService);

        $response = $this->actingAs($this->createAdminWith(['core.modules.read', 'core.modules.install', 'core.plugins.activate']))
            ->postJson('/api/admin/modules/install', [
                'module_name' => 'sirsoft-board',
                'integrations' => [
                    ['type' => 'plugin', 'identifier' => 'acme-sms'],
                    ['type' => 'plugin', 'identifier' => 'acme-broken'],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertSame(['integration:acme-sms', 'integration:acme-broken', 'main:sirsoft-board'], $calls);
        $this->assertIsArray($response->json('data.integration_notices'));
    }

    /**
     * 연동 선언을 담은 픽스처 템플릿을 활성 디렉토리에 만듭니다(설치하지 않음).
     *
     * @param  array<string, mixed>  $integrations  template.json 의 integrations
     * @return void 반환값 없음
     */
    private function createFixtureTemplate(array $integrations): void
    {
        File::makeDirectory($this->fixturePath.'/layouts', 0755, true);
        File::put($this->fixturePath.'/template.json', (string) json_encode([
            'identifier' => 'test-integration_preview',
            'vendor' => 'test',
            'name' => ['ko' => '연동 미리보기', 'en' => 'Integration preview'],
            'version' => '1.0.0',
            'type' => 'user',
            'description' => ['ko' => '', 'en' => ''],
            'dependencies' => ['modules' => (object) [], 'plugins' => (object) []],
            'integrations' => $integrations,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        app(TemplateManager::class)->loadTemplates();
    }

    /**
     * 설치 오케스트레이션 trait 의 보호 메서드를 부르는 하네스를 만듭니다.
     *
     * @return object 하네스
     */
    private function harness(): object
    {
        return new class
        {
            use OrchestratesCascadeInstall {
                installSelectedIntegrations as public;
                buildIntegrationNotices as public;
            }
        };
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
            'identifier' => 'integration_test_'.uniqid(),
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
}
