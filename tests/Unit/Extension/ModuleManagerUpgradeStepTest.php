<?php

namespace Tests\Unit\Extension;

use App\Extension\ModuleManager;
use Mockery;
use Tests\TestCase;

/**
 * ModuleManager::runUpgradeSteps() 의 onStep 콜백 호출 검증.
 *
 * CLI UpdateModuleCommand 에서 progress bar clear → line → display 사이클을 만들기 위해
 * 각 upgrade step 버전마다 콜백이 호출되어야 한다. runUpgradeSteps 는 protected 이므로
 * Reflection 으로 직접 호출한다.
 */
class ModuleManagerUpgradeStepTest extends TestCase
{
    public function test_run_upgrade_steps_invokes_on_step_for_each_version(): void
    {
        $moduleManager = app(ModuleManager::class);

        // Module mock: upgrades() 가 2 개 step 반환
        $module = Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');
        $module->shouldReceive('upgrades')->andReturn([
            '1.1.0' => function () { /* no-op */ },
            '1.2.0' => function () { /* no-op */ },
        ]);

        $capturedVersions = [];
        $onStep = function (string $version) use (&$capturedVersions): void {
            $capturedVersions[] = $version;
        };

        $reflection = new \ReflectionClass($moduleManager);
        $method = $reflection->getMethod('runUpgradeSteps');
        $method->setAccessible(true);

        // $fromVersion=1.0.0, $toVersion=1.2.0 → 1.1.0, 1.2.0 실행
        $method->invoke($moduleManager, $module, '1.0.0', '1.2.0', false, $onStep);

        $this->assertEquals(['1.1.0', '1.2.0'], $capturedVersions, '각 step 버전이 콜백에 전달되어야 함');
    }

    /**
     * onStep 이 null 이어도 정상 동작 (하위 호환).
     */
    public function test_run_upgrade_steps_tolerates_null_on_step(): void
    {
        $moduleManager = app(ModuleManager::class);

        $module = Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');
        $module->shouldReceive('upgrades')->andReturn([
            '1.1.0' => function () { /* no-op */ },
        ]);

        $reflection = new \ReflectionClass($moduleManager);
        $method = $reflection->getMethod('runUpgradeSteps');
        $method->setAccessible(true);

        $this->expectNotToPerformAssertions();
        $method->invoke($moduleManager, $module, '1.0.0', '1.1.0', false, null);
    }
}
