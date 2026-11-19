<?php

namespace Tests\Feature\Upgrade;

use App\Contracts\Extension\ModuleInterface;
use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Enums\UpgradeStepRunStatus;
use App\Exceptions\UpgradeStepAlreadyRunningException;
use App\Execution\CommandRunnerResolver;
use App\Execution\CommandRunResult;
use App\Execution\Contracts\CommandRunner;
use App\Execution\ExecutionMode;
use App\Execution\InProcessCommandRunner;
use App\Extension\ModuleManager;
use App\Extension\Upgrade\UpgradeStepRunLedger;
use App\Models\UpgradeStepRun;
use App\Services\CoreUpdateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 업그레이드 스텝 실행 대장 — 같은 DB 에서 두 번 돌려도 스텝은 한 번만 돈다 (#888).
 *
 * 코어 판 번호는 서버마다 `.env` 에만 있어, DB 를 함께 쓰는 두 번째 서버가 같은 코어 스텝을 다시 돌렸다.
 * 스텝마다 대장 `upgrade_step_runs` 를 보고, 끝난 스텝은 건너뛰고(`--force` 는 다시 돌린다), 선점은 유니크 삽입으로 한다.
 *
 * @scenario-manifest tests/scenarios/upgrade-step-run-ledger.yaml
 */
class UpgradeStepRunLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 만든 스텝 파일 */
    private array $createdPaths = [];

    /**
     * 대장을 켭니다(단위 테스트에서는 기본으로 꺼져 있다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        UpgradeStepRunLedger::enableForTests();
    }

    /**
     * 만든 스텝 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            File::delete($path);
        }
        UpgradeStepRunLedger::enableForTests(false);

        parent::tearDown();
    }

    /**
     * 코어 스텝 — 두 번째 실행은 0회, `--force` 는 다시 1회.
     *
     * @scenario case=core__rerun_and_force
     *
     * @effects done_step_is_skipped_on_shared_db, force_reruns_done_step
     *
     * @return void 반환값 없음
     */
    public function test_core_step_runs_once_per_db_and_force_reruns(): void
    {
        $this->writeCountingStep('0.0.7', 'ledger_probe');
        $service = app(CoreUpdateService::class);

        $GLOBALS['g7_ledger_probe_runs'] = 0;
        $service->runUpgradeSteps('0.0.6', '0.0.7', freshProcess: true);
        $service->runUpgradeSteps('0.0.6', '0.0.7', freshProcess: true);
        $this->assertSame(1, $GLOBALS['g7_ledger_probe_runs'], '같은 DB 의 두 번째 실행은 끝난 스텝을 건너뛴다');

        // 접미사 붙은 스텝 파일의 판은 0.0.7-test.ledger.probe 다 — 같은 판 --force 재실행은 그 판으로 부른다
        $service->runUpgradeSteps('0.0.7-test.ledger.probe', '0.0.7-test.ledger.probe', null, true, freshProcess: true);
        $this->assertSame(2, $GLOBALS['g7_ledger_probe_runs'], '--force 는 대장을 무시하고 다시 돌린다');

        $row = UpgradeStepRun::query()->where('scope', 'core')->where('version', '0.0.7-test.ledger.probe')->firstOrFail();
        $this->assertSame(UpgradeStepRunStatus::Done, $row->status);
        $this->assertSame('', $row->identifier);
    }

    /**
     * 웹 코어 업데이트도 대장을 따른다 — 같은 DB 의 다른 서버가 끝낸 스텝을 관리자 화면 업데이트가 다시 돌리지 않는다.
     *
     * 웹 단계 실행은 `core:execute-upgrade-steps` 를 부른다. 그때 `--force` 를 붙이면 대장이 「이미 실행됨」을 무시해
     * 두 번째 서버의 웹 업데이트가 끝난 스텝을 다시 돌렸다.
     *
     * @scenario case=core__web_rerun
     *
     * @effects done_step_is_skipped_on_shared_db, web_update_follows_ledger
     *
     * @return void 반환값 없음
     */
    public function test_web_core_update_skips_step_already_run_on_shared_db(): void
    {
        $this->writeCountingStep('0.0.7', 'ledger_web');
        $service = app(CoreUpdateService::class);

        $GLOBALS['g7_ledger_probe_runs'] = 0;
        $service->runUpgradeSteps('0.0.6', '0.0.7', freshProcess: true);
        $this->assertSame(1, $GLOBALS['g7_ledger_probe_runs']);

        // 실행 잠금·상태 파일이 개발 저장소에 남지 않게 storage 를 격리한다
        $root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'ledger_web_'.uniqid());
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');
        $originalStoragePath = storage_path();
        app()->useStoragePath($root.DIRECTORY_SEPARATOR.'storage');

        $seen = new \ArrayObject;
        $runner = new class($seen) implements CommandRunner
        {
            /**
             * 받은 인자를 바깥에 남긴다.
             *
             * @param  \ArrayObject<string, mixed>  $seen  마지막 인자를 담을 그릇
             */
            public function __construct(private readonly \ArrayObject $seen) {}

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
             * 인자를 기록하고 같은 프로세스로 실제 명령을 돌린다.
             *
             * @param  string  $command  커맨드
             * @param  array<string, mixed>  $parameters  인자
             * @return CommandRunResult 결과
             */
            public function run(string $command, array $parameters = []): CommandRunResult
            {
                $this->seen['parameters'] = $parameters;

                return (new InProcessCommandRunner)->run($command, $parameters);
            }
        };
        $resolver = Mockery::mock(CommandRunnerResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($runner);

        try {
            $state = new CoreUpdateRunState($root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME);
            $state->start(new CoreUpdateOptions, '0.0.6', '0.0.7', 1);
            $state->patch(['next_step' => CoreUpdateStep::UpgradeSteps->value]);

            $status = (new CoreUpdateOrchestrator($service, $state, new CoreUpdatePreflight($service), $resolver, new CoreUpdateTimeBudget(null, 300)))->advance();
        } finally {
            $service->releaseRunLock();
            app()->useStoragePath($originalStoragePath);
            putenv('G7_UPDATE_IN_PROGRESS');
            unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);
            File::deleteDirectory($root);
        }

        $this->assertNull($status['run']['error'] ?? null, json_encode($status['run']['error'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertIsArray($seen['parameters'] ?? null, '웹 단계가 업그레이드 스텝 명령을 부르지 않았다');
        $this->assertArrayNotHasKey('--force', $seen['parameters'], '웹 업데이트가 --force 로 대장을 무시한다');
        $this->assertSame(1, $GLOBALS['g7_ledger_probe_runs'], '다른 서버가 끝낸 스텝을 웹 업데이트가 다시 돌렸다');
    }

    /**
     * 같은 DB 의 다른 실행이 끝낸 스텝은 「건너뜀」으로 따로 센다 — 단독·자식 실행의 종료 신호에 실려 부모가 실패로 읽지 않는다.
     *
     * @scenario case=core__skipped_reported
     *
     * @effects skipped_steps_are_counted_separately
     *
     * @return void 반환값 없음
     */
    public function test_skipped_steps_are_reported_separately_from_executed(): void
    {
        $this->writeCountingStep('0.0.7', 'ledger_skip_signal');
        $service = app(CoreUpdateService::class);

        $GLOBALS['g7_ledger_probe_runs'] = 0;
        $service->runUpgradeSteps('0.0.6', '0.0.7', freshProcess: true);

        $executed = [];
        $skipped = [];
        $service->runUpgradeSteps(
            '0.0.6',
            '0.0.7',
            function (string $version) use (&$executed): void {
                $executed[] = $version;
            },
            freshProcess: true,
            onSkipped: function (string $version) use (&$skipped): void {
                $skipped[] = $version;
            },
        );
        $this->assertSame([], $executed, '이미 끝난 스텝은 실행 건수에 들지 않는다');
        $this->assertSame(['0.0.7-test.ledger.skip.signal'], $skipped, '건너뛴 스텝은 따로 통지된다');

        $exit = Artisan::call('core:execute-upgrade-steps', ['--from' => '0.0.6', '--to' => '0.0.7', '--steps-only' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/\[STEPS_EXECUTED\] (\{.*\})/', $output);
        preg_match('/\[STEPS_EXECUTED\] (\{.*\})/', $output, $m);
        $signal = json_decode($m[1], true);
        $this->assertSame(0, $signal['count']);
        $this->assertSame(1, $signal['discovered']);
        $this->assertSame(1, $signal['skipped'] ?? null, '종료 신호에 건너뛴 스텝 수가 실린다');
        $this->assertSame(1, $GLOBALS['g7_ledger_probe_runs']);
    }

    /**
     * 다른 실행이 「실행 중」으로 선점한 스텝은 돌리지 않고 실패한다.
     *
     * @scenario case=core__contended
     *
     * @effects running_step_claim_conflict_fails
     *
     * @return void 반환값 없음
     */
    public function test_step_claimed_by_another_run_fails(): void
    {
        $this->writeCountingStep('0.0.8', 'ledger_busy');
        UpgradeStepRun::query()->create(['scope' => 'core', 'identifier' => '', 'version' => '0.0.8-test.ledger.busy', 'step' => 'App\\Upgrades\\Upgrade_0_0_8_test_ledger_busy', 'status' => 'running', 'started_at' => now()]);

        $GLOBALS['g7_ledger_probe_runs'] = 0;
        try {
            app(CoreUpdateService::class)->runUpgradeSteps('0.0.7', '0.0.8', freshProcess: true);
            $this->fail('선점 경합이면 예외');
        } catch (UpgradeStepAlreadyRunningException $e) {
            $this->assertSame('0.0.8-test.ledger.busy', $e->version);
        }
        $this->assertSame(0, $GLOBALS['g7_ledger_probe_runs']);
    }

    /**
     * 실패한 스텝은 failed 로 남고, 다음 실행이 다시 선점해 돌린다.
     *
     * @scenario case=ledger__failed_retry
     *
     * @effects failed_step_can_be_reclaimed
     *
     * @return void 반환값 없음
     */
    public function test_failed_step_is_recorded_and_retried(): void
    {
        $ledger = app(UpgradeStepRunLedger::class);

        try {
            $ledger->run('module', 'acme-ledger', '1.0.1', 'callable@1.0.1', false, function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(UpgradeStepRunStatus::Failed, UpgradeStepRun::query()->where('identifier', 'acme-ledger')->firstOrFail()->status);

        $ran = false;
        $this->assertTrue($ledger->run('module', 'acme-ledger', '1.0.1', 'callable@1.0.1', false, function () use (&$ran): void {
            $ran = true;
        }));
        $this->assertTrue($ran);
        $this->assertSame(UpgradeStepRunStatus::Done, UpgradeStepRun::query()->where('identifier', 'acme-ledger')->firstOrFail()->status);
    }

    /**
     * 비정상 종료로 남은 「실행 중」 행 — 최근 행은 막고, 오래된 행은 다음 실행이 다시 선점한다.
     *
     * @scenario case=ledger__stale_running_reclaim
     *
     * @effects running_step_claim_conflict_fails, stale_running_step_is_reclaimed
     *
     * @return void 반환값 없음
     */
    public function test_stale_running_step_is_reclaimed(): void
    {
        $ledger = app(UpgradeStepRunLedger::class);
        $row = UpgradeStepRun::query()->create(['scope' => 'module', 'identifier' => 'acme-ledger-stale', 'version' => '1.0.2', 'step' => 'callable@1.0.2', 'status' => 'running', 'started_at' => now()->subHour()]);

        $ran = false;
        try {
            $ledger->run('module', 'acme-ledger-stale', '1.0.2', 'callable@1.0.2', false, function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('최근에 시작한 「실행 중」 행은 선점 경합으로 실패해야 한다');
        } catch (UpgradeStepAlreadyRunningException) {
        }
        $this->assertFalse($ran);

        $row->forceFill(['started_at' => now()->subHours(UpgradeStepRunLedger::STALE_RUNNING_HOURS + 1)])->save();

        $this->assertTrue($ledger->run('module', 'acme-ledger-stale', '1.0.2', 'callable@1.0.2', false, function () use (&$ran): void {
            $ran = true;
        }));
        $this->assertTrue($ran, '오래된 「실행 중」 행은 비정상 종료로 보고 다시 선점한다');
        $this->assertSame(UpgradeStepRunStatus::Done, $row->fresh()->status);
    }

    /**
     * `--force` 재실행은 경과 시간과 무관하게 「실행 중」으로 남은 행을 다시 선점한다.
     *
     * @scenario case=ledger__force_running_reclaim
     *
     * @effects force_reclaims_running_step
     *
     * @return void 반환값 없음
     */
    public function test_force_reclaims_running_step(): void
    {
        $row = UpgradeStepRun::query()->create(['scope' => 'module', 'identifier' => 'acme-ledger-force', 'version' => '1.0.3', 'step' => 'callable@1.0.3', 'status' => 'running', 'started_at' => now()]);

        $ran = false;
        $this->assertTrue(app(UpgradeStepRunLedger::class)->run('module', 'acme-ledger-force', '1.0.3', 'callable@1.0.3', true, function () use (&$ran): void {
            $ran = true;
        }));
        $this->assertTrue($ran, '--force 는 「실행 중」 행도 다시 선점해 돌린다');
        $this->assertSame(UpgradeStepRunStatus::Done, $row->fresh()->status);
    }

    /**
     * 확장(모듈) 스텝도 같다 — 두 번째 업데이트 실행은 0회, `--force` 같은 판 재실행은 1회.
     *
     * @scenario case=module__rerun_and_force
     *
     * @effects done_step_is_skipped_on_shared_db, force_reruns_done_step
     *
     * @return void 반환값 없음
     */
    public function test_module_steps_use_same_ledger(): void
    {
        $runs = 0;
        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getIdentifier')->andReturn('acme-ledger_module');
        $module->shouldReceive('getRequiredCoreVersion')->andReturn(null);
        $module->shouldReceive('getVersion')->andReturn('1.1.0');
        $module->shouldReceive('upgrades')->andReturn(['1.1.0' => function () use (&$runs): void {
            $runs++;
        }]);

        $manager = app(ModuleManager::class);
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');

        $method->invoke($manager, $module, '1.0.0', '1.1.0', false, null);
        $method->invoke($manager, $module, '1.0.0', '1.1.0', false, null);
        $this->assertSame(1, $runs);

        $method->invoke($manager, $module, '1.1.0', '1.1.0', true, null);
        $this->assertSame(2, $runs);
    }

    /**
     * 실행 횟수를 세는 코어 스텝 파일을 만듭니다.
     *
     * @param  string  $version  스텝 판
     * @param  string  $suffix  클래스 이름 접미
     * @return void 반환값 없음
     */
    private function writeCountingStep(string $version, string $suffix): void
    {
        $versionSnake = str_replace('.', '_', $version);
        $className = "Upgrade_{$versionSnake}_test_{$suffix}";
        $path = base_path("upgrades/{$className}.php");
        $code = <<<PHP
<?php

namespace App\\Upgrades;

use App\\Contracts\\Extension\\UpgradeStepInterface;
use App\\Extension\\UpgradeContext;

class {$className} implements UpgradeStepInterface
{
    public function run(UpgradeContext \$context): void
    {
        \$context->logger->info('ledger probe');
        \$GLOBALS['g7_ledger_probe_runs'] = (\$GLOBALS['g7_ledger_probe_runs'] ?? 0) + 1;
    }
}
PHP;
        File::put($path, $code);
        $this->createdPaths[] = $path;
    }
}
