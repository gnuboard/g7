<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 설치 워커 실행 시간 제한·비정상 종료 회귀 테스트.
 *
 * 회귀 1 (실행 시간 제한): 설치 워커(SSE install-worker.php, 폴링 install-process.php)는
 * 모든 설치 작업을 한 요청 안에서 차례로 돌리면서 시간 제한을 진입 시 한 번(600초)만 걸었다.
 * Windows 의 PHP 는 max_execution_time 을 실제 경과 시간으로 재므로, 확장을 모두 고른 설치는
 * 작업 수에 비례해 길어져 중간 작업에서 "Maximum execution time of 600 seconds exceeded" 로 죽었다.
 * → 작업을 시작할 때마다 set_time_limit() 으로 카운터를 다시 건다.
 *
 * 회귀 2 (상태 락 교착): 그 fatal 이 state.json 쓰기 락(installerWithStateLock) 안에서 나면
 * finally 가 실행되지 않아 락이 쥐어진 채 shutdown 함수(releaseWorkerLock)로 넘어가고,
 * 거기서 새 핸들로 같은 락을 요청해 프로세스가 영원히 멈췄다. 이후 같은 사이트의 모든
 * 상태 쓰기(EventSource 재연결 워커·재시도·중단)가 그 락에서 멈춰 화면이 「진행 중」에 고정됐다.
 * → 락 재진입 + shutdown 단계에서 끊긴 락 해제.
 *
 * 회귀 3 (실패 미기록): 워커가 fatal 로 죽어도 상태는 running 으로 남아 화면이 실패를 알리지 못했다.
 * → 워커 shutdown 처리기가 fatal 을 감지하면 상태를 failed 로 바꾸고(재시도로 이어서 진행 가능),
 *   SSE 모드면 error 이벤트를 보낸다.
 *
 * 회귀 4 (오류 없는 스트림 종료): SSE 워커는 ignore_user_abort(false) 라 연결이 끊긴 것을 다음 출력에서
 * 감지하면 PHP 가 스크립트를 조용히 끝낸다(오류 없음, 종료 이벤트 없음, shutdown 처리기는 잠금만 해제).
 * 화면이 이어 받기 연결 여부를 정하도록 상태 조회가 워커 잠금 유무(worker_active)를 알리고,
 * shutdown 처리기는 그 종료를 설치 로그에 남긴다.
 *
 * 모든 시나리오는 실제 PHP 하위 프로세스에서 시간 제한을 1초로 걸고 CPU 를 쓰는 루프로
 * 초과시킨다(Linux 는 CPU 시간, Windows 는 경과 시간으로 재므로 두 OS 모두에서 초과한다).
 *
 * 시나리오 SSoT: tests/scenarios/installer-worker-termination.yaml
 */
class InstallerWorkerTimeLimitTest extends TestCase
{
    private string $projectRoot = '';

    private string $tempBase = '';

    /**
     * 하위 프로세스용 임시 BASE_PATH 를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = str_replace('\\', '/', dirname(__DIR__, 3));
        $this->tempBase = str_replace('\\', '/', sys_get_temp_dir()).'/g7-worker-timelimit-'.bin2hex(random_bytes(4));
        @mkdir($this->tempBase.'/storage/logs', 0755, true);
        @mkdir($this->tempBase.'/storage/installer', 0755, true);
    }

    /**
     * 임시 디렉터리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempBase);

        parent::tearDown();
    }

    /**
     * 상태 락 안에서 실행 시간이 초과돼도 shutdown 의 상태 저장이 멈추지 않아야 한다.
     *
     * 수정 전: releaseWorkerLock() 이 같은 프로세스가 쥔 락을 기다리며 영원히 멈춘다(제한 시간 내 미종료).
     *
     * @scenario situation=timeout_inside_state_lock
     *
     * @effects shutdown_lock_release_completes_after_timeout_inside_state_lock
     *
     * @return void 반환값 없음
     */
    public function test_shutdown_state_write_after_timeout_inside_state_lock_does_not_deadlock(): void
    {
        $result = $this->runScenario(<<<'PHP'
require_once G7_ROOT.'/public/install/includes/config.php';
require_once G7_ROOT.'/public/install/includes/installer-state.php';

$state = getInstallationState();
$state['installation_status'] = 'running';
$state['active_worker_id'] = 'worker-a';
$state['last_heartbeat'] = time();
saveInstallationState($state);

// 진입점이 종전에 등록하던 shutdown 처리 그대로
register_shutdown_function('releaseWorkerLock', 'worker-a');

set_time_limit(1);
installerWithStateLock(static function (): bool {
    $start = microtime(true);
    while (microtime(true) - $start < 6) {
        // CPU 루프 — 락을 쥔 채 실행 시간 초과
    }

    return true;
});
echo 'UNREACHABLE';
PHP);

        $this->assertFalse($result['timed_out'], "shutdown 단계에서 상태 락 교착으로 프로세스가 끝나지 않았다:\n".$result['output']);
        $this->assertStringContainsString('Maximum execution time', $result['output']);

        $state = $this->readState();
        $this->assertArrayNotHasKey('active_worker_id', $state, 'shutdown 의 워커 락 해제가 저장되지 않았다');
    }

    /**
     * 워커가 fatal 로 죽으면 상태가 failed 로 기록되고, SSE 모드면 error 이벤트가 나간다.
     *
     * 수정 전: 상태가 running 으로 남아 화면이 「진행 중」에 멈춘다.
     *
     * @scenario situation=worker_fatal
     *
     * @effects worker_fatal_marks_state_failed_with_task_and_detail, completed_tasks_preserved_for_resume, sse_mode_emits_error_event_with_task_and_target, polling_mode_emits_no_sse_event
     *
     * @param  string  $mode  설치 모드 (sse | polling)
     * @return void 반환값 없음
     */
    #[DataProvider('modeProvider')]
    public function test_worker_fatal_marks_installation_failed(string $mode): void
    {
        $result = $this->runScenario(<<<PHP
require_once G7_ROOT.'/public/install/includes/config.php';
require_once G7_ROOT.'/public/install/includes/functions.php';
require_once G7_ROOT.'/public/install/includes/installer-state.php';
require_once G7_ROOT.'/public/install/includes/progress-emitter.php';
require_once G7_ROOT.'/public/install/includes/task-runner.php';

\$state = getInstallationState();
\$state['installation_status'] = 'running';
\$state['current_task'] = 'plugin_install:sirsoft-tosspayments';
\$state['completed_tasks'] = ['plugin_activate:sirsoft-sso'];
\$state['active_worker_id'] = 'worker-a';
\$state['last_heartbeat'] = time();
saveInstallationState(\$state);

register_shutdown_function('installerHandleWorkerShutdown', 'worker-a', '{$mode}');

set_time_limit(1);
installerWithStateLock(static function (): bool {
    \$start = microtime(true);
    while (microtime(true) - \$start < 6) {
    }

    return true;
});
echo 'UNREACHABLE';
PHP);

        $this->assertFalse($result['timed_out'], "워커 shutdown 처리가 끝나지 않았다:\n".$result['output']);

        $state = $this->readState();
        $this->assertSame('failed', $state['installation_status'] ?? null, "fatal 뒤 상태가 failed 로 기록되지 않았다:\n".$result['output']);
        $this->assertSame('plugin_install:sirsoft-tosspayments', $state['failed_task'] ?? null);
        $this->assertSame('error_worker_terminated', $state['error_message_key'] ?? null);
        $this->assertStringContainsString('Maximum execution time', (string) ($state['error_detail'] ?? ''));
        $this->assertSame(['plugin_activate:sirsoft-sso'], $state['completed_tasks'] ?? null, '완료된 작업은 이어서 하기를 위해 보존돼야 한다');
        $this->assertArrayNotHasKey('active_worker_id', $state);

        if ($mode === 'sse') {
            $this->assertMatchesRegularExpression('/event: error\ndata: \{[^\n]*"message_key":"error_worker_terminated"/', $result['stdout']);
            $this->assertStringContainsString('"task":"plugin_install"', $result['stdout']);
            $this->assertStringContainsString('"target":"sirsoft-tosspayments"', $result['stdout']);
        } else {
            $this->assertStringNotContainsString('event: error', $result['stdout'], '폴링 모드는 응답이 이미 끝났으므로 SSE 이벤트를 보내지 않는다');
        }
    }

    /**
     * 설치 모드 목록.
     *
     * @return array<string, array{0: string}> 케이스 이름 => [설치 모드]
     */
    public static function modeProvider(): array
    {
        return [
            'SSE 모드' => ['sse'],
            '폴링 모드' => ['polling'],
        ];
    }

    /**
     * 정상 종료(설치 완료 등)에서는 상태를 건드리지 않고 워커 락만 푼다.
     *
     * @scenario situation=normal_exit
     *
     * @effects normal_shutdown_keeps_state_and_releases_worker_lock
     *
     * @return void 반환값 없음
     */
    public function test_worker_shutdown_without_fatal_only_releases_lock(): void
    {
        $result = $this->runScenario(<<<'PHP'
require_once G7_ROOT.'/public/install/includes/config.php';
require_once G7_ROOT.'/public/install/includes/functions.php';
require_once G7_ROOT.'/public/install/includes/installer-state.php';
require_once G7_ROOT.'/public/install/includes/progress-emitter.php';
require_once G7_ROOT.'/public/install/includes/task-runner.php';

$state = getInstallationState();
$state['installation_status'] = 'running';
$state['current_task'] = 'module_install:sirsoft-board';
$state['active_worker_id'] = 'worker-a';
$state['last_heartbeat'] = time();
saveInstallationState($state);

register_shutdown_function('installerHandleWorkerShutdown', 'worker-a', 'sse');
echo 'DONE';
PHP);

        $this->assertFalse($result['timed_out']);
        $this->assertStringNotContainsString('event: error', $result['stdout']);

        $state = $this->readState();
        $this->assertSame('running', $state['installation_status'] ?? null);
        $this->assertArrayNotHasKey('active_worker_id', $state);
    }

    /**
     * 작업 루프가 작업마다 시간 제한을 다시 걸어, 전체 합계가 제한을 넘어도 끝까지 진행된다.
     *
     * 작업 함수는 function_exists 가드라 미리 정의한 스텁이 쓰인다 — 각 작업은 0.4초 CPU 를 쓰고,
     * 작업당 제한은 1초, 작업 14개 합계는 약 5.6초.
     * 수정 전: 진입 시 한 번 건 1초 제한에 걸려 세 번째 작업 근처에서 죽는다.
     *
     * @scenario situation=cumulative_tasks_over_limit
     *
     * @effects time_limit_reset_for_each_task
     *
     * @return void 반환값 없음
     */
    public function test_task_loop_resets_time_limit_for_each_task(): void
    {
        $result = $this->runScenario(<<<'PHP'
define('INSTALLER_TASK_TIME_LIMIT', 1);

function g7_test_busy_task(): array
{
    $start = microtime(true);
    while (microtime(true) - $start < 0.4) {
    }
    $GLOBALS['g7_test_ran'] = ($GLOBALS['g7_test_ran'] ?? 0) + 1;

    return ['success' => true];
}

foreach ([
    'checkComposerSSE', 'installComposerDependenciesSSE', 'updateEnvFileSSE', 'generateApplicationKeySSE',
    'dependencyPrecheckSSE', 'cleanupExistingTablesSSE', 'runDatabaseMigrationSSE', 'runDatabaseSeedingSSE',
    'createSettingsJsonSSE', 'clearCacheSSE', 'setInstallationCompleteSSE', 'optimizeConfigCacheSSE',
    'optimizeRouteCacheSSE', 'publishStaticCacheSSE',
] as $fn) {
    eval("function {$fn}(): array { return g7_test_busy_task(); }");
}
function getSelectedExtensions(): array
{
    return [];
}

require_once G7_ROOT.'/public/install/includes/config.php';
require_once G7_ROOT.'/public/install/includes/functions.php';
require_once G7_ROOT.'/public/install/includes/installer-state.php';
require_once G7_ROOT.'/public/install/includes/progress-emitter.php';
require_once G7_ROOT.'/public/install/includes/task-runner.php';

$state = getInstallationState();
$state['installation_status'] = 'running';
saveInstallationState($state);

setProgressEmitter(new NullEmitter);
set_time_limit(1);
runInstallationTasks();
echo 'ALL_DONE:'.$GLOBALS['g7_test_ran'];
PHP);

        $this->assertFalse($result['timed_out']);
        $this->assertStringNotContainsString('Maximum execution time', $result['output'], "작업 합계 시간이 진입 시 제한에 걸렸다:\n".$result['output']);
        $this->assertStringContainsString('ALL_DONE:14', $result['output']);
    }

    /**
     * 실행 시간 제한이 없는 실행(터미널 설치는 진입 때 set_time_limit(0))은 작업마다 다시 걸 때도 제한 없음을 유지한다.
     *
     * 같은 작업 루프(runInstallationTasks)를 웹 워커와 터미널 설치가 함께 쓴다. 작업마다 600초를 새로 걸면
     * 종전에 제한이 없던 터미널 설치가 느린 망의 의존성 설치 같은 긴 작업에서 새로 끊긴다.
     *
     * @scenario situation=unlimited_cli_run
     *
     * @effects unlimited_time_limit_kept_for_cli_run
     *
     * @return void 반환값 없음
     */
    public function test_task_time_limit_reset_keeps_unlimited_run_unlimited(): void
    {
        $result = $this->runScenario(<<<'PHP'
require_once G7_ROOT.'/public/install/includes/config.php';
require_once G7_ROOT.'/public/install/includes/functions.php';
require_once G7_ROOT.'/public/install/includes/installer-state.php';
require_once G7_ROOT.'/public/install/includes/progress-emitter.php';
require_once G7_ROOT.'/public/install/includes/task-runner.php';

set_time_limit(0);
installerResetTaskTimeLimit();
echo 'UNLIMITED:'.ini_get('max_execution_time')."\n";

set_time_limit(5);
installerResetTaskTimeLimit();
echo 'LIMITED:'.ini_get('max_execution_time')."\n";
PHP);

        $this->assertFalse($result['timed_out']);
        $this->assertStringContainsString('UNLIMITED:0', $result['stdout'], "제한 없는 실행에 작업당 제한이 새로 걸렸다:\n".$result['output']);
        $this->assertStringContainsString('LIMITED:'.self::taskTimeLimit(), $result['stdout'], "제한이 있는 실행은 작업당 제한으로 다시 걸려야 한다:\n".$result['output']);
    }

    /**
     * 설치기 설정의 작업당 실행 시간 제한(초).
     *
     * @return int 제한(초)
     */
    private static function taskTimeLimit(): int
    {
        $config = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/config.php');
        preg_match("/define\\('INSTALLER_TASK_TIME_LIMIT', (\\d+)\\)/", $config, $m);

        return (int) ($m[1] ?? 0);
    }

    /**
     * 두 진입점이 워커 shutdown 처리기를 등록하고, 그 시점에 처리기가 정의돼 있어야 한다.
     *
     * @effects both_entrypoints_register_worker_shutdown_handler_after_definition
     *
     * @return void 반환값 없음
     */
    public function test_both_worker_entrypoints_register_worker_shutdown_handler(): void
    {
        foreach (['public/install/api/install-worker.php', 'public/install/api/install-process.php'] as $file) {
            $source = (string) file_get_contents($this->projectRoot.'/'.$file);

            $this->assertMatchesRegularExpression(
                "/register_shutdown_function\\('installerHandleWorkerShutdown', \\\$workerId, '(sse|polling)'\\)/",
                $source,
                "{$file} 가 워커 shutdown 처리기를 등록하지 않는다 — 워커가 죽으면 상태가 running 으로 남는다"
            );
            $this->assertStringNotContainsString(
                "register_shutdown_function('releaseWorkerLock'",
                $source,
                "{$file} 가 여전히 락 해제만 등록한다"
            );

            $registerPos = strpos($source, "register_shutdown_function('installerHandleWorkerShutdown'");
            $requirePos = strpos($source, "task-runner.php';");
            $this->assertNotFalse($requirePos);
            $this->assertLessThan($registerPos, $requirePos, "{$file}: 처리기 정의(task-runner.php)를 등록보다 먼저 불러와야 한다");
        }
    }

    /**
     * 연결이 끊겨 워커가 오류 없이 끝나면(연결 끊김을 감지한 PHP 가 스크립트를 끝냄) 상태는 running 으로 두고
     * 워커 잠금을 풀며, 그 사실을 설치 로그에 남긴다.
     *
     * 화면은 잠금이 빈 running 상태를 보고 새 워커로 다시 연결해 남은 작업을 이어 받는다.
     * 수정 전: 아무 기록도 남지 않아 설치 로그만으로는 워커가 왜 끝났는지 알 수 없었다.
     *
     * @scenario situation=worker_connection_aborted
     *
     * @effects connection_abort_shutdown_keeps_state_running_releases_lock_and_logs
     *
     * @return void 반환값 없음
     */
    public function test_worker_shutdown_after_connection_abort_keeps_running_and_logs(): void
    {
        $result = $this->runScenario(<<<'PHP'
require_once G7_ROOT.'/public/install/includes/config.php';
require_once G7_ROOT.'/public/install/includes/functions.php';
require_once G7_ROOT.'/public/install/includes/installer-state.php';
require_once G7_ROOT.'/public/install/includes/progress-emitter.php';
require_once G7_ROOT.'/public/install/includes/task-runner.php';

$state = getInstallationState();
$state['installation_status'] = 'running';
$state['current_task'] = 'module_activate';
$state['completed_tasks'] = ['module_install:sirsoft-board'];
$state['active_worker_id'] = 'worker-a';
$state['last_heartbeat'] = time();
saveInstallationState($state);
$GLOBALS['g7_installer_running_task'] = ['id' => 'module_activate', 'target' => 'sirsoft-board'];

// CLI 에서는 연결 상태가 늘 NORMAL 이라 끊김 상태를 넘겨 재현한다
register_shutdown_function('installerHandleWorkerShutdown', 'worker-a', 'sse', CONNECTION_ABORTED);
echo 'DONE';
PHP);

        $this->assertFalse($result['timed_out']);
        $this->assertStringNotContainsString('event: error', $result['stdout']);

        $state = $this->readState();
        $this->assertSame('running', $state['installation_status'] ?? null, '연결 끊김은 실패가 아니다 — 화면이 이어 받는다');
        $this->assertSame(['module_install:sirsoft-board'], $state['completed_tasks'] ?? null);
        $this->assertArrayNotHasKey('active_worker_id', $state, '잠금이 풀려야 새 워커가 이어 받는다');

        $log = (string) @file_get_contents($this->tempBase.'/storage/logs/installation.log');
        $this->assertStringContainsString('connection closed', $log, "연결 끊김 종료가 설치 로그에 남지 않았다:\n".$log);
        $this->assertStringContainsString('module_activate:sirsoft-board', $log);
    }

    /**
     * 상태 조회 API 가 워커 잠금 유무(worker_active)를 알려 준다 — 화면이 이어 받기 연결 여부를 정하는 근거.
     *
     * @scenario situation=state_api_worker_presence
     *
     * @effects state_api_reports_worker_active
     *
     * @return void 반환값 없음
     */
    public function test_state_api_reports_whether_a_worker_holds_the_lock(): void
    {
        $body = <<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'get';
require_once G7_ROOT.'/public/install/includes/config.php';
require_once G7_ROOT.'/public/install/includes/installer-state.php';

$state = getInstallationState();
$state['installation_status'] = 'running';
%s
saveInstallationState($state);

require G7_ROOT.'/public/install/api/state-management.php';
PHP;

        $held = $this->runScenario(sprintf($body, "\$state['active_worker_id'] = 'worker-a';\n\$state['last_heartbeat'] = time();"));
        $free = $this->runScenario(sprintf($body, "unset(\$state['active_worker_id'], \$state['last_heartbeat']);"));

        $heldJson = json_decode($held['stdout'], true);
        $freeJson = json_decode($free['stdout'], true);
        $this->assertIsArray($heldJson, "상태 응답이 JSON 이 아니다:\n".$held['output']);
        $this->assertIsArray($freeJson, "상태 응답이 JSON 이 아니다:\n".$free['output']);
        $this->assertSame('running', $heldJson['status'] ?? null);
        $this->assertTrue($heldJson['worker_active'] ?? null, '잠금을 쥔 워커가 있으면 worker_active=true');
        $this->assertFalse($freeJson['worker_active'] ?? null, '잠금이 비면 worker_active=false');
        $this->assertArrayNotHasKey('active_worker_id', $heldJson, '워커 ID 자체는 내보내지 않는다');
    }

    /**
     * 하위 PHP 프로세스로 시나리오를 실행한다 (최대 20초, 넘으면 강제 종료하고 timed_out=true).
     *
     * @param  string  $body  실행할 PHP 본문 (<?php 없이)
     * @return array{timed_out: bool, stdout: string, output: string} 강제 종료 여부, 표준 출력, 표준 출력과 오류 출력을 합친 전체 출력
     */
    private function runScenario(string $body): array
    {
        $script = $this->tempBase.'/scenario.php';
        $prelude = "<?php\n"
            ."error_reporting(E_ALL);\n"
            ."ini_set('display_errors', 'stderr');\n"
            ."define('G7_ROOT', '{$this->projectRoot}');\n"
            ."define('BASE_PATH', '{$this->tempBase}');\n"
            // config.php 는 BASE_PATH/app/Support 의 세 클래스를 require 한다 — 임시 BASE_PATH 에는
            // 없으므로 프로젝트 원본을 먼저 불러 둔다(config.php 의 class_exists 가드가 건너뛴다).
            // PhpExtensionRequirements 는 자기 옆의 목록 파일을 읽으므로 원본 경로에서 불러야 한다.
            ."require_once G7_ROOT.'/app/Support/PrivilegedDatabaseAccounts.php';\n"
            ."require_once G7_ROOT.'/app/Support/OpcacheStatus.php';\n"
            ."require_once G7_ROOT.'/app/Support/PhpExtensionRequirements.php';\n";
        file_put_contents($script, $prelude.$body."\n");

        // 출력은 파일로 받는다 — Windows 에서는 proc_open 파이프의 non-blocking 읽기가 동작하지 않아,
        // 멈춘 하위 프로세스를 파이프로 읽으면 테스트 자체가 함께 멈춘다.
        $stdoutFile = $this->tempBase.'/scenario.out';
        $stderrFile = $this->tempBase.'/scenario.err';
        $descriptors = [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']];
        $process = proc_open([PHP_BINARY, '-d', 'max_execution_time=0', $script], $descriptors, $pipes);
        $this->assertIsResource($process, 'PHP 하위 프로세스를 띄우지 못함');

        $timedOut = false;
        $deadline = microtime(true) + 20;

        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                $this->killProcessTree((int) $status['pid']);
                proc_terminate($process, 9);
                break;
            }
            usleep(50000);
        }

        proc_close($process);

        $stdout = (string) @file_get_contents($stdoutFile);
        $stderr = (string) @file_get_contents($stderrFile);

        return ['timed_out' => $timedOut, 'stdout' => $stdout, 'output' => $stdout.$stderr];
    }

    /**
     * 멈춘 하위 프로세스를 강제 종료한다.
     *
     * @param  int  $pid  프로세스 ID
     * @return void 반환값 없음
     */
    private function killProcessTree(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            @exec('taskkill /F /T /PID '.$pid.' 2>NUL');
        } elseif (function_exists('posix_kill')) {
            @posix_kill($pid, 9);
        }
    }

    /**
     * 임시 BASE_PATH 의 state.json 을 읽는다.
     *
     * @return array<string, mixed> 상태
     */
    private function readState(): array
    {
        $decoded = json_decode((string) @file_get_contents($this->tempBase.'/storage/installer-state.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 디렉터리를 재귀 삭제한다.
     *
     * @param  string  $dir  디렉터리
     * @return void 반환값 없음
     */
    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
