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
    $runtime = readInstallerRuntime();

    // 멱등성: runtime.php 부재 → 이미 finalize 된 상태
    if ($runtime === null) {
        return;
    }

    $envPath = BASE_PATH.'/.env';
    $envBase = generateEnvContent();

    if ($envBase === null) {
        addLog(lang('log_finalize_env_generate_failed'));

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
    // 쓰기 직전 원본 보존 (13차 #D8).
    //
    // `.env` 는 `.env.example` 템플릿에서 새로 만들어져 **통째로 대체**된다 — 운영자가 설치
    // 전에 넣어 둔 값(메일·큐·레디스·서드파티 키)은 설치가 **성공해도** 사라지고 되돌릴 방법이
    // 없다. 쓰기는 부모 디렉토리 쓰기 권한을 요구하지 않으려고 rename 이 아닌 제자리 쓰기라
    // (바로 위 주석), 보존은 이 사본으로만 가능하다.
    //
    // 보존 실패는 설치 실패가 아니다 — 헬퍼가 null 을 돌려주면 기록만 남기고 그대로 진행한다.
    $envBackupPath = backupExistingEnvFile();

    if ($envBackupPath !== null) {
        addLog(lang('log_finalize_env_backup_kept', ['path' => $envBackupPath]));
    }

    // 설치 워커가 병합 전 .env(와 runtime.php 주입 값)로 만든 설정 캐시를 쓰기 전에 지운다 — 쓰기 직후 이
    // 프로세스가 끊겨도(php artisan serve 는 .env 변경을 보고 서버를 다시 띄운다) 병합 전 값의 캐시가
    // 남지 않는다. 캐시는 아래 runtime.php 삭제 뒤 다시 만든다.
    if (! installerClearConfigCache()) {
        addLog(lang('log_finalize_env_config_cache_delete_failed', [
            'path' => installerConfigCachePath(),
            'error' => (error_get_last()['message'] ?? 'unknown'),
        ]));
    }

    // 부분 쓰기(디스크 full·quota)는 `false` 가 아니라 **쓴 바이트 수**를 돌려준다. 그것을
    // 성공으로 통과시키면 잘린 `.env` 가 「머지 완료」로 보고되고, 바로 아래에서 유일한
    // 비밀 사본(`runtime.php`)이 삭제되어 `APP_KEY`·DB 자격증명을 되찾을 수 없다.
    // 쓰기는 부모 디렉토리 권한을 요구하지 않으려고 rename 이 아닌 제자리 쓰기라
    // 원본이 그 자리에서 잘린다. 형제 4지점(`exposure-check`·`installer-state`·
    // `AbstractRootMarkerFileUpdater`·`CoreUpdateService`)이 이미 이 축을 막는다.
    if (! installerWroteFully(@file_put_contents($envPath, $envContent, LOCK_EX), $envContent)) {
        $lastError = error_get_last();
        addLog(lang('log_finalize_env_write_failed', [
            'path' => $envPath,
            'error' => $lastError['message'] ?? 'unknown',
        ]));

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

    addLog(lang('log_finalize_env_merged', [
        'path' => $envPath,
        'perms' => decoct($envPermsAfter),
        'owner' => $envOwnerAfter === false ? 'unknown' : (string) $envOwnerAfter,
        'group' => $envGroupAfter === false ? 'unknown' : (string) $envGroupAfter,
        'euser' => $currentEuser,
    ]));

    // runtime.php 삭제 — Provider 가 다음 부팅부터 no-op
    if (! deleteInstallerRuntime()) {
        addLog(lang('log_finalize_env_runtime_delete_failed', [
            'path' => INSTALLER_RUNTIME_PATH,
            'error' => (error_get_last()['message'] ?? 'unknown'),
        ]));
    } else {
        addLog(lang('log_finalize_env_runtime_removed'));
    }

    // 설정 캐시 무효화와 다시 만들기 — 제품 결정(13차 D7/E2) + 설치 워커와의 경합 차단.
    //
    // 마지막 태스크 `config_cache` 는 `.env` 머지 **이전**에 돈다. 그 시점의 config 는
    // `InstallerRuntimeServiceProvider` 가 runtime.php 에서 주입한 값이고, Laravel 의
    // `config:cache` 는 전체 부팅(ServiceProvider boot 포함)을 거친 config 를 그대로
    // `var_export` 하므로 **DB 비밀번호가 평문으로 캐시 파일에 복제**된다. 덤으로 그
    // 캐시에는 `app.installer_completed=false` 가 박혀 모듈·플러그인 라우트 프로바이더가
    // 매 요청 조기 return 한다. 그런데 그 캐시를 갱신하는 지점이 어디에도 없었다.
    //
    // 선택은 「굽지 않는다」가 아니라 「끝날 때 지우고 병합된 값으로 다시 굽는다」다 — 태스크를
    // 목록에서 빼면 진행 화면·매니페스트의 계약이 함께 바뀐다. 다시 굽는 것은 설치가 config 캐시
    // 켜진 상태로 시작하게 하려는 것이고, 굽지 못하면(무CLI 환경처럼 `exec` 가 막혔거나 artisan
    // 실패) 캐시를 지운 채 둔다 — `config:clear` 의 실질이 이 파일 삭제이므로 다음 요청이 머지된
    // `.env` 로 부팅한다.
    //
    // 위치는 `.env` 쓰기 성공 **뒤**, runtime.php 를 지우는 이 정리 구간이다. 앞에 두면
    // 그 뒤의 부팅이 다시 캐시를 만들 여지가 있다. 쓰기 직전에도 한 번 지운다(위) — 이 사이에
    // 남이 다시 구웠을 수 있어(워커 대기 상한 초과 등) 여기서도 지운다.
    //
    // 삭제·다시 만들기 실패는 설치를 중단시키지 않는다 — 기록만 남긴다.
    $configCachePath = installerConfigCachePath();

    if (file_exists($configCachePath)) {
        if (@unlink($configCachePath) === false) {
            addLog(lang('log_finalize_env_config_cache_delete_failed', [
                'path' => $configCachePath,
                'error' => (error_get_last()['message'] ?? 'unknown'),
            ]));
        } else {
            addLog(lang('log_finalize_env_config_cache_removed'));
        }
    }

    // PHP 경로는 상태 파일에 있으므로 상태 삭제 전에 읽는다. 후보 순서는 워커의 getPhpBinary() 와
    // 같다 — 운영자 명시(config.php_binary) > 설치 시작 시 자동 탐색된 경로(php_binary_resolved) > php.
    $finalizePhpState = getInstallationState();
    $finalizePhpBinary = installer_php_binary_from_state($finalizePhpState);
    if ($finalizePhpBinary === 'php' && (string) ($finalizePhpState['php_binary_resolved'] ?? '') !== '') {
        $finalizePhpBinary = installer_php_binary_from_state([
            'config' => ['php_binary' => (string) $finalizePhpState['php_binary_resolved']],
        ]);
    }

    $configCacheRebuild = installerRebuildConfigCache($finalizePhpBinary);
    if ($configCacheRebuild['rebuilt']) {
        addLog(lang('log_finalize_env_config_cache_rebuilt'));
    } else {
        addLog(lang('log_finalize_env_config_cache_rebuild_failed', [
            'exit' => $configCacheRebuild['exit_code'] === null ? 'n/a' : (string) $configCacheRebuild['exit_code'],
            // mbstring 이 없는 호스팅도 있다 — 없으면 바이트 단위로 자른다.
            'output' => function_exists('mb_substr')
                ? mb_substr($configCacheRebuild['output'], 0, 500)
                : substr($configCacheRebuild['output'], 0, 500),
        ]));
    }

    // state.json 삭제 — setInstallationCompleteSSE 가 본 단계로 위임함
    // (finalize 가 generateEnvContent() 호출 시 state.config 가 필요했기 때문)
    //
    // 삭제 실패 또는 DELETE_INSTALLER_AFTER_COMPLETE=false 로 파일이 남는 모든 경로에서는
    // 비밀 필드를 제거한 뒤 재저장한다 (이슈 #465). .env 머지가 이미 성공한 뒤이므로
    // state.config 가 더 이상 필요하지 않아 순서상 안전하다.
    // 경로 리터럴을 두 곳에 두면 STATE_PATH 를 먼저 정의하는 진입점(테스트 하네스)이나
    // 배치가 달라졌을 때 finalize 만 조용히 다른 파일을 지운다 — session.php 가 10차에
    // 같은 이유로 리터럴을 걷어냈다 (11차 #A11).
    $stateFilePath = STATE_PATH;
    $stateDeleted = false;

    if (defined('DELETE_INSTALLER_AFTER_COMPLETE') && DELETE_INSTALLER_AFTER_COMPLETE) {
        if (is_file($stateFilePath)) {
            if (@unlink($stateFilePath) === false) {
                addLog(lang('log_finalize_env_state_delete_failed', [
                    'path' => $stateFilePath,
                    'error' => (error_get_last()['message'] ?? 'unknown'),
                ]));
            } else {
                $stateDeleted = true;
                addLog(lang('log_finalize_env_state_removed'));
            }
            // 워커 lock 판정용 파일(installerWithStateLock 의 flock 대상) — 상태 파일과 함께 치운다.
            // 이름은 생산자 상수다 — 리터럴이면 갈려도 아무것도 울리지 않는다 (15차 G3 W1-4).
            if (is_file($stateFilePath.INSTALLER_STATE_LOCK_SUFFIX)) {
                @unlink($stateFilePath.INSTALLER_STATE_LOCK_SUFFIX);
            }
        } else {
            $stateDeleted = true;
        }
    }

    if (! $stateDeleted && is_file($stateFilePath)) {
        if (saveInstallationState(redactInstallationStateSecrets(getInstallationState()))) {
            addLog(lang('log_finalize_env_state_redacted'));
        } else {
            addLog(lang('log_finalize_env_state_redact_failed', ['path' => $stateFilePath]));
        }
    }

    // 설치 소유권 부속 파일 정리 — 복구 토큰 실패 집계. 상태 쓰기 직렬화 잠금 파일은 위 상태 파일
    // 삭제 구간이 생산자 상수(INSTALLER_STATE_LOCK_SUFFIX)로 함께 치운다.
    installer_reclaim_reset_throttle();
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
    // `finally` 인 이유: 이 블록의 성공 흐름 한복판에 두면 그 앞의 조기 `return` 세 곳
    // (runtime 부재·`.env.example` 부재·`.env` 쓰기 실패)과 `catch` 가 전부 정리를 건너뛴다.
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
