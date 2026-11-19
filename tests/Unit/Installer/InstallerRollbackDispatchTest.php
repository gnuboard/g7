<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 롤백 디스패치·실패 기록의 태스크 정합 회귀 테스트.
 *
 * 고정하는 계약:
 *
 *  - 살아 있는 태스크 id 는 전부 rollbackTask() 가 알아본다 — 「알 수 없는 작업」으로 떨어지면
 *    롤백할 것이 없는 단계에 「롤백 실패」·「수동 정리 필요」가 표시된다
 *  - 수동 복구 안내(getManualCommands)도 같은 목록을 모집단으로 갖는다
 *  - DB 태스크 실패의 롤백은 실패한 그 태스크의 롤백이다 — db_seed 실패를 migrate:rollback 으로
 *    되돌리면 「중단으로 끝났을 때」와 「실패로 끝났을 때」의 DB 상태가 갈린다
 *  - 중단(aborted)으로 확정된 설치는 그 뒤 태스크가 실패해도 실패로 덮이지 않는다
 *  - best_effort 태스크의 예외는 이미 기록된 완료를 실패로 덮지 않는다
 *  - 건너뛰기는 직전 실패의 자리표시자 치환값을 남기지 않는다
 *
 * BASE_PATH 는 temp 로 고정한다 (R7). 인스톨러 테스트 여럿이 실 저장소 루트를 BASE_PATH 로
 * define 하므로 메서드마다 새 프로세스에서 돌려 상수 충돌을 피한다.
 *
 * 커버 축: task_outcome failed_db·aborted·best_effort_failed / rollback_dispatch known·unknown·db_seed·db_migrate
 *
 * 효과 요약(마커 아님 — 평문): rollback_dispatch_covers_every_live_task, manual_commands_cover_every_live_task, db_seed_failure_rolls_back_seed_not_migrate, abort_survives_later_task_failure, best_effort_exception_keeps_completion, skip_clears_error_message_params
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerRollbackDispatchTest extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /** @var array<int, array{command: string, parameters: array<string|int, mixed>}> */
    private array $calls = [];

    /**
     * 임시 BASE_PATH 와 저장소, 설정 폴더를 한 번만 만들고 기본 설정 파일을 복사한 뒤 태스크 실행기와 롤백 함수까지 적재한다.
     *
     * 이미 정의된 BASE_PATH 가 시스템 임시 폴더 밖이면 실제 상태 파일을 지키려고 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    private static function bootInstallerRuntime(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 상태 파일 파괴 방지를 위해 skip. '
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerRollbackDispatchTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-rollback-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app', 'storage/temp', 'config/settings'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/run-task.php';
        }

        $root = dirname(__DIR__, 3);

        if (! is_file(self::$base.'/config/settings/defaults.json')) {
            @copy($root.'/config/settings/defaults.json', self::$base.'/config/settings/defaults.json');
        }

        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/task-runner.php';
        require_once $root.'/public/install/api/rollback-functions.php';
    }

    /**
     * 설치기 런타임을 준비하고 상태, 진행 기록, 설치 완료 표식, 런타임 파일을 지운 뒤 늘 성공하는 Artisan 실행기를 끼운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::bootInstallerRuntime();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        $this->calls = [];

        foreach ([
            self::$base.'/storage/installer-state.json',
            self::$base.'/storage/logs/installation.log',
            self::$base.'/storage/app/g7_installed',
            INSTALLER_RUNTIME_PATH,
        ] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        setProgressEmitter(new \BufferEmitter);

        $this->installExecutor(fn (string $command, array $parameters): array => ['exit_code' => 0, 'output' => ["ran {$command}"]]);
    }

    /**
     * 테스트가 끼운 Artisan 실행기 대체 함수를 걷어낸다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['g7_installer_artisan_executor_override']);

        parent::tearDown();
    }

    /**
     * Artisan 실행기 대체 함수를 끼워 호출을 기록하고 결과는 주어진 판정 함수에 맡긴다.
     *
     * @param  callable  $decider  fn (string $command, array $parameters): array{exit_code:int, output:array}  명령과 인자를 받아 종료 코드와 출력을 돌려주는 판정 함수
     * @return void 반환값 없음
     */
    private function installExecutor(callable $decider): void
    {
        $GLOBALS['g7_installer_artisan_executor_override'] = function (string $command, array $parameters) use ($decider): array {
            $this->calls[] = ['command' => $command, 'parameters' => $parameters];

            return $decider($command, $parameters);
        };
    }

    /**
     * 단계별 모드, subprocess 실행, 관리자 템플릿 하나를 고른 실행 중 상태와 런타임 비밀값 파일을 깐다.
     *
     * @param  array<int, string>  $completed  이미 끝난 것으로 둘 태스크 키 목록
     * @param  array<string, mixed>  $overrides  기본 상태 위에 덮어쓸 키와 값
     * @return void 반환값 없음
     */
    private function seedState(array $completed, array $overrides = []): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        $state['installation_mode'] = 'stepwise';
        $state['execution_mode_resolved'] = 'subprocess';
        $state['g7_locale'] = 'en';
        $state['config'] = [
            'db_write_host' => 'localhost',
            'db_write_port' => '3306',
            'db_write_database' => 'g7_test',
            'db_write_username' => 'g7',
            'db_prefix' => 'g7_',
            'admin_name' => 'Tester',
            'admin_email' => 'admin@example.com',
            'admin_language' => 'en',
            'vendor_mode' => 'bundled',
            'execution_mode' => 'auto',
            'php_binary' => 'php',
        ];
        $state['selected_extensions'] = [
            'admin_templates' => ['sirsoft-admin_basic'],
            'user_templates' => [],
            'modules' => [],
            'plugins' => [],
            'language_packs' => [],
        ];
        $state['completed_tasks'] = $completed;

        $state = array_replace($state, $overrides);
        $this->assertTrue(saveInstallationState($state));

        writeInstallerRuntime([
            'db' => ['write' => ['host' => 'localhost', 'port' => '3306', 'database' => 'g7_test', 'username' => 'g7', 'password' => 'pw']],
            'app' => ['key' => generateAppKeyInline()],
            'admin' => ['password' => 'admin-secret'],
            'installer' => ['stepwise_token' => 'tok'],
        ]);
    }

    /**
     * 태스크 목록이 만드는 id 전수 (확장 target 은 떼어낸 종류 단위).
     *
     * @return array<int, string> 확장 종류를 모두 채운 상태에서 태스크 목록이 만드는 고유 태스크 id
     */
    private function liveTaskIds(): array
    {
        // 태스크 목록은 **선택된 확장에서 파생**된다 — 확장 종류를 하나라도 비워 두면 그 종류의
        // 태스크 id 가 아예 생성되지 않아, 「전수」를 자처하는 이 모집단이 조용히 줄어든다.
        // 실측: modules·plugins·user_templates·language_packs 를 빈 배열로 두던 동안 7종이
        // 모집단 밖이었고, 그 사각에서 `language_pack_install` 의 수동 복구 안내 누락이
        // 두 커버리지 단언을 모두 통과했다.
        $this->seedState([], ['selected_extensions' => [
            'admin_templates' => ['sirsoft-admin_basic'],
            'user_templates' => ['sirsoft-basic'],
            'modules' => ['sirsoft-board'],
            'plugins' => ['sirsoft-gdpr'],
            'language_packs' => ['g7-core-ja'],
        ]]);
        $ids = [];
        foreach (buildInstallationTaskList() as $task) {
            $ids[] = (string) $task['id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * #M1 — 살아 있는 태스크는 전부 롤백 디스패치가 알아본다.
     *
     * @scenario task=module, task_outcome=failed_db
     *
     * @effects rollback_dispatch_covers_every_live_task
     *
     * @return void 반환값 없음
     */
    public function test_rollback_dispatch_knows_every_live_task(): void
    {
        $state = ['config' => []];
        $unknown = [];

        // 「모르는 태스크」의 응답 모양을 실제로 한 번 만들어 기준으로 삼는다 — 문구를 직접
        // 비교하면 로케일·치환 여부에 따라 판정이 흔들린다.
        $sentinelId = '__no_such_installer_task__';
        $sentinel = (string) (rollbackTask($sentinelId, $state)['message'] ?? '');

        foreach ($this->liveTaskIds() as $id) {
            $result = rollbackTask($id, $state);
            $message = (string) ($result['message'] ?? '');
            if (($result['success'] ?? false) === false
                && $message === str_replace($sentinelId, $id, $sentinel)) {
                $unknown[] = $id;
            }
        }

        $this->assertSame([], $unknown, '롤백 디스패치가 모르는 태스크가 있으면 그 단계 실패·중단이 「알 수 없는 작업」 + 거짓 「수동 정리 필요」로 표시된다');
    }

    /**
     * #L10 — 수동 복구 안내도 같은 목록을 모집단으로 갖는다 (subprocess 환경).
     *
     * 안내가 비면 `handleInstallationTaskFailure` 가 문단 자체를 건너뛰므로 조용히 사라진다.
     *
     * @scenario task=language_pack, runner_strategy=subprocess
     *
     * @effects manual_commands_cover_every_live_task
     *
     * @return void 반환값 없음
     */
    public function test_manual_commands_cover_every_live_task(): void
    {
        $ids = $this->liveTaskIds();
        $missing = [];

        foreach ($ids as $id) {
            $commands = getManualCommands($id, 'sirsoft-admin_basic');
            if ($commands === []) {
                $missing[] = $id;
            }
        }

        $this->assertSame([], $missing, 'subprocess 환경에서 수동 복구 안내가 비는 태스크가 있으면 실패 화면에 안내가 조용히 사라진다');
    }

    /**
     * #M2 — db_seed 실패는 seed 롤백으로 디스패치된다 (migrate:rollback 아님).
     *
     * @scenario task=db_seed, task_outcome=failed_db
     *
     * @effects db_seed_failure_rolls_back_seed_not_migrate
     *
     * @return void 반환값 없음
     */
    public function test_db_seed_failure_rolls_back_seed_not_migrate(): void
    {
        $this->seedState([
            'composer_check', 'composer_install', 'env_update', 'key_generate',
            'dependency_precheck', 'db_cleanup', 'db_migrate',
        ]);

        $this->installExecutor(function (string $command): array {
            if ($command === 'db:seed') {
                return ['exit_code' => 1, 'output' => ['seed failed']];
            }

            return ['exit_code' => 0, 'output' => ["ran {$command}"]];
        });

        $run = runNextInstallationTask();

        $this->assertSame('db_seed', $run['task'] ?? null);
        $this->assertNotContains(
            'migrate:rollback',
            array_column($this->calls, 'command'),
            'db_seed 실패를 migrate:rollback 으로 되돌리면 중단으로 끝났을 때(TRUNCATE)와 DB 상태가 갈린다'
        );

        $state = getInstallationState();
        $this->assertNotContains('db_seed', $state['completed_tasks']);
        $this->assertContains('db_migrate', $state['completed_tasks'], '시딩만 실패했으면 마이그레이션 결과는 유지된다');
    }

    /**
     * #M10 — 중단으로 확정된 설치는 뒤이은 태스크 실패로 「실패」가 되지 않는다.
     *
     * @scenario task=key_generate, task_outcome=aborted
     *
     * @effects abort_survives_later_task_failure
     *
     * @return void 반환값 없음
     */
    public function test_abort_survives_later_task_failure(): void
    {
        $this->seedState(['composer_check', 'composer_install', 'env_update'], [
            'installation_status' => 'aborted',
            'aborted_at' => date('Y-m-d\TH:i:s\Z'),
            'abort_reason' => 'user',
        ]);

        handleInstallationTaskFailure(
            ['id' => 'key_generate', 'target' => null, 'key' => 'key_generate', 'skippable' => false],
            ['success' => false, 'message_key' => 'error_key_generate_failed']
        );

        $state = getInstallationState();
        $this->assertSame('aborted', $state['installation_status'], '중단을 누른 운영자에게 실패 화면 + [다시 시도] 를 보여서는 안 된다');
    }

    /**
     * #M18 — best_effort 태스크의 예외가 이미 기록된 완료를 실패로 덮지 않는다.
     *
     * @scenario task=config_cache, task_outcome=best_effort_failed
     *
     * @effects best_effort_exception_keeps_completion
     *
     * @return void 반환값 없음
     */
    public function test_best_effort_exception_keeps_completion(): void
    {
        $this->seedState(['composer_check'], [
            'installation_status' => 'completed',
            'installation_completed_at' => date('Y-m-d\TH:i:s\Z'),
            'current_task' => 'config_cache',
            'error_message_params' => ['errors' => 'stale'],
        ]);

        recordInstallationRunException(new \RuntimeException('boom'));

        $state = getInstallationState();
        $this->assertSame('completed', $state['installation_status'], 'best_effort 태스크의 예외는 설치 완료를 뒤집지 않는다');
        $this->assertNull($state['error_message_params'] ?? null, '직전 실패의 치환값이 새 키에 남으면 화면 문구가 어긋난다');
    }

    /**
     * #L4 — 건너뛰기가 직전 실패의 자리표시자 치환값을 남기지 않는다.
     *
     * @scenario task=module, skip_request=skippable
     *
     * @effects skip_clears_error_message_params
     *
     * @return void 반환값 없음
     */
    public function test_skip_clears_error_message_params(): void
    {
        $this->seedState(['composer_check'], [
            'installation_status' => 'failed',
            'failed_task' => 'module_install:sirsoft-board',
            'failed_task_skippable' => true,
            'error_message_key' => 'error_module_install_failed',
            'error_message_params' => ['errors' => 'stale detail'],
        ]);

        $result = markInstallationTaskSkipped(getInstallationState());
        $this->assertTrue($result['success']);

        $state = getInstallationState();
        $this->assertNull($state['error_message_params'] ?? null, '건너뛴 뒤에도 남은 치환값이 다음 화면 문구에 실린다');
    }

    /**
     * #L6 — 예외 응답의 태스크는 현재 태스크다 (요청 진입 시점 스냅샷 아님).
     *
     * @scenario task=db_cleanup, task_outcome=failed_db
     *
     * @effects exception_response_reports_the_current_task
     *
     * @return void 반환값 없음
     */
    public function test_exception_response_reports_the_current_task(): void
    {
        $this->seedState([
            'composer_check', 'composer_install', 'env_update', 'key_generate',
            'dependency_precheck', 'db_cleanup',
        ], ['current_task' => 'db_cleanup']);

        // 태스크 본문 밖에서 예외를 만든다 — 실행기 seam 이 던지면 runSingleInstallationTask
        // 안이므로, 오케스트레이터의 catch 로 올라간다.
        $this->installExecutor(function (): array {
            throw new \RuntimeException('boom');
        });

        $run = runNextInstallationTask();

        $this->assertSame('failed', $run['outcome']);
        $this->assertSame(
            getInstallationState()['failed_task'],
            $run['task'],
            '응답의 task 와 state 의 failed_task 가 서로 다른 태스크를 가리키면 화면과 기록이 갈린다'
        );
    }

    /**
     * #L9 — 강제 종료가 남긴 명령 출력 임시 로그를 청소한다.
     *
     * @scenario resume_point=retry_after_failure
     *
     * @effects command_output_leftovers_are_cleaned
     *
     * @return void 반환값 없음
     */
    public function test_command_output_leftovers_are_cleaned(): void
    {
        $leftover = self::$base.'/storage/logs/installer-cmd-output-'.uniqid('', true).'.log';
        file_put_contents($leftover, 'stale');

        $removed = installerCleanupCommandOutputFiles();

        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertFileDoesNotExist($leftover, '청소 주체가 없으면 재시도마다 새 파일명으로 계속 쌓인다');
    }
}
