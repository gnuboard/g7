<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\PasswordResetToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 비밀번호 재설정 흐름 IdentityPolicy 통합 라우트 레벨 회귀 테스트.
 *
 * 정책 키: `core.auth.password_reset` (시드 기본 enabled=false, 운영자 opt-in)
 * 라우트: POST /api/auth/reset-password — `identity.policy:core.auth.password_reset` 미들웨어 부착
 */
class PasswordResetPolicyDrivenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();

        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);
        $this->seed(IdentityPolicySeeder::class);
    }

    /**
     * 정책 비활성 상태 — 유효한 password_reset 토큰만으로 비밀번호 재설정 200.
     */
    public function test_password_reset_policy_disabled_passes_without_idv(): void
    {
        $user = $this->makeUser('disabled@example.com');
        [$plain, $hashed] = $this->createResetToken($user->email);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $plain,
            'password' => 'newPassword123!',
            'password_confirmation' => 'newPassword123!',
        ]);

        $response->assertStatus(200);
        $user->refresh();
        $this->assertTrue(Hash::check('newPassword123!', $user->password));
    }

    /**
     * 정책 활성 + grace 내 IDV verified 로그 부재 — 미들웨어가 428 반환.
     */
    public function test_password_reset_policy_enabled_blocks_without_verified_idv(): void
    {
        $this->enableAndExtendGrace('core.auth.password_reset', 5);

        $user = $this->makeUser('blocked@example.com');
        [$plain] = $this->createResetToken($user->email);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $plain,
            'password' => 'newPassword123!',
            'password_confirmation' => 'newPassword123!',
        ]);

        $response->assertStatus(428);
        $user->refresh();
        $this->assertFalse(
            Hash::check('newPassword123!', $user->password),
            '정책에 의해 차단되었으므로 비밀번호가 변경되지 않아야 함',
        );
    }

    /**
     * 정책 활성 + grace 내 IDV verified 로그 존재 — 미들웨어 통과 + 재설정 200.
     */
    public function test_password_reset_policy_enabled_succeeds_with_recent_verified(): void
    {
        $this->enableAndExtendGrace('core.auth.password_reset', 5);

        $user = $this->makeUser('verified@example.com');
        [$plain] = $this->createResetToken($user->email);

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'password_reset',
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', mb_strtolower($user->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $plain,
            'password' => 'newPassword123!',
            'password_confirmation' => 'newPassword123!',
        ]);

        $response->assertStatus(200);
        $user->refresh();
        $this->assertTrue(Hash::check('newPassword123!', $user->password));
    }

    private function makeUser(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('oldPassword123!'),
        ]);
    }

    /**
     * @return array{0: string, 1: string} [평문 토큰, 해시 토큰]
     */
    private function createResetToken(string $email): array
    {
        $plain = Str::random(64);
        PasswordResetToken::updateOrCreate(
            ['email' => $email],
            ['token' => Hash::make($plain), 'created_at' => now()],
        );

        return [$plain, ''];
    }

    private function enableAndExtendGrace(string $key, int $graceMinutes): void
    {
        IdentityPolicy::where('key', $key)->update([
            'enabled' => true,
            'grace_minutes' => $graceMinutes,
        ]);
    }
}
