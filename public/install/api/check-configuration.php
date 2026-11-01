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

// 실행 바이너리 경로 허용 형태 정책 — 인스톨러 API 와 설치 워커가 같은 규칙을 공유한다.
// 한쪽만 고치면 다른 쪽이 우회로가 되므로 의존성 없는 공용 파일로 두고 양쪽에서 로드한다.
require_once __DIR__.'/../includes/utf8.php';
require_once __DIR__.'/../includes/binary-path-policy.php';
// 설치 소유권·CSRF·DB 연결 지문 헬퍼 (의존성 없는 순수 함수)
require_once __DIR__.'/../includes/installer-ownership.php';
// 요구사항·바이너리·DB 검사 본문 — 웹·터미널 공유 함수 (함수 정의만, 로드 시 실행 없음)
require_once __DIR__.'/../includes/requirements.php';
require_once __DIR__.'/../includes/db-check.php';

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
        // 판정 본문은 공유 함수 — Step 2 다음 단계 POST 의 서버 재검사·터미널 설치와 같은 결과를 낸다.
        echo installer_json_encode(installer_collect_requirements(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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

        // DB 테스트는 세션에 "검사 통과" 지문과 기존 테이블 삭제 확인 토큰을 남기는 상태변경이다.
        installer_require_csrf();

        $input = installer_request_json_body();

        if (! $input || ! is_array($input)) {
            echo installer_json_encode([
                'success' => false,
                'message' => lang('api_invalid_request'),
                'type' => 'write',
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        // DB 타입 확인 ('write' 또는 'read')
        $type = ($input['type'] ?? 'write') === 'read' ? 'read' : 'write';
        $isReadDb = ($type === 'read');

        // 연결·권한·기존 테이블 검사 본문은 공유 함수 — 터미널 설치와 같은 판정.
        $result = installer_test_database([
            'host' => $input['host'] ?? 'localhost',
            'port' => $input['port'] ?? '3306',
            'database' => $input['database'] ?? '',
            'username' => $input['username'] ?? '',
            'password' => $input['password'] ?? '',
            'db_prefix' => $input['db_prefix'] ?? '',
        ], $type, getCurrentLanguage());

        if (! $result['success']) {
            // 실패는 "검사 안 됨" 과 같다 — false 를 저장하면 isset 계열 검사가 통과시킨다.
            installer_forget_db_test($isReadDb);

            echo installer_json_encode([
                'success' => false,
                'message' => $result['message'],
                'type' => $type,
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        // 검사 통과 기록 + 검사한 연결설정 지문 — Step 3 제출 때 같은 지문을 다시 계산해 "검사한 설정 = 제출한 설정" 을 확인한다.
        $tested = [
            'host' => $input['host'] ?? 'localhost',
            'port' => $input['port'] ?? '3306',
            'database' => $input['database'] ?? '',
            'username' => $input['username'] ?? '',
            'password' => $input['password'] ?? '',
        ];
        if ($isReadDb) {
            $_SESSION['db_read_tested'] = true;
            $_SESSION['db_read_fp'] = installer_db_fingerprint($tested, installer_owner_token());
        } else {
            $tested['prefix'] = $input['db_prefix'] ?? '';
            $_SESSION['db_write_tested'] = true;
            $_SESSION['db_write_fp'] = installer_db_fingerprint($tested, installer_owner_token());
        }

        // 기존 테이블이 있으면 삭제 확인 토큰을 발급한다 — drop_tables 는 이 토큰과 탐지 테이블 불변이
        // 확인될 때만 실행된다 (요청 본문의 액션 값만으로는 삭제 불가).
        $dropConfirmToken = null;
        if (! $isReadDb) {
            unset($_SESSION['drop_confirm']);
            if (! empty($result['existing_tables']['has_tables'])) {
                $issued = installer_issue_drop_confirm((string) $result['detect_prefix'], $result['prefix_tables']);
                $_SESSION['drop_confirm'] = $issued['record'];
                $dropConfirmToken = $issued['token'];
            }
        }

        echo installer_json_encode([
            'success' => true,
            'message' => $result['message'],
            'type' => $type,
            'privileges' => $result['privileges'],
            'existing_tables' => $result['existing_tables'],
            'drop_confirm' => $dropConfirmToken,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // ========================================
    // 서버 요구사항·바이너리 검사 — 본문은 웹·터미널 공유 함수(includes/requirements.php)가 소유한다.
    // 아래 메서드는 하위 호환 위임이다. 규칙을 바꿀 때는 공유 함수에서 바꾼다.
    // ========================================

    /**
     * 기존 vendor 의 개발용 패키지 포함 여부 (공유 함수 위임)
     *
     * @return array{required: bool, vendor_exists: bool, dev: bool|null, packages: array<int, string>, message: string}
     */
    private function checkVendorDevPackages(): array
    {
        return installer_requirement_vendor_dev_packages();
    }

    /**
     * GET ?action=detect-php
     * 서버에서 사용 가능한 PHP 바이너리 자동 탐색
     */
    private function detectPhpBinaries(): void
    {
        echo installer_json_encode(installer_detect_php_binaries(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
        echo installer_json_encode(installer_check_core_pending_path((string) ($_GET['path'] ?? '')), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 셸 인자로 전달하기 안전한 단일 토큰인지 검증 (공용 정책 위임).
     */
    private function isInstallerSafePathArg(string $path): bool
    {
        return installer_binary_path_shape_ok($path);
    }

    /**
     * 공백 분리 입력을 두 토큰(PHP 인터프리터 + Composer 바이너리) 으로 분해 (공용 정책 위임).
     *
     * @return array{php: string, composer: string}|null 분해 실패 시 null
     */
    private function splitPhpComposerTokens(string $path): ?array
    {
        return installer_resolve_php_composer_pair($path);
    }

    /**
     * PHP 바이너리 경로 유효성 검증 (공유 함수 위임)
     *
     * @param  string  $path  PHP 바이너리 경로
     * @return array{valid: bool, version: string|null, message: string}
     */
    private function validatePhpPath(string $path): array
    {
        return installer_verify_php_binary($path);
    }

    /**
     * Composer 바이너리 경로 유효성 검증 (공유 함수 위임)
     *
     * @param  string  $composerPath  Composer 바이너리 경로 (빈 문자열이면 시스템 'composer')
     * @param  string  $phpPath  PHP 바이너리 경로 (.phar 실행 시 사용)
     * @return array{valid: bool, version: string|null, message: string}
     */
    private function validateComposerPath(string $composerPath, string $phpPath = 'php'): array
    {
        return installer_verify_composer($composerPath, $phpPath);
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
        http_response_code(500);
        echo installer_json_encode([
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

// 라이브러리 모드 — 단위 테스트가 ValidationApi 클래스 정의만 로드할 때 메인 실행을 건너뛴다.
$checkConfigurationLibraryMode = defined('CHECK_CONFIGURATION_LIBRARY') && constant('CHECK_CONFIGURATION_LIBRARY');

if (! $checkConfigurationLibraryMode) {
    // 필수 파일 로드 (config.php가 BASE_PATH를 정의함)
    require_once __DIR__.'/../includes/config.php';
    require_once __DIR__.'/../includes/session.php';
    require_once __DIR__.'/../includes/functions.php';

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
