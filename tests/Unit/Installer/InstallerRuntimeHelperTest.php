<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 런타임 헬퍼 단위 테스트
 *
 * public/install/includes/installer-runtime.php 의 헬퍼 함수들을 검증한다.
 * 인스톨러는 Laravel 외부에서 동작하므로 BASE_PATH 상수와 헬퍼 인클루드를
 * 테스트 setUp 단계에서 직접 처리한다.
 *
 * 메서드마다 새 프로세스에서 돈다 (R7) — 다른 인스톨러 테스트가 실 저장소 루트를 BASE_PATH 로
 * 먼저 define 하면 아래 skip 가드가 26 메서드를 통째로 건너뛰어 커버리지가 조용히 사라진다.
 *
 * @see https://github.com/gnuboard/g7/issues/23
 *
 * 효과 요약(마커 아님 — 평문): 런타임 파일의 읽기, 쓰기, 삭제, 재구성과 .env 머지 헬퍼가 약속대로 동작한다. 시나리오 표식은 메서드마다 단다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerRuntimeHelperTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private string $tempBase = '';

    private string $skipReason = '';

    /**
     * 임시 BASE_PATH 와 런타임 저장 디렉토리를 준비하고 런타임 헬퍼를 적재한다.
     *
     * BASE_PATH 가 이미 시스템 임시 디렉토리 밖으로 정의돼 있으면 실 런타임 파일 보호를 위해 건너뛴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 격리된 BASE_PATH — 운영 환경 storage/installer 와 충돌 방지
        $this->tempBase = sys_get_temp_dir().'/g7-installer-runtime-test-'.bin2hex(random_bytes(4));

        // 안전 가드: INSTALLER_RUNTIME_PATH(= BASE_PATH/storage/installer/runtime.php) 는
        // PHP 상수라 한 프로세스에서 단 한 번만 정의된다. 다른 Installer 테스트가 먼저
        // 프로젝트 루트로 BASE_PATH 를 박으면 tearDown 의 @unlink 가 실제 운영 runtime.php
        // (DB 자격증명/APP_KEY 임시 보관 파일)를 삭제하게 된다. temp 하위가 아니면 skip.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                $this->skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '
                    .'다른 Installer 테스트의 BASE_PATH 정의가 선행됨. 실제 storage/installer/runtime.php '
                    .'파괴 방지를 위해 skip. 격리 실행: php vendor/bin/phpunit --filter=InstallerRuntimeHelperTest';
                $this->markTestSkipped($this->skipReason);
            }
            $this->tempBase = (string) BASE_PATH;
        } else {
            define('BASE_PATH', $this->tempBase);
        }

        if (! is_dir($this->tempBase.'/storage/installer')) {
            @mkdir($this->tempBase.'/storage/installer', 0755, true);
        }

        // INSTALLER_RUNTIME_PATH 는 BASE_PATH 기반으로 한 번만 정의되므로,
        // 첫 테스트가 끝까지 책임진다 (BASE_PATH 자체가 const 라 재정의 불가).
        // installer-runtime.php 자체에 escapeEnvValue polyfill 이 있어 functions.php
        // 미로드 환경에서도 mergeRuntimeIntoEnv 가 정상 동작한다.
        require_once dirname(__DIR__, 3).'/public/install/includes/installer-runtime.php';
    }

    /**
     * 건너뛰지 않은 테스트에 한해 테스트가 만든 런타임 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        // skip 상태(= BASE_PATH 가 실 루트)면 파괴적 정리를 절대 수행하지 않는다.
        if ($this->skipReason === '' && defined('INSTALLER_RUNTIME_PATH') && is_file(INSTALLER_RUNTIME_PATH)) {
            @unlink(INSTALLER_RUNTIME_PATH);
        }

        parent::tearDown();
    }

    /**
     * 런타임 파일이 없으면 읽기 헬퍼가 null 을 돌려주는지 확인한다.
     *
     * @scenario runtime_state=absent
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_read_returns_null_when_file_absent(): void
    {
        $this->assertFileDoesNotExist(INSTALLER_RUNTIME_PATH);
        $this->assertNull(readInstallerRuntime());
    }

    /**
     * 쓰기 헬퍼가 런타임 파일을 만들고 쓴 DB 정보와 앱 키를 그대로 다시 읽을 수 있는지 확인한다.
     *
     * @scenario runtime_state=present_with_db_and_key
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
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

    /**
     * 쓴 런타임 파일을 삭제 헬퍼가 지우고, 파일이 없을 때 다시 불러도 성공하는지 확인한다.
     *
     * @scenario runtime_state=present_with_db_and_key, runtime_state=absent
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_write_then_delete_round_trip(): void
    {
        writeInstallerRuntime(['app' => ['key' => 'base64:xyz']]);
        $this->assertFileExists(INSTALLER_RUNTIME_PATH);

        $this->assertTrue(deleteInstallerRuntime());
        $this->assertFileDoesNotExist(INSTALLER_RUNTIME_PATH);

        // 멱등성 — 부재 상태에서 재호출 OK
        $this->assertTrue(deleteInstallerRuntime());
    }

    /**
     * 삭제 경로도 쓰기 경로와 같은 OPcache 규약을 따른다 (15차 G3 W1-11).
     *
     * `runtime.php` 는 PHP 파일이라 OPcache 가 캐시한다. 쓰기(`writeInstallerRuntime`)와 읽기는
     * 갱신 직후 항목을 버리는데 삭제만 빠져 있었다 — 지운 뒤 `revalidate_freq` 창 안의 다음
     * 요청이 stale 항목을 include 해 이미 삭제된 비밀을 읽는다.
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_runtime_opcache_is_invalidated_on_write
     *
     * @return void 반환값 없음
     */
    public function test_delete_invalidates_the_runtime_opcache_entry_like_the_write_path(): void
    {
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/installer-runtime.php');

        $this->assertStringContainsString(
            'installerInvalidateRuntimeOpcache();',
            $this->functionBody($source, 'deleteInstallerRuntime'),
            '삭제 경로가 OPcache 항목을 버리지 않는다 — 쓰기 경로와 비대칭이다'
        );
    }

    /**
     * 배열이 아닌 값을 돌려주는 손상된 런타임 파일을 읽기 헬퍼가 null 로 처리하는지 확인한다.
     *
     * @scenario runtime_state=present_corrupted
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_read_returns_null_for_corrupted_file(): void
    {
        file_put_contents(INSTALLER_RUNTIME_PATH, "<?php\nreturn 'not-an-array';\n");

        $this->assertNull(readInstallerRuntime());
    }

    /**
     * 순수 PHP 로 만든 앱 키가 base64 접두와 32바이트 길이를 갖고 호출마다 달라지는지 확인한다.
     *
     * @scenario runtime_state=absent
     *
     * @effects key_generate_task_writes_app_key_to_runtime_php_via_pure_php
     *
     * @return void 반환값 없음
     */
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

    /**
     * 기존 런타임 파일에 앱 키가 있으면 재구성이 그 키를 새 키로 덮어쓰지 않는지 확인한다.
     *
     * @scenario runtime_state=present_with_db_and_key, retry_kind=resume_after_failure
     *
     * @effects build_runtime_preserves_existing_app_key_unchanged_regression_guard, retry_run_preserves_existing_app_key_in_runtime_php
     *
     * @return void 반환값 없음
     */
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

    /**
     * 런타임 파일이 없을 때 재구성이 형식에 맞는 새 앱 키를 만드는지 확인한다.
     *
     * @scenario runtime_state=absent
     *
     * @effects key_generate_task_writes_app_key_to_runtime_php_via_pure_php
     *
     * @return void 반환값 없음
     */
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

    /**
     * Read DB 사용을 고르고 다른 호스트를 주면 재구성 결과에 별도 read 연결이 실리는지 확인한다.
     *
     * @scenario read_db=enabled_separate_host
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_build_runtime_includes_separate_read_when_specified(): void
    {
        $built = buildInstallerRuntimeFromState([
            'use_read_db' => true, // Read DB 사용 선택 (판정 SSoT)
            'db_write_host' => 'write-host',
            'db_write_database' => 'g7',
            'db_read_host' => 'read-host',
            'db_read_database' => 'g7_replica',
        ]);

        $this->assertArrayHasKey('read', $built['db']);
        $this->assertSame('read-host', $built['db']['read']['host']);
        $this->assertSame('g7_replica', $built['db']['read']['database']);
    }

    /**
     * Read DB 호스트가 Write 와 같으면 별도 read 연결을 만들지 않는지 확인한다.
     *
     * @scenario runtime_state=absent
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_build_runtime_omits_read_when_same_as_write(): void
    {
        $built = buildInstallerRuntimeFromState([
            'use_read_db' => true,
            'db_write_host' => 'localhost',
            'db_read_host' => 'localhost', // 동일 호스트 → 별도 read 불필요
        ]);

        $this->assertArrayNotHasKey('read', $built['db']);
    }

    /**
     * 이슈 #63 회귀 — use_read_db=false 인데 db_read_host 에 잘못된 값이 잔존한 경우
     * read 커넥션을 만들지 않아야 한다 (Laravel 이 write 로 자동 fallback).
     *
     * @scenario read_db=disabled
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_build_runtime_omits_read_when_use_read_db_false(): void
    {
        $built = buildInstallerRuntimeFromState([
            'use_read_db' => false,
            'db_write_host' => 'mysql',
            'db_write_database' => 'g7',
            'db_read_host' => 'l3ocalhost', // 잔존 잘못된 값 (이슈 재현값)
            'db_read_database' => 'wrong_db',
        ]);

        $this->assertArrayNotHasKey('read', $built['db']);
    }

    /**
     * 이슈 #63 회귀 — use_read_db 키 자체가 없는 레거시 state 도
     * read 를 만들지 않아야 한다 (기본 미사용).
     *
     * @scenario read_db=disabled
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_build_runtime_omits_read_when_use_read_db_absent(): void
    {
        $built = buildInstallerRuntimeFromState([
            'db_write_host' => 'mysql',
            'db_read_host' => 'read-host', // use_read_db 미지정 → read 무시
        ]);

        $this->assertArrayNotHasKey('read', $built['db']);
    }

    /**
     * 접두 없는 옛 DB 키만 있는 상태에서도 재구성이 write 연결 값을 채우는지 확인한다.
     *
     * @scenario runtime_state=absent
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
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

    /**
     * .env 의 빈 APP_KEY 줄을 런타임 키로 바꾸고 다른 줄은 보존하는지 확인한다.
     *
     * @scenario finalize_call=success, env_state=example_only
     *
     * @effects finalize_replaces_app_key_line_in_env_when_present
     *
     * @return void 반환값 없음
     */
    public function test_merge_replaces_app_key_line_in_env_content(): void
    {
        $envContent = "APP_NAME=Test\nAPP_KEY=\nDB_HOST=localhost\n";
        $runtime = ['app' => ['key' => 'base64:abc']];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('APP_KEY=base64:abc', $merged);
        $this->assertStringNotContainsString("APP_KEY=\n", $merged);
        $this->assertStringContainsString('DB_HOST=localhost', $merged); // 다른 라인 보존
    }

    /**
     * .env 에 APP_KEY 줄이 없으면 런타임 키 줄을 덧붙이는지 확인한다.
     *
     * @scenario finalize_call=success, env_state=example_only
     *
     * @effects finalize_appends_app_key_line_when_absent_from_template
     *
     * @return void 반환값 없음
     */
    public function test_merge_appends_app_key_when_not_present(): void
    {
        $envContent = "APP_NAME=Test\nDB_HOST=localhost\n";
        $runtime = ['app' => ['key' => 'base64:xyz']];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('APP_KEY=base64:xyz', $merged);
    }

    /**
     * 머지 결과에 설치 상태 주석과 설치 완료 표시 줄이 붙는지 확인한다.
     *
     * @scenario finalize_call=success, env_state=example_only
     *
     * @effects finalize_appends_installer_completed_true_with_status_comment
     *
     * @return void 반환값 없음
     */
    public function test_merge_appends_installer_completed_flag(): void
    {
        $envContent = "APP_KEY=base64:test\n";
        $runtime = ['app' => ['key' => 'base64:test']];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('INSTALLER_COMPLETED=true', $merged);
        $this->assertStringContainsString('# Installation Status', $merged);
    }

    /**
     * 이미 설치 완료 표시가 있는 .env 를 다시 머지해도 그 줄이 한 번만 남는지 확인한다.
     *
     * @scenario finalize_call=retry_after_failure, env_state=partially_filled
     *
     * @effects finalize_does_not_duplicate_installer_completed_on_retry
     *
     * @return void 반환값 없음
     */
    public function test_merge_does_not_duplicate_installer_completed(): void
    {
        $envContent = "APP_KEY=base64:test\n\nINSTALLER_COMPLETED=true\n";
        $runtime = ['app' => ['key' => 'base64:test']];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        // 정확히 1회만 등장
        $count = substr_count($merged, 'INSTALLER_COMPLETED=true');
        $this->assertSame(1, $count, 'INSTALLER_COMPLETED 가 중복되지 않아야 함');
    }

    /**
     * base64 접두가 없는 런타임 키는 기존 APP_KEY 줄을 바꾸지 않는지 확인한다.
     *
     * @scenario finalize_call=success, env_state=partially_filled
     *
     * @effects finalize_skips_app_key_when_runtime_value_lacks_base64_prefix
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario finalize_call=success, env_state=example_only
     *
     * @effects finalize_replaces_db_write_credentials_with_runtime_values, finalize_replaces_db_prefix_with_runtime_value, finalize_completes_env_even_when_state_json_is_missing
     *
     * @return void 반환값 없음
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
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('a', 32))],
        ];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        // 안전 문자셋 값은 `.env` 표기를 바꾸지 않고 원문으로 남는다(escapeEnvLineValue) —
        // 그 밖의 값만 관문(serializeEnvValue)을 지나 따옴표로 감싸고, 개행·NUL 은 거부된다.
        $this->assertStringContainsString('DB_WRITE_HOST=real-host.example.com', $merged);
        $this->assertStringContainsString('DB_WRITE_PORT=3307', $merged);
        $this->assertStringContainsString('DB_WRITE_DATABASE=real_db', $merged);
        $this->assertStringContainsString('DB_WRITE_USERNAME=real_user', $merged);
        $this->assertStringContainsString('DB_WRITE_PASSWORD="real_pass"', $merged);
        $this->assertStringContainsString('DB_PREFIX=real_', $merged);

        // Read 라인도 write 와 동기화되어 치환되어야 함
        $this->assertStringNotContainsString('DB_WRITE_HOST=127.0.0.1', $merged);
        $this->assertStringNotContainsString('DB_WRITE_DATABASE=g7'.PHP_EOL, $merged);
    }

    /**
     * 이슈 #63 — read 미지정(use_read_db=false) 시 DB_READ_* 를 빈 값으로 남긴다.
     * write 값을 복사하지 않고 config/database.php 의 Elvis fallback 에 위임한다.
     *
     * @scenario finalize_call=success, env_state=example_only, read_db=disabled
     *
     * @effects finalize_replaces_db_read_credentials_with_runtime_values_or_syncs_with_write
     *
     * @return void 반환값 없음
     */
    public function test_merge_leaves_read_empty_when_read_not_specified(): void
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

        // read 미지정 시 DB_READ_* 는 빈 값 — write 값이 복사되면 안 됨
        $this->assertStringContainsString("DB_READ_HOST=\n", $merged);
        $this->assertStringContainsString("DB_READ_DATABASE=\n", $merged);
        $this->assertStringContainsString("DB_READ_USERNAME=\n", $merged);
        $this->assertStringNotContainsString('DB_READ_HOST=w-host', $merged);
        $this->assertStringNotContainsString('DB_READ_DATABASE=wdb', $merged);
        // write 라인은 정상 기록
        $this->assertStringContainsString('DB_WRITE_HOST=w-host', $merged);
    }

    /**
     * 런타임에 별도 read 연결이 있으면 DB_READ 줄이 그 값으로 채워지는지 확인한다.
     *
     * @scenario finalize_call=success, env_state=example_only, read_db=enabled_separate_host
     *
     * @effects finalize_replaces_db_read_credentials_with_runtime_values_or_syncs_with_write
     *
     * @return void 반환값 없음
     */
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

    /**
     * .env 에 DB 줄이 없는 비표준 템플릿이면 런타임 DB 값을 끝에 덧붙이는지 확인한다.
     *
     * @scenario finalize_call=success, env_state=example_only
     *
     * @effects finalize_appends_db_lines_when_absent_from_template
     *
     * @return void 반환값 없음
     */
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

    /**
     * 런타임에 DB 블록이 없으면 기존 .env 의 DB 줄을 그대로 두는지 확인한다.
     *
     * @scenario finalize_call=success, env_state=partially_filled
     *
     * @effects finalize_preserves_db_lines_when_runtime_has_no_db_block
     *
     * @return void 반환값 없음
     */
    public function test_merge_preserves_db_lines_when_runtime_has_no_db(): void
    {
        $envContent = "DB_WRITE_HOST=user-input.com\nAPP_KEY=\n";

        // runtime 에 db 키 자체가 없는 케이스 (이상 상태) — 기존 .env 값 보존
        $runtime = ['app' => ['key' => 'base64:'.base64_encode(str_repeat('x', 32))]];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringContainsString('DB_WRITE_HOST=user-input.com', $merged);
    }

    /**
     * 이슈 #465 불변식 — runtime.php 의 admin 섹션은 절대 `.env` 로 새어나가지 않는다.
     *
     * mergeRuntimeIntoEnv 는 db/app.key 만 명시 치환하는 화이트리스트 방식이다.
     * 이후 누군가 "runtime 배열을 통째로 .env 로 덤프" 하는 방식으로 리팩터링하면
     * 관리자 평문 비밀번호가 .env 에 영구 기록되므로, 그 회귀를 여기서 차단한다.
     *
     * @scenario runtime_state=present_with_db_and_admin
     *
     * @effects merge_runtime_into_env_never_writes_admin_section_whitelist_invariant, merge_runtime_into_env_still_writes_db_credentials_and_app_key
     *
     * @return void 반환값 없음
     */
    public function test_merge_runtime_into_env_never_writes_admin_section(): void
    {
        $envContent = "APP_KEY=\nDB_WRITE_PASSWORD=\n";

        $runtime = [
            'db' => [
                'write' => ['host' => 'h', 'port' => '3306', 'database' => 'd', 'username' => 'u', 'password' => 'dbpw'],
            ],
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32))],
            'admin' => ['password' => 'super-secret-admin-pw'],
        ];

        $merged = mergeRuntimeIntoEnv($envContent, $runtime);

        $this->assertStringNotContainsString('super-secret-admin-pw', $merged, '관리자 평문이 .env 로 유출되면 안 됨');
        $this->assertStringNotContainsString('ADMIN', $merged, 'ADMIN 계열 키가 .env 에 기록되면 안 됨');

        // db/app.key 는 정상 기록 (화이트리스트가 좁아지지 않았음을 함께 고정)
        $this->assertStringContainsString('DB_WRITE_PASSWORD="dbpw"', $merged);
        $this->assertStringContainsString('APP_KEY=base64:', $merged);
    }

    /**
     * 단계별 실행 토큰(installer 섹션)은 runtime 재구성에서 보존된다 — env_update 태스크가
     * runtime 을 다시 만들 때 토큰이 사라지면 그 다음 태스크 요청이 전부 거부된다.
     *
     * @scenario installation_mode=stepwise, runtime_state=present_with_db_and_key
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_build_runtime_preserves_installer_section(): void
    {
        writeInstallerRuntime([
            'app' => ['key' => generateAppKeyInline()],
            'installer' => ['stepwise_token' => 'abc123'],
        ]);

        $built = buildInstallerRuntimeFromState([
            'db_write_host' => 'localhost',
            'db_write_database' => 'g7',
            'db_write_username' => 'root',
        ]);

        $this->assertSame('abc123', $built['installer']['stepwise_token'] ?? null);
    }

    /**
     * R3 — installer 섹션(토큰)은 .env 로 새어나가지 않는다 (화이트리스트 머지).
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_merge_runtime_into_env_never_writes_installer_section(): void
    {
        $merged = mergeRuntimeIntoEnv("APP_KEY=\n", [
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32))],
            'installer' => ['stepwise_token' => 'secret-token-value'],
        ]);

        $this->assertStringNotContainsString('secret-token-value', $merged);
        $this->assertStringNotContainsString('stepwise', $merged);
    }

    /**
     * 큰따옴표와 달러 기호가 섞인 DB 비밀번호가 이스케이프되어 큰따옴표로 감싸이는지 확인한다.
     *
     * @scenario finalize_call=success, env_state=example_only
     *
     * @effects finalize_escapes_db_password_special_characters_via_escapeEnvValue
     *
     * @return void 반환값 없음
     */
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

    /**
     * 생성 규칙(`installerUniqueSuffix(INSTALLER_TMP_SUFFIX_BYTES)`)으로 만든 tmp 가 스윕에 걷힌다.
     *
     * 종전 판정 패턴은 `{8}` 이었는데 생성 이름은 16진 **16자**라 영원히 매치되지 않았다 —
     * 스윕 모집단이 0 인 채 형제(`installer-state.php`)만 `{16,}` 이었다 (13차 G3 W1-1).
     * 생성 규칙과 판정 패턴이 같은 SSoT(`functions.php`)를 보는지를 실제 이름으로 잰다.
     *
     * @scenario runtime_state=present_with_db_and_key
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_stale_runtime_tmp_files_named_by_the_real_suffix_rule_are_swept(): void
    {
        $prefix = INSTALLER_RUNTIME_PATH.INSTALLER_RUNTIME_TMP_INFIX;

        $stale = $prefix.installerUniqueSuffix(INSTALLER_TMP_SUFFIX_BYTES);
        file_put_contents($stale, 'stale');
        touch($stale, time() - 3600);

        $fresh = $prefix.installerUniqueSuffix(INSTALLER_TMP_SUFFIX_BYTES);
        file_put_contents($fresh, 'fresh');

        // 생성 규칙과 다른 이름 — 운영자가 같은 이름 공간에 둔 파일이다.
        $operator = $prefix.'backup';
        file_put_contents($operator, 'operator');
        touch($operator, time() - 3600);

        try {
            $this->assertTrue(writeInstallerRuntime(['app' => ['key' => 'base64:'.base64_encode(random_bytes(32))]]));

            $this->assertFileDoesNotExist($stale, '생성 규칙으로 만든 오래된 tmp 가 걷히지 않는다 — 판정 패턴이 생성 규칙과 갈렸다');
            $this->assertFileExists($fresh, '300초 미만의 tmp 를 지웠다 — 진행 중인 다른 요청의 파일이다');
            $this->assertFileExists($operator, '생성 규칙과 다른 이름을 지웠다');
        } finally {
            @unlink($fresh);
            @unlink($operator);
            @unlink($stale);
        }
    }

    /**
     * 생성 규칙과 판정 패턴이 **같은 SSoT** 를 본다.
     *
     * 실제 생성 이름(CSPRNG 경로)이 패턴에 매치되고, 두 스윕(런타임 · 상태)의 패턴이 모두
     * `functions.php` 의 한 상수다. mtime 조회 실패 보존은 실 파일로 재현할 수 없어(정상
     * 파일시스템에서 `filemtime` 은 실패하지 않는다) 소스 계약으로 잠근다.
     *
     * @scenario runtime_state=present_with_db_and_key
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_tmp_suffix_rule_and_sweep_pattern_share_one_source(): void
    {
        $this->assertSame(1, preg_match(INSTALLER_RUNTIME_TMP_SUFFIX_PATTERN, installerUniqueSuffix(INSTALLER_TMP_SUFFIX_BYTES)), '생성된 접미사가 판정 패턴에 매치되지 않는다');
        $this->assertSame(INSTALLER_TMP_SUFFIX_PATTERN, INSTALLER_RUNTIME_TMP_SUFFIX_PATTERN, '런타임 스윕 패턴이 SSoT 와 다르다');

        // 폴백 경로(CSPRNG 부재)는 더 긴 이름을 만든다 — 하한 패턴이므로 그것도 통과한다.
        $seed = str_repeat('ab', INSTALLER_TMP_SUFFIX_BYTES).dechex((int) (microtime(true) * 1000));
        $this->assertSame(1, preg_match(INSTALLER_TMP_SUFFIX_PATTERN, $seed));

        // 주석 속 문장이 대조를 통과시키지 않도록 주석을 걷어낸 소스로 대조한다.
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/installer-runtime.php');
        $this->assertStringContainsString('INSTALLER_RUNTIME_TMP_INFIX.installerUniqueSuffix(INSTALLER_TMP_SUFFIX_BYTES)', $source, '생산자가 판정과 다른 바이트 수로 이름을 만든다');
        $this->assertStringContainsString('$mtime !== false && $mtime < time() - 300', $source, 'mtime 조회 실패를 「아주 오래됨」으로 읽어 지운다 (fail-open)');
    }
}
