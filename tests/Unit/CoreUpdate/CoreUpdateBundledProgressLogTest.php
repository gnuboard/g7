<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
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
 * 관리자 화면 코어 업데이트의 번들 단계가 항목마다 남기는 실행 기록과 응답의 `run.current_item`.
 *
 * 웹은 요청 하나에 번들 항목 하나를 처리한다. 화면(옛 판 포함)은 실행 기록 줄만 보여 주므로, 항목마다 무엇을 몇 판에서
 * 몇 판으로 올렸는지·실패 사유·다시 시도할 명령을 한 줄로 남긴다(명령줄 화면과 같은 정보). 새 화면은 `run.current_item`
 * 으로 다음에 처리할 항목을 그린다.
 *
 * 커버 축: core_update_bundled_progress_log
 */
class CoreUpdateBundledProgressLogTest extends TestCase
{
    /** 격리 루트 */
    private string $root;

    /**
     * 격리 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_bundled_log_'.uniqid());
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'state');
    }

    /**
     * 픽스처를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 번들 단계를 처음 시작하면 대상 수를 종류별로 한 줄 남기고, 첫 항목을 마치면 종류·식별자·이전 판 → 새 판·소요를 남긴다.
     *
     * @scenario case=first_item_success
     *
     * @effects targets_summary_logged_at_step_start, success_line_has_type_identifier_and_versions, versions_carried_into_progress_items
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_logs_the_target_summary_and_a_versioned_success_line(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);

        $this->orchestrator($this->runnerReturning(0, '[BUNDLED-RESULT] {"success":1,"failed":0}'), [
            ['identifier' => 'sirsoft-basic', 'current_version' => '1.0.0', 'latest_version' => '1.0.1'],
            ['identifier' => 'sirsoft-admin_basic', 'current_version' => '2.0.0', 'latest_version' => '2.1.0'],
        ])->advance();

        $after = $this->state();
        $targets = (string) __('settings.core_update.web.log_bundled_targets', [
            'total' => '2',
            'summary' => (string) __('settings.core_update.web.log_bundled_targets_part', ['type' => '템플릿', 'count' => '2']),
        ]);
        $this->assertLogEndsWith($after, $targets, '번들 단계 시작 때 대상 수 요약이 없다');

        $done = (string) __('settings.core_update.web.log_bundled_item_done', [
            'position' => '1', 'total' => '2', 'type' => '템플릿', 'identifier' => 'sirsoft-basic',
            'from' => '1.0.0', 'to' => '1.0.1', 'seconds' => '0',
        ]);
        $this->assertLogEndsWith($after, $done, '항목 성공 줄에 종류·식별자·판이 없다');

        $progress = (array) $after->context('bundled_progress');
        $this->assertSame('2.0.0', $progress['items'][1]['from_version'] ?? null, '진행 항목에 이전 판을 싣지 않았다');
        $this->assertSame('2.1.0', $progress['items'][1]['to_version'] ?? null, '진행 항목에 새 판을 싣지 않았다');
    }

    /**
     * 항목이 실패하면 자식이 알려 준 사유와 그 항목만 다시 갱신하는 명령을 한 줄로 남긴다.
     *
     * @scenario case=item_failure
     *
     * @effects failure_line_has_reason_and_retry_command
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_logs_the_failure_reason_and_the_retry_command(): void
    {
        $item = ['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'keep', 'from_version' => '1.0.0', 'to_version' => '1.0.1'];
        $this->runWithProgress([$item], 0);

        $payload = json_encode([
            'success' => 0, 'failed' => 1, 'skipped' => 0, 'aborted' => false,
            'failed_items' => [['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'keep', 'reason' => '파일 교체 실패', 'exit_code' => 1]],
        ], JSON_UNESCAPED_UNICODE);
        $this->orchestrator($this->runnerReturning(1, '[BUNDLED-RESULT] '.$payload))->advance();

        $failed = (string) __('settings.core_update.web.log_bundled_item_failed', [
            'position' => '1', 'total' => '1', 'type' => '템플릿', 'identifier' => 'sirsoft-basic',
            'reason' => '파일 교체 실패',
            'command' => ExecuteBundledUpdatesCommand::retryCommand($item),
        ]);
        $this->assertLogEndsWith($this->state(), $failed, '실패 줄에 사유·다시 시도 명령이 없다');
        $this->assertStringContainsString('template:update sirsoft-basic --source=bundled --force --layout-strategy=keep', $failed);
    }

    /**
     * 결과 줄 없이 끝난 항목(강제 종료 등)은 종료 코드를 사유로 남긴다.
     *
     * @scenario case=item_failure
     *
     * @effects missing_result_line_reports_exit_code
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_missing_result_line_reports_the_exit_code(): void
    {
        $item = ['type' => 'lang_pack', 'identifier' => 'g7-core-ja', 'strategy' => null];

        $line = CoreUpdateOrchestrator::bundledItemLogLine($item, 3, 5, [], 255, 1.2);

        $this->assertSame((string) __('settings.core_update.web.log_bundled_item_failed', [
            'position' => '3', 'total' => '5', 'type' => '언어팩', 'identifier' => 'g7-core-ja',
            'reason' => (string) __('extensions.commands.bundled_update.item_process_aborted', ['code' => 255]),
            'command' => 'php artisan language-pack:update g7-core-ja --source=bundled',
        ]), $line);
    }

    /**
     * `run.current_item` 은 번들 단계가 다음에 처리할 항목이다 — status·advance 응답 모두. 마지막 항목을 마친 뒤에는 null.
     *
     * @scenario case=current_item
     *
     * @effects current_item_points_to_next_item, current_item_null_after_last_item
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_current_item_points_to_the_next_item_and_is_null_after_the_last(): void
    {
        $this->runWithProgress([
            ['type' => 'module', 'identifier' => 'sirsoft-board', 'strategy' => 'overwrite', 'from_version' => '1.0.0', 'to_version' => '1.1.0'],
            ['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'keep', 'from_version' => '1.0.0', 'to_version' => '1.0.1'],
        ], 0);

        $status = $this->orchestrator($this->runnerReturning(0, '[BUNDLED-RESULT] {"success":1,"failed":0}'))->status();
        $this->assertSame([
            'step' => 'bundled', 'type' => 'module', 'identifier' => 'sirsoft-board', 'name' => null,
            'from_version' => '1.0.0', 'to_version' => '1.1.0', 'position' => 1, 'total' => 2,
        ], $status['run']['current_item'] ?? null, 'status 응답의 current_item 이 첫 항목이 아니다');

        $advanced = $this->orchestrator($this->runnerReturning(0, '[BUNDLED-RESULT] {"success":1,"failed":0}'))->advance();
        $this->assertSame('sirsoft-basic', $advanced['run']['current_item']['identifier'] ?? null, 'advance 응답의 current_item 이 다음 항목이 아니다');
        $this->assertSame(2, $advanced['run']['current_item']['position'] ?? null);

        $last = $this->orchestrator($this->runnerReturning(0, '[BUNDLED-RESULT] {"success":1,"failed":0}'))->advance();
        $this->assertNull($last['run']['current_item'], '마지막 항목을 마친 뒤에도 current_item 이 남았다');
    }

    /**
     * 번들 단계 진행분을 둔 실행 상태를 만든다.
     *
     * @param  array<int, array<string, mixed>>  $items  처리할 항목
     * @param  int  $index  다음에 처리할 항목 차례 (0부터)
     * @return void 반환값 없음
     */
    private function runWithProgress(array $items, int $index): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);
        $state->mergeContext(['bundled_progress' => [
            'items' => $items, 'index' => $index, 'success' => 0, 'failed' => 0, 'planned' => count($items),
        ]]);
    }

    /**
     * 항목 실행을 주어진 종료 코드·출력으로 돌려주는 러너.
     *
     * @param  int  $exitCode  종료 코드
     * @param  string  $output  출력
     * @return CommandRunnerResolver 러너 해석기
     */
    private function runnerReturning(int $exitCode, string $output): CommandRunnerResolver
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('run')->andReturn(new CommandRunResult($exitCode, $output, ExecutionMode::Subprocess));
        $runners = Mockery::mock(CommandRunnerResolver::class);
        $runners->shouldReceive('resolve')->andReturn($runner);

        return $runners;
    }

    /**
     * 시험용 지휘자. 시계는 단계 시작 뒤 곧바로 몫을 다 쓴 것으로 답한다(요청 안에서 기다리지 않게).
     *
     * @param  CommandRunnerResolver  $runners  러너 해석기
     * @param  array<int, array<string, string>>  $templates  번들에 새 판이 있는 템플릿
     * @return CoreUpdateOrchestrator 지휘자
     */
    private function orchestrator(CommandRunnerResolver $runners, array $templates = []): CoreUpdateOrchestrator
    {
        $calls = 0;
        $clock = static function () use (&$calls): float {
            return $calls++ === 0 ? 1000.0 : 1_000_000.0;
        };
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('collectBundledExtensionUpdates')->andReturn(['modules' => [], 'plugins' => [], 'templates' => $templates]);
        $langPacks = Mockery::mock(LanguagePackService::class);
        $langPacks->shouldReceive('collectBundledLangPackUpdates')->andReturn([]);
        $this->app->instance(LanguagePackService::class, $langPacks);

        return new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), $runners, new CoreUpdateTimeBudget($clock, 300));
    }

    /**
     * 실행 기록에 그 문구로 끝나는 줄이 있는지 확인한다.
     *
     * @param  CoreUpdateRunState  $state  실행 상태
     * @param  string  $expected  기대 문구
     * @param  string  $message  실패 메시지
     * @return void 반환값 없음
     */
    private function assertLogEndsWith(CoreUpdateRunState $state, string $expected, string $message): void
    {
        $this->assertNotEmpty(
            array_filter($state->logs(), static fn (string $line): bool => str_ends_with($line, $expected)),
            $message."\n".implode("\n", $state->logs()),
        );
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
}
