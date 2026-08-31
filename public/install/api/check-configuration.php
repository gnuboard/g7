<?php

use App\Support\ExecutionEnvironment;
use App\Support\OpcacheStatus;
use App\Support\PhpBinaryResolver;
use App\Support\PrivilegedDatabaseAccounts;

/**
 * G7 인스톨러 - 검증 통합 API
 *
 * 서버 요구사항 검증 및 데이터베이스 연결 테스트 기능을 하나의 API로 통합
 *
 * 엔드포인트:
 * - GET  ?action=requirements : 서버 요구사항 검증 (action 생략 시 기본값)
 * - POST ?action=test-db      : 데이터베이스 연결 테스트
 */

// 실행 바이너리 경로 허용 형태 정책 — 인스톨러 API 와 설치 워커가 같은 규칙을 공유한다.
// 한쪽만 고치면 다른 쪽이 우회로가 되므로 의존성 없는 공용 파일로 두고 양쪽에서 로드한다.
require_once __DIR__.'/../includes/utf8.php';
require_once __DIR__.'/../includes/binary-path-policy.php';
// 실행 모드 판정 헬퍼 — Step 3 의 실행 방식 선택란이 같은 판정을 본다 (D33)
require_once __DIR__.'/../includes/execution-mode.php';

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

        // 설치 창에는 인증이 없다 (13차 보안#6·보안#7). 그 사이 `test-db` 는 임의 host:port 로
        // 나가는 접속 프록시이고 `test-php-binary` 는 임의 절대경로를 `--version` 으로 실행한다 —
        // 정상 설치에는 몇 번이면 충분하므로 창당 호출 수에 상한을 둔다. 상한 판정은
        // execution-mode.php 가 소유하고, 기록에 실패하면 통과시킨다(정상 설치를 막지 않는다).
        $rateLimitedBuckets = [
            'test-db' => 'probe_db',
            'test-php-binary' => 'probe_binary',
            'test-composer' => 'probe_binary',
            'detect-php' => 'probe_binary',
            'detect-execution-mode' => 'probe_binary',
        ];

        if (isset($rateLimitedBuckets[$action]) && installerProbeRateLimitExceeded($rateLimitedBuckets[$action])) {
            http_response_code(429);
            echo installer_json_encode([
                'success' => false,
                'message' => lang('error_probe_rate_limited'),
                'message_key' => 'error_probe_rate_limited',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 세션에 쓰지 않는 액션은 잠금을 먼저 놓는다 (17차 #B2). 이 액션들은 외부 바이너리를
        // 타임아웃 없이 스폰하거나(`detect-php`·`test-composer`·`test-php-binary`) 요구사항
        // 전수를 훑는데, PHP 기본 세션 핸들러는 스크립트 종료까지 세션 파일을 배타 잠근다 —
        // 쥔 채로 오래 돌면 같은 세션의 다른 요청과 새로고침이 `session_start()` 에서 줄을 선다.
        //
        // `test-db` 는 제외한다 — 결과를 `$_SESSION['db_*_tested']` 에 기록하므로 닫으면
        // 그 쓰기가 유실된다. 그쪽은 세션이 **필요한** 유일한 액션이다.
        if ($action !== 'test-db' && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            // action에 따라 적절한 메서드 호출
            match ($action) {
                'requirements' => $this->checkRequirements(),
                'test-db' => $this->testDbConnection(),
                'detect-php' => $this->detectPhpBinaries(),
                'test-php-binary' => $this->testPhpBinary(),
                'test-composer' => $this->testComposer(),
                'detect-execution-mode' => $this->detectExecutionMode(),
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
        // 진단 결과는 절대 캐시되지 않아야 한다 (17차 #B5) — 운영자가 권한·확장을 고치고
        // 다시 검사해도 중간 프록시·브라우저 휴리스틱이 옛 「실패」를 되돌려 줄 수 있다.
        header('Cache-Control: no-store');
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
            'opcache' => $this->checkOpcache(),
            'php_limits' => $this->checkPhpLimits(),
            'asset_url_mode' => $this->checkAssetUrlMode(),
            'web_exposure' => $this->checkWebExposure(),
        ];

        // 모든 필수 요구사항 통과 여부
        $requirements['all_required_passed'] = $this->isAllRequiredPassed($requirements);

        // OS 정보 (프론트엔드에서 명령어 분기용)
        $requirements['is_windows'] = isWindows();

        // JSON 응답 반환
        echo installer_json_encode($requirements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
            echo installer_json_encode([
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

            // DB 최고권한 계정 차단 (Write/Read 공통).
            // 최고권한 계정 자격증명이 유출되면 데이터베이스 전체가 위험해지므로
            // 연결 시도 전에 막는다. 판정은 App\Support\PrivilegedDatabaseAccounts 가 SSoT.
            if (PrivilegedDatabaseAccounts::isBlocked($username)) {
                throw new Exception(
                    lang('error_db_username_privileged', ['username' => $username])
                );
            }

            // DB 테이블 접두사 길이 검증 (Write DB 입력에만 적용)
            // 접두사가 길면 자동 생성 인덱스명이 MySQL identifier 한도(64자)를 초과해
            // 일부 확장 설치 시 마이그레이션이 실패하므로 연결 시도 전에 차단한다.
            if (! $isReadDb) {
                $tablePrefix = (string) ($input['db_prefix'] ?? '');
                if (strlen($tablePrefix) > MAX_DB_PREFIX_LENGTH) {
                    throw new Exception(
                        lang('error_db_prefix_too_long', [
                            'max' => MAX_DB_PREFIX_LENGTH,
                            'current' => strlen($tablePrefix),
                        ])
                    );
                }
            }

            // 데이터베이스 연결 시도
            $pdo = getDatabaseConnection($config, $isReadDb);

            // 권한 검증
            $privileges = checkDatabasePrivileges($pdo, $database, $isReadDb);
            $dbTypeLabel = $isReadDb ? 'Read' : 'Write';
            $privilegeWarning = null;

            // 「확인 불가」는 차단하지 않는다 (제품 결정 §16.26.4 #2) — SHOW GRANTS 거부·롤 전개
            // 실패 환경에서 권한이 실재해도 설치가 원리상 불가능해지던 거짓 차단(20차 #6)의 수정.
            // 실제 부족은 설치 중 해당 단계가 사유와 함께 잡는다 (실패 화면 기정비).
            if (! empty($privileges['indeterminate'])) {
                $privilegeWarning = lang('warning_db_privileges_indeterminate', ['type' => $dbTypeLabel]);
            } elseif (! $privileges['has_all'] && ! empty($privileges['missing'])) {
                // 해석이 확정된 부족만 차단한다.
                $missingPrivs = implode(', ', $privileges['missing']);

                throw new Exception(
                    lang('error_db_privileges_insufficient_detail', ['type' => $dbTypeLabel, 'missing' => $missingPrivs])
                );
            }

            // DB 서버 버전 하한 안내 (20차 #7 — 차단 아님). 조회 실패는 안내 대상이 아니다.
            $dbServerNotice = null;
            try {
                $serverVersionNotice = installerDbServerVersionNotice((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
                if ($serverVersionNotice !== null) {
                    $dbServerNotice = lang('warning_db_server_version_low', [
                        'server' => $serverVersionNotice['server'],
                        'minimum' => $serverVersionNotice['minimum'],
                    ]);
                }
            } catch (PDOException $versionProbeFailure) {
                $dbServerNotice = null;
            }

            // 연결 및 권한 검증 성공 플래그 (Write/Read 구분)
            if ($isReadDb) {
                $_SESSION['db_read_tested'] = true;
                $message = lang('success_db_read_connected');
            } else {
                $_SESSION['db_write_tested'] = true;
                $message = lang('success_db_write_connected');
            }

            // 기존 테이블 감지 (Write DB만 수행)
            // 사용자가 입력한 db_prefix를 g7 시그니처에 적용하여 정확히 비교
            $existingTables = null;
            if (! $isReadDb) {
                $detectPrefix = ($input['db_prefix'] ?? '') !== '' ? (string) $input['db_prefix'] : 'g7_';
                $existingTables = checkExistingTables($pdo, $database, $detectPrefix);
            }

            // 성공 응답
            echo installer_json_encode([
                'success' => true,
                'message' => $message,
                'type' => $type,
                'privileges' => [
                    'has_all' => $privileges['has_all'],
                    'found' => $privileges['found'],
                    'required' => $privileges['required'],
                    'indeterminate' => (bool) ($privileges['indeterminate'] ?? false),
                ],
                'privilege_warning' => $privilegeWarning,
                'db_server_notice' => $dbServerNotice,
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
            logInstallationError(lang('error_db_connection_failed', ['error' => $e->getMessage()]), $e);

            // 에러 응답 (200 OK + success: false).
            // 예외 원문은 싣지 않는다 (13차 보안#6) — 이 엔드포인트는 인증 없이 임의
            // host:port 로 접속을 시도할 수 있어, 원문을 그대로 돌려주면 내부망 포트 상태와
            // DB 서버 버전·자격증명 존재 여부를 알려 주는 오라클이 된다. 대신 조치가 갈리는
            // 만큼만 분류해 사유 키로 돌려준다. 원문은 위 logInstallationError 가 남긴다.
            echo installer_json_encode([
                'success' => false,
                'message' => lang('error_db_connection_failed_detail', [
                    'type' => ($isReadDb ? 'Read' : 'Write'),
                    'error' => lang($this->classifyDbConnectionFailure($e)),
                ]),
                'message_key' => $this->classifyDbConnectionFailure($e),
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
            echo installer_json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'type' => $type ?? 'write',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * DB 연결 실패를 조치가 갈리는 만큼만 분류한다 (13차 보안#6).
     *
     * 운영자에게 필요한 것은 "무엇을 고쳐야 하는가" 지 드라이버 원문이 아니다. 그리고 이
     * 엔드포인트는 인증이 없으므로 원문을 그대로 돌려주면 임의 host:port 의 상태를 읽는
     * 오라클이 된다 — 분류하면 정상 운영자에게 필요한 정보는 유지되고 열거 가치는 사라진다.
     *
     * @param  PDOException  $e  연결 예외
     * @return string 사유 다국어 키
     */
    private function classifyDbConnectionFailure(PDOException $e): string
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $sqlState = (string) ($e->getCode() ?: '');

        if (in_array($driverCode, [1045, 1698], true) || $sqlState === '28000') {
            return 'error_db_failure_credentials';
        }

        if ($driverCode === 1049 || $sqlState === '42000') {
            return 'error_db_failure_unknown_database';
        }

        if (in_array($driverCode, [2002, 2003, 2005, 2006], true)) {
            return 'error_db_failure_unreachable';
        }

        if ($driverCode === 1044) {
            return 'error_db_failure_denied';
        }

        return 'error_db_failure_generic';
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

        // `zip` 은 이 환경이 in-process 로 떨어질 때만 필수다 (13차 환경#4 ②).
        // in-process 는 Vendor 설치 방식이 bundled 로 고정되고(A-4) 그 묶음은 ZipArchive 로
        // 푼다 — 그런데 종전 요구사항 목록에는 zip 이 필수에도 선택에도 없었다. 그 결과
        // Step 2 가 「CLI 없이 설치 진행」 파랑 + passed=true 로 통과시킨 뒤 번들 설치기가
        // 정지했다. subprocess 로 떨어지는 환경은 composer 경로가 있으므로 필수가 아니다.
        // 감지는 PHP 바이너리 probe 를 포함하므로 요청당 한 번만 돈다 (installerDetectExecutionMode 의 정적 캐시).
        $inprocess = installerDetectExecutionMode('php')['mode'] === ExecutionEnvironment::MODE_INPROCESS;
        if ($inprocess && ! in_array('zip', $requiredExtensions, true)) {
            $requiredExtensions[] = 'zip';
            $optionalExtensions = array_values(array_diff($optionalExtensions, ['zip']));
        } elseif (! $inprocess && ! in_array('zip', $optionalExtensions, true) && ! in_array('zip', $requiredExtensions, true)) {
            $optionalExtensions[] = 'zip';
        }

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

        // `disk_free_space` 는 cPanel/CloudLinux/Imunify 의 표준 차단 목록에 `exec` 와 나란히
        // 들어간다 — 이 안이 지원하려는 바로 그 호스팅이다. PHP 8 부터 `disable_functions` 는
        // 함수를 함수 테이블에서 **제거**하므로 `@` 가 Error 를 막지 못하고, 그 Error 는
        // handleRequest() 의 최상위 catch 로 흘러 요구사항 목록이 **통째로** 안 뜬다.
        //
        // 확인 불가는 차단하지 않는다 — checkOpcache 가 쓰는 3상태(`enabled === null`)와 같은
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
        // 디스크는 넉넉한데 설치가 원리상 진행 불가가 되는 거짓 차단(20차 #6 과 같은 형태).
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
        // (21차 #21-7, 제품 결정 §16.27.1 #3 — 차단 기준은 유지하고 경고만 덧붙인다).
        // 종전에는 여유 600MB 서버가 초록으로 통과하고 설치 중반에 디스크가 차서
        // 엉뚱한 오류(composer 쓰기 실패·PDO 예외)로 죽었다.
        $recommendedMb = RECOMMENDED_DISK_SPACE_MB;
        $lowHeadroom = $passed && $freeSpaceMb < $recommendedMb;

        // 경고 문구는 message 에 합성하지 않고 별도 필드로 낸다 (22차 N-5) — 카드 값 슬롯은
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
                : lang('error_directory_not_writable_detail'),
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
     *
     * 이 검사는 저장소에서 유일하게 X-Forwarded-Proto 를 실제로 읽는 지점이었다. 그래서
     * 설치 마법사는 "HTTPS 정상" 이라고 보고하는데 그 직후 앱은 http:// 절대 URL 을 만드는
     * 비대칭이 있었다 — 운영자 입장에서 원인 추적이 사실상 불가능한 조합이다 (#124).
     *
     * 프록시 헤더가 감지되면 신뢰 프록시 설정이 필요하다는 안내를 결과에 덧붙인다.
     * 설치를 차단하지는 않는다 (HTTPS 항목이 `required = false` 인 기존 정책과 동일).
     */
    private function checkHttps(): array
    {
        // 판정은 세션 쿠키 설정과 같은 함수를 쓴다 (13차 환경#17) — 두 벌로 두었더니
        // 한쪽만 프록시를 고려해, TLS 종단이 앞단인 배치에서 secure 쿠키가 붙지 않았다.
        $isHttps = installerRequestIsHttps($_SERVER);

        $forwardedHeaders = $this->detectForwardedHeaders();
        $behindProxy = $forwardedHeaders !== [];

        $message = $isHttps
            ? lang('https_enabled')
            : lang('https_disabled');

        // HTTP 전용 사이트가 프록시 뒤에 있는 구성도 대상이다 — 화면은 정상 렌더되지만
        // 방문자 IP·결제 통보 수신은 그대로 어긋난다. HTTPS 여부로 가르지 않는다.
        if ($behindProxy) {
            $message .= ' '.lang('https_behind_proxy');
        }

        return [
            'required' => false, // HTTPS는 선택 사항
            'enabled' => $isHttps,
            'behind_proxy' => $behindProxy,
            'forwarded_headers' => $forwardedHeaders,
            'message' => $message,
        ];
    }

    /**
     * 수신 중인 X-Forwarded-* 계열 헤더 이름 목록을 반환합니다 (#124).
     *
     * 설치 마법사는 순수 PHP 영역이라 Laravel 헬퍼를 쓸 수 없다. 목록은
     * App\Support\TrustedProxyDiagnostic::FORWARDED_HEADERS 와 같은 집합을 유지한다.
     *
     * @return array<int, string> 수신 중인 헤더 이름 목록
     */
    private function detectForwardedHeaders(): array
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

    /**
     * OPcache 활성화 여부 검증 (권장 사항 — 설치를 차단하지 않음)
     *
     * 확장 로드 여부만으로는 부족하다. `Zend OPcache` 가 로드돼 있어도
     * `opcache.enable=0` 이면 실제로는 동작하지 않으므로 지시자까지 확인한다.
     * 판정은 App\Support\OpcacheStatus 가 SSoT 이며, 코어 관리자 화면과 같은 답을 낸다.
     *
     * `enabled` 가 null 이면 "확인 불가"(ini_get 차단 환경)로, 경고도 차단도 하지 않는다.
     */
    private function checkOpcache(): array
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
            'required' => false, // OPcache는 선택 사항 (성능 권장)
            'loaded' => $status['loaded'],
            'enabled' => $status['enabled'],
            'message' => $message,
        ];
    }

    /**
     * 실행 예산(메모리·시간) 확인 (정보 — 설치를 차단하지 않음)
     *
     * 종전에는 요구사항 목록에 이 두 값이 **하나도 없었다** (13차 환경#8). 128M 공유호스팅
     * 에서 in-process 부팅 + migrate + seed 는 죽는데, 그 사실을 설치 전에 알 방법이 없어
     * 운영자는 요구사항을 전부 통과하고서 중간에 멈추는 설치를 만난다.
     *
     * 차단하지 않는 이유: 두 값 모두 실행 중 조정될 수 있고(`ini_set`·`set_time_limit`),
     * 값이 낮다고 반드시 실패하지도 않는다. 그리고 `ini_get` 자체가 막힌 환경이 있으므로
     * 「확인 불가」 3상태를 함께 둔다 — checkOpcache 와 같은 규칙이다.
     *
     * @return array{required: bool, memory_limit: string|null, memory_limit_bytes: int|null, recommended_memory_mb: int, max_execution_time: string|null, open_basedir: string|null, message: string}
     */
    private function checkPhpLimits(): array
    {
        // 실행 경로의 실제 요구와 같은 값이어야 한다 (20차 #1, 제품 결정 §16.26.4 #3) —
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
        // open_basedir 상태 노출 (20차 #10) — 이 제한은 detect-php·경로 검사·시스템 temp 접근의
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

    /**
     * 자산 URL 방식 항목 정의 (권장 사항 — 설치를 차단하지 않음)
     *
     * 판정 자체는 **서버가 할 수 없다.** 서버에서 자기 APP_URL 로 curl 하면 loopback 이
     * nginx vhost·SSL·프록시 체인을 우회하거나 다른 vhost 를 타서, 실제 방문자가 겪는
     * 것과 다른 답을 낸다. 그래서 여기서는 프로브 경로만 내려주고 실제 판정은
     * 브라우저가 수행한다 (`installer.js::probeAssetUrlMode`).
     *
     * `detected` 가 항상 null 인 것은 미구현이 아니라 위 이유에 따른 설계다.
     * 프론트가 이 값을 받아 카드를 "확인 중" 상태로 먼저 그리고, 프로브가 끝나면 갱신한다.
     */
    private function checkAssetUrlMode(): array
    {
        return [
            'required' => false, // 어느 방식이든 정상 — 통과/실패 게이트가 아니다
            'detected' => null,  // 브라우저 프로브가 채운다 (서버 판정 불가)
            'probe_extension' => '/api/system/asset-probe.js',
            'probe_extensionless' => '/api/system/asset-probe',
        ];
    }

    /**
     * 웹 노출 점검 항목 정의 (필수 게이트 — 판정은 브라우저가 한다. D3·D21)
     *
     * `checkAssetUrlMode` 와 같은 구조다: 서버 loopback 프로브는 vhost·SSL·프록시 체인을
     * 우회해 실제 방문자가 겪는 것과 다른 답을 내므로, 서버는 프로브 **대상 목록**만
     * 내려주고 실제 HTTP 판정은 브라우저가 수행한다 (`installer.js::refreshWebExposureCard`).
     *
     * - 노출 확정 → 그 항목만 빨강 + [다음 단계] 비활성 (설치 차단 — D3)
     * - 판정 불능(프로브 예외) → 노랑 경고 + 수동 확인 절차(주소 목록) + '확인했음'
     *   체크 후에만 진행 (D21)
     *
     * `detected` 가 null 인 것은 미구현이 아니라 위 이유에 따른 설계다. 서버 집계
     * (`isAllRequiredPassed`)에 이 항목이 없는 것도 같은 이유 — 게이트는 브라우저
     * 판정 이후 화면이 잡는다 (Step 2 전환은 원래 클라이언트 게이트다).
     */
    private function checkWebExposure(): array
    {
        $plan = installerExposureProbePlan();

        return [
            'required' => true, // 노출 확정 시 설치 차단 게이트 (D3)
            'detected' => null, // 브라우저 프로브가 채운다 (서버 loopback 판정 금지)
            'targets' => $plan['targets'],
            // 준비에 실패해 **한 번도 재지 못한** 계열 (fail-open 차단). 종전에는 이런 계열이
            // 목록에서 조용히 빠져, 남은 두 계열이 403 이면 화면이 「보호 확인됨」으로 초록이
            // 됐다 — 재지 않은 축까지 안전으로 읽힌 것이다. 하나라도 있으면 판정 불능(D21).
            'unmeasured' => $plan['unmeasured'],
            // 양성 대조 — **열려야 하는** 주소다. 403/404 를 보호로 읽는 판정에서 「막혔다」와
            // 「그 주소에 아무것도 없다」를 가려내지 못하면, 위임이 죽은 서버(mod_rewrite 부재)와
            // 기준 주소를 잘못 잡은 배치가 전부 「보호 확인됨」으로 수렴한다.
            'controls' => $plan['controls'],
            // 음성 대조 — **파일이 없는** 주소다. 레이아웃 ②는 모든 주소를 앱으로 위임하므로
            // 서빙되지 않는 파일도 200 앱 화면으로 돌아온다(실측: `/vendor/autoload.php`·
            // `/storage/...`). 403/404 만 보호로 읽으면 그 정상 배치가 상시 「판정 불능」이 되어
            // D3 자동 차단이 모든 배치에서 D21 로 강등된다. 이 대조와 **같은 형태**로 돌아온
            // 대상은 파일이 서빙되지 않은 것이므로 보호로 읽는다 (양성 대조가 통과한 경우에만).
            'absent_control' => $plan['absent_control'],
            // 링크 가용 실측 (B-4) — 정보 제공용. 링크 불가 시 대체 서빙(B-3)은 런타임이
            // 링크 부재를 감지해 자동 선택하므로 이 값이 설치를 막지 않는다.
            'symlink_supported' => installerProbeSymlinkSupport(),
            // 보호 설정이 사는 곳은 서버마다 다르다 — 동봉 파일이 스스로 적용되는 서버
            // (Apache·LiteSpeed·IIS)와 운영자가 서버 설정에 직접 넣어야 하는 서버
            // (nginx·Caddy)의 조치가 갈린다. 안내 분기에만 쓰고 게이트로 쓰지 않는다.
            'server_kind' => installerDetectServerKind(),
        ];
    }

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
     */
    private function checkDisabledFunctions(): array
    {
        // 감지는 캐시를 거친다 — 본체에 PHP 바이너리 probe 가 들어 있어 이 화면에서 가장 비싼
        // 연산이고, 바로 위 항목(ext-zip 편입)이 이미 같은 감지를 돌렸다. 종전에는 여기서
        // 캐시를 우회해 두 번 돌았고, 「요청당 한 번」이라는 바로 위의 주석과 어긋났다.
        // 두 판정이 갈라질 여지도 함께 없어진다.
        $detection = installerDetectExecutionMode('php');
        $requiredFunctions = ExecutionEnvironment::SUBPROCESS_FUNCTIONS;
        $disabled = $detection['blocked_functions'];

        // 「번들이 있다」가 아니라 「번들을 쓸 수 있다」를 본다 (13차 환경#4) — 푸는 데
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

    /**
     * PHP CLI 버전과 웹 PHP 버전 일치 여부 확인
     */
    private function checkPhpCliVersion(): array
    {
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

        // 버전이 같아도 **설정이 같다는 뜻은 아니다** (13차 환경#7). 멀티 PHP 패널에서 웹은
        // ea-php83, PATH 의 php 는 시스템 php83 인데 php.ini 가 달라 pdo_mysql 이 CLI 에만
        // 없는 조합이 흔하다 — 그 조합에서 요구사항은 전부 초록인데 마이그레이션만 실패한다.
        // 그래서 CLI 쪽 확장 목록·메모리 상한을 함께 조회해 REQUIRED_EXTENSIONS 와 대조한다.
        $cliRuntime = $this->probeCliRuntime($cliPath);
        $cliMissingExtensions = [];
        // 상수는 config.php 가 정의한다 — 이 클래스만 로드하는 진입점(라이브러리 모드)에서는
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

    /**
     * CLI 바이너리의 런타임 설정을 조회한다 (13차 환경#7).
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
    private function probeCliRuntime(string $binary): array
    {
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
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && ! in_array(PHP_BINARY, $checkedPaths, true)) {
            $checkedPaths[] = PHP_BINARY;
            $result = $this->validatePhpPath(PHP_BINARY);
            if ($result['valid']) {
                $found[] = ['path' => PHP_BINARY, 'version' => $result['version'], 'sapi' => $result['sapi'] ?? null];
            }
        }

        // 공통 경로 스캔
        foreach ($commonPaths as $path) {
            if (in_array($path, $checkedPaths, true)) {
                continue;
            }
            $checkedPaths[] = $path;
            // 후보는 문서루트 **밖** 시스템 경로다 — open_basedir 환경에서 이 stat 은
            // warning 을 내며 false 를 돌려준다. 판정(건너뜀)은 그대로 옳으므로 진단만
            // 억제한다 (20차 #8). 억제하지 않으면 그 텍스트가 JSON 본문을 오염시킨다.
            if (! @file_exists($path)) {
                continue;
            }
            $result = $this->validatePhpPath($path);
            if ($result['valid']) {
                $found[] = ['path' => $path, 'version' => $result['version'], 'sapi' => $result['sapi'] ?? null];
            }
        }

        // 시스템 PATH의 'php'
        $defaultPhpAvailable = false;
        if (! in_array('php', $checkedPaths, true)) {
            $result = $this->validatePhpPath('php');
            if ($result['valid']) {
                $found[] = ['path' => 'php', 'version' => $result['version'], 'sapi' => $result['sapi'] ?? null];
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

        // CLI 후보를 앞으로 보낸다 — 화면이 첫 후보를 입력란에 자동으로 채우므로(3-configuration.php),
        // 정렬하지 않으면 웹 SAPI 바이너리(PHP_BINARY 는 mod_php/FPM 에서 그 자신이다)가 그대로
        // 실행 경로로 굳는다. 그 바이너리는 artisan 을 받지 못해 전 태스크가 실패한다.
        $found = $this->orderPhpBinaryCandidates($found);

        // Composer 자동 감지 (감지된 PHP 중 첫 번째로 검증)
        $phpForComposer = ! empty($found) ? $found[0]['path'] : 'php';
        $composerResult = $this->detectComposerBinary($phpForComposer);

        echo installer_json_encode([
            'success' => ! empty($found),
            'binaries' => $found,
            'default_php_available' => $defaultPhpAvailable,
            'composer' => $composerResult,
            'message' => empty($found) ? lang('no_php_detected') : lang('php_detected_count', ['count' => count($found)]),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 감지된 PHP 후보를 CLI 우선으로 정렬한다 (코어 정렬 규칙 공유).
     *
     * 순수 함수로 떼어 둔다 — 탐색은 실제 프로세스를 스폰하므로 개발 환경에서는 CLI 후보만
     * 나오고, 정렬을 지워도 탐색 경로 테스트는 통과한다.
     *
     * @param  array<int, array{path: string, version: string|null, sapi: string|null}>  $found
     * @return array<int, array{path: string, version: string|null, sapi: string|null}>
     */
    private function orderPhpBinaryCandidates(array $found): array
    {
        return PhpBinaryResolver::sortCliFirst($found);
    }

    /**
     * Composer 바이너리 자동 감지
     *
     * @param  string  $phpPath  .phar 실행 시 사용할 PHP 경로
     * @return array{found: bool, path: string|null, version: string|null}
     */
    private function detectComposerBinary(string $phpPath = 'php'): array
    {
        // 1. 시스템 PATH의 composer
        $result = $this->validateComposerPath('composer', $phpPath);
        if ($result['valid']) {
            return ['found' => true, 'path' => 'composer', 'version' => $result['version']];
        }

        // 2. 프로젝트 루트의 composer.phar.
        // `realpath` 가 막힌 환경 (13차 환경#1) — BASE_PATH 로 대체한다(같은 값을 가리킨다).
        $pharPath = (installerHasFunction('realpath') ? (realpath(__DIR__.'/../../..') ?: BASE_PATH) : BASE_PATH).'/composer.phar';
        if (file_exists($pharPath)) {
            $result = $this->validateComposerPath($pharPath, $phpPath);
            if ($result['valid']) {
                return ['found' => true, 'path' => $pharPath, 'version' => $result['version']];
            }
        }

        // 3. 현재 디렉토리(public/install/api)의 composer.phar
        $localPhar = (installerHasFunction('realpath') ? (realpath(__DIR__) ?: __DIR__) : __DIR__).'/composer.phar';
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

        echo installer_json_encode([
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

        echo installer_json_encode([
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
            echo installer_json_encode(['success' => false, 'message' => lang('error_path_required')], JSON_UNESCAPED_UNICODE);

            return;
        }

        // 경로 traversal 및 NUL 바이트 거부.
        // 정상 사용자는 BASE_PATH 하위의 _pending 또는 자기 디스크의 절대경로만 입력하므로
        // 상대경로 부모 추적이 필요한 시나리오가 없다.
        $hasParentSegment = preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $path) === 1;
        if ($hasParentSegment || str_contains($path, "\0")) {
            echo installer_json_encode([
                'success' => false,
                'message' => lang('error_core_pending_path_invalid'),
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        $candidatePath = (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1)
            ? $path
            : BASE_PATH.DIRECTORY_SEPARATOR.$path;

        // `realpath` 가 막혀 있으면 정규화 없이 판정한다 (13차 환경#1) — 위에서 상위 경로
        // 세그먼트(`..`)와 NUL 을 이미 거부했으므로 traversal 방어는 그대로 성립한다.
        $resolved = installerHasFunction('realpath') ? @realpath($candidatePath) : $candidatePath;

        // 운영자가 임의 절대경로를 입력하는 칸이라 open_basedir 밖 입력이 정상 시나리오다 —
        // stat 의 판정(false)은 옳고 warning 만 억제한다 (20차 #9, 위 realpath 와 같은 규칙).
        if ($resolved === false || ! @is_dir($resolved)) {
            // 존재 여부/타입 차이를 응답으로 분기하지 않고 단일 메시지로 통일하여
            // 임의 경로 enumeration 신호 차단.
            echo installer_json_encode([
                'success' => false,
                'message' => lang('error_core_pending_path_invalid'),
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        $writable = @is_writable($resolved);
        echo installer_json_encode([
            'success' => $writable,
            'message' => $writable
                ? lang('success_core_pending_path')
                // 이 키는 :path 자리표시자를 갖는다 — 치환값 없이 부르면 운영자 화면에
                // `디렉토리(:path)에 …` 가 그대로 나가 어느 경로인지가 사라진다.
                : lang('error_core_pending_not_writable', ['path' => $resolved]),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 셸 인자로 전달하기 안전한 단일 토큰인지 검증.
     *
     * 셸 메타문자(공백·따옴표·리다이렉션·세미콜론·백틱·$()·| 등) 와 제어문자(NUL/CR/LF 등)
     * 가 없어야 함. 백슬래시(`\`) 는 Windows 경로 구분자이므로 차단 대상이 아니다 —
     * 셸 인젝션 차단은 호출자의 escapeshellarg 가 담당 (Windows 는 큰따옴표 wrapping,
     * Unix 는 작은따옴표 wrapping).
     *
     * 파일 시스템 stat 은 호출하지 않는다 — open_basedir 등 PHP 런타임 제약
     * 환경에서 정상 절대경로가 false negative 로 거부되는 회귀를 피하기 위함.
     * 실제 실행 가능 여부는 exec/proc_open 결과로 최종 판정.
     */
    private function isInstallerSafePathArg(string $path): bool
    {
        return installer_binary_path_shape_ok($path);
    }

    /**
     * 공백 분리 입력을 두 토큰(PHP 인터프리터 + Composer 바이너리) 으로 분해.
     *
     * 멀티 PHP 버전 환경(시놀로지 DSM Web Station, cPanel/Plesk multi-PHP) 에서
     * `composer` 를 특정 PHP 로 실행하려는 운영 의도를 지원한다.
     * 두 토큰 모두 isInstallerSafePathArg 통과해야 정상 입력으로 인정.
     *
     * @return array{php: string, composer: string}|null 분해 실패 시 null
     */
    private function splitPhpComposerTokens(string $path): ?array
    {
        return installer_resolve_php_composer_pair($path);
    }

    /**
     * POST ?action=detect-execution-mode
     * Step 3 실행 방식 선택란의 감지 결과 (D33 · W2)
     *
     * 운영자가 입력한 php_binary 기준으로 환경을 다시 감지해 — auto 가 어느 모드로
     * 떨어지는지, subprocess 를 고를 수 있는지(막혀 있으면 그 사유), inprocess 에서
     * 설치가 성립하는지(동봉 묶음 존재) — 화면이 배지와 안내를 그릴 재료를 돌려준다.
     * 판정 본체는 ExecutionEnvironment 가 SSoT 이고, 이 값은 표시용이다 — 저장 시점
     * (request-handler) 과 설치 시작 시점(install-process) 이 각각 다시 판정한다.
     */
    private function detectExecutionMode(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->error400('POST method required');
        }

        $input = json_decode((string) file_get_contents('php://input'), true);
        // 경로 정규화와 「되돌렸는가」 판정은 execution-mode.php 가 소유한다 (§4.1 판정 SSoT).
        $resolvedBinary = installerDetectionPhpBinary((string) (is_array($input) ? ($input['php_binary'] ?? 'php') : 'php'));
        $phpBinary = $resolvedBinary['binary'];
        $binarySubstituted = $resolvedBinary['substituted'];

        $detection = installerDetectExecutionMode($phpBinary);
        $subprocessBlocked = $detection['mode'] === ExecutionEnvironment::MODE_INPROCESS;

        echo installer_json_encode([
            'success' => true,
            'detected_mode' => $detection['mode'],
            'php_binary' => $detection['php_binary'],
            'php_binary_substituted' => $binarySubstituted,
            'blocked_functions' => $detection['blocked_functions'],
            'php_probe' => $detection['php_probe'],
            'bundle_available' => $detection['bundle_available'],
            // 화면의 「고를 수 있는가」는 **저장 게이트와 같은 판정**을 쓴다 (19차 S-6).
            //
            // 종전에는 `mode !== inprocess` 였다. 그런데 저장 게이트
            // (`installerExecutionModeRejection`)는 **필수 함수가 막혔을 때만** subprocess 를
            // 거부하고, PHP probe 실패는 일부러 막지 않는다(A-0 ③ — 자동 탐색 폴백이 남아 있고
            // 그 불확실은 subprocess 로 둔다). 그래서 exec 은 되는데 지정 php 경로 probe 만
            // 실패하는 서버에서, 서버는 그 선택을 **받아들이는데** 화면이 카드를 잠갔다 —
            // A-4 가 없앤 비대칭(화면과 저장이 다른 규칙)의 역방향이다.
            'subprocess_selectable' => installerExecutionModeRejection(
                ExecutionEnvironment::MODE_SUBPROCESS,
                $detection
            ) === null,
            'reasons' => installerExecutionModeReasons($detection),
            'message' => $subprocessBlocked
                ? lang('execution_mode_detected_inprocess')
                : lang('execution_mode_detected_subprocess'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * PHP 바이너리 경로 유효성 검증 헬퍼
     *
     * @param  string  $path  PHP 바이너리 경로
     * @return array{valid: bool, version: string|null, message: string}
     */
    private function validatePhpPath(string $path): array
    {
        // 이 판정은 `{binary} --version` 을 실제로 돌려서 내린다 — 실행 함수가 막힌 환경
        // (= 이 안이 지원하려는 대표 환경)에서 가드 없이 부르면 `exec()` 자체가 정의되지 않아
        // Error 가 나고, 라우터의 catch 가 그 개발자 원문을 화면에 띄운다 (10차 #M5).
        // 가용성 판정은 코어와 공유하는 SSoT 가 소유한다(disable_functions 까지 본다).
        if (! ExecutionEnvironment::hasFunction('exec')) {
            return ['valid' => false, 'version' => null, 'sapi' => null, 'message' => lang('error_binary_probe_unavailable')];
        }

        if (empty($path)) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_php_path_empty')];
        }

        // 'php' 기본값이 아니면 실행 경로 형태 규칙을 적용한다(이름은 제한하지 않는다 —
        // 이 자리는 인자가 `--version` 으로 고정되어 있고 설치 환경마다 이름이 다르다).
        // 파일 존재/실행 가능 검사는 open_basedir 같은 PHP 런타임 제약 환경의
        // false negative 를 피하기 위해 생략하고, exec 결과로 최종 판정한다.
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

    /**
     * Composer 바이너리 경로 유효성 검증 헬퍼
     *
     * @param  string  $composerPath  Composer 바이너리 경로 (빈 문자열이면 시스템 'composer')
     * @param  string  $phpPath  PHP 바이너리 경로 (.phar 실행 시 사용)
     * @return array{valid: bool, version: string|null, message: string}
     */
    private function validateComposerPath(string $composerPath, string $phpPath = 'php'): array
    {
        // PHP 경로 판정과 같은 이유의 가드 (10차 #M5) — 이 함수도 `--version` 을 실제로 돌린다.
        if (! ExecutionEnvironment::hasFunction('exec')) {
            return ['valid' => false, 'version' => null, 'message' => lang('error_binary_probe_unavailable')];
        }

        // 빈 문자열이면 시스템 기본 composer 사용
        $effectivePath = $composerPath ?: 'composer';

        // 공백 분리 입력은 "PHP 절대경로 + Composer 절대경로" 의 멀티 PHP 운영 패턴.
        // 두 토큰으로 분해 후 각 토큰별 메타문자 차단 + 각각 escapeshellarg 적용한다.
        // 옛 raw shell 전달(escape 없는 분기) 은 복원하지 않음.
        if ($effectivePath !== 'composer' && str_contains($effectivePath, ' ')) {
            // 자리별 규칙(PHP 자리=형태만, Composer 자리=이름 형태까지)은 공용 정책이 담당한다.
            $tokens = $this->splitPhpComposerTokens($effectivePath);
            if ($tokens === null) {
                return [
                    'valid' => false,
                    'version' => null,
                    'message' => lang('error_composer_binary_path_not_allowed', ['path' => $effectivePath]),
                ];
            }

            $command = escapeshellarg($tokens['php']).' '.escapeshellarg($tokens['composer']).' --version 2>&1';

            $output = [];
            $returnCode = -1;
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

        // 단일 토큰 — 시스템 기본('composer') 가 아니면 Composer 자리 규칙을 적용한다.
        // 파일 존재/실행 가능 검사는 open_basedir 환경의 false negative 회피를 위해 생략.
        if ($effectivePath !== 'composer' && ! installer_is_composer_binary_path($effectivePath)) {
            return [
                'valid' => false,
                'version' => null,
                'message' => lang('error_composer_binary_path_not_allowed', ['path' => $effectivePath]),
            ];
        }

        // .phar 파일이면 PHP 바이너리와 결합
        if (str_ends_with(strtolower($effectivePath), '.phar')) {
            // phpPath 는 실행 파일 자리 — 이름은 제한하지 않고 형태 규칙만 적용한다.
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

        $output = [];
        $returnCode = -1;
        // root/super user 환경 + 비대화형 컨텍스트에서 composer interactive 경고로 인한
        // 비정상 종료를 차단 (Synology DSM 등 PHP-FPM root 실행 환경 대응)
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

        // HTTPS / OPcache 는 선택 사항이므로 검증하지 않음 (경고만 표시하고 설치는 진행)

        // 웹 노출 점검(web_exposure)은 required 지만 이 집계에 넣지 않는다 — 판정 주체가
        // 브라우저(서버 loopback 프로브는 오판)라 서버는 판정값을 모른다. 노출/판정 불능의
        // [다음 단계] 게이팅은 화면이 프로브 결과로 잡는다 (checkWebExposure 주석 · D3 · D21).

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
        echo installer_json_encode([
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
        //
        // `message` 는 화면이 **제목**으로 그리는 자리다 — 예외 원문을 그대로 실으면
        // 「Call to undefined function exec()」 같은 개발자 문구가 설치 화면의 안내가 된다
        // (§12.0 W3: 진행·안내 문구는 사람 말). 원문은 진단 상세(`details`)에만 싣고,
        // 제목은 번역된 일반 문구로 고정한다. 로그에는 예외가 그대로 남는다(위 logInstallationError).
        http_response_code(500);
        echo installer_json_encode([
            'success' => false,
            'error' => 'Internal Server Error',
            'message' => lang('error_unexpected_exception'),
            'message_key' => 'error_unexpected_exception',
            'details' => $e->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========================================
// 실행 부분
// ========================================

// 라이브러리 모드 — 단위 테스트가 ValidationApi 클래스 정의만 로드할 때 메인 실행을 건너뛴다.
$checkConfigurationLibraryMode = defined('CHECK_CONFIGURATION_LIBRARY') && constant('CHECK_CONFIGURATION_LIBRARY');

if (! $checkConfigurationLibraryMode) {
    // 오류 텍스트가 JSON 본문에 섞이지 않게 한다 (20차 #13 · 21차 N4) — **include 체인·가드보다
    // 앞**. 이 파일의 detect-php 는 문서루트 밖 후보 경로를 stat 하므로 open_basedir 환경에서
    // warning 발생이 정상 시나리오다 (#8) — 그 텍스트가 본문 앞에 붙으면 클라이언트의
    // response.json() 이 throw 해 PHP 자동 감지가 사유 없이 무동작이 된다.
    // installerHasFunction 은 파일 상단의 execution-mode.php require 가 이미 정의했다.
    if (installerHasFunction('ini_set')) {
        @ini_set('display_errors', '0');
    }
    error_reporting(E_ALL);

    // 필수 파일 로드 (config.php가 BASE_PATH를 정의함)
    require_once __DIR__.'/../includes/config.php';
    require_once __DIR__.'/../includes/session.php';
    require_once __DIR__.'/../includes/functions.php';
    // 웹 노출 자가 진단 (B-4) — 프로브 대상 산출 + symlink 실측. 선언 전용 파일이지만
    // 출력 채널 가드(위)보다 뒤에 배선한다 (21차 N4 규약 — 예외 목록을 늘리지 않는다).
    require_once __DIR__.'/../includes/exposure-check.php';

    // 설치 완료 시 인스톨러 비즈니스 로직 진입 차단
    require_once __DIR__.'/_guard.php';
    installer_guard_or_410();

    // 다국어 로드
    $currentLang = getCurrentLanguage();
    $translations = loadTranslations($currentLang);

    // API 인스턴스 생성 및 요청 처리
    $api = new ValidationApi;
    $api->handleRequest();
}
