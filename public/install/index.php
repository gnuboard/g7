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
installer_guard_finalize_or_410();

$currentLang = getCurrentLanguage();

// 세션 기반 단계 관리 (URL 파라미터 무시)
if (! isset($_SESSION['installer_current_step'])) {
    // 세션이 없으면 state.json에서 현재 step 가져오기
    $state = getInstallationState();

    // 설치가 진행 중이거나 중단/실패 상태면 state의 current_step 사용
    //
    // 19차 S-5 — 어휘가 두 표에서 섞여 있었다. `pending` 은 **상태 파일에 기록되지 않는다**
    // (폴링 API 가 응답용으로 만드는 매핑값이다). 반대로 Step 3 저장이 쓰는 `ready` 는
    // 기록되는데 이 목록에 없었다 — 즉 실재하지 않는 값을 보고 실재하는 값을 빠뜨렸다.
    // 그래서 Step 3·4 까지 저장한 뒤 세션이 만료되거나 다른 기기로 접속하면, 저장된 진행분이
    // state 에 그대로 있는데도 환영 화면부터 다시 시작해야 했다.
    //
    // `pending` 은 남긴다 — 지우면 그 값을 쓰는 레거시 상태 파일이 되돌아간다(무해한 잉여).
    // `not_started` 도 채택한다 (22차 #22-3) — [초기화]가 실제로 기록하는 값이자 신규 설치
    // 기본값이다. 빠뜨리면 초기화 후 세션이 끊긴 접속이 저장된 진행(step_status)을 두고
    // 환영 화면부터 다시 걷는다(19차 S-5 와 같은 결함이 다른 값에서 잔존했던 것). 신규
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

// Step 3 기본값 설정
if ($currentStep === 3) {
    $defaults = DEFAULT_INSTALL_CONFIG;
    $defaults['app_url'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
    $defaults['admin_language'] = getCurrentLanguage();
    // state.json에서 비밀번호 제외된 config 복원 (폼에 비밀번호를 사전 입력하지 않음).
    // state 에는 이미 비밀이 기록되지 않지만(이슈 #465), 레거시 state.json 이 남아 있는
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

// 설치 흐름 검증
validateInstallationFlow($currentStep, $state);

// POST 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePostRequest($currentStep, $currentLang, $formData, $errors, $error);
}

// 번역 로드
if (! isset($translations)) {
    $translations = loadTranslations($currentLang);
}

// Step 범위 체크 (0: welcome ~ 6: complete)
if ($currentStep < 0 || $currentStep > 6) {
    $currentStep = 0;
}

$stepFile = __DIR__.'/views/'.$currentStep.'-'.(STEP_FILE_MAP[$currentStep] ?? 'welcome').'.php';

// 표시할 단계는 세션에서 파생된다 — 캐시되면 뒤로가기·프록시 재생에서 **옛 단계**가 그려지고,
// 그 화면의 버튼은 이미 지난 단계를 향한다 (17차 #B10). API 응답과 같은 강도로 막는다.
if (! headers_sent()) {
    header('Cache-Control: no-store');
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
    <meta charset="UTF-8">
    <?php /* 다크모드 즉시 적용 — 렌더링 전 실행 (FOUC 깜빡임 방지).
             `<html>` 과 `<head>` **사이**가 아니라 head 안에 둔다: 그 자리는 유효하지 않은
             문서 구조이고(파서가 head 를 암묵적으로 열어 줘 동작만 할 뿐), charset 선언보다
             앞서면 인코딩 판정도 늦어진다 (10차). */ ?>
    <script>
    (function(){
        try {
            const theme = localStorage.getItem('g7_color_scheme')
                || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        } catch(e) {
            // localStorage 접근 실패 시 기본값(light) 사용
            document.documentElement.setAttribute('data-theme', 'light');
        }
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= lang('welcome_title') ?> - <?= lang('brand_name') ?></title>

    <!-- CSS -->
    <!-- 아이콘: 설치 마법사는 SPA 부팅 전이라 자산 URL 헬퍼를 쓸 수 없으므로 상대 경로로 자체 제공한다 -->
    <link rel="stylesheet" href="<?= INSTALLER_BASE_URL ?>/assets/vendor/font-awesome/6.5.1/css/all.inlined.css">
    <link rel="stylesheet" href="<?= INSTALLER_BASE_URL ?>/assets/css/installer.css?v=<?= time() ?>">
</head>
<body>
    <?php /* 헤더 바는 첫 화면(환영)에도 나온다 — 테마 토글이 그 안에 있어, 종전처럼 1단계부터
             띄우면 시스템과 반대로 보고 싶은 사용자는 다음 단계로 넘어가야만 전환 수단을 만난다
             (10차, PO 결정). 단계 표시만 첫 화면에서 생략한다 — 아직 시작하지 않았기 때문이다. */ ?>
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

    <!-- JavaScript -->
    <script>
        <?php $jsonInlineFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; ?>
        // 인라인 script 에 실리는 값은 예외 없이 같은 HEX 플래그를 지난다 — 한 곳만 빠지면
        // 그 값이 스크립트 탈출 통로가 된다. 기준 경로는 dirname($_SERVER['SCRIPT_NAME'])
        // 에서 오므로 리터럴 상수가 아니다 (13차 보안#10).
        // 인코딩은 installer_json_encode 를 지난다 — 그 자리가 인스톨러의 유일한 json_encode
        // 이고, 계정명·경로의 invalid UTF-8 이 섞이면 빈 본문이 나가는 것을 막는다 (#62).
        window.INSTALLER_BASE_URL = <?= installer_json_encode(INSTALLER_BASE_URL, $jsonInlineFlags) ?>;
        window.CURRENT_STEP = <?= installer_json_encode($currentStep, $jsonInlineFlags) ?>;
        window.INSTALLER_LANG = <?= installer_json_encode($translations ?? [], $jsonInlineFlags) ?>;
        window.INSTALLER_STATE_LOCALE = <?= installer_json_encode($state['g7_locale'] ?? null, $jsonInlineFlags) ?>;
        // 화면이 **실제로 렌더된** 로케일 — 문구(INSTALLER_LANG)와 같은 출처다.
        // 확장 이름처럼 화면 문구와 나란히 놓이는 값은 이것을 써야 한 화면에 두 언어가
        // 섞이지 않는다(localStorage 는 이전 설치의 값이 남아 이길 수 있다) (10차).
        window.INSTALLER_LOCALE = <?= installer_json_encode($currentLang, $jsonInlineFlags) ?>;
        // DB 최고권한 계정 목록 — 서버 상수를 그대로 내려보내 JS 에 목록을 중복 정의하지 않는다.
        // 클라이언트 검증은 즉시 피드백용이며, 실제 차단은 서버 3개 경로가 담당한다.
        window.INSTALLER_BLOCKED_DB_ACCOUNTS = <?= installer_json_encode(PrivilegedDatabaseAccounts::BLOCKED, $jsonInlineFlags) ?>;
    </script>
    <script src="<?= INSTALLER_BASE_URL ?>/assets/js/installation-monitor.js?v=<?= time() ?>"></script>
    <script src="<?= INSTALLER_BASE_URL ?>/assets/js/installer.js?v=<?= time() ?>"></script>
</body>
</html>
