<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 런타임 헬퍼 단위 테스트
 *
 * public/install/includes/installer-runtime.php 의 헬퍼 함수들을 검증한다.
 * 인스톨러는 Laravel 외부에서 동작하므로 BASE_PATH 상수와 헬퍼 인클루드를
 * 테스트 setUp 단계에서 직접 처리한다.
 *
 * @see https://github.com/gnuboard/g7/issues/23
 */
class InstallerRuntimeHelperTest extends TestCase
{
    private string $tempBase;

    protected function setUp(): void
    {
        parent::setUp();

        // 격리된 BASE_PATH — 운영 환경 storage/installer 와 충돌 방지
        $this->tempBase = sys_get_temp_dir() . '/g7-installer-runtime-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempBase . '/storage/installer', 0755, true);

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $this->tempBase);
        }

        // INSTALLER_RUNTIME_PATH 는 BASE_PATH 기반으로 한 번만 정의되므로,
        // 첫 테스트가 끝까지 책임진다 (BASE_PATH 자체가 const 라 재정의 불가).
        // installer-runtime.php 자체에 escapeEnvValue polyfill 이 있어 functions.php
        // 미로드 환경에서도 mergeRuntimeIntoEnv 가 정상 동작한다.
        require_once dirname(__DIR__, 3) . '/public/install/includes/installer-runtime.php';
    }

    protected function tearDown(): void
    {
        // INSTALLER_RUNTIME_PATH 는 첫 정의된 BASE_PATH 에 묶여있으므로
        // 그 파일만 정리하고, 임시 디렉토리는 시스템 temp cleaner 에 위임
        if (defined('INSTALLER_RUNTIME_PATH') && is_file(INSTALLER_RUNTIME_PATH)) {
            @unlink(INSTALLER_RUNTIME_PATH);
        }

        parent::tearDown();
    }

    public function test_read_returns_null_when_file_absent(): void
    {
        $this->assertFileDoesNotExist(INSTALLER_RUNTIME_PATH);
        $this->assertNull(readInstallerRuntime());
    }

    public function test_write_creates_file_atomically(): void
    {
        $data = [
            'db' => ['write' => ['host' => '127.0.0.1', 'port' => '3306']],
            'app' => ['key' => 'base64:abc'],
        ];

        $this->assertTrue(writeInstallerRuntime($data));
        $this->assertFileExists(INSTALLER_RUNTIME_PATH);

        $read = readInstallerRuntime();
        $this->assertSame($data['db']['write']['host'], $read['db']['write']['host']);
        $this->assertSame('base64:abc', $read['app']['key']);
    }

    public function test_write_then_delete_round_trip(): void
    {
        writeInstallerRuntime(['app' => ['key' => 'base64:xyz']]);
        $this->assertFileExists(INSTALLER_RUNTIME_PATH);

        $this->assertTrue(deleteInstallerRuntime());
        $this->assertFileDoesNotExist(INSTALLER_RUNTIME_PATH);

        // 멱등성 — 부재 상태에서 재호출 OK
        $this->assertTrue(deleteInstallerRuntime());
    }

    public function test_read_returns_null_for_corrupted_file(): void
    {
        file_put_contents(INSTALLER_RUNTIME_PATH, "<?php\nreturn 'not-an-array';\n");

        $this->assertNull(readInstallerRuntime());
    }

    public function test_generate_app_key_inline_produces_valid_laravel_format(): void
    {
        $key = generateAppKeyInline();

        $this->assertStringStartsWith('base64:', $key);

        $raw = base64_decode(substr($key, 7), true);
        $this->assertNotFalse($raw, 'base64 portion must decode');
        $this->assertSame(32, strlen($raw), 'AES-256-CBC requires 32-byte key');

        // 매 호출마다 다른 값
        $this->assertNotSame($key, generateAppKeyInline());
    }

    public function test_build_runtime_preserves_existing_app_key(): void
    {
        $existingKey = generateAppKeyInline();
        writeInstallerRuntime(['app' => ['key' => $existingKey]]);

        $built = buildInstallerRuntimeFromState([
            'db_write_host' => 'localhost',
            'db_write_database' => 'g7',
            'db_write_username' => 'root',
        ]);

        // 재시도 시 키 보존 — 새 키로 덮어쓰지 않음
        $this->assertSame($existingKey, $built['app']['key']);
    }

    public function test_build_runtime_generates_new_app_key_when_absent(): void
    {
        $this->assertFileDoesNotExist(INSTALLER_RUNTIME_PATH);

        $built = buildInstallerRuntimeFromState([
            'db_write_host' => 'localhost',
            'db_write_database' => 'g7',
            'db_write_username' => 'root',
        ]);

        $this->assertStringStartsWith('base64:', $built['app']['key']);
        $this->assertSame(32, strlen(base64_decode(substr($built['app']['key'], 7), true)));
    }

    public function test_build_runtime_includes_separate_read_when_specified(): void
    {
        $built = buildInstallerRuntimeFromState([
            'db_write_host' => 'write-host',
            'db_write_database' => 'g7',
            'db_read_host' => 'read-host',
            'db_read_database' => 'g7_replica',
        ]);

        $this->assertArrayHasKey('read', $built['db']);
        $this->assertSame('read-host', $built['db']['read']['host']);
        $this->assertSame('g7_replica', $built['db']['read']['database']);
    }

    public function test_build_runtime_omits_read_when_same_as_write(): void
    {
        $built = buildInstallerRuntimeFromState([
            'db_write_host' => 'localhost',
            'db_read_host' => 'localhost', // 동일 호스트 → 별도 read 불필요
        ]);

        $this->assertArrayNotHasKey('read', $built['db']);
    }

    public function test_build_runtime_falls_back_to_legacy_db_keys(): void
    {
        // 일부 인스톨러 경로가 db_host/db_database 등 prefix 없는 키를 사용
        $built = buildInstallerRuntimeFromState([
            'db_host' => 'legacy-host',
            'db_database' => 'g7',
            'db_username' => 'admin',
        ]);

        $this->assertSame('legacy-host', $built['db']['write']['host']);
        $this->assertSame('g7', $built['db']['write']['database']);
        $this->assertSame('admin', $built['db']['write']['username']);
    }

    public function test_merge_replaces_app_key_line_in_env_content(): void
    {
        $envContent = "APP_NAME=Test\nAPP_KEY=\nDB_HOST=localhost\n";
        $runtime = ['app' => ['key' => 'base64:abc']];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('APP_KEY=base64:abc', $merged);
        $this->assertStringNotContainsString("APP_KEY=\n", $merged);
        $this->assertStringContainsString('DB_HOST=localhost', $merged); // 다른 라인 보존
    }

    public function test_merge_appends_app_key_when_not_present(): void
    {
        $envContent = "APP_NAME=Test\nDB_HOST=localhost\n";
        $runtime = ['app' => ['key' => 'base64:xyz']];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('APP_KEY=base64:xyz', $merged);
    }

    public function test_merge_appends_installer_completed_flag(): void
    {
        $envContent = "APP_KEY=base64:test\n";
        $runtime = ['app' => ['key' => 'base64:test']];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('INSTALLER_COMPLETED=true', $merged);
        $this->assertStringContainsString('# Installation Status', $merged);
    }

    public function test_merge_does_not_duplicate_installer_completed(): void
    {
        $envContent = "APP_KEY=base64:test\n\nINSTALLER_COMPLETED=true\n";
        $runtime = ['app' => ['key' => 'base64:test']];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        // 정확히 1회만 등장
        $count = substr_count($merged, 'INSTALLER_COMPLETED=true');
        $this->assertSame(1, $count, 'INSTALLER_COMPLETED 가 중복되지 않아야 함');
    }

    public function test_merge_skips_app_key_when_invalid_format(): void
    {
        $envContent = "APP_KEY=existing\n";
        $runtime = ['app' => ['key' => 'not-base64-format']];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        // base64: prefix 가 없으면 치환하지 않음
        $this->assertStringContainsString('APP_KEY=existing', $merged);
        $this->assertStringNotContainsString('APP_KEY=not-base64', $merged);
    }

    /**
     * 회귀 테스트: state.json 결손 시에도 runtime 의 DB 정보로 .env 가 완성되어야 한다.
     *
     * 회귀 시나리오: setInstallationCompleteSSE 가 state.json 을 finalize 호출 전에
     * 삭제 → finalize 가 generateEnvContent() 호출 시 state.config 를 못 읽어 .env.example
     * 의 placeholder 값(127.0.0.1 / g7 / root / 빈 비밀번호) 으로 .env 가 작성되던 회귀.
     */
    public function test_merge_replaces_db_credentials_with_runtime_values(): void
    {
        $envContent = <<<'ENV'
DB_WRITE_HOST=127.0.0.1
DB_WRITE_PORT=3306
DB_WRITE_DATABASE=g7
DB_WRITE_USERNAME=root
DB_WRITE_PASSWORD=
DB_PREFIX=g7_
APP_KEY=
ENV;

        $runtime = [
            'db' => [
                'write' => [
                    'host' => 'real-host.example.com',
                    'port' => '3307',
                    'database' => 'real_db',
                    'username' => 'real_user',
                    'password' => 'real_pass',
                ],
                'prefix' => 'real_',
            ],
            'app' => ['key' => 'base64:' . base64_encode(str_repeat('a', 32))],
        ];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('DB_WRITE_HOST=real-host.example.com', $merged);
        $this->assertStringContainsString('DB_WRITE_PORT=3307', $merged);
        $this->assertStringContainsString('DB_WRITE_DATABASE=real_db', $merged);
        $this->assertStringContainsString('DB_WRITE_USERNAME=real_user', $merged);
        $this->assertStringContainsString('DB_WRITE_PASSWORD="real_pass"', $merged);
        $this->assertStringContainsString('DB_PREFIX=real_', $merged);

        // Read 라인도 write 와 동기화되어 치환되어야 함
        $this->assertStringNotContainsString('DB_WRITE_HOST=127.0.0.1', $merged);
        $this->assertStringNotContainsString('DB_WRITE_DATABASE=g7' . PHP_EOL, $merged);
    }

    public function test_merge_syncs_read_with_write_when_read_not_specified(): void
    {
        $envContent = "DB_READ_HOST=127.0.0.1\nDB_READ_DATABASE=g7\nDB_READ_USERNAME=root\nDB_READ_PASSWORD=\n";

        $runtime = [
            'db' => [
                'write' => [
                    'host' => 'w-host',
                    'port' => '3306',
                    'database' => 'wdb',
                    'username' => 'wu',
                    'password' => 'wp',
                ],
            ],
        ];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        // read 미지정 시 write 값으로 동기
        $this->assertStringContainsString('DB_READ_HOST=w-host', $merged);
        $this->assertStringContainsString('DB_READ_DATABASE=wdb', $merged);
        $this->assertStringContainsString('DB_READ_USERNAME=wu', $merged);
        $this->assertStringContainsString('DB_READ_PASSWORD="wp"', $merged);
    }

    public function test_merge_uses_separate_read_when_specified(): void
    {
        $envContent = "DB_READ_HOST=\nDB_READ_DATABASE=\n";

        $runtime = [
            'db' => [
                'write' => ['host' => 'w-host', 'database' => 'wdb', 'username' => 'wu', 'password' => 'wp'],
                'read' => ['host' => 'r-host', 'database' => 'rdb', 'username' => 'ru', 'password' => 'rp'],
            ],
        ];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('DB_READ_HOST=r-host', $merged);
        $this->assertStringContainsString('DB_READ_DATABASE=rdb', $merged);
    }

    public function test_merge_appends_db_lines_when_absent_from_template(): void
    {
        $envContent = "APP_NAME=Test\n";

        $runtime = [
            'db' => [
                'write' => [
                    'host' => 'h',
                    'port' => '3306',
                    'database' => 'd',
                    'username' => 'u',
                    'password' => 'p',
                ],
            ],
        ];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        // .env.example 에 라인이 없는 비표준 케이스 — 끝에 추가
        $this->assertStringContainsString('DB_WRITE_HOST=h', $merged);
        $this->assertStringContainsString('DB_WRITE_DATABASE=d', $merged);
    }

    public function test_merge_preserves_db_lines_when_runtime_has_no_db(): void
    {
        $envContent = "DB_WRITE_HOST=user-input.com\nAPP_KEY=\n";

        // runtime 에 db 키 자체가 없는 케이스 (이상 상태) — 기존 .env 값 보존
        $runtime = ['app' => ['key' => 'base64:' . base64_encode(str_repeat('x', 32))]];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('DB_WRITE_HOST=user-input.com', $merged);
    }

    public function test_merge_escapes_password_with_special_characters(): void
    {
        $envContent = "DB_WRITE_PASSWORD=\n";

        $runtime = [
            'db' => [
                'write' => [
                    'host' => 'h',
                    'database' => 'd',
                    'username' => 'u',
                    'password' => 'p@ss"with$pecial',
                ],
            ],
        ];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        // escapeEnvValue 가 큰따옴표/백슬래시 escape + 큰따옴표 wrapping
        $this->assertStringContainsString('DB_WRITE_PASSWORD="p@ss\\"with$pecial"', $merged);
    }
}
