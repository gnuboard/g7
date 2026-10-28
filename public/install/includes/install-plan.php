<?php

/**
 * 그누보드7 인스톨러 - 설치 시작 계획 확정 (웹·터미널 공유)
 *
 * 웹 `install-process.php`(SSE·폴링 공통 시작점)와 터미널 설치가 **같은 함수**로 설치를 시작한다:
 * 필수값 확인 → 관리자 비밀번호 출처 확인 → .env 존재 확인 → 기존 테이블 삭제 확인 검증 →
 * runtime.php(비밀·APP_KEY·계획 서명 키) 기록 → running 전환 + 계획 서명 → 로그 초기화 → CAS 저장.
 *
 * 소유자·CSRF 게이트와 HTTP 응답·폴링 분기는 호출자(웹 껍데기) 몫이다. 터미널은 같은 프로세스에서
 * 소유권을 각인한 뒤 이 함수를 부른다.
 *
 * 의존: config.php · functions.php · installer-state.php · installer-runtime.php · installer-ownership.php.
 */

if (! function_exists('installer_begin_installation')) {
    /**
     * 설치 시작 계획을 확정하고 state 를 running 으로 저장한다.
     *
     * @param  array<string, mixed>  $state  현재 state (generation 포함)
     * @param  array<string, mixed>  $config  설치 config (비밀 제외 가능)
     * @param  array<string, string>  $secrets  비밀값 — admin_password·db_write_password·db_read_password (빈 값은 무시)
     * @param  string  $existingDbAction  skip|drop_tables
     * @param  string|null  $dropConfirmToken  기존 테이블 삭제 확인 토큰 (drop_tables 전용)
     * @param  array<string, mixed>  $opts  installation_mode(sse|polling|cli)
     * @return array{ok: bool, status: int, error_code: string|null, message: string|null, missing_fields: array<int, string>, env_required: bool, state: array<string, mixed>, existing_db_action: string, drop_confirm_rejected: bool, warning: string|null}
     */
    function installer_begin_installation(
        array $state,
        array $config,
        array $secrets,
        string $existingDbAction,
        ?string $dropConfirmToken,
        array $opts = []
    ): array {
        $result = [
            'ok' => false,
            'status' => 400,
            'error_code' => null,
            'message' => null,
            'missing_fields' => [],
            'env_required' => false,
            'state' => $state,
            'existing_db_action' => 'skip',
            'drop_confirm_rejected' => false,
            'warning' => null,
        ];

        foreach (['admin_password', 'db_write_password', 'db_read_password'] as $secretKey) {
            if (isset($secrets[$secretKey]) && $secrets[$secretKey] !== '') {
                $config[$secretKey] = $secrets[$secretKey];
            }
        }

        $generation = (int) ($state['generation'] ?? 0);

        // 필수 설정 항목
        $missingFields = [];
        foreach (['db_write_host', 'db_write_database', 'db_write_username', 'app_name', 'app_url', 'admin_name', 'admin_email'] as $field) {
            if (empty($config[$field])) {
                $missingFields[] = $field;
            }
        }

        // admin_password 는 state.config 에 기록되지 않으므로(이슈 #465) 3중 출처로 판정한다:
        // 입력(세션·터미널) → runtime.php admin 섹션(같은 소유자의 재시도·재개) → db_seed 완료(더는 불필요).
        $hasAdminPassword = ! empty($config['admin_password'])
            || ! empty(readInstallerRuntime()['admin']['password'] ?? '')
            || in_array('db_seed', $state['completed_tasks'] ?? [], true);

        if (! $hasAdminPassword) {
            $missingFields[] = 'admin_password';
        }

        if (! empty($missingFields)) {
            $result['error_code'] = 'required_fields_missing';
            $result['missing_fields'] = $missingFields;
            $result['message'] = lang('error_required_fields_missing', ['fields' => implode(', ', $missingFields)]);

            return $result;
        }

        if (! file_exists(BASE_PATH.'/.env')) {
            $result['error_code'] = 'env_not_found';
            $result['env_required'] = true;
            $result['message'] = lang('error_env_not_found');

            return $result;
        }

        $existingDbAction = in_array($existingDbAction, ['skip', 'drop_tables'], true) ? $existingDbAction : 'skip';

        // 기존 테이블 삭제 확인 — 액션 값만으로는 삭제하지 않는다. DB 테스트가 발급해 설치 계획에 실린 확인 토큰을
        // 제시하고, 그때 탐지한 테이블이 지금도 그대로여야 한다. 불충족이면 삭제 없이(skip) 진행하고 경고를 돌려준다.
        $dropConfirmRecord = is_array($state['drop_confirm'] ?? null) ? $state['drop_confirm'] : null;
        if ($existingDbAction === 'drop_tables') {
            $dbPrefix = (string) ($config['db_prefix'] ?? '');
            $currentPrefixTables = null;

            if ($dropConfirmRecord !== null && empty($dropConfirmRecord['consumed'])) {
                try {
                    $pdo = getDatabaseConnection(hydrateDbSecretsFromRuntime($config), false);
                    $statement = $pdo->query('SHOW TABLES');
                    $currentPrefixTables = filterTablesByPrefix(
                        $statement ? $statement->fetchAll(PDO::FETCH_COLUMN) : [],
                        installer_normalize_db_prefix($dbPrefix)
                    );
                } catch (Throwable $e) {
                    $currentPrefixTables = null;
                }
            }

            $decision = installer_apply_drop_confirm($dropConfirmRecord, (string) $dropConfirmToken, $dbPrefix, $currentPrefixTables);

            $existingDbAction = $decision['action'];
            $dropConfirmRecord = $decision['record'];
            $result['drop_confirm_rejected'] = $decision['rejected'];

            if ($decision['rejected']) {
                addLog(lang('log_drop_confirm_rejected', ['reason' => $decision['reason']]));
                $result['warning'] = lang('drop_confirm_rejected');
            }
        }

        // 재시도/재개(running·failed·aborted)면 완료 작업을 유지해 건너뛴다.
        $isRetry = in_array($state['installation_status'] ?? null, ['running', 'failed', 'aborted'], true);

        // 비밀 채널 일원화 (이슈 #465) — DB·관리자 비밀번호는 runtime.php(0600, finalize 시 삭제)로만 전달한다.
        // buildInstallerRuntimeFromState() 가 기존 runtime 의 비밀번호·APP_KEY·계획 키를 보존한다.
        $runtime = buildInstallerRuntimeFromState($config);
        if (! empty($config['admin_password'])) {
            $runtime['admin'] = ['password' => $config['admin_password']];
        }
        // 설치 계획 서명 키 — 세션이 없는 워커가 소유 게이트를 통과한 계획인지 확인하는 데 쓴다.
        if (! is_string($runtime['plan_key'] ?? null) || $runtime['plan_key'] === '') {
            $runtime['plan_key'] = bin2hex(random_bytes(32));
        }
        if (! writeInstallerRuntime($runtime)) {
            throw new Exception(lang('state_save_failed'));
        }

        $state['current_step'] = 5;
        $state['installation_status'] = 'running';
        $state['completed_tasks'] = $isRetry ? ($state['completed_tasks'] ?? []) : [];
        $state['current_task'] = null;
        $state['config'] = sanitizeConfigForState($config);
        $state['error'] = null;
        $state['installation_mode'] = (string) ($opts['installation_mode'] ?? 'sse');

        // existing_db_action 변경 시 db_cleanup 재실행 보장 (이슈 #319)
        $state = applyExistingDbActionStateGuard($state, $existingDbAction);
        $state['existing_db_action'] = $existingDbAction;
        $state['drop_confirm'] = $dropConfirmRecord;

        // 커밋될 세대로 설치 계획에 서명한다 (saveInstallationStateCas 가 같은 값으로 올린다).
        $state['generation'] = $generation + 1;
        $state['install_plan_sig'] = installer_plan_signature($state, $runtime['plan_key']);

        // 새로 시작하면 이전 로그를 지운다(재시도·재개는 유지).
        if (! $isRetry) {
            $logFilePath = BASE_PATH.'/storage/logs/installation.log';
            if (file_exists($logFilePath)) {
                @unlink($logFilePath);
            }
        }

        if (! saveInstallationStateCas($state, $generation)) {
            $result['status'] = 409;
            $result['error_code'] = 'state_conflict';
            $result['message'] = lang('error_state_conflict');

            return $result;
        }

        addLog(lang('log_installation_config_saved'));

        $result['ok'] = true;
        $result['status'] = 200;
        $result['state'] = $state;
        $result['existing_db_action'] = $existingDbAction;

        return $result;
    }
}
