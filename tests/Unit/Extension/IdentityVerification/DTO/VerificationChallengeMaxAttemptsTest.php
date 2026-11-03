<?php

namespace Tests\Unit\Extension\IdentityVerification\DTO;

use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use Carbon\Carbon;
use Tests\PureTestCase;

/**
 * VerificationChallenge DTO 의 maxAttempts 필드 회귀 차단.
 *
 * 이슈 #275 — DB 의 max_attempts 가 환경설정값으로 정상 저장되었음에도 응답 페이로드에
 * 노출되지 않아 프론트 launcher 가 maxAttempts:5 하드코딩으로 fallback 하여 사용자
 * 모달에 "5회 남음" 으로 표시되던 결함을 차단한다.
 *
 * DTO 가 maxAttempts 필드를 보유 + toArray() 가 max_attempts 키로 노출하는지 검증.
 */
class VerificationChallengeMaxAttemptsTest extends PureTestCase
{
    /**
     * named argument 로 넘긴 maxAttempts 값이 DTO 필드에 그대로 담긴다.
     *
     * @scenario case=named_argument
     *
     * @effects max_attempts_field_holds_given_value
     *
     * @return void 반환값 없음
     */
    public function test_dto_accepts_max_attempts_via_named_argument(): void
    {
        $challenge = new VerificationChallenge(
            id: 'uuid-1',
            providerId: 'g7:core.mail',
            purpose: 'signup',
            channel: 'email',
            targetHash: hash('sha256', 'user@example.com'),
            expiresAt: Carbon::parse('2026-05-14T10:00:00+00:00'),
            renderHint: 'text_code',
            maxAttempts: 12,
        );

        $this->assertSame(12, $challenge->maxAttempts);
    }

    /**
     * maxAttempts 를 지정하지 않은 popup/SDK 형 provider 호출에서도 기본값 0 으로 생성된다.
     *
     * @scenario case=default_value
     *
     * @effects max_attempts_defaults_to_zero
     *
     * @return void 반환값 없음
     */
    public function test_dto_defaults_max_attempts_to_zero(): void
    {
        // 기본값 0 — popup/SDK 형 provider (이니시스 등) 가 named args 로 호출 시 별도 지정 없이도 동작
        $challenge = new VerificationChallenge(
            id: 'uuid-2',
            providerId: 'inicis',
            purpose: 'signup',
            channel: 'ipin',
            targetHash: hash('sha256', 'user@example.com'),
            expiresAt: Carbon::now(),
            renderHint: 'text_code',
        );

        $this->assertSame(0, $challenge->maxAttempts);
    }

    /**
     * toArray() 응답 페이로드가 max_attempts 키로 시도 한도를 노출한다.
     *
     * @scenario case=to_array_payload
     *
     * @effects max_attempts_exposed_in_payload
     *
     * @return void 반환값 없음
     */
    public function test_to_array_exposes_max_attempts_key(): void
    {
        $challenge = new VerificationChallenge(
            id: 'uuid-3',
            providerId: 'g7:core.mail',
            purpose: 'signup',
            channel: 'email',
            targetHash: hash('sha256', 'a@b.com'),
            expiresAt: Carbon::parse('2026-05-14T11:00:00+00:00'),
            renderHint: 'text_code',
            maxAttempts: 7,
        );

        $arr = $challenge->toArray();

        $this->assertArrayHasKey('max_attempts', $arr);
        $this->assertSame(7, $arr['max_attempts']);
    }
}
