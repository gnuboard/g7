<?php

/**
 * 그누보드7 인스톨러 - 데이터베이스 연결·권한·기존 테이블 검사 (웹·터미널 공유)
 *
 * 웹 Step 3 의 DB 테스트(`check-configuration.php?action=test-db`)와 터미널 설치가 **같은 함수**를 부른다.
 * 세션 지문 기록·기존 테이블 삭제 확인 토큰 발급은 호출자 몫이다 — 웹은 세션에, 터미널은 같은 프로세스 안에서
 * 결과를 바로 쓴다. 이 함수는 상태를 바꾸지 않는다.
 *
 * 의존: config.php(MAX_DB_PREFIX_LENGTH) · functions.php(getDatabaseConnection·checkDatabasePrivileges·checkExistingTables).
 */

if (! function_exists('installer_test_database')) {
    /**
     * DB 연결·권한을 검사하고(쓰기 DB 면) 기존 테이블을 탐지한다.
     *
     * @param  array<string, mixed>  $conn  host, port, database, username, password (+ 쓰기 DB 는 db_prefix)
     * @param  string  $type  write|read
     * @param  string  $lang  오류 문구 언어
     * @return array{success: bool, message: string, type: string, privileges: array<string, mixed>|null, existing_tables: array<string, mixed>|null, prefix_tables: array<int, string>, detect_prefix: string|null, severity: string|null}
     */
    function installer_test_database(array $conn, string $type, string $lang): array
    {
        global $translations;
        if (! is_array($translations ?? null) || $translations === []) {
            $translations = loadTranslations($lang);
        }

        $isReadDb = ($type === 'read');
        $result = [
            'success' => false,
            'message' => '',
            'type' => $isReadDb ? 'read' : 'write',
            'privileges' => null,
            'existing_tables' => null,
            'prefix_tables' => [],
            'detect_prefix' => null,
            'severity' => null,
        ];

        $key = $isReadDb ? 'db_read' : 'db_write';
        $config = [
            "{$key}_host" => $conn['host'] ?? 'localhost',
            "{$key}_port" => $conn['port'] ?? '3306',
            "{$key}_database" => $conn['database'] ?? '',
            "{$key}_username" => $conn['username'] ?? '',
            "{$key}_password" => $conn['password'] ?? '',
        ];
        $database = (string) $config["{$key}_database"];
        $username = (string) $config["{$key}_username"];

        try {
            if ($database === '' || $username === '') {
                throw new Exception(lang('error_db_credentials_required'));
            }

            // 최고권한 계정은 연결 시도 전에 막는다 — 판정 SSoT 는 PrivilegedDatabaseAccounts.
            if (\App\Support\PrivilegedDatabaseAccounts::isBlocked($username)) {
                throw new Exception(lang('error_db_username_privileged', ['username' => $username]));
            }

            // 접두사가 길면 자동 생성 인덱스명이 식별자 한도(64자)를 넘어 일부 확장 마이그레이션이 실패한다.
            if (! $isReadDb) {
                $tablePrefix = (string) ($conn['db_prefix'] ?? '');
                if (strlen($tablePrefix) > MAX_DB_PREFIX_LENGTH) {
                    throw new Exception(lang('error_db_prefix_too_long', [
                        'max' => MAX_DB_PREFIX_LENGTH,
                        'current' => strlen($tablePrefix),
                    ]));
                }
            }

            $pdo = getDatabaseConnection($config, $isReadDb);

            $privileges = checkDatabasePrivileges($pdo, $database, $isReadDb);
            if (! $privileges['has_all'] && ! empty($privileges['missing'])) {
                throw new Exception(lang('error_db_privileges_insufficient_detail', [
                    'type' => $isReadDb ? 'Read' : 'Write',
                    'missing' => implode(', ', $privileges['missing']),
                ]));
            }

            $result['privileges'] = [
                'has_all' => $privileges['has_all'],
                'found' => $privileges['found'],
                'required' => $privileges['required'],
            ];
            $result['message'] = lang($isReadDb ? 'success_db_read_connected' : 'success_db_write_connected');

            // 기존 테이블 감지 (쓰기 DB 만) — 입력 접두사를 G7 시그니처에 적용해 비교한다.
            if (! $isReadDb) {
                $detectPrefix = ($conn['db_prefix'] ?? '') !== '' ? (string) $conn['db_prefix'] : 'g7_';
                $existing = checkExistingTables($pdo, $database, $detectPrefix);
                $result['existing_tables'] = $existing;
                $result['detect_prefix'] = $detectPrefix;
                $result['severity'] = $existing['severity'] ?? null;
                $result['prefix_tables'] = filterTablesByPrefix($existing['all_tables'] ?? [], $detectPrefix);
            }

            $result['success'] = true;
        } catch (PDOException $e) {
            logInstallationError(lang('error_db_connection_failed', ['error' => $e->getMessage()]), $e);
            $result['message'] = lang('error_db_connection_failed_detail', [
                'type' => $isReadDb ? 'Read' : 'Write',
                'error' => $e->getMessage(),
            ]);
        } catch (Exception $e) {
            logInstallationError(lang('error_db_test_failed'), $e);
            $result['message'] = $e->getMessage();
        }

        return $result;
    }
}
