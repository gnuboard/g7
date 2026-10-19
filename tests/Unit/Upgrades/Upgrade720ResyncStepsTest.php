<?php

namespace Tests\Unit\Upgrades;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\UpgradeContext;
use App\Services\CoreUpdateService;
use App\Upgrades\Data\V7_2_0\Migrations\ResyncCoreDeclarations;
use App\Upgrades\Data\V7_2_0\Migrations\SyncActiveExtensionSchedules;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 7.2.0 데이터 스텝 00·01 — 새 코드로 코어·활성 확장 선언(스케줄 포함)을 다시 동기화한다.
 *
 * 부모 프로세스(7.1.x 코드)는 스케줄 카탈로그·확장 스케줄 동기화를 모른다. 이 두 스텝이 돌지 않으면 업데이트 직후
 * 스케줄 등록부가 비어 코어·확장 예약이 모두 멈춘다(옛 routes/console.php 등록은 사라졌다). 반대로 동기화가 실패해도
 * 업데이트 전체를 되돌리면 안 된다 — 경고와 복구 명령만 남긴다.
 */
class Upgrade720ResyncStepsTest extends TestCase
{
    /**
     * 테스트 환경 준비 — 데이터 스텝 적재.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 데이터 스텝은 오토로드되지 않는다 — 실행 시점과 같은 방식으로 싣는다.
        require_once base_path('upgrades/data/7.2.0/migrations/00_ResyncCoreDeclarations.php');
        require_once base_path('upgrades/data/7.2.0/migrations/01_SyncActiveExtensionSchedules.php');
    }

    /**
     * 00 이 코어 재동기화를 정확히 한 번 부르는지 확인합니다.
     *
     * @scenario step=resync_core, outcome=success
     *
     * @effects resync_called_once
     *
     * @return void 반환값 없음
     */
    public function test_core_resync_runs_once(): void
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('reloadCoreConfigAndResync')->once();
        $this->app->instance(CoreUpdateService::class, $service);

        (new ResyncCoreDeclarations)->run($this->context());
    }

    /**
     * 00 의 재동기화가 실패해도 예외를 올려 업데이트를 되돌리지 않는지 확인합니다.
     *
     * @scenario step=resync_core, outcome=failure
     *
     * @effects failure_does_not_abort_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_core_resync_failure_does_not_abort(): void
    {
        $failing = Mockery::mock(CoreUpdateService::class);
        $failing->shouldReceive('reloadCoreConfigAndResync')->once()->andThrow(new RuntimeException('boom'));
        $this->app->instance(CoreUpdateService::class, $failing);

        (new ResyncCoreDeclarations)->run($this->context());

        $this->addToAssertionCount(1); // 예외 없이 끝났다
    }

    /**
     * 01 이 모듈·플러그인 매니저의 활성 선언 재동기화를 각각 한 번 부르는지 확인합니다.
     *
     * @scenario step=resync_extensions, outcome=success
     *
     * @effects resync_called_once
     *
     * @return void 반환값 없음
     */
    public function test_extension_resync_runs_for_modules_and_plugins(): void
    {
        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('resyncAllActiveDeclarativeArtifacts')->once()->andReturn([]);
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('resyncAllActiveDeclarativeArtifacts')->once()->andReturn([]);
        $this->app->instance(ModuleManager::class, $modules);
        $this->app->instance(PluginManager::class, $plugins);

        (new SyncActiveExtensionSchedules)->run($this->context());
    }

    /**
     * 01 에서 모듈 재동기화가 실패해도 플러그인 재동기화가 돌고 예외를 올리지 않는지 확인합니다.
     *
     * @scenario step=resync_extensions, outcome=failure
     *
     * @effects failure_does_not_abort_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_extension_resync_failure_does_not_block_plugins(): void
    {
        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('resyncAllActiveDeclarativeArtifacts')->once()->andThrow(new RuntimeException('module boom'));
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('resyncAllActiveDeclarativeArtifacts')->once()->andReturn([]);
        $this->app->instance(ModuleManager::class, $modules);
        $this->app->instance(PluginManager::class, $plugins);

        (new SyncActiveExtensionSchedules)->run($this->context());
    }

    /**
     * 업그레이드 컨텍스트를 만듭니다.
     *
     * @return UpgradeContext 7.1.1 → 7.2.0 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext(fromVersion: '7.1.1', toVersion: '7.2.0', currentStep: '7.2.0');
    }
}
