<?php

use App\Support\ExecutionEnvironment;
use App\Support\PrivilegedDatabaseAccounts;

/**
 * 그누보드7 웹 인스톨러 요청 처리 핸들러
 *
 * 모든 POST 요청 및 비즈니스 로직을 처리합니다.
 */

require_once __DIR__.'/installer-ownership.php';

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
 * @param  string|null  &$error  에러 메시지 (참조) — 노출됨 판정이면 여기 실리고 단계는 넘어가지 않는다
 */
function handleStep2Post(?string &$error = null): void
{
    if (isset($_POST['proceed'])) {
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
 * 빈 값(설정 누락)과 DB 최고권한 계정을 모두 거부합니다. 최고권한 계정 자격증명이
 * 유출되면 데이터베이스 전체가 위험해지므로 설치 단계에서 차단합니다.
 * 판정은 App\Support\PrivilegedDatabaseAccounts 가 SSoT 입니다.
 *
 * @param  string  $username  입력된 DB 사용자명
 * @param  string  $field  에러 배열에 사용할 필드명 (db_write_username 등)
 * @param  array  &$errors  에러 배열 (참조)
 */
function validateDbUsername(string $username, string $field, array &$errors): void
{
    $username = trim($username);

    if ($username === '') {
        $errors[$field] = lang('error_db_username_required');

        return;
    }

    if (PrivilegedDatabaseAccounts::isBlocked($username)) {
        $errors[$field] = lang('error_db_username_privileged', ['username' => $username]);
    }
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
    unset($formData['csrf_token']);
    $formData['use_read_db'] = isset($_POST['use_read_db']) ? true : false;

    // Write DB 검증
    if (empty($formData['db_write_host'])) {
        $errors['db_write_host'] = lang('error_db_host_required');
    }
    if (empty($formData['db_write_database'])) {
        $errors['db_write_database'] = lang('error_db_name_required');
    }
    validateDbUsername($formData['db_write_username'] ?? '', 'db_write_username', $errors);

    // Read DB 검증 (사용하는 경우)
    if (! empty($formData['use_read_db'])) {
        if (empty($formData['db_read_host'])) {
            $errors['db_read_host'] = lang('error_db_host_required');
        }
        if (empty($formData['db_read_database'])) {
            $errors['db_read_database'] = lang('error_db_name_required');
        }
        validateDbUsername($formData['db_read_username'] ?? '', 'db_read_username', $errors);
    }

    // 사이트명 길이 — 이 값은 `.env` 의 APP_NAME 과 설정의 site_name 으로 그대로 흘러가
    // 설치 직후 관리자·사용자 화면의 입력이 된다. 화면의 maxlength 는 거들 수 있으므로
    // 서버가 같은 상한을 다시 강제한다.
    if (mb_strlen((string) ($formData['app_name'] ?? '')) > MAX_APP_NAME_LENGTH) {
        $errors['app_name'] = lang('error_app_name_too_long', ['max' => MAX_APP_NAME_LENGTH]);
    }

    // 관리자 정보 검증
    if (empty($formData['admin_email']) || ! filter_var($formData['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['admin_email'] = lang('error_admin_email_invalid');
    }
    if (! array_key_exists($formData['admin_language'] ?? '', SUPPORTED_LANGUAGES)) {
        $errors['admin_language'] = lang('error_admin_language_invalid');
    }
    if (empty($formData['admin_password']) || strlen($formData['admin_password']) < 8) {
        $errors['admin_password'] = lang('error_admin_password_min');
    }
    if ($formData['admin_password'] !== $formData['admin_password_confirm']) {
        $errors['admin_password_confirm'] = lang('error_password_mismatch');
    }

    // PHP CLI / Composer 경로 처리 — 저장 시점에 허용 형태를 강제한다.
    // 이 값들은 설치 워커에서 실제 명령의 실행 바이너리가 되므로, 형태 위반 값이
    // 애초에 .env / 설치 상태에 기록되지 않게 여기서 막는다.
    require_once __DIR__.'/binary-path-policy.php';

    $phpBinary = trim($formData['php_binary'] ?? 'php');
    $phpBinary = $phpBinary !== '' ? $phpBinary : 'php';
    if ($phpBinary !== 'php' && ! installer_binary_path_shape_ok($phpBinary)) {
        $errors['php_binary'] = lang('error_php_binary_path_not_allowed', ['path' => $phpBinary]);
    }

    // 운영자가 **직접 지정한** 경로가 CLI 가 아니면 저장하지 않는다 (13차 보안#7 ②).
    // `php-fpm --version` · `php-cgi --version` 은 CLI 와 똑같이 정상 종료하고 같은 형태로
    // 버전을 찍으므로 형태·버전 검사로는 구분되지 않는다 — 그런데 그 바이너리는 artisan 을
    // 받지 못해 설치 태스크가 전부 실패한다. 종전에는 화면에 경고만 띄우고 그 값을 그대로
    // state 에 박았다. 자동 탐색 경로(입력을 비워 둔 경우)는 코어 판정이 CLI 후보를 먼저
    // 고르므로 이 검사의 대상이 아니다 — php-cgi 로만 동작하던 환경을 새로 막지 않는다.
    if (! isset($errors['php_binary']) && $phpBinary !== 'php') {
        require_once __DIR__.'/execution-mode.php';
        installerEnsureExecutionEnvironmentLoaded();

        $probe = ExecutionEnvironment::probePhpBinary($phpBinary);
        $probedSapi = $probe['sapi'] ?? null;

        if (($probe['status'] ?? '') === 'valid' && is_string($probedSapi) && $probedSapi !== 'cli') {
            $errors['php_binary'] = lang('error_php_binary_not_cli_rejected', [
                'path' => $phpBinary,
                'sapi' => $probedSapi,
            ]);
        }
    }

    $formData['php_binary'] = $phpBinary;

    $composerBinary = trim($formData['composer_binary'] ?? '');
    if ($composerBinary !== '' && $composerBinary !== 'composer') {
        // 단일 토큰이면 Composer 자리 규칙, 공백 분리 입력이면 (PHP, Composer) 쌍 해석.
        $allowed = str_contains($composerBinary, ' ')
            ? installer_resolve_php_composer_pair($composerBinary) !== null
            : installer_is_composer_binary_path($composerBinary);

        if (! $allowed) {
            $errors['composer_binary'] = lang('error_composer_binary_path_not_allowed', ['path' => $composerBinary]);
        }
    }
    $formData['composer_binary'] = $composerBinary;

    // Vendor 설치 모드 처리 (auto|composer|bundled)
    $vendorMode = trim($formData['vendor_mode'] ?? 'auto');
    if (! in_array($vendorMode, ['auto', 'composer', 'bundled'], true)) {
        $vendorMode = 'auto';
    }

    // 실행 모드 (auto|subprocess|inprocess, 기본 auto — D33). 저장되는 것은 "선택" 이고
    // auto 는 감지 결과를 박지 않는다 (A-0 ①). inprocess 가 유효하면 composer 는 성립하지
    // 않으므로 Vendor 설치 방식을 bundled 로 고정한다 (A-4) — 화면이 보내는 값만 믿지
    // 않고 저장 시점에 다시 판정한다 (R9: 모드가 관여하는 지점은 모드를 읽는다).
    require_once __DIR__.'/execution-mode.php';

    $formData['execution_mode'] = installerNormalizeExecutionMode($formData['execution_mode'] ?? 'auto');

    // 명시 선택은 성립 여부를 저장 시점에 검사한다 (A-4 대칭). 화면이 고를 수 없게 막는 것만으로는
    // 부족하다 — 재감지 요청이 조용히 실패한 뒤·Step 3 저장과 설치 시작 사이에 환경이 바뀐 뒤·
    // 개발자 도구로 disabled 를 지운 뒤에는 서버가 유일한 판정자다. 여기서 막지 않으면 실패가
    // 설치 중반(태스크 실행)에야 드러나고, 그때의 복구는 롤백/재시도다.
    if ($formData['execution_mode'] !== 'auto') {
        $executionRejection = installerExecutionModeRejection(
            $formData['execution_mode'],
            installerDetectExecutionMode($phpBinary !== '' ? $phpBinary : 'php'),
        );

        if ($executionRejection !== null) {
            $errors['execution_mode'] = lang($executionRejection);
        }
    }

    $executionResolution = installerResolveExecutionMode([
        'execution_mode' => $formData['execution_mode'],
        'php_binary' => $phpBinary,
    ]);
    $formData['vendor_mode'] = installerCoerceVendorModeForExecution($vendorMode, $executionResolution['mode']);

    // 자산 URL 방식 (이슈 #486) — Step 3 의 브라우저 프로브가 채운 hidden 필드.
    // 정적 최적화 블록이 있는 서버는 확장자 붙은 동적 응답이 PHP 에 도달하지 못하므로
    // 설치 시점에 확장자 없는 형태로 확정해야 첫 화면부터 정상 동작한다.
    // 판정 불가(프로브 실패·JS 미실행)면 키를 비워 defaults.json 기본값을 따르게 한다.
    $assetUrlMode = trim($formData['asset_url_mode'] ?? '');
    $formData['asset_url_mode'] = in_array($assetUrlMode, ['extension', 'extensionless'], true)
        ? $assetUrlMode
        : '';

    // .env 로 기록되는 사용자 입력의 사전 거부 (KVE-2026-2042)
    // 직렬화기가 최종 관문이지만, 그 단계의 실패는 설치 진행 중에 예외로 드러나 운영자가
    // 어느 입력이 문제인지 알기 어렵다. 여기서 필드별로 거부해 화면에 사유를 표시한다.
    require_once __DIR__.'/env-value.php';

    foreach (['app_name', 'app_url', 'core_update_github_url'] as $envField) {
        if (! installer_env_value_is_single_line((string) ($formData[$envField] ?? ''))) {
            $errors[$envField] = lang('error_env_value_line_break');
        }
    }

    // URL 필드는 형태까지 확인한다 — 개행이 없어도 스킴이 없는 값이 그대로 기록되면
    // 배포 후 절대 URL 생성이 어긋난다.
    foreach (['app_url', 'core_update_github_url'] as $urlField) {
        $urlValue = trim((string) ($formData[$urlField] ?? ''));
        if ($urlValue === '' || isset($errors[$urlField])) {
            continue;
        }

        // FILTER_VALIDATE_URL 은 호스트명의 밑줄(예: my_site.test)을 거부하므로 쓰지 않는다 —
        // 로컬 개발 도메인에 흔한 형태라 스킴·호스트 존재와 공백 부재만 확인한다.
        $parts = parse_url($urlValue);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || preg_match('/\s/', $urlValue)) {
            $errors[$urlField] = lang('error_env_value_invalid_url', ['value' => $urlValue]);
        }
    }

    // 코어 업데이트 _pending 경로 검증 (입력된 경우만) — 검사 버튼(check-core-pending-path)과
    // **같은 규칙**이어야 한다 (21차 #21-4). 종전에는 저장 경로만 `..`/NUL 거부·Windows
    // 절대경로 인식·stat 진단 억제가 전부 빠져 있었다: Windows 절대경로(`D:\pending`)를
    // 상대경로로 오인해 검사 버튼이 초록을 준 경로와 다른 경로를 검증했고, open_basedir
    // 환경에서는 무억제 stat 의 warning 이 redirect 헤더보다 먼저 나가 화면이 Step 3 에
    // 고착됐다(상태는 이미 Step 4 로 넘어간 뒤라 새로고침해야만 풀린다).
    $corePendingPath = trim($formData['core_update_pending_path'] ?? '');
    if ($corePendingPath !== '') {
        $hasParentSegment = preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $corePendingPath) === 1;

        if ($hasParentSegment || str_contains($corePendingPath, "\0")) {
            $errors['core_update_pending_path'] = lang('error_core_pending_path_invalid');
        } else {
            // 절대 경로 변환 — 검사 버튼과 같은 판정 (POSIX `/` + Windows 드라이브 문자)
            $absolutePath = (str_starts_with($corePendingPath, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $corePendingPath) === 1)
                ? $corePendingPath
                : BASE_PATH.'/'.$corePendingPath;

            // 운영자 임의 경로라 open_basedir 밖이 정상 시나리오 — 판정은 옳고 진단만 억제 (20차 #9 규칙)
            if (@file_exists($absolutePath) && ! @is_dir($absolutePath)) {
                $errors['core_update_pending_path'] = lang('error_core_pending_not_directory');
            }
        }
    }

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
