<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
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
            $this->pluginManager,
            app(LayoutVersionRepositoryInterface::class)
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

    /**
     * @effects code_editor_restores_layout_from_route_query
     */
    #[Test]
    public function get_layout_route_path_map_maps_layout_name_to_route_path(): void
    {
        // Given: 활성화된 user 템플릿 (실제 routes.json 보유)
        Template::factory()->create([
            'identifier' => 'sirsoft-basic',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-basic',
            'type' => 'user',
        ]);
        $this->moduleManager->method('getActiveModules')->willReturn([]);

        // When: 레이아웃 → path 매핑 조회
        $map = $this->templateService->getLayoutRoutePathMap('sirsoft-basic');

        // Then: 정적 라우트는 path 로 매핑, base/partial(라우트 없음)은 제외
        $this->assertSame('/', $map['home'] ?? null);
        $this->assertSame('/login', $map['auth/login'] ?? null);
        $this->assertArrayNotHasKey('_user_base', $map, 'base 레이아웃은 라우트가 없어 매핑에서 제외');
    }

    #[Test]
    public function get_layout_route_path_map_returns_empty_for_template_without_routes(): void
    {
        // Given: routes.json 없는 템플릿
        Template::factory()->create([
            'identifier' => 'no-routes-template',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'no-routes-template',
            'type' => 'admin',
        ]);

        // When: 매핑 조회
        $map = $this->templateService->getLayoutRoutePathMap('no-routes-template');

        // Then: 빈 배열 (route_path 는 모두 null 로 직렬화됨)
        $this->assertSame([], $map);
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
        $mockModule = $this->createMock(ModuleInterface::class);
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

    /**
     * 활성 모듈의 디렉토리가 없으면 열화 상태로 표시되는지 확인합니다.
     *
     * 확장 업데이트 중에는 활성 디렉토리가 잠시 비어 그 모듈의 라우트가 통째로 빠진다.
     * 이 순간의 응답이 캐시 버전 키에 남으면, 업데이트가 끝난 뒤에도 캐시가 만료될 때까지
     * 해당 모듈의 모든 화면이 404 가 된다 — 버전은 이미 올라간 뒤라 스스로 회복되지 않는다.
     * 캐시 여부를 판단할 수 있도록 병합 결과가 열화였음을 알려야 한다.
     */
    #[Test]
    public function route_merge_is_flagged_degraded_when_active_module_directory_is_missing(): void
    {
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        // 활성 목록에는 있으나 디렉토리가 없는 모듈 (= 업데이트 중 스왑 창)
        $mockModule = $this->createMock(ModuleInterface::class);
        $mockModule->method('getIdentifier')->willReturn('vendor-absent_module');
        $this->moduleManager->method('getActiveModules')->willReturn([
            'vendor-absent_module' => $mockModule,
        ]);

        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        $this->assertTrue($result['success']);
        $this->assertTrue(
            $result['degraded'],
            '활성 모듈 디렉토리가 없는데 열화로 표시되지 않았습니다 — 이 응답이 캐시되면 업데이트 후에도 404 가 남습니다.'
        );
        $this->assertTrue($this->templateService->lastRouteMergeWasDegraded());
    }

    /**
     * 정상 상태에서는 열화로 표시되지 않는지 확인합니다 (과잉 차단 방지).
     *
     * 열화 판정이 헐거우면 모든 응답이 캐시되지 않아 매 요청이 디스크 병합으로 떨어진다.
     */
    #[Test]
    public function route_merge_is_not_flagged_degraded_in_normal_state(): void
    {
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        $mockModule = $this->createMock(ModuleInterface::class);
        $mockModule->method('getIdentifier')->willReturn('sirsoft-board');
        $this->moduleManager->method('getActiveModules')->willReturn([
            'sirsoft-board' => $mockModule,
        ]);

        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['degraded']);
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
        $mockModule = $this->createMock(ModuleInterface::class);
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
        $pluginA = $this->createMock(PluginInterface::class);
        $pluginA->method('getIdentifier')->willReturn('sirsoft-tosspayments');
        $pluginA->method('hasSettings')->willReturn(true);

        $pluginB = $this->createMock(PluginInterface::class);
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
        $plugin = $this->createMock(PluginInterface::class);
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
        $pluginA = $this->createMock(PluginInterface::class);
        $pluginA->method('getIdentifier')->willReturn('sirsoft-tosspayments');
        $pluginA->method('hasSettings')->willReturn(true);

        $pluginB = $this->createMock(PluginInterface::class);
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

        // layout 을 가진 settings 페이지만 대상 — `/settings/language-packs` 같은
        // redirect 라우트(layout 키 없음)는 플러그인 레이아웃 격리 검증 대상이 아니다.
        $settingsRoutes = array_filter($routes, function ($route) {
            return str_contains($route['path'], '/settings') && isset($route['layout']);
        });

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

    /**
     * 편집기 라우트 병합이 직전 호출의 열화 판정을 승계하지 않는지 확인합니다.
     *
     * 열화 플래그는 인스턴스 프로퍼티인데, 진입 시 리셋하는 것은
     * `getRoutesDataWithModules()` 뿐이었다. 서비스가 공유 인스턴스가 된 뒤로는
     * 편집기 경로가 한 번 열화를 만나면 그 판정이 인스턴스에 남아, 모듈 디렉토리가
     * 복구된 뒤의 병합까지 열화로 보고된다. 열화 응답은 캐시되지 않으므로
     * 편집기 라우트 트리가 매 요청 디스크 병합으로 떨어진다.
     */
    #[Test]
    public function editor_route_merge_does_not_inherit_previous_degraded_state(): void
    {
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        $absentModule = $this->createMock(ModuleInterface::class);
        $absentModule->method('getIdentifier')->willReturn('vendor-absent_module');
        $healthyModule = $this->createMock(ModuleInterface::class);
        $healthyModule->method('getIdentifier')->willReturn('sirsoft-board');

        // 한 번의 병합에서도 활성 모듈 목록은 여러 번 조회된다(라우트 로더 + base/모달 수집).
        // 호출 횟수가 아니라 "지금 어떤 상태인가" 로 갈라야 테스트가 구현 세부에 묶이지 않는다.
        $activeModules = ['vendor-absent_module' => $absentModule];
        $this->moduleManager->method('getActiveModules')
            ->willReturnCallback(function () use (&$activeModules) {
                return $activeModules;
            });

        // 1회차 = 업데이트 스왑 창(디렉토리 부재)
        $this->templateService->getEditorRoutesDataWithModules('sirsoft-admin_basic');
        $this->assertTrue(
            $this->templateService->lastRouteMergeWasDegraded(),
            '편집기 경로가 활성 모듈 디렉토리 부재를 열화로 표시하지 않았습니다.'
        );

        // 2회차 = 디렉토리 복구 후
        $activeModules = ['sirsoft-board' => $healthyModule];
        $this->templateService->getEditorRoutesDataWithModules('sirsoft-admin_basic');

        $this->assertFalse(
            $this->templateService->lastRouteMergeWasDegraded(),
            '편집기 라우트 병합이 직전 호출의 열화 판정을 승계했습니다 — 공유 인스턴스에 열화가 눌어붙습니다.'
        );
    }

    /**
     * 진입 리셋을 넣은 뒤에도 편집기 경로의 열화 검출이 살아 있는지 확인합니다 (과잉 리셋 방지).
     */
    #[Test]
    public function editor_route_merge_still_flags_missing_module_directory(): void
    {
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
        ]);

        $absentModule = $this->createMock(ModuleInterface::class);
        $absentModule->method('getIdentifier')->willReturn('vendor-absent_module');
        $this->moduleManager->method('getActiveModules')->willReturn([
            'vendor-absent_module' => $absentModule,
        ]);

        $result = $this->templateService->getEditorRoutesDataWithModules('sirsoft-admin_basic');

        $this->assertTrue($result['success']);
        $this->assertTrue(
            $this->templateService->lastRouteMergeWasDegraded(),
            '진입 리셋이 편집기 경로의 열화 검출까지 지웠습니다.'
        );
    }
}
