<?php

use App\Support\ExecutionEnvironment;

/**
 * 그누보드7 웹 인스톨러 유틸리티 함수
 *
 * HTML/JavaScript 이스케이프, 데이터베이스 연결, 권한 검증, 에러 로깅 등의
 * 공통 유틸리티 함수를 제공합니다.
 */

// UTF-8 정규화 / JSON 출력 헬퍼 (js_escape · getWebServerUser 가 사용)
require_once __DIR__.'/utf8.php';
require_once __DIR__.'/env-value.php';

/**
 * HTML 이스케이프 함수
 *
 * XSS 공격을 방지하기 위해 HTML 특수 문자를 이스케이프합니다.
 * Laravel 환경에서는 이미 e()가 선언되어 있으므로 중복 선언을 방지합니다.
 *
 * 시그니처는 Laravel 의 e($value, $doubleEncode) 와 호환되게 둔다 — stepwise in-process
 * 실행에서는 인스톨러 함수가 먼저 선언된 뒤 같은 프로세스에 Laravel 이 부팅되므로,
 * 이 정의가 살아남아 프레임워크 코드(Blade 컴파일 결과 등)의 호출을 받는다.
 * 문자열이 아닌 값(Htmlable·Enum·Stringable)에서 TypeError 가 나지 않아야 한다.
 */
if (! function_exists('e')) {
    function e($value, $doubleEncode = true): string
    {
        if ($value === null) {
            return '';
        }

        if (is_object($value)) {
            if (method_exists($value, 'toHtml')) {
                return (string) $value->toHtml();
            }
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof UnitEnum) {
                $value = $value->name;
            } else {
                $value = (string) $value;
            }
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', (bool) $doubleEncode);
    }
}

/**
 * JavaScript 이스케이프 함수
 *
 * JavaScript 컨텍스트에서 안전하게 사용할 수 있도록 값을 이스케이프합니다.
 */
function js_escape(mixed $value): string
{
    $json = installer_json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
    );

    if ($json === '') {
        return '""';
    }

    return $json;
}

/**
 * 데이터베이스 연결 생성
 *
 * @param  array  $config  데이터베이스 연결 정보 배열
 * @param  bool  $isReadDb  Read DB 여부 (true: read, false: write)
 *
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
 * `indeterminate=true` 는 「부족」이 아니라 「확인 불가」다 — 게이트는 경고만 하고 진행을
 * 허용한다(PO 결정 §16.26.4 #2). 그때 missing 은 항상 빈 배열이다.
 *
 * @param  PDO  $pdo  PDO 연결 객체
 * @param  string  $database  데이터베이스명
 * @param  bool  $isReadDb  Read DB 여부 (true: read, false: write)
 * @return array{has_all: bool, required: array<string>, found: array<string>, missing: array<string>, indeterminate: bool}
 */
function checkDatabasePrivileges(PDO $pdo, string $database, bool $isReadDb = false): array
{
    // Read DB는 SELECT만 필요
    $requiredPrivileges = $isReadDb
        ? ['SELECT']
        : ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX'];

    try {
        // 현재 사용자의 권한 조회 — 대상 DB 를 덮는 부여만 센다 (20차 #4).
        // 종전에는 $database 를 받아만 두고 쓰지 않아, 같은 계정의 **다른 DB** 부여로
        // 검사가 통과했다. 설치는 db_migrate 에서 1142 로 죽고, 운영자는 "권한 정상"
        // 화면을 이미 지나온 뒤다.
        $stmt = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
        $grants = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $collected = installerCollectGrantedPrivileges($grants, $database, $requiredPrivileges);
        $foundPrivileges = $collected['found'];
        $unparseable = $collected['unparseable'];
        $rolesUnexpanded = false;

        // MySQL 8 롤 전개 (20차 #6): SHOW GRANTS 는 USING 없이 롤 권한을 전개하지 않는다.
        // 직접 부여만으로 부족하고 롤이 부여돼 있으면, 그 롤을 활성화한 출력으로 재조회한다.
        // 롤 토큰은 서버 자신의 SHOW GRANTS 출력 원문이므로 그대로 되돌려 보내도 안전하다.
        if (count($foundPrivileges) < count($requiredPrivileges) && $collected['roles'] !== []) {
            try {
                $expandedStmt = $pdo->query('SHOW GRANTS FOR CURRENT_USER() USING '.implode(', ', $collected['roles']));
                $expanded = installerCollectGrantedPrivileges(
                    $expandedStmt->fetchAll(PDO::FETCH_COLUMN),
                    $database,
                    $requiredPrivileges
                );
                $foundPrivileges = array_values(array_unique(array_merge($foundPrivileges, $expanded['found'])));
                $unparseable += $expanded['unparseable'];

                // 전개 출력에 **새 롤 부여**(중첩 롤)가 남아 있으면 확인을 단정하지 않는다
                // (22차 N-8) — 서버의 USING 이 롤 그래프를 재귀 전개하는지는 버전마다 다를 수
                // 있고, 전개되지 않은 롤의 권한을 조용히 놓치는 것보다 「확인 불가」 경고가 옳다.
                if (array_diff($expanded['roles'], $collected['roles']) !== []) {
                    $rolesUnexpanded = true;
                }
            } catch (PDOException $e) {
                // 전개 불가 (MariaDB 구버전 · 권한 제약 등) — 부족 단정 금지, 아래에서 확인 불가로 강등.
                $rolesUnexpanded = true;
            }
        }

        $missingPrivileges = array_values(array_diff($requiredPrivileges, $foundPrivileges));

        // 「부족」과 「확인 불가」의 구분 (PO 결정 §16.26.4 #2 — 확인 불가는 경고 후 진행 허용).
        // 전개하지 못한 롤이나 해석하지 못한 행이 **하나라도** 남아 있으면 판정을 단정하지
        // 않는다 — missing 이 비어 있어도 그 행이 REVOKE 변형(권한을 빼는 문장)일 수 있다
        // (21차 N2 파생 — 종전에는 missing 이 비면 unparseable 을 무시해, 해석 못 한 회수가
        // 초록으로 지나갔다). missing 을 비워 돌려주는 이유: 게이트가 `!has_all && missing`
        // 으로 차단하므로, 확인 불가 상태에서 missing 이 차 있으면 소비부가 갱신을 놓쳐도
        // 거짓 차단으로 샌다.
        $indeterminate = $rolesUnexpanded || $unparseable > 0;

        return [
            'has_all' => ! $indeterminate && $missingPrivileges === [],
            'required' => $requiredPrivileges,
            'found' => $foundPrivileges,
            'missing' => $indeterminate ? [] : $missingPrivileges,
            'indeterminate' => $indeterminate,
        ];
    } catch (PDOException $e) {
        // SHOW GRANTS 자체가 거부되는 환경 — 권한이 없다는 뜻이 아니라 모른다는 뜻이다.
        // 종전에는 missing=전부 로 떨어져 설치가 원리상 불가능했다 (20차 #6 거짓 차단).
        logInstallationError(lang('error_privilege_check_failed'), $e);

        return [
            'has_all' => false,
            'required' => $requiredPrivileges,
            'found' => [],
            'missing' => [],
            'indeterminate' => true,
        ];
    }
}

/**
 * SHOW GRANTS 출력에서 대상 DB 를 덮는 부여의 권한 토큰과 롤 부여를 수집합니다.
 *
 * 권한명은 토큰 단위로 대조한다 — `CREATE TEMPORARY TABLES` 는 CREATE 가 아니다 (20차 #5).
 * 해석하지 못한 줄은 개수만 세어 돌려준다: 그 줄에 권한이 있을 수도 있으므로, 호출자는
 * 부족 판정을 「확인 불가」로 강등하는 근거로 쓴다.
 *
 * 회수의 범위 규칙 (22차 N-7 명문화): 대상이 이 DB 를 **덮는** 회수(`*.*`·`db`.*)만 차감하고,
 * 그 안에서는 컬럼 단위 회수까지 보수적으로 뺀다. 대상이 더 좁은 회수(단일 테이블·타 DB)는
 * 차감하지 않는다 — 설치는 새 테이블을 만들므로 기존 테이블 한 개의 회수는 판정과 무관하다.
 *
 * @param  array<int, mixed>  $grants  SHOW GRANTS 출력 행
 * @param  string  $database  대상 데이터베이스명
 * @param  array<int, string>  $requiredPrivileges  요구 권한 목록 (대문자)
 * @return array{found: array<int, string>, roles: array<int, string>, unparseable: int}
 */
function installerCollectGrantedPrivileges(array $grants, string $database, array $requiredPrivileges): array
{
    $found = [];
    $revoked = [];
    $roles = [];
    $unparseable = 0;

    foreach ($grants as $grant) {
        $grant = trim((string) $grant);

        // partial_revokes(MySQL 8.0.16+) — SHOW GRANTS 는 REVOKE 행으로 회수분을 낸다.
        // 이 행을 해석 불가로 뭉개면 회수된 권한이 초록으로 지나간다 (21차 N2).
        if (stripos($grant, 'REVOKE ') === 0) {
            if (preg_match('/^REVOKE\s+(.+?)\s+ON\s+(.+?)\s+FROM\s/is', $grant, $m) === 1) {
                $covers = installerGrantTargetCoversDatabase($m[2], $database);

                if ($covers === null) {
                    $unparseable++;

                    continue;
                }

                if (! $covers) {
                    continue;
                }

                // 회수는 보수적으로 뺀다 — 컬럼 단위 회수도 그 권한의 온전함을 깨뜨린다.
                $tokens = installerGrantPrivilegeTokens($m[1], true);

                if (in_array('ALL', $tokens, true) || in_array('ALL PRIVILEGES', $tokens, true)) {
                    $revoked = array_merge($revoked, $requiredPrivileges);

                    continue;
                }

                foreach ($requiredPrivileges as $privilege) {
                    if (in_array($privilege, $tokens, true)) {
                        $revoked[] = $privilege;
                    }
                }

                continue;
            }

            $unparseable++;

            continue;
        }

        if ($grant === '' || stripos($grant, 'GRANT ') !== 0) {
            $unparseable++;

            continue;
        }

        // PROXY 부여는 스키마 권한 판정과 무관하다.
        if (preg_match('/^GRANT\s+PROXY\s+ON\s/i', $grant) === 1) {
            continue;
        }

        if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/is', $grant, $m) === 1) {
            $covers = installerGrantTargetCoversDatabase($m[2], $database);

            if ($covers === null) {
                $unparseable++;

                continue;
            }

            if (! $covers) {
                continue;
            }

            // 부여는 엄격하게 센다 — 컬럼 스코프 토큰(`SELECT (a,b)`)은 그 권한의 전체가
            // 아니므로 집계에서 제외한다 (21차 1c).
            $tokens = installerGrantPrivilegeTokens($m[1], false);

            if (in_array('ALL', $tokens, true) || in_array('ALL PRIVILEGES', $tokens, true)) {
                $found = array_merge($found, $requiredPrivileges);

                continue;
            }

            foreach ($requiredPrivileges as $privilege) {
                if (in_array($privilege, $tokens, true)) {
                    $found[] = $privilege;
                }
            }

            continue;
        }

        // ON 절이 없는 GRANT … TO … 는 롤 부여다. MySQL 8 은 롤을 인용해 출력하고
        // (`` `r`@`%` ``), MariaDB 는 **인용 없이** 출력한다(`GRANT app_rw TO 'u'@'%'`) —
        // 무인용 식별자도 롤로 신뢰해야 MariaDB 롤 계정에서 전개가 성립한다 (21차 N3).
        // 토큰은 USING 재조회에 원문 그대로 쓴다.
        if (preg_match('/^GRANT\s+(.+?)\s+TO\s/is', $grant, $m) === 1) {
            foreach (explode(',', $m[1]) as $roleToken) {
                $roleToken = trim($roleToken);

                // 인용 토큰도 형태를 검사한다 (23차 8g) — 선두 1글자만 보면 닫는 따옴표가
                // 없는 조각도 USING 에 보간된다. 여닫이 균형 + 선택적 @host(같은 인용 또는
                // bare 호스트)까지 닫힌 형태만 신뢰한다.
                $isQuoted = preg_match(
                    '/^(?:`[^`]+`|\'[^\']+\'|"[^"]+")(?:@(?:`[^`]+`|\'[^\']+\'|"[^"]+"|[A-Za-z0-9_$%.\-]+))?$/',
                    $roleToken
                ) === 1;
                // 호스트 꼬리도 닫힌 문자 집합으로 제한한다 (22차 N-6) — 토큰은 USING 재조회에
                // 문자열 보간되므로, 신뢰 근거(서버 자신의 출력)에 더해 형태로도 닫는다.
                $isBareIdentifier = preg_match('/^[A-Za-z0-9_$]+(?:@[A-Za-z0-9_$%.\-]+)?$/', $roleToken) === 1;

                if ($isQuoted || $isBareIdentifier) {
                    $roles[] = $roleToken;
                } else {
                    $unparseable++;
                }
            }

            continue;
        }

        $unparseable++;
    }

    $effective = array_diff(array_unique($found), array_unique($revoked));

    return [
        'found' => array_values(array_intersect($requiredPrivileges, $effective)),
        'roles' => array_values(array_unique($roles)),
        'unparseable' => $unparseable,
    ];
}

/**
 * GRANT 대상(`db`.`obj` 형태)이 대상 데이터베이스를 덮는지 판정합니다.
 *
 * MySQL 부여 대상의 DB 부분은 LIKE 계열 패턴이다 — `%`/`_` 는 와일드카드,
 * `\%`/`\_` 는 리터럴. 백틱 안 `` `` `` 는 리터럴 백틱이다.
 *
 * @param  string  $target  ON 과 TO 사이 구간 (예: "*.*", "`my\_db`.*")
 * @param  string  $database  대상 데이터베이스명
 * @return bool|null true=덮음, false=안 덮음, null=해석 불가
 */
function installerGrantTargetCoversDatabase(string $target, string $database): ?bool
{
    $target = trim($target);

    if ($target === '') {
        return null;
    }

    // *.* — 전역 부여
    if ($target[0] === '*') {
        return true;
    }

    $objectPart = null;

    if ($target[0] === '`') {
        $db = '';
        $length = strlen($target);
        $closed = false;
        $i = 1;

        for (; $i < $length; $i++) {
            if ($target[$i] === '`') {
                if ($i + 1 < $length && $target[$i + 1] === '`') {
                    $db .= '`';
                    $i++;

                    continue;
                }
                $closed = true;
                $i++;
                break;
            }
            $db .= $target[$i];
        }

        if (! $closed) {
            return null;
        }

        $objectPart = substr($target, $i);
    } else {
        $dot = strpos($target, '.');

        if ($dot === false) {
            return null;
        }
        $db = substr($target, 0, $dot);
        $objectPart = substr($target, $dot);
    }

    if ($db === '') {
        return null;
    }

    // 객체 부분이 `*` 인 부여만 DB 를 덮는다 (21차 N1) — `db`.`tbl`(테이블)·`db`.proc
    // (루틴) 스코프 부여는 새 테이블 생성·다른 테이블 접근을 주지 않으므로, 이것을 DB
    // 전체로 접으면 #4 가 막으려던 실패(권한 정상 화면 통과 후 db_migrate 1142)가
    // 다른 부여 형태로 재개방된다. 객체 부분이 없으면 해석 불가다.
    $objectPart = trim(ltrim(trim((string) $objectPart), '.'));

    if ($objectPart === '') {
        return null;
    }

    if ($objectPart !== '*') {
        return false;
    }

    $regex = '';
    $length = strlen($db);

    for ($i = 0; $i < $length; $i++) {
        $ch = $db[$i];

        if ($ch === '\\' && $i + 1 < $length && ($db[$i + 1] === '_' || $db[$i + 1] === '%')) {
            $regex .= preg_quote($db[$i + 1], '/');
            $i++;

            continue;
        }

        if ($ch === '_') {
            $regex .= '.';

            continue;
        }

        if ($ch === '%') {
            $regex .= '.*';

            continue;
        }

        $regex .= preg_quote($ch, '/');
    }

    // 대소문자 무시: lower_case_table_names 환경차로 인한 거짓 차단을 피한다.
    return preg_match('/^'.$regex.'$/is', $database) === 1;
}

/**
 * GRANT/REVOKE 문의 권한 구간을 토큰 목록으로 분해합니다 (공백 정규화 · 대문자).
 *
 * 컬럼 스코프 토큰(`SELECT (col1, col2)`)의 처리는 방향에 따라 다르다 (21차 1c):
 * - **부여**($includeColumnScoped=false): 컬럼 권한은 그 권한의 전체가 아니므로 제외한다 —
 *   2개 컬럼 SELECT 가 전체 SELECT 로 승격되면 Read DB 검사가 거짓 통과한다.
 * - **회수**($includeColumnScoped=true): 컬럼 단위 회수도 그 권한의 온전함을 깨뜨리므로
 *   보수적으로 기본 권한 토큰으로 포함한다(빼는 방향).
 *
 * @param  string  $segment  GRANT/REVOKE 와 ON 사이 구간
 * @param  bool  $includeColumnScoped  컬럼 스코프 토큰을 기본 권한으로 셀지 여부
 * @return array<int, string> 권한 토큰 (예: "SELECT", "CREATE TEMPORARY TABLES")
 */
function installerGrantPrivilegeTokens(string $segment, bool $includeColumnScoped = false): array
{
    // 괄호 안 쉼표가 토큰 경계로 오인되지 않도록, 컬럼 목록을 표식으로 접은 뒤 분해한다.
    $segment = preg_replace('/\([^)]*\)/', ' #COLS#', $segment) ?? $segment;

    $tokens = [];

    foreach (explode(',', $segment) as $raw) {
        $columnScoped = str_contains($raw, '#COLS#');
        $token = strtoupper(trim((string) preg_replace('/\s+/', ' ', str_replace('#COLS#', '', $raw))));

        if ($token === '') {
            continue;
        }

        if ($columnScoped && ! $includeColumnScoped) {
            continue;
        }

        $tokens[] = $token;
    }

    return $tokens;
}

/**
 * DB 서버 버전이 요구 하한(MySQL 8.0 / MariaDB 10.3) 미만이면 안내 정보를 돌려줍니다 (20차 #7).
 *
 * 차단이 아니라 안내다 — 하한 미달 서버에서도 연결은 성공하므로 종전에는 설치 중반
 * `db_migrate` 실패로만 드러났다. 해석 불가 문자열(프록시 배너 등)은 경고하지 않는다:
 * 거짓 경고는 운영자가 진짜 경고를 무시하게 만든다.
 *
 * @param  string|null  $rawVersion  PDO::ATTR_SERVER_VERSION 원문
 * @return array{server: string, minimum: string}|null 하한 미달이면 안내 정보, 아니면 null
 */
function installerDbServerVersionNotice(?string $rawVersion): ?array
{
    if (! is_string($rawVersion) || trim($rawVersion) === '') {
        return null;
    }

    $raw = trim($rawVersion);

    if (stripos($raw, 'mariadb') !== false) {
        // 레플리케이션 호환 접두 "5.5.5-" 를 걷어내면 첫 버전 토큰이 MariaDB 실제 버전이다.
        $normalized = (string) preg_replace('/^5\.5\.5-/', '', $raw);

        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $normalized, $m) !== 1) {
            return null;
        }

        return version_compare($m[1], '10.3', '>=')
            ? null
            : ['server' => 'MariaDB '.$m[1], 'minimum' => 'MariaDB 10.3'];
    }

    if (preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $raw, $m) !== 1) {
        return null;
    }

    return version_compare($m[1], '8.0', '>=')
        ? null
        : ['server' => 'MySQL '.$m[1], 'minimum' => 'MySQL 8.0'];
}

/**
 * 현재 PHP 프로세스에 composer root/non-interactive 환경변수를 적용합니다.
 *
 * exec() 자식 프로세스는 부모의 환경변수를 상속하므로 putenv() 시점에 설정해두면
 * 후속 composer 자식 프로세스가 root 환경에서도 정상 동작합니다.
 *
 * Synology DSM 등 PHP-FPM root 실행 환경 + 비대화형 컨텍스트 대응.
 */
if (! function_exists('applyInstallerComposerEnvVars')) {
    function applyInstallerComposerEnvVars(): void
    {
        // `putenv` 는 공유 호스팅이 흔히 막는 함수다. PHP 8 은 차단 함수를 함수 테이블에서
        // 제거하므로 무가드 호출은 `Error`(치명) 이고 `@` 로는 잡히지 않는다 — 이 한 줄이
        // 인스톨러 요청 전체를 무너뜨린다. 설정하지 못하면 proc_open 5번째 인자로 넘기는
        // buildInstallerComposerEnv() 가 같은 값을 자식에게 전달하므로 기능 손실은 없다.
        if (! ExecutionEnvironment::hasFunction('putenv')) {
            return;
        }

        putenv('COMPOSER_ALLOW_SUPERUSER=1');
        putenv('COMPOSER_NO_INTERACTION=1');
    }
}

/**
 * proc_open() 5번째 인자에 병합할 composer 환경변수 배열을 반환합니다.
 *
 * 호출자는 기존 $env 와 array_merge 하여 사용합니다.
 *
 * @return array<string, string>
 */
if (! function_exists('buildInstallerComposerEnv')) {
    function buildInstallerComposerEnv(): array
    {
        return [
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
        ];
    }
}

/**
 * 인스톨러 에러 로깅
 *
 * @param  string  $message  에러 메시지
 * @param  Throwable|null  $exception  예외 객체 (선택)
 */
function logInstallationError(string $message, ?Throwable $exception = null): void
{
    $logDir = BASE_PATH.'/storage/logs';
    $logFile = $logDir.'/installer.log';

    // 로그 디렉토리 생성
    if (! is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    // 로그 메시지 구성
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";

    if ($exception !== null) {
        $logMessage .= "\n";
        $logMessage .= 'Exception: '.get_class($exception)."\n";
        $logMessage .= "Message: {$exception->getMessage()}\n";
        $logMessage .= "File: {$exception->getFile()}:{$exception->getLine()}\n";
        $logMessage .= "Trace:\n{$exception->getTraceAsString()}\n";
    }

    $logMessage .= "\n".str_repeat('-', 80)."\n\n";

    // 로그 파일에 기록 — 쓰지 못하면 최후 폴백 채널로 넘긴다 (19차 F9).
    //
    // 이 함수만 쓰는 정보가 있다 — 예외의 클래스·파일·줄·스택트레이스는 진행 기록에 실리지
    // 않는다. `storage/logs` 를 만들지도 쓰지도 못하는 환경에서 반환값을 버리면 그 진단이
    // **통째로** 사라진다. 같은 파일의 형제 로거는 18차 #A8 로 이미 폴백을 갖췄고 이 지점만
    // 남아 있었다.
    if (@file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX) === false && function_exists('installerErrorLog')) {
        installerErrorLog('[installer] '.str_replace("\n", ' | ', trim($logMessage)));
    }
}

/**
 * 브라우저의 Accept-Language 헤더에서 선호 언어를 감지합니다.
 *
 * @return string|null 감지된 언어 코드 (ko 또는 en), 감지 실패 시 null
 */
function detectBrowserLanguage(): ?string
{
    // HTTP_ACCEPT_LANGUAGE 헤더가 없으면 null 반환
    if (! isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return null;
    }

    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'];

    // 지원 언어 목록
    $supportedLanguages = array_keys(SUPPORTED_LANGUAGES);   // 목록 SSoT 는 config.php 의 상수 (11차 #C7)

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
 * @param  string  $lang  언어 코드 (예: 'ko', 'en')
 * @return array<string, mixed> 번역 배열, 파일이 없으면 빈 배열
 */
function loadTranslations(string $lang): array
{
    $langFile = __DIR__."/../lang/{$lang}.php";

    if (! file_exists($langFile)) {
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
 * @param  string  $key  번역 키 (예: 'welcome_title', 'next_step')
 * @param  array  $replace  치환할 플레이스홀더 배열 (예: ['current' => '8.2', 'min' => '8.2'])
 */
if (! function_exists('lang')) {
    function lang(string $key, array $replace = []): string
    {
        global $translations;

        if (! isset($translations) || ! is_array($translations)) {
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
 * @param  int  $step  단계 번호 (0~5, 6은 예약됨)
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
 * @param  string  $task  작업 ID (예: 'composer_check', 'env_create')
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
 * @param  int  $step  이동할 단계 번호
 */
function redirectToStep(int $step): void
{
    $_SESSION['installer_current_step'] = $step;
    header('Location: '.INSTALLER_BASE_URL.'/');
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
 * @param  string  $lang  언어 코드 ('ko' 또는 'en')
 */
function handleLanguageChange(string $lang): void
{
    // 지원하는 언어만 허용
    if (! array_key_exists($lang, SUPPORTED_LANGUAGES)) {
        return;
    }

    // 세션에 저장
    $_SESSION['g7_locale'] = $lang;

    // state.json에도 저장 (다른 브라우저에서 재개 지원 + 설치 완료 후 본체 전달).
    // 저장 실패를 무음 폐기하지 않는다 (22차 #22-7, 17차 #C2 규율) — 실패가 무음이면
    // 그 순간부터 화면(세션)과 워커 로그·설치본 기본 언어(state)가 서로 다른 언어를 쓴다.
    $state = getInstallationState();
    $state['g7_locale'] = $lang;
    installerSaveStateOrReport($state, 'handleLanguageChange');
}

/**
 * 현재 단계의 상태를 업데이트합니다.
 *
 * @param  int  $step  완료할 단계 번호
 * @param  int  $nextStep  다음 단계 번호
 * @param  array  $additionalData  추가로 저장할 state 데이터 (예: ['config' => $data])
 */
function updateStepStatus(int $step, int $nextStep, array $additionalData = []): void
{
    $state = getInstallationState();
    $state['current_step'] = $nextStep;
    $state['step_status'][$step] = 'completed';

    if (! empty($additionalData)) {
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
    if (! isset($translations)) {
        $translations = loadTranslations(getCurrentLanguage());
    }

    $_SESSION['installer_current_step'] = 5;
    showAlertAndRedirect(
        lang('installation_in_progress'),
        lang('installation_in_progress_message'),
        INSTALLER_BASE_URL.'/'
    );
}

/**
 * 설치 완료 알럿을 표시하고 홈으로 리다이렉트합니다.
 */
function showInstallationCompletedAlert(): void
{
    global $translations;

    // 번역이 로드되지 않은 경우 로드
    if (! isset($translations)) {
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
 * @param  string  $title  페이지 제목
 * @param  string  $message  알럿 메시지
 * @param  string  $redirectUrl  리다이렉트 URL
 */
function showAlertAndRedirect(string $title, string $message, string $redirectUrl): void
{
    global $translations;

    if (! isset($translations)) {
        $translations = loadTranslations(getCurrentLanguage());
    }

    $lang = htmlspecialchars(getCurrentLanguage());
    $title = htmlspecialchars($title);
    $message = htmlspecialchars($message, ENT_QUOTES);
    $redirectUrl = htmlspecialchars($redirectUrl);

    echo '<!DOCTYPE html>';
    echo '<html lang="'.$lang.'">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>'.$title.'</title>';
    echo '</head>';
    echo '<body>';
    echo '<script>';
    echo 'alert("'.$message.'");';
    echo 'window.location.href = "'.$redirectUrl.'";';
    echo '</script>';
    echo '</body>';
    echo '</html>';
    exit;
}

/**
 * 단계 파일을 찾을 수 없을 때 에러 페이지를 표시합니다.
 *
 * @param  int  $currentStep  현재 단계 번호
 */
function showStepFileNotFoundError(int $currentStep): void
{
    $previousStep = max(0, $currentStep - 1);
    $_SESSION['installer_current_step'] = $previousStep;

    echo '<div class="installer-container">';
    echo '<h1 class="installer-title">';
    echo '<span class="error-icon">';
    // 제목 텍스트(lang('error'))가 이미 상태를 말하므로 아이콘은 보조기술에서 감춘다 —
    // getSvgIcon() 의 결과 아이콘 3종과 같은 처리다 (9차 #L33 · 12차 #B32).
    echo '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false">';
    echo '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />';
    echo '</svg>';
    echo '</span>';
    echo lang('error');
    echo '</h1>';
    echo '<p class="installer-description">'.lang('error_step_file_not_found').'</p>';
    echo '<div class="btn-group">';
    echo '<a href="'.INSTALLER_BASE_URL.'/" class="btn btn-secondary">← '.lang('previous').'</a>';
    echo '</div>';
    echo '</div>';
}

/**
 * 데이터베이스 필드 해시값을 계산합니다.
 *
 * DB 연결 정보가 변경되었는지 감지하기 위해 사용됩니다.
 *
 * @param  array  $config  폼 데이터 배열
 * @param  string  $prefix  DB 접두사 ('db_write' 또는 'db_read')
 * @return string MD5 해시값
 */
function getDatabaseFieldHash(array $config, string $prefix): string
{
    // json-encode:allow — 캐시 키 계산용 md5 입력, 응답으로 나가지 않는다.
    // 플래그를 바꾸면 해시값이 달라져 "필드가 바뀌었다" 는 오판이 생기므로 그대로 둔다.
    return md5(json_encode([
        $config["{$prefix}_host"] ?? '',
        $config["{$prefix}_port"] ?? '',
        $config["{$prefix}_database"] ?? '',
        $config["{$prefix}_username"] ?? '',
    ]));
}

/**
 * 인라인 `<script>` 안에 문자열 값을 안전하게 심는다 (따옴표 포함 JS 리터럴).
 *
 * 뷰가 `'<?= lang('key') ?>'` 처럼 값을 직접 보간하면, 그 값에 작은따옴표·`</script>`·개행이
 * 하나만 들어와도 스크립트 전체가 깨진다 — 번역 문구는 언제든 바뀌는 값이라 그 상태는
 * 「지금은 무해」일 뿐이다. HEX 4플래그로 `<`·`>`·`&`·`'`·`"` 를 전부 이스케이프하므로
 * HTML 텍스트 자리에 그대로 넣어도 안전하다 (9차 #L36).
 *
 * @param  string  $value  심을 문자열
 * @return string 따옴표를 포함한 JS 문자열 리터럴
 */
function installerJsString(string $value): string
{
    // 인코딩 자체는 installer_json_encode 가 소유한다 — 인스톨러의 유일한 json_encode 자리이고,
    // 계정명·경로의 invalid UTF-8 이 섞였을 때 빈 본문(HTTP 200)이 나가는 것을 막는다 (#62).
    // 여기서 더하는 것은 인라인 <script> 전용 HEX 플래그뿐이다.
    return installer_json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/**
 * SVG 아이콘을 반환합니다.
 *
 * @param  string  $type  아이콘 타입 ('success', 'warning', 'error', 'magic', 'terminal', 'package', 'check-circle', 'check')
 * @return string SVG HTML
 */
function getSvgIcon(string $type): string
{
    $icons = [
        // 결과 아이콘 셋은 같은 자리(결과 섹션 제목 옆)에 들어간다 — 접근성 처리도 형제끼리
        // 같아야 한다. 제목 텍스트가 이미 상태를 말하므로 아이콘은 보조기술에서 감춘다 (9차 #L33).
        'success' => '<svg class="result-icon result-icon-success" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle class="result-icon-circle" cx="26" cy="26" r="25" fill="none"/>
                <path class="result-icon-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
            </svg>',
        // 색은 CSS 토큰이 정한다 — 인라인 style="color:#f59e0b" 를 두면 느낌표 점(대응 CSS 규칙이
        // 없는 유일한 요소)만 그 리터럴로 칠해져, 다크에서 링·줄기(--warning-icon: #fbbf24)와
        // 한 글리프 안에서 색이 갈렸다. 형제(success·error)는 인라인 색이 없다.
        'warning' => '<svg class="result-icon result-icon-warning" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle class="result-icon-circle" cx="26" cy="26" r="25" fill="none" stroke-width="2"/>
                <path d="M26 12 L26 28" stroke-width="3" stroke-linecap="round" fill="none"/>
                <circle class="result-icon-dot" cx="26" cy="36" r="2.5"/>
            </svg>',
        'error' => '<svg class="result-icon result-icon-error" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle class="result-icon-circle" cx="26" cy="26" r="25" fill="none"/>
                <path class="result-icon-x" fill="none" d="M16 16 L36 36 M36 16 L16 36"/>
            </svg>',

        /*
         * Step 3 카드용 글리프 (11차 #B12).
         *
         * 이 다섯 키는 호출부 19곳이 부르는데 정의가 없어 `?:` 폴백이 **항상** 발화했고,
         * 결과적으로 카드마다 이모지(✨ ⚙ 📦 ◉ ✓)가 그려졌다 — 승인 시안 W2 에 없는 모양이고
         * 운영체제·글꼴에 따라 색·크기가 제각각이며, 텍스트 대체가 없어 화면 판독기가
         * "반짝임"·"톱니바퀴"·"소포" 를 읽는다.
         *
         * 위 결과 아이콘 셋(.result-icon)과 **다른 클래스**를 쓴다 — 그쪽은 48px 스트로크
         * 애니메이션이라 40px 카드 슬롯과 12px 글줄에 그대로 박으면 줄 높이가 무너진다
         * (같은 파일 3-configuration.php 의 기존 주석이 그 사실을 기록해 두었다).
         * 크기는 슬롯이 정하고(`currentColor` + `1em`), 색도 슬롯의 색을 물려받는다.
         */
        'magic' => '<svg class="inline-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <path d="M15 4V2M15 16v-2M8 9h2M20 9h2M17.8 11.8L19 13M17.8 6.2L19 5M3 21l9-9M12.2 6.2L11 5"/>
            </svg>',
        'terminal' => '<svg class="inline-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>
            </svg>',
        'package' => '<svg class="inline-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <path d="M16.5 9.4 7.5 4.21"/>
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>',
        'check-circle' => '<svg class="inline-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="10"/><polyline points="8 12 11 15 16 9"/>
            </svg>',
        'check' => '<svg class="inline-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <polyline points="20 6 9 17 4 12"/>
            </svg>',
    ];

    return $icons[$type] ?? '';
}

/**
 * 설치 결과 섹션 HTML을 렌더링합니다.
 *
 * @param  string  $sectionId  섹션 ID ('completion', 'aborted', 'failure')
 * @param  string  $iconType  아이콘 타입 ('success', 'warning', 'error')
 * @param  string  $titleKey  제목 번역 키
 * @param  string  $messageKey  메시지 번역 키 (failure인 경우 동적)
 * @param  string  $buttonsHtml  버튼 HTML
 * @param  string  $extraHtml  메시지 하단에 추가로 렌더링할 HTML (이미 escape 된 상태로 전달)
 * @return string 결과 섹션 HTML
 */
function renderInstallResultSection(string $sectionId, string $iconType, string $titleKey, string $messageKey, string $buttonsHtml, string $extraHtml = ''): string
{
    $icon = getSvgIcon($iconType);
    $title = htmlspecialchars(lang($titleKey));

    // failure는 동적 메시지이므로 번역하지 않음
    $message = $sectionId === 'failure'
        ? '<div class="result-message" id="failure-message"></div>'
        : '<p class="result-message">'.htmlspecialchars(lang($messageKey)).'</p>';

    // role="status" + tabindex="-1" — 이 섹션은 설치가 끝났을 때 비로소 나타나는 결과 발표다.
    // 선언이 없으면 14단계가 다 끝나고 완료/실패/중단이 떠도 화면 판독기에 알림이 없고,
    // 포커스는 [설치 시작] 이 있던 자리에 남아 Tab 으로 화면 끝까지 가야 결과를 만난다.
    return <<<HTML
    <div id="{$sectionId}-section" class="result-section hidden" role="status" aria-live="polite" tabindex="-1">
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
        $path = BASE_PATH.$relative;
        if (! is_readable($path)) {
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
 * @param  int  $currentStep  현재 단계 번호
 * @param  int  $totalSteps  전체 단계 수 (기본값: 7, Step 0~6)
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
        $html .= '<div class="'.$class.'"></div>';
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
        if ($info && ! empty($info['name'])) {
            return $info['name'];
        }
    }

    // 2. exec('whoami') — 가용성 판정은 코어와 공유하는 SSoT (disable_functions 까지 본다)
    //
    // whoami 는 UTF-8 이 아니라 그 명령을 실행한 콘솔의 코드페이지로 출력한다.
    // 한국어 Windows(OEM 949)에서 계정명에 한글이 있으면 invalid UTF-8 바이트가 들어오고,
    // 그 값이 응답에 실리면 json_encode 가 false 를 반환해 빈 본문(HTTP 200)이 나간다
    // (gnuboard/g7#62). 반환 전에 반드시 정규화한다.
    if (ExecutionEnvironment::hasFunction('exec')) {
        $user = @exec('whoami');
        if (! empty($user)) {
            return installer_normalize_process_output(trim($user), 'whoami');
        }
    }

    // 3. 환경변수 — `getenv` 차단 환경에서는 `$_SERVER` 로 대체한다
    //    (무가드 호출은 PHP 8 에서 `Error` 라 요구사항 화면 전체가 500 이 된다)
    $envUser = ExecutionEnvironment::hasFunction('getenv')
        ? (getenv('USER') ?: getenv('APACHE_RUN_USER') ?: null)
        : (($_SERVER['USER'] ?? null) ?: ($_SERVER['APACHE_RUN_USER'] ?? null) ?: null);
    if (! empty($envUser)) {
        return installer_normalize_process_output($envUser, 'env:USER');
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
        if ($info && ! empty($info['name'])) {
            return $info['name'];
        }
    }

    $envGroup = ExecutionEnvironment::hasFunction('getenv')
        ? (getenv('APACHE_RUN_GROUP') ?: null)
        : (($_SERVER['APACHE_RUN_GROUP'] ?? null) ?: null);
    if (! empty($envGroup)) {
        return installer_normalize_process_output($envGroup, 'env:APACHE_RUN_GROUP');
    }

    return null;
}

/**
 * 파일/디렉토리의 소유자 이름을 반환합니다.
 *
 * posix 확장이 없거나 확인할 수 없는 경우 null을 반환합니다.
 *
 * @param  string  $path  파일/디렉토리 경로
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
        if ($info && ! empty($info['name'])) {
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
 * @param  string  $path  파일/디렉토리 경로
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
        if ($info && ! empty($info['name'])) {
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

    return $user.':'.$group;
}

if (! function_exists('installerDirectoryIsWritable')) {
    /**
     * 디렉토리에 **실제로** 쓸 수 있는지 확인합니다.
     *
     * `is_writable()` 은 Windows 에서 읽기전용 **속성**만 본다 — NTFS ACL 은 보지 않는다.
     * 그래서 서비스 계정에 쓰기 ACL 이 없는 디렉토리도 true 로 통과시키고, 설치는
     * 요구사항 화면을 모두 초록으로 지나간 뒤 실제 쓰기 지점에서 `Permission denied`
     * 로 죽는다. 운영자에게는 "권한은 정상이라고 했는데 설치가 실패" 로만 보인다.
     *
     * 임시 파일을 만들었다 지워서 판정한다 — 두 OS 공통으로 성립하는 유일한 기준이다.
     * `is_writable()` 이 이미 false 면 probe 하지 않는다(그 방향은 오탐이 아니고,
     * 불필요한 파일 생성을 늘리지 않는다).
     *
     * @param  string  $path  디렉토리 절대 경로
     * @return bool 실제 쓰기 가능 여부
     */
    function installerDirectoryIsWritable(string $path): bool
    {
        if (! is_dir($path) || ! is_writable($path)) {
            return false;
        }

        $probe = rtrim($path, '/\\').DIRECTORY_SEPARATOR.'.installer-write-probe-'.uniqid('', true);

        $handle = @fopen($probe, 'wb');
        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, 'g7');
        @fclose($handle);
        @unlink($probe);

        return $written !== false;
    }
}

/**
 * 디렉토리 권한을 체크합니다.
 *
 * Step 0과 Step 2에서 재사용 가능한 공통 함수입니다.
 * 디렉토리를 생성하지 않고 존재 여부와 권한만 체크합니다.
 *
 * @param  array<string, bool>  $directories  체크할 디렉토리 경로 배열 (키: 경로, 값: 재귀 체크 여부)
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
        $fullPath = BASE_PATH.'/'.$path;

        // 디렉토리 존재 여부 확인 (생성하지 않음)
        $exists = is_dir($fullPath);

        // 실제 동작 기준 검증: 비트 값이 아닌 **실제 쓰기 probe** 로 판정.
        // 업계 표준에 따라 어떤 권한 조합이든 실제 동작하면 통과 (0755/0775/0770/0777 등).
        // `is_writable()` 만으로는 Windows 의 NTFS ACL 을 못 봐서 쓰기 불가 디렉토리가
        // 초록으로 통과한다 — "실제 동작 기준" 이라는 선언을 실물로 만든다.
        $writable = $exists && installerDirectoryIsWritable($fullPath);
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
        $passed = $parentPassed && ! $hasSubdirectoryIssues;

        // 에러 타입 구분
        // ownership_mismatch: 권한 비트는 0755 이상이지만 소유자가 웹서버 사용자와 달라
        //                     실제 쓰기 불가 (전통적 Apache: 파일 소유자 != www-data)
        $errorType = null;
        if (! $exists) {
            $errorType = 'not_exists';
        } elseif (! $writable) {
            // 권한 비트가 충분(0755+)한데도 쓰기 불가 → 소유권 불일치 가능성
            if ($permsOctal >= 0755 && $webServerUser && $owner && $owner !== $webServerUser) {
                $errorType = 'ownership_mismatch';
            } else {
                $errorType = 'not_writable';
            }
        } elseif (! $readable) {
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

        if (! $passed) {
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
 * 깊이 제한(maxDepth)을 둔다 (dev-g7#445 후속): storage 처럼 하위에 수만 개
 * 디렉토리가 누적되는 경로(예: storage/framework/testing/)를 무한 재귀로 순회하면
 * 인스톨러 2단계(요구사항 검증) 응답이 수 초 이상 지연된다. 쓰기 권한 문제는 트리
 * 상위 얕은 깊이에서 결정되므로(상위가 쓰기 가능하면 하위도 통상 상속), 제한 깊이까지만
 * 검사해 성능과 안내 유효성을 모두 확보한다.
 *
 * @param  string  $path  체크할 디렉토리 경로
 * @param  int  $maxDepth  재귀 최대 깊이 (0 = 현재 디렉토리의 직속 하위만). 기본 1
 * @param  int  $currentDepth  내부 재귀용 현재 깊이 (호출자는 지정하지 않음)
 * @return array<array{path: string, permissions: string}> 권한이 없는 하위 디렉토리 정보 배열
 */
function checkSubdirectoriesRecursive(string $path, int $maxDepth = 1, int $currentDepth = 0): array
{
    $failedPaths = [];

    if (! is_dir($path)) {
        return $failedPaths;
    }

    // 하위 디렉토리 목록 — `scandir` 이 차단된 호스팅에서는 하위 점검을 건너뛴다.
    //
    // 아래 `catch (Exception)` 은 이 경우를 잡지 못한다. PHP 8 은 `disable_functions` 로 막힌
    // 함수를 함수 테이블에서 **제거**하므로 호출은 `Error` 이고, `@` 는 진단 억제일 뿐이다.
    // 가드가 없으면 요구사항 화면이 통째로 뜨지 않는다 (13차 환경#1 과 같은 유형).
    //
    // 여기서 `ExecutionEnvironment` 대신 `function_exists` 를 쓰는 이유: 이 파일은 인스톨러
    // 최하위 유틸이라 `execution-mode.php` 를 끌어오면 순환 의존이 된다. PHP 8 에서는
    // 차단 함수가 테이블에서 사라지므로 이 판정만으로도 결과가 같다.
    if (! function_exists('scandir')) {
        return $failedPaths;
    }

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

        $itemPath = $path.DIRECTORY_SEPARATOR.$item;

        // 디렉토리인지 확인 (심볼릭 링크 제외)
        if (! is_dir($itemPath) || is_link($itemPath)) {
            continue;
        }

        // 절대 경로를 상대 경로로 변환
        $relativePath = str_replace(BASE_PATH.'/', '', $itemPath);
        $relativePath = str_replace('\\', '/', $relativePath);

        // 쓰기 권한 체크 — 상위 디렉토리와 같은 기준(실제 쓰기 probe)을 쓴다.
        // 한쪽만 stat 판정이면 상위는 걸러내는 것을 하위가 통과시켜 결과가 어긋난다.
        $isWritable = installerDirectoryIsWritable($itemPath);

        if (! $isWritable) {
            // 권한 코드 가져오기
            $perms = fileperms($itemPath);
            $permissions = substr(sprintf('%o', $perms), -3);

            $failedPaths[] = [
                'path' => $relativePath,
                'permissions' => $permissions,
            ];
        }

        // 재귀적으로 하위 디렉토리 체크 (권한 없어도 계속 진행)
        // 깊이 제한 도달 시 더 내려가지 않는다 — storage/framework/testing 등에 누적된
        // 수만 개 하위 디렉토리를 순회해 인스톨러 2단계가 지연되던 문제 차단 (dev-g7#445 후속).
        if ($currentDepth < $maxDepth) {
            try {
                $subFailed = checkSubdirectoriesRecursive($itemPath, $maxDepth, $currentDepth + 1);
                $failedPaths = array_merge($failedPaths, $subFailed);
            } catch (Exception $e) {
                // 하위 디렉토리 체크 실패해도 계속 진행
            }
        }
    }

    return $failedPaths;
}

/**
 * 라이선스 파일 내용을 로드합니다.
 *
 * 루트 LICENSE 파일을 읽습니다. (한국어 번역 + 영문 원문 통합)
 *
 * @param  string  $lang  언어 코드 (미사용, 하위 호환성 유지)
 * @return string 라이선스 파일 내용
 */
function loadLicenseFile(string $lang): string
{
    $licenseFile = __DIR__.'/../../../LICENSE';

    if (file_exists($licenseFile)) {
        return file_get_contents($licenseFile);
    }

    return lang('license_not_found');
}

if (! function_exists('escapeEnvValue')) {
    /**
     * .env 값 이스케이프
     *
     * 특수 문자가 포함된 값을 .env 파일에 안전하게 기록할 수 있도록 큰따옴표로 감싸고
     * 내부 큰따옴표와 백슬래시를 이스케이프합니다.
     *
     * installer-runtime.php 가 동일 함수를 polyfill 로 선언하므로 function_exists
     * 가드가 필수다. 가드가 없으면 installer-runtime.php 가 functions.php 보다 먼저
     * 로드되는 엔드포인트(install-process.php)에서 "Cannot redeclare" fatal 이 발생해
     * JSON 응답이 통째로 유실된다 (설치 시작 즉시 실패).
     *
     * @param  string  $value  이스케이프할 값
     * @return string 큰따옴표로 감싸고 내부 큰따옴표와 백슬래시를 이스케이프한 값
     */
    function escapeEnvValue(string $value): string
    {
        // 직렬화 정책은 serializeEnvValue 단일 관문이 소유한다 (KVE-2026-2042).
        // 이 이름은 하위호환 별칭일 뿐이며, 개행을 조용히 지우던 종전 동작은 남기지 않는다 —
        // 남겨 두면 다음 호출자가 그 경로로 같은 결함을 다시 들여온다.
        return serializeEnvValue($value);
    }
}

if (! function_exists('escapeEnvLineValue')) {
    /**
     * .env 라인에 보간할 값을 안전한 형태로 만듭니다.
     *
     * `escapeEnvValue()` 와 달리 따옴표가 필요 없는 값(호스트·포트·DB 이름·접두사 등
     * 영숫자와 `_.-/:@` 로만 이루어진 값)은 원문 그대로 돌려주어 `.env` 표기를 바꾸지
     * 않는다. 그 외에는 전부 `escapeEnvValue()` 로 넘긴다 — 개행이 제거되고 따옴표로
     * 닫히므로 라인 주입(`host\nAPP_CONFIG_CACHE=/tmp/x.php`)이 성립하지 않는다.
     *
     * `.env` 로 나가는 **모든** 사용자 입력이 이 함수 또는 `escapeEnvValue()` 를 거쳐야
     * 한다. 한 키라도 원문 보간으로 남으면 그 키가 곧 주입 통로다.
     *
     * @param  string  $value  원본 값
     * @return string .env 라인에 그대로 이어 붙일 수 있는 값
     */
    function escapeEnvLineValue(string $value): string
    {
        // 빈 값은 빈 라인(`KEY=`)으로 — 따옴표를 씌우면 기존 `.env` 표기가 바뀐다.
        if ($value === '') {
            return '';
        }

        if (preg_match('#^[A-Za-z0-9_.\-/:@]+$#', $value) === 1) {
            return $value;
        }

        return escapeEnvValue($value);
    }
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
    $envExamplePath = BASE_PATH.'/.env.example';
    if (! file_exists($envExamplePath)) {
        return null;
    }

    $envContent = file_get_contents($envExamplePath);
    $state = getInstallationState();
    $config = $state['config'] ?? [];

    // replaceEnvLine 의 출처는 installer-runtime.php 다 — 유일한 프로덕션 호출처
    // (finalize-env.php)는 그것을 먼저 로드하지만, 그 로드 순서에 전이 의존하면 새 호출처가
    // 생기는 순간 fatal 이 된다 (21차 6c). 함수 존재로 판정하는 것은 내부 함수 가용성이라
    // 차단 함수 축(execution-function-availability-via-ssot)의 대상이 아니다.
    if (! function_exists('replaceEnvLine')) {
        require_once __DIR__.'/installer-runtime.php';
    }

    // 치환은 **키 기준**이다 (20차 #11) — 종전에는 `.env.example` 의 기본값까지 포함한
    // 리터럴(`'APP_URL=http://localhost'`)을 str_replace 검색어로 써서, 템플릿 기본값이
    // 한 글자만 표류해도 치환이 오류 없이 건너뛰어 운영자 입력이 소실된 채 설치가
    // "성공" 했다. 키 기준 매칭(replaceEnvLine — `^KEY=.*$` + 부재 시 라인 추가)은
    // 앞 항목이 삽입한 **값** 안의 문자열이 뒤 항목의 검색어에 걸리는 오염 경로도 닫는다.
    // 값의 escape 규칙(escapeEnvValue/escapeEnvLineValue 선택)은 종전 그대로다.
    $replacements = [
        'DB_CONNECTION' => 'mysql',
        'DB_WRITE_HOST' => escapeEnvLineValue((string) ($config['db_write_host'] ?? '127.0.0.1')),
        'DB_WRITE_PORT' => escapeEnvLineValue((string) ($config['db_write_port'] ?? '3306')),
        'DB_WRITE_DATABASE' => escapeEnvLineValue((string) ($config['db_write_database'] ?? 'g7')),
        'DB_WRITE_USERNAME' => escapeEnvLineValue((string) ($config['db_write_username'] ?? 'root')),
        'DB_WRITE_PASSWORD' => escapeEnvValue((string) ($config['db_write_password'] ?? '')),
        'DB_PREFIX' => escapeEnvLineValue((string) ($config['db_prefix'] ?? 'g7_')),
    ];

    // Read DB 설정
    if (! empty($config['use_read_db']) && $config['use_read_db']) {
        $replacements['DB_READ_HOST'] = escapeEnvLineValue((string) ($config['db_read_host'] ?? '127.0.0.1'));
        $replacements['DB_READ_PORT'] = escapeEnvLineValue((string) ($config['db_read_port'] ?? '3306'));
        $replacements['DB_READ_DATABASE'] = escapeEnvLineValue((string) ($config['db_read_database'] ?? 'g7'));
        $replacements['DB_READ_USERNAME'] = escapeEnvLineValue((string) ($config['db_read_username'] ?? 'root'));
        $replacements['DB_READ_PASSWORD'] = escapeEnvValue((string) ($config['db_read_password'] ?? ''));
    } else {
        // Read DB를 사용하지 않는 경우 DB_READ_* 를 빈 값으로 둔다.
        // config/database.php 의 write fallback(Elvis) 이 SELECT 를 write DB 로 넘기므로
        // .env 에 write 값을 중복 기록하지 않는다 (write 변경 시 read stale 방지). (이슈 #63)
        $replacements['DB_READ_HOST'] = '';
        $replacements['DB_READ_PORT'] = '';
        $replacements['DB_READ_DATABASE'] = '';
        $replacements['DB_READ_USERNAME'] = '';
        $replacements['DB_READ_PASSWORD'] = '';
    }

    // 앱 설정 치환
    // APP_NAME 은 수동으로 따옴표를 감싸지 않는다 — 값 안의 `"` 가 따옴표를 조기에 닫아
    // 뒤따르는 문자열이 새 라인으로 해석되던 통로였다. 따옴표 판단·escape 를 헬퍼에 위임한다.
    $replacements['APP_NAME'] = escapeEnvLineValue((string) ($config['app_name'] ?? '그누보드7'));
    $replacements['APP_ENV'] = escapeEnvLineValue((string) ($config['app_env'] ?? 'production'));
    $replacements['APP_URL'] = escapeEnvLineValue((string) ($config['app_url'] ?? 'http://localhost'));

    // 언어 설정
    $currentLang = getCurrentLanguage();
    // 세션에서 온 값이라 형태가 보장되지 않는다 — 같은 헬퍼로 닫는다.
    $replacements['APP_LOCALE'] = escapeEnvLineValue($currentLang);
    $replacements['APP_FAKER_LOCALE'] = $currentLang === 'ko' ? 'ko_KR' : 'en_US';

    if (isset($config['app_env']) && $config['app_env'] !== 'production') {
        $replacements['APP_DEBUG'] = 'true';
    }

    // 코어 업데이트 설정 치환
    $corePendingPath = $config['core_update_pending_path'] ?? '';
    $coreGithubUrl = $config['core_update_github_url'] ?? 'https://github.com/gnuboard/g7';
    // 토큰은 state.config 에 기록되지 않는다 (비밀 5종) — runtime.php(0600) 가 보관처다.
    $coreGithubToken = (string) ($config['core_update_github_token']
        ?? (readInstallerRuntime()['core_update']['github_token'] ?? ''));

    $replacements['G7_UPDATE_PENDING_PATH'] = escapeEnvValue($corePendingPath);
    $replacements['G7_UPDATE_GITHUB_URL'] = escapeEnvLineValue((string) $coreGithubUrl);
    $replacements['G7_UPDATE_GITHUB_TOKEN'] = escapeEnvValue($coreGithubToken);

    // PHP CLI / Composer 바이너리 경로 치환
    //
    // 운영자가 비워 두었더라도 설치 시작 시 자동 탐색이 찾은 경로가 있으면 그것을 남긴다 (D35).
    // 그러지 않으면 인스톨러는 `/usr/local/php83/bin/php` 로 설치를 끝내 놓고 설치본의 `.env`
    // 에는 `PHP_BINARY=php` 를 남긴다 — 운영자가 나중에 실행 모드를 subprocess 로 **고정**하면
    // 해석기가 감지를 건너뛰고 설정값(`php`)을 그대로 스폰해, 판정과 실행이 다시 어긋난다.
    // 기록도 실행(getPhpBinary)과 **같은 형태 판정**을 지난다 (22차 #22-5) — 실행은 형태
    // 위반 후보를 건너뛰고 폴백으로 내려가는데 기록이 원문을 그대로 박으면, 설치는 A 로
    // 끝나고 설치본 `.env` 는 B 를 쓰라고 기록된다(D35 가 막으려던 어긋남의 역방향).
    // 도달 경로는 Step 3 저장 게이트를 지나지 않은 config(레거시 상태 파일·수동 편집)다.
    if (! function_exists('installer_binary_path_shape_ok')) {
        require_once __DIR__.'/binary-path-policy.php';
    }

    $phpBinary = trim((string) ($config['php_binary'] ?? ''));
    if ($phpBinary !== '' && $phpBinary !== 'php' && ! installer_binary_path_shape_ok($phpBinary)) {
        $phpBinary = '';
    }
    if ($phpBinary === '' || $phpBinary === 'php') {
        $resolvedPhpBinary = trim((string) ($state['php_binary_resolved'] ?? ''));
        $phpBinary = ($resolvedPhpBinary !== '' && installer_binary_path_shape_ok($resolvedPhpBinary))
            ? $resolvedPhpBinary
            : 'php';
    }
    // composer 도 같은 형태 게이트 (23차 — php 축과의 비대칭 해소). 위반 원문은 기록하지
    // 않고 빈 값으로 남긴다(빈 값은 「미설정」의 정상 표기).
    $composerBinary = trim((string) ($config['composer_binary'] ?? ''));
    if ($composerBinary !== '' && ! installer_binary_path_shape_ok($composerBinary)) {
        $composerBinary = '';
    }
    $replacements['PHP_BINARY'] = escapeEnvValue($phpBinary);
    $replacements['COMPOSER_BINARY'] = escapeEnvValue($composerBinary);

    foreach ($replacements as $key => $value) {
        $envContent = replaceEnvLine($envContent, $key, $value);
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
 * @param  string  $basePath  프로젝트 루트 경로 (빈 문자열이면 상대 경로 사용)
 * @return string 복사 명령어
 */
function getEnvCopyCommand(string $basePath = ''): string
{
    if (isWindows()) {
        $source = $basePath ? $basePath.'\\.env.example' : '.env.example';
        $dest = $basePath ? $basePath.'\\.env' : '.env';

        return 'copy '.str_replace('/', '\\', $source).' '.str_replace('/', '\\', $dest);
    }

    $source = $basePath ? $basePath.'/.env.example' : '.env.example';
    $dest = $basePath ? $basePath.'/.env' : '.env';

    return 'cp '.$source.' '.$dest;
}

/**
 * OS에 맞는 권한 수정 명령어를 반환합니다.
 *
 * 업계 표준(WordPress/Drupal/Joomla/Laravel)에 맞춰 디렉토리 755, 파일 644로 통일.
 * chown/setgid 의존성을 제거하여 공유호스팅 호환성 확보.
 * 실제 통과 기준은 is_writable() && is_readable()이므로 비트 값은 참고용.
 *
 * @param  string  $pathList  대상 경로 (공백 구분)
 * @param  string  $mode  'ownership' (디렉토리) 또는 'file' (파일)
 * @return string 권한 수정 명령어
 */
function getPermissionFixCommand(string $pathList, string $mode = 'ownership'): string
{
    return installerPermissionFixCommandFor($pathList, $mode, isWindows());
}

/**
 * 권한 수정 명령어 판정 본체 — OS 를 **인자로** 받는다 (11차 #D16).
 *
 * `isWindows()` 를 함수 안에서 직접 부르면 그 프로세스의 OS 분기 하나만 평가된다 —
 * Windows 에서 돌리면 `chmod` 분기가, Linux 에서 돌리면 `icacls` 분기가 한 번도 실행되지
 * 않는다(그래서 「skip 0」이라는 수치도 측정 OS 한정이었다). 두 분기를 같은 프로세스에서
 * 태울 수 있게 판정을 분리한다.
 *
 * @param  string  $pathList  대상 경로(들)
 * @param  string  $mode  'ownership' (디렉토리) 또는 'file' (파일)
 * @param  bool  $isWindows  Windows 환경인가
 * @return string 권한 수정 명령어
 */
function installerPermissionFixCommandFor(string $pathList, string $mode, bool $isWindows): string
{
    if ($isWindows) {
        $winPath = str_replace('/', '\\', $pathList);
        if ($mode === 'file') {
            return 'icacls '.$winPath.' /grant Everyone:F';
        }

        return 'icacls '.$winPath.' /grant Everyone:(OI)(CI)F /T';
    }

    if ($mode === 'file') {
        return 'chmod 644 '.$pathList;
    }

    return 'chmod -R 755 '.$pathList;
}

/**
 * 기존 DB 테이블 감지.
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
 * @param  PDO  $pdo  연결된 PDO 인스턴스
 * @param  string  $database  대상 데이터베이스명
 */
function checkExistingTables(PDO $pdo, string $database, string $tablePrefix = ''): array
{
    try {
        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (PDOException $e) {
        // 「조회하지 못했다」와 「빈 DB」는 다른 사실이다 (19차 S-9).
        //
        // 종전에는 둘 다 `severity: 'empty'` + `has_tables: false` 로 나가서, 화면은 초록
        // 「연결 성공」만 그리고 백업 안내·동의 체크박스를 감췄다. 기존 테이블이 실재해도
        // `db_cleanup` 이 요구되지 않아, 설치 중반 마이그레이션 단계에서야 충돌로 멈춘다.
        //
        // `check_failed` 로 그 구분을 실어 보낸다. 원문은 싣지 않는다 — 이 응답은 화면으로
        // 나가고 PDO 메시지에는 접속 좌표가 섞인다 (13차 보안#9 와 같은 규칙). 로그로만 남긴다.
        if (function_exists('logInstallationError')) {
            logInstallationError('checkExistingTables failed', $e);
        }

        return [
            'has_tables' => false,
            'is_g7_install' => false,
            'g7_tables_found' => [],
            'other_tables_count' => 0,
            'all_tables' => [],
            'drop_targets' => [],
            'nested_g7_prefixes' => [],
            'severity' => 'empty',
            'check_failed' => true,
        ];
    }

    if (empty($tables)) {
        return [
            'has_tables' => false,
            'is_g7_install' => false,
            'g7_tables_found' => [],
            'other_tables_count' => 0,
            'all_tables' => [],
            'drop_targets' => [],
            'nested_g7_prefixes' => [],
            'severity' => 'empty',
        ];
    }

    // G7 핵심 시그니처 테이블 — 정책 결정: 4개 모두 일치 시 "G7 설치됨"으로 판단
    // 테이블 prefix를 적용하여 비교 (예: prefix='g7_'이면 'g7_users', 'g7_migrations' 등)
    $coreSignatures = ['users', 'migrations', 'roles', 'permissions'];
    $g7Signatures = array_map(fn ($t) => $tablePrefix.$t, $coreSignatures);
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
        // 동의 화면이 그리는 삭제 대상은 **삭제 계산과 동일 산출**이어야 한다 (21차 #21-2 ①,
        // PO 결정 §16.27.1 #2) — 다른 목록을 보여주면 동의가 거짓이 된다.
        'drop_targets' => filterTablesByPrefix($tables, $tablePrefix),
        // 접두 매칭은 더 긴 접두사 이웃(g7_ ⊂ g7_shop_)을 함께 문다 — 그 이웃이 G7 설치로
        // 감지되면 화면이 별도 경고를 띄운다 (21차 #21-2 ②).
        'nested_g7_prefixes' => installerDetectNestedG7Prefixes($tables, $tablePrefix),
        'severity' => $severity,
    ];
}

if (! function_exists('installerDetectNestedG7Prefixes')) {
    /**
     * 입력 접두사보다 **긴** 접두사로 설치된 다른 G7 사이트를 감지합니다 (21차 #21-2 ②).
     *
     * 접두 매칭 삭제는 같은 DB 의 더 긴 접두사 사이트(`g7_` 입력 시 `g7_shop_*`)를 함께
     * 지운다. 그 사이트가 G7 설치인지의 판정은 checkExistingTables 와 같은 시그니처
     * 규칙(4종 전부 실재)을 쓴다 — 4종 미만이면 감지하지 않아 오경보를 피한다.
     *
     * @param  array<int, mixed>  $tables  SHOW TABLES 전체 목록
     * @param  string  $prefix  운영자가 입력한 접두사
     * @return array<int, string> 감지된 더 긴 접두사 목록 (예: ['g7_shop_'])
     */
    function installerDetectNestedG7Prefixes(array $tables, string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        $coreSignatures = ['users', 'migrations', 'roles', 'permissions'];
        $prefixLength = strlen($prefix);
        $candidates = [];

        foreach ($tables as $table) {
            $table = (string) $table;

            if (strncmp($table, $prefix, $prefixLength) !== 0) {
                continue;
            }

            foreach ($coreSignatures as $signature) {
                if (! str_ends_with($table, $signature)) {
                    continue;
                }

                $candidate = substr($table, 0, strlen($table) - strlen($signature));

                if ($candidate !== $prefix && strlen($candidate) > $prefixLength) {
                    $candidates[$candidate][$signature] = true;
                }
            }
        }

        $nested = [];

        foreach ($candidates as $candidate => $signatures) {
            if (count($signatures) >= 4) {
                $nested[] = (string) $candidate;
            }
        }

        sort($nested);

        return $nested;
    }
}

if (! function_exists('filterTablesByPrefix')) {
    /**
     * 기존 테이블 목록을 db_prefix 로 필터링합니다.
     *
     * drop_tables 동의의 의미는 "이 prefix 로 설치되는 G7 테이블을 지우고 다시 설치"이므로,
     * 입력한 prefix 로 시작하는 테이블만 삭제 대상으로 선별합니다. prefix 가 없는
     * 다른 애플리케이션/이전 설치의 테이블은 동일 DB 를 공유하더라도 보존합니다.
     *
     * 빈 prefix 는 전체 일치가 되어 모든 테이블이 삭제되는 위험이 있으므로,
     * 데이터 손실 방어를 위해 빈 목록(삭제 대상 없음)을 반환합니다.
     *
     * @param  array  $tables  SHOW TABLES 로 조회한 전체 테이블 목록
     * @param  string  $prefix  사용자가 입력한 테이블 prefix (예: 'g7_')
     * @return array prefix 로 시작하는 테이블만 담은 목록
     */
    function filterTablesByPrefix(array $tables, string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        $prefixLength = strlen($prefix);

        return array_values(array_filter(
            $tables,
            fn ($table) => strncmp((string) $table, $prefix, $prefixLength) === 0
        ));
    }
}

if (! function_exists('installerDiscardOutputBuffers')) {
    /**
     * 열려 있는 출력 버퍼를 전부 닫는다 — 진행 보장 포함 (17차 #B7).
     *
     * 종전 형태 `while (ob_get_level() > 0) { ob_end_clean(); }` 는 탈출 조건이 **레벨 감소**에만
     * 달려 있다. 삭제할 수 없는 버퍼(SAPI·확장이 연 버퍼)가 하나라도 있으면 `ob_end_clean()` 이
     * `false` 를 돌려주고 레벨은 그대로라, 그 루프는 `max_execution_time` 까지 회전한다.
     * `display_errors=0` 인 프로덕션에서는 경고도 보이지 않아 요청이 조용히 멈춘 것처럼 보인다.
     *
     * 레벨이 줄지 않으면 즉시 포기한다 — 남은 버퍼는 호출부가 감수한다(비우지 못하는 것과
     * 무한 회전 중에는 전자가 낫다).
     *
     * @param  bool  $flush  true 면 내용을 내보내며 닫는다(`ob_end_flush`), false 면 버린다
     * @return bool 모든 버퍼를 닫았으면 true
     */
    function installerDiscardOutputBuffers(bool $flush = false): bool
    {
        while (($level = ob_get_level()) > 0) {
            $closed = $flush ? @ob_end_flush() : @ob_end_clean();

            if ($closed === false || ob_get_level() >= $level) {
                // 닫히지 않는 버퍼다 — 더 돌아도 같은 결과이므로 회전하지 않는다.
                return false;
            }
        }

        return true;
    }
}

if (! function_exists('installerErrorLog')) {
    /**
     * PHP 오류 로그에 한 줄 남긴다 — `error_log` 가 막힌 호스팅에서도 성립하는 단일 지점.
     *
     * `error_log` 는 3인자 형태로 임의 파일에 쓸 수 있어 하드닝 프로필의 표준 차단 목록에
     * 올라 있다. PHP 8 은 차단 함수를 함수 테이블에서 **제거**하므로 그 호출은 `Error`(치명)
     * 이고 `@` 는 가드가 아니다 — 그리고 인스톨러에서 이 함수는 **최후 폴백 채널**이다
     * (`installer-state.php` 의 진행 기록 저장이 실패했을 때 사유가 남는 유일한 통로이자,
     * `session.php` 세션 부팅의 최상위 코드). 무가드로 부르면 그 최후 수단이 첫 요청에서
     * 화면을 백지로 만든다.
     *
     * 여기서 `ExecutionEnvironment` 대신 `function_exists` 를 쓰는 이유는 같은 파일의
     * `checkDirectoryPermissions` 와 동일하다 — 이 파일은 인스톨러 최하위 유틸이고
     * `execution-mode.php` 를 끌어오면 순환 의존이 된다. PHP 8 에서는 차단 함수가 테이블에서
     * 사라지므로 이 판정만으로 결과가 같다.
     *
     * @param  string  $message  기록할 메시지
     */
    function installerErrorLog(string $message): void
    {
        // audit:allow execution-function-availability-via-ssot 최하위 유틸 — SSoT 로드는 순환 의존
        if (! function_exists('error_log')) {
            return;
        }

        error_log($message);
    }
}
