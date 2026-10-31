<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 세션 쿠키 round-trip 진단 endpoint 회귀 테스트
 *
 * 브라우저가 PHPSESSID 쿠키를 보내지 않아 인스톨러가 Step 0 무한 루프에 빠지는
 * 케이스를 사전 감지하기 위한 endpoint. set 으로 세션에 nonce 저장 후 verify 로
 * 같은 세션에서 nonce 가 복원되는지 확인.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class SessionProbeTest extends TestCase
{
    private static bool $loaded = false;

    /**
     * 클래스 시작 때 한 번 언어 스텁과 기준 경로, 임시 상태 파일 경로, 라이브러리 모드 상수를 정하고 설치기 공용 함수와 세션 진단 소스를 읽어 들인다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        if (self::$loaded) {
            return;
        }

        require_once __DIR__.'/stubs/lang_stub.php';

        $projectRoot = dirname(__DIR__, 3);
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }
        if (! defined('STATE_PATH')) {
            define('STATE_PATH', sys_get_temp_dir().'/g7-session-probe-test-'.bin2hex(random_bytes(4)).'.json');
        }
        if (! defined('SESSION_PROBE_LIBRARY')) {
            define('SESSION_PROBE_LIBRARY', true);
        }

        require_once $projectRoot.'/public/install/includes/functions.php';
        require_once $projectRoot.'/public/install/api/session-probe.php';

        self::$loaded = true;
    }

    /**
     * 각 테스트 전에 세션 배열을 비워 이전 테스트의 nonce 가 남지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 각 테스트 시작 시 세션 상태 초기화
        $_SESSION = [];
    }

    /**
     * set 동작이 32자리 16진 nonce 를 만들어 응답에 싣고 같은 값을 세션의 진단 키에 저장하는지 확인한다.
     *
     * @scenario session_state=set_called
     *
     * @effects set_action_generates_32_hex_nonce, set_action_stores_nonce_in_installer_session_probe_key
     *
     * @return void 반환값 없음
     */
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

    /**
     * set 뒤 같은 세션으로 verify 하면 일치로 판정하고 set 때의 nonce 를 돌려주는지 확인한다.
     *
     * @scenario session_state=set_then_verify_called, cookie_round_trip=success
     *
     * @effects verify_action_returns_matched_true_when_session_preserved
     *
     * @return void 반환값 없음
     */
    public function test_verify_action_matches_when_session_preserved(): void
    {
        $setResult = sessionProbeSet();
        $verifyResult = sessionProbeVerify();

        $this->assertSame('verify', $verifyResult['action']);
        $this->assertTrue($verifyResult['matched']);
        $this->assertSame($setResult['nonce'], $verifyResult['nonce'] ?? null);
    }

    /**
     * set 없이 빈 세션으로 verify 하면 쿠키가 왕복하지 않은 경우처럼 불일치로 판정하는지 확인한다.
     *
     * @scenario session_state=empty, cookie_round_trip=blocked
     *
     * @effects verify_action_returns_matched_false_when_session_empty
     *
     * @return void 반환값 없음
     */
    public function test_verify_action_returns_unmatched_when_session_empty(): void
    {
        // set 호출 없이 verify — 세션 쿠키가 round-trip 되지 않은 시뮬레이션
        $verifyResult = sessionProbeVerify();

        $this->assertSame('verify', $verifyResult['action']);
        $this->assertFalse($verifyResult['matched']);
    }

    /**
     * set 을 두 번 부르면 매번 다른 nonce 가 나오고 세션에는 마지막 값만 남는지 확인한다.
     *
     * @scenario session_state=set_called
     *
     * @effects set_action_issues_a_fresh_nonce_on_every_call
     *
     * @return void 반환값 없음
     */
    public function test_set_generates_distinct_nonces_on_repeated_calls(): void
    {
        $first = sessionProbeSet();
        $second = sessionProbeSet();

        // 매 호출마다 새 nonce — 이전 호출의 nonce 가 캐시되지 않음
        $this->assertNotSame($first['nonce'], $second['nonce']);

        // 세션에는 마지막 set 의 nonce 만 남음
        $this->assertSame($second['nonce'], $_SESSION['_installer_session_probe']);
    }

    /**
     * 한 번 verify 한 nonce 가 세션에서 지워지고 두 번째 verify 는 불일치로 판정되는지 확인한다.
     *
     * @scenario session_state=set_then_verify_called, cookie_round_trip=success
     *
     * @effects verify_action_consumes_nonce_after_first_call, second_verify_after_consumption_returns_matched_false
     *
     * @return void 반환값 없음
     */
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
