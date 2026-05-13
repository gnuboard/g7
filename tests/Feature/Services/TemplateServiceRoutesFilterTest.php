<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Repositories\TemplateRepository;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 모듈/플러그인 라우트의 admin/user 타입별 필터링 테스트.
 *
 * - 모듈 라우트: routes/admin.json, routes/user.json 디렉토리 구조
 * - 플러그인 라우트: admin 템플릿에만 포함
 * - 레거시 routes.json: admin 타입에만 폴백
 */
class TemplateServiceRoutesFilterTest extends TestCase
{
    use RefreshDatabase;

    private TemplateService $templateService;

    private TemplateManagerInterface $templateManager;

    private ModuleManagerInterface $moduleManager;

    private PluginManagerInterface $pluginManager;

    /** @var string 테스트용 임시 모듈 디렉토리 경로 */
    private string $testModulePath;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 임시 모듈 디렉토리 생성
        $this->testModulePath = base_path('modules/test-routes-module');
        File::ensureDirectoryExists($this->testModulePath.'/resources/routes');

        // Mock 의존성
        $this->templateManager = $this->createMock(TemplateManagerInterface::class);
        $this->moduleManager = $this->createMock(ModuleManagerInterface::class);
        $this->pluginManager = $this->createMock(PluginManagerInterface::class);

        $templateRepository = new TemplateRepository;

        $this->templateManager->method('loadTemplates');

        $this->templateService = new TemplateService(
            $templateRepository,
            $this->templateManager,
            $this->moduleManager,
            $this->pluginManager
        );
    }

    protected function tearDown(): void
    {
        // 테스트용 임시 모듈 디렉토리 정리
        if (File::isDirectory($this->testModulePath)) {
            File::deleteDirectory($this->testModulePath);
        }

        // 레거시 테스트용 디렉토리 정리
        $legacyModulePath = base_path('modules/test-legacy-module');
        if (File::isDirectory($legacyModulePath)) {
            File::deleteDirectory($legacyModulePath);
        }

        // 이커머스 통합 테스트용 디렉토리 정리 (실제 활성 디렉토리가 아닌 테스트 전용)
        $ecommerceTestPath = base_path('modules/test-ecommerce-routes');
        if (File::isDirectory($ecommerceTestPath)) {
            File::deleteDirectory($ecommerceTestPath);
        }

        // User 템플릿 테스트용 디렉토리 정리
        $userTemplatePath = base_path('templates/test-user_template');
        if (File::isDirectory($userTemplatePath)) {
            File::deleteDirectory($userTemplatePath);
        }

        parent::tearDown();
    }

    /**
     * 테스트용 모듈 Mock을 생성합니다.
     */
    private function createMockModule(string $identifier): ModuleInterface
    {
        $mockModule = $this->createMock(ModuleInterface::class);
        $mockModule->method('getIdentifier')->willReturn($identifier);

        return $mockModule;
    }

    /**
     * 테스트용 라우트 JSON 파일을 생성합니다.
     */
    private function createRoutesFile(string $path, array $routes): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'version' => '1.0.0',
            'routes' => $routes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Admin 템플릿 DB 레코드 및 Mock을 설정합니다.
     */
    private function setupAdminTemplate(): void
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
    }

    /**
     * User 템플릿 DB 레코드 및 Mock을 설정합니다.
     */
    private function setupUserTemplate(): void
    {
        Template::factory()->create([
            'identifier' => 'test-user_template',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager->method('getTemplateInfo')->willReturn([
            'identifier' => 'test-user_template',
            'type' => 'user',
        ]);

        // User 템플릿용 최소 routes.json 생성
        $userTemplatePath = base_path('templates/test-user_template');
        File::ensureDirectoryExists($userTemplatePath);
        File::put($userTemplatePath.'/routes.json', json_encode([
            'version' => '1.0.0',
            'routes' => [
                ['path' => '*/home', 'layout' => 'user_home'],
            ],
        ]));
    }

    // ========================================================================
    // admin 타입 → admin 라우트만 로드
    // ========================================================================

    #[Test]
    public function admin_template_loads_admin_routes_only(): void
    {
        // Given: admin 라우트와 user 라우트 파일 생성
        $this->createRoutesFile($this->testModulePath.'/resources/routes/admin.json', [
            ['path' => '*/admin/products', 'layout' => 'admin_product_list', 'auth_required' => true],
        ]);
        $this->createRoutesFile($this->testModulePath.'/resources/routes/user.json', [
            ['path' => '*/shop/products', 'layout' => 'user_product_list'],
        ]);

        $this->setupAdminTemplate();

        $mockModule = $this->createMockModule('test-routes-module');
        $this->moduleManager->method('getActiveModules')->willReturn(['test-routes-module' => $mockModule]);
        $this->pluginManager->method('getActivePlugins')->willReturn([]);

        // When: admin 템플릿으로 라우트 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: admin 라우트만 포함, user 라우트 미포함
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $adminRoutes = array_filter($routes, fn ($r) => $r['path'] === '*/admin/products');
        $userRoutes = array_filter($routes, fn ($r) => $r['path'] === '*/shop/products');

        $this->assertNotEmpty($adminRoutes, 'Admin 라우트가 포함되어야 함');
        $this->assertEmpty($userRoutes, 'User 라우트는 admin 템플릿에 포함되지 않아야 함');
    }

    // ========================================================================
    // user 타입 → user 라우트만 로드
    // ========================================================================

    #[Test]
    public function user_template_loads_user_routes_only(): void
    {
        // Given: admin 라우트와 user 라우트 파일 생성
        $this->createRoutesFile($this->testModulePath.'/resources/routes/admin.json', [
            ['path' => '*/admin/products', 'layout' => 'admin_product_list', 'auth_required' => true],
        ]);
        $this->createRoutesFile($this->testModulePath.'/resources/routes/user.json', [
            ['path' => '*/shop/products', 'layout' => 'user_product_list'],
        ]);

        $this->setupUserTemplate();

        $mockModule = $this->createMockModule('test-routes-module');
        $this->moduleManager->method('getActiveModules')->willReturn(['test-routes-module' => $mockModule]);
        $this->pluginManager->method('getActivePlugins')->willReturn([]);

        // When: user 템플릿으로 라우트 조회
        $result = $this->templateService->getRoutesDataWithModules('test-user_template');

        // Then: user 라우트만 포함, admin 라우트 미포함
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $userRoutes = array_filter($routes, fn ($r) => $r['path'] === '*/shop/products');
        $adminRoutes = array_filter($routes, fn ($r) => $r['path'] === '*/admin/products');

        $this->assertNotEmpty($userRoutes, 'User 라우트가 포함되어야 함');
        $this->assertEmpty($adminRoutes, 'Admin 라우트는 user 템플릿에 포함되지 않아야 함');
    }

    // ========================================================================
    // 레거시 routes.json → admin 폴백
    // ========================================================================

    #[Test]
    public function legacy_routes_json_falls_back_for_admin_template(): void
    {
        // Given: 레거시 구조 모듈 (routes/ 디렉토리 없이 routes.json만 존재)
        $legacyModulePath = base_path('modules/test-legacy-module');
        File::ensureDirectoryExists($legacyModulePath.'/resources');
        $this->createRoutesFile($legacyModulePath.'/resources/routes.json', [
            ['path' => '*/admin/legacy-page', 'layout' => 'admin_legacy', 'auth_required' => true],
        ]);

        $this->setupAdminTemplate();

        $mockModule = $this->createMockModule('test-legacy-module');
        $this->moduleManager->method('getActiveModules')->willReturn(['test-legacy-module' => $mockModule]);
        $this->pluginManager->method('getActivePlugins')->willReturn([]);

        // 경고 로그 확인
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '레거시');
            });

        // When: admin 템플릿으로 라우트 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: 레거시 라우트가 admin 템플릿에 포함됨
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $legacyRoutes = array_filter($routes, fn ($r) => $r['path'] === '*/admin/legacy-page');
        $this->assertNotEmpty($legacyRoutes, '레거시 routes.json이 admin 템플릿에 폴백으로 포함되어야 함');
    }

    // ========================================================================
    // 레거시 routes.json + user 요청 → 빈 결과
    // ========================================================================

    #[Test]
    public function legacy_routes_json_not_loaded_for_user_template(): void
    {
        // Given: 레거시 구조 모듈
        $legacyModulePath = base_path('modules/test-legacy-module');
        File::ensureDirectoryExists($legacyModulePath.'/resources');
        $this->createRoutesFile($legacyModulePath.'/resources/routes.json', [
            ['path' => '*/admin/legacy-page', 'layout' => 'admin_legacy', 'auth_required' => true],
        ]);

        $this->setupUserTemplate();

        $mockModule = $this->createMockModule('test-legacy-module');
        $this->moduleManager->method('getActiveModules')->willReturn(['test-legacy-module' => $mockModule]);
        $this->pluginManager->method('getActivePlugins')->willReturn([]);

        // When: user 템플릿으로 라우트 조회
        $result = $this->templateService->getRoutesDataWithModules('test-user_template');

        // Then: 레거시 라우트가 user 템플릿에 포함되지 않음
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $legacyRoutes = array_filter($routes, fn ($r) => $r['path'] === '*/admin/legacy-page');
        $this->assertEmpty($legacyRoutes, '레거시 routes.json은 user 템플릿에 로드되지 않아야 함');
    }

    // ========================================================================
    // user 타입 → 플러그인 라우트 미포함
    // ========================================================================

    #[Test]
    public function user_template_excludes_plugin_routes(): void
    {
        // Given: user 라우트가 있는 모듈 + 설정이 있는 플러그인
        $this->createRoutesFile($this->testModulePath.'/resources/routes/user.json', [
            ['path' => '*/shop/products', 'layout' => 'user_product_list'],
        ]);

        $this->setupUserTemplate();

        $mockModule = $this->createMockModule('test-routes-module');
        $this->moduleManager->method('getActiveModules')->willReturn(['test-routes-module' => $mockModule]);

        $mockPlugin = $this->createMock(PluginInterface::class);
        $mockPlugin->method('getIdentifier')->willReturn('sirsoft-tosspayments');
        $mockPlugin->method('hasSettings')->willReturn(true);
        $this->pluginManager->method('getActivePlugins')->willReturn(['sirsoft-tosspayments' => $mockPlugin]);

        // When: user 템플릿으로 라우트 조회
        $result = $this->templateService->getRoutesDataWithModules('test-user_template');

        // Then: 플러그인 설정 라우트가 포함되지 않음
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $pluginRoutes = array_filter($routes, fn ($r) => str_contains($r['path'], '/plugins/'));
        $this->assertEmpty($pluginRoutes, '플러그인 라우트는 user 템플릿에 포함되지 않아야 함');
    }

    // ========================================================================
    // admin 타입 → 플러그인 라우트 포함
    // ========================================================================

    #[Test]
    public function admin_template_includes_plugin_routes(): void
    {
        // Given: admin 라우트가 있는 모듈 + 설정이 있는 플러그인
        $this->createRoutesFile($this->testModulePath.'/resources/routes/admin.json', [
            ['path' => '*/admin/products', 'layout' => 'admin_product_list', 'auth_required' => true],
        ]);

        $this->setupAdminTemplate();

        $mockModule = $this->createMockModule('test-routes-module');
        $this->moduleManager->method('getActiveModules')->willReturn(['test-routes-module' => $mockModule]);

        $mockPlugin = $this->createMock(PluginInterface::class);
        $mockPlugin->method('getIdentifier')->willReturn('sirsoft-tosspayments');
        $mockPlugin->method('hasSettings')->willReturn(true);
        $this->pluginManager->method('getActivePlugins')->willReturn(['sirsoft-tosspayments' => $mockPlugin]);

        // When: admin 템플릿으로 라우트 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: 플러그인 설정 라우트가 포함됨
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $pluginRoutes = array_filter($routes, fn ($r) => str_contains($r['path'], '/plugins/sirsoft-tosspayments/settings'));
        $this->assertNotEmpty($pluginRoutes, '플러그인 라우트는 admin 템플릿에 포함되어야 함');
    }

    // ========================================================================
    // layout 접두사 추가 동작 유지
    // ========================================================================

    #[Test]
    public function module_layout_prefix_is_added_correctly(): void
    {
        // Given: admin 라우트 파일 생성
        $this->createRoutesFile($this->testModulePath.'/resources/routes/admin.json', [
            ['path' => '*/admin/products', 'layout' => 'admin_product_list', 'auth_required' => true],
        ]);

        $this->setupAdminTemplate();

        $mockModule = $this->createMockModule('test-routes-module');
        $this->moduleManager->method('getActiveModules')->willReturn(['test-routes-module' => $mockModule]);
        $this->pluginManager->method('getActivePlugins')->willReturn([]);

        // When: admin 템플릿으로 라우트 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: layout 필드에 모듈 식별자가 접두사로 추가됨
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $moduleRoutes = array_filter($routes, fn ($r) => $r['path'] === '*/admin/products');
        $moduleRoute = array_values($moduleRoutes)[0];

        $this->assertEquals(
            'test-routes-module.admin_product_list',
            $moduleRoute['layout'],
            'Layout 필드에 모듈 식별자 접두사가 추가되어야 함'
        );
    }

    // ========================================================================
    // 이커머스 모듈 실제 user routes 통합 테스트
    // ========================================================================

    /**
     * 이커머스 통합 테스트용 모듈 디렉토리를 생성합니다.
     *
     * _bundled의 라우트 파일만 테스트 전용 디렉토리에 복사하여
     * 실제 활성 디렉토리(modules/sirsoft-ecommerce)를 조작하지 않습니다.
     *
     * @return string 테스트용 모듈 식별자
     */
    private function setupEcommerceTestModule(): string
    {
        $testIdentifier = 'test-ecommerce-routes';
        $testPath = base_path("modules/{$testIdentifier}/resources/routes");
        File::ensureDirectoryExists($testPath);

        // _bundled에서 라우트 파일만 복사 (user.json 은 현재 ecommerce 에 부재 — 테스트 전용으로 생성)
        $bundledRoutesPath = base_path('modules/_bundled/sirsoft-ecommerce/resources/routes');
        File::copy($bundledRoutesPath.'/admin.json', $testPath.'/admin.json');

        // user.json 은 fixture 로 직접 생성 (ecommerce 번들에 더 이상 존재하지 않음)
        $userRoutes = [
            'routes' => [
                [
                    'path' => '*/shop/terms',
                    'layout' => 'user_ecommerce_terms',
                    'meta' => ['title' => '쇼핑몰 이용약관'],
                ],
            ],
        ];
        File::put($testPath.'/user.json', json_encode($userRoutes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $testIdentifier;
    }

    #[Test]
    public function ecommerce_module_user_routes_loaded_for_user_template(): void
    {
        // Given: _bundled 이커머스 라우트 파일을 테스트 전용 모듈 디렉토리에 복사
        $testIdentifier = $this->setupEcommerceTestModule();

        $this->setupUserTemplate();

        $mockModule = $this->createMockModule($testIdentifier);
        $this->moduleManager->method('getActiveModules')->willReturn([$testIdentifier => $mockModule]);
        $this->pluginManager->method('getActivePlugins')->willReturn([]);

        // When: user 템플릿으로 라우트 조회
        $result = $this->templateService->getRoutesDataWithModules('test-user_template');

        // Then: user 라우트(쇼핑몰 이용약관)가 포함됨
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $termsRoutes = array_filter($routes, fn ($r) => $r['path'] === '*/shop/terms');
        $this->assertNotEmpty($termsRoutes, '이커머스 모듈의 user 라우트(쇼핑몰 이용약관)가 포함되어야 함');

        // layout 접두사 확인
        $termsRoute = array_values($termsRoutes)[0];
        $this->assertEquals(
            $testIdentifier.'.user_ecommerce_terms',
            $termsRoute['layout'],
            'User 라우트 layout에 모듈 식별자 접두사가 추가되어야 함'
        );

        // admin 라우트는 포함되지 않아야 함
        $adminRoutes = array_filter($routes, fn ($r) => str_contains($r['path'], '/admin/'));
        $this->assertEmpty($adminRoutes, 'Admin 라우트는 user 템플릿에 포함되지 않아야 함');
    }

    #[Test]
    public function ecommerce_module_admin_routes_not_include_user_routes(): void
    {
        // Given: _bundled 이커머스 라우트 파일을 테스트 전용 모듈 디렉토리에 복사
        $testIdentifier = $this->setupEcommerceTestModule();

        $this->setupAdminTemplate();

        $mockModule = $this->createMockModule($testIdentifier);
        $this->moduleManager->method('getActiveModules')->willReturn([$testIdentifier => $mockModule]);
        $this->pluginManager->method('getActivePlugins')->willReturn([]);

        // When: admin 템플릿으로 라우트 조회
        $result = $this->templateService->getRoutesDataWithModules('sirsoft-admin_basic');

        // Then: admin 라우트가 포함됨
        $this->assertTrue($result['success']);
        $routes = $result['data']['routes'];

        $adminRoutes = array_filter($routes, fn ($r) => str_contains($r['path'], '/admin/ecommerce/'));
        $this->assertNotEmpty($adminRoutes, '이커머스 모듈의 admin 라우트가 포함되어야 함');

        // user 라우트(이용약관)는 포함되지 않아야 함
        $termsRoutes = array_filter($routes, fn ($r) => $r['path'] === '*/shop/terms');
        $this->assertEmpty($termsRoutes, 'User 라우트(쇼핑몰 이용약관)는 admin 템플릿에 포함되지 않아야 함');
    }
}
