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
 */

// 이 엔드포인트가 직접 부르는 판정 함수(installerSseWorkerRejected · installerHasFunction)의
// 출처 — task-runner 경유 전이 의존에 기대지 않고 명시한다 (R4 취지). 최상위 실행문이 없고
// BASE_PATH 를 자체 역산하므로 config 보다 앞에 와도 안전하다.
require_once __DIR__.'/../includes/execution-mode.php';

// 오류 텍스트가 SSE 프레임에 섞이지 않게 한다 — 상태 파일을 읽기 **전**, 헤더를 세우기 전,
// **include 체인·가드보다 앞** (13차 E6 · 21차 N4). 그 구간의 warning 한 줄이면
// text/event-stream 헤더가 서지 못해 EventSource 는 사유 대신 "연결 실패" 만 본다 —
// 12차 #A6 은 이 파일을 이미 고쳐진 것으로 기록했으나 실물은 그렇지 않았다.
if (installerHasFunction('ini_set')) {
    @ini_set('display_errors', '0');
}
error_reporting(E_ALL);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/installer-state.php';
require_once __DIR__.'/../includes/progress-emitter.php';
require_once __DIR__.'/../includes/task-runner.php';
require_once __DIR__.'/_guard.php';
installer_guard_finalize_or_410();
// 끝난 설치에 워커가 다시 붙는 일은 없다 (13차 보안#8) — `.env` 표식이 없어도 락 파일과
// 상태 파일의 완료 마커가 함께 참이면 설치는 실제로 끝난 것이다.
installer_guard_post_complete_or_410();

// SSE는 세션을 사용하지 않음 (세션 잠금 방지)
$state = getInstallationState();
$lang = $state['g7_locale'] ?? 'ko';
$translations = loadTranslations($lang);

// GET 요청만 허용 (SSE는 GET 기반)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    // charset 이 없으면 한글 사유가 mojibake 로 나가고, 캐시 지시자가 없으면 EventSource 의
    // GET 재연결에서 거부 응답이 재생되어 조건이 해소된 뒤에도 같은 거부가 반복된다 (17차 #B6).
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo installer_json_encode([
        'success' => false,
        'message' => lang('sse_method_not_allowed'),
        // 형제 거부 갈래들과 같은 봉투 (23차 8b) — 이 파일의 세 거부가 같은 규칙을 쓴다.
        'message_key' => 'sse_method_not_allowed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 진행 방식 확인 (R1 — 모드는 진입 1회 결정, 실행 중 전환 금지). 단계별 실행으로 확정된 설치는
// run-task.php 만 태스크를 돌린다. 이 입구로 들어오면 runInstallationTasks() 가 한 요청 안에서
// 전 태스크를 in-process 로 연속 실행하게 되어 계획서 A-2 가 피하려던 컨테이너 오염·실행 시간
// 상한 문제가 그대로 재현된다. run-task.php 가 stepwise 아닌 설치를 거부하는 것과 대칭이다.
if (installerSseWorkerRejected($state)) {
    http_response_code(409);
    // charset 이 없으면 한글 사유가 mojibake 로 나가고, 캐시 지시자가 없으면 EventSource 의
    // GET 재연결에서 거부 응답이 재생되어 조건이 해소된 뒤에도 같은 거부가 반복된다 (17차 #B6).
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo installer_json_encode([
        'success' => false,
        'message' => lang('error_sse_mode_mismatch'),
        // 형제 게이트(installerSseWorkerStateRejection)와 같은 봉투 (22차 #22-8) — 같은
        // 사실(이 입구의 모드가 아니다)이 모드에 따라 다른 봉투로 나가면, 본문을 읽는
        // 폴백 소비자가 생기는 순간 한쪽만 사유가 빈다.
        'message_key' => 'error_sse_mode_mismatch',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 진입 전제 (13차 보안#5·F6) — 위 판정은 「stepwise 인가」만 본다. 그래서 이 입구는
// 토큰도, CSRF 도, **상태 전제도** 없이 GET 하나로 runInstallationTasks() 를 돌릴 수 있었다:
// 크로스오리진 `<img src=".../install-worker.php">` 한 번이 실제 마이그레이션·시딩을 시작한다.
// 형제 입구 run-task.php 는 installerStepwiseEndpointRejection() 으로 이미 「진행 중인가」를
// 요구한다 — 같은 규칙을 같은 파일이 소유하게 해 두 입구를 대칭으로 유지한다.
// polling 으로 확정된 설치를 이 입구가 모는 비대칭도 그 판정이 함께 닫는다.
$workerRejection = installerCrossOriginRejection($_SERVER) ?? installerSseWorkerStateRejection($state);

if ($workerRejection !== null) {
    http_response_code($workerRejection['status']);
    // charset 이 없으면 한글 사유가 mojibake 로 나가고, 캐시 지시자가 없으면 EventSource 의
    // GET 재연결에서 거부 응답이 재생되어 조건이 해소된 뒤에도 같은 거부가 반복된다 (17차 #B6).
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo installer_json_encode([
        'success' => false,
        'message' => lang($workerRejection['message_key'], $workerRejection['message_params'] ?? []),
        'message_key' => $workerRejection['message_key'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// SSE 헤더 설정
header('Content-Type: text/event-stream');
// 디스크 캐시를 쓰는 중간 노드까지 막는다 — 형제 JSON 응답과 같은 강도 (17차 #B9).
header('Cache-Control: no-store');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx 버퍼링 비활성화

// 출력 버퍼 비활성화 (즉시 전송)
if (installerHasFunction('ini_set')) {
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
}

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

installerDiscardOutputBuffers();

// 타임아웃 설정 — 작업 루프(runInstallationTasks)가 작업마다 같은 값으로 다시 건다.
// 여기서는 첫 작업 전 준비 구간만 덮는다. 전체 설치 시간에는 제한이 없다.
// 막힌 호스팅에서도 설치는 진행되어야 하므로 가용할 때만 부른다 (13차 환경#1).
if (installerHasFunction('set_time_limit')) {
    set_time_limit((int) INSTALLER_TASK_TIME_LIMIT);
}

// 클라이언트 연결 끊김 시 즉시 워커 종료 (이슈 #319 부수 발견)
// 폴링 풀백과 SSE 워커 동시 실행으로 인한 race condition 차단.
// PHP_INI default 는 0(false) 이지만 명시적으로 설정하여 환경 차이를 봉합.
if (installerHasFunction('ignore_user_abort')) {
    ignore_user_abort(false);
}

// 출력 즉시 flush — disconnect 감지를 빠르게 트리거 (flush 시 EPIPE 발생).
@ob_implicit_flush(true);

// 여기부터 상태 파일을 읽고 쓴다 — 손상된 state.json 의 Error 계열이 새면 헤더가 이미
// text/event-stream 이라 EventSource 는 그것을 일반 연결 오류로만 보고한다. 운영자에게는
// 실제 사유 대신 "SSE 연결 실패" 가 남고, 폴링으로 폴백해 같은 손상을 다시 만난다.
// run-task.php 의 stepwiseFail 과 같은 이유·같은 폭이다.
try {
    // Worker 시작 로그 — 진행 기록의 독자는 운영자다 (§12.0 W3)
    addLog(lang('log_worker_started'));
    addLog(lang('log_worker_client_ip', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']));

    // 설치 계획 검증 — 이 워커는 세션을 쓰지 않으므로(세션 잠금 방지) 요청자를 세션으로 확인할 수 없다.
    // 대신 소유 게이트를 통과한 install-process 만 쓸 수 있는 running 상태 + 계획 서명을 확인한다.
    // 서명이 없거나 어긋나면 어떤 작업도 실행하지 않는다.
    $planVerdict = installer_plan_verify(getInstallationState(), readInstallerRuntime());
    if ($planVerdict !== 'ok') {
        addLog(lang('log_worker_rejected_plan_unverified', ['reason' => $planVerdict]));
        setProgressEmitter(new SseEmitter);
        sendSSEEvent('aborted', [
            'message' => lang('error_install_plan_unverified'),
            'reason' => 'plan_unverified',
        ]);
        exit;
    }

    // 워커 lock 획득 — 다른 워커가 활동 중이면 진입 거부 (race 차단).
    // SSE 응답으로 거부 사유 전달 후 종료.
    $lockResult = acquireWorkerLock(15);
    if (! $lockResult['acquired']) {
        addLog(lang('log_worker_rejected_busy'));
        setProgressEmitter(new SseEmitter);
        sendSSEEvent('aborted', [
            'message' => lang('error_worker_busy'),
            'reason' => 'busy',
        ]);
        exit;
    }

    $workerId = $lockResult['worker_id'];
    // 실행부가 자기 lock 을 가리킬 수 있게 기억해 둔다 — in-process 부팅이 요청 수명을
    // 늘릴 때 그 예산을 lock 에도 선언해야 아직 살아 있는 이 요청을 다른 요청이 stale 로
    // 보고 탈취하지 않는다. stepwise 진입점에만 배선하면 이 모드에서 겹쳐 실행이 남는다.
    installerCurrentWorkerId($workerId);
    addLog(lang('log_worker_lock_acquired'));

    // 워커 종료 시 lock 자동 해제 + 치명 종료면 상태를 실패로 승격 (12차 #A2) —
    // 승격하지 않으면 fatal/OOM/시간초과 뒤 상태가 running 에 고착해 [건너뛰고 계속] 이
    // 원리상 불가능해진다. SSE 모드면 실패를 error 이벤트로도 보내고, 연결 끊김으로 끝났으면
    // 그 사실을 진행 기록에 남긴다(화면이 새 워커로 다시 연결해 이어 받는다).
    register_shutdown_function('installerHandleWorkerShutdown', $workerId, 'sse');

    // SSE emitter 등록 후 task runner 실행
    setProgressEmitter(new SseEmitter($workerId));
    runInstallationTasks();
} catch (InstallerWorkerLockLostException $e) {
    // 점유자가 바뀐 것이지 설치가 실패한 것이 아니다 — 이 워커는 조용히 물러난다.
    // 실패로 그리면 승자 요청이 멀쩡히 도는 중에 화면이 빨개진다.
    addLog(lang('log_stepwise_lock_taken_over'));
    sendSSEEvent('aborted', [
        'message' => lang('error_worker_busy'),
        'reason' => 'busy',
    ]);
    exit;
} catch (Throwable $e) {
    // emitter 가 아직 없을 수 있다 (lock 획득 전 실패) — 그때도 프레임은 나가야 한다
    setProgressEmitter(new SseEmitter($workerId ?? null));

    // 상태도 함께 남긴다 (12차 #A11). 종전에는 error 프레임만 보내 화면만 빨갛고 서버는
    // `running` 으로 남았다 — 그 조합에서는 재개도 [건너뛰고 계속] 도 성립하지 않는다.
    // 기록·로깅·error 프레임 송출은 실패 경로 SSoT(recordInstallationRunException)가 한다.
    recordInstallationRunException($e);
    exit;
}
