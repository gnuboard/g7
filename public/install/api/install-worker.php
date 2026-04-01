<?php

/**
 * 그누보드7 웹 인스톨러 - SSE 기반 설치 작업 워커
 *
 * Server-Sent Events(SSE)를 사용하여 실시간으로 설치 진행 상태를 스트리밍합니다.
 * 브라우저 연결이 끊어지면 즉시 설치를 중단합니다.
 *
 * @package G7\Installer
 */

// 필수 파일 include (config.php가 BASE_PATH를 정의함)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/installer-state.php';
require_once __DIR__ . '/rollback-functions.php';

// SSE는 세션을 사용하지 않음 (세션 잠금 방지)
// state.json에서 모든 정보를 가져옴

// 현재 언어 가져오기 (state.json에서)
$state = getInstallationState();
$lang = $state['g7_locale'] ?? 'ko';
$translations = loadTranslations($lang);

// GET 요청만 허용 (SSE는 GET 기반)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => lang('sse_method_not_allowed'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// SSE 헤더 설정
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx 버퍼링 비활성화

// 출력 버퍼 비활성화 (즉시 전송)
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);

// Apache 환경인 경우에만 gzip 비활성화
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}

// 모든 출력 버퍼 레벨 제거
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 타임아웃 설정 (10분)
set_time_limit(600);

// 오류를 SSE 이벤트로 전송하기 위해 오류 출력 비활성화
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Worker 시작 로그 (디버깅용)
addLog('=== Install Worker SSE Started ===');
addLog('Client IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// escapeEnvValue()와 generateEnvContent()는 functions.php에 정의됨

/**
 * SSE 이벤트 전송
 *
 * @param string $event 이벤트 타입
 * @param array $data 데이터
 */
function sendSSEEvent(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();

    // log 이벤트인 경우 installation.log에도 기록
    if ($event === 'log' && isset($data['message'])) {
        addLog($data['message']);
    }
}

/**
 * 롤백 실행 결과를 SSE 로그로 출력합니다.
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

/**
 * 설치 중단 여부 확인 (SSE 버전)
 *
 * 브라우저 연결이 끊어졌거나 사용자가 중단했는지 확인합니다.
 */
function checkAbortStatusSSE(): bool
{
    // 1. 브라우저 연결 확인
    if (connection_aborted()) {
        addLog(lang('abort_connection_lost'));
        $state = getInstallationState();

        // 현재 진행 중인 작업만 롤백 (abort.php와 동일한 로직)
        $currentTask = $state['current_task'] ?? null;
        if ($currentTask && !in_array($currentTask, $state['completed_tasks'] ?? [])) {
            addLog(lang('abort_rollback_start', ['task' => $currentTask]));

            $rollbackResult = rollbackTask($currentTask, $state);

            // 롤백 상세 로그 출력 (있는 경우)
            if (!empty($rollbackResult['output']) && is_array($rollbackResult['output'])) {
                $outputStr = implode("\n", $rollbackResult['output']);
                addLog($outputStr);
            }

            if ($rollbackResult['success']) {
                addLog(lang('abort_rollback_success', ['message' => $rollbackResult['message']]));
            } else {
                addLog(lang('abort_rollback_failed', ['message' => $rollbackResult['message']]));

                // 롤백 실패 시 안내 (로그만, connection_aborted 상태이므로 SSE는 무의미)
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

        // 설치 상태를 'aborted'로 변경
        $state['installation_status'] = 'aborted';
        $state['current_task'] = null;
        // completed_tasks는 유지
        $state['abort_reason'] = 'Connection aborted unexpectedly';
        $state['aborted_at'] = date('Y-m-d H:i:s');

        // 롤백 실패 정보 저장 (새로고침 후에도 표시용)
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

/**
 * 설치 상태에서 PHP 바이너리 경로를 가져옵니다.
 *
 * @return string PHP 바이너리 경로
 */
function getPhpBinary(): string
{
    $state = getInstallationState();
    return $state['config']['php_binary'] ?? 'php' ?: 'php';
}

/**
 * 설치 상태에서 Composer 실행 명령어를 생성합니다. (실행용 — escapeshellarg 적용)
 *
 * @return string Composer 실행 명령어
 */
function getComposerCommand(): string
{
    $state = getInstallationState();
    $composerBinary = $state['config']['composer_binary'] ?? '';

    if ($composerBinary) {
        // 공백 포함 = 전체 실행 명령어 (예: "/usr/local/php84/bin/php /home/user/g7/composer.phar")
        if (str_contains($composerBinary, ' ')) {
            return $composerBinary;
        }
        return str_ends_with($composerBinary, '.phar')
            ? escapeshellarg(getPhpBinary()) . ' ' . escapeshellarg($composerBinary)
            : escapeshellarg($composerBinary);
    }

    return 'composer';
}

/**
 * 수동 실행 안내용 Composer 명령어를 생성합니다. (표시용 — escapeshellarg 미적용)
 *
 * @return string Composer 표시용 명령어
 */
function getComposerCommandForDisplay(): string
{
    $state = getInstallationState();
    $composerBinary = $state['config']['composer_binary'] ?? '';

    if ($composerBinary) {
        // 공백 포함 = 전체 실행 명령어 → 그대로 표시
        if (str_contains($composerBinary, ' ')) {
            return $composerBinary;
        }
        return str_ends_with($composerBinary, '.phar')
            ? getPhpBinary() . ' ' . $composerBinary
            : $composerBinary;
    }

    return 'composer';
}

/**
 * Task 1: Composer 확인
 */
function checkComposerSSE(): array
{
    if (checkAbortStatusSSE()) {
        return ['success' => false, 'aborted' => true];
    }

    $taskName = lang('task_composer_check');

    updateCurrentTask('composer_check');
    sendSSEEvent('task_start', [
        'task' => 'composer_check',
        'name' => $taskName,
    ]);

    // 작업 시작 로그
    sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

    // COMPOSER_HOME 환경변수 설정 (웹 서버 환경에서 필수)
    $composerHome = BASE_PATH . '/storage/composer';
    if (!is_dir($composerHome)) {
        @mkdir($composerHome, 0755, true);
    }
    putenv('COMPOSER_HOME=' . $composerHome);
    putenv('HOME=' . $composerHome);

    // composer 명령어 확인
    $output = [];
    $returnCode = 0;
    $composerCmd = getComposerCommand();
    exec($composerCmd . ' --version 2>&1', $output, $returnCode);

    if ($returnCode !== 0) {
        $errorMessage = implode("\n", $output);
        sendSSEEvent('log', ['message' => lang('log_error_occurred', ['error' => $errorMessage])]);
        logInstallationError(lang('error_composer_not_installed'));
        return [
            'success' => false,
            'message' => lang('error_composer_not_installed'), // 로그용
            'message_key' => 'error_composer_not_installed', // state.json 저장용
            'detail' => $errorMessage,
        ];
    }

    // 실제 출력 결과 로그
    foreach ($output as $line) {
        sendSSEEvent('log', ['message' => $line]);
    }

    // 작업 완료 로그
    sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
    sendSSEEvent('log', ['message' => lang('log_separator')]);

    markTaskCompleted('composer_check');
    sendSSEEvent('task_complete', [
        'task' => 'composer_check',
        'message' => lang('log_composer_check_success'),
    ]);

    return ['success' => true];
}

/**
 * Task 2: Composer 의존성 설치
 *
 * 4가지 케이스를 처리합니다:
 * 1. vendor ✅ + lock ✅ → skip (이미 정상 설치됨)
 * 2. vendor ✅ + lock ❌ → vendor 삭제 후 install (불완전 상태)
 * 3. vendor ❌ + lock ✅ → 일반 install (lock 파일 사용)
 * 4. vendor ❌ + lock ❌ → 일반 install (lock 생성됨)
 */
function installComposerDependenciesSSE(): array
{
    if (checkAbortStatusSSE()) {
        return ['success' => false, 'aborted' => true];
    }

    $taskName = lang('task_composer_install');

    updateCurrentTask('composer_install');
    sendSSEEvent('task_start', [
        'task' => 'composer_install',
        'name' => $taskName,
    ]);

    // 작업 시작 로그
    sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

    $vendorExists = is_dir(BASE_PATH . '/vendor') && file_exists(BASE_PATH . '/vendor/autoload.php');
    $lockExists = file_exists(BASE_PATH . '/composer.lock');

    // ========================================================================
    // 케이스 1: vendor ✅ + lock ✅ → skip (이미 정상 설치됨)
    // ========================================================================
    if ($vendorExists && $lockExists) {
        sendSSEEvent('log', ['message' => lang('log_composer_already_installed')]);
        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('composer_install');
        sendSSEEvent('task_complete', [
            'task' => 'composer_install',
            'message' => lang('log_composer_already_installed'),
        ]);
        return ['success' => true];
    }

    // ========================================================================
    // 케이스 2: vendor ✅ + lock ❌ → vendor 삭제 후 install
    // ========================================================================
    if ($vendorExists && !$lockExists) {
        sendSSEEvent('log', ['message' => lang('log_composer_vendor_without_lock')]);
        sendSSEEvent('log', ['message' => lang('log_composer_removing_vendor')]);

        $deleted = deleteDirectory(BASE_PATH . '/vendor');
        if (!$deleted) {
            sendSSEEvent('log', ['message' => lang('log_composer_vendor_delete_failed')]);
            // 실패해도 install 시도 (덮어쓰기 될 수 있음)
        } else {
            sendSSEEvent('log', ['message' => lang('log_composer_vendor_deleted')]);
        }
    }

    // ========================================================================
    // 케이스 3: vendor ❌ + lock ✅ → 일반 install (lock 파일 사용)
    // ========================================================================
    if (!$vendorExists && $lockExists) {
        sendSSEEvent('log', ['message' => lang('log_composer_installing_from_lock')]);
    }

    // ========================================================================
    // 케이스 4: vendor ❌ + lock ❌ → 일반 install
    // ========================================================================
    if (!$vendorExists && !$lockExists) {
        sendSSEEvent('log', ['message' => lang('log_composer_fresh_install')]);
    }

    // --no-dev 설치 전 bootstrap cache 삭제 (이전 개발 환경 캐시와 충돌 방지)
    $bootstrapCachePath = BASE_PATH . '/bootstrap/cache/packages.php';
    if (file_exists($bootstrapCachePath)) {
        @unlink($bootstrapCachePath);
        sendSSEEvent('log', ['message' => lang('log_composer_cache_cleared')]);
    }

    // composer install 실행
    chdir(BASE_PATH);

    // COMPOSER_HOME 환경변수 설정 (웹 서버 환경에서 필수)
    $composerHome = BASE_PATH . '/storage/composer';
    if (!is_dir($composerHome)) {
        @mkdir($composerHome, 0755, true);
    }

    // 환경변수 배열 구성
    $env = [];

    // 현재 환경변수 복사 (getenv()로 가져오기)
    foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'APPDATA', 'LOCALAPPDATA', 'USERPROFILE'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $env[$key] = $value;
        }
    }

    // Composer 관련 환경변수 설정
    $env['COMPOSER_HOME'] = $composerHome;
    $env['HOME'] = $composerHome;

    // Windows에서 추가로 필요한 환경변수
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        if (!isset($env['TEMP']) || !is_dir($env['TEMP']) || !is_writable($env['TEMP'])) {
            // TEMP가 없거나 쓰기 불가능하면 storage/temp 사용
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
            'message' => lang('error_composer_install_failed'), // 로그용
            'message_key' => 'error_composer_install_failed', // state.json 저장용
            'detail' => lang('error_composer_process_failed'),
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);

    // 실시간 출력 읽기 및 SSE 전송
    while (!feof($pipes[1])) {
        // 중단 체크
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
        usleep(100000); // 0.1초
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $returnCode = proc_close($process);

    if ($returnCode !== 0) {
        logInstallationError(lang('error_composer_install_failed'));
        return [
            'success' => false,
            'message' => lang('error_composer_install_failed'), // 로그용
            'message_key' => 'error_composer_install_failed', // state.json 저장용
            'detail' => lang('error_composer_exit_code', ['code' => $returnCode]),
        ];
    }

    // 작업 완료 로그
    sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
    sendSSEEvent('log', ['message' => lang('log_separator')]);

    markTaskCompleted('composer_install');
    sendSSEEvent('task_complete', [
        'task' => 'composer_install',
        'message' => lang('log_composer_install_success'),
    ]);

    return ['success' => true];
}

/**
 * Task 3: .env 파일 업데이트
 *
 * 사용자가 미리 생성한 .env 파일에 설정값을 채워 넣습니다.
 * .env가 없으면 에러를 반환합니다 (Step 5 UI에서 이미 존재 확인했으므로 비정상 상황).
 * .env에 쓰기 권한이 없으면 경고 로그만 남기고 성공 처리합니다.
 */
function updateEnvFileSSE(): array
{
    if (checkAbortStatusSSE()) {
        return ['success' => false, 'aborted' => true];
    }

    $taskName = lang('task_env_update');

    updateCurrentTask('env_update');
    sendSSEEvent('task_start', [
        'task' => 'env_update',
        'name' => $taskName,
    ]);

    // 작업 시작 로그
    sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

    $envPath = BASE_PATH . '/.env';

    // .env 파일 존재 여부 확인
    if (!file_exists($envPath)) {
        logInstallationError(lang('error_env_not_found'));
        return [
            'success' => false,
            'message' => lang('error_env_not_found'),
            'message_key' => 'error_env_not_found',
        ];
    }

    // .env 쓰기 가능 여부 확인
    if (is_writable($envPath)) {
        $content = generateEnvContent();
        if ($content !== null) {
            file_put_contents($envPath, $content);
            @chmod($envPath, 0600);
        }

        sendSSEEvent('log', ['message' => lang('log_env_update_success')]);
    } else {
        // .env 읽기 전용 — 경고만 남기고 성공 처리
        sendSSEEvent('log', ['message' => lang('log_env_readonly_skip')]);
    }

    // 작업 완료 로그
    sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
    sendSSEEvent('log', ['message' => lang('log_separator')]);

    markTaskCompleted('env_update');
    sendSSEEvent('task_complete', [
        'task' => 'env_update',
        'message' => lang('log_env_update_success'),
    ]);

    return ['success' => true];
}

/**
 * Task 4: Application Key 생성
 *
 * 기존 APP_KEY가 이미 유효한 값이면 건너뜁니다.
 */
function generateApplicationKeySSE(): array
{
    // 기존 APP_KEY가 유효하면 건너뛰기
    $envPath = BASE_PATH . '/.env';
    if (file_exists($envPath)) {
        $content = file_get_contents($envPath);
        if (preg_match('/^APP_KEY=base64:.{40,}$/m', $content)) {
            // 이미 유효한 APP_KEY 존재 → 건너뛰기
            $taskName = lang('task_key_generate');

            updateCurrentTask('key_generate');
            sendSSEEvent('task_start', [
                'task' => 'key_generate',
                'name' => $taskName,
            ]);
            sendSSEEvent('log', ['message' => lang('log_already_completed', ['task' => $taskName])]);
            sendSSEEvent('log', ['message' => lang('log_separator')]);

            markTaskCompleted('key_generate');
            sendSSEEvent('task_complete', [
                'task' => 'key_generate',
                'message' => lang('log_key_generate_success'),
            ]);

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

/**
 * Task 5: 데이터베이스 마이그레이션
 *
 * 코어 및 모듈/플러그인 마이그레이션을 실행합니다.
 */
function runDatabaseMigrationSSE(): array
{
    // DB 작업은 중단 요청 시에도 완료 후 롤백하도록 별도 함수 사용
    return executeDbCommandSSE(
        artisanCommand: 'migrate --force',
        taskId: 'db_migrate',
        taskNameKey: 'task_db_migrate',
        successMsgKey: 'log_db_migrate_success',
        errorMsgKey: 'error_db_migrate_failed'
    );
}

/**
 * Task 6: 기본 데이터 시딩
 */
function runDatabaseSeedingSSE(): array
{
    // 관리자 계정 정보를 환경변수로 설정 (AdminUserSeeder에서 사용)
    $state = getInstallationState();
    $config = $state['config'] ?? [];

    if (empty($config['admin_email']) || empty($config['admin_password'])) {
        return ['success' => false, 'error' => '관리자 이메일과 비밀번호가 설정되지 않았습니다.'];
    }

    putenv('INSTALLER_ADMIN_NAME=' . ($config['admin_name'] ?? 'Administrator'));
    putenv('INSTALLER_ADMIN_EMAIL=' . $config['admin_email']);
    putenv('INSTALLER_ADMIN_PASSWORD=' . $config['admin_password']);
    putenv('INSTALLER_ADMIN_LANGUAGE=' . ($config['admin_language'] ?? $state['g7_locale'] ?? 'ko'));

    // DB 작업은 중단 요청 시에도 완료 후 롤백하도록 별도 함수 사용
    return executeDbCommandSSE(
        artisanCommand: 'db:seed --force',
        taskId: 'db_seed',
        taskNameKey: 'task_db_seed',
        successMsgKey: 'log_db_seed_success',
        errorMsgKey: 'error_db_seed_failed'
    );
}

// ============================================================================
// 선택된 확장 기능 로드
// ============================================================================

/**
 * state.json에서 선택된 확장 기능 가져오기
 *
 * @return array 선택된 확장 기능 배열
 */
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

/**
 * Artisan 명령어 실행 (공통 함수)
 *
 * @param string $artisanCommand Artisan 명령어 (예: "template:install sirsoft-admin_basic", "cache:clear")
 * @param string $taskId 작업 ID
 * @param string $taskNameKey 작업 이름 번역 키
 * @param string $successMsgKey 성공 메시지 번역 키
 * @param string $errorMsgKey 에러 메시지 번역 키
 * @return array
 */
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
    sendSSEEvent('task_start', [
        'task' => $taskId,
        'name' => $taskName,
    ]);

    // 작업 시작 로그
    sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

    chdir(BASE_PATH);
    $output = [];
    $returnCode = 0;
    $phpBin = escapeshellarg(getPhpBinary());
    $fullCommand = "{$phpBin} -d memory_limit=512M artisan {$artisanCommand} 2>&1";
    exec($fullCommand, $output, $returnCode);

    // 명령 출력 로그
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
            'message' => lang($errorMsgKey), // 로그용
            'message_key' => $errorMsgKey, // state.json 저장용
            'detail' => $errorMessage,
        ];
    }

    // 작업 완료 로그
    sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
    sendSSEEvent('log', ['message' => lang('log_separator')]);

    markTaskCompleted($taskId);
    sendSSEEvent('task_complete', [
        'task' => $taskId,
        'message' => lang($successMsgKey),
    ]);

    return ['success' => true];
}

/**
 * DB 작업용 Artisan 명령어 실행 (중단 요청 시에도 완료 후 롤백)
 *
 * 마이그레이션/시딩 중간에 중단하면 DB 상태가 꼬이므로,
 * 작업이 완료될 때까지 기다린 후 롤백 처리합니다.
 *
 * @param string $artisanCommand Artisan 명령어
 * @param string $taskId 작업 ID
 * @param string $taskNameKey 작업 이름 번역 키
 * @param string $successMsgKey 성공 메시지 번역 키
 * @param string $errorMsgKey 에러 메시지 번역 키
 * @return array
 */
function executeDbCommandSSE(
    string $artisanCommand,
    string $taskId,
    string $taskNameKey,
    string $successMsgKey,
    string $errorMsgKey
): array {
    // 작업 시작 전 중단 상태 확인 (아직 시작 안했으면 중단)
    $state = getInstallationState();
    $wasAborted = isset($state['installation_status']) && $state['installation_status'] === 'aborted';

    if ($wasAborted) {
        addLog(lang('db_task_abort_detected_before_start'));
        return ['success' => false, 'aborted' => true];
    }

    $taskName = lang($taskNameKey);

    updateCurrentTask($taskId);
    sendSSEEvent('task_start', [
        'task' => $taskId,
        'name' => $taskName,
    ]);

    // 작업 시작 로그
    sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

    chdir(BASE_PATH);
    $output = [];
    $returnCode = 0;
    $phpBin = escapeshellarg(getPhpBinary());
    $fullCommand = "{$phpBin} -d memory_limit=512M artisan {$artisanCommand} 2>&1";

    // DB 명령어 실행 (중단 체크 없이 완료까지 실행)
    exec($fullCommand, $output, $returnCode);

    // 명령 출력 로그
    foreach ($output as $line) {
        if (!empty(trim($line))) {
            sendSSEEvent('log', ['message' => $line]);
        }
    }

    // 작업 실패 시
    if ($returnCode !== 0) {
        $errorMessage = implode("\n", $output);
        logInstallationError(lang($errorMsgKey), new Exception($errorMessage));

        // 마이그레이션/시딩 실패 시 롤백 시도
        addLog(lang('db_task_failed_rollback_start', ['task' => $taskId]));
        sendSSEEvent('log', ['message' => lang('failed_rollback_start', ['task' => $taskName])]);

        $rollbackResult = rollbackDbMigrate();
        $rollbackFailure = null;

        sendRollbackOutputSSE($rollbackResult);

        if ($rollbackResult['success']) {
            sendSSEEvent('log', ['message' => lang('failed_rollback_success', ['message' => $rollbackResult['message']])]);

            // DB 롤백 성공 시 completed_tasks에서 DB 작업 제거 (재시도 시 db_migrate부터 실행)
            removeTaskCompleted('db_migrate');
            removeTaskCompleted('db_seed');

            sendSSEEvent('log', ['message' => lang('failed_rollback_db_restart')]);
        } else {
            sendSSEEvent('log', ['message' => lang('failed_rollback_failed', ['message' => $rollbackResult['message']])]);

            // 롤백 실패 정보 (state 저장 및 SSE 이벤트용)
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

    // 작업 완료
    markTaskCompleted($taskId);

    // 작업 완료 후 중단 상태 확인 (완료 후에 롤백 처리)
    $state = getInstallationState();
    $isAborted = isset($state['installation_status']) && $state['installation_status'] === 'aborted';
    $connectionLost = connection_aborted();

    if ($isAborted || $connectionLost) {
        $reason = $connectionLost ? lang('db_task_abort_reason_connection') : lang('db_task_abort_reason_user');
        addLog(lang('db_task_completed_abort_detected', ['task' => $taskName, 'reason' => $reason]));
        sendSSEEvent('log', ['message' => lang('db_task_completed_rollback_start', ['task' => $taskName])]);

        // DB 롤백 실행
        $rollbackResult = rollbackDbMigrate();
        sendRollbackOutputSSE($rollbackResult);

        if ($rollbackResult['success']) {
            sendSSEEvent('log', ['message' => lang('abort_rollback_success', ['message' => $rollbackResult['message']])]);

            // DB 롤백 성공 시 completed_tasks에서 DB 작업 제거 (재시도 시 db_migrate부터 실행)
            removeTaskCompleted('db_migrate');
            removeTaskCompleted('db_seed');

            sendSSEEvent('log', ['message' => lang('failed_rollback_db_restart')]);
        } else {
            sendSSEEvent('log', ['message' => lang('abort_rollback_failed', ['message' => $rollbackResult['message']])]);
            sendSSEEvent('log', ['message' => lang('failed_rollback_manual_cleanup')]);
        }

        return ['success' => false, 'aborted' => true];
    }

    // 정상 완료
    sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
    sendSSEEvent('log', ['message' => lang('log_separator')]);

    sendSSEEvent('task_complete', [
        'task' => $taskId,
        'message' => lang($successMsgKey),
    ]);

    return ['success' => true];
}

/**
 * 확장 명령어 실행 (공통 함수) - target 지원
 *
 * @param string $artisanCommand Artisan 명령어
 * @param string $taskId 작업 ID
 * @param string|null $target 대상 확장 식별자
 * @param string $taskNameKey 작업 이름 번역 키
 * @param string $successMsgKey 성공 메시지 번역 키
 * @param string $errorMsgKey 에러 메시지 번역 키
 * @return array
 */
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

    // 확장 이름 가져오기 (저장된 extension_names에서)
    $targetDisplayName = $target;
    if ($target) {
        $state = getInstallationState();
        $extensionNames = $state['extension_names'] ?? [];
        $lang = $state['g7_locale'] ?? 'ko';
        if (isset($extensionNames[$target])) {
            $name = $extensionNames[$target];
            // 다국어 이름 처리
            if (is_array($name)) {
                $targetDisplayName = $name[$lang] ?? $name['ko'] ?? $name['en'] ?? $target;
            } else {
                $targetDisplayName = $name;
            }
        }
    }

    $displayName = $target ? "{$targetDisplayName} {$taskName} ({$target})" : $taskName;

    updateCurrentTask($taskId);
    sendSSEEvent('task_start', [
        'task' => $taskId,
        'target' => $target,
        'name' => $displayName,
    ]);

    // 작업 시작 로그
    sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $displayName])]);

    chdir(BASE_PATH);
    $output = [];
    $returnCode = 0;
    $phpBin = escapeshellarg(getPhpBinary());
    $fullCommand = "{$phpBin} -d memory_limit=512M artisan {$artisanCommand} 2>&1";
    exec($fullCommand, $output, $returnCode);

    // 명령 출력 로그
    foreach ($output as $line) {
        if (!empty(trim($line))) {
            sendSSEEvent('log', ['message' => $line]);
        }
    }

    // "이미 설치됨/활성화됨" 패턴 감지 (건너뛰기 처리)
    $outputText = implode("\n", $output);
    $alreadyExistsPatterns = [
        'already installed',
        'already active',
        '이미 설치',
        '이미 활성화',
    ];

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
            'message' => lang($errorMsgKey), // 로그용
            'message_key' => $errorMsgKey, // state.json 저장용
            'detail' => $errorMessage,
            'target' => $target,
        ];
    }

    // 이미 존재하는 경우 건너뛰기 로그, 그렇지 않으면 완료 로그
    if ($isAlreadyExists) {
        sendSSEEvent('log', ['message' => lang('log_task_skipped', ['task' => $displayName])]);
    } else {
        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $displayName])]);
    }
    sendSSEEvent('log', ['message' => lang('log_separator')]);

    // completed_tasks에 target 정보 포함하여 저장
    $completedTaskKey = $target ? "{$taskId}:{$target}" : $taskId;
    markTaskCompleted($completedTaskKey);

    sendSSEEvent('task_complete', [
        'task' => $taskId,
        'target' => $target,
        'message' => lang($successMsgKey),
    ]);

    return ['success' => true];
}

// ============================================================================
// 관리자 템플릿 설치/활성화
// ============================================================================

/**
 * 관리자 템플릿 설치
 *
 * @param string $templateId 템플릿 식별자
 * @return array
 */
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

/**
 * 관리자 템플릿 활성화
 *
 * @param string $templateId 템플릿 식별자
 * @return array
 */
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

// ============================================================================
// 모듈 설치/활성화
// ============================================================================

/**
 * 모듈 설치
 *
 * @param string $moduleId 모듈 식별자
 * @return array
 */
function installModuleSSE(string $moduleId): array
{
    return executeExtensionCommandSSE(
        artisanCommand: "module:install {$moduleId}",
        taskId: 'module_install',
        target: $moduleId,
        taskNameKey: 'task_module_install',
        successMsgKey: 'log_module_install_success',
        errorMsgKey: 'error_module_install_failed'
    );
}

/**
 * 모듈 활성화
 *
 * @param string $moduleId 모듈 식별자
 * @return array
 */
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

// ============================================================================
// 플러그인 설치/활성화
// ============================================================================

/**
 * 플러그인 설치
 *
 * @param string $pluginId 플러그인 식별자
 * @return array
 */
function installPluginSSE(string $pluginId): array
{
    return executeExtensionCommandSSE(
        artisanCommand: "plugin:install {$pluginId}",
        taskId: 'plugin_install',
        target: $pluginId,
        taskNameKey: 'task_plugin_install',
        successMsgKey: 'log_plugin_install_success',
        errorMsgKey: 'error_plugin_install_failed'
    );
}

/**
 * 플러그인 활성화
 *
 * @param string $pluginId 플러그인 식별자
 * @return array
 */
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

// ============================================================================
// 사용자 템플릿 설치/활성화
// ============================================================================

/**
 * 사용자 템플릿 설치
 *
 * @param string $templateId 템플릿 식별자
 * @return array
 */
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

/**
 * 사용자 템플릿 활성화
 *
 * @param string $templateId 템플릿 식별자
 * @return array
 */
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

/**
 * Task 9: 캐시 클리어
 */
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

/**
 * Task 10: 설정 JSON 파일 생성
 *
 * config/settings/defaults.json 파일의 기본값을 기반으로
 * 인스톨러 입력값을 오버라이드하여 설정 파일을 생성합니다.
 */
function createSettingsJsonSSE(): array
{
    if (checkAbortStatusSSE()) {
        return ['success' => false, 'aborted' => true];
    }

    $taskName = lang('task_create_settings_json');

    updateCurrentTask('create_settings_json');
    sendSSEEvent('task_start', [
        'task' => 'create_settings_json',
        'name' => $taskName,
    ]);

    // 작업 시작 로그
    sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);
    sendSSEEvent('log', ['message' => lang('log_creating_settings')]);

    try {
        $settingsDir = BASE_PATH . '/storage/app/settings';

        // 디렉토리 생성
        if (!is_dir($settingsDir)) {
            mkdir($settingsDir, 0755, true);
        }

        // config/settings/defaults.json에서 기본값 로드
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

        // state.json에서 인스톨러 입력값 가져오기
        $state = getInstallationState();
        $config = $state['config'] ?? [];

        // 인스톨러 입력값으로 기본값 오버라이드
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

        // 코어 업데이트 설정 오버라이드
        if (!empty($config['core_update_github_url'])) {
            $defaults['core_update']['github_url'] = $config['core_update_github_url'];
        }
        if (!empty($config['core_update_github_token'])) {
            $defaults['core_update']['github_token'] = $config['core_update_github_token'];
        }

        // 현재 인스톨러 언어 설정 적용
        $defaults['general']['language'] = getCurrentLanguage();

        // 각 카테고리별 JSON 파일 생성
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

        // 작업 완료 로그
        sendSSEEvent('log', ['message' => lang('log_settings_json_created')]);
        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('create_settings_json');
        sendSSEEvent('task_complete', [
            'task' => 'create_settings_json',
            'message' => lang('log_settings_json_created'),
        ]);

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

/**
 * Task 11: 설치 완료 플래그 설정
 */
function setInstallationCompleteSSE(): array
{
    if (checkAbortStatusSSE()) {
        return ['success' => false, 'aborted' => true];
    }

    $taskName = lang('task_complete_flag');

    updateCurrentTask('complete_flag');
    sendSSEEvent('task_start', [
        'task' => 'complete_flag',
        'name' => $taskName,
    ]);

    // 작업 시작 로그
    sendSSEEvent('log', ['message' => lang('log_task_in_progress', ['task' => $taskName])]);

    try {
        // .env 파일에 플래그 추가 (graceful — 쓰기 불가 시 건너뜀)
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

        // g7_installed 파일 생성
        $installedFlagPath = BASE_PATH . '/storage/app/g7_installed';
        $installedFlagDir = dirname($installedFlagPath);

        if (!is_dir($installedFlagDir)) {
            @mkdir($installedFlagDir, 0775, true);
        }

        file_put_contents($installedFlagPath, date('Y-m-d H:i:s'));
        @chmod($installedFlagPath, 0644);
        sendSSEEvent('log', ['message' => lang('log_installed_flag_created')]);

        // 설치 상태 업데이트
        $state = getInstallationState();
        $state['current_step'] = 5;
        $state['step_status']['5'] = 'completed';
        $state['installation_status'] = 'completed';
        $state['installation_completed_at'] = date('Y-m-d\TH:i:s\Z');
        saveInstallationState($state);
        sendSSEEvent('log', ['message' => lang('log_state_updated')]);

        // 작업 완료 로그
        sendSSEEvent('log', ['message' => lang('log_task_completed', ['task' => $taskName])]);
        sendSSEEvent('log', ['message' => lang('log_separator')]);

        markTaskCompleted('complete_flag');
        sendSSEEvent('task_complete', [
            'task' => 'complete_flag',
            'message' => lang('log_installation_completed'),
        ]);

        // ============================================================
        // ⚠️ INSTALLER 상태 파일 자동 삭제
        // ⚠️ config.php의 DELETE_INSTALLER_AFTER_COMPLETE 설정값 사용
        // ⚠️ 삭제 비활성화: config.php에서 false로 변경
        // ============================================================
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
            'message' => lang('error_complete_flag_failed'), // 로그용
            'message_key' => 'error_complete_flag_failed', // state.json 저장용
            'detail' => $e->getMessage(),
        ];
    }
}

// ============================================================================
// 메인 실행 로직
// ============================================================================

// 연결 성공 이벤트 즉시 전송
echo "event: connected\n";
echo "data: " . json_encode(['message' => lang('sse_connection_established')], JSON_UNESCAPED_UNICODE) . "\n\n";
if (ob_get_level()) ob_flush();
flush();

try {
    $state = getInstallationState();
    $completedTasks = $state['completed_tasks'] ?? [];
    $selectedExtensions = getSelectedExtensions();

    // ========================================================================
    // 동적 작업 목록 생성
    // ========================================================================

    $tasks = [];

    // 1. 환경 설정 작업 (고정)
    $tasks[] = ['id' => 'composer_check', 'function' => 'checkComposerSSE'];
    $tasks[] = ['id' => 'composer_install', 'function' => 'installComposerDependenciesSSE'];
    $tasks[] = ['id' => 'env_update', 'function' => 'updateEnvFileSSE'];
    $tasks[] = ['id' => 'key_generate', 'function' => 'generateApplicationKeySSE'];

    // 2. 데이터베이스 작업 (고정)
    $tasks[] = ['id' => 'db_migrate', 'function' => 'runDatabaseMigrationSSE'];
    $tasks[] = ['id' => 'db_seed', 'function' => 'runDatabaseSeedingSSE'];

    // 3. 관리자 템플릿 설치/활성화 (선택된 항목)
    foreach ($selectedExtensions['admin_templates'] ?? [] as $templateId) {
        $tasks[] = ['id' => 'template_install', 'target' => $templateId, 'function' => 'installAdminTemplateSSE', 'args' => [$templateId]];
        $tasks[] = ['id' => 'template_activate', 'target' => $templateId, 'function' => 'activateAdminTemplateSSE', 'args' => [$templateId]];
    }

    // 4. 모듈 설치/활성화 (선택된 항목)
    foreach ($selectedExtensions['modules'] ?? [] as $moduleId) {
        $tasks[] = ['id' => 'module_install', 'target' => $moduleId, 'function' => 'installModuleSSE', 'args' => [$moduleId]];
        $tasks[] = ['id' => 'module_activate', 'target' => $moduleId, 'function' => 'activateModuleSSE', 'args' => [$moduleId]];
    }

    // 5. 플러그인 설치/활성화 (선택된 항목)
    foreach ($selectedExtensions['plugins'] ?? [] as $pluginId) {
        $tasks[] = ['id' => 'plugin_install', 'target' => $pluginId, 'function' => 'installPluginSSE', 'args' => [$pluginId]];
        $tasks[] = ['id' => 'plugin_activate', 'target' => $pluginId, 'function' => 'activatePluginSSE', 'args' => [$pluginId]];
    }

    // 6. 사용자 템플릿 설치/활성화 (선택된 항목)
    foreach ($selectedExtensions['user_templates'] ?? [] as $templateId) {
        $tasks[] = ['id' => 'user_template_install', 'target' => $templateId, 'function' => 'installUserTemplateSSE', 'args' => [$templateId]];
        $tasks[] = ['id' => 'user_template_activate', 'target' => $templateId, 'function' => 'activateUserTemplateSSE', 'args' => [$templateId]];
    }

    // 7. 마무리 작업 (고정)
    $tasks[] = ['id' => 'create_settings_json', 'function' => 'createSettingsJsonSSE'];
    $tasks[] = ['id' => 'cache_clear', 'function' => 'clearCacheSSE'];
    $tasks[] = ['id' => 'complete_flag', 'function' => 'setInstallationCompleteSSE'];

    // ========================================================================
    // 작업 실행
    // ========================================================================

    foreach ($tasks as $task) {
        // 작업 시작 전 abort 체크 (루프 시작 시 즉시 확인)
        if (checkAbortStatusSSE()) {
            sendSSEEvent('aborted', [
                'message' => lang('abort_installation_stopped'),
                'task' => $task['id'],
                'target' => $task['target'] ?? null,
            ]);
            addLog(lang('abort_installation_stopped'));
            exit;
        }

        $taskId = $task['id'];
        $target = $task['target'] ?? null;
        $functionName = $task['function'];
        $args = $task['args'] ?? [];

        // completed_tasks 키 생성 (target 포함)
        $completedKey = $target ? "{$taskId}:{$target}" : $taskId;

        // 이미 완료된 작업은 건너뛰기 (하지만 UI 업데이트는 필요)
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

        // 작업 실행 (인자가 있으면 전달)
        $result = empty($args) ? $functionName() : $functionName(...$args);

        // 중단된 경우
        if (isset($result['aborted']) && $result['aborted']) {
            sendSSEEvent('aborted', [
                'message' => lang('abort_installation_stopped'),
                'task' => $taskId,
                'target' => $target,
            ]);
            addLog(lang('abort_installation_stopped'));
            exit;
        }

        // 실패 시 즉시 중단
        if (!$result['success']) {
            $state = getInstallationState();
            $taskNameKey = "task_{$taskId}";
            $taskName = lang($taskNameKey);
            $displayName = $target ? "{$taskName} ({$target})" : $taskName;

            // 실패한 작업 롤백 (executeDbCommandSSE 등에서 이미 롤백한 경우 건너뛰기)
            if (empty($result['rollback_done'])) {
                sendSSEEvent('log', ['message' => lang('failed_rollback_start', ['task' => $displayName])]);

                $rollbackResult = rollbackTask($taskId, $state);

                sendRollbackOutputSSE($rollbackResult);

                if ($rollbackResult['success']) {
                    sendSSEEvent('log', ['message' => lang('failed_rollback_success', ['message' => $rollbackResult['message']])]);
                } else {
                    sendSSEEvent('log', ['message' => lang('failed_rollback_failed', ['message' => $rollbackResult['message']])]);

                    // DB 관련 작업인 경우에만 DB 수동 정리 안내
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
                            'detail' => lang('failed_rollback_manual_cleanup_detail')
                        ]);
                    } else {
                        // DB 무관 작업 실패 시 재시도 안내
                        sendSSEEvent('log', ['message' => lang('failed_rollback_retry')]);
                        sendSSEEvent('log', ['message' => lang('failed_rollback_retry_detail')]);

                        $rollbackFailure = [
                            'message_key' => 'failed_rollback_retry',
                            'detail_key' => 'failed_rollback_retry_detail',
                        ];

                        sendSSEEvent('rollback_failed', [
                            'message' => lang('failed_rollback_retry'),
                            'detail' => lang('failed_rollback_retry_detail')
                        ]);
                    }
                }
            }

            // 실패 상태 설정
            $state['installation_status'] = 'failed';

            // 실패 정보 상세 저장 (새로고침 후에도 표시용)
            $state['failed_task'] = $completedKey; // target 포함된 키 저장
            $state['failed_task_target'] = $target; // target 별도 저장
            $state['error_message_key'] = $result['message_key'] ?? null; // 번역 키 저장
            $state['error_detail'] = $result['detail'] ?? null;

            // 롤백 실패 정보 저장 (새로고침 후에도 표시용)
            // rollback_done 경로: 반환값의 rollback_failure 사용, 메인 루프 경로: $rollbackFailure 변수 사용
            if (!empty($result['rollback_done'])) {
                $state['rollback_failure'] = $result['rollback_failure'] ?? null;
            } else {
                $state['rollback_failure'] = $rollbackFailure ?? null;
            }

            // 수동 명령어 생성
            $manualCommands = getManualCommands($taskId, $target);
            $state['manual_commands'] = $manualCommands;

            saveInstallationState($state);

            addLog(lang('log_installation_task_failed', [
                'task' => $displayName,
                'message' => $result['message'] ?? lang('log_installation_failed')
            ]));

            // 수동 명령어 로그 출력
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
            exit;
        }
    }

    // 모든 작업 성공
    $_SESSION['installer_current_step'] = 5; // 세션을 Step 5로 동기화
    sendSSEEvent('completed', [
        'message' => lang('log_installation_completed'),
        'redirect' => '/install/',
    ]);

    addLog(lang('log_all_tasks_completed'));
} catch (Exception $e) {
    logInstallationError(lang('error_worker_exception'), $e);

    $state = getInstallationState();
    $state['installation_status'] = 'failed';

    // 실패 정보 상세 저장 (새로고침 후에도 표시용)
    $state['failed_task'] = $state['current_task'] ?? null;
    // failed_task_name은 저장하지 않음 (프론트엔드에서 task ID로 번역)
    $state['error_message_key'] = 'error_unexpected_exception'; // 예외 발생 시 번역 키
    $state['error_detail'] = $e->getMessage();

    saveInstallationState($state);

    addLog(lang('log_installation_exception', ['error' => $e->getMessage()]));

    sendSSEEvent('error', [
        'message' => lang('log_installation_failed'),
        'message_key' => 'error_unexpected_exception',
        'error' => $e->getMessage(),
    ]);
}
