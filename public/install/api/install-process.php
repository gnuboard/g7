<?php

/**
 * G7 인스톨러 설치 프로세스 API (SSE 방식)
 *
 * 설치 설정을 저장하고 상태를 'started'로 변경합니다.
 * 실제 설치 작업은 install-worker.php가 SSE를 통해 실행합니다.
 *
 * @method POST
 * @response JSON {"status": "started", "message": "설치가 시작되었습니다"}
 */

// 필수 파일 포함 (config.php가 BASE_PATH를 정의함)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/installer-state.php';
require_once __DIR__ . '/../includes/functions.php';

// 다국어 로드
$currentLang = getCurrentLanguage();
$translations = loadTranslations($currentLang);

// JSON 응답 헤더 설정
header('Content-Type: application/json; charset=UTF-8');

/**
 * POST 요청인지 확인
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => lang('error_method_not_allowed'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /**
     * 세션에서 설정 정보 로드
     * 세션이 비어있으면 (브라우저 재시작 등) state.json에서 로드
     */
    $config = getSessionData('install_config');

    // 세션이 비어있으면 state.json에서 로드
    if (empty($config)) {
        $state = getInstallationState();
        $config = $state['config'] ?? [];

        // state.json에도 config가 없으면 에러
        if (empty($config)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => lang('error_config_not_in_session'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // state.json에서 로드한 config를 세션에도 저장 (이후 요청에서 사용)
        setSessionData('install_config', $config);
    }

    /**
     * 필수 설정 항목 검증
     */
    $requiredFields = [
        'db_write_host',
        'db_write_database',
        'db_write_username',
        'app_name',
        'app_url',
        'admin_name',
        'admin_email',
        'admin_password',
    ];

    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (empty($config[$field])) {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => lang('error_required_fields_missing', ['fields' => implode(', ', $missingFields)]),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 필수 파일 존재 여부 사전 체크
     */
    $missingRequiredFiles = [];
    if (!file_exists(BASE_PATH . '/.env')) {
        $missingRequiredFiles[] = '.env';
    }

    if (!empty($missingRequiredFiles)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => lang('error_env_not_found'),
            'env_required' => true,
            'missing_files' => $missingRequiredFiles,
            'base_path' => BASE_PATH,
            'is_windows' => isWindows(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 설치 모드 수신 (sse | polling, 기본 sse)
     *
     * POST 본문(JSON 또는 form)에서 installation_mode 필드로 전달됩니다.
     * - sse: 기존 방식 (install-worker.php GET + EventSource)
     * - polling: install-process.php 응답 완료 후 인라인으로 runInstallationTasks() 실행,
     *            프론트엔드는 state-management.php를 1초 간격으로 폴링
     */
    $requestBody = [];
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $requestBody = $decoded;
        }
    }

    $installationMode = $requestBody['installation_mode']
        ?? $_POST['installation_mode']
        ?? 'sse';
    if (!in_array($installationMode, ['sse', 'polling'], true)) {
        $installationMode = 'sse';
    }

    /**
     * 기존 DB 처리 액션 수신 (skip | drop_tables, 기본 skip)
     *
     * 이슈 #244: Step 3에서 기존 DB 감지 후 사용자가 "강제 진행" 선택 시 전달.
     */
    $existingDbAction = $requestBody['existing_db_action']
        ?? $_POST['existing_db_action']
        ?? 'skip';
    if (!in_array($existingDbAction, ['skip', 'drop_tables'], true)) {
        $existingDbAction = 'skip';
    }

    /**
     * 현재 상태 가져오기
     */
    $state = getInstallationState();

    /**
     * 상태 초기화
     *
     * 재시도/재개인 경우 (installation_status가 'running', 'failed' 또는 'aborted'인 경우):
     * - completed_tasks는 유지 (이미 완료된 작업은 건너뛰기)
     * - error는 초기화
     * - 나머지는 running 상태로 재설정
     */
    $isRetry = isset($state['installation_status']) &&
               in_array($state['installation_status'], ['running', 'failed', 'aborted']);

    $state['current_step'] = 5;  // Step 5 = Installation (Step 4 = Extension Selection)
    $state['installation_status'] = 'running';
    $state['completed_tasks'] = $isRetry ? ($state['completed_tasks'] ?? []) : [];
    $state['current_task'] = null;
    $state['config'] = $config;
    $state['error'] = null;
    $state['installation_mode'] = $installationMode;
    $state['existing_db_action'] = $existingDbAction;

    /**
     * 로그 파일 초기화 (재시도가 아닌 경우에만)
     *
     * Step 4 -> Step 5로 새로 진입하는 경우 이전 로그를 초기화합니다.
     * 재시도/재개인 경우에는 기존 로그를 유지합니다.
     */
    if (!$isRetry) {
        $logFilePath = BASE_PATH . '/storage/logs/installation.log';
        if (file_exists($logFilePath)) {
            @unlink($logFilePath);
        }
    }

    /**
     * 상태 저장
     */
    $saved = saveInstallationState($state);

    if (!$saved) {
        throw new Exception(lang('state_save_failed'));
    }

    /**
     * 로그 기록
     */
    addLog(lang('log_installation_config_saved'));

    /**
     * 응답 JSON 준비 (echo 이전에 미리 문자열화하여 Content-Length 계산용)
     */
    $responseJson = json_encode([
        'success' => true,
        'status' => 'started',
        'message' => lang('success_installation_started'),
        'installation_mode' => $installationMode,
    ], JSON_UNESCAPED_UNICODE);

    /**
     * 폴링 모드: 응답을 완전히 종료한 후 워커 인라인 실행
     *
     * 핵심 과제: 브라우저의 `await fetch(install-process.php)`가 응답 완료를
     * 인식해야 JS가 폴링 시작 코드로 진행 가능하다. Apache mod_php에서는
     * ob_end_flush() + flush() 만으로는 HTTP 연결이 끊기지 않아, 워커가
     * 인라인으로 10분간 실행되는 동안 브라우저 fetch 가 대기하게 된다.
     *
     * 해결:
     *  - PHP-FPM: fastcgi_finish_request() — 응답 즉시 종료
     *  - mod_php 폴백: Content-Length + Connection: close 헤더 명시 →
     *    브라우저가 지정된 바이트 수만 읽고 연결을 닫음
     */
    if ($installationMode === 'polling') {
        ignore_user_abort(true);

        // CRITICAL: 세션 잠금 해제 — 워커가 인라인으로 10분간 실행되는 동안
        // 세션 파일이 잠겨있으면 폴링용 state-management.php?action=get 요청이
        // session_start()에서 대기하여 진행 상황을 전혀 확인할 수 없게 됨.
        //
        // task-runner의 완료 시점 $_SESSION['installer_current_step']=5 쓰기는
        // 세션이 닫힌 후에는 효과 없으므로, 미리 여기서 저장한다.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['installer_current_step'] = 5;
            session_write_close();
        }

        // 기존 출력 버퍼 전부 비우기 (헤더 설정 전)
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        // 응답 헤더 — 브라우저가 응답 완료를 인식하도록 Content-Length + Connection: close
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Length: ' . strlen($responseJson));
        header('Connection: close');

        echo $responseJson;

        // 출력 플러시 + PHP-FPM 지원 시 연결 종료
        @flush();
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        // 워커 타임아웃 설정 (SSE 모드와 동일)
        @set_time_limit(600);
        @ini_set('display_errors', '0');
        error_reporting(E_ALL);

        addLog('=== Install Worker Polling Started ===');
        addLog('Client IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

        // 폴링 모드 — NullEmitter 등록 후 task runner 실행
        require_once __DIR__ . '/../includes/progress-emitter.php';
        require_once __DIR__ . '/../includes/task-runner.php';

        setProgressEmitter(new NullEmitter());
        runInstallationTasks();
        exit;
    }

    // SSE 모드: 일반 JSON 응답
    http_response_code(200);
    echo $responseJson;

} catch (Exception $e) {
    /**
     * 에러 처리
     */
    // 에러 로깅
    logInstallationError(lang('error_installation_start_failed'), $e);

    // 상태를 failed로 변경
    $state = getInstallationState();
    $state['installation_status'] = 'failed';
    $state['error'] = $e->getMessage();
    saveInstallationState($state);

    // 에러 응답
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => lang('error_installation_start_exception', ['error' => $e->getMessage()]),
    ], JSON_UNESCAPED_UNICODE);
}
