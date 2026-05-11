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
 * @package G7\Installer
 * @see https://github.com/gnuboard/g7/issues/23
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/installer-runtime.php';
require_once __DIR__ . '/../includes/installer-state.php';
require_once __DIR__ . '/_guard.php';
installer_guard_or_410();

// ---------------------------------------------------------------------------
// 1. 응답을 즉시 송출하여 브라우저가 완료 UI 를 유지할 수 있게 한다.
// ---------------------------------------------------------------------------

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ignore_user_abort(true);

$accepted = json_encode(['accepted' => true]);

header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . strlen($accepted));
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

try {
    $runtime = readInstallerRuntime();

    // 멱등성: runtime.php 부재 → 이미 finalize 된 상태
    if ($runtime === null) {
        return;
    }

    $envPath = BASE_PATH . '/.env';
    $envBase = generateEnvContent();

    if ($envBase === null) {
        error_log('[finalize-env] generateEnvContent() returned null — .env.example missing');
        return;
    }

    // 머지 로직은 mergeRuntimeIntoEnv() 헬퍼로 분리 — 단위 테스트 가능
    $envContent = mergeRuntimeIntoEnv($envBase, $runtime);

    // .env 단일 쓰기 — file_put_contents 는 close 시점에 mtime 1회만 갱신하므로
    // ServeCommand 의 mtime watcher 가 다중 재시작을 일으키지 않는다.
    // atomic rename 대신 file_put_contents 를 사용하여 부모 디렉토리(프로젝트 루트)
    // 쓰기 권한 요구를 회피 — 기존 인스톨러 권한 안내 (chmod 664 .env + chgrp) 만으로 충분.
    if (@file_put_contents($envPath, $envContent, LOCK_EX) === false) {
        error_log('[finalize-env] failed to write .env');
        return;
    }

    @chmod($envPath, 0600);

    // runtime.php 삭제 — Provider 가 다음 부팅부터 no-op
    deleteInstallerRuntime();

    // state.json 삭제 — setInstallationCompleteSSE 가 본 단계로 위임함
    // (finalize 가 generateEnvContent() 호출 시 state.config 가 필요했기 때문)
    if (defined('DELETE_INSTALLER_AFTER_COMPLETE') && DELETE_INSTALLER_AFTER_COMPLETE) {
        $stateFilePath = BASE_PATH . '/storage/installer-state.json';
        if (is_file($stateFilePath)) {
            @unlink($stateFilePath);
        }
    }
} catch (\Throwable $e) {
    // 예외 시 runtime.php 보존 → Provider 가 계속 config 주입 → 앱 정상 동작.
    error_log('[finalize-env] unexpected exception: ' . $e->getMessage());
}
