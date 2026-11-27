<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 무CLI 환경에서 실행기가 조용히 죽지 않는다는 계약 (3· 12· 25 · E3 · 보안 12).
 *
 * 고정하는 계약:
 *
 *  - 환경변수 주입은 `$_ENV`/`$_SERVER` 를 항상 채우고 `putenv` 는 **가용할 때만** 부른다.
 *    PHP 8 부터 `disable_functions` 는 함수를 함수 테이블에서 제거하므로 `@putenv()` 의 `@`
 *    로도 `Error: Call to undefined function` 을 막지 못한다. `exec` 를 막는 호스팅은
 *    `putenv` 도 대개 함께 막으므로, 무가드 호출은 in-process 부팅 자체를 죽인다.
 *  - 자식이 시그널로 죽으면(cgroup OOM-kill 은 exit 137) 종료 코드만으로는 사유가 없다.
 *  - 출력 파일을 예약하지 못하면 예약 실패를 명시하고 파이프 경로로 내려간다 — 쓸 수 없는
 *    경로를 그대로 돌려주면 리다이렉트가 실패해 실패 사유가 통째로 사라진다.
 *  - 서브프로세스 argv 는 in-process 러너와 같은 규약이다 (`--no-interaction`).
 *  - 관리자 비밀번호는 시딩 성공·실패와 무관하게 해제된다.
 *
 * 커버 축: env_injection putenv_available·putenv_blocked / exit_code normal·signal / output_file reserved·unreserved / seed_outcome success·failure
 *
 * 효과 요약(마커 아님 — 평문): env_injection_fills_superglobals, putenv_calls_are_guarded, signal_exit_is_explained, output_file_reservation_failure_is_explicit, artisan_command_line_forces_non_interaction, admin_password_purged_on_seed_failure
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerExecutionEnvHardeningTest extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 프로세스마다 한 번 임시 기준 경로와 저장, 설정 디렉터리를 준비하고 오토로더와 설치기 설정, 상태, 런타임, 진행 이벤트, 태스크 러너 소스를 읽어 들인다.
     * 기준 경로 상수가 시스템 임시 경로 밖으로 이미 정의돼 있으면 실제 상태 파일을 지키려고 건너뛰기 사유만 남긴다.
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerExecutionEnvHardeningTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-execenv-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app', 'config/settings'] as $dir) {
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
     * 설치기 런타임을 올리고 남은 상태 파일, 설치 로그, 런타임 파일을 지운 뒤 진행 이벤트를 버퍼로 받고 영어 번역 사전을 싣는다.
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

        foreach ([
            self::$base.'/storage/installer-state.json',
            self::$base.'/storage/logs/installation.log',
        ] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_file(INSTALLER_RUNTIME_PATH)) {
            unlink(INSTALLER_RUNTIME_PATH);
        }

        setProgressEmitter(new \BufferEmitter);

        // lang() 은 전역 $translations 를 읽는다 — 사전이 없으면 키가 그대로 돌아와
        // 「사유가 실렸는가」를 판정할 수 없다.
        $GLOBALS['translations'] = loadTranslations('en');
    }

    /**
     * 테스트가 심은 실행기 대체 함수와 관리자 환경변수 네 개를 전역에서 걷어 낸다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['g7_installer_artisan_executor_override']);

        foreach (['INSTALLER_ADMIN_NAME', 'INSTALLER_ADMIN_EMAIL', 'INSTALLER_ADMIN_PASSWORD', 'INSTALLER_ADMIN_LANGUAGE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
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
     * 환경 3 — 주입은 `$_ENV`/`$_SERVER` 를 항상 채운다.
     *
     * @scenario runner_strategy=inprocess
     *
     * @effects env_injection_fills_superglobals
     *
     * @return void 반환값 없음
     */
    public function test_env_injection_fills_superglobals(): void
    {
        installerPutEnv('G7_TEST_ENV_KEY', 'value-1');

        $this->assertSame('value-1', $_ENV['G7_TEST_ENV_KEY'] ?? null);
        $this->assertSame('value-1', $_SERVER['G7_TEST_ENV_KEY'] ?? null);

        installerPutEnv('G7_TEST_ENV_KEY', null);

        $this->assertArrayNotHasKey('G7_TEST_ENV_KEY', $_ENV);
        $this->assertArrayNotHasKey('G7_TEST_ENV_KEY', $_SERVER);
    }

    /**
     * 환경 2· 3 — `putenv` 직접 호출은 단일 지점(installerPutEnv) 밖에 없다.
     *
     * `disable_functions=putenv` 환경에서 무가드 호출은 `Error` 이고, `@` 로도 막히지 않는다.
     *
     * @scenario runner_strategy=inprocess
     *
     * @effects putenv_calls_are_guarded
     *
     * @return void 반환값 없음
     */
    public function test_putenv_calls_are_guarded(): void
    {
        foreach ([
            'public/install/includes/artisan-executor.php',
            'public/install/includes/task-runner.php',
        ] as $relative) {
            $source = $this->strippedSource($relative);

            // installerPutEnv 자신의 본문만 putenv 를 부를 수 있다.
            $body = $source;
            $at = strpos($body, 'function installerPutEnv');
            if ($at !== false) {
                $body = substr($body, 0, $at);
            }

            $this->assertDoesNotMatchRegularExpression(
                '/(?<![A-Za-z_])putenv\s*\(/',
                $body,
                $relative.' 에 가드 없는 putenv 호출이 남아 있다 — disable_functions 환경에서 Error 로 죽는다',
            );
        }

        $executor = $this->strippedSource('public/install/includes/artisan-executor.php');
        $this->assertStringContainsString(
            "ExecutionEnvironment::hasFunction('putenv')",
            $executor,
            'putenv 가용성 판정은 execution-mode SSoT(ExecutionEnvironment)가 맡는다',
        );
    }

    /**
     * 환경 25 — 시그널 종료는 사유가 붙는다 (cgroup OOM-kill 은 exit 137).
     *
     * @scenario runner_strategy=subprocess
     *
     * @effects signal_exit_is_explained
     *
     * @return void 반환값 없음
     */
    public function test_signal_exit_is_explained(): void
    {
        $this->assertNull(installerDescribeSignalExit(0));
        $this->assertNull(installerDescribeSignalExit(1));
        $this->assertNull(installerDescribeSignalExit(128));

        $message = installerDescribeSignalExit(137);
        $this->assertIsString($message);
        $this->assertStringContainsString('9', $message, '137 = 128 + SIGKILL(9) — 어떤 시그널인지 드러나야 조치가 가능하다');

        $this->assertStringContainsString(
            'installerDescribeSignalExit(',
            $this->strippedSource('public/install/includes/artisan-executor.php'),
            '실행 결과 조립부가 이 판정을 실제로 쓰지 않으면 사유는 어디에도 도달하지 않는다',
        );
    }

    /**
     * 환경 12 — 출력 파일을 예약하지 못하면 그 사실을 명시한다.
     *
     * @scenario runner_strategy=subprocess
     *
     * @effects output_file_reservation_failure_is_explicit
     *
     * @return void 반환값 없음
     */
    public function test_output_file_reservation_failure_is_explicit(): void
    {
        $notADirectory = self::$base.'/storage/logs/not-a-directory';
        file_put_contents($notADirectory, 'x');

        $this->assertNull(
            reserveCommandOutputFile([$notADirectory]),
            '쓸 수 없는 경로를 그대로 돌려주면 리다이렉트가 실패해 실행 실패 사유가 통째로 사라진다',
        );

        $reserved = reserveCommandOutputFile();
        $this->assertIsString($reserved);
        $this->assertStringContainsString('installer-cmd-output-', $reserved);

        $executor = $this->strippedSource('public/install/includes/artisan-executor.php');
        $this->assertStringContainsString(
            '$cmdLogFile === null',
            $executor,
            '예약 실패 분기가 없으면 output 이 빈 배열로 남아 사유가 사라진다',
        );
    }

    /**
     * E3 — 서브프로세스 argv 는 in-process 러너와 같은 비대화형 규약을 쓴다.
     *
     * @scenario runner_strategy=subprocess
     *
     * @effects artisan_command_line_forces_non_interaction
     *
     * @return void 반환값 없음
     */
    public function test_artisan_command_line_forces_non_interaction(): void
    {
        // 순수 변환은 종전 계약 그대로다 (InstallerArtisanExecutorTest 가 고정).
        $this->assertSame('migrate --force', installerBuildArtisanCommandLine('migrate', ['--force' => true]));

        // 비대화형 강제는 러너가 붙인다 — 코어 SubprocessCommandRunner::run() 과 같은 자리.
        $source = $this->strippedSource('public/install/includes/artisan-executor.php');

        $start = strpos($source, 'function installerRunArtisanSubprocess');
        $this->assertNotFalse($start);
        $body = substr($source, $start, 3000);

        $this->assertStringContainsString(
            '--no-interaction',
            $body,
            '웹 요청 안에는 응답할 TTY 가 없다 — 확인 프롬프트가 뜨면 그대로 멈춘다',
        );
        $this->assertStringContainsString(
            "array_key_exists('--no-interaction', \$parameters)",
            $body,
            '호출부가 이미 넘긴 경우 중복 추가하지 않는다',
        );
    }

    /**
     * 보안 12 — 시딩이 **실패해도** 관리자 비밀번호는 ENV 에서 사라진다.
     *
     * in-process 는 putenv 가 FPM 워커 수명 동안 유지되므로, 실패 경로에서 남으면 이후 다른
     * 요청의 자식 프로세스가 평문을 상속한다.
     *
     * @scenario task=db_seed, task_outcome=failed_db
     *
     * @effects admin_password_purged_on_seed_failure
     *
     * @return void 반환값 없음
     */
    public function test_admin_password_purged_on_seed_failure(): void
    {
        $this->seedStateForSeeding();

        $GLOBALS['g7_installer_artisan_executor_override'] = static fn (string $command): array => [
            'exit_code' => $command === 'db:seed' ? 1 : 0,
            'output' => ['boom'],
        ];

        $result = runDatabaseSeedingSSE();

        $this->assertFalse($result['success'] ?? true, '시딩 실패가 성공으로 보고되면 이 검사는 무의미하다');

        foreach (['INSTALLER_ADMIN_PASSWORD', 'INSTALLER_ADMIN_NAME', 'INSTALLER_ADMIN_EMAIL', 'INSTALLER_ADMIN_LANGUAGE'] as $key) {
            $this->assertArrayNotHasKey($key, $_ENV, "{$key} 가 실패 경로에 남으면 이후 요청의 자식 프로세스가 상속한다");
            $this->assertArrayNotHasKey($key, $_SERVER, "{$key} 가 실패 경로에 남으면 이후 요청의 자식 프로세스가 상속한다");
        }
    }

    /**
     * 환경 3 — 시딩 직전 주입은 `$_ENV` 로도 도달한다 (putenv 만으로는 부족하다).
     *
     * @scenario installation_mode=stepwise, task=db_seed, task_outcome=done
     *
     * @effects env_injection_fills_superglobals, stepwise_seed_injects_admin_env_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_admin_credentials_reach_superglobals_before_seeding(): void
    {
        $this->seedStateForSeeding();

        $seen = [];
        $GLOBALS['g7_installer_artisan_executor_override'] = static function (string $command) use (&$seen): array {
            if ($command === 'db:seed') {
                $seen = [
                    'email' => $_ENV['INSTALLER_ADMIN_EMAIL'] ?? null,
                    'password' => $_ENV['INSTALLER_ADMIN_PASSWORD'] ?? null,
                    'server_email' => $_SERVER['INSTALLER_ADMIN_EMAIL'] ?? null,
                ];
            }

            return ['exit_code' => 0, 'output' => []];
        };

        runDatabaseSeedingSSE();

        $this->assertSame('admin@example.com', $seen['email'] ?? null);
        $this->assertSame('admin-secret', $seen['password'] ?? null);
        $this->assertSame('admin@example.com', $seen['server_email'] ?? null);
    }

    /**
     * 시딩 태스크가 요구하는 최소 상태를 세운다 (DB 설정은 비운다 — 롤백이 DB 에 닿지 않도록).
     *
     * @return void 반환값 없음
     */
    private function seedStateForSeeding(): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        $state['installation_mode'] = 'stepwise';
        $state['execution_mode_resolved'] = 'subprocess';
        $state['g7_locale'] = 'en';
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
}
