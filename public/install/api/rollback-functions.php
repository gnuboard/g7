<?php

/**
 * G7 인스톨러 - 롤백 함수 모음
 *
 * 설치 중단 시 각 작업을 롤백하는 함수들을 제공합니다.
 *
 * 예외는 `Throwable` 로 받는다 — in-process 실행은 같은 프로세스에서 Laravel 을 부팅하므로
 * `Error`/`TypeError` 가 정상 범위다. `Exception` 만 잡으면 그 오류가 롤백 함수 밖으로 새어
 * `handleInstallationTaskFailure` 를 통째로 건너뛰고, 오케스트레이터의 예외 기록이 **원래
 * 실패 사유를 롤백 예외 메시지로 덮어쓴다** (state-management·run-task·install-worker
 * 세 파일이 먼저 같은 기준으로 정리됐고 이 파일만 남아 있었다).
 */

use App\Support\ExecutionEnvironment;

/**
 * 중단된 작업을 롤백합니다.
 *
 * @param  string  $taskId  롤백할 작업 식별자
 * @param  array  $state  현재 설치 상태
 * @return array ['success' => bool, 'message' => string] 롤백 결과
 */
function rollbackTask(string $taskId, array $state): array
{
    try {
        // 확장 태스크의 current_task 는 "taskId:target" 합성 키다 (완료 마커와 같은 키를 쓰기 위함).
        // 중단·연결끊김 경로는 current_task 를 그대로 넘기므로 여기서 태스크 종류만 떼어낸다 —
        // 안 그러면 module_install:foo 가 default 로 떨어져 "롤백 불필요" 가 "알 수 없는 작업"
        // 으로 뒤집히고, 운영자에게 불필요한 "수동 정리 필요" 가 표시된다.
        $taskKind = strpos($taskId, ':') !== false ? strstr($taskId, ':', true) : $taskId;

        switch ($taskKind) {
            case 'db_migrate':
                return rollbackDbMigrate();

            case 'db_seed':
                return rollbackDbSeed($state);

            case 'complete_flag':
                return rollbackCompleteFlag();

                // 롤백 불필요한 작업들 (새로 생성하면 되는 것들)
                //
                // 이 목록은 buildInstallationTaskList() 가 만드는 태스크 전수를 덮어야 한다 —
                // 빠진 태스크는 default 로 떨어져 "알 수 없는 작업" 이 되고, 롤백할 것이 없는
                // 단계의 실패·중단에 「롤백 실패」와 거짓 「수동 정리 필요」가 표시된다.
                // (`env_create` 는 현행 목록에 없는 구 태스크 — 레거시 state 의 current_task 를
                //  위해 남긴다. 죽은 표식은 죽은 채로 두는 편이 동작 불변이다.)
            case 'composer_check':
            case 'composer_install':
            case 'env_create':
            case 'env_update':
            case 'key_generate':
            case 'cache_clear':
            case 'create_settings_json':
            case 'dependency_precheck': // 검증만 한다 — 만든 것이 없다
            case 'db_cleanup':          // 재시도가 다시 비우고 migrate 가 다시 만든다
            case 'config_cache':        // bootstrap/cache 는 다음 부팅에 재생성된다
            case 'route_cache':         // 라우트 캐시는 확장 설치·변경 때 다시 굽는다
            case 'static_publish':      // 정적 게시물은 첫 방문의 자가 치유로 재생성된다
                return [
                    'success' => true,
                    'message' => lang('rollback_not_needed_recreatable'),
                ];

                // 롤백 불필요한 확장 작업 (재설치/재활성화 시 덮어쓰기 가능)
            case 'template_install':
            case 'template_activate':
            case 'module_install':
            case 'module_activate':
            case 'plugin_install':
            case 'plugin_activate':
            case 'user_template_install':
            case 'user_template_activate':
            case 'language_pack_install':
                return [
                    'success' => true,
                    'message' => lang('rollback_not_needed_overwritable'),
                ];

            default:
                return [
                    'success' => false,
                    'message' => lang('rollback_unknown_task', ['task' => $taskId]),
                ];
        }
    } catch (Throwable $e) {
        addLog(lang('rollback_error', ['task' => installerTaskKeyDisplayName($taskId), 'error' => $e->getMessage()]));

        return [
            'success' => false,
            'message' => lang('rollback_exception', ['error' => $e->getMessage()]),
        ];
    }
}

/**
 * 롤백용 DB 연결을 만든다 (설정 부재·부적합이면 null, 접속 실패는 PDOException).
 *
 * 시드 TRUNCATE 와 마이그레이션 배치 조회가 같은 연결 규칙을 쓴다 — 두 곳이 각자 DSN 을
 * 조립하면 한쪽만 주입 방어를 갖는 상태가 된다.
 *
 * @param  array<string, mixed>  $config  설치 상태의 DB 설정 (runtime.php 로 보강된 것)
 * @return PDO|null 연결 (설정이 없거나 값에 DSN 구분자가 섞여 있으면 null)
 */
function installerRollbackConnect(array $config): ?PDO
{
    if (empty($config['db_write_host']) || empty($config['db_write_database'])) {
        return null;
    }

    // DSN 파라미터 주입 차단 — 정상 입력에는 ;/=/NUL/CRLF 가 들어갈 일이 없다.
    foreach (['db_write_host', 'db_write_port', 'db_write_database'] as $field) {
        $val = (string) ($config[$field] ?? '');
        if ($val !== '' && preg_match('/[;=\0\r\n]/', $val)) {
            return null;
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['db_write_host'],
        $config['db_write_port'] ?? '3306',
        $config['db_write_database']
    );

    return new PDO($dsn, $config['db_write_username'] ?? '', $config['db_write_password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // 롤백은 실패 처리 경로다 — 닿지 않는 DB 를 기다리며 요청 수명을 다 쓰면 운영자에게는
        // 화면이 멈춘 것으로만 보인다. 짧게 끊고 「접속 실패」로 내려간다.
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

/**
 * 이번 실행이 만든 마이그레이션 건수 — 이 수만큼만 되돌린다.
 *
 * 배치 지정 없는 `migrate:rollback` 은 **마지막 배치**를 되돌린다. 기존 스키마가 있는 DB 에서
 * 이번 실행이 0건 적용하고 실패하면 그 마지막 배치는 운영자의 것이고, 되돌리는 순간 인스톨러가
 * 만들지도 않은 스키마가 사라진다. 그래서 migrate 직전에 기록해 둔 기준 배치보다 큰 행만 센다.
 *
 * 기준선을 모르면(레거시 상태·기록 실패) null 을 돌려준다 — 그 경우 되돌리지 않는다.
 * 「어느 배치가 이번 실행의 것인지 모른다」와 「되돌릴 것이 없다」는 다른 사실이다.
 *
 * @param  array<int, int>  $batchNumbers  migrations 테이블의 batch 값 목록
 * @param  int|null  $baselineBatch  migrate 직전의 최대 batch (없으면 null)
 * @return int|null 되돌릴 마이그레이션 수 (판정 불가면 null)
 */
function installerMigrateRollbackStepCount(array $batchNumbers, ?int $baselineBatch): ?int
{
    if ($baselineBatch === null) {
        return null;
    }

    $count = 0;
    foreach ($batchNumbers as $batch) {
        if ((int) $batch > $baselineBatch) {
            $count++;
        }
    }

    return $count;
}

/**
 * 확장 설치 태스크 id → 그 확장이 기록되는 테이블 (접두사 제외).
 *
 * @var array<string, string>
 */
const INSTALLER_EXTENSION_TASK_TABLES = [
    'module_install' => 'modules',
    'plugin_install' => 'plugins',
    'template_install' => 'templates',
    'user_template_install' => 'templates',
];

/**
 * 그 확장이 **실제로** 설치되어 있는가.
 *
 * ## 무엇을 가르는 판정인가
 *
 * `*_install` 이 실패로 기록되면 운영자에게 남는 행동은 재시도와 [건너뛰고 계속] 둘인데,
 * 재시도는 이미 설치된 확장에서 **원리상 성공할 수 없다**(인스톨러는 `--force` 를 넘기지
 * 않고, `module:install` 은 기존 설치를 발견하면 실패를 돌려준다). 그래서 남는 길은
 * 건너뛰기 하나인데, 그 처리가 짝이 되는 `*_activate` 까지 함께 지나가 버린다.
 *
 * 그 짝 처리는 「설치되지 않은 확장을 활성화할 수 없다」를 전제하지만, **부수효과가 끝난 뒤
 * 완료 마커를 남기기 전에 요청이 죽은 경우**(실패로 승격된 그 경로) 그 전제는
 * 사실이 아니다. 그대로 두면 운영자가 고른 확장이 **설치되어 있는데 켜지지 않은 채** 설치가
 * 「완료」로 끝난다 — 라우트도 메뉴도 없고, 오류도 로그도 남지 않는다.
 *
 * ## 판정 불가는 현행 동작을 유지한다
 *
 * DB 설정이 없거나 닿지 못하면 `null` 을 돌려준다. 호출부는 그때 **종전과 똑같이** 짝을
 * 함께 건너뛴다 — 확인하지 못한 것을 근거로 동작을 바꾸지 않는다.
 *
 * @param  string  $taskId  실패한 태스크 id (`module_install` 등)
 * @param  string  $target  확장 identifier
 * @param  array<string, mixed>  $config  DB 설정 (runtime.php 로 보강된 것)
 * @param  string  $prefix  테이블 접두사
 * @return bool|null 설치되어 있으면 true, 없으면 false, 판정 불가면 null
 */
function installerExtensionIsInstalled(string $taskId, string $target, array $config, string $prefix): ?bool
{
    $table = INSTALLER_EXTENSION_TASK_TABLES[$taskId] ?? null;
    if ($table === null || $target === '') {
        return null;
    }

    try {
        $pdo = installerRollbackConnect($config);
    } catch (Throwable $e) {
        return null;
    }

    if ($pdo === null) {
        return null;
    }

    try {
        // 테이블명은 사용자 입력(prefix)이 섞이므로 식별자 인용 + 백틱 이스케이프
        // (installerReadMigrationBatches 와 같은 규칙). identifier 는 바인딩한다.
        $quoted = str_replace('`', '``', $prefix.$table);
        $stmt = $pdo->prepare("SELECT 1 FROM `{$quoted}` WHERE `identifier` = ? LIMIT 1");
        $stmt->execute([$target]);

        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        // 테이블 부재 = 아직 마이그레이션 전 → 설치되어 있을 수 없다. 그 밖의 오류는
        // 구분할 수단이 없으므로 같은 갈래로 두되, 어느 쪽이든 「설치됨」은 아니다.
        return null;
    }
}

/**
 * migrations 테이블의 batch 값 목록을 읽는다.
 *
 * 「테이블이 없다」(빈 DB — 정상 경로)와 「DB 에 닿지 못했다」(판정 불가)를 구분한다. 둘을
 * 뭉뚱그리면 접속 실패가 기준선 0 으로 기록되어, 운영자의 기존 배치가 이번 실행의 것으로
 * 오인된다.
 *
 * @param  array<string, mixed>  $config  DB 설정
 * @param  string  $prefix  테이블 접두사
 * @return array<int, int>|null 배치 목록 (테이블 부재는 빈 배열, 접속 불가는 null)
 */
function installerReadMigrationBatches(array $config, string $prefix): ?array
{
    try {
        $pdo = installerRollbackConnect($config);
    } catch (Throwable $e) {
        return null;
    }

    if ($pdo === null) {
        return null;
    }

    try {
        // 테이블명은 사용자 입력(prefix)이 섞이므로 식별자 인용 + 백틱 이스케이프.
        $table = str_replace('`', '``', $prefix.'migrations');
        $rows = $pdo->query("SELECT `batch` FROM `{$table}`");

        return $rows === false ? [] : array_map('intval', $rows->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
    } catch (Throwable $e) {
        // 테이블 부재 — 아직 아무것도 마이그레이션되지 않은 DB
        return [];
    }
}

/**
 * 마이그레이션 롤백을 실행합니다 (이번 실행이 만든 배치 범위만)
 *
 * @param  string|null  $logPrefix  로그 접두사 (null이면 lang('log_prefix_rollback') 사용)
 * @return array ['success' => bool, 'message' => string, 'output' => array]
 */
function executeMigrateRollback(?string $logPrefix = null): array
{
    // 롤백도 설치 태스크와 같은 실행기를 쓴다 (A-3) — 실행 방식은 유효 실행 모드가 정한다.
    // state-management.php(중단/초기화) 경로에서는 task-runner 가 로드되지 않으므로 여기서 직접 로드.
    require_once __DIR__.'/../includes/task-runner.php';
    require_once __DIR__.'/../includes/installer-runtime.php';

    $logPrefix = $logPrefix ?? lang('log_prefix_rollback');
    try {
        addLog("{$logPrefix} ".lang('rollback_migrate_start'));

        $state = getInstallationState();
        $config = hydrateDbSecretsFromRuntime($state['config'] ?? []);
        $baseline = isset($state['db_migrate_batch_baseline']) ? (int) $state['db_migrate_batch_baseline'] : null;
        $batches = installerReadMigrationBatches($config, (string) ($config['db_prefix'] ?? ''));

        $step = $batches === null ? null : installerMigrateRollbackStepCount($batches, $baseline);

        // 이번 실행이 아무것도 적용하지 않았으면 되돌릴 것이 없다 — 그런데도 배치 지정 없이
        // 롤백하면 마지막 배치(= 운영자의 것)가 사라진다. 이것이 #D9 의 핵심 분기다.
        if ($step === 0) {
            addLog("{$logPrefix} ".lang('rollback_migrate_nothing_applied'));

            return [
                'success' => true,
                'message' => lang('rollback_migrate_nothing_applied'),
                'output' => [],
            ];
        }

        // 범위를 확정하지 못하면 **되돌리지 않는다**.
        //
        // `$step === null` 은 두 가지 사실을 뭉뚱그린다 — ① DB 에 닿지 못했다(`$batches === null`)
        // ② 닿았는데 기준선이 없다(기록 시점의 DB 미도달·`saveInstallationState` 실패·레거시 상태).
        // 종전에는 둘 다 배치 지정 없는 `migrate:rollback --force` 로 내려갔고, 그 근거는
        // 「어차피 DB 에 못 닿으니 무해하게 실패한다」였다. 그 근거는 ② 에서 성립하지 않는다 —
        // DB 는 붙어 있으므로 롤백이 **성공**하고, 배치 지정이 없으니 마지막 배치(= 운영자의 것)가
        // 사라진다. 앞서 막으려던 손실이 그대로 재발한다.
        //
        // `installerMigrateRollbackStepCount()` docblock(「기준선을 모르면 … 되돌리지 않는다」)과
        // `installerRecordMigrationBatchBaseline()` 주석(「기준선이 없으면 롤백은 되돌리지 않고
        // 수동 정리를 안내한다」), 그리고 `rollback_migrate_range_unknown` 문구 자신(「롤백을
        // 건너뜁니다. 데이터베이스를 직접 확인해 주세요」)이 이미 같은 계약을 말하고 있었다.
        // 셋 다 사실이 되도록 코드를 맞춘다.
        if ($step === null) {
            addLog("{$logPrefix} ".lang('rollback_migrate_range_unknown'));

            return [
                'success' => false,
                'message' => lang('rollback_migrate_range_unknown'),
                'output' => [],
            ];
        }

        $parameters = ['--force' => true, '--step' => (string) $step];

        $run = installerRunArtisan('migrate:rollback', $parameters);
        $output = $run['output'];
        $returnCode = $run['exit_code'];

        addLog("{$logPrefix} ".lang('rollback_migrate_result', ['result' => implode("\n", $output)]));

        if ($returnCode === 0) {
            return [
                'success' => true,
                'message' => lang('rollback_migrate_success'),
                'output' => $output,
            ];
        }

        addLog("{$logPrefix} ".lang('rollback_migrate_failed_code', ['code' => $returnCode]));

        return [
            'success' => false,
            'message' => lang('rollback_migrate_failed'),
            'output' => $output,
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => lang('rollback_migrate_error', ['error' => $e->getMessage()]),
            'output' => [],
        ];
    }
}

/**
 * db_migrate 작업 롤백: migrate:rollback 실행
 *
 * @return array ['success' => bool, 'message' => string, 'output' => array]
 */
function rollbackDbMigrate(): array
{
    return executeMigrateRollback(lang('log_prefix_rollback'));
}

/**
 * 시드 롤백이 비울 테이블 목록을 도출한다.
 *
 * 종전에는 `users`·`roles` … 를 **접두 없이** TRUNCATE 했다. `.env` 의 `DB_PREFIX` 기본값이
 * `g7_` 이므로 그 목록은 두 방향으로 동시에 틀린다 — 실제 시드 행(`g7_users`)은 그대로 남아
 * 「롤백 성공」이 거짓으로 보고되고, 같은 DB 를 쓰는 다른 애플리케이션의 `users`/`roles` 는
 * 지워진다. 후자는 Step 3 이 `foreign_data` 로 감지해 경고하는 바로 그 상황이다.
 *
 * 빈 prefix 방어는 `filterTablesByPrefix()` 가 db_cleanup 과 공유하는 규칙을 그대로 쓴다 —
 * 빈 문자열은 전체 일치가 되므로 삭제 자체를 거부한다.
 *
 * @param  array<int, string>  $existingTables  SHOW TABLES 결과
 * @param  string  $prefix  이 설치의 테이블 접두사
 * @return array<int, string> TRUNCATE 대상 테이블 (실재하는 것만)
 */
function installerSeedRollbackTargetTables(array $existingTables, string $prefix): array
{
    require_once __DIR__.'/../includes/functions.php';

    $owned = filterTablesByPrefix($existingTables, $prefix);
    if ($owned === []) {
        return [];
    }

    $targets = [];
    foreach (['users', 'roles', 'permissions', 'role_user', 'permission_role'] as $name) {
        $table = $prefix.$name;
        if (in_array($table, $owned, true)) {
            $targets[] = $table;
        }
    }

    return $targets;
}

/**
 * 시드 데이터 TRUNCATE를 실행합니다 (PDO 세션 내 FOREIGN_KEY_CHECKS 제어)
 *
 * @param  array  $config  DB 연결 설정
 * @param  bool  $force  true: 무조건 TRUNCATE, false: 미완료 시에만 TRUNCATE
 * @param  string|null  $logPrefix  로그 접두사 (null이면 lang('log_prefix_rollback') 사용)
 * @param  bool  $seedCompleted  시딩이 끝났는가 (completed_tasks 의 db_seed 마커)
 * @return array{success: bool, message: string, reverted: bool}
 *
 * `reverted` 는 **실제로 되돌렸는가**다. `success` 와 갈라야 하는 이유는, 이 함수가
 * 아무것도 되돌리지 않고 `success:true` 를 돌려주는 갈래를 넷 갖기 때문이다(마커로 이미 끝남 ·
 * DB 설정 없음 · 대상 테이블 없음 · 접속 실패 스킵). 호출부가 그 `true` 를 「되돌렸다」로 읽고
 * 완료 마커를 지우면, **DB 에는 시드가 남고 상태에는 없는** 상태가 만들어진다 — 그 상태에서
 * 재개하면 `db:seed` 가 다시 돌고 `AdminUserSeeder` 가 전체 사용자를 지운다.
 */
function executeSeedTruncate(array $config, bool $force = false, ?string $logPrefix = null, bool $seedCompleted = false): array
{
    // state.config 에는 DB 비밀번호가 기록되지 않으므로 runtime.php 에서 복원한다.
    // 호출자(rollbackDbSeed / forceRollbackDbSeed) 가 state['config'] 를 그대로 넘기므로
    // 각 호출자 대신 함수 진입부 1곳에서 처리한다. 롤백은 env_update 이후에만 발생하여
    // runtime.php 가 항상 존재하며, 부재 시 헬퍼가 원본 config 를 그대로 반환한다.
    require_once __DIR__.'/../includes/installer-runtime.php';
    $config = hydrateDbSecretsFromRuntime($config);

    $logPrefix = $logPrefix ?? lang('log_prefix_rollback');

    // 완료 판정은 DB 행 존재가 아니라 태스크 마커가 정한다.
    //
    // 종전에는 「`roles` 에 행이 있으면 시딩이 이미 끝난 것」이라는 휴리스틱으로 되돌리기를
    // 건너뛰고 「롤백 성공」을 보고했다. 그런데 이 함수가 불리는 상황 중 하나는 db_seed 가
    // **실패했을 때** 다 — 그 상황은 "시딩이 끝나지 않았다" 가 증명된 상태이고, 시딩은 roles 를
    // 먼저 채우므로 부분 시딩된 DB 에서 전제가 정확히 반대로 성립한다. 아무것도 되돌리지 않은
    // 채 성공으로 보고되므로 오류도 남지 않는다.
    if (! $force && $seedCompleted) {
        addLog("{$logPrefix} ".lang('rollback_seed_already_done'));

        return ['success' => true, 'message' => lang('rollback_seed_already_done'), 'reverted' => false];
    }

    try {
        $pdo = installerRollbackConnect($config);
        if ($pdo === null) {
            addLog("{$logPrefix} ".lang('rollback_seed_no_config'));

            return ['success' => true, 'message' => lang('rollback_seed_no_config'), 'reverted' => false];
        }

        // 완료 판정은 위에서 태스크 마커로 이미 끝났다 — 여기까지 온 것은 되돌릴 대상이다.
        if (! $force) {
            addLog("{$logPrefix} ".lang('rollback_seed_interrupted'));
        } else {
            addLog("{$logPrefix} ".lang('rollback_seed_force_truncate'));
        }

        // 대상은 이 설치의 prefix 가 정한다 — 접두 없는 이름은 같은 DB 를 쓰는 다른
        // 애플리케이션의 테이블이다.
        $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $tables = installerSeedRollbackTargetTables($existing, (string) ($config['db_prefix'] ?? ''));

        if ($tables === []) {
            addLog("{$logPrefix} ".lang('rollback_seed_no_target_tables'));

            return ['success' => true, 'message' => lang('rollback_seed_no_target_tables'), 'reverted' => false];
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            try {
                $pdo->exec('TRUNCATE TABLE `'.str_replace('`', '``', $table).'`');
                addLog("{$logPrefix} ".lang('rollback_table_truncated', ['table' => $table]));
            } catch (PDOException $e) {
                addLog("{$logPrefix} ".lang('rollback_table_truncate_skipped', ['table' => $table, 'error' => $e->getMessage()]));
            }
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return ['success' => true, 'message' => lang('rollback_seed_data_deleted'), 'reverted' => true];
    } catch (PDOException $e) {
        if ($force) {
            return ['success' => false, 'message' => lang('rollback_db_connection_failed', ['error' => $e->getMessage()]), 'reverted' => false];
        }
        addLog("{$logPrefix} ".lang('rollback_db_connection_failed_skip'));

        return ['success' => true, 'message' => lang('rollback_db_connection_failed_skip'), 'reverted' => false];
    }
}

/**
 * 시딩이 끝났는지 판정한다 — 근거는 completed_tasks 의 db_seed 마커다.
 *
 * DB 행 존재로 추정하면 「실패해서 되돌리는 중」과 「이미 끝나서 건드리면 안 되는 상태」가
 * 구분되지 않는다 — 시딩은 roles 를 먼저 채우므로 부분 시딩에서 전제가 반대로 성립한다.
 *
 * @param  array<string, mixed>  $state  현재 설치 상태
 * @return bool 시딩 완료 여부
 */
function installerSeedRollbackIsAlreadyDone(array $state): bool
{
    return in_array('db_seed', (array) ($state['completed_tasks'] ?? []), true);
}

/**
 * db_seed 작업 롤백: 중단·실패로 시딩이 끝나지 않은 경우에만 TRUNCATE
 *
 * @param  array  $state  현재 설치 상태
 * @return array ['success' => bool, 'message' => string]
 */
function rollbackDbSeed(array $state): array
{
    try {
        return executeSeedTruncate(
            $state['config'] ?? [],
            false,
            lang('log_prefix_rollback'),
            installerSeedRollbackIsAlreadyDone($state),
        );
    } catch (Throwable $e) {
        return ['success' => false, 'message' => lang('rollback_seed_error', ['error' => $e->getMessage()])];
    }
}

/**
 * db_migrate 작업 강제 롤백.
 *
 * 현재 호출처 0. 종전 주석은 "reset-state.php 에서 사용" 이라고 적었으나 그 파일은 존재하지
 * 않고, `state-management.php?action=reset` 은 롤백을 수행하지 않는다(안내만 남긴다). 제거는
 * 동작 변경이라 별개 결정이므로 표기만 사실에 맞춘다 (10차, 제품 결정 「기록만」).
 *
 * @return array ['success' => bool, 'message' => string, 'output' => array]
 */
function forceRollbackDbMigrate(): array
{
    return executeMigrateRollback(lang('log_prefix_force_rollback'));
}

/**
 * db_seed 작업 강제 롤백.
 *
 * 현재 호출처 0 — 위 forceRollbackDbMigrate 와 같은 사정이다.
 *
 * @param  array  $state  현재 설치 상태
 * @return array ['success' => bool, 'message' => string]
 */
function forceRollbackDbSeed(array $state): array
{
    try {
        return executeSeedTruncate($state['config'] ?? [], true, lang('log_prefix_force_rollback'));
    } catch (Throwable $e) {
        return ['success' => false, 'message' => lang('rollback_seed_error', ['error' => $e->getMessage()])];
    }
}

/**
 * complete_flag 작업 롤백: 설치 완료 플래그 제거
 *
 * 되돌리는 것은 **이 태스크가 만든 것**뿐이다 — g7_installed (Step 5 완료 마커) 와
 * .env 의 INSTALLER_COMPLETED (finalize 완료 마커). 어느 단계에서 실패했는지에 따라
 * 일부만 존재할 수 있으므로 각각 독립적으로 처리.
 *
 * runtime.php 는 지우지 않는다. 그 파일에는 stepwise 토큰·APP_KEY·DB 비밀번호가
 * 함께 들어 있어, 지우면 `run-task`·`abort`·`reset`·`skip_task` 가 전부 403(expected==='')
 * 이 되고 유일한 출구인 재시작마저 세션 유실 시 DB 비밀번호를 잃는다. 반면 되돌려야 할
 * `env_update`·`key_generate` 마커는 남아 있어 재실행되지도 않는다 — 복구 수단만 사라진다.
 * runtime.php 의 수명은 finalize 가 소유한다.
 */
function rollbackCompleteFlag(): array
{
    try {
        require_once __DIR__.'/../includes/installer-runtime.php';

        $envPath = BASE_PATH.'/.env';
        $installedFlagPath = BASE_PATH.'/storage/app/g7_installed';

        $results = [];

        $failures = [];

        // .env에서 INSTALLER_COMPLETED 제거 (finalize 후 롤백 시 존재)
        if (file_exists($envPath)) {
            // 읽기 결과를 확인한다. `file_get_contents` 가 false 를 돌려주면
            // `preg_replace` 가 그것을 빈 문자열로 다루므로, 이어지는 쓰기가 `.env` 를
            // **통째로 비운다** — DB 자격증명·APP_KEY 가 한 번에 사라지고, 이 경로에는
            // finalize 형제(`backupExistingEnvFile()`)와 달리 사본이 없다.
            $envContent = file_get_contents($envPath);

            if ($envContent === false) {
                $failures[] = lang('rollback_env_flag_read_failed');
                addLog(lang('rollback_env_flag_read_failed'));
            } else {
                $stripped = preg_replace('/^INSTALLER_COMPLETED=.*$/m', '', $envContent);
                $stripped = $stripped === null ? null : preg_replace("/\n\n+/", "\n\n", $stripped);

                if ($stripped === null) {
                    // PCRE 가 실패하면(백트랙 한계 등) null 이 온다 — 그것을 쓰면 위와 같은 결과다.
                    $failures[] = lang('rollback_env_flag_read_failed');
                    addLog(lang('rollback_env_flag_read_failed'));
                    // 형제 지점(finalize)과 같은 잠금을 쓴다 — 되돌리기와 마무리가
                    // 겹치는 창은 좁지만, 같은 파일을 쓰는 두 지점에 다른 규칙을 두지 않는다.
                    // 부분 쓰기도 실패로 읽는다 — 형제(finalize)가 같은 축을 막으므로
                    // 여기만 통과시키면 「같은 파일을 쓰는 두 지점에 다른 규칙」이 된다.
                    // 되돌리기가 `.env` 를 잘라 놓고 성공으로 보고하면 복구가 더 어려워진다.
                } elseif (installerWroteFully(@file_put_contents($envPath, $stripped, LOCK_EX), $stripped)) {
                    addLog(lang('rollback_env_flag_removed'));
                    $results[] = lang('rollback_env_flag_removed');
                } else {
                    $failures[] = lang('rollback_env_flag_write_failed');
                    addLog(lang('rollback_env_flag_write_failed'));
                }
            }
        }

        // g7_installed 파일 삭제
        if (file_exists($installedFlagPath)) {
            if (@unlink($installedFlagPath)) {
                addLog(lang('rollback_installed_flag_removed'));
                $results[] = lang('rollback_installed_flag_removed');
            } else {
                $failures[] = lang('rollback_installed_flag_remove_failed');
                addLog(lang('rollback_installed_flag_remove_failed'));
            }
        }

        // 제거하지 못한 것이 하나라도 있으면 실패로 보고한다.
        //
        // 이 태스크가 되돌리는 두 표식은 **인스톨러 진입 가드의 근거**다. 남은 채로
        // 「되돌렸다」고 보고하면 운영자에게는 되돌리기가 성공한 것으로 보이는데 모든
        // 인스톨러 엔드포인트는 계속 410 이고, 화면 어디에도 그 사유가 없어 탈출구가 사라진다.
        if ($failures !== []) {
            return [
                'success' => false,
                'message' => lang('rollback_complete_flag_partial', ['details' => implode(', ', $failures)]),
            ];
        }

        return [
            'success' => true,
            'message' => lang('rollback_complete_flag_removed', ['details' => implode(', ', $results)]),
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => lang('rollback_complete_flag_error', ['error' => $e->getMessage()]),
        ];
    }
}

/**
 * 디렉토리 내용을 삭제합니다.
 *
 * Git 추적 파일(.gitkeep 등)은 보존하고, 디렉토리 자체도 유지합니다.
 * 이는 인스톨러가 프로젝트 구조에 필수적인 파일/디렉토리를
 * 실수로 삭제하는 것을 방지합니다.
 *
 * @param  string  $dir  삭제할 디렉토리 경로
 * @param  bool  $removeDir  true이면 디렉토리 자체도 삭제 (기본: false, 내용만 삭제)
 * @return bool 삭제 성공 여부
 */
function deleteDirectory(string $dir, bool $removeDir = false): bool
{
    if (! is_dir($dir)) {
        return false;
    }

    // 보존해야 할 파일 패턴 (Git 추적용 파일)
    $preserveFiles = ['.gitkeep', '.gitignore'];

    // 열거 실패를 확인한다. 권한이 없으면 `scandir` 은 `false` 를 돌려주고,
    // PHP 8 에서 `array_diff(false, …)` 는 **TypeError** 다 — 워커가 그 자리에서 즉사하고
    // 사유는 실패 승격 경로를 지나 「치명 종료」로만 남는다.
    $entries = @scandir($dir);
    if ($entries === false) {
        return false;
    }

    $items = array_diff($entries, ['.', '..']);
    $hasPreservedFiles = false;
    // 개별 실패를 삼키지 않는다. 종전에는 `@unlink`·`@rmdir` 이 실패해도 `true` 를
    // 돌려주어, 「vendor 삭제 완료」 로그 뒤 반쪽 vendor 위에 `composer install` 이 돌았다.
    $allRemoved = true;

    foreach ($items as $item) {
        $path = $dir.DIRECTORY_SEPARATOR.$item;

        // 심볼릭 링크는 **따라가지 않는다**. `is_dir()` 은 링크 대상을 보므로
        // 링크된 디렉토리에 재귀하면 삭제 경계가 이 디렉토리 밖으로 나간다 — composer 의
        // path 저장소나 운영자가 만든 링크가 vendor/ 안에 있으면 그 **대상**이 지워진다.
        // 형제(`vendor-bundle-installer.php` 의 삭제 헬퍼)는 이미 같은 가드를 갖고 있었다.
        if (is_link($path)) {
            if (! @unlink($path) && ! @rmdir($path)) {
                $allRemoved = false;
            }

            continue;
        }

        if (is_dir($path)) {
            if (! deleteDirectory($path, true)) {
                $allRemoved = false;
            }
        } else {
            // 보존 대상 파일은 건너뛰기
            if (in_array($item, $preserveFiles, true)) {
                $hasPreservedFiles = true;

                continue;
            }
            if (! @unlink($path)) {
                $allRemoved = false;
            }
        }
    }

    // 보존 파일이 남아있거나 removeDir이 false이면 디렉토리 유지
    if ($hasPreservedFiles || ! $removeDir) {
        return $allRemoved;
    }

    return $allRemoved && @rmdir($dir);
}

/**
 * 현재 진행 중인 작업을 롤백합니다. (abort.php에서 사용)
 *
 * @param  array  $state  현재 설치 상태
 * @return array ['success' => bool, 'message' => string, 'task' => string|null]
 */
function rollbackCurrentTask(array $state): array
{
    $currentTask = $state['current_task'] ?? null;

    if (! $currentTask) {
        addLog(lang('rollback_no_current_task'));

        return [
            'success' => true,
            'message' => lang('rollback_no_current_task'),
            'task' => null,
        ];
    }

    if (in_array($currentTask, $state['completed_tasks'] ?? [])) {
        addLog(lang('rollback_task_already_completed', ['task' => installerTaskKeyDisplayName((string) $currentTask)]));

        return [
            'success' => true,
            'message' => lang('rollback_task_already_completed', ['task' => installerTaskKeyDisplayName((string) $currentTask)]),
            'task' => $currentTask,
        ];
    }

    addLog(lang('rollback_current_task_start', ['task' => installerTaskKeyDisplayName((string) $currentTask)]));
    $rollbackResult = rollbackTask($currentTask, $state);

    if ($rollbackResult['success']) {
        addLog(lang('abort_rollback_success', ['message' => $rollbackResult['message']]));

        return [
            'success' => true,
            'message' => lang('abort_rollback_success', ['message' => $rollbackResult['message']]),
            'task' => $currentTask,
        ];
    } else {
        addLog(lang('abort_rollback_failed', ['message' => $rollbackResult['message']]));

        return [
            'success' => false,
            'message' => lang('failed_rollback_failed', ['message' => $rollbackResult['message']]),
            'task' => $currentTask,
        ];
    }
}

/**
 * 완료된 작업들을 역순으로 롤백합니다.
 *
 * 현재 호출처 0 — 종전 주석의 `reset-state.php` 는 존재하지 않는 파일이다 (10차, 제품 결정 「기록만」).
 *
 * @param  array  $completedTasks  완료된 작업 목록
 * @param  array  $rollbackableTasks  롤백 가능한 작업 목록
 * @param  array  $state  현재 설치 상태
 * @return array ['results' => array, 'errors' => array]
 */
// **현재 호출처 0** (저장소 전체 스캔, 테스트 포함).
//
// §7 R6 이 방어 조건으로 요구한 「완료분 역순 롤백」은 제품 경로에 없다. 실패·중단이 되돌리는
// 것은 **그 태스크 하나**뿐이고(`task-runner.php` 두 지점 · `state-management.php` 한 지점),
// N개 성공 후 N+1 실패에서 앞선 부수효과는 그대로 남는다. 그 설계 자체는 의도된 것이다 —
// 21종 중 실제로 되돌릴 것이 있는 태스크는 셋뿐이고 나머지는 재시도가 덮어쓴다.
//
// 게다가 `array_intersect($rollbackableTasks, $completedTasks)` 는 **첫 인자 순서**를
// 보존하므로 이어지는 `array_reverse` 도 실행 역순이 아니다. 되살려 쓰려면 그 정렬 근거부터
// 다시 세워야 한다.
//
// 제거는 동작 변경이라 별개 결정이므로 표기만 사실에 맞춘다 (형제 `forceRollbackDbMigrate`
// 와 같은 처분 — 10차 제품 결정 「기록만」).
function rollbackCompletedTasks(array $completedTasks, array $rollbackableTasks, array $state): array
{
    $results = [];
    $errors = [];

    if (empty($completedTasks)) {
        addLog(lang('rollback_no_completed_tasks'));

        return ['results' => $results, 'errors' => $errors];
    }

    addLog(lang('rollback_checking_tasks'));

    $tasksToRollback = array_reverse(
        array_intersect($rollbackableTasks, $completedTasks)
    );

    if (empty($tasksToRollback)) {
        addLog(lang('rollback_no_matching_tasks'));

        return ['results' => $results, 'errors' => $errors];
    }

    // 진행 기록은 사람 말로 쓴다 (§12.0 W3) — 같은 함수의 나머지 표기와 규칙을 맞춘다
    addLog(lang('rollback_tasks_to_rollback', [
        'tasks' => implode(', ', array_map('installerTaskKeyDisplayName', $tasksToRollback)),
    ]));

    foreach ($tasksToRollback as $taskId) {
        addLog(lang('rollback_task_rolling_back', ['task' => installerTaskKeyDisplayName($taskId)]));

        if ($taskId === 'db_migrate') {
            $result = forceRollbackDbMigrate();
        } elseif ($taskId === 'db_seed') {
            $result = forceRollbackDbSeed($state);
        } else {
            $result = rollbackTask($taskId, $state);
        }

        if ($result['success']) {
            $results[] = $taskId.': '.$result['message'];
            addLog(lang('rollback_task_success', ['task' => installerTaskKeyDisplayName($taskId)]));
        } else {
            $errors[] = $taskId.': '.$result['message'];
            addLog(lang('rollback_task_failed', ['task' => installerTaskKeyDisplayName($taskId), 'error' => $result['message']]));
        }
    }

    return ['results' => $results, 'errors' => $errors];
}

/**
 * 실패한 작업에 대한 수동 명령어를 생성합니다.
 *
 * @param  string  $taskId  작업 ID
 * @param  string|null  $target  대상 (확장 식별자)
 * @return array 수동 명령어 배열
 */
function getManualCommands(string $taskId, ?string $target = null): array
{
    // 무CLI(inprocess) 로 확정된 설치에서는 이 목록을 만들지 않는다 — 그 환경은 외부 명령
    // 실행이 막혀 있다는 판정 위에서 stepwise 로 도는 것이므로, `php artisan …` 안내는
    // 운영자가 따를 수 없는 유일한 안내가 된다. 그 환경의 복구 수단은 화면의 [다시 시도]·
    // [건너뛰고 계속]·[되돌리기] 이고, 목록이 비면 진행 기록에 안내 문단 자체가 나오지 않는다.
    if (function_exists('installerArtisanExecutionMode')
        && installerArtisanExecutionMode() === ExecutionEnvironment::MODE_INPROCESS) {
        return [];
    }

    $commands = [];
    $php = getPhpBinary();
    $composer = getComposerCommandForDisplay();

    // 이 목록도 buildInstallationTaskList() 전수를 모집단으로 갖는다 — 빠진 태스크는 안내가
    // 빈 배열이 되고, 호출자가 빈 목록을 건너뛰므로 실패 화면에서 문단 자체가 조용히 사라진다.
    switch ($taskId) {
        case 'composer_check':
            $commands[] = "{$composer} --version";
            break;

        case 'composer_install':
            // --no-dev 는 마법사가 실제로 실행하는 명령(task-runner.php)과 같아야 한다. 이 안내를
            // 옵션 없이 따라 하면 개발용(require-dev) 패키지가 섞인 vendor 가 만들어지고, 그 상태는
            // 이후 코어 업데이트가 vendor 를 교체할 때 이전 패키지 매니페스트와 어긋나 부팅을 깨뜨린다.
            $commands[] = "{$composer} install --no-interaction --no-dev --prefer-dist --optimize-autoloader";
            break;

        case 'env_create':
        case 'env_update':
            $commands[] = getEnvCopyCommand();
            break;

        case 'dependency_precheck':
            $commands[] = '# '.lang('manual_cmd_dependency_precheck');
            break;

        case 'db_cleanup':
            $commands[] = '# '.lang('manual_cmd_db_cleanup');
            break;

        case 'config_cache':
            $commands[] = "{$php} artisan config:cache";
            break;

        case 'route_cache':
            $commands[] = "{$php} artisan route:cache";
            break;

        case 'static_publish':
            $commands[] = "{$php} artisan ext-static:publish --force";
            break;

        case 'key_generate':
            $commands[] = "{$php} artisan key:generate";
            break;

        case 'db_migrate':
            $commands[] = "{$php} artisan migrate --force";
            break;

        case 'db_seed':
            $commands[] = "{$php} artisan db:seed --force";
            break;

        case 'template_install':
            if ($target) {
                $commands[] = "{$php} artisan template:install {$target}";
            }
            break;

        case 'template_activate':
            if ($target) {
                $commands[] = "{$php} artisan template:activate {$target}";
            }
            break;

        case 'module_install':
            if ($target) {
                $commands[] = "{$php} artisan module:install {$target}";
            }
            break;

        case 'module_activate':
            if ($target) {
                $commands[] = "{$php} artisan module:activate {$target}";
            }
            break;

        case 'plugin_install':
            if ($target) {
                $commands[] = "{$php} artisan plugin:install {$target}";
            }
            break;

        case 'plugin_activate':
            if ($target) {
                $commands[] = "{$php} artisan plugin:activate {$target}";
            }
            break;

        case 'user_template_install':
            if ($target) {
                $commands[] = "{$php} artisan template:install {$target}";
            }
            break;

        case 'user_template_activate':
            if ($target) {
                $commands[] = "{$php} artisan template:activate {$target}";
            }
            break;

        case 'language_pack_install':
            if ($target) {
                $commands[] = "{$php} artisan language-pack:install {$target} --source=bundled";
            }
            break;

        case 'create_settings_json':
            $commands[] = '# '.lang('manual_cmd_settings_json');
            break;

        case 'cache_clear':
            $commands[] = "{$php} artisan optimize:clear";
            break;

        case 'complete_flag':
            if (isWindows()) {
                $commands[] = 'echo INSTALLER_COMPLETED=true >> .env';
                $commands[] = 'type nul > storage\\app\\g7_installed';
            } else {
                $commands[] = 'echo "INSTALLER_COMPLETED=true" >> .env';
                $commands[] = 'touch storage/app/g7_installed';
            }
            break;
    }

    return $commands;
}
