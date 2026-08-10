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
 * ============================================================================
 * Apache + mod_fcgid 환경에서 SSE 미동작 트러블슈팅
 * ============================================================================
 *
 * 증상: 응답 헤더는 즉시 도착하나 body 가 응답 종료 시점에 한 번에 송출되어
 *      브라우저가 SSE 이벤트를 실시간 수신 못함. 인스톨러가 폴링 모드로 fallback.
 *
 * 원인: mod_fcgid 의 `FcgidOutputBufferSize` directive default 가 64KB 라서
 *      PHP-CGI 의 stdout chunk 가 64KB 미만이면 mod_fcgid 가 응답 종료까지
 *      buffer 에 hold. PHP 측 ob_implicit_flush / ini_set('output_buffering','off')
 *      는 mod_fcgid 레이어를 못 뚫지만, 64KB 임계 padding 송출은 실측상 즉시 flush
 *      를 트리거함 (curl ttfb 측정으로 확인).
 *
 * 해결:
 *  - 권장: Apache 의 fcgid.conf 에 `FcgidOutputBufferSize 0` 추가 후 재시작 — SSE 매
 *    이벤트가 즉시 client 에 도달하므로 본질적 해결.
 *  - 코드 워크어라운드 (폴링 분기 install-process.php 적용): 응답 echo 직후 64KB
 *    공백 padding 을 송출하여 mod_fcgid 임계 강제 통과. 폴링은 응답 1회만 송출하므로
 *    overhead 무시 가능. SSE 는 매 이벤트마다 padding 시 수십 MB 인플레이션이라
 *    적용하지 않음 — SSE 호환성 사전 체크(sse-probe.php) 가 buffered 환경을 감지하면
 *    클라이언트가 폴링 모드로 fallback.
 * ============================================================================
 *
 * @package G7\Installer
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/installer-state.php';
require_once __DIR__ . '/../includes/progress-emitter.php';
require_once __DIR__ . '/../includes/task-runner.php';
require_once __DIR__ . '/_guard.php';
installer_guard_or_410();

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

// 클라이언트 연결 끊김 시 즉시 워커 종료 (이슈 #319 부수 발견)
// 폴링 풀백과 SSE 워커 동시 실행으로 인한 race condition 차단.
// PHP_INI default 는 0(false) 이지만 명시적으로 설정하여 환경 차이를 봉합.
ignore_user_abort(false);

// 출력 즉시 flush — disconnect 감지를 빠르게 트리거 (flush 시 EPIPE 발생).
@ob_implicit_flush(true);

// 오류를 SSE 이벤트로 전송하기 위해 오류 출력 비활성화
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Worker 시작 로그 (디버깅용)
addLog('=== Install Worker SSE Started ===');
addLog('Client IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// 워커 lock 획득 — 다른 워커가 활동 중이면 진입 거부 (race 차단).
// SSE 응답으로 거부 사유 전달 후 종료.
$lockResult = acquireWorkerLock(15);
if (! $lockResult['acquired']) {
    addLog('=== SSE Worker rejected — another worker is active ===');
    setProgressEmitter(new SseEmitter());
    sendSSEEvent('aborted', [
        'message' => lang('error_worker_busy'),
        'reason' => 'busy',
    ]);
    exit;
}

$workerId = $lockResult['worker_id'];
addLog('Worker lock acquired: ' . $workerId . ' (reason: ' . $lockResult['reason'] . ')');

// 워커 종료 시 lock 자동 해제
register_shutdown_function('releaseWorkerLock', $workerId);

// SSE emitter 등록 후 task runner 실행
setProgressEmitter(new SseEmitter($workerId));
runInstallationTasks();
