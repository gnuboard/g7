<?php

use App\Support\ExecutionEnvironment;

/**
 * G7 인스톨러 - 검증 통합 API
 *
 * 서버 요구사항 검증 및 데이터베이스 연결 테스트 기능을 하나의 API로 통합
 *
 * 엔드포인트:
 * - GET  ?action=requirements : 서버 요구사항 검증 (action 생략 시 기본값)
 * - POST ?action=test-db      : 데이터베이스 연결 테스트
 */

// 실행 모드 판정 헬퍼 — Step 3 의 실행 방식 선택란이 같은 판정을 본다 (D33).
// 선언 전용 파일이라 출력 채널 가드보다 앞에 둘 수 있고, 아래 가드가 쓰는
// installerHasFunction 을 여기서 정의한다 (21차 N4 의 순서 예외 2파일 중 하나).
require_once __DIR__.'/../includes/execution-mode.php';

// 오류 텍스트가 JSON 본문에 섞이지 않게 한다 (20차 #13 · 21차 N4) — **include 체인보다 앞**.
// 이 파일의 detect-php 는 문서루트 밖 후보 경로를 stat 하므로 open_basedir 환경에서 warning
// 발생이 정상 시나리오다 (#8) — 그 텍스트가 본문 앞에 붙으면 클라이언트의 response.json() 이
// throw 해 PHP 자동 감지가 사유 없이 무동작이 된다.
//
// 라이브러리 모드(단위 테스트) 에서도 무는 자리다. utf8.php 는 선언 전용이 아니라
// 최상위에서 app/Support 클래스를 조건부로 로드하므로, 그 include 를 가드 뒤로 넘기려면
// 채널을 그보다 먼저 열어야 한다. 그 파일이 주는 installer_json_encode 는 라이브러리
// 모드 소비자도 쓰므로 include 자체를 메인 블록 안으로 내릴 수는 없다.
if (installerHasFunction('ini_set')) {
    @ini_set('display_errors', '0');
}
error_reporting(E_ALL);

// 프로세스 출력 UTF-8 정규화 + JSON 인코딩 — 응답 본문을 만드는 유일한 통로다.
require_once __DIR__.'/../includes/utf8.php';
// 실행 바이너리 경로 허용 형태 정책 — 인스톨러 API 와 설치 워커가 같은 규칙을 공유한다.
// 한쪽만 고치면 다른 쪽이 우회로가 되므로 의존성 없는 공용 파일로 두고 양쪽에서 로드한다.
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
        // 판정 본문은 공유 함수 — Step 2 다음 단계 POST 의 서버 재검사·터미널 설치와 같은 결과를 낸다.
        $collected = installer_collect_requirements();

        // 웹 노출 점검(web_exposure)은 웹 전용 항목이라 공유 판정에 없다 — 판정 주체가 브라우저이고
        // (서버 loopback 프로브는 오판) 프로브 대상 산출은 웹 진입만 싣는 exposure-check.php 가 한다.
        // 항목은 화면 순서대로 자산 URL 방식 뒤에 두고, 집계 키(all_required_passed·is_windows·blocking)는 뒤로 보낸다.
        $summaryKeys = array_flip(['all_required_passed', 'is_windows', 'blocking']);
        $requirements = array_diff_key($collected, $summaryKeys);
        $requirements['web_exposure'] = $this->checkWebExposure();
        $requirements += array_intersect_key($collected, $summaryKeys);

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
                // 실패 분류 키 (13차 보안#6) — 드라이버 원문 대신 조치가 갈리는 만큼만 알린다.
                'message_key' => $result['message_key'] ?? null,
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
            // 권한 「확인 불가」 안내와 DB 서버 버전 하한 안내 — 둘 다 차단이 아니다 (20차 #6·#7).
            'privilege_warning' => $result['privilege_warning'] ?? null,
            'db_server_notice' => $result['db_server_notice'] ?? null,
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
     * 웹 노출 점검 항목 정의 (필수 게이트 — 판정은 브라우저가 한다. D3·D21)
     *
     * 공유 함수 `installer_requirement_asset_url_mode()` 와 같은 구조다: 서버 loopback 프로브는 vhost·SSL·프록시 체인을
     * 우회해 실제 방문자가 겪는 것과 다른 답을 내므로, 서버는 프로브 **대상 목록**만
     * 내려주고 실제 HTTP 판정은 브라우저가 수행한다 (`installer.js::refreshWebExposureCard`).
     *
     * - 노출 확정 → 그 항목만 빨강 + [다음 단계] 비활성 (설치 차단 — D3)
     * - 판정 불능(프로브 예외) → 노랑 경고 + 수동 확인 절차(주소 목록) + '확인했음'
     *   체크 후에만 진행 (D21)
     *
     * `detected` 가 null 인 것은 미구현이 아니라 위 이유에 따른 설계다. 서버 집계
     * (`installer_requirements_all_passed()`)에 이 항목이 없는 것도 같은 이유 — 게이트는 브라우저
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
