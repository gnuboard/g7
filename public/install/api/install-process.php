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

// installerHasFunction 의 출처 — 아래 출력 채널 가드가 쓴다 (전이 의존 금지, R4 취지).
// 최상위 실행문이 없고 BASE_PATH 를 자체 역산하므로 config 보다 앞에 와도 안전하다.
require_once __DIR__.'/../includes/execution-mode.php';

// 오류 텍스트가 본문에 섞이지 않게 한다 — **include 체인·가드보다 앞**.
// 종전에는 include 체인 뒤에 있어서 설정 로드·세션 시작 구간의 warning 이 JSON 본문 앞에
// 붙는 창이 남았다.
if (installerHasFunction('ini_set')) {
    @ini_set('display_errors', '0');
}
error_reporting(E_ALL);

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
installer_guard_finalize_or_410();
// 설치가 실제로 끝났는데 `.env` 표식만 없는 상태에서는 재시작이 성립하지 않는다.
// `.env` 쓰기가 권한 문제로 실패해도 위 가드는 통과시키므로, 운영 사이트에서 이 엔드포인트가
// 영구히 열린 채 남는 것이 종전 상태였다.
installer_guard_post_complete_or_410();

// 다국어 로드
$currentLang = getCurrentLanguage();
$translations = loadTranslations($currentLang);

// JSON 응답 헤더 설정
header('Content-Type: application/json; charset=UTF-8');

// 채널 가드 이력 — 폴링 워커 구간 한정이던 것을 이 위치로 올렸고, 이어서
// include 체인보다 앞(파일 최상단)으로 다시 올렸다. 존재·순서는 파생 모집단 게이트
// InstallerJsonEndpointErrorChannelTest 가 강제한다.

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

/**
 * 출처 확인
 *
 * 인스톨러에는 CSRF 토큰 검증이 없다 — 헬퍼는 정의만 있고 호출부가 0건이었다. 세션 쿠키가
 * `SameSite=Lax` 라 top-level 폼 제출에는 동반 전송되므로, 다른 사이트의 자동 제출 폼 하나로
 * 이 엔드포인트(설치 시작 + 단계별 토큰 발급)를 부를 수 있었다. 판정은 execution-mode.php 가
 * 소유한다 — 형제 엔드포인트들이 같은 규칙을 공유해야 한 곳이 우회로가 되지 않는다.
 */
$originRejection = installerCrossOriginRejection($_SERVER);
if ($originRejection !== null) {
    http_response_code($originRejection['status']);
    echo installer_json_encode([
        'success' => false,
        'message' => lang($originRejection['message_key']),
        'message_key' => $originRejection['message_key'],
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
     * 설치 모드 (sse | polling | stepwise, 기본 sse) · 기존 DB 처리 액션 (skip | drop_tables, 기본 skip) · 삭제 확인 토큰
     *
     * POST 본문(JSON 또는 form)으로 전달된다.
     * - sse: install-worker.php GET + EventSource
     * - polling: 응답 완료 후 인라인으로 runInstallationTasks() 실행, 프론트는 state-management.php 폴링
     * - stepwise: 요청 1개 = 태스크 1개. 프론트엔드가 run-task.php 를 순차 호출한다 (A-2).
     *            유효 실행 모드가 inprocess 면 이 모드로 강제된다 — 한 요청 안에서
     *            Artisan::call 을 연속 호출하면 직전 설치의 변경이 반영되지 않은 컨테이너로
     *            다음 태스크가 돈다.
     */
    $requestBody = installer_request_json_body();

    $installationMode = $requestBody['installation_mode']
        ?? $_POST['installation_mode']
        ?? 'sse';
    if (! in_array($installationMode, ['sse', 'polling', 'stepwise'], true)) {
        $installationMode = 'sse';
    }

    /**
     * 실행 모드 판정 — 설치 시작 시점에 한 번 내려 state 에 박는다 (R1: 진입 1회 결정).
     *
     * 명시 선택(subprocess/inprocess)은 그대로, auto 는 환경 감지(Step 3 php_binary 기준).
     * inprocess 면 (1) 진행 모드를 stepwise 로 강제하고 (2) Vendor 설치 방식을 bundled 로
     * 고정한다 (A-4) — composer 는 proc_open 없이 성립하지 않는다.
     */
    $executionResolution = installerResolveExecutionMode($config);
    $executionMode = $executionResolution['mode'];

    $config['vendor_mode'] = installerCoerceVendorModeForExecution(
        (string) ($config['vendor_mode'] ?? 'auto'),
        $executionMode,
    );

    // 진행 방식 확정 — inprocess 강제(A-2)와 composer 조합 차단(#M3)을 한 판정이 소유한다.
    // 화면이 두 규칙을 모두 표시하지만, 저장된 값·직접 요청이 그대로 들어올 수 있으므로
    // 서버가 같은 함수로 다시 적용한다 (R9 — 화면만 막으면 그 지점이 우회로가 된다).
    $installationDecision = installerResolveInstallationMode(
        $installationMode,
        $executionMode,
        (string) $config['vendor_mode'],
    );
    $installationMode = $installationDecision['mode'];
    $forcedStepwise = $installationDecision['forced'];
    // 저장된 선택이 서버에서 뒤집힌 두 번째 경우 — 「단계별 실행 + Composer」 조합은 한 단계가
    // 요청 수명을 넘기므로 실시간 스트리밍으로 내린다. 형제 판정(forced)만 로그·응답에 실려서,
    // 이쪽은 화면 표시와 실제 진행 방식이 어긋나도 흔적이 없었다.
    $downgradedFromStepwise = $installationDecision['downgraded'];

    /**
     * 시작 요청 거부 판정
     *
     * 상태를 덮어쓰기 **전에** 본다 — 종전에는 두 번째 탭이 먼저 상태를 통째로 갈아엎고
     * `started` 를 응답한 뒤에야 워커 lock 획득에 실패해, 아무 신호 없이 종료했다.
     * 그 순간 첫 탭은 진행 방식이 바뀌어 409 가 되고 두 번째 탭은 아무도 진행시키지 않는
     * `running` 을 무한 폴링한다 — 두 탭이 함께 멈춘다. 설치 계획 확정(installer_begin_installation)
     * 이 runtime·state 를 쓰므로 그 호출보다 앞에 둔다.
     *
     * 같은 판정이 진행 중인 stepwise 설치에 기존 토큰을 요구한다. 이 엔드포인트가
     * 무인증으로 새 토큰을 발급해 온 탓에, 앞서 [중단]·[초기화]·[건너뛰고 계속]에 건
     * 토큰 게이트가 요청 하나로 우회 가능했다.
     */
    // 생존 판정과 「남은 시간」은 같은 셈에서 나온다 (D7) — 한 함수가 둘을 낸다.
    $lockRemaining = installerWorkerLockRemainingSeconds($state, 15);

    $startRejection = installerStartRejection(
        $state,
        $lockRemaining > 0,
        (string) ($_SERVER['HTTP_X_INSTALLER_TOKEN'] ?? ($requestBody['token'] ?? '')),
        (string) (readInstallerRuntime()['installer']['stepwise_token'] ?? ''),
        $lockRemaining,
    );

    if ($startRejection !== null) {
        http_response_code($startRejection['status']);
        $payload = [
            'success' => false,
            // 남은 시간을 **문구 안에도** 넣는다 (D7). 화면이 `retry_after` 를 쓰지 못하는
            // 판본(구형 자산 캐시)에서도 운영자가 "얼마나 기다리면 되는가" 를 읽을 수 있어야 한다.
            'message' => lang($startRejection['message_key'], ['seconds' => (string) $lockRemaining]),
            'message_key' => $startRejection['message_key'],
        ];
        if (isset($startRejection['retry_after'])) {
            // 표준 헤더로도 준다 — 프록시·클라이언트가 같은 뜻으로 읽는 값이다.
            header('Retry-After: '.$startRejection['retry_after']);
            $payload['retry_after'] = $startRejection['retry_after'];
        }
        echo installer_json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 단계별 실행 토큰 (R8) — run-task.php 는 세션이 아니라 이 토큰으로 호출자를 확인한다.
     * 비밀 채널 규칙(R3)에 따라 runtime.php(0600) 에만 두고 state.json 에는 싣지 않는다
     * (기록은 설치 계획 확정이 runtime_installer 로 받아 한다). 시작할 때마다 새로 발급한다 (재시도 포함).
     */
    $stepwiseToken = null;
    $runtimeInstaller = null;
    if ($installationMode === 'stepwise') {
        $stepwiseToken = bin2hex(random_bytes(16));

        // 소유자 표식 — 이 토큰은 Step 5 화면 HTML 에 실린다. 진행 중 설치가
        // 있으면 **세션이 없는 요청**도 Step 5 로 안내되므로(index.php 가 state 의
        // current_step 을 채택) 무인증 GET 한 번으로 토큰이 유출된다. 「이 세션이 설치를
        // 시작한 세션인가」를 대조할 수 있게 같은 값을 runtime.php(0600)와 세션 양쪽에 심는다.
        // 판정은 installerSessionOwnsStepwiseInstall() 이 소유한다.
        $sessionOwner = bin2hex(random_bytes(16));
        setSessionData('installer_session_owner', $sessionOwner);

        $runtimeInstaller = ['stepwise_token' => $stepwiseToken, 'session_owner' => $sessionOwner];
    } else {
        deleteSessionData('installer_session_owner');
    }

    $existingDbAction = (string) ($requestBody['existing_db_action'] ?? $_POST['existing_db_action'] ?? 'skip');

    /**
     * 설치 계획 확정 — 필수값·관리자 비밀번호 출처·.env 존재·쓰기 가능·삭제 확인·runtime·상태 초기화·서명·CAS 저장.
     * 본문은 웹·터미널 공유 함수(includes/install-plan.php)가 소유한다. 위에서 내린 웹 판정 중 상태에 함께
     * 저장돼 계획 서명에 들어가야 하는 값은 state_extra 로, 단계별 실행 토큰은 runtime_installer 로 넘긴다.
     */
    $plan = installer_begin_installation(
        $state,
        $config,
        [],
        $existingDbAction,
        (string) ($requestBody['drop_confirm'] ?? $_POST['drop_confirm'] ?? ''),
        [
            'installation_mode' => $installationMode,
            'state_extra' => [
                'execution_mode_resolved' => $executionMode,
                // D35 — 자동 탐색된 PHP 경로를 실행(subprocess 러너)이 실제로 쓰도록 state 에 박는다
                'php_binary_resolved' => installerResolvedPhpBinary($config, $executionResolution),
            ],
            'runtime_installer' => $runtimeInstaller,
        ]
    );

    if (! $plan['ok']) {
        if ($plan['error_code'] === 'state_conflict') {
            installer_json_error(409, 'state_conflict', $plan['message']);
        }

        http_response_code($plan['status']);
        $payload = ['success' => false, 'message' => $plan['message']];
        if ($plan['env_required']) {
            $payload['env_required'] = true;
            if (! empty($plan['env_not_writable'])) {
                // 화면이 「부재」 안내와 「쓰기 불가」 안내를 구분해 그리는 데 필요한 필드
                // 형제 갈래(.env 부재)와 같은 봉투 폭을 유지한다.
                $payload['env_not_writable'] = true;
            } else {
                $payload['missing_files'] = ['.env'];
            }
            $payload += [
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
     * 실행 모드 로그 — 설치 계획 확정이 새 시작의 로그를 비운 뒤에 남긴다.
     */
    addLog(lang('log_execution_mode_resolved', [
        'mode' => lang('execution_mode_label_'.$executionMode),
        'source' => lang('execution_mode_source_'.$executionResolution['source']),
    ]));
    if ($forcedStepwise) {
        addLog(lang('log_installation_mode_forced_stepwise'));
    }
    if ($downgradedFromStepwise) {
        addLog(lang('log_installation_mode_downgraded_sse'));
    }

    /**
     * 응답 JSON 준비 (echo 이전에 미리 문자열화하여 Content-Length 계산용)
     */
    $responseJson = installer_json_encode([
        'success' => true,
        'status' => 'started',
        'message' => lang('success_installation_started'),
        'installation_mode' => $installationMode,
        'installation_mode_forced' => $forcedStepwise,
        'installation_mode_downgraded' => $downgradedFromStepwise,
        'execution_mode' => $executionMode,
        'stepwise_token' => $stepwiseToken,
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
        if (installerHasFunction('ignore_user_abort')) {
            ignore_user_abort(true);
        }

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
        if (installerHasFunction('ini_set')) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        // 기존 출력 버퍼 전부 비우기 (헤더 설정 전)
        installerDiscardOutputBuffers();

        // 응답 헤더 — 브라우저가 응답 완료를 인식하도록 Content-Length + Connection: close
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        // Nginx 리버스 프록시 뒤에서는 위의 ob/zlib 차단만으로 부족하다 — 프록시가 응답을
        // 버퍼링하면 인라인 워커가 끝날 때까지(최대 600초) 본문이 브라우저에 닿지 않아
        // 폴링이 시작조차 못 한다. SSE 형제 둘은 이 헤더를 세운다.
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store');
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

        // 워커 타임아웃 설정 (SSE 모드와 동일) — 작업 루프가 작업마다 같은 값으로 다시 건다.
        // 막힌 호스팅에서도 설치는 진행되어야 하므로 가용할 때만 부른다.
        if (installerHasFunction('set_time_limit')) {
            @set_time_limit((int) INSTALLER_TASK_TIME_LIMIT);
        }
        if (installerHasFunction('ini_set')) {
            @ini_set('display_errors', '0');
        }
        error_reporting(E_ALL);

        addLog(lang('log_worker_started'));
        addLog(lang('log_worker_client_ip', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']));

        // 클라이언트가 SSE 호환성 사전 체크 후 폴링 모드를 명시 선택한 흐름이다.
        // SSE 워커가 동시 실행되지 않으므로 takeover 로직 불필요 — 일반 lock 획득.
        $lockResult = acquireWorkerLock(15);
        if (! $lockResult['acquired']) {
            addLog(lang('log_worker_rejected_busy'));

            // 응답은 이미 `started` 로 나갔으므로 여기서 조용히 끝내면 화면은 아무도
            // 진행시키지 않는 `running` 을 무한 폴링한다. 형제 두 입구는 사유를 돌려준다
            // (run-task 는 `busy`, SSE 워커는 `aborted{reason:'busy'}`) — 폴링만 통로가
            // 없었다. 상태 파일에 사유를 남겨 폴링 조회가 읽게 한다.
            //
            // 읽기·쓰기는 배타 구간 안에서 한다 — 이 분기는 정의상 승자가 도는
            // 중에만 실행되므로, 구간 밖 RMW 는 승자의 완료 마커를 덮는 구조적 경합이다.
            installerRecordBusyRejection();
            exit;
        }

        $workerId = $lockResult['worker_id'];
        // in-process 부팅이 요청 수명을 늘릴 때 그 예산을 lock 에도 선언하려면
        // 실행부가 자기 worker_id 를 볼 수 있어야 한다 (run-task.php 와 동일 배선).
        installerCurrentWorkerId($workerId);
        addLog(lang('log_worker_lock_acquired'));

        // 폴링 모드 — NullEmitter 등록 후 task runner 실행
        require_once __DIR__.'/../includes/progress-emitter.php';
        require_once __DIR__.'/../includes/task-runner.php';

        // 워커 종료 처리 — lock 해제 + fatal(실행 시간 초과 등)이면 설치를 failed 로 기록한다
        // (폴링 화면이 상태를 읽어 실패 안내와 재시도를 보인다). 처리기는 task-runner.php 에 있다.
        register_shutdown_function('installerHandleWorkerShutdown', $workerId, 'polling');

        setProgressEmitter(new NullEmitter($workerId));
        runInstallationTasks();
        exit;
    }

    // SSE 모드: 일반 JSON 응답
    http_response_code(200);
    echo $responseJson;

} catch (InstallerWorkerLockLostException $e) {
    /**
     * 점유자가 바뀐 것이지 설치가 실패한 것이 아니다.
     *
     * 아래 generic catch 로 흘려보내면 `installation_status = 'failed'` 를 기록해
     * **승자 요청의 진행 위에 실패를 덮어쓴다** — §16.10.1 #3 이 태스크 본문에서 닫은
     * 것과 같은 사고를 오케스트레이터 바깥에서 되풀이하는 셈이다. 상태는 손대지 않고
     * 물러난다(브라우저는 폴링으로 승자의 진행을 계속 본다).
     */
    logInstallationError(lang('log_stepwise_lock_taken_over'), $e);
    exit;
} catch (Throwable $e) {
    /**
     * 에러 처리
     *
     * Throwable 로 받는다 — try 안에 클래스 로드(installerResolveExecutionMode 등)가 있어
     * Error 계열이 가능하고, 이 응답은 JSON 계약이라 예외가 새면 본문 형식이 깨진다.
     */
    // 에러 로깅
    logInstallationError(lang('error_installation_start_failed'), $e);

    // 상태를 failed로 변경.
    // 예외 원문은 상태 파일에 남기지 않는다 — 그 값은 무인증 폴링 응답
    // (`state-management.php?action=get` 의 `error`)으로 그대로 나간다. 원문은 위
    // logInstallationError 가 로그에만 남기고, 상태에는 번역 가능한 키를 남긴다.
    $state = getInstallationState();
    $state['installation_status'] = 'failed';
    $state['error'] = lang('error_installation_start_failed');
    $state['error_message_key'] = 'error_installation_start_failed';
    $state['error_message_params'] = null;
    saveInstallationState($state);

    // 에러 응답 — 예외 원문은 싣지 않는다. 이 엔드포인트는 인증이 없어
    // 절대경로·DB 호스트명 같은 배치 정보가 그대로 나갔다. 원문은 위 logInstallationError
    // 가 로그에만 남기고, 화면에는 번역된 일반 문구를 준다.
    http_response_code(500);
    echo installer_json_encode([
        'success' => false,
        'message' => lang('error_installation_start_failed'),
        'message_key' => 'error_installation_start_failed',
    ], JSON_UNESCAPED_UNICODE);
}
