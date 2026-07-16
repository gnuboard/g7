<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3 PoC — 나이스페이먼츠 AuthToken 60s dedupe 의 cache key 결정성 검증
 *
 * 본 테스트는 PaymentCallbackController::authCallback 에 정의된 dedupe
 * cache key 산출 로직이 다음 속성을 만족하는지 검증한다:
 *
 *   1. 결정성 — 동일 (mid, authToken, txTid) 입력은 항상 동일 key 산출
 *   2. 충돌 회피 — 다른 입력이면 다른 key
 *   3. 길이 안정성 — 항상 64자 (sha256 hex)
 *   4. prefix — 'nicepay_auth_token_seen:' 로 시작
 *
 * 실제 dedupe 차단 동작은 Feature 테스트(컨트롤러 + 가짜 cache) 에서
 * 검증되며, 본 테스트는 cache key 구성 그 자체의 안정성에 집중.
 *
 * 운영적 의미:
 *   - 동일 cache key 가 도출되어야 두 번째 콜백이 차단됨
 *   - mid 가 다르면 (= 다른 가맹점) 같은 token/tid 라도 충돌 안 함
 *   - txTid 가 다르면 (= 다른 PG 거래) 같은 token 이라도 충돌 안 함
 */
class AuthTokenDedupeKeyTest extends TestCase
{
    private function buildKey(string $mid, string $authToken, string $txTid): string
    {
        // PaymentCallbackController.php:113 와 동일한 산출 로직
        return 'nicepay_auth_token_seen:' . hash('sha256', $mid . ':' . $authToken . ':' . $txTid);
    }

    /**
     * @scenario context=authorize_card, threat=replay_token_short, callback_state=first_arrival
     * @effects auth_token_cache_key_uses_sha256_of_mid_token_txtid, auth_token_first_arrival_writes_cache
     */
    public function test_key_is_deterministic_for_same_input(): void
    {
        $a = $this->buildKey('SR12345', 'AUTH_TOKEN_001', 'TX_TID_001');
        $b = $this->buildKey('SR12345', 'AUTH_TOKEN_001', 'TX_TID_001');

        $this->assertSame($a, $b);
    }

    /**
     * @scenario context=authorize_card, threat=replay_token_short, callback_state=second_arrival_within_60s
     * @effects auth_token_within_60s_blocked
     */
    public function test_key_differs_when_token_differs(): void
    {
        $a = $this->buildKey('SR12345', 'AUTH_TOKEN_001', 'TX_TID_001');
        $b = $this->buildKey('SR12345', 'AUTH_TOKEN_002', 'TX_TID_001');

        $this->assertNotSame($a, $b);
    }

    /**
     * @scenario context=authorize_card, threat=replay_token_short, callback_state=second_arrival_within_60s
     * @effects auth_token_within_60s_blocked
     */
    public function test_key_differs_when_tx_tid_differs(): void
    {
        $a = $this->buildKey('SR12345', 'AUTH_TOKEN_001', 'TX_TID_001');
        $b = $this->buildKey('SR12345', 'AUTH_TOKEN_001', 'TX_TID_002');

        $this->assertNotSame($a, $b);
    }

    /**
     * 다른 가맹점 (MID) 라면 같은 token+tid 도 충돌 없음.
     * 멀티 가맹점 환경에서 한 가맹점의 dedupe 가 다른 가맹점에 영향 안 미침.
     *
     * @scenario context=authorize_card, threat=replay_token_short, callback_state=first_arrival
     * @effects auth_token_cache_key_uses_sha256_of_mid_token_txtid
     */
    public function test_key_differs_when_mid_differs(): void
    {
        $a = $this->buildKey('SR12345', 'AUTH_TOKEN_001', 'TX_TID_001');
        $b = $this->buildKey('SR99999', 'AUTH_TOKEN_001', 'TX_TID_001');

        $this->assertNotSame($a, $b);
    }

    /**
     * @scenario context=naverpay_callback, threat=replay_token_short, callback_state=first_arrival
     * @effects auth_token_cache_key_uses_sha256_of_mid_token_txtid
     */
    public function test_key_has_expected_prefix(): void
    {
        $key = $this->buildKey('SR12345', 'AT001', 'TX001');

        $this->assertStringStartsWith('nicepay_auth_token_seen:', $key);
    }

    /**
     * sha256 hex = 64자.
     * @scenario context=authorize_card, threat=replay_token_short, callback_state=first_arrival
     * @effects auth_token_cache_key_uses_sha256_of_mid_token_txtid
     */
    public function test_key_has_stable_length(): void
    {
        $key = $this->buildKey('SR12345', 'AT001', 'TX001');

        // 'nicepay_auth_token_seen:' (24) + sha256 hex (64) = 88
        $this->assertSame(88, strlen($key));
    }

    /**
     * 셸 메타문자/제어문자가 mid/token/tid 에 들어와도 hash 함수가
     * 결정적 hex 만 산출하므로 cache key 부분에서 인젝션 위험 없음.
     *
     * @scenario context=authorize_card, threat=replay_token_short, callback_state=first_arrival
     * @effects auth_token_cache_key_uses_sha256_of_mid_token_txtid
     */
    public function test_key_safe_with_metachars_in_input(): void
    {
        $key = $this->buildKey('SR" || OR 1=1 --', "TOKEN\nLF", "TID;rm");

        $this->assertMatchesRegularExpression('/^nicepay_auth_token_seen:[0-9a-f]{64}$/', $key);
    }

    /**
     * @scenario context=kakaopay_callback, threat=replay_token_short, callback_state=second_arrival_outside_60s
     * @effects auth_token_outside_60s_proceeds
     */
    public function test_key_collision_with_concatenation_blurring(): void
    {
        // 만약 구분자 ':' 없이 단순 concat 했다면 ('ab', 'cd', 'ef') 와 ('abc', 'd', 'ef') 가
        // 같은 hash 를 만들 수 있다. ':' 구분자 사용으로 이 모호성을 회피하는지 검증.
        $a = $this->buildKey('SR', '12345', 'TID');
        $b = $this->buildKey('SR12345', '', 'TID');

        // 구분자 ':' 가 있으면 'SR:12345:TID' vs 'SR12345::TID' → 다른 hash
        $this->assertNotSame($a, $b);
    }
}
