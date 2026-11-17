<?php

namespace Tests\Feature\Api\Auth;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Repositories\UserRepository;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    /**
     * 알림·큐를 가짜로 바꾸고 역할 권한과 기본 본인인증 정책을 시드합니다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario case=no_policy
     *
     * @effects signup_without_policy_active_email_unverified, signup_without_policy_creates_active_user
     *
     * @return void 반환값 없음
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
        $this->assertNull(User::where('email', 'a@example.com')->value('email_verified_at'), '이메일을 확인하지 않은 가입은 인증으로 기록하지 않는다');
    }

    /**
     * signup_before_submit 정책 enabled — 토큰 미제출 시 EnforceIdentityPolicy 미들웨어가
     * 428 (IdentityVerificationRequiredException) 으로 차단합니다.
     *
     * @scenario case=before_submit_no_token
     *
     * @effects signup_before_submit_requires_token
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=before_submit_mail_token
     *
     * @effects signup_before_submit_mail_token_activates_and_marks_email, verified_token_passes_matching_policy
     *
     * @return void 반환값 없음
     */
    public function test_signup_before_submit_policy_succeeds_with_valid_token(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');

        $token = 'test-idv-token-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
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
        $this->assertNotNull(User::where('email', 'b2@example.com')->value('email_verified_at'), '가입 전 메일 인증번호로 확인한 이메일은 인증으로 기록한다');
    }

    /**
     * 이메일 인증 기록이 실패해도 가입은 그대로 끝난다 — 기록은 가입의 부가 작업이다.
     *
     * @scenario case=before_submit_mark_fails
     *
     * @effects signup_survives_email_mark_failure
     *
     * @return void 반환값 없음
     */
    public function test_signup_survives_when_email_mark_fails(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');

        $token = 'test-idv-token-'.bin2hex(random_bytes(8));
        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'user_id' => null,
            'target_hash' => hash('sha256', 'b6@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $failing = \Mockery::mock(UserRepository::class)->makePartial();
        $failing->shouldReceive('markEmailVerified')->andThrow(new \RuntimeException('기록 실패 주입'));
        $this->app->instance(UserRepositoryInterface::class, $failing);

        $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'b6@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
            'verification_token' => $token,
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'b6@example.com', 'status' => UserStatus::Active->value]);
    }

    /**
     * 가입 전 본인확인을 메일이 아닌 수단으로 마쳤으면 이메일 인증으로 기록하지 않는다.
     *
     * @effects signup_email_marked_verified_only_for_mail_channel
     *
     * @scenario case=before_submit_non_mail_token
     *
     * @return void 반환값 없음
     */
    public function test_signup_before_submit_non_mail_verification_does_not_mark_email(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');

        $token = 'test-idv-token-'.bin2hex(random_bytes(8));
        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:test.phone',
            'purpose' => 'signup',
            'channel' => 'sms',
            'user_id' => null,
            'target_hash' => hash('sha256', 'b5@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'b5@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
            'verification_token' => $token,
        ])->assertStatus(201);

        $this->assertNull(User::where('email', 'b5@example.com')->value('email_verified_at'));
    }

    /**
     * 회귀 — verify 직후 retry 가 발생할 때 verification_token 만으로 통과해야 함.
     *
     * 과거 EnforceIdentityPolicy 미들웨어가 verification_token 을 무시하고 grace_minutes 윈도우만
     * 검사했기 때문에, grace_minutes=0 (signup 정책 기본값) + verified_at < now() 인 일반적인 retry 에서는
     * 무한 428 루프가 발생했음. 이 테스트는 verified_at 을 의도적으로 2초 전으로 두어 second-boundary 우연
     * 일치를 회피하고, 미들웨어가 token 을 직접 인식하는지 검증한다.
     *
     * @scenario case=before_submit_token_past_grace
     *
     * @effects signup_token_bypasses_grace_window, token_bypass_ignores_grace_window
     *
     * @return void 반환값 없음
     */
    public function test_signup_before_submit_token_bypasses_middleware_after_grace_window(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');

        $token = 'test-idv-token-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
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
     *
     * @scenario case=before_submit_target_mismatch
     *
     * @effects signup_token_target_mismatch_blocked, guest_target_bound
     *
     * @return void 반환값 없음
     */
    public function test_token_with_mismatched_target_hash_is_blocked_by_middleware(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');

        $token = 'test-idv-token-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
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
     *
     * @scenario case=after_create_pending
     *
     * @effects signup_after_create_pending_with_challenge, signup_after_create_creates_pending_user
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=after_create_mail_verify
     *
     * @effects signup_after_create_verify_activates_and_marks_email, signup_after_create_verify_activates_user
     *
     * @return void 반환값 없음
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
        $this->assertNull($user->email_verified_at, '확인 전에는 인증으로 기록하지 않는다');

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
        $this->assertNotNull($user->email_verified_at, '가입 후 메일 인증번호로 확인하면 인증으로 기록한다');
    }

    /**
     * signup_after_create 정책이 켜져 있어도 가입 훅이 이중으로 강제되지 않는지 확인합니다.
     *
     * `core.auth.after_register` 는 challenge 를 발행하는 전담 리스너가 소유한다. 일반 정책
     * 강제 리스너까지 이 훅을 구독하면, challenge 를 발행한 직후 428 이 던져져 가입 자체가
     * 막힌다 — '가입 후 본인확인' 정책이 '가입 전' 정책과 구분되지 않고, 켜는 순간 아무도
     * 가입할 수 없게 된다. 동작(위 두 테스트)과 별개로 구독 상태를 직접 고정한다.
     *
     * @scenario case=after_create_generic_enforcer
     *
     * @effects after_register_not_enforced_by_generic_listener, after_register_hook_not_enforced_twice
     *
     * @return void 반환값 없음
     */
    public function test_after_register_hook_is_not_subscribed_by_the_generic_enforcer(): void
    {
        $this->enablePolicy('core.auth.signup_after_create');

        EnforceIdentityPolicyListener::syncDynamicHookSubscriptions();

        $hooks = new \ReflectionProperty(HookManager::class, 'hooks');
        $hooks->setAccessible(true);

        $registered = $hooks->getValue()['core.auth.after_register'] ?? [];
        $priorities = array_keys($registered);

        $this->assertContains(5, $priorities, 'challenge 발행 리스너가 구독되어 있어야 합니다.');
        $this->assertNotContains(
            15,
            $priorities,
            '정책 강제 리스너가 after_register 를 구독하면 가입이 428 로 막힙니다.'
        );
    }

    /**
     * 한 번 가입에 쓴 인증 결과로는 다른 이메일·같은 이메일 모두 두 번째 가입을 할 수 없다 (KVE 동형 O4·B2).
     *
     * @effects signup_token_single_use
     *
     * @return void 반환값 없음
     */
    public function test_signup_token_cannot_be_used_twice(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');
        $token = $this->verifiedSignupToken('twice@example.com');

        $this->postJson('/api/auth/register', $this->signupBody('twice@example.com', $token))->assertStatus(201);

        $second = $this->postJson('/api/auth/register', $this->signupBody('twice@example.com', $token));
        $this->assertContains($second->status(), [422, 428]);
        $this->assertSame(1, User::where('email', 'like', 'twice%')->count());
    }

    /**
     * 가입이 입력 오류(422)로 끝나면 인증 결과를 돌려줘 고쳐서 다시 가입할 수 있다.
     *
     * @effects signup_failure_releases_token
     *
     * @return void 반환값 없음
     */
    public function test_validation_failure_releases_signup_token(): void
    {
        $this->enablePolicy('core.auth.signup_before_submit');
        $token = $this->verifiedSignupToken('retry@example.com');

        $invalid = $this->signupBody('retry@example.com', $token);
        $invalid['password_confirmation'] = 'mismatch-value';
        $this->postJson('/api/auth/register', $invalid)->assertStatus(422);

        $this->assertNull(IdentityVerificationLog::where('verification_token', $token)->value('consumed_at'));

        $this->postJson('/api/auth/register', $this->signupBody('retry@example.com', $token))->assertStatus(201);
    }

    /**
     * 가입용 확인 완료 결과를 만들고 토큰을 돌려줍니다.
     *
     * @param  string  $email  가입할 이메일
     * @return string 확인 토큰
     */
    private function verifiedSignupToken(string $email): string
    {
        $token = 'test-idv-token-'.bin2hex(random_bytes(8));
        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'target_hash' => hash('sha256', $email),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
        ]);

        return $token;
    }

    /**
     * 가입 요청 본문을 만듭니다.
     *
     * @param  string  $email  이메일
     * @param  string  $token  확인 토큰
     * @return array<string, mixed> 요청 본문
     */
    private function signupBody(string $email, string $token): array
    {
        return [
            'name' => '테스트',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
            'verification_token' => $token,
        ];
    }

    /**
     * 시드된 정책의 enabled 를 true 로 토글합니다.
     *
     * @param  string  $key  켤 정책 키
     * @return void 반환값 없음
     */
    private function enablePolicy(string $key): void
    {
        IdentityPolicy::where('key', $key)->update(['enabled' => true]);
    }
}
