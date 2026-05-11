<?php

/**
 * 그누보드7 웹 인스톨러 유틸리티 함수
 *
 * HTML/JavaScript 이스케이프, 데이터베이스 연결, 권한 검증, 에러 로깅 등의
 * 공통 유틸리티 함수를 제공합니다.
 *
 * @package G7\Installer
 */

/**
 * HTML 이스케이프 함수
 *
 * XSS 공격을 방지하기 위해 HTML 특수 문자를 이스케이프합니다.
 * Laravel 환경에서는 이미 e()가 선언되어 있으므로 중복 선언을 방지합니다.
 */
if (! function_exists('e')) {
    function e(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
    }
}

/**
 * JavaScript 이스케이프 함수
 *
 * JavaScript 컨텍스트에서 안전하게 사용할 수 있도록 값을 이스케이프합니다.
 */
function js_escape(mixed $value): string
{
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        return '""';
    }

    return $json;
}

/**
 * 데이터베이스 연결 생성
 *
 * @param array $config 데이터베이스 연결 정보 배열
 * @param bool $isReadDb Read DB 여부 (true: read, false: write)
 * @throws PDOException 연결 실패 시
 */
function getDatabaseConnection(array $config, bool $isReadDb = false): PDO
{
    $prefix = $isReadDb ? 'db_read' : 'db_write';

    $host = $config["{$prefix}_host"] ?? 'localhost';
    $port = $config["{$prefix}_port"] ?? '3306';
    $database = $config["{$prefix}_database"] ?? '';
    $username = $config["{$prefix}_username"] ?? '';
    $password = $config["{$prefix}_password"] ?? '';

    if (empty($database) || empty($username)) {
        throw new PDOException(lang('error_db_name_username_required'));
    }

    // DSN 키-밸류 구분자(`;`, `=`) 와 NUL 바이트가 포함된 입력을 거부 — DSN 파라미터 주입 차단.
    // 호스트/포트/DB 이름은 정상 입력에서 이 문자가 들어갈 일이 없다.
    foreach ([
        'host' => $host,
        'port' => $port,
        'database' => $database,
    ] as $field => $val) {
        $val = (string) $val;
        if ($val === '' && $field !== 'database') {
            continue;
        }
        if (preg_match('/[;=\0\r\n]/', $val)) {
            throw new PDOException(lang('error_db_invalid_parameter', ['field' => $field]));
        }
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    } catch (PDOException $e) {
        throw new PDOException(lang('error_db_connection_failed', ['error' => $e->getMessage()]), (int) $e->getCode(), $e);
    }
}

/**
 * 데이터베이스 권한 검증
 *
 * @param PDO $pdo PDO 연결 객체
 * @param string $database 데이터베이스명
 * @param bool $isReadDb Read DB 여부 (true: read, false: write)
 * @return array{has_all: bool, required: array<string>, found: array<string>, missing: array<string>}
 */
function checkDatabasePrivileges(PDO $pdo, string $database, bool $isReadDb = false): array
{
    // Read DB는 SELECT만 필요
    $requiredPrivileges = $isReadDb
        ? ['SELECT']
        : ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX'];

    try {
        // 현재 사용자의 권한 조회
        $stmt = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
        $grants = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $foundPrivileges = [];

        foreach ($grants as $grant) {
            $grant = strtoupper($grant);

            // ALL PRIVILEGES 체크
            if (strpos($grant, 'ALL PRIVILEGES') !== false) {
                $foundPrivileges = $requiredPrivileges;
                break;
            }

            // 개별 권한 체크
            foreach ($requiredPrivileges as $privilege) {
                if (strpos($grant, $privilege) !== false) {
                    $foundPrivileges[] = $privilege;
                }
            }
        }

        // 중복 제거
        $foundPrivileges = array_unique($foundPrivileges);

        // 누락된 권한 확인
        $missingPrivileges = array_diff($requiredPrivileges, $foundPrivileges);

        return [
            'has_all' => empty($missingPrivileges),
            'required' => $requiredPrivileges,
            'found' => array_values($foundPrivileges),
            'missing' => array_values($missingPrivileges),
        ];
    } catch (PDOException $e) {
        logInstallationError(lang('error_privilege_check_failed'), $e);

        return [
            'has_all' => false,
            'required' => $requiredPrivileges,
            'found' => [],
            'missing' => $requiredPrivileges,
        ];
    }
}

/**
 * 인스톨러 에러 로깅
 *
 * @param string $message 에러 메시지
 * @param Throwable|null $exception 예외 객체 (선택)
 */
function logInstallationError(string $message, ?Throwable $exception = null): void
{
    $logDir = BASE_PATH . '/storage/logs';
    $logFile = $logDir . '/installer.log';

    // 로그 디렉토리 생성
    if (! is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    // 로그 메시지 구성
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";

    if ($exception !== null) {
        $logMessage .= "\n";
        $logMessage .= "Exception: " . get_class($exception) . "\n";
        $logMessage .= "Message: {$exception->getMessage()}\n";
        $logMessage .= "File: {$exception->getFile()}:{$exception->getLine()}\n";
        $logMessage .= "Trace:\n{$exception->getTraceAsString()}\n";
    }

    $logMessage .= "\n" . str_repeat('-', 80) . "\n\n";

    // 로그 파일에 기록
    @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * 브라우저의 Accept-Language 헤더에서 선호 언어를 감지합니다.
 *
 * @return string|null 감지된 언어 코드 (ko 또는 en), 감지 실패 시 null
 */
function detectBrowserLanguage(): ?string
{
    // HTTP_ACCEPT_LANGUAGE 헤더가 없으면 null 반환
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return null;
    }

    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'];

    // 지원 언어 목록
    $supportedLanguages = ['ko', 'en'];

    // Accept-Language 헤더 파싱 (예: "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7")
    $languages = [];
    foreach (explode(',', $acceptLanguage) as $lang) {
        // q값과 언어 코드 분리
        $parts = explode(';', trim($lang));
        $langCode = strtolower(trim($parts[0]));

        // q값 추출 (없으면 1.0)
        $q = 1.0;
        if (isset($parts[1]) && strpos($parts[1], 'q=') === 0) {
            $q = (float) substr($parts[1], 2);
        }

        // 언어 코드에서 메인 부분만 추출 (ko-KR -> ko)
        $mainLang = explode('-', $langCode)[0];

        $languages[$mainLang] = $q;
    }

    // q값 기준으로 정렬 (내림차순)
    arsort($languages);

    // 지원하는 언어 중에서 가장 우선순위가 높은 언어 반환
    foreach (array_keys($languages) as $lang) {
        if (in_array($lang, $supportedLanguages)) {
            return $lang;
        }
    }

    return null;
}

/**
 * 현재 설정된 언어 코드를 반환합니다.
 *
 * 세션에 저장된 g7_locale 값을 반환하며, 설정되지 않은 경우 기본값으로 'ko'를 반환합니다.
 */
function getCurrentLanguage(): string
{
    return $_SESSION['g7_locale'] ?? 'ko';
}

/**
 * 지정된 언어의 번역 파일을 로드합니다.
 *
 * @param string $lang 언어 코드 (예: 'ko', 'en')
 * @return array<string, mixed> 번역 배열, 파일이 없으면 빈 배열
 */
function loadTranslations(string $lang): array
{
    $langFile = __DIR__ . "/../lang/{$lang}.php";

    if (!file_exists($langFile)) {
        return [];
    }

    $translations = require $langFile;

    return is_array($translations) ? $translations : [];
}

/**
 * 번역 키에 해당하는 번역 문자열을 반환합니다.
 *
 * global $translations 배열에서 키에 해당하는 값을 찾아 반환합니다.
 * 키가 존재하지 않으면 키 자체를 반환합니다.
 *
 * @param string $key 번역 키 (예: 'welcome_title', 'next_step')
 * @param array $replace 치환할 플레이스홀더 배열 (예: ['current' => '8.2', 'min' => '8.2'])
 */
if (! function_exists('lang')) {
    function lang(string $key, array $replace = []): string
    {
        global $translations;

        if (!isset($translations) || !is_array($translations)) {
            return $key;
        }

        $message = $translations[$key] ?? $key;

        // 플레이스홀더 치환 (:placeholder 형식)
        foreach ($replace as $placeholder => $value) {
            $message = str_replace(":{$placeholder}", $value, $message);
        }

        return $message;
    }
}

/**
 * 설치 단계 이름을 반환합니다.
 *
 * @param int $step 단계 번호 (0~5, 6은 예약됨)
 * @return string 단계명 번역 키
 */
function getStepName(int $step): string
{
    $stepNames = [
        0 => 'step_0_welcome',
        1 => 'step_1_license',
        2 => 'step_2_requirements',
        3 => 'step_3_configuration',
        4 => 'step_4_extension_selection',
        5 => 'step_5_installation',
        6 => 'step_6_complete',
    ];

    return $stepNames[$step] ?? 'step_unknown';
}

/**
 * 설치 작업 이름을 반환합니다.
 *
 * @param string $task 작업 ID (예: 'composer_check', 'env_create')
 * @return string 작업명 번역 키
 */
function getTaskName(string $task): string
{
    $taskNames = [
        // 환경 설정 그룹
        'composer_check' => 'task_composer_check',
        'composer_install' => 'task_composer_install',
        'env_create' => 'task_env_create',
        'env_update' => 'task_env_update',
        'key_generate' => 'task_key_generate',
        // 데이터베이스 그룹
        'db_migrate' => 'task_db_migrate',
        'db_seed' => 'task_db_seed',
        // 관리자 템플릿 그룹
        'template_install' => 'task_template_install',
        'template_activate' => 'task_template_activate',
        // 모듈 그룹
        'module_install' => 'task_module_install',
        'module_activate' => 'task_module_activate',
        // 플러그인 그룹
        'plugin_install' => 'task_plugin_install',
        'plugin_activate' => 'task_plugin_activate',
        // 사용자 템플릿 그룹
        'user_template_install' => 'task_user_template_install',
        'user_template_activate' => 'task_user_template_activate',
        // 마무리 그룹
        'create_settings_json' => 'task_create_settings_json',
        'cache_clear' => 'task_cache_clear',
        'complete_flag' => 'task_complete_flag',
    ];

    return $taskNames[$task] ?? 'task_unknown';
}

/**
 * 지정한 Step으로 리다이렉트합니다.
 *
 * @param int $step 이동할 단계 번호
 */
function redirectToStep(int $step): void
{
    $_SESSION['installer_current_step'] = $step;
    header("Location: " . INSTALLER_BASE_URL . "/");
    exit;
}

/**
 * 언어를 변경하고 세션 및 state.json에 저장합니다.
 *
 * 저장 위치:
 * - 세션: PHP 페이지 렌더링 시 사용
 * - state.json: 다른 브라우저에서 재개 시 사용, 설치 완료 후 Laravel 본체에 전달
 * - localStorage: JavaScript에서 직접 관리 (0-welcome.php에서 처리)
 *
 * @param string $lang 언어 코드 ('ko' 또는 'en')
 */
function handleLanguageChange(string $lang): void
{
    // 지원하는 언어만 허용
    if (!array_key_exists($lang, SUPPORTED_LANGUAGES)) {
        return;
    }

    // 세션에 저장
    $_SESSION['g7_locale'] = $lang;

    // state.json에도 저장 (다른 브라우저에서 재개 지원 + 설치 완료 후 본체 전달)
    $state = getInstallationState();
    $state['g7_locale'] = $lang;
    saveInstallationState($state);
}

/**
 * 현재 단계의 상태를 업데이트합니다.
 *
 * @param int $step 완료할 단계 번호
 * @param int $nextStep 다음 단계 번호
 * @param array $additionalData 추가로 저장할 state 데이터 (예: ['config' => $data])
 */
function updateStepStatus(int $step, int $nextStep, array $additionalData = []): void
{
    $state = getInstallationState();
    $state['current_step'] = $nextStep;
    $state['step_status'][$step] = 'completed';

    if (!empty($additionalData)) {
        $state = array_merge($state, $additionalData);
    }

    saveInstallationState($state);
}

/**
 * 설치가 진행 중인지 확인합니다.
 */
function isInstallationRunning(): bool
{
    $state = getInstallationState();
    return isset($state['installation_status']) && $state['installation_status'] === 'running';
}

/**
 * 설치 진행 중 알럿을 표시하고 Step 5로 리다이렉트합니다.
 */
function showInstallationRunningAlert(): void
{
    global $translations;

    // 번역이 로드되지 않은 경우 로드
    if (!isset($translations)) {
        $translations = loadTranslations(getCurrentLanguage());
    }

    $_SESSION['installer_current_step'] = 5;
    showAlertAndRedirect(
        lang('installation_in_progress'),
        lang('installation_in_progress_message'),
        INSTALLER_BASE_URL . '/'
    );
}

/**
 * 설치 완료 알럿을 표시하고 홈으로 리다이렉트합니다.
 */
function showInstallationCompletedAlert(): void
{
    global $translations;

    // 번역이 로드되지 않은 경우 로드
    if (!isset($translations)) {
        $translations = loadTranslations(getCurrentLanguage());
    }

    showAlertAndRedirect(
        lang('installation_already_completed'),
        lang('installation_already_completed_message'),
        '../'
    );
}

/**
 * 알럿을 표시하고 지정된 URL로 리다이렉트합니다.
 *
 * @param string $title 페이지 제목
 * @param string $message 알럿 메시지
 * @param string $redirectUrl 리다이렉트 URL
 */
function showAlertAndRedirect(string $title, string $message, string $redirectUrl): void
{
    global $translations;

    if (!isset($translations)) {
        $translations = loadTranslations(getCurrentLanguage());
    }

    $lang = htmlspecialchars(getCurrentLanguage());
    $title = htmlspecialchars($title);
    $message = htmlspecialchars($message, ENT_QUOTES);
    $redirectUrl = htmlspecialchars($redirectUrl);

    echo '<!DOCTYPE html>';
    echo '<html lang="' . $lang . '">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . $title . '</title>';
    echo '</head>';
    echo '<body>';
    echo '<script>';
    echo 'alert("' . $message . '");';
    echo 'window.location.href = "' . $redirectUrl . '";';
    echo '</script>';
    echo '</body>';
    echo '</html>';
    exit;
}

/**
 * 단계 파일을 찾을 수 없을 때 에러 페이지를 표시합니다.
 *
 * @param int $currentStep 현재 단계 번호
 */
function showStepFileNotFoundError(int $currentStep): void
{
    $previousStep = max(0, $currentStep - 1);
    $_SESSION['installer_current_step'] = $previousStep;

    echo '<div class="installer-container">';
    echo '<h1 class="installer-title">';
    echo '<span class="error-icon">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">';
    echo '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />';
    echo '</svg>';
    echo '</span>';
    echo lang('error');
    echo '</h1>';
    echo '<p class="installer-description">' . lang('error_step_file_not_found') . '</p>';
    echo '<div class="btn-group">';
    echo '<a href="' . INSTALLER_BASE_URL . '/" class="btn btn-secondary">← ' . lang('previous') . '</a>';
    echo '</div>';
    echo '</div>';
}

/**
 * 데이터베이스 필드 해시값을 계산합니다.
 *
 * DB 연결 정보가 변경되었는지 감지하기 위해 사용됩니다.
 *
 * @param array $config 폼 데이터 배열
 * @param string $prefix DB 접두사 ('db_write' 또는 'db_read')
 * @return string MD5 해시값
 */
function getDatabaseFieldHash(array $config, string $prefix): string
{
    return md5(json_encode([
        $config["{$prefix}_host"] ?? '',
        $config["{$prefix}_port"] ?? '',
        $config["{$prefix}_database"] ?? '',
        $config["{$prefix}_username"] ?? ''
    ]));
}

/**
 * SVG 아이콘을 반환합니다.
 *
 * @param string $type 아이콘 타입 ('success', 'warning', 'error')
 * @return string SVG HTML
 */
function getSvgIcon(string $type): string
{
    $icons = [
        'success' => '<svg class="result-icon result-icon-success" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg">
                <circle class="result-icon-circle" cx="26" cy="26" r="25" fill="none"/>
                <path class="result-icon-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
            </svg>',
        'warning' => '<svg class="result-icon result-icon-warning" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="color:#f59e0b">
                <circle class="result-icon-circle" cx="26" cy="26" r="25" fill="none" stroke="currentColor" stroke-width="2"/>
                <path d="M26 12 L26 28" stroke="currentColor" stroke-width="3" stroke-linecap="round" fill="none"/>
                <circle cx="26" cy="36" r="2.5" fill="currentColor"/>
            </svg>',
        'error' => '<svg class="result-icon result-icon-error" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg">
                <circle class="result-icon-circle" cx="26" cy="26" r="25" fill="none"/>
                <path class="result-icon-x" fill="none" d="M16 16 L36 36 M36 16 L16 36"/>
            </svg>',
    ];

    return $icons[$type] ?? '';
}

/**
 * 설치 결과 섹션 HTML을 렌더링합니다.
 *
 * @param string $sectionId 섹션 ID ('completion', 'aborted', 'failure')
 * @param string $iconType 아이콘 타입 ('success', 'warning', 'error')
 * @param string $titleKey 제목 번역 키
 * @param string $messageKey 메시지 번역 키 (failure인 경우 동적)
 * @param string $buttonsHtml 버튼 HTML
 * @param string $extraHtml 메시지 하단에 추가로 렌더링할 HTML (이미 escape 된 상태로 전달)
 * @return string 결과 섹션 HTML
 */
function renderInstallResultSection(string $sectionId, string $iconType, string $titleKey, string $messageKey, string $buttonsHtml, string $extraHtml = ''): string
{
    $icon = getSvgIcon($iconType);
    $title = htmlspecialchars(lang($titleKey));

    // failure는 동적 메시지이므로 번역하지 않음
    $message = $sectionId === 'failure'
        ? '<div class="result-message" id="failure-message"></div>'
        : '<p class="result-message">' . htmlspecialchars(lang($messageKey)) . '</p>';

    return <<<HTML
    <div id="{$sectionId}-section" class="result-section hidden">
        <div class="result-content">
            <div class="result-left">
                {$icon}
                <div class="result-text">
                    <h3 class="result-title">{$title}</h3>
                    {$message}
                    {$extraHtml}
                </div>
            </div>
            <div class="result-button-group">
                {$buttonsHtml}
            </div>
        </div>
    </div>
    HTML;
}

/**
 * 코어 애플리케이션 버전을 추출합니다.
 *
 * 우선순위: .env(설치 후) → .env.example(설치 전) → 'unknown'
 * 인스톨러는 Laravel 부팅 전이므로 config('app.version') 을 쓸 수 없습니다.
 *
 * @return string 코어 버전 문자열 (예: '7.0.0-beta.4')
 */
function getCoreAppVersion(): string
{
    foreach (['/.env', '/.env.example'] as $relative) {
        $path = BASE_PATH . $relative;
        if (!is_readable($path)) {
            continue;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            continue;
        }
        if (preg_match('/^APP_VERSION=(.+)$/m', $content, $matches)) {
            return trim($matches[1], "\"' \t\r\n");
        }
    }

    return 'unknown';
}

/**
 * Progress indicator HTML을 렌더링합니다.
 *
 * @param int $currentStep 현재 단계 번호
 * @param int $totalSteps 전체 단계 수 (기본값: 7, Step 0~6)
 * @return string Progress indicator HTML
 */
function renderProgressIndicator(int $currentStep, int $totalSteps = 7): string
{
    $html = '<div class="progress-indicator">';
    for ($i = 0; $i < $totalSteps; $i++) {
        $class = 'progress-step';
        if ($i < $currentStep) {
            $class .= ' completed';
        } elseif ($i === $currentStep) {
            $class .= ' active';
        }
        $html .= '<div class="' . $class . '"></div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * 현재 PHP 프로세스를 실행 중인 웹 서버 사용자명을 감지합니다.
 *
 * posix 확장 → exec('whoami') → 환경변수 순서로 시도하며,
 * 모두 실패하면 null을 반환합니다.
 *
 * @return string|null 웹 서버 사용자명 (예: 'www-data', 'nginx', 'apache') 또는 null
 */
function getWebServerUser(): ?string
{
    // 1. posix 확장 (가장 신뢰도 높음)
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if ($info && !empty($info['name'])) {
            return $info['name'];
        }
    }

    // 2. exec('whoami')
    if (function_exists('exec')) {
        $user = @exec('whoami');
        if (!empty($user)) {
            return trim($user);
        }
    }

    // 3. 환경변수
    $envUser = getenv('USER') ?: getenv('APACHE_RUN_USER') ?: null;
    if (!empty($envUser)) {
        return $envUser;
    }

    return null;
}

/**
 * 현재 PHP 프로세스를 실행 중인 웹 서버 그룹명을 감지합니다.
 *
 * @return string|null 웹 서버 그룹명 (예: 'www-data', 'nginx') 또는 null
 */
function getWebServerGroup(): ?string
{
    if (function_exists('posix_getegid') && function_exists('posix_getgrgid')) {
        $info = posix_getgrgid(posix_getegid());
        if ($info && !empty($info['name'])) {
            return $info['name'];
        }
    }

    $envGroup = getenv('APACHE_RUN_GROUP') ?: null;
    if (!empty($envGroup)) {
        return $envGroup;
    }

    return null;
}

/**
 * 파일/디렉토리의 소유자 이름을 반환합니다.
 *
 * posix 확장이 없거나 확인할 수 없는 경우 null을 반환합니다.
 *
 * @param string $path 파일/디렉토리 경로
 * @return string|null 소유자 이름 또는 null
 */
function getFileOwnerName(string $path): ?string
{
    $uid = @fileowner($path);
    if ($uid === false) {
        return null;
    }

    if (function_exists('posix_getpwuid')) {
        $info = @posix_getpwuid($uid);
        if ($info && !empty($info['name'])) {
            return $info['name'];
        }
    }

    // posix 확장이 없으면 UID를 문자열로 반환
    return (string) $uid;
}

/**
 * 파일/디렉토리의 소유 그룹 이름을 반환합니다.
 *
 * posix 확장이 없거나 확인할 수 없는 경우 null을 반환합니다.
 *
 * @param string $path 파일/디렉토리 경로
 * @return string|null 그룹 이름 또는 null
 */
function getFileGroupName(string $path): ?string
{
    $gid = @filegroup($path);
    if ($gid === false) {
        return null;
    }

    if (function_exists('posix_getgrgid')) {
        $info = @posix_getgrgid($gid);
        if ($info && !empty($info['name'])) {
            return $info['name'];
        }
    }

    // posix 확장이 없으면 GID를 문자열로 반환
    return (string) $gid;
}

/**
 * chown 명령어에 사용할 user:group 문자열을 반환합니다.
 *
 * 감지 실패 시 'www-data:www-data'를 기본값으로 사용합니다.
 *
 * @return string 'user:group' 형식 문자열
 */
function getChownTarget(): string
{
    $user = getWebServerUser() ?? 'www-data';
    $group = getWebServerGroup() ?? $user;

    return $user . ':' . $group;
}

/**
 * 디렉토리 권한을 체크합니다.
 *
 * Step 0과 Step 2에서 재사용 가능한 공통 함수입니다.
 * 디렉토리를 생성하지 않고 존재 여부와 권한만 체크합니다.
 *
 * @param array<string, bool> $directories 체크할 디렉토리 경로 배열 (키: 경로, 값: 재귀 체크 여부)
 * @return array{all_passed: bool, results: array<string, array{exists: bool, writable: bool, permissions: string, required_permissions: string, passed: bool, has_subdirectory_issues: bool, failed_subdirectories: array<string>}>}
 */
function checkDirectoryPermissions(array $directories): array
{
    // 파일 시스템 캐시 클리어 (권한 체크 결과가 캐싱되는 것을 방지)
    clearstatcache(true);

    $results = [];
    $allPassed = true;
    $requiredPermsDisplay = REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY;

    // 웹서버 실행 사용자 (ownership_mismatch 판별용)
    $webServerUser = getWebServerUser();

    foreach ($directories as $path => $checkRecursive) {
        $fullPath = BASE_PATH . '/' . $path;

        // 디렉토리 존재 여부 확인 (생성하지 않음)
        $exists = is_dir($fullPath);

        // 실제 동작 기준 검증: 비트 값이 아닌 is_writable/is_readable로 판정
        // 업계 표준에 따라 어떤 권한 조합이든 실제 동작하면 통과 (0755/0775/0770/0777 등)
        $writable = $exists && is_writable($fullPath);
        $readable = $exists && is_readable($fullPath);

        // 권한 코드는 정보 표시용으로만 유지
        $permissions = 'N/A';
        $permsOctal = 0;
        if ($exists) {
            $perms = fileperms($fullPath);
            $permissions = substr(sprintf('%o', $perms), -3);
            $permsOctal = octdec($permissions);
        }

        // 소유자/소유그룹 정보 가져오기
        $owner = null;
        $group = null;
        if ($exists) {
            $owner = getFileOwnerName($fullPath);
            $group = getFileGroupName($fullPath);
        }

        // 재귀 체크 여부 확인
        $failedSubdirectories = [];
        $hasSubdirectoryIssues = false;

        if ($checkRecursive && $exists) {
            // 디렉토리가 존재하면 하위 체크 (writable 여부와 무관하게)
            $failedSubdirectories = checkSubdirectoriesRecursive($fullPath);
            $hasSubdirectoryIssues = count($failedSubdirectories) > 0;
        }

        // 상위 디렉토리 자체의 통과 여부 — is_writable && is_readable만 충족하면 OK
        $parentPassed = $writable && $readable;

        // 최종 통과 여부: 상위 + 하위 모두 통과해야 함
        $passed = $parentPassed && !$hasSubdirectoryIssues;

        // 에러 타입 구분
        // ownership_mismatch: 권한 비트는 0755 이상이지만 소유자가 웹서버 사용자와 달라
        //                     실제 쓰기 불가 (전통적 Apache: 파일 소유자 != www-data)
        $errorType = null;
        if (!$exists) {
            $errorType = 'not_exists';
        } elseif (!$writable) {
            // 권한 비트가 충분(0755+)한데도 쓰기 불가 → 소유권 불일치 가능성
            if ($permsOctal >= 0755 && $webServerUser && $owner && $owner !== $webServerUser) {
                $errorType = 'ownership_mismatch';
            } else {
                $errorType = 'not_writable';
            }
        } elseif (!$readable) {
            $errorType = 'not_readable';
        } elseif ($hasSubdirectoryIssues) {
            // 상위는 OK, 하위 디렉토리만 문제
            $errorType = 'subdirectory_issues';
        }

        $results[$path] = [
            'exists' => $exists,
            'writable' => $writable,
            'readable' => $readable,
            'permissions' => $permissions,
            'required_permissions' => $requiredPermsDisplay,
            'owner' => $owner,
            'group' => $group,
            'web_server_user' => $webServerUser,
            'passed' => $passed,
            'error_type' => $errorType,
            'has_subdirectory_issues' => $hasSubdirectoryIssues,
            'failed_subdirectories' => $failedSubdirectories,
        ];

        if (!$passed) {
            $allPassed = false;
        }
    }

    return [
        'all_passed' => $allPassed,
        'results' => $results,
    ];
}

/**
 * 디렉토리의 하위를 재귀적으로 체크하여 권한이 없는 경로와 권한 정보 반환
 *
 * @param string $path 체크할 디렉토리 경로
 * @return array<array{path: string, permissions: string}> 권한이 없는 하위 디렉토리 정보 배열
 */
function checkSubdirectoriesRecursive(string $path): array
{
    $failedPaths = [];

    if (!is_dir($path)) {
        return $failedPaths;
    }

    // scandir를 사용하여 디렉토리 목록 가져오기 (읽을 수 없으면 빈 배열 반환)
    try {
        $items = @scandir($path);
        if ($items === false) {
            // 디렉토리를 읽을 수 없으면 빈 배열 반환
            return $failedPaths;
        }
    } catch (Exception $e) {
        return $failedPaths;
    }

    foreach ($items as $item) {
        // . 및 .. 제외
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path . DIRECTORY_SEPARATOR . $item;

        // 디렉토리인지 확인 (심볼릭 링크 제외)
        if (!is_dir($itemPath) || is_link($itemPath)) {
            continue;
        }

        // 절대 경로를 상대 경로로 변환
        $relativePath = str_replace(BASE_PATH . '/', '', $itemPath);
        $relativePath = str_replace('\\', '/', $relativePath);

        // 쓰기 권한 체크
        $isWritable = is_writable($itemPath);

        if (!$isWritable) {
            // 권한 코드 가져오기
            $perms = fileperms($itemPath);
            $permissions = substr(sprintf('%o', $perms), -3);

            $failedPaths[] = [
                'path' => $relativePath,
                'permissions' => $permissions,
            ];
        }

        // 재귀적으로 하위 디렉토리 체크 (권한 없어도 계속 진행)
        try {
            $subFailed = checkSubdirectoriesRecursive($itemPath);
            $failedPaths = array_merge($failedPaths, $subFailed);
        } catch (Exception $e) {
            // 하위 디렉토리 체크 실패해도 계속 진행
        }
    }

    return $failedPaths;
}

/**
 * 라이선스 파일 내용을 로드합니다.
 *
 * 루트 LICENSE 파일을 읽습니다. (한국어 번역 + 영문 원문 통합)
 *
 * @param string $lang 언어 코드 (미사용, 하위 호환성 유지)
 * @return string 라이선스 파일 내용
 */
function loadLicenseFile(string $lang): string
{
    $licenseFile = __DIR__ . '/../../../LICENSE';

    if (file_exists($licenseFile)) {
        return file_get_contents($licenseFile);
    }

    return lang('license_not_found');
}

/**
 * .env 값 이스케이프
 *
 * 특수 문자가 포함된 값을 .env 파일에 안전하게 기록할 수 있도록 큰따옴표로 감싸고
 * 내부 큰따옴표와 백슬래시를 이스케이프합니다.
 *
 * @param string $value 이스케이프할 값
 * @return string 큰따옴표로 감싸고 내부 큰따옴표와 백슬래시를 이스케이프한 값
 */
function escapeEnvValue(string $value): string
{
    // 개행 문자는 .env 라인 구분자이므로 사용자 입력에 포함되면 추가 변수 라인이 주입될 수 있다.
    // CR/LF 모두 제거 — DB 비밀번호·바이너리 경로·토큰 등 정상 값에는 개행이 들어갈 일이 없다.
    if ($value !== '') {
        $value = str_replace(["\r", "\n"], '', $value);
    }

    // 빈 값은 빈 따옴표로
    if ($value === '') {
        return '""';
    }

    // 큰따옴표와 백슬래시를 이스케이프
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

    // 큰따옴표로 감싸기
    return '"' . $escaped . '"';
}

/**
 * state.json의 config 값을 기반으로 .env 내용을 생성합니다.
 *
 * .env.example 템플릿의 플레이스홀더를 사용자 입력값으로 치환합니다.
 * APP_KEY는 치환하지 않음 — artisan key:generate로 별도 생성합니다.
 *
 * @return string|null .env 내용 문자열, .env.example 없으면 null
 */
function generateEnvContent(): ?string
{
    $envExamplePath = BASE_PATH . '/.env.example';
    if (!file_exists($envExamplePath)) {
        return null;
    }

    $envContent = file_get_contents($envExamplePath);
    $state = getInstallationState();
    $config = $state['config'] ?? [];

    // 데이터베이스 설정 치환
    $replacements = [
        'DB_CONNECTION=mysql' => 'DB_CONNECTION=mysql',
        'DB_WRITE_HOST=127.0.0.1' => 'DB_WRITE_HOST=' . ($config['db_write_host'] ?? '127.0.0.1'),
        'DB_WRITE_PORT=3306' => 'DB_WRITE_PORT=' . ($config['db_write_port'] ?? '3306'),
        'DB_WRITE_DATABASE=g7' => 'DB_WRITE_DATABASE=' . ($config['db_write_database'] ?? 'g7'),
        'DB_WRITE_USERNAME=root' => 'DB_WRITE_USERNAME=' . ($config['db_write_username'] ?? 'root'),
        'DB_WRITE_PASSWORD=' => 'DB_WRITE_PASSWORD=' . escapeEnvValue($config['db_write_password'] ?? ''),
        'DB_PREFIX=g7_' => 'DB_PREFIX=' . ($config['db_prefix'] ?? 'g7_'),
    ];

    // Read DB 설정
    if (!empty($config['use_read_db']) && $config['use_read_db']) {
        $replacements['DB_READ_HOST='] = 'DB_READ_HOST=' . ($config['db_read_host'] ?? '127.0.0.1');
        $replacements['DB_READ_PORT='] = 'DB_READ_PORT=' . ($config['db_read_port'] ?? '3306');
        $replacements['DB_READ_DATABASE='] = 'DB_READ_DATABASE=' . ($config['db_read_database'] ?? 'g7');
        $replacements['DB_READ_USERNAME='] = 'DB_READ_USERNAME=' . ($config['db_read_username'] ?? 'root');
        $replacements['DB_READ_PASSWORD='] = 'DB_READ_PASSWORD=' . escapeEnvValue($config['db_read_password'] ?? '');
    } else {
        // Read DB를 사용하지 않는 경우 Write DB 정보와 동일하게 설정
        $replacements['DB_READ_HOST='] = 'DB_READ_HOST=' . ($config['db_write_host'] ?? '127.0.0.1');
        $replacements['DB_READ_PORT='] = 'DB_READ_PORT=' . ($config['db_write_port'] ?? '3306');
        $replacements['DB_READ_DATABASE='] = 'DB_READ_DATABASE=' . ($config['db_write_database'] ?? 'g7');
        $replacements['DB_READ_USERNAME='] = 'DB_READ_USERNAME=' . ($config['db_write_username'] ?? 'root');
        $replacements['DB_READ_PASSWORD='] = 'DB_READ_PASSWORD=' . escapeEnvValue($config['db_write_password'] ?? '');
    }

    // 앱 설정 치환
    $replacements['APP_NAME=그누보드7'] = 'APP_NAME="' . ($config['app_name'] ?? '그누보드7') . '"';
    $replacements['APP_ENV=production'] = 'APP_ENV=' . ($config['app_env'] ?? 'production');
    $replacements['APP_URL=http://localhost'] = 'APP_URL=' . ($config['app_url'] ?? 'http://localhost');

    // 언어 설정
    $currentLang = getCurrentLanguage();
    $replacements['APP_LOCALE=ko'] = 'APP_LOCALE=' . $currentLang;
    $replacements['APP_FAKER_LOCALE=ko_KR'] = 'APP_FAKER_LOCALE=' . ($currentLang === 'ko' ? 'ko_KR' : 'en_US');

    if (isset($config['app_env']) && $config['app_env'] !== 'production') {
        $replacements['APP_DEBUG=false'] = 'APP_DEBUG=true';
    }

    // 코어 업데이트 설정 치환
    $corePendingPath = $config['core_update_pending_path'] ?? '';
    $coreGithubUrl = $config['core_update_github_url'] ?? 'https://github.com/gnuboard/g7';
    $coreGithubToken = $config['core_update_github_token'] ?? '';

    $replacements['G7_UPDATE_PENDING_PATH='] = 'G7_UPDATE_PENDING_PATH=' . escapeEnvValue($corePendingPath);
    $replacements['G7_UPDATE_GITHUB_URL=https://github.com/gnuboard/g7'] = 'G7_UPDATE_GITHUB_URL=' . $coreGithubUrl;
    $replacements['G7_UPDATE_GITHUB_TOKEN='] = 'G7_UPDATE_GITHUB_TOKEN=' . escapeEnvValue($coreGithubToken);

    // PHP CLI / Composer 바이너리 경로 치환
    $phpBinary = $config['php_binary'] ?? 'php';
    $composerBinary = $config['composer_binary'] ?? '';
    $replacements['PHP_BINARY=php'] = 'PHP_BINARY=' . escapeEnvValue($phpBinary);
    $replacements['COMPOSER_BINARY='] = 'COMPOSER_BINARY=' . escapeEnvValue($composerBinary);

    foreach ($replacements as $search => $replace) {
        $envContent = str_replace($search, $replace, $envContent);
    }

    return $envContent;
}

/**
 * 현재 서버가 Windows 환경인지 확인합니다.
 *
 * @return bool Windows인 경우 true
 */
function isWindows(): bool
{
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

/**
 * OS에 맞는 .env 파일 복사 명령어를 반환합니다.
 *
 * @param string $basePath 프로젝트 루트 경로 (빈 문자열이면 상대 경로 사용)
 * @return string 복사 명령어
 */
function getEnvCopyCommand(string $basePath = ''): string
{
    if (isWindows()) {
        $source = $basePath ? $basePath . '\\.env.example' : '.env.example';
        $dest = $basePath ? $basePath . '\\.env' : '.env';

        return 'copy ' . str_replace('/', '\\', $source) . ' ' . str_replace('/', '\\', $dest);
    }

    $source = $basePath ? $basePath . '/.env.example' : '.env.example';
    $dest = $basePath ? $basePath . '/.env' : '.env';

    return 'cp ' . $source . ' ' . $dest;
}

/**
 * OS에 맞는 권한 수정 명령어를 반환합니다.
 *
 * 업계 표준(WordPress/Drupal/Joomla/Laravel)에 맞춰 디렉토리 755, 파일 644로 통일.
 * chown/setgid 의존성을 제거하여 공유호스팅 호환성 확보.
 * 실제 통과 기준은 is_writable() && is_readable()이므로 비트 값은 참고용.
 *
 * @param string $pathList 대상 경로 (공백 구분)
 * @param string $mode 'ownership' (디렉토리) 또는 'file' (파일)
 * @return string 권한 수정 명령어
 */
function getPermissionFixCommand(string $pathList, string $mode = 'ownership'): string
{
    if (isWindows()) {
        $winPath = str_replace('/', '\\', $pathList);
        if ($mode === 'file') {
            return 'icacls ' . $winPath . ' /grant Everyone:F';
        }

        return 'icacls ' . $winPath . ' /grant Everyone:(OI)(CI)F /T';
    }

    if ($mode === 'file') {
        return 'chmod 644 ' . $pathList;
    }

    return 'chmod -R 755 ' . $pathList;
}


/**
 * 기존 DB 테이블 감지 (이슈 #244 대응).
 *
 * Write DB에 테이블이 존재하는지 확인하고, G7 시그니처 테이블과 비교하여
 * 설치 진행 가능 여부를 판정합니다.
 *
 * severity:
 * - 'empty'        : 빈 DB (정상 진행)
 * - 'g7_existing'  : G7 시그니처 4개 모두 존재 (기존 설치 감지)
 * - 'mixed'        : G7 일부 + 기타 혼재
 * - 'foreign_data' : G7 시그니처 없음 + 기타 테이블 존재
 *
 * @param PDO $pdo 연결된 PDO 인스턴스
 * @param string $database 대상 데이터베이스명
 * @return array
 */
function checkExistingTables(PDO $pdo, string $database, string $tablePrefix = ''): array
{
    try {
        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (PDOException $e) {
        return [
            'has_tables' => false,
            'is_g7_install' => false,
            'g7_tables_found' => [],
            'other_tables_count' => 0,
            'all_tables' => [],
            'severity' => 'empty',
            'error' => $e->getMessage(),
        ];
    }

    if (empty($tables)) {
        return [
            'has_tables' => false,
            'is_g7_install' => false,
            'g7_tables_found' => [],
            'other_tables_count' => 0,
            'all_tables' => [],
            'severity' => 'empty',
        ];
    }

    // G7 핵심 시그니처 테이블 — PO 결정: 4개 모두 일치 시 "G7 설치됨"으로 판단
    // 테이블 prefix를 적용하여 비교 (예: prefix='g7_'이면 'g7_users', 'g7_migrations' 등)
    $coreSignatures = ['users', 'migrations', 'roles', 'permissions'];
    $g7Signatures = array_map(fn ($t) => $tablePrefix . $t, $coreSignatures);
    $g7TablesFound = array_values(array_intersect($g7Signatures, $tables));

    $severity = 'foreign_data';
    if (count($g7TablesFound) >= 4) {
        $severity = 'g7_existing';
    } elseif (count($g7TablesFound) > 0) {
        $severity = 'mixed';
    }

    return [
        'has_tables' => true,
        'is_g7_install' => $severity === 'g7_existing',
        'g7_tables_found' => $g7TablesFound,
        'other_tables_count' => max(0, count($tables) - count($g7TablesFound)),
        'all_tables' => $tables,
        'severity' => $severity,
    ];
}
