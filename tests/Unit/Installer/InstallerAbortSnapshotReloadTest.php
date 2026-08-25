<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 중단 확정이 진행 기록을 되감지 않는지에 대한 회귀 테스트 (12차 검증 #A1).
 *
 * 배경:
 *   중단 경로 둘(운영자 [중단] · SSE 연결 끊김)이 상태를 **한 번 읽고**, 그 스냅샷을
 *   수 초 블로킹하는 롤백에 넘긴 뒤, **같은 스냅샷**에 중단 표식만 얹어 저장했다.
 *   그 사이 워커가 쓴 완료 마커 · 건너뜀 · best-effort 기록은 통째로 사라진다.
 *   실패 경로(handleInstallationTaskFailure)는 같은 상황에 「롤백이 state 를 갱신했을 수
 *   있다 — 재로드 후 기록」 주석과 함께 이미 재로드를 하고 있었다 — 중단 경로만 빠져 있었다.
 *
 * 고정하는 계약:
 *  - 중단 확정은 롤백 **직후 다시 읽은** 상태 위에 얹는다.
 *  - 중단 두 경로가 같은 확정 함수를 쓴다 — 규칙이 갈리면 한쪽에서만 기록이 사라진다.
 *  - 무기한 잔존하는 중단 상태에는 두 경로 모두 비밀이 남지 않는다 (이슈 #465).
 *
 * 커버 축: task_outcome aborted / resume_point abort_then_resume
 *
 * @effects abort_reloads_state_after_rollback, abort_paths_share_one_finalizer, aborted_state_is_redacted_on_both_paths
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerAbortSnapshotReloadTest extends TestCase
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerAbortSnapshotReloadTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-abortreload-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/state-management.php';
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

    private function seedRunning(): array
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        $state['current_task'] = 'module_install:sirsoft-board';
        $state['completed_tasks'] = ['env_update'];
        $state['config'] = [
            'db_write_password' => 'super-secret',
            'admin_password' => 'another-secret',
        ];

        $this->assertTrue(saveInstallationState($state));

        return $state;
    }

    public function test_abort_finalizer_keeps_progress_written_during_the_rollback(): void
    {
        $entrySnapshot = $this->seedRunning();

        // 롤백이 도는 동안 워커가 쓴 기록 — 상태 파일에는 있지만 진입 스냅샷에는 없다
        $duringRollback = getInstallationState();
        $duringRollback['completed_tasks'] = ['env_update', 'db_migrate', 'db_seed'];
        $duringRollback['skipped_tasks'] = ['language_pack_install:g7-core-ja'];
        $duringRollback['best_effort_failed_tasks'] = ['config_cache'];
        $this->assertTrue(saveInstallationState($duringRollback));

        // 진입 스냅샷이 아니라 파일의 현재 내용 위에 중단을 얹어야 한다 — 두 호출부가 쓰는 조합
        $this->assertTrue(installerFinalizeAbortedState(installerApplyAbortedState(getInstallationState())));

        $final = getInstallationState();

        $this->assertSame('aborted', $final['installation_status'] ?? null);
        $this->assertSame(
            ['env_update', 'db_migrate', 'db_seed'],
            $final['completed_tasks'] ?? [],
            '롤백 도중 워커가 남긴 완료 마커가 사라졌다 — 진입 시 스냅샷을 그대로 저장했다는 뜻이다.'
        );
        $this->assertSame(['language_pack_install:g7-core-ja'], $final['skipped_tasks'] ?? []);
        $this->assertSame(['config_cache'], $final['best_effort_failed_tasks'] ?? []);

        // 진입 스냅샷은 실제로 뒤처져 있었다 (테스트 자신의 전제 확인)
        $this->assertSame(['env_update'], $entrySnapshot['completed_tasks']);
    }

    public function test_abort_finalizer_records_reason_and_rollback_failure(): void
    {
        $this->seedRunning();

        $this->assertTrue(installerFinalizeAbortedState(installerApplyAbortedState(getInstallationState(), 'abort_reason_connection_lost'), [
            'task' => 'db_seed',
            'message' => 'connection refused',
            'message_key' => 'failed_rollback_manual_cleanup',
            'detail_key' => 'failed_rollback_manual_cleanup_detail',
        ]));

        $final = getInstallationState();

        $this->assertSame('abort_reason_connection_lost', $final['abort_reason'] ?? null);
        $this->assertSame('failed_rollback_manual_cleanup', $final['rollback_failure']['message_key'] ?? null);
        $this->assertNull($final['active_worker_id'] ?? null);
    }

    public function test_aborted_state_carries_no_plaintext_secret_on_either_path(): void
    {
        $this->seedRunning();

        $this->assertTrue(installerFinalizeAbortedState(
            installerApplyAbortedState(getInstallationState(), 'abort_reason_connection_lost')
        ));

        $raw = (string) file_get_contents(self::$base.'/storage/installer-state.json');

        $this->assertStringNotContainsString(
            'super-secret',
            $raw,
            '중단 상태는 무기한 잔존할 수 있다 — 연결 끊김 경로도 운영자 [중단] 경로와 같이 비밀을 지워야 한다.'
        );
        $this->assertStringNotContainsString('another-secret', $raw);
    }

    /**
     * 두 중단 경로가 롤백 뒤 **다시 읽은** 상태 위에 중단을 얹고, 같은 확정 함수로 저장하는지.
     *
     * 진입 시 스냅샷($state)을 그대로 넘기는 형태가 남아 있으면 그 경로에서만 기록이 사라진다 —
     * 실행 재현이 어려운 창(롤백 수 초)이라 형태를 소스에서 고정한다.
     */
    public function test_both_abort_paths_apply_the_transition_to_a_freshly_read_state(): void
    {
        $root = dirname(__DIR__, 3);

        foreach ([
            'public/install/api/state-management.php',
            'public/install/includes/task-runner.php',
        ] as $relative) {
            // 주석을 지운 뒤 대조한다 — 원문에는 주석 처리된 호출도 글자로 남아,
            // 「그 배선이 살아 있다」를 증명하지 못한다.
            $source = $this->stripComments((string) file_get_contents($root.'/'.$relative));

            $this->assertStringContainsString(
                'installerApplyAbortedState(getInstallationState()',
                $source,
                "{$relative} 의 중단 경로가 롤백 직전에 읽은 스냅샷 위에 중단을 얹고 있다 — "
                .'그 사이 워커가 쓴 완료 마커·건너뜀이 통째로 사라진다.'
            );

            $this->assertStringNotContainsString(
                'installerApplyAbortedState($state',
                $source,
                "{$relative} 에 진입 시 스냅샷을 그대로 넘기는 형태가 남아 있다."
            );

            $this->assertStringContainsString(
                'installerFinalizeAbortedState(',
                $source,
                "{$relative} 이 중단 저장 SSoT 를 거치지 않는다 — 비밀 제거가 한쪽에만 적용된다."
            );
        }
    }
}
