<?php

namespace Tests\Feature\Repositories;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Plugin;
use App\Repositories\ModuleRepository;
use App\Repositories\PluginRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 켜진 의존 확장 조회 — 매니페스트의 dependencies.{modules,plugins} 맵으로 판정한다 (#138 실측 ①)
 *
 * 모듈·플러그인 저장소의 의존 조회 4곳이 존재하지 않는 메서드(getModuleDependencies·getPluginDependencies)를
 * 찾거나 `{modules: {...}, plugins: {...}}` 모양의 매니페스트에 in_array 를 써서 **한 번도 의존 확장을 찾지
 * 못했다.** 그래서 의존 확장이 켜져 있어도 모듈 비활성화 경고가 뜨지 않고, 제거도 막히지 않았다.
 */
class ExtensionDependencyLookupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 식별자 → 매니페스트 dependencies 를 돌려주는 가짜 매니저를 묶습니다.
     *
     * @param  array<string, array<string, mixed>>  $modules  모듈 식별자 → dependencies
     * @param  array<string, array<string, mixed>>  $plugins  플러그인 식별자 → dependencies
     * @return void 반환값 없음
     */
    private function fakeManagers(array $modules, array $plugins): void
    {
        $instance = function (string $interface, array $deps) {
            $extension = Mockery::mock($interface);
            $extension->shouldReceive('getDependencies')->andReturn($deps);

            return $extension;
        };

        $moduleManager = Mockery::mock(ModuleManagerInterface::class);
        $moduleManager->shouldReceive('getModule')->andReturnUsing(fn ($id) => isset($modules[$id]) ? $instance(ModuleInterface::class, $modules[$id]) : null);
        $this->app->instance(ModuleManagerInterface::class, $moduleManager);

        $pluginManager = Mockery::mock(PluginManagerInterface::class);
        $pluginManager->shouldReceive('getPlugin')->andReturnUsing(fn ($id) => isset($plugins[$id]) ? $instance(PluginInterface::class, $plugins[$id]) : null);
        $this->app->instance(PluginManagerInterface::class, $pluginManager);

        foreach (array_keys($modules) as $id) {
            Module::factory()->create(['identifier' => $id, 'status' => ExtensionStatus::Active->value]);
        }
        foreach (array_keys($plugins) as $id) {
            Plugin::factory()->create(['identifier' => $id, 'status' => ExtensionStatus::Active->value]);
        }
    }

    /**
     * 모듈·플러그인이 매니페스트 dependencies 맵으로 선언한 의존을 네 조회 모두 찾는다.
     *
     * @scenario case=manifest_map
     *
     * @effects active_dependents_found_from_manifest_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_all_four_lookups_find_dependents_declared_in_manifest(): void
    {
        $this->fakeManagers(
            [
                'acme-shop' => ['modules' => ['acme-core' => '>=1.0.0'], 'plugins' => ['acme-pay' => '>=1.0.0']],
                'acme-core' => ['modules' => [], 'plugins' => []],
            ],
            [
                'acme-pay' => ['modules' => ['acme-core' => '>=1.0.0'], 'plugins' => []],
                'acme-pay-addon' => ['modules' => [], 'plugins' => ['acme-pay' => '>=1.0.0']],
            ],
        );

        $modules = app(ModuleRepository::class);
        $plugins = app(PluginRepository::class);

        $this->assertSame(['acme-shop'], $modules->findActiveByModuleDependency('acme-core')->pluck('identifier')->all());
        $this->assertSame(['acme-shop'], $modules->findActiveByPluginDependency('acme-pay')->pluck('identifier')->all());
        $this->assertSame(['acme-pay'], $plugins->findActiveByModuleDependency('acme-core')->pluck('identifier')->all());
        $this->assertSame(['acme-pay-addon'], $plugins->findActiveByPluginDependency('acme-pay')->pluck('identifier')->all());
    }
}
