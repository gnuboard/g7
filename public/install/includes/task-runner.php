<?php

use App\Support\ExecutionEnvironment;

/**
 * 그누보드7 웹 인스톨러 - 설치 작업 실행 코어 (SSE/폴링 공용)
 *
 * 설치 task 정의와 실행 로직을 모드와 무관하게 제공합니다.
 * 진입점(install-worker.php SSE 모드, install-process.php 폴링 모드)이
 * ProgressEmitter를 등록한 뒤 runInstallationTasks()를 호출합니다.
 */

require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/installer-state.php';
require_once __DIR__.'/installer-runtime.php';
require_once __DIR__.'/installer-ownership.php';
require_once __DIR__.'/progress-emitter.php';
require_once __DIR__.'/execution-mode.php';
require_once __DIR__.'/artisan-executor.php';
require_once __DIR__.'/../api/rollback-functions.php';

// ============================================================================
// Emitter 호환 래퍼 — 기존 task 함수의 sendSSEEvent() 호출을 그대로 유지하면서
// 내부적으로 현재 등록된 ProgressEmitter로 delegate합니다.
// ============================================================================

if (! function_exists('sendSSEEvent')) {
    /**
     * 이벤트 송출 (SSE 모드/폴링 모드 공통).
     *
     * @param  string  $event  이벤트 타입
     * @param  array  $data  이벤트 데이터
     */
    function sendSSEEvent(string $event, array $data): void
    {
        getProgressEmitter()->emit($event, $data);
    }
}

if (! function_exists('sendCommandOutputSSE')) {
    /**
     * 커맨드 실행 원문을 「명령 상세」 채널로 내보냅니다 (§12.0 W3).
     *
     * 진행 기록의 독자는 운영자이므로 마이그레이션 파일명·`INFO Running migrations` 같은
     * 개발자 표기가 거기 섞이면 안 된다. 그렇다고 버리면 실패했을 때 짚을 근거가 사라지므로,
     * 표식을 붙여 같은 로그 채널로 보내고 **화면이** 접기 영역으로 분류한다. 로그 파일에는
     * 그대로 남는다.
     *
     * 진행 기록에서 걷어내는 규칙은 이미 수동 복구 명령에 적용돼 있었다 — 그때 형제인
     * 커맨드 stdout 을 함께 옮기지 않아 규칙이 한쪽 통로에만 살아 있었다.
     *
     * @param  array<int, string>  $output  커맨드 출력 줄 목록
     */
    function sendCommandOutputSSE(array $output): void
    {
        $lines = [];
        foreach ($output as $line) {
            if (trim((string) $line) !== '') {
                $lines[] = INSTALLER_COMMAND_OUTPUT_PREFIX.$line;
            }
        }

        if ($lines === []) {
            return;
        }

        // 단계별(BufferEmitter) 경로는 **묶어서** 낸다 (D2 L6).
        //
        // 그 모드의 응답은 태스크가 끝난 뒤 한 번에 나가므로 줄 단위 실시간성이 없다. 그런데
        // 종전에는 줄마다 `emit('log')` 였고, 그 안에서 줄마다 `addLog()` 가 파일을
        // open/flock/write/fflush/close 한다 — 공유 호스팅 디스크에서 줄당 수 ms 다.
        // 실측(2026-09-11): 한 설치가 `[cmd]` 12,798 줄을 만들고 가장 긴 태스크가 3,844 줄을
        // 뱉는다. 그 태스크의 응답은 44.0 초가 걸렸고 그 사이 화면은 아무것도 받지 못했다.
        //
        // SSE 는 **그대로 둔다** — 그 모드의 존재 이유가 줄 단위 실시간 표시이고, 그쪽 줄은
        // 시간에 흩어져 도착하므로 같은 부하가 아니다. 폴링은 이미 배치(NullEmitter)다.
        $emitter = getProgressEmitter();
        if ($emitter instanceof BufferEmitter) {
            $emitter->emitLogBatch($lines);

            return;
        }

        foreach ($lines as $line) {
            sendSSEEvent('log', ['message' => $line]);
        }
    }
}

if (! function_exists('sendRollbackOutputSSE')) {
    /**
     * 롤백 실행 결과를 로그 이벤트로 출력합니다.
     *
     * @param  array  $rollbackResult  rollbackDbMigrate() 등의 반환값
     */
    function sendRollbackOutputSSE(array $rollbackResult): void
    {
        if (! empty($rollbackResult['output']) && is_array($rollbackResult['output'])) {
            sendCommandOutputSSE($rollbackResult['output']);
        }
    }
}

if (! function_exists('checkAbortStatusSSE')) {
    /**
     * 설치 중단 여부 확인 (SSE/폴링 공용).
     *
     * SSE 모드: 브라우저 연결 끊김 체크 + state.json aborted 체크.
     * 폴링 모드: state.json aborted 체크만 (연결 끊김은 항상 false).
     */
    function checkAbortStatusSSE(): bool
    {
        // 1. 브라우저 연결 확인 (SSE 모드 전용, NullEmitter는 항상 false 반환)
        if (getProgressEmitter()->isConnectionAborted()) {
            addLog(lang('abort_connection_lost'));
            $state = getInstallationState();

            $currentTask = $state['current_task'] ?? null;
            if ($currentTask && ! in_array($currentTask, $state['completed_tasks'] ?? [])) {
                addLog(lang('abort_rollback_start', ['task' => installerTaskKeyDisplayName((string) $currentTask)]));

                $rollbackResult = rollbackTask($currentTask, $state);

                if (! empty($rollbackResult['output']) && is_array($rollbackResult['output'])) {
                    $outputStr = implode("\n", $rollbackResult['output']);
                    addLog($outputStr);
                }

                if ($rollbackResult['success']) {
                    addLog(lang('abort_rollback_success', ['message' => $rollbackResult['message']]));
                } else {
                    addLog(lang('abort_rollback_failed', ['message' => $rollbackResult['message']]));

                    $dbTasks = ['db_migrate', 'db_seed'];
                    if (in_array($currentTask, $dbTasks)) {
                        addLog(lang('failed_rollback_manual_cleanup'));
                        addLog(lang('failed_rollback_manual_cleanup_detail'));
                    } else {
                        addLog(lang('failed_rollback_retry'));
                        addLog(lang('failed_rollback_retry_detail'));
                    }
                }
            } else {
                addLog(lang('abort_no_rollback_needed'));
            }

            // 중단 전이는 API 경로와 같은 SSoT 를 쓴다 (재로드 · 워커 lock 흔적 정리 · 비밀 제거) —
            // 두 경로가 다른 규칙을 쓰면 한쪽에서만 죽은 워커의 시간 예산이 남거나,
            // 롤백이 도는 동안 워커가 남긴 완료 마커가 사라진다 (12차 #A1).
            $rollbackFailure = (isset($rollbackResult) && ! $rollbackResult['success'])
                ? [
                    'task' => $currentTask,
                    'message' => $rollbackResult['message'] ?? null,
                    'message_key' => 'failed_rollback_manual_cleanup',
                    'detail_key' => 'failed_rollback_manual_cleanup_detail',
                ]
                : null;

            installerFinalizeAbortedState(
                installerApplyAbortedState(getInstallationState(), 'abort_reason_connection_lost'),
                $rollbackFailure,
            );

            return true;
        }

        // 2. state.json에서 중단 여부 확인
        $state = getInstallationState();
        if (isset($state['installation_status']) && $state['installation_status'] === 'aborted') {
            $currentTask = $state['current_task'] ?? 'unknown';
            addLog(lang('abort_by_user', ['task' => installerTaskKeyDisplayName((string) $currentTask)]));

            return true;
        }

        return false;
    }
}

// ============================================================================
// Task 함수 정의 (install-worker.php에서 이관됨 — 동작 동일)
// ============================================================================

// 실행 바이너리 경로 허용 형태 정책 — 인스톨러 API 와 동일 규칙을 공유한다.
require_once __DIR__.'/binary-path-policy.php';

if (! function_exists('getPhpBinary')) {
    /**
     * 설치 상태에 저장된 PHP 실행 경로를 돌려준다.
     *
     * 이 값은 실제 명령의 실행 바이너리가 되므로(설치 워커의 artisan 호출 등),
     * 형태 규칙을 통과하지 못하면 시스템 기본값으로 폴백한다 — 설치 흐름은 유지하되
     * 사용자 입력이 인자 자리로 흘러가지 않도록 한다. 형태 규칙은 installer_binary_path_shape_ok() 가
     * 소유한다 — 설치 완료 직후 .env 병합의 설정 캐시 다시 만들기(finalize-env.php)도 같은 규칙
     * (installer_php_binary_from_state())과 같은 후보 순서를 쓴다.
     */
    function getPhpBinary(): string
    {
        $state = getInstallationState();

        // 운영자 명시 > 설치 시작 시 자동 탐색된 경로(php_binary_resolved, D35) > 'php'
        static $rejectionLogged = [];

        foreach ([(string) ($state['config']['php_binary'] ?? ''), (string) ($state['php_binary_resolved'] ?? '')] as $candidate) {
            if ($candidate === '' || $candidate === 'php') {
                continue;
            }
            if (installer_binary_path_shape_ok($candidate)) {
                return $candidate;
            }

            // 형태 규칙에 걸려 버린 경로는 운영자에게 보여야 한다 — 조용히 'php' 로 떨어지면
            // 판정(subprocess 가능)과 실행(전 태스크 실패)이 어긋난 이유를 알 방법이 없다.
            // 이 함수는 커맨드마다 호출되므로 경로당 한 번만 남긴다.
            if (! isset($rejectionLogged[$candidate])) {
                $rejectionLogged[$candidate] = true;
                addLog(lang('log_php_binary_path_rejected', ['path' => $candidate]));
            }
        }

        return 'php';
    }
}

if (! function_exists('isInstallerExecutablePath')) {
    /**
     * 인스톨러가 exec 에 전달하기 안전한 단일 토큰 경로인지 검증한다.
     *
     * 판정은 공용 정책(binary-path-policy.php)이 소유한다 — 인스톨러 API 와 설치 워커가
     * 서로 다른 규칙을 쓰면 한쪽이 다른 쪽의 우회로가 된다.
     */
    function isInstallerExecutablePath(string $path): bool
    {
        return installer_binary_path_shape_ok($path);
    }
}

if (! function_exists('splitInstallerPhpComposerTokens')) {
    /**
     * 공백 분리 입력을 "PHP 인터프리터 절대경로 + Composer 바이너리 절대경로" 두 토큰으로 분해.
     *
     * 멀티 PHP 버전 환경(시놀로지 DSM Web Station, cPanel/Plesk multi-PHP) 의
     * 운영 의도를 지원한다. 자리별 규칙은 공용 정책이 담당한다.
     *
     * @return array{php: string, composer: string}|null 분해 실패 시 null
     */
    function splitInstallerPhpComposerTokens(string $path): ?array
    {
        return installer_resolve_php_composer_pair($path);
    }
}

if (! function_exists('getComposerCommand')) {
    function getComposerCommand(): string
    {
        $state = getInstallationState();
        $composerBinary = (string) ($state['config']['composer_binary'] ?? '');

        if ($composerBinary === '') {
            return 'composer';
        }

        // 공백 분리 입력 — 두 토큰으로 분해 후 각각 검증/escape.
        // 멀티 PHP 환경에서 특정 PHP 인터프리터로 composer 를 실행하려는 운영 의도 지원.
        if (str_contains($composerBinary, ' ')) {
            $tokens = splitInstallerPhpComposerTokens($composerBinary);
            if ($tokens === null) {
                return 'composer';
            }

            return escapeshellarg($tokens['php']).' '.escapeshellarg($tokens['composer']);
        }

        // 검증 실패 시 시스템 기본 'composer' 로 폴백 — 설치 흐름은 유지하되
        // 사용자 입력이 셸 명령이나 인자 자리로 흘러가지 않도록 차단.
        if (! installer_is_composer_binary_path($composerBinary)) {
            return 'composer';
        }

        if (str_ends_with(strtolower($composerBinary), '.phar')) {
            $phpBinary = getPhpBinary();
            $phpArg = ($phpBinary !== 'php' && isInstallerExecutablePath($phpBinary))
                ? escapeshellarg($phpBinary)
                : escapeshellarg('php');

            return $phpArg.' '.escapeshellarg($composerBinary);
        }

        return escapeshellarg($composerBinary);
    }
}

if (! function_exists('getComposerCommandForDisplay')) {
    function getComposerCommandForDisplay(): string
    {
        $state = getInstallationState();
        $composerBinary = (string) ($state['config']['composer_binary'] ?? '');

        if ($composerBinary === '') {
            return 'composer';
        }

        // 공백 분리 입력 — 토큰 검증 통과 시 사람 친화적 표기로 그대로 노출.
        if (str_contains($composerBinary, ' ')) {
            $tokens = splitInstallerPhpComposerTokens($composerBinary);
            if ($tokens === null) {
                return 'composer';
            }

            return $tokens['php'].' '.$tokens['composer'];
        }

        if (! installer_is_composer_binary_path($composerBinary)) {
            return 'composer';
        }

        if (str_ends_with(strtolower($composerBinary), '.phar')) {
            return getPhpBinary().' '.$composerBinary;
        }

        return $composerBinary;
    }
}

if (! function_exists('vendorModeFromState')) {
    /**
     * 인스톨러 state 의 vendor_mode 를 확장 설치 커맨드의 `--vendor-mode` 값으로 돌려줍니다.
     *
     * 사용자가 Step 3 에서 선택한 vendor_mode 를 module:install/plugin:install
     * 등 확장 설치 커맨드로 일관되게 전파하기 위함 (R10). 유효 실행 모드가 inprocess 면
     * bundled 로 고정된다 (A-4) — composer 는 그 환경에서 성립하지 않는다.
     *
     * @return string|null auto|composer|bundled. 미설정 시 null (옵션 생략).
     */
    function vendorModeFromState(): ?string
    {
        $state = getInstallationState();
        $mode = $state['config']['vendor_mode'] ?? '';

        if (! in_array($mode, ['auto', 'composer', 'bundled'], true)) {
            return null;
        }

        return installerCoerceVendorModeForExecution($mode, installerEffectiveExecutionMode($state));
    }
}

if (! function_exists('vendorModeOptionFromState')) {
    /**
     * vendorModeFromState() 를 Artisan::call 규약 파라미터 배열로 돌려줍니다.
     *
     * @return array<string, string> ['--vendor-mode' => 'bundled'] 또는 빈 배열
     */
    function vendorModeOptionFromState(): array
    {
        $mode = vendorModeFromState();

        return $mode === null ? [] : ['--vendor-mode' => $mode];
    }
}

if (! function_exists('checkComposerSSE')) {
    /**
     * Composer 가용성 확인.
     *
     * vendor_mode 분기:
     * - bundled  → 즉시 스킵 (composer 불필요)
     * - composer → 검사 실패 시 에러 (사용자가 명시적으로 composer 모드 선택)
     * - auto     → 검사 실패 시 경고만 (installComposerDependenciesSSE 가 폴백 처리)
     */
    function checkComposerSSE(): array
    {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang('task_composer_check');

        updateCurrentTask('composer_check');
        sendSSEEvent('task_start', ['task' => 'composer_check', 'name' => $taskName]);

        $vendorMode = vendorModeFromState() ?? 'auto';

        // bundled 모드: composer 불필요 — 즉시 스킵
        if ($vendorMode === 'bundled') {
            sendSSEEvent('log', ['message' => lang('log_composer_check_skipped_bundled')]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);
            markTaskCompleted('composer_check');
            sendSSEEvent('task_complete', ['task' => 'composer_check', 'message' => lang('log_composer_check_skipped_bundled')]);

            return ['success' => true];
        }

        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        $composerHome = BASE_PATH.'/storage/composer';
        if (! is_dir($composerHome)) {
            @mkdir($composerHome, 0755, true);
        }
        // 무가드 putenv 는 disable_functions 환경에서 Error 다 (13차 환경#2) — 단일 지점 경유.
        installerPutEnv('COMPOSER_HOME', $composerHome);
        installerPutEnv('HOME', $composerHome);
        applyInstallerComposerEnvVars();

        $output = [];
        $returnCode = 0;
        $composerCmd = getComposerCommand();

        // exec 가 막힌 환경에서는 composer 가용성 자체를 확인할 수 없다 — "미설치" 와 같은
        // 분기로 보낸다 (auto 는 번들 폴백, composer 명시는 오류). 직접 호출하면 fatal 이다.
        if (! ExecutionEnvironment::hasFunction('exec')) {
            $returnCode = 1;
            $output = [lang('error_subprocess_unavailable')];
        } else {
            exec($composerCmd.' --version 2>&1', $output, $returnCode);
        }

        // 이 판정을 기록해 둔다 — 다음 태스크(composer_install)의 auto 분기가 같은 답을 써야
        // 한다. 각자 판정하면 "check 는 초록인데 install 은 composer 를 스폰" 같은 어긋남이
        // 생기고, 그 태스크는 재시도해도 같은 자리에서 막힌다.
        $composerState = getInstallationState();
        $composerState['composer_available'] = ($returnCode === 0);
        saveInstallationState($composerState);

        if ($returnCode !== 0) {
            $errorMessage = implode("\n", $output);

            // auto 모드: composer 미설치 시 번들 폴백 가능하면 비치명적 처리.
            // 「번들이 있다」가 아니라 「풀 수 있다」를 본다 (13차 환경#4) — ZipArchive 가 없는
            // 호스팅에서 파일 존재만으로 넘어가면 composer 부재를 초록으로 지나친 뒤 다음
            // 태스크가 풀 수 없는 번들 앞에서 멈춘다. 판정은 installerVendorBundleUsable() 소유.
            if ($vendorMode === 'auto' && installerVendorBundleUsable()) {
                sendSSEEvent('log', ['message' => lang('log_composer_check_auto_fallback')]);
                sendSSEEvent('log', ['message' => lang('log_separator')]);
                markTaskCompleted('composer_check');
                sendSSEEvent('task_complete', ['task' => 'composer_check', 'message' => lang('log_composer_check_auto_fallback')]);

                return ['success' => true];
            }

            sendSSEEvent('log', ['message' => lang('log_error_occurred', ['error' => $errorMessage])]);
            logInstallationError(lang('error_composer_not_installed'));

            return [
                'success' => false,
                'message' => lang('error_composer_not_installed'),
                'message_key' => 'error_composer_not_installed',
                'detail' => $errorMessage,
            ];
        }

        sendCommandOutputSSE($output);

        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('composer_check');
        sendSSEEvent('task_complete', ['task' => 'composer_check', 'message' => lang('log_composer_check_success')]);

        return ['success' => true];
    }
}

if (! function_exists('runCustomVendorStepSSE')) {
    /**
     * 개발자 전용 composer(`custom/`, 7.2.0)의 vendor 를 코어 vendor 단계 끝에서 맞춥니다.
     *
     * custom/composer.json 이 없으면 아무 일도 하지 않는다(일반 설치). 실패하면 설치를 멈춘다 —
     * 개발자 화면이 쓰는 패키지가 없으면 그 화면이 500 이 되기 때문이다.
     *
     * @param  bool  $useBundle  코어와 같은 판정으로 묶음(vendor-bundle.zip)을 쓸지 여부
     * @return array|null 실패 결과(설치 중단), 성공·건너뜀이면 null
     */
    function runCustomVendorStepSSE(bool $useBundle): ?array
    {
        require_once __DIR__.'/vendor-bundle-installer.php';

        $composerRunner = function (string $workingDir): bool {
            // composer 실행은 proc_open 을 요구한다 — 막힌 호스팅에서 호출하면 fatal 이므로 실패로 돌려준다
            // (코어 vendor 단계 installComposerDependenciesSSE 와 같은 가드).
            if (! ExecutionEnvironment::hasFunction('proc_open')) {
                return false;
            }

            $composerHome = BASE_PATH.'/storage/composer';
            if (! is_dir($composerHome)) {
                @mkdir($composerHome, 0755, true);
            }
            $env = [];
            foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'APPDATA', 'LOCALAPPDATA', 'USERPROFILE'] as $key) {
                // getenv 가 막힌 호스팅에서도 $_ENV/$_SERVER 로 읽는다 (코어 vendor 단계와 같은 규칙).
                $value = installerReadEnv($key);
                if ($value !== null) {
                    $env[$key] = $value;
                }
            }
            $env['COMPOSER_HOME'] = $composerHome;
            $env['HOME'] = $composerHome;
            $env = array_merge($env, buildInstallerComposerEnv());

            $process = proc_open(
                getComposerCommand().' install --no-interaction --no-dev --optimize-autoloader --no-ansi 2>&1',
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $workingDir,
                $env
            );
            if (! is_resource($process)) {
                return false;
            }
            fclose($pipes[0]);
            while (($line = fgets($pipes[1])) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    sendSSEEvent('log', ['message' => '[custom] '.$line]);
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($process) === 0;
        };

        // 코어가 composer 로 설치됐어도 auto 모드에서 composer 를 못 쓰는 서버면 custom 묶음으로 푼다.
        // 방식은 코어 단계와 같은 지점(vendorModeFromState — 실행 모드 보정 포함)에서 읽는다.
        $state = getInstallationState();
        $vendorMode = vendorModeFromState() ?? 'auto';
        if (! $useBundle && $vendorMode === 'auto'
            && file_exists(BASE_PATH.'/custom/vendor-bundle.zip')
            && ! canExecuteComposerForInstall($state['config']['composer_binary'] ?? '', $state['config']['php_binary'] ?? 'php')) {
            $useBundle = true;
        }

        $result = installCustomVendorIfNeeded(BASE_PATH, $useBundle, $composerRunner);
        if (! empty($result['skipped'])) {
            if (($result['mode'] ?? '') === 'in-sync') {
                sendSSEEvent('log', ['message' => lang('log_custom_vendor_in_sync')]);
            }

            return null;
        }
        if (! $result['success']) {
            $error = $result['error'] ?? 'unknown';
            sendSSEEvent('log', ['message' => lang('error_custom_vendor_failed', ['error' => $error])]);
            logInstallationError('custom vendor: '.$error);

            return ['success' => false, 'message' => lang('error_custom_vendor_failed', ['error' => $error])];
        }
        sendSSEEvent('log', ['message' => lang('log_custom_vendor_installed', ['mode' => $result['mode'] ?? ''])]);

        return null;
    }
}

if (! function_exists('installVendorBundleSSE')) {
    /**
     * vendor-bundle.zip 을 추출하여 vendor/ 를 구성합니다 (bundled 모드용).
     */
    function installVendorBundleSSE(): array
    {
        require_once __DIR__.'/vendor-bundle-installer.php';

        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang('task_vendor_bundle_extract');
        updateCurrentTask('composer_install');
        sendSSEEvent('task_start', ['task' => 'composer_install', 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_vendor_bundle_extract_start')]);

        $integrity = verifyVendorBundle(BASE_PATH);
        if (! $integrity['valid']) {
            $errorList = implode(', ', $integrity['errors']);
            sendSSEEvent('log', ['message' => lang('log_vendor_bundle_integrity_failed', ['errors' => $errorList])]);
            logInstallationError(lang('error_vendor_bundle_integrity_failed', ['errors' => $errorList]));

            return [
                'success' => false,
                'message' => lang('error_vendor_bundle_integrity_failed', ['errors' => $errorList]),
                'message_key' => 'error_vendor_bundle_integrity_failed',
                'message_params' => ['errors' => $errorList],
            ];
        }

        $packageCount = (int) ($integrity['meta']['package_count'] ?? 0);
        sendSSEEvent('log', ['message' => lang('log_vendor_bundle_verified', ['count' => (string) $packageCount])]);
        sendSSEEvent('log', ['message' => lang('log_vendor_bundle_extracting')]);

        // 추출은 설치에서 가장 오래 걸리는 태스크다(P0 실측 ≈10~15s). 그 구간에 emit 이 하나도
        // 없으면 워커 heartbeat 가 멈춰, 새로고침한 요청이 아직 살아 있는 이 요청을 stale 로 보고
        // 탈취한다 — 두 요청이 같은 vendor/ 를 동시에 옮기고 푼다 (10차 #M2). heartbeat 이벤트는
        // 클라이언트가 화면 변화 없이 흘려보내므로(installation-monitor `_replay` 기본 분기)
        // 진행 기록을 어지럽히지 않으면서 점유가 살아 있음만 갱신한다.
        $result = extractVendorBundle(BASE_PATH, BASE_PATH, static function (int $done, int $total): void {
            sendSSEEvent('heartbeat', ['done' => $done, 'total' => $total]);
        });

        if (! $result['success']) {
            $error = $result['error'] ?? 'unknown';
            sendSSEEvent('log', ['message' => lang('error_vendor_bundle_extract_failed', ['error' => $error])]);
            logInstallationError(lang('error_vendor_bundle_extract_failed', ['error' => $error]));

            return [
                'success' => false,
                'message' => lang('error_vendor_bundle_extract_failed', ['error' => $error]),
                'message_key' => 'error_vendor_bundle_extract_failed',
                'message_params' => ['error' => $error],
            ];
        }

        sendSSEEvent('log', ['message' => lang('log_vendor_bundle_extract_done', ['count' => (string) $result['package_count']])]);

        // 이전 환경(특히 dev) 의 stale 캐시 정리 — 미정리 시 제거된 dev 패키지의
        // ServiceProvider 를 후속 artisan 호출(key:generate 등) 에서 참조하다가 실패한다.
        $cleared = clearLaravelCompiledCache(BASE_PATH);
        if (! empty($cleared)) {
            sendSSEEvent('log', ['message' => lang('log_composer_cache_cleared')]);
        }

        sendSSEEvent('log', ['message' => lang('log_separator')]);

        if (($customFailure = runCustomVendorStepSSE(true)) !== null) {
            return $customFailure;
        }

        markTaskCompleted('composer_install');
        sendSSEEvent('task_complete', ['task' => 'composer_install', 'message' => lang('log_vendor_bundle_extract_complete')]);

        return ['success' => true, 'mode' => 'bundled'];
    }
}

if (! function_exists('installComposerDependenciesSSE')) {
    function installComposerDependenciesSSE(): array
    {
        // Laravel compiled cache 정리 헬퍼(clearLaravelCompiledCache)가 정의된 shim
        require_once __DIR__.'/vendor-bundle-installer.php';

        // Vendor 모드에 따라 분기: bundled → vendor-bundle.zip 추출
        // (inprocess 유효 모드면 vendorModeFromState 가 bundled 로 고정한다 — A-4)
        $state = getInstallationState();
        $vendorMode = vendorModeFromState() ?? 'auto';

        if ($vendorMode === 'bundled') {
            return installVendorBundleSSE();
        }

        if ($vendorMode === 'auto') {
            // composer 가능 여부에 따라 자동 분기
            $composerBinary = $state['config']['composer_binary'] ?? '';
            $phpBinary = $state['config']['php_binary'] ?? 'php';

            if (! canExecuteComposerForInstall($composerBinary, $phpBinary)) {
                // composer 사용 불가 → 번들을 **쓸 수 있을 때만** 폴백 (13차 환경#4)
                if (installerVendorBundleUsable()) {
                    sendSSEEvent('log', ['message' => lang('log_composer_unavailable_fallback_bundle')]);

                    return installVendorBundleSSE();
                }
            }
        }

        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang('task_composer_install');

        updateCurrentTask('composer_install');
        sendSSEEvent('task_start', ['task' => 'composer_install', 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        // composer 실행은 proc_open 을 요구한다 — 막힌 환경에서 호출하면 fatal 이므로
        // 여기서 명확한 실패로 끝낸다 (R9: 모드가 관여하는 지점은 모드를 읽는다).
        if (! ExecutionEnvironment::hasFunction('proc_open')) {
            logInstallationError(lang('error_composer_install_failed'));

            return [
                'success' => false,
                'message' => lang('error_composer_install_failed'),
                'message_key' => 'error_composer_install_failed',
                'detail' => lang('error_subprocess_unavailable'),
            ];
        }

        $vendorExists = is_dir(BASE_PATH.'/vendor') && file_exists(BASE_PATH.'/vendor/autoload.php');
        $lockExists = file_exists(BASE_PATH.'/composer.lock');

        if ($vendorExists && $lockExists) {
            // 이미 준비된 vendor 를 그대로 쓴다. 그 vendor 가 개발용(require-dev 포함) 설치면
            // 설치는 계속하되 운영자에게 알린다 — 그대로 두면 이후 코어 업데이트가 vendor 를
            // --no-dev 로 교체할 때 이전 매니페스트에만 남은 provider 를 찾다 부팅이 깨진다.
            $devInfo = detectDevVendorInstall(BASE_PATH);
            if ($devInfo['dev'] === true) {
                sendSSEEvent('log', ['message' => lang('log_composer_dev_packages_detected', [
                    'count' => count($devInfo['packages']),
                    'packages' => implode(', ', array_slice($devInfo['packages'], 0, 5)).(count($devInfo['packages']) > 5 ? ' …' : ''),
                ])]);
                // sendSSEEvent('log') 는 progress emitter 안에서 installation.log 에도 기록한다
                // (SSE·폴링 두 모드 공통) — 여기서 addLog 를 또 부르면 로그에 같은 줄이 두 번 남는다.
                sendSSEEvent('log', ['message' => lang('warning_composer_dev_packages_kept')]);
            } else {
                sendSSEEvent('log', ['message' => lang('log_composer_already_installed')]);
            }

            // 재사용 경로에서도 이전 환경의 컴파일 캐시를 정리한다 — 종전에는 정리 없이
            // return 해, key_generate 가 이미 완료된 재개 설치에서는 어디서도 정리되지 않았다.
            if (! empty(clearLaravelCompiledCache(BASE_PATH))) {
                sendSSEEvent('log', ['message' => lang('log_composer_cache_cleared')]);
            }

            sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            if (($customFailure = runCustomVendorStepSSE(false)) !== null) {
                return $customFailure;
            }

            markTaskCompleted('composer_install');
            sendSSEEvent('task_complete', ['task' => 'composer_install', 'message' => lang('log_composer_already_installed')]);

            return ['success' => true];
        }

        if ($vendorExists && ! $lockExists) {
            sendSSEEvent('log', ['message' => lang('log_composer_vendor_without_lock')]);
            sendSSEEvent('log', ['message' => lang('log_composer_removing_vendor')]);

            $deleted = deleteDirectory(BASE_PATH.'/vendor');
            if (! $deleted) {
                sendSSEEvent('log', ['message' => lang('log_composer_vendor_delete_failed')]);
            } else {
                sendSSEEvent('log', ['message' => lang('log_composer_vendor_deleted')]);
            }
        }

        if (! $vendorExists && $lockExists) {
            sendSSEEvent('log', ['message' => lang('log_composer_installing_from_lock')]);
        }

        if (! $vendorExists && ! $lockExists) {
            sendSSEEvent('log', ['message' => lang('log_composer_fresh_install')]);
        }

        // packages.php / services.php / config.php 모두 정리 — 이전 환경의 캐시가 남아있으면
        // composer post-autoload-dump 단계의 package:discover 가 부팅 중 stale provider 를
        // 참조하여 실패한다.
        $cleared = clearLaravelCompiledCache(BASE_PATH);
        if (! empty($cleared)) {
            sendSSEEvent('log', ['message' => lang('log_composer_cache_cleared')]);
        }

        chdir(BASE_PATH);

        $composerHome = BASE_PATH.'/storage/composer';
        if (! is_dir($composerHome)) {
            @mkdir($composerHome, 0755, true);
        }

        $env = [];
        foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'APPDATA', 'LOCALAPPDATA', 'USERPROFILE'] as $key) {
            // getenv 가 막힌 호스팅에서도 $_ENV/$_SERVER 로 읽는다 (installerPutEnv 와 짝).
            $value = installerReadEnv($key);
            if ($value !== null) {
                $env[$key] = $value;
            }
        }
        $env['COMPOSER_HOME'] = $composerHome;
        $env['HOME'] = $composerHome;

        // root/super user 환경 + 비대화형 컨텍스트 대응 (Synology DSM 등 PHP-FPM root 실행)
        $env = array_merge($env, buildInstallerComposerEnv());

        // 판정은 functions.php 의 isWindows() 하나다 — 사본은 Linux CI 에서 평가되지 않는다.
        if (isWindows()) {
            // OS 환경변수 경로는 문서루트 밖이다 — open_basedir 환경에서 이 stat 의 warning 이
            // SSE 프레임 경계를 깨면 진행 이벤트가 유실된다. 판정은 옳고 진단만 억제한다
            // (20차 #8 규칙의 형제, 21차 #21-5).
            if (! isset($env['TEMP']) || ! @is_dir($env['TEMP']) || ! @is_writable($env['TEMP'])) {
                $tempDir = BASE_PATH.'/storage/temp';
                if (! is_dir($tempDir)) {
                    @mkdir($tempDir, 0755, true);
                }
                $env['TEMP'] = $tempDir;
                $env['TMP'] = $tempDir;
            }
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $composerCmd = getComposerCommand();
        $process = proc_open(
            $composerCmd.' install --no-interaction --no-dev --optimize-autoloader --no-ansi 2>&1',
            $descriptorspec,
            $pipes,
            BASE_PATH,
            $env
        );

        if (! is_resource($process)) {
            logInstallationError(lang('error_composer_install_failed'));

            return [
                'success' => false,
                'message' => lang('error_composer_install_failed'),
                'message_key' => 'error_composer_install_failed',
                'detail' => lang('error_composer_process_failed'),
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        while (! feof($pipes[1])) {
            if (checkAbortStatusSSE()) {
                proc_terminate($process);
                proc_close($process);

                return ['success' => false, 'aborted' => true];
            }

            $line = fgets($pipes[1]);
            if ($line !== false) {
                $line = trim($line);
                // composer stdout 은 시스템 코드페이지(Windows CP949 등)로 출력되고,
                // 진행바 갱신이 non-blocking 읽기와 겹치면 임의 바이트 경계에서 잘려
                // invalid UTF-8 바이트가 섞인다. SSE 응답(progress-emitter)과
                // 폴링 응답(state-management) 양쪽이 무력화되지 않도록 전송 전 정규화한다.
                //
                // 정규화는 복원 가능한 코드페이지 출력(한글 경로·메시지)을 먼저 되살리므로,
                // 이전의 mb_scrub 단독 처리와 달리 한글이 U+FFFD 로 훼손되지 않는다.
                // (gnuboard/g7#62 — addLog 최종 방어와 이중 안전망)
                $line = installer_utf8_normalize($line);
                if (! empty($line)) {
                    sendSSEEvent('log', ['message' => $line]);
                }
            }
            usleep(100000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            logInstallationError(lang('error_composer_install_failed'));

            return [
                'success' => false,
                'message' => lang('error_composer_install_failed'),
                'message_key' => 'error_composer_install_failed',
                'detail' => lang('error_composer_exit_code', ['code' => $returnCode]),
            ];
        }

        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        if (($customFailure = runCustomVendorStepSSE(false)) !== null) {
            return $customFailure;
        }

        markTaskCompleted('composer_install');
        sendSSEEvent('task_complete', ['task' => 'composer_install', 'message' => lang('log_composer_install_success')]);

        return ['success' => true];
    }
}

if (! function_exists('updateEnvFileSSE')) {
    /**
     * 동적 설정(DB 자격증명) 을 storage/installer/runtime.php 에 작성한다.
     *
     * php artisan serve 의 .env mtime 워처가 진행 중 재시작을 일으키지 않도록
     * 설치 진행 중에는 .env 를 직접 수정하지 않는다. runtime.php 는 부팅 시
     * InstallerRuntimeServiceProvider 가 읽어 config 에 주입하므로 mysql
     * 마이그레이션/시더가 정상 동작한다.
     *
     * 설치 완료 UI 노출 후 finalize-env.php 가 runtime.php → .env 머지를 1회 수행한다.
     *
     * @return array{success: bool, message?: string, message_key?: string}
     */
    function updateEnvFileSSE(): array
    {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang('task_env_update');

        updateCurrentTask('env_update');
        sendSSEEvent('task_start', ['task' => 'env_update', 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        // .env.example 존재 여부만 확인 — finalize 단계에서 generateEnvContent() 가 사용
        $envExamplePath = BASE_PATH.'/.env.example';
        if (! file_exists($envExamplePath)) {
            logInstallationError(lang('error_env_example_not_found'));

            return [
                'success' => false,
                'message' => lang('error_env_example_not_found'),
                'message_key' => 'error_env_example_not_found',
            ];
        }

        $state = getInstallationState();
        $stateConfig = is_array($state['config'] ?? null) ? $state['config'] : [];

        $runtime = buildInstallerRuntimeFromState($stateConfig);

        if (! writeInstallerRuntime($runtime)) {
            logInstallationError(lang('error_env_write_failed', ['path' => INSTALLER_RUNTIME_PATH]));

            return [
                'success' => false,
                'message' => lang('error_env_write_failed', ['path' => INSTALLER_RUNTIME_PATH]),
                'message_key' => 'error_env_write_failed',
                'message_params' => ['path' => INSTALLER_RUNTIME_PATH],
            ];
        }

        sendSSEEvent('log', ['message' => lang('log_env_update_success')]);
        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('env_update');
        sendSSEEvent('task_complete', ['task' => 'env_update', 'message' => lang('log_env_update_success')]);

        return ['success' => true];
    }
}

if (! function_exists('generateApplicationKeySSE')) {
    /**
     * APP_KEY 를 pure PHP 로 생성하여 runtime.php 에 기록한다.
     *
     * 기존: php artisan key:generate --force (artisan 이 .env 직접 수정).
     * 신규: random_bytes(32) + base64 → runtime.php 의 'app.key' 키로 기록.
     *       Laravel 의 Encrypter::generateKey('AES-256-CBC') 와 동일.
     *
     * runtime.php 에 이미 키가 있으면 보존 (재시도 시 키 변경 방지).
     *
     * @return array{success: bool, message?: string, message_key?: string}
     */
    function generateApplicationKeySSE(): array
    {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        // 첫 in-process 부팅(db_migrate) 앞에서 stale bootstrap/cache 를 비운다 (R11).
        // 이 태스크가 completed 면 runSingleInstallationTask 가 함수 진입 자체를 막으므로
        // 재시도 경로의 보장은 아니다 — 그 구간은 정리 이후 캐시를 stale 로 만드는 변화가
        // 없다는 사실에 기대고 있고, 그 판단 근거는 검증 라운드 기록에 남아 있다.
        require_once __DIR__.'/vendor-bundle-installer.php';
        clearLaravelCompiledCache(BASE_PATH);

        $taskName = lang('task_key_generate');

        updateCurrentTask('key_generate');
        sendSSEEvent('task_start', ['task' => 'key_generate', 'name' => $taskName]);

        // runtime.php 에 키가 이미 있으면 그대로 둠 (재시도 안전성)
        $runtime = readInstallerRuntime();
        $existingKey = $runtime['app']['key'] ?? null;

        if (is_string($existingKey) && str_starts_with($existingKey, 'base64:') && strlen($existingKey) >= 47) {
            sendSSEEvent('log', ['message' => lang('log_already_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            markTaskCompleted('key_generate');
            sendSSEEvent('task_complete', ['task' => 'key_generate', 'message' => lang('log_key_generate_success')]);

            return ['success' => true];
        }

        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        // pure PHP 키 생성 — .env 무수정
        $key = generateAppKeyInline();

        $runtime = is_array($runtime) ? $runtime : [];
        $runtime['app']['key'] = $key;
        $runtime['created_at'] = $runtime['created_at'] ?? date('c');

        if (! writeInstallerRuntime($runtime)) {
            logInstallationError(lang('error_key_generate_failed'));

            return [
                'success' => false,
                'message' => lang('error_key_generate_failed'),
                'message_key' => 'error_key_generate_failed',
            ];
        }

        sendSSEEvent('log', ['message' => lang('log_key_generate_success')]);
        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('key_generate');
        sendSSEEvent('task_complete', ['task' => 'key_generate', 'message' => lang('log_key_generate_success')]);

        return ['success' => true];
    }
}

if (! function_exists('installerSelectedDependencyMap')) {
    /**
     * 선택된 확장의 의존 관계 그래프 (identifier → 의존 대상 목록).
     *
     * 사전 검증(dependencyPrecheckSSE)과 건너뛰기 파급 판정이 같은 그래프를 본다 — 사전
     * 검증은 「설치 전에 한 번」만 판정하므로, 그 뒤 [건너뛰고 계속] 이 의존 대상을 지나가면
     * 그 판정은 더 이상 사실이 아니다. 두 지점이 각자 매니페스트를 읽으면 한쪽만 고쳐진다.
     *
     * @return array<string, array<int, string>> identifier → 의존 대상 identifier 목록
     */
    function installerSelectedDependencyMap(): array
    {
        $selected = getSelectedExtensions();
        $selectedIds = array_merge(
            $selected['admin_templates'] ?? [],
            $selected['user_templates'] ?? [],
            $selected['modules'] ?? [],
            $selected['plugins'] ?? []
        );
        $selectedIdSet = array_flip($selectedIds);

        $scanTargets = [
            'modules' => BASE_PATH.'/modules/_bundled',
            'plugins' => BASE_PATH.'/plugins/_bundled',
            'templates' => BASE_PATH.'/templates/_bundled',
        ];

        $map = [];

        foreach ($scanTargets as $type => $baseDir) {
            if (! is_dir($baseDir)) {
                continue;
            }

            foreach (scandir($baseDir) ?: [] as $dir) {
                if ($dir === '.' || $dir === '..') {
                    continue;
                }

                $manifestFile = match ($type) {
                    'modules' => "{$baseDir}/{$dir}/module.json",
                    'plugins' => "{$baseDir}/{$dir}/plugin.json",
                    default => "{$baseDir}/{$dir}/template.json",
                };

                if (! file_exists($manifestFile)) {
                    continue;
                }

                $data = json_decode((string) @file_get_contents($manifestFile), true);
                if (! is_array($data)) {
                    continue;
                }

                $identifier = $data['identifier'] ?? $dir;
                if (! isset($selectedIdSet[$identifier])) {
                    continue;
                }

                $deps = $data['dependencies'] ?? [];
                if (! is_array($deps)) {
                    continue;
                }

                $check = [];
                if (isset($deps['modules']) && is_array($deps['modules'])) {
                    foreach ($deps['modules'] as $depId => $value) {
                        $check[] = is_int($depId) ? $value : $depId;
                    }
                }
                if (isset($deps['plugins']) && is_array($deps['plugins'])) {
                    foreach ($deps['plugins'] as $depId => $value) {
                        $check[] = is_int($depId) ? $value : $depId;
                    }
                }
                if (! isset($deps['modules']) && ! isset($deps['plugins'])) {
                    foreach ($deps as $depId) {
                        if (is_string($depId)) {
                            $check[] = $depId;
                        }
                    }
                }

                $map[(string) $identifier] = array_values(array_unique(array_map('strval', $check)));
            }
        }

        return $map;
    }
}

if (! function_exists('installerDependentsOfSkippedTarget')) {
    /**
     * 건너뛴 확장에 의존하는 다른 선택 확장을 갈라 돌려준다.
     *
     * 설치 순서는 모듈 전량 → 플러그인 전량이므로, 의존 대상이 뒤에 오는 조합에서는
     * 의존하는 쪽이 **이미 설치된 뒤**에 그 대상이 실패한다. 그 경우는 되돌릴 수 없으므로
     * 운영자에게 알리고(already), 아직 실행되지 않은 것은 함께 지나간다(pending).
     *
     * 파급은 **고정점까지** 간다 (12차 #A10). 직접 의존 1단계만 보면 A → B → C 사슬에서
     * C 를 건너뛸 때 B 는 지나가지만 A 는 그대로 설치·활성화되어, 의존이 깨진 확장이
     * 「완료」된 채 남는다. 현행 번들 확장이 전부 깊이 1 이라 잠복해 있었을 뿐이다.
     * 다만 **이미 설치가 끝난** 의존 확장(already)에서는 멈춘다 — 그 확장은 지나간 것이
     * 아니라 설치되어 있으므로, 그것에 의존하는 확장의 의존은 여전히 충족된다.
     *
     * 방문 집합으로 유한 종료를 보장한다 — 오염된 매니페스트의 순환 의존에서도 멈춘다.
     *
     * @param  string  $skippedTarget  건너뛴 확장 identifier
     * @param  array<int, string>  $completedKeys  현재 완료 마커
     * @return array{pending: array<int, string>, already: array<int, string>} 지나갈 태스크 키 / 이미 설치된 의존 확장
     */
    function installerDependentsOfSkippedTarget(string $skippedTarget, array $completedKeys): array
    {
        $pending = [];
        $already = [];

        $map = installerSelectedDependencyMap();
        $tasks = buildInstallationTaskList();

        $queue = [$skippedTarget];
        $seen = [$skippedTarget => true];

        while ($queue !== []) {
            $current = (string) array_shift($queue);

            foreach ($map as $identifier => $deps) {
                if (isset($seen[$identifier]) || ! in_array($current, $deps, true)) {
                    continue;
                }

                $seen[$identifier] = true;

                $keys = [];
                foreach ($tasks as $task) {
                    if (($task['target'] ?? null) === $identifier) {
                        $keys[] = (string) $task['key'];
                    }
                }

                $unfinished = array_values(array_filter($keys, fn (string $k): bool => ! in_array($k, $completedKeys, true)));

                if ($unfinished === []) {
                    $already[] = $identifier;

                    continue;
                }

                foreach ($unfinished as $key) {
                    $pending[] = $key;
                }

                // 이 확장도 지나가므로, 이것에 의존하는 확장까지 따라간다
                $queue[] = (string) $identifier;
            }
        }

        return ['pending' => array_values(array_unique($pending)), 'already' => array_values(array_unique($already))];
    }
}

if (! function_exists('dependencyPrecheckSSE')) {
    /**
     * 의존성 사전 검증 (백엔드 안전망).
     *
     * 선택된 모든 확장의 manifest를 직접 읽어 의존성 그래프를 검증합니다.
     * 프론트엔드 검증을 우회한 경우에도 마이그레이션/활성화 작업 시작 전에 차단합니다.
     */
    function dependencyPrecheckSSE(): array
    {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang('task_dependency_precheck');

        updateCurrentTask('dependency_precheck');
        sendSSEEvent('task_start', ['task' => 'dependency_precheck', 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        $selected = getSelectedExtensions();
        $selectedIds = array_merge(
            $selected['admin_templates'] ?? [],
            $selected['user_templates'] ?? [],
            $selected['modules'] ?? [],
            $selected['plugins'] ?? []
        );
        $selectedIdSet = array_flip($selectedIds);

        $missing = [];

        // 그래프는 installerSelectedDependencyMap() 이 소유한다 — 건너뛰기 파급 판정과 같은
        // 그래프를 봐야 「설치 전 한 번」의 판정이 그 뒤 조작으로 무효가 되는 것을 잡는다.
        foreach (installerSelectedDependencyMap() as $identifier => $deps) {
            foreach ($deps as $depId) {
                if (! isset($selectedIdSet[$depId])) {
                    $missing[] = "{$identifier} → {$depId}";
                }
            }
        }

        if (! empty($missing)) {
            $errorMessage = lang('dependency_precheck_failed');
            foreach ($missing as $line) {
                sendSSEEvent('log', ['message' => '  - '.$line]);
            }
            logInstallationError($errorMessage);

            return [
                'success' => false,
                'message' => $errorMessage,
                'message_key' => 'dependency_precheck_failed',
                'detail' => implode("\n", $missing),
            ];
        }

        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('dependency_precheck');
        sendSSEEvent('task_complete', ['task' => 'dependency_precheck', 'message' => $taskName]);

        return ['success' => true];
    }
}

if (! function_exists('cleanupExistingTablesSSE')) {
    /**
     * 기존 DB 테이블 정리.
     *
     * state.json의 existing_db_action 값에 따라 기존 테이블을 삭제합니다.
     * - skip (또는 null): 작업 없이 건너뛰기
     * - drop_tables: FOREIGN_KEY_CHECKS=0 → db_prefix 로 시작하는 테이블만 DROP → FOREIGN_KEY_CHECKS=1
     *
     * prefix 없는 타 테이블까지 삭제하던 결함 수정. db_prefix 로 시작하는
     * 테이블만 선별 삭제하며, 빈 prefix 는 데이터 손실 방어로 삭제를 수행하지 않는다.
     */
    function cleanupExistingTablesSSE(): array
    {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang('task_db_cleanup');

        updateCurrentTask('db_cleanup');
        sendSSEEvent('task_start', ['task' => 'db_cleanup', 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        $state = getInstallationState();
        $action = $state['existing_db_action'] ?? 'skip';

        if ($action !== 'drop_tables') {
            sendSSEEvent('log', ['message' => lang('log_db_cleanup_skipped')]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);
            markTaskCompleted('db_cleanup');
            sendSSEEvent('task_complete', ['task' => 'db_cleanup', 'message' => lang('log_db_cleanup_skipped')]);

            return ['success' => true];
        }

        // 파괴 작업 직전 재검증 — 이 함수는 세션이 없는 워커에서도 돈다. 소유 게이트를 통과한
        // install-process 가 서명한 계획이고, 그 요청이 삭제 확인 토큰을 소비했을 때만 DROP 한다.
        // 조건이 깨졌으면 조용히 건너뛰지 않고 실패시킨다 — 건너뛰면 migrate 가 1050(테이블 존재)로
        // 실패해 원인이 가려진다. 재시도는 install-process 재서명을 거친다.
        $planVerdict = installer_plan_verify($state, readInstallerRuntime());
        $dropConfirmed = is_array($state['drop_confirm'] ?? null) && ! empty($state['drop_confirm']['consumed']);
        if ($planVerdict !== 'ok' || ! $dropConfirmed) {
            $reason = $planVerdict !== 'ok' ? $planVerdict : 'drop_not_confirmed';
            addLog('[db_cleanup] 설치 계획 검증 실패 — DROP 미실행: '.$reason);

            return [
                'success' => false,
                'message' => lang('error_install_plan_unverified'),
                'message_key' => 'error_install_plan_unverified',
                'detail' => $reason,
            ];
        }

        // state.config 에는 DB 비밀번호가 기록되지 않으므로(이슈 #465) runtime.php 에서
        // 자격증명을 복원한다. db_cleanup 은 env_update 이후 실행되어 runtime 이 항상 존재.
        $config = hydrateDbSecretsFromRuntime($state['config'] ?? []);

        try {
            $pdo = getDatabaseConnection($config, false);
            $database = $config['db_write_database'] ?? '';
            $prefix = (string) ($config['db_prefix'] ?? 'g7_');

            $stmt = $pdo->query('SHOW TABLES');
            $allTables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

            // 입력한 db_prefix 로 시작하는 테이블만 삭제 대상으로 선별.
            // prefix 없는 타 애플리케이션/이전 설치 테이블은 동일 DB 라도 보존한다.
            $tables = filterTablesByPrefix($allTables, $prefix);

            if (empty($tables)) {
                sendSSEEvent('log', ['message' => lang('log_db_cleanup_empty')]);
            } else {
                sendSSEEvent('log', ['message' => lang('log_db_cleanup_dropping', ['count' => count($tables)])]);

                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tables as $table) {
                    $quoted = '`'.str_replace('`', '``', $table).'`';
                    $pdo->exec("DROP TABLE IF EXISTS {$quoted}");
                    // 원시 SQL 은 진행 기록에 넣지 않는다 (§12.0 W3) — 건수는 이 반복문 앞뒤의
                    // `log_db_cleanup_dropping` · `log_db_cleanup_done` 가 사람 말로 전한다.
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

                sendSSEEvent('log', ['message' => lang('log_db_cleanup_done', ['count' => count($tables)])]);
                // 지운 테이블명을 기록에 남긴다 (21차 #21-2 ③, 제품 결정) — 접두 매칭 삭제는
                // 더 긴 접두사 이웃까지 물 수 있는데, 종전에는 count 만 남아 무엇이 지워졌는지
                // 사후에도 알 수 없었다. 사람 문장 + 이름 나열이므로 §12.0 W3(원시 SQL 금지)와
                // 충돌하지 않는다.
                sendSSEEvent('log', ['message' => lang('log_db_cleanup_dropped_tables', ['tables' => implode(', ', $tables)])]);
            }

            sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            markTaskCompleted('db_cleanup');
            sendSSEEvent('task_complete', ['task' => 'db_cleanup', 'message' => $taskName]);

            return ['success' => true];
        } catch (InstallerWorkerLockLostException $e) {
            // lock 손실은 이 태스크의 실패가 아니라 "이 요청이 물러날 차례" 다.
            // 실패로 변환하면 호출자가 롤백을 돌리고 installation_status=failed 를 기록해
            // 이미 이어받아 진행 중인 승자 요청 위에 실패 상태를 덮어쓴다.
            throw $e;
        } catch (Throwable $e) {
            // Throwable 로 받는다 (12차 #A9) — in-process 실행에서는 Error/TypeError 가 정상
            // 범위라, Exception 만 잡으면 그 경우 handleInstallationTaskFailure 를 통째로
            // 건너뛰어 상태가 running 인 채 남는다.
            logInstallationError(lang('error_db_cleanup_failed'), $e);

            return [
                'success' => false,
                'message' => lang('error_db_cleanup_failed'),
                'message_key' => 'error_db_cleanup_failed',
                'detail' => $e->getMessage(),
            ];
        }
    }
}

if (! function_exists('installerRecordMigrationBatchBaseline')) {
    /**
     * migrate 직전의 최대 batch 를 기록한다 — 롤백이 되돌릴 범위의 기준선이다 (13차 #D9).
     *
     * 배치 지정 없는 `migrate:rollback` 은 마지막 배치를 되돌린다. 기존 스키마가 있는 DB 에서
     * 이번 실행이 0건 적용하고 실패하면 그 마지막 배치는 **운영자의 것**이므로, 되돌릴 범위를
     * 실행 전에 못박아 둬야 한다.
     *
     * 이미 기록돼 있으면 덮어쓰지 않는다 — 부분 적용 후 롤백이 실패한 상태에서 재시도하면
     * 기준선이 올라가 앞선 부분 적용분이 영구히 롤백 대상 밖으로 나간다.
     */
    function installerRecordMigrationBatchBaseline(): void
    {
        $state = getInstallationState();
        if (isset($state['db_migrate_batch_baseline'])) {
            return;
        }

        $config = hydrateDbSecretsFromRuntime($state['config'] ?? []);
        $batches = installerReadMigrationBatches($config, (string) ($config['db_prefix'] ?? ''));

        // DB 에 닿지 못했으면 기록하지 않는다 — 0 으로 적으면 운영자의 기존 배치가 이번
        // 실행의 것으로 오인된다. 기준선이 없으면 롤백은 되돌리지 않고 수동 정리를 안내한다.
        if ($batches === null) {
            return;
        }

        // migrations 테이블 자체가 없는 빈 DB 는 정상 경로다 — 기준선 0.
        $state['db_migrate_batch_baseline'] = (int) max([0, ...$batches]);
        saveInstallationState($state);
    }
}

if (! function_exists('runDatabaseMigrationSSE')) {
    function runDatabaseMigrationSSE(): array
    {
        installerRecordMigrationBatchBaseline();

        return executeDbCommandSSE(
            command: 'migrate',
            parameters: ['--force' => true],
            taskId: 'db_migrate',
            taskNameKey: 'task_db_migrate',
            successMsgKey: 'log_db_migrate_success',
            errorMsgKey: 'error_db_migrate_failed'
        );
    }
}

if (! function_exists('runDatabaseSeedingSSE')) {
    function runDatabaseSeedingSSE(): array
    {
        $state = getInstallationState();
        $config = $state['config'] ?? [];

        // 관리자 비밀번호는 runtime.php(0600) 가 SSoT (이슈 #465). state.config 폴백은
        // 이 수정 이전에 시작된 설치(레거시 state) 를 위한 한시 경로.
        $adminPassword = readInstallerRuntime()['admin']['password'] ?? ($config['admin_password'] ?? '');

        if (empty($config['admin_email']) || empty($adminPassword)) {
            // 실패 경로(handleInstallationTaskFailure)가 읽는 키로 돌려준다 — 'error' 로
            // 돌려주면 사유가 버려져 화면에는 사유 없는 일반 「설치 실패」만 남는다.
            logInstallationError(lang('error_admin_credentials_missing'));

            return [
                'success' => false,
                'message' => lang('error_admin_credentials_missing'),
                'message_key' => 'error_admin_credentials_missing',
            ];
        }

        // 이름/이메일/언어는 비밀이 아니므로 state.config 에 그대로 둔다.
        // 주입은 installerPutEnv 단일 지점이 맡는다 — putenv 가 차단된 호스팅에서도 in-process
        // 시더가 값을 볼 수 있도록 $_ENV/$_SERVER 를 함께 채운다 (13차 환경#3).
        installerPutEnv('INSTALLER_ADMIN_NAME', (string) ($config['admin_name'] ?? 'Administrator'));
        installerPutEnv('INSTALLER_ADMIN_EMAIL', (string) $config['admin_email']);
        installerPutEnv('INSTALLER_ADMIN_PASSWORD', (string) $adminPassword);
        installerPutEnv('INSTALLER_ADMIN_LANGUAGE', (string) ($config['admin_language'] ?? $state['g7_locale'] ?? 'ko'));

        // ENV 해제는 성공·실패와 무관하다 (13차 보안#12). in-process 는 putenv 가 FPM 워커
        // 수명 동안 유지되므로, 실패 경로에서 남으면 이후 **다른 요청**의 자식 프로세스가
        // 평문 비밀번호를 상속한다. 성공 시에만 지우던 종전 배선이 그 창을 열어 두고 있었다.
        //
        // 반면 runtime.php·state 의 잔존처 삭제는 **성공했을 때만** 한다 — 실패한 db_seed 는
        // 완료 마커가 없어 재시도 대상이고, 그 재시도가 읽는 SSoT 가 runtime.php 다.
        try {
            $result = executeDbCommandSSE(
                command: 'db:seed',
                parameters: ['--force' => true],
                taskId: 'db_seed',
                taskNameKey: 'task_db_seed',
                successMsgKey: 'log_db_seed_success',
                errorMsgKey: 'error_db_seed_failed'
            );

            if (($result['success'] ?? false) === true) {
                purgeAdminPasswordAfterSeeding();
            }

            return $result;
        } finally {
            purgeAdminCredentialsFromEnv();
        }
    }
}

if (! function_exists('purgeAdminPasswordAfterSeeding')) {
    /**
     * db_seed 성공 직후 관리자 비밀번호의 모든 잔존처를 제거한다 (이슈 #465).
     *
     * 시딩이 끝나면 관리자 계정이 DB 에 해시로 존재하므로 평문은 더 이상 필요 없다.
     * 노출 창을 최소화하기 위해 소비 즉시 삭제한다:
     *
     *   1. runtime.php 의 admin 섹션 (0600 이지만 finalize 까지 잔존)
     *   2. state.json 의 비밀 4종 (이 수정 이전에 시작된 레거시 설치 대비)
     *   3. process ENV — 해제하지 않으면 이후 모든 exec() 자식(확장 설치 artisan 전부)
     *      이 평문을 상속받는다
     *
     * 재시도 안전성: db_seed 는 completed_tasks 마커로 스킵된다. drop_tables 재시도로
     * 마커가 제거되는 경로는 반드시 install-process.php 를 다시 거치므로 세션 또는
     * runtime 에서 비밀번호가 재주입된다.
     */
    function purgeAdminPasswordAfterSeeding(): void
    {
        $runtime = readInstallerRuntime();
        if (is_array($runtime) && isset($runtime['admin'])) {
            unset($runtime['admin']);
            writeInstallerRuntime($runtime);
        }

        // executeDbCommandSSE 가 markTaskCompleted 로 state 를 갱신했으므로 재로드 후
        // redact 한다 (스냅샷을 덮어쓰면 completed 마커가 유실됨).
        //
        // 저장 실패를 버리면 **관리자 비밀번호가 state.json 에 그대로 남는다** — 다른 두 지점과
        // 달리 이쪽은 보안 결과다. 지울 수는 없어도 남았다는 사실은 보여야 한다 (17차 #C2).
        installerSaveStateOrReport(
            redactInstallationStateSecrets(getInstallationState()),
            'admin_secret_purge'
        );

        purgeAdminCredentialsFromEnv();
    }
}

if (! function_exists('purgeAdminCredentialsFromEnv')) {
    /**
     * 관리자 자격증명을 이 프로세스의 환경에서만 해제한다 (13차 보안#12).
     *
     * runtime.php·state 의 잔존처는 건드리지 않는다 — 실패한 db_seed 의 재시도가 그 값을
     * 읽기 때문이다. 여기서 지우는 것은 「이 요청 이후 자식 프로세스로의 상속」뿐이다.
     */
    function purgeAdminCredentialsFromEnv(): void
    {
        foreach ([
            'INSTALLER_ADMIN_PASSWORD',
            'INSTALLER_ADMIN_NAME',
            'INSTALLER_ADMIN_EMAIL',
            'INSTALLER_ADMIN_LANGUAGE',
        ] as $key) {
            installerPutEnv($key, null);
        }
    }
}

if (! function_exists('getSelectedExtensions')) {
    function getSelectedExtensions(): array
    {
        $state = getInstallationState();

        return $state['selected_extensions'] ?? [
            'admin_templates' => [],
            'user_templates' => [],
            'modules' => [],
            'plugins' => [],
            'language_packs' => [],
        ];
    }
}

if (! function_exists('reserveCommandOutputFile')) {
    /**
     * artisan 명령 stdout/stderr redirect 용 임시 로그 파일 경로 예약.
     *
     * pipe deadlock 회피를 위해 PHP exec() 의 child output 을 pipe 가 아닌 파일로 보낸다.
     * 파일은 권한이 보장된 디렉토리에 생성 — 인스톨러 환경에서 항상 writable 인 곳:
     *  1) storage/logs/  (installation.log 와 같은 검증된 디렉토리, REQUIRED_DIRECTORIES 의 storage 하위)
     *  2) storage/installer/  (runtime.php 와 같은 디렉토리)
     *  3) sys_get_temp_dir()  (최후 fallback)
     *
     * @param  array<int, string>|null  $candidates  후보 디렉토리 (null 이면 기본 후보 — 테스트 주입용)
     * @return string|null 예약된 임시 파일 경로 (호출자가 redirect 후 read + unlink). 예약 불가면 null
     */
    function reserveCommandOutputFile(?array $candidates = null): ?string
    {
        // sys_get_temp_dir 이 막힌 호스팅에서는 그 후보를 아예 빼 둔다 — 빈 문자열 경로가
        // 남으면 `is_dir('')` 가 false 라 조용히 예약 실패로만 보인다.
        $candidates ??= array_values(array_filter([
            BASE_PATH.'/storage/logs',
            BASE_PATH.'/storage/installer',
            installerTempDir(),
        ], static fn (?string $dir): bool => $dir !== null));

        foreach ($candidates as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                // 이름을 추측 불가능하게 만든다 (19차 F10).
                //
                // 세 후보 중 마지막은 **시스템 temp** 이고, 그 디렉토리는 같은 호스트의 다른
                // 계정과 공유된다. `uniqid('', true)` 는 microtime + lcg 라 값의 범위가 좁아
                // 이름을 맞힐 여지가 있고, 맞히면 그 자리에 심볼릭 링크를 선점해 자식
                // 프로세스의 출력 리다이렉트를 다른 파일로 흘릴 수 있다. 그 출력에는 명령
                // 원문과 실패 사유가 실린다.
                //
                // `random_bytes` 는 CSPRNG 다. 막힌 환경(`disable_functions`)에서는 종전
                // 방식으로 떨어진다 — 이름이 약해질 뿐 기능은 유지된다.
                // `random_bytes` 실패 폴백을 `catch` 안에서 `uniqid()` 로 두면, `uniqid` 까지
                // 막힌 환경에서 **catch 블록 안에서 다시 `Error`** 가 나고 받을 곳이 없다.
                // 폴백은 잡히는 자리에 있어야 한다 — 그 자리가 installerUniqueSuffix() 다.
                $suffix = installerUniqueSuffix(16);

                return $dir.DIRECTORY_SEPARATOR.'installer-cmd-output-'.$suffix.'.log';
            }
        }

        // 후보가 전부 막힌 환경(open_basedir 가 /tmp 까지 차단) — 쓸 수 없는 경로를 그대로
        // 돌려주면 리다이렉트가 실패하고, 호출부는 파일 부재를 「출력 없음」으로 읽어 실패
        // 사유가 통째로 사라진다 (13차 환경#12). 예약 실패를 명시해 파이프 경로로 넘긴다.
        return null;
    }
}

if (! function_exists('installerCleanupCommandOutputFiles')) {
    /**
     * 명령 출력 임시 로그(`installer-cmd-output-*.log`) 잔여 파일을 정리한다.
     *
     * 정상 경로는 출력을 읽은 직후 지우지만, 요청이 강제 종료되면(타임아웃·OOM·프로세스 kill)
     * 파일이 남는다. 파일명이 `uniqid` 라 재시도마다 새로 쌓이고, 그것을 지우는 주체가
     * 어디에도 없었다 (9차 #L9). 예약과 같은 후보 디렉토리를 본다.
     *
     * @return int 지운 파일 수
     */
    function installerCleanupCommandOutputFiles(): int
    {
        $removed = 0;

        foreach ([BASE_PATH.'/storage/logs', BASE_PATH.'/storage/installer', installerTempDir()] as $dir) {
            if ($dir === null || ! is_dir($dir)) {
                continue;
            }

            if (! installerHasFunction('glob')) {
                continue;
            }

            foreach (glob($dir.DIRECTORY_SEPARATOR.'installer-cmd-output-*.log') ?: [] as $path) {
                if (is_file($path) && @unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}

if (! function_exists('executeArtisanCommandSSE')) {
    /**
     * 일반 Artisan 태스크 실행 (cache_clear · config_cache).
     *
     * 실행 방식(subprocess/inprocess)은 인스톨러 러너(installerRunArtisan)가 정한다.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string|int, mixed>  $parameters  Artisan::call 규약 파라미터
     */
    function executeArtisanCommandSSE(
        string $command,
        array $parameters,
        string $taskId,
        string $taskNameKey,
        string $successMsgKey,
        string $errorMsgKey
    ): array {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang($taskNameKey);

        updateCurrentTask($taskId);
        sendSSEEvent('task_start', ['task' => $taskId, 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        $run = installerRunArtisan($command, $parameters);
        $output = $run['output'];
        $returnCode = $run['exit_code'];

        sendCommandOutputSSE($output);

        if ($returnCode !== 0) {
            $errorMessage = implode("\n", $output);
            logInstallationError(lang($errorMsgKey), new Exception($errorMessage));

            return [
                'success' => false,
                'message' => lang($errorMsgKey),
                'message_key' => $errorMsgKey,
                'detail' => $errorMessage,
            ];
        }

        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted($taskId);
        sendSSEEvent('task_complete', ['task' => $taskId, 'message' => lang($successMsgKey)]);

        return ['success' => true];
    }
}

if (! function_exists('executeDbCommandSSE')) {
    /**
     * DB 태스크 실행 (db_migrate · db_seed) — 실패·중단 시 마이그레이션 롤백 동반.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string|int, mixed>  $parameters  Artisan::call 규약 파라미터
     */
    function executeDbCommandSSE(
        string $command,
        array $parameters,
        string $taskId,
        string $taskNameKey,
        string $successMsgKey,
        string $errorMsgKey
    ): array {
        $state = getInstallationState();
        $wasAborted = isset($state['installation_status']) && $state['installation_status'] === 'aborted';

        if ($wasAborted) {
            addLog(lang('db_task_abort_detected_before_start'));

            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang($taskNameKey);

        updateCurrentTask($taskId);
        sendSSEEvent('task_start', ['task' => $taskId, 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        $run = installerRunArtisan($command, $parameters);
        $output = $run['output'];
        $returnCode = $run['exit_code'];

        sendCommandOutputSSE($output);

        if ($returnCode !== 0) {
            $errorMessage = implode("\n", $output);
            logInstallationError(lang($errorMsgKey), new Exception($errorMessage));

            addLog(lang('db_task_failed_rollback_start', ['task' => installerTaskKeyDisplayName($taskId)]));
            sendSSEEvent('log', ['message' => lang('failed_rollback_start', ['task' => $taskName])]);

            // 실패한 그 태스크의 롤백으로 디스패치한다 — db_seed 실패를 migrate:rollback 으로
            // 되돌리면 같은 실패가 「중단으로 끝났을 때」(rollbackTask → TRUNCATE)와 다른 DB
            // 상태를 남긴다. 마커도 되돌린 범위만 지운다: 시딩만 실패했으면 마이그레이션 결과는
            // 유효하므로 재시도가 스키마를 다시 만들 이유가 없다.
            $rollbackResult = rollbackTask($taskId, getInstallationState());
            $rollbackFailure = null;

            sendRollbackOutputSSE($rollbackResult);

            if ($rollbackResult['success']) {
                sendSSEEvent('log', ['message' => lang('failed_rollback_success', ['message' => $rollbackResult['message']])]);
                if ($taskId === 'db_migrate') {
                    removeTaskCompleted('db_migrate');
                }
                // 중단 경로와 같은 규칙 — 실제로 되돌렸을 때만 마커를 지운다 (19차 R3).
                // 이 경로에서는 마커가 애초에 없어 결과가 같지만, 두 경로에 다른 규칙을 두면
                // 나중에 한쪽만 옳은 상태가 생긴다.
                if (($rollbackResult['reverted'] ?? true) === true) {
                    removeTaskCompleted('db_seed');
                }
                sendSSEEvent('log', ['message' => lang('failed_rollback_db_restart')]);
            } else {
                sendSSEEvent('log', ['message' => lang('failed_rollback_failed', ['message' => $rollbackResult['message']])]);
                $rollbackFailure = [
                    'message_key' => 'failed_rollback_manual_cleanup',
                    'detail_key' => 'failed_rollback_manual_cleanup_detail',
                ];
                sendSSEEvent('rollback_failed', [
                    'message' => lang('failed_rollback_manual_cleanup'),
                    'detail' => lang('failed_rollback_manual_cleanup_detail'),
                ]);
            }

            return [
                'success' => false,
                'message' => lang($errorMsgKey),
                'message_key' => $errorMsgKey,
                'detail' => $errorMessage,
                'rollback_done' => true,
                'rollback_failure' => $rollbackFailure,
            ];
        }

        markTaskCompleted($taskId);

        $state = getInstallationState();
        $isAborted = isset($state['installation_status']) && $state['installation_status'] === 'aborted';
        $connectionLost = getProgressEmitter()->isConnectionAborted();

        if ($isAborted || $connectionLost) {
            $reason = $connectionLost ? lang('db_task_abort_reason_connection') : lang('db_task_abort_reason_user');
            addLog(lang('db_task_completed_abort_detected', ['task' => $taskName, 'reason' => $reason]));
            sendSSEEvent('log', ['message' => lang('db_task_completed_rollback_start', ['task' => $taskName])]);

            // 실패 경로와 같은 규칙으로 디스패치한다 (13차 #D4). 종전에는 이 분기만
            // `rollbackDbMigrate()` 하드코딩이라, db_seed 가 끝난 뒤 [중단]/연결끊김이면
            // 시드가 아니라 **스키마 전체**가 되돌려지고 두 마커가 함께 지워졌다 — 같은 실패가
            // 「실패로 끝났을 때」와 「중단으로 끝났을 때」 서로 다른 DB 상태를 남긴다.
            $rollbackResult = rollbackTask($taskId, getInstallationState());
            sendRollbackOutputSSE($rollbackResult);

            if ($rollbackResult['success']) {
                sendSSEEvent('log', ['message' => lang('abort_rollback_success', ['message' => $rollbackResult['message']])]);
                if ($taskId === 'db_migrate') {
                    removeTaskCompleted('db_migrate');
                }

                // 마커는 **실제로 되돌렸을 때만** 지운다 (19차 R3).
                //
                // 이 분기는 `markTaskCompleted($taskId)` **뒤**에 있다. 그래서 db_seed 가 성공한
                // 직후의 중단·연결끊김에서는 `rollbackDbSeed()` 가 방금 세운 그 마커를 보고
                // 「이미 끝났다」로 단락해 **아무것도 되돌리지 않고** `success:true` 를 돌려준다.
                // 그 true 로 마커를 지우면 DB 에는 시드가 남고 상태에는 없는 상태가 되고, 재개하면
                // `db:seed` 가 다시 돌아 `AdminUserSeeder` 가 **전체 사용자를 삭제**한다.
                //
                // 되돌리지 못했으면 마커를 그대로 둔다 — 그러면 재개가 그 단계를 건너뛰어
                // DB 와 상태가 어긋나지 않는다. 되돌리지 않은 것을 되돌린 척하지 않는 쪽이,
                // 확인하지 못한 것을 근거로 데이터를 지우는 쪽보다 안전하다.
                if (($rollbackResult['reverted'] ?? true) === true) {
                    removeTaskCompleted('db_seed');
                }
                sendSSEEvent('log', ['message' => lang('failed_rollback_db_restart')]);
            } else {
                sendSSEEvent('log', ['message' => lang('abort_rollback_failed', ['message' => $rollbackResult['message']])]);
                sendSSEEvent('log', ['message' => lang('failed_rollback_manual_cleanup')]);
            }

            return ['success' => false, 'aborted' => true];
        }

        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        sendSSEEvent('task_complete', ['task' => $taskId, 'message' => lang($successMsgKey)]);

        return ['success' => true];
    }
}

if (! function_exists('executeExtensionCommandSSE')) {
    /**
     * 확장(템플릿·모듈·플러그인·언어팩) 설치/활성화 태스크 실행.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string|int, mixed>  $parameters  Artisan::call 규약 파라미터
     */
    function executeExtensionCommandSSE(
        string $command,
        array $parameters,
        string $taskId,
        ?string $target,
        string $taskNameKey,
        string $successMsgKey,
        string $errorMsgKey
    ): array {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang($taskNameKey);

        $targetDisplayName = $target;
        if ($target) {
            $state = getInstallationState();
            $extensionNames = $state['extension_names'] ?? [];
            $lang = $state['g7_locale'] ?? 'ko';
            if (isset($extensionNames[$target])) {
                $name = $extensionNames[$target];
                if (is_array($name)) {
                    $targetDisplayName = $name[$lang] ?? $name['ko'] ?? $name['en'] ?? $target;
                } else {
                    $targetDisplayName = $name;
                }
            }
        }

        $displayName = $target ? "{$targetDisplayName} {$taskName} ({$target})" : $taskName;

        // 완료·best-effort 기록은 "taskId:target" 키로 비교하므로 current_task 도 같은 키여야 한다.
        // bare id 를 두면 확장 태스크의 current_task 가 완료 후에도 지워지지 않고, 이후 예외가
        // 나면 failed_task 에 엉뚱한 태스크명이 박힌다. 화면(JS)도 이미 이 합성 키를 전제로
        // 콜론을 분리한다.
        updateCurrentTask($target !== null ? "{$taskId}:{$target}" : $taskId);
        sendSSEEvent('task_start', ['task' => $taskId, 'target' => $target, 'name' => $displayName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $displayName])]);

        $run = installerRunArtisan($command, $parameters);
        $output = $run['output'];
        $returnCode = $run['exit_code'];

        // 폴링 모드(NullEmitter)는 batch write — Windows file IO 라인당 ~5ms 누적 회피.
        // SSE 모드는 stream emit 으로 사용자에게 실시간 진행 표시.
        $emitter = getProgressEmitter();
        if ($emitter instanceof NullEmitter) {
            $nonEmpty = [];
            foreach ($output as $line) {
                if (trim($line) !== '') {
                    // 배치 경로도 같은 표식을 붙인다 — 표식이 붙는 자리가 갈리면 폴링
                    // 모드에서만 명령 원문이 진행 기록에 남는다 (§12.0 W3).
                    $nonEmpty[] = INSTALLER_COMMAND_OUTPUT_PREFIX.$line;
                }
            }
            if (! empty($nonEmpty)) {
                addLogBatch($nonEmpty);
            }
            // heartbeat 갱신 1회 (라인당 호출 시 state.json IO 병목)
            $emitter->emit('heartbeat', []);
        } else {
            sendCommandOutputSSE($output);
        }

        $outputText = implode("\n", $output);

        // 「이미 설치/활성화」 판정은 execution-mode.php 의 단일 판정을 경유한다 (21차 #21-3).
        // 종전의 무경계 부분문자열 판정은 의존성 충돌 실패 메시지(`… is already installed at
        // version 1.2, but … Aborting.`)까지 성공으로 뒤집어, 설치는 초록으로 끝나는데 그
        // 확장은 어디에도 없는 상태를 만들었다.
        $isAlreadyExists = installerOutputConfirmsAlreadyApplied($outputText);

        // 생명주기 명령은 항목 일부가 실패해도 작업을 끝내고 종료코드 1 + 경고 표지를 낸다(코어 7.1.3).
        // 표지가 있는 1 은 「완료(경고 포함)」 — 경고 표는 위 로그로 이미 보였다. 표지 없는 1 은 실패다.
        $completedWithWarnings = $returnCode === 1 && str_contains($outputText, '[g7-lifecycle-warnings]');

        if ($returnCode !== 0 && ! $isAlreadyExists && ! $completedWithWarnings) {
            $errorMessage = $outputText;
            logInstallationError(lang($errorMsgKey), new Exception($errorMessage));

            return [
                'success' => false,
                'message' => lang($errorMsgKey),
                'message_key' => $errorMsgKey,
                'detail' => $errorMessage,
                'target' => $target,
            ];
        }

        if ($isAlreadyExists) {
            sendSSEEvent('log', ['message' => lang('log_task_skipped', ['task' => $displayName])]);
        } elseif ($completedWithWarnings) {
            sendSSEEvent('log', ['message' => lang('log_task_completed_with_warnings', ['task' => $displayName])]);
        } else {
            sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $displayName])]);
        }
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        $completedTaskKey = $target ? "{$taskId}:{$target}" : $taskId;
        markTaskCompleted($completedTaskKey);

        sendSSEEvent('task_complete', [
            'task' => $taskId,
            'target' => $target,
            'message' => lang($successMsgKey),
        ]);

        return ['success' => true];
    }
}

if (! function_exists('installAdminTemplateSSE')) {
    function installAdminTemplateSSE(string $templateId): array
    {
        return executeExtensionCommandSSE(
            command: 'template:install',
            parameters: [$templateId],
            taskId: 'template_install',
            target: $templateId,
            taskNameKey: 'task_template_install',
            successMsgKey: 'log_template_install_success',
            errorMsgKey: 'error_template_install_failed'
        );
    }
}

if (! function_exists('activateAdminTemplateSSE')) {
    function activateAdminTemplateSSE(string $templateId): array
    {
        return executeExtensionCommandSSE(
            command: 'template:activate',
            parameters: [$templateId],
            taskId: 'template_activate',
            target: $templateId,
            taskNameKey: 'task_template_activate',
            successMsgKey: 'log_template_activate_success',
            errorMsgKey: 'error_template_activate_failed'
        );
    }
}

if (! function_exists('installModuleSSE')) {
    function installModuleSSE(string $moduleId): array
    {
        return executeExtensionCommandSSE(
            command: 'module:install',
            parameters: [$moduleId] + vendorModeOptionFromState(),
            taskId: 'module_install',
            target: $moduleId,
            taskNameKey: 'task_module_install',
            successMsgKey: 'log_module_install_success',
            errorMsgKey: 'error_module_install_failed'
        );
    }
}

if (! function_exists('activateModuleSSE')) {
    function activateModuleSSE(string $moduleId): array
    {
        return executeExtensionCommandSSE(
            command: 'module:activate',
            parameters: [$moduleId],
            taskId: 'module_activate',
            target: $moduleId,
            taskNameKey: 'task_module_activate',
            successMsgKey: 'log_module_activate_success',
            errorMsgKey: 'error_module_activate_failed'
        );
    }
}

if (! function_exists('installPluginSSE')) {
    function installPluginSSE(string $pluginId): array
    {
        return executeExtensionCommandSSE(
            command: 'plugin:install',
            parameters: [$pluginId] + vendorModeOptionFromState(),
            taskId: 'plugin_install',
            target: $pluginId,
            taskNameKey: 'task_plugin_install',
            successMsgKey: 'log_plugin_install_success',
            errorMsgKey: 'error_plugin_install_failed'
        );
    }
}

if (! function_exists('activatePluginSSE')) {
    function activatePluginSSE(string $pluginId): array
    {
        return executeExtensionCommandSSE(
            command: 'plugin:activate',
            parameters: [$pluginId],
            taskId: 'plugin_activate',
            target: $pluginId,
            taskNameKey: 'task_plugin_activate',
            successMsgKey: 'log_plugin_activate_success',
            errorMsgKey: 'error_plugin_activate_failed'
        );
    }
}

if (! function_exists('installUserTemplateSSE')) {
    function installUserTemplateSSE(string $templateId): array
    {
        return executeExtensionCommandSSE(
            command: 'template:install',
            parameters: [$templateId],
            taskId: 'user_template_install',
            target: $templateId,
            taskNameKey: 'task_user_template_install',
            successMsgKey: 'log_user_template_install_success',
            errorMsgKey: 'error_user_template_install_failed'
        );
    }
}

if (! function_exists('activateUserTemplateSSE')) {
    function activateUserTemplateSSE(string $templateId): array
    {
        return executeExtensionCommandSSE(
            command: 'template:activate',
            parameters: [$templateId],
            taskId: 'user_template_activate',
            target: $templateId,
            taskNameKey: 'task_user_template_activate',
            successMsgKey: 'log_user_template_activate_success',
            errorMsgKey: 'error_user_template_activate_failed'
        );
    }
}

if (! function_exists('installLanguagePackSSE')) {
    /**
     * 번들 언어팩 설치 (best-effort).
     *
     * `php artisan language-pack:install {id} --source=bundled` 호출. 자동 활성화 default.
     * 1건 실패는 전체 설치를 중단하지 않으며, 호출자(runInstallationTasks)는
     * task 정의에 `best_effort: true` 를 두어 실패 시 rollback 우회 + 경고 로그 처리.
     *
     * @param  string  $packId  번들 언어팩 식별자 (예: g7-core-ja)
     * @return array{success: bool, ...}
     */
    function installLanguagePackSSE(string $packId): array
    {
        return executeExtensionCommandSSE(
            command: 'language-pack:install',
            parameters: [$packId, '--source' => 'bundled'],
            taskId: 'language_pack_install',
            target: $packId,
            taskNameKey: 'task_language_pack_install',
            successMsgKey: 'log_language_pack_install_success',
            errorMsgKey: 'error_language_pack_install_failed'
        );
    }
}

if (! function_exists('clearCacheSSE')) {
    function clearCacheSSE(): array
    {
        return executeArtisanCommandSSE(
            command: 'optimize:clear',
            parameters: [],
            taskId: 'cache_clear',
            taskNameKey: 'task_cache_clear',
            successMsgKey: 'log_cache_clear_success',
            errorMsgKey: 'error_cache_clear_failed'
        );
    }
}

if (! function_exists('optimizeConfigCacheSSE')) {
    /**
     * 설치 완료 직후 config 캐시를 최초 생성한다.
     *
     * complete_flag(g7_installed) 이후에 실행되어 설치 완료 판정 상태에서 캐시를 만들며,
     * 이 지점이 없으면 설치는 config:cache 가 꺼진(매 요청 config 재파싱) 상태로 종료된다.
     * best_effort — 실패해도 설치 자체는 완료 처리한다(비캐시 부팅은 정상 동작).
     *
     * 주의 (13차 #D7/E2): 이 태스크는 finalize 의 `.env` 머지 **앞**에서 돈다. 이 캐시는 병합 전 값
     * (병합 전 .env + runtime.php 주입)으로 만들어져 `installer_completed=false` 와 평문 DB 비밀번호가
     * 박힌다. 완료 화면이 부르는 finalize-env.php 가 이 워커의 lock 해제를 기다린 뒤 병합하고, 이 캐시를
     * 지운 뒤 병합된 .env 로 다시 만든다(다시 만들 수 없는 환경이면 지운 채 둔다).
     *
     * @return array 태스크 실행 결과
     */
    function optimizeConfigCacheSSE(): array
    {
        return executeArtisanCommandSSE(
            command: 'config:cache',
            parameters: [],
            taskId: 'config_cache',
            taskNameKey: 'task_config_cache',
            successMsgKey: 'log_config_cache_success',
            errorMsgKey: 'error_config_cache_failed'
        );
    }
}

if (! function_exists('optimizeRouteCacheSSE')) {
    /**
     * 설치 완료 직후 라우트 캐시를 최초 생성한다 (#599).
     *
     * complete_flag 이후에 실행되어야 한다 — `RouteCacheHelper::rebuild()` 는
     * `installer_completed` 가 서기 전이면 `route:clear` 만 하고 재생성을 건너뛰므로
     * (불완전한 부팅을 캐시에 박제하지 않으려는 의도된 설계), 설치 도중의 확장
     * install·activate 가 부른 rebuild 는 전부 clear 로 끝난다. 그리고 완료 플래그
     * 이후에는 재생성 트리거가 확장 라이프사이클에만 묶여 있어 이 지점이 없으면
     * **아무도 굽지 않는다** — 설치가 라우트 캐시 꺼진 상태로 종료된다.
     *
     * 웹은 캐시가 없어도 정상 동작하지만(매 요청 라우트 파일 스캔 — 느릴 뿐),
     * `php artisan test` 는 죽는다: artisan 은 `test` 명령일 때 확장 오토로드 등록을
     * 건너뛰면서 중복 방지 상수는 정의하므로 CoreServiceProvider 의 등록까지 막히는데,
     * 캐시가 있으면 부모 부팅이 라우트 파일을 아예 실행하지 않아 무해하고 비어 있으면
     * 라우트 파일이 실행되며 확장 클래스를 만나 "Class ... not found" 로 터진다.
     *
     * best_effort — 실패해도 설치 자체는 완료 처리한다(비캐시 부팅은 정상 동작).
     *
     * @return array 태스크 실행 결과
     */
    function optimizeRouteCacheSSE(): array
    {
        return executeArtisanCommandSSE(
            command: 'route:cache',
            parameters: [],
            taskId: 'route_cache',
            taskNameKey: 'task_route_cache',
            successMsgKey: 'log_route_cache_success',
            errorMsgKey: 'error_route_cache_failed'
        );
    }
}

if (! function_exists('publishStaticCacheSSE')) {
    /**
     * 설치 완료 직후 부트스트랩 리소스 정적 게시(bake)를 최초 수행한다 (#122).
     *
     * 이 지점이 없으면 첫 방문자가 blade 자가 치유(1회 API 폴백)를 겪는다.
     * best_effort — 실패해도 설치는 완료 처리한다(사이트는 API 폴백으로 정상).
     *
     * @return array 태스크 실행 결과
     */
    function publishStaticCacheSSE(): array
    {
        return executeArtisanCommandSSE(
            command: 'ext-static:publish',
            parameters: ['--force' => true],
            taskId: 'static_publish',
            taskNameKey: 'task_static_publish',
            successMsgKey: 'log_static_publish_success',
            errorMsgKey: 'error_static_publish_failed'
        );
    }
}

if (! function_exists('createSettingsJsonSSE')) {
    function createSettingsJsonSSE(): array
    {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang('task_create_settings_json');

        updateCurrentTask('create_settings_json');
        sendSSEEvent('task_start', ['task' => 'create_settings_json', 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_creating_settings')]);

        try {
            $settingsDir = BASE_PATH.'/storage/app/settings';

            // 생성 결과를 확인한다 (19차 F1). 반환값을 버리면 storage/app 이 쓰기 불가일 때도
            // 아래 쓰기가 전부 조용히 실패하고 「설정 파일 생성 완료」가 보고된다 — 사이트는
            // 기본 설정이 하나도 없는 채로 뜬다. 같은 파일의 형제(`complete_flag` 의
            // `g7_installed` 쓰기)는 13차 #D12 로 이미 검사하고 있었다.
            if (! is_dir($settingsDir) && ! @mkdir($settingsDir, 0755, true) && ! is_dir($settingsDir)) {
                logInstallationError(lang('error_settings_dir_create_failed', ['path' => $settingsDir]));

                return [
                    'success' => false,
                    'message' => lang('error_settings_dir_create_failed', ['path' => $settingsDir]),
                    'message_key' => 'error_settings_dir_create_failed',
                    'message_params' => ['path' => $settingsDir],
                ];
            }

            $defaultsFile = BASE_PATH.'/config/settings/defaults.json';
            if (! file_exists($defaultsFile)) {
                throw new Exception('defaults.json file not found: '.$defaultsFile);
            }

            $defaultsContent = file_get_contents($defaultsFile);
            $defaultsData = json_decode($defaultsContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('defaults.json JSON parsing failed: '.json_last_error_msg());
            }

            $defaults = $defaultsData['defaults'] ?? [];
            $categories = $defaultsData['_meta']['categories'] ?? array_keys($defaults);

            if (empty($defaults)) {
                throw new Exception('defaults.json does not contain defaults section');
            }

            $state = getInstallationState();
            $config = $state['config'] ?? [];

            if (! empty($config['app_name'])) {
                $defaults['general']['site_name'] = $config['app_name'];
                $defaults['mail']['from_name'] = $config['app_name'];
            }
            if (! empty($config['app_url'])) {
                $defaults['general']['site_url'] = $config['app_url'];
            }
            if (! empty($config['admin_email'])) {
                $defaults['general']['admin_email'] = $config['admin_email'];
                $defaults['mail']['from_address'] = $config['admin_email'];
            }

            if (! empty($config['core_update_github_url'])) {
                $defaults['core_update']['github_url'] = $config['core_update_github_url'];
            }
            // 토큰은 state.config 가 아니라 runtime.php(0600) 에서 온다 (비밀 5종).
            $githubToken = (string) ($config['core_update_github_token']
                ?? (readInstallerRuntime()['core_update']['github_token'] ?? ''));
            if ($githubToken !== '') {
                $defaults['core_update']['github_token'] = $githubToken;
            }

            // 사이트 기본 언어는 세션이 아니라 상태 파일에서 읽는다 — 단계별 실행(run-task.php)과
            // SSE 워커(install-worker.php)는 session.php 를 require 하지 않으므로 getCurrentLanguage()
            // 가 언제나 'ko' 로 떨어진다. 폴링만 세션을 열어, 종전에는 같은 설치가 진행 모드에 따라
            // 다른 언어로 끝났다(오류 없이 값만 틀린다 — §7 R2 와 같은 성질).
            //
            // 관리자 **계정** 언어와는 의도적으로 별개 축이다 (22차 #22-6 주석 정정) — 그쪽은
            // Step 3 의 admin_language 선택(config)을 우선하고(INSTALLER_ADMIN_LANGUAGE 주입부),
            // 사이트 기본 언어는 인스톨러 UI 언어(state.g7_locale)를 따른다. 운영자가 관리자
            // 언어를 UI 와 다르게 골랐다면 둘이 갈리는 것이 맞다.
            $defaults['general']['language'] = $state['g7_locale'] ?? 'ko';

            // 실행 모드 (Step 3 선택값, 기본 auto) — 저장되는 것은 "선택" 이지 감지 결과가 아니다.
            // auto 면 운영 중 매 판정마다 감지하므로 호스팅 환경 변화를 따라간다 (A-0 ①).
            $defaults['system'] = is_array($defaults['system'] ?? null) ? $defaults['system'] : [];
            $defaults['system']['execution_mode'] = installerNormalizeExecutionMode($config['execution_mode'] ?? 'auto');

            // 자산 URL 방식 (이슈 #486) — 설치 화면이 브라우저에서 프로브를 던져 판정한 결과.
            // 정적 최적화 블록(`location ~* \.(js|css|json)$`)이 있는 서버는 확장자 붙은
            // 동적 응답이 PHP 에 도달하지 못하므로 확장자 없는 형태로 설치를 마쳐야 한다.
            // 미판정(구버전 설치 화면·프로브 실패)이면 defaults.json 의 기본값을 그대로 둔다.
            if (in_array($config['asset_url_mode'] ?? null, ['extension', 'extensionless'], true)) {
                $defaults['general']['asset_url_mode'] = $config['asset_url_mode'];
            }

            foreach ($categories as $category) {
                if (! isset($defaults[$category])) {
                    sendSSEEvent('log', ['message' => lang('log_settings_json_category_skipped', ['category' => $category])]);

                    continue;
                }

                $settings = $defaults[$category];
                $data = [
                    '_meta' => [
                        'version' => '1.0.0',
                        'updated_at' => date('c'),
                    ],
                ];
                $data = array_merge($data, $settings);

                $json = installer_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $filePath = $settingsDir.'/'.$category.'.json';

                // 쓰기 결과를 확인한다 (19차 F1) — 버리면 한 카테고리도 저장되지 않은 채
                // 「생성 완료」가 보고되고, 그 사실은 어디에도 남지 않는다.
                // 부분 쓰기는 `false` 가 아니라 쓴 바이트 수다 — 잘린 설정 JSON 이
                // 「생성 완료」로 보고되면 그 카테고리의 설정이 통째로 깨진 채 설치가 끝난다.
                if (! installerWroteFully(@file_put_contents($filePath, $json, LOCK_EX), $json)) {
                    logInstallationError(lang('error_settings_json_write_failed', ['path' => $filePath]));

                    return [
                        'success' => false,
                        'message' => lang('error_settings_json_write_failed', ['path' => $filePath]),
                        'message_key' => 'error_settings_json_write_failed',
                        'message_params' => ['path' => $filePath],
                    ];
                }
                // 비밀이 실린 파일은 소유자 전용으로 좁힌다 (19차 R10).
                //
                // `core_update.json` 에는 프라이빗 저장소 GitHub PAT 가 들어간다. R3 이
                // `runtime.php` 로 세운 규칙(「비밀은 0600 채널에만」)이 이 사본에는 적용되지
                // 않아, 기본 umask 로 0644 가 되고 되돌리기도 그 파일을 대상으로 보지 않는다.
                if ($category === 'core_update' && isset($data['github_token']) && $data['github_token'] !== ''
                    && installerHasFunction('chmod')) {
                    @chmod($filePath, 0600);
                }

                sendSSEEvent('log', ['message' => lang('log_settings_json_category_created', ['category' => $category])]);
            }

            sendSSEEvent('log', ['message' => lang('log_settings_json_created')]);
            sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            markTaskCompleted('create_settings_json');
            sendSSEEvent('task_complete', ['task' => 'create_settings_json', 'message' => lang('log_settings_json_created')]);

            return ['success' => true];
        } catch (InstallerWorkerLockLostException $e) {
            // lock 손실은 태스크 실패가 아니다 — 위 db_cleanup 과 같은 이유로 그대로 올린다.
            throw $e;
        } catch (Throwable $e) {
            // Throwable 로 받는다 (12차 #A9) — 위 db_cleanup 과 같은 이유다.
            logInstallationError(lang('error_settings_json_failed'), $e);

            return [
                'success' => false,
                'message' => lang('error_settings_json_failed'),
                'message_key' => 'error_settings_json_failed',
                'detail' => $e->getMessage(),
            ];
        }
    }
}

if (! function_exists('setInstallationCompleteSSE')) {
    function setInstallationCompleteSSE(): array
    {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang('task_complete_flag');

        updateCurrentTask('complete_flag');
        sendSSEEvent('task_start', ['task' => 'complete_flag', 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        try {
            // .env 의 INSTALLER_COMPLETED=true 는 finalize-env.php 단계에서 작성된다.
            // 본 단계에서는 .env 를 건드리지 않아 php artisan serve 의 mtime 워처가
            // 진행 중 재시작을 일으키지 않도록 한다. g7_installed 파일과 state.json
            // 의 completed 마커만으로 "Step 5 완료" 시점을 보장.

            $installedFlagPath = BASE_PATH.'/storage/app/g7_installed';
            $installedFlagDir = dirname($installedFlagPath);

            if (! is_dir($installedFlagDir)) {
                @mkdir($installedFlagDir, 0775, true);
            }

            // 쓰기 결과를 확인한다 (13차 #D12). 반환값을 버리면 storage/app 이 쓰기 불가일 때도
            // 「설치 완료」가 보고되고, 그 뒤 부팅은 g7_installed 부재를 보고 다시 인스톨러로 간다
            // — 운영자에게는 완료 화면을 본 직후 설치가 처음부터 다시 뜨는 것으로만 보인다.
            // 부분 쓰기도 실패로 읽는다 — 이 파일의 **존재**가 설치 완료 판정 근거이므로,
            // 잘린 채 남으면 위 주석이 막으려던 상태(완료 화면 직후 인스톨러 재진입)가
            // 그대로 성립한다. 짧은 문자열이라 확률은 낮지만 규율을 지점마다 갈라 두지 않는다.
            $installedFlagBody = date('Y-m-d H:i:s');

            if (! installerWroteFully(@file_put_contents($installedFlagPath, $installedFlagBody), $installedFlagBody)) {
                logInstallationError(lang('error_installed_flag_write_failed', ['path' => $installedFlagPath]));

                return [
                    'success' => false,
                    'message' => lang('error_installed_flag_write_failed', ['path' => $installedFlagPath]),
                    'message_key' => 'error_installed_flag_write_failed',
                    'message_params' => ['path' => $installedFlagPath],
                ];
            }
            if (installerHasFunction('chmod')) {
                @chmod($installedFlagPath, 0644);
            }
            sendSSEEvent('log', ['message' => lang('log_installed_flag_created')]);

            // `public/storage` 링크를 만들 수 있으면 만든다 (B-3 · B-4).
            //
            // 링크가 있으면 공개 파일이 웹서버의 정적 서빙으로 나가고, 없으면 앱 서빙 경로가
            // 대신한다 — 둘 다 성립하지만 **전자가 기본 경로**다. 종전에는 인스톨러가
            // `symlink()` 가용 여부를 **재기만 하고 만들지는 않아**, 링크가 가능한 호스팅에
            // 새로 설치해도 언제나 대체 경로를 탔다(링크 생성은 코어 업데이트에만 있었다).
            //
            // 실패해도 설치를 막지 않는다 — 그것이 대체 경로의 존재 이유다. 대상은
            // `config('filesystems.links')` 가 정하므로(`public/storage` → `storage/app/public`)
            // 여기서 경로나 가용성 판정을 복제하지 않는다. Artisan 실행은 인스톨러의 단일
            // 지점(`installerRunArtisan`)을 그대로 쓴다 — 직접 스폰 금지 규율과 같다.
            $linkResult = installerRunArtisan('storage:link');

            // 종료 코드 0 이 곧 「만들었다」가 아니다 — Laravel 의 `storage:link` 는 링크가
            // **이미 있으면** 알림만 내고 0 으로 끝난다. 그 경우까지 「만들었습니다」로 적으면
            // 설치 로그가 사실과 달라진다. 결과 파일을 직접 확인해 문구를 가른다.
            $linkExists = file_exists(BASE_PATH.'/public/storage');
            sendSSEEvent('log', [
                'message' => $linkResult['exit_code'] === 0 && $linkExists
                    ? lang('log_storage_link_created')
                    : lang('log_storage_link_skipped'),
            ]);

            // `deploy/**` 서버 설정 조각의 해시 원장을 설치 시점 디스크로 씨앗한다 (D25 · 회차 16).
            //
            // 코어 업데이트는 그 원장을 근거로 「운영자가 고친 조각인가」를 가른다. 원장이 없으면
            // 판정 자체가 불가능해 첫 업데이트에서 전량이 보존(`.dist` + 경고)으로 떨어진다 —
            // 갓 설치한 사이트가 새 보호 규칙을 못 받는 형태다. 설치 직후 디스크는 곧 릴리즈
            // 판본이라 이 시점이 유일하게 확실한 기준선이다. 실패해도 설치를 막지 않는다 —
            // 원장이 없으면 다음 업데이트가 보존 쪽(잃지 않는 쪽)으로 떨어질 뿐이다.
            $ledgerResult = installerRunArtisan('core:deploy-ledger-seed');
            sendSSEEvent('log', [
                'message' => $ledgerResult['exit_code'] === 0
                    ? lang('log_deploy_ledger_seeded')
                    : lang('log_deploy_ledger_seed_skipped'),
            ]);

            // 인스톨러 작업 중 composer install 등이 storage/temp 에 남긴 Symfony Process
            // sf_proc_*.{out,err,lock} 잔여 파일 정리. 디렉토리 자체는 보존 (다음 사용 대비).
            $tempDir = BASE_PATH.'/storage/temp';
            if (is_dir($tempDir)) {
                $entries = @scandir($tempDir);
                if (is_array($entries)) {
                    foreach ($entries as $entry) {
                        if ($entry === '.' || $entry === '..') {
                            continue;
                        }
                        $entryPath = $tempDir.'/'.$entry;
                        if (is_file($entryPath)) {
                            @unlink($entryPath);
                        }
                    }
                }
            }

            // 명령 출력 임시 로그도 함께 정리한다 (9차 #L9). 정상 경로는 읽은 뒤 지우지만,
            // 요청이 강제 종료되면(타임아웃·OOM) 파일이 남고 이름이 uniqid 라 재시도마다
            // 누적된다 — 청소 주체가 없으면 storage/logs 에 조용히 쌓인다.
            installerCleanupCommandOutputFiles();

            $state = getInstallationState();
            $state['current_step'] = 5;
            $state['step_status']['5'] = 'completed';
            $state['installation_status'] = 'completed';
            $state['installation_completed_at'] = date('Y-m-d\TH:i:s\Z');
            // 완료 시점 이중 방어 (이슈 #465) — Phase 1/2 가 정상 동작하면 no-op.
            // 레거시 state 로 시작된 설치가 이 코드로 완료되는 경우를 커버한다.
            $state = redactInstallationStateSecrets($state);
            saveInstallationState($state);
            sendSSEEvent('log', ['message' => lang('log_state_updated')]);

            sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            markTaskCompleted('complete_flag');
            sendSSEEvent('task_complete', ['task' => 'complete_flag', 'message' => lang('log_installation_completed')]);

            // state.json 삭제는 finalize-env.php 로 위임됨.
            // 본 단계에서 즉시 삭제하면 finalize 가 generateEnvContent() 호출 시
            // state.config 를 읽지 못해 .env.example 의 placeholder 값으로 .env 가
            // 작성되는 회귀 발생. finalize 가 .env 머지 성공 후 state 삭제.

            return ['success' => true];
        } catch (InstallerWorkerLockLostException $e) {
            // lock 손실은 이 태스크의 실패가 아니다 — 실패로 변환하면 호출자가 롤백을 부르고,
            // 이 태스크의 롤백은 runtime.php(= stepwise 토큰)를 지워 승자 요청까지 무력화한다.
            throw $e;
        } catch (Throwable $e) {
            logInstallationError(lang('error_complete_flag_failed'), $e);

            return [
                'success' => false,
                'message' => lang('error_complete_flag_failed'),
                'message_key' => 'error_complete_flag_failed',
                'detail' => $e->getMessage(),
            ];
        }
    }
}

// ============================================================================
// 메인 실행 로직
// ============================================================================

if (! function_exists('buildInstallationTaskList')) {
    /**
     * 설치 태스크 목록을 순서대로 만든다 (진행 모드 무관 — 한 목록이 세 모드의 SSoT).
     *
     * 각 항목: id · function · args · target(확장) · best_effort(실패해도 계속) · skippable.
     * completed_tasks 키는 target 이 있으면 "id:target", 없으면 "id" 다.
     *
     * @return array<int, array<string, mixed>> 태스크 목록
     */
    function buildInstallationTaskList(): array
    {
        $selectedExtensions = getSelectedExtensions();
        $skippable = installerSkippableTaskIds();

        $tasks = [];

        // 1. 환경 설정 작업
        $tasks[] = ['id' => 'composer_check', 'function' => 'checkComposerSSE'];
        $tasks[] = ['id' => 'composer_install', 'function' => 'installComposerDependenciesSSE'];
        $tasks[] = ['id' => 'env_update', 'function' => 'updateEnvFileSSE'];
        $tasks[] = ['id' => 'key_generate', 'function' => 'generateApplicationKeySSE'];

        // 2. 의존성 사전 검증 (프론트엔드 우회 방어)
        $tasks[] = ['id' => 'dependency_precheck', 'function' => 'dependencyPrecheckSSE'];

        // 3. 기존 DB 테이블 정리 (existing_db_action === 'drop_tables'인 경우만 동작)
        $tasks[] = ['id' => 'db_cleanup', 'function' => 'cleanupExistingTablesSSE'];

        // 4. 데이터베이스 작업
        $tasks[] = ['id' => 'db_migrate', 'function' => 'runDatabaseMigrationSSE'];
        $tasks[] = ['id' => 'db_seed', 'function' => 'runDatabaseSeedingSSE'];

        // 5. 관리자 템플릿 (필수 — 건너뛰기 불가)
        foreach ($selectedExtensions['admin_templates'] ?? [] as $templateId) {
            $tasks[] = ['id' => 'template_install', 'target' => $templateId, 'function' => 'installAdminTemplateSSE', 'args' => [$templateId]];
            $tasks[] = ['id' => 'template_activate', 'target' => $templateId, 'function' => 'activateAdminTemplateSSE', 'args' => [$templateId]];
        }

        // 6. 모듈
        foreach ($selectedExtensions['modules'] ?? [] as $moduleId) {
            $tasks[] = ['id' => 'module_install', 'target' => $moduleId, 'function' => 'installModuleSSE', 'args' => [$moduleId]];
            $tasks[] = ['id' => 'module_activate', 'target' => $moduleId, 'function' => 'activateModuleSSE', 'args' => [$moduleId]];
        }

        // 7. 플러그인
        foreach ($selectedExtensions['plugins'] ?? [] as $pluginId) {
            $tasks[] = ['id' => 'plugin_install', 'target' => $pluginId, 'function' => 'installPluginSSE', 'args' => [$pluginId]];
            $tasks[] = ['id' => 'plugin_activate', 'target' => $pluginId, 'function' => 'activatePluginSSE', 'args' => [$pluginId]];
        }

        // 8. 사용자 템플릿
        foreach ($selectedExtensions['user_templates'] ?? [] as $templateId) {
            $tasks[] = ['id' => 'user_template_install', 'target' => $templateId, 'function' => 'installUserTemplateSSE', 'args' => [$templateId]];
            $tasks[] = ['id' => 'user_template_activate', 'target' => $templateId, 'function' => 'activateUserTemplateSSE', 'args' => [$templateId]];
        }

        // 9. 번들 언어팩 (best-effort — 1건 실패는 전체 중단하지 않음)
        //    코어/모듈/플러그인/템플릿 install·activate 가 모두 끝난 뒤 실행해야
        //    target_identifier 매칭 + DB 마이그레이션·시드가 완료된 상태에서 활성화 가능.
        foreach ($selectedExtensions['language_packs'] ?? [] as $packId) {
            $tasks[] = [
                'id' => 'language_pack_install',
                'target' => $packId,
                'function' => 'installLanguagePackSSE',
                'args' => [$packId],
                'best_effort' => true,
            ];
        }

        // 10. 마무리
        $tasks[] = ['id' => 'create_settings_json', 'function' => 'createSettingsJsonSSE'];
        $tasks[] = ['id' => 'cache_clear', 'function' => 'clearCacheSSE'];
        $tasks[] = ['id' => 'complete_flag', 'function' => 'setInstallationCompleteSSE'];
        // 이 순서의 전제는 사실이 아니다 (13차 #D7/E2 — 조치 완료, 소유는 finalize).
        //
        // 종전 주석은 「complete_flag(installer_completed=true) 이후이므로 설치가 config:cache
        // 켜진 상태로 시작한다」고 적었으나, complete_flag 은 `.env` 를 건드리지 않는다
        // (`g7_installed` 파일과 state 마커만 쓴다). `INSTALLER_COMPLETED=true` 를 쓰는 것은
        // 그 뒤에 오는 finalize-env.php 이고, 이 태스크는 **그보다 앞**이다.
        //
        // 그래서 여기서 구운 `bootstrap/cache/config.php` 에는 `app.installer_completed=false`
        // 가 박히고 runtime.php 의 평문 DB 비밀번호가 캐시로 복제된다.
        //
        // 제품 결정(13차): 굽는 것 자체는 그대로 두고, **finalize 가 마지막에 그 캐시 파일을
        // 지운다** (`finalize-env.php` 의 runtime.php 정리 구간). 목록에서 빼는 것은 계약
        // 변경(매니페스트 `task` 축·뷰 파리티)이라 선택하지 않았다. finalize 는 이 워커가 lock 을
        // 풀 때까지 기다린 뒤 병합하고, 지운 캐시를 병합된 `.env` 로 다시 만든다(다시 만들 수
        // 없는 환경이면 지운 채 두어 다음 요청이 병합된 `.env` 로 부팅한다).
        $tasks[] = ['id' => 'config_cache', 'function' => 'optimizeConfigCacheSSE', 'best_effort' => true];

        // 라우트 캐시도 complete_flag 이후에 최초 생성한다 — 설치 도중 확장 install·
        // activate 가 부른 RouteCacheHelper::rebuild() 는 installer_completed 가 서기
        // 전이라 clear 만 하고 재생성을 건너뛰고, 완료 후에는 재생성 트리거가 확장
        // 라이프사이클에만 묶여 있어 이 지점이 없으면 아무도 굽지 않는다.
        // best_effort — 실패해도 설치는 완료(비캐시 부팅은 느릴 뿐 정상).
        $tasks[] = ['id' => 'route_cache', 'function' => 'optimizeRouteCacheSSE', 'best_effort' => true];

        // 부트스트랩 리소스 정적 게시(bake) 최초 수행 — 없으면 첫 방문자가 자가 치유(1회 API
        // 폴백)를 겪는다. best_effort — 실패해도 설치는 완료.
        $tasks[] = ['id' => 'static_publish', 'function' => 'publishStaticCacheSSE', 'best_effort' => true];

        foreach ($tasks as &$task) {
            $task['target'] = $task['target'] ?? null;
            $task['args'] = $task['args'] ?? [];
            $task['best_effort'] = ! empty($task['best_effort']);
            $task['skippable'] = in_array($task['id'], $skippable, true);
            $task['key'] = $task['target'] !== null ? "{$task['id']}:{$task['target']}" : $task['id'];
        }
        unset($task);

        return $tasks;
    }
}

if (! function_exists('installerTaskIsBestEffort')) {
    /**
     * 완료 마커 키가 best_effort 태스크인지 태스크 목록에서 판정한다.
     *
     * 목록이 SSoT 다 — 여기에 id 를 다시 열거하면 목록이 바뀔 때 한쪽만 갱신되어,
     * 「실패해도 지나가는 태스크」와 「설치를 세우는 태스크」의 경계가 경로마다 달라진다.
     *
     * @param  string  $completedKey  태스크 완료 마커 키 (확장은 "id:target")
     * @return bool best_effort 여부 (목록에 없으면 false)
     */
    function installerTaskIsBestEffort(string $completedKey): bool
    {
        foreach (buildInstallationTaskList() as $task) {
            if ($task['key'] === $completedKey) {
                return ! empty($task['best_effort']);
            }
        }

        return false;
    }
}

if (! function_exists('installerTaskDisplayName')) {
    /**
     * 태스크의 사람 말 표시 이름 (로그·오류 문구용).
     *
     * @param  array<string, mixed>  $task  태스크 항목
     * @return string 표시 이름
     */
    function installerTaskDisplayName(array $task): string
    {
        $taskName = lang("task_{$task['id']}");

        return $task['target'] !== null ? "{$taskName} ({$task['target']})" : $taskName;
    }
}

if (! function_exists('handleInstallationTaskFailure')) {
    /**
     * 태스크 실패 처리 — 롤백 · 상태 failed 기록 · 수동 명령 안내 · error 이벤트.
     *
     * 세 진행 모드가 같은 처리를 공유한다. 이 함수가 끝나면 installation_status 는 failed 다.
     *
     * @param  array<string, mixed>  $task  실패한 태스크
     * @param  array<string, mixed>  $result  태스크 함수 반환값
     */
    function handleInstallationTaskFailure(array $task, array $result): void
    {
        $taskId = $task['id'];
        $target = $task['target'];
        $completedKey = $task['key'];
        $state = getInstallationState();
        $displayName = installerTaskDisplayName($task);
        $rollbackFailure = null;

        if (empty($result['rollback_done'])) {
            sendSSEEvent('log', ['message' => lang('failed_rollback_start', ['task' => $displayName])]);

            $rollbackResult = rollbackTask($taskId, $state);

            sendRollbackOutputSSE($rollbackResult);

            if ($rollbackResult['success']) {
                sendSSEEvent('log', ['message' => lang('failed_rollback_success', ['message' => $rollbackResult['message']])]);
            } else {
                sendSSEEvent('log', ['message' => lang('failed_rollback_failed', ['message' => $rollbackResult['message']])]);

                $dbTasks = ['db_migrate', 'db_seed'];
                if (in_array($taskId, $dbTasks)) {
                    sendSSEEvent('log', ['message' => lang('failed_rollback_manual_cleanup')]);
                    sendSSEEvent('log', ['message' => lang('failed_rollback_manual_cleanup_detail')]);

                    $rollbackFailure = [
                        'message_key' => 'failed_rollback_manual_cleanup',
                        'detail_key' => 'failed_rollback_manual_cleanup_detail',
                    ];

                    sendSSEEvent('rollback_failed', [
                        'message' => lang('failed_rollback_manual_cleanup'),
                        'detail' => lang('failed_rollback_manual_cleanup_detail'),
                    ]);
                } else {
                    sendSSEEvent('log', ['message' => lang('failed_rollback_retry')]);
                    sendSSEEvent('log', ['message' => lang('failed_rollback_retry_detail')]);

                    $rollbackFailure = [
                        'message_key' => 'failed_rollback_retry',
                        'detail_key' => 'failed_rollback_retry_detail',
                    ];

                    sendSSEEvent('rollback_failed', [
                        'message' => lang('failed_rollback_retry'),
                        'detail' => lang('failed_rollback_retry_detail'),
                    ]);
                }
            }
        }

        // 롤백이 state 를 갱신했을 수 있다 (completed 마커 제거 등) — 재로드 후 기록
        $state = getInstallationState();
        // 중단이 확정된 설치는 뒤따르는 태스크 실패로 「실패」가 되지 않는다. 중단은 도는 태스크가
        // 있을 때만 가능하고 그 태스크는 ignore_user_abort 로 끝까지 가므로, 그 마지막 태스크가
        // 실패하면 status 만 failed 로 뒤집혀 [중단] 을 누른 운영자에게 실패 화면 + [다시 시도] 가 뜬다.
        $state['installation_status'] = ($state['installation_status'] ?? '') === 'aborted' ? 'aborted' : 'failed';
        $state['failed_task'] = $completedKey;
        $state['failed_task_target'] = $target;
        $state['failed_task_skippable'] = ! empty($task['skippable']);
        $state['error_message_key'] = $result['message_key'] ?? null;
        // 자리표시자 치환값 — 새로고침 복원은 키만 보고 번역하므로(치환된 문장은
        // state 에 없다) 이 값이 없으면 화면에 `:errors` 같은 토큰이 그대로 남는다.
        $state['error_message_params'] = $result['message_params'] ?? null;
        $state['error_detail'] = $result['detail'] ?? null;

        if (! empty($result['rollback_done'])) {
            $state['rollback_failure'] = $result['rollback_failure'] ?? null;
        } else {
            $state['rollback_failure'] = $rollbackFailure;
        }

        $manualCommands = getManualCommands($taskId, $target);
        $state['manual_commands'] = $manualCommands;

        // 저장 실패를 버리면 status 가 running 인 채 failed_task 가 비어 [건너뛰고 계속] 이
        // 원리상 불가능해진다 — 그 상태가 오류 없이 남는 것이 이 결함의 실체다 (17차 #C2).
        installerSaveStateOrReport($state, 'task_failure');

        addLog(lang('log_installation_task_failed', [
            'task' => $displayName,
            'message' => $result['message'] ?? lang('log_installation_failed'),
        ]));

        // 수동 복구 명령은 **진행 기록에 넣지 않는다** — §12.0 W3 「진행 기록은 사람 말로 쓴다
        // (명령·클래스 이름 금지)」. 목록은 아래 error 이벤트의 `manual_commands` 로 그대로
        // 나가고, 실패 화면이 접기 영역에 그린다 (10차). 필요한 사람은 펼쳐서 복사한다.

        sendSSEEvent('error', [
            'message' => $result['message'] ?? lang('log_installation_failed'),
            'message_key' => $result['message_key'] ?? 'log_installation_failed',
            'message_params' => $result['message_params'] ?? null,
            'error' => $result['detail'] ?? null,
            'task' => $taskId,
            'target' => $target,
            'skippable' => ! empty($task['skippable']),
            'manual_commands' => $manualCommands,
        ]);
    }
}

if (! function_exists('runSingleInstallationTask')) {
    /**
     * 태스크 하나를 실행하고 결과를 분류한다.
     *
     * 반환 outcome:
     *   - already_completed : completed_tasks 에 있어 건너뜀 (건너뛴 태스크 포함)
     *   - done              : 성공
     *   - best_effort_failed: 실패했으나 best_effort 라 계속 진행
     *   - failed            : 실패 — 상태는 failed 로 기록됨 (handleInstallationTaskFailure)
     *   - aborted           : 사용자 중단 / 연결 끊김
     *
     * @param  array<string, mixed>  $task  buildInstallationTaskList() 의 항목
     * @param  array<int, string>  $completedTasks  현재 completed_tasks
     * @param  array<int, string>  $skippedTasks  현재 skipped_tasks (운영자가 건너뛴 것)
     * @param  array<int, string>  $bestEffortTasks  현재 best_effort_failed_tasks (실패했지만 필수가 아니라 지나간 것)
     * @return array{outcome: string, task: string, target: string|null, result?: array<string, mixed>} 실행 결과
     */
    function runSingleInstallationTask(array $task, array $completedTasks, array $skippedTasks = [], array $bestEffortTasks = []): array
    {
        $taskId = $task['id'];
        $target = $task['target'];
        $completedKey = $task['key'];
        $functionName = $task['function'];
        $args = $task['args'];
        $bestEffort = $task['best_effort'];

        if (in_array($completedKey, $completedTasks, true)) {
            $displayName = installerTaskDisplayName($task);
            // 운영자가 건너뛴 것과 best-effort 로 실패해 지나간 것은 화면에서 같게 보이면
            // 안 된다 — 운영자는 건드린 적이 없는데 "건너뛰었습니다" 를 읽게 된다.
            $bestEffortFailed = in_array($completedKey, $bestEffortTasks, true);
            $skippedByUser = ! $bestEffortFailed && in_array($completedKey, $skippedTasks, true);
            $skipped = $skippedByUser || $bestEffortFailed;

            sendSSEEvent('task_complete', [
                'task' => $taskId,
                'target' => $target,
                'skipped' => $skipped,
                'message' => match (true) {
                    $bestEffortFailed => lang('log_task_best_effort_skipped', ['task' => $displayName]),
                    $skippedByUser => lang('log_task_skipped_by_user', ['task' => $displayName]),
                    default => lang('log_already_completed', ['task' => $displayName]),
                },
            ]);

            return ['outcome' => 'already_completed', 'task' => $taskId, 'target' => $target];
        }

        // 워커가 fatal 로 끊기면 shutdown 처리기가 어느 작업(대상 포함)에서 끊겼는지 기록한다.
        $GLOBALS['g7_installer_running_task'] = ['id' => $taskId, 'target' => $target];

        $result = empty($args) ? $functionName() : $functionName(...$args);

        if (isset($result['aborted']) && $result['aborted']) {
            sendSSEEvent('aborted', [
                'message' => lang('abort_installation_stopped'),
                'task' => $taskId,
                'target' => $target,
            ]);
            addLog(lang('abort_installation_stopped'));

            return ['outcome' => 'aborted', 'task' => $taskId, 'target' => $target, 'result' => $result];
        }

        if (! $result['success']) {
            // best-effort task (예: 번들 언어팩 설치) — 실패 시 경고 로그만 남기고 계속 진행
            if ($bestEffort) {
                $warnMsg = lang('warning_best_effort_task_failed', [
                    'task' => installerTaskDisplayName($task),
                    'error' => (string) ($result['message'] ?? $result['detail'] ?? lang('unknown_error_occurred')),
                ]);
                sendSSEEvent('log', ['message' => $warnMsg]);
                addLog($warnMsg);

                // completed 가 아니라 best_effort_failed_tasks 에 기록한다 — 단계별 실행이 다음 작업으로
                // 넘어가려면 "지나간 작업" 으로 셈해야 하고(아니면 같은 작업을 영원히 다시 뽑는다),
                // 재시도 때는 이 목록이 비워져 다시 시도된다. 화면에는 경고 표시로 지나간다.
                markTaskBestEffortFailed($completedKey);
                sendSSEEvent('task_complete', [
                    'task' => $taskId,
                    'target' => $target,
                    'message' => $warnMsg,
                    'skipped' => true,
                    'best_effort_failed' => true,
                ]);

                return ['outcome' => 'best_effort_failed', 'task' => $taskId, 'target' => $target, 'result' => $result];
            }

            handleInstallationTaskFailure($task, $result);

            return ['outcome' => 'failed', 'task' => $taskId, 'target' => $target, 'result' => $result];
        }

        // 완료 마커가 실제로 남았는지 확인한다 (11차 #A5).
        //
        // markTaskCompleted() 는 저장 성공 여부를 돌려주는데 호출부 19곳이 전부 그 값을
        // 버렸다. 디스크가 차거나 부분 쓰기가 나면 태스크는 성공했는데 마커만 없는 상태가
        // 되고, 단계별 실행은 completed_tasks 에 없는 첫 작업을 다음 작업으로 뽑으므로
        // **같은 태스크를 무한 반복**한다. 화면에는 오류가 없고 진행률만 제자리이며,
        // 클라이언트의 정지 감지는 task_done 마다 타이머를 재무장하므로 영원히 발화하지
        // 않는다. 호출부 19곳을 각자 고치는 대신 오케스트레이터 한 지점에서 확인한다.
        $persisted = getInstallationState();
        $persistedTasks = array_merge(
            (array) ($persisted['completed_tasks'] ?? []),
            (array) ($persisted['skipped_tasks'] ?? []),
        );

        if (! in_array($completedKey, $persistedTasks, true)) {
            $saveFailure = [
                'success' => false,
                'message' => lang('error_task_marker_not_persisted', ['task' => installerTaskDisplayName($task)]),
                'message_key' => 'error_task_marker_not_persisted',
                'message_params' => ['task' => installerTaskDisplayName($task)],
            ];

            handleInstallationTaskFailure($task, $saveFailure);

            return ['outcome' => 'failed', 'task' => $taskId, 'target' => $target, 'result' => $saveFailure];
        }

        return ['outcome' => 'done', 'task' => $taskId, 'target' => $target, 'result' => $result];
    }
}

if (! function_exists('finalizeInstallationRun')) {
    /**
     * 모든 태스크가 끝났을 때의 마무리 — completed 이벤트 + 로그.
     */
    function finalizeInstallationRun(): void
    {
        // 세션 단계 쓰기는 여기에 두지 않는다 (13차 #F3). 세 모드 모두 이 시점에는 응답이
        // 이미 나갔거나(폴링·SSE) 세션이 이 요청의 것이 아니라 쓰기가 화면에 반영되지 않는다.
        // 실제 보장은 install-process.php(`$_SESSION['installer_current_step'] = 5`)와
        // request-handler.php 의 단계 보정이 한다.

        // 완료 상태를 여기서도 멱등 기록한다 (11차 #A6).
        //
        // `installation_status = 'completed'` 를 쓰는 지점은 complete_flag 태스크 본문
        // 하나뿐인데, best-effort 인 config_cache 가 **그 뒤**에 온다. config_cache 만
        // 실패한 뒤 재시도하면 complete_flag 는 이미 마커가 있어 재실행되지 않으므로,
        // 설치가 완주해도 상태는 재시작이 써 둔 `running` 으로 남는다. 브라우저가 finalize
        // 호출 전에 닫히면 그 뒤로 조회·요약이 계속 「진행 중」을 보고한다.
        $state = getInstallationState();
        if (($state['installation_status'] ?? null) !== 'completed') {
            $state['installation_status'] = 'completed';
            $state['installation_completed_at'] = $state['installation_completed_at'] ?? date('Y-m-d\TH:i:s\Z');
            saveInstallationState($state);
        }

        sendSSEEvent('completed', [
            'message' => lang('log_installation_completed'),
            // 고정 `/install/` 금지 — 서브경로 배치(문서루트=프로젝트 루트의 `/public/install` 등)에서
            // 완료 리다이렉트가 존재하지 않는 경로로 떨어진다 (G3 입력 ②). config.php 가 API
            // 컨텍스트에서도 옳은 값을 만들도록 보정되어 있다.
            'redirect' => INSTALLER_BASE_URL.'/',
        ]);

        addLog(lang('log_all_tasks_completed'));
    }
}

if (! function_exists('recordInstallationRunException')) {
    /**
     * 태스크 실행 중 잡히지 않은 예외를 상태와 이벤트에 기록한다.
     *
     * @param  Throwable  $e  예외
     */
    function recordInstallationRunException(Throwable $e): void
    {
        logInstallationError(lang('error_worker_exception'), $e);

        $state = getInstallationState();
        $failedKey = $state['current_task'] ?? null;

        // 태스크 본문 밖으로 나온 예외에도 best_effort 계약이 적용된다 — 적용하지 않으면
        // complete_flag 뒤에 도는 config_cache 의 예외가 이미 기록된 「완료」를 실패로 덮는다.
        if ($failedKey !== null && installerTaskIsBestEffort($failedKey)) {
            markTaskBestEffortFailed($failedKey);

            $state = getInstallationState();
            $state['current_task'] = null;
            $state['error_message_params'] = null;
            saveInstallationState($state);

            $warnMsg = lang('warning_best_effort_task_failed', [
                'task' => installerTaskKeyDisplayName($failedKey),
                'error' => $e->getMessage(),
            ]);
            addLog($warnMsg);
            sendSSEEvent('task_complete', [
                'task' => $failedKey,
                'target' => null,
                'message' => $warnMsg,
                'skipped' => true,
                'best_effort_failed' => true,
            ]);

            return;
        }

        // 중단이 확정된 설치는 예외로도 「실패」가 되지 않는다 (handleInstallationTaskFailure 와 같은 기준).
        $state['installation_status'] = ($state['installation_status'] ?? '') === 'aborted' ? 'aborted' : 'failed';
        $state['failed_task'] = $failedKey;
        $state['error_message_key'] = 'error_unexpected_exception';
        // 직전 실패의 치환값을 남기면 새 키의 자리표시자가 엉뚱한 값으로 채워진다
        // (초기화 지점 셋 — resetState · 재시작 · 여기 — 이 같은 키 집합을 비운다).
        $state['error_message_params'] = null;
        $state['error_detail'] = $e->getMessage();

        // 실패 경로(handleInstallationTaskFailure)와 같은 표시 정보를 남긴다 — 남기지 않으면
        // 예외로 죽은 선택 확장에서 [건너뛰고 계속] 이 나타나지 않아, 같은 실패가 어떻게
        // 끝났느냐에 따라 운영자의 선택지가 달라진다.
        [$failedId, $failedTarget] = ($failedKey !== null && str_contains($failedKey, ':'))
            ? explode(':', $failedKey, 2)
            : [$failedKey, null];
        $state['failed_task_target'] = $failedTarget;
        $skippable = $failedId !== null
            && in_array($failedId, installerSkippableTaskIds(), true);
        $state['failed_task_skippable'] = $skippable;
        $manualCommands = $failedId !== null ? getManualCommands($failedId, $failedTarget) : [];
        $state['manual_commands'] = $manualCommands;

        // 실패 기록 경로와 같은 이유로 결과를 확인한다 (17차 #C2).
        installerSaveStateOrReport($state, 'run_exception');

        addLog(lang('log_installation_exception', ['error' => $e->getMessage()]));

        // 이벤트에도 같은 표시 정보를 싣는다 — state 에만 남기면 SSE·stepwise 는 그 값을 못 받아
        // [건너뛰고 계속]·수동 복구 안내가 사라지고, 새로고침(state 직독) 해야 나타난다.
        sendSSEEvent('error', [
            'message' => lang('log_installation_failed'),
            'message_key' => 'error_unexpected_exception',
            'error' => $e->getMessage(),
            'task' => $failedId,
            'target' => $failedTarget,
            'skippable' => $skippable,
            'manual_commands' => $manualCommands,
        ]);
    }
}

if (! function_exists('installerResetTaskTimeLimit')) {
    /**
     * 설치 작업 하나를 시작하기 전에 PHP 실행 시간 카운터를 다시 건다.
     *
     * set_time_limit() 은 호출 시점부터 다시 센다. 워커 진입 시 한 번만 걸면 전체 설치 시간이
     * 제한이 되어, 확장을 많이 고른 설치가 중간 작업에서 "Maximum execution time" 으로 죽는다
     * (Windows 의 PHP 는 명령 대기·파일 복사 시간까지 경과 시간으로 잰다).
     *
     * 막힌 호스팅에서는 부르지 않는다 — `@` 는 차단 함수의 Error 를 삼키지 않는다 (13차 환경#1).
     *
     * @return bool set_time_limit 성공 여부 (disable_functions 등으로 막히면 false)
     */
    function installerResetTaskTimeLimit(): bool
    {
        if (! installerHasFunction('set_time_limit')) {
            return false;
        }

        return @set_time_limit((int) INSTALLER_TASK_TIME_LIMIT);
    }
}

if (! function_exists('runInstallationTasks')) {
    /**
     * 설치 작업 전체 실행 (SSE · 폴링 모드 — 한 요청 안에서 끝까지).
     *
     * 진입점(install-worker.php / install-process.php 폴링 인라인)에서
     * ProgressEmitter 등록 후 이 함수를 호출합니다.
     */
    function runInstallationTasks(): void
    {
        // 연결 성공 이벤트 (SSE 전용 — NullEmitter는 no-op)
        getProgressEmitter()->emit('connected', ['message' => lang('sse_connection_established')]);

        try {
            $state = getInstallationState();
            $completedTasks = $state['completed_tasks'] ?? [];
            $skippedTasks = $state['skipped_tasks'] ?? [];
            // 표시 판정용 — 운영자가 건너뛴 것과 구분해야 하므로 원본을 그대로 들고 간다
            $bestEffortTasks = $state['best_effort_failed_tasks'] ?? [];
            // best-effort 로 실패한 태스크는 이번 실행에서 이미 지나간 것으로 본다 —
            // stepwise 의 pending 필터와 같은 집합이어야 SSE→폴링 재개가 그 태스크를
            // 한 번 더 시도하지 않는다 (멱등이라 무해하지만 두 경로의 규칙이 갈린다).
            //
            // 건너뜀 목록($skippedTasks)에는 넣지 않는다 — 그 목록은 「운영자가 건너뛴 것」
            // 전용이고, 표시 판정(runSingleInstallationTask)은 best_effort_failed_tasks 를
            // 먼저 본다. 여기서 넣어 봐야 그 값은 정의상 읽히지 않는다 (12차 #A12).
            foreach ($bestEffortTasks as $bestEffortKey) {
                if (! in_array($bestEffortKey, $completedTasks, true)) {
                    $completedTasks[] = $bestEffortKey;
                }
            }

            foreach (buildInstallationTaskList() as $task) {
                if (checkAbortStatusSSE()) {
                    sendSSEEvent('aborted', [
                        'message' => lang('abort_installation_stopped'),
                        'task' => $task['id'],
                        'target' => $task['target'],
                    ]);
                    addLog(lang('abort_installation_stopped'));

                    return;
                }

                // 작업마다 실행 시간 카운터를 다시 건다 — 전체 설치가 아니라 작업 하나가
                // INSTALLER_TASK_TIME_LIMIT 를 넘을 때만 끊긴다. SSE·폴링은 한 요청이 모든 작업을
                // 도는 이 루프에서만 건다 — 단계별 실행(runNextInstallationTask)은 요청 1개 = 작업 1개이고
                // 요청 수명을 lock 선언과 함께 run-task.php 가 정하므로 여기서 늘리지 않는다.
                installerResetTaskTimeLimit();

                $run = runSingleInstallationTask($task, $completedTasks, $skippedTasks, $bestEffortTasks);

                if (in_array($run['outcome'], ['aborted', 'failed'], true)) {
                    return;
                }
            }

            finalizeInstallationRun();
        } catch (InstallerWorkerLockLostException $e) {
            // 점유자가 바뀐 것이지 설치가 실패한 것이 아니다 — stepwise 오케스트레이터와
            // 같은 규칙이다. 실패로 변환하면 승자 요청의 진행 위에 installation_status=failed
            // 를 덮어써 멀쩡히 도는 설치가 실패로 보인다. 현재 이 경로의 emitter 는 예외를
            // 던지지 않지만, 두 오케스트레이터에 다른 규칙을 두면 그 차이가 곧 우회로다.
            throw $e;
        } catch (Throwable $e) {
            recordInstallationRunException($e);
        }
    }
}

if (! function_exists('installerRemainingTaskCount')) {
    /**
     * 이 요청 뒤에 남는 태스크 수 — `remaining` 응답 필드의 유일한 계산 지점 (13차 F1/F2).
     *
     * 종전에는 분기마다 기준이 달랐다: 실행 전 중단은 `count($pending)`, 실행 후는 성공·실패·
     * 중단을 가리지 않고 `count($pending) - 1`. 같은 이름이 「아직 남은 수」와 「하나를 시도한
     * 뒤의 수」를 오갔고, 현재 소비처가 없어 그 어긋남이 드러나지도 않았다.
     *
     * 기준을 하나로 고정한다 — **목록에서 실제로 빠지는 것은 성공한 태스크뿐**이다. 실패한
     * 태스크는 재시도 대상으로 남아 있고, 중단은 아무것도 소비하지 않는다.
     *
     * @param  string  $outcome  이번 요청의 결과 (task_done|failed|aborted|completed)
     * @return int 남은 태스크 수
     */
    function installerRemainingTaskCount(int $pendingCount, string $outcome): int
    {
        $pendingCount = max(0, $pendingCount);

        return $outcome === 'task_done' ? max(0, $pendingCount - 1) : $pendingCount;
    }
}

if (! function_exists('runNextInstallationTask')) {
    /**
     * 단계별(stepwise) 진행 — 아직 끝나지 않은 첫 태스크 하나만 실행한다.
     *
     * 한 HTTP 요청이 이 함수를 한 번 부른다 (api/run-task.php). 어떤 태스크가 다음인지는
     * 서버의 completed_tasks 가 정한다 — 클라이언트는 인덱스를 보내지 않는다 (목록은
     * 선택 확장에 따라 달라지고, 클라이언트와 서버가 각자 세면 어긋난다).
     *
     * 반환 outcome:
     *   - task_done          : 태스크 1개 성공 (또는 best-effort 실패) — 다음 요청에서 계속
     *   - completed          : 남은 태스크가 없어 마무리까지 끝냄
     *   - failed             : 태스크 실패 — 상태 failed (재시도/건너뛰기는 클라이언트 선택)
     *   - aborted            : 중단 감지
     *
     * @return array{outcome: string, task: string|null, target: string|null, remaining: int} 실행 결과
     */
    function runNextInstallationTask(): array
    {
        try {
            $state = getInstallationState();
            $completedTasks = $state['completed_tasks'] ?? [];
            $skippedTasks = $state['skipped_tasks'] ?? [];
            $bestEffortFailed = $state['best_effort_failed_tasks'] ?? [];
            $bestEffortTasks = $bestEffortFailed;
            $tasks = buildInstallationTaskList();

            // 실패한 best-effort 작업도 "지나간 작업" 이다 — 제외하지 않으면 같은 작업을 영원히 다시 뽑는다
            $pending = array_values(array_filter(
                $tasks,
                static fn (array $task): bool => ! in_array($task['key'], $completedTasks, true)
                    && ! in_array($task['key'], $bestEffortFailed, true),
            ));

            if ($pending === []) {
                finalizeInstallationRun();

                return ['outcome' => 'completed', 'task' => null, 'target' => null, 'remaining' => installerRemainingTaskCount(0, 'completed')];
            }

            if (checkAbortStatusSSE()) {
                $first = $pending[0];
                sendSSEEvent('aborted', [
                    'message' => lang('abort_installation_stopped'),
                    'task' => $first['id'],
                    'target' => $first['target'],
                ]);
                addLog(lang('abort_installation_stopped'));

                return [
                    'outcome' => 'aborted',
                    'task' => $first['id'],
                    'target' => $first['target'],
                    'remaining' => installerRemainingTaskCount(count($pending), 'aborted'),
                ];
            }

            $task = $pending[0];
            $run = runSingleInstallationTask($task, $completedTasks, $skippedTasks, $bestEffortTasks);
            $pendingCount = count($pending);
            $outcome = match ($run['outcome']) {
                'aborted', 'failed' => $run['outcome'],
                default => 'task_done',
            };

            return [
                'outcome' => $outcome,
                'task' => $task['id'],
                'target' => $task['target'],
                'remaining' => installerRemainingTaskCount($pendingCount, $outcome),
            ];
        } catch (InstallerWorkerLockLostException $e) {
            // 점유자가 바뀐 것이지 설치가 실패한 것이 아니다 — run-task.php 가 busy 로 응답한다
            throw $e;
        } catch (Throwable $e) {
            recordInstallationRunException($e);

            // 상태를 **다시 읽어** 돌려준다 (9차 #L6). `$state` 는 이 요청 진입 시점의 스냅샷이라
            // updateCurrentTask() 가 파일에만 쓴 갱신이 반영돼 있지 않다 — 그대로 쓰면 응답의
            // `task` 는 직전 태스크를 가리키고, 같은 예외를 기록한 state 는 현재 태스크를 가리켜
            // 두 값이 서로 다른 태스크를 말한다. 정상 분기가 돌려주는 것과 같은 bare id 로 맞춘다.
            $currentKey = getInstallationState()['current_task'] ?? null;
            [$failedId, $failedTarget] = ($currentKey !== null && str_contains($currentKey, ':'))
                ? explode(':', $currentKey, 2)
                : [$currentKey, null];

            return ['outcome' => 'failed', 'task' => $failedId, 'target' => $failedTarget, 'remaining' => 0];
        }
    }
}
