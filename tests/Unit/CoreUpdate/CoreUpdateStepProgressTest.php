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
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 관리자 화면 코어 업데이트 응답의 `run.step_progress` — 단계가 여러 요청에 걸칠 때 어디까지 갔는지.
 *
 * 웹은 요청 하나가 단계 하나(또는 그 조각)를 처리한다. 화면은 이 값으로 단계 줄 아래 진행 막대와 「n / m」 수치를 그린다.
 * 값은 지금 단계(`current_step`)의 것일 때만 싣고, 단계가 끝나면 지운다. 동작(순서·판정)에는 쓰지 않는다.
 *
 * 커버 축: core_update_web step_progress
 */
class CoreUpdateStepProgressTest extends TestCase
{
    /** base_path 아래 격리 루트 */
    private string $root;

    /** 원래 storage 경로 */
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
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_step_progress_'.uniqid());
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'state');
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');

        $this->originalStoragePath = storage_path();
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
    }

    /**
     * storage 경로와 업데이트 진행 플래그를 되돌리고 픽스처를 지운다.
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
     * 압축 풀기를 다음 요청으로 이어 가면 푼 항목 수 / 전체 항목 수를 싣고, 단계가 끝나면 null 이 된다.
     *
     * @scenario run_state=running, source=manual_zip, failing_step=none
     *
     * @effects the_download_step_progress_counts_extracted_entries, the_step_progress_clears_when_the_step_changes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_download_continuation_reports_extracted_entries_and_total(): void
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

        $seen = [];
        $requests = 0;

        do {
            $status = $this->realOrchestrator()->advance();
            $requests++;

            if (($status['run']['next_step'] ?? null) === CoreUpdateStep::Download->value && is_array($status['run']['step_progress'] ?? null)) {
                $seen[] = $status['run']['step_progress'];
            }
        } while (($status['run']['next_step'] ?? null) === CoreUpdateStep::Download->value
            && ($status['run']['status'] ?? null) === CoreUpdateRunState::STATUS_RUNNING
            && $requests < 300);

        $this->assertSame(CoreUpdateStep::Backup->value, $status['run']['next_step'] ?? null, (string) json_encode($status['run']['error'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($seen, '압축 풀기를 이어 가는 동안 step_progress 가 없었다');

        $previous = -1;
        foreach ($seen as $progress) {
            $this->assertSame('download', $progress['step']);
            $this->assertSame('entries', $progress['unit']);
            $this->assertSame(count($files), $progress['total'], '전체 항목 수가 압축 파일의 항목 수와 다르다');
            $this->assertGreaterThan($previous, $progress['done'], '푼 항목 수가 앞으로 가지 않았다');
            $this->assertLessThan($progress['total'], $progress['done']);
            $previous = $progress['done'];
        }

        $this->assertNull($status['run']['step_progress'], '압축 풀기가 끝난 뒤에도 그 진행량이 남았다');
        $this->assertNull($this->state()->context('step_progress'), '끝난 단계의 진행량을 지우지 않았다');
    }

    /**
     * 적용 대상 산출(파일 비교)을 다음 요청으로 이어 가면 확인한 파일 수를 싣는다(전체를 모르면 total 은 null).
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects the_plan_step_progress_counts_checked_files, the_step_progress_clears_when_the_step_changes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_plan_apply_continuation_reports_checked_files(): void
    {
        [$old, $new, $targets] = $this->planFixture();
        config([
            'app.update.targets' => $targets,
            'app.update.protected_paths' => [],
            'app.update.excludes' => [],
            'app.update.reserved_paths' => [],
        ]);

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->mergeContext(['pending_path' => $new, 'backup_path' => $old]);
        $state->patch(['next_step' => CoreUpdateStep::Plan->value]);

        $seen = [];
        $requests = 0;

        do {
            $status = $this->realOrchestrator()->advance();
            $requests++;

            if (($status['run']['next_step'] ?? null) === CoreUpdateStep::Plan->value && is_array($status['run']['step_progress'] ?? null)) {
                $seen[] = $status['run']['step_progress'];
            }
        } while (($status['run']['next_step'] ?? null) === CoreUpdateStep::Plan->value && $requests < 100);

        $this->assertSame(CoreUpdateStep::Apply->value, $status['run']['next_step'] ?? null, (string) json_encode($status['run']['error'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($seen, '파일 비교를 이어 가는 동안 step_progress 가 없었다');

        foreach ($seen as $progress) {
            $this->assertSame('plan', $progress['step']);
            $this->assertSame('files', $progress['unit']);
            $this->assertGreaterThanOrEqual(0, $progress['done']);

            if ($progress['total'] !== null) {
                $this->assertLessThanOrEqual($progress['total'], $progress['done']);
            }
        }

        $this->assertNotEmpty(array_filter($seen, static fn (array $p): bool => $p['done'] > 0), '확인한 파일 수가 한 번도 늘지 않았다');
        $this->assertNull($status['run']['step_progress'], '산출이 끝난 뒤에도 그 진행량이 남았다');
    }

    /**
     * 적용 대상 산출의 확인 수는 구간(새 파일 목록 → 적용 대상 → 출하 목록)을 넘어 누적된다 — 화면 수치가 줄어들지 않는다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects the_plan_step_progress_accumulates_across_phases
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_plan_checked_count_accumulates_across_phases(): void
    {
        [$old, $new, $targets] = $this->planFixture();
        config([
            'app.update.targets' => $targets,
            'app.update.protected_paths' => [],
            'app.update.excludes' => [],
            'app.update.reserved_paths' => [],
        ]);

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->mergeContext(['pending_path' => $new, 'backup_path' => $old]);
        $state->patch(['next_step' => CoreUpdateStep::Plan->value]);

        $done = [];
        $requests = 0;

        do {
            $status = $this->realOrchestrator()->advance();
            $requests++;

            if (($status['run']['next_step'] ?? null) === CoreUpdateStep::Plan->value && is_array($status['run']['step_progress'] ?? null)) {
                $done[] = (int) $status['run']['step_progress']['done'];
            }
        } while (($status['run']['next_step'] ?? null) === CoreUpdateStep::Plan->value && $requests < 100);

        $this->assertSame(CoreUpdateStep::Apply->value, $status['run']['next_step'] ?? null, (string) json_encode($status['run']['error'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThan(1, count($done), '여러 요청에 걸친 산출이 아니어서 누적을 잴 수 없다');

        $sorted = $done;
        sort($sorted);
        $this->assertSame($sorted, $done, '구간이 바뀔 때 확인 수가 줄었다: '.implode(', ', $done));
    }

    /**
     * 번들 단계는 끝낸 항목 수(다음 항목 순번 - 1) / 항목 수를 싣는다 — current_item 과 같은 근거. 마지막 항목 뒤에는 null.
     *
     * @scenario run_state=running, bundled_extensions=on, failing_step=none
     *
     * @effects the_bundled_step_progress_counts_finished_items, the_step_progress_clears_when_the_step_changes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bundled_progress_counts_finished_items(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);
        $state->mergeContext(['bundled_progress' => [
            'items' => [
                ['type' => 'module', 'identifier' => 'sirsoft-board', 'strategy' => 'overwrite', 'from_version' => '1.0.0', 'to_version' => '1.1.0'],
                ['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'keep', 'from_version' => '1.0.0', 'to_version' => '1.0.1'],
            ],
            'index' => 0, 'success' => 0, 'failed' => 0, 'planned' => 2,
        ]]);

        $status = $this->bundledOrchestrator()->status();
        $this->assertSame(['step' => 'bundled', 'done' => 0, 'total' => 2, 'unit' => 'items'], $status['run']['step_progress']);

        $advanced = $this->bundledOrchestrator()->advance();
        $this->assertSame(['step' => 'bundled', 'done' => 1, 'total' => 2, 'unit' => 'items'], $advanced['run']['step_progress']);
        $this->assertSame(2, $advanced['run']['current_item']['position'] ?? null, 'step_progress 와 current_item 의 근거가 갈렸다');

        $last = $this->bundledOrchestrator()->advance();
        $this->assertNull($last['run']['step_progress'], '마지막 항목을 마친 뒤에도 step_progress 가 남았다');
    }

    /**
     * 적어 둔 진행량이 지금 단계의 것이 아니면(단계가 바뀐 뒤·끝난 실행) 응답에 싣지 않는다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects the_step_progress_clears_when_the_step_changes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_progress_of_another_step_is_not_reported(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Backup->value]);
        $state->mergeContext(['step_progress' => ['step' => 'download', 'done' => 3, 'total' => 8, 'unit' => 'entries']]);

        $this->assertNull($this->bundledOrchestrator()->status()['run']['step_progress'], '앞 단계의 진행량을 지금 단계 것처럼 실었다');

        $this->state()->mergeContext(['step_progress' => ['step' => 'backup', 'done' => 3, 'total' => null, 'unit' => 'files']]);
        $this->assertSame(
            ['step' => 'backup', 'done' => 3, 'total' => null, 'unit' => 'files'],
            $this->bundledOrchestrator()->status()['run']['step_progress'],
        );

        $this->state()->patch(['status' => CoreUpdateRunState::STATUS_ROLLING_BACK]);
        $this->assertNull($this->bundledOrchestrator()->status()['run']['step_progress'], '되돌리는 중에 단계 진행량을 실었다');

        $this->state()->mergeContext(['step_progress' => ['step' => 'rollback', 'done' => 1, 'total' => 4, 'unit' => 'tasks']]);
        $this->assertSame('rollback', $this->bundledOrchestrator()->status()['run']['step_progress']['step'] ?? null);
    }

    /**
     * 실제 서비스와 매 요청이 항목 하나만 처리하고 멈추는 예산으로 오케스트레이터를 만든다.
     *
     * @return CoreUpdateOrchestrator 오케스트레이터
     */
    private function realOrchestrator(): CoreUpdateOrchestrator
    {
        $tick = 0.0;
        $budget = new CoreUpdateTimeBudget(static function () use (&$tick): float {
            return $tick += 100.0;
        }, 30);
        $service = app(CoreUpdateService::class);

        return new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), $budget);
    }

    /**
     * 번들 항목 실행이 늘 성공하는 오케스트레이터. 시계는 단계 시작 뒤 곧바로 몫을 다 쓴 것으로 답한다.
     *
     * @return CoreUpdateOrchestrator 오케스트레이터
     */
    private function bundledOrchestrator(): CoreUpdateOrchestrator
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('run')->andReturn(new CommandRunResult(0, '[BUNDLED-RESULT] {"success":1,"failed":0}', ExecutionMode::Subprocess));
        $runners = Mockery::mock(CommandRunnerResolver::class);
        $runners->shouldReceive('resolve')->andReturn($runner);

        $calls = 0;
        $clock = static function () use (&$calls): float {
            return $calls++ === 0 ? 1000.0 : 1_000_000.0;
        };
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('collectBundledExtensionUpdates')->andReturn(['modules' => [], 'plugins' => [], 'templates' => []]);
        $langPacks = Mockery::mock(LanguagePackService::class);
        $langPacks->shouldReceive('collectBundledLangPackUpdates')->andReturn([]);
        $this->app->instance(LanguagePackService::class, $langPacks);

        return new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), $runners, new CoreUpdateTimeBudget($clock, 300));
    }

    /**
     * 격리 경로의 실행 상태.
     *
     * @return CoreUpdateRunState 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState($this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME);
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
     * 구버전(백업)과 신버전(전개본) 픽스처를 만든다.
     *
     * @return array{0: string, 1: string, 2: list<string>} 백업 경로, 전개본 경로, targets
     */
    private function planFixture(): array
    {
        $old = $this->root.DIRECTORY_SEPARATOR.'old';
        $new = $this->root.DIRECTORY_SEPARATOR.'new';

        $put = static function (string $base, string $relative, string $content): void {
            $path = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $content);
        };

        foreach (['app/A.php' => 'same-a', 'app/B.php' => 'old-b', 'app/sub/D.php' => 'same-d', 'config/x.php' => 'x'] as $rel => $content) {
            $put($old, $rel, $content);
        }

        foreach (['app/A.php' => 'same-a', 'app/B.php' => 'new-b', 'app/C.php' => 'added', 'app/sub/D.php' => 'same-d', 'config/x.php' => 'longer-x', 'single.txt' => 'single'] as $rel => $content) {
            $put($new, $rel, $content);
        }

        return [$old, $new, ['app', 'config', 'single.txt']];
    }
}
