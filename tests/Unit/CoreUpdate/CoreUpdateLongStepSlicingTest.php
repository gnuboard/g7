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
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Helpers\SafeArchiveExtractor;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 웹 코어 업데이트의 긴 단계(ZIP 추출, 백업, vendor 반영, 번들 확장 일괄 업데이트, 되돌리기, 마무리 정리)가
 * 한 요청의 시간 몫을 넘지 않고 다음 요청에서 이어 가는지 잰다.
 *
 * 실행 시간 30~60초인 공유 호스팅이 관리자 화면 업데이트의 대상이다. 한 요청에서 끝까지 하면 그 서버에서는
 * 업데이트가 시간 초과로 끊겨 되돌아오기만 한다. 각 단계는 시간 몫이 다하면 커서를 남기고(같은 단계를 다음
 * 요청에서 다시), 나눠 한 결과는 한 번에 한 결과와 같아야 한다(명령줄 업데이트는 같은 함수를 멈춤 없이 부른다).
 *
 * 시계를 주입해 「매 요청이 항목 하나만 처리하고 멈춘다」를 만든다.
 *
 * 커버 축: core_update_web long_step_slicing
 */
class CoreUpdateLongStepSlicingTest extends TestCase
{
    /** base_path 아래 격리 루트 (대상 경로가 base 상대라서). */
    private string $root;

    /** 원래 storage 경로. */
    private string $originalStoragePath;

    /**
     * 격리 storage 와 픽스처 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_slice_'.uniqid());
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');

        $this->originalStoragePath = storage_path();
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
    }

    /**
     * storage 경로를 되돌리고 픽스처를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app(CoreUpdateService::class)->releaseRunLock();
        app()->useStoragePath($this->originalStoragePath);
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);

        $this->removeTree($this->root);

        parent::tearDown();
    }

    /**
     * 링크·정션을 따라가지 않고 트리를 지운다.
     *
     * @param  string  $path  지울 경로
     * @return void 반환값 없음
     */
    private function removeTree(string $path): void
    {
        if (! file_exists($path) && ! is_link($path) && @readlink($path) === false) {
            return;
        }

        $isJunction = PHP_OS_FAMILY === 'Windows' && ! is_link($path) && ! is_file($path) && ! is_dir($path) && @readlink($path) !== false;

        if (is_link($path) || $isJunction) {
            if (! @rmdir($path)) {
                @unlink($path);
            }

            return;
        }

        if (is_dir($path)) {
            foreach ((array) scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
                }
            }

            @rmdir($path);

            return;
        }

        @unlink($path);
    }

    /**
     * ZIP 추출을 한 항목씩 나눠도 한 번에 푼 결과와 같다.
     *
     * @scenario long_step=archive_extraction, slicing=sliced
     *
     * @effects the_archive_extraction_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_sliced_zip_extraction_matches_a_single_pass(): void
    {
        $zip = $this->makeZip([
            'g7/composer.json' => '{}',
            'g7/app/A.php' => '<?php // a',
            'g7/app/sub/B.php' => '<?php // b',
            'g7/config/app.php' => "<?php return ['version' => env('APP_VERSION', '7.9.9')];",
            'g7/README.md' => 'readme',
        ]);

        $single = $this->root.DIRECTORY_SEPARATOR.'single';
        SafeArchiveExtractor::extractZip($zip, $single);

        $sliced = $this->root.DIRECTORY_SEPARATOR.'sliced';
        $from = 0;
        $calls = 0;

        do {
            $chunk = SafeArchiveExtractor::extractZipChunk($zip, $sliced, $from, static fn (): bool => true);
            $from = $chunk['next'];
            $calls++;
        } while (! $chunk['done'] && $calls < 100);

        $this->assertTrue($chunk['done']);
        $this->assertGreaterThan(3, $calls, '멈춤 신호를 받고도 한 번에 다 풀었다');
        $this->assertSame($this->treeOf($single), $this->treeOf($sliced));
    }

    /**
     * 웹 실행의 ZIP 추출 단계가 여러 요청으로 나뉘고, 끝나면 대상 버전과 전개본 경로가 기록된다.
     *
     * @scenario long_step=archive_extraction, slicing=web_requests
     *
     * @effects the_archive_extraction_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_web_download_step_extracts_the_uploaded_zip_across_requests(): void
    {
        $pendingBase = $this->root.DIRECTORY_SEPARATOR.'core_pending';
        File::ensureDirectoryExists($pendingBase);
        config(['app.update.pending_path' => $pendingBase, 'app.update.reserved_paths' => []]);

        $files = ['g7/composer.json' => '{}', 'g7/config/app.php' => "<?php return ['version' => env('APP_VERSION', '7.9.9')];"];
        for ($i = 0; $i < 6; $i++) {
            $files['g7/app/F'.$i.'.php'] = '<?php // '.$i;
        }
        File::move($this->makeZip($files), $pendingBase.DIRECTORY_SEPARATOR.'g7-7.9.9.zip');

        $state = $this->state();
        $state->start(new CoreUpdateOptions(zipFile: 'g7-7.9.9.zip'), '7.2.0', null, 1);
        $state->patch(['next_step' => CoreUpdateStep::Download->value, 'completed_steps' => ['preflight', 'maintenance']]);

        $requests = $this->advanceWhile($state, CoreUpdateStep::Download);

        $after = $this->state();
        $this->assertSame(CoreUpdateStep::Backup, $after->nextStep(), (string) json_encode($after->all()['error'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThan(2, $requests, '요청 몫을 넘겼는데 한 요청에서 다 풀었다');
        $this->assertSame('7.9.9', $after->all()['to_version']);
        $this->assertFileExists((string) $after->context('pending_path').DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'F5.php');
    }

    /**
     * 백업을 한 항목씩 나눠도 한 번에 만든 백업과 같다 (링크는 링크째).
     *
     * @scenario long_step=backup, slicing=sliced
     *
     * @effects the_backup_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_sliced_backup_matches_a_single_pass_backup(): void
    {
        $site = $this->siteFixture();
        $targets = [$this->relative($site.DIRECTORY_SEPARATOR.'app'), $this->relative($site.DIRECTORY_SEPARATOR.'config'), $this->relative($site.DIRECTORY_SEPARATOR.'index.php')];
        $excludes = ['node_modules'];

        $single = CoreBackupHelper::createBackup($targets, null, $excludes);

        $progress = [];
        $calls = 0;

        do {
            $progress = CoreBackupHelper::createBackupChunk($targets, $excludes, $progress, static fn (): bool => true);
            $calls++;
        } while (! $progress['done'] && $calls < 200);

        $this->assertTrue($progress['done']);
        $this->assertGreaterThan(3, $calls);
        $this->assertNotSame($single, $progress['backup_path']);
        $this->assertSame($this->treeOf($single), $this->treeOf($progress['backup_path']));
        $this->assertArrayNotHasKey(str_replace('\\', '/', $this->relative($site)).'/app/node_modules/x.js', $this->treeOf($progress['backup_path']), '제외 항목이 백업에 들어갔다');
    }

    /**
     * 되돌리기를 나눠도 한 번에 되돌린 결과와 같고, 이번 업데이트가 바꾼 파일을 먼저 되돌린다.
     *
     * @scenario long_step=rollback, slicing=sliced
     *
     * @effects the_rollback_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_sliced_restore_matches_a_single_pass_and_restores_changed_files_first(): void
    {
        $site = $this->siteFixture();
        $targets = [$this->relative($site.DIRECTORY_SEPARATOR.'app'), $this->relative($site.DIRECTORY_SEPARATOR.'config')];
        $backup = CoreBackupHelper::createBackup($targets, null, []);
        $before = $this->treeOf($site);

        $changed = $this->relative($site.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'A.php');
        File::put(base_path($changed), 'NEW VERSION');
        File::put($site.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'c.php', 'NEW CONFIG');

        $progress = [];
        $calls = 0;
        $firstSliceRestoredChanged = null;

        do {
            $progress = CoreBackupHelper::restoreFromBackupChunk($backup, $targets, [], $progress, static fn (): bool => true, [str_replace('\\', '/', $changed)]);
            $calls++;
            $firstSliceRestoredChanged ??= (string) file_get_contents(base_path($changed)) !== 'NEW VERSION';
        } while (! $progress['done'] && $calls < 200);

        $this->assertTrue($progress['done']);
        $this->assertTrue($firstSliceRestoredChanged, '이번 업데이트가 바꾼 파일이 첫 요청에서 돌아오지 않으면 끊긴 사이에 사이트가 섞인 판으로 뜬다');
        $this->assertGreaterThan(3, $calls);
        $this->assertSame([], $progress['failed_targets']);
        $this->assertSame($before, $this->treeOf($site));
    }

    /**
     * 웹 되돌리기가 여러 요청으로 나뉘고, 도중에 요청이 치명 오류로 끊겨도 다음 요청이 커서에서 이어 받아 끝낸다.
     *
     * @scenario long_step=rollback, slicing=interrupted
     *
     * @effects an_interrupted_sliced_rollback_is_resumed_by_the_next_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_an_interrupted_sliced_rollback_is_resumed_by_the_next_request(): void
    {
        $site = $this->siteFixture();
        $targets = [$this->relative($site.DIRECTORY_SEPARATOR.'app'), $this->relative($site.DIRECTORY_SEPARATOR.'config')];
        config(['app.update.targets' => $targets, 'app.update.backup_only' => [], 'app.update.excludes' => [], 'app.update.protected_paths' => [], 'app.update.reserved_paths' => []]);
        $backup = CoreBackupHelper::createBackup($targets, null, []);
        $before = $this->treeOf($site);

        File::put($site.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'A.php', 'NEW VERSION');

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->mergeContext(['backup_path' => $backup]);
        $state->patch([
            'status' => CoreUpdateRunState::STATUS_ROLLING_BACK,
            'next_step' => CoreUpdateStep::VendorCopy->value,
            'completed_steps' => ['preflight', 'maintenance', 'download', 'backup', 'vendor', 'plan', 'apply'],
            'error' => ['step' => 'vendor_copy', 'message' => 'x'],
        ]);

        // 첫 요청 — 한 조각 되돌리고 멈춘다.
        $this->orchestrator($this->state(), $this->tightBudget())->advance();
        $this->assertSame(CoreUpdateRunState::STATUS_ROLLING_BACK, $this->state()->status(), '한 조각에 다 되돌렸다면 나뉘지 않은 것이다');

        // 둘째 요청이 시간 초과로 끊겼다(종료 처리가 기록).
        $dying = $this->state();
        $dying->claim('token-dying', CoreUpdateOrchestrator::PHASE_ROLLBACK, 60);
        $budget = $this->tightBudget();
        $budget->begin();
        $this->orchestrator($dying, $budget)->handleFatalShutdown('token-dying', ['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded']);
        $this->assertSame(CoreUpdateRunState::STATUS_ROLLING_BACK, $this->state()->status());
        $this->assertNotNull($this->state()->context('rollback_progress'), '커서가 남지 않으면 다음 요청이 처음부터 다시 한다');

        $requests = 0;
        do {
            $status = $this->orchestrator($this->state(), $this->tightBudget())->advance();
            $requests++;
        } while (($status['run']['status'] ?? null) === CoreUpdateRunState::STATUS_ROLLING_BACK && $requests < 300);

        $this->assertSame(CoreUpdateRunState::STATUS_ROLLED_BACK, $status['run']['status'], (string) json_encode($status['run']['logs'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertTrue($status['run']['rollback']['restored']);
        $this->assertSame($before, $this->treeOf($site));
    }

    /**
     * 웹 실행의 백업 단계가 여러 요청으로 나뉜다.
     *
     * @scenario long_step=backup, slicing=web_requests
     *
     * @effects the_backup_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_web_backup_step_spans_requests(): void
    {
        $site = $this->siteFixture();
        $targets = [$this->relative($site.DIRECTORY_SEPARATOR.'app'), $this->relative($site.DIRECTORY_SEPARATOR.'config')];
        config(['app.update.targets' => $targets, 'app.update.backup_only' => [], 'app.update.backup_extra' => [], 'app.update.excludes' => []]);

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Backup->value, 'completed_steps' => ['preflight', 'maintenance', 'download']]);

        $requests = $this->advanceWhile($state, CoreUpdateStep::Backup);

        $after = $this->state();
        $this->assertSame(CoreUpdateStep::Vendor, $after->nextStep(), (string) json_encode($after->all()['error'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThan(2, $requests);
        $this->assertSame($this->treeOf(CoreBackupHelper::createBackup($targets, null, [])), $this->treeOf((string) $after->context('backup_path')));
    }

    /**
     * vendor 반영은 디렉터리를 통째로 바꿔 끼우고(한 요청 안에 끝난다), 되돌리기는 옛 vendor 를 그대로 다시 끼운다.
     *
     * @scenario long_step=vendor_copy, slicing=swap
     *
     * @effects the_vendor_replacement_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_directory_swap_replaces_and_restores_without_copying(): void
    {
        $live = $this->root.DIRECTORY_SEPARATOR.'vendor';
        $replacement = $this->root.DIRECTORY_SEPARATOR.'pending'.DIRECTORY_SEPARATOR.'vendor';
        $parking = $this->root.DIRECTORY_SEPARATOR.'parking'.DIRECTORY_SEPARATOR.'vendor-previous';

        File::put($this->ensureParent($live.DIRECTORY_SEPARATOR.'old.txt'), 'old');
        File::put($this->ensureParent($replacement.DIRECTORY_SEPARATOR.'new.txt'), 'new');

        $this->assertTrue(FilePermissionHelper::swapDirectory($live, $replacement, $parking));
        $this->assertFileExists($live.DIRECTORY_SEPARATOR.'new.txt');
        $this->assertFileExists($parking.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertDirectoryDoesNotExist($replacement);

        $discard = $this->root.DIRECTORY_SEPARATOR.'parking'.DIRECTORY_SEPARATOR.'vendor-discarded';
        $this->assertTrue(FilePermissionHelper::swapDirectory($live, $parking, $discard));
        $this->assertFileExists($live.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertFileExists($discard.DIRECTORY_SEPARATOR.'new.txt');

        // 바꿔 끼울 것이 없으면 아무것도 건드리지 않고 거짓을 돌려준다(호출부가 복사로 넘어간다).
        $this->assertFalse(FilePermissionHelper::swapDirectory($live, $this->root.DIRECTORY_SEPARATOR.'missing', $this->root.DIRECTORY_SEPARATOR.'p2'));
        $this->assertFileExists($live.DIRECTORY_SEPARATOR.'old.txt');
    }

    /**
     * 번들 확장 일괄 업데이트는 한 요청에 한 항목씩 돌고, 마지막 항목에만 검색 인덱스 재생성을 싣는다.
     *
     * @scenario long_step=bundled_updates, slicing=web_requests
     *
     * @effects the_bundled_extension_updates_fit_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bundled_extension_updates_run_one_item_per_request(): void
    {
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('collectBundledExtensionUpdates')->andReturn([
            'modules' => [['identifier' => 'vendor-mod_a'], ['identifier' => 'vendor-mod_b']],
            'plugins' => [['identifier' => 'vendor-plug_c']],
            'templates' => [],
        ]);
        $langPacks = Mockery::mock(LanguagePackService::class);
        $langPacks->shouldReceive('collectBundledLangPackUpdates')->andReturn([]);
        $this->app->instance(LanguagePackService::class, $langPacks);

        $runner = new class implements CommandRunner
        {
            /** @var array<int, array<string, mixed>> 실행한 매니페스트 기록 */
            public array $seen = [];

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
             * 매니페스트를 기록하고 성공 결과를 돌려준다.
             *
             * @param  string  $command  커맨드
             * @param  array<string, mixed>  $parameters  인자
             * @return CommandRunResult 결과
             */
            public function run(string $command, array $parameters = []): CommandRunResult
            {
                $this->seen[] = json_decode((string) file_get_contents((string) $parameters['--manifest']), true);

                return new CommandRunResult(0, '[BUNDLED-RESULT] {"success":1,"failed":0}', ExecutionMode::Inprocess);
            }
        };
        $resolver = Mockery::mock(CommandRunnerResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($runner);

        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true, rebuildSearchIndex: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value, 'completed_steps' => ['preflight', 'maintenance', 'download', 'backup', 'vendor', 'plan', 'apply', 'vendor_copy', 'migrate', 'upgrade_steps']]);

        $requests = 0;
        do {
            $status = (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), $resolver, $this->tightBudget()))->advance();
            $requests++;
        } while (($status['run']['next_step'] ?? null) === CoreUpdateStep::Bundled->value && $requests < 20);

        $this->assertSame(CoreUpdateStep::Finalize->value, $status['run']['next_step']);
        $this->assertCount(3, $runner->seen, '항목마다 한 요청이어야 한 요청이 확장 하나의 업데이트 시간만 쓴다');

        foreach ($runner->seen as $index => $manifest) {
            $count = count($manifest['modules']) + count($manifest['plugins']) + count($manifest['templates']) + count($manifest['lang_packs']);
            $this->assertSame(1, $count, ($index + 1).'번째 요청이 항목 하나가 아니다');
            $this->assertSame($index === 2, (bool) $manifest['rebuild_search_index'], '검색 인덱스 재생성은 마지막 항목에서 한 번만');
        }

        $this->assertSame(3, (int) ($this->state()->context('bundled_result')['success'] ?? 0));
        $this->assertSame(3, (int) ($this->state()->context('bundled_result')['planned'] ?? 0));
    }

    /**
     * 큰 트리 지우기를 나눠도 끝내 다 지우고, 링크·정션은 따라가 지우지 않는다.
     *
     * @scenario long_step=finalize_cleanup, slicing=sliced
     *
     * @effects the_cleanup_fits_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_sliced_tree_deletion_finishes_and_never_follows_links(): void
    {
        $outside = $this->root.DIRECTORY_SEPARATOR.'uploads';
        File::put($this->ensureParent($outside.DIRECTORY_SEPARATOR.'keep.jpg'), 'keep');

        $tree = $this->root.DIRECTORY_SEPARATOR.'pending-tree';
        for ($i = 0; $i < 12; $i++) {
            File::put($this->ensureParent($tree.DIRECTORY_SEPARATOR.'d'.($i % 3).DIRECTORY_SEPARATOR.'f'.$i.'.txt'), 'x');
        }
        $this->makeDirectoryLink($outside, $tree.DIRECTORY_SEPARATOR.'public-storage');

        $calls = 0;
        do {
            $done = FilePermissionHelper::deleteDirectoryChunk($tree, static fn (): bool => true);
            $calls++;
        } while (! $done && $calls < 200);

        $this->assertTrue($done);
        $this->assertGreaterThan(3, $calls);
        $this->assertFalse(file_exists($tree));
        $this->assertFileExists($outside.DIRECTORY_SEPARATOR.'keep.jpg', '링크를 따라가 지우면 운영 중인 업로드 파일이 사라진다');
    }

    /**
     * 번들 확장 한 항목이 시간 초과로 끊기면 코어를 되돌리지 않고 그 항목을 「끊긴 항목」으로 적어 둔 채 다음 항목으로 넘어간다.
     *
     * @scenario long_step=bundled_updates, slicing=interrupted
     *
     * @effects the_bundled_extension_updates_fit_within_a_short_request_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_an_interrupted_bundled_item_is_skipped_without_rolling_back_the_core(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);
        $state->mergeContext(['bundled_progress' => [
            'items' => [['type' => 'module', 'identifier' => 'vendor-heavy', 'strategy' => 'overwrite'], ['type' => 'plugin', 'identifier' => 'vendor-next', 'strategy' => 'overwrite']],
            'index' => 0, 'success' => 0, 'failed' => 0, 'planned' => 2,
        ]]);
        $state->claim('token-bundled', CoreUpdateStep::Bundled->value, 60);

        $budget = $this->tightBudget();
        $budget->begin();
        $this->orchestrator($state, $budget)->handleFatalShutdown('token-bundled', ['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded']);

        $after = $this->state();
        $this->assertSame(CoreUpdateRunState::STATUS_RUNNING, $after->status(), '확장 하나가 끊겼다고 정상 반영된 코어를 되돌리면 안 된다');
        $this->assertSame(CoreUpdateStep::Bundled, $after->nextStep());
        $this->assertSame(1, $after->context('bundled_progress')['index']);
        // 끊긴 것은 요청이지 항목 자식이 아니다 — 실패로 세지 않고 「끊긴 항목」으로 적어 두었다가 단계 끝에서 판을 확인한다.
        $this->assertSame(0, $after->context('bundled_progress')['failed']);
        $this->assertSame(['vendor-heavy'], array_column($after->context('bundled_progress')['interrupted'] ?? [], 'identifier'));
        $this->assertNull($after->claimSummary());
    }

    /**
     * 격리 경로를 쓰는 상태를 만든다.
     *
     * @return CoreUpdateRunState 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState($this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME);
    }

    /**
     * 물을 때마다 크게 흐르는 시계의 예산 — 매 요청이 항목 하나만 처리하고 멈춘다.
     *
     * @return CoreUpdateTimeBudget 예산
     */
    private function tightBudget(): CoreUpdateTimeBudget
    {
        $tick = 0.0;

        return new CoreUpdateTimeBudget(static function () use (&$tick): float {
            return $tick += 100.0;
        }, 30);
    }

    /**
     * 실제 서비스와 지정 상태로 오케스트레이터를 만든다.
     *
     * @param  CoreUpdateRunState  $state  상태
     * @param  CoreUpdateTimeBudget  $budget  예산
     * @return CoreUpdateOrchestrator 오케스트레이터
     */
    private function orchestrator(CoreUpdateRunState $state, CoreUpdateTimeBudget $budget): CoreUpdateOrchestrator
    {
        $service = app(CoreUpdateService::class);

        return new CoreUpdateOrchestrator($service, $state, new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), $budget);
    }

    /**
     * 지정 단계가 다음 단계로 넘어갈 때까지 요청을 반복한다.
     *
     * @param  CoreUpdateRunState  $state  처음 상태(경로만 쓴다)
     * @param  CoreUpdateStep  $step  반복할 단계
     * @return int 요청 수
     */
    private function advanceWhile(CoreUpdateRunState $state, CoreUpdateStep $step): int
    {
        $requests = 0;

        do {
            $status = $this->orchestrator($this->state(), $this->tightBudget())->advance();
            $requests++;
        } while (($status['run']['next_step'] ?? null) === $step->value
            && ($status['run']['status'] ?? null) === CoreUpdateRunState::STATUS_RUNNING
            && $requests < 300);

        return $requests;
    }

    /**
     * 사이트 픽스처를 만든다 (하위 폴더·제외 항목·링크 포함).
     *
     * @return string 사이트 루트
     */
    private function siteFixture(): string
    {
        $site = $this->root.DIRECTORY_SEPARATOR.'site';

        foreach ([
            'app/A.php' => 'a', 'app/B.php' => 'b', 'app/sub/C.php' => 'c', 'app/sub/deep/D.php' => 'd',
            'app/node_modules/x.js' => 'x', 'config/c.php' => 'conf', 'config/d.php' => 'conf-d', 'index.php' => 'root',
        ] as $relative => $content) {
            File::put($this->ensureParent($site.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative)), $content);
        }

        $uploads = $this->root.DIRECTORY_SEPARATOR.'site-uploads';
        File::put($this->ensureParent($uploads.DIRECTORY_SEPARATOR.'u.jpg'), 'u');
        $this->makeDirectoryLink($uploads, $site.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'storage-link');

        return $site;
    }

    /**
     * ZIP 픽스처를 만든다.
     *
     * @param  array<string, string>  $files  항목 이름 → 내용
     * @return string ZIP 경로
     */
    private function makeZip(array $files): string
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'fixture-'.uniqid().'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }

    /**
     * 트리를 「상대 경로 → 내용(링크는 링크 표시)」 표로 펼친다.
     *
     * @param  string  $root  루트
     * @return array<string, string> 표
     */
    private function treeOf(string $root): array
    {
        $out = [];
        $walk = function (string $dir, string $prefix) use (&$walk, &$out): void {
            $entries = scandir($dir) ?: [];
            sort($entries, SORT_STRING);

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === CoreBackupHelper::NEW_FILES_MANIFEST) {
                    continue;
                }

                $path = $dir.DIRECTORY_SEPARATOR.$entry;
                $key = $prefix === '' ? $entry : $prefix.'/'.$entry;

                if (is_link($path) || (PHP_OS_FAMILY === 'Windows' && ! is_file($path) && ! is_dir($path))) {
                    $out[$key] = '<link>';
                } elseif (is_dir($path)) {
                    $out[$key.'/'] = '<dir>';
                    $walk($path, $key);
                } else {
                    $out[$key] = (string) file_get_contents($path);
                }
            }
        };
        $walk($root, '');
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * 디렉터리 링크를 만든다 (Windows 는 정션, 그 밖에는 심볼릭 링크).
     *
     * @param  string  $target  대상
     * @param  string  $link  링크 경로
     * @return void 반환값 없음
     */
    private function makeDirectoryLink(string $target, string $link): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($target).' 2>&1', $output, $code);
            $this->assertSame(0, $code, '정션을 만들지 못했다: '.implode(' ', $output));

            return;
        }

        $this->assertTrue(symlink($target, $link));
    }

    /**
     * 부모 디렉터리를 보장하고 경로를 그대로 돌려준다.
     *
     * @param  string  $path  파일 경로
     * @return string 같은 경로
     */
    private function ensureParent(string $path): string
    {
        File::ensureDirectoryExists(dirname($path));

        return $path;
    }

    /**
     * base_path 상대 경로로 바꾼다.
     *
     * @param  string  $absolute  절대 경로
     * @return string 상대 경로
     */
    private function relative(string $absolute): string
    {
        return ltrim(substr($absolute, strlen(base_path())), '/\\');
    }
}
