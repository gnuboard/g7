<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Identity\PolicyLifecycleTestHelpers;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 코어 IDV 정책 챌린지/검증 라이프사이클 (Part B-3).
 *
 * 7단계 통합:
 *   1. 보호된 API 호출 → HTTP 428 + payload 검증
 *   2. POST /api/identity/challenges → challenge 발급 (TestIdentityProvider 결정적 코드)
 *   3. challenge 코드 획득 (TestIdentityProvider::FIXED_CODE)
 *   4. POST /api/identity/challenges/{id}/verify → verification_token 발급, identity_verification_logs.verified_at 기록
 *   5. 원래 보호 API 재시도 → 통과 (정책에 의한 428 미발생)
 *   6. grace_minutes 윈도우 내 재호출 → 통과
 *   7. grace_minutes 경과 후 재호출 → 다시 428
 *
 * 메일 회로 자체는 `IdentityPolicyMailFakeSmokeTest` 가 별도 검증.
 */
class CoreIdentityPolicyLifecycleTest extends TestCase
{
    use PolicyLifecycleTestHelpers, RefreshDatabase;

    private IdentityPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(IdentityPolicyService::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityPolicySeeder::class);
        $this->registerTestIdentityProvider();
        Notification::fake();
    }

    /**
     * signup_before_submit — HTTP route 레이어 라이프사이클.
     *
     * /api/auth/register 가 정책 enabled 시 IDV 를 강제하고, challenge → verify 후에는
     * 정책 미들웨어를 통과해 회원가입이 정상 진행되어야 한다.
     */
    public function test_signup_before_submit_full_http_lifecycle(): void
    {
        $policy = $this->enableSignupPolicy('core.auth.signup_before_submit');

        // 1단계 — 보호된 register 호출은 428 (정책 위반)
        $email = 'newuser_'.uniqid().'@example.test';

        $payload = $this->assertProtectedRouteIssues428(
            'POST',
            '/api/auth/register',
            $this->guestToken(),
            'core.auth.signup_before_submit',
            'signup',
            [
                'name' => 'Lifecycle Tester',
                'nickname' => 'lt_'.uniqid(),
                'email' => $email,
                'password' => 'StrongPass!23',
                'password_confirmation' => 'StrongPass!23',
                'agreement' => true,
            ],
        );

        $this->assertSame('signup', $payload['purpose']);

        // 2~4단계 — 게스트로 challenge 발급 + verify (Mode B 가입 흐름)
        $issued = $this->issueAndVerifyChallengeAsGuest($email, 'signup');

        $this->assertNotNull($issued['challenge_id']);

        // 5단계 — verified 토큰을 함께 보내서 register 재시도 시 정책이 통과되어야 함.
        // (IdentityPolicyService::resolveTargetHash 가 게스트의 input.email 을 기준으로 verified 로그 조회)
        $retry = $this->postJson('/api/auth/register', [
            'name' => 'Lifecycle Tester',
            'nickname' => 'lt_'.uniqid(),
            'email' => $email,
            'password' => 'StrongPass!23',
            'password_confirmation' => 'StrongPass!23',
            'agreement' => true,
        ]);

        // verify 직후라 정책 미들웨어는 통과해야 한다 — 응답 코드는 register 비즈니스 로직 결과에 따라
        // 200/201 또는 검증 실패 시 422 가 될 수 있으나 428 은 안 나야 한다.
        $this->assertNotSame(
            428,
            $retry->getStatusCode(),
            'verify 직후 register 재시도는 정책 미들웨어를 통과해야 함 (현재 status: '.$retry->getStatusCode().')',
        );
    }

    /**
     * Service-level 라이프사이클 — password_change (grace=5).
     *
     * 1) enforce throws (이력 없음)
     * 2) verified 로그 기록 (challenge → verify 결과를 fixture 로 시뮬레이션)
     * 3) enforce passes (grace 윈도우 내)
     * 4) Carbon::setTestNow 로 grace 경과 시뮬레이션
     * 5) enforce throws 재발생
     */
    public function test_password_change_service_lifecycle_with_grace_window(): void
    {
        $user = User::factory()->create();
        $policy = $this->enableServicePolicy('core.profile.password_change');

        // 1단계 — 인증 이력 없을 때 enforce 는 throw
        try {
            $this->service->enforce($policy, $user, []);
            $this->fail('인증 이력 없을 때 enforce 는 throw 해야 함');
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame('core.profile.password_change', $e->policyKey);
        }

        // 2단계 — 결정적 challenge 발급 + verify (Service 레이어로 직접 호출)
        $logRepository = $this->app->make(\App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface::class);
        $provider = new TestIdentityProvider($logRepository);
        $challenge = $provider->requestChallenge($user, ['purpose' => 'sensitive_action']);
        $result = $provider->verify($challenge->id, ['code' => TestIdentityProvider::FIXED_CODE]);

        $this->assertTrue($result->success, 'TestIdentityProvider verify 는 결정적 코드로 성공해야 함');

        // 3단계 — verified 직후 enforce 는 grace 윈도우로 통과
        $this->service->enforce($policy, $user, []);
        $this->assertTrue(true, 'verified 직후 enforce 는 throw 없이 통과해야 함');

        // 4~5단계 — grace_minutes (5) + 1분 경과 후 enforce 는 다시 throw
        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        try {
            $this->service->enforce($policy, $user->fresh(), []);
            $this->fail('grace 경과 후에는 enforce 가 다시 throw 해야 함');
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame('core.profile.password_change', $e->policyKey);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 코어 9개 정책 + 모듈 8개 정책 전수 Service-level 라이프사이클 매트릭스.
     *
     * 각 정책마다:
     *   1. enforce throws (no verification)
     *   2. TestIdentityProvider 로 challenge 발급 + verify
     *   3. enforce skips (verified 직후)
     *   4. grace > 0 정책 → Carbon::setTestNow grace+1 분 시뮬레이션
     *   5. grace > 0 정책 → enforce throws again
     *
     * @dataProvider policyLifecycleProvider
     */
    public function test_policy_full_service_lifecycle(
        string $policyKey,
        string $purpose,
        string $userType,
        int $graceMinutes,
        ?array $contextHint = null,
        ?string $sourceType = 'core',
        ?array $declaredOverride = null,
    ): void {
        if ($sourceType !== 'core') {
            $this->markTestSkipped("module/plugin 정책은 해당 모듈 테스트에서 검증");
        }

        $user = $userType === 'admin' ? $this->makeAdminUser() : User::factory()->create();
        $policy = $this->enableServicePolicy($policyKey);

        $context = $contextHint ?? [];
        if ($declaredOverride !== null) {
            // signup_before_submit 와 같이 conditions 매칭이 필요한 정책은 컨텍스트 보충 (enforce 자체는 conditions 무시)
            $context = array_merge($context, $declaredOverride);
        }

        // 1. 인증 이력 없을 때 enforce 는 throw
        try {
            $this->service->enforce($policy, $user, $context);
            $this->fail("정책 '{$policyKey}' 가 인증 이력 없을 때 throw 해야 함");
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame($policyKey, $e->policyKey);
        }

        // 2. challenge 발급 + verify
        $logRepository = $this->app->make(\App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface::class);
        $provider = new TestIdentityProvider($logRepository);
        $challenge = $provider->requestChallenge($user, ['purpose' => $purpose]);
        $result = $provider->verify($challenge->id, ['code' => TestIdentityProvider::FIXED_CODE]);
        $this->assertTrue($result->success, "TestIdentityProvider verify 실패 (policyKey: {$policyKey})");

        // 3. verified 직후 enforce 는 통과
        $this->service->enforce($policy, $user->fresh(), $context);
        $this->assertTrue(true, "정책 '{$policyKey}' verified 직후 enforce 통과");

        // 4~5. grace > 0 정책만 grace 윈도우 만료 후 다시 throw
        if ($graceMinutes > 0) {
            Carbon::setTestNow(Carbon::now()->addMinutes($graceMinutes + 1));
            try {
                $this->service->enforce($policy, $user->fresh(), $context);
                $this->fail("정책 '{$policyKey}' grace+1 분 경과 후 throw 해야 함");
            } catch (IdentityVerificationRequiredException $e) {
                $this->assertSame($policyKey, $e->policyKey);
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    public static function policyLifecycleProvider(): array
    {
        // 코어 9개 정책 — module/plugin 정책은 각자 모듈 테스트에서 동일 매트릭스 커버.
        return [
            'signup_before_submit (self, grace=0)' => [
                'core.auth.signup_before_submit', 'signup', 'self', 0, ['signup_stage' => 'before_submit', 'http_method' => 'POST'],
            ],
            'signup_after_create (self, grace=0)' => [
                'core.auth.signup_after_create', 'signup', 'self', 0, ['signup_stage' => 'after_create'],
            ],
            'password_reset (both/self, grace=0)' => [
                'core.auth.password_reset', 'password_reset', 'self', 0,
            ],
            'password_change (self, grace=5)' => [
                'core.profile.password_change', 'sensitive_action', 'self', 5,
            ],
            'contact_change (self, grace=5)' => [
                'core.profile.contact_change', 'sensitive_action', 'self', 5, ['changed_fields' => ['email']],
            ],
            'account_withdraw (self, grace=0)' => [
                'core.account.withdraw', 'sensitive_action', 'self', 0,
            ],
            'app_key_regenerate (admin, grace=0)' => [
                'core.admin.app_key_regenerate', 'sensitive_action', 'admin', 0,
            ],
            'user_delete (admin, grace=0)' => [
                'core.admin.user_delete', 'sensitive_action', 'admin', 0,
            ],
            'extension_uninstall (admin, grace=0)' => [
                'core.admin.extension_uninstall', 'sensitive_action', 'admin', 0,
            ],
        ];
    }

    private function makeAdminUser(): User
    {
        $admin = User::factory()->create(['is_super' => true]);
        $adminRole = \App\Models\Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }

        return $admin->fresh();
    }

    /**
     * password_reset — HTTP route 레이어 라이프사이클 (게스트, both 적용).
     */
    public function test_password_reset_route_returns_428_when_enabled(): void
    {
        $this->enableSignupPolicy('core.auth.password_reset');
        $email = 'reset_'.uniqid().'@example.test';
        // user 가 존재해야 AuthService::resetPassword 가 token 검증을 거쳐 hook 까지 도달.
        // 직전 회귀: 명시 미들웨어 등록 제거 후 자동 매핑은 hook scope 정책을 처리하지 않으므로,
        // controller → AuthService 까지 진입해야 hook listener 가 enforce 한다.
        \App\Models\User::factory()->create(['email' => $email]);
        // 비밀번호 재설정 토큰 1건 시드
        \DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => bcrypt('valid-token'),
            'created_at' => now(),
        ]);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/auth/reset-password', [
                'email' => $email,
                'token' => 'valid-token',
                'password' => 'NewStrong!23',
                'password_confirmation' => 'NewStrong!23',
            ]);

        $this->assertSame(428, $response->getStatusCode(), 'enabled 시 password_reset 정책은 428 발동');
        $payload = $response->json('verification');
        $this->assertIsArray($payload);
        $this->assertSame('core.auth.password_reset', $payload['policy_key']);
        $this->assertSame('password_reset', $payload['purpose']);
    }

    private function enableSignupPolicy(string $key): IdentityPolicy
    {
        $policy = IdentityPolicy::where('key', $key)->first();
        $this->assertNotNull($policy);
        $policy->enabled = true;
        $policy->provider_id = TestIdentityProvider::ID;
        $policy->save();

        return $policy->fresh();
    }

    private function enableServicePolicy(string $key): IdentityPolicy
    {
        return $this->enableSignupPolicy($key);
    }

    private function guestToken(): string
    {
        // 라우트 미들웨어가 'optional.sanctum' 으로 게스트도 허용 — 빈 문자열로 헤더만 형성
        return '';
    }

    /**
     * 게스트 흐름 (Mode B) 에서 challenge 발급 후 결정적 코드로 검증.
     */
    private function issueAndVerifyChallengeAsGuest(string $email, string $purpose): array
    {
        $issue = $this->postJson('/api/identity/challenges', [
            'purpose' => $purpose,
            'target' => ['email' => $email],
        ]);

        $issue->assertStatus(201);
        $challengeId = $issue->json('data.id');
        $this->assertNotNull($challengeId);

        $verify = $this->postJson('/api/identity/challenges/'.$challengeId.'/verify', [
            'code' => TestIdentityProvider::FIXED_CODE,
        ]);
        $verify->assertStatus(200);

        return ['challenge_id' => $challengeId];
    }
}
