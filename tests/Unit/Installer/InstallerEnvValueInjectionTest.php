<?php

namespace Tests\Unit\Installer;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `.env` 라인 주입 회귀 가드.
 *
 * 판정 방식이 이 테스트의 핵심이다 — 생산 소스에서 `escapeEnvValue(` 호출을 세지 않는다.
 * 그런 대조는 "판정은 잠겼고 배선은 아무도 안 본다" 로 끝난다(호출을 세는 규칙은 키가 하나
 * 늘어난 것을 영원히 모른다). 대신 **행위**로 고정한다: config/runtime 의 각 값에 `\n`·`"`·
 * `$1`·`\`·`${...}` 를 넣고 완성된 `.env` 를 Laravel 이 실제로 쓰는 파서(phpdotenv)로 되읽어
 * **키가 하나도 늘지 않았는지**를 본다. 어느 키가 원문 보간으로 남아 있든 새 키가 생기므로
 * 배선 누락이 그대로 red 가 된다.
 *
 * 효과 요약(마커 아님 — 평문): 환경 파일 쓰기의 라인 주입 안전성. 표식은 각 테스트 메서드에 있다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerEnvValueInjectionTest extends TestCase
{
    private string $tempBase = '';

    private string $skipReason = '';

    /**
     * 상태 파일과 런타임 파일 경로를 임시 폴더로 떼어 정하고 설치기 함수 파일들을 불러온다.
     *
     * 다른 테스트가 먼저 그 상수를 운영 경로로 정해 두었으면 운영 파일을 지키려고 건너뛴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $projectRoot = dirname(__DIR__, 3);
        $this->tempBase = sys_get_temp_dir().'/g7-env-injection-'.bin2hex(random_bytes(4));
        @mkdir($this->tempBase.'/storage/installer', 0755, true);

        // BASE_PATH 는 `.env.example` 을 읽기 위해 실제 저장소 루트여야 한다(읽기 전용).
        // 반면 상태/런타임 파일 경로는 반드시 temp 로 떼어 놓는다 — 그러지 못하면
        // 운영 storage/installer-state.json·runtime.php 를 건드리게 되므로 skip 한다.
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }
        if (! defined('STATE_PATH')) {
            define('STATE_PATH', $this->tempBase.'/installer-state.json');
        }
        if (! defined('INSTALLER_RUNTIME_PATH')) {
            define('INSTALLER_RUNTIME_PATH', $this->tempBase.'/storage/installer/runtime.php');
        }

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        foreach (['STATE_PATH' => STATE_PATH, 'INSTALLER_RUNTIME_PATH' => INSTALLER_RUNTIME_PATH] as $name => $path) {
            $resolved = realpath(dirname((string) $path)) ?: dirname((string) $path);
            if (strpos($resolved, $tempPrefix) !== 0) {
                $this->skipReason = $name.' ('.$path.') 가 시스템 temp 하위가 아님 — 다른 Installer '
                    .'테스트의 상수 정의가 선행됨. 운영 파일 파괴 방지를 위해 skip. 격리 실행: '
                    .'php vendor/bin/phpunit --filter=InstallerEnvValueInjectionTest';
                $this->markTestSkipped($this->skipReason);
            }
        }

        require_once __DIR__.'/stubs/lang_stub.php';
        require_once $projectRoot.'/public/install/includes/config.php';
        require_once $projectRoot.'/public/install/includes/installer-state.php';
        require_once $projectRoot.'/public/install/includes/functions.php';
        require_once $projectRoot.'/public/install/includes/installer-runtime.php';
    }

    /**
     * 건너뛰지 않은 경우에만 임시 상태 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->skipReason === '' && is_file(STATE_PATH)) {
            @unlink(STATE_PATH);
        }

        parent::tearDown();
    }

    /**
     * state.json 의 config 섹션을 temp 경로에 기록한다.
     *
     * @param  array<string, mixed>  $config  config 섹션
     * @return void 반환값 없음
     */
    private function writeState(array $config): void
    {
        @file_put_contents(STATE_PATH, json_encode(['config' => $config]));
    }

    /**
     * 완성된 `.env` 본문을 Laravel 이 쓰는 파서로 되읽는다.
     *
     * @param  string  $envContent  `.env` 본문
     * @return array<string, string|null> 키 → 값
     */
    private function parseEnv(string $envContent): array
    {
        return Dotenv::parse($envContent);
    }

    /**
     * 정상 입력(무해한 값) 조합.
     *
     * @return array<string, mixed> 설정 키별 무해한 값
     */
    private function benignConfig(): array
    {
        return [
            'db_write_host' => '127.0.0.1',
            'db_write_port' => '3306',
            'db_write_database' => 'g7db',
            'db_write_username' => 'g7user',
            'db_write_password' => 'g7pass',
            'db_prefix' => 'g7_',
            'use_read_db' => true,
            'db_read_host' => '127.0.0.2',
            'db_read_port' => '3307',
            'db_read_database' => 'g7read',
            'db_read_username' => 'g7reader',
            'db_read_password' => 'g7readpass',
            'app_name' => 'G7',
            'app_env' => 'production',
            'app_url' => 'https://example.com',
            'core_update_pending_path' => '/var/g7/pending',
            'core_update_github_url' => 'https://github.com/gnuboard/g7',
            'core_update_github_token' => 'ghp_token',
            'php_binary' => '/usr/bin/php',
            'composer_binary' => '/usr/local/bin/composer',
        ];
    }

    /**
     * 같은 키 조합에 라인 주입 페이로드를 채운 것.
     *
     * 각 값은 `.env` 문법에서 위험한 문자를 모두 담는다: 개행(라인 분리), `"`(따옴표 조기 종료),
     * `$1`·`\`(preg_replace 치환문자열 역참조/escape), `${...}`(phpdotenv 변수 보간).
     *
     * @return array<string, mixed> 설정 키별 주입 페이로드 값
     */
    private function injectedConfig(): array
    {
        $config = [];
        $index = 0;

        foreach ($this->benignConfig() as $key => $value) {
            if ($key === 'use_read_db') {
                $config[$key] = true;

                continue;
            }

            $index++;
            $config[$key] = $value."\"\nG7_INJECTED_{$index}=pwned\na\$1b\\c\${APP_KEY}";
        }

        return $config;
    }

    // ========================================================================
    // 보안 1 — generateEnvContent() 의 전 키가 escape 를 거친다
    // ========================================================================

    /**
     * 정상 설정으로 만든 환경 파일의 키를 기준으로 삼고, 주입 페이로드가 든 설정은 생성 단계에서 거부되는지 확인한다.
     *
     * @scenario task=env
     *
     * @effects installer_env_writes_are_injection_safe
     *
     * @return void 반환값 없음
     */
    public function test_generated_env_gains_no_keys_from_injected_config_values(): void
    {
        $this->writeState($this->benignConfig());
        $baseline = array_keys($this->parseEnv((string) generateEnvContent()));
        sort($baseline);

        $this->assertNotEmpty($baseline, '정상 입력으로도 `.env` 가 만들어지지 않았다');

        // 주입 페이로드는 **거부**된다 (KVE-2026-2042). 종전에는 개행을 조용히 지워
        // 키가 늘지 않는 것으로 막았는데, 그러면 운영자가 입력한 값과 저장된 값이
        // 달라진 사실이 어디에도 드러나지 않는다.
        $this->writeState($this->injectedConfig());

        try {
            generateEnvContent();
            $this->fail('주입 페이로드가 거부되지 않고 `.env` 가 생성됐다');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('line breaks', $e->getMessage());
        }
    }

    /**
     * 13차 감사가 제시한 정확한 재현 입력 두 건.
     *
     * @scenario task=env
     *
     * @effects installer_env_writes_are_injection_safe
     *
     * @return void 반환값 없음
     */
    public function test_generated_env_blocks_audit_reported_payloads(): void
    {
        $config = $this->benignConfig();
        $config['db_write_host'] = "127.0.0.1\nAPP_CONFIG_CACHE=/tmp/x.php";
        $config['app_name'] = "x\"\nAPP_DEBUG=true";
        $this->writeState($config);

        $this->expectException(\InvalidArgumentException::class);

        generateEnvContent();
    }

    /**
     * `${VAR}` 보간 — 다른 변수 값이 이 값으로 흘러들지 않는다.
     *
     * @scenario task=env
     *
     * @effects installer_env_writes_are_injection_safe
     *
     * @return void 반환값 없음
     */
    public function test_escaped_value_does_not_interpolate_other_variables(): void
    {
        $content = "SECRET=topsecret\nAPP_NAME=".escapeEnvValue('${SECRET}')."\n";

        $parsed = $this->parseEnv($content);

        $this->assertSame('${SECRET}', $parsed['APP_NAME'] ?? null);
    }

    // ========================================================================
    // 보안 2 — replaceEnvLine() 이 치환문자열 메타문자를 해석하지 않는다
    // ========================================================================

    /**
     * 정상 런타임 값의 병합은 키를 늘리지 않고, 주입 페이로드가 든 런타임 값은 병합 단계에서 거부되는지 확인한다.
     *
     * @scenario task=env
     *
     * @effects installer_env_writes_are_injection_safe
     *
     * @return void 반환값 없음
     */
    public function test_merged_env_gains_no_keys_from_injected_runtime_values(): void
    {
        $this->writeState($this->benignConfig());
        $envBase = (string) generateEnvContent();

        $benignRuntime = [
            'db' => [
                'write' => ['host' => 'h', 'port' => '3306', 'database' => 'd', 'username' => 'u', 'password' => 'p'],
                'read' => ['host' => 'rh', 'port' => '3306', 'database' => 'rd', 'username' => 'ru', 'password' => 'rp'],
                'prefix' => 'g7_',
            ],
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('x', 32))],
        ];

        $payload = "v\"\nG7_RUNTIME_INJECTED=pwned\na\$1b\\c\${APP_KEY}";
        $injectedRuntime = $benignRuntime;
        foreach (['write', 'read'] as $side) {
            foreach (['host', 'port', 'database', 'username', 'password'] as $field) {
                $injectedRuntime['db'][$side][$field] = $payload;
            }
        }
        $injectedRuntime['db']['prefix'] = $payload;

        $baseline = array_keys($this->parseEnv(mergeRuntimeIntoEnv($envBase, $benignRuntime)));
        sort($baseline);

        $this->assertContains('DB_WRITE_HOST', $baseline, '정상 runtime 병합이 동작하지 않았다');
        $this->assertNotContains('G7_RUNTIME_INJECTED', $baseline);

        // 주입 페이로드는 병합 단계에서도 **거부**된다 — 같은 관문(serializeEnvValue)을 지난다.
        try {
            mergeRuntimeIntoEnv($envBase, $injectedRuntime);
            $this->fail('runtime 주입 페이로드가 거부되지 않고 병합됐다');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('line breaks', $e->getMessage());
        }
    }

    /**
     * `$1`·`\` 를 담은 비밀번호가 손상 없이 `.env` 에 기록된다.
     *
     * preg_replace 의 치환문자열 해석이 남아 있으면 `a$1b\c` 가 `ab\c` 로 조용히 줄어들어
     * 설치는 성공으로 끝나고 첫 부팅의 DB 인증에서만 드러난다.
     *
     * @scenario task=env
     *
     * @effects installer_env_writes_are_injection_safe
     *
     * @return void 반환값 없음
     */
    public function test_password_with_backreference_metacharacters_round_trips(): void
    {
        $password = 'a$1b\\c${X}d\\\\e';

        $content = replaceEnvLine("DB_WRITE_PASSWORD=\n", 'DB_WRITE_PASSWORD', escapeEnvValue($password));
        $parsed = $this->parseEnv($content);

        $this->assertSame($password, $parsed['DB_WRITE_PASSWORD'] ?? null);
    }

    /**
     * 백슬래시 escape 가 붕괴하지 않는다 — 붕괴하면 보안 1 의 escape 자체가 무력화된다.
     *
     * @scenario task=env
     *
     * @effects installer_env_writes_are_injection_safe
     *
     * @return void 반환값 없음
     */
    public function test_replace_env_line_does_not_collapse_escape_backslashes(): void
    {
        $value = escapeEnvValue('a"b');

        $content = replaceEnvLine("APP_NAME=\n", 'APP_NAME', $value);

        $this->assertStringContainsString($value, $content, 'escape 결과가 치환 과정에서 변형됨');
        $this->assertSame('a"b', $this->parseEnv($content)['APP_NAME'] ?? null);
    }
}
