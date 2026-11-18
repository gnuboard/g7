<?php

namespace Tests\Feature;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Execution\CommandRunnerResolver;
use App\Execution\CommandRunResult;
use App\Execution\Contracts\CommandRunner;
use App\Execution\ExecutionMode;
use App\Extension\Integrity\PackageVerification;
use App\Services\Core\BundledSourcePlanner;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 코어 업데이트가 실패해 되돌린 뒤 파일 적용 단계의 루트·서버 설정 조각 안내가 남지 않는다
 *
 * 적용 단계는 운영자가 고친 서버 설정 조각(`deploy/nginx/g7-layout2.conf`)을 보존하고 새 판본을 `.dist` 로 옆에 두며
 * 「두 파일을 합친 뒤 .dist 를 지우세요」를 알린다. 그 뒤 단계가 실패해 되돌리면 복원이 `.dist` 와 해시 원장을 걷는데
 * (`CoreUpdateService::removeDeployFragmentSidecars`), 관리자 화면 실행 상태(`run.warnings`)에 쌓인 적용 단계 안내는
 * 그대로 남아 없는 파일을 합치라고 했다(실측). 명령줄 `core:update` 도 적용 단계에서 같은 안내를 찍은 뒤 되돌리면서
 * 그것이 무효가 됐다는 말이 없었다.
 *
 * 이 시험이 잠그는 것:
 *  - 관리자 화면: 되돌리기의 복원이 끝나면 적용 단계 안내를 `warnings` 에서 걷고 「되돌리며 해당 없어졌다」 통보 1건을
 *    `notices` 에 남긴다. 복원 자체가 낸 경고와 적용 단계와 무관한 경고는 그대로 둔다.
 *  - 관리자 화면: 복원이 예외로 끝나 파일이 적용 상태일 수 있으면 적용 단계 안내를 그대로 둔다.
 *  - 명령줄: 되돌린 뒤 같은 통보를 출력한다.
 *
 * 시나리오 SSoT: tests/scenarios/core-update-rollback-apply-warnings.yaml
 */
class CoreUpdateRollbackApplyWarningsTest extends TestCase
{
    /** 적용 단계 안내 대용 문구 (`deploy_fragment_preserved` 와 같은 자리) */
    private const DIST_WARNING = '서버 설정 조각(deploy/nginx/g7-layout2.conf)은 운영자가 수정한 판본이라 그대로 두고, 새 판본을 deploy/nginx/g7-layout2.conf.dist 로 옆에 두었습니다.';

    /** 격리 루트 */
    private string $root;

    /** 원래 storage 경로 */
    private string $originalStoragePath;

    /**
     * 격리 storage 를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_rollback_warnings_'.uniqid());
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');

        $this->originalStoragePath = storage_path();
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
    }

    /**
     * 경로를 되돌리고 픽스처를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);

        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 관리자 화면 실측 재현: 적용 단계가 `.dist` 안내를 남긴 뒤 실패해 되돌리면, 복원이 끝난 시점에 그 안내가 경고에서
     * 빠지고 「되돌리며 해당 없어졌다」 통보로 바뀐다. 복원이 낸 경고와 적용 단계와 무관한 경고는 남는다.
     *
     * @scenario case=web_restore_finished
     *
     * @effects apply_warnings_withdrawn_after_restore, withdrawn_notice_added, other_warnings_kept
     *
     * @return void 반환값 없음
     */
    public function test_web_rollback_withdraws_apply_step_warnings(): void
    {
        $service = $this->webService(fn (): array => [
            'done' => true, 'phase' => 'done', 'priority_index' => 0, 'target' => 0, 'cursor' => null,
            'failed_targets' => [],
            'root_file_warnings' => ['되돌린 뒤 루트 진입·보호 파일이 없습니다 (.htaccess)'],
        ]);

        $state = $this->rollingBackState();
        $state->appendWarnings(['적용과 무관한 앞선 경고']);
        $this->recordApplyWarnings($service, $state);

        $this->advance($service);

        $after = $this->state()->all();
        $this->assertNotContains(self::DIST_WARNING, $after['warnings'] ?? [], '되돌리기가 지운 .dist 를 합치라는 적용 단계 안내가 남았다');
        $this->assertContains('적용과 무관한 앞선 경고', $after['warnings'] ?? []);
        $this->assertContains('되돌린 뒤 루트 진입·보호 파일이 없습니다 (.htaccess)', $after['warnings'] ?? [], '복원이 낸 경고가 사라졌다');
        $this->assertContains(
            trans_count('settings.core_update.rollback_apply_warnings_withdrawn', ['count' => 1]),
            $after['notices'] ?? [],
            '적용 단계 안내가 되돌리며 해당 없어졌다는 통보가 없다'
        );
    }

    /**
     * 복원이 예외로 끝나면(파일이 적용 상태일 수 있다) 적용 단계 안내를 걷지 않는다.
     *
     * @scenario case=web_restore_threw
     *
     * @effects apply_warnings_kept_when_restore_failed
     *
     * @return void 반환값 없음
     */
    public function test_web_rollback_keeps_apply_warnings_when_restore_throws(): void
    {
        $service = $this->webService(function (): array {
            throw new \RuntimeException('restore boom');
        });

        $state = $this->rollingBackState();
        $this->recordApplyWarnings($service, $state);

        $this->advance($service);

        $after = $this->state()->all();
        $this->assertContains(self::DIST_WARNING, $after['warnings'] ?? [], '복원이 실패했는데 적용 단계 안내를 걷었다');
        $this->assertNotContains(
            trans_count('settings.core_update.rollback_apply_warnings_withdrawn', ['count' => 1]),
            $after['notices'] ?? []
        );
    }

    /**
     * 번들 단계에서 일부 확장이 실패한 뒤 마무리 단계에서 실패해 되돌리면, 「코어는 정상 반영」이라던 경고를 「코어는 되돌렸고
     * 이미 갱신된 확장은 그대로」로 바꾼다. 확장 실패 사실은 남긴다.
     *
     * @scenario case=web_bundled_failure_then_rollback
     *
     * @effects bundled_failure_warning_restated_after_rollback
     *
     * @return void 반환값 없음
     */
    public function test_web_rollback_restates_bundled_partial_failure(): void
    {
        $service = $this->webService(fn (): array => [
            'done' => true, 'phase' => 'done', 'priority_index' => 0, 'target' => 0, 'cursor' => null,
            'failed_targets' => [], 'root_file_warnings' => [],
        ]);
        $service->shouldReceive('collectBundledExtensionUpdates')->andReturn(['modules' => [['identifier' => 'vendor-broken']], 'plugins' => [], 'templates' => []]);
        $langPacks = Mockery::mock(LanguagePackService::class);
        $langPacks->shouldReceive('collectBundledLangPackUpdates')->andReturn([]);
        $this->app->instance(LanguagePackService::class, $langPacks);

        $runner = new class implements CommandRunner
        {
            /**
             * 실행 모드.
             *
             * @return ExecutionMode 같은 프로세스
             */
            public function mode(): ExecutionMode
            {
                return ExecutionMode::Inprocess;
            }

            /**
             * 번들 항목 하나가 실패한 결과를 돌려준다.
             *
             * @param  string  $command  커맨드
             * @param  array<string, mixed>  $parameters  인자
             * @return CommandRunResult 결과
             */
            public function run(string $command, array $parameters = []): CommandRunResult
            {
                return new CommandRunResult(1, '[BUNDLED-RESULT] {"success":0,"failed":1}', ExecutionMode::Inprocess);
            }
        };
        $resolver = Mockery::mock(CommandRunnerResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($runner);

        $this->rollingBackState(new CoreUpdateOptions(updateBundledExtensions: true));
        $orchestrator = new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), $resolver, new CoreUpdateTimeBudget(null, 300));
        (new \ReflectionMethod($orchestrator, 'stepBundled'))->invoke($orchestrator);

        $partial = trans_count('settings.core_update.web.bundled_partial_failure', ['failed' => '1', 'count' => 1]);
        $this->assertContains($partial, $this->state()->all()['warnings'] ?? [], '전제: 번들 단계가 부분 실패 경고를 남겼다');

        $this->advance($service);

        $warnings = $this->state()->all()['warnings'] ?? [];
        $this->assertNotContains($partial, $warnings, '코어를 되돌렸는데 「코어는 정상 반영」 경고가 남았다');
        $this->assertContains(trans_count('settings.core_update.web.bundled_partial_failure_rolled_back', ['failed' => '1', 'count' => 1]), $warnings, '확장 실패 사실이 사라졌다');
    }

    /**
     * 명령줄 `core:update` 도 적용 단계 안내를 찍은 뒤 되돌렸으면 그 안내가 해당 없어졌다고 출력한다.
     *
     * @scenario case=cli_restore_finished
     *
     * @effects cli_prints_withdrawn_notice_after_restore
     *
     * @return void 반환값 없음
     */
    public function test_cli_rollback_prints_withdrawn_notice(): void
    {
        $release = $this->writeRelease();
        $backup = $this->root.DIRECTORY_SEPARATOR.'backup';
        File::ensureDirectoryExists($backup);
        $this->app->instance(BundledSourcePlanner::class, new BundledSourcePlanner(...$this->plannerRepositories()));
        config(['app.update.spawn_failure_mode' => 'fallback']);

        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('acquireRunLock')->andReturn(true);
        $service->shouldReceive('releaseRunLock');
        $service->shouldReceive('getLastRootFileWarnings')->andReturn([]);
        $service->shouldReceive('normalizeRuntimeOwnershipAfterRootRun');
        $service->shouldReceive('preflightReleaseIntegrity');
        $service->shouldReceive('verifyCoreZipIntegrity')->andReturn(PackageVerification::unsigned());
        $service->shouldReceive('confirmCoreIntegrity')->andReturnUsing(fn ($verification) => $verification);
        $service->shouldReceive('validatePendingPath')->andReturn(['valid' => true]);
        $service->shouldReceive('enableMaintenanceMode')->once()->andReturn('secret');
        $service->shouldReceive('validatePendingUpdate')->once();
        $service->shouldReceive('copySourceToPending')->once()->andReturn($release);
        $service->shouldReceive('createBackup')->once()->andReturn($backup);
        $service->shouldReceive('snapshotOwnership')->andReturn([]);
        $service->shouldReceive('resolveStagingRoot')->andReturn($release);
        $service->shouldReceive('snapshotOwnershipDetailed')->andReturn([]);
        $service->shouldReceive('isComposerUnchangedForCore')->andReturn(true);
        $service->shouldReceive('applyUpdate')->once()->andReturn(['root_file_warnings' => [self::DIST_WARNING]]);
        $service->shouldReceive('runMigrations')->once()->andThrow(new \RuntimeException('migration failed'));
        $service->shouldReceive('restoreFromBackup')->once()->andReturn(['failed_targets' => [], 'root_file_warnings' => []]);
        $service->shouldReceive('clearAllCaches')->once();
        $service->shouldReceive('cleanupPending')->once();
        $service->shouldReceive('generateFailureReport')->andReturn('report.log');
        $service->shouldReceive('disableMaintenanceMode')->once();
        $this->app->instance(CoreUpdateService::class, $service);

        $this->artisan('core:update', ['--source' => $release, '--bundled-sources' => 'none'])
            ->expectsQuestion(__('settings.core_update.console.confirm_update').' (yes/no) [no]', 'yes')
            ->expectsOutputToContain(self::DIST_WARNING)
            ->expectsOutputToContain(trans_count('settings.core_update.rollback_apply_warnings_withdrawn', ['count' => 1]))
            ->assertExitCode(1);
    }

    /**
     * 명령줄 되돌리기 출력의 모양: 적용 단계 안내는 한 번만 찍히고(실패 경로가 같은 서비스 경고 버퍼를 다시 찍지 않는다),
     * 되돌린 뒤의 알림은 복원 진행 막대 줄에 붙지 않고 제 줄에서 시작한다. 실측(r3E): 같은 `.dist` 경고 2회, 알림이
     * 「…10/10 [====] vendor파일 적용 단계에서 알린…」처럼 막대 끝에 붙었다.
     *
     * @scenario case=cli_restore_finished
     *
     * @effects cli_apply_warnings_printed_once, cli_withdrawn_notice_starts_on_its_own_line
     *
     * @return void 반환값 없음
     */
    public function test_cli_rollback_output_prints_apply_warning_once_and_notice_on_its_own_line(): void
    {
        $release = $this->writeRelease();
        $backup = $this->root.DIRECTORY_SEPARATOR.'backup';
        File::ensureDirectoryExists($backup);
        $this->app->instance(BundledSourcePlanner::class, new BundledSourcePlanner(...$this->plannerRepositories()));
        config(['app.update.spawn_failure_mode' => 'fallback']);

        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('acquireRunLock')->andReturn(true);
        $service->shouldReceive('releaseRunLock');
        // 실제 서비스처럼 적용 뒤에도 경고 버퍼가 그 안내를 들고 있다 — 실패 경로가 게터로 다시 읽는다
        $service->shouldReceive('getLastRootFileWarnings')->andReturn([self::DIST_WARNING]);
        $service->shouldReceive('normalizeRuntimeOwnershipAfterRootRun');
        $service->shouldReceive('preflightReleaseIntegrity');
        $service->shouldReceive('verifyCoreZipIntegrity')->andReturn(PackageVerification::unsigned());
        $service->shouldReceive('confirmCoreIntegrity')->andReturnUsing(fn ($verification) => $verification);
        $service->shouldReceive('validatePendingPath')->andReturn(['valid' => true]);
        $service->shouldReceive('enableMaintenanceMode')->once()->andReturn('secret');
        $service->shouldReceive('validatePendingUpdate')->once();
        $service->shouldReceive('copySourceToPending')->once()->andReturn($release);
        $service->shouldReceive('createBackup')->once()->andReturn($backup);
        $service->shouldReceive('snapshotOwnership')->andReturn([]);
        $service->shouldReceive('resolveStagingRoot')->andReturn($release);
        $service->shouldReceive('snapshotOwnershipDetailed')->andReturn([]);
        $service->shouldReceive('isComposerUnchangedForCore')->andReturn(true);
        $service->shouldReceive('applyUpdate')->once()->andReturn(['root_file_warnings' => [self::DIST_WARNING]]);
        $service->shouldReceive('runMigrations')->once()->andThrow(new \RuntimeException('migration failed'));
        // 실제 복원처럼 대상마다 진행 막대를 다시 그린다(개행 없이 끝난다)
        $service->shouldReceive('restoreFromBackup')->once()->andReturnUsing(function (string $path, ?\Closure $onProgress = null): array {
            $onProgress?->__invoke('restore', 'app');
            $onProgress?->__invoke('restore', 'vendor');

            return ['failed_targets' => [], 'root_file_warnings' => []];
        });
        $service->shouldReceive('clearAllCaches')->once();
        $service->shouldReceive('cleanupPending')->once();
        $service->shouldReceive('generateFailureReport')->andReturn('report.log');
        $service->shouldReceive('disableMaintenanceMode')->once();
        $this->app->instance(CoreUpdateService::class, $service);

        $exit = Artisan::call('core:update', ['--source' => $release, '--bundled-sources' => 'none', '--yes' => true, '--no-interaction' => true]);
        $output = Artisan::output();
        $notice = trans_count('settings.core_update.rollback_apply_warnings_withdrawn', ['count' => 1]);

        $this->assertSame(1, $exit);
        $this->assertSame(1, substr_count($output, self::DIST_WARNING), '적용 단계 안내가 두 번 찍혔다 — 실패 경로가 같은 경고 버퍼를 다시 찍는다');
        $this->assertStringContainsString($notice, $output);
        $this->assertMatchesRegularExpression('/(^|\n)'.preg_quote($notice, '/').'/u', $output, '되돌린 뒤 알림이 진행 막대 줄 끝에 붙었다');
    }

    /**
     * 적용 단계의 안내를 실행 상태에 남긴다 — 실제 적용 단계(`stepApply`)를 그 안내를 돌려주는 서비스로 돌린다.
     *
     * @param  MockInterface  $service  서비스 목
     * @param  CoreUpdateRunState  $state  실행 상태
     * @return void 반환값 없음
     */
    private function recordApplyWarnings(MockInterface $service, CoreUpdateRunState $state): void
    {
        $orchestrator = new CoreUpdateOrchestrator($service, $state, new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget(null, 300));

        // 적용 단계는 끝에 컴파일 캐시 파일을 지운다 — 개발 체크아웃의 `bootstrap/cache` 를 건드리지 않도록 그 경로를
        // 격리 폴더로 돌린다(Laravel 은 `APP_*_CACHE` 환경값으로 캐시 경로를 정한다).
        $keys = ['APP_CONFIG_CACHE' => 'config.php', 'APP_ROUTES_CACHE' => 'routes.php', 'APP_SERVICES_CACHE' => 'services.php', 'APP_PACKAGES_CACHE' => 'packages.php', 'APP_EVENTS_CACHE' => 'events.php'];
        foreach ($keys as $key => $file) {
            $_ENV[$key] = $_SERVER[$key] = $this->root.DIRECTORY_SEPARATOR.'cache-'.$file;
        }

        try {
            (new \ReflectionMethod($orchestrator, 'stepApply'))->invoke($orchestrator);
        } finally {
            foreach (array_keys($keys) as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    /**
     * 적용 단계와 되돌리기를 흉내 내는 서비스 목을 만든다.
     *
     * @param  \Closure  $restore  `restoreFromBackupChunk` 대역
     * @return MockInterface 서비스 목
     */
    private function webService(\Closure $restore): MockInterface
    {
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('applyUpdate')->andReturn(['root_file_warnings' => [self::DIST_WARNING]]);
        $service->shouldReceive('restoreFromBackupChunk')->andReturnUsing($restore);
        $this->app->instance(BundledSourcePlanner::class, new BundledSourcePlanner(...$this->plannerRepositories()));

        return $service;
    }

    /**
     * 적용을 마친 뒤 실패해 되돌리기 복원 구간에 들어선 실행 상태를 만든다.
     *
     * @param  CoreUpdateOptions|null  $options  실행 옵션 (null 이면 기본)
     * @return CoreUpdateRunState 실행 상태
     */
    private function rollingBackState(?CoreUpdateOptions $options = null): CoreUpdateRunState
    {
        $pending = $this->root.DIRECTORY_SEPARATOR.'pending';
        $backup = $this->root.DIRECTORY_SEPARATOR.'backup';
        File::ensureDirectoryExists($pending);
        File::ensureDirectoryExists($backup);

        $state = $this->state();
        $state->start($options ?? new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->mergeContext(['pending_path' => $pending, 'backup_path' => $backup]);

        return $state;
    }

    /**
     * 되돌리기를 한 요청 진행한다 — 복원 구간이 끝나면 멈추도록 시계를 크게 건너뛴다.
     *
     * @param  MockInterface  $service  서비스 목
     * @return void 반환값 없음
     */
    private function advance(MockInterface $service): void
    {
        $state = $this->state();
        $state->patch(['status' => CoreUpdateRunState::STATUS_ROLLING_BACK, 'error' => ['step' => CoreUpdateStep::Migrate->value, 'message' => 'migration failed']]);
        $state->mergeContext(['rollback_progress' => ['phase' => 'restore', 'restored' => false, 'failed_targets' => []]]);

        $tick = 0.0;
        $clock = function () use (&$tick): float {
            $tick += 100000.0;

            return $tick;
        };

        (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget($clock, 300)))->advance();
    }

    /**
     * 격리 storage 의 실행 상태를 연다.
     *
     * @return CoreUpdateRunState 실행 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState(storage_path('app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.'run.json'));
    }

    /**
     * 판정 서비스 생성자에 넣을 설치 상태 Repository 목(아무것도 설치되지 않음)을 만든다.
     *
     * @return array<int, mixed> 모듈·플러그인·템플릿·언어팩 Repository 목
     */
    private function plannerRepositories(): array
    {
        $repo = function (string $class) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('getInstalledIdentifiers')->andReturn([]);

            return $mock;
        };
        $lp = Mockery::mock(LanguagePackRepositoryInterface::class);
        $lp->shouldReceive('allForUpdateCheck')->andReturn(new EloquentCollection);

        return [
            $repo(ModuleRepositoryInterface::class),
            $repo(PluginRepositoryInterface::class),
            $repo(TemplateRepositoryInterface::class),
            $lp,
        ];
    }

    /**
     * 최소 배포본(번들 없음)을 쓴다.
     *
     * @return string 배포본 경로
     */
    private function writeRelease(): string
    {
        $release = $this->root.DIRECTORY_SEPARATOR.'release';
        File::ensureDirectoryExists($release.DIRECTORY_SEPARATOR.'app');
        File::ensureDirectoryExists($release.DIRECTORY_SEPARATOR.'config');
        File::put($release.DIRECTORY_SEPARATOR.'composer.json', '{"name":"test/g7"}');
        File::put($release.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'.gitkeep', '');
        File::put($release.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php', "<?php return ['version' => env('APP_VERSION', '7.2.1')];");

        return $release;
    }
}
