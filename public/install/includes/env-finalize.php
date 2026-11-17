<?php

/**
 * 그누보드7 인스톨러 - .env 확정 (웹·터미널 공유)
 *
 * 설치 작업이 끝난 뒤 runtime.php 의 동적 설정(DB 자격증명·APP_KEY)을 `.env` 에 머지하고
 * runtime.php·state.json 을 정리한다. 웹은 완료 화면을 띄운 뒤 `finalize-env.php` 를 비동기로 부르고,
 * 터미널 설치는 같은 프로세스에서 이 함수를 바로 부른다.
 *
 * 멱등: runtime.php 가 없으면 이미 확정된 것으로 보고 아무것도 하지 않는다.
 * 실패 시 runtime.php 를 보존한다 → InstallerRuntimeServiceProvider 가 계속 설정을 주입해 앱은 동작한다.
 *
 * 로그 문구는 다국어 키(`log_finalize_env_*`)다 — 호출자가 번역 테이블(`$translations`)을 먼저 싣는다
 * (웹 `finalize-env.php` 는 진입 직후, 터미널 `g7_install_cli_main()` 은 입력 수집 직후).
 *
 * 의존: config.php · functions.php(lang) · installer-runtime.php(backupExistingEnvFile·installerWroteFully·
 * 설정 캐시 헬퍼, execution-mode.php 를 싣는다) · installer-state.php(STATE_PATH·INSTALLER_STATE_LOCK_SUFFIX) ·
 * installer-ownership.php. binary-path-policy.php 는 있으면 쓴다(없으면 PHP 경로는 php).
 */
if (! function_exists('installer_finalize_rebuild_config_cache')) {
    /**
     * 확정한 .env 로 설정 캐시를 새 artisan 프로세스로 다시 만든다 — 만들지 못하면 캐시를 지운다.
     *
     * 본체는 installerRebuildConfigCache() 다. 시험이 artisan 대신 기록 함수로 바꿔 끼우는 자리로 둔다.
     *
     * @param  string  $phpBinary  artisan 을 띄울 PHP 실행 경로
     * @return array{rebuilt: bool, exit_code: int|null, output: string} 다시 만들었는지, 종료 코드, 명령 출력
     */
    function installer_finalize_rebuild_config_cache(string $phpBinary): array
    {
        return installerRebuildConfigCache($phpBinary);
    }
}

if (! function_exists('installer_finalize_php_binary')) {
    /**
     * 설정 캐시를 다시 만들 PHP 실행 경로를 상태에서 고른다.
     *
     * 후보 순서는 설치 워커의 getPhpBinary() 와 같다 — 운영자 명시(config.php_binary) > 설치 시작 시
     * 자동 탐색된 경로(php_binary_resolved) > php. 경로 정책(binary-path-policy.php)이 실리지 않은
     * 진입점에서는 php 로 둔다.
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @return string PHP 실행 경로
     */
    function installer_finalize_php_binary(array $state): string
    {
        if (! function_exists('installer_php_binary_from_state')) {
            return 'php';
        }

        $phpBinary = installer_php_binary_from_state($state);
        if ($phpBinary === 'php' && (string) ($state['php_binary_resolved'] ?? '') !== '') {
            $phpBinary = installer_php_binary_from_state([
                'config' => ['php_binary' => (string) $state['php_binary_resolved']],
            ]);
        }

        return $phpBinary;
    }
}

if (! function_exists('tryDeleteInstallerRuntime')) {
    /**
     * runtime.php 삭제를 한 번 시도한다.
     *
     * @return bool 지웠거나 이미 없으면 true
     */
    function tryDeleteInstallerRuntime(): bool
    {
        return deleteInstallerRuntime();
    }
}

if (! function_exists('neutralizeInstallerRuntime')) {
    /**
     * runtime.php 의 내용을 빈 배열로 덮어 비밀값(DB 계정·APP_KEY)을 없앤다.
     *
     * 파일을 지우지 못할 때의 차선책이다 — 파일은 남지만 담긴 것이 없어 앱 부팅에 영향을 주지 않는다.
     *
     * @return bool 덮어쓰면 true
     */
    function neutralizeInstallerRuntime(): bool
    {
        return @file_put_contents(INSTALLER_RUNTIME_PATH, "<?php\n\nreturn [];\n", LOCK_EX) !== false;
    }
}

if (! function_exists('cleanupInstallerRuntime')) {
    /**
     * 설치가 끝난 뒤 runtime.php 를 정리한다 — 지우고, 안 되면 한 번 더 시도하고, 그래도 안 되면 내용을 비운다.
     *
     * 지우지 못한 채 남으면 그 파일이 `.env` 대신 쓰여, 서버를 옮기며 `.env` 를 고쳐도 반영되지 않는다.
     *
     * @return string 'deleted'(지움) · 'neutralized'(못 지워 내용을 비움) · 'failed'(지우지도 비우지도 못함)
     */
    function cleanupInstallerRuntime(): string
    {
        if (tryDeleteInstallerRuntime()) {
            return 'deleted';
        }

        // 바이러스 검사·색인 등이 잠깐 쥐고 있는 경우가 있어 한 번 더 시도한다
        usleep(200000);
        clearstatcache(true, INSTALLER_RUNTIME_PATH);
        if (tryDeleteInstallerRuntime()) {
            return 'deleted';
        }

        return neutralizeInstallerRuntime() ? 'neutralized' : 'failed';
    }
}

if (! function_exists('installer_finalize_env')) {
    /**
     * runtime.php → .env 머지와 인스톨러 부속 파일 정리.
     *
     * @return array{success: bool, already: bool, messages: array<int, string>} 결과와 기록한 로그 문구
     */
    function installer_finalize_env(): array
    {
        $messages = [];
        $log = static function (string $message) use (&$messages): void {
            $messages[] = $message;
            addLog($message);
        };

        try {
            $runtime = readInstallerRuntime();

            // 멱등성: runtime.php 부재 → 이미 finalize 된 상태
            if ($runtime === null) {
                return ['success' => true, 'already' => true, 'messages' => $messages];
            }

            // 설정 캐시는 웹·터미널이 같은 방식으로 다룬다 — 병합 전에 지우고, 병합·runtime.php 삭제 뒤 늘 새로 만들고,
            // 만들지 못하면 캐시 없이 둔다(다음 요청은 병합된 .env 로 부팅). 설치 워커·config_cache 작업이 만든 캐시는
            // 병합 전 .env(설치 미완료 표식)와 runtime.php 주입 값을 담는다. 먼저 지워 두면 .env 쓰기 직후 프로세스가
            // 끊겨도(php artisan serve 는 .env 변경을 보고 서버를 다시 띄운다) 병합 전 값의 캐시가 남지 않는다.
            // PHP 경로는 상태 파일에 있으므로 상태를 지우기 전에 읽는다.
            $phpBinary = installer_finalize_php_binary(getInstallationState());
            if (! installerClearConfigCache()) {
                $log(lang('log_finalize_env_config_cache_delete_failed', [
                    'path' => installerConfigCachePath(),
                    'error' => (error_get_last()['message'] ?? 'unknown'),
                ]));
            }

            $envPath = BASE_PATH.'/.env';
            $envBase = generateEnvContent();

            if ($envBase === null) {
                $log(lang('log_finalize_env_generate_failed'));

                return ['success' => false, 'already' => false, 'messages' => $messages];
            }

            $envContent = mergeRuntimeIntoEnv($envBase, $runtime);

            // 쓰기 직전 원본 보존 (13차 #D8).
            //
            // `.env` 는 `.env.example` 템플릿에서 새로 만들어져 **통째로 대체**된다 — 운영자가 설치
            // 전에 넣어 둔 값(메일·큐·레디스·서드파티 키)은 설치가 **성공해도** 사라지고 되돌릴 방법이
            // 없다. 쓰기는 부모 디렉토리 쓰기 권한을 요구하지 않으려고 rename 이 아닌 제자리 쓰기라
            // (아래 주석), 보존은 이 사본으로만 가능하다.
            //
            // 보존 실패는 설치 실패가 아니다 — 헬퍼가 null 을 돌려주면 기록만 남기고 그대로 진행한다.
            $envBackupPath = backupExistingEnvFile($envPath);

            if ($envBackupPath !== null) {
                $log(lang('log_finalize_env_backup_kept', ['path' => $envBackupPath]));
            }

            // .env 단일 쓰기 — file_put_contents 는 close 시점에 mtime 1회만 갱신하므로 artisan serve 의
            // mtime 워처가 다중 재시작을 일으키지 않는다. 기존 inode 권한을 바꾸지 않는다(chmod 시도 안 함 — #371).
            //
            // 부분 쓰기(디스크 full·quota)는 `false` 가 아니라 **쓴 바이트 수**를 돌려준다. 그것을
            // 성공으로 통과시키면 잘린 `.env` 가 「머지 완료」로 보고되고, 바로 아래에서 유일한
            // 비밀 사본(`runtime.php`)이 삭제되어 `APP_KEY`·DB 자격증명을 되찾을 수 없다.
            // 제자리 쓰기라 원본이 그 자리에서 잘린다. 형제 4지점(`exposure-check`·`installer-state`·
            // `AbstractRootMarkerFileUpdater`·`CoreUpdateService`)이 이미 이 축을 막는다.
            if (! installerWroteFully(@file_put_contents($envPath, $envContent, LOCK_EX), $envContent)) {
                $lastError = error_get_last();
                $log(lang('log_finalize_env_write_failed', [
                    'path' => $envPath,
                    'error' => $lastError['message'] ?? 'unknown',
                ]));

                return ['success' => false, 'already' => false, 'messages' => $messages];
            }

            // 머지 후 권한·소유자·그룹 상태를 명시 기록 — 운영자 사후 검증용
            clearstatcache(true, $envPath);
            $envPermsAfter = fileperms($envPath) & 0777;
            $envOwnerAfter = @fileowner($envPath);
            $envGroupAfter = @filegroup($envPath);

            $currentEuid = function_exists('posix_geteuid') ? posix_geteuid() : null;
            $currentEuser = ($currentEuid !== null && function_exists('posix_getpwuid'))
                ? (posix_getpwuid($currentEuid)['name'] ?? (string) $currentEuid)
                : 'unknown';

            $log(lang('log_finalize_env_merged', [
                'path' => $envPath,
                'perms' => decoct($envPermsAfter),
                'owner' => $envOwnerAfter === false ? 'unknown' : (string) $envOwnerAfter,
                'group' => $envGroupAfter === false ? 'unknown' : (string) $envGroupAfter,
                'euser' => $currentEuser,
            ]));

            // runtime.php 정리 — 지우고, 안 되면 한 번 더, 그래도 안 되면 내용을 비운다.
            // 남은 채로 두면 그 파일이 `.env` 대신 쓰여 서버를 옮기며 `.env` 를 고쳐도 반영되지 않는다.
            $runtimeCleanup = cleanupInstallerRuntime();
            if ($runtimeCleanup === 'deleted') {
                $log(lang('log_finalize_env_runtime_removed'));
            } elseif ($runtimeCleanup === 'neutralized') {
                $log(sprintf(
                    '[finalize-env] runtime.php 를 지우지 못해 내용을 비움: path=%s, last_error=%s. 파일을 직접 지워 주세요.',
                    INSTALLER_RUNTIME_PATH,
                    (error_get_last()['message'] ?? 'unknown'),
                ));
            } else {
                // 지우지도 비우지도 못함 — 평문 자격증명이 남는다
                $log(lang('log_finalize_env_runtime_delete_failed', [
                    'path' => INSTALLER_RUNTIME_PATH,
                    'error' => (error_get_last()['message'] ?? 'unknown'),
                ]));
            }

            // 다시 만들기 직전에 한 번 더 지운다 (제품 결정 13차 D7/E2) — 병합 전 삭제와 이 지점 사이에 남이 다시
            // 구웠을 수 있다(워커 대기 상한 초과 등). 그 캐시는 runtime.php 주입 값(평문 DB 비밀번호)과
            // `app.installer_completed=false` 를 담는다. 같은 이름의 디렉토리처럼 지울 수 없는 것이 그 자리에
            // 있으면 운영자가 직접 치우도록 기록한다. 삭제 실패는 설치를 중단시키지 않는다.
            $configCachePath = installerConfigCachePath();

            if (file_exists($configCachePath)) {
                if (@unlink($configCachePath) === false) {
                    $log(lang('log_finalize_env_config_cache_delete_failed', [
                        'path' => $configCachePath,
                        'error' => (error_get_last()['message'] ?? 'unknown'),
                    ]));
                } else {
                    $log(lang('log_finalize_env_config_cache_removed'));
                }
            }

            // 설정 캐시를 병합된 .env 로 다시 만든다(설치를 설정 캐시가 켜진 상태로 끝낸다).
            // runtime.php 를 지운 뒤에 만든다 — 남아 있으면 Provider 가 그 값을 캐시에 다시 섞는다.
            // 굽지 못하면(무CLI 환경처럼 `exec` 가 막혔거나 artisan 실패) 캐시를 지운 채 둔다.
            $rebuild = installer_finalize_rebuild_config_cache($phpBinary);
            $log($rebuild['rebuilt']
                ? lang('log_finalize_env_config_cache_rebuilt')
                : lang('log_finalize_env_config_cache_rebuild_failed', [
                    'exit' => $rebuild['exit_code'] === null ? 'n/a' : (string) $rebuild['exit_code'],
                    // mbstring 이 없는 호스팅도 있다 — 없으면 바이트 단위로 자른다.
                    'output' => function_exists('mb_substr')
                        ? mb_substr($rebuild['output'], 0, 500)
                        : substr($rebuild['output'], 0, 500),
                ]));

            // state.json 삭제 — 남는 모든 경로에서는 비밀 필드를 제거해 재저장한다 (이슈 #465).
            // .env 머지가 이미 성공한 뒤라 state.config 가 더는 필요하지 않다.
            // 경로 리터럴을 두 곳에 두면 STATE_PATH 를 먼저 정의하는 진입점(테스트 하네스)이나
            // 배치가 달라졌을 때 finalize 만 조용히 다른 파일을 지운다 — session.php 가 10차에
            // 같은 이유로 리터럴을 걷어냈다 (11차 #A11).
            $stateFilePath = STATE_PATH;
            $stateDeleted = false;

            if (defined('DELETE_INSTALLER_AFTER_COMPLETE') && DELETE_INSTALLER_AFTER_COMPLETE) {
                if (is_file($stateFilePath)) {
                    if (@unlink($stateFilePath) === false) {
                        $log(lang('log_finalize_env_state_delete_failed', [
                            'path' => $stateFilePath,
                            'error' => (error_get_last()['message'] ?? 'unknown'),
                        ]));
                    } else {
                        $stateDeleted = true;
                        $log(lang('log_finalize_env_state_removed'));
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
                    $log(lang('log_finalize_env_state_redacted'));
                } else {
                    $log(lang('log_finalize_env_state_redact_failed', ['path' => $stateFilePath]));
                }
            }

            // 설치 소유권 부속 파일 정리 — 복구 토큰 실패 집계. 상태 쓰기 직렬화 잠금 파일은 위 상태 파일
            // 삭제 구간이 생산자 상수(INSTALLER_STATE_LOCK_SUFFIX)로 함께 치운다.
            installer_reclaim_reset_throttle();

            return [
                'success' => true,
                'already' => false,
                'messages' => $messages,
                // runtime.php 정리 결과 — 'deleted' 가 아니면 파일이 남아 있다
                'runtime_cleanup' => $runtimeCleanup,
                'runtime_cleanup_failed' => $runtimeCleanup !== 'deleted',
            ];
        } catch (Throwable $e) {
            // 예외 시 runtime.php 보존 → Provider 가 계속 config 주입 → 앱 정상 동작.
            $log(lang('log_finalize_env_unexpected_exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => (string) $e->getLine(),
            ]));

            return ['success' => false, 'already' => false, 'messages' => $messages];
        }
    }
}
