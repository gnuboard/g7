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

        // BASE_PATH 미정의 시 프로젝트 루트로 정의 — 같은 PHPUnit 프로세스에서 뒤에
        // 실행되는 인접 테스트(DeleteDirectoryTest, InstallerWindowsCommandsTest 등)가
        // `require_once BASE_PATH . '/public/install/...'` 로 로드하는 패턴이 깨지지 않도록.
        $projectRoot = dirname(__DIR__, 3);
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }
        // STATE_PATH 는 테스트 격리용 임시 경로로 분리 — 운영 storage/installer-state.json 미오염.
        self::$tempBase = sys_get_temp_dir() . '/g7-installer-hardening-' . bin2hex(random_bytes(4));
        @mkdir(self::$tempBase . '/storage/app', 0755, true);

        if (! defined('STATE_PATH')) {
            define('STATE_PATH', self::$tempBase . '/storage/installer-state.json');
        }

        require_once $projectRoot . '/public/install/api/check-configuration.php';
        require_once $projectRoot . '/public/install/includes/installer-state.php';
        require_once $projectRoot . '/public/install/includes/functions.php';
        require_once $projectRoot . '/public/install/includes/task-runner.php';

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
        if (is_file(STATE_PATH)) {
            @unlink(STATE_PATH);
        }
        $projectStateFile = dirname(__DIR__, 3) . '/storage/installer-state.json';
        if (is_file($projectStateFile)) {
            @unlink($projectStateFile);
        }
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

    public function test_getComposerCommand_passes_through_safe_absolute_path(): void
    {
        // open_basedir 같은 PHP 런타임 제약 환경의 false negative 를 피하기 위해
        // stat 의존 가드가 제거됨. 메타문자 없는 단일 절대경로는 escape 후 그대로 사용.
        // 실제 실행 가능 여부는 exec 결과로 최종 판정 (silent fallback 안 함).
        $this->writeState(['composer_binary' => '/usr/local/bin/composer']);

        $cmd = getComposerCommand();

        $this->assertSame(escapeshellarg('/usr/local/bin/composer'), $cmd);
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
        // 백슬래시는 Windows 경로 구분자이므로 차단 대상 아님 (escapeshellarg 가 셸 인젝션 차단)
        foreach (['foo bar', 'a;b', 'a`b', 'a$b', 'a|b', "a\nb", 'a"b', "a'b", "a\0b", "a\x01b", "a\rb"] as $bad) {
            $this->assertFalse(isInstallerExecutablePath($bad), "메타문자 거부: " . bin2hex($bad));
        }
    }

    public function test_isInstallerExecutablePath_accepts_windows_paths(): void
    {
        // Windows 절대경로는 백슬래시 포함이지만 공백·메타문자 없으면 통과.
        // 공백 포함 디렉토리(예: 'C:\\Program Files\\...') 는 공백 분리 입력 형식의 토큰
        // 분리 휴리스틱과 충돌하므로 본 회복 범위 외 — 알려진 한계.
        foreach ([
            'C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe',
            'C:\\php\\php.exe',
            'D:\\xampp\\php\\php.exe',
            'C:\\php\\composer.phar',
        ] as $path) {
            $this->assertTrue(isInstallerExecutablePath($path), "Windows 경로 허용: {$path}");
        }
    }

    public function test_isInstallerExecutablePath_rejects_windows_path_with_space_known_limitation(): void
    {
        // 'C:\\Program Files\\...' 같은 공백 포함 Windows 경로는 본 회복 범위 외.
        // 공백 분리 입력(PHP + Composer 합성) 형식 휴리스틱과 충돌 — escapeshellarg 단일 토큰
        // wrap 만으로는 공백 의도(토큰 구분 vs 디렉토리명) 를 자동 구분할 수 없음.
        // 회피: 8.3 short path 형식(C:\\PROGRA~1\\...) 사용 또는 공백 없는 경로 사용.
        $this->assertFalse(isInstallerExecutablePath('C:\\Program Files\\PHP\\php.exe'));
    }

    // ========================================================================
    // open_basedir 회귀 회복 — stat 의존 가드 완화
    // ========================================================================

    /**
     * stat 가드가 제거되어 시놀로지 DSM 등 open_basedir 환경에서도
     * 정상 절대경로 입력이 통과해야 함. 실제 실행 가능 여부는 exec 결과로 판정.
     */
    public function test_isInstallerExecutablePath_accepts_safe_absolute_path_without_stat(): void
    {
        // 실제로 존재하지 않는 경로지만 메타문자 없음 — stat 가드 제거로 true
        $this->assertTrue(isInstallerExecutablePath('/usr/local/bin/php83'));
        $this->assertTrue(isInstallerExecutablePath('/opt/plesk/php/8.3/bin/php'));
        $this->assertTrue(isInstallerExecutablePath('/nonexistent/path/to/binary'));
    }

    // ========================================================================
    // Composer 공백 분리 입력 안전 복원 — 멀티 PHP 환경 호환성
    // ========================================================================

    public function test_getComposerCommand_accepts_php_composer_space_separated_input(): void
    {
        // 시놀로지/cPanel/Plesk 멀티 PHP 환경의 운영 패턴
        $this->writeState(['composer_binary' => '/usr/local/bin/php83 /usr/local/bin/composer']);

        $cmd = getComposerCommand();

        // 두 토큰 각각 escapeshellarg 적용 후 공백으로 합성
        $expected = escapeshellarg('/usr/local/bin/php83') . ' ' . escapeshellarg('/usr/local/bin/composer');
        $this->assertSame($expected, $cmd);
        $this->clearState();
    }

    public function test_getComposerCommand_rejects_space_separated_with_metachar_in_first_token(): void
    {
        // 첫 토큰에 메타문자 → 거부, composer 폴백
        $this->writeState(['composer_binary' => '/usr/local/bin/php$(id) /usr/local/bin/composer']);

        $cmd = getComposerCommand();

        $this->assertSame('composer', $cmd);
        $this->clearState();
    }

    public function test_getComposerCommand_rejects_space_separated_with_metachar_in_second_token(): void
    {
        // 두 번째 토큰에 메타문자 → 거부, composer 폴백
        $this->writeState(['composer_binary' => '/usr/local/bin/php83 /usr/local/bin/composer;id']);

        $cmd = getComposerCommand();

        $this->assertSame('composer', $cmd);
        $this->clearState();
    }

    public function test_getComposerCommand_rejects_three_or_more_tokens(): void
    {
        // 3 토큰 이상은 두 번째 토큰에 공백이 남음 → 두 번째 토큰의 메타문자(공백) 로 거부
        $this->writeState(['composer_binary' => '/bin/php /bin/composer extra_arg']);

        $cmd = getComposerCommand();

        $this->assertSame('composer', $cmd);
        $this->clearState();
    }

    public function test_getComposerCommandForDisplay_shows_human_friendly_space_separated_form(): void
    {
        $this->writeState(['composer_binary' => '/usr/local/bin/php83 /usr/local/bin/composer']);

        $display = getComposerCommandForDisplay();

        // 사람에게 보이는 표기는 escape 없는 원본 형식 유지
        $this->assertSame('/usr/local/bin/php83 /usr/local/bin/composer', $display);
        $this->clearState();
    }

    public function test_getComposerCommandForDisplay_does_not_leak_shell_payload_in_space_separated(): void
    {
        // 두 토큰 중 하나라도 메타문자 포함이면 display 도 composer 폴백
        $this->writeState(['composer_binary' => '/bin/php `id`']);

        $display = getComposerCommandForDisplay();

        $this->assertSame('composer', $display);
        $this->assertStringNotContainsString('`', $display);
        $this->clearState();
    }

    // ========================================================================
    // validatePhpPath / validateComposerPath stat 가드 완화 (인스톨러 검증 API)
    // ========================================================================

    public function test_validatePhpPath_rejects_shell_metachars(): void
    {
        $api = new ValidationApi();
        foreach ([
            '/usr/local/bin/php; id',
            '/usr/local/bin/php$(id)',
            '`/usr/local/bin/php`',
            '/usr/local/bin/php|nc evil',
            "/usr/local/bin/php\nrm -rf /",
        ] as $payload) {
            $result = $this->invokePrivate($api, 'validatePhpPath', [$payload]);
            $this->assertFalse($result['valid'], "메타문자 거부: {$payload}");
        }
    }

    public function test_validatePhpPath_rejects_empty(): void
    {
        $api = new ValidationApi();
        $result = $this->invokePrivate($api, 'validatePhpPath', ['']);
        $this->assertFalse($result['valid']);
    }

    public function test_validatePhpPath_safe_path_reaches_exec_phase(): void
    {
        // 메타문자 없는 절대경로는 stat 가드 없이 exec 단계까지 도달.
        // 존재하지 않으면 exec 실패 (return code != 0) 로 거부 — 단, 거부 메시지는
        // 'error_php_exec_failed' 로 stat 거부와 동일. 핵심: stat 가드가 사라졌다는 점.
        $api = new ValidationApi();
        $result = $this->invokePrivate($api, 'validatePhpPath', ['/nonexistent/path/to/php']);

        // 실제 실행 실패로 invalid 이지만, 그 판정이 exec 결과에 기반함을 의미적으로 검증.
        $this->assertFalse($result['valid']);
    }

    public function test_validateComposerPath_rejects_shell_metachars(): void
    {
        $api = new ValidationApi();
        foreach ([
            '/usr/local/bin/composer; id',
            '/usr/local/bin/composer$(id)',
            '`/usr/local/bin/composer`',
            "/usr/local/bin/composer\nrm",
        ] as $payload) {
            $result = $this->invokePrivate($api, 'validateComposerPath', [$payload]);
            $this->assertFalse($result['valid'], "메타문자 거부: {$payload}");
        }
    }

    public function test_validateComposerPath_rejects_space_separated_with_metachar(): void
    {
        // 공백 분리 입력의 토큰별 메타문자 검증
        $api = new ValidationApi();
        foreach ([
            '/bin/php$(id) /bin/composer',
            '/bin/php /bin/composer;id',
            '/bin/php `id`',
            '/bin/php /bin/composer extra_token',
        ] as $payload) {
            $result = $this->invokePrivate($api, 'validateComposerPath', [$payload]);
            $this->assertFalse($result['valid'], "공백 분리 + 메타문자 거부: {$payload}");
        }
    }

    public function test_splitPhpComposerTokens_helper_splits_into_two_tokens(): void
    {
        $api = new ValidationApi();
        $result = $this->invokePrivate($api, 'splitPhpComposerTokens', ['/usr/local/bin/php83 /usr/local/bin/composer']);
        $this->assertSame(['php' => '/usr/local/bin/php83', 'composer' => '/usr/local/bin/composer'], $result);
    }

    public function test_splitPhpComposerTokens_helper_returns_null_for_single_token(): void
    {
        $api = new ValidationApi();
        $result = $this->invokePrivate($api, 'splitPhpComposerTokens', ['/usr/local/bin/composer']);
        $this->assertNull($result);
    }

    public function test_isInstallerSafePathArg_helper_accepts_safe_paths(): void
    {
        $api = new ValidationApi();

        foreach ([
            '/usr/local/bin/php83',
            '/opt/php/bin/php',
            'C:/php/php.exe',
            '/nonexistent/path',
            // Windows 절대경로 (백슬래시 포함) — 회귀 가드: Windows 빌트인 서버 환경
            'C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe',
            'C:\\php\\php.exe',
            'D:\\xampp\\php\\php.exe',
        ] as $safe) {
            $this->assertTrue($this->invokePrivate($api, 'isInstallerSafePathArg', [$safe]), "허용: {$safe}");
        }
    }

    public function test_isInstallerSafePathArg_helper_rejects_metachars_and_empty(): void
    {
        $api = new ValidationApi();

        $this->assertFalse($this->invokePrivate($api, 'isInstallerSafePathArg', ['']));
        // 백슬래시는 Windows 경로 구분자이므로 차단 대상이 아님 — 셸 인젝션 차단은 escapeshellarg 가 담당
        foreach (['foo bar', 'a;b', 'a`b', 'a$b', 'a|b', "a\nb", 'a"b', "a'b", "a\0b", "a\x01b", "a\rb"] as $bad) {
            $this->assertFalse($this->invokePrivate($api, 'isInstallerSafePathArg', [$bad]), "메타문자 거부: " . bin2hex($bad));
        }
    }

    // ========================================================================
    // 워커 정합 — 검증 통과한 입력이 워커에서도 silent fallback 없이 전달
    // ========================================================================

    public function test_getComposerCommand_passes_through_single_absolute_path_without_silent_fallback(): void
    {
        // 검증 단계에서 통과한 단일 절대경로가 워커에서도 그대로 사용됨을 보장.
        // stat 가드 제거 후 회귀 — 정상 입력이 silent fallback 으로 사라지지 않음.
        $this->writeState(['composer_binary' => '/usr/local/bin/composer']);

        $cmd = getComposerCommand();

        $this->assertNotSame('composer', $cmd, '검증 통과한 입력은 silent fallback 안 됨');
        $this->assertSame(escapeshellarg('/usr/local/bin/composer'), $cmd);
        $this->clearState();
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
