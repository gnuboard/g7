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

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/binary-path-policy.php';
require_once __DIR__.'/../includes/installer-runtime.php';
require_once __DIR__.'/../includes/installer-state.php';
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

// 설치 완료(complete_flag 가 만든 g7_installed) 이후에만 머지한다. 진행 중에 호출되면
// .env 에 INSTALLER_COMPLETED=true 가 기록돼 모든 인스톨러 엔드포인트가 410 이 되고 설치가 봉쇄된다.
if (! is_file(BASE_PATH.'/storage/app/g7_installed')) {
    installer_json_error(409, 'installation_not_completed', installer_ownership_lang(
        'error_finalize_not_ready',
        'Installation has not completed yet.'
    ));
}

// state 가 남아 있으면(아직 finalize 전) 설치 소유 세션만 머지를 요청할 수 있다.
$finalizeState = installerReadStateFile();
if ($finalizeState !== null && installer_state_is_claimed($finalizeState)) {
    installer_require_owner($finalizeState);
    installer_require_csrf();
}

// ---------------------------------------------------------------------------
// 1. 응답을 즉시 송출하여 브라우저가 완료 UI 를 유지할 수 있게 한다.
// ---------------------------------------------------------------------------

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ignore_user_abort(true);

$accepted = installer_json_encode(['accepted' => true]);

header('Content-Type: application/json; charset=utf-8');
header('Content-Length: '.strlen($accepted));
header('Connection: close');

echo $accepted;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // cli-server (artisan serve) / Apache mod_php 폴백
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

// ---------------------------------------------------------------------------
// 2. 응답 송출 후 .env 머지
// ---------------------------------------------------------------------------

// 응답 뒤 단계는 워커 대기와 설정 캐시 다시 만들기(artisan)를 포함한다 — 실행 시간을 그만큼 확보한다.
@set_time_limit((int) INSTALLER_FINALIZE_WORKER_WAIT_SECONDS + (int) INSTALLER_TASK_TIME_LIMIT);

// 2-1. 설치 워커가 마지막 작업(설정 캐시·라우트 캐시·정적 게시)을 끝내고 lock 을 풀 때까지 기다린다.
// 워커의 config:cache 도중에 병합하면 병합 전 .env 값이 설정 캐시에 굳고(사이트 503), 상태 파일이
// 지워진 워커는 lock 을 잃어 남은 작업을 건너뛴다.
$stateBeforeWait = installerReadStateFile();
if ($stateBeforeWait !== null && installerHasActiveWorker($stateBeforeWait)) {
    addLog('[finalize-env] 설치 워커 종료를 기다립니다 (설정 캐시 등 마지막 작업 진행 중)');

    $workerWait = installerWaitForWorkerRelease((int) INSTALLER_FINALIZE_WORKER_WAIT_SECONDS);
    if ($workerWait['released']) {
        addLog(sprintf('[finalize-env] 설치 워커 잠금 해제 확인 (%.1f초 대기)', $workerWait['waited_seconds']));
    } else {
        addLog(sprintf(
            '[finalize-env] 설치 워커 잠금이 %d초 안에 풀리지 않아 병합을 진행합니다 (워커가 강제 종료됐을 수 있음)',
            (int) INSTALLER_FINALIZE_WORKER_WAIT_SECONDS,
        ));
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
    installer_finalize_env();
} catch (Throwable $e) {
    // 예외 시 runtime.php 보존 → Provider 가 계속 config 주입 → 앱 정상 동작.
    addLog('[finalize-env] unexpected exception: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
} finally {
    if ($finalizeLock !== false) {
        @flock($finalizeLock, LOCK_UN);
        @fclose($finalizeLock);
    }
}
