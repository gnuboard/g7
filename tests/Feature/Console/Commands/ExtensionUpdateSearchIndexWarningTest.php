<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\Module\UpdateModuleCommand;
use App\Console\Commands\Plugin\UpdatePluginCommand;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Models\Module;
use App\Models\Plugin;
use App\Search\SearchIndexMaintenanceManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 업데이트가 끝난 뒤 검색 인덱스 재생성이 실패해도 「업데이트 실패」로 끝나지 않는다 (7.1.6).
 *
 * `module:update`·`plugin:update` 는 업데이트 성공 뒤 `--rebuild-search-index` 가 있으면 인덱스를 다시 만든다.
 * 그 호출이 바깥 실패 처리 안에 있어, 재생성 오류가 ❌ 와 「업데이트 실패」로 보였다(업데이트는 이미 끝났는데).
 * 지금은 경고 목록에 넣어 「완료했지만 경고 있음」(종료코드 1)으로 알린다.
 *
 * @scenario-manifest tests/scenarios/extension-update-failure-cleanup.yaml
 */
class ExtensionUpdateSearchIndexWarningTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 수집된 경고를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        LifecycleWarnings::current()->clear();

        parent::tearDown();
    }

    /**
     * 출처별 커맨드 축.
     *
     * @return array<string, array{0: string}> [출처]
     */
    public static function sources(): array
    {
        return ['module' => ['module'], 'plugin' => ['plugin']];
    }

    /**
     * 검색 인덱스 재생성이 실패해도 업데이트 완료를 알리고, 실패는 경고 표로 보인다.
     *
     * @scenario case=search_index_fails_after_update
     *
     * @effects search_index_failure_reported_as_warning_not_update_failure
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('sources')]
    public function test_search_index_failure_after_update_is_a_warning(string $source): void
    {
        $maintenance = Mockery::mock(SearchIndexMaintenanceManager::class);
        $maintenance->shouldReceive('hasMaintainer')->andReturn(true);
        $maintenance->shouldReceive('unavailableReason')->andReturn(null);
        $maintenance->shouldReceive('repairStale')->once()->andThrow(new \RuntimeException('index is locked'));
        $this->app->instance(SearchIndexMaintenanceManager::class, $maintenance);

        [$name, $args, $identifier] = $this->registerUpdateCommand($source);

        $exit = Artisan::call($name, $args + ['--rebuild-search-index' => true]);
        $output = Artisan::output();

        // 업데이트는 끝났다고 알린다 — ❌ 실패 안내가 아니다
        $this->assertStringContainsString(__("{$source}s.commands.update.success", [$source => $identifier]), $output);
        $this->assertStringNotContainsString('❌', $output);
        // 재생성 실패는 경고 표에 단계·대상·사유로 나온다
        $this->assertStringContainsString('search_index', $output);
        $this->assertStringContainsString('index is locked', $output);
        // 경고가 있으면 종료코드 1 (완료했지만 경고 있음 표지와 함께)
        $this->assertSame(1, $exit);
        $this->assertStringContainsString(LifecycleWarnings::CLI_MARKER, $output);
    }

    /**
     * 재생성이 성공하면 지금처럼 종료코드 0 이다.
     *
     * @scenario case=search_index_ok_after_update
     *
     * @effects search_index_success_keeps_exit_zero
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('sources')]
    public function test_update_without_search_index_request_exits_zero(string $source): void
    {
        $maintenance = Mockery::mock(SearchIndexMaintenanceManager::class);
        $maintenance->shouldReceive('hasMaintainer')->andReturn(false);
        $maintenance->shouldNotReceive('repairStale');
        $this->app->instance(SearchIndexMaintenanceManager::class, $maintenance);

        [$name, $args] = $this->registerUpdateCommand($source);

        $this->assertSame(0, Artisan::call($name, $args));
        $this->assertStringNotContainsString('search_index', Artisan::output());
    }

    /**
     * 출처의 update 커맨드를 「업데이트 성공」 대역 관리자로 등록하고 artisan 인자를 돌려줍니다.
     *
     * @param  string  $source  출처
     * @return array{0: string, 1: array<string, mixed>, 2: string} [커맨드 이름, 인자, 식별자]
     */
    private function registerUpdateCommand(string $source): array
    {
        $identifier = "probe-{$source}";
        $check = ['update_available' => true, 'current_version' => '1.0.0', 'latest_version' => '1.1.0', 'update_source' => 'bundled'];
        $result = ['success' => true, 'from_version' => '1.0.0', 'to_version' => '1.1.0', 'layout_strategy' => 'overwrite', 'layout_sync' => null];
        $modified = ['modified_count' => 0, 'modified_layouts' => []];

        if ($source === 'module') {
            $manager = Mockery::mock(ModuleManager::class, function (MockInterface $m) use ($identifier, $check, $result, $modified) {
                $m->shouldReceive('loadModules')->once();
                $m->shouldReceive('checkModuleUpdate')->with($identifier, null)->andReturn($check);
                $m->shouldReceive('hasModifiedLayouts')->with($identifier)->andReturn($modified);
                $m->shouldReceive('updateModule')->once()->andReturn($result);
            });
            $repository = Mockery::mock(ModuleRepositoryInterface::class);
            $repository->shouldReceive('findByIdentifier')->with($identifier)->andReturn(new Module(['identifier' => $identifier, 'version' => '1.0.0']));
            $command = new UpdateModuleCommand($manager, $repository);
        } else {
            $manager = Mockery::mock(PluginManager::class, function (MockInterface $m) use ($identifier, $check, $result, $modified) {
                $m->shouldReceive('loadPlugins')->once();
                $m->shouldReceive('checkPluginUpdate')->with($identifier, null)->andReturn($check);
                $m->shouldReceive('hasModifiedLayouts')->with($identifier)->andReturn($modified);
                $m->shouldReceive('updatePlugin')->once()->andReturn($result);
            });
            $repository = Mockery::mock(PluginRepositoryInterface::class);
            $repository->shouldReceive('findByIdentifier')->with($identifier)->andReturn(new Plugin(['identifier' => $identifier, 'version' => '1.0.0']));
            $command = new UpdatePluginCommand($manager, $repository);
        }

        $command->setLaravel($this->app);
        $this->app[Kernel::class]->registerCommand($command);

        return ["{$source}:update", ['identifier' => $identifier, '--force' => true, '--layout-strategy' => 'overwrite'], $identifier];
    }
}
