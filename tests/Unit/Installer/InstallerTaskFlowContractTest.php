<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 태스크 흐름의 되돌리기·보고 계약 (13차 #D4·#D12 · E5 · F1/F2 · F3 · F7).
 *
 * 고정하는 계약:
 *
 *  - 완료 직후 중단이 감지되면 **그 태스크의** 롤백으로 디스패치한다. 실패 경로는 이미 그렇게
 *    고쳐졌는데 중단 경로만 `rollbackDbMigrate()` 하드코딩으로 남아 있어, db_seed 가 끝난 뒤
 *    중단하면 시드가 아니라 스키마 전체가 되돌려지고 두 마커가 함께 지워졌다.
 *  - 완료 플래그 파일을 쓰지 못하면 실패다. 쓰기 반환값을 보지 않으면 「설치 완료」가 보고된
 *    뒤에도 `g7_installed` 가 없어 이후 부팅이 다시 인스톨러로 간다.
 *  - `remaining` 은 한 기준으로만 계산한다 — 분기마다 다른 기준을 쓰면 같은 이름이 서로 다른
 *    수를 뜻한다.
 *  - stepwise 버퍼는 heartbeat 을 접는다. 진행 값은 단조 증가라 마지막 1건이면 충분한데,
 *    전부 모아 두면 응답 하나에 수백 개가 실려 클라이언트가 같은 프레임을 되풀이 재생한다.
 *  - SSE 워커가 lock 을 잃고 물러날 때는 그 사실을 한 프레임 보낸다 — 아무 말 없이 끊으면
 *    브라우저는 재연결 → busy → 「중단」 화면을 그리는데 승자 요청은 멀쩡히 돌고 있다.
 *
 * 커버 축: abort_point after_seed·after_migrate / flag_write ok·failed / emitter heartbeat_folded·log_kept / remaining done·failed·aborted
 *
 * 효과 요약(마커 아님 — 평문): 완료 직후 중단은 그 태스크의 롤백으로, 완료 플래그 쓰기 실패는 실패로, 남은 수는 한 기준으로,
 * 버퍼의 heartbeat 는 하나로 접고, SSE 워커는 물러나기 전에 알린다. 표식은 각 테스트 메서드가 단다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerTaskFlowContractTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /** @var array<int, string> */
    private array $calls = [];

    /**
     * 프로세스당 한 번만 임시 BASE_PATH 를 정하고 저장소와 설정 디렉토리를 만든 뒤 오토로더와 인스톨러 설정, 상태, 런타임, 진행 방출기, 태스크 러너를 불러온다.
     *
     * BASE_PATH 가 이미 시스템 temp 밖으로 정의돼 있으면 실제 상태 파일을 지키려고 건너뛸 사유만 남긴다.
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerTaskFlowContractTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-taskflow-'.bin2hex(random_bytes(4));
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
        require_once $root.'/vendor/autoload.php';
        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/task-runner.php';
    }

    /**
     * 인스톨러를 부팅하고 건너뛸 사유가 있으면 건너뛴 뒤, 호출 기록을 비우고 상태 파일과 사본, 설치 로그, 완료 플래그, 런타임 파일을 지운다.
     *
     * 진행 방출기는 버퍼 방출기로 바꾸고 번역 표는 영어로 싣는다.
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

        foreach (glob(self::$base.'/storage/installer-state.json*') ?: [] as $path) {
            @unlink($path);
        }
        foreach ([self::$base.'/storage/logs/installation.log', self::$base.'/storage/app/g7_installed'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_file(INSTALLER_RUNTIME_PATH)) {
            unlink(INSTALLER_RUNTIME_PATH);
        }

        setProgressEmitter(new \BufferEmitter);
        $GLOBALS['translations'] = loadTranslations('en');
    }

    /**
     * Artisan 실행기 대체 훅을 걷어 내고, 검사가 파일로 막아 둔 앱 저장소 자리를 디렉토리로 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['g7_installer_artisan_executor_override']);

        // storage/app 을 파일로 바꿔 두는 검사가 있으므로 디렉토리로 되돌린다.
        $appDir = self::$base.'/storage/app';
        if (is_file($appDir)) {
            unlink($appDir);
        }
        if (! is_dir($appDir)) {
            @mkdir($appDir, 0755, true);
        }

        parent::tearDown();
    }

    /**
     * 주석을 지운 소스 — 배선이 「살아 있는지」는 주석이 아니라 코드가 증명한다.
     *
     * @param  string  $relative  저장소 상대 경로
     * @return string 주석이 제거된 소스
     */
    private function strippedSource(string $relative): string
    {
        return $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/'.$relative));
    }

    /**
     * PHP 주석을 토크나이저로 지운다 (줄 수는 보존).
     *
     * @param  string  $source  원본 소스
     * @return string 주석이 제거된 소스
     */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * 시딩 태스크가 요구하는 최소 상태 (DB 설정은 비워 롤백이 실 DB 에 닿지 않게 한다).
     *
     * @param  array<int, string>  $completed  완료 마커
     * @return void 반환값 없음
     */
    private function seedState(array $completed): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        $state['installation_mode'] = 'stepwise';
        $state['execution_mode_resolved'] = 'subprocess';
        $state['g7_locale'] = 'en';
        $state['completed_tasks'] = $completed;
        $state['config'] = [
            'admin_name' => 'Tester',
            'admin_email' => 'admin@example.com',
            'admin_language' => 'en',
            'db_prefix' => 'g7_',
        ];
        saveInstallationState($state);

        writeInstallerRuntime([
            'admin' => ['password' => 'admin-secret'],
            'installer' => ['stepwise_token' => 'tok'],
        ]);
    }

    /**
     * 시딩 도중 중단이 표시되면 설치 로그에 시드 롤백 문구만 남고 마이그레이션 롤백은 돌지 않으며, 마이그레이션 완료 마커가 유지되는지 확인한다.
     *
     * #D4 — db_seed 완료 직후 중단은 **시드**를 되돌린다 (스키마가 아니라).
     *
     * @scenario installation_mode=stepwise, task=db_seed, task_outcome=aborted
     *
     * @effects abort_after_task_rolls_back_that_task
     *
     * @return void 반환값 없음
     */
    public function test_abort_after_seed_rolls_back_seed_not_migrate(): void
    {
        $this->seedState(['db_migrate']);

        $GLOBALS['g7_installer_artisan_executor_override'] = function (string $command): array {
            $this->calls[] = $command;

            if ($command === 'db:seed') {
                // 시딩이 도는 동안 운영자가 [중단] 을 눌렀다
                $state = getInstallationState();
                $state['installation_status'] = 'aborted';
                saveInstallationState($state);
            }

            return ['exit_code' => 0, 'output' => []];
        };

        $result = runDatabaseSeedingSSE();

        $this->assertTrue($result['aborted'] ?? false, '중단 감지 자체가 없으면 이 검사는 무의미하다');

        // 어느 롤백으로 갔는지는 로그가 증명한다 — 두 경로는 진입 즉시 서로 다른 줄을 남긴다.
        $log = (string) file_get_contents(self::$base.'/storage/logs/installation.log');
        $this->assertStringNotContainsString(
            lang('rollback_migrate_start'),
            $log,
            'db_seed 완료 후 중단인데 스키마 전체를 되돌리면 「실패로 끝났을 때」와 DB 상태가 갈린다',
        );
        $this->assertStringContainsString(
            lang('rollback_seed_already_done'),
            $log,
            '중단 경로도 실패 경로와 같이 그 태스크의 롤백으로 디스패치해야 한다',
        );
        $this->assertNotContains('migrate:rollback', $this->calls);

        $this->assertContains(
            'db_migrate',
            getInstallationState()['completed_tasks'] ?? [],
            '시드만 되돌렸으면 마이그레이션 마커는 유효하다 — 지우면 재시도가 스키마를 다시 만든다',
        );
    }

    /**
     * 태스크 러너 소스(주석 제거)에 중단 경로의 마이그레이션 롤백 하드코딩 대입이 남아 있지 않은지 확인한다.
     *
     * #D4 — 중단 경로도 태스크별 디스패치를 쓴다 (하드코딩 잔존 금지).
     *
     * @scenario task=db_seed, task_outcome=aborted
     *
     * @effects abort_after_task_rolls_back_that_task
     *
     * @return void 반환값 없음
     */
    public function test_abort_path_dispatches_by_task(): void
    {
        $source = $this->strippedSource('public/install/includes/task-runner.php');

        $this->assertStringNotContainsString(
            '$rollbackResult = rollbackDbMigrate();',
            $source,
            '중단 경로의 하드코딩이 남아 있으면 db_seed 중단이 스키마를 되돌린다',
        );
    }

    /**
     * 앱 저장소 자리를 파일로 막아 완료 플래그를 쓸 수 없게 만든 뒤, 완료 처리가 성공이 아니라 실패를 돌려주는지 확인한다.
     *
     * #D12 — 완료 플래그를 쓰지 못하면 실패로 보고한다.
     *
     * @scenario task=complete_flag
     *
     * @effects complete_flag_write_failure_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_complete_flag_write_failure_is_reported(): void
    {
        $this->seedState([]);

        // storage/app 자리를 파일로 막는다 → mkdir 실패 → file_put_contents 실패
        $appDir = self::$base.'/storage/app';
        foreach (glob($appDir.'/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($appDir);
        file_put_contents($appDir, 'blocked');

        $result = setInstallationCompleteSSE();

        $this->assertFalse(
            $result['success'] ?? true,
            '락 파일 쓰기 실패를 성공으로 보고하면 「설치 완료」 뒤에도 g7_installed 가 없어 다시 인스톨러로 간다',
        );
    }

    /**
     * 남은 태스크 수 계산이 완료, 실패, 중단 갈래마다 한 규칙으로 나오고 음수가 되지 않으며, 태스크 러너에 흩어진 별도 계산식이 없는지 확인한다.
     *
     * F1/F2 — `remaining` 은 한 기준으로만 계산한다.
     *
     * @scenario task_outcome=done, task_outcome=failed_db, task_outcome=aborted
     *
     * @effects remaining_uses_one_rule
     *
     * @return void 반환값 없음
     */
    public function test_remaining_uses_one_rule(): void
    {
        $this->assertSame(4, installerRemainingTaskCount(5, 'task_done'));
        $this->assertSame(5, installerRemainingTaskCount(5, 'failed'), '실패한 태스크는 아직 지나가지 않았다');
        $this->assertSame(5, installerRemainingTaskCount(5, 'aborted'), '중단은 아무것도 소비하지 않는다');
        $this->assertSame(0, installerRemainingTaskCount(0, 'completed'));
        $this->assertSame(0, installerRemainingTaskCount(0, 'task_done'), '음수는 나오지 않는다');

        $source = $this->strippedSource('public/install/includes/task-runner.php');
        $this->assertSame(
            0,
            substr_count($source, 'count($pending) - 1'),
            'remaining 계산이 분기마다 흩어져 있으면 같은 이름이 서로 다른 수를 뜻한다',
        );
    }

    /**
     * 버퍼 방출기가 여러 heartbeat 를 마지막 값 하나로 접고 log 이벤트는 그대로 남기는지 확인한다.
     *
     * F7 — stepwise 버퍼는 heartbeat 을 마지막 1건으로 접는다.
     *
     * @scenario installation_mode=stepwise
     *
     * @effects buffer_emitter_folds_heartbeats
     *
     * @return void 반환값 없음
     */
    public function test_buffer_emitter_folds_heartbeats(): void
    {
        $emitter = new \BufferEmitter;
        $emitter->emit('heartbeat', ['n' => 1]);
        $emitter->emit('log', ['message' => 'first']);
        $emitter->emit('heartbeat', ['n' => 2]);
        $emitter->emit('heartbeat', ['n' => 3]);

        $events = $emitter->drain();
        $names = array_column($events, 'event');

        $this->assertSame(
            1,
            count(array_filter($names, static fn (string $n): bool => $n === 'heartbeat')),
            '수백 개가 한꺼번에 실리면 클라이언트가 같은 프레임을 되풀이 재생한다',
        );
        $this->assertContains('log', $names, '접는 것은 heartbeat 뿐이다');

        $heartbeat = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'heartbeat'));
        $this->assertSame(3, $heartbeat[0]['data']['n'] ?? null, '남기는 것은 마지막 값이다 (진행 값은 단조 증가)');
    }

    /**
     * SSE 방출기 클래스 본문에서 worker_superseded 알림이 프로세스 종료 호출보다 앞에 있는지 확인한다.
     *
     * E5 — SSE 워커는 물러나기 전에 그 사실을 한 프레임 보낸다.
     *
     * @scenario installation_mode=sse
     *
     * @effects sse_announces_lock_loss
     *
     * @return void 반환값 없음
     */
    public function test_sse_announces_lock_loss_before_retreat(): void
    {
        $source = $this->strippedSource('public/install/includes/progress-emitter.php');

        $start = strpos($source, 'class SseEmitter');
        $this->assertNotFalse($start);
        $end = strpos($source, 'class NullEmitter');
        $this->assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        $announce = strpos($body, 'worker_superseded');
        $this->assertNotFalse(
            $announce,
            'SSE 가 아무 말 없이 끊으면 브라우저는 재연결 → busy → 「중단」 화면을 그리는데 승자 요청은 멀쩡히 돌고 있다',
        );

        $exit = strpos($body, 'exit(0)');
        $this->assertNotFalse($exit);
        $this->assertLessThan($exit, $announce, '이벤트는 물러나기 **전에** 나가야 한다');
    }

    /**
     * 설치 마무리 함수 본문에 응답에 반영되지 않는 현재 단계 세션 쓰기가 따옴표나 값 형태와 무관하게 남아 있지 않은지 정규식으로 확인한다.
     *
     * F3 — 응답에 반영되지 않는 세션 쓰기는 남기지 않는다.
     *
     * @scenario installation_mode=sse, installation_mode=polling, installation_mode=stepwise
     *
     * @effects finalize_leaves_no_dead_session_write
     *
     * @return void 반환값 없음
     */
    public function test_finalize_has_no_dead_session_write(): void
    {
        $source = $this->strippedSource('public/install/includes/task-runner.php');

        // 19차 G#10 — 본문은 중괄호로 자르고, 대조는 리터럴이 아니라 정규식으로 한다.
        //
        // 종전에는 고정 창 3,000자 + **정확 문자열** 부재였다. 따옴표만 바꾸거나
        // (`$_SESSION["installer_current_step"]`) 값을 변수로 빼면(`= $step;`) 그대로 초록이다.
        $body = $this->functionBody($source, 'finalizeInstallationRun');
        $this->assertNotSame('', $body, 'finalizeInstallationRun() 을 찾지 못했다');

        $this->assertDoesNotMatchRegularExpression(
            '/\$_SESSION\s*\[\s*[\'"]installer_current_step[\'"]\s*\]\s*=/',
            $body,
            '세 모드 모두 이 쓰기는 응답에 반영되지 않는다 — 실제 보장은 install-process.php 가 한다',
        );
    }
}
