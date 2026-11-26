<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\ExtensionBatchUpdateContext;
use App\Extension\TemplateManager;
use App\Listeners\ExtensionUpdateQueueListener;
use App\Services\CoreUpdateService;
use App\Services\ExtensionStaticCacheService;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Console\NoSpawnExecuteBundledUpdatesCommand;
use Tests\TestCase;

/**
 * 확장 2개 이상을 한꺼번에 올리는 일괄 업데이트에서 항목마다의 부수 작업을 끝으로 모으는지 검증.
 *
 * 회귀 가드(7.2.1 웹 코어 업데이트 실측 — 번들 31개, 항목당 약 22초): 웹 코어 업데이트는 항목마다 이 명령을 새로
 * 부르는데 (가) 항목 프로세스 표시 없이 불러 항목마다 정적 게시를 다시 예약했고, (나) 시작 때 확장 오토로드·훅·config
 * 캐시를 늘 다시 구웠으며(직전 항목이 방금 구운 것과 같다), (다) 확장마다 큐 워커 재시작 신호를 보냈다.
 * 확장 하나만 올리는 업데이트(1개짜리 묶음·표시 없는 매니페스트)는 셋 다 종전과 같아야 한다.
 */
class BundledBatchUpdateDeferralTest extends TestCase
{
    /** @var array<int, string> 지울 임시 매니페스트 */
    private array $tempFiles = [];

    /**
     * 게시 예약·root 흉내·임시 파일을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            File::delete($path);
        }
        ExtensionStaticCacheService::resetPublishScheduleForTesting();
        ExtensionStaticCacheService::fakeRootProcessForTesting(null);

        parent::tearDown();
    }

    /**
     * 웹 코어 업데이트의 일괄(2개 이상) 항목은 정적 게시를 예약하지 않는다 — 마무리 단계가 한 번 예약한다.
     *
     * @scenario batch=batch, caller=web_update
     *
     * @effects web_batch_item_leaves_static_publish_to_finalize
     *
     * @return void 반환값 없음
     */
    public function test_web_batch_item_does_not_schedule_static_publish(): void
    {
        $this->productionWithoutRoot();
        $this->fakeExtensionWork(autoloadRebuilds: null);

        $this->runChild($this->pluginManifest(ExtensionBatchUpdateContext::begin(2)));

        $this->assertFalse(ExtensionStaticCacheService::isPublishScheduledForTesting(), '일괄 항목이 정적 게시를 예약했다 — 항목마다 전 로케일 병합·템플릿 복사를 되풀이한다');
    }

    /**
     * 확장 하나만 올리면(1개짜리 묶음·표시 없는 매니페스트) 종전대로 끝에서 정적 게시를 예약한다.
     *
     * @scenario batch=single, caller=web_update
     *
     * @effects single_item_update_keeps_static_publish
     *
     * @return void 반환값 없음
     */
    public function test_single_item_update_still_schedules_static_publish(): void
    {
        foreach ([ExtensionBatchUpdateContext::begin(1), null] as $batch) {
            ExtensionStaticCacheService::resetPublishScheduleForTesting();
            $this->productionWithoutRoot();
            $this->fakeExtensionWork(autoloadRebuilds: 1);

            $this->runChild($this->pluginManifest($batch));

            $this->assertTrue(ExtensionStaticCacheService::isPublishScheduledForTesting(), '확장 하나만 올리는 업데이트가 정적 게시를 빠뜨렸다');
        }
    }

    /**
     * 일괄 항목은 캐시가 이 묶음 안에서 구워졌고 지금 상태와 맞으면 시작 때 오토로드·훅·config 캐시를 다시 굽지 않는다.
     *
     * @scenario batch=batch, caller=web_update, caller=cli_batch
     *
     * @effects batch_item_skips_redundant_startup_autoload_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_batch_item_skips_startup_rebuild_when_caches_are_fresh(): void
    {
        $batch = ExtensionBatchUpdateContext::begin(2, 1_700_000_000);
        $this->fakeExtensionWork(autoloadRebuilds: 0, freshSince: [1_700_000_000, true]);

        $this->runChild($this->pluginManifest($batch));
    }

    /**
     * 일괄 항목이라도 캐시가 낡았으면(묶음 전에 구웠거나 지금 상태와 다르면) 종전대로 시작 때 다시 굽는다.
     *
     * @scenario batch=batch, caller=web_update, caller=cli_batch
     *
     * @effects batch_item_rebuilds_stale_startup_autoload
     *
     * @return void 반환값 없음
     */
    public function test_batch_item_rebuilds_when_caches_are_stale(): void
    {
        $batch = ExtensionBatchUpdateContext::begin(2, 1_700_000_000);
        $this->fakeExtensionWork(autoloadRebuilds: 1, freshSince: [1_700_000_000, false]);

        $this->runChild($this->pluginManifest($batch));
    }

    /**
     * 템플릿·언어팩만 담은 일괄 항목은 PHP 클래스를 들이지 않으므로 「묶음 안에서 구웠는가」를 따지지 않고 지금 상태와만 대조한다.
     *
     * @scenario batch=batch, caller=web_update, caller=cli_batch
     *
     * @effects batch_template_item_skips_startup_autoload_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_batch_template_item_checks_freshness_without_batch_window(): void
    {
        $batch = ExtensionBatchUpdateContext::begin(2, 1_700_000_000);
        $this->fakeExtensionWork(autoloadRebuilds: 0, freshSince: [null, true], plugins: 0);

        $manifest = ExecuteBundledUpdatesCommand::manifestForItem(['type' => 'template', 'identifier' => 'probe-template', 'strategy' => 'overwrite']);
        $manifest[ExtensionBatchUpdateContext::MANIFEST_KEY] = $batch->toManifest();
        $this->runChild($manifest);
    }

    /**
     * 확장 하나만 올리는 업데이트는 캐시 상태를 묻지도 않고 종전대로 시작 때 다시 굽는다.
     *
     * @scenario batch=single, caller=web_update, caller=cli_batch
     *
     * @effects single_item_update_keeps_startup_autoload_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_single_item_update_always_rebuilds_at_startup(): void
    {
        foreach ([ExtensionBatchUpdateContext::begin(1), null] as $batch) {
            $this->fakeExtensionWork(autoloadRebuilds: 1, freshSince: false);
            $this->runChild($this->pluginManifest($batch));
        }
    }

    /**
     * 일괄을 시작한 프로세스(명령줄 코어 업데이트의 일괄 자식)는 늘 시작 때 굽는다 — 묶음의 첫 굽기다.
     *
     * @scenario batch=batch, caller=cli_batch
     *
     * @effects batch_owner_keeps_startup_autoload_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_batch_owner_always_rebuilds_at_startup(): void
    {
        $this->app[Kernel::class]->registerCommand($this->app->make(NoSpawnExecuteBundledUpdatesCommand::class));
        $this->fakeExtensionWork(autoloadRebuilds: 1, freshSince: false, plugins: 2, batchRestarts: 1);

        $this->runChild([
            'modules' => [],
            'plugins' => [['identifier' => 'probe-plugin-a', 'strategy' => 'overwrite'], ['identifier' => 'probe-plugin-b', 'strategy' => 'overwrite']],
            'templates' => [],
            'lang_packs' => [],
        ]);
    }

    /**
     * 일괄을 시작한 프로세스는 묶음 끝에서 큐 워커 재시작을 한 번 보낸다(항목마다의 재시작은 묶음 안에서 미뤄진다).
     *
     * @scenario batch=batch, caller=cli_batch
     *
     * @effects batch_update_restarts_queue_once_at_end
     *
     * @return void 반환값 없음
     */
    public function test_batch_owner_restarts_queue_once_at_end(): void
    {
        $this->app[Kernel::class]->registerCommand($this->app->make(NoSpawnExecuteBundledUpdatesCommand::class));
        $this->fakeExtensionWork(autoloadRebuilds: null, plugins: 2, batchRestarts: 1, deferDuringUpdate: true);

        $this->runChild([
            'modules' => [],
            'plugins' => [['identifier' => 'probe-plugin-a', 'strategy' => 'overwrite'], ['identifier' => 'probe-plugin-b', 'strategy' => 'overwrite']],
            'templates' => [],
            'lang_packs' => [],
        ]);
    }

    /**
     * 묶음의 한 항목이 실패해(그 확장은 스스로 되돌린다) 일괄이 실패로 끝나도 끝 재시작은 한 번 보낸다 — 성공한 항목의 새 코드를 워커가 물도록.
     *
     * @scenario batch=batch, caller=cli_batch
     *
     * @effects batch_update_restarts_queue_once_at_end
     *
     * @return void 반환값 없음
     */
    public function test_batch_owner_restarts_queue_even_when_an_item_fails(): void
    {
        $this->app[Kernel::class]->registerCommand($this->app->make(NoSpawnExecuteBundledUpdatesCommand::class));
        $this->fakeExtensionWork(autoloadRebuilds: null, plugins: 0, batchRestarts: 1);
        $this->app->instance(PluginManager::class, Mockery::mock(PluginManager::class, function (MockInterface $m): void {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('updatePlugin')->with('probe-plugin-a', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->once()->andThrow(new \RuntimeException('update failed and rolled back'));
            $m->shouldReceive('updatePlugin')->with('probe-plugin-b', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->once()->andReturn(['success' => true]);
        }));

        $path = storage_path('app/test_bundled_batch_'.uniqid().'.json');
        $this->tempFiles[] = $path;
        File::put($path, (string) json_encode([
            'modules' => [],
            'plugins' => [['identifier' => 'probe-plugin-a', 'strategy' => 'overwrite'], ['identifier' => 'probe-plugin-b', 'strategy' => 'overwrite']],
            'templates' => [],
            'lang_packs' => [],
        ]));

        $this->assertSame(1, Artisan::call('core:execute-bundled-updates', ['--manifest' => $path, '--force' => true]));
        $this->assertStringContainsString('"success":1,"failed":1', Artisan::output());
    }

    /**
     * 묶음의 구성원(항목 프로세스·웹 항목)은 끝 재시작을 보내지 않는다 — 시작한 쪽이 보낸다. 1개짜리도 보내지 않는다(확장마다 보냈다).
     *
     * @scenario batch=single, batch=batch, caller=web_update, caller=cli_batch
     *
     * @effects batch_member_leaves_queue_restart_to_owner, single_update_keeps_per_extension_queue_restart
     *
     * @return void 반환값 없음
     */
    public function test_members_and_single_updates_do_not_send_batch_restart(): void
    {
        $this->fakeExtensionWork(autoloadRebuilds: null, batchRestarts: 0, deferDuringUpdate: true);
        $this->runChild($this->pluginManifest(ExtensionBatchUpdateContext::begin(2)));

        $this->fakeExtensionWork(autoloadRebuilds: null, batchRestarts: 0, deferDuringUpdate: false);
        $this->runChild($this->pluginManifest(null));
    }

    /**
     * 운영 환경·웹 계정을 흉내 냅니다 (정적 게시 예약 조건).
     *
     * @return void 반환값 없음
     */
    private function productionWithoutRoot(): void
    {
        $this->app['env'] = 'production';
        ExtensionStaticCacheService::fakeRootProcessForTesting(false);
    }

    /**
     * 플러그인 하나짜리 항목 매니페스트를 만듭니다 (웹 코어 업데이트가 항목마다 쓰는 모양).
     *
     * @param  ExtensionBatchUpdateContext|null  $batch  실을 묶음 (없으면 표시 없음)
     * @return array<string, mixed> 매니페스트
     */
    private function pluginManifest(?ExtensionBatchUpdateContext $batch): array
    {
        $manifest = ExecuteBundledUpdatesCommand::manifestForItem(['type' => 'plugin', 'identifier' => 'probe-plugin', 'strategy' => 'overwrite']);
        $manifest['defer_static_publish'] = true;
        if ($batch !== null) {
            $manifest[ExtensionBatchUpdateContext::MANIFEST_KEY] = $batch->toManifest();
        }

        return $manifest;
    }

    /**
     * 확장 작업을 대역으로 바꿉니다.
     *
     * @param  int|null  $autoloadRebuilds  시작 때 오토로드 재생성 기대 횟수 (null 이면 따지지 않음)
     * @param  array{0: int|null, 1: bool}|false|null  $freshSince  캐시 신선도 질의 기대 [기준 시각, 답] (false 면 묻지 않아야 함, null 이면 따지지 않음)
     * @param  int  $plugins  올릴 플러그인 수
     * @param  int|null  $batchRestarts  묶음 끝 재시작 기대 횟수 (null 이면 따지지 않음)
     * @param  bool|null  $deferDuringUpdate  업데이트 도중 항목마다의 작업을 미루는 중이어야 하는지 (null 이면 따지지 않음)
     * @return void 반환값 없음
     */
    private function fakeExtensionWork(?int $autoloadRebuilds, array|false|null $freshSince = null, int $plugins = 1, ?int $batchRestarts = null, ?bool $deferDuringUpdate = null): void
    {
        $this->app->instance(ExtensionManager::class, Mockery::mock(ExtensionManager::class, function (MockInterface $m) use ($autoloadRebuilds, $freshSince): void {
            $rebuild = $m->shouldReceive('updateComposerAutoload');
            $autoloadRebuilds === null ? $rebuild->zeroOrMoreTimes() : $rebuild->times($autoloadRebuilds);

            if ($freshSince === false) {
                $m->shouldReceive('extensionCachesFreshSince')->never();
            } elseif (is_array($freshSince)) {
                $m->shouldReceive('extensionCachesFreshSince')->once()->with($freshSince[0])->andReturn($freshSince[1]);
            } else {
                $m->shouldReceive('extensionCachesFreshSince')->zeroOrMoreTimes()->andReturn(false);
            }
        }));
        $this->app->instance(CoreUpdateService::class, Mockery::mock(CoreUpdateService::class, fn (MockInterface $m) => $m->shouldReceive('sweepEmptyStagingDirectories')->zeroOrMoreTimes()));
        $this->app->instance(PluginManager::class, Mockery::mock(PluginManager::class, function (MockInterface $m) use ($plugins, $deferDuringUpdate): void {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('updatePlugin')->times($plugins)->andReturnUsing(function () use ($deferDuringUpdate): array {
                if ($deferDuringUpdate !== null) {
                    $this->assertSame($deferDuringUpdate, ExtensionBatchUpdateContext::defersPerExtensionWork(), '업데이트 도중 항목마다의 작업 미루기 상태가 틀렸다');
                }

                return ['success' => true];
            });
        }));
        $this->app->instance(TemplateManager::class, Mockery::mock(TemplateManager::class, fn (MockInterface $m) => $m->shouldIgnoreMissing()->shouldReceive('updateTemplate')->andReturn(['success' => true])));
        $this->app->instance(ExtensionUpdateQueueListener::class, Mockery::mock(ExtensionUpdateQueueListener::class, function (MockInterface $m) use ($batchRestarts): void {
            $restart = $m->shouldReceive('restartAfterBatch');
            $batchRestarts === null ? $restart->zeroOrMoreTimes() : $restart->times($batchRestarts);
        }));
    }

    /**
     * 매니페스트로 번들 일괄 업데이트 명령을 돌리고 성공 종료를 단언합니다.
     *
     * @param  array<string, mixed>  $manifest  매니페스트
     * @return void 반환값 없음
     */
    private function runChild(array $manifest): void
    {
        $path = storage_path('app/test_bundled_batch_'.uniqid().'.json');
        $this->tempFiles[] = $path;
        File::put($path, (string) json_encode($manifest));

        $exit = Artisan::call('core:execute-bundled-updates', ['--manifest' => $path, '--force' => true]);

        $this->assertSame(0, $exit, Artisan::output());
        Mockery::getContainer()->mockery_verify();
    }

    /**
     * 명령줄 코어 업데이트가 일괄 자식을 띄우지 못해 같은 프로세스에서 돌 때도 2개 이상이면 확장마다의 재시작을 미루고 끝에서 한 번 보낸다.
     *
     * @scenario batch=batch, caller=cli_in_process_fallback
     *
     * @effects batch_update_restarts_queue_once_at_end
     *
     * @return void 반환값 없음
     */
    public function test_cli_in_process_fallback_batch_restarts_once_at_end(): void
    {
        $this->fakeExtensionWork(autoloadRebuilds: null, plugins: 2, batchRestarts: 1, deferDuringUpdate: true);

        $this->runCliInProcessFallback(['probe-plugin-a', 'probe-plugin-b']);
    }

    /**
     * 같은 프로세스 경로에서 확장 하나만 올리면 종전대로 확장 자리에서 재시작하고 끝 재시작은 없다.
     *
     * @scenario batch=single, caller=cli_in_process_fallback
     *
     * @effects single_update_keeps_per_extension_queue_restart
     *
     * @return void 반환값 없음
     */
    public function test_cli_in_process_fallback_single_item_is_unchanged(): void
    {
        $this->fakeExtensionWork(autoloadRebuilds: null, plugins: 1, batchRestarts: 0, deferDuringUpdate: false);

        $this->runCliInProcessFallback(['probe-plugin-a']);
    }

    /**
     * 명령줄 코어 업데이트의 같은 프로세스 일괄 처리(일괄 자식을 띄우지 못한 경우)를 돌립니다.
     *
     * @param  array<int, string>  $plugins  올릴 플러그인 식별자
     * @return void 반환값 없음
     */
    private function runCliInProcessFallback(array $plugins): void
    {
        $command = $this->app->make(CoreUpdateCommand::class);
        $input = new ArrayInput([]);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput));

        $result = (new \ReflectionMethod($command, 'executeBulkUpdateInProcess'))->invoke(
            $command,
            $this->app->make(ModuleManager::class),
            $this->app->make(PluginManager::class),
            $this->app->make(TemplateManager::class),
            ['modules' => [], 'plugins' => array_map(static fn (string $id): array => ['identifier' => $id], $plugins), 'templates' => [], 'lang_packs' => []],
            [],
            false,
        );

        $this->assertSame(count($plugins), $result['success']);
        Mockery::getContainer()->mockery_verify();
    }
}
