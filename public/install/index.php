<?php

use App\Support\PrivilegedDatabaseAccounts;

/**
 * 그누보드7 웹 인스톨러 메인 라우터
 *
 * @author sirsoft
 */

require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/session.php';
require_once __DIR__.'/includes/installer-state.php';
require_once __DIR__.'/includes/request-handler.php';
require_once __DIR__.'/api/_guard.php';
// 설치가 끝났으면 410. 단, 완료 표식 뒤 마무리 구간(.env 확정 전)에 설치 소유 세션이 새로고침한 GET 만
// 통과한다 — 그때는 Step 5 화면만 렌더해 마무리 작업 대기 → .env 확정(finalize-env)으로 잇는다.
$finalizeWindow = installer_guard_index_or_410();

$currentLang = getCurrentLanguage();

if ($finalizeWindow) {
    // 완료 뒤 재진입 경로와 같이 세션에 남은 설치 설정(비밀번호 포함)을 정리한다 — finalize 는 세션을 쓰지 않는다.
    unset(
        $_SESSION['install_config'],
        $_SESSION['db_write_tested'],
        $_SESSION['db_read_tested']
    );
    $_SESSION['installer_current_step'] = 5;
}

// 세션 기반 단계 관리 (URL 파라미터 무시)
if (! isset($_SESSION['installer_current_step'])) {
    // 세션이 없으면 state.json에서 현재 step 가져오기
    $state = getInstallationState();

    // 설치가 진행 중이거나 중단/실패 상태면 state의 current_step 사용
    //
    // 어휘가 두 표에서 섞여 있었다. `pending` 은 **상태 파일에 기록되지 않는다**
    // (폴링 API 가 응답용으로 만드는 매핑값이다). 반대로 Step 3 저장이 쓰는 `ready` 는
    // 기록되는데 이 목록에 없었다 — 즉 실재하지 않는 값을 보고 실재하는 값을 빠뜨렸다.
    // 그래서 Step 3·4 까지 저장한 뒤 세션이 만료되거나 다른 기기로 접속하면, 저장된 진행분이
    // state 에 그대로 있는데도 환영 화면부터 다시 시작해야 했다.
    //
    // `pending` 은 남긴다 — 지우면 그 값을 쓰는 레거시 상태 파일이 되돌아간다(무해한 잉여).
    // `not_started` 도 채택한다 (22차 #22-3) — [초기화]가 실제로 기록하는 값이자 신규 설치
    // 기본값이다. 빠뜨리면 초기화 후 세션이 끊긴 접속이 저장된 진행(step_status)을 두고
    // 환영 화면부터 다시 걷는다(위와 같은 결함이 다른 값에 남아 있던 것). 신규
    // 설치에서는 current_step 이 0 이라 채택해도 동작이 같다.
    if (isset($state['installation_status']) &&
        in_array($state['installation_status'], ['running', 'aborted', 'failed', 'pending', 'ready', 'not_started'], true)) {
        $_SESSION['installer_current_step'] = $state['current_step'] ?? 0;
    } else {
        // 그 외에는 0부터 시작
        $_SESSION['installer_current_step'] = 0;
    }
}
$currentStep = $_SESSION['installer_current_step'];

// URL 파라미터로 step 접근 시 알림 후 리다이렉트
if (isset($_GET['step'])) {
    $urlStep = (int) $_GET['step'];

    // 번역 로드
    if (! isset($translations)) {
        $translations = loadTranslations($currentLang);
    }

    // URL 파라미터의 step과 세션 step이 다른 경우
    if ($urlStep !== $currentStep) {
        showAlertAndRedirect(
            lang('url_parameter_not_supported'),
            lang('url_parameter_redirect_message', [
                'requested' => $urlStep,
                'current' => $currentStep,
            ]),
            INSTALLER_BASE_URL.'/'
        );
    }

    // 같은 경우에도 깔끔한 URL로 리다이렉트
    header('Location: '.INSTALLER_BASE_URL.'/');
    exit;
}

$state = getInstallationState();
$errors = [];
$formData = [];
$error = null;

// 설치 소유권 잠금 — 다른 브라우저 세션이 소유한 설치에는 어떤 단계 화면·POST 처리도 열지 않고
// 복구 토큰 입력 화면만 보여준다. 같은 세션은 토큰 없이 그대로 재개한다.
$ownershipLocked = installer_state_is_claimed($state)
    && ! installer_session_is_owner($state)
    && ! isInstallationCompleted();

// Step 3 기본값 설정
if (! $ownershipLocked && $currentStep === 3) {
    $defaults = DEFAULT_INSTALL_CONFIG;
    // `APP_URL` 에는 **마운트 접두사까지** 담는다.
    //
    // 종전에는 scheme + host 만 조립해, 하위 폴더 설치(`https://host/g7/`)에서 그 값이
    // `https://host` 가 됐다. 그 값은 요청이 없는 문맥의 주소 SSoT 다 —
    // `AssetUrl::configuredBasePath()` 가 `app.url` 의 경로를 접두사 폴백으로 읽고,
    // 큐·콘솔·예약 작업·**메일 본문**이 그 폴백을 탄다. 즉 접두사를 빠뜨리면 그 문맥의
    // 주소가 전부 사이트 밖을 가리키는데, 화면(요청 기반)은 정상이라 드러나지 않는다.
    // 그 폴백이 막으려고 만들어진 실패를 인스톨러가 심는 형태였다.
    //
    // 접두사는 인스톨러 자신의 기준 주소 SSoT 에서 얻는다(`/…/install` → `/…`) — 그
    // 함수가 이미 Alias·하위 디렉토리·API 컨텍스트를 전부 흡수한다. 인자는 기준 URL
    // 파생(`config.php`)과 **같은 집합**이다 — 프록시가 벗긴 접두사(`X-Forwarded-Prefix`)를
    // 여기만 빠뜨리면 그 배치에서 접두사 없는 `APP_URL` 이 `.env` 에 영구 저장된다.
    $installerBase = installerDeriveBaseUrl(
        (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : null,
        isset($_SERVER['HTTP_X_FORWARDED_PREFIX']) ? (string) $_SERVER['HTTP_X_FORWARDED_PREFIX'] : null,
    );
    $mountPrefix = rtrim((string) preg_replace('#/install$#i', '', $installerBase), '/');

    // 스킴도 같은 저장소의 공용 판정을 쓴다. 종전에는 `HTTPS` 서버 변수 하나만 보는
    // 단독 판정이었는데, TLS 를 앞단에서 끝내는 프록시 뒤에서는 그 값이 비어 있고
    // `HTTP_X_FORWARDED_PROTO: https` 만 온다 — 그러면 `.env` 에 `APP_URL=http://…` 가
    // **영구 저장**되고, 오리진 축은 설정이 요청보다 권위이므로(`AssetUrl::absolute()`)
    // https 페이지에 `http://` 이미지가 실려 브라우저가 혼합 콘텐츠로 조용히 차단한다.
    // 그 실패는 그 독블록이 이름을 붙여 막으려던 바로 그것이다. 형제 둘(`session.php`
    // ·`public/install/api/check-configuration.php`)이 이미 이 판정을 쓴다 — 값을 영구화하는 여기만
    // 안 쓰고 있었다.
    // 호스트는 `Host` 헤더가 없으면(HTTP/1.0 클라이언트·일부 프록시) `SERVER_NAME` 으로 떨어진다
    // — 가드 없이 읽으면 경고와 함께 빈 호스트가 `APP_URL=http://` 로 `.env` 에 영구 저장된다.
    $defaults['app_url'] = (installerRequestIsHttps($_SERVER) ? 'https' : 'http')
        .'://'.((string) ($_SERVER['HTTP_HOST'] ?? '') ?: (string) ($_SERVER['SERVER_NAME'] ?? '')).$mountPrefix;
    // 서버가 아는 Host 는 프록시 뒤에서 내부 주소일 수 있다 — 저장된 값이 없으면 화면 스크립트가
    // 운영자가 실제로 보고 있는 주소(앱 루트 = origin + 마운트 접두사)로 채운다(data-autofill).
    $defaults['app_url_autofill'] = ! isset($state['config']['app_url']);
    $defaults['admin_language'] = getCurrentLanguage();
    // state.json에서 비밀번호 제외된 config 복원 (폼에 비밀번호를 사전 입력하지 않음).
    // state 에는 이미 비밀이 기록되지 않지만, 레거시 state.json 이 남아 있는
    // 환경에서도 평문이 HTML 로 렌더되지 않도록 프리필 단계에서 다시 제거한다.
    $savedConfig = $state['config'] ?? $defaults;
    unset(
        $savedConfig['db_write_password'],
        $savedConfig['db_read_password'],
        $savedConfig['admin_password'],
        $savedConfig['admin_password_confirm']
    );
    $formData = array_merge($defaults, $savedConfig);
}

// 마무리 구간은 Step 5 화면만 렌더한다 — 흐름 검증(완료 시 홈 이동)·POST 처리를 거치지 않는다.
if (! $ownershipLocked && ! $finalizeWindow) {
    // 설치 흐름 검증
    validateInstallationFlow($currentStep, $state);

    // POST 요청 처리
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handlePostRequest($currentStep, $currentLang, $formData, $errors, $error);
    }
}

// 번역 로드
if (! isset($translations)) {
    $translations = loadTranslations($currentLang);
}

// Step 범위 체크 (0: welcome ~ 6: complete)
if ($currentStep < 0 || $currentStep > 6) {
    $currentStep = 0;
}

$stepFile = $ownershipLocked
    ? __DIR__.'/views/ownership-recovery.php'
    : __DIR__.'/views/'.$currentStep.'-'.(STEP_FILE_MAP[$currentStep] ?? 'welcome').'.php';

// 표시할 단계는 세션에서 파생된다 — 캐시되면 뒤로가기·프록시 재생에서 **옛 단계**가 그려지고,
// 그 화면의 버튼은 이미 지난 단계를 향한다. API 응답과 같은 강도로 막는다.
if (! headers_sent()) {
    header('Cache-Control: no-store');
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= lang('welcome_title') ?> - <?= lang('brand_name') ?></title>
    <?= installer_favicon_tags() ?>

    <!-- CSS -->
    <!-- 아이콘: 설치 마법사는 SPA 부팅 전이라 자산 URL 헬퍼를 쓸 수 없으므로 상대 경로로 자체 제공한다.
         기준 주소는 `dirname($_SERVER['SCRIPT_NAME'])` 에서 오는 요청 유래 값이라 그대로 두지 않고 이스케이프한다. -->
    <link rel="stylesheet" href="<?= e(INSTALLER_BASE_URL) ?>/assets/vendor/font-awesome/6.5.1/css/all.inlined.css">
    <link rel="stylesheet" href="<?= e(INSTALLER_BASE_URL) ?>/assets/css/installer.css?v=<?= time() ?>">

    <!-- 서버 값 — 비실행 JSON 데이터 블록. 인라인 실행 코드는 두지 않는다(공개 #158 — 웹서버가
         `Content-Security-Policy: script-src 'self'` 를 걸면 인라인 스크립트·on* 이 실행되지 않는다).
         installer-boot.js 가 이 값을 종전과 같은 이름의 window.INSTALLER_* 전역으로 옮기고,
         다크 모드를 즉시 적용(FOUC 방지)하며, data-action 위임 리스너를 건다. -->
    <script type="application/json" id="installer-data"><?= installer_json_encode([
        'base_url' => INSTALLER_BASE_URL,
        'current_step' => (int) $currentStep,
        // 상태변경 API 요청 검증 토큰 (세션 CSRF) — 소유 토큰 원문은 페이지에 싣지 않는다
        'csrf' => installer_csrf_token(),
        'ownership_locked' => (bool) $ownershipLocked,
        'lang' => $translations ?? [],
        'state_locale' => $state['g7_locale'] ?? null,
        // 화면이 **실제로 렌더된** 로케일 — 문구(lang)와 같은 출처다. 확장 이름처럼 화면 문구와 나란히
        // 놓이는 값은 이것을 써야 한 화면에 두 언어가 섞이지 않는다(localStorage 는 이전 설치의 값이 남아
        // 이길 수 있다) (10차). installer-boot.js 가 window.INSTALLER_LOCALE 로 옮긴다.
        'locale' => $currentLang,
        // DB 최고권한 계정 목록 — 서버 상수를 그대로 내려보내 JS 에 목록을 중복 정의하지 않는다.
        // 클라이언트 검증은 즉시 피드백용이며, 실제 차단은 서버 3개 경로가 담당한다.
        'blocked_db_accounts' => PrivilegedDatabaseAccounts::BLOCKED,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="<?= e(INSTALLER_BASE_URL) ?>/assets/js/installer-boot.js?v=<?= time() ?>"></script>
</head>
<body>
    <?php /* 헤더 바는 첫 화면(환영)에도 나온다 — 테마 토글이 그 안에 있어, 종전처럼 1단계부터
             띄우면 시스템과 반대로 보고 싶은 사용자는 다음 단계로 넘어가야만 전환 수단을 만난다
             (10차, 제품 결정). 단계 표시만 첫 화면에서 생략한다 — 아직 시작하지 않았기 때문이다. */ ?>
    <?php /* 다른 브라우저 세션이 소유한 설치의 복구 토큰 화면에는 헤더 바를 띄우지 않는다 — 단계 화면이 아니다. */ ?>
    <?php if (! $ownershipLocked) { ?>
    <!-- Installer Header Bar -->
    <div class="installer-header-bar">
        <div class="installer-header-content">
            <div class="installer-header-left">
                <span class="installer-logo"><?= htmlspecialchars(lang('brand_name')) ?></span>
            </div>
            <div class="installer-header-right">
                <?php if ($currentStep > 0) { ?>
                <span class="installer-step-indicator">
                    [<?= $currentStep ?>/5] <?= lang(getStepName($currentStep)) ?>
                </span>
                <?php } ?>
                <button
                    id="theme-toggle-btn"
                    class="theme-toggle"
                    type="button"
                    aria-label="<?= htmlspecialchars(lang('toggle_theme')) ?>"
                    title="<?= htmlspecialchars(lang('toggle_theme')) ?>"
                >
                    <span id="theme-toggle-icon">🌙</span>
                </button>
            </div>
        </div>
    </div>
    <?php } ?>

    <!-- Main Content -->
    <?php
    if (file_exists($stepFile)) {
        require_once $stepFile;
    } else {
        showStepFileNotFoundError($currentStep);
    }
?>

    <!-- Installer Footer -->
    <footer class="installer-footer">
        <p>&copy; <?= APP_RELEASE_YEAR ?> Gnuboard7. All rights reserved.</p>
    </footer>

    <!-- JavaScript (전역 INSTALLER_* 는 <head> 의 installer-boot.js 가 이미 채웠다) -->
    <script src="<?= e(INSTALLER_BASE_URL) ?>/assets/js/installation-monitor.js?v=<?= time() ?>"></script>
    <!-- 확장 선택 해석기 — 서버(확장 저장·사전 검증·터미널 설치)와 같은 규칙. installer.js 보다 먼저 로드 -->
    <script src="<?= e(INSTALLER_BASE_URL) ?>/assets/js/installer-extension-resolver.js?v=<?= time() ?>"></script>
    <script src="<?= e(INSTALLER_BASE_URL) ?>/assets/js/installer.js?v=<?= time() ?>"></script>
</body>
</html>
