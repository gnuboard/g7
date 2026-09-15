<?php

namespace Tests\Feature\Identity;

use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [case:backend-36] 본인인증 코드 확인(verify) 요청 제한이 다른 공개 API 의 요청 제한과 횟수를 공유하지 않는지 검증합니다.
 *
 * 배경: Laravel 의 `throttle:N,1` 은 비로그인 요청의 키를 `sha1(domain|ip)` 하나로 만든다 — 경로와 무관하다.
 * 그래서 접두사 없이 쓰면 `api/user/*`(600/분) 같은 화면 로드 요청이 올린 횟수가 verify(10/분)의 상한을
 * 앞질러 넘겨, 2단계 인증 화면을 몇 번 오간 방문자가 인증번호 확인에서 429 를 받는다(E2E 직렬 실행에서
 * 403 대신 429 로 재현). 라우트에 접두사(`throttle:10,1,identity_verify`)를 주면 키가 갈라진다.
 */
class IdentityVerifyThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 다른 공개 API 가 비로그인 공유 키를 상한 이상으로 채워도 verify 는 429 가 아니다.
     *
     * @scenario two_factor=on, controller=user, code=consumed_by_public_verify
     *
     * @effects public_verify_throttle_isolated_from_other_guest_routes
     */
    public function test_verify_throttle_is_not_shared_with_other_guest_routes(): void
    {
        $limiter = app(RateLimiter::class);

        // `ThrottleRequests::resolveRequestSignature` 의 비로그인 키 — 접두사 없는 모든 throttle 라우트가 공유한다.
        $sharedGuestKey = sha1('|127.0.0.1');

        for ($i = 0; $i < 11; $i++) {
            $limiter->hit($sharedGuestKey, 60);
        }

        // 전제 고정: 위 키가 실제로 공유 키 형태다(형식이 바뀌면 이 단언이 먼저 깨져 재검토를 강제한다).
        $this->assertSame(11, $limiter->attempts($sharedGuestKey));

        $response = $this->postJson('/api/identity/challenges/00000000-0000-0000-0000-000000000000/verify', [
            'code' => '000000',
        ]);

        $this->assertNotSame(429, $response->status(), 'verify 가 다른 공개 API 와 요청 제한 횟수를 공유하고 있다');
    }

    /**
     * 분리한 뒤에도 verify 자체의 상한(10/분)은 그대로 걸린다.
     *
     * @scenario two_factor=on, controller=user, code=invalid
     *
     * @effects public_verify_throttle_isolated_from_other_guest_routes
     */
    public function test_verify_own_limit_still_applies(): void
    {
        $statuses = [];

        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->postJson('/api/identity/challenges/00000000-0000-0000-0000-000000000000/verify', [
                'code' => '000000',
            ])->status();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, 10), '상한 이내의 요청이 차단됐다');
        $this->assertSame(429, $statuses[10], '11번째 요청이 429 가 아니다 — 상한이 사라졌다');
    }
}
