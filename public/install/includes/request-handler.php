<?php

/**
 * 그누보드7 웹 인스톨러 요청 처리 핸들러
 *
 * 모든 POST 요청 및 비즈니스 로직을 처리합니다.
 */

require_once __DIR__.'/installer-ownership.php';
require_once __DIR__.'/config-validation.php';

/**
 * 설치 흐름 검증 및 리다이렉트
 *
 * @param  int  $currentStep  현재 단계
 * @param  array  $state  설치 상태
 */
function validateInstallationFlow(int $currentStep, array $state): void
{
    // 설치 완료 시 홈으로 리다이렉트
    if (isInstallationCompleted()) {
        // 세션에 남은 설치 config (DB/관리자 비밀번호 포함) 를 정리한다 (이슈 #465).
        // 설치가 끝나면 세션 config 는 소비처가 없으므로 세션 파일에 평문이 잔존할 이유가
        // 없다. 완료 후 인스톨러에 재접근하는 모든 경로가 이 지점을 통과한다.
        unset(
            $_SESSION['install_config'],
            $_SESSION['db_write_tested'],
            $_SESSION['db_read_tested']
        );

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
 * @param  int  $correctStep  올바른 단계 번호
 */
function showInvalidStepAccessAlert(int $correctStep): void
{
    global $translations;

    // 번역이 로드되지 않은 경우 로드
    if (! isset($translations)) {
        $translations = loadTranslations(getCurrentLanguage());
    }

    // 세션에 올바른 단계 설정
    $_SESSION['installer_current_step'] = $correctStep;

    showAlertAndRedirect(
        lang('error_invalid_step_access'),
        lang('error_invalid_step_access_message'),
        INSTALLER_BASE_URL.'/'
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
    if (! isset($_POST['language_change_only'])) {
        unset($_SESSION['license_agreed']);
        updateStepStatus(0, 1);
        redirectToStep(1);
    }

    redirectToStep(0);
}

/**
 * Step 1 POST 처리 (라이선스 동의)
 *
 * @param  string  $currentLang  현재 언어
 * @param  string|null  &$error  에러 메시지 (참조)
 */
function handleStep1Post(string $currentLang, ?string &$error = null): void
{
    // lang() 은 전역 번역 테이블을 읽는다 — 지역 변수에 담으면 화면 오류가 번역 키 원문으로 나간다.
    global $translations;
    $translations = loadTranslations($currentLang);

    if (! isset($_POST['agree']) || $_POST['agree'] !== '1') {
        $error = lang('must_agree');
    } else {
        $_SESSION['license_agreed'] = true;
        updateStepStatus(1, 2);
        redirectToStep(2);
    }
}

/**
 * Step 2 POST 처리 (요구사항 검증)
 *
 * 화면은 차단 항목이 있으면 다음 버튼을 숨기지만, POST 를 직접 보내면 그대로 넘어갔다.
 * 다음 단계로 가기 전에 서버가 요구사항을 다시 검사한다(터미널 설치와 같은 함수).
 *
 * @param  string|null  &$error  화면에 표시할 오류 (참조)
 */
function handleStep2Post(?string &$error = null): void
{
    if (isset($_POST['proceed'])) {
        require_once __DIR__.'/requirements.php';

        if (installer_collect_requirements()['blocking']) {
            $error = lang('requirements_not_met');

            return;
        }

        updateStepStatus(2, 3);
        redirectToStep(3);
    }
}

/**
 * DB 사용자명을 검증해 에러 배열에 결과를 기록합니다.
 *
 * 빈 값(설정 누락)과 DB 최고권한 계정을 모두 거부합니다. 판정 본문은 웹·터미널 공유 함수
 * `installer_validate_db_username()` 이 소유합니다 — 이 이름은 하위 호환 별칭입니다.
 *
 * @param  string  $username  입력된 DB 사용자명
 * @param  string  $field  에러 배열에 사용할 필드명 (db_write_username 등)
 * @param  array  &$errors  에러 배열 (참조)
 */
function validateDbUsername(string $username, string $field, array &$errors): void
{
    installer_validate_db_username($username, $field, $errors);
}

/**
 * Step 3 POST 처리 (데이터베이스 및 사이트 설정)
 *
 * @param  string  $currentLang  현재 언어
 * @param  array  &$formData  폼 데이터 (참조)
 * @param  array  &$errors  에러 배열 (참조)
 */
function handleStep3Post(string $currentLang, array &$formData, array &$errors): void
{
    // lang() 은 전역 번역 테이블을 읽는다 — 지역 변수에 담으면 화면 오류가 번역 키 원문으로 나간다.
    global $translations;
    $translations = loadTranslations($currentLang);

    // Step 3 저장은 소유권을 최초로 주장하고 state.config 를 바꾸는 상태변경이다.
    // 조기 반환하지 않는다 — 필드 오류도 함께 보여주고, 저장은 오류가 하나도 없을 때만 일어난다.
    if (! installer_csrf_is_valid(installer_request_csrf_token())) {
        $errors['csrf'] = lang('error_csrf_invalid');
    }

    $formData = array_merge($formData, $_POST);
    $formData['use_read_db'] = isset($_POST['use_read_db']) ? true : false;

    // 입력 검증 본문은 웹·터미널 공유 함수가 소유한다 — 규칙을 바꿀 때는 config-validation.php 에서 바꾼다.
    $validated = installer_validate_config($formData, $currentLang);
    $formData = $validated['config'];
    $errors = array_merge($errors, $validated['errors']);

    // DB 테스트 완료 확인 — 통과 기록(`=== true`)과 함께, 이 세션이 검사한 연결설정과
    // 지금 제출한 연결설정이 같은지 지문으로 대조한다. 실패 기록·미검사·검사 후 설정 변경은 모두 거부.
    if (empty($errors)) {
        $connections = installer_step3_db_connections($formData);
        $testStatus = installer_db_test_status('write', $connections['write']);

        if ($testStatus === 'ok' && $connections['read'] !== null) {
            $testStatus = installer_db_test_status('read', $connections['read']);
        }

        if ($testStatus !== 'ok') {
            $errors['db_test'] = lang($testStatus === 'changed' ? 'error_db_config_changed' : 'error_db_not_tested');
        }
    }

    // 검증 통과 시 다음 단계로 이동
    if (empty($errors)) {
        $state = getInstallationState();
        $generation = (int) ($state['generation'] ?? 0);
        $isFirstClaim = ! installer_state_is_claimed($state);

        // 이미 다른 세션이 소유한 설치면 덮어쓰지 않는다 (index.php 가 비소유 세션을 잠그는 것의 이중 방어).
        if (! $isFirstClaim && ! installer_session_is_owner($state)) {
            $errors['ownership'] = lang('error_ownership_conflict');

            return;
        }

        // state.json 에는 비밀(DB/관리자 비밀번호) 을 저장하지 않는다 (이슈 #465).
        // read 필드 정리(이슈 #63 2단계 방어) 도 sanitizeConfigForState 가 함께 수행.
        $state['config'] = sanitizeConfigForState($formData);
        $state['installation_status'] = 'ready';
        $state['current_step'] = 4;
        $state['step_status'][3] = 'completed';
        // 기존 테이블 삭제 확인은 이 세션의 DB 테스트가 발급한 것만 설치 계획에 싣는다.
        $state['drop_confirm'] = is_array($_SESSION['drop_confirm'] ?? null) ? $_SESSION['drop_confirm'] : null;

        if ($isFirstClaim) {
            // 소유자가 없던 설치에 남은 runtime.php 는 이전 시도(다른 세션)의 비밀이다 —
            // 새 소유자의 설치에 섞이지 않도록 폐기한다 (자격증명 혼합 차단).
            require_once __DIR__.'/installer-runtime.php';
            deleteInstallerRuntime();
            $state = installer_claim_owner($state);
        }

        if (! saveInstallationStateCas($state, $generation)) {
            $errors['state'] = lang('error_state_conflict');

            return;
        }

        if ($isFirstClaim) {
            addLog(lang('log_ownership_claimed'));
        }

        // 세션에는 비밀번호를 유지한다 (install-process.php 가 runtime.php 로 이송).
        // 단 admin_password_confirm 은 검증 이후 소비처가 전무한 순수 잔여물이므로 제거.
        $sessionConfig = $formData;
        unset($sessionConfig['admin_password_confirm']);
        $_SESSION['install_config'] = $sessionConfig;

        redirectToStep(4);
    }
}

/**
 * POST 요청 라우팅
 *
 * @param  int  $currentStep  현재 단계
 * @param  string  $currentLang  현재 언어
 * @param  array  &$formData  폼 데이터 (참조)
 * @param  array  &$errors  에러 배열 (참조)
 * @param  string|null  &$error  에러 메시지 (참조)
 */
function handlePostRequest(int $currentStep, string $currentLang, array &$formData = [], array &$errors = [], ?string &$error = null): void
{
    // 단계 이동 처리 (go_to_step POST 파라미터)
    if (isset($_POST['go_to_step'])) {
        $step = (int) $_POST['go_to_step'];
        if ($step >= 0 && $step <= 5) {
            $_SESSION['installer_current_step'] = $step;
        }
        header('Location: '.INSTALLER_BASE_URL.'/');
        exit;
    }

    // 구 방식 언어 전환 처리 (호환성 유지)
    if (isset($_POST['change_language'])) {
        $lang = $_POST['lang'] ?? 'ko';
        handleLanguageChange($lang);
        redirectToStep($currentStep);
    }

    // Step별 POST 처리
    match ($currentStep) {
        0 => handleStep0Post(),
        1 => handleStep1Post($currentLang, $error),
        2 => handleStep2Post($error),
        3 => handleStep3Post($currentLang, $formData, $errors),
        default => null
    };
}
