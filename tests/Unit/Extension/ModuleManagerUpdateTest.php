<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Models\Module;
use App\Services\LayoutExtensionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * ModuleManager 업데이트 관련 메서드 테스트
 *
 * checkModuleUpdate, checkAllModulesForUpdates, pending/bundled 로딩,
 * getUninstalledModules, getInstalledModulesWithDetails 확장 필드를 검증합니다.
 */
class ModuleManagerUpdateTest extends TestCase
{
    private string $modulesPath;

    private ModuleRepositoryInterface|Mockery\MockInterface $moduleRepository;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesPath = base_path('modules');

        $this->moduleRepository = Mockery::mock(ModuleRepositoryInterface::class);

        $this->manager = new ModuleManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            moduleRepository: $this->moduleRepository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            menuRepository: Mockery::mock(MenuRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
        );

        // _pending 테스트 모듈 디렉토리 생성
        $pendingPath = $this->modulesPath.'/_pending/test-update-mod';
        File::ensureDirectoryExists($pendingPath);
        File::put($pendingPath.'/module.json', json_encode([
            'identifier' => 'test-update-mod',
            'version' => '2.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '업데이트 테스트 모듈', 'en' => 'Update Test Module'],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'dependencies' => [],
        ]));

        // _bundled 테스트 모듈 디렉토리 생성
        $bundledPath = $this->modulesPath.'/_bundled/test-bundled-mod';
        File::ensureDirectoryExists($bundledPath);
        File::put($bundledPath.'/module.json', json_encode([
            'identifier' => 'test-bundled-mod',
            'version' => '1.5.0',
            'vendor' => 'test',
            'name' => ['ko' => '번들 테스트 모듈', 'en' => 'Bundled Test Module'],
            'description' => ['ko' => '번들용', 'en' => 'For bundled'],
            'dependencies' => [],
        ]));
    }

    protected function tearDown(): void
    {
        // 테스트 디렉토리 정리
        $paths = [
            $this->modulesPath.'/_pending/test-update-mod',
            $this->modulesPath.'/_bundled/test-bundled-mod',
            $this->modulesPath.'/test-update-mod',
            $this->modulesPath.'/test-bundled-mod',
        ];

        foreach ($paths as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Module mock을 생성합니다.
     *
     * @param  array  $attributes  모델 속성
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
     * loadPendingModules가 _pending의 메타데이터를 올바르게 로드하는지 테스트
     */
    public function test_load_pending_modules_populates_pending_array(): void
    {
        // loadModules() 호출 시 _pending 모듈 로드됨
        $this->manager->loadModules();

        $pending = $this->manager->getPendingModules();

        $this->assertArrayHasKey('test-update-mod', $pending);
        $this->assertEquals('2.0.0', $pending['test-update-mod']['version']);
        $this->assertEquals('test', $pending['test-update-mod']['vendor']);
    }

    /**
     * loadBundledModules가 _bundled의 메타데이터를 올바르게 로드하는지 테스트
     */
    public function test_load_bundled_modules_populates_bundled_array(): void
    {
        $this->manager->loadModules();

        $bundled = $this->manager->getBundledModules();

        $this->assertArrayHasKey('test-bundled-mod', $bundled);
        $this->assertEquals('1.5.0', $bundled['test-bundled-mod']['version']);
    }

    /**
     * checkModuleUpdate가 _pending은 업데이트 감지에 사용하지 않는지 테스트
     * (_pending은 임시 스테이징 디렉토리이므로 버전 비교 대상이 아님)
     */
    public function test_check_module_update_does_not_use_pending_for_version_check(): void
    {
        $this->manager->loadModules();

        $record = $this->createModuleMock([
            'identifier' => 'test-update-mod',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'update_available' => false,
            'latest_version' => null,
            'update_source' => null,
            'github_changelog_url' => null,
        ]);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-update-mod')
            ->andReturn($record);

        $result = $this->manager->checkModuleUpdate('test-update-mod');

        // _pending에 v2.0.0이 있어도 업데이트로 감지하지 않음
        $this->assertFalse($result['update_available']);
        $this->assertNull($result['update_source']);
    }

    /**
     * checkModuleUpdate가 _bundled에서 업데이트를 감지하는지 테스트
     */
    public function test_check_module_update_detects_bundled_update(): void
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

    /**
     * checkModuleUpdate가 미설치 모듈에 대해 업데이트 없음을 반환하는지 테스트
     */
    public function test_check_module_update_returns_false_when_not_installed(): void
    {
        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('nonexistent-mod')
            ->andReturn(null);

        $result = $this->manager->checkModuleUpdate('nonexistent-mod');

        $this->assertFalse($result['update_available']);
        $this->assertNull($result['update_source']);
    }

    /**
     * checkModuleUpdate가 이미 최신 버전이면 업데이트 없음을 반환하는지 테스트
     */
    public function test_check_module_update_returns_false_when_already_latest(): void
    {
        $this->manager->loadModules();

        $record = $this->createModuleMock([
            'identifier' => 'test-update-mod',
            'version' => '2.0.0', // _pending과 동일 버전
            'status' => ExtensionStatus::Active->value,
            'update_available' => false,
            'latest_version' => null,
            'update_source' => null,
            'github_changelog_url' => null,
        ]);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-update-mod')
            ->andReturn($record);

        $result = $this->manager->checkModuleUpdate('test-update-mod');

        $this->assertFalse($result['update_available']);
    }

    /**
     * checkAllModulesForUpdates가 여러 모듈을 검사하고 DB를 갱신하는지 테스트
     */
    public function test_check_all_modules_for_updates_scans_and_updates_db(): void
    {
        $this->manager->loadModules();

        $recordUpdate = $this->createModuleMock([
            'identifier' => 'test-update-mod',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'update_available' => false,
            'latest_version' => null,
            'update_source' => null,
            'github_changelog_url' => null,
        ]);

        $recordBundled = $this->createModuleMock([
            'identifier' => 'test-bundled-mod',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'update_available' => false,
            'latest_version' => null,
            'update_source' => null,
            'github_changelog_url' => null,
        ]);

        $records = new EloquentCollection([
            'test-update-mod' => $recordUpdate,
            'test-bundled-mod' => $recordBundled,
        ]);

        $this->moduleRepository->shouldReceive('getAllKeyedByIdentifier')
            ->once()
            ->andReturn($records);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-update-mod')
            ->andReturn($recordUpdate);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-bundled-mod')
            ->andReturn($recordBundled);

        $this->moduleRepository->shouldReceive('updateByIdentifier')
            ->times(2);

        $result = $this->manager->checkAllModulesForUpdates();

        // _pending은 업데이트 감지에 사용하지 않으므로 _bundled만 업데이트로 감지됨
        $this->assertEquals(1, $result['updated_count']);
        $this->assertCount(1, $result['details']);
        $this->assertEquals('test-bundled-mod', $result['details'][0]['identifier']);
    }

    /**
     * getUninstalledModules가 pending/bundled 모듈을 is_pending/is_bundled 필드와 함께 포함하는지 테스트
     */
    public function test_get_uninstalled_modules_includes_pending_and_bundled(): void
    {
        $this->manager->loadModules();

        // getUninstalledModules 내부에서 의존성 해석 시 findByIdentifier가 호출될 수 있음
        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->andReturn(null);

        $result = $this->manager->getUninstalledModules();

        // pending 모듈 확인
        $this->assertArrayHasKey('test-update-mod', $result);
        $this->assertTrue($result['test-update-mod']['is_pending']);
        $this->assertFalse($result['test-update-mod']['is_bundled']);

        // bundled 모듈 확인
        $this->assertArrayHasKey('test-bundled-mod', $result);
        $this->assertFalse($result['test-bundled-mod']['is_pending']);
        $this->assertTrue($result['test-bundled-mod']['is_bundled']);
    }

    /**
     * copyFromPendingOrBundled가 _pending에서 활성 디렉토리로 올바르게 복사하는지 테스트
     */
    public function test_copy_from_pending_copies_to_active(): void
    {
        $method = new \ReflectionMethod($this->manager, 'copyFromPendingOrBundled');
        $method->setAccessible(true);

        $activePath = $this->modulesPath.'/test-update-mod';
        $this->assertFalse(File::isDirectory($activePath));

        $method->invoke($this->manager, 'test-update-mod');

        $this->assertTrue(File::isDirectory($activePath));
        $this->assertTrue(File::exists($activePath.'/module.json'));
    }

    /**
     * copyFromPendingOrBundled가 이미 활성 디렉토리에 존재하면 복사하지 않는지 테스트
     */
    public function test_copy_from_pending_skips_when_active_exists(): void
    {
        $activePath = $this->modulesPath.'/test-update-mod';
        File::ensureDirectoryExists($activePath);
        File::put($activePath.'/existing.txt', 'original');

        $method = new \ReflectionMethod($this->manager, 'copyFromPendingOrBundled');
        $method->setAccessible(true);

        $method->invoke($this->manager, 'test-update-mod');

        // 기존 파일이 유지됨 (복사가 이루어지지 않았음)
        $this->assertTrue(File::exists($activePath.'/existing.txt'));
        $this->assertEquals('original', File::get($activePath.'/existing.txt'));
    }

    /**
     * copyFromPendingOrBundled가 _pending에 없으면 _bundled에서 복사하는지 테스트
     */
    public function test_copy_from_bundled_as_fallback(): void
    {
        $method = new \ReflectionMethod($this->manager, 'copyFromPendingOrBundled');
        $method->setAccessible(true);

        $activePath = $this->modulesPath.'/test-bundled-mod';
        $this->assertFalse(File::isDirectory($activePath));

        $method->invoke($this->manager, 'test-bundled-mod');

        $this->assertTrue(File::isDirectory($activePath));
        $this->assertTrue(File::exists($activePath.'/module.json'));
    }

    /**
     * 상태 가드가 updating 상태에서 updateModule을 차단하는지 테스트
     */
    public function test_update_module_blocked_when_status_is_updating(): void
    {
        $record = $this->createModuleMock([
            'identifier' => 'test-update-mod',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Updating->value,
        ]);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-update-mod')
            ->andReturn($record);

        $this->expectException(\RuntimeException::class);

        $this->manager->updateModule('test-update-mod');
    }

    /**
     * 상태 가드가 installing 상태에서 installModule을 차단하는지 테스트
     */
    public function test_install_module_blocked_when_status_is_installing(): void
    {
        $record = $this->createModuleMock([
            'identifier' => 'test-update-mod',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Installing->value,
        ]);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-update-mod')
            ->andReturn($record);

        $this->expectException(\RuntimeException::class);

        $this->manager->installModule('test-update-mod');
    }

    /**
     * checkModuleUpdate가 업데이트 없을 때 update_available=false를 반환하고,
     * updateModule(force: false)는 이를 기반으로 조기 반환하는지 간접 테스트
     */
    public function test_check_module_update_no_update_and_force_false_early_return(): void
    {
        $this->manager->loadModules();

        $record = $this->createModuleMock([
            'identifier' => 'test-update-mod',
            'version' => '2.0.0',
            'status' => ExtensionStatus::Active->value,
            'update_available' => false,
            'latest_version' => null,
            'update_source' => null,
            'github_changelog_url' => null,
        ]);

        $this->moduleRepository->shouldReceive('findByIdentifier')
            ->with('test-update-mod')
            ->andReturn($record);

        // checkModuleUpdate 결과 확인: 업데이트 없음
        $checkResult = $this->manager->checkModuleUpdate('test-update-mod');
        $this->assertFalse($checkResult['update_available']);
        $this->assertNull($checkResult['update_source']);
        $this->assertEquals('2.0.0', $checkResult['current_version']);
    }

    /**
     * resolveForceUpdateSource가 _bundled에 존재하는 모듈에 대해 'bundled'를 반환하는지 테스트
     */
    public function test_resolve_force_update_source_returns_bundled(): void
    {
        $this->manager->loadModules();

        $method = new \ReflectionMethod($this->manager, 'resolveForceUpdateSource');
        $method->setAccessible(true);

        $result = $method->invoke($this->manager, 'test-bundled-mod');

        $this->assertEquals('bundled', $result);
    }

    /**
     * resolveForceUpdateSource가 _bundled에 없는 모듈에 대해 null을 반환하는지 테스트
     */
    public function test_resolve_force_update_source_returns_null_when_no_source(): void
    {
        $this->manager->loadModules();

        $method = new \ReflectionMethod($this->manager, 'resolveForceUpdateSource');
        $method->setAccessible(true);

        $result = $method->invoke($this->manager, 'nonexistent-mod');

        $this->assertNull($result);
    }
}
