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
     * 클라이언트에 즉시 응답 전송
     *
     * JavaScript에서 이 응답을 받은 후 install-worker.php로 SSE 연결을 시작합니다.
     */
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status' => 'started',
        'message' => lang('success_installation_started'),
    ], JSON_UNESCAPED_UNICODE);

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
