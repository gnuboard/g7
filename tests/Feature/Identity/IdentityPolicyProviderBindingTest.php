<?php

namespace Tests\Feature\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityPolicyFailMode;
use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Exceptions\IdentityPolicyProviderUnavailableException;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 정책에 인증 수단이 지정되면 그 수단으로 받은 결과만 인정하는지 검증한다 (KVE-2026-3188, 동형 B3·B5·B8·B9).
 *
 * `TestIdentityProvider`(test:fixed_code) 를 「정책이 지정한 다른 수단(예: 휴대폰 본인확인)」 역할로 등록한다.
 */
class IdentityPolicyProviderBindingTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = 'core.auth.password_reset';

    private const EMAIL = 'binding@example.test';

    /**
     * 정책 시더와 대역 수단을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityPolicySeeder::class);
        app(IdentityVerificationManager::class)->register(new TestIdentityProvider(app(IdentityVerificationLogRepositoryInterface::class)));
    }

    /**
     * 정책 수단·유예 시간을 설정하고 정책을 켭니다.
     *
     * @param  string|null  $providerId  지정 수단
     * @param  int  $grace  유예 시간(분)
     * @return IdentityPolicy 정책
     */
    private function policy(?string $providerId, int $grace = 0): IdentityPolicy
    {
        IdentityPolicy::where('key', self::POLICY)->update(['enabled' => true, 'provider_id' => $providerId, 'grace_minutes' => $grace]);

        return IdentityPolicy::where('key', self::POLICY)->firstOrFail();
    }

    /**
     * 확인 완료 결과를 만듭니다.
     *
     * @param  string  $providerId  결과를 낸 수단
     * @return IdentityVerificationLog 결과 로그
     */
    private function verified(string $providerId): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => $providerId,
            'purpose' => 'password_reset',
            'channel' => 'email',
            'target_hash' => hash('sha256', self::EMAIL),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => 'tok-'.Str::random(24),
            'verified_at' => now()->subSeconds(5),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * 정책을 강제합니다.
     *
     * @param  IdentityPolicy  $policy  정책
     * @param  string  $token  확인 토큰 (없으면 빈 문자열)
     * @return void 반환값 없음
     */
    private function enforce(IdentityPolicy $policy, string $token = ''): void
    {
        app(IdentityPolicyService::class)->enforce($policy, null, ['target_email' => self::EMAIL, 'verification_token' => $token]);
    }

    /**
     * 지정 수단과 다른 수단(메일)으로 받은 결과는 인정하지 않고, 지정 수단으로 다시 인증을 요구한다 (3188 재현).
     *
     * @effects required_provider_enforced
     *
     * @return void 반환값 없음
     */
    public function test_mail_token_rejected_when_policy_requires_other_provider(): void
    {
        $policy = $this->policy(TestIdentityProvider::ID);
        $log = $this->verified('g7:core.mail');

        try {
            $this->enforce($policy, $log->verification_token);
            $this->fail('정책 지정 수단과 다른 수단의 결과가 통과했습니다.');
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame(TestIdentityProvider::ID, $e->providerId);
        }

        $this->assertNull($log->fresh()->consumed_at);
    }

    /**
     * 지정 수단으로 받은 결과는 통과한다.
     *
     * @effects required_provider_enforced
     *
     * @return void 반환값 없음
     */
    public function test_required_provider_token_passes(): void
    {
        $policy = $this->policy(TestIdentityProvider::ID);
        $log = $this->verified(TestIdentityProvider::ID);

        $this->enforce($policy, $log->verification_token);

        $this->assertNotNull($log->fresh()->consumed_at);
    }

    /**
     * 수단을 지정하지 않은 정책(기본 인증 수단 사용)은 어떤 수단의 결과든 인정한다.
     *
     * @effects unspecified_provider_accepts_any
     *
     * @return void 반환값 없음
     */
    public function test_unspecified_policy_accepts_any_provider(): void
    {
        $policy = $this->policy(null);
        $log = $this->verified(TestIdentityProvider::ID);

        $this->enforce($policy, $log->verification_token);

        $this->assertNotNull($log->fresh()->consumed_at);
    }

    /**
     * 유예 시간 안의 다른 수단 인증은 수단이 지정된 정책을 통과시키지 않는다 (B3).
     *
     * @effects grace_window_respects_provider
     *
     * @return void 반환값 없음
     */
    public function test_grace_window_requires_same_provider(): void
    {
        $policy = $this->policy(TestIdentityProvider::ID, 30);
        $this->verified('g7:core.mail');

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->enforce($policy);
    }

    /**
     * 지정 수단을 지금 쓸 수 없으면(플러그인 제거 등) 메일로 대신하지 않고 503 으로 알린다 (B9).
     *
     * @effects unavailable_provider_blocks
     *
     * @return void 반환값 없음
     */
    public function test_unavailable_required_provider_blocks_with_503(): void
    {
        $policy = $this->policy('removed.provider');

        try {
            $this->enforce($policy);
            $this->fail('사용할 수 없는 지정 수단인데 요청이 통과했습니다.');
        } catch (IdentityPolicyProviderUnavailableException $e) {
            $this->assertSame(self::POLICY, $e->policyKey);
        }
    }

    /**
     * 기록만 하는 정책(log_only)은 지정 수단을 쓸 수 없어도 기록하고 통과시킨다.
     *
     * @effects unavailable_provider_blocks
     *
     * @return void 반환값 없음
     */
    public function test_unavailable_provider_with_log_only_passes(): void
    {
        $policy = $this->policy('removed.provider');
        $policy->update(['fail_mode' => IdentityPolicyFailMode::LogOnly->value]);

        $this->enforce($policy->fresh());

        $this->assertSame(1, IdentityVerificationLog::where('status', IdentityVerificationStatus::PolicyViolationLogged->value)->count());
    }

    /**
     * 가입 후 정책에 수단이 지정돼 있으면 다른 수단의 인증으로는 계정을 활성화하지 않는다 (B5).
     *
     * @effects after_register_activation_requires_provider
     *
     * @return void 반환값 없음
     */
    public function test_after_register_activation_requires_policy_provider(): void
    {
        IdentityPolicy::where('key', 'core.auth.signup_after_create')->update(['enabled' => true, 'provider_id' => TestIdentityProvider::ID]);
        $user = User::factory()->create(['status' => UserStatus::PendingVerification->value]);
        $log = IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', mb_strtolower($user->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        HookManager::doAction('core.identity.after_verify', VerificationResult::success($log->id, 'g7:core.mail', now()), $log, []);

        $this->assertSame(UserStatus::PendingVerification->value, $user->fresh()->status);
    }

    /**
     * 관리자 정책 저장은 등록되지 않은 수단·목적을 지원하지 않는 수단을 거부한다 (B8).
     *
     * @effects admin_policy_provider_validated
     *
     * @return void 반환값 없음
     */
    public function test_admin_policy_save_validates_provider(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['is_super' => true]);
        $admin->roles()->attach(Role::where('identifier', 'admin')->firstOrFail()->id, ['assigned_at' => now()]);
        $token = $admin->createToken('t')->plainTextToken;
        $policy = IdentityPolicy::where('key', self::POLICY)->firstOrFail();

        $unknown = $this->withToken($token)->putJson('/api/admin/identity/policies/'.$policy->id, ['provider_id' => 'not.registered']);
        $unknown->assertStatus(422)->assertJsonValidationErrors(['provider_id']);

        $this->withToken($token)->putJson('/api/admin/identity/policies/'.$policy->id, ['provider_id' => TestIdentityProvider::ID])->assertOk();

        // 이미 저장돼 있던 값(지금은 제거된 플러그인)이어도 정책 끄기 저장은 된다
        IdentityPolicy::whereKey($policy->id)->update(['provider_id' => 'removed.provider']);
        $this->withToken($token)->putJson('/api/admin/identity/policies/'.$policy->id, ['enabled' => false, 'provider_id' => 'removed.provider'])->assertOk();
    }
}
