<?php

namespace Tests\Unit\Http\Controllers\Concerns;

use App\Enums\ExtensionStatus;
use App\Http\Controllers\Concerns\OrchestratesCascadeInstall;
use App\Services\ModuleService;
use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * cascade 의존 확장 설치 흐름의 상태별 처리 검증.
 *
 * 일반 설치(웹/CLI 단독)는 install 만 하지만, cascade 흐름은 본 확장 install 직후
 * `checkDependencies` 가 active 상태를 요구하므로 install + activate 를 묶어 수행한다.
 *
 * 회귀 시나리오: 템플릿 설치 시 의존 모듈이 "이미 설치됨 + 비활성" 상태인 경우
 * cascade 가 activate 를 호출하지 않으면 "필수 의존성 충족되지 않음" 으로 실패한다.
 */
class OrchestratesCascadeInstallTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private object $harness;

    /**
     * 테스트 준비.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // OrchestratesCascadeInstall trait 의 protected 메서드 호출용 무명 클래스
        $this->harness = new class {
            use OrchestratesCascadeInstall {
                installSelectedDependencies as public;
                withKeptDependencies as public;
            }
        };
    }

    /**
     * 테스트 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 설치됐지만 꺼진 의존 모듈은 다시 설치하지 않고 활성화만 한다.
     *
     * @effects cascade_activates_inactive_dependency_without_reinstall
     *
     * @return void 반환값 없음
     */
    public function test_inactive_installed_module_only_activates_no_reinstall(): void
    {
        $moduleService = Mockery::mock(ModuleService::class);
        $moduleService->shouldReceive('getModuleInfo')
            ->with('foo-module')
            ->andReturn([
                'is_installed' => true,
                'status' => ExtensionStatus::Inactive->value,
            ]);
        $moduleService->shouldNotReceive('installModule');
        $moduleService->shouldReceive('activateModule')
            ->once()
            ->with('foo-module')->andReturn(['success' => true]);
        $this->app->instance(ModuleService::class, $moduleService);

        $this->harness->installSelectedDependencies([
            ['type' => 'module', 'identifier' => 'foo-module'],
        ]);
    }

    /**
     * 미설치 의존 모듈은 설치한 뒤 활성화한다.
     *
     * @effects cascade_installs_then_activates_missing_dependency
     *
     * @return void 반환값 없음
     */
    public function test_uninstalled_module_installs_then_activates(): void
    {
        $moduleService = Mockery::mock(ModuleService::class);
        $moduleService->shouldReceive('getModuleInfo')
            ->with('bar-module')
            ->andReturn([
                'is_installed' => false,
                'status' => 'uninstalled',
            ]);
        $moduleService->shouldReceive('installModule')->once()->with('bar-module', \Mockery::any())->andReturn(['identifier' => 'bar-module']);
        $moduleService->shouldReceive('activateModule')->once()->with('bar-module')->andReturn(['success' => true]);
        $this->app->instance(ModuleService::class, $moduleService);

        $this->harness->installSelectedDependencies([
            ['type' => 'module', 'identifier' => 'bar-module'],
        ]);
    }

    /**
     * 이미 활성인 의존 모듈은 건너뛴다.
     *
     * @effects cascade_skips_active_dependency
     *
     * @return void 반환값 없음
     */
    public function test_active_module_is_skipped(): void
    {
        $moduleService = Mockery::mock(ModuleService::class);
        $moduleService->shouldReceive('getModuleInfo')
            ->with('baz-module')
            ->andReturn([
                'is_installed' => true,
                'status' => ExtensionStatus::Active->value,
            ]);
        $moduleService->shouldNotReceive('installModule');
        $moduleService->shouldNotReceive('activateModule');
        $this->app->instance(ModuleService::class, $moduleService);

        $this->harness->installSelectedDependencies([
            ['type' => 'module', 'identifier' => 'baz-module'],
        ]);
    }

    /**
     * 설치됐지만 꺼진 의존 플러그인은 활성화만 한다.
     *
     * @effects cascade_activates_inactive_dependency_without_reinstall
     *
     * @return void 반환값 없음
     */
    public function test_inactive_installed_plugin_only_activates(): void
    {
        $pluginService = Mockery::mock(PluginService::class);
        $pluginService->shouldReceive('getPluginInfo')
            ->with('foo-plugin')
            ->andReturn([
                'is_installed' => true,
                'status' => ExtensionStatus::Inactive->value,
            ]);
        $pluginService->shouldNotReceive('installPlugin');
        $pluginService->shouldReceive('activatePlugin')->once()->with('foo-plugin')->andReturn(['success' => true]);
        $this->app->instance(PluginService::class, $pluginService);

        $this->harness->installSelectedDependencies([
            ['type' => 'plugin', 'identifier' => 'foo-plugin'],
        ]);
    }

    /**
     * 본 확장 설치가 실패했을 때 실패 사유에 앞서 남긴 의존 확장 목록을 덧붙인다(웹 실패 응답 — D14).
     *
     * @effects cascade_web_failure_reason_lists_kept_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_failure_reason_appends_kept_dependencies(): void
    {
        $moduleService = Mockery::mock(ModuleService::class);
        $moduleService->shouldReceive('getModuleInfo')->with('bar-module')->andReturn(['is_installed' => false, 'status' => 'uninstalled']);
        $moduleService->shouldReceive('installModule')->once()->andReturn(['identifier' => 'bar-module']);
        $moduleService->shouldReceive('activateModule')->once()->with('bar-module')->andReturn(['success' => true]);
        $this->app->instance(ModuleService::class, $moduleService);

        $this->assertSame('사유', $this->harness->withKeptDependencies('사유'), '의존 설치 전에는 사유 그대로');

        $result = $this->harness->installSelectedDependencies([['type' => 'module', 'identifier' => 'bar-module']]);

        $this->assertSame(['bar-module'], $result['activated']);
        $this->assertSame(
            '사유 '.__('extensions.errors.cascade_dependencies_kept', ['list' => 'bar-module']),
            $this->harness->withKeptDependencies('사유')
        );
    }
}
