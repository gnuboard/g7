<?php

namespace Tests\Feature\Api\Auth;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 회원가입 흐름 IdentityPolicy 기반 정책 통합 Feature 테스트.
 *
 * 정책 키:
 *   - core.auth.signup_before_submit (Mode B): route scope, verification_token 검증
 *   - core.auth.signup_after_create  (Mode C): hook scope, after_register 시 challenge 발행 + PendingVerification
 */
class RegisterPolicyDrivenSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // 기존 훅 큐 job 의 Carbon 직렬화 문제로 테스트가 터지는 것을 방지
        Queue::fake();

        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest/user 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);

        // 기본 정책 시드 (모두 enabled=false 상태)
        $this->seed(IdentityPolicySeeder::class);
    }

    /**
     * 정책 미활성 상태 — 일반 가입 흐름. Active 사용자 생성, IDV challenge 미발행.
     */
    public function test_no_signup_policy_creates_active_user(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'a@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'a@example.com',
            'status' => UserStatus::Active->value,
        ]);
        $this->assertDatabaseMissing('identity_verification_logs', ['purpose' => 'signup']);
    }

    /**
     * signup_before_submit 정책 enabled — 토큰 미제출 시 EnforceIdentityPolicy 미들웨어가
     * 428 (IdentityVerificationRequiredException) 으로 차단합니다.
     */
    public function test_signup_before_submit_policy_blocks_without_token(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');

        $response = $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'b@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(428);
        $this->assertDatabaseMissing('users', ['email' => 'b@example.com']);
    }

    /**
     * signup_before_submit 정책 enabled + 유효한 verification_token — Active 가입 성공.
     */
    public function test_signup_before_submit_policy_succeeds_with_valid_token(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');

        $token = 'test-idv-token-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'user_id' => null,
            'target_hash' => hash('sha256', 'b2@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'b2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
            'verification_token' => $token,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'b2@example.com',
            'status' => UserStatus::Active->value,
        ]);
    }

    /**
     * 회귀 — verify 직후 retry 가 발생할 때 verification_token 만으로 통과해야 함.
     *
     * 과거 EnforceIdentityPolicy 미들웨어가 verification_token 을 무시하고 grace_minutes 윈도우만
     * 검사했기 때문에, grace_minutes=0 (signup 정책 기본값) + verified_at < now() 인 일반적인 retry 에서는
     * 무한 428 루프가 발생했음. 이 테스트는 verified_at 을 의도적으로 2초 전으로 두어 second-boundary 우연
     * 일치를 회피하고, 미들웨어가 token 을 직접 인식하는지 검증한다.
     */
    public function test_signup_before_submit_token_bypasses_middleware_after_grace_window(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');

        $token = 'test-idv-token-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'user_id' => null,
            'target_hash' => hash('sha256', 'b3@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            // grace_minutes=0 윈도우 밖으로 의도적으로 밀어두어 token bypass 만이 통과 경로가 되도록 함
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/auth/register?verification_token='.$token, [
            'name' => '테스트',
            'email' => 'b3@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
            'verification_token' => $token,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'b3@example.com',
            'status' => UserStatus::Active->value,
        ]);
    }

    /**
     * 회귀 — 미들웨어 단계에서 target hijacking 차단.
     *
     * A 가 자기 이메일(a@example.com)로 인증받은 token 을 훔쳐 B 의 이메일(b4@example.com)로
     * 가입 시도하면 미들웨어가 target_hash 불일치를 감지하여 428 로 차단해야 함.
     * 다운스트림 listener (AssertIdentityVerifiedBeforeRegister) 도 같은 검사를 하지만,
     * listener 가 없는 다른 정책 라우트에 대비한 안전망이 미들웨어에 함께 있어야 함.
     */
    public function test_token_with_mismatched_target_hash_is_blocked_by_middleware(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');

        $token = 'test-idv-token-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'user_id' => null,
            // 토큰은 a@example.com 으로 인증됨
            'target_hash' => hash('sha256', 'a@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
        ]);

        // B 의 이메일로 토큰 hijack 시도
        $response = $this->postJson('/api/auth/register?verification_token='.$token, [
            'name' => '공격자',
            'email' => 'b4@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
            'verification_token' => $token,
        ]);

        $response->assertStatus(428);
        $this->assertDatabaseMissing('users', ['email' => 'b4@example.com']);
    }

    /**
     * signup_after_create 정책 enabled — PendingVerification 생성 + challenge 발행.
     */
    public function test_signup_after_create_policy_creates_pending_user(): void
    {
        $this->enablePolicy('core.auth.signup_after_create');

        $response = $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'c@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'c@example.com',
            'status' => UserStatus::PendingVerification->value,
        ]);

        $this->assertDatabaseHas('identity_verification_logs', [
            'purpose' => 'signup',
            'origin_identifier' => 'core.auth.after_register',
        ]);
    }

    /**
     * signup_after_create 정책 enabled — verify 후 Active 전환.
     */
    public function test_signup_after_create_policy_verify_activates_user(): void
    {
        $this->enablePolicy('core.auth.signup_after_create');

        // 1) 가입 (pending 상태)
        $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'c2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ])->assertStatus(201);

        $user = User::where('email', 'c2@example.com')->first();
        $this->assertSame(UserStatus::PendingVerification->value, $user->status);

        /** @var IdentityVerificationLogRepositoryInterface $repo */
        $repo = $this->app->make(IdentityVerificationLogRepositoryInterface::class);
        $log = IdentityVerificationLog::where('user_id', $user->id)
            ->where('purpose', 'signup')
            ->first();
        $this->assertNotNull($log);

        $repo->updateById($log->id, [
            'metadata' => ['code_hash' => Hash::make('123456')],
            'status' => IdentityVerificationStatus::Sent->value,
        ]);

        $this->postJson("/api/identity/challenges/{$log->id}/verify", ['code' => '123456'])
            ->assertStatus(200);

        $user->refresh();
        $this->assertSame(UserStatus::Active->value, $user->status);
    }

    /**
     * 시드된 정책의 enabled 를 true 로 토글합니다.
     */
    private function enablePolicy(string $key): void
    {
        IdentityPolicy::where('key', $key)->update(['enabled' => true]);
    }
}
