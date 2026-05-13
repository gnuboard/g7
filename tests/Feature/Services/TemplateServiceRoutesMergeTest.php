<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Repositories\TemplateRepository;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateServiceRoutesMergeTest extends TestCase
{
    use RefreshDatabase;

    private TemplateService $templateService;

    private TemplateRepository $templateRepository;

    private TemplateManagerInterface $templateManager;

    private ModuleManagerInterface $moduleManager;

    private PluginManagerInterface $pluginManager;

    /** @var bool 활성 디렉토리가 테스트 전에 이미 존재했는지 (tearDown에서 정리 판단용) */
    private bool $boardExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 라우트 파일 테스트를 위해 _bundled에서 활성 디렉토리로 복사
        $activePath = base_path('modules/sirsoft-board');
        $bundledPath = base_path('modules/_bundled/sirsoft-board');
        $this->boardExistedBefore = File::isDirectory($activePath);
        if (! $this->boardExistedBefore && File::isDirectory($bundledPath)) {
            File::copyDirectory($bundledPath, $activePath);
        }

        // Mock 의존성
        $this->templateManager = $this->createMock(TemplateManagerInterface::class);
        $this->moduleManager = $this->createMock(ModuleManagerInterface::class);
        $this->pluginManager = $this->createMock(PluginManagerInterface::class);

        $this->templateRepository = new TemplateRepository;

        // TemplateManager의 loadTemplates() 호출 Mock 처리
        $this->templateManager->method('loadTemplates');

        $this->templateService = new TemplateService(
            $this->templateRepository,
            $this->templateManager,
            $this->moduleManager,
            $this->pluginManager
        );
    }

    protected function tearDown(): void
    {
        // 테스트에서 생성한 활성 디렉토리만 정리 (기존에 있었으면 건드리지 않음)
        if (! $this->boardExistedBefore) {
            $activePath = base_path('modules/sirsoft-board');
            if (File::isDirectory($activePath)) {
                File::deleteDirectory($activePath);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function get_routes_data_returns_template_routes(): void
    {
        // Given: 활성화된 템플릿 생성
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        // Template Manager Mock 설정
        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        // Module Manager Mock 설정 (활성 모듈 없음)
        $this->moduleManager->method('getActiveModules')->willReturn([]);

        // When: routes 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: 성공적으로 템플릿 routes 반환
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('version', $result['data']);
        $this->assertArrayHasKey('routes', $result['data']);
        $this->assertIsArray($result['data']['routes']);
    }

    #[Test]
    public function module_routes_data_is_merged_with_template_routes(): void
    {
        // Given: 활성화된 템플릿 생성
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        // Template Manager Mock 설정
        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        // Module Manager Mock 설정
        $mockModule = $this->createMock(\App\Contracts\Extension\ModuleInterface::class);
        $mockModule->method('getIdentifier')->willReturn('sirsoft-board');
        $this->moduleManager->method('getActiveModules')->willReturn([
            'sirsoft-board' => $mockModule,
        ]);

        // When: routes 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: 성공 및 병합된 routes 반환
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);

        // 모듈 routes가 포함되어야 함 (sirsoft-board 모듈)
        $routes = $result['data']['routes'];
        $modulePaths = array_filter($routes, function ($route) {
            return str_contains($route['path'], '/admin/boards');
        });
        $this->assertNotEmpty($modulePaths, 'Module routes should be merged');
    }

    #[Test]
    public function returns_error_for_nonexistent_template(): void
    {
        // Given: 존재하지 않는 템플릿

        // When: routes 조회
        $result = $this->templateService->getRoutesDataWithModules('nonexistent-template');

        // Then: 에러 반환
        $this->assertFalse($result['success']);
        $this->assertEquals('template_not_found', $result['error']);
    }

    #[Test]
    public function returns_error_when_routes_file_not_found(): void
    {
        // Given: 활성화된 템플릿 생성 (routes.json 없는 템플릿)
        Template::factory()->create([
            'identifier' => 'nonexistent-routes-template',
            'status' => ExtensionStatus::Active->value,
        ]);

        // Template Manager Mock 설정
        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'nonexistent-routes-template',
            'type' => 'admin',
        ]);

        // When: routes 조회
        $result = $this->templateService->getRoutesDataWithModules('nonexistent-routes-template');

        // Then: routes_not_found 에러 반환
        $this->assertFalse($result['success']);
        $this->assertEquals('routes_not_found', $result['error']);
    }

    #[Test]
    public function module_routes_merged_correctly(): void
    {
        // Given: 활성화된 템플릿
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        // Template Manager Mock
        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        // Module Manager Mock
        $mockModule = $this->createMock(\App\Contracts\Extension\ModuleInterface::class);
        $mockModule->method('getIdentifier')->willReturn('sirsoft-board');
        $this->moduleManager->method('getActiveModules')->willReturn([
            'sirsoft-board' => $mockModule,
        ]);

        // When: routes 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: routes 배열에 템플릿과 모듈 routes 모두 포함
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        // 템플릿 routes 확인 (예: /admin/dashboard)
        $templateRoute = array_filter($routes, function ($route) {
            return $route['path'] === '*/admin/dashboard';
        });
        $this->assertNotEmpty($templateRoute, 'Template routes should exist');

        // 모듈 routes 확인 (예: /admin/boards)
        $moduleRoute = array_filter($routes, function ($route) {
            return $route['path'] === '*/admin/boards';
        });
        $this->assertNotEmpty($moduleRoute, 'Module routes should be merged');
    }

    #[Test]
    public function returns_error_for_inactive_template(): void
    {
        // Given: 비활성화된 템플릿
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // When: routes 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: 에러 반환
        $this->assertFalse($result['success']);
        $this->assertEquals('template_not_found', $result['error']);
    }

    #[Test]
    public function plugin_settings_routes_auto_generated_for_plugins_with_settings(): void
    {
        // Given: 활성화된 템플릿
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        $this->moduleManager->method('getActiveModules')->willReturn([]);

        // Plugin Mock: 설정이 있는 플러그인 2개
        $pluginA = $this->createMock(\App\Contracts\Extension\PluginInterface::class);
        $pluginA->method('getIdentifier')->willReturn('sirsoft-tosspayments');
        $pluginA->method('hasSettings')->willReturn(true);

        $pluginB = $this->createMock(\App\Contracts\Extension\PluginInterface::class);
        $pluginB->method('getIdentifier')->willReturn('sirsoft-daum_postcode');
        $pluginB->method('hasSettings')->willReturn(true);

        $this->pluginManager->method('getActivePlugins')->willReturn([
            'sirsoft-tosspayments' => $pluginA,
            'sirsoft-daum_postcode' => $pluginB,
        ]);

        // When: routes 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: 각 플러그인별 고유 설정 라우트가 자동 생성됨
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        // 토스페이먼츠 설정 라우트 확인
        $tossSettingsRoutes = array_filter($routes, function ($route) {
            return $route['path'] === '*/admin/plugins/sirsoft-tosspayments/settings';
        });
        $this->assertNotEmpty($tossSettingsRoutes, '토스페이먼츠 설정 라우트가 자동 생성되어야 함');

        $tossRoute = array_values($tossSettingsRoutes)[0];
        $this->assertEquals('sirsoft-tosspayments.plugin_settings', $tossRoute['layout']);
        $this->assertEquals('$t:sirsoft-tosspayments.settings.title', $tossRoute['meta']['title']);
        $this->assertEquals('sirsoft-tosspayments', $tossRoute['params']['identifier'],
            'params.identifier가 플러그인 식별자와 일치해야 함');

        // 다음 우편번호 설정 라우트 확인
        $daumSettingsRoutes = array_filter($routes, function ($route) {
            return $route['path'] === '*/admin/plugins/sirsoft-daum_postcode/settings';
        });
        $this->assertNotEmpty($daumSettingsRoutes, '다음 우편번호 설정 라우트가 자동 생성되어야 함');

        $daumRoute = array_values($daumSettingsRoutes)[0];
        $this->assertEquals('sirsoft-daum_postcode.plugin_settings', $daumRoute['layout']);
        $this->assertEquals('sirsoft-daum_postcode', $daumRoute['params']['identifier'],
            'params.identifier가 플러그인 식별자와 일치해야 함');
    }

    #[Test]
    public function plugin_without_settings_does_not_get_settings_route(): void
    {
        // Given: 활성화된 템플릿
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        $this->moduleManager->method('getActiveModules')->willReturn([]);

        // Plugin Mock: 설정이 없는 플러그인
        $plugin = $this->createMock(\App\Contracts\Extension\PluginInterface::class);
        $plugin->method('getIdentifier')->willReturn('sirsoft-no-settings');
        $plugin->method('hasSettings')->willReturn(false);

        $this->pluginManager->method('getActivePlugins')->willReturn([
            'sirsoft-no-settings' => $plugin,
        ]);

        // When: routes 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: 설정 없는 플러그인은 설정 라우트가 생성되지 않음
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $settingsRoutes = array_filter($routes, function ($route) {
            return str_contains($route['path'], 'sirsoft-no-settings/settings');
        });
        $this->assertEmpty($settingsRoutes, '설정 없는 플러그인에 설정 라우트가 없어야 함');
    }

    #[Test]
    public function each_plugin_gets_its_own_layout_not_shared(): void
    {
        // Given: 활성화된 템플릿
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        $this->moduleManager->method('getActiveModules')->willReturn([]);

        // Plugin Mock: 설정이 있는 플러그인 2개
        $pluginA = $this->createMock(\App\Contracts\Extension\PluginInterface::class);
        $pluginA->method('getIdentifier')->willReturn('sirsoft-tosspayments');
        $pluginA->method('hasSettings')->willReturn(true);

        $pluginB = $this->createMock(\App\Contracts\Extension\PluginInterface::class);
        $pluginB->method('getIdentifier')->willReturn('sirsoft-daum_postcode');
        $pluginB->method('hasSettings')->willReturn(true);

        $this->pluginManager->method('getActivePlugins')->willReturn([
            'sirsoft-tosspayments' => $pluginA,
            'sirsoft-daum_postcode' => $pluginB,
        ]);

        // When: routes 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: 각 플러그인의 layout이 서로 다름 (다른 플러그인의 설정을 로드하지 않음)
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $settingsRoutes = array_filter($routes, function ($route) {
            return str_contains($route['path'], '/settings');
        });

        $layouts = array_map(fn ($route) => $route['layout'], array_values($settingsRoutes));

        // 토스페이먼츠 경로에 토스페이먼츠 레이아웃이 매핑되어야 함
        foreach ($settingsRoutes as $route) {
            if (str_contains($route['path'], 'sirsoft-tosspayments')) {
                $this->assertEquals('sirsoft-tosspayments.plugin_settings', $route['layout'],
                    '토스페이먼츠 설정 페이지는 토스페이먼츠 레이아웃을 로드해야 함');
            }
            if (str_contains($route['path'], 'sirsoft-daum_postcode')) {
                $this->assertEquals('sirsoft-daum_postcode.plugin_settings', $route['layout'],
                    '다음 우편번호 설정 페이지는 다음 우편번호 레이아웃을 로드해야 함');
            }
        }
    }
}
