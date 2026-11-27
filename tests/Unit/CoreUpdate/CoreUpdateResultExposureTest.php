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
use App\Models\User;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 관리자 화면 코어 업데이트 응답이 터미널과 같은 결과·실패 정보를 싣는지 (7.2.1).
 *
 * 터미널은 끝날 때 번들 실패 항목과 다시 시도할 명령, 검색 인덱스 결과, 점검 모드가 남았는지, 실행 기록 파일 위치를
 * 보여 준다. 웹은 같은 정보를 `run` 의 `bundled_result`·`maintenance_left_on`·`started_by`·`log_file`, 그리고
 * `warnings`·`notices` 로 싣는다. 시작 전 PHP 확장 점검도 터미널과 같은 판정으로 파일을 바꾸기 전에 멈춘다.
 *
 * 커버 축: core_update_web result exposure
 */
class CoreUpdateResultExposureTest extends TestCase
{
    use RefreshDatabase;

    /** base_path 아래 격리 루트 */
    private string $root;

    /** 원래 storage 경로 */
    private string $originalStoragePath;

    /** @var list<CommandRunResult> 번들 항목 실행이 차례로 돌려줄 결과 */
    private array $runQueue = [];

    /**
     * 격리 storage 와 픽스처 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_result_exposure_'.uniqid());
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
     * 번들 단계가 끝나면 성공·실패·건너뜀 수, 실패 항목(사유·다시 시도할 명령), 검색 인덱스 결과를 싣는다.
     *
     * @scenario run_state=finished, bundled_extensions=on, rebuild_search_index=on, failing_step=bundled
     *
     * @effects the_bundled_result_lists_failed_items_with_retry_commands, the_bundled_result_carries_the_search_index_outcome
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bundled_result_lists_failed_items_retry_commands_and_search_index(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true, rebuildSearchIndex: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);
        $state->mergeContext(['bundled_progress' => [
            'items' => [
                ['type' => 'module', 'identifier' => 'sirsoft-board', 'strategy' => 'keep', 'from_version' => '1.0.0', 'to_version' => '1.1.0'],
                ['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'overwrite', 'from_version' => '1.0.0', 'to_version' => '1.0.1'],
            ],
            'index' => 0, 'success' => 0, 'failed' => 0, 'planned' => 2,
        ]]);

        $this->assertNull($this->orchestrator()->status()['run']['bundled_result'], '번들 단계가 끝나기 전에 결과를 실었다');

        $outputs = [
            new CommandRunResult(1, '[BUNDLED-RESULT] {"success":0,"failed":1,"skipped":0,"aborted":false,"failed_items":[{"type":"module","identifier":"sirsoft-board","strategy":"keep","reason":"boom","exit_code":1}]}', ExecutionMode::Subprocess),
            new CommandRunResult(0, '[BUNDLED-RESULT] {"success":1,"failed":0,"search_index":{"status":"rebuilt","message":"2 indexes rebuilt"}}', ExecutionMode::Subprocess),
        ];

        $this->runQueue = $outputs;
        $this->orchestrator()->advance();
        $this->orchestrator()->advance();
        $result = $this->orchestrator()->status()['run']['bundled_result'];

        $this->assertIsArray($result, '번들 단계가 끝났는데 결과가 없다');
        $this->assertSame(1, $result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([[
            'type' => 'module',
            'identifier' => 'sirsoft-board',
            'reason' => 'boom',
            'retry_command' => 'php artisan module:update sirsoft-board --source=bundled --force --layout-strategy=keep',
        ]], $result['failed_items']);
        $this->assertSame(['status' => 'rebuilt', 'message' => '2 indexes rebuilt'], $result['search_index']);
    }

    /**
     * 결과 줄 없이 실패로 끝난 항목(자식이 죽음)도 실패 항목으로 적고 다시 시도할 명령을 붙인다.
     *
     * @scenario failing_step=bundled
     *
     * @effects the_bundled_result_lists_failed_items_with_retry_commands
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bundled_item_without_result_line_is_listed_as_failed(): void
    {
        $item = ['type' => 'lang_pack', 'identifier' => 'g7-core-ja', 'strategy' => 'overwrite'];

        $items = CoreUpdateOrchestrator::bundledFailedItems($item, [], 255, false);

        $this->assertCount(1, $items);
        $this->assertSame('g7-core-ja', $items[0]['identifier']);
        $this->assertSame((string) __('extensions.commands.bundled_update.item_process_aborted', ['code' => 255]), $items[0]['reason']);
        $this->assertSame('php artisan language-pack:update g7-core-ja --source=bundled', $items[0]['retry_command']);
        $this->assertSame([], CoreUpdateOrchestrator::bundledFailedItems($item, ['success' => 1, 'failed' => 0], 0, true));
    }

    /**
     * 되돌리기 실패나 점검 해제 실패로 점검 모드가 남았으면 `maintenance_left_on` 이 true 다. 진행 중에는 false.
     *
     * @scenario run_state=finished, rollback_outcome=restore_failed
     *
     * @effects the_status_reports_maintenance_left_on
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_maintenance_left_on_is_reported(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->mergeContext(['maintenance_enabled' => true]);

        $this->assertFalse($this->orchestrator()->status()['run']['maintenance_left_on'], '진행 중인데 점검 모드가 남았다고 했다');

        $this->state()->patch(['status' => CoreUpdateRunState::STATUS_ROLLBACK_FAILED, 'rollback' => ['restored' => false, 'maintenance_left_on' => true]]);
        $this->assertTrue($this->orchestrator()->status()['run']['maintenance_left_on']);

        $this->state()->patch(['status' => CoreUpdateRunState::STATUS_COMPLETED, 'rollback' => null]);
        $this->assertTrue($this->orchestrator()->status()['run']['maintenance_left_on'], '끝났는데 점검 해제를 못 한 것을 알리지 않았다');

        $this->state()->mergeContext(['maintenance_enabled' => false]);
        $this->assertFalse($this->orchestrator()->status()['run']['maintenance_left_on']);
    }

    /**
     * 업데이트를 시작한 관리자의 번호와 이름을 싣는다. 찾지 못하면 null.
     *
     * @scenario concurrency=single, actor=with_permission
     *
     * @effects the_status_names_the_admin_who_started_the_run
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_started_by_names_the_admin(): void
    {
        $user = User::factory()->create(['name' => '업데이트관리자']);

        $this->state()->start(new CoreUpdateOptions, '7.2.0', '7.2.1', (int) $user->id);
        $this->assertSame(['id' => (int) $user->id, 'name' => '업데이트관리자'], $this->orchestrator()->status()['run']['started_by']);

        $this->state()->clear();
        $this->state()->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 987654);
        $this->assertNull($this->orchestrator()->status()['run']['started_by']);
    }

    /**
     * 실행이 끝나면 기록 전체를 storage/logs 아래 업데이트 보고서 파일로 남기고 그 상대 경로를 싣는다.
     *
     * @scenario run_state=finished, failing_step=none
     *
     * @effects the_finished_run_leaves_a_log_file
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_finished_run_leaves_a_log_file(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->appendLog('기록 파일에 남을 줄');

        $this->assertNull($this->orchestrator()->status()['run']['log_file']);

        $orchestrator = $this->orchestrator();
        $finish = new \ReflectionMethod($orchestrator, 'finishSuccessfully');
        $finish->invoke($orchestrator);

        $logFile = $this->orchestrator()->status()['run']['log_file'];

        $this->assertIsString($logFile);
        $this->assertStringStartsWith('storage/', $logFile, '사이트 루트 기준 상대 경로가 아니다');
        $this->assertStringContainsString('/logs/'.CoreUpdateService::UPDATE_REPORT_PREFIX.'success_', $logFile);
        $this->assertFileExists(base_path($logFile));
        $content = (string) file_get_contents(base_path($logFile));
        $this->assertStringContainsString('기록 파일에 남을 줄', $content);
        $this->assertStringContainsString('시작 버전: 7.2.0', $content);
    }

    /**
     * 업그레이드 스텝의 `[DOCROOT_EXPOSURE]` 표식을 터미널과 같은 머리말로 경고에 옮기고, 명령에 표식 옵션이 있다.
     *
     * @scenario failing_step=none
     *
     * @effects the_docroot_exposure_notice_reaches_the_warnings
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_docroot_exposure_marker_becomes_a_warning(): void
    {
        $this->assertTrue(Artisan::all()['core:execute-upgrade-steps']->getDefinition()->hasOption('markers'));

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);

        $orchestrator = $this->orchestrator();
        $method = new \ReflectionMethod($orchestrator, 'appendDocrootExposureWarnings');
        $method->invoke($orchestrator, "정리 중\n[DOCROOT_EXPOSURE] {\"warnings\":[\"public/storage 사본을 확인하세요\"]}\n[STEPS_EXECUTED] {\"count\":0}");

        $warnings = $this->orchestrator()->status()['run']['warnings'];
        $this->assertContains(
            (string) __('settings.core_update.console.docroot_exposure_header')."\n- public/storage 사본을 확인하세요",
            $warnings,
        );
    }

    /**
     * 권한 정상화 실패 경고에 실패 경로 앞 5개·남은 수와 복구 명령 예시를 붙인다.
     *
     * @scenario failing_step=none
     *
     * @effects the_permission_warning_names_paths_and_a_recovery_command
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_permission_warning_detail_names_paths_and_recovery_command(): void
    {
        $paths = array_map(static fn (int $i): string => "/var/www/storage/f{$i}", range(1, 7));

        $detail = CoreUpdateOrchestrator::permissionWarningDetail(['target' => '/var/www/storage', 'kind' => 'chown', 'failed' => 7, 'failed_paths' => $paths]);

        $this->assertStringContainsString('/var/www/storage/f5', $detail);
        $this->assertStringNotContainsString('/var/www/storage/f6', $detail);
        $this->assertStringContainsString((string) __('settings.core_update.web.permission_warning_more', ['count' => '2']), $detail);
        $this->assertStringContainsString('sudo chown -R <owner>:<group> /var/www/storage', $detail);

        $groupWrite = CoreUpdateOrchestrator::permissionWarningDetail(['target' => '/var/www/bootstrap', 'kind' => 'group_writable', 'failed' => 1, 'failed_paths' => ['/var/www/bootstrap/cache']]);
        $this->assertStringContainsString('sudo chmod -R g+w /var/www/bootstrap', $groupWrite);
    }

    /**
     * 적용 대상 산출이 공식 번들 배치 요약을 기록뿐 아니라 알림(notices)에도 싣는다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects the_plan_and_apply_summaries_reach_the_notices
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bundled_placement_summary_reaches_the_notices(): void
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

        $requests = 0;
        do {
            $status = $this->realOrchestrator()->advance();
            $requests++;
        } while (($status['run']['next_step'] ?? null) === CoreUpdateStep::Plan->value && $requests < 100);

        // 배치 수는 픽스처마다 다를 수 있어 문구의 앞부분(첫 자리표시자 앞)으로 찾는다
        $prefix = (string) strtok((string) __('settings.core_update.web.log_bundled_placement', ['placed' => '§', 'unplaced' => '§']), '§');
        $placement = array_values(array_filter(
            (array) $status['run']['notices'],
            static fn (string $notice): bool => str_starts_with($notice, $prefix),
        ));
        $this->assertCount(1, $placement, '배치 요약이 알림에 한 번 실리지 않았다');
    }

    /**
     * 받은 배포본이 요구하는 코어 PHP 확장이 서버에 없으면 내려받기 단계에서 터미널과 같은 문구로 멈추고, 백업·적용으로 가지 않는다.
     *
     * @scenario run_state=running, source=manual_zip, failing_step=download
     *
     * @effects the_web_stops_before_applying_when_a_required_php_extension_is_missing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_missing_core_php_extension_stops_the_download_step(): void
    {
        $pendingBase = $this->root.DIRECTORY_SEPARATOR.'core_pending';
        File::ensureDirectoryExists($pendingBase);
        config(['app.update.pending_path' => $pendingBase, 'app.update.reserved_paths' => []]);

        File::move($this->makeZip([
            'g7/composer.json' => '{}',
            'g7/config/app.php' => "<?php return ['version' => env('APP_VERSION', '7.9.9')];",
            'g7/app/Support/Requirements/php-extensions.json' => '{"required":["g7_missing_ext_for_test"]}',
        ]), $pendingBase.DIRECTORY_SEPARATOR.'g7-7.9.9.zip');

        $state = $this->state();
        $state->start(new CoreUpdateOptions(zipFile: 'g7-7.9.9.zip'), '7.2.0', null, 1);
        $state->patch(['next_step' => CoreUpdateStep::Download->value, 'completed_steps' => ['preflight', 'maintenance']]);

        $requests = 0;
        do {
            $status = $this->realOrchestrator()->advance();
            $requests++;
        } while (($status['run']['next_step'] ?? null) === CoreUpdateStep::Download->value
            && ($status['run']['status'] ?? null) === CoreUpdateRunState::STATUS_RUNNING
            && $requests < 300);

        $this->assertNotSame(CoreUpdateRunState::STATUS_RUNNING, $status['run']['status'] ?? null, 'PHP 확장이 없는데 업데이트가 이어졌다');
        $this->assertSame(
            (string) __('settings.core_update.console.php_extension_core_missing', ['extensions' => 'g7_missing_ext_for_test']),
            $status['run']['error']['message'] ?? null,
        );
        $this->assertNotContains(CoreUpdateStep::Backup->value, (array) ($status['run']['completed_steps'] ?? []));
        $this->assertNotContains(CoreUpdateStep::Apply->value, (array) ($status['run']['completed_steps'] ?? []));
    }

    /**
     * 번들 항목 실행 결과를 차례로 돌려주는 오케스트레이터. 시계는 단계 시작 뒤 곧바로 몫을 다 쓴 것으로 답한다.
     *
     * 결과는 `$this->runQueue` 에서 차례로 꺼낸다(다 쓰면 성공 1건).
     *
     * @return CoreUpdateOrchestrator 오케스트레이터
     */
    private function orchestrator(): CoreUpdateOrchestrator
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (): CommandRunResult {
            return array_shift($this->runQueue) ?? new CommandRunResult(0, '[BUNDLED-RESULT] {"success":1,"failed":0}', ExecutionMode::Subprocess);
        });
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

        foreach (['app/A.php' => 'same-a', 'app/B.php' => 'old-b'] as $rel => $content) {
            $put($old, $rel, $content);
        }

        foreach (['app/A.php' => 'same-a', 'app/B.php' => 'new-b', 'app/C.php' => 'added'] as $rel => $content) {
            $put($new, $rel, $content);
        }

        return [$old, $new, ['app']];
    }
}
