<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 워커 프로세스가 치명 오류로 죽었을 때의 상태 승격 회귀 테스트 (12차 검증 #A2).
 *
 * 배경:
 *   세 진입점(run-task · install-worker · install-process 폴링)의
 *   `register_shutdown_function` 이 전부 `releaseWorkerLock` 만 했다. fatal / OOM /
 *   시간초과로 프로세스가 중간에 죽으면 `installation_status` 는 `running` 인 채,
 *   `failed_task` 는 비어 있는 채 남는다. 그런데 [건너뛰고 계속](markInstallationTaskSkipped)
 *   은 `installation_status === 'failed'` + `failed_task` 를 요구하므로, 그 조합에서는
 *   **건너뛰기가 원리상 불가능**해진다 — 화면은 영원히 진행 중으로 보이고 탈출구는
 *   완료 마커를 전부 버리는 초기화뿐이다.
 *
 * 고정하는 계약 (제품 결정 — 실패로 승격해 건너뛰기를 허용한다):
 *  - 치명 오류로 끝난 요청은 `current_task` 를 `failed_task` 로 승격하고
 *    `installation_status='failed'` · `failed_task_target` · `failed_task_skippable` 을
 *    정상 실패 경로와 같은 필드 집합으로 기록한다.
 *  - 승격된 상태에서 [건너뛰고 계속] 이 실제로 성립한다.
 *  - 치명이 아닌 종료(정상 종료 · warning) 와 이미 끝난 설치(완료 · 중단) 는 건드리지 않는다(멱등).
 *  - 세 진입점의 shutdown 훅이 같은 함수를 등록한다 — 한 곳만 배선하면 그 모드에서만 고착이 남는다.
 *
 * 커버 축: task_outcome failed / resume_point retry_after_failure / endpoint_guard general
 *
 * @effects fatal_shutdown_promotes_running_task_to_failed, fatal_shutdown_promotion_keeps_skip_available, fatal_shutdown_promotion_is_idempotent, worker_entrypoints_share_one_shutdown_handler
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerFatalShutdownRecoveryTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    private static function boot(): void
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerFatalShutdownRecoveryTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-fatalshutdown-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app'] as $dir) {
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::boot();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach ([
            self::$base.'/storage/installer-state.json',
            self::$base.'/storage/logs/installation.log',
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedRunning(array $overrides = []): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        $state['installation_mode'] = 'stepwise';
        $state['current_task'] = 'module_install:sirsoft-board';
        $state['completed_tasks'] = ['env_update', 'db_migrate'];
        $state = array_replace($state, $overrides);

        $this->assertTrue(saveInstallationState($state));
    }

    /**
     * @return array{type: int, message: string, file: string, line: int}
     */
    private function fatal(int $type = E_ERROR): array
    {
        return [
            'type' => $type,
            'message' => 'Allowed memory size of 134217728 bytes exhausted',
            'file' => '/app/vendor/laravel/framework/src/Foo.php',
            'line' => 42,
        ];
    }

    public function test_fatal_shutdown_promotes_the_running_task_to_failed(): void
    {
        $this->seedRunning();

        $this->assertTrue(installerPromoteFatalShutdownToFailure($this->fatal()));

        $state = getInstallationState();

        $this->assertSame('failed', $state['installation_status'] ?? null);
        $this->assertSame('module_install:sirsoft-board', $state['failed_task'] ?? null);
        $this->assertSame('sirsoft-board', $state['failed_task_target'] ?? null);
        $this->assertTrue((bool) ($state['failed_task_skippable'] ?? false));
        $this->assertNotNull($state['error_message_key'] ?? null);
    }

    public function test_promoted_state_makes_skip_and_continue_possible(): void
    {
        $this->seedRunning();

        installerPromoteFatalShutdownToFailure($this->fatal());

        $result = markInstallationTaskSkipped(getInstallationState());

        $this->assertTrue(
            $result['success'],
            '치명 오류로 끝난 요청 뒤에도 [건너뛰고 계속] 이 성립해야 한다 — '
            .'승격이 없으면 status 가 running 이라 skip_task_not_failed 로 거부된다.'
        );
        $this->assertContains('module_install:sirsoft-board', $result['skipped']);
    }

    public function test_non_fatal_or_clean_shutdown_leaves_the_state_untouched(): void
    {
        $this->seedRunning();

        $this->assertFalse(installerPromoteFatalShutdownToFailure(null));
        $this->assertFalse(installerPromoteFatalShutdownToFailure([
            'type' => E_WARNING,
            'message' => 'fopen(): failed to open stream',
            'file' => '/app/x.php',
            'line' => 1,
        ]));

        $this->assertSame('running', getInstallationState()['installation_status'] ?? null);
        $this->assertNull(getInstallationState()['failed_task'] ?? null);
    }

    public function test_finished_installations_are_not_touched(): void
    {
        foreach (['completed', 'aborted', 'failed'] as $status) {
            $this->seedRunning(['installation_status' => $status, 'failed_task' => null]);

            $this->assertFalse(
                installerPromoteFatalShutdownToFailure($this->fatal()),
                "installation_status={$status} 는 이미 끝난 설치다 — 승격이 그 결말을 덮으면 안 된다."
            );
            $this->assertSame($status, getInstallationState()['installation_status'] ?? null);
        }
    }

    public function test_promotion_records_a_non_skippable_required_task_as_such(): void
    {
        $this->seedRunning(['current_task' => 'db_migrate']);

        $this->assertTrue(installerPromoteFatalShutdownToFailure($this->fatal(E_COMPILE_ERROR)));

        $state = getInstallationState();
        $this->assertSame('db_migrate', $state['failed_task'] ?? null);
        $this->assertArrayHasKey('failed_task_target', $state);
        $this->assertNull($state['failed_task_target']);
        $this->assertFalse((bool) ($state['failed_task_skippable'] ?? true));
    }

    public function test_all_three_worker_entrypoints_register_the_shared_shutdown_handler(): void
    {
        $root = dirname(__DIR__, 3);

        foreach ([
            'public/install/api/run-task.php',
            'public/install/api/install-worker.php',
            'public/install/api/install-process.php',
        ] as $relative) {
            // 주석을 지운 뒤 대조한다 — 등록을 주석으로 감싸도 원문에는 글자가 남는다.
            $source = $this->stripComments((string) file_get_contents($root.'/'.$relative));

            $this->assertStringContainsString(
                "register_shutdown_function('installerHandleWorkerShutdown'",
                $source,
                "{$relative} 의 shutdown 훅이 lock 해제만 한다 — 그 모드에서만 상태가 running 에 고착한다."
            );
        }
    }
}
