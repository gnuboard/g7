<?php

/**
 * 그누보드7 웹 인스톨러 - 설치 작업 실행 코어 (SSE/폴링 공용)
 *
 * 설치 task 정의와 실행 로직을 모드와 무관하게 제공합니다.
 * 진입점(install-worker.php SSE 모드, install-process.php 폴링 모드)이
 * ProgressEmitter를 등록한 뒤 runInstallationTasks()를 호출합니다.
 *
 * @package G7\Installer
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/installer-state.php';
require_once __DIR__ . '/progress-emitter.php';
require_once __DIR__ . '/../api/rollback-functions.php';

// ============================================================================
// Emitter 호환 래퍼 — 기존 task 함수의 sendSSEEvent() 호출을 그대로 유지하면서
// 내부적으로 현재 등록된 ProgressEmitter로 delegate합니다.
// ============================================================================

if (!function_exists('sendSSEEvent')) {
    /**
     * 이벤트 송출 (SSE 모드/폴링 모드 공통).
     *
     * @param string $event 이벤트 타입
     * @param array $data 이벤트 데이터
     */
    function sendSSEEvent(string $event, array $data): void
    {
        getProgressEmitter()->emit($event, $data);
    }
}

if (!function_exists('sendRollbackOutputSSE')) {
    /**
     * 롤백 실행 결과를 로그 이벤트로 출력합니다.
     *
     * @param array $rollbackResult rollbackDbMigrate() 등의 반환값
     */
    function sendRollbackOutputSSE(array $rollbackResult): void
    {
        if (!empty($rollbackResult['output']) && is_array($rollbackResult['output'])) {
            foreach ($rollbackResult['output'] as $line) {
                if (!empty(trim($line))) {
                    sendSSEEvent('log', ['message' => $line]);
                }
            }
        }
    }
}

if (!function_exists('checkAbortStatusSSE')) {
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
            if ($currentTask && !in_array($currentTask, $state['completed_tasks'] ?? [])) {
                addLog(lang('abort_rollback_start', ['task' => $currentTask]));

                $rollbackResult = rollbackTask($currentTask, $state);

                if (!empty($rollbackResult['output']) && is_array($rollbackResult['output'])) {
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

            $state['installation_status'] = 'aborted';
            $state['current_task'] = null;
            $state['abort_reason'] = 'Connection aborted unexpectedly';
            $state['aborted_at'] = date('Y-m-d H:i:s');

            if (isset($rollbackResult) && !$rollbackResult['success']) {
                $state['rollback_failure'] = [
                    'task' => $currentTask,
                    'message' => $rollbackResult['message'] ?? null,
                    'message_key' => 'failed_rollback_manual_cleanup',
                    'detail_key' => 'failed_rollback_manual_cleanup_detail',
                ];
            }

            saveInstallationState($state);
            return true;
        }

        // 2. state.json에서 중단 여부 확인
        $state = getInstallationState();
        if (isset($state['installation_status']) && $state['installation_status'] === 'aborted') {
            $currentTask = $state['current_task'] ?? 'unknown';
            addLog(lang('abort_by_user', ['task' => $currentTask]));
            return true;
        }

        return false;
    }
}

// ============================================================================
// Task 함수 정의 (install-worker.php에서 이관됨 — 동작 동일)
// ============================================================================

if (!function_exists('getPhpBinary')) {
    function getPhpBinary(): string
    {
        $state = getInstallationState();
        return $state['config']['php_binary'] ?? 'php' ?: 'php';
    }
}

if (!function_exists('getComposerCommand')) {
    function getComposerCommand(): string
    {
        $state = getInstallationState();
        $composerBinary = $state['config']['composer_binary'] ?? '';

        if ($composerBinary) {
            if (str_contains($composerBinary, ' ')) {
                return $composerBinary;
            }
            return str_ends_with($composerBinary, '.phar')
                ? escapeshellarg(getPhpBinary()) . ' ' . escapeshellarg($composerBinary)
                : escapeshellarg($composerBinary);
        }

        return 'composer';
    }
}

if (!function_exists('getComposerCommandForDisplay')) {
    function getComposerCommandForDisplay(): string
    {
        $state = getInstallationState();
        $composerBinary = $state['config']['composer_binary'] ?? '';

        if ($composerBinary) {
            if (str_contains($composerBinary, ' ')) {
                return $composerBinary;
            }
            return str_ends_with($composerBinary, '.phar')
                ? getPhpBinary() . ' ' . $composerBinary
                : $composerBinary;
        }

        return 'composer';
    }
}

if (!function_exists('vendorModeOptionFromState')) {
    /**
     * 인스톨러 state 의 vendor_mode 를 artisan 옵션 문자열로 변환합니다.
     *
     * 사용자가 Step 3 에서 선택한 vendor_mode 를 module:install/plugin:install
     * 등 확장 설치 커맨드로 일관되게 전파하기 위함.
     *
     * @return string  ' --vendor-mode=bundled' 같은 문자열 (선행 공백 포함). 미설정 시 빈 문자열.
     */
    function vendorModeOptionFromState(): string
    {
        $state = getInstallationState();
        $mode = $state['config']['vendor_mode'] ?? '';

        if (! in_array($mode, ['auto', 'composer', 'bundled'], true)) {
            return '';
        }

        return ' --vendor-mode='.escapeshellarg($mode);
    }
}

if (!function_exists('checkComposerSSE')) {
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

        $state = getInstallationState();
        $vendorMode = $state['config']['vendor_mode'] ?? 'auto';

        // bundled 모드: composer 불필요 — 즉시 스킵
        if ($vendorMode === 'bundled') {
            sendSSEEvent('log', ['message' => lang('log_composer_check_skipped_bundled')]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);
            markTaskCompleted('composer_check');
            sendSSEEvent('task_complete', ['task' => 'composer_check', 'message' => lang('log_composer_check_skipped_bundled')]);

            return ['success' => true];
        }

        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        $composerHome = BASE_PATH . '/storage/composer';
        if (!is_dir($composerHome)) {
            @mkdir($composerHome, 0755, true);
        }
        putenv('COMPOSER_HOME=' . $composerHome);
        putenv('HOME=' . $composerHome);

        $output = [];
        $returnCode = 0;
        $composerCmd = getComposerCommand();
        exec($composerCmd . ' --version 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMessage = implode("\n", $output);

            // auto 모드: composer 미설치 시 번들 폴백 가능하면 비치명적 처리
            if ($vendorMode === 'auto' && file_exists(BASE_PATH . '/vendor-bundle.zip')) {
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

        foreach ($output as $line) {
            sendSSEEvent('log', ['message' => $line]);
        }

        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('composer_check');
        sendSSEEvent('task_complete', ['task' => 'composer_check', 'message' => lang('log_composer_check_success')]);

        return ['success' => true];
    }
}

if (!function_exists('installVendorBundleSSE')) {
    /**
     * vendor-bundle.zip 을 추출하여 vendor/ 를 구성합니다 (bundled 모드용).
     */
    function installVendorBundleSSE(): array
    {
        require_once __DIR__ . '/vendor-bundle-installer.php';

        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = 'Vendor 번들 추출';
        updateCurrentTask('composer_install');
        sendSSEEvent('task_start', ['task' => 'composer_install', 'name' => $taskName]);
        sendSSEEvent('log', ['message' => 'Vendor 번들 추출을 시작합니다...']);

        $integrity = verifyVendorBundle(BASE_PATH);
        if (!$integrity['valid']) {
            $errorList = implode(', ', $integrity['errors']);
            sendSSEEvent('log', ['message' => "번들 무결성 검증 실패: {$errorList}"]);
            logInstallationError("vendor-bundle.zip 무결성 검증 실패: {$errorList}");
            return [
                'success' => false,
                'message' => "vendor-bundle.zip 무결성 검증 실패: {$errorList}",
            ];
        }

        $packageCount = (int) ($integrity['meta']['package_count'] ?? 0);
        sendSSEEvent('log', ['message' => "vendor-bundle.zip 검증 통과 ({$packageCount} packages)"]);
        sendSSEEvent('log', ['message' => 'vendor/ 디렉토리에 추출 중...']);

        $result = extractVendorBundle(BASE_PATH, BASE_PATH);

        if (!$result['success']) {
            $error = $result['error'] ?? 'unknown';
            sendSSEEvent('log', ['message' => "vendor 번들 추출 실패: {$error}"]);
            logInstallationError("vendor 번들 추출 실패: {$error}");
            return [
                'success' => false,
                'message' => "vendor 번들 추출 실패: {$error}",
            ];
        }

        sendSSEEvent('log', ['message' => "vendor 번들 추출 완료 ({$result['package_count']} packages)"]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('composer_install');
        sendSSEEvent('task_complete', ['task' => 'composer_install', 'message' => 'vendor 번들 추출 완료']);

        return ['success' => true, 'mode' => 'bundled'];
    }
}

if (!function_exists('installComposerDependenciesSSE')) {
    function installComposerDependenciesSSE(): array
    {
        // Vendor 모드에 따라 분기: bundled → vendor-bundle.zip 추출
        $state = getInstallationState();
        $vendorMode = $state['config']['vendor_mode'] ?? 'auto';

        if ($vendorMode === 'bundled') {
            return installVendorBundleSSE();
        }

        if ($vendorMode === 'auto') {
            // composer 가능 여부에 따라 자동 분기
            require_once __DIR__ . '/vendor-bundle-installer.php';
            $composerBinary = $state['config']['composer_binary'] ?? '';
            $phpBinary = $state['config']['php_binary'] ?? 'php';

            if (!canExecuteComposerForInstall($composerBinary, $phpBinary)) {
                // composer 사용 불가 → 번들 zip 존재 여부 확인 후 폴백
                if (file_exists(BASE_PATH . '/vendor-bundle.zip')) {
                    sendSSEEvent('log', ['message' => 'Composer 실행 불가 환경 감지 → 번들 vendor 모드로 자동 전환']);
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

        $vendorExists = is_dir(BASE_PATH . '/vendor') && file_exists(BASE_PATH . '/vendor/autoload.php');
        $lockExists = file_exists(BASE_PATH . '/composer.lock');

        if ($vendorExists && $lockExists) {
            sendSSEEvent('log', ['message' => lang('log_composer_already_installed')]);
            sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            markTaskCompleted('composer_install');
            sendSSEEvent('task_complete', ['task' => 'composer_install', 'message' => lang('log_composer_already_installed')]);
            return ['success' => true];
        }

        if ($vendorExists && !$lockExists) {
            sendSSEEvent('log', ['message' => lang('log_composer_vendor_without_lock')]);
            sendSSEEvent('log', ['message' => lang('log_composer_removing_vendor')]);

            $deleted = deleteDirectory(BASE_PATH . '/vendor');
            if (!$deleted) {
                sendSSEEvent('log', ['message' => lang('log_composer_vendor_delete_failed')]);
            } else {
                sendSSEEvent('log', ['message' => lang('log_composer_vendor_deleted')]);
            }
        }

        if (!$vendorExists && $lockExists) {
            sendSSEEvent('log', ['message' => lang('log_composer_installing_from_lock')]);
        }

        if (!$vendorExists && !$lockExists) {
            sendSSEEvent('log', ['message' => lang('log_composer_fresh_install')]);
        }

        $bootstrapCachePath = BASE_PATH . '/bootstrap/cache/packages.php';
        if (file_exists($bootstrapCachePath)) {
            @unlink($bootstrapCachePath);
            sendSSEEvent('log', ['message' => lang('log_composer_cache_cleared')]);
        }

        chdir(BASE_PATH);

        $composerHome = BASE_PATH . '/storage/composer';
        if (!is_dir($composerHome)) {
            @mkdir($composerHome, 0755, true);
        }

        $env = [];
        foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'APPDATA', 'LOCALAPPDATA', 'USERPROFILE'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }
        $env['COMPOSER_HOME'] = $composerHome;
        $env['HOME'] = $composerHome;

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            if (!isset($env['TEMP']) || !is_dir($env['TEMP']) || !is_writable($env['TEMP'])) {
                $tempDir = BASE_PATH . '/storage/temp';
                if (!is_dir($tempDir)) {
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
            $composerCmd . ' install --no-interaction --no-dev --optimize-autoloader --no-ansi 2>&1',
            $descriptorspec,
            $pipes,
            BASE_PATH,
            $env
        );

        if (!is_resource($process)) {
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

        while (!feof($pipes[1])) {
            if (checkAbortStatusSSE()) {
                proc_terminate($process);
                proc_close($process);
                return ['success' => false, 'aborted' => true];
            }

            $line = fgets($pipes[1]);
            if ($line !== false) {
                $line = trim($line);
                if (!empty($line)) {
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

        markTaskCompleted('composer_install');
        sendSSEEvent('task_complete', ['task' => 'composer_install', 'message' => lang('log_composer_install_success')]);

        return ['success' => true];
    }
}

if (!function_exists('updateEnvFileSSE')) {
    function updateEnvFileSSE(): array
    {
        if (checkAbortStatusSSE()) {
            return ['success' => false, 'aborted' => true];
        }

        $taskName = lang('task_env_update');

        updateCurrentTask('env_update');
        sendSSEEvent('task_start', ['task' => 'env_update', 'name' => $taskName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

        $envPath = BASE_PATH . '/.env';

        if (!file_exists($envPath)) {
            logInstallationError(lang('error_env_not_found'));
            return [
                'success' => false,
                'message' => lang('error_env_not_found'),
                'message_key' => 'error_env_not_found',
            ];
        }

        if (is_writable($envPath)) {
            $content = generateEnvContent();
            if ($content !== null) {
                file_put_contents($envPath, $content);
                @chmod($envPath, 0600);
            }
            sendSSEEvent('log', ['message' => lang('log_env_update_success')]);
        } else {
            sendSSEEvent('log', ['message' => lang('log_env_readonly_skip')]);
        }

        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('env_update');
        sendSSEEvent('task_complete', ['task' => 'env_update', 'message' => lang('log_env_update_success')]);

        return ['success' => true];
    }
}

if (!function_exists('generateApplicationKeySSE')) {
    function generateApplicationKeySSE(): array
    {
        $envPath = BASE_PATH . '/.env';
        if (file_exists($envPath)) {
            $content = file_get_contents($envPath);
            if (preg_match('/^APP_KEY=base64:.{40,}$/m', $content)) {
                $taskName = lang('task_key_generate');

                updateCurrentTask('key_generate');
                sendSSEEvent('task_start', ['task' => 'key_generate', 'name' => $taskName]);
                sendSSEEvent('log', ['message' => lang('log_already_completed', ['task' => $taskName])]);
                sendSSEEvent('log', ['message' => lang('log_separator')]);

                markTaskCompleted('key_generate');
                sendSSEEvent('task_complete', ['task' => 'key_generate', 'message' => lang('log_key_generate_success')]);

                return ['success' => true];
            }
        }

        return executeArtisanCommandSSE(
            artisanCommand: 'key:generate --force',
            taskId: 'key_generate',
            taskNameKey: 'task_key_generate',
            successMsgKey: 'log_key_generate_success',
            errorMsgKey: 'error_key_generate_failed'
        );
    }
}

if (!function_exists('dependencyPrecheckSSE')) {
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

        // 확장 매니페스트 읽기 (모듈/플러그인 — 템플릿은 dependencies 사용 빈도 낮음)
        $scanTargets = [
            'modules' => BASE_PATH . '/modules/_bundled',
            'plugins' => BASE_PATH . '/plugins/_bundled',
            'templates' => BASE_PATH . '/templates/_bundled',
        ];

        foreach ($scanTargets as $type => $baseDir) {
            if (!is_dir($baseDir)) {
                continue;
            }
            $dirs = scandir($baseDir) ?: [];
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') {
                    continue;
                }
                $manifestFile = null;
                if ($type === 'modules' && file_exists("{$baseDir}/{$dir}/module.json")) {
                    $manifestFile = "{$baseDir}/{$dir}/module.json";
                } elseif ($type === 'plugins' && file_exists("{$baseDir}/{$dir}/plugin.json")) {
                    $manifestFile = "{$baseDir}/{$dir}/plugin.json";
                } elseif ($type === 'templates' && file_exists("{$baseDir}/{$dir}/template.json")) {
                    $manifestFile = "{$baseDir}/{$dir}/template.json";
                }
                if (!$manifestFile) {
                    continue;
                }

                $data = json_decode((string) @file_get_contents($manifestFile), true);
                if (!is_array($data)) {
                    continue;
                }
                $identifier = $data['identifier'] ?? $dir;

                // 선택된 확장만 검증 대상
                if (!isset($selectedIdSet[$identifier])) {
                    continue;
                }

                $deps = $data['dependencies'] ?? [];
                if (!is_array($deps)) {
                    continue;
                }

                // 객체형 (modules/plugins 키) 또는 배열형 처리
                $check = [];
                if (isset($deps['modules']) && is_array($deps['modules'])) {
                    foreach ($deps['modules'] as $depId => $_) {
                        $check[] = is_int($depId) ? $_ : $depId;
                    }
                }
                if (isset($deps['plugins']) && is_array($deps['plugins'])) {
                    foreach ($deps['plugins'] as $depId => $_) {
                        $check[] = is_int($depId) ? $_ : $depId;
                    }
                }
                if (!isset($deps['modules']) && !isset($deps['plugins'])) {
                    foreach ($deps as $depId) {
                        if (is_string($depId)) {
                            $check[] = $depId;
                        }
                    }
                }

                foreach ($check as $depId) {
                    if (!isset($selectedIdSet[$depId])) {
                        $missing[] = "{$identifier} → {$depId}";
                    }
                }
            }
        }

        if (!empty($missing)) {
            $errorMessage = lang('dependency_precheck_failed');
            foreach ($missing as $line) {
                sendSSEEvent('log', ['message' => '  - ' . $line]);
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

if (!function_exists('cleanupExistingTablesSSE')) {
    /**
     * 기존 DB 테이블 정리 (이슈 #244 대응).
     *
     * state.json의 existing_db_action 값에 따라 기존 테이블을 삭제합니다.
     * - skip (또는 null): 작업 없이 건너뛰기
     * - drop_tables: FOREIGN_KEY_CHECKS=0 → 모든 테이블 DROP → FOREIGN_KEY_CHECKS=1
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

        $config = $state['config'] ?? [];

        try {
            $pdo = getDatabaseConnection($config, false);
            $database = $config['db_write_database'] ?? '';

            $stmt = $pdo->query('SHOW TABLES');
            $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

            if (empty($tables)) {
                sendSSEEvent('log', ['message' => lang('log_db_cleanup_empty')]);
            } else {
                sendSSEEvent('log', ['message' => lang('log_db_cleanup_dropping', ['count' => count($tables)])]);

                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tables as $table) {
                    $quoted = '`' . str_replace('`', '``', $table) . '`';
                    $pdo->exec("DROP TABLE IF EXISTS {$quoted}");
                    sendSSEEvent('log', ['message' => "  - DROP TABLE {$table}"]);
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

                sendSSEEvent('log', ['message' => lang('log_db_cleanup_done', ['count' => count($tables)])]);
            }

            sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            markTaskCompleted('db_cleanup');
            sendSSEEvent('task_complete', ['task' => 'db_cleanup', 'message' => $taskName]);

            return ['success' => true];
        } catch (Exception $e) {
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

if (!function_exists('runDatabaseMigrationSSE')) {
    function runDatabaseMigrationSSE(): array
    {
        return executeDbCommandSSE(
            artisanCommand: 'migrate --force',
            taskId: 'db_migrate',
            taskNameKey: 'task_db_migrate',
            successMsgKey: 'log_db_migrate_success',
            errorMsgKey: 'error_db_migrate_failed'
        );
    }
}

if (!function_exists('runDatabaseSeedingSSE')) {
    function runDatabaseSeedingSSE(): array
    {
        $state = getInstallationState();
        $config = $state['config'] ?? [];

        if (empty($config['admin_email']) || empty($config['admin_password'])) {
            return ['success' => false, 'error' => '관리자 이메일과 비밀번호가 설정되지 않았습니다.'];
        }

        putenv('INSTALLER_ADMIN_NAME=' . ($config['admin_name'] ?? 'Administrator'));
        putenv('INSTALLER_ADMIN_EMAIL=' . $config['admin_email']);
        putenv('INSTALLER_ADMIN_PASSWORD=' . $config['admin_password']);
        putenv('INSTALLER_ADMIN_LANGUAGE=' . ($config['admin_language'] ?? $state['g7_locale'] ?? 'ko'));

        return executeDbCommandSSE(
            artisanCommand: 'db:seed --force',
            taskId: 'db_seed',
            taskNameKey: 'task_db_seed',
            successMsgKey: 'log_db_seed_success',
            errorMsgKey: 'error_db_seed_failed'
        );
    }
}

if (!function_exists('getSelectedExtensions')) {
    function getSelectedExtensions(): array
    {
        $state = getInstallationState();
        return $state['selected_extensions'] ?? [
            'admin_templates' => [],
            'user_templates' => [],
            'modules' => [],
            'plugins' => [],
        ];
    }
}

if (!function_exists('executeArtisanCommandSSE')) {
    function executeArtisanCommandSSE(
        string $artisanCommand,
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

        chdir(BASE_PATH);
        $output = [];
        $returnCode = 0;
        $phpBin = escapeshellarg(getPhpBinary());
        $fullCommand = "{$phpBin} -d memory_limit=512M artisan {$artisanCommand} 2>&1";
        exec($fullCommand, $output, $returnCode);

        foreach ($output as $line) {
            if (!empty(trim($line))) {
                sendSSEEvent('log', ['message' => $line]);
            }
        }

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

if (!function_exists('executeDbCommandSSE')) {
    function executeDbCommandSSE(
        string $artisanCommand,
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

        chdir(BASE_PATH);
        $output = [];
        $returnCode = 0;
        $phpBin = escapeshellarg(getPhpBinary());
        $fullCommand = "{$phpBin} -d memory_limit=512M artisan {$artisanCommand} 2>&1";

        exec($fullCommand, $output, $returnCode);

        foreach ($output as $line) {
            if (!empty(trim($line))) {
                sendSSEEvent('log', ['message' => $line]);
            }
        }

        if ($returnCode !== 0) {
            $errorMessage = implode("\n", $output);
            logInstallationError(lang($errorMsgKey), new Exception($errorMessage));

            addLog(lang('db_task_failed_rollback_start', ['task' => $taskId]));
            sendSSEEvent('log', ['message' => lang('failed_rollback_start', ['task' => $taskName])]);

            $rollbackResult = rollbackDbMigrate();
            $rollbackFailure = null;

            sendRollbackOutputSSE($rollbackResult);

            if ($rollbackResult['success']) {
                sendSSEEvent('log', ['message' => lang('failed_rollback_success', ['message' => $rollbackResult['message']])]);
                removeTaskCompleted('db_migrate');
                removeTaskCompleted('db_seed');
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

            $rollbackResult = rollbackDbMigrate();
            sendRollbackOutputSSE($rollbackResult);

            if ($rollbackResult['success']) {
                sendSSEEvent('log', ['message' => lang('abort_rollback_success', ['message' => $rollbackResult['message']])]);
                removeTaskCompleted('db_migrate');
                removeTaskCompleted('db_seed');
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

if (!function_exists('executeExtensionCommandSSE')) {
    function executeExtensionCommandSSE(
        string $artisanCommand,
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

        updateCurrentTask($taskId);
        sendSSEEvent('task_start', ['task' => $taskId, 'target' => $target, 'name' => $displayName]);
        sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $displayName])]);

        chdir(BASE_PATH);
        $output = [];
        $returnCode = 0;
        $phpBin = escapeshellarg(getPhpBinary());
        $fullCommand = "{$phpBin} -d memory_limit=512M artisan {$artisanCommand} 2>&1";
        exec($fullCommand, $output, $returnCode);

        foreach ($output as $line) {
            if (!empty(trim($line))) {
                sendSSEEvent('log', ['message' => $line]);
            }
        }

        $outputText = implode("\n", $output);
        $alreadyExistsPatterns = ['already installed', 'already active', '이미 설치', '이미 활성화'];

        $isAlreadyExists = false;
        foreach ($alreadyExistsPatterns as $pattern) {
            if (stripos($outputText, $pattern) !== false) {
                $isAlreadyExists = true;
                break;
            }
        }

        if ($returnCode !== 0 && !$isAlreadyExists) {
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

if (!function_exists('installAdminTemplateSSE')) {
    function installAdminTemplateSSE(string $templateId): array
    {
        return executeExtensionCommandSSE(
            artisanCommand: "template:install {$templateId}",
            taskId: 'template_install',
            target: $templateId,
            taskNameKey: 'task_template_install',
            successMsgKey: 'log_template_install_success',
            errorMsgKey: 'error_template_install_failed'
        );
    }
}

if (!function_exists('activateAdminTemplateSSE')) {
    function activateAdminTemplateSSE(string $templateId): array
    {
        return executeExtensionCommandSSE(
            artisanCommand: "template:activate {$templateId}",
            taskId: 'template_activate',
            target: $templateId,
            taskNameKey: 'task_template_activate',
            successMsgKey: 'log_template_activate_success',
            errorMsgKey: 'error_template_activate_failed'
        );
    }
}

if (!function_exists('installModuleSSE')) {
    function installModuleSSE(string $moduleId): array
    {
        $vendorModeOpt = vendorModeOptionFromState();

        return executeExtensionCommandSSE(
            artisanCommand: "module:install {$moduleId}{$vendorModeOpt}",
            taskId: 'module_install',
            target: $moduleId,
            taskNameKey: 'task_module_install',
            successMsgKey: 'log_module_install_success',
            errorMsgKey: 'error_module_install_failed'
        );
    }
}

if (!function_exists('activateModuleSSE')) {
    function activateModuleSSE(string $moduleId): array
    {
        return executeExtensionCommandSSE(
            artisanCommand: "module:activate {$moduleId}",
            taskId: 'module_activate',
            target: $moduleId,
            taskNameKey: 'task_module_activate',
            successMsgKey: 'log_module_activate_success',
            errorMsgKey: 'error_module_activate_failed'
        );
    }
}

if (!function_exists('installPluginSSE')) {
    function installPluginSSE(string $pluginId): array
    {
        $vendorModeOpt = vendorModeOptionFromState();

        return executeExtensionCommandSSE(
            artisanCommand: "plugin:install {$pluginId}{$vendorModeOpt}",
            taskId: 'plugin_install',
            target: $pluginId,
            taskNameKey: 'task_plugin_install',
            successMsgKey: 'log_plugin_install_success',
            errorMsgKey: 'error_plugin_install_failed'
        );
    }
}

if (!function_exists('activatePluginSSE')) {
    function activatePluginSSE(string $pluginId): array
    {
        return executeExtensionCommandSSE(
            artisanCommand: "plugin:activate {$pluginId}",
            taskId: 'plugin_activate',
            target: $pluginId,
            taskNameKey: 'task_plugin_activate',
            successMsgKey: 'log_plugin_activate_success',
            errorMsgKey: 'error_plugin_activate_failed'
        );
    }
}

if (!function_exists('installUserTemplateSSE')) {
    function installUserTemplateSSE(string $templateId): array
    {
        return executeExtensionCommandSSE(
            artisanCommand: "template:install {$templateId}",
            taskId: 'user_template_install',
            target: $templateId,
            taskNameKey: 'task_user_template_install',
            successMsgKey: 'log_user_template_install_success',
            errorMsgKey: 'error_user_template_install_failed'
        );
    }
}

if (!function_exists('activateUserTemplateSSE')) {
    function activateUserTemplateSSE(string $templateId): array
    {
        return executeExtensionCommandSSE(
            artisanCommand: "template:activate {$templateId}",
            taskId: 'user_template_activate',
            target: $templateId,
            taskNameKey: 'task_user_template_activate',
            successMsgKey: 'log_user_template_activate_success',
            errorMsgKey: 'error_user_template_activate_failed'
        );
    }
}

if (!function_exists('clearCacheSSE')) {
    function clearCacheSSE(): array
    {
        return executeArtisanCommandSSE(
            artisanCommand: 'optimize:clear',
            taskId: 'cache_clear',
            taskNameKey: 'task_cache_clear',
            successMsgKey: 'log_cache_clear_success',
            errorMsgKey: 'error_cache_clear_failed'
        );
    }
}

if (!function_exists('createSettingsJsonSSE')) {
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
            $settingsDir = BASE_PATH . '/storage/app/settings';

            if (!is_dir($settingsDir)) {
                mkdir($settingsDir, 0755, true);
            }

            $defaultsFile = BASE_PATH . '/config/settings/defaults.json';
            if (!file_exists($defaultsFile)) {
                throw new Exception('defaults.json file not found: ' . $defaultsFile);
            }

            $defaultsContent = file_get_contents($defaultsFile);
            $defaultsData = json_decode($defaultsContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('defaults.json JSON parsing failed: ' . json_last_error_msg());
            }

            $defaults = $defaultsData['defaults'] ?? [];
            $categories = $defaultsData['_meta']['categories'] ?? array_keys($defaults);

            if (empty($defaults)) {
                throw new Exception('defaults.json does not contain defaults section');
            }

            $state = getInstallationState();
            $config = $state['config'] ?? [];

            if (!empty($config['app_name'])) {
                $defaults['general']['site_name'] = $config['app_name'];
                $defaults['mail']['from_name'] = $config['app_name'];
            }
            if (!empty($config['app_url'])) {
                $defaults['general']['site_url'] = $config['app_url'];
            }
            if (!empty($config['admin_email'])) {
                $defaults['general']['admin_email'] = $config['admin_email'];
                $defaults['mail']['from_address'] = $config['admin_email'];
            }

            if (!empty($config['core_update_github_url'])) {
                $defaults['core_update']['github_url'] = $config['core_update_github_url'];
            }
            if (!empty($config['core_update_github_token'])) {
                $defaults['core_update']['github_token'] = $config['core_update_github_token'];
            }

            $defaults['general']['language'] = getCurrentLanguage();

            foreach ($categories as $category) {
                if (!isset($defaults[$category])) {
                    sendSSEEvent('log', ['message' => "  - {$category}.json skipped (no defaults)"]);
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

                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $filePath = $settingsDir . '/' . $category . '.json';

                file_put_contents($filePath, $json, LOCK_EX);
                sendSSEEvent('log', ['message' => "  - {$category}.json created"]);
            }

            sendSSEEvent('log', ['message' => lang('log_settings_json_created')]);
            sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            markTaskCompleted('create_settings_json');
            sendSSEEvent('task_complete', ['task' => 'create_settings_json', 'message' => lang('log_settings_json_created')]);

            return ['success' => true];
        } catch (Exception $e) {
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

if (!function_exists('setInstallationCompleteSSE')) {
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
            $envPath = BASE_PATH . '/.env';
            if (file_exists($envPath) && is_writable($envPath)) {
                $envContent = file_get_contents($envPath);
                $envContent .= "\n\n# Installation Status\n";
                $envContent .= "INSTALLER_COMPLETED=true\n";
                file_put_contents($envPath, $envContent);
                sendSSEEvent('log', ['message' => lang('log_env_flag_added')]);
            } else {
                sendSSEEvent('log', ['message' => lang('log_env_flag_skipped')]);
            }

            $installedFlagPath = BASE_PATH . '/storage/app/g7_installed';
            $installedFlagDir = dirname($installedFlagPath);

            if (!is_dir($installedFlagDir)) {
                @mkdir($installedFlagDir, 0775, true);
            }

            file_put_contents($installedFlagPath, date('Y-m-d H:i:s'));
            @chmod($installedFlagPath, 0644);
            sendSSEEvent('log', ['message' => lang('log_installed_flag_created')]);

            $state = getInstallationState();
            $state['current_step'] = 5;
            $state['step_status']['5'] = 'completed';
            $state['installation_status'] = 'completed';
            $state['installation_completed_at'] = date('Y-m-d\TH:i:s\Z');
            saveInstallationState($state);
            sendSSEEvent('log', ['message' => lang('log_state_updated')]);

            sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            markTaskCompleted('complete_flag');
            sendSSEEvent('task_complete', ['task' => 'complete_flag', 'message' => lang('log_installation_completed')]);

            if (DELETE_INSTALLER_AFTER_COMPLETE) {
                $stateFilePath = BASE_PATH . '/storage/installer-state.json';

                if (file_exists($stateFilePath)) {
                    sendSSEEvent('log', ['message' => lang('log_removing_state_file')]);

                    if (@unlink($stateFilePath)) {
                        sendSSEEvent('log', ['message' => lang('log_state_file_removed')]);
                        addLog(lang('log_state_file_removed') . ': ' . $stateFilePath);
                    } else {
                        sendSSEEvent('log', ['message' => lang('log_state_file_remove_failed')]);
                        addLog(lang('log_state_file_remove_failed') . ': ' . $stateFilePath);
                    }
                }
            }

            return ['success' => true];
        } catch (Exception $e) {
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

if (!function_exists('runInstallationTasks')) {
    /**
     * 설치 작업 전체 실행.
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
            $selectedExtensions = getSelectedExtensions();

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

            // 3. 관리자 템플릿
            foreach ($selectedExtensions['admin_templates'] ?? [] as $templateId) {
                $tasks[] = ['id' => 'template_install', 'target' => $templateId, 'function' => 'installAdminTemplateSSE', 'args' => [$templateId]];
                $tasks[] = ['id' => 'template_activate', 'target' => $templateId, 'function' => 'activateAdminTemplateSSE', 'args' => [$templateId]];
            }

            // 4. 모듈
            foreach ($selectedExtensions['modules'] ?? [] as $moduleId) {
                $tasks[] = ['id' => 'module_install', 'target' => $moduleId, 'function' => 'installModuleSSE', 'args' => [$moduleId]];
                $tasks[] = ['id' => 'module_activate', 'target' => $moduleId, 'function' => 'activateModuleSSE', 'args' => [$moduleId]];
            }

            // 5. 플러그인
            foreach ($selectedExtensions['plugins'] ?? [] as $pluginId) {
                $tasks[] = ['id' => 'plugin_install', 'target' => $pluginId, 'function' => 'installPluginSSE', 'args' => [$pluginId]];
                $tasks[] = ['id' => 'plugin_activate', 'target' => $pluginId, 'function' => 'activatePluginSSE', 'args' => [$pluginId]];
            }

            // 6. 사용자 템플릿
            foreach ($selectedExtensions['user_templates'] ?? [] as $templateId) {
                $tasks[] = ['id' => 'user_template_install', 'target' => $templateId, 'function' => 'installUserTemplateSSE', 'args' => [$templateId]];
                $tasks[] = ['id' => 'user_template_activate', 'target' => $templateId, 'function' => 'activateUserTemplateSSE', 'args' => [$templateId]];
            }

            // 7. 마무리
            $tasks[] = ['id' => 'create_settings_json', 'function' => 'createSettingsJsonSSE'];
            $tasks[] = ['id' => 'cache_clear', 'function' => 'clearCacheSSE'];
            $tasks[] = ['id' => 'complete_flag', 'function' => 'setInstallationCompleteSSE'];

            foreach ($tasks as $task) {
                if (checkAbortStatusSSE()) {
                    sendSSEEvent('aborted', [
                        'message' => lang('abort_installation_stopped'),
                        'task' => $task['id'],
                        'target' => $task['target'] ?? null,
                    ]);
                    addLog(lang('abort_installation_stopped'));
                    return;
                }

                $taskId = $task['id'];
                $target = $task['target'] ?? null;
                $functionName = $task['function'];
                $args = $task['args'] ?? [];

                $completedKey = $target ? "{$taskId}:{$target}" : $taskId;

                if (in_array($completedKey, $completedTasks)) {
                    $taskNameKey = "task_{$taskId}";
                    $taskName = lang($taskNameKey);
                    $displayName = $target ? "{$taskName} ({$target})" : $taskName;

                    sendSSEEvent('task_complete', [
                        'task' => $taskId,
                        'target' => $target,
                        'message' => lang('log_already_completed', ['task' => $displayName]),
                    ]);
                    continue;
                }

                $result = empty($args) ? $functionName() : $functionName(...$args);

                if (isset($result['aborted']) && $result['aborted']) {
                    sendSSEEvent('aborted', [
                        'message' => lang('abort_installation_stopped'),
                        'task' => $taskId,
                        'target' => $target,
                    ]);
                    addLog(lang('abort_installation_stopped'));
                    return;
                }

                if (!$result['success']) {
                    $state = getInstallationState();
                    $taskNameKey = "task_{$taskId}";
                    $taskName = lang($taskNameKey);
                    $displayName = $target ? "{$taskName} ({$target})" : $taskName;

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

                    $state['installation_status'] = 'failed';
                    $state['failed_task'] = $completedKey;
                    $state['failed_task_target'] = $target;
                    $state['error_message_key'] = $result['message_key'] ?? null;
                    $state['error_detail'] = $result['detail'] ?? null;

                    if (!empty($result['rollback_done'])) {
                        $state['rollback_failure'] = $result['rollback_failure'] ?? null;
                    } else {
                        $state['rollback_failure'] = $rollbackFailure ?? null;
                    }

                    $manualCommands = getManualCommands($taskId, $target);
                    $state['manual_commands'] = $manualCommands;

                    saveInstallationState($state);

                    addLog(lang('log_installation_task_failed', [
                        'task' => $displayName,
                        'message' => $result['message'] ?? lang('log_installation_failed'),
                    ]));

                    if (!empty($manualCommands)) {
                        sendSSEEvent('log', ['message' => lang('log_separator')]);
                        sendSSEEvent('log', ['message' => lang('manual_commands_guide')]);
                        foreach ($manualCommands as $cmd) {
                            sendSSEEvent('log', ['message' => "  $ {$cmd}"]);
                        }
                        sendSSEEvent('log', ['message' => lang('log_separator')]);
                    }

                    sendSSEEvent('error', [
                        'message' => $result['message'] ?? lang('log_installation_failed'),
                        'message_key' => $result['message_key'] ?? 'log_installation_failed',
                        'error' => $result['detail'] ?? null,
                        'task' => $taskId,
                        'target' => $target,
                        'manual_commands' => $manualCommands,
                    ]);
                    return;
                }
            }

            // 모든 작업 성공
            if (isset($_SESSION)) {
                $_SESSION['installer_current_step'] = 5;
            }
            sendSSEEvent('completed', [
                'message' => lang('log_installation_completed'),
                'redirect' => '/install/',
            ]);

            addLog(lang('log_all_tasks_completed'));
        } catch (Exception $e) {
            logInstallationError(lang('error_worker_exception'), $e);

            $state = getInstallationState();
            $state['installation_status'] = 'failed';
            $state['failed_task'] = $state['current_task'] ?? null;
            $state['error_message_key'] = 'error_unexpected_exception';
            $state['error_detail'] = $e->getMessage();

            saveInstallationState($state);

            addLog(lang('log_installation_exception', ['error' => $e->getMessage()]));

            sendSSEEvent('error', [
                'message' => lang('log_installation_failed'),
                'message_key' => 'error_unexpected_exception',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
