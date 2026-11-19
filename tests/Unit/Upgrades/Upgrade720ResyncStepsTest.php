<?php

namespace Tests\Unit\Upgrades;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\UpgradeContext;
use App\Services\CoreUpdateService;
use App\Upgrades\Data\V7_2_0\Migrations\ResyncCoreDeclarations;
use App\Upgrades\Data\V7_2_0\Migrations\SyncActiveExtensionSchedules;
use Illuminate\Support\Facades\File;
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
     * @scenario case=core_success
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
     * @scenario case=core_failure
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
     * @scenario case=extensions_success
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
     * @scenario case=extensions_failure
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
     * 01 — 7.1.6 → 7.2.0 실측 재현: 새 판 `_bundled` 선언이 가리키는 확장 클래스가 아직 활성 디렉토리(7.1.x)에 없어
     * 항목 선언 동기화가 「Class … not found」로 실패해도, 그 경고는 운영자 경고 표에서 빠진다(확장 갱신이 이어서 한다).
     * 스텝 전부터 있던 경고와 다른 사유의 경고는 그대로 남는다.
     *
     * @scenario case=extensions_pending_extension_class
     *
     * @effects pending_extension_class_warning_deferred, unrelated_warnings_kept
     *
     * @return void 반환값 없음
     */
    public function test_field_sync_failures_for_classes_awaiting_extension_update_are_not_reported(): void
    {
        $root = $this->makeExtensionTree();
        $warnings = LifecycleWarnings::current();
        $warnings->clear();
        $warnings->add('layout_register', 'admin_dashboard.json', '앞선 단계의 경고');

        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('resyncAllActiveDeclarativeArtifacts')->once()->andReturnUsing(function () use ($warnings): array {
            $warnings->add('field_definitions', 'sirsoft-board', 'Class "Modules\Sirsoft\Board\Fields\GuestAuthorFields" not found');
            $warnings->add('field_definitions', 'sirsoft-ecommerce', 'Class "Modules\Sirsoft\Ecommerce\Fields\OrderContactFields" not found');
            $warnings->add('schedules', 'sirsoft-board', '다른 사유의 실패');

            return [];
        });
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('resyncAllActiveDeclarativeArtifacts')->once()->andReturnUsing(function () use ($warnings): array {
            $warnings->add('field_definitions', 'sirsoft-marketing', 'Class "Plugins\Sirsoft\Marketing\Fields\ConsentFields" not found');

            return [];
        });
        $this->app->instance(ModuleManager::class, $modules);
        $this->app->instance(PluginManager::class, $plugins);

        try {
            (new SyncActiveExtensionSchedules($root))->run($this->context());

            $this->assertSame(
                [['layout_register', 'admin_dashboard.json'], ['schedules', 'sirsoft-board']],
                array_map(static fn (array $w): array => [$w['step'], $w['target']], $warnings->all()),
                '확장 갱신 전이라 아직 없는 확장 클래스의 항목 선언 실패가 경고 표에 남았거나, 다른 경고가 사라졌다'
            );
        } finally {
            $warnings->clear();
            File::deleteDirectory($root);
        }
    }

    /**
     * 01 — 클래스가 활성 디렉토리에도 있는데 못 찾았거나(실제 결함), 새 판에도 없는 클래스면 실패 경고를 그대로 남긴다.
     *
     * @scenario case=extensions_real_field_failure
     *
     * @effects real_field_sync_failure_still_reported
     *
     * @return void 반환값 없음
     */
    public function test_real_field_sync_failures_are_still_reported(): void
    {
        $root = $this->makeExtensionTree();
        // 게시판은 이미 갱신돼 활성 디렉토리에도 클래스 파일이 있다 — 그런데도 못 찾았다면 실제 결함이다.
        File::ensureDirectoryExists($root.'/modules/sirsoft-board/src/Fields');
        file_put_contents($root.'/modules/sirsoft-board/src/Fields/GuestAuthorFields.php', '<?php');

        $warnings = LifecycleWarnings::current();
        $warnings->clear();

        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('resyncAllActiveDeclarativeArtifacts')->once()->andReturnUsing(function () use ($warnings): array {
            $warnings->add('field_definitions', 'sirsoft-board', 'Class "Modules\Sirsoft\Board\Fields\GuestAuthorFields" not found');
            $warnings->add('field_definitions', 'sirsoft-ecommerce', 'Class "Modules\Sirsoft\Ecommerce\Fields\NoSuchFields" not found');

            return [];
        });
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('resyncAllActiveDeclarativeArtifacts')->once()->andReturn([]);
        $this->app->instance(ModuleManager::class, $modules);
        $this->app->instance(PluginManager::class, $plugins);

        try {
            (new SyncActiveExtensionSchedules($root))->run($this->context());

            $this->assertSame(
                ['sirsoft-board', 'sirsoft-ecommerce'],
                array_map(static fn (array $w): string => $w['target'], $warnings->all()),
                '실제 항목 선언 실패를 경고 표에서 지웠다'
            );
        } finally {
            $warnings->clear();
            File::deleteDirectory($root);
        }
    }

    /**
     * 새 판 `_bundled` 에만 항목 클래스가 있고 활성 디렉토리(7.1.x)에는 없는 확장 트리를 만듭니다.
     *
     * @return string 임시 설치 루트
     */
    private function makeExtensionTree(): string
    {
        $root = storage_path('framework/testing/resync-720-'.uniqid());

        foreach ([
            'modules/_bundled/sirsoft-board/src/Fields/GuestAuthorFields.php',
            'modules/_bundled/sirsoft-ecommerce/src/Fields/OrderContactFields.php',
            'plugins/_bundled/sirsoft-marketing/src/Fields/ConsentFields.php',
        ] as $relative) {
            File::ensureDirectoryExists(dirname($root.'/'.$relative));
            file_put_contents($root.'/'.$relative, '<?php');
        }

        foreach (['modules/sirsoft-board/src', 'modules/sirsoft-ecommerce/src', 'plugins/sirsoft-marketing/src'] as $active) {
            File::ensureDirectoryExists($root.'/'.$active);
        }

        return $root;
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
