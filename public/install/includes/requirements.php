<?php

use App\Support\OpcacheStatus;

/**
 * 그누보드7 인스톨러 - 서버 요구사항·실행 바이너리 검사 (웹·터미널 공유)
 *
 * 웹 Step 2 요구사항 API(`check-configuration.php?action=requirements`), Step 2 다음 단계 POST 의 서버 재검사,
 * Step 3 의 PHP·Composer 탐지/검증, 터미널 설치(`php g7-install`)가 **같은 함수**를 부른다.
 * 판정 규칙은 이 파일에서만 바꾼다.
 *
 * 의존: config.php(상수) · functions.php(lang·권한 검사·OS 판정) · binary-path-policy.php.
 */

require_once __DIR__.'/binary-path-policy.php';

if (! function_exists('installer_requirement_php_version')) {
    /**
     * PHP 버전 검증
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_php_version(): array
    {
        $currentVersion = PHP_VERSION;
        $minVersion = MIN_PHP_VERSION;
        $passed = version_compare($currentVersion, $minVersion, '>=');

        return [
            'required' => true,
            'min_version' => $minVersion,
            'current_version' => $currentVersion,
            'passed' => $passed,
            'message' => $passed
                ? lang('success_php_version_detail', ['current' => $currentVersion, 'min' => $minVersion])
                : lang('error_php_version_insufficient_detail', ['current' => $currentVersion, 'min' => $minVersion]),
        ];
    }
}

if (! function_exists('installer_requirement_php_extensions')) {
    /**
     * PHP 확장 모듈 검증 — 선택 모듈은 설치 여부만 표시하며 통과 판정에 영향을 주지 않는다.
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_php_extensions(): array
    {
        $requiredExtensions = REQUIRED_EXTENSIONS;
        $optionalExtensions = defined('OPTIONAL_EXTENSIONS') ? OPTIONAL_EXTENSIONS : [];

        $installed = [];
        $allRequiredPassed = true;

        foreach ($requiredExtensions as $extension) {
            $isLoaded = extension_loaded($extension);
            $installed[$extension] = [
                'required' => true,
                'installed' => $isLoaded,
            ];

            if (! $isLoaded) {
                $allRequiredPassed = false;
            }
        }

        $optionalInstalled = [];
        foreach ($optionalExtensions as $extension) {
            $optionalInstalled[$extension] = [
                'required' => false,
                'installed' => extension_loaded($extension),
            ];
        }

        return [
            'required' => $requiredExtensions,
            'optional' => $optionalExtensions,
            'installed' => $installed,
            'optional_installed' => $optionalInstalled,
            'all_required_passed' => $allRequiredPassed,
            'message' => $allRequiredPassed
                ? lang('success_php_extensions')
                : lang('error_php_extensions_missing'),
        ];
    }
}

if (! function_exists('installer_requirement_disk_space')) {
    /**
     * 디스크 공간 검증
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_disk_space(): array
    {
        $minSpaceMb = MIN_DISK_SPACE_MB;
        $freeSpaceBytes = @disk_free_space(BASE_PATH);

        if ($freeSpaceBytes === false) {
            return [
                'required' => true,
                'min_mb' => $minSpaceMb,
                'current_mb' => 0,
                'passed' => false,
                'message' => lang('error_disk_space_unknown'),
            ];
        }

        $freeSpaceMb = round($freeSpaceBytes / 1024 / 1024, 2);
        $passed = $freeSpaceMb >= $minSpaceMb;

        return [
            'required' => true,
            'min_mb' => $minSpaceMb,
            'current_mb' => $freeSpaceMb,
            'passed' => $passed,
            'message' => $passed
                ? lang('success_disk_space_detail', ['current' => $freeSpaceMb, 'min' => $minSpaceMb])
                : lang('error_disk_space_insufficient_detail', ['current' => $freeSpaceMb, 'min' => $minSpaceMb]),
        ];
    }
}

if (! function_exists('installer_requirement_directories')) {
    /**
     * 디렉토리 권한 검증 — 판정은 실행 계정 기준(웹은 웹서버 계정, 터미널은 그 셸 계정).
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_directories(): array
    {
        $check = checkDirectoryPermissions(REQUIRED_DIRECTORIES);

        $results = [];
        foreach ($check['results'] as $dir => $result) {
            $results[$dir] = [
                'path' => $dir,
                'full_path' => BASE_PATH.'/'.$dir,
                'relative_path' => './'.$dir,
                'exists' => $result['exists'],
                'writable' => $result['writable'],
                'readable' => $result['readable'],
                'permissions' => $result['permissions'],
                'owner' => $result['owner'],
                'group' => $result['group'],
                'passed' => $result['passed'],
                'error_type' => $result['error_type'],
                'has_subdirectory_issues' => $result['has_subdirectory_issues'],
                'failed_subdirectories' => $result['failed_subdirectories'],
            ];
        }

        return [
            'required' => true,
            'paths' => array_keys(REQUIRED_DIRECTORIES),
            'results' => $results,
            'required_permissions' => REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY,
            'web_server_group' => getWebServerGroup() ?? getWebServerUser() ?? 'www-data',
            'web_server_user' => getWebServerUser(),
            'all_passed' => $check['all_passed'],
            'message' => $check['all_passed']
                ? lang('success_directories_writable')
                : lang('error_directory_not_writable_detail', ['permissions' => REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY]),
        ];
    }
}

if (! function_exists('installer_requirement_required_files')) {
    /**
     * 필수 파일(.env) 존재·쓰기 가능 여부 검증
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_required_files(): array
    {
        $basePath = BASE_PATH;

        $filePaths = [
            '.env' => $basePath.'/.env',
        ];

        $createCommands = [
            '.env' => getEnvCopyCommand($basePath),
        ];

        $webServerUser = getWebServerUser();
        $files = [];
        $allPassed = true;
        $hasNotWritable = false;

        foreach ($filePaths as $name => $fullPath) {
            $exists = file_exists($fullPath);
            $writable = $exists && is_writable($fullPath);

            $passed = $exists && $writable;
            if (! $passed) {
                $allPassed = false;
            }

            $permissions = 'N/A';
            $permsOctal = 0;
            $owner = null;
            if ($exists) {
                $perms = fileperms($fullPath);
                $permissions = substr(sprintf('%o', $perms), -3);
                $permsOctal = octdec($permissions);
                $owner = getFileOwnerName($fullPath);
            }

            // ownership_mismatch: 파일이 존재하고 권한이 0644 이상인데도 쓰기 불가 → 소유자와 실행 사용자 불일치
            $errorType = null;
            if (! $exists) {
                $errorType = 'not_exists';
            } elseif (! $writable) {
                if ($permsOctal >= 0644 && $webServerUser && $owner && $owner !== $webServerUser) {
                    $errorType = 'ownership_mismatch';
                } else {
                    $errorType = 'not_writable';
                }
                $hasNotWritable = true;
            }

            $files[$name] = [
                'exists' => $exists,
                'writable' => $writable,
                'passed' => $passed,
                'error_type' => $errorType,
                'permissions' => $permissions,
                'owner' => $owner,
                'web_server_user' => $webServerUser,
                'command' => $createCommands[$name],
            ];
        }

        // 프로젝트 루트 소유자가 실행 사용자와 다르면 생성 직후 ownership_mismatch 가 재현되므로
        // 복사 명령에 chgrp + chmod 664 를 미리 포함시키기 위한 정보.
        $basePathOwner = function_exists('posix_getpwuid') && is_dir($basePath)
            ? (posix_getpwuid(fileowner($basePath))['name'] ?? null)
            : null;
        $basePathOwnerMatchesWebUser = $basePathOwner && $webServerUser && $basePathOwner === $webServerUser;

        return [
            'required' => true,
            'files' => $files,
            'all_passed' => $allPassed,
            'has_not_writable' => $hasNotWritable,
            'web_server_group' => getWebServerGroup() ?? $webServerUser ?? 'www-data',
            'web_server_user' => $webServerUser,
            'base_path' => $basePath,
            'base_path_owner' => $basePathOwner,
            'base_path_owner_matches_web_user' => $basePathOwnerMatchesWebUser,
            'relative_base_path' => '.',
            'message' => $allPassed
                ? lang('success_required_files')
                : lang('error_required_files_missing'),
        ];
    }
}

if (! function_exists('installer_forwarded_headers')) {
    /**
     * 수신 중인 X-Forwarded-* 계열 헤더 이름 목록 (#124).
     *
     * 목록은 App\Support\TrustedProxyDiagnostic::FORWARDED_HEADERS 와 같은 집합을 유지한다.
     *
     * @return array<int, string> 수신 중인 헤더 이름 목록
     */
    function installer_forwarded_headers(): array
    {
        $headers = [
            'X-Forwarded-For' => 'HTTP_X_FORWARDED_FOR',
            'X-Forwarded-Proto' => 'HTTP_X_FORWARDED_PROTO',
            'X-Forwarded-Host' => 'HTTP_X_FORWARDED_HOST',
            'X-Forwarded-Port' => 'HTTP_X_FORWARDED_PORT',
            'X-Forwarded-Prefix' => 'HTTP_X_FORWARDED_PREFIX',
            'X-Forwarded-Aws-Elb' => 'HTTP_X_FORWARDED_AWS_ELB',
            'Forwarded' => 'HTTP_FORWARDED',
        ];

        $present = [];

        foreach ($headers as $name => $serverKey) {
            if (isset($_SERVER[$serverKey])) {
                $present[] = $name;
            }
        }

        return $present;
    }
}

if (! function_exists('installer_requirement_https')) {
    /**
     * HTTPS 사용 여부 (선택 항목 — 설치를 차단하지 않는다). 터미널에서는 판정하지 않는다(웹 전용).
     *
     * 프록시 헤더가 감지되면 신뢰 프록시 설정이 필요하다는 안내를 덧붙인다 (#124).
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_https(): array
    {
        $isHttps = isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on';

        if (! $isHttps && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $isHttps = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }

        $forwardedHeaders = installer_forwarded_headers();
        $behindProxy = $forwardedHeaders !== [];

        $message = $isHttps
            ? lang('https_enabled')
            : lang('https_disabled');

        // HTTP 전용 사이트가 프록시 뒤에 있는 구성도 대상이다 — HTTPS 여부로 가르지 않는다.
        if ($behindProxy) {
            $message .= ' '.lang('https_behind_proxy');
        }

        return [
            'required' => false,
            'enabled' => $isHttps,
            'behind_proxy' => $behindProxy,
            'forwarded_headers' => $forwardedHeaders,
            'message' => $message,
        ];
    }
}

if (! function_exists('installer_requirement_opcache')) {
    /**
     * OPcache 활성화 여부 (권장 사항) — 판정 SSoT 는 App\Support\OpcacheStatus.
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_opcache(): array
    {
        $status = OpcacheStatus::probe();

        if ($status['enabled'] === null) {
            $message = lang('opcache_unknown');
        } else {
            $message = $status['enabled']
                ? lang('opcache_enabled')
                : lang('opcache_disabled_warning');
        }

        return [
            'required' => false,
            'loaded' => $status['loaded'],
            'enabled' => $status['enabled'],
            'message' => $message,
        ];
    }
}

if (! function_exists('installer_requirement_vendor_dev_packages')) {
    /**
     * 기존 vendor 의 개발용(require-dev) 패키지 포함 여부 (선택 항목 — 경고만).
     *
     * 이후 코어 업데이트가 vendor 를 `--no-dev` 로 교체하면 매니페스트에만 남은 provider 를 찾다
     * 부팅이 깨지므로 설치 시점에 미리 알린다. `dev` 가 null 이면 판정 불가.
     *
     * @return array{required: bool, vendor_exists: bool, dev: bool|null, packages: array<int, string>, message: string}
     */
    function installer_requirement_vendor_dev_packages(): array
    {
        $vendorExists = is_dir(BASE_PATH.'/vendor') && file_exists(BASE_PATH.'/vendor/autoload.php');

        if (! $vendorExists) {
            return [
                'required' => false,
                'vendor_exists' => false,
                'dev' => null,
                'packages' => [],
                'message' => lang('vendor_dev_packages_no_vendor'),
            ];
        }

        require_once __DIR__.'/vendor-bundle-installer.php';
        $info = detectDevVendorInstall(BASE_PATH);

        if ($info['dev'] === true) {
            $message = lang('vendor_dev_packages_detected_warning', ['count' => count($info['packages'])]);
        } elseif ($info['dev'] === false) {
            $message = lang('vendor_dev_packages_none');
        } else {
            $message = lang('vendor_dev_packages_unknown');
        }

        return [
            'required' => false,
            'vendor_exists' => true,
            'dev' => $info['dev'],
            'packages' => $info['packages'],
            'message' => $message,
        ];
    }
}

if (! function_exists('installer_requirement_asset_url_mode')) {
    /**
     * 자산 URL 방식 항목 정의 (권장 사항).
     *
     * 웹은 실제 방문자와 같은 경로를 타는 브라우저가 판정한다(서버 loopback 은 vhost·프록시를 우회해
     * 다른 답을 낸다). 그래서 여기서는 프로브 경로만 내려준다 — `detected` 가 null 인 것은 설계다.
     * 터미널 설치는 같은 두 경로를 app_url 기준으로 직접 요청해 판정한다(`installer_probe_asset_url_mode`).
     *
     * @return array<string, mixed> 항목 정의
     */
    function installer_requirement_asset_url_mode(): array
    {
        return [
            'required' => false,
            'detected' => null,
            'probe_extension' => '/api/system/asset-probe.js',
            'probe_extensionless' => '/api/system/asset-probe',
        ];
    }
}

if (! function_exists('installer_requirement_disabled_functions')) {
    /**
     * 필수 PHP 함수(exec, proc_open, shell_exec) 비활성화 여부 검증
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_disabled_functions(): array
    {
        $requiredFunctions = ['exec', 'proc_open', 'shell_exec'];
        $disabledStr = ini_get('disable_functions');
        $disabledList = array_map('trim', explode(',', (string) $disabledStr));

        $disabled = [];
        foreach ($requiredFunctions as $func) {
            if (in_array($func, $disabledList, true)) {
                $disabled[] = $func;
            }
        }

        $passed = empty($disabled);

        return [
            'required' => true,
            'checked_functions' => $requiredFunctions,
            'disabled' => $disabled,
            'passed' => $passed,
            'message' => $passed
                ? lang('success_required_functions')
                : lang('error_disabled_functions', ['functions' => implode(', ', $disabled)]),
        ];
    }
}

if (! function_exists('installer_requirement_php_cli_version')) {
    /**
     * PHP CLI 버전과 실행 중인 PHP 버전 일치 여부 (선택 항목)
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_php_cli_version(): array
    {
        $webVersion = PHP_VERSION;
        $cliVersion = null;
        $cliPath = 'php';

        $disabledList = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (in_array('exec', $disabledList, true)) {
            return [
                'required' => false,
                'web_version' => $webVersion,
                'cli_version' => null,
                'cli_path' => null,
                'matched' => null,
                'message' => lang('php_cli_version_check_skipped'),
            ];
        }

        $output = [];
        $returnCode = -1;
        exec('php --version 2>&1', $output, $returnCode);

        if ($returnCode === 0 && ! empty($output)) {
            $outputStr = implode("\n", $output);
            if (preg_match('/PHP\s+(\d+\.\d+\.\d+)/', $outputStr, $matches)) {
                $cliVersion = $matches[1];
            }
        }

        // 메이저.마이너 버전 비교 (패치 차이는 무시)
        $webMajorMinor = implode('.', array_slice(explode('.', $webVersion), 0, 2));
        $cliMajorMinor = $cliVersion ? implode('.', array_slice(explode('.', $cliVersion), 0, 2)) : null;
        $matched = $cliMajorMinor !== null && $webMajorMinor === $cliMajorMinor;

        return [
            'required' => false,
            'web_version' => $webVersion,
            'cli_version' => $cliVersion,
            'cli_path' => $cliPath,
            'matched' => $matched,
            'message' => $cliVersion === null
                ? lang('php_cli_version_unknown')
                : ($matched
                    ? lang('php_cli_version_matched', ['web' => $webVersion, 'cli' => $cliVersion])
                    : lang('php_cli_version_mismatch', ['web' => $webVersion, 'cli' => $cliVersion])),
        ];
    }
}

if (! function_exists('installer_requirements_all_passed')) {
    /**
     * 모든 필수 요구사항 통과 여부 — HTTPS·OPcache·vendor 개발 패키지·자산 URL 방식은 선택 항목.
     *
     * @param  array<string, mixed>  $requirements  항목별 판정 결과
     * @return bool 통과 여부
     */
    function installer_requirements_all_passed(array $requirements): bool
    {
        if (! $requirements['php_version']['passed']) {
            return false;
        }

        if (! $requirements['php_extensions']['all_required_passed']) {
            return false;
        }

        if (isset($requirements['disabled_functions']) && ! $requirements['disabled_functions']['passed']) {
            return false;
        }

        if (! $requirements['disk_space']['passed']) {
            return false;
        }

        if (! $requirements['directories']['all_passed']) {
            return false;
        }

        if (! $requirements['required_files']['all_passed']) {
            return false;
        }

        return true;
    }
}

if (! function_exists('installer_collect_requirements')) {
    /**
     * 서버 요구사항 전체 판정 — 웹 Step 2 API 응답과 같은 구조 + `blocking`.
     *
     * @return array<string, mixed> 항목별 판정 + all_required_passed·is_windows·blocking
     */
    function installer_collect_requirements(): array
    {
        $requirements = [
            'php_version' => installer_requirement_php_version(),
            'php_extensions' => installer_requirement_php_extensions(),
            'disabled_functions' => installer_requirement_disabled_functions(),
            'php_cli_version' => installer_requirement_php_cli_version(),
            'disk_space' => installer_requirement_disk_space(),
            'directories' => installer_requirement_directories(),
            'required_files' => installer_requirement_required_files(),
            'https' => installer_requirement_https(),
            'opcache' => installer_requirement_opcache(),
            'vendor_dev_packages' => installer_requirement_vendor_dev_packages(),
            'asset_url_mode' => installer_requirement_asset_url_mode(),
        ];

        $requirements['all_required_passed'] = installer_requirements_all_passed($requirements);
        $requirements['is_windows'] = isWindows();
        $requirements['blocking'] = ! $requirements['all_required_passed'];

        return $requirements;
    }
}

if (! function_exists('installer_verify_php_binary')) {
    /**
     * PHP 바이너리 경로 유효성 검증 — 형태 규칙 + `--version` 이 최소 버전 이상인지.
     *
     * 파일 존재/실행 가능 검사는 open_basedir 같은 런타임 제약의 false negative 를 피하려 생략하고
     * exec 결과로 최종 판정한다.
     *
     * @param  string  $path  PHP 바이너리 경로
     * @return array{valid: bool, version: string|null, message: string}
     */
    function installer_verify_php_binary(string $path): array
    {
        if (empty($path)) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_php_path_empty')];
        }

        if ($path !== 'php' && ! installer_binary_path_shape_ok($path)) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_php_binary_path_not_allowed', ['path' => $path])];
        }

        $command = escapeshellarg($path).' --version 2>&1';
        $output = [];
        $returnCode = -1;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_php_exec_failed', ['path' => $path])];
        }

        $outputStr = implode("\n", $output);
        if (preg_match('/PHP\s+(\d+\.\d+\.\d+)/', $outputStr, $matches)) {
            $version = $matches[1];
            if (version_compare($version, MIN_PHP_VERSION, '>=')) {
                return [
                    'valid' => true,
                    'version' => $version,
                    'message' => lang('success_php_binary_version', ['path' => $path, 'version' => $version]),
                ];
            }

            return [
                'valid' => false,
                'version' => $version,
                'message' => lang('error_php_version_too_low', ['path' => $path, 'version' => $version, 'min' => MIN_PHP_VERSION]),
            ];
        }

        return ['valid' => false, 'version' => null, 'message' => lang('error_php_version_parse_failed')];
    }
}

if (! function_exists('installer_verify_composer')) {
    /**
     * Composer 바이너리 경로 유효성 검증 — 빈 값이면 시스템 'composer'.
     *
     * 공백 분리 입력은 "PHP 절대경로 + Composer 절대경로" 멀티 PHP 운영 패턴으로, 두 토큰을 각각
     * 검증·escape 한다. `.phar` 는 PHP 바이너리와 결합해 실행한다.
     *
     * @param  string  $composerPath  Composer 바이너리 경로
     * @param  string  $phpPath  PHP 바이너리 경로 (.phar 실행 시 사용)
     * @return array{valid: bool, version: string|null, message: string}
     */
    function installer_verify_composer(string $composerPath, string $phpPath = 'php'): array
    {
        $effectivePath = $composerPath ?: 'composer';

        if ($effectivePath !== 'composer' && str_contains($effectivePath, ' ')) {
            $tokens = installer_resolve_php_composer_pair($effectivePath);
            if ($tokens === null) {
                return [
                    'valid' => false,
                    'version' => null,
                    'message' => lang('error_composer_binary_path_not_allowed', ['path' => $effectivePath]),
                ];
            }

            $command = escapeshellarg($tokens['php']).' '.escapeshellarg($tokens['composer']).' --version 2>&1';
        } else {
            if ($effectivePath !== 'composer' && ! installer_is_composer_binary_path($effectivePath)) {
                return [
                    'valid' => false,
                    'version' => null,
                    'message' => lang('error_composer_binary_path_not_allowed', ['path' => $effectivePath]),
                ];
            }

            if (str_ends_with(strtolower($effectivePath), '.phar')) {
                if ($phpPath !== 'php' && ! installer_binary_path_shape_ok($phpPath)) {
                    return [
                        'valid' => false,
                        'version' => null,
                        'message' => lang('error_php_binary_path_not_allowed', ['path' => $phpPath]),
                    ];
                }
                $command = escapeshellarg($phpPath).' '.escapeshellarg($effectivePath).' --version 2>&1';
            } else {
                $command = escapeshellarg($effectivePath).' --version 2>&1';
            }
        }

        $output = [];
        $returnCode = -1;
        // root/super user + 비대화형 컨텍스트의 composer interactive 경고로 인한 비정상 종료 차단
        applyInstallerComposerEnvVars();
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_composer_exec_failed', ['path' => $effectivePath])];
        }

        $outputStr = implode("\n", $output);
        if (preg_match('/Composer\s+(?:version\s+)?(\d+\.\d+\.\d+)/', $outputStr, $matches)) {
            $version = $matches[1];

            return [
                'valid' => true,
                'version' => $version,
                'message' => lang('success_composer_version', ['path' => $effectivePath, 'version' => $version]),
            ];
        }

        return ['valid' => false, 'version' => null, 'message' => lang('error_composer_version_parse_failed')];
    }
}

if (! function_exists('installer_detect_composer_binary')) {
    /**
     * Composer 바이너리 자동 감지 — 시스템 PATH → 프로젝트 루트 composer.phar → api 폴더 composer.phar.
     *
     * @param  string  $phpPath  .phar 실행 시 사용할 PHP 경로
     * @return array{found: bool, path: string|null, version: string|null}
     */
    function installer_detect_composer_binary(string $phpPath = 'php'): array
    {
        $result = installer_verify_composer('composer', $phpPath);
        if ($result['valid']) {
            return ['found' => true, 'path' => 'composer', 'version' => $result['version']];
        }

        $candidates = [
            BASE_PATH.'/composer.phar',
            dirname(__DIR__).'/api/composer.phar',
        ];
        foreach ($candidates as $pharPath) {
            if (file_exists($pharPath)) {
                $result = installer_verify_composer($pharPath, $phpPath);
                if ($result['valid']) {
                    return ['found' => true, 'path' => $pharPath, 'version' => $result['version']];
                }
            }
        }

        return ['found' => false, 'path' => null, 'version' => null];
    }
}

if (! function_exists('installer_detect_php_binaries')) {
    /**
     * 서버에서 사용 가능한 PHP 바이너리 자동 탐색 + Composer 감지 (웹 detect-php 응답과 같은 구조).
     *
     * @return array{success: bool, binaries: array<int, array{path: string, version: string|null}>, default_php_available: bool, composer: array<string, mixed>, message: string}
     */
    function installer_detect_php_binaries(): array
    {
        $commonPaths = [
            '/usr/local/php84/bin/php',
            '/usr/local/php83/bin/php',
            '/usr/local/php82/bin/php',
            '/usr/bin/php8.4',
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/local/bin/php',
            '/usr/bin/php',
        ];

        $found = [];
        $checkedPaths = [];

        if (defined('PHP_BINARY') && PHP_BINARY !== '' && ! in_array(PHP_BINARY, $checkedPaths, true)) {
            $checkedPaths[] = PHP_BINARY;
            $result = installer_verify_php_binary(PHP_BINARY);
            if ($result['valid']) {
                $found[] = ['path' => PHP_BINARY, 'version' => $result['version']];
            }
        }

        foreach ($commonPaths as $path) {
            if (in_array($path, $checkedPaths, true)) {
                continue;
            }
            $checkedPaths[] = $path;
            if (! file_exists($path)) {
                continue;
            }
            $result = installer_verify_php_binary($path);
            if ($result['valid']) {
                $found[] = ['path' => $path, 'version' => $result['version']];
            }
        }

        $defaultPhpAvailable = false;
        if (! in_array('php', $checkedPaths, true)) {
            $result = installer_verify_php_binary('php');
            if ($result['valid']) {
                $found[] = ['path' => 'php', 'version' => $result['version']];
                $defaultPhpAvailable = true;
            }
        } else {
            foreach ($found as $bin) {
                if ($bin['path'] === 'php') {
                    $defaultPhpAvailable = true;
                    break;
                }
            }
        }

        $phpForComposer = ! empty($found) ? $found[0]['path'] : 'php';

        return [
            'success' => ! empty($found),
            'binaries' => $found,
            'default_php_available' => $defaultPhpAvailable,
            'composer' => installer_detect_composer_binary($phpForComposer),
            'message' => empty($found) ? lang('no_php_detected') : lang('php_detected_count', ['count' => count($found)]),
        ];
    }
}

if (! function_exists('installer_check_core_pending_path')) {
    /**
     * 코어 업데이트 _pending 경로 검사 — 상위 경로(`..`)·NUL 거부, 디렉터리·쓰기 가능 여부.
     *
     * 존재 여부/타입 차이를 응답으로 나누지 않고 한 문구로 통일해 임의 경로 탐색 신호를 막는다.
     *
     * @param  string  $path  입력 경로 (절대 또는 프로젝트 기준 상대)
     * @return array{success: bool, message: string}
     */
    function installer_check_core_pending_path(string $path): array
    {
        if (empty($path)) {
            return ['success' => false, 'message' => lang('error_path_required')];
        }

        $hasParentSegment = preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $path) === 1;
        if ($hasParentSegment || str_contains($path, "\0")) {
            return ['success' => false, 'message' => lang('error_core_pending_path_invalid')];
        }

        $candidatePath = (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1)
            ? $path
            : BASE_PATH.DIRECTORY_SEPARATOR.$path;

        $resolved = @realpath($candidatePath);

        if ($resolved === false || ! is_dir($resolved)) {
            return ['success' => false, 'message' => lang('error_core_pending_path_invalid')];
        }

        $writable = is_writable($resolved);

        return [
            'success' => $writable,
            'message' => $writable
                ? lang('success_core_pending_path')
                : lang('error_core_pending_not_writable'),
        ];
    }
}
