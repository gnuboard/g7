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
    $runtime = readInstallerRuntime();

    // 멱등성: runtime.php 부재 → 이미 finalize 된 상태
    if ($runtime === null) {
        return;
    }

    $envPath = BASE_PATH.'/.env';
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
    //
    // 설치 워커가 병합 전 .env(와 runtime.php 주입 값)로 만든 설정 캐시를 쓰기 전에 지운다 — 쓰기 직후 이
    // 프로세스가 끊겨도(php artisan serve 는 .env 변경을 보고 서버를 다시 띄운다) 병합 전 값의 캐시가
    // 남지 않는다. 캐시는 아래 runtime.php 삭제 뒤 다시 만든다.
    if (! installerClearConfigCache()) {
        addLog('[finalize-env] 설정 캐시 삭제 FAILED: path='.installerConfigCachePath());
    }

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

    // 설정 캐시를 병합된 .env 로 다시 만든다 (설치를 config 캐시 켜진 상태로 시작). 실패하면 캐시 없이 둔다 —
    // 다음 요청이 병합된 .env 로 부팅한다. PHP 경로는 상태 파일에 있으므로 상태 삭제 전에 읽는다.
    $configCacheRebuild = installerRebuildConfigCache(installer_php_binary_from_state(getInstallationState()));
    if ($configCacheRebuild['rebuilt']) {
        addLog('[finalize-env] 설정 캐시 다시 생성 완료');
    } else {
        addLog(sprintf(
            '[finalize-env] 설정 캐시 다시 생성 실패 — 캐시 없이 병합된 .env 로 부팅한다: exit=%s, output=%s',
            $configCacheRebuild['exit_code'] === null ? 'n/a' : (string) $configCacheRebuild['exit_code'],
            mb_substr($configCacheRebuild['output'], 0, 500),
        ));
    }

    // state.json 삭제 — setInstallationCompleteSSE 가 본 단계로 위임함
    // (finalize 가 generateEnvContent() 호출 시 state.config 가 필요했기 때문)
    //
    // 삭제 실패 또는 DELETE_INSTALLER_AFTER_COMPLETE=false 로 파일이 남는 모든 경로에서는
    // 비밀 필드를 제거한 뒤 재저장한다 (이슈 #465). .env 머지가 이미 성공한 뒤이므로
    // state.config 가 더 이상 필요하지 않아 순서상 안전하다.
    $stateFilePath = BASE_PATH.'/storage/installer-state.json';
    $stateDeleted = false;

    if (defined('DELETE_INSTALLER_AFTER_COMPLETE') && DELETE_INSTALLER_AFTER_COMPLETE) {
        if (is_file($stateFilePath)) {
            if (@unlink($stateFilePath) === false) {
                addLog(sprintf(
                    '[finalize-env] state.json unlink FAILED: path=%s, last_error=%s',
                    $stateFilePath,
                    (error_get_last()['message'] ?? 'unknown'),
                ));
            } else {
                $stateDeleted = true;
                addLog('[finalize-env] state.json 삭제 완료');
            }
        } else {
            $stateDeleted = true;
        }
    }

    if (! $stateDeleted && is_file($stateFilePath)) {
        if (saveInstallationState(redactInstallationStateSecrets(getInstallationState()))) {
            addLog('[finalize-env] state.json 잔존 — 비밀 필드 redact 완료');
        } else {
            addLog('[finalize-env] state.json redact 재저장 FAILED — 수동 삭제 권장: '.$stateFilePath);
        }
    }

    // 설치 소유권 부속 파일 정리 — state 쓰기 직렬화 락, 복구 토큰 실패 집계
    if ($stateDeleted) {
        @unlink($stateFilePath.'.lock');
    }
    installer_reclaim_reset_throttle();
} catch (Throwable $e) {
    // 예외 시 runtime.php 보존 → Provider 가 계속 config 주입 → 앱 정상 동작.
    addLog('[finalize-env] unexpected exception: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
} finally {
    if ($finalizeLock !== false) {
        @flock($finalizeLock, LOCK_UN);
        @fclose($finalizeLock);
    }
}
