<?php

use App\Support\ExecutionEnvironment;
use App\Support\PrivilegedDatabaseAccounts;

/**
 * 그누보드7 인스톨러 - Step 3 설정 검증 (웹·터미널 공유)
 *
 * 웹 Step 3 저장(`handleStep3Post()`)과 터미널 설치(`php g7-install`)가 **같은 함수**로 입력을 검증한다.
 * 한쪽에만 규칙을 더하면 다른 쪽이 우회로가 되므로, 검증 규칙은 이 파일에서만 바꾼다.
 *
 * 브라우저에서만 하던 검사(사이트 이름·관리자 이름 필수, 테이블 접두사 형식, vendor 설치 방식 가용성)도
 * 서버에서 한다 — HTTP 직접 호출이나 터미널 입력으로 화면 검사를 건너뛸 수 있기 때문이다.
 *
 * 세션·CSRF·DB 테스트 지문·소유권·상태 저장은 호출자(웹 껍데기) 몫이다. 이 함수는 상태를 바꾸지 않는다.
 */

require_once __DIR__.'/binary-path-policy.php';
require_once __DIR__.'/env-value.php';
// 함수 가용성 판정(installerHasFunction)·번들 가용성(installerVendorBundleUsable)·실행 방식 판정은
// execution-mode.php 소유다. 터미널 진입(cli/app.php)과 웹 Step 3 저장이 이 파일을 먼저 싣는 순서에
// 기대지 않도록 여기서 직접 보장한다.
require_once __DIR__.'/execution-mode.php';

if (! function_exists('installer_field_label')) {
    /**
     * 번역 테이블의 필드 표시 이름을 반환한다 (`fields.{name}`, 없으면 필드명).
     *
     * @param  string  $field  필드명
     * @return string 표시 이름
     */
    function installer_field_label(string $field): string
    {
        global $translations;

        $label = is_array($translations['fields'] ?? null) ? ($translations['fields'][$field] ?? null) : null;

        return is_string($label) && $label !== '' ? $label : $field;
    }
}

if (! function_exists('installer_vendor_mode_availability')) {
    /**
     * vendor 설치 방식별 사용 가능 여부 — 웹 Step 3 의 라디오 비활성 판정과 같은 식.
     *
     * 판정은 화면(views/3-configuration.php)과 같은 단일 지점을 쓴다: proc_open 가용성은
     * installerHasFunction()(ExecutionEnvironment::hasFunction 위임 — function_exists 와 disable_functions 를
     * 함께 본다), 번들 가용성은 installerVendorBundleUsable()(파일 존재 + ZipArchive). 여기서 `ini_get` 으로
     * 목록을 따로 읽으면 그 함수가 막힌 호스팅에서 이 판정 자체가 Error 로 죽고, 화면과 판정식이 갈린다.
     *
     * @return array{auto: bool, composer: bool, bundled: bool} 방식별 가용성
     */
    function installer_vendor_mode_availability(): array
    {
        return [
            'auto' => true,
            'composer' => installerHasFunction('proc_open'),
            'bundled' => installerVendorBundleUsable(),
        ];
    }
}

if (! function_exists('installer_validate_db_username')) {
    /**
     * DB 사용자명을 검증해 오류를 기록한다 — 빈 값과 DB 최고권한 계정을 거부.
     *
     * @param  string  $username  입력된 DB 사용자명
     * @param  string  $field  오류 배열 키
     * @param  array<string, string>  &$errors  오류 배열 (참조)
     * @return void 반환값 없음
     */
    function installer_validate_db_username(string $username, string $field, array &$errors): void
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
}

if (! function_exists('installer_validate_config')) {
    /**
     * Step 3 입력을 정규화하고 검증한다.
     *
     * 정규화 결과의 `execution_mode` 는 auto|subprocess|inprocess 로 정규화되고, `vendor_mode` 는 유효 실행
     * 방식으로 보정된다(inprocess 면 bundled). 실행 방식 감지에는 PHP 바이너리 probe 가 들어가지만 요청 안에서
     * 바이너리별로 한 번만 돈다(installerDetectExecutionMode 정적 캐시).
     *
     * @param  array<string, mixed>  $input  폼/터미널 입력 (기본값과 병합된 상태, 비밀 포함)
     * @param  string  $lang  오류 문구 언어 (ko|en)
     * @return array{config: array<string, mixed>, errors: array<string, string>} 정규화 config 와 필드별 오류 문구
     */
    function installer_validate_config(array $input, string $lang): array
    {
        // lang() 은 전역 번역 테이블을 읽는다 — 지역 변수에 담으면 오류가 번역 키 원문으로 나간다.
        global $translations;
        $translations = loadTranslations($lang);

        $config = $input;
        unset($config['csrf_token']);
        $errors = [];

        $useReadDb = $config['use_read_db'] ?? false;
        $config['use_read_db'] = is_bool($useReadDb) ? $useReadDb : filter_var($useReadDb, FILTER_VALIDATE_BOOLEAN);

        // 쓰기 DB
        if (empty($config['db_write_host'])) {
            $errors['db_write_host'] = lang('error_db_host_required');
        }
        if (empty($config['db_write_database'])) {
            $errors['db_write_database'] = lang('error_db_name_required');
        }
        installer_validate_db_username((string) ($config['db_write_username'] ?? ''), 'db_write_username', $errors);

        // 테이블 접두사 — 화면 검사와 같은 규칙(영문 소문자 시작·소문자/숫자/밑줄·최대 길이). 빈 값은 기본값.
        $prefix = (string) ($config['db_prefix'] ?? '');
        if ($prefix === '') {
            $config['db_prefix'] = 'g7_';
        } elseif (! preg_match('/^[a-z]/', $prefix)) {
            $errors['db_prefix'] = str_replace(':field', installer_field_label('db_prefix'), lang('validation_starts_with_alpha'));
        } elseif (! preg_match('/^[a-z][a-z0-9_]*$/', $prefix)) {
            $errors['db_prefix'] = str_replace(':field', installer_field_label('db_prefix'), lang('validation_alpha_num_underscore'));
        } elseif (strlen($prefix) > MAX_DB_PREFIX_LENGTH) {
            $errors['db_prefix'] = lang('error_db_prefix_too_long', ['max' => MAX_DB_PREFIX_LENGTH, 'current' => strlen($prefix)]);
        }

        // 읽기 DB (사용하는 경우)
        if (! empty($config['use_read_db'])) {
            if (empty($config['db_read_host'])) {
                $errors['db_read_host'] = lang('error_db_host_required');
            }
            if (empty($config['db_read_database'])) {
                $errors['db_read_database'] = lang('error_db_name_required');
            }
            installer_validate_db_username((string) ($config['db_read_username'] ?? ''), 'db_read_username', $errors);
        }

        // 사이트 정보 — 화면은 required 속성으로만 막던 항목이다.
        if (trim((string) ($config['app_name'] ?? '')) === '') {
            $errors['app_name'] = lang('error_app_name_required');
        } elseif (mb_strlen((string) $config['app_name']) > MAX_APP_NAME_LENGTH) {
            // 이 값은 `.env` 의 APP_NAME 과 설정의 site_name 으로 그대로 흘러가 설치 직후 관리자·사용자 화면의
            // 입력이 된다. 화면의 maxlength 는 거들 수 있고 터미널에는 그런 칸이 없으므로 서버가 같은 상한을 강제한다.
            $errors['app_name'] = lang('error_app_name_too_long', ['max' => MAX_APP_NAME_LENGTH]);
        }
        if (trim((string) ($config['app_url'] ?? '')) === '') {
            $errors['app_url'] = lang('error_app_url_required');
        }

        // 관리자 정보
        if (trim((string) ($config['admin_name'] ?? '')) === '') {
            $errors['admin_name'] = lang('error_admin_name_required');
        }
        if (empty($config['admin_email']) || ! filter_var($config['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = lang('error_admin_email_invalid');
        }
        if (! array_key_exists((string) ($config['admin_language'] ?? ''), SUPPORTED_LANGUAGES)) {
            $errors['admin_language'] = lang('error_admin_language_invalid');
        }
        if (empty($config['admin_password']) || strlen((string) $config['admin_password']) < 8) {
            $errors['admin_password'] = lang('error_admin_password_min');
        }
        if (($config['admin_password'] ?? null) !== ($config['admin_password_confirm'] ?? null)) {
            $errors['admin_password_confirm'] = lang('error_password_mismatch');
        }

        // 사이트 기본 언어 — 미지정·미지원이면 설치 UI 언어. .env APP_LOCALE 과 설정 general.language 가 이 값을 쓴다.
        $siteLanguage = (string) ($config['site_language'] ?? '');
        $config['site_language'] = array_key_exists($siteLanguage, SUPPORTED_LANGUAGES) ? $siteLanguage : $lang;

        // PHP CLI / Composer 경로 — 설치 워커가 실제 실행 바이너리로 쓰므로 형태 위반 값을 저장 전에 막는다.
        $phpBinary = trim((string) ($config['php_binary'] ?? 'php'));
        $phpBinary = $phpBinary !== '' ? $phpBinary : 'php';
        if ($phpBinary !== 'php' && ! installer_binary_path_shape_ok($phpBinary)) {
            $errors['php_binary'] = lang('error_php_binary_path_not_allowed', ['path' => $phpBinary]);
        }

        // 지정한 경로가 CLI 가 아니면 저장하지 않는다 (13차 보안#7 ②). `php-fpm --version`·`php-cgi --version` 은
        // CLI 와 똑같이 정상 종료하고 같은 형태로 버전을 찍으므로 형태·버전 검사로는 구분되지 않는데, 그 바이너리는
        // artisan 을 받지 못해 설치 태스크가 전부 실패한다. 기본값 'php' 는 대상이 아니다 — 코어 판정이 CLI 후보를
        // 먼저 고르므로 php-cgi 로만 동작하던 환경을 새로 막지 않는다. 판정 불확실(exec 차단 등)은 막지 않는다.
        if (! isset($errors['php_binary']) && $phpBinary !== 'php') {
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
        $config['php_binary'] = $phpBinary;

        $composerBinary = trim((string) ($config['composer_binary'] ?? ''));
        if ($composerBinary !== '' && $composerBinary !== 'composer') {
            // 단일 토큰이면 Composer 자리 규칙, 공백 분리 입력이면 (PHP, Composer) 쌍 해석.
            $allowed = str_contains($composerBinary, ' ')
                ? installer_resolve_php_composer_pair($composerBinary) !== null
                : installer_is_composer_binary_path($composerBinary);

            if (! $allowed) {
                $errors['composer_binary'] = lang('error_composer_binary_path_not_allowed', ['path' => $composerBinary]);
            }
        }
        $config['composer_binary'] = $composerBinary;

        // vendor 설치 방식 — 화면에서 비활성인 방식을 고른 요청은 거부한다(설치 중에 늦게 실패하지 않게).
        // 모르는 값은 거부한다 — 조용히 auto 로 바꾸면 터미널의 오타(`--vendor-mode=bundeld`)가 다른 방식으로 설치된다.
        $vendorMode = trim((string) ($config['vendor_mode'] ?? 'auto'));
        if (! in_array($vendorMode, ['auto', 'composer', 'bundled'], true)) {
            $errors['vendor_mode'] = lang('error_vendor_mode_invalid', ['value' => $vendorMode]);
            $vendorMode = 'auto';
        } elseif (! installer_vendor_mode_availability()[$vendorMode]) {
            $errors['vendor_mode'] = lang('error_vendor_mode_unavailable', [
                'mode' => lang('vendor_mode_'.$vendorMode.'_title'),
            ]);
        }

        // 실행 방식 (auto|subprocess|inprocess, 기본 auto — D33). 저장되는 것은 「선택」이고 auto 는 감지 결과를
        // 박지 않는다 (A-0 ①). 모르는 값은 auto 로 정규화한다 — 어휘 SSoT 는 코어 ExecutionMode 다.
        $config['execution_mode'] = installerNormalizeExecutionMode($config['execution_mode'] ?? 'auto');

        // 명시 선택은 성립 여부를 저장 시점에 검사한다 (A-4 대칭). 화면이 고를 수 없게 막는 것만으로는 부족하다 —
        // 재감지가 조용히 실패한 뒤·저장과 설치 시작 사이에 환경이 바뀐 뒤·개발자 도구로 disabled 를 지운 뒤에는
        // 서버가 유일한 판정자다. 여기서 막지 않으면 실패가 설치 중반(태스크 실행)에야 드러난다.
        if ($config['execution_mode'] !== 'auto') {
            $executionRejection = installerExecutionModeRejection(
                $config['execution_mode'],
                installerDetectExecutionMode($phpBinary),
            );

            if ($executionRejection !== null) {
                $errors['execution_mode'] = lang($executionRejection);
            }
        }

        // 유효 실행 방식이 inprocess 면 composer 는 성립하지 않으므로 vendor 설치 방식을 bundled 로 고정한다 (A-4) —
        // 화면이 보내는 값·터미널 입력만 믿지 않고 저장 시점에 다시 판정한다 (R9: 모드가 관여하는 지점은 모드를 읽는다).
        $config['vendor_mode'] = installerCoerceVendorModeForExecution(
            $vendorMode,
            installerResolveExecutionMode(['execution_mode' => $config['execution_mode'], 'php_binary' => $phpBinary])['mode'],
        );

        // 자산 URL 방식 — 판정 불가(빈 값)면 defaults.json 기본값을 따른다.
        $assetUrlMode = trim((string) ($config['asset_url_mode'] ?? ''));
        $config['asset_url_mode'] = in_array($assetUrlMode, ['extension', 'extensionless'], true) ? $assetUrlMode : '';

        // .env 로 기록되는 입력의 사전 거부 (KVE-2026-2042) — 직렬화기 단계 실패는 원인 입력을 알기 어렵다.
        foreach (['app_name', 'app_url', 'core_update_github_url'] as $envField) {
            if (! installer_env_value_is_single_line((string) ($config[$envField] ?? ''))) {
                $errors[$envField] = lang('error_env_value_line_break');
            }
        }

        // URL 필드는 형태까지 확인한다. FILTER_VALIDATE_URL 은 호스트명 밑줄(my_site.test)을 거부하므로 쓰지 않는다.
        foreach (['app_url', 'core_update_github_url'] as $urlField) {
            $urlValue = trim((string) ($config[$urlField] ?? ''));
            if ($urlValue === '' || isset($errors[$urlField])) {
                continue;
            }

            $parts = parse_url($urlValue);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = (string) ($parts['host'] ?? '');
            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || preg_match('/\s/', $urlValue)) {
                $errors[$urlField] = lang('error_env_value_invalid_url', ['value' => $urlValue]);
            }
        }

        // 코어 업데이트 _pending 경로 (입력된 경우만) — 검사 버튼(check-core-pending-path)과 **같은 규칙**이어야
        // 한다 (21차 #21-4): `..`/NUL 거부, Windows 절대경로(`D:\pending`) 인식, stat 진단 억제. 절대경로를
        // 상대경로로 오인하면 검사 버튼이 초록을 준 경로와 다른 경로를 검증하고, open_basedir 밖 경로의 무억제
        // stat 은 warning 을 응답 헤더보다 먼저 낸다.
        $corePendingPath = trim((string) ($config['core_update_pending_path'] ?? ''));
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

        return ['config' => $config, 'errors' => $errors];
    }
}
