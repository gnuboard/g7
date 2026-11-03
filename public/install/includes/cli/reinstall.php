<?php

/**
 * 그누보드7 터미널 설치 - 설치 초기화(재설치)
 *
 * 설치된 사이트를 같은 서버에서 다시 설치하기 전에 설치 표식·인스톨러 상태·부트스트랩 캐시·애플리케이션 파일 캐시를 지우고,
 * 옵션에 따라 vendor·활성 확장·설정·업로드를 지운다(삭제 대상 토글은 개발 대시보드의 초기화와 같다).
 * 데이터베이스 테이블은 여기서 지우지 않는다 — 이어지는 설치의 기존 테이블 정리(db_cleanup)가
 * 접두사 일치 테이블만, 서명된 설치 계획을 검증한 뒤 지운다.
 *
 * `--delete-settings` 를 주지 않으면 기존 APP_KEY 를 이어 쓴다 — 남긴 확장 설정의 암호화 값이 새 키로는
 * 읽히지 않아 암호문이 그대로 쓰이는 일을 막기 위해서다.
 */
if (! function_exists('g7_cli_remove_tree')) {
    /**
     * 경로를 지운다 — 디렉터리는 하위 전체.
     *
     * 디렉터리 자신을 남길 때만 그 바로 아래의 `.gitkeep`·`.gitignore` 를 보존한다(저장소 골격). 통째로 지우는
     * 폴더(활성 확장 등) 안쪽의 같은 파일은 보존하지 않는다 — 남기면 폴더를 지우지 못한다.
     *
     * @param  string  $path  대상 경로
     * @param  bool  $keepSelf  디렉터리 자신은 남길지
     * @return array{removed: int, failed: array<int, string>} 지운 항목 수와 실패 경로
     */
    function g7_cli_remove_tree(string $path, bool $keepSelf = true): array
    {
        $result = ['removed' => 0, 'failed' => []];

        if (is_link($path) || is_file($path)) {
            if (@unlink($path)) {
                $result['removed']++;
            } else {
                $result['failed'][] = $path;
            }

            return $result;
        }

        if (! is_dir($path)) {
            return $result;
        }

        $preserved = false;
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if ($keepSelf && ! is_dir($child) && in_array($entry, ['.gitkeep', '.gitignore'], true)) {
                $preserved = true;

                continue;
            }
            $sub = g7_cli_remove_tree($child, false);
            $result['removed'] += $sub['removed'];
            $result['failed'] = [...$result['failed'], ...$sub['failed']];
        }

        if (! $keepSelf && ! $preserved) {
            if (@rmdir($path)) {
                $result['removed']++;
            } else {
                $result['failed'][] = $path;
            }
        }

        return $result;
    }
}

if (! function_exists('installer_cli_reinstall_targets')) {
    /**
     * 옵션별 삭제 대상 목록 (사람이 읽는 이름 → 경로 목록, 디렉터리 내용 비우기/통째 삭제 구분).
     *
     * @param  array{delete_vendor?: bool, delete_extensions?: bool, delete_settings?: bool, delete_uploads?: bool}  $opts  삭제 옵션
     * @return array<string, array{paths: array<int, string>, keep_self: bool}> 그룹 → 대상
     */
    function installer_cli_reinstall_targets(array $opts): array
    {
        $base = BASE_PATH;
        // 디렉터리 바로 아래 항목(`<dir>/*` 와 같은 범위 — 점으로 시작하는 이름 제외). `glob` 은 하드닝 프로필이 막을 수
        // 있고 막히면 호출 자체가 Error 다. 그때는 scandir 로 같은 목록을 만든다 — 대상을 조용히 빠뜨리면 「지웠다」고
        // 보고한 재설치가 옛 확장·설정을 남긴다.
        $children = static function (string $dir): array {
            if (installerHasFunction('glob')) {
                return glob($dir.'/*', GLOB_NOSORT) ?: [];
            }

            $entries = [];
            foreach ((is_dir($dir) ? @scandir($dir) : false) ?: [] as $entry) {
                if ($entry !== '' && $entry[0] !== '.') {
                    $entries[] = $dir.'/'.$entry;
                }
            }

            return $entries;
        };
        // `<dir>/*/<name>` — 하위 디렉터리마다 그 이름이 있는 것만.
        $childrenNamed = static fn (string $dir, string $name): array => array_values(array_filter(
            array_map(static fn (string $child): string => $child.'/'.$name, $children($dir)),
            'file_exists'
        ));
        $activeExtensionDirs = static function (string $root) use ($children): array {
            return array_values(array_filter(
                $children($root),
                static fn (string $p): bool => is_dir($p) && ! in_array(basename($p), ['_bundled', '_pending'], true)
            ));
        };

        $targets = [
            'installed_flag' => ['paths' => [$base.'/storage/app/g7_installed'], 'keep_self' => false],
            'installer_state' => ['paths' => [
                $base.'/storage/installer-state.json',
                $base.'/storage/installer-state.json.lock',
                $base.'/storage/installer/runtime.php',
            ], 'keep_self' => false],
            'bootstrap_cache' => ['paths' => array_map(static fn (string $f): string => $base.'/bootstrap/cache/'.$f,
                ['config.php', 'routes-v7.php', 'hooks.php', 'autoload-extensions.php']), 'keep_self' => false],
            'static_publish' => ['paths' => [$base.'/public/build/ext'], 'keep_self' => false],
            // 애플리케이션 파일 캐시 — 옛 사이트의 「활성 확장 목록」이 남으면 빈 DB 위에서도 그 확장이 켜진 것으로
            // 보여, 이번 설치에 넣지 않은 확장의 라우트를 오토로드 없이 실어 artisan 부팅이 죽는다(data/.gitignore 보존).
            'app_cache' => ['paths' => [$base.'/storage/framework/cache/data'], 'keep_self' => true],
        ];

        if (! empty($opts['delete_vendor'])) {
            $targets['vendor'] = ['paths' => [$base.'/vendor'], 'keep_self' => true];
            // vendor 를 지우면 패키지 매니페스트 캐시도 옛 provider 를 가리키므로 함께 지운다.
            $targets['bootstrap_cache']['paths'][] = $base.'/bootstrap/cache/packages.php';
            $targets['bootstrap_cache']['paths'][] = $base.'/bootstrap/cache/services.php';
        }

        if (! empty($opts['delete_extensions'])) {
            $targets['extensions'] = ['paths' => [
                ...$activeExtensionDirs($base.'/modules'),
                ...$activeExtensionDirs($base.'/plugins'),
                ...$activeExtensionDirs($base.'/templates'),
                ...$activeExtensionDirs($base.'/lang-packs'),
            ], 'keep_self' => false];
        }

        if (! empty($opts['delete_settings'])) {
            $targets['settings'] = ['paths' => [
                $base.'/storage/app/settings',
                ...$childrenNamed($base.'/storage/app/modules', 'settings'),
                ...$childrenNamed($base.'/storage/app/plugins', 'settings'),
            ], 'keep_self' => true];
        }

        if (! empty($opts['delete_uploads'])) {
            $uploads = [$base.'/storage/app/attachments', $base.'/storage/app/public'];
            foreach (['modules', 'plugins'] as $kind) {
                foreach ($children($base.'/storage/app/'.$kind) as $extensionStorage) {
                    foreach ($children($extensionStorage) as $entry) {
                        if (basename($entry) !== 'settings') {
                            $uploads[] = $entry;
                        }
                    }
                }
            }
            $targets['uploads'] = ['paths' => $uploads, 'keep_self' => true];
        }

        return $targets;
    }
}

if (! function_exists('installer_cli_read_env_app_key')) {
    /**
     * 현재 .env 의 APP_KEY (base64: 형식일 때만).
     *
     * @return string|null 키
     */
    function installer_cli_read_env_app_key(): ?string
    {
        $env = @file_get_contents(BASE_PATH.'/.env');
        if (! is_string($env) || ! preg_match('/^APP_KEY=["\']?(base64:[A-Za-z0-9+\/=]+)["\']?\s*$/m', $env, $m)) {
            return null;
        }

        return $m[1];
    }
}

if (! function_exists('installer_cli_reinstall')) {
    /**
     * 재설치 초기화를 실행한다.
     *
     * @param  array{delete_vendor?: bool, delete_extensions?: bool, delete_settings?: bool, delete_uploads?: bool}  $opts  삭제 옵션
     * @return array{ok: bool, rows: array<int, array{group: string, removed: int, failed: array<int, string>}>, app_key: string|null} 그룹별 결과와 이어 쓸 APP_KEY
     */
    function installer_cli_reinstall(array $opts): array
    {
        // 키는 지우기 전에 읽는다 — 설정을 남기면 그 암호화 값을 계속 읽을 수 있어야 한다.
        $appKey = empty($opts['delete_settings']) ? installer_cli_read_env_app_key() : null;

        $rows = [];
        $ok = true;
        $targets = installer_cli_reinstall_targets($opts);
        // 옵션 대상(vendor·확장·설정·업로드)을 먼저 지운다 — 실패하면 설치 표식은 남겨 사이트가 「설치됨」으로
        // 남게 한다(반쯤 지운 사이트가 미설치로 보이면 다음 실행이 그 위에 새로 설치한다).
        $markers = ['installed_flag', 'installer_state', 'bootstrap_cache', 'static_publish', 'app_cache'];
        uksort($targets, static fn (string $a, string $b): int => (int) in_array($a, $markers, true) <=> (int) in_array($b, $markers, true));
        foreach ($targets as $group => $target) {
            if (! $ok && in_array($group, $markers, true)) {
                continue;
            }
            $row = ['group' => $group, 'removed' => 0, 'failed' => []];
            foreach ($target['paths'] as $path) {
                $result = g7_cli_remove_tree($path, $target['keep_self']);
                $row['removed'] += $result['removed'];
                $row['failed'] = [...$row['failed'], ...$result['failed']];
            }
            if ($row['failed'] !== []) {
                $ok = false;
            }
            $rows[] = $row;
        }

        if (! $ok) {
            return ['ok' => false, 'rows' => $rows, 'app_key' => $appKey];
        }

        // .env 의 설치 완료 표식만 지운다 — 나머지 줄은 다음 설치가 확정할 때까지 그대로 둔다.
        $envPath = BASE_PATH.'/.env';
        $env = @file_get_contents($envPath);
        $envRow = ['group' => 'env_completed_flag', 'removed' => 0, 'failed' => []];
        if (is_string($env) && preg_match('/^[ \t]*INSTALLER_COMPLETED[ \t]*=.*$/m', $env)) {
            // 줄 종결자는 실제 개행만 본다 — `u` 없는 `\R` 은 0x85 단일 바이트도 개행으로 읽어 그 바이트로 끝나는 한글 값을
            // 글자 중간에서 자른다(`.` 이 CR 을 먹으므로 CRLF 줄은 LF 가 마저 끝낸다).
            $stripped = (string) preg_replace('/^[ \t]*INSTALLER_COMPLETED[ \t]*=.*(?:\r\n|\r|\n)?/m', '', $env);
            // 부분 쓰기(디스크 가득 등)는 `false` 가 아니라 짧은 바이트 수로 돌아온다 — 길이까지 대조한다.
            if (installerWroteFully(@file_put_contents($envPath, $stripped, LOCK_EX), $stripped)) {
                $envRow['removed'] = 1;
            } else {
                $envRow['failed'][] = $envPath;
                $ok = false;
            }
        }
        $rows[] = $envRow;

        return ['ok' => $ok, 'rows' => $rows, 'app_key' => $appKey];
    }
}
