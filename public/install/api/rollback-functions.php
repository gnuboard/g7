<?php
/**
 * G7 인스톨러 - 롤백 함수 모음
 *
 * 설치 중단 시 각 작업을 롤백하는 함수들을 제공합니다.
 */

/**
 * 중단된 작업을 롤백합니다.
 *
 * @param string $taskId 롤백할 작업 식별자
 * @param array $state 현재 설치 상태
 * @return array ['success' => bool, 'message' => string] 롤백 결과
 */
function rollbackTask(string $taskId, array $state): array
{
    try {
        switch ($taskId) {
            case 'db_migrate':
                return rollbackDbMigrate();

            case 'db_seed':
                return rollbackDbSeed($state);

            case 'complete_flag':
                return rollbackCompleteFlag();

            // 롤백 불필요한 작업들 (새로 생성하면 되는 것들)
            case 'composer_check':
            case 'composer_install':
            case 'env_create':
            case 'env_update':
            case 'key_generate':
            case 'cache_clear':
            case 'create_settings_json':
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
                return [
                    'success' => true,
                    'message' => lang('rollback_not_needed_overwritable'),
                ];

            default:
                return [
                    'success' => false,
                    'message' => lang('rollback_unknown_task', ['task' => $taskId])
                ];
        }
    } catch (Exception $e) {
        addLog(lang('rollback_error', ['task' => $taskId, 'error' => $e->getMessage()]));
        return [
            'success' => false,
            'message' => lang('rollback_exception', ['error' => $e->getMessage()])
        ];
    }
}

/**
 * 마이그레이션 롤백을 실행합니다 (migrate:rollback --force)
 *
 * @param string|null $logPrefix 로그 접두사 (null이면 lang('log_prefix_rollback') 사용)
 * @return array ['success' => bool, 'message' => string, 'output' => array]
 */
function executeMigrateRollback(?string $logPrefix = null): array
{
    $logPrefix = $logPrefix ?? lang('log_prefix_rollback');
    try {
        addLog("{$logPrefix} " . lang('rollback_migrate_start'));

        $output = [];
        $returnCode = 0;
        $phpBin = escapeshellarg(function_exists('getPhpBinary') ? getPhpBinary() : 'php');
        $command = sprintf(
            'cd /d %s && %s artisan migrate:rollback --force 2>&1',
            escapeshellarg(BASE_PATH),
            $phpBin
        );

        exec($command, $output, $returnCode);

        addLog("{$logPrefix} " . lang('rollback_migrate_result', ['result' => implode("\n", $output)]));

        if ($returnCode === 0) {
            return [
                'success' => true,
                'message' => lang('rollback_migrate_success'),
                'output' => $output,
            ];
        }

        addLog("{$logPrefix} " . lang('rollback_migrate_failed_code', ['code' => $returnCode]));

        return [
            'success' => false,
            'message' => lang('rollback_migrate_failed'),
            'output' => $output,
        ];
    } catch (Exception $e) {
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
 * 시드 데이터 TRUNCATE를 실행합니다 (PDO 세션 내 FOREIGN_KEY_CHECKS 제어)
 *
 * @param array $config DB 연결 설정
 * @param bool $force true: 무조건 TRUNCATE, false: 미완료 시에만 TRUNCATE
 * @param string|null $logPrefix 로그 접두사 (null이면 lang('log_prefix_rollback') 사용)
 * @return array ['success' => bool, 'message' => string]
 */
function executeSeedTruncate(array $config, bool $force = false, ?string $logPrefix = null): array
{
    $logPrefix = $logPrefix ?? lang('log_prefix_rollback');
    if (empty($config['db_write_host']) || empty($config['db_write_database'])) {
        addLog("{$logPrefix} " . lang('rollback_seed_no_config'));
        return ['success' => true, 'message' => lang('rollback_seed_no_config')];
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['db_write_host'],
        $config['db_write_port'] ?? '3306',
        $config['db_write_database']
    );

    try {
        $pdo = new PDO($dsn, $config['db_write_username'], $config['db_write_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // 강제 모드가 아니면 완료 여부 확인
        if (!$force) {
            $stmt = $pdo->query("SHOW TABLES LIKE 'roles'");
            if ($stmt->rowCount() > 0) {
                $count = $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
                if ($count > 0) {
                    addLog("{$logPrefix} " . lang('rollback_seed_already_done'));
                    return ['success' => true, 'message' => lang('rollback_seed_already_done')];
                }
            }
            addLog("{$logPrefix} " . lang('rollback_seed_interrupted'));
        } else {
            addLog("{$logPrefix} " . lang('rollback_seed_force_truncate'));
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = ['users', 'roles', 'permissions', 'role_user', 'permission_role'];
        foreach ($tables as $table) {
            try {
                $pdo->exec("TRUNCATE TABLE `{$table}`");
                addLog("{$logPrefix} " . lang('rollback_table_truncated', ['table' => $table]));
            } catch (PDOException $e) {
                addLog("{$logPrefix} " . lang('rollback_table_truncate_skipped', ['table' => $table, 'error' => $e->getMessage()]));
            }
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return ['success' => true, 'message' => lang('rollback_seed_data_deleted')];
    } catch (PDOException $e) {
        if ($force) {
            return ['success' => false, 'message' => lang('rollback_db_connection_failed', ['error' => $e->getMessage()])];
        }
        addLog("{$logPrefix} " . lang('rollback_db_connection_failed_skip'));
        return ['success' => true, 'message' => lang('rollback_db_connection_failed_skip')];
    }
}

/**
 * db_seed 작업 롤백: 중단된 경우에만 TRUNCATE
 *
 * @param array $state 현재 설치 상태
 * @return array ['success' => bool, 'message' => string]
 */
function rollbackDbSeed(array $state): array
{
    try {
        return executeSeedTruncate($state['config'] ?? [], false, lang('log_prefix_rollback'));
    } catch (Exception $e) {
        return ['success' => false, 'message' => lang('rollback_seed_error', ['error' => $e->getMessage()])];
    }
}

/**
 * db_migrate 작업 강제 롤백 (설정으로 돌아갈 때 사용)
 *
 * @return array ['success' => bool, 'message' => string, 'output' => array]
 */
function forceRollbackDbMigrate(): array
{
    return executeMigrateRollback(lang('log_prefix_force_rollback'));
}

/**
 * db_seed 작업 강제 롤백 (설정으로 돌아갈 때 사용)
 *
 * @param array $state 현재 설치 상태
 * @return array ['success' => bool, 'message' => string]
 */
function forceRollbackDbSeed(array $state): array
{
    try {
        return executeSeedTruncate($state['config'] ?? [], true, lang('log_prefix_force_rollback'));
    } catch (Exception $e) {
        return ['success' => false, 'message' => lang('rollback_seed_error', ['error' => $e->getMessage()])];
    }
}

/**
 * complete_flag 작업 롤백: 설치 완료 플래그 제거
 */
function rollbackCompleteFlag(): array
{
    try {
        $envPath = BASE_PATH . '/.env';
        $installedFlagPath = BASE_PATH . '/storage/app/g7_installed';

        $results = [];

        // .env에서 INSTALLER_COMPLETED 제거
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);
            $envContent = preg_replace('/^INSTALLER_COMPLETED=.*$/m', '', $envContent);
            $envContent = preg_replace("/\n\n+/", "\n\n", $envContent);

            if (file_put_contents($envPath, $envContent) !== false) {
                addLog(lang('rollback_env_flag_removed'));
                $results[] = lang('rollback_env_flag_removed');
            }
        }

        // g7_installed 파일 삭제
        if (file_exists($installedFlagPath)) {
            if (@unlink($installedFlagPath)) {
                addLog(lang('rollback_installed_flag_removed'));
                $results[] = lang('rollback_installed_flag_removed');
            }
        }

        return [
            'success' => true,
            'message' => lang('rollback_complete_flag_removed', ['details' => implode(', ', $results)])
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => lang('rollback_complete_flag_error', ['error' => $e->getMessage()])
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
 * @param string $dir 삭제할 디렉토리 경로
 * @param bool $removeDir true이면 디렉토리 자체도 삭제 (기본: false, 내용만 삭제)
 * @return bool 삭제 성공 여부
 */
function deleteDirectory(string $dir, bool $removeDir = false): bool
{
    if (!is_dir($dir)) {
        return false;
    }

    // 보존해야 할 파일 패턴 (Git 추적용 파일)
    $preserveFiles = ['.gitkeep', '.gitignore'];

    $items = array_diff(scandir($dir), ['.', '..']);
    $hasPreservedFiles = false;

    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            deleteDirectory($path, true);
        } else {
            // 보존 대상 파일은 건너뛰기
            if (in_array($item, $preserveFiles, true)) {
                $hasPreservedFiles = true;
                continue;
            }
            @unlink($path);
        }
    }

    // 보존 파일이 남아있거나 removeDir이 false이면 디렉토리 유지
    if ($hasPreservedFiles || !$removeDir) {
        return true;
    }

    return @rmdir($dir);
}

/**
 * 현재 진행 중인 작업을 롤백합니다. (abort.php에서 사용)
 *
 * @param array $state 현재 설치 상태
 * @return array ['success' => bool, 'message' => string, 'task' => string|null]
 */
function rollbackCurrentTask(array $state): array
{
    $currentTask = $state['current_task'] ?? null;

    if (!$currentTask) {
        addLog(lang('rollback_no_current_task'));
        return [
            'success' => true,
            'message' => lang('rollback_no_current_task'),
            'task' => null,
        ];
    }

    if (in_array($currentTask, $state['completed_tasks'] ?? [])) {
        addLog(lang('rollback_task_already_completed', ['task' => $currentTask]));
        return [
            'success' => true,
            'message' => lang('rollback_task_already_completed', ['task' => $currentTask]),
            'task' => $currentTask,
        ];
    }

    addLog(lang('rollback_current_task_start', ['task' => $currentTask]));
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
 * 완료된 작업들을 역순으로 롤백합니다. (reset-state.php에서 사용)
 *
 * @param array $completedTasks 완료된 작업 목록
 * @param array $rollbackableTasks 롤백 가능한 작업 목록
 * @param array $state 현재 설치 상태
 * @return array ['results' => array, 'errors' => array]
 */
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

    addLog(lang('rollback_tasks_to_rollback', ['tasks' => implode(', ', $tasksToRollback)]));

    foreach ($tasksToRollback as $taskId) {
        addLog(lang('rollback_task_rolling_back', ['task' => $taskId]));

        if ($taskId === 'db_migrate') {
            $result = forceRollbackDbMigrate();
        } elseif ($taskId === 'db_seed') {
            $result = forceRollbackDbSeed($state);
        } else {
            $result = rollbackTask($taskId, $state);
        }

        if ($result['success']) {
            $results[] = $taskId . ': ' . $result['message'];
            addLog(lang('rollback_task_success', ['task' => $taskId]));
        } else {
            $errors[] = $taskId . ': ' . $result['message'];
            addLog(lang('rollback_task_failed', ['task' => $taskId, 'error' => $result['message']]));
        }
    }

    return ['results' => $results, 'errors' => $errors];
}

/**
 * 실패한 작업에 대한 수동 명령어를 생성합니다.
 *
 * @param string $taskId 작업 ID
 * @param string|null $target 대상 (확장 식별자)
 * @return array 수동 명령어 배열
 */
function getManualCommands(string $taskId, ?string $target = null): array
{
    $commands = [];
    $php = getPhpBinary();
    $composer = getComposerCommandForDisplay();

    switch ($taskId) {
        case 'composer_install':
            $commands[] = "{$composer} install --no-interaction --prefer-dist --optimize-autoloader";
            break;

        case 'env_create':
            $commands[] = getEnvCopyCommand();
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

        case 'create_settings_json':
            $commands[] = '# ' . lang('manual_cmd_settings_json');
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
