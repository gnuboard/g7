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
 * @see https://github.com/gnuboard/g7/issues/23
 */

declare(strict_types=1);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/functions.php';
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

// 본문은 웹·터미널 공유 함수(includes/env-finalize.php) — 결과 문구는 installation.log 에 남는다.
installer_finalize_env();
