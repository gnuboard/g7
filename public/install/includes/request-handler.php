<?php

/**
 * 그누보드7 웹 인스톨러 요청 처리 핸들러
 *
 * 모든 POST 요청 및 비즈니스 로직을 처리합니다.
 *
 * @package G7\Installer
 */

/**
 * 설치 흐름 검증 및 리다이렉트
 *
 * @param int $currentStep 현재 단계
 * @param array $state 설치 상태
 */
function validateInstallationFlow(int $currentStep, array $state): void
{
    // 설치 완료 시 홈으로 리다이렉트
    if (isInstallationCompleted()) {
        $translations = loadTranslations(getCurrentLanguage());
        showInstallationCompletedAlert();
    }

    // 설치 진행 중 체크 (Step 5 제외 - Installation 화면)
    if (isInstallationRunning() && $currentStep !== 5) {
        showInstallationRunningAlert();
    }

    // 상태 파일의 last_completed_step 가져오기
    $lastCompletedStep = getLastCompletedStep();

    // 허용된 최대 단계 계산 (마지막 완료 단계 + 1)
    $allowedMaxStep = $lastCompletedStep + 1;

    // 현재 단계가 허용된 범위를 벗어나면 알림 후 올바른 단계로 리다이렉트
    if ($currentStep > $allowedMaxStep) {
        showInvalidStepAccessAlert($allowedMaxStep);
    }
}

/**
 * 잘못된 단계 접근 시 알림 표시
 *
 * @param int $correctStep 올바른 단계 번호
 */
function showInvalidStepAccessAlert(int $correctStep): void
{
    global $translations;

    // 번역이 로드되지 않은 경우 로드
    if (!isset($translations)) {
        $translations = loadTranslations(getCurrentLanguage());
    }

    // 세션에 올바른 단계 설정
    $_SESSION['installer_current_step'] = $correctStep;

    showAlertAndRedirect(
        lang('error_invalid_step_access'),
        lang('error_invalid_step_access_message'),
        INSTALLER_BASE_URL . '/'
    );
}

/**
 * Step 0 POST 처리 (환영 화면)
 */
function handleStep0Post(): void
{
    if (isset($_POST['language'])) {
        handleLanguageChange($_POST['language']);
    }

    // 다음 버튼 클릭 시 (언어 선택 변경이 아닌 경우)
    if (!isset($_POST['language_change_only'])) {
        unset($_SESSION['license_agreed']);
        updateStepStatus(0, 1);
        redirectToStep(1);
    }

    redirectToStep(0);
}

/**
 * Step 1 POST 처리 (라이선스 동의)
 *
 * @param string $currentLang 현재 언어
 * @param string|null &$error 에러 메시지 (참조)
 */
function handleStep1Post(string $currentLang, ?string &$error = null): void
{
    $translations = loadTranslations($currentLang);

    if (!isset($_POST['agree']) || $_POST['agree'] !== '1') {
        $error = lang('must_agree');
    } else {
        $_SESSION['license_agreed'] = true;
        updateStepStatus(1, 2);
        redirectToStep(2);
    }
}

/**
 * Step 2 POST 처리 (요구사항 검증)
 */
function handleStep2Post(): void
{
    if (isset($_POST['proceed'])) {
        updateStepStatus(2, 3);
        redirectToStep(3);
    }
}

/**
 * Step 3 POST 처리 (데이터베이스 및 사이트 설정)
 *
 * @param string $currentLang 현재 언어
 * @param array &$formData 폼 데이터 (참조)
 * @param array &$errors 에러 배열 (참조)
 */
function handleStep3Post(string $currentLang, array &$formData, array &$errors): void
{
    $translations = loadTranslations($currentLang);

    $formData = array_merge($formData, $_POST);
    $formData['use_read_db'] = isset($_POST['use_read_db']) ? true : false;

    // Write DB 검증
    if (empty($formData['db_write_host'])) {
        $errors['db_write_host'] = lang('error_db_host_required');
    }
    if (empty($formData['db_write_database'])) {
        $errors['db_write_database'] = lang('error_db_name_required');
    }

    // Read DB 검증 (사용하는 경우)
    if (!empty($formData['use_read_db'])) {
        if (empty($formData['db_read_host'])) {
            $errors['db_read_host'] = lang('error_db_host_required');
        }
        if (empty($formData['db_read_database'])) {
            $errors['db_read_database'] = lang('error_db_name_required');
        }
    }

    // 관리자 정보 검증
    if (empty($formData['admin_email']) || !filter_var($formData['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['admin_email'] = lang('error_admin_email_invalid');
    }
    if (!array_key_exists($formData['admin_language'] ?? '', SUPPORTED_LANGUAGES)) {
        $errors['admin_language'] = lang('error_admin_language_invalid');
    }
    if (empty($formData['admin_password']) || strlen($formData['admin_password']) < 8) {
        $errors['admin_password'] = lang('error_admin_password_min');
    }
    if ($formData['admin_password'] !== $formData['admin_password_confirm']) {
        $errors['admin_password_confirm'] = lang('error_password_mismatch');
    }

    // PHP CLI / Composer 경로 처리
    $phpBinary = trim($formData['php_binary'] ?? 'php');
    $formData['php_binary'] = $phpBinary !== '' ? $phpBinary : 'php';
    $formData['composer_binary'] = trim($formData['composer_binary'] ?? '');

    // 코어 업데이트 _pending 경로 검증 (입력된 경우만)
    $corePendingPath = trim($formData['core_update_pending_path'] ?? '');
    if ($corePendingPath !== '') {
        // 절대 경로 변환
        $absolutePath = str_starts_with($corePendingPath, '/')
            ? $corePendingPath
            : BASE_PATH . '/' . $corePendingPath;

        if (file_exists($absolutePath) && !is_dir($absolutePath)) {
            $errors['core_update_pending_path'] = lang('error_core_pending_not_directory');
        }
    }

    // DB 테스트 완료 확인
    if (empty($errors) && !isset($_SESSION['db_write_tested'])) {
        $errors['db_test'] = lang('error_db_not_tested');
    }

    if (empty($errors) && !empty($formData['use_read_db']) && !isset($_SESSION['db_read_tested'])) {
        $errors['db_test'] = lang('error_db_not_tested');
    }

    // 검증 통과 시 다음 단계로 이동
    if (empty($errors)) {
        $_SESSION['install_config'] = $formData;

        // state.json에는 비밀번호를 저장하지 않음 (보안)
        $safeFormData = $formData;
        unset($safeFormData['db_write_password'], $safeFormData['db_read_password']);

        updateStepStatus(3, 4, [
            'config' => $safeFormData,
            'installation_status' => 'ready'
        ]);

        redirectToStep(4);
    }
}

/**
 * POST 요청 라우팅
 *
 * @param int $currentStep 현재 단계
 * @param string $currentLang 현재 언어
 * @param array &$formData 폼 데이터 (참조)
 * @param array &$errors 에러 배열 (참조)
 * @param string|null &$error 에러 메시지 (참조)
 */
function handlePostRequest(int $currentStep, string $currentLang, array &$formData = [], array &$errors = [], ?string &$error = null): void
{
    // 단계 이동 처리 (go_to_step POST 파라미터)
    if (isset($_POST['go_to_step'])) {
        $step = (int)$_POST['go_to_step'];
        if ($step >= 0 && $step <= 5) {
            $_SESSION['installer_current_step'] = $step;
        }
        header('Location: ' . INSTALLER_BASE_URL . '/');
        exit;
    }

    // 구 방식 언어 전환 처리 (호환성 유지)
    if (isset($_POST['change_language'])) {
        $lang = $_POST['lang'] ?? 'ko';
        handleLanguageChange($lang);
        redirectToStep($currentStep);
    }

    // Step별 POST 처리
    match($currentStep) {
        0 => handleStep0Post(),
        1 => handleStep1Post($currentLang, $error),
        2 => handleStep2Post(),
        3 => handleStep3Post($currentLang, $formData, $errors),
        default => null
    };
}
