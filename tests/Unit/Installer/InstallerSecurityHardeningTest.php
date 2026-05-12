<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ValidationApi;

/**
 * 인스톨러 추가 보안 강화 회귀 테스트
 *
 * 후속 감사로 추가 발견된 5개 이슈에 대한 회귀 가드:
 *  - getComposerCommand/Display 의 공백 분기 RCE
 *  - save-extensions 식별자 셸 주입
 *  - checkCorePendingPath 경로 traversal/정보 노출
 *  - escapeEnvValue 개행 라인 주입
 *  - PDO DSN 파라미터 주입
 */
class InstallerSecurityHardeningTest extends TestCase
{
    private static bool $loaded = false;
    private static string $tempBase = '';

    public static function setUpBeforeClass(): void
    {
        if (self::$loaded) {
            return;
        }

        require_once __DIR__ . '/stubs/lang_stub.php';

        if (! defined('MIN_PHP_VERSION')) {
            define('MIN_PHP_VERSION', '8.2.0');
        }
        if (! defined('CHECK_CONFIGURATION_LIBRARY')) {
            define('CHECK_CONFIGURATION_LIBRARY', true);
        }

        self::$tempBase = sys_get_temp_dir() . '/g7-installer-hardening-' . bin2hex(random_bytes(4));
        @mkdir(self::$tempBase . '/storage/app', 0755, true);

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', self::$tempBase);
        } else {
            self::$tempBase = BASE_PATH;
            @mkdir(BASE_PATH . '/storage/app', 0755, true);
        }

        if (! defined('STATE_PATH')) {
            define('STATE_PATH', BASE_PATH . '/storage/installer-state.json');
        }

        require_once dirname(__DIR__, 3) . '/public/install/api/check-configuration.php';
        require_once dirname(__DIR__, 3) . '/public/install/includes/installer-state.php';
        require_once dirname(__DIR__, 3) . '/public/install/includes/functions.php';
        require_once dirname(__DIR__, 3) . '/public/install/includes/task-runner.php';

        self::$loaded = true;
    }

    private function invokePrivate(object $obj, string $method, array $args)
    {
        $ref = new ReflectionClass($obj);
        $m = $ref->getMethod($method);
        return $m->invokeArgs($obj, $args);
    }

    /**
     * 테스트가 도중 종료되어도 사용자 작업 디렉토리에 state.json 잔여물이 남지 않도록 항상 정리.
     * BASE_PATH 가 다른 테스트 인프라에 의해 프로젝트 루트로 바인딩되는 경우를 대비해
     * 프로젝트 루트의 storage/installer-state.json 도 함께 정리.
     */
    protected function tearDown(): void
    {
        @unlink(STATE_PATH);
        $projectStateFile = dirname(__DIR__, 3) . '/storage/installer-state.json';
        @unlink($projectStateFile);
        parent::tearDown();
    }

    /**
     * 테스트별 임시 state.json 작성/삭제 도우미.
     */
    private function writeState(array $config): void
    {
        $state = ['config' => $config];
        @file_put_contents(STATE_PATH, json_encode($state));
    }

    private function clearState(): void
    {
        @unlink(STATE_PATH);
    }

    // ========================================================================
    // Critical-1 — getComposerCommand 공백 분기 RCE 차단
    // ========================================================================

    public function test_getComposerCommand_rejects_space_containing_input_falls_back_to_composer(): void
    {
        $this->writeState(['composer_binary' => 'sh -c "id > /tmp/g7_should_not_run"']);

        $cmd = getComposerCommand();

        $this->assertSame('composer', $cmd, '공백 포함 입력은 시스템 기본 composer 로 폴백');
        $this->clearState();
    }

    public function test_getComposerCommand_rejects_shell_metachars_falls_back_to_composer(): void
    {
        foreach ([
            '/bin/sh; touch /tmp/x',
            '/bin/composer && id',
            '$(id)',
            '`id`',
            "/bin/composer\nrm -rf /",
        ] as $payload) {
            $this->writeState(['composer_binary' => $payload]);
            $cmd = getComposerCommand();
            $this->assertSame('composer', $cmd, "셸 메타문자 포함 입력 거부 (입력: {$payload})");
        }
        $this->clearState();
    }

    public function test_getComposerCommand_rejects_nonexistent_path(): void
    {
        $this->writeState(['composer_binary' => '/nonexistent/path/to/composer']);

        $cmd = getComposerCommand();

        $this->assertSame('composer', $cmd);
        $this->clearState();
    }

    public function test_getComposerCommand_empty_returns_default(): void
    {
        $this->writeState(['composer_binary' => '']);

        $this->assertSame('composer', getComposerCommand());
        $this->clearState();
    }

    public function test_getComposerCommandForDisplay_does_not_leak_shell_payload(): void
    {
        $this->writeState(['composer_binary' => 'rm -rf /']);

        $display = getComposerCommandForDisplay();

        $this->assertSame('composer', $display, '셸 페이로드는 디스플레이에도 노출되지 않음');
        $this->assertStringNotContainsString('rm', $display);
        $this->clearState();
    }

    public function test_isInstallerExecutablePath_rejects_metachars(): void
    {
        foreach (['foo bar', 'a;b', 'a`b', 'a$b', 'a|b', "a\nb", 'a"b', "a'b", 'a\\b'] as $bad) {
            $this->assertFalse(isInstallerExecutablePath($bad), "메타문자 거부: {$bad}");
        }
    }

    // ========================================================================
    // High-2 — checkCorePendingPath traversal 차단
    // ========================================================================

    public function test_checkCorePendingPath_rejects_parent_traversal(): void
    {
        $api = new ValidationApi();

        foreach (['../../../etc', '..', 'foo/../bar', 'foo/..\\bar'] as $bad) {
            $_GET = ['path' => $bad];
            ob_start();
            $this->invokePrivate($api, 'checkCorePendingPath', []);
            $body = ob_get_clean();
            $decoded = json_decode($body, true);

            $this->assertIsArray($decoded);
            $this->assertFalse($decoded['success'], "traversal 거부: {$bad}");
            // 단일 통일 메시지로 enumeration 신호 차단
            $this->assertSame('error_core_pending_path_invalid', $decoded['message']);
        }
        $_GET = [];
    }

    public function test_checkCorePendingPath_rejects_null_byte(): void
    {
        $api = new ValidationApi();
        $_GET = ['path' => "valid\0../etc"];
        ob_start();
        $this->invokePrivate($api, 'checkCorePendingPath', []);
        $body = ob_get_clean();
        $decoded = json_decode($body, true);

        $this->assertFalse($decoded['success']);
        $_GET = [];
    }

    public function test_checkCorePendingPath_returns_uniform_message_for_nonexistent(): void
    {
        $api = new ValidationApi();
        $_GET = ['path' => '/nonexistent/g7-test-' . bin2hex(random_bytes(4))];
        ob_start();
        $this->invokePrivate($api, 'checkCorePendingPath', []);
        $body = ob_get_clean();
        $decoded = json_decode($body, true);

        $this->assertFalse($decoded['success']);
        // 존재 여부/타입 차이를 응답에 노출하지 않음
        $this->assertSame('error_core_pending_path_invalid', $decoded['message']);
        $_GET = [];
    }

    // ========================================================================
    // Medium-1 — escapeEnvValue 개행 제거
    // ========================================================================

    public function test_escapeEnvValue_strips_newlines(): void
    {
        $payload = "secret\nINJECTED=true";
        $result = escapeEnvValue($payload);

        // 핵심 보안 속성: 결과에 개행 문자가 없어야 한다 (라인 주입 차단).
        // INJECTED=true 가 따옴표 내부 일부로 포함되는 것은 문제 아님 — .env 파서는
        // 따옴표 닫힘 전까지 단일 값으로만 해석.
        $this->assertStringNotContainsString("\n", $result, 'LF 가 결과에 포함되면 안 됨');
        $this->assertStringNotContainsString("\r", $result);
        // 결과는 따옴표로 시작/종료 (라인 주입이 성립하려면 따옴표가 닫힌 뒤 개행이 와야 하나 개행이 제거됨)
        $this->assertStringStartsWith('"', $result);
        $this->assertStringEndsWith('"', $result);
    }

    public function test_escapeEnvValue_strips_crlf(): void
    {
        $result = escapeEnvValue("a\r\nb");

        $this->assertSame('"ab"', $result);
    }

    public function test_escapeEnvValue_preserves_normal_password(): void
    {
        $result = escapeEnvValue('p@ssw0rd!#$%');

        $this->assertSame('"p@ssw0rd!#$%"', $result);
    }

    public function test_escapeEnvValue_escapes_quotes_and_backslashes(): void
    {
        $result = escapeEnvValue('a"b\\c');

        $this->assertSame('"a\\"b\\\\c"', $result);
    }

    // ========================================================================
    // Medium-2 — PDO DSN 파라미터 sanitize
    // ========================================================================

    public function test_getDatabaseConnection_rejects_semicolon_in_host(): void
    {
        $this->expectException(\PDOException::class);

        getDatabaseConnection([
            'db_write_host' => 'localhost;injected=evil',
            'db_write_port' => '3306',
            'db_write_database' => 'testdb',
            'db_write_username' => 'root',
            'db_write_password' => '',
        ], false);
    }

    public function test_getDatabaseConnection_rejects_equals_in_database(): void
    {
        $this->expectException(\PDOException::class);

        getDatabaseConnection([
            'db_write_host' => 'localhost',
            'db_write_port' => '3306',
            'db_write_database' => 'test=evil',
            'db_write_username' => 'root',
            'db_write_password' => '',
        ], false);
    }

    public function test_getDatabaseConnection_rejects_newline_in_port(): void
    {
        $this->expectException(\PDOException::class);

        getDatabaseConnection([
            'db_write_host' => 'localhost',
            'db_write_port' => "3306\nfoo=bar",
            'db_write_database' => 'testdb',
            'db_write_username' => 'root',
            'db_write_password' => '',
        ], false);
    }
}
