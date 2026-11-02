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
 * 웹 노출 점검 판정값을 로그에 실을 표시 문구로 바꿉니다.
 *
 * 값을 보내는 쪽은 무인증 화면이므로 **닫힌 집합**으로만 받는다 — 임의 문자열을 그대로
 * 로그에 실으면 그 줄이 공격자의 받아쓰기가 된다. 집합 밖(부재 포함)은 「보고되지 않음」
 * 으로 접는다: 그 사실 자체가 화면 배선이 끊겼다는 신호이므로 없애지 않고 남긴다.
 *
 * @param  mixed  $verdict  화면이 보낸 판정값
 * @return string 로그에 실을 번역 문구
 */
function installerWebExposureVerdictLabel($verdict): string
{
    return match (installerNormalizeWebExposureVerdict($verdict)) {
        'safe' => lang('web_exposure_safe'),
        'exposed' => lang('web_exposure_exposed'),
        'unknown' => lang('web_exposure_unknown'),
        'confirmed' => lang('web_exposure_verdict_confirmed'),
        'pending' => lang('web_exposure_verdict_pending'),
        default => lang('web_exposure_verdict_unreported'),
    };
}

/**
 * 웹 노출 점검 판정값을 닫힌 집합으로 정규화합니다 — 로그 문구와 서버측 게이트가 같은 값을 본다.
 *
 * 화면(`installer.js`)이 보내는 값은 `safe`·`exposed`·`unknown`·`confirmed` 와 숨은 필드의
 * 초기값 `pending`(프로브가 끝나기 전에 제출된 경우) 다섯이다. 그 밖(부재·비문자열·대소문자
 * 변형 포함)은 `unreported` 로 접는다 — 게이트는 `exposed` 만 막으므로 이 접힘이 차단을
 * 넓히지는 않는다. `pending` 을 집합에 두는 이유는 로그다: 접으면 「보고되지 않음」(배선 파손
 * 신호)과 「판정 전 제출」이 같은 줄이 된다 (16차 G3 D-6).
 *
 * @param  mixed  $verdict  화면이 보낸 판정값
 * @return string `safe`|`exposed`|`unknown`|`confirmed`|`pending`|`unreported`
 */
function installerNormalizeWebExposureVerdict($verdict): string
{
    return match (is_string($verdict) ? $verdict : '') {
        'safe', 'exposed', 'unknown', 'confirmed', 'pending' => $verdict,
        default => 'unreported',
    };
}

/**
 * Step 2 POST 처리 (요구사항 검증)
 *
 * 화면은 차단 항목이 있으면 다음 버튼을 숨기지만, POST 를 직접 보내면 그대로 넘어갔다.
 * 다음 단계로 가기 전에 서버가 요구사항을 다시 검사한다(터미널 설치와 같은 함수).
 * 그 앞에서 웹 노출 점검 판정(화면 자기 신고, 웹 전용 게이트)을 먼저 본다 — 값 비교뿐이라
 * 요구사항 전체 판정(CLI 버전 확인 등 외부 호출 포함)보다 싸고, 노출됨 차단 기록이 요구사항 결과에
 * 가려지지 않는다. 판정 통과 기록은 두 게이트를 모두 지난 뒤 단계를 넘어가는 시점에 한 줄 남긴다.
 *
 * @param  string|null  &$error  화면에 표시할 오류 (참조) — 요구사항 미충족·노출됨 판정이면 여기 실리고 단계는 넘어가지 않는다
 */
function handleStep2Post(?string &$error = null): void
{
    if (isset($_POST['proceed'])) {
        // 1) 웹 노출 점검 게이트 (웹 전용)
        $verdict = installerNormalizeWebExposureVerdict($_POST['web_exposure_verdict'] ?? null);

        // 노출 점검(B-4)의 판정은 브라우저가 내리고 [다음 단계] 버튼도 화면이 잠근다(4차 제품
        // 결정 — 차단은 화면이 한다). 서버는 그 값을 닫힌 집합으로 정규화하고 **자기 신고
        // `exposed` 만** 막는다 (14차 G3 W2-3) — 실효 범위는 「노출됨으로 판정된 화면에서
        // 버튼 비활성을 풀고 눌러도 넘어가지 않는다」까지다. 게이트의 목적은 경고 무시 방지이지
        // 공격자 차단이 아니므로, 값 위조(`safe` 로 바꿔 전송)와 API 직접 호출은 설계상 열려
        // 있다. `unknown`(판정 불능)과 `confirmed`(운영자 직접 확인, D21)는 종전대로 통과한다
        // — 자동 판정이 불가능한 배치를 서버가 막을 근거는 없다.
        if ($verdict === 'exposed') {
            addLog(lang('log_web_exposure_blocked'));
            $error = lang('error_web_exposure_blocked');

            return;
        }

        // 2) 서버 요구사항 재검사 — 화면의 버튼 숨김을 건너뛴 직접 POST 를 막는다(터미널 설치와 같은 함수).
        require_once __DIR__.'/requirements.php';

        if (installer_collect_requirements()['blocking']) {
            $error = lang('requirements_not_met');

            return;
        }

        // 그 결과는 단계를 넘어가는 순간 사라졌다 — 「보호 확인됨으로 통과했는지 / 판정
        // 불능에서 운영자가 직접 확인하고 넘어갔는지」를 되짚을 근거가 어디에도 없었다
        // (잔여물 정리 실패조차 설치 로그에 남는데 정작 판정은 남지 않았다). 통과 시점에 한 줄 남긴다.
        addLog(lang('log_web_exposure_verdict', [
            'verdict' => installerWebExposureVerdictLabel($verdict),
        ]));

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

    // 입력 검증은 웹·터미널 공유 함수가 소유한다 — 규칙을 바꿀 때는 config-validation.php 에서 바꾼다.
    // 사이트명 길이, 직접 지정한 PHP 경로의 비-CLI 거부, 실행 방식 정규화·성립 검사와 vendor 방식 보정,
    // 코어 업데이트 _pending 경로의 검사 버튼 동형 규칙(`..`/NUL 거부·Windows 절대경로·stat 진단 억제)도
    // 그 함수 안에 있다. 터미널 설치가 같은 규칙을 거치도록 껍데기의 사본은 두지 않는다.
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
        // 읽기-판정-쓰기 전체를 상태 잠금 안에서 수행한다 (22차 N-2) — 잠금 밖이면 읽기와
        // 쓰기 사이에 워커가 running 으로 전이한 것을 이 저장이 되덮는 경합 창이 남는다.
        // 같은 라운드의 reset(state-management)이 지킨 규율과 동일하다. 저장은 CAS 로 한다 —
        // 소유권을 최초로 주장하는 상태변경이라 세대를 올려, 그 전의 설치 계획 서명을 무효로 만든다.
        $saveOutcome = installerWithStateLock(static function () use ($formData): string {
            $state = getInstallationState();
            $generation = (int) ($state['generation'] ?? 0);
            $isFirstClaim = ! installer_state_is_claimed($state);

            // 이미 다른 세션이 소유한 설치면 덮어쓰지 않는다 (index.php 가 비소유 세션을 잠그는 것의 이중 방어).
            if (! $isFirstClaim && ! installer_session_is_owner($state)) {
                return 'ownership';
            }

            // 진행 이력 상태(running/failed/aborted)는 되감지 않는다 (21차 #21-9 ①) — 다른 탭의
            // 재저장이 failed 를 ready 로 덮으면 [다시 시도]가 신규 설치로 둔갑해 완료 마커·
            // 롤백 기준선·실패 로그가 소거된다. 판정은 installerConfigSaveStatusPatch 가 소유한다.
            $currentLifecycleStatus = $state['installation_status'] ?? null;

            // state.json 에는 비밀(DB/관리자 비밀번호) 을 저장하지 않는다 (이슈 #465).
            // read 필드 정리(이슈 #63 2단계 방어) 도 sanitizeConfigForState 가 함께 수행.
            $state['config'] = sanitizeConfigForState($formData);
            $state['current_step'] = 4;
            $state['step_status'][3] = 'completed';
            $state = array_merge(
                $state,
                installerConfigSaveStatusPatch(is_string($currentLifecycleStatus) ? $currentLifecycleStatus : null)
            );
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
                return 'conflict';
            }

            return $isFirstClaim ? 'claimed' : 'saved';
        });

        if ($saveOutcome === 'ownership') {
            $errors['ownership'] = lang('error_ownership_conflict');

            return;
        }

        if ($saveOutcome === 'conflict') {
            $errors['state'] = lang('error_state_conflict');

            return;
        }

        if ($saveOutcome === 'claimed') {
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
