<?php

namespace Tests\Unit\Installer;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * DB 권한 사전 검사의 스코프·토큰·롤 판정 회귀 가드 (20차 #4·#5·#6, 제품 결정 §16.26.4 #2)
 *
 * 세 결함을 잠근다:
 *
 * - **#4 스코프 부재**: `checkDatabasePrivileges()` 가 `$database` 인자를 본문에서 쓰지 않아,
 *   한 계정에 DB 가 둘 붙은 공유 호스팅에서 **다른 DB** 의 `ALL PRIVILEGES` 로 검사가
 *   통과했다. 설치는 `db_migrate` 에서 1142 로 죽고, 운영자는 "권한은 정상" 화면을 이미
 *   지나온 뒤다.
 * - **#5 부분문자열 매칭**: `strpos` 판정이라 `CREATE TEMPORARY TABLES` 가 `CREATE` 로,
 *   `ALTER ROUTINE` 이 `ALTER` 로 집계되어 `missing` 안내가 틀렸다.
 * - **#6 롤 미전개 → 거짓 차단**: `SHOW GRANTS FOR CURRENT_USER()` 는 MySQL 8 롤을
 *   전개하지 않는다. 롤로만 권한을 받은 계정은 전부 `missing` 으로 떨어져 설치가
 *   시작조차 되지 않았다. 제품 결정(§16.26.4 #2): 롤 전개를 시도하고, **끝내 확인 불가면
 *   `indeterminate` 로 강등해 게이트는 경고만 하고 진행을 허용**한다. 실제 부족은 설치 중
 *   해당 단계가 사유와 함께 잡는다.
 *
 * 판정 계약: `missing` 이 비지 않은 채 반환되는 것은 **해석이 확정된 부족**뿐이다.
 * 확인 불가(SHOW GRANTS 실패 · 롤 전개 실패 · 해석 불가 부여 잔존)는 `indeterminate=true`
 * + `missing=[]` 로 돌려, 게이트 구조가 어긋나도 차단으로 새지 않게 한다(fail-open 은
 * 제품에서 명시적으로 결정한 방향이다 — 임의 완화가 아니다).
 *
 * @effects installer_db_privilege_check_scopes_to_target_database
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerDbPrivilegeScopeTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-db-priv-scope-'.bin2hex(random_bytes(4)));
            @mkdir(BASE_PATH.'/storage', 0755, true);
        }

        $projectRoot = dirname(__DIR__, 3);
        require_once __DIR__.'/stubs/lang_stub.php';
        require_once $projectRoot.'/public/install/includes/config.php';
        require_once $projectRoot.'/public/install/includes/installer-state.php';
        require_once $projectRoot.'/public/install/includes/functions.php';
    }

    /**
     * 라우팅형 PDO 스텁 — SHOW GRANTS 직접 조회와 USING 전개 조회를 구분해 응답한다.
     *
     * @param  array<int, string>|PDOException  $direct  직접 조회 응답 (또는 던질 예외)
     * @param  array<int, string>|PDOException|null  $using  USING 전개 응답 (null = 호출되면 실패)
     * @param  array<int, string>  $issuedQueries  실행된 쿼리 기록 (참조)
     */
    private function pdoStub(array|PDOException $direct, array|PDOException|null $using, array &$issuedQueries): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $test = $this;
        $pdo->method('query')->willReturnCallback(
            function (string $query) use ($direct, $using, &$issuedQueries, $test): PDOStatement {
                $issuedQueries[] = $query;
                $response = str_contains($query, 'USING') ? $using : $direct;
                if ($response === null) {
                    $test->fail('예상하지 못한 USING 전개 조회: '.$query);
                }
                if ($response instanceof PDOException) {
                    throw $response;
                }
                $stmt = $test->createStub(PDOStatement::class);
                $stmt->method('fetchAll')->willReturn($response);

                return $stmt;
            }
        );

        return $pdo;
    }

    /**
     * #4: 다른 DB 에만 부여된 ALL PRIVILEGES 는 대상 DB 검사를 통과시키지 못한다.
     */
    public function test_grants_on_other_database_do_not_count(): void
    {
        $queries = [];
        $pdo = $this->pdoStub([
            "GRANT USAGE ON *.* TO 'u'@'localhost'",
            "GRANT ALL PRIVILEGES ON `other_db`.* TO 'u'@'localhost'",
        ], null, $queries);

        $result = checkDatabasePrivileges($pdo, 'target_db');

        $this->assertFalse($result['has_all'], '다른 DB 의 ALL PRIVILEGES 로 통과하면 안 된다 (#4)');
        $this->assertNotEmpty($result['missing']);
        $this->assertFalse((bool) ($result['indeterminate'] ?? false), '해석이 확정된 부족은 indeterminate 가 아니다');
    }

    /**
     * 전역 부여(*.*)와 대상 DB 부여는 통과한다. 백틱 안 이스케이프 와일드카드(\_)는 리터럴이다.
     */
    public function test_global_and_matching_database_grants_count(): void
    {
        $queries = [];
        $pdo = $this->pdoStub(["GRANT ALL PRIVILEGES ON *.* TO 'u'@'%'"], null, $queries);
        $this->assertTrue(checkDatabasePrivileges($pdo, 'target_db')['has_all']);

        $queries = [];
        $pdo = $this->pdoStub([
            "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX ON `target\\_db`.* TO 'u'@'%'",
        ], null, $queries);
        $this->assertTrue(checkDatabasePrivileges($pdo, 'target_db')['has_all']);
    }

    /**
     * 부여 대상의 미이스케이프 와일드카드(% · _)는 MySQL 규칙대로 패턴 매칭한다.
     */
    public function test_wildcard_database_pattern_matches_like_mysql(): void
    {
        $queries = [];
        $pdo = $this->pdoStub(["GRANT ALL PRIVILEGES ON `myapp\\_%`.* TO 'u'@'%'"], null, $queries);
        $this->assertTrue(checkDatabasePrivileges($pdo, 'myapp_prod')['has_all'], '`myapp\\_%` 는 myapp_prod 를 덮는다');

        $queries = [];
        $pdo = $this->pdoStub(["GRANT ALL PRIVILEGES ON `myapp\\_%`.* TO 'u'@'%'"], null, $queries);
        $this->assertFalse(checkDatabasePrivileges($pdo, 'other_db')['has_all'], '`myapp\\_%` 는 other_db 를 덮지 않는다');
    }

    /**
     * #5: 권한명은 토큰 단위로 대조한다 — CREATE TEMPORARY TABLES 는 CREATE 가 아니다.
     */
    public function test_privilege_names_match_whole_tokens(): void
    {
        $queries = [];
        $pdo = $this->pdoStub([
            "GRANT SELECT, CREATE TEMPORARY TABLES, LOCK TABLES, ALTER ROUTINE ON `target_db`.* TO 'u'@'%'",
        ], null, $queries);

        $result = checkDatabasePrivileges($pdo, 'target_db');

        $this->assertContains('SELECT', $result['found']);
        $this->assertNotContains('CREATE', $result['found'], 'CREATE TEMPORARY TABLES 가 CREATE 로 집계되면 안 된다 (#5)');
        $this->assertNotContains('ALTER', $result['found'], 'ALTER ROUTINE 이 ALTER 로 집계되면 안 된다 (#5)');
        $this->assertContains('CREATE', $result['missing']);
        $this->assertContains('ALTER', $result['missing']);
    }

    /**
     * #6: 롤로만 권한을 받은 계정은 USING 전개 재조회로 확인한다.
     */
    public function test_role_grants_are_expanded_via_using(): void
    {
        $queries = [];
        $pdo = $this->pdoStub(
            [
                "GRANT USAGE ON *.* TO 'u'@'%'",
                'GRANT `app_rw`@`%` TO `u`@`%`',
            ],
            [
                "GRANT USAGE ON *.* TO 'u'@'%'",
                'GRANT ALL PRIVILEGES ON `target_db`.* TO `u`@`%`',
            ],
            $queries
        );

        $result = checkDatabasePrivileges($pdo, 'target_db');

        $this->assertTrue($result['has_all'], '롤 전개로 확인된 권한은 통과한다 (#6)');
        $usingQueries = array_values(array_filter($queries, fn (string $q): bool => str_contains($q, 'USING')));
        $this->assertCount(1, $usingQueries, 'USING 전개 재조회가 정확히 한 번 나가야 한다');
        $this->assertStringContainsString('`app_rw`@`%`', $usingQueries[0], '롤 토큰은 서버 출력 원문 그대로 전달한다');
    }

    /**
     * #6/제품 결정: 롤 전개가 실패하면 차단이 아니라 「확인 불가」로 강등한다.
     */
    public function test_unexpandable_roles_degrade_to_indeterminate(): void
    {
        $queries = [];
        $pdo = $this->pdoStub(
            [
                "GRANT USAGE ON *.* TO 'u'@'%'",
                'GRANT `app_rw`@`%` TO `u`@`%`',
            ],
            new PDOException('USING not supported'),
            $queries
        );

        $result = checkDatabasePrivileges($pdo, 'target_db');

        $this->assertFalse($result['has_all']);
        $this->assertTrue((bool) ($result['indeterminate'] ?? false), '전개 불가는 indeterminate 다 (제품 결정 — 경고 후 진행)');
        $this->assertSame([], $result['missing'], '확인 불가 상태에서 missing 을 채우면 게이트가 차단해 버린다');
    }

    /**
     * #6/제품 결정: SHOW GRANTS 자체가 거부되는 환경도 차단이 아니라 「확인 불가」다.
     */
    public function test_show_grants_failure_is_indeterminate_not_blocking(): void
    {
        $queries = [];
        $pdo = $this->pdoStub(new PDOException('SHOW GRANTS denied'), null, $queries);

        $result = checkDatabasePrivileges($pdo, 'target_db');

        $this->assertFalse($result['has_all']);
        $this->assertTrue((bool) ($result['indeterminate'] ?? false));
        $this->assertSame([], $result['missing'], 'SHOW GRANTS 거부가 설치 차단으로 새면 안 된다 (#6)');
    }

    /**
     * 21차 N1: 테이블 스코프 부여(`db`.`tbl`)는 DB 전체 검사를 통과시키지 못한다 —
     * 객체 부분이 `*` 인 부여만 DB 를 덮는다.
     */
    public function test_table_scoped_grant_does_not_cover_database(): void
    {
        $queries = [];
        $pdo = $this->pdoStub([
            "GRANT USAGE ON *.* TO 'u'@'%'",
            'GRANT ALL PRIVILEGES ON `target_db`.`one_table` TO `u`@`%`',
        ], null, $queries);

        $result = checkDatabasePrivileges($pdo, 'target_db');

        $this->assertFalse($result['has_all'], '테이블 1개 부여가 DB 전체 권한으로 통과하면 안 된다 (21차 N1)');
    }

    /**
     * 21차 N2: partial_revokes(MySQL 8.0.16+) — 회수된 권한은 found 에서 빠지고
     * missing 에 정확히 반영된다 (REVOKE 행을 해석 불가로 뭉개면 회수분이 초록 통과).
     */
    public function test_partial_revoke_subtracts_privileges(): void
    {
        $queries = [];
        $pdo = $this->pdoStub([
            "GRANT ALL PRIVILEGES ON *.* TO 'u'@'%'",
            "REVOKE INSERT, UPDATE ON `target_db`.* FROM 'u'@'%'",
        ], null, $queries);

        $result = checkDatabasePrivileges($pdo, 'target_db');

        $this->assertFalse($result['has_all'], '회수된 권한이 초록으로 통과하면 안 된다 (21차 N2)');
        $this->assertContains('INSERT', $result['missing']);
        $this->assertContains('UPDATE', $result['missing']);
        $this->assertNotContains('SELECT', $result['missing'], '회수되지 않은 권한까지 부족으로 접으면 안 된다');
    }

    /**
     * 21차 N2 파생: 해석하지 못한 행이 남으면 missing 이 비어도 「확인」을 단정하지 않는다 —
     * 그 행이 REVOKE 변형일 수 있다. 확인 불가는 경고 후 진행(제품 결정)이므로 안전 방향이다.
     */
    public function test_unparseable_line_degrades_even_when_nothing_missing(): void
    {
        $queries = [];
        $pdo = $this->pdoStub([
            "GRANT ALL PRIVILEGES ON *.* TO 'u'@'%'",
            'SOMETHING THE PARSER DOES NOT KNOW',
        ], null, $queries);

        $result = checkDatabasePrivileges($pdo, 'target_db');

        $this->assertTrue((bool) ($result['indeterminate'] ?? false), '해석 불가 행이 남았는데 확인을 단정하면 안 된다 (21차 N2 파생)');
        $this->assertSame([], $result['missing']);
    }

    /**
     * 21차 N3: MariaDB 는 롤을 인용 없이 출력한다 — 무인용 롤 토큰도 전개 대상이다.
     */
    public function test_mariadb_unquoted_role_grants_are_expanded(): void
    {
        $queries = [];
        $pdo = $this->pdoStub(
            [
                "GRANT USAGE ON *.* TO 'u'@'%'",
                "GRANT app_rw TO 'u'@'%'",
            ],
            [
                "GRANT USAGE ON *.* TO 'u'@'%'",
                "GRANT ALL PRIVILEGES ON `target_db`.* TO 'u'@'%'",
            ],
            $queries
        );

        $result = checkDatabasePrivileges($pdo, 'target_db');

        $this->assertTrue($result['has_all'], 'MariaDB 무인용 롤이 전개되지 않았다 (21차 N3)');
        $usingQueries = array_values(array_filter($queries, fn (string $q): bool => str_contains($q, 'USING')));
        $this->assertCount(1, $usingQueries);
        $this->assertStringContainsString('app_rw', $usingQueries[0]);
    }

    /**
     * 21차 1c: 컬럼 권한(`SELECT (col)`)은 전체 권한이 아니다 — Read DB 도 통과 불가.
     */
    public function test_column_scoped_privilege_is_not_full_privilege(): void
    {
        $queries = [];
        $pdo = $this->pdoStub([
            "GRANT SELECT (`id`, `name`) ON `target_db`.* TO 'u'@'%'",
        ], null, $queries);

        $result = checkDatabasePrivileges($pdo, 'target_db', true);

        $this->assertFalse($result['has_all'], '2개 컬럼 SELECT 가 전체 SELECT 로 승격되면 안 된다 (21차 1c)');
    }

    /**
     * Read DB 는 SELECT 하나만 요구한다 (기존 계약 유지).
     */
    public function test_read_db_requires_only_select(): void
    {
        $queries = [];
        $pdo = $this->pdoStub(["GRANT SELECT ON `target_db`.* TO 'u'@'%'"], null, $queries);

        $result = checkDatabasePrivileges($pdo, 'target_db', true);

        $this->assertTrue($result['has_all']);
        $this->assertSame(['SELECT'], $result['required']);
    }

    /**
     * #7: DB 서버 버전 하한 미달 안내 — MySQL 8.0 / MariaDB 10.3 미만이면 경고, 이상·해석 불가면 null.
     *
     * 해석 불가를 경고로 접지 않는 이유: 포크·프록시가 임의 문자열을 돌려줄 수 있고,
     * 그때 뜨는 거짓 경고는 운영자가 진짜 경고를 무시하게 만든다.
     */
    public function test_db_server_version_notice_thresholds(): void
    {
        $this->assertNotNull(installerDbServerVersionNotice('5.7.44-log'), 'MySQL 5.7 은 하한 미달');
        $this->assertNull(installerDbServerVersionNotice('8.0.36'), 'MySQL 8.0 은 통과');
        $this->assertNull(installerDbServerVersionNotice('9.1.0'), 'MySQL 9 는 통과');
        $this->assertNotNull(installerDbServerVersionNotice('10.2.44-MariaDB'), 'MariaDB 10.2 는 하한 미달');
        $this->assertNull(installerDbServerVersionNotice('5.5.5-10.4.28-MariaDB-log'), '레플리케이션 접두 5.5.5- 를 걷어내면 10.4 — 통과');
        $this->assertNotNull(installerDbServerVersionNotice('5.5.68-MariaDB'), 'MariaDB 5.5 는 하한 미달');
        $this->assertNull(installerDbServerVersionNotice('weird-proxy-banner'), '해석 불가는 경고하지 않는다');
        $this->assertNull(installerDbServerVersionNotice(null));
    }

    /**
     * 서버 판정 ↔ 화면 배선 (14차 규약): 게이트가 만든 경고 2종이 응답에 실리고 화면이 그린다.
     *
     * 행위 재현이 API 스크립트 구조상 불가하므로 소스 계약으로 고정한다 — 서버는
     * `privilege_warning`·`db_server_notice` 를 생산하고, installer.js 성공 렌더가 둘을 소비한다.
     */
    public function test_privilege_and_version_warnings_reach_screen(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $api = $this->sourceWithoutComments($projectRoot.'/public/install/api/check-configuration.php');
        $js = $this->sourceWithoutComments($projectRoot.'/public/install/assets/js/installer.js');

        $this->assertStringContainsString("'privilege_warning'", $api, '게이트가 권한 경고를 응답에 실어야 한다');
        $this->assertStringContainsString("'db_server_notice'", $api, '게이트가 서버 버전 안내를 응답에 실어야 한다');
        $this->assertStringContainsString('indeterminate', $api, '게이트가 확인 불가를 차단과 구분해야 한다 (제품 결정)');
        $this->assertStringContainsString('result.privilege_warning', $js, '화면이 권한 경고를 그려야 한다');
        $this->assertStringContainsString('result.db_server_notice', $js, '화면이 서버 버전 안내를 그려야 한다');
    }
}
