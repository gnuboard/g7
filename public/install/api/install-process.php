<?php

/**
 * G7 인스톨러 설치 프로세스 API (SSE 방식)
 *
 * 설치 설정을 저장하고 상태를 'started'로 변경합니다.
 * 실제 설치 작업은 install-worker.php가 SSE를 통해 실행합니다.
 *
 * @method POST
 *
 * @response JSON {"status": "started", "message": "설치가 시작되었습니다"}
 */

// 필수 파일 포함 (config.php가 BASE_PATH를 정의함)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/session.php';
require_once __DIR__.'/../includes/installer-state.php';
// functions.php 를 installer-runtime.php 보다 먼저 로드한다 — 다른 인스톨러
// 엔드포인트(finalize-env.php / task-runner.php)와 동일한 순서.
// installer-runtime.php 는 escapeEnvValue() 를 function_exists 가드와 함께
// polyfill 로 선언하므로, functions.php 가 먼저 와야 그 가드가 제 역할을 한다.
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/installer-runtime.php';
require_once __DIR__.'/../includes/installer-ownership.php';
require_once __DIR__.'/../includes/install-plan.php';
require_once __DIR__.'/_guard.php';
installer_guard_or_410();

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
    echo installer_json_encode([
        'success' => false,
        'message' => lang('error_method_not_allowed'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /**
     * 설치 소유권 게이트 — 다른 무엇보다 먼저.
     *
     * 이 엔드포인트는 runtime.php(DB·관리자 비밀)와 설치 계획을 쓰고 설치를 실행시킨다.
     * Step 3 를 통과해 소유권을 가진 세션만, CSRF 토큰을 제시할 때만 진입한다.
     */
    $state = getInstallationState();
    installer_require_owner($state);
    installer_require_csrf();

    /**
     * 세션에서 설정 정보 로드
     * 세션이 비어있으면 state.json에서 로드 — 소유 게이트를 통과했으므로 같은 소유자의
     * 재개(복구 토큰으로 소유권을 되찾은 새 세션 등)만 이 경로에 도달한다.
     */
    $config = getSessionData('install_config');

    // 세션이 비어있으면 state.json에서 로드
    if (empty($config)) {
        $config = $state['config'] ?? [];

        // state.json에도 config가 없으면 에러
        if (empty($config)) {
            http_response_code(400);
            echo installer_json_encode([
                'success' => false,
                'message' => lang('error_config_not_in_session'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // state.json에서 로드한 config를 세션에도 저장 (이후 요청에서 사용)
        setSessionData('install_config', $config);
    }

    /**
     * 설치 모드 (sse | polling, 기본 sse) · 기존 DB 처리 액션 (skip | drop_tables, 기본 skip) · 삭제 확인 토큰
     *
     * POST 본문(JSON 또는 form)으로 전달된다.
     * - sse: install-worker.php GET + EventSource
     * - polling: 응답 완료 후 인라인으로 runInstallationTasks() 실행, 프론트는 state-management.php 폴링
     */
    $requestBody = installer_request_json_body();

    $installationMode = $requestBody['installation_mode']
        ?? $_POST['installation_mode']
        ?? 'sse';
    if (! in_array($installationMode, ['sse', 'polling'], true)) {
        $installationMode = 'sse';
    }

    $existingDbAction = (string) ($requestBody['existing_db_action'] ?? $_POST['existing_db_action'] ?? 'skip');

    /**
     * 설치 계획 확정 — 필수값·관리자 비밀번호 출처·.env·삭제 확인·runtime·서명·CAS 저장.
     * 본문은 웹·터미널 공유 함수(includes/install-plan.php)가 소유한다.
     */
    $plan = installer_begin_installation(
        $state,
        $config,
        [],
        $existingDbAction,
        (string) ($requestBody['drop_confirm'] ?? $_POST['drop_confirm'] ?? ''),
        ['installation_mode' => $installationMode]
    );

    if (! $plan['ok']) {
        if ($plan['error_code'] === 'state_conflict') {
            installer_json_error(409, 'state_conflict', $plan['message']);
        }

        http_response_code($plan['status']);
        $payload = ['success' => false, 'message' => $plan['message']];
        if ($plan['env_required']) {
            $payload += [
                'env_required' => true,
                'missing_files' => ['.env'],
                'base_path' => BASE_PATH,
                'is_windows' => isWindows(),
            ];
        }
        echo installer_json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingDbAction = $plan['existing_db_action'];
    $dropConfirmRejected = $plan['drop_confirm_rejected'];

    /**
     * 응답 JSON 준비 (echo 이전에 미리 문자열화하여 Content-Length 계산용)
     */
    $responseJson = installer_json_encode([
        'success' => true,
        'status' => 'started',
        'message' => lang('success_installation_started'),
        'installation_mode' => $installationMode,
        'existing_db_action' => $existingDbAction,
        'drop_confirm_rejected' => $dropConfirmRejected,
        'warning' => $plan['warning'],
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

        // 출력 버퍼 / 압축 비활성화 — install-worker.php(SSE) 와 동일.
        // PHP 8.5 회귀: php.ini output_buffering 또는 zlib.output_compression 이 활성화된
        // SAPI 환경에서 echo $responseJson 후 @flush() / fastcgi_finish_request() 만으로는
        // wire body 가 즉시 송신되지 않아 브라우저 fetch 가 워커 종료 시점까지 hang.
        // Content-Length 헤더와 압축 후 실제 바이트 수가 어긋나 chunked 전환되는 케이스도
        // 동시에 차단. echo 이전에 모두 비활성화해야 한다.
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', false);
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }

        // 기존 출력 버퍼 전부 비우기 (헤더 설정 전)
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        // 응답 헤더 — 브라우저가 응답 완료를 인식하도록 Content-Length + Connection: close
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Length: '.strlen($responseJson));
        header('Connection: close');

        echo $responseJson;

        // mod_fcgid 64KB 출력 버퍼 강제 flush — Apache + mod_fcgid 환경에서 PHP 응답이
        // FCGI 소켓 → mod_fcgid → Apache 경로로 전달되는데, mod_fcgid 의 default
        // FcgidOutputBufferSize(64KB) 가 차거나 스크립트가 종료해야 Apache 로 송출.
        // 워커가 인라인으로 10분간 실행되는 동안 응답이 client 까지 도달 못해
        // PollingMonitor 시작 자체가 불가능했던 회귀 차단.
        // 브라우저는 Content-Length 만큼만 읽고 fetch resolve — padding 은 무시됨.
        // FcgidOutputBufferSize 0 환경 / mod_php / PHP-FPM 환경에서는 무해 (불필요한
        // 64KB 1회 송출만 발생, 사용자 체감 영향 없음).
        echo str_repeat(' ', 65536);

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
        addLog('Client IP: '.($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

        // 클라이언트가 SSE 호환성 사전 체크 후 폴링 모드를 명시 선택한 흐름이다.
        // SSE 워커가 동시 실행되지 않으므로 takeover 로직 불필요 — 일반 lock 획득.
        $lockResult = acquireWorkerLock(15);
        if (! $lockResult['acquired']) {
            addLog('=== Polling Worker rejected — another worker is active ===');
            exit;
        }

        $workerId = $lockResult['worker_id'];
        addLog('Worker lock acquired: '.$workerId.' (reason: '.$lockResult['reason'].')');

        register_shutdown_function('releaseWorkerLock', $workerId);

        // 폴링 모드 — NullEmitter 등록 후 task runner 실행
        require_once __DIR__.'/../includes/progress-emitter.php';
        require_once __DIR__.'/../includes/task-runner.php';

        setProgressEmitter(new NullEmitter($workerId));
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
    echo installer_json_encode([
        'success' => false,
        'message' => lang('error_installation_start_exception', ['error' => $e->getMessage()]),
    ], JSON_UNESCAPED_UNICODE);
}
