<?php

namespace Tests\Unit\Installer;

use App\Support\ExecutionEnvironment;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 인스톨러 Artisan 실행기(includes/artisan-executor.php) 단위 테스트.
 *
 *  - 서브프로세스 인자 조립이 종전 호출부가 손으로 만들던 문자열과 같은 형태다 (회귀 보장 1)
 *  - 실행기 seam 이 전략보다 우선한다 (흐름 테스트의 근거)
 *  - 전략은 설치 상태의 확정 모드를 따른다 — inprocess 는 vendor 부재 시 명확한 실패로 끝난다
 *  - 서브프로세스 전략은 실제로 exec 경로를 타고 종료 코드·출력을 돌려준다
 *
 * BASE_PATH 는 temp 로 고정한다 (R7). 실 저장소 루트를 define 하는 다른 인스톨러 테스트와 한 프로세스에
 * 섞이면 skip 가드가 이 클래스를 통째로 건너뛰므로 메서드마다 새 프로세스에서 돌린다 — 부팅은 setUp 에서.
 *
 * 커버 축: runner_strategy subprocess·inprocess·inprocess_no_vendor·override
 *
 * @effects installer_artisan_command_line_matches_legacy_shape, installer_runner_dispatches_by_resolved_mode, installer_inprocess_fails_clearly_without_vendor
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerArtisanExecutorTest extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

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
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 격리 실행: php vendor/bin/phpunit --filter=InstallerArtisanExecutorTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-executor-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/run-task.php';
        }

        $root = dirname(__DIR__, 3);
        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/task-runner.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootInstallerRuntime();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        $stateFile = self::$base.'/storage/installer-state.json';
        if (is_file($stateFile)) {
            unlink($stateFile);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['g7_installer_artisan_executor_override']);
        parent::tearDown();
    }

    private function seedMode(string $resolved): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['execution_mode_resolved'] = $resolved;
        $state['config'] = ['php_binary' => 'php'];
        $this->assertTrue(saveInstallationState($state));
    }

    public function test_command_line_matches_legacy_hand_assembled_shape(): void
    {
        $this->assertSame('migrate --force', installerBuildArtisanCommandLine('migrate', ['--force' => true]));
        $this->assertSame('optimize:clear', installerBuildArtisanCommandLine('optimize:clear', []));
        $this->assertSame(
            'module:install '.escapeshellarg('sirsoft-board').' --vendor-mode='.escapeshellarg('bundled'),
            installerBuildArtisanCommandLine('module:install', ['sirsoft-board', '--vendor-mode' => 'bundled']),
        );
        $this->assertSame(
            'language-pack:install '.escapeshellarg('g7-core-ja').' --source='.escapeshellarg('bundled'),
            installerBuildArtisanCommandLine('language-pack:install', ['g7-core-ja', '--source' => 'bundled']),
        );
        $this->assertSame('list', installerBuildArtisanCommandLine('list', ['--skip' => false]));
    }

    public function test_override_seam_takes_precedence_over_strategy(): void
    {
        $this->seedMode(ExecutionEnvironment::MODE_INPROCESS);
        $GLOBALS['g7_installer_artisan_executor_override'] = fn (string $c, array $p) => ['exit_code' => 7, 'output' => ['x']];

        $run = installerRunArtisan('migrate', ['--force' => true]);

        $this->assertSame(7, $run['exit_code']);
        $this->assertSame(['x'], $run['output']);
        $this->assertSame('override', $run['mode']);
    }

    public function test_inprocess_without_vendor_fails_clearly_instead_of_fatal(): void
    {
        $this->seedMode(ExecutionEnvironment::MODE_INPROCESS);
        $this->assertFileDoesNotExist(self::$base.'/vendor/autoload.php');

        $run = installerRunArtisan('list');

        $this->assertSame(1, $run['exit_code']);
        $this->assertSame(ExecutionEnvironment::MODE_INPROCESS, $run['mode']);
        $this->assertStringContainsString('error_inprocess_vendor_missing', implode("\n", $run['output']));
    }

    /**
     * in-process 부팅은 늘어난 요청 수명을 워커 lock 에도 알린다 (배선 축).
     *
     * 부팅이 `set_time_limit(INSTALLER_INPROCESS_TIME_LIMIT)` 로 이 요청의 수명을
     * 올리는데 lock 이 선언한 stale 창은 그대로면, 아직 살아 있는 이 요청을 다른 요청이
     * 죽은 것으로 보고 탈취해 같은 태스크를 겹쳐 돌린다.
     *
     * 기존 계약 테스트(InstallerWorkerLockRecoveryTest)는 `extendWorkerStaleWindow` 를
     * **직접 불러** 왕복만 확인한다 — 부팅 안의 그 세 줄을 지워도 초록이다. 여기서는
     * 부팅 경로를 실제로 태워 배선이 살아 있는지 본다.
     */
    public function test_inprocess_boot_declares_its_time_budget_on_the_worker_lock(): void
    {
        $this->seedMode(ExecutionEnvironment::MODE_INPROCESS);

        // vendor 스텁 — 부재 가드에서 조기 반환하면 배선 지점에 도달하지 못한다.
        // 부팅은 그 뒤 Kernel 해석에서 실패하지만, 시간 예산 선언은 그보다 앞이다.
        @mkdir(self::$base.'/vendor', 0755, true);
        @mkdir(self::$base.'/bootstrap', 0755, true);
        file_put_contents(self::$base.'/vendor/autoload.php', "<?php\n");
        file_put_contents(self::$base.'/bootstrap/app.php', "<?php\nreturn null;\n");

        $lock = acquireWorkerLock(15);
        $this->assertTrue($lock['acquired']);
        installerCurrentWorkerId($lock['worker_id']);
        $this->assertSame(15, getInstallationState()['worker_stale_after'] ?? null);

        installerRunArtisan('list');

        $this->assertSame(
            INSTALLER_INPROCESS_TIME_LIMIT,
            getInstallationState()['worker_stale_after'] ?? null,
            '부팅이 늘린 요청 수명이 lock 의 stale 창에 선언되어야 한다'
        );
    }

    /**
     * 서브프로세스 스폰도 선언한 stale 창만큼 자기 요청 수명을 먼저 올린다 (9차 #M9).
     *
     * 선언(`worker_stale_after`)과 요청 수명은 같이 움직여야 한다 — stepwise 요청은 180초로
     * 들어오는데 선언만 600 으로 올리면, 그 요청이 비정상 종료했을 때 이미 죽은 점유가 최대
     * 10분 남아 다음 요청이 그동안 busy 를 받는다. in-process 부팅과 같은 순서(수명 → 선언)다.
     */
    public function test_subprocess_raises_its_own_request_lifetime_before_declaring_it(): void
    {
        if (! ExecutionEnvironment::hasFunction('exec')) {
            $this->markTestSkipped('exec 차단 환경 — 서브프로세스 전략은 측정 대상이 아니다 (P0 프로필)');
        }

        $this->seedMode(ExecutionEnvironment::MODE_SUBPROCESS);

        $lock = acquireWorkerLock(15);
        $this->assertTrue($lock['acquired']);
        installerCurrentWorkerId($lock['worker_id']);

        // stepwise 요청의 수명을 흉내낸다 — 이 값보다 큰 창을 선언하면서 수명을 올리지 않으면
        // 선언과 실제 수명이 갈린다.
        @set_time_limit(180);

        installerRunArtisan('list');

        $this->assertSame(
            INSTALLER_SUBPROCESS_TIME_LIMIT,
            getInstallationState()['worker_stale_after'] ?? null,
            '스폰 구간의 시간 예산이 lock 에 선언되어야 한다'
        );
        $this->assertSame(
            (string) INSTALLER_SUBPROCESS_TIME_LIMIT,
            (string) ini_get('max_execution_time'),
            '선언한 창만큼 이 요청의 수명도 올라가야 한다 — 선언만 올리면 죽은 점유가 그만큼 남는다'
        );
    }

    public function test_subprocess_strategy_spawns_and_returns_exit_code_and_output(): void
    {
        if (! ExecutionEnvironment::hasFunction('exec')) {
            $this->markTestSkipped('exec 차단 환경 — 서브프로세스 전략은 측정 대상이 아니다 (P0 프로필)');
        }

        $this->seedMode(ExecutionEnvironment::MODE_SUBPROCESS);

        // temp BASE_PATH 에는 artisan 이 없으므로 스폰은 실패로 끝나야 한다 — 그 실패가 exec 경로를 탔다는 증거다
        $run = installerRunArtisan('list');

        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $run['mode']);
        $this->assertNotSame(0, $run['exit_code']);

        $output = trim(implode("\n", $run['output']));
        $this->assertNotSame('', $output);

        // "실패했다" 만으로는 부족하다 — exec 이 불가한 환경의 조기 반환도 같은 형태(비-0 +
        // 비어 있지 않은 출력)를 돌려준다. 그 문구가 아님을 함께 단언해야 이 테스트가
        // "정말 스폰 경로를 탔는가" 를 증명한다.
        $this->assertStringNotContainsString(
            'error_subprocess_unavailable',
            $output,
            'exec 불가 조기 반환이 아니라 실제 스폰 결과여야 한다'
        );
        $this->assertStringNotContainsString(
            lang('error_subprocess_unavailable'),
            $output,
            'exec 불가 조기 반환이 아니라 실제 스폰 결과여야 한다'
        );
    }

    public function test_ini_bytes_parses_php_size_notation(): void
    {
        $this->assertSame(128 * 1024 * 1024, installerIniBytes('128M'));
        $this->assertSame(1024 * 1024 * 1024, installerIniBytes('1G'));
        $this->assertSame(512 * 1024, installerIniBytes('512K'));
        $this->assertSame(4096, installerIniBytes('4096'));
    }
}
