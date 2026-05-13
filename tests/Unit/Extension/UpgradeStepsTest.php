<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Mockery;
use Tests\TestCase;

/**
 * 버전별 업그레이드 스텝 실행 테스트
 *
 * runUpgradeSteps() 메서드의 필터링, 정렬, 실행 로직을 검증합니다.
 */
class UpgradeStepsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 빈 업그레이드 스텝 배열에서 아무 작업도 하지 않는지 테스트
     */
    public function test_run_upgrade_steps_does_nothing_with_empty_steps(): void
    {
        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([]);

        // runUpgradeSteps는 protected이므로 Reflection으로 접근
        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        // 예외 없이 정상 완료
        $method->invoke($manager, $module, '1.0.0', '2.0.0');
        $this->assertTrue(true);
    }

    /**
     * fromVersion 초과 ~ toVersion 이하 필터링 테스트
     */
    public function test_run_upgrade_steps_filters_by_version_range(): void
    {
        $executedSteps = [];

        $step_1_1 = Mockery::mock(UpgradeStepInterface::class);
        $step_1_1->shouldReceive('run')->once()->andReturnUsing(function (UpgradeContext $ctx) use (&$executedSteps) {
            $executedSteps[] = $ctx->currentStep;
        });

        $step_1_2 = Mockery::mock(UpgradeStepInterface::class);
        $step_1_2->shouldReceive('run')->once()->andReturnUsing(function (UpgradeContext $ctx) use (&$executedSteps) {
            $executedSteps[] = $ctx->currentStep;
        });

        $step_0_5 = Mockery::mock(UpgradeStepInterface::class);
        $step_0_5->shouldNotReceive('run'); // fromVersion 이하이므로 실행되지 않아야 함

        $step_2_0 = Mockery::mock(UpgradeStepInterface::class);
        $step_2_0->shouldNotReceive('run'); // toVersion(1.2.0) 초과이므로 실행되지 않아야 함

        // fromVersion=1.0.0, toVersion=1.2.0 → 1.1.0과 1.2.0만 실행
        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([
            '0.5.0' => $step_0_5,
            '1.1.0' => $step_1_1,
            '1.2.0' => $step_1_2,
            '2.0.0' => $step_2_0,
        ]);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        $method->invoke($manager, $module, '1.0.0', '1.2.0');

        $this->assertEquals(['1.1.0', '1.2.0'], $executedSteps);
    }

    /**
     * 버전순 정렬 테스트 (역순으로 제공된 스텝이 올바른 순서로 실행되는지)
     */
    public function test_run_upgrade_steps_executes_in_version_order(): void
    {
        $executedOrder = [];

        $step_1_3 = Mockery::mock(UpgradeStepInterface::class);
        $step_1_3->shouldReceive('run')->once()->andReturnUsing(function () use (&$executedOrder) {
            $executedOrder[] = '1.3.0';
        });

        $step_1_1 = Mockery::mock(UpgradeStepInterface::class);
        $step_1_1->shouldReceive('run')->once()->andReturnUsing(function () use (&$executedOrder) {
            $executedOrder[] = '1.1.0';
        });

        $step_1_2 = Mockery::mock(UpgradeStepInterface::class);
        $step_1_2->shouldReceive('run')->once()->andReturnUsing(function () use (&$executedOrder) {
            $executedOrder[] = '1.2.0';
        });

        // 역순으로 제공
        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([
            '1.3.0' => $step_1_3,
            '1.1.0' => $step_1_1,
            '1.2.0' => $step_1_2,
        ]);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        $method->invoke($manager, $module, '1.0.0', '2.0.0');

        $this->assertEquals(['1.1.0', '1.2.0', '1.3.0'], $executedOrder);
    }

    /**
     * callable 스텝 실행 테스트
     */
    public function test_run_upgrade_steps_supports_callable(): void
    {
        $executed = false;
        $receivedContext = null;

        $callableStep = function (UpgradeContext $ctx) use (&$executed, &$receivedContext) {
            $executed = true;
            $receivedContext = $ctx;
        };

        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([
            '1.1.0' => $callableStep,
        ]);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        $method->invoke($manager, $module, '1.0.0', '2.0.0');

        $this->assertTrue($executed);
        $this->assertInstanceOf(UpgradeContext::class, $receivedContext);
        $this->assertEquals('1.0.0', $receivedContext->fromVersion);
        $this->assertEquals('2.0.0', $receivedContext->toVersion);
        $this->assertEquals('1.1.0', $receivedContext->currentStep);
    }

    /**
     * 스텝 실행 중 예외 발생 시 전파되는지 테스트
     */
    public function test_run_upgrade_steps_propagates_exception(): void
    {
        $failingStep = Mockery::mock(UpgradeStepInterface::class);
        $failingStep->shouldReceive('run')->once()->andThrow(new \RuntimeException('Step failed'));

        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([
            '1.1.0' => $failingStep,
        ]);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Step failed');

        $method->invoke($manager, $module, '1.0.0', '2.0.0');
    }

    /**
     * UpgradeContext의 withCurrentStep이 올바르게 동작하는지 테스트
     */
    public function test_upgrade_context_with_current_step_creates_new_instance(): void
    {
        $context = new UpgradeContext(
            fromVersion: '1.0.0',
            toVersion: '2.0.0',
        );

        $stepContext = $context->withCurrentStep('1.5.0');

        $this->assertEquals('1.0.0', $stepContext->fromVersion);
        $this->assertEquals('2.0.0', $stepContext->toVersion);
        $this->assertEquals('1.5.0', $stepContext->currentStep);
        // 원본은 변경되지 않음
        $this->assertEquals('', $context->currentStep);
    }

    /**
     * fromVersion과 toVersion이 같은 경우 아무 스텝도 실행되지 않는지 테스트
     */
    public function test_run_upgrade_steps_no_execution_when_versions_equal(): void
    {
        $step = Mockery::mock(UpgradeStepInterface::class);
        $step->shouldNotReceive('run');

        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([
            '1.0.0' => $step,
        ]);

        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        $method->invoke($manager, $module, '1.0.0', '1.0.0');

        $this->assertTrue(true);
    }

    /**
     * force + 동일 버전: 해당 버전의 스텝이 실행되는지 테스트
     */
    public function test_run_upgrade_steps_force_with_same_version_executes_step(): void
    {
        $executedSteps = [];

        $step = Mockery::mock(UpgradeStepInterface::class);
        $step->shouldReceive('run')->once()->andReturnUsing(function (UpgradeContext $ctx) use (&$executedSteps) {
            $executedSteps[] = $ctx->currentStep;
        });

        $otherStep = Mockery::mock(UpgradeStepInterface::class);
        $otherStep->shouldNotReceive('run'); // 다른 버전은 실행되지 않아야 함

        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([
            '1.0.0' => $step,
            '1.1.0' => $otherStep,
        ]);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        $method->invoke($manager, $module, '1.0.0', '1.0.0', true);

        $this->assertEquals(['1.0.0'], $executedSteps);
    }

    /**
     * force + 프리릴리스 동일 버전: 해당 버전의 스텝이 실행되는지 테스트
     */
    public function test_run_upgrade_steps_force_with_same_prerelease_version(): void
    {
        $executedSteps = [];

        $step_beta2 = Mockery::mock(UpgradeStepInterface::class);
        $step_beta2->shouldReceive('run')->once()->andReturnUsing(function (UpgradeContext $ctx) use (&$executedSteps) {
            $executedSteps[] = $ctx->currentStep;
        });

        $step_beta1 = Mockery::mock(UpgradeStepInterface::class);
        $step_beta1->shouldNotReceive('run');

        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([
            '1.0.0-beta.1' => $step_beta1,
            '1.0.0-beta.2' => $step_beta2,
        ]);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        $method->invoke($manager, $module, '1.0.0-beta.2', '1.0.0-beta.2', true);

        $this->assertEquals(['1.0.0-beta.2'], $executedSteps);
    }

    /**
     * force + 다른 버전 범위: 기존 범위 필터링이 정상 동작하는지 테스트
     */
    public function test_run_upgrade_steps_force_with_different_versions_uses_normal_range(): void
    {
        $executedSteps = [];

        $step_1_1 = Mockery::mock(UpgradeStepInterface::class);
        $step_1_1->shouldReceive('run')->once()->andReturnUsing(function (UpgradeContext $ctx) use (&$executedSteps) {
            $executedSteps[] = $ctx->currentStep;
        });

        $step_1_0 = Mockery::mock(UpgradeStepInterface::class);
        $step_1_0->shouldNotReceive('run'); // fromVersion 이하

        $step_1_2 = Mockery::mock(UpgradeStepInterface::class);
        $step_1_2->shouldReceive('run')->once()->andReturnUsing(function (UpgradeContext $ctx) use (&$executedSteps) {
            $executedSteps[] = $ctx->currentStep;
        });

        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([
            '1.0.0' => $step_1_0,
            '1.1.0' => $step_1_1,
            '1.2.0' => $step_1_2,
        ]);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        // force=true이지만 fromVersion != toVersion이므로 일반 범위 필터링 적용
        $method->invoke($manager, $module, '1.0.0', '1.2.0', true);

        $this->assertEquals(['1.1.0', '1.2.0'], $executedSteps);
    }

    /**
     * 업그레이드 스텝 파일명에서 일반 버전을 올바르게 파싱합니다.
     */
    public function test_upgrade_step_filename_parses_standard_version(): void
    {
        $pattern = '/^Upgrade_(\d+)_(\d+)_(\d+)(?:_([a-zA-Z]\w*(?:_\d+)*))?$/';

        $this->assertMatchesRegularExpression($pattern, 'Upgrade_1_0_0');
        $this->assertMatchesRegularExpression($pattern, 'Upgrade_1_2_3');
        $this->assertMatchesRegularExpression($pattern, 'Upgrade_10_20_30');

        // 파싱 결과 확인
        preg_match($pattern, 'Upgrade_1_2_3', $matches);
        $version = "{$matches[1]}.{$matches[2]}.{$matches[3]}";
        $this->assertSame('1.2.3', $version);
        $this->assertEmpty($matches[4] ?? '');
    }

    /**
     * 업그레이드 스텝 파일명에서 프리릴리스 버전을 올바르게 파싱합니다.
     */
    public function test_upgrade_step_filename_parses_prerelease_version(): void
    {
        $pattern = '/^Upgrade_(\d+)_(\d+)_(\d+)(?:_([a-zA-Z]\w*(?:_\d+)*))?$/';

        // alpha.1
        preg_match($pattern, 'Upgrade_7_0_0_alpha_1', $matches);
        $version = "{$matches[1]}.{$matches[2]}.{$matches[3]}";
        if (! empty($matches[4])) {
            $version .= '-'.str_replace('_', '.', $matches[4]);
        }
        $this->assertSame('7.0.0-alpha.1', $version);

        // rc.2
        preg_match($pattern, 'Upgrade_1_0_0_rc_2', $matches);
        $version = "{$matches[1]}.{$matches[2]}.{$matches[3]}";
        if (! empty($matches[4])) {
            $version .= '-'.str_replace('_', '.', $matches[4]);
        }
        $this->assertSame('1.0.0-rc.2', $version);

        // alpha (숫자 없는 프리릴리스)
        preg_match($pattern, 'Upgrade_2_0_0_alpha', $matches);
        $version = "{$matches[1]}.{$matches[2]}.{$matches[3]}";
        if (! empty($matches[4])) {
            $version .= '-'.str_replace('_', '.', $matches[4]);
        }
        $this->assertSame('2.0.0-alpha', $version);
    }

    /**
     * 무효한 업그레이드 스텝 파일명은 매칭되지 않습니다.
     */
    public function test_upgrade_step_filename_rejects_invalid_names(): void
    {
        $pattern = '/^Upgrade_(\d+)_(\d+)_(\d+)(?:_([a-zA-Z]\w*(?:_\d+)*))?$/';

        // 숫자로 시작하는 프리릴리스
        $this->assertDoesNotMatchRegularExpression($pattern, 'Upgrade_1_0_0_1beta');
        // 접두사 누락
        $this->assertDoesNotMatchRegularExpression($pattern, '1_0_0');
        // 버전 세그먼트 부족
        $this->assertDoesNotMatchRegularExpression($pattern, 'Upgrade_1_0');
    }

    /**
     * 프리릴리스 버전이 포함된 업그레이드 스텝의 범위 필터링 테스트
     */
    public function test_run_upgrade_steps_filters_prerelease_versions(): void
    {
        $executedSteps = [];

        $step_beta1 = Mockery::mock(UpgradeStepInterface::class);
        $step_beta1->shouldReceive('run')->once()->andReturnUsing(function (UpgradeContext $ctx) use (&$executedSteps) {
            $executedSteps[] = $ctx->currentStep;
        });

        $step_beta2 = Mockery::mock(UpgradeStepInterface::class);
        $step_beta2->shouldReceive('run')->once()->andReturnUsing(function (UpgradeContext $ctx) use (&$executedSteps) {
            $executedSteps[] = $ctx->currentStep;
        });

        $step_alpha = Mockery::mock(UpgradeStepInterface::class);
        $step_alpha->shouldNotReceive('run');

        // from=7.0.0-alpha.1, to=7.0.0-beta.2 → beta.1, beta.2만 실행
        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('upgrades')->once()->andReturn([
            '7.0.0-alpha.1' => $step_alpha,
            '7.0.0-beta.1' => $step_beta1,
            '7.0.0-beta.2' => $step_beta2,
        ]);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        $manager = $this->createModuleManagerWithMocks();
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->setAccessible(true);

        $method->invoke($manager, $module, '7.0.0-alpha.1', '7.0.0-beta.2');

        $this->assertEquals(['7.0.0-beta.1', '7.0.0-beta.2'], $executedSteps);
    }

    /**
     * 모든 의존성을 Mock으로 대체한 ModuleManager 인스턴스를 생성합니다.
     */
    private function createModuleManagerWithMocks(): \App\Extension\ModuleManager
    {
        return new \App\Extension\ModuleManager(
            extensionManager: Mockery::mock(\App\Extension\ExtensionManager::class),
            moduleRepository: Mockery::mock(\App\Contracts\Repositories\ModuleRepositoryInterface::class),
            permissionRepository: Mockery::mock(\App\Contracts\Repositories\PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(\App\Contracts\Repositories\RoleRepositoryInterface::class),
            menuRepository: Mockery::mock(\App\Contracts\Repositories\MenuRepositoryInterface::class),
            templateRepository: Mockery::mock(\App\Contracts\Repositories\TemplateRepositoryInterface::class),
            pluginRepository: Mockery::mock(\App\Contracts\Repositories\PluginRepositoryInterface::class),
            layoutRepository: Mockery::mock(\App\Contracts\Repositories\LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(\App\Services\LayoutExtensionService::class),
        );
    }
}
