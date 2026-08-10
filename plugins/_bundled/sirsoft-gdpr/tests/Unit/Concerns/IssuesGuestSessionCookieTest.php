<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Concerns;

use Plugins\Sirsoft\Gdpr\Concerns\IssuesGuestSessionCookie;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * IssuesGuestSessionCookie 트레이트 테스트
 *
 * gdpr_session 쿠키의 HMAC 서명 발급/검증 — 위조된 session_id 를 신뢰하지 않는지 검증.
 */
class IssuesGuestSessionCookieTest extends PluginTestCase
{
    /**
     * @return object 트레이트를 사용하는 익명 클래스 인스턴스
     */
    private function subject(): object
    {
        return new class
        {
            use IssuesGuestSessionCookie;

            public function sign(string $sessionId): string
            {
                return $this->signGuestSessionId($sessionId);
            }

            public function verify(?string $cookieValue): ?string
            {
                return $this->verifyGuestSessionId($cookieValue);
            }
        };
    }

    public function test_signed_cookie_value_round_trips_to_original_session_id(): void
    {
        $subject = $this->subject();
        $sessionId = '11111111-2222-3333-4444-555555555555';

        $signed = $subject->sign($sessionId);
        $verified = $subject->verify($signed);

        $this->assertSame($sessionId, $verified);
    }

    public function test_tampered_session_id_with_stale_signature_is_rejected(): void
    {
        $subject = $this->subject();
        $signed = $subject->sign('11111111-2222-3333-4444-555555555555');

        [, $expiresTs, $signature] = explode('|', $signed, 3);
        $tampered = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee|'.$expiresTs.'|'.$signature;

        $this->assertNull($subject->verify($tampered));
    }

    public function test_tampered_expiry_with_stale_signature_is_rejected(): void
    {
        // 만료시각만 늘려서 서명 유효기간을 연장하려는 위조 시도 — 서명이 session_id
        // 뿐 아니라 expiresTs 도 함께 서명하므로 expiresTs 변조 시 서명 불일치로 거부.
        $subject = $this->subject();
        $signed = $subject->sign('11111111-2222-3333-4444-555555555555');

        [$sessionId, $expiresTs, $signature] = explode('|', $signed, 3);
        $tampered = $sessionId.'|'.((int) $expiresTs + 3600).'|'.$signature;

        $this->assertNull($subject->verify($tampered));
    }

    public function test_expired_signature_is_rejected(): void
    {
        // 만료시각이 과거인 서명(정상 서명이지만 유효기간 경과)은 거부되어야 한다.
        $subject = $this->subject();
        $sessionId = '11111111-2222-3333-4444-555555555555';
        $pastExpiresTs = time() - 1;

        $signMethod = new \ReflectionMethod($subject, 'computeGuestSessionSignature');
        $signMethod->setAccessible(true);
        $signature = $signMethod->invoke($subject, $sessionId, $pastExpiresTs);

        $expiredValue = $sessionId.'|'.$pastExpiresTs.'|'.$signature;

        $this->assertNull($subject->verify($expiredValue));
    }

    public function test_arbitrary_unsigned_value_is_rejected(): void
    {
        $subject = $this->subject();

        $this->assertNull($subject->verify('just-a-random-string'));
    }

    public function test_empty_or_null_value_is_rejected(): void
    {
        $subject = $this->subject();

        $this->assertNull($subject->verify(null));
        $this->assertNull($subject->verify(''));
    }

    public function test_malformed_signature_format_is_rejected(): void
    {
        $subject = $this->subject();

        // 서명 길이가 64자 hex 가 아님
        $this->assertNull($subject->verify('11111111-2222-3333-4444-555555555555|not-a-valid-signature'));
    }

    public function test_signature_computed_with_different_app_key_is_rejected(): void
    {
        $subject = $this->subject();
        $sessionId = '11111111-2222-3333-4444-555555555555';

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $signed = $subject->sign($sessionId);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertNull($subject->verify($signed));
    }
}
