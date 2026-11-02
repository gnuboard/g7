<?php

/**
 * 그누보드7 인스톨러 - 데이터베이스 연결·권한·기존 테이블 검사 (웹·터미널 공유)
 *
 * 웹 Step 3 의 DB 테스트(`check-configuration.php?action=test-db`)와 터미널 설치가 **같은 함수**를 부른다.
 * 세션 지문 기록·기존 테이블 삭제 확인 토큰 발급은 호출자 몫이다 — 웹은 세션에, 터미널은 같은 프로세스 안에서
 * 결과를 바로 쓴다. 이 함수는 상태를 바꾸지 않는다.
 *
 * 의존: config.php(MAX_DB_PREFIX_LENGTH) · functions.php(getDatabaseConnection·checkDatabasePrivileges·checkExistingTables·
 * installerDbServerVersionNotice).
 */

if (! function_exists('installer_classify_db_connection_failure')) {
    /**
     * DB 연결 실패를 조치가 갈리는 만큼만 분류한다 (13차 보안#6).
     *
     * 운영자에게 필요한 것은 "무엇을 고쳐야 하는가" 지 드라이버 원문이 아니다. 그리고 웹 DB 테스트
     * 엔드포인트는 인증이 없으므로 원문을 그대로 돌려주면 임의 host:port 의 상태를 읽는
     * 오라클이 된다 — 분류하면 정상 운영자에게 필요한 정보는 유지되고 열거 가치는 사라진다.
     *
     * @param  PDOException  $e  연결 예외
     * @return string 사유 다국어 키
     */
    function installer_classify_db_connection_failure(PDOException $e): string
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $sqlState = (string) ($e->getCode() ?: '');

        if (in_array($driverCode, [1045, 1698], true) || $sqlState === '28000') {
            return 'error_db_failure_credentials';
        }

        if ($driverCode === 1049 || $sqlState === '42000') {
            return 'error_db_failure_unknown_database';
        }

        if (in_array($driverCode, [2002, 2003, 2005, 2006], true)) {
            return 'error_db_failure_unreachable';
        }

        if ($driverCode === 1044) {
            return 'error_db_failure_denied';
        }

        return 'error_db_failure_generic';
    }
}

if (! function_exists('installer_test_database')) {
    /**
     * DB 연결·권한을 검사하고(쓰기 DB 면) 기존 테이블을 탐지한다.
     *
     * 권한 「확인 불가」(indeterminate)는 차단하지 않고 privilege_warning 으로 알린다. DB 서버 버전 하한 미달은
     * db_server_notice 로 안내만 한다. PDO 연결 실패는 예외 원문 대신 분류 키(message_key)와 그 문구로 돌려준다.
     *
     * @param  array<string, mixed>  $conn  host, port, database, username, password (+ 쓰기 DB 는 db_prefix)
     * @param  string  $type  write|read
     * @param  string  $lang  오류 문구 언어
     * @return array{success: bool, message: string, message_key: string|null, type: string, privileges: array{has_all: bool, found: array<int, string>, required: array<int, string>, indeterminate: bool}|null, privilege_warning: string|null, db_server_notice: string|null, existing_tables: array<string, mixed>|null, prefix_tables: array<int, string>, detect_prefix: string|null, severity: string|null}
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
            // PDO 연결 실패의 분류 키 — 성공·비PDO 실패는 null.
            'message_key' => null,
            'type' => $isReadDb ? 'read' : 'write',
            'privileges' => null,
            'privilege_warning' => null,
            'db_server_notice' => null,
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
        $dbTypeLabel = $isReadDb ? 'Read' : 'Write';

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

            // 「확인 불가」는 차단하지 않는다 (제품 결정 §16.26.4 #2) — SHOW GRANTS 거부·롤 전개
            // 실패 환경에서 권한이 실재해도 설치가 원리상 불가능해지던 거짓 차단(20차 #6)의 수정.
            // 실제 부족은 설치 중 해당 단계가 사유와 함께 잡는다 (실패 화면 기정비).
            if (! empty($privileges['indeterminate'])) {
                $result['privilege_warning'] = lang('warning_db_privileges_indeterminate', ['type' => $dbTypeLabel]);
            } elseif (! $privileges['has_all'] && ! empty($privileges['missing'])) {
                // 해석이 확정된 부족만 차단한다.
                throw new Exception(lang('error_db_privileges_insufficient_detail', [
                    'type' => $dbTypeLabel,
                    'missing' => implode(', ', $privileges['missing']),
                ]));
            }

            // DB 서버 버전 하한 안내 (20차 #7 — 차단 아님). 조회 실패는 안내 대상이 아니다.
            try {
                $serverVersionNotice = installerDbServerVersionNotice((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
                if ($serverVersionNotice !== null) {
                    $result['db_server_notice'] = lang('warning_db_server_version_low', [
                        'server' => $serverVersionNotice['server'],
                        'minimum' => $serverVersionNotice['minimum'],
                    ]);
                }
            } catch (PDOException $versionProbeFailure) {
                $result['db_server_notice'] = null;
            }

            $result['privileges'] = [
                'has_all' => $privileges['has_all'],
                'found' => $privileges['found'],
                'required' => $privileges['required'],
                'indeterminate' => (bool) ($privileges['indeterminate'] ?? false),
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
            // 예외 원문은 싣지 않는다 (13차 보안#6) — 웹 DB 테스트는 인증 없이 임의
            // host:port 로 접속을 시도할 수 있어, 원문을 그대로 돌려주면 내부망 포트 상태와
            // DB 서버 버전·자격증명 존재 여부를 알려 주는 오라클이 된다. 대신 조치가 갈리는
            // 만큼만 분류해 사유 키로 돌려준다. 원문은 위 logInstallationError 가 남긴다.
            $failureKey = installer_classify_db_connection_failure($e);
            $result['message_key'] = $failureKey;
            $result['message'] = lang('error_db_connection_failed_detail', [
                'type' => $dbTypeLabel,
                'error' => lang($failureKey),
            ]);
        } catch (Exception $e) {
            logInstallationError(lang('error_db_test_failed'), $e);
            $result['message'] = $e->getMessage();
        }

        return $result;
    }
}
