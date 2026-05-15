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
// finalize 전용 가드 — `.env` 의 INSTALLER_COMPLETED=true 단독으로만 차단한다.
// 일반 인스톨러 엔드포인트의 `installer_guard_or_410()` 은 `g7_installed` 락 파일도
// 차단 사유로 삼는데, 그 락 파일은 finalize 호출 직전 단계의 complete_flag task 가
// 먼저 생성하므로 자가 차단 회귀가 발생한다 (이슈 #371).
installer_guard_finalize_or_410();

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
        addLog('[finalize-env] generateEnvContent() returned null — .env.example missing');
        return;
    }

    // 머지 로직은 mergeRuntimeIntoEnv() 헬퍼로 분리 — 단위 테스트 가능
    $envContent = mergeRuntimeIntoEnv($envBase, $runtime);

    // .env 단일 쓰기 — file_put_contents 는 close 시점에 mtime 1회만 갱신하므로
    // ServeCommand 의 mtime watcher 가 다중 재시작을 일으키지 않는다.
    // atomic rename 대신 file_put_contents 를 사용하여 부모 디렉토리(프로젝트 루트)
    // 쓰기 권한 요구를 회피 — 기존 인스톨러 권한 안내 (chmod 664 .env + chgrp) 만으로 충분.
    //
    // 권한 정책 (#371): finalize-env.php 는 chmod 0600 시도를 수행하지 않는다.
    // PHP-FPM(www-data) 가 .env 소유자(예: jjh) 와 다른 자체 구축 환경에서는 POSIX 상
    // chmod 가 거부되며, 또한 인스톨러가 임의로 0600 으로 깎으면 운영자가 의도해서
    // 설정한 0664/0640 권한이 손상된다. file_put_contents 는 기존 inode 의 권한을
    // 변경하지 않으므로, 인스톨러 안내 단계의 권한 (chgrp www-data + chmod 664) 이
    // 그대로 유지된다. 추가 보안 강화는 Step 6 완료 화면 안내 + INSTALL.md 운영
    // 가이드에 위임.
    if (@file_put_contents($envPath, $envContent, LOCK_EX) === false) {
        $lastError = error_get_last();
        addLog(sprintf(
            '[finalize-env] file_put_contents FAILED: path=%s, last_error=%s',
            $envPath,
            $lastError['message'] ?? 'unknown',
        ));
        return;
    }

    // 머지 후 권한·소유자·그룹 상태를 명시 기록 — 운영자 사후 검증용
    $envPermsAfter = fileperms($envPath) & 0777;
    $envOwnerAfter = @fileowner($envPath);
    $envGroupAfter = @filegroup($envPath);

    $currentEuid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $currentEuser = ($currentEuid !== null && function_exists('posix_getpwuid'))
        ? (posix_getpwuid($currentEuid)['name'] ?? (string) $currentEuid)
        : 'unknown';

    addLog(sprintf(
        '[finalize-env] .env 머지 완료 (chmod 시도 안 함, 기존 권한 보존): path=%s, perms=%s, owner_uid=%s, group_gid=%s, process_euser=%s',
        $envPath,
        decoct($envPermsAfter),
        $envOwnerAfter === false ? 'unknown' : (string) $envOwnerAfter,
        $envGroupAfter === false ? 'unknown' : (string) $envGroupAfter,
        $currentEuser,
    ));

    // runtime.php 삭제 — Provider 가 다음 부팅부터 no-op
    if (! deleteInstallerRuntime()) {
        addLog(sprintf(
            '[finalize-env] deleteInstallerRuntime FAILED: path=%s, last_error=%s. '
            .'runtime.php 잔존 — InstallerRuntimeServiceProvider 폴백 동작 유지되나 평문 자격증명 보안 우려.',
            INSTALLER_RUNTIME_PATH,
            (error_get_last()['message'] ?? 'unknown'),
        ));
    } else {
        addLog('[finalize-env] runtime.php 삭제 완료');
    }

    // state.json 삭제 — setInstallationCompleteSSE 가 본 단계로 위임함
    // (finalize 가 generateEnvContent() 호출 시 state.config 가 필요했기 때문)
    if (defined('DELETE_INSTALLER_AFTER_COMPLETE') && DELETE_INSTALLER_AFTER_COMPLETE) {
        $stateFilePath = BASE_PATH . '/storage/installer-state.json';
        if (is_file($stateFilePath)) {
            if (@unlink($stateFilePath) === false) {
                addLog(sprintf(
                    '[finalize-env] state.json unlink FAILED: path=%s, last_error=%s',
                    $stateFilePath,
                    (error_get_last()['message'] ?? 'unknown'),
                ));
            } else {
                addLog('[finalize-env] state.json 삭제 완료');
            }
        }
    }
} catch (\Throwable $e) {
    // 예외 시 runtime.php 보존 → Provider 가 계속 config 주입 → 앱 정상 동작.
    addLog('[finalize-env] unexpected exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
