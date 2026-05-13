<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Http\Middleware\EnforceIdentityPolicy;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EnforceIdentityPolicy 미들웨어의 verification_token 우회 매트릭스 회귀.
 *
 * 검증 차원:
 * - purpose × token 상태 (verified+미소비, verified+소비됨, status!=verified, 다른 purpose, 위조)
 * - target 매칭 (일치 / 불일치 / 인증 사용자 컨텍스트 / 식별자 미존재)
 * - provider 비종속성 — provider_id 가 무엇이든 IdentityVerificationLog 인프라만 따르면 동작
 *
 * 새 IDV provider 추가 시 이 매트릭스가 통과해야 token-based 재시도 흐름이 깨지지 않음.
 *
 * @since 7.0.0-beta.4
 */
class EnforceIdentityPolicyTokenBypassTest extends TestCase
{
    use RefreshDatabase;

    private EnforceIdentityPolicy $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityPolicySeeder::class);
        $this->middleware = $this->app->make(EnforceIdentityPolicy::class);
    }

    /**
     * @return array<string, array{0: string, 1: string}> [policy_key, purpose]
     */
    public static function enforcedPolicyMatrix(): array
    {
        return [
            'signup_before_submit' => ['core.auth.signup_before_submit', 'signup'],
            'password_reset' => ['core.auth.password_reset', 'password_reset'],
        ];
    }

    /**
     * @return array<string, array{0: string}> provider id 매트릭스 — 인프라 규약을 따르는 한 provider 무관.
     */
    public static function providerMatrix(): array
    {
        return [
            'core.mail' => ['g7:core.mail'],
            'hypothetical.sms' => ['g7:plugin.sms'],
            'hypothetical.kcp' => ['g7:plugin.kcp'],
        ];
    }

    #[DataProvider('enforcedPolicyMatrix')]
    public function test_valid_token_bypasses_for_each_policy(string $policyKey, string $purpose): void
    {
        IdentityPolicy::where('key', $policyKey)->update(['enabled' => true]);
        $email = 'matrix-'.$purpose.'@example.com';
        $token = 'tok-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => $purpose,
            'channel' => 'email',
            'target_hash' => hash('sha256', $email),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
        ]);

        $request = Request::create('/api/test', 'POST', [
            'email' => $email,
            'verification_token' => $token,
        ]);

        $passed = false;
        $this->middleware->handle($request, function () use (&$passed) {
            $passed = true;

            return new \Illuminate\Http\Response('ok');
        }, $policyKey);

        $this->assertTrue($passed, "policy={$policyKey} 가 token 으로 통과하지 못함");
    }

    #[DataProvider('enforcedPolicyMatrix')]
    public function test_consumed_token_does_not_bypass(string $policyKey, string $purpose): void
    {
        IdentityPolicy::where('key', $policyKey)->update(['enabled' => true]);
        $email = 'consumed-'.$purpose.'@example.com';
        $token = 'tok-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => $purpose,
            'channel' => 'email',
            'target_hash' => hash('sha256', $email),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now()->subSeconds(2),
            'consumed_at' => now()->subSecond(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $request = Request::create('/api/test', 'POST', [
            'email' => $email,
            'verification_token' => $token,
        ]);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->middleware->handle($request, fn () => new \Illuminate\Http\Response('ok'), $policyKey);
    }

    #[DataProvider('enforcedPolicyMatrix')]
    public function test_token_with_wrong_purpose_does_not_bypass(string $policyKey, string $purpose): void
    {
        IdentityPolicy::where('key', $policyKey)->update(['enabled' => true]);
        $email = 'wrongp-'.$purpose.'@example.com';
        $token = 'tok-'.bin2hex(random_bytes(8));

        // 토큰은 sensitive_action 으로 발급됨 — 정책의 purpose 와 다름
        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'target_hash' => hash('sha256', $email),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
        ]);

        $request = Request::create('/api/test', 'POST', [
            'email' => $email,
            'verification_token' => $token,
        ]);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->middleware->handle($request, fn () => new \Illuminate\Http\Response('ok'), $policyKey);
    }

    #[DataProvider('enforcedPolicyMatrix')]
    public function test_unverified_status_does_not_bypass(string $policyKey, string $purpose): void
    {
        IdentityPolicy::where('key', $policyKey)->update(['enabled' => true]);
        $email = 'unver-'.$purpose.'@example.com';
        $token = 'tok-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => $purpose,
            'channel' => 'email',
            'target_hash' => hash('sha256', $email),
            // verified 이전 단계 — verify 미완료
            'status' => IdentityVerificationStatus::Sent->value,
            'verification_token' => $token,
            'expires_at' => now()->addMinutes(15),
        ]);

        $request = Request::create('/api/test', 'POST', [
            'email' => $email,
            'verification_token' => $token,
        ]);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->middleware->handle($request, fn () => new \Illuminate\Http\Response('ok'), $policyKey);
    }

    public function test_forged_token_not_in_db_does_not_bypass(): void
    {
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => true]);

        $request = Request::create('/api/test', 'POST', [
            'email' => 'victim@example.com',
            'verification_token' => 'forged-'.bin2hex(random_bytes(16)),
        ]);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->middleware->handle($request, fn () => new \Illuminate\Http\Response('ok'), 'core.auth.signup_before_submit');
    }

    public function test_token_target_mismatch_does_not_bypass(): void
    {
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => true]);
        $token = 'tok-'.bin2hex(random_bytes(8));

        // 토큰은 a@example.com 으로 발급
        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'target_hash' => hash('sha256', 'a@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
        ]);

        // 공격자가 b@example.com 로 hijack 시도
        $request = Request::create('/api/test', 'POST', [
            'email' => 'b@example.com',
            'verification_token' => $token,
        ]);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->middleware->handle($request, fn () => new \Illuminate\Http\Response('ok'), 'core.auth.signup_before_submit');
    }

    /**
     * provider 비종속성 — provider_id 가 무엇이든 IdentityVerificationLog 인프라(verified/token/target_hash)만
     * 따르면 동일하게 통과. 새 provider(SMS/KCP/외부 SDK 등) 추가 시 이 동작이 자동 보장됨.
     *
     */
    #[DataProvider('providerMatrix')]
    public function test_token_bypass_is_provider_agnostic(string $providerId): void
    {
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => true]);
        $email = 'p-'.md5($providerId).'@example.com';
        $token = 'tok-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => $providerId,
            'purpose' => 'signup',
            'channel' => 'sms',
            'target_hash' => hash('sha256', $email),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
        ]);

        $request = Request::create('/api/test', 'POST', [
            'email' => $email,
            'verification_token' => $token,
        ]);

        $passed = false;
        $this->middleware->handle($request, function () use (&$passed) {
            $passed = true;

            return new \Illuminate\Http\Response('ok');
        }, 'core.auth.signup_before_submit');

        $this->assertTrue($passed, "provider={$providerId} 토큰 우회가 동작하지 않음");
    }
}
