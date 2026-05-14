<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 세션 쿠키 round-trip 진단 endpoint 회귀 테스트
 *
 * 브라우저가 PHPSESSID 쿠키를 보내지 않아 인스톨러가 Step 0 무한 루프에 빠지는
 * 케이스를 사전 감지하기 위한 endpoint. set 으로 세션에 nonce 저장 후 verify 로
 * 같은 세션에서 nonce 가 복원되는지 확인.
 */
class SessionProbeTest extends TestCase
{
    private static bool $loaded = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$loaded) {
            return;
        }

        require_once __DIR__ . '/stubs/lang_stub.php';

        $projectRoot = dirname(__DIR__, 3);
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }
        if (! defined('STATE_PATH')) {
            define('STATE_PATH', sys_get_temp_dir() . '/g7-session-probe-test-' . bin2hex(random_bytes(4)) . '.json');
        }
        if (! defined('SESSION_PROBE_LIBRARY')) {
            define('SESSION_PROBE_LIBRARY', true);
        }

        require_once $projectRoot . '/public/install/includes/functions.php';
        require_once $projectRoot . '/public/install/api/session-probe.php';

        self::$loaded = true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // 각 테스트 시작 시 세션 상태 초기화
        $_SESSION = [];
    }

    public function test_set_action_generates_nonce_and_stores_in_session(): void
    {
        $result = sessionProbeSet();

        $this->assertArrayHasKey('action', $result);
        $this->assertSame('set', $result['action']);
        $this->assertArrayHasKey('nonce', $result);
        $this->assertIsString($result['nonce']);
        // 32 hex chars (random_bytes(16))
        $this->assertSame(32, strlen($result['nonce']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['nonce']);

        // 세션에 저장 확인
        $this->assertArrayHasKey('_installer_session_probe', $_SESSION);
        $this->assertSame($result['nonce'], $_SESSION['_installer_session_probe']);
    }

    public function test_verify_action_matches_when_session_preserved(): void
    {
        $setResult = sessionProbeSet();
        $verifyResult = sessionProbeVerify();

        $this->assertSame('verify', $verifyResult['action']);
        $this->assertTrue($verifyResult['matched']);
        $this->assertSame($setResult['nonce'], $verifyResult['nonce'] ?? null);
    }

    public function test_verify_action_returns_unmatched_when_session_empty(): void
    {
        // set 호출 없이 verify — 세션 쿠키가 round-trip 되지 않은 시뮬레이션
        $verifyResult = sessionProbeVerify();

        $this->assertSame('verify', $verifyResult['action']);
        $this->assertFalse($verifyResult['matched']);
    }

    public function test_set_generates_distinct_nonces_on_repeated_calls(): void
    {
        $first = sessionProbeSet();
        $second = sessionProbeSet();

        // 매 호출마다 새 nonce — 이전 호출의 nonce 가 캐시되지 않음
        $this->assertNotSame($first['nonce'], $second['nonce']);

        // 세션에는 마지막 set 의 nonce 만 남음
        $this->assertSame($second['nonce'], $_SESSION['_installer_session_probe']);
    }

    public function test_verify_action_consumes_nonce(): void
    {
        sessionProbeSet();
        sessionProbeVerify();

        // 한 번 verify 한 nonce 는 재사용 방지를 위해 세션에서 제거되어야 함
        $this->assertArrayNotHasKey('_installer_session_probe', $_SESSION);

        // 다음 verify 는 세션 빔 케이스와 동일하게 unmatched
        $secondVerify = sessionProbeVerify();
        $this->assertFalse($secondVerify['matched']);
    }
}
