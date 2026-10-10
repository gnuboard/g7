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
use App\Extension\CoreVersionChecker;
use App\Extension\ExtensionManager;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Http\Resources\ModuleResource;
use App\Http\Resources\PluginResource;
use App\Http\Resources\TemplateResource;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionProperty;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

/**
 * 확장 목록 행이 **업데이트 대상 버전**의 코어 호환 정보를 싣는지 검증 (F-D4)
 *
 * 업데이트 창의 코어 호환 경고·「강제 업데이트」 체크는 목록 행의 `is_compatible`·
 * `required_core_version`·`current_core_version` 을 읽는다. 목록 응답이 이 셋을 싣지 않아
 * 새 버전이 코어를 만족하지 않아도 경고 없이 업데이트를 눌러 서버 422 로 끝났다.
 * 업데이트가 있을 때만 싣고, 값은 단건 업데이트 확인(check*Update)과 같은 규칙으로 정한다 —
 * 번들 출처면 _bundled 매니페스트의 g7_version, GitHub 출처면 활성 매니페스트의 g7_version.
 *
 * 시나리오 매니페스트: tests/scenarios/extension-update-core-compat-warning.yaml
 */
class ExtensionListUpdateCompatibilityTest extends TestCase
{
    use DatabaseTransactions;
    use ProtectsExtensionDirectories;

    private const REQUIREMENT = '>=999.0.0';

    private string $templatesPath;

    /**
     * 템플릿 fixture(활성 + 같은 식별자의 _bundled 새 버전)를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 단건 업데이트 확인의 GitHub 조회는 실패로 고정한다(네트워크 금지 — _bundled 폴백 경로를 탄다).
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 404)]);

        $this->templatesPath = base_path('templates');
        $this->writeTemplateManifest($this->templatesPath.'/testupdcompat-admin_tpl', '1.0.0', '>=1.0.0');
        $this->writeTemplateManifest($this->templatesPath.'/_bundled/testupdcompat-admin_tpl', '2.0.0', self::REQUIREMENT);

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
            $this->templatesPath.'/testupdcompat-admin_tpl',
            $this->templatesPath.'/_bundled/testupdcompat-admin_tpl',
        ] as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * 템플릿 매니페스트를 쓴다.
     *
     * @param  string  $path  템플릿 디렉토리
     * @param  string  $version  버전
     * @param  string  $requirement  코어 요구 버전
     * @return void 반환값 없음
     */
    private function writeTemplateManifest(string $path, string $version, string $requirement): void
    {
        File::ensureDirectoryExists($path);
        File::put($path.'/template.json', json_encode([
            'identifier' => basename($path),
            'version' => $version,
            'vendor' => 'test',
            'type' => 'admin',
            'name' => ['ko' => '업데이트 호환 테스트', 'en' => 'Update compat test'],
            'description' => ['ko' => '업데이트 대상 코어 요구', 'en' => 'Update target core requirement'],
            'g7_version' => $requirement,
            'dependencies' => [],
        ]));
    }

    /**
     * 목록 행을 리소스로 바꾼다.
     *
     * @param  class-string  $resourceClass  리소스 클래스
     * @param  array<string, mixed>  $row  목록 행
     * @return array<string, mixed> 직렬화된 행
     */
    private function serialize(string $resourceClass, array $row): array
    {
        return (new $resourceClass($row))->toArray(Request::create('/'));
    }

    /**
     * 실제 설치된 모듈(sirsoft-page)을 업데이트 대기 레코드로 보이게 하는 모듈 매니저를 만든다.
     *
     * @param  array<string, mixed>  $record  레코드 속성
     * @param  array<string, mixed>|null  $bundled  _bundled 매니페스트(없으면 null)
     * @return ModuleManager 모듈 매니저
     */
    private function moduleManager(array $record, ?array $bundled): ModuleManager
    {
        $model = (new Module)->forceFill(['identifier' => 'sirsoft-page', 'status' => 'active'] + $record);
        $repository = Mockery::mock(ModuleRepositoryInterface::class);
        $repository->shouldReceive('getAllKeyedByIdentifier')->andReturn(new EloquentCollection(['sirsoft-page' => $model]))->byDefault();
        $repository->shouldReceive('findByIdentifier')->andReturn($model)->byDefault();

        $manager = new ModuleManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            moduleRepository: $repository,
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
        $this->overrideBundled($manager, 'bundledModules', 'sirsoft-page', $bundled);

        return $manager;
    }

    /**
     * 실제 설치된 플러그인(sirsoft-daum_postcode)을 업데이트 대기 레코드로 보이게 하는 플러그인 매니저를 만든다.
     *
     * @param  array<string, mixed>  $record  레코드 속성
     * @param  array<string, mixed>|null  $bundled  _bundled 매니페스트(없으면 null)
     * @return PluginManager 플러그인 매니저
     */
    private function pluginManager(array $record, ?array $bundled): PluginManager
    {
        $model = (new Plugin)->forceFill(['identifier' => 'sirsoft-daum_postcode', 'status' => 'active'] + $record);
        $repository = Mockery::mock(PluginRepositoryInterface::class);
        $repository->shouldReceive('getAllKeyedByIdentifier')->andReturn(new EloquentCollection(['sirsoft-daum_postcode' => $model]))->byDefault();
        $repository->shouldReceive('findByIdentifier')->andReturn($model)->byDefault();

        $manager = new PluginManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            pluginRepository: $repository,
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
        $this->overrideBundled($manager, 'bundledPlugins', 'sirsoft-daum_postcode', $bundled);

        return $manager;
    }

    /**
     * 매니저가 읽어 둔 _bundled 매니페스트를 바꿔 넣는다(실제 _bundled 파일은 건드리지 않는다).
     *
     * @param  object  $manager  매니저
     * @param  string  $property  _bundled 메타 속성 이름
     * @param  string  $identifier  확장 식별자
     * @param  array<string, mixed>|null  $bundled  넣을 매니페스트(null 이면 그대로)
     * @return void 반환값 없음
     */
    private function overrideBundled(object $manager, string $property, string $identifier, ?array $bundled): void
    {
        if ($bundled === null) {
            return;
        }

        $reflection = new ReflectionProperty($manager, $property);
        $value = $reflection->getValue($manager);
        $value[$identifier] = $bundled + ($value[$identifier] ?? []);
        $reflection->setValue($manager, $value);
    }

    /**
     * 번들 업데이트 대상이 코어를 만족하지 못하면 모듈 목록 행이 비호환·요구 버전·현재 코어를 싣는다.
     *
     * @scenario case=list_row_module_bundled_incompatible
     *
     * @effects extension_list_row_exposes_update_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_module_row_carries_bundled_update_target_compatibility(): void
    {
        $manager = $this->moduleManager(
            ['version' => '1.0.0', 'update_available' => true, 'latest_version' => '9.0.0', 'update_source' => 'bundled'],
            ['version' => '9.0.0', 'g7_version' => self::REQUIREMENT],
        );

        $row = $this->serialize(ModuleResource::class, $manager->getInstalledModulesWithDetails()['sirsoft-page']);

        $this->assertFalse($row['is_compatible'] ?? null);
        $this->assertSame(self::REQUIREMENT, $row['required_core_version'] ?? null);
        $this->assertSame(CoreVersionChecker::getCoreVersion(), $row['current_core_version'] ?? null);
        $this->assertTrue($row['core_compatible'], '설치본 호환(core_compatible)은 업데이트 대상과 별개다.');
    }

    /**
     * 목록 행의 호환 정보는 단건 업데이트 확인(checkModuleUpdate)과 같은 값이다.
     *
     * @scenario case=list_row_module_bundled_incompatible
     *
     * @effects extension_list_row_exposes_update_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_module_row_matches_single_update_check(): void
    {
        $manager = $this->moduleManager(
            ['version' => '1.0.0', 'update_available' => true, 'latest_version' => '9.0.0', 'update_source' => 'bundled'],
            ['version' => '9.0.0', 'g7_version' => self::REQUIREMENT],
        );

        $row = $this->serialize(ModuleResource::class, $manager->getInstalledModulesWithDetails()['sirsoft-page']);
        $single = $manager->checkModuleUpdate('sirsoft-page');

        foreach (['is_compatible', 'required_core_version', 'current_core_version'] as $key) {
            $this->assertSame($single[$key], $row[$key] ?? '(없음)', $key);
        }
    }

    /**
     * 업데이트가 없으면 목록 행은 업데이트 호환 정보를 싣지 않는다(설치본 판정은 core_compatible).
     *
     * @scenario case=list_row_module_without_update
     *
     * @effects extension_list_row_exposes_update_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_module_row_without_update_omits_update_compatibility(): void
    {
        $manager = $this->moduleManager(
            ['version' => '1.2.0', 'update_available' => false, 'latest_version' => '1.2.0', 'update_source' => null],
            null,
        );

        $row = $this->serialize(ModuleResource::class, $manager->getInstalledModulesWithDetails()['sirsoft-page']);

        $this->assertArrayNotHasKey('is_compatible', $row);
        $this->assertArrayNotHasKey('required_core_version', $row);
        $this->assertArrayNotHasKey('current_core_version', $row);
    }

    /**
     * GitHub 출처 업데이트는 단건 확인과 같이 활성 매니페스트의 요구 버전으로 판정한다(원격 매니페스트를 받지 않는다).
     *
     * @scenario case=list_row_plugin_github_source
     *
     * @effects extension_list_row_exposes_update_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_plugin_row_with_github_source_uses_active_requirement(): void
    {
        $manager = $this->pluginManager(
            ['version' => '1.0.0', 'update_available' => true, 'latest_version' => '9.0.0', 'update_source' => 'github'],
            ['version' => '9.0.0', 'g7_version' => self::REQUIREMENT],
        );

        $row = $this->serialize(PluginResource::class, $manager->getInstalledPluginsWithDetails()['sirsoft-daum_postcode']);
        $active = $manager->getPlugin('sirsoft-daum_postcode')->getRequiredCoreVersion();

        $this->assertSame($active, $row['required_core_version'] ?? '(없음)');
        $this->assertSame(CoreVersionChecker::isCompatible($active), $row['is_compatible'] ?? null);
    }

    /**
     * 번들 업데이트 대상이 코어를 만족하지 못하면 플러그인 목록 행이 비호환을 싣는다.
     *
     * @scenario case=list_row_plugin_bundled_incompatible
     *
     * @effects extension_list_row_exposes_update_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_plugin_row_carries_bundled_update_target_compatibility(): void
    {
        $manager = $this->pluginManager(
            ['version' => '1.0.0', 'update_available' => true, 'latest_version' => '9.0.0', 'update_source' => 'bundled'],
            ['version' => '9.0.0', 'g7_version' => self::REQUIREMENT],
        );

        $row = $this->serialize(PluginResource::class, $manager->getInstalledPluginsWithDetails()['sirsoft-daum_postcode']);

        $this->assertFalse($row['is_compatible'] ?? null);
        $this->assertSame(self::REQUIREMENT, $row['required_core_version'] ?? null);
        $this->assertSame(CoreVersionChecker::getCoreVersion(), $row['current_core_version'] ?? null);
    }

    /**
     * 번들 업데이트 대상이 코어를 만족하지 못하면 템플릿 목록 행이 비호환을 싣는다.
     *
     * @scenario case=list_row_template_bundled_incompatible
     *
     * @effects extension_list_row_exposes_update_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_template_row_carries_bundled_update_target_compatibility(): void
    {
        $record = (new Template)->forceFill([
            'identifier' => 'testupdcompat-admin_tpl',
            'version' => '1.0.0',
            'status' => 'inactive',
            'type' => 'admin',
            'update_available' => true,
            'latest_version' => '2.0.0',
            'update_source' => 'bundled',
        ]);
        $repository = Mockery::mock(TemplateRepositoryInterface::class);
        $repository->shouldReceive('getAllKeyedByIdentifier')->andReturn(new EloquentCollection(['testupdcompat-admin_tpl' => $record]))->byDefault();
        $repository->shouldReceive('findByIdentifier')->andReturn($record)->byDefault();

        $manager = new TemplateManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            templateRepository: $repository,
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            moduleRepository: Mockery::mock(ModuleRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );
        $manager->loadTemplates();

        $row = $this->serialize(TemplateResource::class, $manager->getInstalledTemplatesWithDetails()['testupdcompat-admin_tpl']);

        $this->assertFalse($row['is_compatible'] ?? null);
        $this->assertSame(self::REQUIREMENT, $row['required_core_version'] ?? null);
        $this->assertSame(CoreVersionChecker::getCoreVersion(), $row['current_core_version'] ?? null);
        $this->assertTrue($row['core_compatible'], '활성 매니페스트(>=1.0.0)는 코어를 만족한다.');
    }
}
