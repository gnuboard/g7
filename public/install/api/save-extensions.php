<?php

/**
 * G7 인스톨러 - 선택된 확장 기능 저장 API
 *
 * 사용자가 선택한 확장 기능 목록을 state.json에 저장합니다.
 *
 * @method POST
 * @body {
 *   "admin_templates": ["sirsoft-admin_basic"],
 *   "user_templates": ["sirsoft-basic"],
 *   "modules": ["sirsoft-board", "sirsoft-ecommerce"],
 *   "plugins": ["sirsoft-verification"]
 * }
 *
 * @response {
 *   "success": true,
 *   "message": "확장 기능 선택이 저장되었습니다."
 * }
 */

// 기본 설정
header('Content-Type: application/json; charset=utf-8');

// 프로젝트 루트 경로
define('BASE_PATH', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));

// 세션 및 설정 포함
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/installer-state.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// 다국어 로드
$currentLang = getCurrentLanguage();
$translations = loadTranslations($currentLang);

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => lang('api_method_not_allowed'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 요청 본문 파싱
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => lang('api_invalid_request'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 필드 검증 및 기본값 설정
$adminTemplates = isset($input['admin_templates']) && is_array($input['admin_templates'])
    ? $input['admin_templates']
    : [];

$userTemplates = isset($input['user_templates']) && is_array($input['user_templates'])
    ? $input['user_templates']
    : [];

$modules = isset($input['modules']) && is_array($input['modules'])
    ? $input['modules']
    : [];

$plugins = isset($input['plugins']) && is_array($input['plugins'])
    ? $input['plugins']
    : [];

// 확장 이름 매핑 (identifier → {ko: '...', en: '...'})
$extensionNames = isset($input['extension_names']) && is_array($input['extension_names'])
    ? $input['extension_names']
    : [];

// 관리자 템플릿은 최소 1개 필수
if (empty($adminTemplates)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => lang('error_admin_template_required'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 문자열 배열로 정리 (보안)
$adminTemplates = array_values(array_filter(array_map('strval', $adminTemplates)));
$userTemplates = array_values(array_filter(array_map('strval', $userTemplates)));
$modules = array_values(array_filter(array_map('strval', $modules)));
$plugins = array_values(array_filter(array_map('strval', $plugins)));

try {
    // 현재 상태 가져오기
    $state = getInstallationState();

    // selected_extensions 업데이트
    $state['selected_extensions'] = [
        'admin_templates' => $adminTemplates,
        'user_templates' => $userTemplates,
        'modules' => $modules,
        'plugins' => $plugins,
    ];

    // 확장 이름 매핑 저장 (설치 시 표시용)
    $state['extension_names'] = $extensionNames;

    // Step 4 완료 표시
    $state['step_status']['4'] = 'completed';

    // 다음 스텝으로 이동
    $state['current_step'] = 5;

    // 세션도 동기화 (Step 5로 이동 허용)
    $_SESSION['installer_current_step'] = 5;

    // 상태 저장
    $saved = saveInstallationState($state);

    if (!$saved) {
        throw new Exception(lang('state_save_failed'));
    }

    // 로그 기록
    addLog(lang('log_extensions_selected', [
        'admin' => count($adminTemplates),
        'user' => count($userTemplates),
        'modules' => count($modules),
        'plugins' => count($plugins),
    ]));

    // 성공 응답
    echo json_encode([
        'success' => true,
        'message' => lang('log_extensions_saved'),
        'data' => [
            'selected_extensions' => $state['selected_extensions'],
            'next_step' => 5,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
