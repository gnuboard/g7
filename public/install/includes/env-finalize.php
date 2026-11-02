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
 * 의존: config.php · functions.php · installer-runtime.php · installer-state.php · installer-ownership.php.
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
            $phpBinary = function_exists('installer_php_binary_from_state')
                ? installer_php_binary_from_state(getInstallationState())
                : 'php';
            if (! installerClearConfigCache()) {
                $log('[finalize-env] 설정 캐시 삭제 FAILED: path='.installerConfigCachePath());
            }

            $envPath = BASE_PATH.'/.env';
            $envBase = generateEnvContent();

            if ($envBase === null) {
                $log('[finalize-env] generateEnvContent() returned null — .env.example missing');

                return ['success' => false, 'already' => false, 'messages' => $messages];
            }

            $envContent = mergeRuntimeIntoEnv($envBase, $runtime);

            // .env 단일 쓰기 — file_put_contents 는 close 시점에 mtime 1회만 갱신하므로 artisan serve 의
            // mtime 워처가 다중 재시작을 일으키지 않는다. 기존 inode 권한을 바꾸지 않는다(chmod 시도 안 함 — #371).
            if (@file_put_contents($envPath, $envContent, LOCK_EX) === false) {
                $lastError = error_get_last();
                $log(sprintf(
                    '[finalize-env] file_put_contents FAILED: path=%s, last_error=%s',
                    $envPath,
                    $lastError['message'] ?? 'unknown',
                ));

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

            $log(sprintf(
                '[finalize-env] .env 머지 완료 (chmod 시도 안 함, 기존 권한 보존): path=%s, perms=%s, owner_uid=%s, group_gid=%s, process_euser=%s',
                $envPath,
                decoct($envPermsAfter),
                $envOwnerAfter === false ? 'unknown' : (string) $envOwnerAfter,
                $envGroupAfter === false ? 'unknown' : (string) $envGroupAfter,
                $currentEuser,
            ));

            // runtime.php 삭제 — Provider 가 다음 부팅부터 no-op
            if (! deleteInstallerRuntime()) {
                $log(sprintf(
                    '[finalize-env] deleteInstallerRuntime FAILED: path=%s, last_error=%s. '
                    .'runtime.php 잔존 — InstallerRuntimeServiceProvider 폴백 동작 유지되나 평문 자격증명 보안 우려.',
                    INSTALLER_RUNTIME_PATH,
                    (error_get_last()['message'] ?? 'unknown'),
                ));
            } else {
                $log('[finalize-env] runtime.php 삭제 완료');
            }

            // 설정 캐시를 병합된 .env 로 다시 만든다(설치를 설정 캐시가 켜진 상태로 끝낸다).
            // runtime.php 를 지운 뒤에 만든다 — 남아 있으면 Provider 가 그 값을 캐시에 다시 섞는다.
            $rebuild = installer_finalize_rebuild_config_cache($phpBinary);
            $log($rebuild['rebuilt']
                ? '[finalize-env] 설정 캐시 다시 생성 완료'
                : sprintf(
                    '[finalize-env] 설정 캐시 다시 생성 실패 — 캐시 없이 병합된 .env 로 부팅한다: exit=%s, output=%s',
                    $rebuild['exit_code'] === null ? 'n/a' : (string) $rebuild['exit_code'],
                    mb_substr($rebuild['output'], 0, 500),
                ));

            // state.json 삭제 — 남는 모든 경로에서는 비밀 필드를 제거해 재저장한다 (이슈 #465).
            // .env 머지가 이미 성공한 뒤라 state.config 가 더는 필요하지 않다.
            $stateFilePath = BASE_PATH.'/storage/installer-state.json';
            $stateDeleted = false;

            if (defined('DELETE_INSTALLER_AFTER_COMPLETE') && DELETE_INSTALLER_AFTER_COMPLETE) {
                if (is_file($stateFilePath)) {
                    if (@unlink($stateFilePath) === false) {
                        $log(sprintf(
                            '[finalize-env] state.json unlink FAILED: path=%s, last_error=%s',
                            $stateFilePath,
                            (error_get_last()['message'] ?? 'unknown'),
                        ));
                    } else {
                        $stateDeleted = true;
                        $log('[finalize-env] state.json 삭제 완료');
                    }
                } else {
                    $stateDeleted = true;
                }
            }

            if (! $stateDeleted && is_file($stateFilePath)) {
                if (saveInstallationState(redactInstallationStateSecrets(getInstallationState()))) {
                    $log('[finalize-env] state.json 잔존 — 비밀 필드 redact 완료');
                } else {
                    $log('[finalize-env] state.json redact 재저장 FAILED — 수동 삭제 권장: '.$stateFilePath);
                }
            }

            // 설치 소유권 부속 파일 정리 — state 쓰기 직렬화 락, 복구 토큰 실패 집계
            if ($stateDeleted) {
                @unlink($stateFilePath.'.lock');
            }
            installer_reclaim_reset_throttle();

            return ['success' => true, 'already' => false, 'messages' => $messages];
        } catch (Throwable $e) {
            // 예외 시 runtime.php 보존 → Provider 가 계속 config 주입 → 앱 정상 동작.
            $log('[finalize-env] unexpected exception: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());

            return ['success' => false, 'already' => false, 'messages' => $messages];
        }
    }
}
