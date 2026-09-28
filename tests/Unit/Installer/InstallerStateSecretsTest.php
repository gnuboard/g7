<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 state.json 비밀 잔존 결함 회귀 테스트 (이슈 #465)
 *
 * 결함: 설치 후 storage/installer-state.json 에 admin_password /
 * admin_password_confirm 이 평문으로 남았다.
 *
 * 수정 설계: 비밀은 state.json 에 아예 기록하지 않고 runtime.php(0600) 로
 * 일원화한다. 본 테스트는 그 계약을 고정한다.
 *
 * - sanitizeConfigForState()      — state 저장 직전 비밀 4종 제거
 * - redactInstallationStateSecrets() — 레거시 state 정리
 * - buildInstallerRuntimeFromState()  — state 결손 시 기존 runtime 값 보존
 * - hydrateDbSecretsFromRuntime()     — DB 비밀번호 소비처 폴백
 *
 * @see https://github.com/gnuboard/dev-g7/issues/465
 */
class InstallerStateSecretsTest extends TestCase
{
    private string $skipReason = '';

    /**
     * 임시 BASE_PATH 를 확인하고 state·runtime 헬퍼를 로드한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $tempBase = sys_get_temp_dir().'/g7-installer-secrets-'.bin2hex(random_bytes(4));

        // 안전 가드: BASE_PATH / INSTALLER_RUNTIME_PATH 는 PHP 상수라 프로세스당 1회만
        // 정의된다. 다른 Installer 테스트가 먼저 프로젝트 루트로 BASE_PATH 를 정의하면
        // 본 테스트의 writeInstallerRuntime/tearDown 이 실제 운영 runtime.php 를
        // (DB 자격증명 보관 파일) 파괴한다. temp 하위가 아니면 skip.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                $this->skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '
                    .'다른 Installer 테스트의 BASE_PATH 정의가 선행됨. 실제 storage/installer/runtime.php '
                    .'파괴 방지를 위해 skip. 격리 실행: php vendor/bin/phpunit --filter=InstallerStateSecretsTest';
                $this->markTestSkipped($this->skipReason);
            }
            $tempBase = (string) BASE_PATH;
        } else {
            define('BASE_PATH', $tempBase);
        }

        if (! is_dir($tempBase.'/storage/installer')) {
            @mkdir($tempBase.'/storage/installer', 0755, true);
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/installer-state.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/installer-runtime.php';
    }

    /**
     * 이번 테스트가 만든 runtime.php 만 지운다 (skip 이면 아무것도 하지 않는다).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->skipReason === '' && defined('INSTALLER_RUNTIME_PATH') && is_file(INSTALLER_RUNTIME_PATH)) {
            @unlink(INSTALLER_RUNTIME_PATH);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // sanitizeConfigForState
    // -----------------------------------------------------------------

    /**
     * state 저장용 정제가 비밀 4종을 모두 제거하고 비밀 아닌 값은 보존한다.
     *
     * @effects step3_post_writes_state_config_without_db_write_password, step3_post_writes_state_config_without_db_read_password, step3_post_writes_state_config_without_admin_password, step3_post_writes_state_config_without_admin_password_confirm
     *
     * @return void 반환값 없음
     */
    public function test_sanitize_config_for_state_removes_all_four_secrets(): void
    {
        $config = [
            'app_name' => 'G7 Site',
            'db_write_host' => 'localhost',
            'db_write_username' => 'root',
            'db_write_password' => 'db-secret',
            'db_read_password' => 'read-secret',
            'use_read_db' => true,
            'db_read_host' => 'read-host',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'plain-admin-pw',
            'admin_password_confirm' => 'plain-admin-pw',
        ];

        $safe = sanitizeConfigForState($config);

        foreach (installerSecretConfigKeys() as $secretKey) {
            $this->assertArrayNotHasKey($secretKey, $safe, "{$secretKey} 가 state 로 유출되면 안 됨");
        }

        // 비-비밀 키는 보존
        $this->assertSame('G7 Site', $safe['app_name']);
        $this->assertSame('localhost', $safe['db_write_host']);
        $this->assertSame('root', $safe['db_write_username']);
        $this->assertSame('admin@example.com', $safe['admin_email']);
        // use_read_db=true 이므로 read 필드 보존
        $this->assertSame('read-host', $safe['db_read_host']);
    }

    /**
     * 비밀 키 목록이 정확히 4종(DB write/read·관리자 비밀번호·확인)이다.
     *
     * @effects step3_post_writes_state_config_without_db_write_password, step3_post_writes_state_config_without_db_read_password, step3_post_writes_state_config_without_admin_password, step3_post_writes_state_config_without_admin_password_confirm
     *
     * @return void 반환값 없음
     */
    public function test_installer_secret_config_keys_covers_four_secrets(): void
    {
        $keys = installerSecretConfigKeys();

        $this->assertEqualsCanonicalizing(
            ['db_write_password', 'db_read_password', 'admin_password', 'admin_password_confirm'],
            $keys,
        );
    }

    /**
     * 이슈 #63 기존 동작 보존 — use_read_db=false 이면 read 접속 필드 잔존값 제거.
     *
     * @effects sanitize_config_for_state_drops_read_connection_fields_when_use_read_db_false
     *
     * @return void 반환값 없음
     */
    public function test_sanitize_config_for_state_drops_read_fields_when_use_read_db_false(): void
    {
        $config = [
            'use_read_db' => false,
            'db_write_host' => 'mysql',
            'db_read_host' => 'stale-host',
            'db_read_port' => '3307',
            'db_read_database' => 'stale_db',
            'db_read_username' => 'stale_user',
            'db_read_password' => 'stale_pw',
        ];

        $safe = sanitizeConfigForState($config);

        $this->assertArrayNotHasKey('db_read_host', $safe);
        $this->assertArrayNotHasKey('db_read_port', $safe);
        $this->assertArrayNotHasKey('db_read_database', $safe);
        $this->assertArrayNotHasKey('db_read_username', $safe);
        $this->assertArrayNotHasKey('db_read_password', $safe);
        $this->assertSame('mysql', $safe['db_write_host']);
    }

    // -----------------------------------------------------------------
    // redactInstallationStateSecrets
    // -----------------------------------------------------------------

    /**
     * 이미 기록된 state 에서 비밀 4종만 제거한다.
     *
     * @effects reset_state_redacts_secrets_in_preserved_config, abort_installation_redacts_secrets_in_preserved_config
     *
     * @return void 반환값 없음
     */
    public function test_redact_installation_state_secrets_strips_config_secrets(): void
    {
        $state = [
            'installation_status' => 'completed',
            'completed_tasks' => ['db_migrate', 'db_seed'],
            'config' => [
                'app_name' => 'G7',
                'admin_password' => 'plain',
                'admin_password_confirm' => 'plain',
                'db_write_password' => 'dbpw',
                'db_read_password' => 'rdpw',
            ],
        ];

        $redacted = redactInstallationStateSecrets($state);

        $this->assertArrayNotHasKey('admin_password', $redacted['config']);
        $this->assertArrayNotHasKey('admin_password_confirm', $redacted['config']);
        $this->assertArrayNotHasKey('db_write_password', $redacted['config']);
        $this->assertArrayNotHasKey('db_read_password', $redacted['config']);

        // 비밀이 아닌 것은 불변
        $this->assertSame('G7', $redacted['config']['app_name']);
        $this->assertSame('completed', $redacted['installation_status']);
        $this->assertSame(['db_migrate', 'db_seed'], $redacted['completed_tasks']);
    }

    /**
     * config 가 없는 state 는 그대로 둔다.
     *
     * @effects reset_state_redacts_secrets_in_preserved_config
     *
     * @return void 반환값 없음
     */
    public function test_redact_installation_state_secrets_is_noop_without_config(): void
    {
        $state = ['installation_status' => 'running', 'completed_tasks' => []];

        $this->assertSame($state, redactInstallationStateSecrets($state));
    }

    /**
     * redact 는 use_read_db 정리를 수행하지 않는다 — 이미 기록된 state 의 접속
     * 정보(비밀 아님)를 지우면 롤백/cleanup 이 깨진다. 비밀 4종만 제거.
     *
     * @effects sanitize_config_for_state_preserves_non_secret_fields_like_app_name_and_db_host
     *
     * @return void 반환값 없음
     */
    public function test_redact_preserves_non_secret_db_fields(): void
    {
        $state = [
            'config' => [
                'use_read_db' => false,
                'db_read_host' => 'leftover',
                'db_write_password' => 'x',
            ],
        ];

        $redacted = redactInstallationStateSecrets($state);

        $this->assertArrayNotHasKey('db_write_password', $redacted['config']);
        $this->assertSame('leftover', $redacted['config']['db_read_host']);
    }

    // -----------------------------------------------------------------
    // buildInstallerRuntimeFromState — 결손 폴백
    // -----------------------------------------------------------------

    /**
     * 호출부가 준 관리자 비밀번호가 runtime 에 실린다.
     *
     * @effects build_runtime_adds_admin_section_only_when_caller_supplies_password
     *
     * @return void 반환값 없음
     */
    public function test_build_runtime_carries_admin_password_when_provided_by_caller(): void
    {
        // 호출자(install-process.php) 가 세션 비밀번호를 넣는 정상 경로는 admin 섹션을
        // 직접 대입한다. 본 테스트는 그 섹션이 기존 runtime 에 있을 때 보존되는지 확인.
        writeInstallerRuntime([
            'app' => ['key' => generateAppKeyInline()],
            'admin' => ['password' => 'kept-admin-pw'],
        ]);

        $built = buildInstallerRuntimeFromState([
            'db_write_host' => 'localhost',
            'db_write_database' => 'g7',
            'db_write_username' => 'root',
        ]);

        $this->assertSame('kept-admin-pw', $built['admin']['password'] ?? null);
    }

    /**
     * state 에 DB 비밀번호가 없으면 기존 runtime 값을 보존한다.
     *
     * @effects build_runtime_preserves_existing_db_write_password_when_state_config_lacks_it
     *
     * @return void 반환값 없음
     */
    public function test_build_runtime_preserves_existing_db_password_when_state_config_lacks_it(): void
    {
        writeInstallerRuntime([
            'db' => [
                'write' => [
                    'host' => 'localhost',
                    'port' => '3306',
                    'database' => 'g7',
                    'username' => 'root',
                    'password' => 'existing-write-pw',
                ],
            ],
            'app' => ['key' => generateAppKeyInline()],
        ]);

        // state.config 에는 비밀번호가 없다 (sanitize 로 제거됨)
        $built = buildInstallerRuntimeFromState([
            'db_write_host' => 'localhost',
            'db_write_database' => 'g7',
            'db_write_username' => 'root',
        ]);

        $this->assertSame('existing-write-pw', $built['db']['write']['password']);
    }

    /**
     * read 비밀번호는 write 값이 아니라 기존 runtime 의 read 값으로 폴백한다.
     *
     * @effects build_runtime_read_password_falls_back_to_existing_runtime_before_write_password
     *
     * @return void 반환값 없음
     */
    public function test_build_runtime_read_password_falls_back_to_existing_runtime_not_write_password(): void
    {
        writeInstallerRuntime([
            'db' => [
                'write' => ['host' => 'w-host', 'database' => 'g7', 'username' => 'wu', 'password' => 'write-pw'],
                'read' => ['host' => 'r-host', 'database' => 'g7r', 'username' => 'ru', 'password' => 'read-pw'],
            ],
            'app' => ['key' => generateAppKeyInline()],
        ]);

        $built = buildInstallerRuntimeFromState([
            'use_read_db' => true,
            'db_write_host' => 'w-host',
            'db_write_database' => 'g7',
            'db_write_username' => 'wu',
            'db_read_host' => 'r-host',
            'db_read_database' => 'g7r',
            'db_read_username' => 'ru',
            // db_read_password / db_write_password 는 state 에 없음
        ]);

        $this->assertSame('read-pw', $built['db']['read']['password'], 'read 비밀번호가 write 값으로 대체되면 안 됨');
        $this->assertSame('write-pw', $built['db']['write']['password']);
    }

    /**
     * state 에 비밀번호가 있으면 그 값을 우선한다.
     *
     * @effects build_runtime_prefers_caller_supplied_password_over_existing_runtime_value
     *
     * @return void 반환값 없음
     */
    public function test_build_runtime_keeps_state_config_password_when_present(): void
    {
        // 세션이 살아있는 정상 경로 — state 가 아닌 호출자가 넘긴 config 에 비밀번호가
        // 있으면 그것이 우선한다 (기존 동작 보존).
        writeInstallerRuntime([
            'db' => ['write' => ['password' => 'old-pw']],
            'app' => ['key' => generateAppKeyInline()],
        ]);

        $built = buildInstallerRuntimeFromState([
            'db_write_host' => 'localhost',
            'db_write_password' => 'new-pw',
        ]);

        $this->assertSame('new-pw', $built['db']['write']['password']);
    }

    // -----------------------------------------------------------------
    // hydrateDbSecretsFromRuntime
    // -----------------------------------------------------------------

    /**
     * 비어 있는 DB 비밀번호를 runtime 값으로 채운다.
     *
     * @effects hydrate_db_secrets_fills_empty_write_password_from_runtime, hydrate_db_secrets_fills_empty_read_password_from_runtime
     *
     * @return void 반환값 없음
     */
    public function test_hydrate_db_secrets_from_runtime_fills_empty_passwords(): void
    {
        writeInstallerRuntime([
            'db' => [
                'write' => ['password' => 'rt-write-pw'],
                'read' => ['password' => 'rt-read-pw'],
            ],
        ]);

        $config = [
            'db_write_host' => 'localhost',
            'db_write_username' => 'root',
        ];

        $hydrated = hydrateDbSecretsFromRuntime($config);

        $this->assertSame('rt-write-pw', $hydrated['db_write_password']);
        $this->assertSame('rt-read-pw', $hydrated['db_read_password']);
        $this->assertSame('localhost', $hydrated['db_write_host']);
    }

    /**
     * 이미 있는 DB 비밀번호는 덮어쓰지 않는다.
     *
     * @effects hydrate_db_secrets_does_not_override_password_already_present_in_config
     *
     * @return void 반환값 없음
     */
    public function test_hydrate_db_secrets_does_not_override_existing_password(): void
    {
        writeInstallerRuntime(['db' => ['write' => ['password' => 'rt-pw']]]);

        $hydrated = hydrateDbSecretsFromRuntime(['db_write_password' => 'session-pw']);

        $this->assertSame('session-pw', $hydrated['db_write_password']);
    }

    /**
     * runtime 이 없으면 config 를 그대로 돌려준다.
     *
     * @effects hydrate_db_secrets_returns_config_unchanged_when_runtime_absent
     *
     * @return void 반환값 없음
     */
    public function test_hydrate_db_secrets_returns_config_unchanged_when_runtime_absent(): void
    {
        // 부재 파일 unlink 는 PHPUnit 11 이 경고로 집계한다 — 있을 때만 지운다
        if (is_file(INSTALLER_RUNTIME_PATH)) {
            unlink(INSTALLER_RUNTIME_PATH);
        }

        $config = ['db_write_host' => 'localhost'];

        $this->assertSame($config, hydrateDbSecretsFromRuntime($config));
    }
}
