<?php

/**
 * 그누보드7 웹 인스톨러 - SSE 기반 설치 작업 워커 (진입점)
 *
 * Server-Sent Events(SSE)를 사용하여 실시간으로 설치 진행 상태를 스트리밍합니다.
 * 브라우저 연결이 끊어지면 즉시 설치를 중단합니다.
 *
 * 실제 작업 실행 로직은 includes/task-runner.php의 runInstallationTasks()가 담당하며,
 * 이 파일은 SSE 헤더 설정과 SseEmitter 등록만 수행합니다.
 *
 * @package G7\Installer
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/installer-state.php';
require_once __DIR__ . '/../includes/progress-emitter.php';
require_once __DIR__ . '/../includes/task-runner.php';

// SSE는 세션을 사용하지 않음 (세션 잠금 방지)
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

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}

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

// SSE emitter 등록 후 task runner 실행
setProgressEmitter(new SseEmitter());
runInstallationTasks();
