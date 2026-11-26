<?php

/**
 * 세션 관리
 *
 * 그누보드7 웹 인스톨러의 세션을 시작하고 로케일을 초기화합니다.
 *
 * installer-state.php 를 require 하여 addLog() 함수를 사용 가능하게 한다.
 * index.php 및 일부 API 엔드포인트가 본 파일을 installer-state.php 보다 먼저
 * require 하기 때문에, 본 파일 안에서 addLog 호출이 필요한 경우를 위해
 * 명시적으로 의존성을 선언한다.
 *
 * execution-mode.php 는 두 가지 판정의 SSoT 라서 함께 로드한다 (13차):
 *  - `installerIniSet()` — `ini_set` 이 막힌 호스팅에서 이 파일이 첫 줄부터 죽지 않게 한다.
 *  - `installerRequestIsHttps()` — 프록시 뒤 HTTPS 판정을 요구사항 화면과 공유한다.
 */

require_once __DIR__.'/installer-state.php';
require_once __DIR__.'/execution-mode.php';

// 세션이 이미 시작되지 않았으면 시작
if (session_status() === PHP_SESSION_NONE) {
    // 세션 설정.
    // `ini_set` 은 호스팅이 흔히 막는 함수이고 이 파일은 인스톨러 **모든 페이지의 최상단**
    // 이다 — 무가드로 부르면 봉투가 서기도 전에 치명 종료해 첫 화면이 백지가 된다.
    // 이 설정들이 적용되지 않아도 세션 자체는 뜬다.
    installerIniSet('session.cookie_httponly', '1');
    installerIniSet('session.use_strict_mode', '1');
    // SameSite=Lax — top-level navigation 의 동일 사이트 POST 에 쿠키 동반 전송.
    // 비표준 포트 / dynamic DNS 도메인 / 일부 브라우저 정책 조합에서 PHPSESSID 가 차단되어
    // Step 0 무한 루프에 빠지던 회귀를 차단하기 위해 Strict 대신 Lax 를 쓴다.
    //
    // CSRF 방어는 `installerCrossOriginRejection()`(execution-mode.php)의 Origin/Referer
    // 검사가 담당한다 — 상태 변경 엔드포인트 전부가 그 판정을 공유한다. 종전 주석은 「별도
    // csrf_token 검증이 담당한다」고 적혀 있었으나 그 검증은 정의만 있고 호출부가 저장소
    // 전체에 0건이었다: 완화의 근거가 실재하지 않는 상태였다.
    installerIniSet('session.cookie_samesite', 'Lax');

    // HTTPS 환경에서는 secure 쿠키 사용.
    // 판정은 요구사항 화면과 같은 함수를 쓴다 — 종전에는 이 자리만
    // `$_SERVER['HTTPS']` 를 직접 봐서, TLS 종단이 앞단에 있는 표준 배치에서 secure 가 붙지 않았다.
    if (installerRequestIsHttps($_SERVER)) {
        installerIniSet('session.cookie_secure', '1');
    }

    session_start();

    // 언어 초기화 우선순위: state.json > 브라우저 언어 > 기본값(ko)
    // 주의: localStorage는 클라이언트에서만 접근 가능하므로 0-welcome.php에서 동기화 처리
    if (! isset($_SESSION['g7_locale'])) {
        $detectedLang = null;

        // 1. state.json에서 복원 시도 (다른 브라우저에서 재개 지원)
        //
        // 경로는 installer-state.php 의 STATE_PATH 가 SSoT 다. 이 파일은 그보다 먼저 로드될 수
        // 있어 상수가 없을 때만 같은 규칙으로 역산한다 — 리터럴을 두 곳에 두면 설치 대상 루트가
        // 문서 루트와 갈리는 배치(G3)에서 **세션 로케일 복구만** 조용히 다른 파일을 본다 (10차).
        $stateFile = defined('STATE_PATH')
            ? STATE_PATH
            : dirname(__DIR__, 3).'/storage/installer-state.json';
        if (file_exists($stateFile) && is_readable($stateFile)) {
            $stateContent = @file_get_contents($stateFile);

            if ($stateContent !== false) {
                $state = json_decode($stateContent, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($state['g7_locale'])) {
                    $detectedLang = $state['g7_locale'];
                    $msg = "[세션 초기화] state.json에서 언어 복원: {$detectedLang}";
                    installerErrorLog($msg);
                    addLog($msg);
                }
            }
        }

        // 2. state.json에 없으면 브라우저 언어 감지
        if ($detectedLang === null) {
            require_once __DIR__.'/functions.php';
            $browserLang = detectBrowserLanguage();

            if ($browserLang !== null) {
                $detectedLang = $browserLang;
                $msg = "[세션 초기화] 브라우저에서 언어 감지: {$browserLang}";
                installerErrorLog($msg);
                addLog($msg);
            }
        }

        // 3. 최종 기본값
        $_SESSION['g7_locale'] = $detectedLang ?? 'ko';
        $msg = "[세션 초기화] 최종 설정 언어: {$_SESSION['g7_locale']}";
        installerErrorLog($msg);
        addLog($msg);
    } else {
        $msg = "[세션 초기화] g7_locale 이미 존재: {$_SESSION['g7_locale']}";
        installerErrorLog($msg);
        addLog($msg);
    }
}

/*
 * CSRF 토큰 헬퍼(generateCsrfToken / getCsrfToken / verifyCsrfToken)는 제거했다.
 *
 * 세 함수 모두 정의만 있고 호출부가 저장소 전체에 0건이었다 — 그런데 위 SameSite 완화 주석은
 * 그 검증이 CSRF 를 막는다고 적혀 있어, 실재하지 않는 방어를 근거로 완화가 유지돼 왔다.
 * 실제 방어는 `installerCrossOriginRejection()` 의 Origin/Referer 검사이며, 상태 변경
 * 엔드포인트가 그 한 판정을 공유한다. **그 엔드포인트 목록은 여기에 적지 않는다** —
 * 계약 검사(`InstallerEntryGateWiringTest`)가 목록과 역방향 도출을 함께 소유하고, 여기
 * 열거는 파일이 늘어도 따라오지 않아 이미 한 차례 낡아 있었다(`public/install/api/run-task.php` 가 그
 * 판정을 부르게 된 뒤에도 다섯으로 남아 있었다). 죽은 헬퍼를 남겨 두면 다음 라운드가
 * 다시 「방어가 있다」고 읽는다.
 */

/**
 * 세션 데이터 저장
 *
 * 세션에 키-값 쌍을 저장합니다.
 *
 * @param  string  $key  키
 * @param  mixed  $value  값
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
 * @param  string  $key  키
 * @param  mixed  $default  기본값 (키가 없을 때 반환)
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
 * @param  string  $key  키
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

    // 세션 쿠키도 삭제. `ini_get` 이 차단된 호스팅에서는 이 판정 자체가 치명 종료이므로
    // 가드한다 — 죽으면 「처음부터 다시」가 통째로 500 이 되어 복구 수단이 사라진다.
    // 못 읽으면 쿠키 삭제를 건너뛴다: 바로 아래 `session_destroy()` 는 그대로 수행된다.
    if (installerHasFunction('ini_get') && ini_get('session.use_cookies')) {
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
