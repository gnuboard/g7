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
use App\Extension\Helpers\DependencyVersionWarnings;
use App\Extension\Helpers\IntegrationNotices;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Http\Resources\ModuleCollection;
use App\Http\Resources\ModuleResource;
use App\Http\Resources\PluginCollection;
use App\Http\Resources\PluginResource;
use App\Http\Resources\TemplateCollection;
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
 * 확장 목록 행이 **업데이트 대상 판**이 요구하는 의존·연동 중 지금 못 미치는 것을 싣는지 검증
 *
 * 새 판이 연동 확장의 요구 버전을 올리면(예: 템플릿 새 판이 쇼핑 모듈 상위 판을 요구) 업데이트가 끝난 뒤 완료 문구에서야
 * 「연동이 꺼진 것이 있습니다」를 알렸다 — 운영자는 그 템플릿부터 올려 연동 기능이 꺼진 채로 남았다. 목록 행이 업데이트가
 * 있을 때 새 판 manifest 의 `dependencies`·`integrations` 를 지금 설치 상태와 대조한 결과를 싣고, 업데이트 창이 그
 * 목록을 실행 전에 보인다. 판정은 업데이트 뒤 안내와 같은 함수다(같은 manifest 면 같은 결과).
 *
 * 시나리오 매니페스트: tests/scenarios/extension-update-requirement-notices.yaml
 */
class ExtensionListUpdateRequirementNoticesTest extends TestCase
{
    use DatabaseTransactions;
    use ProtectsExtensionDirectories;

    private const TEMPLATE = 'testupdreq-admin_tpl';

    /** 새 판이 요구하는 연동 — 설치됐지만 버전 미달인 모듈 */
    private const LOW_MODULE = 'testupdreq-low_mod';

    /** 새 판이 요구하는 연동 — 설치됐지만 버전 미달인 플러그인 */
    private const LOW_PLUGIN = 'testupdreq-low_plg';

    /** 새 판이 요구하는 연동 — 요구를 충족하는 모듈 (목록에 나오면 안 된다) */
    private const MET_MODULE = 'testupdreq-met_mod';

    /** 새 판이 요구하는 필수 의존 — 설치되지 않은 모듈 */
    private const MISSING_DEPENDENCY = 'testupdreq-missing_dep';

    private string $templatesPath;

    /**
     * 템플릿 fixture(활성 1.0.0 + 같은 식별자의 _bundled 2.0.0)와 연동 대상 설치 레코드를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 단건 업데이트 확인의 GitHub 조회는 실패로 고정한다(네트워크 금지).
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 404)]);

        $this->templatesPath = base_path('templates');
        $this->writeTemplateManifest($this->templatesPath.'/'.self::TEMPLATE, '1.0.0', $this->currentRequirements());
        $this->writeTemplateManifest($this->templatesPath.'/_bundled/'.self::TEMPLATE, '2.0.0', $this->newRequirements());

        Module::factory()->create(['identifier' => self::LOW_MODULE, 'version' => '1.3.3', 'status' => ExtensionStatus::Active->value]);
        Module::factory()->create(['identifier' => self::MET_MODULE, 'version' => '1.2.0', 'status' => ExtensionStatus::Active->value]);
        Plugin::factory()->create(['identifier' => self::LOW_PLUGIN, 'version' => '1.1.2', 'status' => ExtensionStatus::Active->value]);

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
            $this->templatesPath.'/'.self::TEMPLATE,
            $this->templatesPath.'/_bundled/'.self::TEMPLATE,
        ] as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * 지금 설치된 판의 요구 — 지금 설치 상태로 모두 충족한다.
     *
     * @return array{dependencies: array<string, mixed>, integrations: array<string, mixed>} 요구 선언
     */
    private function currentRequirements(): array
    {
        return [
            'dependencies' => ['modules' => [], 'plugins' => []],
            'integrations' => [
                'modules' => [self::LOW_MODULE => '>=1.3.0', self::MET_MODULE => '>=1.2.0'],
                'plugins' => [self::LOW_PLUGIN => '>=1.1.0'],
            ],
        ];
    }

    /**
     * 업데이트 대상 판의 요구 — 연동 두 개의 요구 버전을 올리고 필수 의존 하나를 새로 요구한다.
     *
     * @return array{dependencies: array<string, mixed>, integrations: array<string, mixed>} 요구 선언
     */
    private function newRequirements(): array
    {
        return [
            'dependencies' => ['modules' => [self::MISSING_DEPENDENCY => '>=1.0.0'], 'plugins' => []],
            'integrations' => [
                'modules' => [self::LOW_MODULE => '>=1.3.4', self::MET_MODULE => '>=1.2.0'],
                'plugins' => [self::LOW_PLUGIN => '>=1.2.0'],
            ],
        ];
    }

    /**
     * 템플릿 매니페스트를 쓴다.
     *
     * @param  string  $path  템플릿 디렉토리
     * @param  string  $version  버전
     * @param  array{dependencies: array<string, mixed>, integrations: array<string, mixed>}  $requirements  요구 선언
     * @return void 반환값 없음
     */
    private function writeTemplateManifest(string $path, string $version, array $requirements): void
    {
        File::ensureDirectoryExists($path);
        File::put($path.'/template.json', json_encode([
            'identifier' => basename($path),
            'version' => $version,
            'vendor' => 'test',
            'type' => 'admin',
            'name' => ['ko' => '업데이트 요구 테스트', 'en' => 'Update requirement test'],
            'description' => ['ko' => '업데이트 대상 요구 대조', 'en' => 'Update target requirement check'],
            'g7_version' => '>=1.0.0',
            ...$requirements,
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
        return json_decode(json_encode((new $resourceClass($row))->toArray(Request::create('/'))), true);
    }

    /**
     * 목록 행을 관리자 목록 API 와 같은 경로(컬렉션 → 리소스)로 직렬화한다.
     *
     * 컬렉션은 생성자에서 항목을 이미 리소스로 감싸고 `toArray()` 가 한 번 더 감싸므로, 바깥 리소스의
     * `resource` 는 배열이 아니라 리소스 객체다 — 리소스를 배열로 직접 만드는 {@see serialize()} 로는 이 경로가 잠기지 않는다.
     *
     * @param  class-string  $collectionClass  컬렉션 클래스
     * @param  array<string, mixed>  $row  목록 행
     * @return array<string, mixed> 직렬화된 행
     */
    private function serializeViaCollection(string $collectionClass, array $row): array
    {
        $request = Request::create('/');
        $data = (new $collectionClass(collect([$row])))->toArray($request)['data'];

        return json_decode(json_encode($data), true)[0];
    }

    /**
     * 업데이트 대기 레코드로 보이는 템플릿 매니저를 만든다.
     *
     * @param  string|null  $updateSource  업데이트 출처
     * @param  string  $latestVersion  업데이트 대상 버전
     * @param  bool  $updateAvailable  업데이트 여부
     * @return TemplateManager 템플릿 매니저
     */
    private function templateManager(?string $updateSource, string $latestVersion = '2.0.0', bool $updateAvailable = true): TemplateManager
    {
        $record = (new Template)->forceFill([
            'identifier' => self::TEMPLATE,
            'version' => '1.0.0',
            'status' => 'inactive',
            'type' => 'admin',
            'update_available' => $updateAvailable,
            'latest_version' => $updateAvailable ? $latestVersion : '1.0.0',
            'update_source' => $updateAvailable ? $updateSource : null,
        ]);
        $repository = Mockery::mock(TemplateRepositoryInterface::class);
        $repository->shouldReceive('getAllKeyedByIdentifier')->andReturn(new EloquentCollection([self::TEMPLATE => $record]))->byDefault();
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

        return $manager;
    }

    /**
     * 실제 설치된 모듈(sirsoft-page)을 업데이트 대기 레코드로 보이게 하는 모듈 매니저를 만든다.
     *
     * @param  array<string, mixed>  $record  레코드 속성
     * @param  array<string, mixed>  $bundled  _bundled 매니페스트에 덮어쓸 값
     * @return ModuleManager 모듈 매니저
     */
    private function moduleManager(array $record, array $bundled): ModuleManager
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
     * @param  array<string, mixed>  $bundled  _bundled 매니페스트에 덮어쓸 값
     * @return PluginManager 플러그인 매니저
     */
    private function pluginManager(array $record, array $bundled): PluginManager
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
     * @param  array<string, mixed>  $bundled  넣을 매니페스트 값
     * @return void 반환값 없음
     */
    private function overrideBundled(object $manager, string $property, string $identifier, array $bundled): void
    {
        $reflection = new ReflectionProperty($manager, $property);
        $value = $reflection->getValue($manager);
        $value[$identifier] = $bundled + ($value[$identifier] ?? []);
        $reflection->setValue($manager, $value);
    }

    /**
     * 목록 항목을 식별자로 묶는다.
     *
     * @param  mixed  $items  목록 항목
     * @return array<string, array<string, mixed>> 식별자 → 항목
     */
    private function byIdentifier(mixed $items): array
    {
        $this->assertIsArray($items);

        return array_column($items, null, 'identifier');
    }

    /**
     * 번들 업데이트 대상 판이 연동 요구 버전을 올리면, 템플릿 목록 행이 업데이트 전에 버전 미달 연동과 빠진 필수 의존을 싣는다.
     *
     * @scenario case=list_row_template_bundled_raised_requirements
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_template_row_lists_unmet_requirements_of_bundled_update_target(): void
    {
        $row = $this->serialize(TemplateResource::class, $this->templateManager('bundled')->getInstalledTemplatesWithDetails()[self::TEMPLATE]);

        $this->assertArrayHasKey('update_integration_notices', $row, '업데이트가 있는 행은 새 판 기준 연동 대조 결과를 싣는다.');
        $notices = $this->byIdentifier($row['update_integration_notices']);
        $this->assertSame([self::LOW_MODULE, self::LOW_PLUGIN], array_keys($notices), '충족하는 연동은 싣지 않는다.');
        $this->assertSame('version_mismatch', $notices[self::LOW_MODULE]['state']);
        $this->assertSame('>=1.3.4', $notices[self::LOW_MODULE]['required_version']);
        $this->assertSame('1.3.3', $notices[self::LOW_MODULE]['installed_version']);
        $this->assertSame('module', $notices[self::LOW_MODULE]['type']);
        $this->assertSame('plugin', $notices[self::LOW_PLUGIN]['type']);
        $this->assertSame('>=1.2.0', $notices[self::LOW_PLUGIN]['required_version']);
        $this->assertSame('1.1.2', $notices[self::LOW_PLUGIN]['installed_version']);

        $warnings = $this->byIdentifier($row['update_dependency_warnings'] ?? null);
        $this->assertSame([self::MISSING_DEPENDENCY], array_keys($warnings));
        $this->assertSame('not_installed', $warnings[self::MISSING_DEPENDENCY]['state']);
    }

    /**
     * 업데이트 전 목록과 업데이트 뒤 안내는 같은 판정이다 — 같은 새 판 manifest 면 항목·문구가 같다.
     *
     * @scenario case=list_row_template_bundled_raised_requirements
     *
     * @effects update_target_requirements_match_post_update_notices
     *
     * @return void 반환값 없음
     */
    public function test_template_row_matches_post_update_notices_for_same_manifest(): void
    {
        $row = $this->serialize(TemplateResource::class, $this->templateManager('bundled')->getInstalledTemplatesWithDetails()[self::TEMPLATE]);
        $bundledManifest = $this->templatesPath.'/_bundled/'.self::TEMPLATE.'/template.json';

        $this->assertSame(IntegrationNotices::forManifestFile($bundledManifest), $row['update_integration_notices'] ?? '(없음)');
        $this->assertSame(DependencyVersionWarnings::forManifestFile($bundledManifest), $row['update_dependency_warnings'] ?? '(없음)');
    }

    /**
     * 업데이트가 없으면 목록 행은 업데이트 대상 대조 결과를 싣지 않는다.
     *
     * @scenario case=list_row_template_without_update
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_template_row_without_update_omits_requirement_fields(): void
    {
        $row = $this->serialize(TemplateResource::class, $this->templateManager(null, '1.0.0', false)->getInstalledTemplatesWithDetails()[self::TEMPLATE]);

        $this->assertArrayNotHasKey('update_integration_notices', $row);
        $this->assertArrayNotHasKey('update_dependency_warnings', $row);
    }

    /**
     * GitHub 출처이고 _bundled 판이 업데이트 대상과 다르면 대상 판 manifest 를 알 수 없다 — null(빈 목록과 구분).
     *
     * @scenario case=list_row_template_github_unknown_manifest
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_template_row_with_github_source_and_other_bundled_version_is_unknown(): void
    {
        $row = $this->serialize(TemplateResource::class, $this->templateManager('github', '3.0.0')->getInstalledTemplatesWithDetails()[self::TEMPLATE]);

        $this->assertArrayHasKey('update_integration_notices', $row);
        $this->assertNull($row['update_integration_notices']);
        $this->assertNull($row['update_dependency_warnings']);
    }

    /**
     * GitHub 출처라도 _bundled 판이 업데이트 대상과 같은 버전이면 그 manifest 로 대조한다(같은 판이다).
     *
     * @scenario case=list_row_template_github_same_bundled_version
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_template_row_with_github_source_uses_bundled_manifest_of_same_version(): void
    {
        $row = $this->serialize(TemplateResource::class, $this->templateManager('github', '2.0.0')->getInstalledTemplatesWithDetails()[self::TEMPLATE]);

        $this->assertSame([self::LOW_MODULE, self::LOW_PLUGIN], array_keys($this->byIdentifier($row['update_integration_notices'] ?? null)));
    }

    /**
     * 모듈 목록 행도 번들 업데이트 대상 판의 연동 요구를 대조해 버전 미달을 싣는다.
     *
     * @scenario case=list_row_module_bundled_raised_requirements
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_module_row_lists_unmet_integrations_of_bundled_update_target(): void
    {
        $manager = $this->moduleManager(
            ['version' => '1.0.0', 'update_available' => true, 'latest_version' => '9.0.0', 'update_source' => 'bundled'],
            ['version' => '9.0.0', ...$this->newRequirements()],
        );

        $row = $this->serialize(ModuleResource::class, $manager->getInstalledModulesWithDetails()['sirsoft-page']);

        $this->assertSame([self::LOW_MODULE, self::LOW_PLUGIN], array_keys($this->byIdentifier($row['update_integration_notices'] ?? null)));
        $this->assertSame([self::MISSING_DEPENDENCY], array_keys($this->byIdentifier($row['update_dependency_warnings'] ?? null)));
    }

    /**
     * 플러그인 목록 행도 번들 업데이트 대상 판의 연동 요구를 대조해 버전 미달을 싣는다.
     *
     * @scenario case=list_row_plugin_bundled_raised_requirements
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_plugin_row_lists_unmet_integrations_of_bundled_update_target(): void
    {
        $manager = $this->pluginManager(
            ['version' => '1.0.0', 'update_available' => true, 'latest_version' => '9.0.0', 'update_source' => 'bundled'],
            ['version' => '9.0.0', ...$this->newRequirements()],
        );

        $row = $this->serialize(PluginResource::class, $manager->getInstalledPluginsWithDetails()['sirsoft-daum_postcode']);

        $this->assertSame([self::LOW_MODULE, self::LOW_PLUGIN], array_keys($this->byIdentifier($row['update_integration_notices'] ?? null)));
        $this->assertSame([self::MISSING_DEPENDENCY], array_keys($this->byIdentifier($row['update_dependency_warnings'] ?? null)));
    }

    /**
     * 새 판의 요구를 지금 설치 상태가 모두 충족하면 빈 목록이다(창에 경고가 뜨지 않는다).
     *
     * @scenario case=list_row_plugin_bundled_requirements_met
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_plugin_row_with_met_requirements_has_empty_lists(): void
    {
        $manager = $this->pluginManager(
            ['version' => '1.0.0', 'update_available' => true, 'latest_version' => '9.0.0', 'update_source' => 'bundled'],
            ['version' => '9.0.0', ...$this->currentRequirements()],
        );

        $row = $this->serialize(PluginResource::class, $manager->getInstalledPluginsWithDetails()['sirsoft-daum_postcode']);

        $this->assertSame([], $row['update_integration_notices'] ?? '(없음)');
        $this->assertSame([], $row['update_dependency_warnings'] ?? '(없음)');
    }

    /**
     * 관리자 템플릿 목록 API 가 쓰는 컬렉션 경로로 직렬화해도 업데이트 대상 판의 요구 대조 결과가 실린다.
     *
     * @scenario case=list_row_template_bundled_raised_requirements
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_template_collection_path_keeps_update_target_requirements(): void
    {
        $row = $this->serializeViaCollection(TemplateCollection::class, $this->templateManager('bundled')->getInstalledTemplatesWithDetails()[self::TEMPLATE]);

        $this->assertSame([self::LOW_MODULE, self::LOW_PLUGIN], array_keys($this->byIdentifier($row['update_integration_notices'] ?? null)));
        $this->assertSame([self::MISSING_DEPENDENCY], array_keys($this->byIdentifier($row['update_dependency_warnings'] ?? null)));
    }

    /**
     * 관리자 모듈 목록 API 의 컬렉션 경로로 직렬화해도 대조 결과가 실린다.
     *
     * @scenario case=list_row_module_bundled_raised_requirements
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_module_collection_path_keeps_update_target_requirements(): void
    {
        $manager = $this->moduleManager(
            ['version' => '1.0.0', 'update_available' => true, 'latest_version' => '9.0.0', 'update_source' => 'bundled'],
            ['version' => '9.0.0', ...$this->newRequirements()],
        );

        $row = $this->serializeViaCollection(ModuleCollection::class, $manager->getInstalledModulesWithDetails()['sirsoft-page']);

        $this->assertSame([self::LOW_MODULE, self::LOW_PLUGIN], array_keys($this->byIdentifier($row['update_integration_notices'] ?? null)));
        $this->assertSame([self::MISSING_DEPENDENCY], array_keys($this->byIdentifier($row['update_dependency_warnings'] ?? null)));
    }

    /**
     * 관리자 플러그인 목록 API 의 컬렉션 경로로 직렬화해도 대조 결과가 실린다.
     *
     * @scenario case=list_row_plugin_bundled_raised_requirements
     *
     * @effects extension_list_row_exposes_update_target_requirements
     *
     * @return void 반환값 없음
     */
    public function test_plugin_collection_path_keeps_update_target_requirements(): void
    {
        $manager = $this->pluginManager(
            ['version' => '1.0.0', 'update_available' => true, 'latest_version' => '9.0.0', 'update_source' => 'bundled'],
            ['version' => '9.0.0', ...$this->newRequirements()],
        );

        $row = $this->serializeViaCollection(PluginCollection::class, $manager->getInstalledPluginsWithDetails()['sirsoft-daum_postcode']);

        $this->assertSame([self::LOW_MODULE, self::LOW_PLUGIN], array_keys($this->byIdentifier($row['update_integration_notices'] ?? null)));
        $this->assertSame([self::MISSING_DEPENDENCY], array_keys($this->byIdentifier($row['update_dependency_warnings'] ?? null)));
    }
}
