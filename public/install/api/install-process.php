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
    $generation = (int) ($state['generation'] ?? 0);

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
    ];

    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (empty($config[$field])) {
            $missingFields[] = $field;
        }
    }

    /**
     * admin_password 는 state.config 에 기록되지 않으므로(이슈 #465) 3중 출처로 판정한다.
     *
     *  1. 세션 config — 일반 경로 (Step 3 입력 직후)
     *  2. runtime.php 의 admin 섹션 — 세션 유실 후 재시도/재개
     *  3. db_seed 완료 마커 — 이미 관리자 계정이 생성되어 비밀번호가 더 이상 불필요
     *
     * runtime.php 는 소유 게이트를 통과한 이 엔드포인트만 쓰고, 최초 소유 주장 시 이전 시도의
     * 잔존본은 폐기되므로 2번 출처는 언제나 같은 소유자의 값이다 (자격증명 혼합 차단).
     *
     * 셋 다 없으면 Step 3 재입력이 필요한 상태 (의도된 안전 실패).
     */
    $hasAdminPassword = ! empty($config['admin_password'])
        || ! empty(readInstallerRuntime()['admin']['password'] ?? '')
        || in_array('db_seed', $state['completed_tasks'] ?? [], true);

    if (! $hasAdminPassword) {
        $missingFields[] = 'admin_password';
    }

    if (! empty($missingFields)) {
        http_response_code(400);
        echo installer_json_encode([
            'success' => false,
            'message' => lang('error_required_fields_missing', ['fields' => implode(', ', $missingFields)]),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 필수 파일 존재 여부 사전 체크
     */
    $missingRequiredFiles = [];
    if (! file_exists(BASE_PATH.'/.env')) {
        $missingRequiredFiles[] = '.env';
    }

    if (! empty($missingRequiredFiles)) {
        http_response_code(400);
        echo installer_json_encode([
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
    $requestBody = installer_request_json_body();

    $installationMode = $requestBody['installation_mode']
        ?? $_POST['installation_mode']
        ?? 'sse';
    if (! in_array($installationMode, ['sse', 'polling'], true)) {
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
    if (! in_array($existingDbAction, ['skip', 'drop_tables'], true)) {
        $existingDbAction = 'skip';
    }

    /**
     * 기존 테이블 삭제 확인 (drop_tables 전용)
     *
     * 요청 본문의 액션 값만으로는 삭제하지 않는다. Step 3 DB 테스트가 발급해 설치 계획에 실린
     * 확인 토큰을 제시하고, 그때 탐지한 테이블이 지금도 그대로여야 한다. 불충족이면 삭제 없이(skip)
     * 진행하고 경고를 돌려준다. 이미 소비된 확인은 같은 소유자의 재시도로 보고 테이블 변동을 허용한다.
     */
    $dropConfirmRecord = is_array($state['drop_confirm'] ?? null) ? $state['drop_confirm'] : null;
    $dropConfirmRejected = false;

    if ($existingDbAction === 'drop_tables') {
        $dbPrefix = (string) ($config['db_prefix'] ?? '');
        $currentPrefixTables = null;

        if ($dropConfirmRecord !== null && empty($dropConfirmRecord['consumed'])) {
            try {
                $pdo = getDatabaseConnection(hydrateDbSecretsFromRuntime($config), false);
                $statement = $pdo->query('SHOW TABLES');
                $currentPrefixTables = filterTablesByPrefix(
                    $statement ? $statement->fetchAll(PDO::FETCH_COLUMN) : [],
                    installer_normalize_db_prefix($dbPrefix)
                );
            } catch (Throwable $e) {
                $currentPrefixTables = null;
            }
        }

        $decision = installer_apply_drop_confirm(
            $dropConfirmRecord,
            (string) ($requestBody['drop_confirm'] ?? $_POST['drop_confirm'] ?? ''),
            $dbPrefix,
            $currentPrefixTables
        );

        $existingDbAction = $decision['action'];
        $dropConfirmRecord = $decision['record'];
        $dropConfirmRejected = $decision['rejected'];

        if ($dropConfirmRejected) {
            addLog(lang('log_drop_confirm_rejected', ['reason' => $decision['reason']]));
        }
    }

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

    /**
     * 비밀 채널 일원화 (이슈 #465)
     *
     * DB/관리자 비밀번호는 state.json(0664, 실패 시 무기한 잔존) 이 아니라
     * storage/installer/runtime.php(0600, finalize 시 삭제) 로만 전달한다.
     * SSE 워커는 세션에 접근할 수 없으므로 파일 경유가 불가피하며, 세션을 보유한
     * 유일한 공통 시작점인 본 엔드포인트가 이송 지점이다 (SSE/폴링 공통, 재시도 포함).
     *
     * buildInstallerRuntimeFromState() 가 기존 runtime 의 비밀번호/APP_KEY 를 보존하므로
     * 세션 유실 후 재개 시에도 자격증명이 유지된다.
     */
    $runtime = buildInstallerRuntimeFromState($config);
    if (! empty($config['admin_password'])) {
        $runtime['admin'] = ['password' => $config['admin_password']];
    }
    // 설치 계획 서명 키 — 세션이 없는 SSE 워커가 이 엔드포인트(소유 게이트 통과)가 확정한
    // 계획인지 확인하는 데 쓴다. runtime.php(0600) 에만 두고 finalize 때 함께 삭제된다.
    if (! is_string($runtime['plan_key'] ?? null) || $runtime['plan_key'] === '') {
        $runtime['plan_key'] = bin2hex(random_bytes(32));
    }
    if (! writeInstallerRuntime($runtime)) {
        throw new Exception(lang('state_save_failed'));
    }

    $state['current_step'] = 5;  // Step 5 = Installation (Step 4 = Extension Selection)
    $state['installation_status'] = 'running';
    $state['completed_tasks'] = $isRetry ? ($state['completed_tasks'] ?? []) : [];
    $state['current_task'] = null;
    $state['config'] = sanitizeConfigForState($config);
    $state['error'] = null;
    $state['installation_mode'] = $installationMode;

    // existing_db_action 변경 시 db_cleanup 재실행 보장 (이슈 #319 부수 발견)
    // 이전 'skip' → 신규 'drop_tables' 케이스에서 cleanup 이 재호출되도록 마커 제거
    $state = applyExistingDbActionStateGuard($state, $existingDbAction);
    $state['existing_db_action'] = $existingDbAction;
    $state['drop_confirm'] = $dropConfirmRecord;

    // 커밋될 세대로 설치 계획에 서명한다 (saveInstallationStateCas 가 같은 값으로 올린다).
    $state['generation'] = $generation + 1;
    $state['install_plan_sig'] = installer_plan_signature($state, $runtime['plan_key']);

    /**
     * 로그 파일 초기화 (재시도가 아닌 경우에만)
     *
     * Step 4 -> Step 5로 새로 진입하는 경우 이전 로그를 초기화합니다.
     * 재시도/재개인 경우에는 기존 로그를 유지합니다.
     */
    if (! $isRetry) {
        $logFilePath = BASE_PATH.'/storage/logs/installation.log';
        if (file_exists($logFilePath)) {
            @unlink($logFilePath);
        }
    }

    /**
     * 상태 저장 — 읽은 뒤 다른 상태변경이 먼저 커밋됐으면 덮어쓰지 않는다 (CAS).
     */
    if (! saveInstallationStateCas($state, $generation)) {
        installer_json_error(409, 'state_conflict', lang('error_state_conflict'));
    }

    /**
     * 로그 기록
     */
    addLog(lang('log_installation_config_saved'));

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
        'warning' => $dropConfirmRejected ? lang('drop_confirm_rejected') : null,
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
