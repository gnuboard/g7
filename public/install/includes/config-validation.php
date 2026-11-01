<?php

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
     * @return array{auto: bool, composer: bool, bundled: bool} 방식별 가용성
     */
    function installer_vendor_mode_availability(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $procOpen = function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
        $bundled = file_exists(BASE_PATH.'/vendor-bundle.zip') && class_exists('ZipArchive');

        return ['auto' => true, 'composer' => $procOpen, 'bundled' => $bundled];
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

        if (\App\Support\PrivilegedDatabaseAccounts::isBlocked($username)) {
            $errors[$field] = lang('error_db_username_privileged', ['username' => $username]);
        }
    }
}

if (! function_exists('installer_validate_config')) {
    /**
     * Step 3 입력을 정규화하고 검증한다.
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
        $config['vendor_mode'] = $vendorMode;

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

        // 코어 업데이트 _pending 경로 (입력된 경우만)
        $corePendingPath = trim((string) ($config['core_update_pending_path'] ?? ''));
        if ($corePendingPath !== '') {
            $absolutePath = str_starts_with($corePendingPath, '/')
                ? $corePendingPath
                : BASE_PATH.'/'.$corePendingPath;

            if (file_exists($absolutePath) && ! is_dir($absolutePath)) {
                $errors['core_update_pending_path'] = lang('error_core_pending_not_directory');
            }
        }

        return ['config' => $config, 'errors' => $errors];
    }
}
