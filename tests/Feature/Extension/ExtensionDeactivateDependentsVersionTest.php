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
use App\Models\Module;
use App\Models\Plugin;
use App\Services\LayoutExtensionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * 비활성화 경고(409)의 의존 확장 목록이 버전을 싣는지 검증 (Q-G2 Hello 실측)
 *
 * 경고 창은 의존하는 모듈·플러그인마다 「v{버전}」 배지를 그리는데, 서버는 식별자와 이름만 실어
 * 실제 화면에 「Hello 플러그인 v」 처럼 빈 버전이 보였다(실측: Hello 플러그인이 켜진 채 Hello 모듈 끄기).
 *
 * 시나리오 매니페스트: tests/scenarios/extension-deactivate-dependents-version.yaml
 */
class ExtensionDeactivateDependentsVersionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 테스트 후 mock 을 닫는다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 의존 확장 모델 두 개(모듈·플러그인)를 만든다.
     *
     * @return array{0: EloquentCollection, 1: EloquentCollection} [모듈 목록, 플러그인 목록]
     */
    private function dependents(): array
    {
        $module = (new Module)->forceFill(['identifier' => 'acme-dep_module', 'name' => ['ko' => '의존 모듈', 'en' => 'Dep Module'], 'version' => '1.4.0', 'status' => 'active']);
        $plugin = (new Plugin)->forceFill(['identifier' => 'acme-dep_plugin', 'name' => ['ko' => '의존 플러그인', 'en' => 'Dep Plugin'], 'version' => '0.2.0', 'status' => 'active']);

        return [new EloquentCollection([$module]), new EloquentCollection([$plugin])];
    }

    /**
     * 모듈 비활성화 경고의 의존 모듈·플러그인에 버전이 실린다.
     *
     * @scenario case=module_deactivate_warning
     *
     * @effects deactivate_warning_dependents_carry_version
     *
     * @return void 반환값 없음
     */
    public function test_module_deactivate_warning_carries_dependent_versions(): void
    {
        [$modules, $plugins] = $this->dependents();
        $record = (new Module)->forceFill(['identifier' => 'sirsoft-page', 'status' => 'active', 'version' => '1.2.0']);

        $moduleRepository = Mockery::mock(ModuleRepositoryInterface::class);
        $moduleRepository->shouldReceive('findByIdentifier')->andReturn($record);
        $moduleRepository->shouldReceive('getAllKeyedByIdentifier')->andReturn(new EloquentCollection(['sirsoft-page' => $record]));
        $moduleRepository->shouldReceive('findActiveByModuleDependency')->andReturn($modules);
        $templateRepository = Mockery::mock(TemplateRepositoryInterface::class);
        $templateRepository->shouldReceive('findActiveByModuleDependency')->andReturn(new EloquentCollection);
        $pluginRepository = Mockery::mock(PluginRepositoryInterface::class);
        $pluginRepository->shouldReceive('findActiveByModuleDependency')->andReturn($plugins);

        $manager = new ModuleManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            moduleRepository: $moduleRepository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            menuRepository: Mockery::mock(MenuRepositoryInterface::class),
            templateRepository: $templateRepository,
            pluginRepository: $pluginRepository,
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );
        $manager->loadModules();

        $result = $manager->deactivateModule('sirsoft-page');

        $this->assertTrue($result['warning'] ?? false);
        $this->assertSame('1.4.0', $result['dependent_modules'][0]['version'] ?? null);
        $this->assertSame('0.2.0', $result['dependent_plugins'][0]['version'] ?? null);
    }

    /**
     * 플러그인 비활성화 경고의 의존 모듈·플러그인에 버전이 실린다.
     *
     * @scenario case=plugin_deactivate_warning
     *
     * @effects deactivate_warning_dependents_carry_version
     *
     * @return void 반환값 없음
     */
    public function test_plugin_deactivate_warning_carries_dependent_versions(): void
    {
        [$modules, $plugins] = $this->dependents();
        $record = (new Plugin)->forceFill(['identifier' => 'sirsoft-daum_postcode', 'status' => 'active', 'version' => '1.1.0']);

        $pluginRepository = Mockery::mock(PluginRepositoryInterface::class);
        $pluginRepository->shouldReceive('findByIdentifier')->andReturn($record);
        $pluginRepository->shouldReceive('getAllKeyedByIdentifier')->andReturn(new EloquentCollection(['sirsoft-daum_postcode' => $record]));
        $pluginRepository->shouldReceive('findActiveByPluginDependency')->andReturn($plugins);
        $moduleRepository = Mockery::mock(ModuleRepositoryInterface::class);
        $moduleRepository->shouldReceive('findActiveByPluginDependency')->andReturn($modules);
        $templateRepository = Mockery::mock(TemplateRepositoryInterface::class);
        $templateRepository->shouldReceive('findActiveByPluginDependency')->andReturn(new EloquentCollection);

        $manager = new PluginManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            pluginRepository: $pluginRepository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            templateRepository: $templateRepository,
            moduleRepository: $moduleRepository,
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );
        $manager->loadPlugins();

        $result = $manager->deactivatePlugin('sirsoft-daum_postcode');

        $this->assertTrue($result['warning'] ?? false);
        $this->assertSame('1.4.0', $result['dependent_modules'][0]['version'] ?? null);
        $this->assertSame('0.2.0', $result['dependent_plugins'][0]['version'] ?? null);
    }
}
