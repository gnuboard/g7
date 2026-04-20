<?php

/**
 * G7 인스톨러 - 검증 통합 API
 *
 * 서버 요구사항 검증 및 데이터베이스 연결 테스트 기능을 하나의 API로 통합
 *
 * 엔드포인트:
 * - GET  ?action=requirements : 서버 요구사항 검증 (action 생략 시 기본값)
 * - POST ?action=test-db      : 데이터베이스 연결 테스트
 */

/**
 * 검증 API 클래스
 */
class ValidationApi
{
    /**
     * 요청 처리 메인 메서드
     */
    public function handleRequest(): void
    {
        // JSON 헤더 설정
        $this->setJsonHeaders();

        // HTTP 메서드 및 action 파라미터 확인
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? ($method === 'GET' ? 'requirements' : null);

        try {
            // action에 따라 적절한 메서드 호출
            match ($action) {
                'requirements' => $this->checkRequirements(),
                'test-db' => $this->testDbConnection(),
                'detect-php' => $this->detectPhpBinaries(),
                'test-php-binary' => $this->testPhpBinary(),
                'test-composer' => $this->testComposer(),
                'check-core-pending-path' => $this->checkCorePendingPath(),
                default => $this->error400('Invalid action parameter'),
            };
        } catch (Throwable $e) {
            $this->error500($e);
        }
    }

    /**
     * JSON 응답 헤더 설정
     */
    private function setJsonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
    }

    /**
     * GET ?action=requirements
     * 서버 요구사항 검증
     *
     * PHP 버전, 확장 모듈, 디스크 공간, 디렉토리 권한, HTTPS 등을 검증합니다.
     */
    private function checkRequirements(): void
    {
        // 요구사항 검증 결과 배열
        $requirements = [
            'php_version' => $this->checkPhpVersion(),
            'php_extensions' => $this->checkPhpExtensions(),
            'disabled_functions' => $this->checkDisabledFunctions(),
            'php_cli_version' => $this->checkPhpCliVersion(),
            'disk_space' => $this->checkDiskSpace(),
            'directories' => $this->checkDirectoryPermissions(),
            'required_files' => $this->checkRequiredFiles(),
            'https' => $this->checkHttps(),
        ];

        // 모든 필수 요구사항 통과 여부
        $requirements['all_required_passed'] = $this->isAllRequiredPassed($requirements);

        // OS 정보 (프론트엔드에서 명령어 분기용)
        $requirements['is_windows'] = isWindows();

        // JSON 응답 반환
        echo json_encode($requirements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST ?action=test-db
     * 데이터베이스 연결 테스트
     *
     * POST로 전달받은 데이터베이스 연결 정보를 사용하여
     * 연결 테스트 및 권한 검증을 수행합니다.
     */
    private function testDbConnection(): void
    {
        // POST 메서드만 허용
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => lang('api_method_not_allowed'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            // POST 데이터 파싱
            $input = json_decode(file_get_contents('php://input'), true);

            if (! $input || ! is_array($input)) {
                throw new Exception(lang('api_invalid_request'));
            }

            // DB 타입 확인 ('write' 또는 'read')
            $type = $input['type'] ?? 'write';
            $isReadDb = ($type === 'read');

            // DB 연결 정보 구성
            $config = [];

            if ($isReadDb) {
                $config['db_read_host'] = $input['host'] ?? 'localhost';
                $config['db_read_port'] = $input['port'] ?? '3306';
                $config['db_read_database'] = $input['database'] ?? '';
                $config['db_read_username'] = $input['username'] ?? '';
                $config['db_read_password'] = $input['password'] ?? '';
            } else {
                $config['db_write_host'] = $input['host'] ?? 'localhost';
                $config['db_write_port'] = $input['port'] ?? '3306';
                $config['db_write_database'] = $input['database'] ?? '';
                $config['db_write_username'] = $input['username'] ?? '';
                $config['db_write_password'] = $input['password'] ?? '';
            }

            // 필수 필드 검증
            $database = $isReadDb ? $config['db_read_database'] : $config['db_write_database'];
            $username = $isReadDb ? $config['db_read_username'] : $config['db_write_username'];

            if (empty($database) || empty($username)) {
                throw new Exception(lang('error_db_credentials_required'));
            }

            // 데이터베이스 연결 시도
            $pdo = getDatabaseConnection($config, $isReadDb);

            // 권한 검증
            $privileges = checkDatabasePrivileges($pdo, $database, $isReadDb);

            // 권한 부족 확인
            if (! $privileges['has_all'] && ! empty($privileges['missing'])) {
                $missingPrivs = implode(', ', $privileges['missing']);
                $dbTypeLabel = $isReadDb ? 'Read' : 'Write';

                throw new Exception(
                    lang('error_db_privileges_insufficient_detail', ['type' => $dbTypeLabel, 'missing' => $missingPrivs])
                );
            }

            // 연결 및 권한 검증 성공 플래그 (Write/Read 구분)
            if ($isReadDb) {
                $_SESSION['db_read_tested'] = true;
                $message = lang('success_db_read_connected');
            } else {
                $_SESSION['db_write_tested'] = true;
                $message = lang('success_db_write_connected');
            }

            // 기존 테이블 감지 (Write DB만 수행 — 이슈 #244 대응)
            // 사용자가 입력한 db_prefix를 g7 시그니처에 적용하여 정확히 비교
            $existingTables = null;
            if (!$isReadDb) {
                $tablePrefix = (string) ($input['db_prefix'] ?? 'g7_');
                $existingTables = checkExistingTables($pdo, $database, $tablePrefix);
            }

            // 성공 응답
            echo json_encode([
                'success' => true,
                'message' => $message,
                'type' => $type,
                'privileges' => [
                    'has_all' => $privileges['has_all'],
                    'found' => $privileges['found'],
                    'required' => $privileges['required'],
                ],
                'existing_tables' => $existingTables,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            // 연결 실패 플래그
            $isReadDb = isset($type) && $type === 'read';

            if ($isReadDb) {
                $_SESSION['db_read_tested'] = false;
            } else {
                $_SESSION['db_write_tested'] = false;
            }

            // 에러 로깅
            logInstallationError(lang('error_db_connection_failed'), $e);

            // 에러 응답 (200 OK + success: false)
            echo json_encode([
                'success' => false,
                'message' => lang('error_db_connection_failed_detail', ['type' => ($isReadDb ? 'Read' : 'Write'), 'error' => $e->getMessage()]),
                'type' => $type ?? 'write',
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            // 권한 검증 실패 또는 기타 에러
            $isReadDb = isset($type) && $type === 'read';

            if (isset($type)) {
                if ($isReadDb) {
                    $_SESSION['db_read_tested'] = false;
                } else {
                    $_SESSION['db_write_tested'] = false;
                }
            }

            // 에러 로깅
            logInstallationError(lang('error_db_test_failed'), $e);

            // 에러 응답 (200 OK + success: false)
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'type' => $type ?? 'write',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    // ========================================
    // 서버 요구사항 검증 헬퍼 메서드
    // ========================================

    /**
     * PHP 버전 검증
     */
    private function checkPhpVersion(): array
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

    /**
     * PHP 확장 모듈 검증
     *
     * 필수 및 선택적 확장 모듈을 검증합니다.
     * 선택적 모듈은 설치 여부만 표시하며 전체 검증 통과에 영향을 주지 않습니다.
     */
    private function checkPhpExtensions(): array
    {
        $requiredExtensions = REQUIRED_EXTENSIONS;
        $optionalExtensions = defined('OPTIONAL_EXTENSIONS') ? OPTIONAL_EXTENSIONS : [];

        $installed = [];
        $allRequiredPassed = true;

        // 필수 확장 검증
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

        // 선택적 확장 검증 (설치 여부만 표시)
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

    /**
     * 디스크 공간 검증
     */
    private function checkDiskSpace(): array
    {
        $minSpaceMb = MIN_DISK_SPACE_MB;
        $freeSpaceBytes = @disk_free_space(BASE_PATH);

        // disk_free_space() 실패 시
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

    /**
     * 디렉토리 권한 검증
     *
     * functions.php의 공통 함수를 사용하여 권한을 체크합니다.
     */
    private function checkDirectoryPermissions(): array
    {
        // 공통 함수 호출
        $check = checkDirectoryPermissions(REQUIRED_DIRECTORIES);

        // API 응답 형식에 맞게 변환
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

    /**
     * 필수 파일 존재 여부 검증
     *
     * .env 파일이 프로젝트 루트에 존재하는지 확인합니다.
     */
    private function checkRequiredFiles(): array
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

            // 통과 조건: 존재 + 쓰기 가능
            $passed = $exists && $writable;
            if (! $passed) {
                $allPassed = false;
            }

            // 권한 비트/소유자 정보 수집 (ownership_mismatch 판별용)
            $permissions = 'N/A';
            $permsOctal = 0;
            $owner = null;
            if ($exists) {
                $perms = fileperms($fullPath);
                $permissions = substr(sprintf('%o', $perms), -3);
                $permsOctal = octdec($permissions);
                $owner = getFileOwnerName($fullPath);
            }

            // 에러 타입 결정
            // ownership_mismatch: 파일이 존재하고 권한이 0644 이상인데도 쓰기 불가
            //                     → 소유자와 웹서버 실행 사용자 불일치
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

        // base_path 소유자 정보 — .env 파일 생성 시 chgrp 포함 여부 판단용
        // 프로젝트 루트 소유자가 웹서버 실행 사용자와 다른 경우, 생성 직후 ownership_mismatch가
        // 재현되므로 복사 명령 자체에 chgrp + chmod 664를 미리 포함시키기 위함
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

    /**
     * HTTPS 사용 여부 확인
     */
    private function checkHttps(): array
    {
        $isHttps = isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on';

        // HTTP_X_FORWARDED_PROTO 헤더도 확인 (프록시 환경)
        if (! $isHttps && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $isHttps = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }

        return [
            'required' => false, // HTTPS는 선택 사항
            'enabled' => $isHttps,
            'message' => $isHttps
                ? lang('https_enabled')
                : lang('https_disabled'),
        ];
    }

    /**
     * 필수 PHP 함수(exec, proc_open) 비활성화 여부 검증
     */
    private function checkDisabledFunctions(): array
    {
        $requiredFunctions = ['exec', 'proc_open', 'shell_exec'];
        $disabledStr = ini_get('disable_functions');
        $disabledList = array_map('trim', explode(',', $disabledStr));

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

    /**
     * PHP CLI 버전과 웹 PHP 버전 일치 여부 확인
     */
    private function checkPhpCliVersion(): array
    {
        $webVersion = PHP_VERSION;
        $cliVersion = null;
        $cliPath = 'php';

        // exec 사용 가능 여부 먼저 확인
        $disabledStr = ini_get('disable_functions');
        $disabledList = array_map('trim', explode(',', $disabledStr));

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

        if ($returnCode === 0 && !empty($output)) {
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

    /**
     * GET ?action=detect-php
     * 서버에서 사용 가능한 PHP 바이너리 자동 탐색
     */
    private function detectPhpBinaries(): void
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
        $minVersion = MIN_PHP_VERSION;

        // PHP_BINARY 상수
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && !in_array(PHP_BINARY, $checkedPaths, true)) {
            $checkedPaths[] = PHP_BINARY;
            $result = $this->validatePhpPath(PHP_BINARY);
            if ($result['valid']) {
                $found[] = ['path' => PHP_BINARY, 'version' => $result['version']];
            }
        }

        // 공통 경로 스캔
        foreach ($commonPaths as $path) {
            if (in_array($path, $checkedPaths, true)) {
                continue;
            }
            $checkedPaths[] = $path;
            if (!file_exists($path)) {
                continue;
            }
            $result = $this->validatePhpPath($path);
            if ($result['valid']) {
                $found[] = ['path' => $path, 'version' => $result['version']];
            }
        }

        // 시스템 PATH의 'php'
        $defaultPhpAvailable = false;
        if (!in_array('php', $checkedPaths, true)) {
            $result = $this->validatePhpPath('php');
            if ($result['valid']) {
                $found[] = ['path' => 'php', 'version' => $result['version']];
                $defaultPhpAvailable = true;
            }
        } else {
            // 이미 체크된 경우 found 배열에서 확인
            foreach ($found as $bin) {
                if ($bin['path'] === 'php') {
                    $defaultPhpAvailable = true;
                    break;
                }
            }
        }

        // Composer 자동 감지 (감지된 PHP 중 첫 번째로 검증)
        $phpForComposer = !empty($found) ? $found[0]['path'] : 'php';
        $composerResult = $this->detectComposerBinary($phpForComposer);

        echo json_encode([
            'success' => !empty($found),
            'binaries' => $found,
            'default_php_available' => $defaultPhpAvailable,
            'composer' => $composerResult,
            'message' => empty($found) ? lang('no_php_detected') : lang('php_detected_count', ['count' => count($found)]),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Composer 바이너리 자동 감지
     *
     * @param string $phpPath .phar 실행 시 사용할 PHP 경로
     * @return array{found: bool, path: string|null, version: string|null}
     */
    private function detectComposerBinary(string $phpPath = 'php'): array
    {
        // 1. 시스템 PATH의 composer
        $result = $this->validateComposerPath('composer', $phpPath);
        if ($result['valid']) {
            return ['found' => true, 'path' => 'composer', 'version' => $result['version']];
        }

        // 2. 프로젝트 루트의 composer.phar
        $pharPath = realpath(__DIR__ . '/../../..') . '/composer.phar';
        if (file_exists($pharPath)) {
            $result = $this->validateComposerPath($pharPath, $phpPath);
            if ($result['valid']) {
                return ['found' => true, 'path' => $pharPath, 'version' => $result['version']];
            }
        }

        // 3. 현재 디렉토리(public/install/api)의 composer.phar
        $localPhar = realpath(__DIR__) . '/composer.phar';
        if (file_exists($localPhar)) {
            $result = $this->validateComposerPath($localPhar, $phpPath);
            if ($result['valid']) {
                return ['found' => true, 'path' => $localPhar, 'version' => $result['version']];
            }
        }

        return ['found' => false, 'path' => null, 'version' => null];
    }

    /**
     * POST ?action=test-php-binary
     * 지정된 PHP 바이너리 경로의 유효성 검증
     */
    private function testPhpBinary(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->error400('POST method required');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $path = $input['path'] ?? 'php';

        $result = $this->validatePhpPath($path);

        echo json_encode([
            'success' => $result['valid'],
            'version' => $result['version'],
            'message' => $result['message'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST ?action=test-composer
     * Composer 바이너리 경로의 유효성 검증
     */
    private function testComposer(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->error400('POST method required');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $composerPath = trim($input['path'] ?? '');
        $phpPath = trim($input['php_path'] ?? 'php');

        $result = $this->validateComposerPath($composerPath, $phpPath);

        echo json_encode([
            'success' => $result['valid'],
            'version' => $result['version'],
            'message' => $result['message'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET ?action=check-core-pending-path
     * 코어 업데이트 _pending 경로 퍼미션/소유자 체크
     */
    private function checkCorePendingPath(): void
    {
        $path = $_GET['path'] ?? '';
        if (empty($path)) {
            echo json_encode(['success' => false, 'message' => lang('error_path_required')], JSON_UNESCAPED_UNICODE);
            return;
        }

        $absolutePath = str_starts_with($path, '/')
            ? $path
            : BASE_PATH . '/' . $path;

        if (!file_exists($absolutePath)) {
            echo json_encode([
                'success' => false,
                'message' => lang('error_path_not_exists', ['path' => $path]),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!is_dir($absolutePath)) {
            echo json_encode([
                'success' => false,
                'message' => lang('error_core_pending_not_directory'),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $writable = is_writable($absolutePath);
        echo json_encode([
            'success' => $writable,
            'message' => $writable
                ? lang('success_core_pending_path')
                : lang('error_core_pending_not_writable'),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * PHP 바이너리 경로 유효성 검증 헬퍼
     *
     * @param string $path PHP 바이너리 경로
     * @return array{valid: bool, version: string|null, message: string}
     */
    private function validatePhpPath(string $path): array
    {
        if (empty($path)) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_php_path_empty')];
        }

        // file_exists 사전 체크 없이 실행 결과로 판단
        $command = escapeshellarg($path) . ' --version 2>&1';
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

    /**
     * Composer 바이너리 경로 유효성 검증 헬퍼
     *
     * @param string $composerPath Composer 바이너리 경로 (빈 문자열이면 시스템 'composer')
     * @param string $phpPath PHP 바이너리 경로 (.phar 실행 시 사용)
     * @return array{valid: bool, version: string|null, message: string}
     */
    private function validateComposerPath(string $composerPath, string $phpPath = 'php'): array
    {
        // 빈 문자열이면 시스템 기본 composer 사용
        $effectivePath = $composerPath ?: 'composer';

        // 실행 명령어 구성
        if (str_contains($effectivePath, ' ')) {
            // 공백 포함 = 전체 실행 명령어 (예: "/usr/local/php84/bin/php composer.phar")
            // escapeshellarg 없이 그대로 실행
            $command = $effectivePath . ' --version 2>&1';
        } elseif (str_ends_with($effectivePath, '.phar')) {
            // .phar 파일 단독 경로 → PHP 바이너리와 결합
            $command = escapeshellarg($phpPath) . ' ' . escapeshellarg($effectivePath) . ' --version 2>&1';
        } else {
            // composer 바이너리 경로
            $command = escapeshellarg($effectivePath) . ' --version 2>&1';
        }

        $output = [];
        $returnCode = -1;
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

    /**
     * 모든 필수 요구사항 통과 여부 확인
     */
    private function isAllRequiredPassed(array $requirements): bool
    {
        // PHP 버전 확인
        if (! $requirements['php_version']['passed']) {
            return false;
        }

        // PHP 확장 확인
        if (! $requirements['php_extensions']['all_required_passed']) {
            return false;
        }

        // 필수 함수 비활성화 확인
        if (isset($requirements['disabled_functions']) && ! $requirements['disabled_functions']['passed']) {
            return false;
        }

        // 디스크 공간 확인
        if (! $requirements['disk_space']['passed']) {
            return false;
        }

        // 디렉토리 권한 확인
        if (! $requirements['directories']['all_passed']) {
            return false;
        }

        // 필수 파일 확인
        if (! $requirements['required_files']['all_passed']) {
            return false;
        }

        // HTTPS는 선택 사항이므로 검증하지 않음

        return true;
    }

    // ========================================
    // 공통 에러 처리 메서드
    // ========================================

    /**
     * 400 Bad Request 응답
     */
    private function error400(string $message): void
    {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Bad Request',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 500 Internal Server Error 응답
     */
    private function error500(Throwable $e): void
    {
        // 에러 로깅
        if (function_exists('logInstallationError')) {
            logInstallationError('Validation API error', $e);
        }

        // 에러 응답
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
            'details' => $e->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========================================
// 실행 부분
// ========================================

// 필수 파일 로드 (config.php가 BASE_PATH를 정의함)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/session.php';
require_once __DIR__.'/../includes/functions.php';

// 다국어 로드
$currentLang = getCurrentLanguage();
$translations = loadTranslations($currentLang);

// API 인스턴스 생성 및 요청 처리
$api = new ValidationApi;
$api->handleRequest();
