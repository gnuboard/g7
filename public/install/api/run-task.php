<?php

/**
 * 그누보드7 웹 인스톨러 - 단계별(stepwise) 설치 태스크 실행 엔드포인트
 *
 * 요청 하나가 태스크 하나를 실행한다 (A-2). 브라우저(StepwiseMonitor)가 이 엔드포인트를
 * 순차 호출하고, 각 요청은 필요하면 Laravel 을 새로 부팅해 태스크 하나를 수행한 뒤
 * 상태를 state.json 에 기록하고 끝난다. 서브프로세스와 같은 프로세스 격리를 얻으면서
 * 요청당 실행 시간도 짧아지므로 `set_time_limit()` 이 막힌 호스팅에서도 성립한다.
 *
 * 어느 태스크가 다음인지는 서버의 completed_tasks 가 정한다 — 클라이언트는 인덱스를
 * 보내지 않는다. 그래서 새로고침해도 같은 호출로 이어서 진행된다 (W3).
 *
 * 진입 조건 (R5·R8):
 *   - 설치 완료 후 차단 가드 경유. complete_flag 태스크가 g7_installed 락을 먼저 만들고
 *     그 다음 태스크(config_cache)가 같은 흐름에서 이어지므로, 락 파일이 아니라 `.env` 의
 *     INSTALLER_COMPLETED=true(완전 완료 신호)로 판정하는 finalize 전용 가드를 쓴다 —
 *     state-management.php 의 폴링 구간과 같은 이유다.
 *   - 세션이 아니라 상태 파일 + 토큰으로 진행한다. 토큰은 설치 시작(install-process.php)
 *     시 발급되어 runtime.php(0600) 에만 보관되며 state.json 에는 기록되지 않는다 (R3).
 *   - installation_mode 가 stepwise 여야 한다 — 모드는 진입 1회 결정, 실행 중 전환 금지 (R1).
 *   - 워커 lock 을 잡는다. 다른 요청이 태스크를 돌리는 중이면 busy 로 돌려보내고
 *     클라이언트가 기다린다 (새로고침 재개 시 직전 요청이 아직 도는 경우).
 *
 * @method POST
 *
 * @response JSON {"outcome": "task_done|completed|failed|aborted|busy|rejected", "task": "...", "events": [...], "state": {...}}
 * @response 500 JSON {"outcome": "failed", "message_key": "error_unexpected_exception", "error_detail": "...", "state": {...}}
 */

// 이 엔드포인트가 직접 부르는 판정 함수(installerStepwiseEndpointRejection ·
// installerHasFunction)의 출처 — task-runner 경유 전이 의존에 기대지 않고 명시한다
// (R4 취지). 최상위 실행문이 없고 BASE_PATH 를 자체 역산하므로 config 보다 앞에 와도 안전하다.
require_once __DIR__.'/../includes/execution-mode.php';

// 오류 텍스트가 본문에 섞이지 않게 한다 — **include 체인·가드보다 앞**.
// 종전에는 include 체인 뒤에 있어서 그 체인(config/state/runtime)의 warning 이 JSON 본문
// 앞에 붙는 창이 남았다. 상세 사유는 아래 원 위치의 주석 참조.
if (installerHasFunction('ini_set')) {
    @ini_set('display_errors', '0');
}
error_reporting(E_ALL);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/installer-state.php';
require_once __DIR__.'/../includes/installer-runtime.php';
require_once __DIR__.'/../includes/progress-emitter.php';
require_once __DIR__.'/../includes/task-runner.php';
require_once __DIR__.'/_guard.php';
installer_guard_finalize_or_410();

// 태스크 하나가 끝나는 동안 lock 을 잡아 둘 수 있는 상한. 정상 경로는 shutdown 에서 즉시
// 풀리므로 이 값은 프로세스가 강제 종료된 경우의 회수 시간일 뿐이다 — 짧게 잡으면
// 긴 마이그레이션 도중 새로고침한 재개 요청이 같은 태스크를 겹쳐 돌린다.
if (! defined('STEPWISE_LOCK_STALE_SECONDS')) {
    // P0 실측 최대 태스크 ≈15s(vendor 추출) — 그 10배면 느린 호스트도 덮고, 죽은 요청 뒤의
    // "대기 중" 정지는 3분을 넘지 않는다. 탈취가 일어나면 물러나는 쪽은 busy 로 응답한다.
    define('STEPWISE_LOCK_STALE_SECONDS', 180);
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// 채널 가드 이력 — lock 획득 뒤에 있던 것을 헤더 직후로 올렸고(상태 읽기·토큰·
// 모드 가드·lock 구간의 warning 이 JSON 본문 앞에 붙어 res.json() 이 throw), 이어서
// **include 체인보다도 앞**(파일 최상단)으로 다시 올렸다 — 체인(config/state/runtime) 안의
// warning 창이 남아 있었기 때문이다. 이 가드는 응답을 방출하는 **모든** api 진입점의 의무다:
// 실측 당시 11곳 중 4곳만 보유(앞선 「형제 5곳 전부 보유」 기록은 사실과 달랐다).
// 존재·순서 모두 파생 모집단 게이트 InstallerJsonEndpointErrorChannelTest 가 강제한다.
// `ini_set` 은 호스팅 표준 차단 목록에 있고(`installerIniSet` docblock), 그 줄은 try 밖이라
// 막힌 환경에서는 JSON 봉투조차 서지 못한 채 500 이 된다 — 그래서 가용성 가드가 필수다.

/**
 * JSON 응답을 내보내고 종료한다.
 *
 * @param  array<string, mixed>  $payload  응답 본문
 * @param  int  $status  HTTP 상태 코드
 */
function stepwiseRespond(array $payload, int $status = 200): void
{
    http_response_code($status);

    // 인코딩 실패에도 빈 본문을 내보내지 않는다. 이 응답에는 artisan 원문 출력이
    // events 로 실리므로 깊이·인코딩 문제로 false 가 돌아올 수 있는데, `echo false` 는 빈
    // 본문이라 클라이언트의 `res.json()` 이 throw 하고 화면은 서버 오류를 「통신 오류」로
    // 잘못 보고한다. 폴링 응답(state-management.php)이 이미 같은 폴백을 갖고 있다.
    $encoded = installer_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($encoded === false) {
        $encoded = installer_json_encode([
            'success' => false,
            'outcome' => 'failed',
            'message_key' => 'error_response_encoding_failed',
            'state' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    echo $encoded;
    exit;
}

/**
 * 잡히지 않은 예외를 JSON 500 으로 내보낸다.
 *
 * 이 엔드포인트는 헤더가 이미 application/json 이고 브라우저(StepwiseMonitor)가 본문을
 * JSON 으로 파싱한다 — Error/TypeError 가 새어 나가면 비-JSON 본문이 나가 운영자에게는
 * 실제 사유 대신 일반 통신 오류만 보인다. state-management.php 와 같은 이유·같은 폭이다.
 *
 * @param  Throwable  $e  잡힌 예외
 */
function stepwiseFail(Throwable $e): void
{
    if (function_exists('logInstallationError')) {
        logInstallationError(lang('error_worker_exception'), $e);
    }

    // 응답 계약을 지킨다 — `outcome` 이 없으면 클라이언트는 「알 수 없는 응답」 분기로 떨어지고,
    // `message` 에 담긴 예외 원문을 **실패 화면 본문**으로 그린다. 개발자 표기는 제목이 아니라
    // 진단 상세에만 싣는다 (§12.0 W3). 사유는 번역 키로 주고 원문은 detail 로 내린다.
    $state = [];
    try {
        $state = stepwiseStateSummary(getInstallationState());
    } catch (Throwable) {
        // 상태 파일을 읽지 못하는 상황에서도 봉투 자체는 나가야 한다
        $state = [];
    }

    stepwiseRespond(installerStepwiseFailurePayload($e->getMessage(), $state), 500);
}

/**
 * 클라이언트가 필요로 하는 상태 요약 (state-management.php?action=get 과 같은 필드).
 *
 * @param  array<string, mixed>  $state  설치 상태
 * @return array<string, mixed> 상태 요약
 */
function stepwiseStateSummary(array $state): array
{
    // 요약의 형태·값은 execution-mode.php 가 소유한다 — 이 파일은 최상위 스크립트라
    // require 로 부를 수 없어, 여기에 본문을 두면 「부른다」만 소스로 확인할 수 있고
    // 필드가 빠지거나 이름이 바뀌어도 어떤 테스트도 red 가 되지 않는다.
    return installerStepwiseStateSummary($state);
}

// 여기부터가 보호 구간이다 — 응답 헬퍼는 위에서 이미 정의돼 있어야 catch 가 응답할 수 있다.
try {

    // 세션을 쓰지 않는다 — 상태 파일 기준 언어
    $state = getInstallationState();
    $lang = $state['g7_locale'] ?? 'ko';
    $translations = loadTranslations($lang);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        stepwiseRespond(['success' => false, 'outcome' => 'rejected', 'message' => lang('error_method_not_allowed')], 405);
    }

    // 출처 확인 — 형제 상태 변경 입구(abort·reset·skip_task·finalize-env)가 전부 거치는 검사다.
    // 이 엔드포인트만 빠져 있었는데, 인스톨러에서 실제로 Artisan 을 돌리는 곳이 여기다.
    // 토큰이 실질 방어라 즉시 악용되지는 않지만, 요구 집합이 형제와 갈려 있으면 어느 쪽이
    // 옳은지 다음 사람이 알 수 없다 — 앞서 실제로 그 형태의 결함이 있었다.
    $originRejection = installerCrossOriginRejection($_SERVER);
    if ($originRejection !== null) {
        stepwiseRespond([
            'success' => false,
            'outcome' => 'rejected',
            'message' => lang($originRejection['message_key']),
            'message_key' => $originRejection['message_key'],
        ], $originRejection['status']);
    }

    // 토큰 검증 — 헤더 우선, JSON 본문 폴백 (sendBeacon 등 헤더를 못 싣는 호출용)
    $requestBody = [];
    $rawInput = file_get_contents('php://input');
    if (! empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $requestBody = $decoded;
        }
    }

    $presented = (string) ($_SERVER['HTTP_X_INSTALLER_TOKEN'] ?? ($requestBody['token'] ?? ''));
    // 이 창이 이미 화면에 그린 진행 기록 줄 수 — busy 응답이 못 본 꼬리만 싣는 기준이다.
    $clientLogOffset = max(0, (int) ($requestBody['log_offset'] ?? 0));

    $expected = (string) (readInstallerRuntime()['installer']['stepwise_token'] ?? '');

    // 설치 계획 검증(installer_plan_verify)을 따로 하지 않는 이유 — 형제 install-worker.php 는
    // 진입 시 그 검증으로 막는데, 그 워커는 세션도 토큰도 받지 않아 「소유 게이트를 통과한 계획인가」를
    // 서명으로만 확인할 수 있기 때문이다. 이 입구의 토큰은 install-process.php 가 소유 게이트
    // (installer_require_owner) 통과 뒤, 계획 서명과 **같은 설치 계획 확정 호출**에서 새로 발급해
    // runtime.php(0600)에만 둔다. 시작할 때마다 바뀌므로 토큰을 제시했다는 것이 곧 이번 회전의 계획을
    // 소유자가 확정했다는 증거다. 또 그 검증은 running 만 허용해, complete_flag 뒤 completed 상태에서
    // 이어지는 config_cache 재개(installerStepwiseEndpointRejection 이 허용한다)를 막는다.

    // 거부 사유를 나눈다 — 「보관본이 없다」는 새로고침으로 복구되지 않는데
    // 종전에는 「토큰이 틀렸다」와 같은 문구·같은 안내(새로고침)를 냈다.
    $tokenRejection = installerStepwiseTokenRejection($presented, $expected);
    if ($tokenRejection !== null) {
        stepwiseRespond([
            'success' => false,
            'outcome' => 'rejected',
            'message' => lang($tokenRejection['message_key']),
            'message_key' => $tokenRejection['message_key'],
        ], $tokenRejection['status']);
    }

    // 진행 모드·상태 검증 — stepwise 로 시작한 설치만, running(또는 complete_flag 직후의 completed) 만.
    // 판정은 install-worker.php 의 대칭 가드와 같은 파일(execution-mode.php)이 소유한다.
    $rejection = installerStepwiseEndpointRejection($state);
    if ($rejection !== null) {
        stepwiseRespond([
            'success' => false,
            'outcome' => $rejection['outcome'],
            'message' => lang($rejection['message_key'], $rejection['message_params'] ?? []),
            'state' => stepwiseStateSummary($state),
        ], $rejection['status']);
    }

    // 요청 수명 = 이 요청이 lock 에 선언하는 stale 창. 둘은 반드시 같이 움직인다 —
    // 수명이 더 길면 아직 살아 있는 요청을 다른 요청이 stale 로 보고 탈취해 같은 태스크를
    // 겹쳐 돌리고, 더 짧으면 이미 죽은 요청의 점유가 남아 다음 요청을 그만큼 막는다.
    // in-process 부팅은 둘을 함께 올린다 (artisan-executor.php 의 extendWorkerStaleWindow).
    //
    // 선언은 요청값이 아니라 **실제로 적용된 값**으로 한다 (제품 결정) —
    // `set_time_limit(180)` 이 먹지 않는 호스팅(막힌 함수·잠긴 ini)에서 180 을 선언하면
    // 30초에 죽은 요청의 점유가 3분 남아 그동안 화면이 「다른 창에서 진행 중」에 멈춘다.
    // 그래서 수명을 먼저 확정하고, 그 결과를 lock 에 선언한다 (순서가 뒤집히면 lock 이
    // 확정 전 값을 받는다).
    $stepwiseTimeBudget = installerSetTimeLimit(STEPWISE_LOCK_STALE_SECONDS);

    // 워커 lock — 다른 요청이 태스크를 돌리는 중이면 대기 신호만 돌려준다
    $lockResult = acquireWorkerLock($stepwiseTimeBudget);
    if (! $lockResult['acquired']) {
        // 이 창이 기다리는 동안 **다른 요청이** 진행 기록을 쓴다. 단계별 실행의 화면 로그는
        // ① 재개 시 1회 ② 자기 응답의 events 두 통로뿐이라, 그 사이에 쌓인 줄은 어디로도
        // 도달하지 못했다 — 「단계는 초록인데 그 단계의 기록이 없다」가 된다. 폴링은
        // log_offset 증분 조회로 이 구멍이 없다. 같은 방식으로 못 본 꼬리만 실어 준다.
        stepwiseRespond([
            'success' => true,
            'outcome' => 'busy',
            'message' => lang('error_worker_busy'),
            'state' => stepwiseStateSummary($state),
            'logs' => getInstallationLogs($clientLogOffset),
            'log_total' => getInstallationLogCount(),
        ]);
    }

    $workerId = $lockResult['worker_id'];
    installerCurrentWorkerId($workerId);
    // lock 해제 + 치명 종료면 상태를 실패로 승격 — 승격하지 않으면 fatal/OOM/
    // 시간초과 뒤 상태가 running 에 고착해 [건너뛰고 계속] 이 원리상 불가능해진다.
    register_shutdown_function('installerHandleWorkerShutdown', $workerId);

    // 브라우저가 새로고침·이탈해도 진행 중인 태스크는 끝까지 간다 — 중간에 끊기면 DB 가 반쪽이 된다.
    // 두 호출 모두 가용성 판정을 거친다 — `disable_functions` 로 막힌 호스팅에서는 호출 자체가
    // 치명 오류(Error)이고 `@` 는 그것을 잡지 못한다. 막혔으면 그 기능만 포기한다: 이탈 시
    // 태스크가 중간에 끊길 수 있지만, 요청이 통째로 죽어 아무 응답도 못 주는 것보다 낫다.
    if (installerHasFunction('ignore_user_abort')) {
        ignore_user_abort(true);
    }
    if ($lockResult['reason'] === 'takeover_stale') {
        // 진행 기록의 독자는 운영자다 (§12.0 W3) — 개발자 문구를 그대로 넣지 않는다
        addLog(lang('log_stepwise_lock_taken_over'));
    }

    $emitter = new BufferEmitter($workerId);
    setProgressEmitter($emitter);

    try {
        $run = runNextInstallationTask();
    } catch (InstallerWorkerLockLostException $e) {
        // 다른 요청이 같은 태스크를 이어받았다 — 이 요청은 물러나고 브라우저가 잠시 후 재시도한다
        stepwiseRespond([
            'success' => true,
            'outcome' => 'busy',
            'message' => lang('stepwise_lock_lost_retry'),
            'events' => $emitter->drain(),
            'state' => stepwiseStateSummary(getInstallationState()),
        ]);
    }
    $events = $emitter->drain();

    stepwiseRespond([
        'success' => true,
        'outcome' => $run['outcome'],
        'task' => $run['task'],
        'target' => $run['target'],
        'remaining' => $run['remaining'],
        'events' => $events,
        'state' => stepwiseStateSummary(getInstallationState()),
    ]);

} catch (Throwable $e) {
    stepwiseFail($e);
}
