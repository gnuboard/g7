<?php

/**
 * 세션 관리 및 CSRF 토큰 처리
 *
 * 그누보드7 웹 인스톨러의 세션 관리와 CSRF 보호 기능을 제공합니다.
 *
 * installer-state.php 를 require 하여 addLog() 함수를 사용 가능하게 한다.
 * index.php 및 일부 API 엔드포인트가 본 파일을 installer-state.php 보다 먼저
 * require 하기 때문에, 본 파일 안에서 addLog 호출이 필요한 경우를 위해
 * 명시적으로 의존성을 선언한다 (이슈 #371).
 */

require_once __DIR__ . '/installer-state.php';

// 세션이 이미 시작되지 않았으면 시작
if (session_status() === PHP_SESSION_NONE) {
    // 세션 설정
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    // SameSite=Lax — top-level navigation 의 동일 사이트 POST 에 쿠키 동반 전송.
    // CSRF 방어는 별도 csrf_token 검증이 담당하므로 Strict 대신 Lax 로 완화하여
    // 비표준 포트 / dynamic DNS 도메인 / 일부 브라우저 정책 조합에서 PHPSESSID
    // 가 차단되어 Step 0 무한 루프에 빠지던 회귀를 차단.
    ini_set('session.cookie_samesite', 'Lax');

    // HTTPS 환경에서는 secure 쿠키 사용
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');
    }

    session_start();

    // 언어 초기화 우선순위: state.json > 브라우저 언어 > 기본값(ko)
    // 주의: localStorage는 클라이언트에서만 접근 가능하므로 0-welcome.php에서 동기화 처리
    if (!isset($_SESSION['g7_locale'])) {
        $detectedLang = null;

        // 1. state.json에서 복원 시도 (다른 브라우저에서 재개 지원)
        $stateFile = dirname(__DIR__, 3) . '/storage/installer-state.json';
        if (file_exists($stateFile) && is_readable($stateFile)) {
            $stateContent = @file_get_contents($stateFile);

            if ($stateContent !== false) {
                $state = json_decode($stateContent, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($state['g7_locale'])) {
                    $detectedLang = $state['g7_locale'];
                    $msg = "[세션 초기화] state.json에서 언어 복원: {$detectedLang}";
                    error_log($msg);
                    addLog($msg);
                }
            }
        }

        // 2. state.json에 없으면 브라우저 언어 감지
        if ($detectedLang === null) {
            require_once __DIR__ . '/functions.php';
            $browserLang = detectBrowserLanguage();

            if ($browserLang !== null) {
                $detectedLang = $browserLang;
                $msg = "[세션 초기화] 브라우저에서 언어 감지: {$browserLang}";
                error_log($msg);
                addLog($msg);
            }
        }

        // 3. 최종 기본값
        $_SESSION['g7_locale'] = $detectedLang ?? 'ko';
        $msg = "[세션 초기화] 최종 설정 언어: {$_SESSION['g7_locale']}";
        error_log($msg);
        addLog($msg);
    } else {
        $msg = "[세션 초기화] g7_locale 이미 존재: {$_SESSION['g7_locale']}";
        error_log($msg);
        addLog($msg);
    }
}

/**
 * CSRF 토큰 생성
 *
 * 세션에 CSRF 토큰이 없으면 새로 생성합니다.
 *
 * @return string 생성된 CSRF 토큰
 */
function generateCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * CSRF 토큰 반환
 *
 * 현재 세션의 CSRF 토큰을 반환합니다.
 * 토큰이 없으면 새로 생성합니다.
 *
 * @return string CSRF 토큰
 */
function getCsrfToken(): string
{
    return generateCsrfToken();
}

/**
 * CSRF 토큰 검증
 *
 * POST 요청의 csrf_token과 세션의 토큰을 비교하여 검증합니다.
 * 검증 실패 시 403 에러를 반환하고 스크립트를 종료합니다.
 *
 * @return bool 검증 성공 여부 (성공 시 true, 실패 시 스크립트 종료)
 */
function verifyCsrfToken(): bool
{
    // POST 요청이 아니면 검증하지 않음
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }

    // POST 데이터에서 CSRF 토큰 가져오기
    $requestToken = $_POST['csrf_token'] ?? '';

    // 세션의 CSRF 토큰
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    // 토큰 비교 (타이밍 공격 방지를 위해 hash_equals 사용)
    if (!hash_equals($sessionToken, $requestToken)) {
        // 토큰 불일치 시 403 에러
        http_response_code(403);

        // 다국어 메시지 (언어 설정이 있으면 사용)
        $lang = $_SESSION['g7_locale'] ?? 'ko';
        $errorMessages = [
            'ko' => 'CSRF 토큰이 유효하지 않습니다. 페이지를 새로고침한 후 다시 시도해주세요.',
            'en' => 'Invalid CSRF token. Please refresh the page and try again.',
        ];

        $message = $errorMessages[$lang] ?? $errorMessages['ko'];

        // JSON 응답 또는 HTML 응답
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $message,
            ]);
        } else {
            // 403 에러 페이지 템플릿 표시
            include __DIR__ . '/../views/403-forbidden.php';
        }

        exit;
    }

    return true;
}

/**
 * 세션 데이터 저장
 *
 * 세션에 키-값 쌍을 저장합니다.
 *
 * @param string $key 키
 * @param mixed $value 값
 * @return void
 */
function setSessionData(string $key, $value): void
{
    $_SESSION[$key] = $value;
}

/**
 * 세션 데이터 조회
 *
 * 세션에서 키에 해당하는 값을 조회합니다.
 *
 * @param string $key 키
 * @param mixed $default 기본값 (키가 없을 때 반환)
 * @return mixed 세션 값 또는 기본값
 */
function getSessionData(string $key, $default = null)
{
    return $_SESSION[$key] ?? $default;
}

/**
 * 세션 데이터 삭제
 *
 * 세션에서 특정 키를 삭제합니다.
 *
 * @param string $key 키
 * @return void
 */
function deleteSessionData(string $key): void
{
    if (isset($_SESSION[$key])) {
        unset($_SESSION[$key]);
    }
}

/**
 * 세션 초기화
 *
 * 모든 세션 데이터를 삭제하고 세션을 종료합니다.
 *
 * @return void
 */
function destroySession(): void
{
    $_SESSION = [];

    // 세션 쿠키도 삭제
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}
