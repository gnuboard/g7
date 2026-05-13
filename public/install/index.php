<?php
/**
 * 그누보드7 웹 인스톨러 메인 라우터
 *
 * @author sirsoft
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/installer-state.php';
require_once __DIR__ . '/includes/request-handler.php';
require_once __DIR__ . '/api/_guard.php';
installer_guard_or_410();

$currentLang = getCurrentLanguage();

// 세션 기반 단계 관리 (URL 파라미터 무시)
if (!isset($_SESSION['installer_current_step'])) {
    // 세션이 없으면 state.json에서 현재 step 가져오기
    $state = getInstallationState();

    // 설치가 진행 중이거나 중단/실패 상태면 state의 current_step 사용
    if (isset($state['installation_status']) &&
        in_array($state['installation_status'], ['running', 'aborted', 'failed', 'pending'])) {
        $_SESSION['installer_current_step'] = $state['current_step'] ?? 0;
    } else {
        // 그 외에는 0부터 시작
        $_SESSION['installer_current_step'] = 0;
    }
}
$currentStep = $_SESSION['installer_current_step'];

// URL 파라미터로 step 접근 시 알림 후 리다이렉트
if (isset($_GET['step'])) {
    $urlStep = (int)$_GET['step'];

    // 번역 로드
    if (!isset($translations)) {
        $translations = loadTranslations($currentLang);
    }

    // URL 파라미터의 step과 세션 step이 다른 경우
    if ($urlStep !== $currentStep) {
        showAlertAndRedirect(
            lang('url_parameter_not_supported'),
            lang('url_parameter_redirect_message', [
                'requested' => $urlStep,
                'current' => $currentStep
            ]),
            INSTALLER_BASE_URL . '/'
        );
    }

    // 같은 경우에도 깔끔한 URL로 리다이렉트
    header('Location: ' . INSTALLER_BASE_URL . '/');
    exit;
}

$state = getInstallationState();
$errors = [];
$formData = [];
$error = null;

// Step 3 기본값 설정
if ($currentStep === 3) {
    $defaults = DEFAULT_INSTALL_CONFIG;
    $defaults['app_url'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $defaults['admin_language'] = getCurrentLanguage();
    // state.json에서 비밀번호 제외된 config 복원 (세션에서 비밀번호를 사전 입력하지 않음)
    $savedConfig = $state['config'] ?? $defaults;
    unset($savedConfig['db_write_password'], $savedConfig['db_read_password']);
    $formData = array_merge($defaults, $savedConfig);
}

// 설치 흐름 검증
validateInstallationFlow($currentStep, $state);

// POST 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePostRequest($currentStep, $currentLang, $formData, $errors, $error);
}

// 번역 로드
if (!isset($translations)) {
    $translations = loadTranslations($currentLang);
}

// Step 범위 체크 (0: welcome ~ 6: complete)
if ($currentStep < 0 || $currentStep > 6) {
    $currentStep = 0;
}

$stepFile = __DIR__ . '/views/' . $currentStep . '-' . (STEP_FILE_MAP[$currentStep] ?? 'welcome') . '.php';
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<script>
// 다크모드 즉시 적용 - 렌더링 전 실행 (FOUC 깜빡임 방지)
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
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= lang('welcome_title') ?> - <?= lang('brand_name') ?></title>

    <!-- CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="<?= INSTALLER_BASE_URL ?>/assets/css/installer.css?v=<?= time() ?>">
</head>
<body>
    <?php if ($currentStep > 0): ?>
    <!-- Installer Header Bar -->
    <div class="installer-header-bar">
        <div class="installer-header-content">
            <div class="installer-header-left">
                <span class="installer-logo"><?= htmlspecialchars(lang('brand_name')) ?></span>
            </div>
            <div class="installer-header-right">
                <span class="installer-step-indicator">
                    [<?= $currentStep ?>/5] <?= lang(getStepName($currentStep)) ?>
                </span>
                <button
                    id="theme-toggle-btn"
                    class="theme-toggle"
                    type="button"
                    aria-label="<?= lang('toggle_theme') ?>"
                    title="<?= lang('toggle_theme') ?>"
                >
                    <span id="theme-toggle-icon">🌙</span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
        window.INSTALLER_BASE_URL = '<?= INSTALLER_BASE_URL ?>';
        window.CURRENT_STEP = <?= $currentStep ?>;
        window.INSTALLER_LANG = <?= json_encode($translations ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.INSTALLER_STATE_LOCALE = <?= json_encode($state['g7_locale'] ?? null) ?>;
    </script>
    <script src="<?= INSTALLER_BASE_URL ?>/assets/js/installation-monitor.js?v=<?= time() ?>"></script>
    <script src="<?= INSTALLER_BASE_URL ?>/assets/js/installer.js?v=<?= time() ?>"></script>
</body>
</html>
