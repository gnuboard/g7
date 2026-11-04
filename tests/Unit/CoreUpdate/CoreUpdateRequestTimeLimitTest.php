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
use App\Extension\CoreVersionChecker;
use App\Extension\Helpers\CoreBackupHelper;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 웹 코어 업데이트의 단계가 서버 요청 시간 제한 안에서 끝나거나, 끊겨도 스스로 정리되는지 잰다.
 *
 * 한 요청이 한 단계를 맡아도 그 단계가 요청 시간 제한(`max_execution_time`)보다 길면 PHP 가
 * 치명 오류로 요청을 끊는다. 종전에는 그때 단계가 실패로 기록되지 않고 점유만 15분 남아,
 * 사이트가 점검 모드에 갇힌 채 자동 되돌리기도 돌지 않았다. 여기서 잠그는 것:
 *
 * 1. 점유는 자기 요청 수명 + 여유만큼만 남는다.
 * 2. 치명 오류로 끝난 단계는 그 자리에서 실패로 기록되고, 다음 호출이 되돌린다.
 * 3. 기록조차 못 남기고 사라진 요청의 단계는 다음 요청이 실패로 확정한다.
 * 4. 끊긴 되돌리기는 정해진 횟수만 다시 하고, 그 뒤에는 수동 복구 안내로 넘어간다.
 * 5. 사전 점검의 트리 훑기는 요청 몫 안에서 멈춘다.
 * 6. 적용 대상 산출은 여러 요청에 나눠도 한 번에 한 결과와 같다.
 * 7. 사전 점검은 링크·정션을 「쓰기 권한 없음」으로 막지 않는다.
 *
 * 커버 축: core_update_web request_time_limit·interruption·scan·plan_slicing
 */
class CoreUpdateRequestTimeLimitTest extends TestCase
{
    /** 이 테스트가 만든 격리 루트 (base_path 아래 — 사전 점검 후보가 base 상대 경로라서). */
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
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_time_'.uniqid());
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');

        // 실행 잠금·실패 리포트가 개발 저장소에 남지 않게 storage 를 격리한다.
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

        $this->removeTree($this->root);

        parent::tearDown();
    }

    /**
     * 점유는 자기 요청 수명 + 여유가 지나면 회수된다 (고정 15분이 아니다).
     *
     * @scenario request_time_limit=short, interruption=vanished_request, run_state=running
     *
     * @effects a_claim_lives_only_as_long_as_its_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_claim_expires_after_its_declared_request_lifetime(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);

        $this->assertTrue($state->claim('token-dead', CoreUpdateStep::Plan->value, 330));
        $this->ageClaim($state, 400);

        $this->assertTrue(
            $this->state()->claim('token-next', CoreUpdateStep::Plan->value, 330),
            '300초 제한에 죽은 요청의 점유가 15분 남으면 그동안 사이트가 점검 모드에 갇힌다',
        );

        // 창을 선언하지 않은 점유(구 상태 파일)는 종전 기본 창을 그대로 쓴다.
        $legacy = $this->state();
        $legacy->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $legacy->claim('token-legacy');
        $this->ageClaim($legacy, 400);
        $this->assertFalse($this->state()->claim('token-other'));
    }

    /**
     * 치명 오류(시간 초과)로 끝난 단계는 실패로 기록되고 점유가 풀리며, 다음 호출이 되돌린다.
     *
     * @scenario request_time_limit=short, interruption=fatal_shutdown, run_state=running
     *
     * @effects a_timed_out_step_is_recorded_as_failed_and_rolled_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_fatal_shutdown_records_the_step_as_failed_and_the_next_call_rolls_back(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Plan->value, 'completed_steps' => ['preflight', 'maintenance', 'download', 'backup', 'vendor']]);
        $state->claim('token-plan', CoreUpdateStep::Plan->value, 330);

        $budget = new CoreUpdateTimeBudget(null, 300);
        $budget->begin();
        $orchestrator = $this->orchestrator($state, $budget);

        $orchestrator->handleFatalShutdown('token-plan', [
            'type' => E_ERROR,
            'message' => 'Maximum execution time of 300 seconds exceeded',
            'file' => 'CoreBackupHelper.php',
            'line' => 462,
        ]);

        $reloaded = $this->state();
        $this->assertSame(CoreUpdateRunState::STATUS_ROLLING_BACK, $reloaded->status(), '실패로 기록되지 않으면 자동 되돌리기가 돌지 않는다');
        $this->assertSame(CoreUpdateStep::Plan->value, $reloaded->all()['error']['step'] ?? null);
        $this->assertStringContainsString('300', (string) ($reloaded->all()['error']['message'] ?? ''));
        $this->assertNull($reloaded->claimSummary(), '점유가 남으면 다음 호출이 409 를 받는다');

        $status = $this->orchestrator($this->state(), new CoreUpdateTimeBudget(null, 300))->advance();

        $this->assertSame(CoreUpdateRunState::STATUS_ROLLED_BACK, $status['run']['status']);
        $this->assertTrue($status['run']['rollback']['restored'] ?? false);
    }

    /**
     * 치명 오류가 아닌 종료와, 다른 요청이 이어받은 점유는 건드리지 않는다.
     *
     * @scenario request_time_limit=short, interruption=none, run_state=running
     *
     * @effects a_timed_out_step_is_recorded_as_failed_and_rolled_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_normal_shutdown_or_a_foreign_claim_is_left_alone(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->claim('token-live', CoreUpdateStep::Backup->value, 330);
        $orchestrator = $this->orchestrator($state, new CoreUpdateTimeBudget(null, 300));

        $orchestrator->handleFatalShutdown('token-live', ['type' => E_WARNING, 'message' => 'x']);
        $orchestrator->handleFatalShutdown('token-gone', ['type' => E_ERROR, 'message' => 'Maximum execution time of 300 seconds exceeded']);

        $reloaded = $this->state();
        $this->assertSame(CoreUpdateRunState::STATUS_RUNNING, $reloaded->status());
        $this->assertTrue($reloaded->claimSummary()['held'] ?? false);
    }

    /**
     * 기록 없이 사라진 요청(신호로 죽어 종료 처리도 못 돈 경우)의 단계는 다음 요청이 실패로 확정한다.
     *
     * @scenario request_time_limit=short, interruption=vanished_request, run_state=running
     *
     * @effects a_vanished_request_is_promoted_to_a_failure
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_claim_left_by_a_vanished_request_is_promoted_to_a_failure(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->claim('token-dead', CoreUpdateStep::Preflight->value, 60);
        $this->ageClaim($state, 120);

        $status = $this->orchestrator($this->state(), new CoreUpdateTimeBudget(null, 30))->advance();

        $this->assertSame(
            CoreUpdateRunState::STATUS_ROLLING_BACK,
            $status['run']['status'],
            '같은 단계를 다시 돌리면 같은 자리에서 또 죽는다 — 실패로 확정하고 되돌려야 한다',
        );
        $this->assertSame(CoreUpdateStep::Preflight->value, $status['run']['error']['step'] ?? null);
        $this->assertNull($status['run']['claim'], '이 요청의 점유는 끝나면 놓여야 한다');
    }

    /**
     * 되돌리기가 계속 끊기면 정해진 횟수 뒤 되돌리기 실패로 확정하고 수동 복구 안내를 연다.
     *
     * @scenario request_time_limit=short, interruption=rollback_interrupted, run_state=rolling_back
     *
     * @effects an_endlessly_interrupted_rollback_opens_manual_recovery
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_an_interrupted_rollback_gives_up_after_the_attempt_limit(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->mergeContext(['maintenance_enabled' => true]);
        $state->patch(['status' => CoreUpdateRunState::STATUS_ROLLING_BACK, 'error' => ['step' => 'apply', 'message' => 'x']]);

        $fatal = ['type' => E_ERROR, 'message' => 'Maximum execution time of 300 seconds exceeded'];

        for ($attempt = 1; $attempt <= CoreUpdateOrchestrator::MAX_ROLLBACK_ATTEMPTS; $attempt++) {
            $current = $this->state();
            $current->claim('token-'.$attempt, CoreUpdateOrchestrator::PHASE_ROLLBACK, 330);
            $budget = new CoreUpdateTimeBudget(null, 300);
            $budget->begin();
            $this->orchestrator($current, $budget)->handleFatalShutdown('token-'.$attempt, $fatal);

            $expected = $attempt < CoreUpdateOrchestrator::MAX_ROLLBACK_ATTEMPTS
                ? CoreUpdateRunState::STATUS_ROLLING_BACK
                : CoreUpdateRunState::STATUS_ROLLBACK_FAILED;

            $this->assertSame($expected, $this->state()->status(), $attempt.'번째 끊김 뒤 상태');
        }

        $rollback = (array) ($this->state()->all()['rollback'] ?? []);
        $this->assertFalse($rollback['restored'] ?? true);
        $this->assertTrue($rollback['maintenance_left_on'] ?? false, '점검 모드가 켜진 채라는 사실이 빠지면 운영자는 사이트가 멀쩡한 줄 안다');
    }

    /**
     * 파일을 이미 되돌린 뒤의 구간(소유권 복원·정리)이 계속 끊기면, 그 구간을 건너뛰고 점검 모드를 풀어 「되돌렸다」로 끝낸다.
     *
     * 실행 시간이 짧은 서버에서 「백업본으로 되돌렸습니다」 직후 구간이 매번 끊기면, 파일은 이미 되돌려져 있는데
     * 재시도 상한 뒤 `rollback_failed` 로 사이트가 점검 모드에 갇힌다.
     *
     * @scenario request_time_limit=short, interruption=rollback_interrupted, run_state=rolling_back
     *
     * @effects a_restored_site_is_reopened_even_if_the_rollback_cleanup_keeps_failing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_rollback_that_keeps_failing_after_the_files_were_restored_reopens_the_site(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->mergeContext([
            'maintenance_enabled' => true,
            'rollback_progress' => ['phase' => 'ownership', 'restored' => true, 'failed_targets' => [], 'caches_dropped' => true],
        ]);
        $state->patch(['status' => CoreUpdateRunState::STATUS_ROLLING_BACK, 'error' => ['step' => 'upgrade_steps', 'message' => 'x']]);

        $fatal = ['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded'];

        for ($attempt = 1; $attempt <= CoreUpdateOrchestrator::MAX_ROLLBACK_ATTEMPTS; $attempt++) {
            $current = $this->state();
            $current->claim('token-'.$attempt, CoreUpdateOrchestrator::PHASE_ROLLBACK, 60);
            $budget = new CoreUpdateTimeBudget(null, 30);
            $budget->begin();
            $this->orchestrator($current, $budget)->handleFatalShutdown('token-'.$attempt, $fatal);
        }

        $after = $this->state();
        $this->assertSame(CoreUpdateRunState::STATUS_ROLLED_BACK, $after->status(), '파일을 되돌렸는데 정리 구간 때문에 사이트를 점검 모드에 가두면 안 된다');
        $this->assertTrue($after->all()['rollback']['restored'] ?? false);
        $this->assertFalse($after->all()['rollback']['maintenance_left_on'] ?? true);
        $this->assertFalse((bool) $after->context('maintenance_enabled'), '점검 모드를 풀어야 사이트가 열린다');
        $this->assertContains((string) __('settings.core_update.web.rollback_cleanup_skipped'), $after->all()['warnings'] ?? [], '건너뛴 사실을 알려야 한다');
    }

    /**
     * 나눠 하는 되돌리기가 끊길 때마다 앞으로 나아가 있으면 「같은 자리에서 끊김」으로 세지 않는다.
     *
     * @scenario request_time_limit=short, interruption=rollback_interrupted, run_state=rolling_back
     *
     * @effects an_endlessly_interrupted_rollback_opens_manual_recovery
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_an_interrupted_rollback_that_keeps_moving_is_not_counted_as_stuck(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->patch(['status' => CoreUpdateRunState::STATUS_ROLLING_BACK, 'error' => ['step' => 'apply', 'message' => 'x']]);

        $fatal = ['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded'];

        for ($attempt = 1; $attempt <= CoreUpdateOrchestrator::MAX_ROLLBACK_ATTEMPTS + 1; $attempt++) {
            $current = $this->state();
            $current->mergeContext(['rollback_progress' => [
                'phase' => 'restore',
                'restored' => false,
                'failed_targets' => [],
                'restore' => ['phase' => 'overlay', 'target' => 3, 'cursor' => 'Services/File'.$attempt.'.php'],
            ]]);
            $current->claim('token-'.$attempt, CoreUpdateOrchestrator::PHASE_ROLLBACK, 60);
            $budget = new CoreUpdateTimeBudget(null, 30);
            $budget->begin();
            $this->orchestrator($current, $budget)->handleFatalShutdown('token-'.$attempt, $fatal);
        }

        $this->assertSame(CoreUpdateRunState::STATUS_ROLLING_BACK, $this->state()->status(), '앞으로 나아가는 되돌리기를 실패로 확정하면 다음 요청이 이어 받을 기회를 잃는다');
        $this->assertSame(1, (int) $this->state()->context('rollback_attempts'));
    }

    /**
     * 실행 제한을 다시 걸 수 없는 서버(`set_time_limit` 차단)에서는 몫을 요청 시작부터 센다.
     *
     * @scenario request_time_limit=short, interruption=none
     *
     * @effects a_claim_lives_only_as_long_as_its_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_slice_counts_from_the_request_start_when_the_timer_cannot_be_rearmed(): void
    {
        $this->assertSame(90.0, CoreUpdateTimeBudget::effectiveStart(false, 100.0, 90.0), '다시 걸지 못했으면 서버 시계는 요청 시작부터 돈다');
        $this->assertSame(100.0, CoreUpdateTimeBudget::effectiveStart(true, 100.0, 90.0));
        $this->assertSame(100.0, CoreUpdateTimeBudget::effectiveStart(false, 100.0, null));
        $this->assertSame(100.0, CoreUpdateTimeBudget::effectiveStart(false, 100.0, 120.0), '요청 시작이 미래면(시계 어긋남) 지금부터');
        $this->assertSame(30, CoreUpdateTimeBudget::effectiveLimit(300, '30'), '수명 선언은 요청값이 아니라 실제 제한을 따른다');
    }

    /**
     * 하위 명령 출력의 터미널 색 코드는 실패 사유·실행 기록에 실리지 않는다.
     *
     * @scenario request_time_limit=short, interruption=none, run_state=running
     *
     * @effects the_failure_reason_carries_no_terminal_escape_codes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_failure_reason_carries_no_terminal_escape_codes(): void
    {
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
             * 색 코드가 든 실패 출력을 돌려준다.
             *
             * @param  string  $command  커맨드
             * @param  array<string, mixed>  $parameters  인자
             * @return CommandRunResult 결과
             */
            public function run(string $command, array $parameters = []): CommandRunResult
            {
                return new CommandRunResult(1, "\e[32m[steps-only]\e[39m running\n\e[31mUpgrade_7_2_7 failed: boom\e[39m", ExecutionMode::Inprocess);
            }
        };
        $resolver = Mockery::mock(CommandRunnerResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($runner);

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.6', '7.2.7', 1);
        $state->patch(['next_step' => CoreUpdateStep::UpgradeSteps->value]);

        $service = app(CoreUpdateService::class);
        $status = (new CoreUpdateOrchestrator($service, $state, new CoreUpdatePreflight($service), $resolver, new CoreUpdateTimeBudget(null, 300)))->advance();

        $message = (string) ($status['run']['error']['message'] ?? '');
        $this->assertStringContainsString('Upgrade_7_2_7 failed: boom', $message);
        $this->assertStringNotContainsString("\e", $message, '색 코드가 그대로 화면에 보인다');
        $this->assertStringNotContainsString("\e", implode("\n", $status['run']['logs']));
    }

    /**
     * 화면이 얼마나 기다릴지와 서버가 어떤 단계를 돌리는 중인지가 상태 응답에 실린다.
     *
     * @scenario request_time_limit=short, response=cut_off, run_state=running
     *
     * @effects the_status_tells_the_screen_how_long_to_wait
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_status_tells_the_screen_how_long_to_wait_and_which_step_is_held(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->claim('token-backup', CoreUpdateStep::Backup->value, 330);

        $status = $this->orchestrator($state, new CoreUpdateTimeBudget(null, 300))->status();

        $this->assertSame(['request_seconds' => 300, 'client_wait_seconds' => 330], $status['time_budget']);
        $this->assertTrue($status['run']['claim']['held']);
        $this->assertSame(CoreUpdateStep::Backup->value, $status['run']['claim']['step']);
        $this->assertSame(330, $status['run']['claim']['stale_after']);
        $this->assertSame(CoreVersionChecker::getCoreVersion(), $status['current_version']);
    }

    /**
     * 완료된 실행은 대상 버전을 현재 버전으로 알린다 (마무리 요청은 옛 설정을 들고 있다).
     *
     * @scenario request_time_limit=short, run_state=finished
     *
     * @effects the_status_reports_the_installed_version
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_completed_run_reports_its_target_as_the_current_version(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '9.9.9', 1);
        $state->patch(['status' => CoreUpdateRunState::STATUS_COMPLETED, 'next_step' => null]);

        $status = $this->orchestrator($state, new CoreUpdateTimeBudget(null, 300))->status();

        $this->assertSame('9.9.9', $status['current_version']);
    }

    /**
     * 사전 점검의 트리 훑기는 정해진 시간에서 멈추고 그 사실을 알린다.
     *
     * @scenario request_time_limit=short, scan=time_truncated
     *
     * @effects a_preflight_scan_stops_within_its_share_of_the_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_preflight_scan_stops_within_its_share_of_the_request(): void
    {
        $tree = $this->root.DIRECTORY_SEPARATOR.'scan';

        for ($i = 0; $i < 60; $i++) {
            File::put($this->ensureParent($tree.DIRECTORY_SEPARATOR.'d'.intdiv($i, 10).DIRECTORY_SEPARATOR.'f'.$i.'.txt'), 'x');
        }

        $tick = 0.0;
        $clock = static function () use (&$tick): float {
            return $tick += 1.0;
        };

        $preflight = $this->preflightOver([$this->relative($tree)], $clock);
        $preflight->limitScanTime(10.0);
        $started = $tick;

        $writable = $preflight->checkWritableTargets(true);

        $this->assertTrue($writable['ok']);
        $this->assertTrue($writable['truncated'], '시간이 다했는데 끝까지 훑으면 짧은 제한의 서버에서 사전 점검이 죽는다');
        $this->assertTrue($preflight->scanWasTruncated());
        $this->assertLessThan(20.0, $tick - $started, '쓰기 훑기는 몫의 6할 근처에서 멈춰야 한다');
    }

    /**
     * 사전 점검은 링크·정션(설치가 만든 `public/storage`, 백업 안에 보존된 같은 정션)을 막지 않는다.
     *
     * @scenario request_time_limit=short, scan=link_in_tree
     *
     * @effects a_link_or_junction_is_not_reported_as_unwritable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_link_or_junction_in_the_tree_is_not_reported_as_unwritable(): void
    {
        $target = $this->root.DIRECTORY_SEPARATOR.'uploads';
        $tree = $this->root.DIRECTORY_SEPARATOR.'site';
        File::ensureDirectoryExists($target);
        File::put($target.DIRECTORY_SEPARATOR.'a.jpg', 'x');
        File::ensureDirectoryExists($tree.DIRECTORY_SEPARATOR.'public');
        File::ensureDirectoryExists($tree.DIRECTORY_SEPARATOR.'backup'.DIRECTORY_SEPARATOR.'public');

        $this->makeDirectoryLink($target, $tree.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage');
        $this->makeDirectoryLink($target, $tree.DIRECTORY_SEPARATOR.'backup'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage');
        $this->makeDirectoryLink($target, $this->root.DIRECTORY_SEPARATOR.'top-link');

        $preflight = $this->preflightOver([$this->relative($tree), $this->relative($this->root.DIRECTORY_SEPARATOR.'top-link')]);

        $writable = $preflight->checkWritableTargets(true);

        $this->assertSame([], $writable['blocked'], '쓸 수 있는 정션을 「쓰기 권한 없음」으로 막으면 권한을 바꿔도 풀리지 않는다');
        $this->assertTrue($writable['ok']);
    }

    /**
     * 적용 대상 산출을 한 항목씩 나눠도 한 번에 한 결과와 같다.
     *
     * @scenario request_time_limit=short, plan_slicing=sliced
     *
     * @effects a_sliced_plan_matches_the_single_pass_plan
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_sliced_apply_list_matches_the_single_pass_result(): void
    {
        [$old, $new, $targets] = $this->planFixture();

        $full = CoreBackupHelper::computeApplyList($old, $new, $targets, [], [], []);

        $progress = [];
        $calls = 0;

        do {
            $chunk = CoreBackupHelper::computeApplyListChunk($old, $new, $targets, [], [], [], $progress, static fn (): bool => true);
            $progress = $chunk;
            $calls++;
        } while (! $chunk['done'] && $calls < 200);

        $this->assertTrue($chunk['done']);
        $this->assertGreaterThan(3, $calls, '멈춤 신호를 받고도 한 번에 끝냈다면 나눠지지 않은 것이다');
        $this->assertSame($full['apply'], $chunk['apply']);
        $this->assertSame($full['added_count'], count($chunk['added']));
        $this->assertSame($full['changed_count'], count($chunk['changed']));
        $this->assertSame(['app/B.php', 'app/C.php', 'config/x.php', 'single.txt'], $chunk['apply']);

        // 신규 파일 목록도 같은 커서로 나뉘며 결과가 같다.
        $manifest = CoreBackupHelper::writeNewFilesManifest($old, $new, $targets, [], [], '1', '2', []);
        $written = json_decode((string) file_get_contents($old.DIRECTORY_SEPARATOR.CoreBackupHelper::NEW_FILES_MANIFEST), true);

        $progress = [];
        $calls = 0;

        do {
            $collected = CoreBackupHelper::collectNewFilesChunk($old, $new, $targets, [], [], [], $progress, static fn (): bool => true);
            $progress = $collected;
            $calls++;
        } while (! $collected['done'] && $calls < 200);

        $files = $collected['new_files'];
        $dirs = $collected['new_dirs'];
        sort($files);
        sort($dirs);

        $this->assertSame($written['new_files'], $files);
        $this->assertSame($written['new_dirs'], $dirs);
        $this->assertSame($manifest['new_files_count'], count($files));
    }

    /**
     * 경로를 모르는 이전 판의 순번 커서를 받아도 그 대상을 처음부터 다시 훑어 빠지는 파일이 없다.
     *
     * @scenario request_time_limit=short, plan_slicing=order_changed
     *
     * @effects a_sliced_plan_matches_the_single_pass_plan
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_cursor_whose_last_entry_no_longer_matches_restarts_that_target(): void
    {
        [$old, $new, $targets] = $this->planFixture();

        $full = CoreBackupHelper::computeApplyList($old, $new, $targets, [], [], []);

        $chunk = CoreBackupHelper::computeApplyListChunk($old, $new, $targets, [], [], [], [
            'cursor' => ['target' => 0, 'offset' => 2, 'last' => 'app/does-not-exist.php'],
            'added' => [],
            'changed' => [],
        ]);

        $this->assertTrue($chunk['done']);
        $this->assertSame($full['apply'], $chunk['apply'], '순번 커서를 경로 없이 믿고 앞쪽을 건너뛰면 변경 파일이 적용 목록에서 빠진다');
    }

    /**
     * 웹 실행의 적용 대상 산출 단계가 요청 몫을 넘기면 다음 요청에서 이어 가고, 끝난 결과는 한 번에 한 것과 같다.
     *
     * @scenario request_time_limit=short, plan_slicing=sliced, run_state=running
     *
     * @effects the_plan_step_continues_in_the_next_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_plan_step_continues_across_requests_and_stores_the_full_apply_list(): void
    {
        [$old, $new, $targets] = $this->planFixture();
        config([
            'app.update.targets' => $targets,
            'app.update.protected_paths' => [],
            'app.update.excludes' => [],
            'app.update.reserved_paths' => [],
        ]);

        $full = CoreBackupHelper::computeApplyList($old, $new, $targets, [], [], []);

        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->mergeContext(['pending_path' => $new, 'backup_path' => $old]);
        $state->patch(['next_step' => CoreUpdateStep::Plan->value]);

        $requests = 0;

        do {
            $tick = 0.0;
            // 시계가 한 번 물을 때마다 크게 흐른다 — 매 요청이 항목 하나만 처리하고 멈춘다.
            $budget = new CoreUpdateTimeBudget(static function () use (&$tick): float {
                return $tick += 100.0;
            }, 30);

            $status = $this->orchestrator($this->state(), $budget)->advance();
            $requests++;
        } while (($status['run']['next_step'] ?? null) === CoreUpdateStep::Plan->value && $requests < 100);

        $this->assertSame(CoreUpdateStep::Apply->value, $status['run']['next_step'], '산출 단계가 끝나지 않았다');
        $this->assertGreaterThan(2, $requests, '요청 몫을 넘겼는데 한 요청에서 끝까지 했다면 시간 제한에 죽는다');
        $this->assertSame($full['apply'], $this->state()->loadApplyList());
        $this->assertNull($this->state()->loadPlanProgress(), '끝난 산출의 진행분이 남으면 다음 실행이 읽는다');
        $this->assertFileExists($old.DIRECTORY_SEPARATOR.CoreBackupHelper::NEW_FILES_MANIFEST);
        $this->assertNotEmpty(array_filter(
            $status['run']['logs'],
            static fn (string $line): bool => str_contains($line, (string) __('settings.core_update.web.log_plan_continues', ['checked' => ''])) || str_contains($line, '이어 갑니다'),
        ));
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
     * 통과만 하는 사전 점검과 지정 예산으로 오케스트레이터를 만든다.
     *
     * @param  CoreUpdateRunState  $state  상태
     * @param  CoreUpdateTimeBudget  $budget  실행 시간 예산
     * @return CoreUpdateOrchestrator 오케스트레이터
     */
    private function orchestrator(CoreUpdateRunState $state, CoreUpdateTimeBudget $budget): CoreUpdateOrchestrator
    {
        $service = app(CoreUpdateService::class);

        $preflight = new class($service) extends CoreUpdatePreflight
        {
            /**
             * 실제 점검 없이 통과 결과를 돌려준다.
             *
             * @param  bool  $requiresArchiveExtraction  압축 해제 수단 점검 여부(대역에서는 무시)
             * @param  bool  $deep  정밀 점검 여부(대역에서는 무시)
             * @return array<string, mixed> 통과 진단 결과
             */
            public function run(bool $requiresArchiveExtraction = true, bool $deep = true): array
            {
                return [
                    'ok' => true,
                    'writable' => ['ok' => true, 'blocked' => [], 'checked' => 1, 'truncated' => false],
                    'disk' => ['ok' => true],
                    'requirements' => ['available_methods' => []],
                    'errors' => [],
                ];
            }
        };

        return new CoreUpdateOrchestrator($service, $state, $preflight, app(CommandRunnerResolver::class), $budget);
    }

    /**
     * 지정 후보만 훑는 사전 점검을 만든다.
     *
     * @param  list<string>  $candidates  base 상대 후보 경로
     * @param  \Closure|null  $clock  시계
     * @return CoreUpdatePreflight 사전 점검
     */
    private function preflightOver(array $candidates, ?\Closure $clock = null): CoreUpdatePreflight
    {
        return new class(app(CoreUpdateService::class), $clock, $candidates) extends CoreUpdatePreflight
        {
            /**
             * 훑을 후보 경로를 고정한 사전 점검 대역을 만듭니다.
             *
             * @param  CoreUpdateService  $service  코어 업데이트 서비스
             * @param  \Closure|null  $clock  시계
             * @param  list<string>  $candidates  후보
             */
            public function __construct(CoreUpdateService $service, ?\Closure $clock, private readonly array $candidates)
            {
                parent::__construct($service, $clock);
            }

            /**
             * 지정 후보만 돌려준다 (개발 저장소의 실제 storage 를 훑지 않는다).
             *
             * @return list<string> 후보
             */
            protected function writableCandidates(): array
            {
                return $this->candidates;
            }
        };
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

        $put = function (string $base, string $relative, string $content): void {
            File::put($this->ensureParent($base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative)), $content);
        };

        foreach (['app/A.php' => 'same-a', 'app/B.php' => 'old-b', 'app/sub/D.php' => 'same-d', 'config/x.php' => 'x', 'config/y.php' => 'same-y'] as $rel => $content) {
            $put($old, $rel, $content);
        }

        foreach (['app/A.php' => 'same-a', 'app/B.php' => 'new-b', 'app/C.php' => 'added', 'app/sub/D.php' => 'same-d', 'app/sub/deep/E.php' => 'same-e', 'config/x.php' => 'longer-x', 'config/y.php' => 'same-y', 'single.txt' => 'single'] as $rel => $content) {
            $put($new, $rel, $content);
        }

        // E 는 구버전에도 있었다 — 신규가 아니다.
        $put($old, 'app/sub/deep/E.php', 'same-e');

        return [$old, $new, ['app', 'config', 'single.txt']];
    }

    /**
     * 점유의 심박을 지정 초만큼 과거로 돌린다.
     *
     * @param  CoreUpdateRunState  $state  상태
     * @param  int  $seconds  초
     * @return void 반환값 없음
     */
    private function ageClaim(CoreUpdateRunState $state, int $seconds): void
    {
        $raw = json_decode((string) file_get_contents($state->path()), true);
        $raw['claim']['heartbeat_at'] = now()->subSeconds($seconds)->toIso8601String();
        file_put_contents($state->path(), json_encode($raw));
    }

    /**
     * 디렉터리 링크를 만든다 (Windows 는 설치가 쓰는 정션, 그 밖에는 심볼릭 링크).
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

    /**
     * 링크·정션을 따라가지 않고 트리를 지운다 (정션을 따라가면 대상 파일까지 지운다).
     *
     * @param  string  $path  지울 경로
     * @return void 반환값 없음
     */
    private function removeTree(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (is_link($path) || (PHP_OS_FAMILY === 'Windows' && is_dir($path) && @readlink($path) !== false && @readlink($path) !== $path)) {
            @rmdir($path) || @unlink($path);

            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && ! is_dir($path) && ! is_file($path) && @readlink($path) !== false) {
            @rmdir($path);

            return;
        }

        if (is_dir($path)) {
            foreach ((array) scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
            }

            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
