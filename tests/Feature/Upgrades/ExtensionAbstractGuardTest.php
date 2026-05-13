<?php

namespace Tests\Feature\Upgrades;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\AbstractUpgradeStep;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\UpgradeContext;
use Mockery;
use Tests\TestCase;

/**
 * 모듈/플러그인 측 AbstractUpgradeStep 의무 fatal 가드 회귀 검증.
 *
 * 코어 RunUpgradeStepsAbstractGuardTest 와 1:1 미러링 (확장 측 진입점).
 *
 * 6 케이스:
 *   1. 모듈 — g7_version=beta.5 이상, 미상속 → throw
 *   2. 모듈 — g7_version=beta.5 이상, 상속    → pass
 *   3. 모듈 — g7_version=beta.4 이하, 미상속 → legacy bypass (통과)
 *   4. 플러그인 — 동일 3종
 */
class ExtensionAbstractGuardTest extends TestCase
{
    // ── 모듈 ────────────────────────────────────────────────────────────

    public function test_module_throws_when_step_does_not_extend_abstract(): void
    {
        $step = $this->makeLegacyStep();
        $module = $this->mockModule(
            identifier: 'vendor-test-module-a',
            workingVersion: '1.0.0',
            g7Version: '>=7.0.0-beta.5',
            upgrades: ['1.0.0' => $step],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must extend App\\\\Extension\\\\AbstractUpgradeStep/');
        $this->expectExceptionMessageMatches('/vendor-test-module-a/');
        $this->expectExceptionMessageMatches('/module/');

        $this->invokeRunUpgradeSteps(
            manager: app(ModuleManager::class),
            extension: $module,
            from: '0.9.0',
            to: '1.0.0',
        );
    }

    public function test_module_passes_when_step_extends_abstract(): void
    {
        $step = $this->makeAbstractStep();
        $module = $this->mockModule(
            identifier: 'vendor-test-module-b',
            workingVersion: '1.0.0',
            g7Version: '>=7.0.0-beta.5',
            upgrades: ['1.0.0' => $step],
        );

        $captured = [];
        $this->invokeRunUpgradeSteps(
            manager: app(ModuleManager::class),
            extension: $module,
            from: '0.9.0',
            to: '1.0.0',
            onStep: function (string $v) use (&$captured): void { $captured[] = $v; },
        );

        $this->assertSame(['1.0.0'], $captured, '가드 통과 후 step 이 정상 실행되어야 함');
    }

    public function test_module_legacy_g7_version_bypasses_guard(): void
    {
        $step = $this->makeLegacyStep();
        $module = $this->mockModule(
            identifier: 'vendor-test-module-c',
            workingVersion: '1.0.0',
            g7Version: '>=7.0.0-beta.4',
            upgrades: ['1.0.0' => $step],
        );

        $captured = [];
        $this->invokeRunUpgradeSteps(
            manager: app(ModuleManager::class),
            extension: $module,
            from: '0.9.0',
            to: '1.0.0',
            onStep: function (string $v) use (&$captured): void { $captured[] = $v; },
        );

        $this->assertSame(['1.0.0'], $captured, 'legacy g7_version 은 가드 미발동 (호환 보장)');
    }

    public function test_module_callable_step_bypasses_guard(): void
    {
        // closure step 은 instanceof UpgradeStepInterface 미충족 → 가드 자체가 검사 안 함
        $called = false;
        $module = $this->mockModule(
            identifier: 'vendor-test-module-d',
            workingVersion: '1.0.0',
            g7Version: '>=7.0.0-beta.5',
            upgrades: ['1.0.0' => function () use (&$called): void { $called = true; }],
        );

        $this->invokeRunUpgradeSteps(
            manager: app(ModuleManager::class),
            extension: $module,
            from: '0.9.0',
            to: '1.0.0',
        );

        $this->assertTrue($called, 'callable step 은 가드를 우회하고 그대로 실행 (호환)');
    }

    public function test_module_step_below_since_version_bypasses_guard(): void
    {
        // 확장 working version 이 5.0.0 이고 g7_version 이 beta.5 이상이면
        // sinceVersion = 5.0.0. 그러나 실행되는 step 이 4.x 이면 미발동.
        $legacyStep = $this->makeLegacyStep();
        $module = $this->mockModule(
            identifier: 'vendor-test-module-e',
            workingVersion: '5.0.0',
            g7Version: '>=7.0.0-beta.5',
            upgrades: ['4.5.0' => $legacyStep],
        );

        $captured = [];
        $this->invokeRunUpgradeSteps(
            manager: app(ModuleManager::class),
            extension: $module,
            from: '4.4.0',
            to: '4.5.0',
            onStep: function (string $v) use (&$captured): void { $captured[] = $v; },
        );

        $this->assertSame(['4.5.0'], $captured, '확장 working version 이전의 step 은 가드 미발동 (legacy step 호환)');
    }

    // ── 플러그인 ─────────────────────────────────────────────────────────

    public function test_plugin_throws_when_step_does_not_extend_abstract(): void
    {
        $step = $this->makeLegacyStep();
        $plugin = $this->mockPlugin(
            identifier: 'vendor-test-plugin-a',
            workingVersion: '1.0.0',
            g7Version: '>=7.0.0-beta.5',
            upgrades: ['1.0.0' => $step],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must extend App\\\\Extension\\\\AbstractUpgradeStep/');
        $this->expectExceptionMessageMatches('/vendor-test-plugin-a/');
        $this->expectExceptionMessageMatches('/plugin/');

        $this->invokeRunUpgradeSteps(
            manager: app(PluginManager::class),
            extension: $plugin,
            from: '0.9.0',
            to: '1.0.0',
        );
    }

    public function test_plugin_passes_when_step_extends_abstract(): void
    {
        $step = $this->makeAbstractStep();
        $plugin = $this->mockPlugin(
            identifier: 'vendor-test-plugin-b',
            workingVersion: '1.0.0',
            g7Version: '>=7.0.0-beta.5',
            upgrades: ['1.0.0' => $step],
        );

        $captured = [];
        $this->invokeRunUpgradeSteps(
            manager: app(PluginManager::class),
            extension: $plugin,
            from: '0.9.0',
            to: '1.0.0',
            onStep: function (string $v) use (&$captured): void { $captured[] = $v; },
        );

        $this->assertSame(['1.0.0'], $captured);
    }

    public function test_plugin_legacy_g7_version_bypasses_guard(): void
    {
        $step = $this->makeLegacyStep();
        $plugin = $this->mockPlugin(
            identifier: 'vendor-test-plugin-c',
            workingVersion: '1.0.0',
            g7Version: '>=7.0.0-beta.4',
            upgrades: ['1.0.0' => $step],
        );

        $captured = [];
        $this->invokeRunUpgradeSteps(
            manager: app(PluginManager::class),
            extension: $plugin,
            from: '0.9.0',
            to: '1.0.0',
            onStep: function (string $v) use (&$captured): void { $captured[] = $v; },
        );

        $this->assertSame(['1.0.0'], $captured);
    }

    public function test_plugin_null_g7_version_bypasses_guard(): void
    {
        // g7_version 미선언 (legacy 확장) → resolveSinceVersion=null → 가드 우회
        $step = $this->makeLegacyStep();
        $plugin = $this->mockPlugin(
            identifier: 'vendor-test-plugin-d',
            workingVersion: '1.0.0',
            g7Version: null,
            upgrades: ['1.0.0' => $step],
        );

        $captured = [];
        $this->invokeRunUpgradeSteps(
            manager: app(PluginManager::class),
            extension: $plugin,
            from: '0.9.0',
            to: '1.0.0',
            onStep: function (string $v) use (&$captured): void { $captured[] = $v; },
        );

        $this->assertSame(['1.0.0'], $captured);
    }

    // ── 헬퍼 ────────────────────────────────────────────────────────────

    private function mockModule(
        string $identifier,
        string $workingVersion,
        ?string $g7Version,
        array $upgrades,
    ): ModuleInterface {
        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getIdentifier')->andReturn($identifier);
        $module->shouldReceive('getVersion')->andReturn($workingVersion);
        $module->shouldReceive('getRequiredCoreVersion')->andReturn($g7Version);
        $module->shouldReceive('upgrades')->andReturn($upgrades);

        return $module;
    }

    private function mockPlugin(
        string $identifier,
        string $workingVersion,
        ?string $g7Version,
        array $upgrades,
    ): PluginInterface {
        $plugin = Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getIdentifier')->andReturn($identifier);
        $plugin->shouldReceive('getVersion')->andReturn($workingVersion);
        $plugin->shouldReceive('getRequiredCoreVersion')->andReturn($g7Version);
        $plugin->shouldReceive('upgrades')->andReturn($upgrades);

        return $plugin;
    }

    private function makeLegacyStep(): UpgradeStepInterface
    {
        return new class implements UpgradeStepInterface
        {
            public function run(UpgradeContext $context): void {}
        };
    }

    private function makeAbstractStep(): AbstractUpgradeStep
    {
        return new class extends AbstractUpgradeStep {};
    }

    /**
     * @param  ModuleManager|PluginManager  $manager
     * @param  ModuleInterface|PluginInterface  $extension
     */
    private function invokeRunUpgradeSteps(
        $manager,
        $extension,
        string $from,
        string $to,
        ?\Closure $onStep = null,
    ): void {
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('runUpgradeSteps');
        $method->setAccessible(true);
        $method->invoke($manager, $extension, $from, $to, false, $onStep);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
