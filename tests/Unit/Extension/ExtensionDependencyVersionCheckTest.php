<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 모듈·플러그인 의존성 검사가 버전 제약까지 보는지 검증한다 (템플릿과 같은 판정).
 *
 * 회귀: 템플릿은 의존 확장의 설치 버전이 제약(예: >=1.3.0)을 충족하는지 확인했지만 모듈·플러그인은 「활성인지」만 봐서,
 * 지원하지 않는 옛 의존 버전 위에 설치·활성화됐다. 설치 미리보기는 버전 미달을 「미충족」으로 보여 판정이 엇갈렸다.
 */
class ExtensionDependencyVersionCheckTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 의존 대상 활성 모듈·플러그인을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Module::create([
            'identifier' => 'dep-shop', 'vendor' => 'dep', 'name' => ['ko' => '샵', 'en' => 'Shop'],
            'version' => '1.2.0', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Plugin::create([
            'identifier' => 'dep-pay', 'vendor' => 'dep', 'name' => ['ko' => '결제', 'en' => 'Pay'],
            'version' => '2.0.0', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
    }

    /**
     * 의존 선언만 가진 모듈 대역.
     *
     * @param  array<string, array<string, string>>  $dependencies  의존 선언
     * @return ModuleInterface 대역
     */
    private function moduleWith(array $dependencies): ModuleInterface
    {
        $module = \Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getDependencies')->andReturn($dependencies);

        return $module;
    }

    /**
     * 모듈 설치 의존성 검사가 버전 미달을 거부한다.
     *
     * @effects module_install_rejects_dependency_version_below_constraint
     *
     * @return void 반환값 없음
     */
    public function test_module_install_rejects_dependency_below_version(): void
    {
        $manager = app(ModuleManager::class);
        $check = new \ReflectionMethod($manager, 'checkDependencies');

        $this->assertNull($check->invoke($manager, $this->moduleWith(['modules' => ['dep-shop' => '>=1.0.0']])), '충족하면 통과');

        try {
            $check->invoke($manager, $this->moduleWith(['modules' => ['dep-shop' => '>=1.3.0']]));
            $this->fail('버전 미달이 통과했다');
        } catch (\Exception $e) {
            $this->assertSame(__('modules.dependency_version_unmet', ['dependency' => 'dep-shop', 'required' => '>=1.3.0', 'installed' => '1.2.0']), $e->getMessage());
        }
    }

    /**
     * 플러그인 설치 의존성 검사가 버전 미달을 거부한다.
     *
     * @effects plugin_install_rejects_dependency_version_below_constraint
     *
     * @return void 반환값 없음
     */
    public function test_plugin_install_rejects_dependency_below_version(): void
    {
        $manager = app(PluginManager::class);
        $check = new \ReflectionMethod($manager, 'checkDependencies');
        $plugin = \Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getDependencies')->andReturn(['plugins' => ['dep-pay' => '^1.0.0'], 'modules' => ['dep-shop' => '>=1.0.0']]);

        try {
            $check->invoke($manager, $plugin);
            $this->fail('버전 미달이 통과했다');
        } catch (\Exception $e) {
            $this->assertSame(__('plugins.dependency_version_unmet', ['dependency' => 'dep-pay', 'required' => '^1.0.0', 'installed' => '2.0.0']), $e->getMessage());
        }
    }

    /**
     * 활성화 의존성 수집이 버전 미달을 「version_mismatch」로 돌려준다 (경고 → --force 로만 우회).
     *
     * @effects activation_reports_version_mismatch_as_unmet
     *
     * @return void 반환값 없음
     */
    public function test_activation_collects_version_mismatch(): void
    {
        foreach ([app(ModuleManager::class), app(PluginManager::class)] as $manager) {
            $collect = new \ReflectionMethod($manager, 'collectUnmetDependencies');
            $result = $collect->invoke($manager, ['modules' => ['dep-shop' => '>=1.3.0', 'dep-missing' => '*'], 'plugins' => ['dep-pay' => '>=1.0.0']]);

            $this->assertSame(['dep-shop', 'dep-missing'], array_column($result['modules'], 'identifier'), $manager::class);
            $this->assertSame(['version_mismatch', 'not_installed'], array_column($result['modules'], 'status'), $manager::class);
            $this->assertSame('>=1.3.0', $result['modules'][0]['required_version']);
            $this->assertSame('1.2.0', $result['modules'][0]['installed_version']);
            $this->assertSame([], $result['plugins'], '충족한 플러그인은 목록에 없다');
        }
    }

    /**
     * 활성화는 버전 미달이면 경고로 멈추고, --force 면 의존성 관문을 지나 확장 자신의 활성화까지 간다.
     *
     * 대역의 activate() 가 거짓을 돌려줘 상태 쓰기 전에 끝난다 — 사유가 대역의 것이면 관문을 지난 것이다.
     *
     * @effects activation_force_bypasses_dependency_version_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_activation_force_bypasses_version_mismatch(): void
    {
        foreach (['module' => [app(ModuleManager::class), 'modules', 'activateModule', ModuleInterface::class],
            'plugin' => [app(PluginManager::class), 'plugins', 'activatePlugin', PluginInterface::class]] as $type => [$manager, $property, $activate, $contract]) {
            $identifier = 'dep-consumer-'.$type;
            $extension = \Mockery::mock($contract);
            $extension->shouldReceive('getIdentifier')->andReturn($identifier);
            $extension->shouldReceive('getDependencies')->andReturn(['modules' => ['dep-shop' => '>=1.3.0']]);
            $extension->shouldReceive('getRequiredCoreVersion')->andReturn(null);
            $extension->shouldReceive('getStateContracts')->andReturn([]);
            // 활성화가 최근 검색어 범위 선언(search_scopes) 형식도 검사한다 — 선언 없음
            $extension->shouldReceive('getSearchScopes')->andReturn([]);
            $extension->shouldReceive('clearLifecycleFailureReason');
            $extension->shouldReceive('activate')->andReturn(false);
            $extension->shouldReceive('getLifecycleFailureReason')->andReturn('reached-own-activation');

            $loaded = new \ReflectionProperty($manager, $property);
            $loaded->setValue($manager, [...$loaded->getValue($manager), $identifier => $extension]);

            $blocked = $manager->{$activate}($identifier);
            $this->assertTrue($blocked['warning'] ?? false, $type.': 버전 미달은 경고로 멈춘다');
            $this->assertSame('version_mismatch', $blocked['missing_modules'][0]['status'] ?? null, $type);

            $forced = $manager->{$activate}($identifier, true);
            $this->assertArrayNotHasKey('warning', $forced, $type.': --force 는 의존성 경고를 우회한다');
            $this->assertSame('reached-own-activation', $forced['reason'] ?? null, $type);
        }
    }
}
