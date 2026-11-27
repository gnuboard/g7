<?php

use App\Support\ExecutionEnvironment;
use App\Support\OpcacheStatus;
use App\Support\PhpBinaryResolver;

/**
 * 그누보드7 인스톨러 - 서버 요구사항·실행 바이너리 검사 (웹·터미널 공유)
 *
 * 웹 Step 2 요구사항 API(`check-configuration.php?action=requirements`), Step 2 다음 단계 POST 의 서버 재검사,
 * Step 3 의 PHP·Composer 탐지/검증, 터미널 설치(`php g7-install`)가 **같은 함수**를 부른다.
 * 판정 규칙은 이 파일에서만 바꾼다.
 *
 * 의존: config.php(상수) · functions.php(lang·권한 검사·권한 복구·FTP 안내·OS 판정) · binary-path-policy.php ·
 * execution-mode.php(실행 방식 판정·함수 가용성·HTTPS 판정·크기 표기 해석).
 *
 * App\Support\ExecutionEnvironment · PhpBinaryResolver 는 인스톨러가 Laravel 오토로드 밖이라
 * 쓰기 전에 installerEnsureExecutionEnvironmentLoaded() 로 로드를 보장한다 — 웹·터미널 어느 진입이
 * config.php 의 조건부 로드를 거쳤는지에 기대지 않는다.
 */

require_once __DIR__.'/binary-path-policy.php';
// 실행 방식 판정 헬퍼 — 터미널 진입(cli/app.php)은 이 파일을 싣지 않으므로 여기서 직접 싣는다.
// 선언 전용이고 프로젝트 루트를 자기 위치에서 역산하므로 config.php 와의 로드 순서에 무관하다.
require_once __DIR__.'/execution-mode.php';

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
        installerEnsureExecutionEnvironmentLoaded();

        $requiredExtensions = REQUIRED_EXTENSIONS;
        $optionalExtensions = defined('OPTIONAL_EXTENSIONS') ? OPTIONAL_EXTENSIONS : [];

        // 필수·선택 목록의 원본은 app/Support/Requirements/php-extensions.json 이고 `zip` 은 그 원본에서
        // 필수다(실행 방식과 무관 — 코어 업데이트·번들 설치가 모두 ZipArchive 로 푼다).
        // in-process 는 Vendor 설치 방식이 bundled 로 고정되고(A-4) 그 묶음을 ZipArchive 로 푼다
        // (②) — 원본 목록이 바뀌어 zip 이 필수에서 빠지더라도 in-process 로 떨어지는
        // 환경에서는 필수로 남긴다. 원본이 이미 필수로 두는 지금은 아무것도 바꾸지 않는다.
        // 감지는 PHP 바이너리 probe 를 포함하므로 요청당 한 번만 돈다 (installerDetectExecutionMode 의 정적 캐시).
        if (! in_array('zip', $requiredExtensions, true)
            && installerDetectExecutionMode('php')['mode'] === ExecutionEnvironment::MODE_INPROCESS) {
            $requiredExtensions[] = 'zip';
            $optionalExtensions = array_values(array_diff($optionalExtensions, ['zip']));
        }

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

        // 권장 모듈 — 설치를 막지 않고 화면에 경고로만 보인다. sodium 은 확장·코어·언어팩 패키지 서명(Ed25519) 검증에
        // 쓰인다(7.2.0) — 없으면 서명이 있어도 확인할 수 없어 「서명 검증 불가」로 미서명과 같이 다룬다.
        $recommended = [
            'sodium' => [
                'installed' => extension_loaded('sodium') && function_exists('sodium_crypto_sign_verify_detached'),
                'reason' => lang('recommended_sodium_reason'),
            ],
        ];

        return [
            'required' => $requiredExtensions,
            'optional' => $optionalExtensions,
            'installed' => $installed,
            'optional_installed' => $optionalInstalled,
            'recommended' => $recommended,
            'all_required_passed' => $allRequiredPassed,
            'message' => $allRequiredPassed
                ? lang('success_php_extensions')
                : lang('error_php_extensions_missing'),
        ];
    }
}

if (! function_exists('installer_requirement_disk_space')) {
    /**
     * 디스크 공간 검증 — 확인 불가(함수 차단·조회 실패)는 차단하지 않는다.
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_disk_space(): array
    {
        $minSpaceMb = MIN_DISK_SPACE_MB;

        // `disk_free_space` 는 cPanel/CloudLinux/Imunify 의 표준 차단 목록에 `exec` 와 나란히
        // 들어간다 — 이 안이 지원하려는 바로 그 호스팅이다. PHP 8 부터 `disable_functions` 는
        // 함수를 함수 테이블에서 **제거**하므로 `@` 가 Error 를 막지 못하고, 그 Error 는
        // 호출자의 최상위 catch 로 흘러 요구사항 목록이 **통째로** 안 뜬다.
        //
        // 확인 불가는 차단하지 않는다 — installer_requirement_opcache 가 쓰는 3상태(`enabled === null`)와 같은
        // 규칙이다. 값을 모른다는 사실만 알리고 설치는 계속한다.
        if (! installerHasFunction('disk_free_space')) {
            return [
                'required' => true,
                'min_mb' => $minSpaceMb,
                'current_mb' => null,
                'unknown' => true,
                'passed' => true,
                'message' => lang('error_disk_space_unknown'),
            ];
        }

        $freeSpaceBytes = @disk_free_space(BASE_PATH);

        // disk_free_space() 실패 시 — 「확인 불가」는 위의 차단 갈래(함수 부재)와 **같은 판정**
        // 이어야 한다 (21차 #21-6). NFS/CIFS 마운트·일부 컨테이너 볼륨은 함수가 있어도 false
        // 를 돌려주는데, 종전에는 이 갈래만 passed=false 라 화면이 빨강+차단으로 증폭했다 —
        // 디스크는 넉넉한데 설치가 원리상 진행 불가가 되는 거짓 차단(20차 6 과 같은 형태).
        if ($freeSpaceBytes === false) {
            return [
                'required' => true,
                'min_mb' => $minSpaceMb,
                'current_mb' => null,
                'unknown' => true,
                'passed' => true,
                'message' => lang('error_disk_space_unknown'),
            ];
        }

        $freeSpaceMb = round($freeSpaceBytes / 1024 / 1024, 2);
        $passed = $freeSpaceMb >= $minSpaceMb;

        // 차단 기준(500MB)과 실사용(약 700MB — docs/requirements.md §9)의 간극 안내
        // (21차 #21-7, 제품 결정 §16.27.1 3 — 차단 기준은 유지하고 경고만 덧붙인다).
        // 종전에는 여유 600MB 서버가 초록으로 통과하고 설치 중반에 디스크가 차서
        // 엉뚱한 오류(composer 쓰기 실패·PDO 예외)로 죽었다.
        $recommendedMb = RECOMMENDED_DISK_SPACE_MB;
        $lowHeadroom = $passed && $freeSpaceMb < $recommendedMb;

        // 경고 문구는 message 에 합성하지 않고 별도 필드로 낸다 — 카드 값 슬롯은
        // 짧은 라벨 계약(OPcache 카드와 같은 규칙)이라 문단이 들어가면 배지를 밀어낸다.
        // 화면은 값 슬롯을 GB 로 유지하고 이 문구를 카드 아래 안내로 그린다.
        return [
            'required' => true,
            'min_mb' => $minSpaceMb,
            'recommended_mb' => $recommendedMb,
            'current_mb' => $freeSpaceMb,
            'low_headroom' => $lowHeadroom,
            'headroom_message' => $lowHeadroom
                ? lang('warning_disk_space_low_headroom', ['recommended' => $recommendedMb.'MB'])
                : null,
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
     * 안내를 내기 전에 PHP `chmod()` 로 직접 권한 부여를 먼저 시도하고, 실패한 대상만 모아 무쉘(FTP) 절차를 붙인다.
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_directories(): array
    {
        // 안내를 내기 전에 PHP `chmod()` 로 직접 권한 부여를 시도한다 (B-8).
        // 성공하면 아래 검사가 통과해 안내 자체가 나오지 않는다 — 쉘이 없는 계정에서는
        // 이것이 유일한 자동 복구 수단이다. 실패는 무해하다(안내가 그대로 나온다).
        installerRepairPermissions(array_keys(REQUIRED_DIRECTORIES));

        $check = checkDirectoryPermissions(REQUIRED_DIRECTORIES);

        // W7 무쉘 판본 — 실패한 대상만 모아 FTP 조작 절차를 만든다. 소유권 불일치는 표준
        // 값으로 쓰기가 생기지 않는 구성이므로 보조 값을 병기하는 별도 판본을 함께 낸다.
        $failedRelative = [];
        $ownershipRelative = [];
        foreach ($check['results'] as $dir => $result) {
            if ($result['passed']) {
                continue;
            }
            if ($result['error_type'] === 'ownership_mismatch') {
                $ownershipRelative[] = './'.$dir;
            } elseif (in_array($result['error_type'], ['not_writable', 'not_readable', 'subdirectory_issues'], true)) {
                $failedRelative[] = './'.$dir;
            }
        }

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
            'ftp_guide' => $failedRelative === []
                ? null
                : installerFtpPermissionGuide($failedRelative, true, isWindows()),
            'ftp_guide_ownership' => $ownershipRelative === []
                ? null
                : installerFtpPermissionGuide($ownershipRelative, true, isWindows(), true),
            'message' => $check['all_passed']
                ? lang('success_directories_writable')
                : lang('error_directory_not_writable_detail'),
        ];
    }
}

if (! function_exists('installer_requirement_required_files')) {
    /**
     * 필수 파일(.env) 존재·쓰기 가능 여부 검증
     *
     * 디렉토리와 같은 규율로 직접 권한 부여를 먼저 시도하고, 쓰기 불가·소유권 불일치·파일 부재 갈래마다 무쉘(FTP) 절차를 붙인다.
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

        // 디렉토리와 같은 규율 — 안내를 내기 전에 직접 권한 부여를 시도한다 (B-8).
        installerRepairPermissions(array_keys($filePaths), false);

        $webServerUser = getWebServerUser();
        $files = [];
        $allPassed = true;
        $hasNotWritable = false;
        $notWritableRelative = [];
        $ownershipRelative = [];
        $missingNames = [];

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
                $missingNames[] = $name;
            } elseif (! $writable) {
                if ($permsOctal >= 0644 && $webServerUser && $owner && $owner !== $webServerUser) {
                    $errorType = 'ownership_mismatch';
                    $ownershipRelative[] = './'.$name;
                } else {
                    $errorType = 'not_writable';
                    $notWritableRelative[] = './'.$name;
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
            'ftp_guide' => $notWritableRelative === []
                ? null
                : installerFtpPermissionGuide($notWritableRelative, false, isWindows()),
            'ftp_guide_ownership' => $ownershipRelative === []
                ? null
                : installerFtpPermissionGuide($ownershipRelative, false, isWindows(), true),
            // 파일이 **아예 없는** 갈래의 무쉘 판본 (W7). 형제 둘과 달리 종전에는 쉘 복사
            // 명령뿐이라, 쉘을 못 쓰는 호스팅에서는 설치를 시작할 방법이 없었다.
            'ftp_guide_create' => $missingNames === []
                ? null
                : installerFtpCreateFileGuide('.env.example', $missingNames[0]),
            'message' => $allPassed
                ? lang('success_required_files')
                : lang('error_required_files_missing'),
        ];
    }
}

if (! function_exists('installer_forwarded_headers')) {
    /**
     * 수신 중인 X-Forwarded-* 계열 헤더 이름 목록 (공개 #124).
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
     * 프록시 헤더가 감지되면 신뢰 프록시 설정이 필요하다는 안내를 덧붙인다 (공개 #124).
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_https(): array
    {
        // 판정은 세션 쿠키 설정과 같은 함수를 쓴다 — 두 벌로 두었더니
        // 한쪽만 프록시를 고려해, TLS 종단이 앞단인 배치에서 secure 쿠키가 붙지 않았다.
        $isHttps = installerRequestIsHttps($_SERVER);

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

if (! function_exists('installer_requirement_php_limits')) {
    /**
     * 실행 예산(메모리·시간) 확인 (정보 — 설치를 차단하지 않음)
     *
     * 종전에는 요구사항 목록에 이 두 값이 **하나도 없었다**. 128M 공유호스팅
     * 에서 in-process 부팅 + migrate + seed 는 죽는데, 그 사실을 설치 전에 알 방법이 없어
     * 운영자는 요구사항을 전부 통과하고서 중간에 멈추는 설치를 만난다.
     *
     * 차단하지 않는 이유: 두 값 모두 실행 중 조정될 수 있고(`ini_set`·`set_time_limit`),
     * 값이 낮다고 반드시 실패하지도 않는다. 그리고 `ini_get` 자체가 막힌 환경이 있으므로
     * 「확인 불가」 3상태를 함께 둔다 — installer_requirement_opcache 와 같은 규칙이다.
     *
     * @return array{required: bool, memory_limit: string|null, memory_limit_bytes: int|null, recommended_memory_mb: int, max_execution_time: string|null, open_basedir: string|null, message: string}
     */
    function installer_requirement_php_limits(): array
    {
        // 실행 경로의 실제 요구와 같은 값이어야 한다 (20차 1, 제품 결정 §16.26.4 3) —
        // subprocess 는 `-d memory_limit=512M`, in-process 는 512M 상향을 시도한다.
        // 종전 256M 은 그보다 낮아, 256~512M 호스트가 화면을 초록으로 지나고 설치 중반
        // OOM 을 만났다. 세 선언의 등치는 InstallerMemoryBudgetTest 가 잠근다.
        $recommendedMemoryMb = 512;

        if (! installerHasFunction('ini_get')) {
            return [
                'required' => false,
                'memory_limit' => null,
                'memory_limit_bytes' => null,
                'recommended_memory_mb' => $recommendedMemoryMb,
                'max_execution_time' => null,
                'open_basedir' => null,
                'message' => lang('php_limits_unknown'),
            ];
        }

        $memoryLimit = trim((string) ini_get('memory_limit'));
        $maxExecutionTime = trim((string) ini_get('max_execution_time'));
        $memoryBytes = installerParseByteSize($memoryLimit);
        // open_basedir 상태 노출 (20차 10) — 이 제한은 detect-php·경로 검사·시스템 temp 접근의
        // 실패 원인이 되는데 종전에는 서버가 읽지도 화면이 보여주지도 않아 원인 단서가 없었다.
        $openBasedir = trim((string) ini_get('open_basedir'));

        return [
            'required' => false,
            'memory_limit' => $memoryLimit !== '' ? $memoryLimit : null,
            // -1 은 무제한이다 — 바이트 값이 아니라 상태이므로 그대로 싣는다.
            'memory_limit_bytes' => $memoryBytes,
            'recommended_memory_mb' => $recommendedMemoryMb,
            'max_execution_time' => $maxExecutionTime !== '' ? $maxExecutionTime : null,
            'open_basedir' => $openBasedir !== '' ? $openBasedir : null,
            'message' => lang('php_limits_detail', [
                'memory' => $memoryLimit !== '' ? $memoryLimit : '-',
                'time' => $maxExecutionTime !== '' ? $maxExecutionTime : '-',
                'recommended' => $recommendedMemoryMb.'M',
            ]).($openBasedir !== '' ? ' '.lang('php_limits_open_basedir') : ''),
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
     * 실행 방식 판정 (구 "필수 PHP 함수 비활성화 여부 검증")
     *
     * 차단/통과 이분 게이트가 아니라 실행 모드 분기다 (A-1):
     * - subprocess: 함수 전부 가용 + PHP 바이너리 검증 통과 → 현행 그대로 (초록)
     * - inprocess: 위 실패 + vendor-bundle.zip 존재 → CLI 없이 설치 진행 (정보, 파랑)
     * - blocked: 위 실패 + 번들도 없음 → 설치 차단 (빨강) — 사유는 "CLI 부재" 가 아니라 "번들 부재"
     *
     * 판정 본체는 App\Support\ExecutionEnvironment 가 SSoT — 코어의 실행 모드
     * 해석기(CommandRunnerResolver)와 같은 판정을 공유한다.
     *
     * @return array<string, mixed> 판정 결과 (status: pass|info|fail, execution_mode, bundle_available, php_probe, reasons 포함)
     */
    function installer_requirement_disabled_functions(): array
    {
        installerEnsureExecutionEnvironmentLoaded();

        // 감지는 캐시를 거친다 — 본체에 PHP 바이너리 probe 가 들어 있어 이 화면에서 가장 비싼
        // 연산이다. 종전에는 여기서 캐시를 우회해 같은 요청 안에서 두 번 돌았고, 「요청당
        // 한 번」 규칙과 어긋났다. 두 판정이 갈라질 여지도 함께 없어진다.
        $detection = installerDetectExecutionMode('php');
        $requiredFunctions = ExecutionEnvironment::SUBPROCESS_FUNCTIONS;
        $disabled = $detection['blocked_functions'];

        // 「번들이 있다」가 아니라 「번들을 쓸 수 있다」를 본다 — 푸는 데
        // ZipArchive 가 필요하고 공식 php 도커 이미지에는 ext-zip 이 기본으로 없다.
        // 판정은 installerVendorBundleUsable() 단일 지점이 소유한다.
        $bundleAvailable = $detection['bundle_available'] ?? installerVendorBundleUsable();

        if ($detection['mode'] === ExecutionEnvironment::MODE_SUBPROCESS) {
            $status = 'pass';
            $message = lang('success_required_functions');
        } elseif ($bundleAvailable) {
            $status = 'info';
            $message = lang('execution_mode_inprocess_notice');
        } else {
            $status = 'fail';
            $message = lang('error_no_cli_and_no_bundle');
        }

        // 판정 근거 (정보 카드가 그대로 표시한다 — W1). 문장 생성은 execution-mode.php 가
        // 단일 출처다 — 같은 판정을 두 벌로 쓰면 W1 카드와 Step 3 감지 API 의 문장이 갈린다.
        $reasons = installerExecutionModeReasons($detection);

        return [
            'required' => true,
            'checked_functions' => $requiredFunctions,
            'disabled' => $disabled,
            'execution_mode' => $detection['mode'],
            'bundle_available' => $bundleAvailable,
            'php_probe' => $detection['php_probe'],
            'reasons' => $reasons,
            'status' => $status,
            // 정보(inprocess + 번들 보유) 상태는 설치를 막지 않는다
            'passed' => $status !== 'fail',
            'message' => $message,
        ];
    }
}

if (! function_exists('installer_probe_cli_runtime')) {
    /**
     * CLI 바이너리의 런타임 설정을 조회한다.
     *
     * `--version` 은 버전만 알려 준다. 같은 버전이라도 웹 SAPI 와 CLI 가 서로 다른 php.ini
     * 를 읽는 배치(cPanel/Plesk 멀티 PHP, EA-PHP)가 표준이므로, 실제 확장 로드 상태와 메모리
     * 상한을 그 바이너리에게 직접 묻는다.
     *
     * `-r` 로 넘기는 코드는 **고정 리터럴**이다 — 요청 입력이 섞이지 않으므로 실행 바이너리
     * 형태 정책(binary-path-policy)만 지키면 된다.
     *
     * @param  string  $binary  PHP 바이너리 경로
     * @return array{version: string|null, extensions: array<int, string>|null, memory_limit: string|null}
     */
    function installer_probe_cli_runtime(string $binary): array
    {
        installerEnsureExecutionEnvironmentLoaded();

        $empty = ['version' => null, 'extensions' => null, 'memory_limit' => null];

        if (! ExecutionEnvironment::hasFunction('exec')) {
            return $empty;
        }

        if ($binary !== 'php' && ! installer_binary_path_shape_ok($binary)) {
            return $empty;
        }

        // json-encode:allow — 이 인스톨러의 출력이 아니라 `php -r` 로 넘길 **별도 프로세스의
        // 소스 문자열**이다. 그 프로세스에는 인스톨러 헬퍼가 로드되어 있지 않다.
        $code = "echo json_encode(['v' => PHP_VERSION, 'e' => get_loaded_extensions(), 'm' => ini_get('memory_limit')]);";
        $command = escapeshellarg($binary).' -r '.escapeshellarg($code).' 2>&1';

        $output = [];
        $returnCode = -1;
        @exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return $empty;
        }

        // 일부 환경은 경고 한 줄을 먼저 찍는다 — JSON 은 마지막 줄에 있다.
        $decoded = json_decode(trim((string) end($output)), true);

        if (! is_array($decoded) || ! is_array($decoded['e'] ?? null)) {
            return $empty;
        }

        return [
            'version' => is_string($decoded['v'] ?? null) ? $decoded['v'] : null,
            'extensions' => array_values(array_filter($decoded['e'], 'is_string')),
            'memory_limit' => is_string($decoded['m'] ?? null) ? $decoded['m'] : null,
        ];
    }
}

if (! function_exists('installer_requirement_php_cli_version')) {
    /**
     * PHP CLI 버전과 실행 중인 PHP 버전 일치 여부 (선택 항목) — CLI 의 SAPI·누락 확장·메모리 상한도 함께 조회한다.
     *
     * @return array<string, mixed> 판정 결과
     */
    function installer_requirement_php_cli_version(): array
    {
        installerEnsureExecutionEnvironmentLoaded();

        $webVersion = PHP_VERSION;
        $cliVersion = null;
        $cliPath = 'php';

        // exec 사용 가능 여부 먼저 확인 — 판정은 ExecutionEnvironment 가 SSoT (실행 방식 게이트와 동일 판정)
        if (! ExecutionEnvironment::hasFunction('exec')) {
            return [
                'required' => false,
                'web_version' => $webVersion,
                'cli_version' => null,
                'cli_path' => null,
                'matched' => null,
                'message' => lang('php_cli_version_check_skipped'),
            ];
        }

        // 경로 기본값·형태 규칙은 execution-mode 헬퍼가 SSoT 다. probe 도 코어와 공유한다 —
        // 여기서 exec + 버전 정규식을 다시 쓰면 SAPI 판정 같은 개선이 이 사본에만 빠진다.
        $cliPath = installerDetectionPhpBinary('')['binary'];
        $probe = ExecutionEnvironment::probePhpBinary($cliPath);
        $cliVersion = $probe['version'];
        $cliSapi = $probe['sapi'];

        // 메이저.마이너 버전 비교 (패치 차이는 무시)
        $webMajorMinor = implode('.', array_slice(explode('.', $webVersion), 0, 2));
        $cliMajorMinor = $cliVersion ? implode('.', array_slice(explode('.', $cliVersion), 0, 2)) : null;
        $matched = $cliMajorMinor !== null && $webMajorMinor === $cliMajorMinor;

        // 버전이 같아도 **설정이 같다는 뜻은 아니다**. 멀티 PHP 패널에서 웹은
        // ea-php83, PATH 의 php 는 시스템 php83 인데 php.ini 가 달라 pdo_mysql 이 CLI 에만
        // 없는 조합이 흔하다 — 그 조합에서 요구사항은 전부 초록인데 마이그레이션만 실패한다.
        // 그래서 CLI 쪽 확장 목록·메모리 상한을 함께 조회해 REQUIRED_EXTENSIONS 와 대조한다.
        $cliRuntime = installer_probe_cli_runtime($cliPath);
        $cliMissingExtensions = [];
        // 상수는 config.php 가 정의한다 — 이 파일만 로드하는 진입점(라이브러리 모드)에서는
        // 없을 수 있으므로 그 경우 대조를 건너뛴다.
        if (is_array($cliRuntime['extensions'] ?? null) && defined('REQUIRED_EXTENSIONS')) {
            $loaded = array_map('strtolower', $cliRuntime['extensions']);
            foreach (REQUIRED_EXTENSIONS as $extension) {
                if (! in_array(strtolower($extension), $loaded, true)) {
                    $cliMissingExtensions[] = $extension;
                }
            }
        }

        $message = $cliVersion === null
            ? lang('php_cli_version_unknown')
            : ($matched
                ? lang('php_cli_version_matched', ['web' => $webVersion, 'cli' => $cliVersion])
                : lang('php_cli_version_mismatch', ['web' => $webVersion, 'cli' => $cliVersion]));

        if ($cliMissingExtensions !== []) {
            // 확장 누락은 버전 일치 여부보다 앞선 사실이다 — 이 문구가 최종 안내가 된다.
            $message = lang('php_cli_extensions_missing', ['extensions' => implode(', ', $cliMissingExtensions)]);
        }

        return [
            'required' => false,
            'web_version' => $webVersion,
            'cli_version' => $cliVersion,
            'cli_path' => $cliPath,
            // PATH 의 php 가 CLI 가 아닐 수 있다 (fpm/cgi 도 `--version` 은 정상 종료한다).
            // 그 사실을 응답에 남겨야 "버전은 맞는데 전 태스크가 실패" 를 설명할 수 있다.
            'cli_sapi' => $cliSapi,
            'cli_missing_extensions' => $cliMissingExtensions,
            'cli_memory_limit' => $cliRuntime['memory_limit'] ?? null,
            'matched' => $matched,
            'message' => $message,
        ];
    }
}

if (! function_exists('installer_requirements_all_passed')) {
    /**
     * 모든 필수 요구사항 통과 여부 — HTTPS·OPcache·실행 예산·vendor 개발 패키지·자산 URL 방식은 선택 항목.
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

        // 웹 노출 점검(web_exposure)은 required 지만 이 집계에 넣지 않는다 — 판정 주체가
        // 브라우저(서버 loopback 프로브는 오판)라 서버는 판정값을 모른다. 노출/판정 불능의
        // [다음 단계] 게이팅은 화면이 프로브 결과로 잡는다 (checkWebExposure 주석 · D3 · D21).

        return true;
    }
}

if (! function_exists('installer_collect_requirements')) {
    /**
     * 서버 요구사항 전체 판정 — 웹 Step 2 API 응답과 같은 구조 + `blocking`.
     *
     * 웹 전용 항목 web_exposure 는 여기에 없다 — 웹 껍데기(check-configuration.php)가 이 결과에 덧붙인다.
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
            'php_limits' => installer_requirement_php_limits(),
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
     * PHP 바이너리 경로 유효성 검증 — 형태 규칙 + `--version` 이 최소 버전 이상인지 + SAPI.
     *
     * 파일 존재/실행 가능 검사는 open_basedir 같은 런타임 제약의 false negative 를 피하려 생략하고
     * 실행 결과로 최종 판정한다. 실행·파싱은 코어와 공유한다(PhpBinaryResolver::validate).
     *
     * @param  string  $path  PHP 바이너리 경로
     * @return array{valid: bool, version: string|null, sapi?: string|null, message: string}
     */
    function installer_verify_php_binary(string $path): array
    {
        installerEnsureExecutionEnvironmentLoaded();

        // 이 판정은 `{binary} --version` 을 실제로 돌려서 내린다 — 실행 함수가 막힌 환경
        // (= 이 안이 지원하려는 대표 환경)에서 가드 없이 부르면 `exec()` 자체가 정의되지 않아
        // Error 가 나고, 라우터의 catch 가 그 개발자 원문을 화면에 띄운다.
        // 가용성 판정은 코어와 공유하는 SSoT 가 소유한다(disable_functions 까지 본다).
        if (! ExecutionEnvironment::hasFunction('exec')) {
            return ['valid' => false, 'version' => null, 'sapi' => null, 'message' => lang('error_binary_probe_unavailable')];
        }

        if (empty($path)) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_php_path_empty')];
        }

        if ($path !== 'php' && ! installer_binary_path_shape_ok($path)) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_php_binary_path_not_allowed', ['path' => $path])];
        }

        // 실행·파싱은 코어와 공유한다 (PhpBinaryResolver::validate) — 사본을 두면 SAPI 판정처럼
        // 코어에서 닫은 결함이 이 경로에만 남는다. `php-fpm --version` · `php-cgi --version` 은
        // CLI 와 똑같이 정상 종료하고 같은 형태로 버전을 찍으므로, 구분은 SAPI 뿐이다.
        $probe = PhpBinaryResolver::validate($path);
        $version = $probe['version'];
        $sapi = $probe['sapi'];

        if (! $probe['valid']) {
            return [
                'valid' => false,
                'version' => $version,
                'sapi' => $sapi,
                'message' => $version !== null
                    ? lang('error_php_version_too_low', ['path' => $path, 'version' => $version, 'min' => MIN_PHP_VERSION])
                    : lang('error_php_exec_failed', ['path' => $path]),
            ];
        }

        // 비-CLI 는 막지 않는다 — php-cgi 로만 동작하던 환경을 새로 차단하지 않는다는
        // 코어 판정(ExecutionEnvironment::detect)과 같은 기준이다. 대신 사실을 알린다:
        // 이 바이너리는 artisan 을 받지 못해 설치 태스크가 전부 실패한다.
        return [
            'valid' => true,
            'version' => $version,
            'sapi' => $sapi,
            'message' => ($sapi !== null && $sapi !== 'cli')
                ? lang('warning_php_binary_not_cli', ['path' => $path, 'version' => (string) $version, 'sapi' => $sapi])
                : lang('success_php_binary_version', ['path' => $path, 'version' => (string) $version]),
        ];
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
        installerEnsureExecutionEnvironmentLoaded();

        // PHP 경로 판정과 같은 이유의 가드 — 이 함수도 `--version` 을 실제로 돌린다.
        if (! ExecutionEnvironment::hasFunction('exec')) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_binary_probe_unavailable')];
        }

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
     * 후보 경로는 `realpath` 없이 BASE_PATH 와 이 파일 위치로 만든다 — `realpath` 가 막힌 환경
     *에서도 그대로 성립한다.
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
     * 후보는 CLI 우선으로 정렬한다(코어 정렬 규칙 PhpBinaryResolver::sortCliFirst 공유).
     *
     * @return array{success: bool, binaries: array<int, array{path: string, version: string|null, sapi: string|null}>, default_php_available: bool, composer: array<string, mixed>, message: string}
     */
    function installer_detect_php_binaries(): array
    {
        installerEnsureExecutionEnvironmentLoaded();

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
                $found[] = ['path' => PHP_BINARY, 'version' => $result['version'], 'sapi' => $result['sapi'] ?? null];
            }
        }

        foreach ($commonPaths as $path) {
            if (in_array($path, $checkedPaths, true)) {
                continue;
            }
            $checkedPaths[] = $path;
            // 후보는 문서루트 **밖** 시스템 경로다 — open_basedir 환경에서 이 stat 은
            // warning 을 내며 false 를 돌려준다. 판정(건너뜀)은 그대로 옳으므로 진단만
            // 억제한다 (20차 8). 억제하지 않으면 그 텍스트가 JSON 본문을 오염시킨다.
            if (! @file_exists($path)) {
                continue;
            }
            $result = installer_verify_php_binary($path);
            if ($result['valid']) {
                $found[] = ['path' => $path, 'version' => $result['version'], 'sapi' => $result['sapi'] ?? null];
            }
        }

        $defaultPhpAvailable = false;
        if (! in_array('php', $checkedPaths, true)) {
            $result = installer_verify_php_binary('php');
            if ($result['valid']) {
                $found[] = ['path' => 'php', 'version' => $result['version'], 'sapi' => $result['sapi'] ?? null];
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

        // CLI 후보를 앞으로 보낸다 — 화면이 첫 후보를 입력란에 자동으로 채우므로(3-configuration.php),
        // 정렬하지 않으면 웹 SAPI 바이너리(PHP_BINARY 는 mod_php/FPM 에서 그 자신이다)가 그대로
        // 실행 경로로 굳는다. 그 바이너리는 artisan 을 받지 못해 전 태스크가 실패한다.
        $found = PhpBinaryResolver::sortCliFirst($found);

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

        // `realpath` 가 막혀 있으면 정규화 없이 판정한다 — 위에서 상위 경로
        // 세그먼트(`..`)와 NUL 을 이미 거부했으므로 traversal 방어는 그대로 성립한다.
        $resolved = installerHasFunction('realpath') ? @realpath($candidatePath) : $candidatePath;

        // 운영자가 임의 절대경로를 입력하는 칸이라 open_basedir 밖 입력이 정상 시나리오다 —
        // stat 의 판정(false)은 옳고 warning 만 억제한다 (20차 9, 위 realpath 와 같은 규칙).
        if ($resolved === false || ! @is_dir($resolved)) {
            return ['success' => false, 'message' => lang('error_core_pending_path_invalid')];
        }

        $writable = @is_writable($resolved);

        return [
            'success' => $writable,
            'message' => $writable
                ? lang('success_core_pending_path')
                // 이 키는 :path 자리표시자를 갖는다 — 치환값 없이 부르면 운영자 화면에
                // `디렉토리(:path)에 …` 가 그대로 나가 어느 경로인지가 사라진다.
                : lang('error_core_pending_not_writable', ['path' => $resolved]),
        ];
    }
}
