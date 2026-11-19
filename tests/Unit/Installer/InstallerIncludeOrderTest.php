<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 include 파일의 로드 순서 독립성 회귀 가드 (이슈 #465 후속).
 *
 * 회귀: `install-process.php` 가 `installer-runtime.php` 를 `functions.php` 보다
 * *먼저* require 하도록 바뀌면서 다음 fatal 이 발생했다.
 *
 *   Fatal error: Cannot redeclare escapeEnvValue()
 *   (previously declared in installer-runtime.php) in functions.php
 *
 * 원인: `installer-runtime.php` 는 `escapeEnvValue()` 를 `function_exists` 가드와
 * 함께 polyfill 로 선언하는데, `functions.php` 는 같은 함수를 **가드 없이 무조건**
 * 선언한다. 따라서 runtime 이 먼저 로드되면 functions 로드 시 재선언 fatal.
 *
 * 증상: PHP fatal 이라 JSON 이 출력되지 않음 → 빈 응답 본문 →
 *       브라우저의 `res.json()` 이 "Unexpected end of JSON input" 으로 실패
 *       → 설치 시작 즉시 "설치 실패" (SSE/폴링 양쪽 동일 — 두 모드 모두
 *          install-process.php 를 경유하므로)
 *
 * 본 테스트가 고정하는 계약:
 *   인스톨러 include 파일들은 **어떤 순서로 로드되어도** 함수 재선언 fatal 을
 *   일으키지 않는다. 각 엔드포인트가 require 순서를 바꿔도 안전해야 한다.
 *
 * 별도 프로세스로 실행하는 이유: 함수 선언은 프로세스 전역이라 PHPUnit 프로세스
 * 안에서 두 파일을 로드하면 다른 Installer 테스트를 오염시킨다. 실제 fatal 여부를
 * 관측하려면 격리된 PHP 프로세스에서 로드해야 한다.
 *
 * 효과 요약(마커 아님 — 평문): 인스톨러 include 파일은 어떤 순서로 로드되어도 함수 재선언 fatal 을 내지 않는다. 시나리오 표식은 메서드마다 단다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerIncludeOrderTest extends TestCase
{
    use ScansPhpSourceFunctions;

    private string $projectRoot = '';

    /**
     * 프로젝트 루트 경로를 확보한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 3);
    }

    /**
     * 인스톨러 include 파일 2개를 지정한 순서로 로드하는 격리 PHP 프로세스 실행.
     *
     * @param  array<int, string>  $relativeIncludes  BASE_PATH 기준 상대 경로 목록
     * @return array{exitCode:int, output:string} 종료 코드와 출력
     */
    private function loadInIsolatedProcess(array $relativeIncludes): array
    {
        $root = str_replace('\\', '/', $this->projectRoot);

        $lines = ['error_reporting(E_ALL);', "define('BASE_PATH', '{$root}');"];
        foreach ($relativeIncludes as $include) {
            $lines[] = "require_once '{$root}/{$include}';";
        }
        $lines[] = "echo 'LOAD_OK';";

        $code = implode("\n", $lines);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes);

        $this->assertIsResource($process, 'PHP 하위 프로세스를 띄우지 못함');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exitCode' => $exitCode, 'output' => $stdout.$stderr];
    }

    /**
     * 회귀 재현 — installer-runtime.php 를 functions.php 보다 먼저 로드.
     *
     * 이 순서가 곧 install-process.php 의 require 순서였고, escapeEnvValue()
     * 재선언 fatal 로 빈 응답이 발생했다.
     *
     * @effects endpoint_require_chain_defines_gate_helpers
     *
     * @return void 반환값 없음
     */
    public function test_runtime_before_functions_does_not_fatal(): void
    {
        $result = $this->loadInIsolatedProcess([
            'public/install/includes/installer-runtime.php',
            'public/install/includes/functions.php',
        ]);

        $this->assertStringNotContainsString(
            'Cannot redeclare',
            $result['output'],
            'installer-runtime.php 를 functions.php 보다 먼저 로드하면 함수 재선언 fatal 이 발생한다. '
            .'중복 선언되는 함수에 function_exists 가드가 필요하다.',
        );
        $this->assertStringContainsString('LOAD_OK', $result['output'], '두 파일 로드가 완료되어야 함');
        $this->assertSame(0, $result['exitCode'], 'fatal 없이 정상 종료해야 함');
    }

    /**
     * 기존 순서(functions.php 먼저) 도 계속 동작해야 한다 — 회귀 방지.
     *
     * finalize-env.php / task-runner.php 가 이 순서를 사용한다.
     *
     * @effects endpoint_require_chain_defines_gate_helpers
     *
     * @return void 반환값 없음
     */
    public function test_functions_before_runtime_does_not_fatal(): void
    {
        $result = $this->loadInIsolatedProcess([
            'public/install/includes/functions.php',
            'public/install/includes/installer-runtime.php',
        ]);

        $this->assertStringNotContainsString('Cannot redeclare', $result['output']);
        $this->assertStringContainsString('LOAD_OK', $result['output']);
        $this->assertSame(0, $result['exitCode']);
    }

    /**
     * install-process.php 의 실제 require 순서를 그대로 재현.
     *
     * 이 엔드포인트가 SSE / 폴링 양 모드의 공통 시작점이므로, 여기서 fatal 이 나면
     * 두 모드 모두 설치 시작 즉시 실패한다.
     *
     * @effects endpoint_require_chain_defines_gate_helpers
     *
     * @return void 반환값 없음
     */
    public function test_install_process_require_chain_does_not_fatal(): void
    {
        $result = $this->loadInIsolatedProcess([
            'public/install/includes/config.php',
            'public/install/includes/installer-state.php',
            'public/install/includes/installer-runtime.php',
            'public/install/includes/functions.php',
        ]);

        $this->assertStringNotContainsString(
            'Cannot redeclare',
            $result['output'],
            'install-process.php 의 require 체인에서 재선언 fatal 발생 — '
            .'설치 시작 API 가 빈 응답을 반환하여 "Unexpected end of JSON input" 이 된다',
        );
        $this->assertSame(0, $result['exitCode']);
    }

    /**
     * 각 인스톨러 엔드포인트의 실제 require 체인을 그대로 로드했을 때, 그 엔드포인트가
     * 호출하는 함수들이 모두 정의되는지 검증한다.
     *
     * 인스톨러는 Laravel 오토로드 밖의 플레인 PHP require 체인이라, 어떤 파일에 함수
     * 호출을 추가하면 그 함수의 정의 파일이 **그 엔드포인트의 체인에 실제로 포함되어
     * 있는지** 를 사람이 직접 확인해야 한다. 빠뜨리면 "Call to undefined function"
     * fatal → 빈 응답 → 프론트의 JSON 파싱 에러가 된다 (escapeEnvValue 재선언 fatal 과
     * 증상이 동일하지만 원인은 다름).
     *
     * 단위 테스트는 함수를 직접 호출하므로 이 축을 잡지 못한다 — 엔드포인트의 조립
     * 계층을 재현해야만 검출된다.
     *
     * @param  string  $endpoint  엔드포인트 파일명 (실패 메시지용)
     * @param  array<int, string>  $chain  엔드포인트의 top-level require 순서
     * @param  array<int, string>  $requiredFunctions  그 엔드포인트가 호출하는 함수들
     *
     * @effects endpoint_require_chain_defines_gate_helpers
     *
     * @return void 반환값 없음
     */
    #[DataProvider('endpointChainProvider')]
    public function test_endpoint_require_chain_defines_all_functions_it_calls(
        string $endpoint,
        array $chain,
        array $requiredFunctions,
    ): void {
        $root = str_replace('\\', '/', $this->projectRoot);

        $lines = ['error_reporting(E_ALL);'];
        foreach ($chain as $include) {
            $lines[] = "require_once '{$root}/{$include}';";
        }
        foreach ($requiredFunctions as $fn) {
            $lines[] = "if (! function_exists('{$fn}')) { echo 'MISSING:{$fn} '; }";
        }
        $lines[] = "echo 'CHECK_DONE';";

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, '-r', implode("\n", $lines)], $descriptors, $pipes);
        $this->assertIsResource($process);

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertStringNotContainsString(
            'Fatal error',
            $output,
            "{$endpoint} 의 require 체인 로드 중 fatal 발생 — 이 엔드포인트는 빈 응답을 반환한다:\n".$output,
        );
        $this->assertStringNotContainsString(
            'MISSING:',
            $output,
            "{$endpoint} 가 호출하는 함수가 그 require 체인에서 정의되지 않는다 "
            .'— 실행 시 "Call to undefined function" fatal 이 발생한다:'."\n".$output,
        );
        $this->assertStringContainsString('CHECK_DONE', $output);
    }

    /**
     * 엔드포인트별 (실제 require 체인, 그 엔드포인트가 호출하는 함수 목록).
     *
     * 인스톨러 엔드포인트에 새 함수 호출을 추가하면 여기에도 등록한다.
     *
     * @return array<string, array{0:string, 1:array<int,string>, 2:array<int,string>}> 엔드포인트별 체인·호출 함수
     */
    public static function endpointChainProvider(): array
    {
        return [
            'install-process.php (SSE/폴링 공통 시작점)' => [
                'install-process.php',
                [
                    'public/install/includes/config.php',
                    'public/install/includes/session.php',
                    'public/install/includes/installer-state.php',
                    'public/install/includes/functions.php',
                    'public/install/includes/installer-runtime.php',
                    'public/install/includes/installer-ownership.php',
                    'public/install/includes/install-plan.php',
                ],
                [
                    'installer_begin_installation',
                    'sanitizeConfigForState',
                    'buildInstallerRuntimeFromState',
                    'writeInstallerRuntime',
                    'readInstallerRuntime',
                    'getInstallationState',
                    'saveInstallationState',
                    'saveInstallationStateCas',
                    'applyExistingDbActionStateGuard',
                    'escapeEnvValue',
                    'installer_require_owner',
                    'installer_require_csrf',
                    'installer_request_json_body',
                    'installer_verify_drop_confirm',
                    'installer_normalize_db_prefix',
                    'installer_plan_signature',
                    'installer_json_error',
                    'hydrateDbSecretsFromRuntime',
                    'getDatabaseConnection',
                    'filterTablesByPrefix',
                ],
            ],
            'state-management.php (reset / abort / skip_task / reclaim)' => [
                'state-management.php',
                [
                    'public/install/includes/config.php',
                    'public/install/includes/session.php',
                    'public/install/includes/functions.php',
                    'public/install/includes/installer-state.php',
                    'public/install/includes/installer-ownership.php',
                    'public/install/api/rollback-functions.php',
                    // 초기화·중단도 노출 점검 잔여물을 지운다 — finalize 만 등재돼 있어 이 두
                    // 경로의 require 가 사라져도 아무것도 울지 않았다(치명은 그 자리에서 난다).
                    'public/install/includes/exposure-check.php',
                ],
                [
                    'redactInstallationStateSecrets',
                    'saveInstallationState',
                    'saveInstallationStateCas',
                    'getInstallationState',
                    'isInstallationCompleted',
                    'rollbackCurrentTask',
                    'executeSeedTruncate',
                    'markInstallationTaskSkipped',
                    'installerSkippableTaskIds',
                    'installerCleanupExposureProbe',
                    'installer_require_owner',
                    'installer_require_csrf',
                    'installer_state_is_claimed',
                    'installer_session_is_owner',
                    'installer_recovery_token_matches',
                    'installer_reclaim_owner',
                    'installer_reclaim_is_locked',
                    'installer_reclaim_record_failure',
                    'installer_reclaim_reset_throttle',
                    'installerHasActiveWorker',
                    'installerResetNeedsDbNotice',
                ],
            ],
            'run-task.php (단계별 실행 — 요청 1개 = 태스크 1개)' => [
                'run-task.php',
                [
                    'public/install/includes/config.php',
                    'public/install/includes/functions.php',
                    'public/install/includes/installer-state.php',
                    'public/install/includes/installer-runtime.php',
                    'public/install/includes/progress-emitter.php',
                    'public/install/includes/task-runner.php',
                ],
                [
                    'runNextInstallationTask',
                    'buildInstallationTaskList',
                    'installerRunArtisan',
                    'installerEffectiveExecutionMode',
                    'installerCoerceVendorModeForExecution',
                    'acquireWorkerLock',
                    'releaseWorkerLock',
                    'readInstallerRuntime',
                    'executeMigrateRollback',
                ],
            ],
            'install-process.php (실행 모드 판정 동반)' => [
                'install-process.php',
                [
                    'public/install/includes/config.php',
                    'public/install/includes/session.php',
                    'public/install/includes/installer-state.php',
                    'public/install/includes/functions.php',
                    'public/install/includes/installer-runtime.php',
                    'public/install/includes/execution-mode.php',
                ],
                [
                    'installerResolveExecutionMode',
                    'installerCoerceVendorModeForExecution',
                    'installerNormalizeExecutionMode',
                ],
            ],
            'finalize-env.php' => [
                'finalize-env.php',
                [
                    'public/install/includes/config.php',
                    'public/install/includes/functions.php',
                    'public/install/includes/binary-path-policy.php',
                    'public/install/includes/installer-runtime.php',
                    'public/install/includes/installer-state.php',
                    // 마무리 단계가 웹 노출 프로브 sentinel 을 지운다 (B-4) — require 가
                    // 빠지면 가장 되돌리기 어려운 엔드포인트가 undefined function 으로 500 이
                    // 되고, sentinel 은 설치 완료 사이트에 그대로 남는다.
                    'public/install/includes/exposure-check.php',
                    'public/install/includes/session.php',
                    'public/install/includes/installer-ownership.php',
                    'public/install/includes/env-finalize.php',
                ],
                [
                    'installer_finalize_env',
                    'generateEnvContent',
                    'installer_site_language',
                    'addLog',
                    'redactInstallationStateSecrets',
                    'getInstallationState',
                    'saveInstallationState',
                    'installerReadStateFile',
                    'readInstallerRuntime',
                    'mergeRuntimeIntoEnv',
                    'deleteInstallerRuntime',
                    'installerCleanupExposureProbe',
                    'installer_json_error',
                    'installer_ownership_lang',
                    'installer_state_is_claimed',
                    'installer_require_owner',
                    'installer_require_csrf',
                    'installer_reclaim_reset_throttle',
                    'installerHasActiveWorker',
                    'installerWaitForWorkerRelease',
                    'installerConfigCachePath',
                    'installerClearConfigCache',
                    'installerRebuildConfigCache',
                    'installer_php_binary_from_state',
                ],
            ],
            'install-worker.php (SSE 워커 → task-runner)' => [
                'install-worker.php',
                [
                    'public/install/includes/config.php',
                    'public/install/includes/functions.php',
                    'public/install/includes/installer-state.php',
                    'public/install/includes/progress-emitter.php',
                    'public/install/includes/task-runner.php',
                ],
                [
                    'purgeAdminPasswordAfterSeeding',
                    'hydrateDbSecretsFromRuntime',
                    'redactInstallationStateSecrets',
                    'readInstallerRuntime',
                    'writeInstallerRuntime',
                    'executeSeedTruncate',
                    'installer_plan_verify',
                    'installerHandleWorkerShutdown',
                    'installerRunningTaskKey',
                    'installerResetTaskTimeLimit',
                    'installerReleaseInterruptedLocks',
                    'releaseWorkerLock',
                    'getPhpBinary',
                    'installer_php_binary_from_state',
                ],
            ],
            'save-extensions.php' => [
                'save-extensions.php',
                [
                    'public/install/includes/session.php',
                    'public/install/includes/config.php',
                    'public/install/includes/installer-state.php',
                    'public/install/includes/functions.php',
                    'public/install/includes/installer-ownership.php',
                    'public/install/includes/extension-selection.php',
                    'public/install/includes/php-extension-requirements.php',
                ],
                [
                    'getInstallationState',
                    'saveInstallationStateCas',
                    'installer_require_owner',
                    'installer_require_csrf',
                    'installer_json_error',
                    'installer_resolve_extension_selection',
                    'installer_scan_extensions',
                    'installer_extension_error_messages',
                    'installer_exclude_selection_missing_php',
                    'addLog',
                ],
            ],
            'index.php (Step3 폼 / 완료 감지)' => [
                'index.php',
                [
                    'public/install/includes/config.php',
                    'public/install/includes/functions.php',
                    'public/install/includes/session.php',
                    'public/install/includes/installer-state.php',
                    'public/install/includes/request-handler.php',
                ],
                [
                    'sanitizeConfigForState',
                    'isInstallationCompleted',
                    'installerSecretConfigKeys',
                    'installer_state_is_claimed',
                    'installer_session_is_owner',
                    'installer_csrf_token',
                    'installer_csrf_is_valid',
                    'installer_request_csrf_token',
                    'installer_step3_db_connections',
                    'installer_db_test_status',
                    'installer_claim_owner',
                    'installer_format_recovery_token',
                    'saveInstallationStateCas',
                    'installer_validate_config',
                    'installer_validate_db_username',
                    'installer_vendor_mode_availability',
                    'installer_site_language',
                ],
            ],
            'check-configuration.php (요구사항·DB 테스트·바이너리 검사)' => [
                'check-configuration.php',
                [
                    'public/install/includes/config.php',
                    'public/install/includes/session.php',
                    'public/install/includes/functions.php',
                    'public/install/api/_guard.php',
                    'public/install/includes/installer-ownership.php',
                    'public/install/includes/requirements.php',
                    'public/install/includes/db-check.php',
                ],
                [
                    'installer_collect_requirements',
                    'installer_requirements_all_passed',
                    'installer_test_database',
                    'installer_verify_php_binary',
                    'installer_verify_composer',
                    'installer_detect_php_binaries',
                    'installer_check_core_pending_path',
                    'installer_issue_drop_confirm',
                    'installer_forget_db_test',
                    'installer_db_fingerprint',
                    'getDatabaseConnection',
                    'checkExistingTables',
                ],
            ],
            'g7-install (터미널 설치 본체)' => [
                'g7-install',
                [
                    'public/install/includes/cli/app.php',
                ],
                [
                    'g7_install_cli_main',
                    'installer_validate_config',
                    'installer_collect_requirements',
                    'installer_test_database',
                    'installer_resolve_extension_selection',
                    'installer_exclude_selection_missing_php',
                    'installer_begin_installation',
                    'installer_finalize_env',
                    'runInstallationTasks',
                    'acquireWorkerLock',
                    'installer_claim_cli_owner',
                    'installer_cli_reinstall',
                    'installer_cli_apply_web_user',
                ],
            ],
        ];
    }

    /**
     * installer-runtime.php 가 polyfill 로 선언하는 함수는 functions.php 에서도
     * function_exists 가드를 가져야 한다 — 정적 계약 검사.
     *
     * 앞으로 두 파일에 중복 함수가 새로 추가되어도 가드 없이는 통과하지 못한다.
     *
     * @effects endpoint_require_chain_defines_gate_helpers
     *
     * @return void 반환값 없음
     */
    public function test_functions_shared_with_runtime_are_all_guarded(): void
    {
        $functionsPath = $this->projectRoot.'/public/install/includes/functions.php';
        $runtimePath = $this->projectRoot.'/public/install/includes/installer-runtime.php';

        // 주석을 지운 뒤 대조한다 — 원문에는 주석 처리된 배선도 글자로 남아, 원문 대조는 「그 배선이 살아 있다」를 증명하지 못한다 (12차 메타 검사 ①).
        $functionsSource = $this->stripComments((string) file_get_contents($functionsPath));
        $runtimeSource = $this->stripComments((string) file_get_contents($runtimePath));

        // installer-runtime.php 가 선언하는 함수 목록 추출
        preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $runtimeSource, $m);
        $runtimeFunctions = array_unique($m[1]);

        $this->assertNotEmpty($runtimeFunctions, 'installer-runtime.php 에서 함수를 하나도 못 찾음 — 추출 정규식 점검 필요');

        foreach ($runtimeFunctions as $fn) {
            // functions.php 에도 같은 함수가 선언되어 있는가?
            if (! preg_match('/^\s*function\s+'.preg_quote($fn, '/').'\s*\(/m', $functionsSource)) {
                continue; // 중복 아님 — 안전
            }

            // 중복이라면 functions.php 쪽에 function_exists 가드가 있어야 한다
            $this->assertMatchesRegularExpression(
                "/function_exists\(\s*['\"]".preg_quote($fn, '/')."['\"]\s*\)/",
                $functionsSource,
                "functions.php 의 {$fn}() 이 installer-runtime.php 와 중복 선언되는데 "
                ."function_exists 가드가 없다. 로드 순서에 따라 'Cannot redeclare' fatal 이 발생한다.",
            );
        }
    }

    /**
     * 가드 **존재** 축의 모집단을 인스톨러 전수에서 도출한다.
     *
     * 형제 검사(`test_functions_shared_with_runtime_are_all_guarded`)는 두 파일
     * (`functions.php` ↔ `installer-runtime.php`)만 본다. 그런데 앞서 가드 **블록 내용**
     * 축의 모집단을 `public/install/**` 전수로 넓히면서, 그 옆의 「가드가 아예 없는
     * 중복 선언」 축은 두 파일 고정으로 남았다 — `public/install/api/_guard.php` 처럼 나중에 생긴
     * 파일에 중복 선언이 들어와도 아무것도 울지 않는다(변이 실측: 가드 래퍼를
     * 통째로 지워도 초록).
     *
     * 판정: **두 파일 이상에서 최상위 선언되는 함수**는 그 선언 지점마다
     * `function_exists` 가드를 가져야 한다. 현재 위반 0 이므로 지금 넣으면 초록으로
     * 들어가고, 이후 중복이 생기는 순간 red 가 된다.
     *
     * 「선언 지점마다」는 **그 이름의 가드 블록 안에 그 선언이 들어 있는가**로 잰다.
     * 파일 단위로 재면(그 이름의 `function_exists` 가 파일 어딘가에 있으면 통과) 선언이
     * 가드 밖으로 나가도 같은 파일의 **사용 여부 검사** 하나가 그 자리를 메워 초록이 된다.
     *판정 대상 집합이 비면 이 검사 자체가 공허해지므로 그 크기도 함께 잰다.
     *
     * @scenario guard_population=installer_sources
     *
     * @effects installer_includes_are_load_order_independent
     *
     * @return void 반환값 없음
     */
    public function test_every_duplicated_top_level_function_is_guarded_at_each_site(): void
    {
        /** @var array<string, array<int, string>> $sites 함수명 => 선언 파일 목록 */
        $sites = [];

        foreach ($this->installerPhpFiles() as $file) {
            $source = $this->stripComments((string) file_get_contents($file));

            preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $source, $m);

            foreach (array_unique($m[1]) as $fn) {
                $sites[$fn][] = $file;
            }
        }

        $this->assertNotEmpty($sites, '인스톨러에서 최상위 함수를 하나도 찾지 못했다 — 이 검사가 아무것도 재지 않는다');

        $duplicated = array_filter($sites, static fn (array $files): bool => count($files) >= 2);

        // **판정 대상 자체가 비지 않았는가.**
        //
        // 종전 비공허성 단언은 전체 함수 집합(`$sites`)만 봤다 — 그 집합은 332 개라 언제나
        // 비지 않으므로, 실제로 판정되는 중복 집합이 0 이 되어도 초록이었다. 현재 실측
        // 모집단은 함수 2 × 지점 2 = 4 다.
        $this->assertGreaterThanOrEqual(
            2,
            count($duplicated),
            '두 파일 이상에 선언된 함수가 둘 미만이다 — 이 검사가 아무것도 판정하지 않는 상태다. '
            .'중복이 정당하게 사라졌다면 이 하한을 함께 내린다'
        );

        $unguarded = [];

        foreach ($duplicated as $fn => $files) {
            foreach ($files as $file) {
                // **그 선언이 그 이름의 가드 블록 안에 있는가**를 본다.
                //
                // 종전 판정은 `function_exists('X')` 가 **그 파일 어딘가에** 있으면 통과였다.
                // 독블록은 「그 선언 **지점**마다」라고 적는데 도출 입도가 「파일」이었던
                // 것이다 — 선언이 가드 밖으로 나가도, 같은 파일에 그 이름의 **사용 여부
                // 검사**(`if (… && function_exists('X')) { X(…); }`)가 하나만 있으면 초록이다.
                // 그 형태는 실물에 존재한다(`functions.php`·`artisan-executor.php`).
                // 형제 헬퍼가 이미 블록 단위로 자르므로 그것을 재사용한다.
                $guarded = false;

                foreach ($this->functionExistsGuardBlocks($this->stripComments((string) file_get_contents($file))) as [$guardName, $defined]) {
                    if ($guardName === $fn && in_array($fn, $defined, true)) {
                        $guarded = true;
                        break;
                    }
                }

                if (! $guarded) {
                    $unguarded[] = basename($file).' :: '.$fn;
                }
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            '두 파일 이상에 선언된 함수가 **그 선언 지점에서** 가드되지 않는다 — 로드 순서에 따라 '
            .'Cannot redeclare fatal 이 난다',
        );
    }

    /**
     * `function_exists` 가드 블록은 **자기 이름의 함수만** 감싼다.
     *
     * 이 파일들은 최상위에 함수 정의만 두고 `require_once` 로 서로를 부른다. 한 파일
     * 안에서의 중복 정의는 `require_once` 가 막으므로 **모든** 함수가 가드를 가질 필요는
     * 없다(가드는 두 파일에 같은 이름이 있을 때 필요하고, 그 축은 형제 검사가 잰다).
     *
     * 깨지는 것은 다른 형태다 — 가드 블록 **하나가 두 함수**를 감쌀 때다. 그러면 앞
     * 이름이 다른 경로에서 먼저 정의되는 순간 뒤 함수는 **통째로 정의되지 않고**, 그
     * 호출부가 `Call to undefined function` 으로 죽는다. 실제로
     * `installerDetectServerKind()` 가 형제(`installerExposureProbeTargets`)의 가드 안에
     * 중첩돼 있었고, 그 유일한 호출부는 요구사항 응답 조립 한복판이라 그 화면이 통째로
     * 500 봉투가 되는 형태였다 (D-r1).
     *
     * 판정은 **PHP 토크나이저**로 한다. 중괄호를 문자열로 세면 문자열 리터럴 안의 `{`·`}`
     * 가 깊이를 흔들어 엉뚱한 블록을 하나로 읽는다 — 이 검사를 처음 쓸 때 실제로 그렇게
     * 오탐이 나서(한 가드가 12함수를 감싼다고 보고했다) 토크나이저로 교체했다.
     *
     * 모집단은 **도출한다** — 파일 목록을 손으로 적으면 새 include 가 그대로 사각이 된다.
     * 그 도출은 `includes/` 한 겹이 아니라 `public/install/` **전체**를 재귀로 훑는다.
     * 같은 형태의 선언 가드는 `public/install/api/_guard.php`(8개)·`api/*.php`·`views/*.php` 에도 있고,
     * 종전 모집단(`includes/*.php`)은 그것들을 통째로 밖에 두고 있었다 — 그 자리에 형제를
     * 중첩시켜도 이 검사가 초록이었다(실측).
     *
     * @scenario guard_population=installer_sources
     *
     * @effects installer_includes_are_load_order_independent
     *
     * @return void 반환값 없음
     */
    public function test_function_exists_guards_wrap_only_their_own_function(): void
    {
        $files = $this->installerPhpFiles();

        $this->assertGreaterThan(
            20,
            count($files),
            '인스톨러 PHP 파일 도출이 비었거나 줄었다 — 이 검사가 아무것도 재지 않는다'
        );

        // 모집단이 세 하위 트리를 실제로 덮는지도 잰다 — 스캔이 한 겹으로 되돌아가면
        // 개수 하한만으로는 드러나지 않는다.
        foreach (['api', 'includes', 'views'] as $subtree) {
            $this->assertNotEmpty(
                array_filter($files, static fn (string $f): bool => str_contains(strtr($f, '\\', '/'), '/install/'.$subtree.'/')),
                "모집단에 {$subtree}/ 가 없다 — 그 하위 트리의 가드가 통째로 사각이 된다"
            );
        }

        $guardsChecked = 0;

        foreach ($files as $file) {
            foreach ($this->functionExistsGuardBlocks($this->stripComments((string) file_get_contents($file))) as [$guardName, $defined]) {
                $guardsChecked++;

                $foreign = array_values(array_diff($defined, [$guardName]));

                $this->assertSame(
                    [],
                    $foreign,
                    basename($file)." 의 function_exists('{$guardName}') 가드 블록이 다른 함수("
                    .implode(', ', $foreign).')를 함께 감싼다 — 그 형제가 먼저 정의되는 경로에서 '
                    .'이 함수들이 통째로 사라지고 호출부가 Call to undefined function 으로 죽는다.'
                );
            }
        }

        $this->assertGreaterThan(
            20,
            $guardsChecked,
            '가드 블록 도출이 비었다 — 토크나이저 판정이 실물과 어긋났다'
        );
    }

    /**
     * 같은 이름의 가드 블록이 한 파일에 둘 이상 있어도 **전부** 수집된다.
     *
     * 선언 가드(`if (! function_exists('X')) { function X() {…} }`)와 **사용 여부 검사**
     * (`if (… && function_exists('X')) { X(…); }`)는 이름이 같다. 실물이 둘 다 있다 —
     * `public/install/includes/functions.php` 와 `public/install/includes/artisan-executor.php`. 수집을 이름 키로 하면
     * 뒤엣것이 앞엣것을 덮어, 선언 가드가 **빈 집합으로 측정**되고 그 안에 중첩된 형제가
     * 검사에서 사라진다. 실 소스가 준수 상태라 이 사실은 red 로 드러나지 않으므로
     * 합성 입력으로 고정한다.
     *
     * @scenario guard_population=synthetic_source
     *
     * @effects installer_includes_are_load_order_independent
     *
     * @return void 반환값 없음
     */
    public function test_guard_block_collection_keeps_every_block_of_a_reused_name(): void
    {
        // 선언 가드가 **먼저**, 사용 여부 검사가 **나중**. 이름 키 수집은 뒤엣것으로 덮어
        // 중첩된 형제(installerNestedSibling)를 잃는다.
        $source = "<?php\n"
            ."if (! function_exists('installerProbeTarget')) {\n"
            ."    function installerProbeTarget(): void {}\n"
            ."    function installerNestedSibling(): void {}\n"
            ."}\n"
            ."if (\$ok === false && function_exists('installerProbeTarget')) {\n"
            ."    installerProbeTarget();\n"
            ."}\n";

        $blocks = $this->functionExistsGuardBlocks($source);

        $named = array_values(array_filter(
            $blocks,
            static fn (array $b): bool => $b[0] === 'installerProbeTarget',
        ));

        $this->assertCount(2, $named, '같은 이름의 가드 블록 둘이 모두 수집되어야 한다 — 덮어쓰면 하나가 사라진다');

        $allDefined = array_merge(...array_map(static fn (array $b): array => $b[1], $named));

        $this->assertContains(
            'installerNestedSibling',
            $allDefined,
            '선언 가드가 사용 여부 검사에 덮이면 그 블록이 빈 집합으로 측정되어 중첩 형제가 검사에서 사라진다',
        );
    }

    /**
     * 가드 블록의 끝 판정이 **문자열 보간에 흔들리지 않는다**.
     *
     * `"{$var}"` 의 여는 중괄호는 배열 토큰(`T_CURLY_OPEN`)이고 닫는 쪽은 평문 `}` 다 —
     * 여는 쪽을 깊이에 넣지 않으면 블록이 보간 지점에서 **끝난 것으로** 측정되고,
     * 그 뒤에 중첩된 형제 선언이 이 검사에서 영원히 사라진다. 인스톨러 코드는 보간을
     * 상시로 쓰므로 실물 10블록이 이미 그 상태였다.
     *
     * 실 소스가 준수 상태라 red 로 드러나지 않으므로 합성 입력으로 고정한다.
     *
     * @scenario guard_population=synthetic_source
     *
     * @effects installer_includes_are_load_order_independent
     *
     * @return void 반환값 없음
     */
    public function test_guard_block_end_is_not_cut_short_by_string_interpolation(): void
    {
        $source = "<?php\n"
            ."if (! function_exists('installerProbeTarget')) {\n"
            ."    function installerProbeTarget(string \$a): string { return \"path {\$a} end\"; }\n"
            ."    function installerNestedSibling(): void {}\n"
            ."}\n";

        $blocks = $this->functionExistsGuardBlocks($source);

        $named = array_values(array_filter(
            $blocks,
            static fn (array $b): bool => $b[0] === 'installerProbeTarget',
        ));

        $this->assertCount(1, $named);
        $this->assertContains(
            'installerNestedSibling',
            $named[0][1],
            '보간의 여는 중괄호를 깊이에 넣지 않아 블록이 거기서 끝났다 — 그 뒤 중첩 형제가 검사 밖이 된다',
        );
    }

    /**
     * `${var}` 형태(구식 보간)도 같은 판정을 탄다.
     *
     * @scenario guard_population=synthetic_source
     *
     * @effects installer_includes_are_load_order_independent
     *
     * @return void 반환값 없음
     */
    public function test_guard_block_end_is_not_cut_short_by_dollar_brace_interpolation(): void
    {
        $source = "<?php\n"
            ."if (! function_exists('installerProbeTarget')) {\n"
            ."    function installerProbeTarget(array \$a): string { return \"v {\$a['k']} end\"; }\n"
            ."    function installerNestedSibling(): void {}\n"
            ."}\n";

        $blocks = $this->functionExistsGuardBlocks($source);

        $named = array_values(array_filter(
            $blocks,
            static fn (array $b): bool => $b[0] === 'installerProbeTarget',
        ));

        $this->assertCount(1, $named);
        $this->assertContains('installerNestedSibling', $named[0][1]);
    }

    /**
     * 인스톨러 PHP 파일 전수 (재귀).
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function installerPhpFiles(): array
    {
        $root = $this->projectRoot.'/public/install';

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * `if (! function_exists('X')) { … }` 블록별로 그 안에 정의된 함수 이름을 모읍니다.
     *
     * 반환은 **블록 목록**이다 — 이름을 키로 쓰면 같은 이름의 블록이 둘일 때(선언 가드 +
     * 사용 여부 검사) 뒤엣것이 앞엣것을 덮어 그 함수의 가드가 빈 집합으로 측정된다.
     *
     * @param  string  $source  PHP 소스 원문
     * @return array<int, array{0:string, 1:string[]}> [가드 이름, 그 블록 안에 정의된 함수 이름 목록]
     */
    private function functionExistsGuardBlocks(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $blocks = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'function_exists') {
                continue;
            }

            $name = null;

            for ($j = $i + 1; $j < min($i + 6, $count); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $name = trim($tokens[$j][1], '"'.chr(39));
                    break;
                }
            }

            if ($name === null) {
                continue;
            }

            // 이 가드가 여는 블록의 `{` 를 찾는다 (없으면 조건문이 아니다 — 건너뛴다).
            $open = null;

            for ($j = $i; $j < min($i + 14, $count); $j++) {
                if ($tokens[$j] === '{') {
                    $open = $j;
                    break;
                }
            }

            if ($open === null) {
                continue;
            }

            $depth = 0;
            $defined = [];

            for ($j = $open; $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    $depth++;

                    continue;
                }

                // 문자열 보간이 여는 중괄호도 깊이에 넣는다.
                //
                // `"{$var}"` 의 여는 쪽은 **배열 토큰**(`T_CURLY_OPEN`)이고 닫는 쪽은
                // 평문 `}` 다. 여는 쪽만 세지 않으면 깊이가 한 칸 내려가 블록이 **거기서
                // 끝난 것으로** 측정되고, 그 뒤에 중첩된 형제 선언이 이 검사에서 영원히
                // 사라진다. 인스톨러 코드는 보간을 상시로 쓰므로 실물 10블록이 이미 그
                // 상태였다.
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;

                    continue;
                }

                if ($tokens[$j] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }

                    continue;
                }

                if (is_array($tokens[$j]) && $tokens[$j][0] === T_FUNCTION) {
                    // **바로 다음** 비-공백 토큰만 본다. 익명 함수(`function (int $x)`)는
                    // 그 자리가 `(` 라 이름이 없고, 넓게 훑으면 매개변수 타입(`int`)을
                    // 함수 이름으로 오인한다 — 실제로 그렇게 오탐이 났다.
                    $k = $j + 1;

                    while ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        $k++;
                    }

                    if ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
                        $defined[] = $tokens[$k][1];
                    }
                }
            }

            $blocks[] = [$name, array_values(array_unique($defined))];
            $i = $open;
        }

        return $blocks;
    }
}
