<?php

namespace Tests\Feature\Extension;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

/**
 * manifest hidden 플래그 검증 테스트
 *
 * module.json / plugin.json / template.json 의 "hidden": true 플래그가
 * 관리자 UI 목록 (Manager 메서드 / artisan 커맨드) 에서 올바르게 동작하는지 검증합니다.
 *
 * 검증 대상:
 * - ModuleManager / PluginManager / TemplateManager 의 getUninstalled* / getInstalled* 출력에 hidden 필드 포함
 * - ModuleService / PluginService / TemplateService 의 include_hidden 필터 동작
 * - artisan module:list / plugin:list / template:list 의 --hidden 플래그 동작
 *
 * 테스트 fixture 는 _bundled 디렉토리에 생성하고 tearDown 에서 정리합니다.
 * ProtectsExtensionDirectories 트레이트로 활성 디렉토리 보호.
 */
class HiddenFlagTest extends TestCase
{
    use ProtectsExtensionDirectories;
    use RefreshDatabase;

    private string $modulesPath;
    private string $pluginsPath;
    private string $templatesPath;

    /**
     * 테스트용 _bundled fixture 를 생성합니다.
     *
     * - test-hidden-mod (hidden=true) / test-visible-mod (hidden=false)
     * - test-hidden-plg (hidden=true) / test-visible-plg (hidden=false)
     * - test-hidden-tpl (hidden=true) / test-visible-tpl (hidden=false)
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesPath = base_path('modules');
        $this->pluginsPath = base_path('plugins');
        $this->templatesPath = base_path('templates');

        // 모듈 fixture
        $this->createBundledModule('test-hidden-mod', true);
        $this->createBundledModule('test-visible-mod', false);

        // 플러그인 fixture
        $this->createBundledPlugin('test-hidden-plg', true);
        $this->createBundledPlugin('test-visible-plg', false);

        // 템플릿 fixture
        $this->createBundledTemplate('test-hidden-tpl', true);
        $this->createBundledTemplate('test-visible-tpl', false);

        Cache::flush();
        ModuleManager::invalidateModuleStatusCache();

        // 활성 디렉토리 보호 시작 (DB 시딩 이후)
        $this->setUpExtensionProtection();
    }

    /**
     * 테스트 fixture 를 정리합니다.
     */
    protected function tearDown(): void
    {
        $this->tearDownExtensionProtection();

        $paths = [
            $this->modulesPath.'/_bundled/test-hidden-mod',
            $this->modulesPath.'/_bundled/test-visible-mod',
            $this->pluginsPath.'/_bundled/test-hidden-plg',
            $this->pluginsPath.'/_bundled/test-visible-plg',
            $this->templatesPath.'/_bundled/test-hidden-tpl',
            $this->templatesPath.'/_bundled/test-visible-tpl',
        ];

        foreach ($paths as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        Cache::flush();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * _bundled 테스트 모듈을 생성합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  bool  $hidden  hidden 플래그 값
     */
    private function createBundledModule(string $identifier, bool $hidden): void
    {
        $path = $this->modulesPath.'/_bundled/'.$identifier;
        File::ensureDirectoryExists($path);
        File::put($path.'/module.json', json_encode([
            'identifier' => $identifier,
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 '.$identifier, 'en' => 'Test '.$identifier],
            'description' => ['ko' => 'hidden 플래그 테스트', 'en' => 'Hidden flag test'],
            'dependencies' => [],
            'hidden' => $hidden,
        ]));
    }

    /**
     * _bundled 테스트 플러그인을 생성합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  bool  $hidden  hidden 플래그 값
     */
    private function createBundledPlugin(string $identifier, bool $hidden): void
    {
        $path = $this->pluginsPath.'/_bundled/'.$identifier;
        File::ensureDirectoryExists($path);
        File::put($path.'/plugin.json', json_encode([
            'identifier' => $identifier,
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 '.$identifier, 'en' => 'Test '.$identifier],
            'description' => ['ko' => 'hidden 플래그 테스트', 'en' => 'Hidden flag test'],
            'dependencies' => [],
            'hidden' => $hidden,
        ]));
    }

    /**
     * _bundled 테스트 템플릿을 생성합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  bool  $hidden  hidden 플래그 값
     */
    private function createBundledTemplate(string $identifier, bool $hidden): void
    {
        $path = $this->templatesPath.'/_bundled/'.$identifier;
        File::ensureDirectoryExists($path);
        File::put($path.'/template.json', json_encode([
            'identifier' => $identifier,
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 '.$identifier, 'en' => 'Test '.$identifier],
            'description' => ['ko' => 'hidden 플래그 테스트', 'en' => 'Hidden flag test'],
            'type' => 'admin',
            'dependencies' => [],
            'hidden' => $hidden,
        ]));
    }

    /**
     * ModuleManager 를 생성합니다.
     */
    private function makeModuleManager(): ModuleManager
    {
        $moduleRepository = Mockery::mock(ModuleRepositoryInterface::class);
        $moduleRepository->shouldReceive('findByIdentifier')->andReturn(null)->byDefault();
        $moduleRepository->shouldReceive('getAllKeyedByIdentifier')
            ->andReturn(collect())
            ->byDefault();

        return new ModuleManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            moduleRepository: $moduleRepository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            menuRepository: Mockery::mock(MenuRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
        );
    }

    /**
     * PluginManager 를 생성합니다.
     */
    private function makePluginManager(): PluginManager
    {
        $pluginRepository = Mockery::mock(PluginRepositoryInterface::class);
        $pluginRepository->shouldReceive('findByIdentifier')->andReturn(null)->byDefault();
        $pluginRepository->shouldReceive('getAllKeyedByIdentifier')
            ->andReturn(collect())
            ->byDefault();

        return new PluginManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            pluginRepository: $pluginRepository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            moduleRepository: Mockery::mock(ModuleRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
        );
    }

    /**
     * TemplateManager 를 생성합니다.
     */
    private function makeTemplateManager(): TemplateManager
    {
        $templateRepository = Mockery::mock(TemplateRepositoryInterface::class);
        $templateRepository->shouldReceive('getAllKeyedByIdentifier')
            ->andReturn(collect())
            ->byDefault();

        return new TemplateManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            templateRepository: $templateRepository,
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            moduleRepository: Mockery::mock(ModuleRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
        );
    }

    // ========================================================================
    // 모듈
    // ========================================================================

    /**
     * _bundled 모듈의 hidden 플래그가 ModuleManager 출력에 반영되는지 검증
     */
    public function test_module_manager_exposes_hidden_field(): void
    {
        $manager = $this->makeModuleManager();
        $manager->loadModules();

        $uninstalled = $manager->getUninstalledModules();

        $this->assertArrayHasKey('test-hidden-mod', $uninstalled);
        $this->assertTrue($uninstalled['test-hidden-mod']['hidden']);

        $this->assertArrayHasKey('test-visible-mod', $uninstalled);
        $this->assertFalse($uninstalled['test-visible-mod']['hidden']);
    }

    /**
     * artisan module:list 가 기본적으로 hidden 모듈을 제외하는지 검증
     */
    public function test_module_list_command_hides_hidden_by_default(): void
    {
        $this->artisan('module:list')
            ->expectsOutputToContain('test-visible-mod')
            ->doesntExpectOutputToContain('test-hidden-mod')
            ->assertExitCode(0);
    }

    /**
     * artisan module:list --hidden 이 hidden 모듈을 포함해 출력하는지 검증
     */
    public function test_module_list_command_includes_hidden_with_flag(): void
    {
        $this->artisan('module:list', ['--hidden' => true])
            ->expectsOutputToContain('test-hidden-mod')
            ->expectsOutputToContain('test-visible-mod')
            ->assertExitCode(0);
    }

    // ========================================================================
    // 플러그인
    // ========================================================================

    /**
     * _bundled 플러그인의 hidden 플래그가 PluginManager 출력에 반영되는지 검증
     */
    public function test_plugin_manager_exposes_hidden_field(): void
    {
        $manager = $this->makePluginManager();
        $manager->loadPlugins();

        $uninstalled = $manager->getUninstalledPlugins();

        $this->assertArrayHasKey('test-hidden-plg', $uninstalled);
        $this->assertTrue($uninstalled['test-hidden-plg']['hidden']);

        $this->assertArrayHasKey('test-visible-plg', $uninstalled);
        $this->assertFalse($uninstalled['test-visible-plg']['hidden']);
    }

    /**
     * artisan plugin:list 가 기본적으로 hidden 플러그인을 제외하는지 검증
     */
    public function test_plugin_list_command_hides_hidden_by_default(): void
    {
        $this->artisan('plugin:list')
            ->expectsOutputToContain('test-visible-plg')
            ->doesntExpectOutputToContain('test-hidden-plg')
            ->assertExitCode(0);
    }

    /**
     * artisan plugin:list --hidden 이 hidden 플러그인을 포함해 출력하는지 검증
     */
    public function test_plugin_list_command_includes_hidden_with_flag(): void
    {
        $this->artisan('plugin:list', ['--hidden' => true])
            ->expectsOutputToContain('test-hidden-plg')
            ->expectsOutputToContain('test-visible-plg')
            ->assertExitCode(0);
    }

    // ========================================================================
    // 템플릿
    // ========================================================================

    /**
     * _bundled 템플릿의 hidden 플래그가 TemplateManager 출력에 반영되는지 검증
     */
    public function test_template_manager_exposes_hidden_field(): void
    {
        $manager = $this->makeTemplateManager();
        $manager->loadTemplates();

        $uninstalled = $manager->getUninstalledTemplates();

        $this->assertArrayHasKey('test-hidden-tpl', $uninstalled);
        $this->assertTrue($uninstalled['test-hidden-tpl']['hidden']);

        $this->assertArrayHasKey('test-visible-tpl', $uninstalled);
        $this->assertFalse($uninstalled['test-visible-tpl']['hidden']);
    }

    /**
     * artisan template:list 가 기본적으로 hidden 템플릿을 제외하는지 검증
     */
    public function test_template_list_command_hides_hidden_by_default(): void
    {
        $this->artisan('template:list')
            ->expectsOutputToContain('test-visible-tpl')
            ->doesntExpectOutputToContain('test-hidden-tpl')
            ->assertExitCode(0);
    }

    /**
     * artisan template:list --hidden 이 hidden 템플릿을 포함해 출력하는지 검증
     */
    public function test_template_list_command_includes_hidden_with_flag(): void
    {
        $this->artisan('template:list', ['--hidden' => true])
            ->expectsOutputToContain('test-hidden-tpl')
            ->expectsOutputToContain('test-visible-tpl')
            ->assertExitCode(0);
    }
}
