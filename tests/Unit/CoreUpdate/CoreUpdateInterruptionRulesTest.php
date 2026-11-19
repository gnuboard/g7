<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

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
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\FilePermissionHelper;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 웹 코어 업데이트의 단계가 끊겼을 때(시간 초과·기록 없는 종료) 단계마다 다른 정리 규칙을 잰다.
 *
 * - 마무리: 백업을 지우기 시작한 뒤로는 되돌릴 원본이 없다 — 되돌리지 않고, 남은 정리를 건너뛰어 끝낸다.
 * - 번들 확장: 첫 항목이 끊겨도 코어를 되돌리지 않는다 — 진행분을 항목을 돌리기 **전에** 저장한다.
 * - 백업: 첫 요청이 끊겨도 반쪽 백업 폴더를 정리할 수 있게 폴더 경로를 복사 전에 저장한다.
 * - 되돌리기: 옛 vendor 를 다시 끼운 요청은 거기서 끝낸다(이 프로세스의 클래스 로더는 새 vendor 를 들고 있다).
 * - 실행 시간 몫: 실행 제한을 늘려도 조각은 늘리기 전 제한 안에서 끝낸다.
 *
 * 커버 축: core_update_web interruption_rules
 */
class CoreUpdateInterruptionRulesTest extends TestCase
{
    /** base_path 아래 격리 루트. */
    private string $root;

    /** 원래 storage 경로. */
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
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_interrupt_'.uniqid());
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
        app(CoreUpdateService::class)->releaseRunLock();
        app()->useStoragePath($this->originalStoragePath);
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);

        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 마무리의 정리 구간 이후에 계속 끊기면 되돌리지 않고, 남은 정리를 건너뛰어 마무리를 끝내게 한다.
     *
     * @scenario request_time_limit=short, interruption=fatal_shutdown, run_state=running
     *
     * @effects a_finalize_past_its_cleanup_is_never_rolled_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_finalize_interrupted_after_cleanup_started_is_finished_not_rolled_back(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->patch(['next_step' => CoreUpdateStep::Finalize->value]);
        $state->mergeContext(['finalize_progress' => ['phase' => 'cleanup'], 'maintenance_enabled' => true]);

        for ($attempt = 1; $attempt <= CoreUpdateOrchestrator::MAX_ROLLBACK_ATTEMPTS; $attempt++) {
            $this->interrupt(CoreUpdateStep::Finalize->value, 'token-'.$attempt);
        }

        $after = $this->state();
        $this->assertSame(CoreUpdateRunState::STATUS_RUNNING, $after->status(), '백업을 지우기 시작한 뒤 되돌리면 원본 없이 일부만 되돌린다');
        $this->assertSame(CoreUpdateStep::Finalize, $after->nextStep());
        $this->assertSame('link', $after->context('finalize_progress')['phase'] ?? null, '남은 정리를 건너뛰고 점검 모드 해제로 넘어가야 한다');
        $this->assertContains((string) __('settings.core_update.web.finalize_cleanup_skipped'), $after->all()['warnings'] ?? []);
    }

    /**
     * 마무리의 준비 구간(백업이 남아 있음)이 계속 끊기는 것은 종전대로 되돌리기로 간다.
     *
     * @scenario request_time_limit=short, interruption=fatal_shutdown, run_state=running
     *
     * @effects a_finalize_past_its_cleanup_is_never_rolled_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_finalize_interrupted_before_cleanup_still_rolls_back(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->patch(['next_step' => CoreUpdateStep::Finalize->value]);

        for ($attempt = 1; $attempt <= CoreUpdateOrchestrator::MAX_ROLLBACK_ATTEMPTS; $attempt++) {
            $this->interrupt(CoreUpdateStep::Finalize->value, 'token-'.$attempt);
        }

        $this->assertSame(CoreUpdateRunState::STATUS_ROLLING_BACK, $this->state()->status());
    }

    /**
     * 번들 확장 진행분은 첫 항목을 돌리기 전에 저장된다 — 첫 항목이 끊겨도 코어를 되돌리지 않고 그 항목만 건너뛴다.
     *
     * @scenario request_time_limit=short, interruption=fatal_shutdown, run_state=running
     *
     * @effects the_bundled_progress_is_saved_before_the_first_item_runs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_bundled_progress_is_saved_before_the_first_item_runs(): void
    {
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('collectBundledExtensionUpdates')->andReturn([
            'modules' => [['identifier' => 'vendor-first']],
            'plugins' => [['identifier' => 'vendor-second']],
            'templates' => [],
        ]);
        $langPacks = Mockery::mock(LanguagePackService::class);
        $langPacks->shouldReceive('collectBundledLangPackUpdates')->andReturn([]);
        $this->app->instance(LanguagePackService::class, $langPacks);

        $statePath = $this->statePath();
        $runner = new class($statePath) implements CommandRunner
        {
            /** @var array<string, mixed>|null 첫 항목을 돌리는 순간 저장돼 있던 진행분 */
            public ?array $progressSeenDuringFirstItem = null;

            /** @var bool 첫 호출을 지났는지 */
            private bool $seen = false;

            /**
             * 첫 항목을 돌리는 순간의 진행분을 읽을 실행 기록 경로를 받는다.
             *
             * @param  string  $statePath  실행 기록 경로
             */
            public function __construct(private readonly string $statePath) {}

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
             * 첫 항목을 돌리는 순간의 실행 기록을 읽어 둔다(여기서 프로세스가 죽었다고 보면 남는 것이 그 기록이다).
             *
             * @param  string  $command  커맨드
             * @param  array<string, mixed>  $parameters  인자
             * @return CommandRunResult 결과
             */
            public function run(string $command, array $parameters = []): CommandRunResult
            {
                if (! $this->seen) {
                    $this->seen = true;
                    $this->progressSeenDuringFirstItem = (new CoreUpdateRunState($this->statePath))->context('bundled_progress');
                }

                return new CommandRunResult(0, '[BUNDLED-RESULT] {"success":1,"failed":0}', ExecutionMode::Inprocess);
            }
        };
        $resolver = Mockery::mock(CommandRunnerResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($runner);

        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.6', '7.2.7', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);

        (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), $resolver, new CoreUpdateTimeBudget(null, 300)))->advance();

        $this->assertIsArray($runner->progressSeenDuringFirstItem, '첫 항목이 끊기면 진행분이 없어 코어 되돌리기로 간다');
        $this->assertSame(0, $runner->progressSeenDuringFirstItem['index'] ?? null);
        $this->assertCount(2, $runner->progressSeenDuringFirstItem['items'] ?? []);
    }

    /**
     * 백업 폴더 경로는 복사를 시작하기 전에 진행분에 저장된다 — 첫 요청이 끊겨도 반쪽 백업을 정리할 수 있다.
     *
     * @scenario request_time_limit=short, interruption=fatal_shutdown, run_state=running
     *
     * @effects the_backup_path_is_saved_before_copying_starts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_backup_path_is_recorded_before_copying_starts(): void
    {
        $site = $this->root.DIRECTORY_SEPARATOR.'site';
        File::ensureDirectoryExists($site.DIRECTORY_SEPARATOR.'app');
        File::put($site.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'A.php', 'a');
        $relative = ltrim(str_replace('\\', '/', substr($site.DIRECTORY_SEPARATOR.'app', strlen(base_path()))), '/');
        config(['app.update.targets' => [$relative], 'app.update.backup_only' => [], 'app.update.backup_extra' => [], 'app.update.excludes' => []]);

        $statePath = $this->statePath();
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $seen = null;
        $service->shouldReceive('createBackupChunk')->andReturnUsing(function (array $progress) use ($statePath, &$seen): array {
            // 복사를 시작하는 순간에 실행 기록에 남아 있는 것 — 여기서 죽으면 이것만 남는다.
            $seen = (new CoreUpdateRunState($statePath))->context('backup_progress');

            throw new \RuntimeException('copy interrupted');
        });

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->patch(['next_step' => CoreUpdateStep::Backup->value]);

        (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget(null, 300)))->advance();

        $path = (string) ($seen['copy']['backup_path'] ?? '');
        $this->assertNotSame('', $path, '첫 요청이 복사 중에 끊기면 반쪽 백업 경로를 아무도 모른다');
        $this->assertDirectoryExists($path);
    }

    /**
     * 되돌리기에서 옛 vendor 를 다시 끼운 요청은 거기서 끝나고, 파일 복원은 다음 요청(옛 vendor 로 부팅)에서 한다.
     *
     * @scenario long_step=rollback, slicing=swap
     *
     * @effects the_request_that_swaps_the_vendor_back_ends_there
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_request_that_swaps_the_vendor_back_does_not_restore_files(): void
    {
        $previous = $this->root.DIRECTORY_SEPARATOR.'staging'.DIRECTORY_SEPARATOR.'vendor-previous';
        $backup = $this->root.DIRECTORY_SEPARATOR.'backup';
        File::ensureDirectoryExists($previous);
        File::ensureDirectoryExists($backup);

        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('swapVendorBack')->once()->andReturn(true);
        $restoreCalls = 0;
        $service->shouldReceive('restoreFromBackupChunk')->andReturnUsing(function () use (&$restoreCalls): array {
            $restoreCalls++;

            return ['done' => false, 'phase' => 'priority', 'priority_index' => 0, 'target' => 0, 'cursor' => null, 'failed_targets' => [], 'root_file_warnings' => []];
        });

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->mergeContext(['backup_path' => $backup, 'vendor_swap' => ['previous' => $previous]]);
        $state->patch(['status' => CoreUpdateRunState::STATUS_ROLLING_BACK, 'error' => ['step' => 'migrate', 'message' => 'x']]);

        (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget(null, 300)))->advance();

        $this->assertSame(0, $restoreCalls, '이 프로세스의 클래스 로더는 새 vendor 를 들고 있다 — 같은 요청에서 복원을 이어 가면 판이 섞인다');
        $this->assertSame('restore', $this->state()->context('rollback_progress')['phase'] ?? null);
    }

    /**
     * 실행 제한을 늘려도 조각의 몫은 늘리기 전 제한을 넘지 않는다 (서버 밖 제한 — 프록시·FPM — 은 PHP 가 모른다).
     *
     * @scenario request_time_limit=short, interruption=none
     *
     * @effects a_slice_leaves_room_to_finish_the_response
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_slice_basis_never_exceeds_the_limit_before_raising_it(): void
    {
        $this->assertSame(30, CoreUpdateTimeBudget::sliceBasis(30, 300), '30초 서버에서 300초로 늘렸다고 40초 조각을 잡으면 서버 밖 제한에 끊긴다');
        $this->assertSame(300, CoreUpdateTimeBudget::sliceBasis(600, 300));
        $this->assertSame(300, CoreUpdateTimeBudget::sliceBasis(0, 300), '늘리기 전에 제한이 없었으면 지금 값');
        $this->assertSame(0, CoreUpdateTimeBudget::sliceBasis(0, 0));
    }

    /**
     * 진행분 사이드카와 실행 기록은 임시 파일에 다 쓴 뒤 이름을 바꿔 교체한다 — 교체 직전에 끊겨도 이전 내용이 온전하다.
     *
     * @scenario request_time_limit=short, interruption=fatal_shutdown, run_state=running
     *
     * @effects the_progress_files_are_replaced_atomically
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_write_cut_off_before_the_swap_leaves_the_previous_content_intact(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->storePlanProgress(['phase' => 'apply', 'slices' => 3]);

        $dying = new class($this->statePath()) extends CoreUpdateRunState
        {
            /**
             * 임시 파일을 다 쓴 뒤, 교체하기 직전에 프로세스가 죽은 것을 흉내 낸다.
             *
             * @param  string  $tempPath  임시 파일
             * @param  string  $path  대상 파일
             * @return bool 돌아오지 않는다
             */
            protected function replaceFile(string $tempPath, string $path): bool
            {
                throw new \RuntimeException('process died before the swap');
            }
        };

        try {
            $dying->storePlanProgress(['phase' => 'apply', 'slices' => 4, 'apply' => ['cursor' => ['target' => 1]]]);
        } catch (\RuntimeException) {
            // 프로세스가 죽은 자리
        }

        $this->assertSame(['phase' => 'apply', 'slices' => 3], $this->state()->loadPlanProgress(), '쓰다 끊긴 진행분이 반쪽 JSON 으로 남으면 다음 요청이 산출을 처음부터 다시 한다');

        $this->state()->storePlanProgress(['phase' => 'apply', 'slices' => 5]);
        $this->assertSame(5, $this->state()->loadPlanProgress()['slices'] ?? null);
    }

    /**
     * vendor 바꿔 끼우기가 실패하고 원래대로 되돌리기까지 실패하면, 어느 경로가 어떤 상태로 남았는지와 수동 복구 안내를
     * 실행 기록과 실패 리포트에 남기고, 되돌리기가 옮겨 둔 옛 vendor 를 다시 끼우게 한다.
     *
     * @scenario long_step=vendor_copy, slicing=swap
     *
     * @effects a_failed_vendor_swap_leaves_a_recovery_record
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_vendor_swap_that_cannot_be_undone_is_recorded_with_recovery_guidance(): void
    {
        $live = $this->root.DIRECTORY_SEPARATOR.'vendor';
        $replacement = $this->root.DIRECTORY_SEPARATOR.'pending'.DIRECTORY_SEPARATOR.'vendor';
        $parking = $this->root.DIRECTORY_SEPARATOR.'staging'.DIRECTORY_SEPARATOR.'vendor-previous';
        File::ensureDirectoryExists($live);
        File::ensureDirectoryExists($replacement);

        // 첫 이름 바꾸기(운영 → 자리)만 되고, 새 vendor 끼우기와 원래대로 되돌리기는 실패한다(사용 중 파일 등).
        $calls = 0;
        FilePermissionHelper::useRenamerForTests(function (string $from, string $to) use (&$calls): bool {
            $calls++;

            return $calls === 1 ? rename($from, $to) : false;
        });

        try {
            $this->assertFalse(FilePermissionHelper::swapDirectory($live, $replacement, $parking));
        } finally {
            FilePermissionHelper::useRenamerForTests(null);
        }

        $failure = FilePermissionHelper::takeLastSwapFailure();
        $this->assertNotNull($failure, '운영 vendor 가 비었는데 아무 기록도 없으면 운영자는 복구할 근거가 없다');
        $this->assertSame($live, $failure['live']);
        $this->assertSame($parking, $failure['parked']);
        $this->assertSame($replacement, $failure['replacement']);
        $this->assertFalse($failure['live_exists']);
        $this->assertTrue($failure['parked_exists']);
        $this->assertNull(FilePermissionHelper::takeLastSwapFailure(), '한 번 읽은 기록은 비운다');

        // 웹 실행: 반영 단계가 그 기록을 실행 기록·실패 리포트에 싣고 되돌리기로 넘긴다.
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('swapVendorFromPending')->andReturnUsing(function () use ($live, $replacement, $parking): bool {
            FilePermissionHelper::recordSwapFailureForTests($live, $replacement, $parking);

            return false;
        });
        $service->shouldReceive('resolveStagingRoot')->andReturn($this->root.DIRECTORY_SEPARATOR.'staging');
        $service->shouldNotReceive('copyVendorFromPending');

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->mergeContext(['pending_path' => $this->root.DIRECTORY_SEPARATOR.'pending', 'composer_skipped' => false]);
        $state->patch(['next_step' => CoreUpdateStep::VendorCopy->value]);

        $status = (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget(null, 300)))->advance();

        $this->assertSame(CoreUpdateRunState::STATUS_ROLLING_BACK, $status['run']['status']);
        $message = (string) ($status['run']['error']['message'] ?? '');
        $this->assertStringContainsString($parking, $message, '옛 vendor 가 어디에 있는지 실패 사유에 있어야 한다');
        $this->assertStringContainsString($live, $message);
        $this->assertSame($parking, $this->state()->context('vendor_swap')['previous'] ?? null, '되돌리기가 옮겨 둔 옛 vendor 를 다시 끼워야 한다');
        $this->assertIsArray($this->state()->context('vendor_swap_failure'));

        $report = (string) ($status['run']['error']['report_path'] ?? '');
        $this->assertFileExists($report);
        $this->assertStringContainsString($parking, (string) file_get_contents($report));
    }

    /**
     * 새 백업 폴더를 만들 때 남아 있는 옛 백업 트리를 훑지 않는다 — 부모 체인(백업 루트 → 새 폴더)만 맞춘다.
     *
     * 옛 백업이 쌓일수록(백업 하나가 수만 파일) 그 재귀 훑기가 백업 첫 요청을 요청 시간 제한 밖으로 밀어낸다. 새 폴더를
     * 만드는 데 필요한 것은 부모의 그룹 쓰기뿐이고, 옛 백업의 권한 정상화는 마무리의 소유권 복원(나눠 함)이 맡는다.
     * 확장 백업(번들 확장 업데이트가 항목마다 만든다)도 같은 규칙이다.
     *
     * @scenario long_step=backup, slicing=sliced
     *
     * @effects creating_a_backup_does_not_walk_older_backups
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_creating_a_backup_directory_does_not_walk_older_backups(): void
    {
        foreach ([
            [CoreBackupHelper::class, 'prepareBackupDirectory'],
            [ExtensionBackupHelper::class, 'createBackup'],
        ] as [$class, $method]) {
            $body = $this->methodSource($class, $method);

            $this->assertStringNotContainsString('syncGroupWritability(', $body, $class.'::'.$method.' 가 백업 루트 아래 옛 백업 전체를 재귀로 훑는다');
            $this->assertStringContainsString('promoteGroupWritableChain(', $body, $class.'::'.$method.' 가 새 폴더의 부모 체인 그룹 쓰기를 맞추지 않는다');
        }

        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('posix_getuid')) {
            return; // 아래 권한 비트 확인은 POSIX 전용
        }

        $root = $this->root.DIRECTORY_SEPARATOR.'chain';
        $old = $root.DIRECTORY_SEPARATOR.'old-backup';
        $new = $root.DIRECTORY_SEPARATOR.'new-backup';
        File::ensureDirectoryExists($old);
        File::ensureDirectoryExists($new);
        chmod($root, 0775);
        chmod($new, 0755);
        File::put($old.DIRECTORY_SEPARATOR.'f.txt', 'x');
        chmod($old.DIRECTORY_SEPARATOR.'f.txt', 0644);

        FilePermissionHelper::promoteGroupWritableChain($root, $new);
        clearstatcache();

        $this->assertSame(0775, fileperms($new) & 0777, '새 폴더는 부모가 g+w 면 g+w 로');
        $this->assertSame(0644, fileperms($old.DIRECTORY_SEPARATOR.'f.txt') & 0777, '옛 백업은 건드리지 않는다');
    }

    /**
     * 마무리가 어느 구간에서든 계속 끊겨도 끝나는 상태가 있다 — 정리 → 링크 점검 → 마무리 완료 순으로 건너뛰고,
     * 마지막 구간까지 끊기면 점검 모드를 풀고 완료로 기록한다.
     *
     * @scenario request_time_limit=short, interruption=fatal_shutdown, run_state=running
     *
     * @effects a_finalize_past_its_cleanup_is_never_rolled_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_finalize_that_keeps_failing_in_every_phase_still_ends(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->patch(['next_step' => CoreUpdateStep::Finalize->value]);
        $state->mergeContext(['finalize_progress' => ['phase' => 'link']]);

        $seen = [];

        for ($attempt = 1; $attempt <= CoreUpdateOrchestrator::MAX_ROLLBACK_ATTEMPTS * 3; $attempt++) {
            if ($this->state()->status() !== CoreUpdateRunState::STATUS_RUNNING) {
                break;
            }

            $this->interrupt(CoreUpdateStep::Finalize->value, 'token-'.$attempt);
            $seen[] = $this->state()->context('finalize_progress')['phase'] ?? null;
        }

        $after = $this->state();
        $this->assertSame(CoreUpdateRunState::STATUS_COMPLETED, $after->status(), '끊길 때마다 같은 구간으로 돌아가면 끝나는 상태가 없다: '.json_encode($seen));
        $this->assertContains('finish', $seen, '링크 점검이 계속 끊기면 그것을 건너뛴 마무리 완료 구간으로 가야 한다');
        $this->assertNotContains(CoreUpdateRunState::STATUS_ROLLING_BACK, [$after->status()]);
    }

    /**
     * 되돌리기가 상한에서 실패로 확정될 때 그동안 모은 「되돌리지 못한 대상」을 버리지 않는다.
     *
     * @scenario request_time_limit=short, interruption=rollback_interrupted, run_state=rolling_back
     *
     * @effects an_endlessly_interrupted_rollback_opens_manual_recovery
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_rollback_given_up_at_the_limit_keeps_its_failed_targets(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->patch(['status' => CoreUpdateRunState::STATUS_ROLLING_BACK, 'error' => ['step' => 'apply', 'message' => 'x']]);
        $state->mergeContext(['rollback_progress' => [
            'phase' => 'restore',
            'restored' => false,
            'failed_targets' => ['config'],
            'restore' => ['phase' => 'overlay', 'target' => 2, 'cursor' => 'x.php', 'failed_targets' => ['config']],
        ]]);

        for ($attempt = 1; $attempt <= CoreUpdateOrchestrator::MAX_ROLLBACK_ATTEMPTS; $attempt++) {
            $this->interrupt(CoreUpdateOrchestrator::PHASE_ROLLBACK, 'token-'.$attempt);
        }

        $after = $this->state();
        $this->assertSame(CoreUpdateRunState::STATUS_ROLLBACK_FAILED, $after->status());
        $this->assertSame(['config'], $after->all()['rollback']['failed_targets'] ?? null, '수동 복구 안내가 무엇을 되돌리지 못했는지 말하지 못한다');
    }

    /**
     * 진행 기록에는 줄 상한과 무관하게 늘어나는 누적 순번이 있고 상태 응답에 실린다 (화면의 진척 판정용).
     *
     * @scenario request_time_limit=short, response=cut_off_repeatedly
     *
     * @effects repeated_failures_after_the_claim_still_back_off
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_log_sequence_keeps_growing_past_the_line_cap(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);

        for ($i = 0; $i < CoreUpdateRunState::MAX_LOG_LINES + 10; $i++) {
            $state->appendLog('line '.$i);
        }

        $this->assertCount(CoreUpdateRunState::MAX_LOG_LINES, $this->state()->logs());
        $this->assertSame(CoreUpdateRunState::MAX_LOG_LINES + 10, $this->state()->logSequence());

        $service = app(CoreUpdateService::class);
        $status = (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget(null, 300)))->status();
        $this->assertSame(CoreUpdateRunState::MAX_LOG_LINES + 10, $status['run']['log_seq'] ?? null);
    }

    /**
     * 메서드 소스를 잘라 낸다.
     *
     * @param  class-string  $class  클래스
     * @param  string  $method  메서드
     * @return string 소스
     */
    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName()) ?: [];

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    /**
     * 같은 단계를 실행 중 끊긴 것으로 기록한다 (종료 처리 경로).
     *
     * @param  string  $phase  점유 구간
     * @param  string  $token  점유 토큰
     * @return void 반환값 없음
     */
    private function interrupt(string $phase, string $token): void
    {
        $state = $this->state();
        $state->claim($token, $phase, 60);
        $budget = new CoreUpdateTimeBudget(null, 30);
        $budget->begin();
        $service = app(CoreUpdateService::class);

        (new CoreUpdateOrchestrator($service, $state, new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), $budget))
            ->handleFatalShutdown($token, ['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded']);
    }

    /**
     * 실행 기록 경로.
     *
     * @return string 경로
     */
    private function statePath(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME;
    }

    /**
     * 격리 경로를 쓰는 상태를 만든다.
     *
     * @return CoreUpdateRunState 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState($this->statePath());
    }
}
