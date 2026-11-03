<?php

/**
 * 인스톨러 최종 단계 — runtime.php 의 동적 설정을 .env 에 머지
 *
 * Step 6 완료 UI 노출 직후 브라우저가 fire-and-forget 으로 호출한다.
 * 응답을 즉시 반환한 뒤 .env 작성을 수행하여, php artisan serve 의
 * mtime 워처가 트리거하는 워커 재시작이 사용자에게 영향을 주지 않도록 한다.
 *
 * 멱등성: runtime.php 부재 시 즉시 정상 응답 (이미 finalize 됨).
 * 실패 시: runtime.php 보존 → InstallerRuntimeServiceProvider 가 계속 동작
 *          → 앱은 정상 (관리자 재호출 또는 다음 부팅 시 재시도 경로 확보).
 *
 * 설치 워커와의 순서: 워커는 상태를 completed 로 기록하는 complete_flag 뒤에도 설정 캐시·라우트 캐시·
 * 정적 게시를 이어서 돈다. 완료 화면은 completed 를 보는 즉시 이 엔드포인트를 부르므로, 병합은 워커가
 * lock 을 풀 때까지 기다린 뒤 한다(상한 INSTALLER_FINALIZE_WORKER_WAIT_SECONDS). 워커의 설정 캐시는
 * 병합 전 .env 로 만들어졌으므로 병합 직전에 지우고, 병합·runtime.php 삭제 뒤 다시 만든다.
 *
 * @see https://github.com/gnuboard/g7/issues/23
 */

declare(strict_types=1);

// installerHasFunction 의 출처 (전이 의존 금지, R4 취지). 최상위 실행문이 없고 BASE_PATH
// 를 자체 역산하므로 config 보다 앞에 와도 안전하다.
require_once __DIR__.'/../includes/execution-mode.php';

// 오류 텍스트가 응답 본문에 섞이지 않게 한다 (20차 #13 · 21차 N4). 이 진입점은 아래에서
// Content-Length 를 세워 fire-and-forget 응답을 보내므로, include 체인·가드에서 warning
// 한 줄만 나와도 실제 바이트 수와 어긋나 응답 계약이 깨진다 — **include 체인보다 앞**
// 이어야 한다 (뒤에 두면 그 체인의 warning 창이 그대로 남는다).
if (installerHasFunction('ini_set')) {
    @ini_set('display_errors', '0');
}
error_reporting(E_ALL);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/binary-path-policy.php';
require_once __DIR__.'/../includes/installer-runtime.php';
require_once __DIR__.'/../includes/installer-state.php';
// 노출 점검 sentinel 정리 헬퍼 (B-4) — 아래 정리 구간에서 호출
require_once __DIR__.'/../includes/exposure-check.php';
// 설치 소유 세션 판정용 — session.php 는 installer-state.php 를 require 하므로 그 뒤에 둔다.
require_once __DIR__.'/../includes/session.php';
require_once __DIR__.'/../includes/installer-ownership.php';
require_once __DIR__.'/../includes/env-finalize.php';
require_once __DIR__.'/_guard.php';
// finalize 전용 가드 — `.env` 의 INSTALLER_COMPLETED=true 단독으로만 차단한다.
// 일반 인스톨러 엔드포인트의 `installer_guard_or_410()` 은 `g7_installed` 락 파일도
// 차단 사유로 삼는데, 그 락 파일은 finalize 호출 직전 단계의 complete_flag task 가
// 먼저 생성하므로 자가 차단 회귀가 발생한다 (이슈 #371).
installer_guard_finalize_or_410();

// 다국어 로드 — 형제 엔드포인트 6곳(`install-process` · `install-worker` · `run-task` ·
// `save-extensions` · `state-management` · `check-configuration`)이 모두 이 자리를 갖는데
// 이 파일만 빠져 있었다. `lang()` 은 `$translations` 가 없으면 **키를 그대로 돌려주므로**,
// 마무리 단계의 진행 기록이 `log_finalize_env_config_cache_delete_failed` 같은 원시 키로
// 운영자에게 나갔다 — 예외도 경고도 없이 문구만 사라지는 형태다. 설정 캐시 삭제 실패처럼
// **운영자가 직접 조치해야 하는** 안내가 그 기록에 실리므로 그 키가 곧 조치 불능이 된다
// (13차 G3 라운드).
$currentLang = getCurrentLanguage();
$translations = loadTranslations($currentLang);

// 완료 표식 기록 + runtime.php·상태 파일 삭제는 인스톨러에서 가장 되돌릴 수 없는 동작이다.
// 위 가드는 「이미 finalize 됐는가」만 보므로, 「끝난 설치가 맞는가」·「이 창이 주인인가」는
// 여기서 따로 판정한다 (11차 #A1). 판정 자체는 execution-mode.php 가 소유한다.
$finalizeState = getInstallationState();
$finalizeBody = json_decode((string) file_get_contents('php://input'), true);
$finalizeRejection = installerCrossOriginRejection($_SERVER) ?? installerFinalizeRejection(
    is_array($finalizeState) ? $finalizeState : [],
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['HTTP_X_INSTALLER_TOKEN'] ?? ((is_array($finalizeBody) ? ($finalizeBody['token'] ?? null) : null) ?? '')),
    (string) (readInstallerRuntime()['installer']['stepwise_token'] ?? ''),
    // 「상태 파일이 사라졌다」는 파일을 직접 보고 판정한다 (13차 D10) — getInstallationState() 는
    // 부재 시 기본 상태를 돌려주므로 반환값만으로는 부재를 알 수 없다.
    is_file(STATE_PATH),
);

if ($finalizeRejection !== null) {
    http_response_code($finalizeRejection['status']);
    header('Content-Type: application/json; charset=utf-8');
    echo installer_json_encode([
        'success' => false,
        'message' => lang($finalizeRejection['message_key']),
        'message_key' => $finalizeRejection['message_key'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// state 가 남아 있으면(아직 finalize 전) 설치 소유 세션만 머지를 요청할 수 있다 — 위 판정은 「끝난 설치가
// 맞는가」와 단계별 실행 토큰을 보고, 이 판정은 「설치 마법사를 통과한 그 브라우저인가」를 본다.
// 진행 중 호출 차단(설치 완료 전 머지 → INSTALLER_COMPLETED 기록 → 모든 엔드포인트 410)은 위
// installerFinalizeRejection() 의 complete_flag 판정이 맡는다.
$finalizeStateFile = installerReadStateFile();
if ($finalizeStateFile !== null && installer_state_is_claimed($finalizeStateFile)) {
    installer_require_owner($finalizeStateFile);
    installer_require_csrf();
}

// ---------------------------------------------------------------------------
// 1. 응답을 즉시 송출하여 브라우저가 완료 UI 를 유지할 수 있게 한다.
// ---------------------------------------------------------------------------

// 설치 소유 세션 판정 때문에 이 엔드포인트도 `session.php` 를 싣는다 — 응답 뒤 단계(워커 대기·
// 병합·설정 캐시 다시 만들기)가 세션 잠금을 쥔 채 돌면 같은 브라우저의 다른 요청이 그동안 멈추므로
// 응답 전에 세션을 닫는다 (17차 #B8 이 「세션이 생기면 곧바로 필요한 방어」로 남겨 둔 분기다).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 호스팅이 막았을 수 있는 함수는 호출 전에 가용성을 본다 (13차 환경#1) — `@` 는 Error 를
// 삼키지 않으므로, 막힌 환경에서 무가드로 부르면 여기서 치명 종료해 .env 머지가 통째로 사라진다.
if (installerHasFunction('ignore_user_abort')) {
    ignore_user_abort(true);
}

// 출력 버퍼 / 압축 비활성화 — 형제 `install-process.php` 의 폴링 fire-and-forget 과 동일 (17차 #B3).
// php.ini `output_buffering` 또는 `zlib.output_compression` 이 켜진 SAPI 에서는 아래
// `Content-Length` 와 압축 후 실제 바이트 수가 어긋나 chunked 로 전환되거나 본문이 즉시
// 송신되지 않는다 — `.env` 머지는 서버에서 끝나는데 브라우저의 완료 화면만 멈춘다.
// echo 이전에 모두 비활성화해야 한다.
if (installerHasFunction('ini_set')) {
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
}
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

$accepted = installer_json_encode(['accepted' => true]);

header('Content-Type: application/json; charset=utf-8');
// Nginx 리버스 프록시가 응답을 버퍼링하면 fire-and-forget 이 성립하지 않는다 (SSE 형제 2곳과 동일).
header('X-Accel-Buffering: no');
header('Cache-Control: no-store');
header('Content-Length: '.strlen($accepted));
header('Connection: close');

echo $accepted;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // cli-server (artisan serve) / Apache mod_php 폴백
    installerDiscardOutputBuffers(true);
    @flush();
}

// ---------------------------------------------------------------------------
// 2. 응답 송출 후 .env 머지
// ---------------------------------------------------------------------------

// 응답 뒤 단계는 워커 대기와 설정 캐시 다시 만들기(artisan)를 포함한다 — 실행 시간을 그만큼 확보한다.
// 막힌 호스팅에서는 부르지 않는다 — `@` 는 차단 함수의 Error 를 삼키지 않는다 (13차 환경#1).
if (installerHasFunction('set_time_limit')) {
    @set_time_limit((int) INSTALLER_FINALIZE_WORKER_WAIT_SECONDS + (int) INSTALLER_TASK_TIME_LIMIT);
}

// 2-1. 설치 워커가 마지막 작업(설정 캐시·라우트 캐시·정적 게시)을 끝내고 lock 을 풀 때까지 기다린다.
// 워커의 config:cache 도중에 병합하면 병합 전 .env 값이 설정 캐시에 굳고(사이트 503), 상태 파일이
// 지워진 워커는 lock 을 잃어 남은 작업을 건너뛴다.
$stateBeforeWait = installerReadStateFile();
if ($stateBeforeWait !== null && installerHasActiveWorker($stateBeforeWait)) {
    addLog(lang('log_finalize_env_waiting_for_worker'));

    $workerWait = installerWaitForWorkerRelease((int) INSTALLER_FINALIZE_WORKER_WAIT_SECONDS);
    if ($workerWait['released']) {
        addLog(lang('log_finalize_env_worker_released', [
            'seconds' => sprintf('%.1f', $workerWait['waited_seconds']),
        ]));
    } else {
        addLog(lang('log_finalize_env_worker_wait_timeout', [
            'seconds' => (string) (int) INSTALLER_FINALIZE_WORKER_WAIT_SECONDS,
        ]));
    }
}

// 2-2. 병합은 한 번만 — 완료 화면이 두 번 떠(새로고침, 다른 탭) 동시에 불려도 뒤 요청은 앞 요청이 끝난 뒤
// runtime.php 부재로 바로 끝난다.
$finalizeLockPath = BASE_PATH.'/storage/installer/finalize.lock';
if (! is_dir(dirname($finalizeLockPath))) {
    @mkdir(dirname($finalizeLockPath), 0755, true);
}
$finalizeLock = @fopen($finalizeLockPath, 'c');
if ($finalizeLock !== false && ! @flock($finalizeLock, LOCK_EX)) {
    @fclose($finalizeLock);
    $finalizeLock = false;
}

try {
    // 멱등성: runtime.php 부재 → 이미 finalize 된 상태 (뒤에 온 동시 요청도 여기서 끝난다)
    if (readInstallerRuntime() === null) {
        return;
    }

    // 본문은 웹·터미널 공유 함수(includes/env-finalize.php) — 병합 전 설정 캐시 삭제, 병합, runtime.php 삭제,
    // 병합된 .env 로 설정 캐시 다시 만들기(실패하면 캐시 없이 둔다)까지 한다. 결과 문구는 installation.log 에 남는다.
    // 쓰기 직전 .env 보존, 부분 쓰기 판정, 상태 파일과 그 잠금 파일 정리도 그 함수가 맡는다.
    // 번역 테이블은 위 다국어 로드가 실어 두었다 — 그 함수의 기록 문구는 다국어 키다.
    installer_finalize_env();
} catch (Throwable $e) {
    // 예외 시 runtime.php 보존 → Provider 가 계속 config 주입 → 앱 정상 동작.
    addLog(lang('log_finalize_env_unexpected_exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => (string) $e->getLine(),
    ]));
} finally {
    // 웹 노출 점검 잔여물 제거 (B-4) — 설치 중에만 필요한 난수 파일이다. 남아도 정보
    // 가치는 없지만, 「지우려 했는데 못 지웠다」는 사실은 남긴다: 그 디렉토리가 쓰기 불가로
    // 바뀐 신호일 수 있고, 무기록이면 형제 정리(runtime.php)와 규율이 갈린다.
    //
    // `finally` 인 이유: 이 블록의 성공 흐름 한복판에 두면 그 앞의 조기 `return`(runtime 부재)과
    // 공유 함수의 실패 반환(`.env.example` 부재·`.env` 쓰기 실패), `catch` 가 전부 정리를 건너뛴다.
    // 「전 경로에서 정리한다」는 규율은 그 형태로는 성립하지 않는다.
    $probeCleanup = installerCleanupExposureProbe();
    if (! $probeCleanup['swept']) {
        addLog(lang('log_finalize_env_probe_sweep_unwritable', [
            'reason' => (string) ($probeCleanup['reason'] ?? 'unknown'),
        ]));
    } elseif ($probeCleanup['failed'] > 0) {
        addLog(lang('log_finalize_env_probe_sweep_partial', [
            'failed' => (string) $probeCleanup['failed'],
            'removed' => (string) $probeCleanup['removed'],
        ]));
    } else {
        addLog(lang('log_finalize_env_probe_sweep_done', ['removed' => (string) $probeCleanup['removed']]));
    }

    // 병합 직렬화 잠금을 푼다 — 잠금 파일도 치운다(남기면 설치를 마친 사이트의 storage/installer 에
    // 영구 잔존한다). 뒤 요청이 같은 파일을 기다리고 있었다면 그 요청은 runtime.php 부재로 바로 끝난다.
    if ($finalizeLock !== false) {
        @flock($finalizeLock, LOCK_UN);
        @fclose($finalizeLock);
        @unlink($finalizeLockPath);
    }
}
