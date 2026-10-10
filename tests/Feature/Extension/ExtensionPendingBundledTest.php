<?php

namespace Tests\Feature\Extension;

use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * _pending / _bundled 디렉토리 통합 테스트
 *
 * 모듈/플러그인/템플릿의 _pending/_bundled 디렉토리 스캔, 설치, 삭제,
 * 오토로드 제외, 업데이트 감지 우선순위를 검증합니다.
 */
class ExtensionPendingBundledTest extends TestCase
{
    use RefreshDatabase;

    // ── 모듈 관련 ──
    private string $modulesPath;

    private ModuleRepositoryInterface|Mockery\MockInterface $moduleRepository;

    private ExtensionManager|Mockery\MockInterface $extensionManager;

    private ModuleManager $manager;

    // ── 플러그인 관련 ──
    private string $pluginsPath;

    private PluginRepositoryInterface|Mockery\MockInterface $pluginRepository;

    private PluginManager $pluginManager;

    // ── 템플릿 관련 ──
    private string $templatesPath;

    private TemplateRepositoryInterface|Mockery\MockInterface $templateRepository;

    private LayoutRepositoryInterface|Mockery\MockInterface $layoutRepository;

    private TemplateManager $templateManager;

    /**
     * 테스트용 _pending/_bundled 디렉토리와 Manager 인스턴스를 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // ── 모듈 초기화 ──
        $this->modulesPath = base_path('modules');

        $this->moduleRepository = Mockery::mock(ModuleRepositoryInterface::class);
        $this->extensionManager = Mockery::mock(ExtensionManager::class);

        $this->manager = new ModuleManager(
            extensionManager: $this->extensionManager,
            moduleRepository: $this->moduleRepository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            menuRepository: Mockery::mock(MenuRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );

        // _pending 테스트 모듈 생성
        $this->createTestModule('_pending', 'test-pending-mod', '2.0.0');
        // _bundled 테스트 모듈 생성
        $this->createTestModule('_bundled', 'test-bundled-mod', '1.5.0');

        // ── 플러그인 초기화 ──
        $this->pluginsPath = base_path('plugins');

        $this->pluginRepository = Mockery::mock(PluginRepositoryInterface::class);

        $this->pluginManager = new PluginManager(
            extensionManager: $this->extensionManager,
            pluginRepository: $this->pluginRepository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            moduleRepository: Mockery::mock(ModuleRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );

        // _pending 테스트 플러그인 생성
        $this->createTestPlugin('_pending', 'test-pending-plg', '2.0.0');
        // _bundled 테스트 플러그인 생성
        $this->createTestPlugin('_bundled', 'test-bundled-plg', '1.5.0');

        // ── 템플릿 초기화 ──
        $this->templatesPath = base_path('templates');

        $this->templateRepository = Mockery::mock(TemplateRepositoryInterface::class);
        $this->layoutRepository = Mockery::mock(LayoutRepositoryInterface::class);

        $this->templateManager = new TemplateManager(
            extensionManager: $this->extensionManager,
            templateRepository: $this->templateRepository,
            layoutRepository: $this->layoutRepository,
            moduleRepository: Mockery::mock(ModuleRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );

        // _pending 테스트 템플릿 생성
        $this->createTestTemplate('_pending', 'test-pending-tpl', '2.0.0', 'admin');
        // _bundled 테스트 템플릿 생성
        $this->createTestTemplate('_bundled', 'test-bundled-tpl', '1.5.0', 'user');

        // 캐시 초기화 (getInstalled*Identifiers 캐시 방지)
        Cache::flush();
        ModuleManager::invalidateModuleStatusCache();
    }

    /**
     * 테스트용 디렉토리를 모두 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $paths = [
            // 모듈
            $this->modulesPath.'/_pending/test-pending-mod',
            $this->modulesPath.'/_bundled/test-bundled-mod',
            $this->modulesPath.'/_pending/test-bundled-mod',
            $this->modulesPath.'/test-pending-mod',
            $this->modulesPath.'/test-bundled-mod',
            // 플러그인
            $this->pluginsPath.'/_pending/test-pending-plg',
            $this->pluginsPath.'/_bundled/test-bundled-plg',
            $this->pluginsPath.'/_pending/test-bundled-plg',
            $this->pluginsPath.'/test-pending-plg',
            $this->pluginsPath.'/test-bundled-plg',
            // 템플릿
            $this->templatesPath.'/_pending/test-pending-tpl',
            $this->templatesPath.'/_bundled/test-bundled-tpl',
            $this->templatesPath.'/_pending/test-bundled-tpl',
            $this->templatesPath.'/test-pending-tpl',
            $this->templatesPath.'/test-bundled-tpl',
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
     * 테스트용 모듈 디렉토리와 module.json을 생성합니다.
     *
     * @param  string  $subDir  하위 디렉토리 (_pending, _bundled)
     * @param  string  $identifier  모듈 식별자
     * @param  string  $version  모듈 버전
     * @return void 반환값 없음
     */
    private function createTestModule(string $subDir, string $identifier, string $version): void
    {
        $path = $this->modulesPath.'/'.$subDir.'/'.$identifier;
        File::ensureDirectoryExists($path);
        File::put($path.'/module.json', json_encode([
            'identifier' => $identifier,
            'version' => $version,
            'vendor' => 'test',
            'name' => ['ko' => '테스트 모듈 '.$identifier, 'en' => 'Test Module '.$identifier],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'dependencies' => [],
        ]));
    }

    /**
     * Module mock을 생성합니다.
     *
     * @param  array  $attributes  모델 속성
     * @return Module|Mockery\MockInterface 속성을 채운 모듈 부분 목
     */
    private function createModuleMock(array $attributes): Module|Mockery\MockInterface
    {
        $mock = Mockery::mock(Module::class)->makePartial();
        foreach ($attributes as $key => $value) {
            $mock->$key = $value;
        }

        return $mock;
    }

    /**
     * 테스트용 플러그인 디렉토리와 plugin.json을 생성합니다.
     *
     * @param  string  $subDir  하위 디렉토리 (_pending, _bundled)
     * @param  string  $identifier  플러그인 식별자
     * @param  string  $version  플러그인 버전
     * @return void 반환값 없음
     */
    private function createTestPlugin(string $subDir, string $identifier, string $version): void
    {
        $path = $this->pluginsPath.'/'.$subDir.'/'.$identifier;
        File::ensureDirectoryExists($path);
        File::put($path.'/plugin.json', json_encode([
            'identifier' => $identifier,
            'version' => $version,
            'vendor' => 'test',
            'name' => ['ko' => '테스트 플러그인 '.$identifier, 'en' => 'Test Plugin '.$identifier],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'dependencies' => [],
        ]));
    }

    /**
     * 테스트용 템플릿 디렉토리와 template.json을 생성합니다.
     *
     * @param  string  $subDir  하위 디렉토리 (_pending, _bundled)
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $version  템플릿 버전
     * @param  string  $type  템플릿 타입 (admin, user)
     * @return void 반환값 없음
     */
    private function createTestTemplate(string $subDir, string $identifier, string $version, string $type = 'admin'): void
    {
        $path = $this->templatesPath.'/'.$subDir.'/'.$identifier;
        File::ensureDirectoryExists($path);
        File::put($path.'/template.json', json_encode([
            'identifier' => $identifier,
            'version' => $version,
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿 '.$identifier, 'en' => 'Test Template '.$identifier],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'type' => $type,
            'dependencies' => [],
        ]));
    }

    /**
     * Template mock을 생성합니다.
     *
     * @param  array  $attributes  모델 속성
     * @return Template|Mockery\MockInterface 속성을 채운 템플릿 부분 목
     */
    private function createTemplateMock(array $attributes): Template|Mockery\MockInterface
    {
        $mock = Mockery::mock(Template::class)->makePartial();
        foreach ($attributes as $key => $value) {
            $mock->$key = $value;
        }

        return $mock;
    }

    // ========================================================================
    // 1. _pending 모듈이 미설치 목록에 표시되는지 검증
    // ========================================================================

    /**
     * _pending 디렉토리의 모듈이 미설치 목록에 is_pending: true로 표시되는지 테스트
     *
     * @scenario ext_type=module, source_dir=pending, stage=uninstalled_listing
     *
     * @effects pending_listed_as_uninstalled
     *
     * @return void 반환값 없음
     */
    public function test_pending_modules_appear_in_uninstalled_list(): void
    {
        // enrichDependencies 내부에서 호출되므로 기본 기대 설정
        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->andReturn(null);

        $this->manager->loadModules();

        $result = $this->manager->getUninstalledModules();

        $this->assertArrayHasKey('test-pending-mod', $result);
        $this->assertTrue($result['test-pending-mod']['is_pending']);
        $this->assertFalse($result['test-pending-mod']['is_bundled']);
        $this->assertEquals('uninstalled', $result['test-pending-mod']['status']);
        $this->assertEquals('2.0.0', $result['test-pending-mod']['version']);
    }

    // ========================================================================
    // 4. _bundled 모듈이 미설치 목록에 표시되는지 검증
    // ========================================================================

    /**
     * _bundled 디렉토리의 모듈이 미설치 목록에 is_bundled: true로 표시되는지 테스트
     *
     * @scenario ext_type=module, source_dir=bundled, stage=uninstalled_listing
     *
     * @effects bundled_listed_as_uninstalled
     *
     * @return void 반환값 없음
     */
    public function test_bundled_modules_appear_in_uninstalled_list(): void
    {
        // enrichDependencies 내부에서 호출되므로 기본 기대 설정
        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->andReturn(null);

        $this->manager->loadModules();

        $result = $this->manager->getUninstalledModules();

        $this->assertArrayHasKey('test-bundled-mod', $result);
        $this->assertFalse($result['test-bundled-mod']['is_pending']);
        $this->assertTrue($result['test-bundled-mod']['is_bundled']);
        $this->assertEquals('uninstalled', $result['test-bundled-mod']['status']);
        $this->assertEquals('1.5.0', $result['test-bundled-mod']['version']);
    }

    // ========================================================================
    // 7. _pending에서 설치 시 활성 디렉토리로 복사 검증
    // ========================================================================

    /**
     * installModule 시 _pending에서 활성 디렉토리로 복사되는지 테스트
     *
     * @scenario ext_type=module, source_dir=pending, stage=install_copy
     *
     * @effects install_copies_source_to_active
     *
     * @return void 반환값 없음
     */
    public function test_install_from_pending_copies_to_active_directory(): void
    {
        $method = new \ReflectionMethod($this->manager, 'copyFromPendingOrBundled');
        $method->setAccessible(true);

        $activePath = $this->modulesPath.'/test-pending-mod';
        $this->assertFalse(File::isDirectory($activePath));

        $method->invoke($this->manager, 'test-pending-mod');

        $this->assertTrue(File::isDirectory($activePath));
        $this->assertTrue(File::exists($activePath.'/module.json'));

        // 복사된 module.json 내용 검증
        $manifest = json_decode(File::get($activePath.'/module.json'), true);
        $this->assertEquals('test-pending-mod', $manifest['identifier']);
        $this->assertEquals('2.0.0', $manifest['version']);
    }

    // ========================================================================
    // 8. _bundled에서 설치 시 활성 디렉토리로 복사 검증
    // ========================================================================

    /**
     * _pending에 없는 모듈은 _bundled에서 활성 디렉토리로 복사되는지 테스트
     *
     * @scenario ext_type=module, source_dir=bundled, stage=install_copy
     *
     * @effects install_copies_source_to_active
     *
     * @return void 반환값 없음
     */
    public function test_install_from_bundled_copies_to_active_directory(): void
    {
        $method = new \ReflectionMethod($this->manager, 'copyFromPendingOrBundled');
        $method->setAccessible(true);

        $activePath = $this->modulesPath.'/test-bundled-mod';
        $this->assertFalse(File::isDirectory($activePath));

        $method->invoke($this->manager, 'test-bundled-mod');

        $this->assertTrue(File::isDirectory($activePath));
        $this->assertTrue(File::exists($activePath.'/module.json'));

        // 복사된 module.json 내용 검증
        $manifest = json_decode(File::get($activePath.'/module.json'), true);
        $this->assertEquals('test-bundled-mod', $manifest['identifier']);
        $this->assertEquals('1.5.0', $manifest['version']);
    }

    // ========================================================================
    // 9. _pending에서 설치 후 원본 보존 검증
    // ========================================================================

    /**
     * 설치 후 _pending 원본이 보존되는지 테스트
     *
     * @scenario ext_type=module, source_dir=pending, stage=install_copy
     *
     * @effects install_preserves_source_copy
     *
     * @return void 반환값 없음
     */
    public function test_install_from_pending_preserves_pending_copy(): void
    {
        $method = new \ReflectionMethod($this->manager, 'copyFromPendingOrBundled');
        $method->setAccessible(true);

        $pendingPath = $this->modulesPath.'/_pending/test-pending-mod';
        $this->assertTrue(File::isDirectory($pendingPath));

        $method->invoke($this->manager, 'test-pending-mod');

        // 활성 디렉토리에 복사됨
        $this->assertTrue(File::isDirectory($this->modulesPath.'/test-pending-mod'));
        // 원본 _pending 디렉토리 보존됨
        $this->assertTrue(File::isDirectory($pendingPath));
        $this->assertTrue(File::exists($pendingPath.'/module.json'));
    }

    // ========================================================================
    // 10. _bundled에서 설치 후 원본 보존 검증
    // ========================================================================

    /**
     * 설치 후 _bundled 원본이 보존되는지 테스트
     *
     * @scenario ext_type=module, source_dir=bundled, stage=install_copy
     *
     * @effects install_preserves_source_copy
     *
     * @return void 반환값 없음
     */
    public function test_install_from_bundled_preserves_bundled_copy(): void
    {
        $method = new \ReflectionMethod($this->manager, 'copyFromPendingOrBundled');
        $method->setAccessible(true);

        $bundledPath = $this->modulesPath.'/_bundled/test-bundled-mod';
        $this->assertTrue(File::isDirectory($bundledPath));

        $method->invoke($this->manager, 'test-bundled-mod');

        // 활성 디렉토리에 복사됨
        $this->assertTrue(File::isDirectory($this->modulesPath.'/test-bundled-mod'));
        // 원본 _bundled 디렉토리 보존됨
        $this->assertTrue(File::isDirectory($bundledPath));
        $this->assertTrue(File::exists($bundledPath.'/module.json'));
    }

    // ========================================================================
    // 11. uninstall 시 활성 디렉토리 삭제 검증
    // ========================================================================

    /**
     * uninstall 후 활성 디렉토리가 삭제되는지 테스트
     *
     * @scenario ext_type=module, source_dir=pending, stage=uninstall
     *
     * @effects uninstall_deletes_active_directory
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_deletes_active_directory(): void
    {
        // 먼저 활성 디렉토리에 모듈 배치
        $activePath = $this->modulesPath.'/test-pending-mod';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/module.json', '{}');

        $this->assertTrue(File::isDirectory($activePath));

        // deleteExtensionDirectory로 활성 디렉토리 삭제
        ExtensionPendingHelper::deleteExtensionDirectory($this->modulesPath, 'test-pending-mod');

        $this->assertFalse(File::isDirectory($activePath));
    }

    // ========================================================================
    // 12. uninstall 시 _pending 디렉토리 보존 검증
    // ========================================================================

    /**
     * uninstall 후 _pending 디렉토리가 보존되는지 테스트
     *
     * @scenario ext_type=module, source_dir=pending, stage=uninstall
     *
     * @effects uninstall_preserves_source_directory
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_preserves_pending_directory(): void
    {
        // 활성 디렉토리 생성
        $activePath = $this->modulesPath.'/test-pending-mod';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/module.json', '{}');

        $pendingPath = $this->modulesPath.'/_pending/test-pending-mod';
        $this->assertTrue(File::isDirectory($pendingPath));

        // 활성 디렉토리만 삭제 (uninstall 시 호출되는 로직)
        ExtensionPendingHelper::deleteExtensionDirectory($this->modulesPath, 'test-pending-mod');

        // 활성 디렉토리는 삭제됨
        $this->assertFalse(File::isDirectory($activePath));
        // _pending은 보존됨 (재설치 가능)
        $this->assertTrue(File::isDirectory($pendingPath));
        $this->assertTrue(File::exists($pendingPath.'/module.json'));
    }

    // ========================================================================
    // 13. uninstall 시 _bundled 디렉토리 보존 검증
    // ========================================================================

    /**
     * uninstall 후 _bundled 디렉토리가 보존되는지 테스트
     *
     * @scenario ext_type=module, source_dir=bundled, stage=uninstall
     *
     * @effects uninstall_preserves_source_directory
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_preserves_bundled_directory(): void
    {
        // 활성 디렉토리 생성
        $activePath = $this->modulesPath.'/test-bundled-mod';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/module.json', '{}');

        $bundledPath = $this->modulesPath.'/_bundled/test-bundled-mod';
        $this->assertTrue(File::isDirectory($bundledPath));

        // 활성 디렉토리만 삭제
        ExtensionPendingHelper::deleteExtensionDirectory($this->modulesPath, 'test-bundled-mod');

        // 활성 디렉토리는 삭제됨
        $this->assertFalse(File::isDirectory($activePath));
        // _bundled은 보존됨 (재설치 가능)
        $this->assertTrue(File::isDirectory($bundledPath));
        $this->assertTrue(File::exists($bundledPath.'/module.json'));
    }

    // ========================================================================
    // 14-15. 오토로드 수집에서 _pending/_bundled 제외 검증
    // ========================================================================

    /**
     * collectModuleAutoloads가 _pending 디렉토리를 건너뛰는지 테스트
     *
     * @scenario ext_type=module, source_dir=pending, stage=load_skip
     *
     * @effects source_dirs_skipped_in_autoload_collection
     *
     * @return void 반환값 없음
     */
    public function test_pending_skip_in_autoload_collection(): void
    {
        // _pending 내에 composer.json이 있는 테스트 모듈 추가 (오토로드 대상 후보로 만들기)
        $pendingModPath = $this->modulesPath.'/_pending/test-pending-mod';
        File::put($pendingModPath.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Test\\PendingMod\\' => 'src/']],
        ]));
        File::put($pendingModPath.'/module.php', '<?php // dummy');

        // 실제 ExtensionManager 인스턴스로 collectModuleAutoloads 테스트
        $realExtensionManager = app(ExtensionManager::class);
        $method = new \ReflectionMethod($realExtensionManager, 'collectModuleAutoloads');
        $method->setAccessible(true);

        $result = $method->invoke($realExtensionManager);

        // 결과가 배열임을 확인 (빈 배열이어도 어설션 수행)
        $this->assertIsArray($result['psr4']);
        $this->assertIsArray($result['classmap']);

        // PSR-4 네임스페이스에 _pending 모듈이 포함되지 않아야 함
        $allPaths = json_encode($result);
        $this->assertStringNotContainsString('_pending', $allPaths,
            '오토로드 결과에 _pending 경로가 포함됨');
    }

    /**
     * collectModuleAutoloads가 _bundled 디렉토리를 건너뛰는지 테스트
     *
     * @scenario ext_type=module, source_dir=bundled, stage=load_skip
     *
     * @effects source_dirs_skipped_in_autoload_collection
     *
     * @return void 반환값 없음
     */
    public function test_bundled_skip_in_autoload_collection(): void
    {
        // _bundled 내에 composer.json이 있는 테스트 모듈 추가
        $bundledModPath = $this->modulesPath.'/_bundled/test-bundled-mod';
        File::put($bundledModPath.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Test\\BundledMod\\' => 'src/']],
        ]));
        File::put($bundledModPath.'/module.php', '<?php // dummy');

        $realExtensionManager = app(ExtensionManager::class);
        $method = new \ReflectionMethod($realExtensionManager, 'collectModuleAutoloads');
        $method->setAccessible(true);

        $result = $method->invoke($realExtensionManager);

        // 결과가 배열임을 확인 (빈 배열이어도 어설션 수행)
        $this->assertIsArray($result['psr4']);
        $this->assertIsArray($result['classmap']);

        // PSR-4 네임스페이스에 _bundled 모듈이 포함되지 않아야 함
        $allPaths = json_encode($result);
        $this->assertStringNotContainsString('test-bundled-mod', $allPaths,
            '오토로드 결과에 _bundled 테스트 모듈이 포함됨');
    }

    // ========================================================================
    // 16-17. loadModules에서 _pending/_bundled 모듈이 활성 로드되지 않는 검증
    // ========================================================================

    /**
     * loadModules가 _pending 내부 모듈을 활성 모듈로 로드하지 않는지 테스트
     *
     * @scenario ext_type=module, source_dir=pending, stage=load_skip
     *
     * @effects source_dirs_not_loaded_as_active
     *
     * @return void 반환값 없음
     */
    public function test_pending_skip_in_load_modules(): void
    {
        $this->manager->loadModules();

        $activeModules = $this->manager->getAllModules();

        // _pending의 test-pending-mod가 활성 모듈 목록에 없어야 함
        $this->assertArrayNotHasKey('test-pending-mod', $activeModules,
            '_pending 모듈이 활성 모듈로 로드됨');
    }

    /**
     * loadModules가 _bundled 내부 모듈을 활성 모듈로 로드하지 않는지 테스트
     *
     * @scenario ext_type=module, source_dir=bundled, stage=load_skip
     *
     * @effects source_dirs_not_loaded_as_active
     *
     * @return void 반환값 없음
     */
    public function test_bundled_skip_in_load_modules(): void
    {
        $this->manager->loadModules();

        $activeModules = $this->manager->getAllModules();

        // _bundled의 test-bundled-mod가 활성 모듈 목록에 없어야 함
        $this->assertArrayNotHasKey('test-bundled-mod', $activeModules,
            '_bundled 모듈이 활성 모듈로 로드됨');
    }

    // ========================================================================
    // 18. _pending은 업데이트 감지에 사용되지 않음 검증
    // ========================================================================

    /**
     * _pending은 임시 스테이징 디렉토리이므로 버전 비교 대상이 아님을 확인
     *
     * @scenario ext_type=module, source_dir=pending, stage=update_detection
     *
     * @effects pending_ignored_for_update_detection
     *
     * @return void 반환값 없음
     */
    public function test_pending_is_not_used_for_update_detection(): void
    {
        $this->manager->loadModules();

        $record = $this->createModuleMock([
            'identifier' => 'test-pending-mod',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'update_available' => false,
            'latest_version' => null,
            'update_source' => null,
            'github_changelog_url' => null,
        ]);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-pending-mod')
            ->andReturn($record);

        $result = $this->manager->checkModuleUpdate('test-pending-mod');

        // _pending에 v2.0.0이 있어도 업데이트로 감지하지 않음
        $this->assertFalse($result['update_available']);
        $this->assertNull($result['update_source']);
    }

    // ========================================================================
    // 19. _bundled에서 업데이트 감지 검증
    // ========================================================================

    /**
     * 설치된 v1.0.0 + _bundled에 v1.5.0 → update_available=true, update_source='bundled' 확인
     *
     * @scenario ext_type=module, source_dir=bundled, stage=update_detection
     *
     * @effects bundled_used_as_update_source
     *
     * @return void 반환값 없음
     */
    public function test_update_detection_from_bundled(): void
    {
        $this->manager->loadModules();

        $record = $this->createModuleMock([
            'identifier' => 'test-bundled-mod',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'update_available' => false,
            'latest_version' => null,
            'update_source' => null,
            'github_changelog_url' => null,
        ]);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-bundled-mod')
            ->andReturn($record);

        $result = $this->manager->checkModuleUpdate('test-bundled-mod');

        $this->assertTrue($result['update_available']);
        $this->assertEquals('bundled', $result['update_source']);
        $this->assertEquals('1.5.0', $result['latest_version']);
        $this->assertEquals('1.0.0', $result['current_version']);
    }

    // ========================================================================
    // 21. _pending이 있어도 _bundled만 업데이트 감지에 사용되는지 검증
    // ========================================================================

    /**
     * _pending v2.0.0 + _bundled v1.5.0 → _bundled만 업데이트 소스로 사용됨
     *
     * @scenario ext_type=module, source_dir=bundled, stage=update_detection
     *
     * @effects bundled_used_as_update_source, pending_ignored_for_update_detection
     *
     * @return void 반환값 없음
     */
    public function test_only_bundled_used_for_update_detection_even_with_pending(): void
    {
        // _pending과 _bundled 모두 동일 identifier로 생성
        $this->createTestModule('_pending', 'test-bundled-mod', '2.0.0');

        $this->manager->loadModules();

        $record = $this->createModuleMock([
            'identifier' => 'test-bundled-mod',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'update_available' => false,
            'latest_version' => null,
            'update_source' => null,
            'github_changelog_url' => null,
        ]);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-bundled-mod')
            ->andReturn($record);

        $result = $this->manager->checkModuleUpdate('test-bundled-mod');

        $this->assertTrue($result['update_available']);
        $this->assertEquals('bundled', $result['update_source']);
        $this->assertEquals('1.5.0', $result['latest_version']);
    }

    // ========================================================================
    // _pending/_bundled 메타데이터 로딩 동작 추가 검증
    // ========================================================================

    /**
     * loadPendingModules에서 이미 활성 모듈이면 pending 목록에서 제외되는지 테스트
     *
     * @scenario ext_type=module, source_dir=pending, stage=uninstalled_listing
     *
     * @effects listing_excludes_active_or_pending_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_pending_excludes_already_active_modules(): void
    {
        // 활성 디렉토리에도 test-pending-mod 배치 (module.php가 없어서 로드는 안 되지만 별도 검증)
        // pending 목록에서 활성 모듈 제외 로직은 loadPendingModules 내부에서 수행
        $this->manager->loadModules();

        $pending = $this->manager->getPendingModules();

        // test-pending-mod는 활성 디렉토리에 없으므로 pending 목록에 포함
        $this->assertArrayHasKey('test-pending-mod', $pending);
        // 기존 실제 활성 모듈(sirsoft-board 등)은 pending에서 제외
        $this->assertArrayNotHasKey('sirsoft-board', $pending);
    }

    /**
     * loadBundledModules에서 이미 _pending에 있는 모듈이면 bundled 목록에서 제외되는지 테스트
     *
     * @scenario ext_type=module, source_dir=bundled, stage=uninstalled_listing
     *
     * @effects listing_excludes_active_or_pending_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_bundled_excludes_already_pending_modules(): void
    {
        // _pending과 _bundled에 동일 identifier 존재
        $this->createTestModule('_pending', 'test-bundled-mod', '2.0.0');

        $this->manager->loadModules();

        $bundled = $this->manager->getBundledModules();

        // test-bundled-mod는 이미 _pending에 존재하므로 bundled 목록에서 제외
        $this->assertArrayNotHasKey('test-bundled-mod', $bundled,
            '_pending에 동일 모듈 존재 시 _bundled 목록에서 제외되어야 함');
    }

    /**
     * _pending과 _bundled 메타데이터가 올바른 필드를 포함하는지 테스트
     *
     * @scenario ext_type=module, source_dir=pending, stage=uninstalled_listing
     *
     * @effects source_metadata_contains_required_fields
     *
     * @return void 반환값 없음
     */
    public function test_pending_metadata_contains_required_fields(): void
    {
        $this->manager->loadModules();

        $pending = $this->manager->getPendingModules();

        $this->assertArrayHasKey('test-pending-mod', $pending);
        $metadata = $pending['test-pending-mod'];

        $this->assertArrayHasKey('identifier', $metadata);
        $this->assertArrayHasKey('version', $metadata);
        $this->assertArrayHasKey('vendor', $metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('directory', $metadata);
        $this->assertArrayHasKey('source_path', $metadata);

        $this->assertEquals('test-pending-mod', $metadata['identifier']);
        $this->assertEquals('2.0.0', $metadata['version']);
        $this->assertEquals('test', $metadata['vendor']);
    }

    // ========================================================================
    // ========================================================================
    //
    //                     플러그인 (Plugin) 테스트
    //
    // ========================================================================
    // ========================================================================

    // ========================================================================
    // P-1. _pending 플러그인이 미설치 목록에 표시되는지 검증
    // ========================================================================

    /**
     * _pending 디렉토리의 플러그인이 미설치 목록에 is_pending: true로 표시되는지 테스트
     *
     * @scenario ext_type=plugin, source_dir=pending, stage=uninstalled_listing
     *
     * @effects pending_listed_as_uninstalled
     *
     * @return void 반환값 없음
     */
    public function test_pending_plugins_appear_in_uninstalled_list(): void
    {
        $this->pluginRepository->shouldReceive('findByIdentifier')
            ->andReturn(null);

        $this->pluginManager->loadPlugins();

        $result = $this->pluginManager->getUninstalledPlugins();

        $this->assertArrayHasKey('test-pending-plg', $result);
        $this->assertTrue($result['test-pending-plg']['is_pending']);
        $this->assertFalse($result['test-pending-plg']['is_bundled']);
        $this->assertEquals('uninstalled', $result['test-pending-plg']['status']);
        $this->assertEquals('2.0.0', $result['test-pending-plg']['version']);
    }

    // ========================================================================
    // P-2. _bundled 플러그인이 미설치 목록에 표시되는지 검증
    // ========================================================================

    /**
     * _bundled 디렉토리의 플러그인이 미설치 목록에 is_bundled: true로 표시되는지 테스트
     *
     * @scenario ext_type=plugin, source_dir=bundled, stage=uninstalled_listing
     *
     * @effects bundled_listed_as_uninstalled
     *
     * @return void 반환값 없음
     */
    public function test_bundled_plugins_appear_in_uninstalled_list(): void
    {
        $this->pluginRepository->shouldReceive('findByIdentifier')
            ->andReturn(null);

        $this->pluginManager->loadPlugins();

        $result = $this->pluginManager->getUninstalledPlugins();

        $this->assertArrayHasKey('test-bundled-plg', $result);
        $this->assertFalse($result['test-bundled-plg']['is_pending']);
        $this->assertTrue($result['test-bundled-plg']['is_bundled']);
        $this->assertEquals('uninstalled', $result['test-bundled-plg']['status']);
        $this->assertEquals('1.5.0', $result['test-bundled-plg']['version']);
    }

    // ========================================================================
    // P-3. _pending에서 활성 디렉토리로 복사 검증
    // ========================================================================

    /**
     * _pending에서 플러그인이 활성 디렉토리로 복사되는지 테스트 (ExtensionPendingHelper 직접 사용)
     *
     * @scenario ext_type=plugin, source_dir=pending, stage=install_copy
     *
     * @effects install_copies_source_to_active
     *
     * @return void 반환값 없음
     */
    public function test_plugin_install_from_pending_copies_to_active_directory(): void
    {
        $activePath = $this->pluginsPath.'/test-pending-plg';
        $this->assertFalse(File::isDirectory($activePath));

        $sourcePath = ExtensionPendingHelper::getPendingPath($this->pluginsPath, 'test-pending-plg');
        ExtensionPendingHelper::copyToActive($sourcePath, $activePath);

        $this->assertTrue(File::isDirectory($activePath));
        $this->assertTrue(File::exists($activePath.'/plugin.json'));

        $manifest = json_decode(File::get($activePath.'/plugin.json'), true);
        $this->assertEquals('test-pending-plg', $manifest['identifier']);
        $this->assertEquals('2.0.0', $manifest['version']);
    }

    // ========================================================================
    // P-4. _bundled에서 활성 디렉토리로 복사 검증
    // ========================================================================

    /**
     * _bundled에서 플러그인이 활성 디렉토리로 복사되는지 테스트 (ExtensionPendingHelper 직접 사용)
     *
     * @scenario ext_type=plugin, source_dir=bundled, stage=install_copy
     *
     * @effects install_copies_source_to_active
     *
     * @return void 반환값 없음
     */
    public function test_plugin_install_from_bundled_copies_to_active_directory(): void
    {
        $activePath = $this->pluginsPath.'/test-bundled-plg';
        $this->assertFalse(File::isDirectory($activePath));

        $sourcePath = ExtensionPendingHelper::getBundledPath($this->pluginsPath, 'test-bundled-plg');
        ExtensionPendingHelper::copyToActive($sourcePath, $activePath);

        $this->assertTrue(File::isDirectory($activePath));
        $this->assertTrue(File::exists($activePath.'/plugin.json'));

        $manifest = json_decode(File::get($activePath.'/plugin.json'), true);
        $this->assertEquals('test-bundled-plg', $manifest['identifier']);
        $this->assertEquals('1.5.0', $manifest['version']);
    }

    // ========================================================================
    // P-5. uninstall 시 _pending/_bundled 보존 검증
    // ========================================================================

    /**
     * uninstall 후 _pending 플러그인 디렉토리가 보존되는지 테스트
     *
     * @scenario ext_type=plugin, source_dir=pending, stage=uninstall
     *
     * @effects uninstall_preserves_source_directory
     *
     * @return void 반환값 없음
     */
    public function test_plugin_uninstall_preserves_pending_directory(): void
    {
        $activePath = $this->pluginsPath.'/test-pending-plg';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/plugin.json', '{}');

        $pendingPath = $this->pluginsPath.'/_pending/test-pending-plg';
        $this->assertTrue(File::isDirectory($pendingPath));

        ExtensionPendingHelper::deleteExtensionDirectory($this->pluginsPath, 'test-pending-plg');

        $this->assertFalse(File::isDirectory($activePath));
        $this->assertTrue(File::isDirectory($pendingPath));
    }

    /**
     * uninstall 후 _bundled 플러그인 디렉토리가 보존되는지 테스트
     *
     * @scenario ext_type=plugin, source_dir=bundled, stage=uninstall
     *
     * @effects uninstall_preserves_source_directory
     *
     * @return void 반환값 없음
     */
    public function test_plugin_uninstall_preserves_bundled_directory(): void
    {
        $activePath = $this->pluginsPath.'/test-bundled-plg';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/plugin.json', '{}');

        $bundledPath = $this->pluginsPath.'/_bundled/test-bundled-plg';
        $this->assertTrue(File::isDirectory($bundledPath));

        ExtensionPendingHelper::deleteExtensionDirectory($this->pluginsPath, 'test-bundled-plg');

        $this->assertFalse(File::isDirectory($activePath));
        $this->assertTrue(File::isDirectory($bundledPath));
    }

    // ========================================================================
    // P-6. loadPlugins에서 _pending/_bundled 건너뛰기 검증
    // ========================================================================

    /**
     * loadPlugins가 _pending 플러그인을 활성 로드하지 않는지 테스트
     *
     * @scenario ext_type=plugin, source_dir=pending, stage=load_skip
     *
     * @effects source_dirs_not_loaded_as_active
     *
     * @return void 반환값 없음
     */
    public function test_plugin_pending_skip_in_load_plugins(): void
    {
        $this->pluginManager->loadPlugins();

        $activePlugins = $this->pluginManager->getAllPlugins();

        $this->assertArrayNotHasKey('test-pending-plg', $activePlugins,
            '_pending 플러그인이 활성 플러그인으로 로드됨');
    }

    /**
     * loadPlugins가 _bundled 플러그인을 활성 로드하지 않는지 테스트
     *
     * @scenario ext_type=plugin, source_dir=bundled, stage=load_skip
     *
     * @effects source_dirs_not_loaded_as_active
     *
     * @return void 반환값 없음
     */
    public function test_plugin_bundled_skip_in_load_plugins(): void
    {
        $this->pluginManager->loadPlugins();

        $activePlugins = $this->pluginManager->getAllPlugins();

        $this->assertArrayNotHasKey('test-bundled-plg', $activePlugins,
            '_bundled 플러그인이 활성 플러그인으로 로드됨');
    }

    // ========================================================================
    // P-7. 플러그인 _pending은 업데이트 감지에 사용되지 않음 검증
    // ========================================================================

    /**
     * _pending은 임시 스테이징 디렉토리이므로 버전 비교 대상이 아님을 확인
     *
     * @scenario ext_type=plugin, source_dir=pending, stage=update_detection
     *
     * @effects pending_ignored_for_update_detection
     *
     * @return void 반환값 없음
     */
    public function test_plugin_pending_is_not_used_for_update_detection(): void
    {
        $this->pluginManager->loadPlugins();

        $record = Mockery::mock(Plugin::class)->makePartial();
        $record->identifier = 'test-pending-plg';
        $record->version = '1.0.0';
        $record->status = ExtensionStatus::Active->value;
        $record->github_url = null;
        $record->github_changelog_url = null;

        $this->pluginRepository->shouldReceive('findByIdentifier')
            ->with('test-pending-plg')
            ->andReturn($record);

        $result = $this->pluginManager->checkPluginUpdate('test-pending-plg');

        // _pending에 v2.0.0이 있어도 업데이트로 감지하지 않음
        $this->assertFalse($result['update_available']);
        $this->assertNull($result['update_source']);
    }

    // ========================================================================
    // P-8. 플러그인 _bundled 업데이트 감지 검증
    // ========================================================================

    /**
     * 설치된 v1.0.0 + _bundled에 v1.5.0 → update_available=true, update_source='bundled' 확인
     *
     * @scenario ext_type=plugin, source_dir=bundled, stage=update_detection
     *
     * @effects bundled_used_as_update_source
     *
     * @return void 반환값 없음
     */
    public function test_plugin_update_detection_from_bundled(): void
    {
        $this->pluginManager->loadPlugins();

        $record = Mockery::mock(Plugin::class)->makePartial();
        $record->identifier = 'test-bundled-plg';
        $record->version = '1.0.0';
        $record->status = ExtensionStatus::Active->value;
        $record->github_url = null;
        $record->github_changelog_url = null;

        $this->pluginRepository->shouldReceive('findByIdentifier')
            ->with('test-bundled-plg')
            ->andReturn($record);

        $result = $this->pluginManager->checkPluginUpdate('test-bundled-plg');

        $this->assertTrue($result['update_available']);
        $this->assertEquals('bundled', $result['update_source']);
        $this->assertEquals('1.5.0', $result['latest_version']);
        $this->assertEquals('1.0.0', $result['current_version']);
    }

    // ========================================================================
    // P-9. 플러그인 _pending이 있어도 _bundled만 업데이트 감지에 사용 검증
    // ========================================================================

    /**
     * _pending v2.0.0 + _bundled v1.5.0 → _bundled만 업데이트 소스로 사용됨
     *
     * @scenario ext_type=plugin, source_dir=bundled, stage=update_detection
     *
     * @effects bundled_used_as_update_source, pending_ignored_for_update_detection
     *
     * @return void 반환값 없음
     */
    public function test_plugin_only_bundled_used_for_update_detection_even_with_pending(): void
    {
        $this->createTestPlugin('_pending', 'test-bundled-plg', '2.0.0');

        $this->pluginManager->loadPlugins();

        $record = Mockery::mock(Plugin::class)->makePartial();
        $record->identifier = 'test-bundled-plg';
        $record->version = '1.0.0';
        $record->status = ExtensionStatus::Active->value;
        $record->github_url = null;
        $record->github_changelog_url = null;

        $this->pluginRepository->shouldReceive('findByIdentifier')
            ->with('test-bundled-plg')
            ->andReturn($record);

        $result = $this->pluginManager->checkPluginUpdate('test-bundled-plg');

        $this->assertTrue($result['update_available']);
        $this->assertEquals('bundled', $result['update_source']);
        $this->assertEquals('1.5.0', $result['latest_version']);
    }

    // ========================================================================
    // P-10. 플러그인 메타데이터 필드 검증
    // ========================================================================

    /**
     * _pending 플러그인 메타데이터가 올바른 필드를 포함하는지 테스트
     *
     * @scenario ext_type=plugin, source_dir=pending, stage=uninstalled_listing
     *
     * @effects source_metadata_contains_required_fields
     *
     * @return void 반환값 없음
     */
    public function test_plugin_pending_metadata_contains_required_fields(): void
    {
        $this->pluginManager->loadPlugins();

        $pending = $this->pluginManager->getPendingPlugins();

        $this->assertArrayHasKey('test-pending-plg', $pending);
        $metadata = $pending['test-pending-plg'];

        $this->assertArrayHasKey('identifier', $metadata);
        $this->assertArrayHasKey('version', $metadata);
        $this->assertArrayHasKey('vendor', $metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('directory', $metadata);
        $this->assertArrayHasKey('source_path', $metadata);

        $this->assertEquals('test-pending-plg', $metadata['identifier']);
        $this->assertEquals('2.0.0', $metadata['version']);
        $this->assertEquals('test', $metadata['vendor']);
    }

    // ========================================================================
    // ========================================================================
    //
    //                     템플릿 (Template) 테스트
    //
    // ========================================================================
    // ========================================================================

    // ========================================================================
    // T-1. _pending 템플릿이 미설치 목록에 표시되는지 검증
    // ========================================================================

    /**
     * _pending 디렉토리의 템플릿이 미설치 목록에 source: 'pending'으로 표시되는지 테스트
     *
     * @scenario ext_type=template, source_dir=pending, stage=uninstalled_listing
     *
     * @effects pending_listed_as_uninstalled
     *
     * @return void 반환값 없음
     */
    public function test_pending_templates_appear_in_uninstalled_list(): void
    {
        $this->templateManager->loadTemplates();

        $result = $this->templateManager->getUninstalledTemplates();

        $this->assertArrayHasKey('test-pending-tpl', $result);
        $this->assertEquals('pending', $result['test-pending-tpl']['source']);
        $this->assertEquals('uninstalled', $result['test-pending-tpl']['status']);
        $this->assertEquals('2.0.0', $result['test-pending-tpl']['version']);
        $this->assertEquals('admin', $result['test-pending-tpl']['type']);
    }

    // ========================================================================
    // T-2. _bundled 템플릿이 미설치 목록에 표시되는지 검증
    // ========================================================================

    /**
     * _bundled 디렉토리의 템플릿이 미설치 목록에 source: 'bundled'로 표시되는지 테스트
     *
     * @scenario ext_type=template, source_dir=bundled, stage=uninstalled_listing
     *
     * @effects bundled_listed_as_uninstalled
     *
     * @return void 반환값 없음
     */
    public function test_bundled_templates_appear_in_uninstalled_list(): void
    {
        $this->templateManager->loadTemplates();

        $result = $this->templateManager->getUninstalledTemplates();

        $this->assertArrayHasKey('test-bundled-tpl', $result);
        $this->assertEquals('bundled', $result['test-bundled-tpl']['source']);
        $this->assertEquals('uninstalled', $result['test-bundled-tpl']['status']);
        $this->assertEquals('1.5.0', $result['test-bundled-tpl']['version']);
        $this->assertEquals('user', $result['test-bundled-tpl']['type']);
    }

    // ========================================================================
    // T-3. _pending에서 활성 디렉토리로 복사 검증
    // ========================================================================

    /**
     * copyToActiveFromSource가 _pending에서 활성 디렉토리로 복사하는지 테스트
     *
     * @scenario ext_type=template, source_dir=pending, stage=install_copy
     *
     * @effects install_copies_source_to_active
     *
     * @return void 반환값 없음
     */
    public function test_template_copy_from_pending_to_active(): void
    {
        $this->templateManager->loadTemplates();

        $method = new \ReflectionMethod($this->templateManager, 'copyToActiveFromSource');
        $method->setAccessible(true);

        $activePath = $this->templatesPath.'/test-pending-tpl';
        $this->assertFalse(File::isDirectory($activePath));

        $method->invoke($this->templateManager, 'test-pending-tpl');

        $this->assertTrue(File::isDirectory($activePath));
        $this->assertTrue(File::exists($activePath.'/template.json'));

        $manifest = json_decode(File::get($activePath.'/template.json'), true);
        $this->assertEquals('test-pending-tpl', $manifest['identifier']);
        $this->assertEquals('2.0.0', $manifest['version']);
        $this->assertEquals('admin', $manifest['type']);
    }

    // ========================================================================
    // T-4. _bundled에서 활성 디렉토리로 복사 검증
    // ========================================================================

    /**
     * copyToActiveFromSource가 _bundled에서 활성 디렉토리로 복사하는지 테스트
     *
     * @scenario ext_type=template, source_dir=bundled, stage=install_copy
     *
     * @effects install_copies_source_to_active
     *
     * @return void 반환값 없음
     */
    public function test_template_copy_from_bundled_to_active(): void
    {
        $this->templateManager->loadTemplates();

        $method = new \ReflectionMethod($this->templateManager, 'copyToActiveFromSource');
        $method->setAccessible(true);

        $activePath = $this->templatesPath.'/test-bundled-tpl';
        $this->assertFalse(File::isDirectory($activePath));

        $method->invoke($this->templateManager, 'test-bundled-tpl');

        $this->assertTrue(File::isDirectory($activePath));
        $this->assertTrue(File::exists($activePath.'/template.json'));

        $manifest = json_decode(File::get($activePath.'/template.json'), true);
        $this->assertEquals('test-bundled-tpl', $manifest['identifier']);
        $this->assertEquals('1.5.0', $manifest['version']);
        $this->assertEquals('user', $manifest['type']);
    }

    // ========================================================================
    // T-5. 복사 후 원본 보존 검증
    // ========================================================================

    /**
     * copyToActiveFromSource 후 _pending 원본이 보존되는지 테스트
     *
     * @scenario ext_type=template, source_dir=pending, stage=install_copy
     *
     * @effects install_preserves_source_copy
     *
     * @return void 반환값 없음
     */
    public function test_template_copy_preserves_pending_source(): void
    {
        $this->templateManager->loadTemplates();

        $method = new \ReflectionMethod($this->templateManager, 'copyToActiveFromSource');
        $method->setAccessible(true);

        $pendingPath = $this->templatesPath.'/_pending/test-pending-tpl';
        $this->assertTrue(File::isDirectory($pendingPath));

        $method->invoke($this->templateManager, 'test-pending-tpl');

        // 활성 디렉토리에 복사됨
        $this->assertTrue(File::isDirectory($this->templatesPath.'/test-pending-tpl'));
        // 원본 _pending 디렉토리 보존됨
        $this->assertTrue(File::isDirectory($pendingPath));
        $this->assertTrue(File::exists($pendingPath.'/template.json'));
    }

    /**
     * copyToActiveFromSource 후 _bundled 원본이 보존되는지 테스트
     *
     * @scenario ext_type=template, source_dir=bundled, stage=install_copy
     *
     * @effects install_preserves_source_copy
     *
     * @return void 반환값 없음
     */
    public function test_template_copy_preserves_bundled_source(): void
    {
        $this->templateManager->loadTemplates();

        $method = new \ReflectionMethod($this->templateManager, 'copyToActiveFromSource');
        $method->setAccessible(true);

        $bundledPath = $this->templatesPath.'/_bundled/test-bundled-tpl';
        $this->assertTrue(File::isDirectory($bundledPath));

        $method->invoke($this->templateManager, 'test-bundled-tpl');

        // 활성 디렉토리에 복사됨
        $this->assertTrue(File::isDirectory($this->templatesPath.'/test-bundled-tpl'));
        // 원본 _bundled 디렉토리 보존됨
        $this->assertTrue(File::isDirectory($bundledPath));
        $this->assertTrue(File::exists($bundledPath.'/template.json'));
    }

    // ========================================================================
    // T-6. uninstall 시 _pending/_bundled 보존 검증
    // ========================================================================

    /**
     * uninstall 후 _pending 템플릿 디렉토리가 보존되는지 테스트
     *
     * @scenario ext_type=template, source_dir=pending, stage=uninstall
     *
     * @effects uninstall_preserves_source_directory
     *
     * @return void 반환값 없음
     */
    public function test_template_uninstall_preserves_pending_directory(): void
    {
        $activePath = $this->templatesPath.'/test-pending-tpl';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/template.json', '{}');

        $pendingPath = $this->templatesPath.'/_pending/test-pending-tpl';
        $this->assertTrue(File::isDirectory($pendingPath));

        ExtensionPendingHelper::deleteExtensionDirectory($this->templatesPath, 'test-pending-tpl');

        $this->assertFalse(File::isDirectory($activePath));
        $this->assertTrue(File::isDirectory($pendingPath));
        $this->assertTrue(File::exists($pendingPath.'/template.json'));
    }

    /**
     * uninstall 후 _bundled 템플릿 디렉토리가 보존되는지 테스트
     *
     * @scenario ext_type=template, source_dir=bundled, stage=uninstall
     *
     * @effects uninstall_preserves_source_directory
     *
     * @return void 반환값 없음
     */
    public function test_template_uninstall_preserves_bundled_directory(): void
    {
        $activePath = $this->templatesPath.'/test-bundled-tpl';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/template.json', '{}');

        $bundledPath = $this->templatesPath.'/_bundled/test-bundled-tpl';
        $this->assertTrue(File::isDirectory($bundledPath));

        ExtensionPendingHelper::deleteExtensionDirectory($this->templatesPath, 'test-bundled-tpl');

        $this->assertFalse(File::isDirectory($activePath));
        $this->assertTrue(File::isDirectory($bundledPath));
        $this->assertTrue(File::exists($bundledPath.'/template.json'));
    }

    // ========================================================================
    // T-7. loadTemplates에서 _pending/_bundled 건너뛰기 검증
    // ========================================================================

    /**
     * loadTemplates가 _pending 템플릿을 활성 목록에 로드하지 않는지 테스트
     *
     * @scenario ext_type=template, source_dir=pending, stage=load_skip
     *
     * @effects source_dirs_not_loaded_as_active
     *
     * @return void 반환값 없음
     */
    public function test_template_pending_skip_in_load_templates(): void
    {
        $this->templateManager->loadTemplates();

        $activeTemplates = $this->templateManager->getAllTemplates();

        $this->assertArrayNotHasKey('test-pending-tpl', $activeTemplates,
            '_pending 템플릿이 활성 템플릿으로 로드됨');
    }

    /**
     * loadTemplates가 _bundled 템플릿을 활성 목록에 로드하지 않는지 테스트
     *
     * @scenario ext_type=template, source_dir=bundled, stage=load_skip
     *
     * @effects source_dirs_not_loaded_as_active
     *
     * @return void 반환값 없음
     */
    public function test_template_bundled_skip_in_load_templates(): void
    {
        $this->templateManager->loadTemplates();

        $activeTemplates = $this->templateManager->getAllTemplates();

        $this->assertArrayNotHasKey('test-bundled-tpl', $activeTemplates,
            '_bundled 템플릿이 활성 템플릿으로 로드됨');
    }

    // ========================================================================
    // T-8. 템플릿 _pending은 업데이트 감지에 사용되지 않음 검증
    // ========================================================================

    /**
     * _pending은 임시 스테이징 디렉토리이므로 버전 비교 대상이 아님을 확인
     *
     * @scenario ext_type=template, source_dir=pending, stage=update_detection
     *
     * @effects pending_ignored_for_update_detection
     *
     * @return void 반환값 없음
     */
    public function test_template_pending_is_not_used_for_update_detection(): void
    {
        $this->templateManager->loadTemplates();

        $record = $this->createTemplateMock([
            'identifier' => 'test-pending-tpl',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'github_url' => null,
            'github_changelog_url' => null,
        ]);

        $this->templateRepository->shouldReceive('findByIdentifier')
            ->with('test-pending-tpl')
            ->andReturn($record);

        $result = $this->templateManager->checkTemplateUpdate('test-pending-tpl');

        // _pending에 v2.0.0이 있어도 업데이트로 감지하지 않음
        $this->assertFalse($result['update_available']);
        $this->assertNull($result['update_source']);
    }

    // ========================================================================
    // T-9. 템플릿 _bundled 업데이트 감지 검증
    // ========================================================================

    /**
     * 설치된 v1.0.0 + _bundled에 v1.5.0 → update_available=true, update_source='bundled' 확인
     *
     * @scenario ext_type=template, source_dir=bundled, stage=update_detection
     *
     * @effects bundled_used_as_update_source
     *
     * @return void 반환값 없음
     */
    public function test_template_update_detection_from_bundled(): void
    {
        $this->templateManager->loadTemplates();

        $record = $this->createTemplateMock([
            'identifier' => 'test-bundled-tpl',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'github_url' => null,
            'github_changelog_url' => null,
        ]);

        $this->templateRepository->shouldReceive('findByIdentifier')
            ->with('test-bundled-tpl')
            ->andReturn($record);

        $result = $this->templateManager->checkTemplateUpdate('test-bundled-tpl');

        $this->assertTrue($result['update_available']);
        $this->assertEquals('bundled', $result['update_source']);
        $this->assertEquals('1.5.0', $result['latest_version']);
        $this->assertEquals('1.0.0', $result['current_version']);
    }

    // ========================================================================
    // T-10. 템플릿 _pending이 있어도 _bundled만 업데이트 감지에 사용 검증
    // ========================================================================

    /**
     * _pending v2.0.0 + _bundled v1.5.0 → _bundled만 업데이트 소스로 사용됨
     *
     * @scenario ext_type=template, source_dir=bundled, stage=update_detection
     *
     * @effects bundled_used_as_update_source, pending_ignored_for_update_detection
     *
     * @return void 반환값 없음
     */
    public function test_template_only_bundled_used_for_update_detection_even_with_pending(): void
    {
        // _pending과 _bundled에 동일 identifier 존재
        $this->createTestTemplate('_pending', 'test-bundled-tpl', '2.0.0', 'user');

        $this->templateManager->loadTemplates();

        $record = $this->createTemplateMock([
            'identifier' => 'test-bundled-tpl',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'github_url' => null,
            'github_changelog_url' => null,
        ]);

        $this->templateRepository->shouldReceive('findByIdentifier')
            ->with('test-bundled-tpl')
            ->andReturn($record);

        $result = $this->templateManager->checkTemplateUpdate('test-bundled-tpl');

        $this->assertTrue($result['update_available']);
        $this->assertEquals('bundled', $result['update_source']);
        $this->assertEquals('1.5.0', $result['latest_version']);
    }

    // ========================================================================
    // T-11. 이미 활성인 템플릿은 pending 목록에서 제외 검증
    // ========================================================================

    /**
     * loadPendingTemplates에서 이미 활성 템플릿이면 pending 목록에서 제외되는지 테스트
     *
     * @scenario ext_type=template, source_dir=pending, stage=uninstalled_listing
     *
     * @effects listing_excludes_active_or_pending_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_template_pending_excludes_already_active(): void
    {
        $this->templateManager->loadTemplates();

        $pending = $this->templateManager->getPendingTemplates();

        // test-pending-tpl은 활성 디렉토리에 없으므로 pending 목록에 포함
        $this->assertArrayHasKey('test-pending-tpl', $pending);

        // 실제 활성 템플릿(sirsoft-admin_basic 등)은 pending에서 제외
        foreach ($this->templateManager->getAllTemplates() as $identifier => $_) {
            $this->assertArrayNotHasKey($identifier, $pending,
                "활성 템플릿 {$identifier}이 pending 목록에 포함됨");
        }
    }

    // ========================================================================
    // T-12. 이미 _pending에 있는 템플릿은 bundled 목록에서 제외 검증
    // ========================================================================

    /**
     * loadBundledTemplates에서 이미 _pending에 있는 템플릿이면 bundled 목록에서 제외되는지 테스트
     *
     * @scenario ext_type=template, source_dir=bundled, stage=uninstalled_listing
     *
     * @effects listing_excludes_active_or_pending_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_template_bundled_excludes_already_pending(): void
    {
        // _pending과 _bundled에 동일 identifier 존재
        $this->createTestTemplate('_pending', 'test-bundled-tpl', '2.0.0', 'user');

        $this->templateManager->loadTemplates();

        $bundled = $this->templateManager->getBundledTemplates();

        $this->assertArrayNotHasKey('test-bundled-tpl', $bundled,
            '_pending에 동일 템플릿 존재 시 _bundled 목록에서 제외되어야 함');
    }

    // ========================================================================
    // T-13. 템플릿 메타데이터 필드 검증
    // ========================================================================

    /**
     * _pending 템플릿 메타데이터가 올바른 필드를 포함하는지 테스트
     *
     * @scenario ext_type=template, source_dir=pending, stage=uninstalled_listing
     *
     * @effects source_metadata_contains_required_fields
     *
     * @return void 반환값 없음
     */
    public function test_template_pending_metadata_contains_required_fields(): void
    {
        $this->templateManager->loadTemplates();

        $pending = $this->templateManager->getPendingTemplates();

        $this->assertArrayHasKey('test-pending-tpl', $pending);
        $metadata = $pending['test-pending-tpl'];

        $this->assertArrayHasKey('identifier', $metadata);
        $this->assertArrayHasKey('version', $metadata);
        $this->assertArrayHasKey('vendor', $metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('directory', $metadata);
        $this->assertArrayHasKey('source_path', $metadata);

        $this->assertEquals('test-pending-tpl', $metadata['identifier']);
        $this->assertEquals('2.0.0', $metadata['version']);
        $this->assertEquals('test', $metadata['vendor']);
    }

    // ========================================================================
    // T-14. template.json 사용 검증 (module.json/plugin.json 아닌)
    // ========================================================================

    /**
     * 템플릿이 template.json을 메타데이터 파일로 사용하는지 검증
     *
     * @scenario ext_type=template, source_dir=pending, stage=uninstalled_listing
     *
     * @effects template_manifest_read_from_template_json
     *
     * @return void 반환값 없음
     */
    public function test_template_uses_template_json_manifest(): void
    {
        // template.json이 아닌 module.json만 있는 디렉토리는 무시
        $invalidPath = $this->templatesPath.'/_pending/test-invalid-tpl';
        File::ensureDirectoryExists($invalidPath);
        File::put($invalidPath.'/module.json', json_encode([
            'identifier' => 'test-invalid-tpl',
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => 'Invalid',
            'type' => 'admin',
        ]));

        $this->templateManager->loadTemplates();

        $pending = $this->templateManager->getPendingTemplates();

        // template.json이 없으므로 pending에 포함되지 않음
        $this->assertArrayNotHasKey('test-invalid-tpl', $pending,
            'template.json이 없는 디렉토리가 pending 목록에 포함됨');

        // 정리
        File::deleteDirectory($invalidPath);
    }
}
