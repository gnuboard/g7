<?php

namespace Tests\Unit\Http\Resources\Identity;

use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Http\Resources\Identity\ChallengeResource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ChallengeResource 의 max_attempts 응답 노출 회귀 차단.
 *
 * 이슈 #275 — POST /api/identity/challenges 응답 페이로드에 max_attempts 가 없어 프론트
 * launcher 가 하드코딩 5 로 fallback 하던 결함을 차단한다.
 *
 * Resource → 응답 JSON 의 max_attempts 키 노출이 깨지면 사용자 모달의 "남은 시도 횟수"
 * 표시가 항상 5 로 굳어지는 회귀가 발생한다.
 */
class ChallengeResourceMaxAttemptsTest extends TestCase
{
    public function test_resource_exposes_max_attempts_from_dto(): void
    {
        $challenge = new VerificationChallenge(
            id: 'uuid-1',
            providerId: 'g7:core.mail',
            purpose: 'signup',
            channel: 'email',
            targetHash: hash('sha256', 'a@b.com'),
            expiresAt: Carbon::parse('2026-05-14T10:00:00+00:00'),
            renderHint: 'text_code',
            maxAttempts: 12,
        );

        $resource = new ChallengeResource($challenge);
        $array = $resource->toArray(Request::create('/test', 'GET'));

        $this->assertArrayHasKey('max_attempts', $array);
        $this->assertSame(12, $array['max_attempts']);
    }

    public function test_resource_exposes_zero_for_unlimited_attempts(): void
    {
        // popup/SDK 형 provider (이니시스 등) — max_attempts=0 (무제한) 케이스
        $challenge = new VerificationChallenge(
            id: 'uuid-2',
            providerId: 'inicis',
            purpose: 'signup',
            channel: 'ipin',
            targetHash: hash('sha256', 'a@b.com'),
            expiresAt: Carbon::now(),
            renderHint: 'text_code',
        );

        $resource = new ChallengeResource($challenge);
        $array = $resource->toArray(Request::create('/test', 'GET'));

        $this->assertArrayHasKey('max_attempts', $array);
        $this->assertSame(0, $array['max_attempts']);
    }
}
