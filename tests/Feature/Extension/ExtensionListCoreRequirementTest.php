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
use App\Extension\ExtensionManager;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Http\Resources\ModuleResource;
use App\Http\Resources\PluginResource;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

/**
 * 확장 목록 행이 코어 요구 버전(`requires_core`)을 싣는지 검증 (#703 브라우저 실측)
 *
 * 목록 리소스의 `core_compatible` 은 행의 `requires_core` 로 판정하는데, 목록을 만드는 매니저
 * 메서드(getUninstalled* / getInstalled*WithDetails)가 그 키를 싣지 않아 매니페스트가 코어를
 * 만족하지 못해도 늘 `true` 가 내려갔다. 화면은 설치 버튼·켜기 스위치를 잠그지 못하고 누른 뒤
 * 422(`core_version_mismatch`)만 보였다. 리소스 단위 테스트는 행에 키를 직접 넣어 이 누락을 못 봤다.
 *
 * 시나리오 매니페스트: tests/scenarios/admin-action-gate-parity.yaml
 */
class ExtensionListCoreRequirementTest extends TestCase
{
    use ProtectsExtensionDirectories;
    use RefreshDatabase;

    private const REQUIREMENT = '>=999.0.0';

    private string $modulesPath;

    private string $pluginsPath;

    private string $templatesPath;

    /**
     * 코어를 만족하지 못하는 _bundled 확장과 활성 템플릿 fixture 를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesPath = base_path('modules');
        $this->pluginsPath = base_path('plugins');
        $this->templatesPath = base_path('templates');

        $this->writeManifest($this->modulesPath.'/_bundled/test-core-req-mod', 'module.json', []);
        $this->writeManifest($this->pluginsPath.'/_bundled/test-core-req-plg', 'plugin.json', []);
        $this->writeManifest($this->templatesPath.'/_bundled/test-core-req-tpl', 'template.json', ['type' => 'admin']);
        $this->writeManifest($this->templatesPath.'/testcorereq-active_tpl', 'template.json', ['type' => 'admin']);

        Cache::flush();
        ModuleManager::invalidateModuleStatusCache();

        $this->setUpExtensionProtection();
    }

    /**
     * fixture 를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->tearDownExtensionProtection();

        foreach ([
            $this->modulesPath.'/_bundled/test-core-req-mod',
            $this->pluginsPath.'/_bundled/test-core-req-plg',
            $this->templatesPath.'/_bundled/test-core-req-tpl',
            $this->templatesPath.'/testcorereq-active_tpl',
        ] as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        Cache::flush();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 코어 요구 버전을 가진 매니페스트를 쓴다.
     *
     * @param  string  $path  확장 디렉토리
     * @param  string  $file  매니페스트 파일명
     * @param  array<string, mixed>  $extra  추가 필드
     * @return void 반환값 없음
     */
    private function writeManifest(string $path, string $file, array $extra): void
    {
        $identifier = basename($path);
        File::ensureDirectoryExists($path);
        File::put($path.'/'.$file, json_encode([
            'identifier' => $identifier,
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 '.$identifier, 'en' => 'Test '.$identifier],
            'description' => ['ko' => '코어 요구 버전 테스트', 'en' => 'Core requirement test'],
            'g7_version' => self::REQUIREMENT,
            'dependencies' => [],
        ] + $extra));
    }

    /**
     * 목록 행을 리소스로 바꿔 코어 호환 여부를 읽는다.
     *
     * @param  class-string  $resourceClass  리소스 클래스
     * @param  array<string, mixed>  $row  목록 행
     * @return bool 코어 호환 여부
     */
    private function coreCompatible(string $resourceClass, array $row): bool
    {
        return (new $resourceClass($row))->toArray(Request::create('/'))['core_compatible'];
    }

    /**
     * 미설치 모듈 목록 행은 매니페스트의 코어 요구 버전을 싣고, 리소스는 그 행을 비호환으로 판정한다.
     *
     * @scenario defect=extension_core_incompatible_actions
     *
     * @effects extension_row_exposes_core_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_uninstalled_module_row_carries_core_requirement(): void
    {
        $manager = new ModuleManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            moduleRepository: $this->emptyRepository(ModuleRepositoryInterface::class),
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
        $manager->loadModules();

        $row = $manager->getUninstalledModules()['test-core-req-mod'] ?? null;

        $this->assertIsArray($row);
        $this->assertSame(self::REQUIREMENT, $row['requires_core'] ?? null);
        $this->assertFalse($this->coreCompatible(ModuleResource::class, $row));
    }

    /**
     * 미설치 플러그인 목록 행은 매니페스트의 코어 요구 버전을 싣고, 리소스는 그 행을 비호환으로 판정한다.
     *
     * @scenario defect=extension_core_incompatible_actions
     *
     * @effects extension_row_exposes_core_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_uninstalled_plugin_row_carries_core_requirement(): void
    {
        $manager = new PluginManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            pluginRepository: $this->emptyRepository(PluginRepositoryInterface::class),
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            moduleRepository: Mockery::mock(ModuleRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );
        $manager->loadPlugins();

        $row = $manager->getUninstalledPlugins()['test-core-req-plg'] ?? null;

        $this->assertIsArray($row);
        $this->assertSame(self::REQUIREMENT, $row['requires_core'] ?? null);
        $this->assertFalse($this->coreCompatible(PluginResource::class, $row));
    }

    /**
     * 미설치·설치 템플릿 목록 행 모두 매니페스트의 코어 요구 버전을 싣는다.
     *
     * @scenario defect=extension_core_incompatible_actions
     *
     * @effects extension_row_exposes_core_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_template_rows_carry_core_requirement(): void
    {
        $record = (new Template)->forceFill([
            'identifier' => 'testcorereq-active_tpl',
            'version' => '1.0.0',
            'status' => 'inactive',
            'type' => 'admin',
        ]);
        $templateRepository = Mockery::mock(TemplateRepositoryInterface::class);
        $templateRepository->shouldReceive('getAllKeyedByIdentifier')
            ->andReturn(new EloquentCollection(['testcorereq-active_tpl' => $record]))
            ->byDefault();
        $templateRepository->shouldReceive('findByIdentifier')->andReturn($record)->byDefault();

        $manager = new TemplateManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            templateRepository: $templateRepository,
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            moduleRepository: Mockery::mock(ModuleRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );
        $manager->loadTemplates();

        $uninstalled = $manager->getUninstalledTemplates()['test-core-req-tpl'] ?? null;
        $this->assertIsArray($uninstalled);
        $this->assertSame(self::REQUIREMENT, $uninstalled['requires_core'] ?? null);
        $this->assertFalse($this->coreCompatible(TemplateResource::class, $uninstalled));

        $installed = $manager->getInstalledTemplatesWithDetails()['testcorereq-active_tpl'] ?? null;
        $this->assertIsArray($installed);
        $this->assertSame(self::REQUIREMENT, $installed['requires_core'] ?? null);
        $this->assertFalse($this->coreCompatible(TemplateResource::class, $installed));
    }

    /**
     * 설치된 모듈·플러그인 목록 빌더도 행마다 코어 요구 버전을 싣는다.
     *
     * 설치 경로는 확장 클래스(module.php/plugin.php)가 필요해 fixture 로 만들 수 없어,
     * 빌더 본문이 확장 인스턴스의 getRequiredCoreVersion() 을 행에 담는지 대조한다.
     *
     * @scenario defect=extension_core_incompatible_actions
     *
     * @effects extension_row_exposes_core_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_installed_module_and_plugin_builders_carry_core_requirement(): void
    {
        foreach ([
            [ModuleManager::class, 'getInstalledModulesWithDetails', '$module->getRequiredCoreVersion()'],
            [PluginManager::class, 'getInstalledPluginsWithDetails', '$plugin->getRequiredCoreVersion()'],
        ] as [$class, $method, $expression]) {
            $reflection = new ReflectionMethod($class, $method);
            $lines = file($reflection->getFileName());
            $body = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

            $this->assertStringContainsString("'requires_core' => ".$expression, $body, "{$class}::{$method}");
        }
    }

    /**
     * 확장 레코드가 하나도 없는 저장소 mock 을 만든다.
     *
     * @param  class-string  $interface  저장소 인터페이스
     * @return MockInterface 저장소 mock
     */
    private function emptyRepository(string $interface): MockInterface
    {
        $repository = Mockery::mock($interface);
        $repository->shouldReceive('findByIdentifier')->andReturn(null)->byDefault();
        $repository->shouldReceive('getAllKeyedByIdentifier')->andReturn(collect())->byDefault();

        return $repository;
    }
}
