<?php

namespace Tests\Feature\Auth;

use App\Contracts\Extension\ChallengeDeliveryAwareProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 로그인 2단계 재발송 규칙을 검증한다 (KVE-2026-3195·동형 A2).
 *
 * - 코드를 보내지 않는 수단(인증 앱)은 재발송을 거부한다 — 재발송이 새 challenge 로 시도 상한을 되돌리는 통로였다.
 * - 메일 재발송은 공개 인증 요청과 같은 60초 대기를 지킨다 — 연타로 메일을 쏟아내지 못하게.
 */
class TwoFactorResendPolicyTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd!2fa';

    private User $user;

    /**
     * 2단계 인증을 켜고 회원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepareIdentityNotifications();
        config(['g7_settings.core.security.two_factor_auth' => true]);

        $this->user = User::factory()->create([
            'email' => 'resend-2fa@example.test',
            'password' => Hash::make(self::PASSWORD),
            'status' => UserStatus::Active->value,
        ]);
    }

    /**
     * 주어진 수단·채널의 로그인 challenge 를 직접 만듭니다.
     *
     * @param  string  $providerId  수단 ID
     * @param  string  $channel  채널
     * @return IdentityVerificationLog 만든 로그인 challenge 로그
     */
    private function loginChallenge(string $providerId, string $channel): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => $providerId,
            'purpose' => 'login',
            'channel' => $channel,
            'user_id' => $this->user->id,
            'target_hash' => hash('sha256', $this->user->email),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 3,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    /**
     * 코드를 보내지 않는 수단(보조 계약 deliversChallengeCode=false)의 challenge 는 재발송하지 않는다.
     *
     * @effects non_delivering_provider_resend_refused
     *
     * @return void 반환값 없음
     */
    public function test_resend_refused_for_provider_without_delivery(): void
    {
        $provider = new class(app(IdentityVerificationLogRepositoryInterface::class)) extends TestIdentityProvider implements ChallengeDeliveryAwareProviderInterface
        {
            /**
             * 대역 수단 ID 를 돌려줍니다.
             *
             * @return string 수단 ID
             */
            public function getId(): string
            {
                return 'test:no_delivery';
            }

            /**
             * 코드를 보내지 않는 수단입니다.
             *
             * @return bool 항상 false
             */
            public function deliversChallengeCode(): bool
            {
                return false;
            }
        };
        app(IdentityVerificationManager::class)->register($provider);
        $log = $this->loginChallenge('test:no_delivery', 'email');

        $response = $this->postJson('/api/auth/login/two-factor/resend', ['challenge_id' => $log->id]);

        $response->assertStatus(422);
        $this->assertSame(__('auth.two_factor_resend_not_supported'), $response->json('message'));
        $this->assertSame(3, (int) $log->fresh()->attempts);
        $this->assertSame(IdentityVerificationStatus::Sent, $log->fresh()->status);
    }

    /**
     * 보조 계약이 없는 구버전 수단이라도 채널이 app 이면 재발송하지 않는다.
     *
     * @effects non_delivering_provider_resend_refused
     *
     * @return void 반환값 없음
     */
    public function test_resend_refused_for_app_channel(): void
    {
        app(IdentityVerificationManager::class)->register(new TestIdentityProvider(app(IdentityVerificationLogRepositoryInterface::class)));
        $log = $this->loginChallenge(TestIdentityProvider::ID, 'app');

        $this->postJson('/api/auth/login/two-factor/resend', ['challenge_id' => $log->id])->assertStatus(422);
        $this->assertSame(IdentityVerificationStatus::Sent, $log->fresh()->status);
    }

    /**
     * 메일 재발송은 60초 안에 다시 요청하면 429 와 Retry-After 를 받고, 지난 뒤에는 새 challenge 를 받는다.
     *
     * @effects mail_resend_cooldown
     *
     * @return void 반환값 없음
     */
    public function test_mail_resend_respects_cooldown(): void
    {
        $challengeId = (string) $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => self::PASSWORD])->json('data.challenge_id');

        $early = $this->postJson('/api/auth/login/two-factor/resend', ['challenge_id' => $challengeId]);
        $early->assertStatus(429);
        $this->assertNotNull($early->headers->get('Retry-After'));
        $this->assertSame(IdentityVerificationStatus::Sent, IdentityVerificationLog::findOrFail($challengeId)->status);

        $this->travel(61)->seconds();

        $later = $this->postJson('/api/auth/login/two-factor/resend', ['challenge_id' => $challengeId]);
        $later->assertOk();
        $this->assertNotSame($challengeId, $later->json('data.challenge_id'));
    }
}
