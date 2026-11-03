<?php

/**
 * 그누보드7 인스톨러 - 설치 시작 계획 확정 (웹·터미널 공유)
 *
 * 웹 `install-process.php`(SSE·폴링·단계별 공통 시작점)와 터미널 설치가 **같은 함수**로 설치를 시작한다:
 * 필수값 확인 → 관리자 비밀번호 출처 확인 → .env 존재·쓰기 가능 확인 → 기존 테이블 삭제 확인 검증 →
 * runtime.php(비밀·APP_KEY·계획 서명 키) 기록 → running 전환 + 계획 서명 → 로그 초기화 → CAS 저장.
 *
 * 소유자·CSRF 게이트, 출처 검사, 실행·진행 모드 판정, 시작 거부(워커 점유·단계별 토큰), 세션 처리와
 * HTTP 응답·폴링 분기는 호출자(웹 껍데기) 몫이다. 상태에 함께 저장돼 계획 서명에 들어가야 하는 웹 판정
 * 결과는 `$opts` 로 받는다. 터미널은 같은 프로세스에서 소유권을 각인한 뒤 이 함수를 부른다.
 *
 * 의존: config.php · functions.php · installer-state.php(installerIsRetryRun) · installer-runtime.php ·
 * installer-ownership.php. 명령 출력 임시 로그 청소(installerCleanupCommandOutputFiles)는 task-runner.php 가
 * 실려 있을 때만 부른다.
 */
if (! function_exists('installer_begin_installation')) {
    /**
     * 설치 시작 계획을 확정하고 state 를 running 으로 저장한다.
     *
     * `$opts` 키:
     * - installation_mode: sse|polling|stepwise|cli (기본 sse)
     * - state_extra: state 에 덧붙여 계획 서명에 함께 넣을 필드 (웹의 execution_mode_resolved·php_binary_resolved 등)
     * - runtime_installer: runtime.php 의 installer 섹션(단계별 실행 토큰·세션 소유 표식). 배열이 아니면(null·생략)
     *   그 섹션을 지운다 — 시작할 때마다 새로 발급하므로 이전 실행의 토큰이 남으면 안 된다.
     *
     * @param  array<string, mixed>  $state  현재 state (generation 포함)
     * @param  array<string, mixed>  $config  설치 config (비밀 제외 가능)
     * @param  array<string, string>  $secrets  비밀값 — admin_password·db_write_password·db_read_password (빈 값은 무시)
     * @param  string  $existingDbAction  skip|drop_tables
     * @param  string|null  $dropConfirmToken  기존 테이블 삭제 확인 토큰 (drop_tables 전용)
     * @param  array<string, mixed>  $opts  installation_mode(sse|polling|stepwise|cli) · state_extra(array) · runtime_installer(array|null)
     * @return array{ok: bool, status: int, error_code: string|null, message: string|null, missing_fields: array<int, string>, env_required: bool, env_not_writable: bool, state: array<string, mixed>, existing_db_action: string, drop_confirm_rejected: bool, warning: string|null}
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
            'env_not_writable' => false,
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

        // `.env` 가 있으나 쓰기 불가면 시작을 거부한다 (22차 #22-1 의 서버 절반).
        // 그 상태로 진행하면 14단계가 전부 성공하고도 finalize 의 `.env` 머지가 조용히 실패해
        // INSTALLER_COMPLETED 미기록 → 인스톨러가 영구히 열린 채 남는다(평문 자격증명을 담은
        // runtime.php 포함). 화면 게이트(19차 S-2 · 22차 #22-1)와 같은 술어의 서버 사본이다 —
        // 화면만이 유일한 지점이면 fetch 예외·재검사 우회가 곧 우회로가 된다.
        if (! @is_writable(BASE_PATH.'/.env')) {
            $result['error_code'] = 'env_not_writable';
            $result['env_required'] = true;
            $result['env_not_writable'] = true;
            $result['message'] = lang('error_env_not_writable_start');

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

        // 재시도/재개면 완료 작업을 유지해 건너뛴다. 판정은 installerIsRetryRun 이 소유한다 (21차 #21-9 ②) —
        // 상태 문자열만 보면 Step 3 재저장 같은 바깥 경로가 그 필드를 'ready' 로 갈아치웠을 때 완료 마커·
        // 롤백 기준선·실패 로그가 신규 설치 취급으로 소거된다. 진짜 근거는 이번 회전이 이미 한 일이다.
        $isRetry = installerIsRetryRun($state);

        // 비밀 채널 일원화 (이슈 #465) — DB·관리자 비밀번호는 runtime.php(0600, finalize 시 삭제)로만 전달한다.
        // buildInstallerRuntimeFromState() 가 기존 runtime 의 비밀번호·APP_KEY·계획 키를 보존한다.
        $runtime = buildInstallerRuntimeFromState($config);
        if (! empty($config['admin_password'])) {
            $runtime['admin'] = ['password' => $config['admin_password']];
        }
        if (! empty($config['core_update_github_token'])) {
            $runtime['core_update'] = ['github_token' => $config['core_update_github_token']];
        }
        // 인스톨러 내부 섹션(단계별 실행 토큰·세션 소유 표식) — 호출자가 이번 시작에 발급한 값으로 바꾸고,
        // 주지 않았으면 지운다. 비밀 채널 규칙(R3)에 따라 runtime.php(0600)에만 두고 state.json 에는 싣지 않는다.
        if (is_array($opts['runtime_installer'] ?? null)) {
            $runtime['installer'] = $opts['runtime_installer'];
        } else {
            unset($runtime['installer']);
        }
        // 설치 계획 서명 키 — 세션이 없는 워커가 소유 게이트를 통과한 계획인지 확인하는 데 쓴다.
        if (! is_string($runtime['plan_key'] ?? null) || $runtime['plan_key'] === '') {
            $runtime['plan_key'] = bin2hex(random_bytes(32));
        }
        if (! writeInstallerRuntime($runtime)) {
            throw new Exception(lang('state_save_failed'));
        }

        $state['current_step'] = 5;  // Step 5 = Installation (Step 4 = Extension Selection)
        $state['installation_status'] = 'running';
        $state['completed_tasks'] = $isRetry ? ($state['completed_tasks'] ?? []) : [];
        $state['skipped_tasks'] = $isRetry ? ($state['skipped_tasks'] ?? []) : [];
        // 마이그레이션 기준선은 `completed_tasks` 와 같은 수명을 갖는다 (19차 R2) — 재시도는
        // 같은 실행의 연장이라 유지하고, 새 시작은 비운다. 비우지 않으면 이전 회전의 기준선으로
        // 다음 회전의 롤백 범위가 계산되어, 이번 실행이 만들지도 않은 배치가 대상에 든다.
        $state['db_migrate_batch_baseline'] = $isRetry ? ($state['db_migrate_batch_baseline'] ?? null) : null;
        // 실패한 best-effort 작업은 재시도에서 다시 시도한다 — 목록을 비워 다음 작업 선택이 다시 뽑게 한다
        $state['best_effort_failed_tasks'] = [];
        // 의존성 경고는 이번 실행에서 다시 판정된다 — 이월하면 재시도 로그에 중복해 찍힌다 (11차 #A8)
        $state['dependency_skip_warnings'] = [];
        $state['current_task'] = null;
        $state['config'] = sanitizeConfigForState($config);
        $state['error'] = null;
        $state['failed_task'] = null;
        $state['failed_task_target'] = null;
        $state['failed_task_skippable'] = null;
        $state['error_message_key'] = null;
        $state['error_message_params'] = null;
        $state['error_detail'] = null;
        $state['rollback_failure'] = null;
        $state['manual_commands'] = null;
        $state['installation_mode'] = (string) ($opts['installation_mode'] ?? 'sse');

        // 호출자 판정 결과(웹: 실행 모드·자동 탐색된 PHP 경로) — 서명 전에 넣어야 계획 서명이 덮는다.
        foreach (is_array($opts['state_extra'] ?? null) ? $opts['state_extra'] : [] as $extraKey => $extraValue) {
            $state[(string) $extraKey] = $extraValue;
        }

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

        // 강제 종료된 이전 요청이 남긴 명령 출력 임시 로그를 정리한다 (9차 #L9) —
        // 재시도마다 새 파일명(uniqid)이 만들어지므로 청소하지 않으면 계속 쌓인다.
        if (function_exists('installerCleanupCommandOutputFiles')) {
            installerCleanupCommandOutputFiles();
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
