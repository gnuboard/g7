<?php

namespace Tests\Support\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityPolicy;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * IDV 정책 라이프사이클 통합 테스트 헬퍼.
 *
 * Part B-3 — 보호 API → 428 → challenge 발급 → verify → 재시도 → grace 윈도우 → grace 경과 후 차단
 * 7단계를 일관된 헬퍼 메서드 호출로 검증한다.
 *
 * 인증코드는 결정적(`TestIdentityProvider::FIXED_CODE`) 으로 발급되어 메일 인터셉트 의존이 없다.
 * 메일 회로 자체 검증은 `IdentityPolicyMailFakeSmokeTest` 가 별도로 수행 (`Mail::fake()` + `MailIdentityProvider`).
 */
trait PolicyLifecycleTestHelpers
{
    /**
     * TestIdentityProvider 를 IDV 매니저에 등록하고 기본 프로바이더로 지정한다.
     *
     * 각 테스트 setUp() 또는 trait 의 setUpPolicyLifecycle() 자동 후크에서 호출.
     */
    protected function registerTestIdentityProvider(): void
    {
        $manager = $this->app->make(IdentityVerificationManager::class);
        $logRepository = $this->app->make(IdentityVerificationLogRepositoryInterface::class);

        $provider = new TestIdentityProvider($logRepository);

        $manager->register($provider);

        config(['settings.identity.default_provider' => TestIdentityProvider::ID]);
    }

    /**
     * 일반 사용자(자기 자신) 권한 토큰 발급.
     */
    protected function actingAsRegularUser(?array $attributes = null): array
    {
        $user = User::factory()->create($attributes ?? []);
        $token = $user->createToken('test-user')->plainTextToken;

        return ['user' => $user->fresh(), 'token' => $token];
    }

    /**
     * 관리자 사용자 + 관리자 역할 권한 토큰 발급.
     */
    protected function actingAsAdminUser(?array $attributes = null): array
    {
        $user = User::factory()->create(array_merge(['is_super' => true], $attributes ?? []));
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $user->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }
        $user = $user->fresh();
        $token = $user->createToken('test-admin')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * 1단계: 보호된 API 호출이 정책 위반으로 HTTP 428 을 반환하는지 검증.
     * payload 의 policy_key/purpose/challenge_start_url 도 함께 점검.
     */
    protected function assertProtectedRouteIssues428(
        string $method,
        string $uri,
        string $token,
        string $expectedPolicyKey,
        string $expectedPurpose,
        array $body = [],
    ): array {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->json($method, $uri, $body);

        $response->assertStatus(428);

        // ResponseHelper::identityRequired() 가 만드는 428 응답 구조 — verification 키 하위
        $payload = $response->json('verification');
        $this->assertIsArray($payload, '428 응답에는 verification 객체가 포함되어야 함');
        $this->assertSame($expectedPolicyKey, $payload['policy_key'] ?? null, '428 payload.policy_key 가 기대값과 일치해야 함');
        $this->assertSame($expectedPurpose, $payload['purpose'] ?? null, '428 payload.purpose 가 기대값과 일치해야 함');
        $this->assertSame('/api/identity/challenges', $payload['challenge_start_url'] ?? null);

        return $payload;
    }

    /**
     * 2~4단계: challenge 발급 → 결정적 코드로 verify → verification_token 회수.
     */
    protected function issueAndVerifyChallenge(
        string $token,
        string $purpose,
        ?array $target = null,
    ): array {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/identity/challenges', array_filter([
            'purpose' => $purpose,
            'target' => $target,
        ]));

        $response->assertStatus(201);
        $challengeId = $response->json('data.id');
        $this->assertNotNull($challengeId, 'challenge id 가 응답에 포함되어야 함');

        $verifyResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/identity/challenges/'.$challengeId.'/verify', [
            'code' => TestIdentityProvider::FIXED_CODE,
        ]);

        $verifyResponse->assertStatus(200);

        return [
            'challenge_id' => $challengeId,
            'verified_at' => Carbon::now(),
        ];
    }

    /**
     * 5단계: 인증 직후 보호된 API 재시도가 통과하는지 검증.
     */
    protected function assertProtectedRoutePassesAfterVerification(
        string $method,
        string $uri,
        string $token,
        array $body = [],
        int $expectedStatus = 200,
    ): \Illuminate\Testing\TestResponse {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->json($method, $uri, $body);

        $response->assertStatus($expectedStatus);

        return $response;
    }

    /**
     * 6단계: grace_minutes 윈도우 내(예: grace-1분) 재호출 시 통과 검증.
     */
    protected function assertGraceWindowSkips(
        IdentityPolicy $policy,
        callable $protectedCall,
    ): void {
        Carbon::setTestNow(Carbon::now()->addMinutes(max(1, $policy->grace_minutes - 1)));

        try {
            $response = $protectedCall();
            $this->assertTrue(
                $response->getStatusCode() < 400 || $response->getStatusCode() === 422,
                'grace 윈도우 내 재호출은 정책에 의한 428 이 발생하면 안 됨 (현 상태: '.$response->getStatusCode().')',
            );
            $this->assertNotSame(428, $response->getStatusCode(), 'grace 윈도우 내에는 428 이 발생하지 않아야 함');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 7단계: grace_minutes 경과 후(예: grace+1분) 다시 428 반환 검증.
     */
    protected function assertGraceWindowExpires(
        IdentityPolicy $policy,
        callable $protectedCall,
    ): void {
        Carbon::setTestNow(Carbon::now()->addMinutes($policy->grace_minutes + 1));

        try {
            $response = $protectedCall();
            $this->assertSame(428, $response->getStatusCode(), 'grace 윈도우 경과 후에는 428 이 다시 발생해야 함');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * applies_to 우회: 정책의 applies_to 와 다른 사용자 유형은 정책 영향을 받지 않아야 한다.
     */
    protected function assertAppliesToBypass(
        string $method,
        string $uri,
        string $bypassToken,
        array $body = [],
    ): void {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$bypassToken,
            'Accept' => 'application/json',
        ])->json($method, $uri, $body);

        $this->assertNotSame(
            428,
            $response->getStatusCode(),
            'applies_to 우회 대상은 정책 발동을 받으면 안 됨 (현 상태: '.$response->getStatusCode().')',
        );
    }
}
