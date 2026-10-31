<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 21차 동시성·이중 파서 축 회귀 가드 (#21-1 · #21-4 · #21-8 · #21-9)
 *
 * - **#21-9 (치명)**: Step 3 재저장이 `installation_status` 를 무조건 `'ready'` 로 덮어,
 *   실패한 설치의 [다시 시도]가 신규 설치로 둔갑했다 — 완료 마커·롤백 기준선·실패 로그가
 *   소거되고, 이미 테이블이 있는 DB 에 migrate/seed 가 다시 돈다. 두 브라우저가 필요 없다:
 *   같은 세션의 두 탭·뒤로가기 한 번으로 성립한다. 조치는 두 겹이다 — ① Step 3 저장은
 *   진행 이력 상태(running/failed/aborted)를 되감지 않는다 ② 시작 엔드포인트의 재시도
 *   판정은 상태 문자열만이 아니라 **이번 회전이 이미 한 일**(완료 마커·기준선)도 본다.
 * - **#21-8 (높음)**: 확장 선택 저장(save-extensions)이 설치 상태를 보지 않아, 실행 중에
 *   선택이 교체되면 태스크 목록이 요청마다 재계산된다(선택 확장이 기록 없이 소멸·미검증
 *   확장이 도중 편입). 형제 엔드포인트(run-task·install-worker·install-process)는 전부
 *   상태를 본다 — 이 저장만 규율 밖이었다.
 * - **#21-1 (높음)**: 설치 완료 판정이 화면(strpos 부분문자열)과 API 게이트(parse_ini_file)
 *   **서로 다른 파서**였다. 등호 주변 공백이면 화면만 미완료로 읽어 운영 사이트에 설치
 *   화면이 재개방되고, 주석 처리된 줄이면 화면만 완료로 읽어 재설치가 막힌다.
 * - **#21-4 (높음)**: Step 3 저장의 경로 검증이 검사 버튼(check-core-pending-path)과
 *   비대칭 — `..`/NUL 거부·Windows 절대경로 인식·stat 진단 억제가 전부 빠져 있었다.
 *
 * 효과 요약(마커 아님 — 평문): installer_retry_run_preserves_progress_history
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerRetryLifecycleTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    /**
     * 임시 BASE_PATH 와 상태 파일 경로를 정하고 언어 스텁, 설치기 설정, 상태, 실행 모드 모듈을 적재한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-retry-lifecycle-'.bin2hex(random_bytes(4)));
            @mkdir(BASE_PATH.'/storage', 0755, true);
        }
        if (! defined('STATE_PATH')) {
            define('STATE_PATH', BASE_PATH.'/storage/installer-state.json');
        }

        $projectRoot = dirname(__DIR__, 3);
        require_once __DIR__.'/stubs/lang_stub.php';
        require_once $projectRoot.'/public/install/includes/config.php';
        require_once $projectRoot.'/public/install/includes/installer-state.php';
        require_once $projectRoot.'/public/install/includes/execution-mode.php';
    }

    /**
     * #21-9 ①: 설정 재저장은 진행 이력 상태를 되감지 않는다.
     *
     * @scenario resume_point=retry_after_failure, task_outcome=failed_db
     *
     * @effects installer_retry_run_preserves_progress_history
     *
     * @return void 반환값 없음
     */
    public function test_config_save_preserves_lifecycle_status(): void
    {
        $this->assertSame(['installation_status' => 'ready'], installerConfigSaveStatusPatch(null), '이력 없음 → ready');
        $this->assertSame(['installation_status' => 'ready'], installerConfigSaveStatusPatch('ready'), 'ready → ready (재저장 허용)');
        $this->assertSame([], installerConfigSaveStatusPatch('running'), '실행 중 상태를 되감으면 안 된다');
        $this->assertSame([], installerConfigSaveStatusPatch('failed'), '실패 상태를 되감으면 [다시 시도]가 신규 설치로 둔갑한다 (#21-9)');
        $this->assertSame([], installerConfigSaveStatusPatch('aborted'), '중단 상태를 되감으면 안 된다');
    }

    /**
     * #21-9 ①-배선: Step 3 저장이 그 판정을 실제로 경유한다.
     *
     * @scenario resume_point=retry_after_failure
     *
     * @effects installer_retry_run_preserves_progress_history
     *
     * @return void 반환값 없음
     */
    public function test_step3_save_routes_through_status_patch(): void
    {
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/request-handler.php');

        $this->assertStringContainsString('installerConfigSaveStatusPatch(', $source, 'Step 3 저장이 상태 보존 판정을 경유하지 않는다 (#21-9)');
        $this->assertStringNotContainsString("'installation_status' => 'ready',", $source, "무조건 'ready' 덮어쓰기가 남아 있다 (#21-9)");
    }

    /**
     * #21-9 ②: 재시도 판정은 상태 문자열만이 아니라 이번 회전의 실재 이력도 본다.
     *
     * @scenario resume_point=retry_after_failure, task=db_migrate
     *
     * @effects installer_retry_run_preserves_progress_history
     *
     * @return void 반환값 없음
     */
    public function test_retry_judgment_considers_run_history(): void
    {
        $this->assertFalse(installerIsRetryRun([]), '빈 상태는 신규 설치다');
        $this->assertFalse(installerIsRetryRun(['installation_status' => 'ready']), 'ready 는 신규 설치다');
        $this->assertTrue(installerIsRetryRun(['installation_status' => 'failed']), '실패 상태는 재시도다');
        $this->assertTrue(installerIsRetryRun(['installation_status' => 'running']), '실행 중 상태는 재시도다');
        $this->assertTrue(installerIsRetryRun(['installation_status' => 'aborted']), '중단 상태는 재시도다');
        $this->assertTrue(
            installerIsRetryRun(['installation_status' => 'ready', 'completed_tasks' => ['db_migrate']]),
            '상태가 되감겼어도 완료 마커가 있으면 재시도다 — 마커를 비우면 db_migrate/seed 가 다시 뽑힌다 (#21-9)'
        );
        $this->assertTrue(
            installerIsRetryRun(['installation_status' => 'ready', 'db_migrate_batch_baseline' => 3]),
            '롤백 기준선이 있으면 재시도다 — 지우면 이후 실패의 롤백 범위를 계산할 수 없다'
        );
    }

    /**
     * #21-9 ②-배선: 시작 엔드포인트가 그 판정을 경유한다.
     *
     * @scenario resume_point=retry_after_failure
     *
     * @effects installer_retry_run_preserves_progress_history
     *
     * @return void 반환값 없음
     */
    public function test_install_start_routes_through_retry_judgment(): void
    {
        $source = $this->sourceWithoutCommentsAndStrings(dirname(__DIR__, 3).'/public/install/api/install-process.php');

        $this->assertStringContainsString('installerIsRetryRun(', $source, '시작 엔드포인트가 재시도 판정 SSoT 를 경유하지 않는다 (#21-9)');
    }

    /**
     * #21-8: 진행 이력이 있는 설치의 확장 선택 교체는 거부된다.
     *
     * @scenario resume_point=retry_after_failure, task=module
     *
     * @effects installer_extension_selection_is_locked_once_a_run_has_history
     *
     * @return void 반환값 없음
     */
    public function test_extension_selection_rejected_while_run_in_progress(): void
    {
        $this->assertNull(installerExtensionSelectionRejection([]), '이력 없음 → 허용');
        $this->assertNull(installerExtensionSelectionRejection(['installation_status' => 'ready']), 'ready → 허용');

        foreach (['running', 'failed', 'aborted'] as $status) {
            $rejection = installerExtensionSelectionRejection(['installation_status' => $status]);
            $this->assertIsArray($rejection, $status.' 상태에서 선택 교체가 허용되면 실행 중 태스크 목록이 재계산된다 (#21-8)');
            $this->assertSame(409, $rejection['status']);
            $this->assertSame('error_extension_selection_locked', $rejection['message_key']);
        }
    }

    /**
     * #21-8-배선: save-extensions 가 그 판정을 경유한다.
     *
     * @scenario endpoint_guard=general, task=module
     *
     * @effects installer_extension_selection_is_locked_once_a_run_has_history
     *
     * @return void 반환값 없음
     */
    public function test_save_extensions_routes_through_selection_gate(): void
    {
        $source = $this->sourceWithoutCommentsAndStrings(dirname(__DIR__, 3).'/public/install/api/save-extensions.php');

        $this->assertStringContainsString('installerExtensionSelectionRejection(', $source, 'save-extensions 가 상태 게이트를 경유하지 않는다 (#21-8)');
    }

    /**
     * #21-1: 설치 완료 판정은 API 게이트(_guard)와 같은 파서다 — 등호 공백은 완료로,
     * 주석 처리된 줄은 미완료로 읽는다.
     *
     * @scenario resume_point=all_completed, endpoint_guard=general
     *
     * @effects installer_completion_flag_judgment_shares_one_parser
     *
     * @return void 반환값 없음
     */
    public function test_completion_flag_parsing_matches_api_guard(): void
    {
        $envPath = BASE_PATH.'/.env';

        file_put_contents($envPath, "APP_NAME=g7\nINSTALLER_COMPLETED = true\n");
        $this->assertTrue(isInstallationCompleted(), '등호 주변 공백을 미완료로 읽으면 운영 사이트에 설치 화면이 재개방된다 (#21-1)');

        file_put_contents($envPath, "APP_NAME=g7\n# INSTALLER_COMPLETED=true\n");
        $this->assertFalse(isInstallationCompleted(), '주석 처리된 플래그를 완료로 읽으면 재설치가 막힌다 (#21-1)');

        file_put_contents($envPath, "APP_NAME=g7\nOLD_INSTALLER_COMPLETED=true\n");
        $this->assertFalse(isInstallationCompleted(), '접미 키를 완료로 읽으면 안 된다 (#21-1)');

        file_put_contents($envPath, "APP_NAME=g7\nINSTALLER_COMPLETED=\"true\"\n");
        $this->assertTrue(isInstallationCompleted(), '따옴표 값은 _guard 와 같이 완료다');

        @unlink($envPath);
    }

    /**
     * 22차 N-3: `.env` 파싱이 실패해도 완료 판정은 fail-open 으로 새지 않는다 —
     * parse_ini_file 실패 시 줄 앵커 정규식 폴백으로 2차 판정한다. 운영자가 손으로 편집한
     * `.env`(따옴표 불일치·섹션 괄호)는 escapeEnvValue 규율 밖이고, finalize 계열 가드는
     * 락 파일 폴백조차 없어 이 판정이 열리면 run-task(Artisan 실행)가 열린다.
     *
     * @scenario resume_point=all_completed, endpoint_guard=general
     *
     * @effects installer_completion_flag_judgment_shares_one_parser
     *
     * @return void 반환값 없음
     */
    public function test_completion_flag_survives_env_parse_failure(): void
    {
        $envPath = BASE_PATH.'/.env';
        $broken = "[broken\nINSTALLER_COMPLETED=true\n";

        // 전제 자기검증 — 이 내용이 실제로 파서를 깨뜨려야 폴백 경로가 시험된다.
        $this->assertFalse(
            @parse_ini_string($broken, false, INI_SCANNER_RAW),
            '전제 실패: 이 샘플이 parse_ini 를 깨뜨리지 않는다 — 샘플을 갱신할 것'
        );

        file_put_contents($envPath, $broken);
        $this->assertTrue(isInstallationCompleted(), '파싱 실패가 완료 판정을 fail-open 으로 만들면 안 된다 (22차 N-3)');

        file_put_contents($envPath, "[broken\n# INSTALLER_COMPLETED=true\n");
        $this->assertFalse(isInstallationCompleted(), '폴백도 주석 줄을 완료로 읽으면 안 된다');

        @unlink($envPath);
    }

    /**
     * 22차 N-3 대칭: API 게이트(_guard)의 세 판정도 같은 폴백을 공유한다 (소스 계약 —
     * _guard 는 자기 경로 역산을 쓰므로 temp 기반 행위 재현이 위험하다).
     *
     * @scenario endpoint_guard=finalize_allowed
     *
     * @effects installer_completion_flag_judgment_shares_one_parser
     *
     * @return void 반환값 없음
     */
    public function test_guard_shares_parse_failure_fallback(): void
    {
        $guard = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/api/_guard.php');

        $this->assertSame(
            2,
            substr_count($guard, 'installer_env_completed_flag_fallback($envFile)'),
            '_guard 의 완료 판정 2곳(installer_is_completed·installer_finalize_is_completed)이 파싱 실패 폴백을 공유해야 한다 (22차 N-3)'
        );
    }

    /**
     * 22차 N-2: Step 3 저장의 읽기-판정-쓰기는 상태 잠금 안에서 수행된다 (소스 계약).
     * 잠금 밖이면 #21-9 ① 이 막으려던 「다른 탭」 경합 창이 좁아졌을 뿐 남는다 —
     * 같은 라운드의 reset 은 installerWithStateLock 안에서 재조회하는 규율을 지켰다.
     *
     * @scenario resume_point=retry_after_failure
     *
     * @effects installer_retry_run_preserves_progress_history
     *
     * @return void 반환값 없음
     */
    public function test_step3_save_runs_under_state_lock(): void
    {
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/request-handler.php');

        $this->assertStringContainsString('installerWithStateLock(', $source, 'Step 3 저장이 상태 잠금을 경유하지 않는다 (22차 N-2)');
    }

    /**
     * #21-4: Step 3 저장의 경로 검증이 검사 버튼과 같은 규칙을 갖는다 (소스 계약 —
     * 저장 흐름은 세션·리다이렉트에 결합돼 행위 재현 비용이 크다).
     *
     * @scenario endpoint_guard=general
     *
     * @effects installer_step3_pending_path_validation_matches_check_button
     *
     * @return void 반환값 없음
     */
    public function test_step3_pending_path_validation_matches_check_button(): void
    {
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/request-handler.php');

        $this->assertStringContainsString('\.\.([', $source, '상위 경로 세그먼트(..) 거부 정규식이 없다 (#21-4)');
        $this->assertStringContainsString('"\0"', $source, 'NUL 거부가 없다 (#21-4)');
        $this->assertStringContainsString('#^[A-Za-z]:', $source, 'Windows 절대경로 인식이 없다 — 검사 버튼은 인식하는데 저장은 상대경로로 오인한다 (#21-4)');
        $this->assertStringContainsString('@file_exists($absolutePath)', $source, 'stat 진단 억제가 없다 (#21-4)');
        $this->assertStringContainsString('@is_dir($absolutePath)', $source, 'stat 진단 억제가 없다 (#21-4)');
    }
}
