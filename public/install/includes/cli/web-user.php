<?php

/**
 * 그누보드7 터미널 설치 - root 실행과 웹서버 계정 소유권
 *
 * root 로 설치하면 storage·bootstrap/cache 등이 root 소유가 되어 웹서버(PHP-FPM 등)가 캐시·로그를 쓰지 못한다.
 * 그래서 root 실행은 `--web-user=<웹서버 계정>` 이 있을 때만 허용하고, 설치 단계에 들어간 뒤에는 결과와 무관하게
 * 웹서버가 써야 하는 대상만 그 계정으로 넘긴다(777 같은 넓은 권한 부여는 하지 않는다).
 *
 * 대상: 설치 요구사항의 쓰기 디렉터리(REQUIRED_DIRECTORIES, 존재하는 것만, 하위 전체) → 계정:그룹,
 *       .env → 소유자 유지·그룹만 웹서버 그룹·0640, 남은 runtime.php → 계정 소유·0600.
 */

if (! function_exists('g7_cli_is_root')) {
    /**
     * 유효 사용자가 root 인지 (Windows 는 항상 false).
     *
     * @return bool root 면 true
     */
    function g7_cli_is_root(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }
}

if (! function_exists('g7_cli_resolve_web_user')) {
    /**
     * `--web-user`·`--web-group` 을 시스템 계정으로 확인한다.
     *
     * @param  string  $user  계정 이름
     * @param  string|null  $group  그룹 이름 (null 이면 계정의 기본 그룹)
     * @return array{user: string, group: string, uid: int, gid: int}|null 확인된 계정 (없으면 null)
     */
    function g7_cli_resolve_web_user(string $user, ?string $group = null): ?array
    {
        if (! function_exists('posix_getpwnam')) {
            return null;
        }

        $pw = @posix_getpwnam($user);
        if (! is_array($pw)) {
            return null;
        }

        if ($group !== null && $group !== '') {
            $gr = @posix_getgrnam($group);
            if (! is_array($gr)) {
                return null;
            }
        } else {
            $gr = @posix_getgrgid((int) $pw['gid']);
            $group = is_array($gr) ? (string) $gr['name'] : (string) $pw['gid'];
        }

        return ['user' => $user, 'group' => $group, 'uid' => (int) $pw['uid'], 'gid' => is_array($gr) ? (int) $gr['gid'] : (int) $pw['gid']];
    }
}

if (! function_exists('installer_cli_web_user_targets')) {
    /**
     * 웹서버 계정으로 넘길 대상 목록.
     *
     * @return array{directories: array<int, string>, env: string|null, runtime: string|null} 절대 경로
     */
    function installer_cli_web_user_targets(): array
    {
        $directories = [];
        foreach (array_keys(REQUIRED_DIRECTORIES) as $relative) {
            $path = BASE_PATH.'/'.$relative;
            if (is_dir($path)) {
                $directories[] = $path;
            }
        }

        return [
            'directories' => $directories,
            'env' => is_file(BASE_PATH.'/.env') ? BASE_PATH.'/.env' : null,
            'runtime' => is_file(INSTALLER_RUNTIME_PATH) ? INSTALLER_RUNTIME_PATH : null,
        ];
    }
}

if (! function_exists('installer_cli_apply_web_user')) {
    /**
     * 대상의 소유권을 웹서버 계정으로 넘긴다.
     *
     * @param  array{user: string, group: string, uid: int, gid: int}  $webUser  확인된 계정
     * @return array{changed: int, failed: array<string, string>} 바꾼 항목 수와 실패 경로 → 사유
     */
    function installer_cli_apply_web_user(array $webUser): array
    {
        $changed = 0;
        $failed = [];
        $targets = installer_cli_web_user_targets();

        $apply = static function (string $path, bool $owner) use ($webUser, &$changed, &$failed): void {
            $ok = ($owner ? @lchown($path, $webUser['uid']) : true) && @lchgrp($path, $webUser['gid']);
            if ($ok) {
                $changed++;
            } else {
                $failed[$path] = (string) (error_get_last()['message'] ?? 'chown failed');
            }
        };

        $seen = [];
        foreach ($targets['directories'] as $dir) {
            $apply($dir, true);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $path = $item->getPathname();
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                $apply($path, true);
            }
        }

        // .env 는 소유자를 바꾸지 않는다 — 운영자 계정이 계속 편집할 수 있게 그룹 읽기만 연다.
        if ($targets['env'] !== null) {
            $apply($targets['env'], false);
            @chmod($targets['env'], 0640);
        }

        if ($targets['runtime'] !== null) {
            $apply($targets['runtime'], true);
            @chmod($targets['runtime'], 0600);
        }

        return ['changed' => $changed, 'failed' => $failed];
    }
}

if (! function_exists('installer_cli_web_user_manual_command')) {
    /**
     * chown 실패 시 안내할 수동 명령.
     *
     * @param  array{user: string, group: string}  $webUser  계정
     * @return string 셸 명령
     */
    function installer_cli_web_user_manual_command(array $webUser): string
    {
        $dirs = array_map(static fn (string $p): string => escapeshellarg($p), installer_cli_web_user_targets()['directories']);

        return sprintf('chown -R %s:%s %s && chgrp %s %s && chmod 640 %s',
            $webUser['user'], $webUser['group'], implode(' ', $dirs),
            $webUser['group'], escapeshellarg(BASE_PATH.'/.env'), escapeshellarg(BASE_PATH.'/.env'));
    }
}
