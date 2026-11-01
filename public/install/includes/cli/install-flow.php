<?php

/**
 * 그누보드7 터미널 설치 - 단계 0~5 흐름
 *
 * 웹 마법사의 단계 순서를 그대로 따른다: [0] 언어 → [1] 약관 → [2] 요구사항 → [3] 환경설정 → [4] 확장 선택 → [5] 설치.
 * 각 단계의 판정은 웹과 같은 공유 함수가 한다. 이 파일은 입력을 모으고 결과를 출력하며 state 를 웹과 같은 규칙으로 저장한다.
 */
if (! function_exists('g7_cli_run_install')) {
    /**
     * 설치 흐름 (새 설치·웹 설치 넘겨받기·중단된 터미널 설치 이어하기).
     *
     * @param  G7CliOutput  $out  출력
     * @param  array<string, mixed>  $input  병합 입력 (g7_cli_collect_input 결과)
     * @param  array<string, string|bool>  $options  명령줄 옵션
     * @param  array<string, string>  $env  환경변수
     * @param  string  $uiLang  설치 UI 언어
     * @param  bool  $interactive  대화형 여부
     * @param  callable(string): bool  $flag  플래그 판정
     * @param  string|null  $reinstallKey  재설치에서 이어 쓸 APP_KEY (재설치 초기화가 [3] 뒤에 채운다 — 호출자는 null)
     * @param  array{user: string, group: string, uid: int, gid: int}|null  $webUser  root 실행 시 웹서버 계정
     * @return int 종료 코드
     */
    function g7_cli_run_install(G7CliOutput $out, array $input, array $options, array $env, string $uiLang, bool $interactive, callable $flag, ?string $reinstallKey, ?array $webUser): int
    {
        $existing = installerReadStateFile();
        // 끝난 설치의 state 는 소유를 다투지 않는다 (재설치는 그 state 를 지우고 새로 각인한다).
        $kind = $existing === null || ($existing['installation_status'] ?? null) === 'completed' ? 'none' : installer_state_owner_kind($existing);
        $takeover = false;

        if ($kind === 'web' && ($existing['installation_status'] ?? null) !== 'completed') {
            if (installer_worker_recently_active($existing)) {
                return g7_cli_conflict($out, 'cli_error_web_worker_active', $existing);
            }
            if (! $flag('takeover')) {
                return g7_cli_conflict($out, 'cli_error_web_owned', $existing);
            }
            $takeover = true;
        }

        // 진행 상태 초기화는 소유 판정을 통과한 뒤에만 — 웹 소유 설치는 넘겨받기와 같은 규칙(D8)을 거친다.
        if ($flag('reset-state')) {
            foreach ([STATE_PATH, STATE_PATH.'.lock'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            deleteInstallerRuntime();
            $out->info(lang('cli_state_reset'));
            $kind = 'none';
        }

        if ($kind === 'cli') {
            if (! $flag('resume')) {
                return g7_cli_conflict($out, 'cli_error_cli_interrupted', $existing);
            }

            return g7_cli_resume($out, $existing, $input, $interactive, $webUser);
        } elseif (! $takeover && $flag('resume')) {
            return g7_cli_fail($out, [['key' => 'cli_error_nothing_to_resume', 'params' => []]], G7_CLI_EXIT_INVALID);
        }

        // [0] 언어
        $out->step(0, lang('step_0_welcome'));
        if ($interactive && empty($input['explicit']['locale'])) {
            $uiLang = g7_cli_choice(lang('select_language'), SUPPORTED_LANGUAGES, $uiLang);
            $GLOBALS['translations'] = loadTranslations($uiLang);
            $_SESSION['g7_locale'] = $uiLang;
        }
        $out->info(SUPPORTED_LANGUAGES[$uiLang]);

        // [1] 약관
        $out->step(1, lang('step_1_license'));
        if ($interactive) {
            g7_cli_page_text(loadLicenseFile($uiLang));
            if (! g7_cli_confirm(lang('i_agree'))) {
                return g7_cli_fail($out, [lang('must_agree')], G7_CLI_EXIT_INVALID);
            }
        } elseif (empty($input['config']['accept_license'])) {
            return g7_cli_fail($out, [['key' => 'cli_error_license_not_accepted', 'params' => []]], G7_CLI_EXIT_INVALID);
        }
        $out->info(lang('cli_license_accepted'));

        // .env 가 없으면 .env.example 을 복사한다 (D5) — 웹 Step 2 의 필수 파일 항목을 사람이 손으로 하던 일.
        $envPath = BASE_PATH.'/.env';
        if (! is_file($envPath)) {
            if (@copy(BASE_PATH.'/.env.example', $envPath)) {
                $out->info(lang('cli_env_copied'));
            } else {
                return g7_cli_fail($out, [lang('error_env_not_found'), getEnvCopyCommand(BASE_PATH)], G7_CLI_EXIT_REQUIREMENTS);
            }
        }

        // [2] 요구사항
        $out->step(2, lang('step_2_requirements'));
        $requirements = installer_collect_requirements();
        g7_cli_print_requirements($out, $requirements, $webUser);
        if ($requirements['blocking']) {
            return g7_cli_fail($out, [lang('requirements_not_met')], G7_CLI_EXIT_REQUIREMENTS);
        }

        // [3] 환경설정
        $out->step(3, lang('step_3_configuration'));
        $collected = g7_cli_collect_configuration($out, $input, $uiLang, $interactive);
        if (is_int($collected)) {
            return $collected;
        }
        ['config' => $config, 'action' => $action, 'drop' => $drop] = $collected;

        // [R] 재설치 초기화 — 입력·DB 검사를 모두 통과한 뒤에만 지운다.
        if ($flag('reinstall')) {
            $code = g7_cli_run_reinstall($out, $flag, $interactive, $reinstallKey);
            if ($code !== null) {
                return $code;
            }
        }

        $state = getInstallationState();
        $generation = (int) ($state['generation'] ?? 0);
        if ($takeover) {
            $state = installer_cli_takeover($state);
        } else {
            // 소유자가 없던 설치에 남은 runtime.php 는 이전 시도의 비밀이다 — 섞이지 않게 폐기 (웹 첫 소유와 같음).
            deleteInstallerRuntime();
            $state = installer_claim_cli_owner($state);
        }
        $state['g7_locale'] = $uiLang;
        $state['config'] = sanitizeConfigForState($config);
        $state['installation_status'] = 'ready';
        $state['current_step'] = 4;
        foreach ([0, 1, 2, 3] as $done) {
            $state['step_status'][(string) $done] = 'completed';
        }
        $state['drop_confirm'] = $drop['record'] ?? null;
        if (! saveInstallationStateCas($state, $generation)) {
            return g7_cli_fail($out, [lang('error_state_conflict')], G7_CLI_EXIT_CONFLICT);
        }
        addLog(lang('log_ownership_claimed_cli'));

        // [4] 확장 선택
        $out->step(4, lang('step_4_extension_selection'));
        $selection = g7_cli_select_extensions($out, $input, $uiLang, $interactive);
        if (is_int($selection)) {
            return $selection;
        }

        $state = getInstallationState();
        $generation = (int) ($state['generation'] ?? 0);
        $state['selected_extensions'] = $selection['selection'];
        $state['extension_names'] = $selection['names'];
        $state['install_pulled_by'] = $selection['pulled_by'];
        $state['step_status']['4'] = 'completed';
        $state['current_step'] = 5;
        if (! saveInstallationStateCas($state, $generation)) {
            return g7_cli_fail($out, [lang('error_state_conflict')], G7_CLI_EXIT_CONFLICT);
        }
        addLog(lang('log_extensions_selected', [
            'admin' => count($selection['selection']['admin_templates']),
            'user' => count($selection['selection']['user_templates']),
            'modules' => count($selection['selection']['modules']),
            'plugins' => count($selection['selection']['plugins']),
            'language_packs' => count($selection['selection']['language_packs']),
        ]));

        // 재설치에서 설정을 남겼으면 기존 APP_KEY 를 이어 쓴다 — 키 생성 단계가 runtime 의 키를 보존한다.
        if ($reinstallKey !== null) {
            $runtime = readInstallerRuntime() ?? [];
            $runtime['app']['key'] = $reinstallKey;
            writeInstallerRuntime($runtime);
        }

        // [5] 설치
        $plan = installer_begin_installation(getInstallationState(), $config, [], $action, $drop['token'] ?? null, ['installation_mode' => 'cli']);
        // 새 설치 시작이 로그를 새로 만들므로 넘겨받은 사실을 그 뒤에 다시 남긴다 (3.6).
        if ($takeover && $plan['ok']) {
            addLog(lang('log_cli_takeover'));
        }

        return g7_cli_execute($out, $plan, $drop['token'] ?? null, $webUser);
    }
}

if (! function_exists('g7_cli_conflict')) {
    /**
     * 충돌(종료 5) — 기존 설치 상태 요약과 함께 안내한다.
     *
     * @param  G7CliOutput  $out  출력
     * @param  string  $key  안내 문구 키
     * @param  array<string, mixed>  $state  기존 state
     * @return int 종료 코드
     */
    function g7_cli_conflict(G7CliOutput $out, string $key, array $state): int
    {
        foreach (g7_cli_state_summary($state) as $label => $value) {
            $out->info(lang('cli_state_'.$label).': '.$value);
        }

        return g7_cli_fail($out, [['key' => $key, 'params' => []]], G7_CLI_EXIT_CONFLICT);
    }
}

if (! function_exists('g7_cli_print_requirements')) {
    /**
     * 요구사항 표를 출력한다 — 웹 전용 항목은 「터미널 미판정」 정보 줄.
     *
     * @param  G7CliOutput  $out  출력
     * @param  array<string, mixed>  $req  installer_collect_requirements 결과
     * @param  array<string, mixed>|null  $webUser  root 실행 시 웹서버 계정
     * @return void 반환값 없음
     */
    function g7_cli_print_requirements(G7CliOutput $out, array $req, ?array $webUser): void
    {
        $mark = static fn (bool $ok): string => $ok ? '[OK]  ' : '[FAIL]';

        $out->info($mark($req['php_version']['passed']).' '.$req['php_version']['message']);
        $missing = array_keys(array_filter($req['php_extensions']['installed'], static fn ($r) => ! $r['installed']));
        $out->info($mark($req['php_extensions']['all_required_passed']).' '.$req['php_extensions']['message'].($missing !== [] ? ' ('.implode(', ', $missing).')' : ''));
        $out->info($mark($req['disabled_functions']['passed']).' '.$req['disabled_functions']['message']);
        $out->info($mark($req['disk_space']['passed']).' '.$req['disk_space']['message']);
        $out->info($mark($req['directories']['all_passed']).' '.$req['directories']['message']);
        foreach ($req['directories']['results'] as $dir => $row) {
            if (! $row['passed']) {
                $out->info('        - '.$dir.' ('.$row['error_type'].')');
            }
        }
        $out->info($mark($req['required_files']['all_passed']).' '.$req['required_files']['message']);
        if (($req['vendor_dev_packages']['dev'] ?? null) === true) {
            $out->warn($req['vendor_dev_packages']['message']);
        }
        $out->info('[-]    '.lang('cli_web_only_item', ['item' => 'HTTPS']));
        $out->info('[-]    '.lang('cli_web_only_item', ['item' => 'OPcache']));
        $out->info(lang('cli_requirements_cli_php_note'));
        if ($webUser !== null) {
            $out->info(lang('cli_requirements_chown_planned', ['user' => $webUser['user'], 'group' => $webUser['group']]));
        }
        $out->raw('requirements', ['blocking' => $req['blocking'], 'all_required_passed' => $req['all_required_passed']]);
    }
}

if (! function_exists('g7_cli_apply_binary_detection')) {
    /**
     * 웹 Step 3 의 PHP·Composer 자동 감지를 터미널에 적용한다 — 직접 준 경로는 건드리지 않는다.
     *
     * 웹(views/3-configuration.php)과 같이 기본 php 를 쓸 수 없을 때만 탐지한 첫 PHP 를 고르고,
     * Composer 경로가 비어 있으면 탐지한 경로(PATH 의 composer 가 아니면)로 채운다.
     *
     * @param  G7CliOutput  $out  출력
     * @param  array<string, mixed>  $config  병합 입력 config
     * @param  array<string, mixed>  $explicit  사용자가 직접 준 키
     * @return array<string, mixed> 탐지 결과를 반영한 config
     */
    function g7_cli_apply_binary_detection(G7CliOutput $out, array $config, array $explicit): array
    {
        $phpGiven = ! empty($explicit['php_binary']) || ! in_array((string) ($config['php_binary'] ?? ''), ['', 'php'], true);
        $composerGiven = ! empty($explicit['composer_binary']) || (string) ($config['composer_binary'] ?? '') !== '';
        if ($phpGiven && $composerGiven) {
            return $config;
        }

        $detected = installer_detect_php_binaries();
        if (! $phpGiven && ! ($detected['default_php_available'] ?? false) && ! empty($detected['binaries'])) {
            $first = $detected['binaries'][0];
            $config['php_binary'] = (string) $first['path'];
            $out->info(lang('cli_php_auto_selected', ['path' => (string) $first['path'], 'version' => (string) ($first['version'] ?? '')]));
        }

        $composer = $detected['composer'] ?? [];
        if (! $composerGiven && ($composer['found'] ?? false) && ($composer['path'] ?? 'composer') !== 'composer') {
            $config['composer_binary'] = (string) $composer['path'];
            $out->info(lang('cli_composer_auto_selected', ['path' => (string) $composer['path'], 'version' => (string) ($composer['version'] ?? '')]));
        }

        return $config;
    }
}

if (! function_exists('g7_cli_collect_configuration')) {
    /**
     * [3] 환경설정 — 입력 수집 → 공유 검증 → DB 검사 → 기존 테이블 동의 → PHP·Composer 검증 → 자산 URL 방식.
     *
     * @param  G7CliOutput  $out  출력
     * @param  array<string, mixed>  $input  병합 입력
     * @param  string  $uiLang  설치 UI 언어
     * @param  bool  $interactive  대화형 여부
     * @return array{config: array<string, mixed>, action: string, drop: array{token: string, record: array<string, mixed>}|null}|int 결과 또는 종료 코드
     */
    function g7_cli_collect_configuration(G7CliOutput $out, array $input, string $uiLang, bool $interactive): array|int
    {
        $config = $input['config'];
        $secrets = $input['secrets'];
        $explicit = $input['explicit'];
        $label = static fn (string $field): string => installer_field_label($field);

        if (empty($explicit['admin_language'])) {
            $config['admin_language'] = $uiLang;
        }

        // PHP·Composer 탐지 — 웹 Step 3 와 같은 규칙: 경로를 주지 않았고 기본 php 를 쓸 수 없으면 찾은 첫 PHP, Composer 는 찾은 경로(3.3).
        $config = g7_cli_apply_binary_detection($out, $config, $explicit);

        if ($interactive) {
            // 숨김 입력을 쓸 수 없으면 비밀값을 묻지 않고 거부한다 — 빈 값으로 진행하지 않는다(환경변수·파일 안내).
            $hiddenUnavailable = static fn (string $env, string $file): int => g7_cli_fail($out, [['key' => 'cli_error_hidden_input_unavailable', 'params' => ['env' => $env, 'file' => $file]]], G7_CLI_EXIT_INVALID);
            foreach (['db_write_host', 'db_write_port', 'db_write_database', 'db_write_username'] as $field) {
                $config[$field] = g7_cli_ask($label($field), (string) ($config[$field] ?? ''));
            }
            if (! array_key_exists('db_write_password', $secrets)) {
                if (! g7_cli_hidden_input_supported()) {
                    return $hiddenUnavailable('G7_INSTALL_DB_PASSWORD', '--db-password-file');
                }
                $secrets['db_write_password'] = (string) g7_cli_hidden($label('db_write_password'));
            }
            $config['db_prefix'] = g7_cli_ask($label('db_prefix'), (string) $config['db_prefix']);
            $config['use_read_db'] = g7_cli_confirm(lang('cli_ask_use_read_db'), (bool) $config['use_read_db']);
            if ($config['use_read_db']) {
                foreach (['db_read_host', 'db_read_port', 'db_read_database', 'db_read_username'] as $field) {
                    $config[$field] = g7_cli_ask($label($field), (string) ($config[$field] ?? ''));
                }
                if (! array_key_exists('db_read_password', $secrets)) {
                    if (! g7_cli_hidden_input_supported()) {
                        return $hiddenUnavailable('G7_INSTALL_DB_READ_PASSWORD', '--db-read-password-file');
                    }
                    $secrets['db_read_password'] = (string) g7_cli_hidden($label('db_read_password'));
                }
            }
            foreach (['app_name', 'app_url', 'admin_name', 'admin_email'] as $field) {
                $config[$field] = g7_cli_ask($label($field), (string) ($config[$field] ?? ''));
            }
            if (! array_key_exists('admin_password', $secrets)) {
                if (! g7_cli_hidden_input_supported()) {
                    return $hiddenUnavailable('G7_INSTALL_ADMIN_PASSWORD', '--admin-password-file');
                }
                $secrets['admin_password'] = (string) g7_cli_hidden($label('admin_password'));
                $confirm = (string) g7_cli_hidden($label('admin_password_confirmation'));
            }
            $config['admin_language'] = g7_cli_choice($label('admin_language'), SUPPORTED_LANGUAGES, (string) $config['admin_language']);
            $config['site_language'] = g7_cli_choice(lang('cli_site_language'), SUPPORTED_LANGUAGES, $config['site_language'] !== '' ? (string) $config['site_language'] : $uiLang);
            $config['core_update_pending_path'] = g7_cli_ask(lang('cli_ask_core_pending_path'), (string) $config['core_update_pending_path']);
            $config['core_update_github_url'] = g7_cli_ask(lang('cli_ask_github_url'), (string) $config['core_update_github_url']);
            $config['php_binary'] = g7_cli_ask(lang('cli_ask_php_binary'), (string) $config['php_binary']);
            $config['composer_binary'] = g7_cli_ask(lang('cli_ask_composer_binary'), (string) $config['composer_binary']);
            $config['vendor_mode'] = g7_cli_choice(lang('vendor_mode_title'), [
                'auto' => lang('vendor_mode_auto_title'),
                'composer' => lang('vendor_mode_composer_title'),
                'bundled' => lang('vendor_mode_bundled_title'),
            ], (string) $config['vendor_mode']);
        }

        foreach (['locale', 'accept_license', 'drop_existing_tables', 'all_extensions'] as $helperKey) {
            unset($config[$helperKey]);
        }

        // 비밀값 — 비대화형은 환경변수·파일 하나가 원천이므로 확인 입력은 같은 값.
        $config = array_merge($config, $secrets);
        $config['admin_password_confirm'] = $confirm ?? ($secrets['admin_password'] ?? null);

        $validated = installer_validate_config($config, $uiLang);
        if ($validated['errors'] !== []) {
            $lines = [];
            foreach ($validated['errors'] as $field => $message) {
                $lines[] = $label($field).': '.$message;
            }

            return g7_cli_fail($out, $lines, G7_CLI_EXIT_INVALID);
        }
        $config = $validated['config'];

        // DB 연결·권한 (웹 DB 테스트와 같은 함수)
        $write = installer_test_database([
            'host' => $config['db_write_host'], 'port' => $config['db_write_port'], 'database' => $config['db_write_database'],
            'username' => $config['db_write_username'], 'password' => $config['db_write_password'] ?? '', 'db_prefix' => $config['db_prefix'],
        ], 'write', $uiLang);
        if (! $write['success']) {
            return g7_cli_fail($out, [$write['message']], G7_CLI_EXIT_INVALID);
        }
        $out->info($write['message']);

        if (! empty($config['use_read_db'])) {
            $read = installer_test_database([
                'host' => $config['db_read_host'], 'port' => $config['db_read_port'], 'database' => $config['db_read_database'],
                'username' => $config['db_read_username'], 'password' => $config['db_read_password'] ?? '',
            ], 'read', $uiLang);
            if (! $read['success']) {
                return g7_cli_fail($out, [$read['message']], G7_CLI_EXIT_INVALID);
            }
            $out->info($read['message']);
        }

        // 기존 테이블 — 접두사 일치 테이블이 있으면 삭제 동의가 있어야 진행한다(삭제 대상은 접두사 일치 테이블뿐).
        $action = 'skip';
        $drop = null;
        if ($write['prefix_tables'] !== []) {
            $severity = (string) ($write['severity'] ?? 'generic');
            $titleKey = in_array($severity, ['g7_existing', 'foreign_data', 'mixed'], true)
                ? ['g7_existing' => 'db_existing_g7_title', 'foreign_data' => 'db_existing_foreign_title', 'mixed' => 'db_existing_mixed_title'][$severity]
                : 'db_existing_generic_title';
            $descKey = str_replace('_title', '_desc', $titleKey);
            $out->warn(lang($titleKey).' — '.lang($descKey));
            $out->info(lang('db_existing_tables_list').' '.implode(', ', array_slice($write['prefix_tables'], 0, 20)));

            $consent = ! empty($input['config']['drop_existing_tables'])
                || ($interactive && g7_cli_confirm(lang('db_backup_confirmed')));
            if (! $consent) {
                return g7_cli_fail($out, [lang('error_db_cleanup_consent_required'), lang('cli_hint_drop_existing_tables')], G7_CLI_EXIT_INVALID);
            }
            $drop = installer_issue_drop_confirm((string) $write['detect_prefix'], $write['prefix_tables']);
            $action = 'drop_tables';
        } elseif (! empty($write['existing_tables']['has_tables'])) {
            $out->info(lang('cli_existing_tables_other_prefix'));
        }

        // PHP·Composer — 웹 Step 3 와 같은 규칙: PHP 는 항상 검증, Composer 는 번들 방식이 아니고 직접 지정·composer 방식일 때.
        $php = installer_verify_php_binary((string) $config['php_binary']);
        if (! $php['valid']) {
            return g7_cli_fail($out, [$php['message']], G7_CLI_EXIT_INVALID);
        }
        $out->info($php['message']);
        if ($config['vendor_mode'] !== 'bundled' && ($config['composer_binary'] !== '' || $config['vendor_mode'] === 'composer')) {
            $composer = installer_verify_composer((string) $config['composer_binary'], (string) $config['php_binary']);
            if (! $composer['valid']) {
                return g7_cli_fail($out, [$composer['message']], G7_CLI_EXIT_INVALID);
            }
            $out->info($composer['message']);
        }

        // 자산 URL 방식 — 지정하지 않으면 웹 프로브와 같은 판정식으로 사이트에 직접 묻는다(A10).
        if (empty($explicit['asset_url_mode'])) {
            $config['asset_url_mode'] = g7_cli_probe_asset_url_mode((string) $config['app_url']);
        }
        $out->info(lang('asset_url_mode').': '.($config['asset_url_mode'] !== '' ? $config['asset_url_mode'] : lang('cli_asset_url_mode_default')));

        return ['config' => $config, 'action' => $action, 'drop' => $drop];
    }
}

if (! function_exists('g7_cli_select_extensions')) {
    /**
     * [4] 확장 선택 — 웹 기본값(관리자 템플릿 첫 번째)·전체 선택 의미를 따르고 공유 해석기로 의존 확장을 채운다.
     *
     * @param  G7CliOutput  $out  출력
     * @param  array<string, mixed>  $input  병합 입력
     * @param  string  $uiLang  설치 UI 언어
     * @param  bool  $interactive  대화형 여부
     * @return array{selection: array<string, array<int, string>>, names: array<string, mixed>, pulled_by: array<string, array<int, string>>}|int 결과 또는 종료 코드
     */
    function g7_cli_select_extensions(G7CliOutput $out, array $input, string $uiLang, bool $interactive): array|int
    {
        $scan = installer_scan_extensions();
        $requested = $input['extensions'];
        $all = ! empty($input['config']['all_extensions']);
        $ids = static fn (string $cat): array => array_map(static fn (array $i): string => (string) $i['identifier'], $scan[$cat] ?? []);

        $requested['admin_templates'] ??= array_slice($ids('admin_templates'), 0, 1);
        $requested['user_templates'] ??= $all ? array_slice($ids('user_templates'), 0, 1) : [];
        $requested['modules'] ??= $all ? $ids('modules') : [];
        $requested['plugins'] ??= $all ? $ids('plugins') : [];
        $requested['language_packs'] ??= $all ? $ids('language_packs') : [];

        $names = [];
        $labels = [];
        foreach (INSTALLER_EXTENSION_CATEGORIES as $cat) {
            foreach ($scan[$cat] ?? [] as $item) {
                $name = $item['name'] ?? $item['identifier'];
                $names[$item['identifier']] = is_string($name) ? ['ko' => $name, 'en' => $name] : $name;
                $labels[$cat][$item['identifier']] = is_array($name) ? (string) ($name[$uiLang] ?? $name['ko'] ?? $item['identifier']) : (string) $name;
            }
        }

        if ($interactive) {
            $requested['admin_templates'] = g7_cli_toggle_list(lang('cli_ext_admin_templates'), $labels['admin_templates'] ?? [], $requested['admin_templates'], true);
            foreach (['user_templates' => true, 'modules' => false, 'plugins' => false, 'language_packs' => false] as $cat => $single) {
                $requested[$cat] = g7_cli_toggle_list(lang('cli_ext_'.$cat), $labels[$cat] ?? [], $requested[$cat], $single);
            }
        }

        $resolution = installer_resolve_extension_selection($scan, $requested);
        foreach ($resolution['auto_added'] as $added) {
            $out->info(lang('cli_ext_auto_added', ['identifier' => $added['identifier'], 'required_by' => $added['required_by']]));
        }
        foreach ($resolution['excluded_language_packs'] as $packId) {
            $out->info(lang('cli_ext_language_pack_excluded', ['identifier' => $packId]));
        }
        if ($resolution['errors'] !== []) {
            return g7_cli_fail($out, $resolution['errors'], G7_CLI_EXIT_INVALID);
        }
        // 필수 PHP 확장이 서버에 없는 확장은 웹 확장 저장과 같은 함수로 미리 뺀다 — 설치 단계의 코어 게이트에서 멈추지 않게 한다(#151).
        $phpExclusion = installer_exclude_selection_missing_php($resolution, BASE_PATH);
        $resolution = $phpExclusion['resolution'];
        foreach ($phpExclusion['skipped'] as $skippedIdentifier => $missingPhpExtensions) {
            $out->info(lang('log_extension_skipped_php_extension_missing', ['identifier' => $skippedIdentifier, 'extensions' => implode(', ', $missingPhpExtensions)]));
        }
        // 종류 경계를 넘어 앞당긴 항목에는 요구한 확장을 붙인다 — 예: plugins:sirsoft-ckeditor5 (← modules:sirsoft-page)
        $orderLabels = array_map(
            static fn (string $entry): string => isset($resolution['pulled_by'][$entry]) ? $entry.' (← '.implode(', ', $resolution['pulled_by'][$entry]).')' : $entry,
            $resolution['install_order']
        );
        $out->info(lang('cli_ext_install_order').': '.implode(', ', $orderLabels));
        $out->raw('extensions', ['install_order' => $resolution['install_order'], 'pulled_by' => $resolution['pulled_by'], 'auto_added' => $resolution['auto_added'], 'php_extension_skipped' => $phpExclusion['skipped']]);

        return ['selection' => $resolution['selection'], 'names' => $names, 'pulled_by' => $resolution['pulled_by']];
    }
}

if (! function_exists('g7_cli_resume')) {
    /**
     * 중단·실패한 터미널 설치를 이어서 한다 — 완료 작업은 건너뛴다(웹 「이어서 설치」와 같은 의미).
     *
     * runtime.php 가 남아 있으면 비밀값을 다시 받지 않는다. 없으면 입력(환경변수·파일·숨김 입력)에서 다시 받는다.
     *
     * @param  G7CliOutput  $out  출력
     * @param  array<string, mixed>  $state  기존 state (owner_kind=cli)
     * @param  array<string, mixed>  $input  병합 입력
     * @param  bool  $interactive  대화형 여부
     * @param  array<string, mixed>|null  $webUser  root 실행 시 웹서버 계정
     * @return int 종료 코드
     */
    function g7_cli_resume(G7CliOutput $out, array $state, array $input, bool $interactive, ?array $webUser): int
    {
        if (installer_worker_recently_active($state) && ($state['installation_status'] ?? null) === 'running') {
            return g7_cli_conflict($out, 'cli_error_worker_active', $state);
        }
        if (($state['current_step'] ?? 0) < 5 || empty($state['selected_extensions']['admin_templates'])) {
            return g7_cli_conflict($out, 'cli_error_resume_incomplete_state', $state);
        }

        $out->step(5, lang('step_5_installation').' — '.lang('cli_resuming'));

        $runtime = readInstallerRuntime() ?? [];
        $secrets = $input['secrets'];
        // 숨김 입력을 쓸 수 없으면 빈 값으로 진행하지 않고 거부한다 — 새 설치 경로와 같은 규칙(A9).
        $hiddenUnavailable = static fn (string $env, string $file): int => g7_cli_fail($out, [['key' => 'cli_error_hidden_input_unavailable', 'params' => ['env' => $env, 'file' => $file]]], G7_CLI_EXIT_INVALID);
        if (($runtime['db']['write']['password'] ?? '') === '' && ! array_key_exists('db_write_password', $secrets) && $interactive) {
            if (! g7_cli_hidden_input_supported()) {
                return $hiddenUnavailable('G7_INSTALL_DB_PASSWORD', '--db-password-file');
            }
            $secrets['db_write_password'] = (string) g7_cli_hidden(installer_field_label('db_write_password'));
        }
        $needsAdmin = ! in_array('db_seed', $state['completed_tasks'] ?? [], true) && empty($runtime['admin']['password'] ?? '');
        if ($needsAdmin && ! array_key_exists('admin_password', $secrets) && $interactive) {
            if (! g7_cli_hidden_input_supported()) {
                return $hiddenUnavailable('G7_INSTALL_ADMIN_PASSWORD', '--admin-password-file');
            }
            $secrets['admin_password'] = (string) g7_cli_hidden(installer_field_label('admin_password'));
        }

        $token = is_string($runtime['cli_drop_confirm_token'] ?? null) ? $runtime['cli_drop_confirm_token'] : null;
        $plan = installer_begin_installation($state, $state['config'] ?? [], $secrets, (string) ($state['existing_db_action'] ?? 'skip'), $token, ['installation_mode' => 'cli']);

        return g7_cli_execute($out, $plan, $token, $webUser);
    }
}

if (! function_exists('g7_cli_execute')) {
    /**
     * [5] 설치 작업 실행 — 웹 워커와 같은 runInstallationTasks() 를 터미널 송출기로 돌리고 .env 를 확정한다.
     *
     * @param  G7CliOutput  $out  출력
     * @param  array<string, mixed>  $plan  installer_begin_installation 결과
     * @param  string|null  $dropToken  기존 테이블 삭제 확인 토큰 (재개용으로 runtime 에 보관)
     * @param  array<string, mixed>|null  $webUser  root 실행 시 웹서버 계정
     * @return int 종료 코드
     */
    function g7_cli_execute(G7CliOutput $out, array $plan, ?string $dropToken, ?array $webUser): int
    {
        if (! $plan['ok']) {
            $code = match ($plan['error_code']) {
                'state_conflict' => G7_CLI_EXIT_CONFLICT,
                'env_not_found' => G7_CLI_EXIT_REQUIREMENTS,
                default => G7_CLI_EXIT_INVALID,
            };

            return g7_cli_fail($out, [(string) $plan['message']], $code);
        }
        if ($plan['warning'] !== null) {
            $out->warn($plan['warning']);
        }

        // 삭제 확인 토큰은 재개 때 다시 제시해야 한다 — 비밀 파일(runtime.php, 0600)에만 둔다.
        if ($dropToken !== null) {
            $runtime = readInstallerRuntime() ?? [];
            $runtime['cli_drop_confirm_token'] = $dropToken;
            writeInstallerRuntime($runtime);
        }

        $lock = acquireWorkerLock(G7_CLI_HEARTBEAT_ACTIVE_SECONDS);
        if (! $lock['acquired']) {
            return g7_cli_fail($out, [lang('error_worker_busy')], G7_CLI_EXIT_CONFLICT);
        }

        $out->step(5, lang('step_5_installation'));
        $out->info(lang('cli_abort_hint'));
        $workerId = (string) $lock['worker_id'];
        setProgressEmitter($out->json ? new JsonLinesEmitter($workerId) : new CliEmitter($workerId));

        try {
            runInstallationTasks();
        } finally {
            releaseWorkerLock($workerId);
        }

        $state = getInstallationState();
        $status = (string) ($state['installation_status'] ?? '');

        // 중단 신호를 받았으면 하위 artisan 이 함께 끝나 「실패」로 남았을 수 있다 — 「중단」으로 남겨 이어 설치하게 한다.
        if (g7_cli_abort_requested() && $status !== 'completed') {
            $state['installation_status'] = 'aborted';
            $state['abort_reason'] = 'cli_signal';
            $state['aborted_at'] = date('Y-m-d H:i:s');
            saveInstallationState($state);
            g7_cli_apply_web_user_if_root($out, $webUser);

            return g7_cli_fail($out, [['key' => 'cli_aborted', 'params' => []]], G7_CLI_EXIT_ABORTED);
        }

        if ($status !== 'completed') {
            g7_cli_apply_web_user_if_root($out, $webUser);
            $out->info(lang('cli_failed_resume_hint'));

            return g7_cli_fail($out, [], G7_CLI_EXIT_FAILED);
        }

        // 경고 — best-effort 작업(언어팩·config/route 캐시·정적 게시) 중 끝나지 않은 것
        $warnings = [];
        $completed = $state['completed_tasks'] ?? [];
        foreach ($state['selected_extensions']['language_packs'] ?? [] as $packId) {
            if (! in_array('language_pack_install:'.$packId, $completed, true)) {
                $warnings[] = bestEffortFailureMessage('language_pack_install', (string) $packId);
            }
        }
        foreach (['config_cache', 'route_cache', 'static_publish'] as $taskId) {
            if (! in_array($taskId, $completed, true)) {
                $warnings[] = bestEffortFailureMessage($taskId, '');
            }
        }
        $appUrl = rtrim((string) ($state['config']['app_url'] ?? ''), '/');

        $finalize = installer_finalize_env();
        if (! $finalize['success']) {
            $warnings[] = lang('cli_finalize_failed_warning');
        }

        g7_cli_apply_web_user_if_root($out, $webUser);

        return g7_cli_print_completion($out, $appUrl, $warnings, $webUser['group'] ?? null);
    }
}

if (! function_exists('g7_cli_apply_web_user_if_root')) {
    /**
     * root + --web-user 면 웹서버 계정으로 소유권을 넘긴다 (설치 결과와 무관하게).
     *
     * @param  G7CliOutput  $out  출력
     * @param  array<string, mixed>|null  $webUser  웹서버 계정
     * @return void 반환값 없음
     */
    function g7_cli_apply_web_user_if_root(G7CliOutput $out, ?array $webUser): void
    {
        if ($webUser === null) {
            return;
        }

        $result = installer_cli_apply_web_user($webUser);
        $out->info(lang('cli_chown_done', ['count' => (string) $result['changed'], 'user' => $webUser['user'], 'group' => $webUser['group']]));
        if ($result['failed'] !== []) {
            $out->warn(lang('cli_chown_failed', ['count' => (string) count($result['failed'])]));
            foreach (array_slice($result['failed'], 0, 20, true) as $path => $reason) {
                $out->warn('  '.$path.': '.$reason);
            }
            $out->info('  $ '.installer_cli_web_user_manual_command($webUser));
        }
    }
}

if (! function_exists('g7_cli_env_hardening_lines')) {
    /**
     * .env 권한 강화 안내 줄 — 웹 완료 화면(views/5-installation.php)과 같은 두 선택지와 주의 문구.
     *
     * --web-user 설치는 .env 를 root:웹그룹 640 으로 두므로 600 하나만 권하면 웹서버가 .env 를 읽지 못한다.
     *
     * @param  int  $perms  현재 권한 비트
     * @param  string  $owner  .env 소유자 이름
     * @param  string  $envPath  .env 경로
     * @param  string  $webGroup  웹서버 그룹
     * @return array<int, string> 출력할 줄
     */
    function g7_cli_env_hardening_lines(int $perms, string $owner, string $envPath, string $webGroup): array
    {
        return [
            lang('env_hardening_hint_title'),
            '  '.sprintf(lang('env_hardening_hint_current_status'), decoct($perms), $owner),
            '  '.lang('env_hardening_hint_option_strict'),
            '    $ chmod 600 '.$envPath,
            '  '.lang('env_hardening_hint_option_group'),
            '    $ sudo chgrp '.$webGroup.' '.$envPath.' && sudo chmod 640 '.$envPath,
            '  '.lang('env_hardening_hint_note'),
        ];
    }
}

if (! function_exists('g7_cli_print_completion')) {
    /**
     * 완료 요약 — 웹 완료 화면과 같은 판정·문구(.env 권한 강화·cron) + 경고 표 + 마지막 줄 표식.
     *
     * @param  G7CliOutput  $out  출력
     * @param  string  $appUrl  사이트 URL
     * @param  array<int, string>  $warnings  경고 문구
     * @param  string|null  $webGroup  --web-user 로 넘긴 웹서버 그룹 (없으면 현재 실행 그룹)
     * @return int 종료 코드 (경고가 있어도 0)
     */
    function g7_cli_print_completion(G7CliOutput $out, string $appUrl, array $warnings, ?string $webGroup = null): int
    {
        $version = getCoreAppVersion();
        $adminLogin = $appUrl.'/admin/login';
        $cron = sprintf('* * * * * cd %s && php artisan schedule:run >> /dev/null 2>&1', BASE_PATH);

        $out->raw('completed', ['message' => lang('installation_complete_message')]);
        $out->info(lang('installed_version_label').' '.$version);
        $out->info(lang('site_url').': '.$appUrl);
        $out->info(lang('go_to_admin_login').': '.$adminLogin);

        $envPath = BASE_PATH.'/.env';
        if (PHP_OS_FAMILY !== 'Windows' && is_file($envPath)) {
            clearstatcache(true, $envPath);
            $perms = fileperms($envPath) & 0777;
            if ($perms !== 0600) {
                $owner = function_exists('posix_getpwuid') ? (posix_getpwuid((int) fileowner($envPath))['name'] ?? 'owner') : 'owner';
                $webGroup ??= function_exists('posix_getegid') && function_exists('posix_getgrgid')
                    ? (posix_getgrgid(posix_getegid())['name'] ?? 'www-data')
                    : 'www-data';
                foreach (g7_cli_env_hardening_lines($perms, $owner, $envPath, $webGroup) as $line) {
                    $out->info($line);
                }
            }
        }

        $out->info(lang('cron_hint_title').' — '.lang('cron_hint_body'));
        $out->info('  '.$cron);

        foreach ($warnings as $warning) {
            $out->warn($warning);
        }

        $out->raw('summary', ['exit_code' => G7_CLI_EXIT_OK, 'version' => $version, 'admin_login_url' => $adminLogin, 'warnings' => $warnings]);

        if ($warnings !== [] && ! $out->json) {
            fwrite(STDOUT, "[g7-install-warnings]\n");
        }

        return G7_CLI_EXIT_OK;
    }
}
