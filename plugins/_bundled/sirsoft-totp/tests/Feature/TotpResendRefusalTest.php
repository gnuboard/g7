<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Identity\TotpIdentityProvider;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;
use Plugins\Sirsoft\Totp\Support\Totp;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 인증 앱 2단계 challenge 재발송 거부 테스트 (KVE-2026-3195).
 *
 * 인증 앱은 코드를 보내지 않는데도 재발송이 새 challenge(시도 0회)를 만들어 줘서, 비밀번호를 아는 쪽이
 * 「오답 4회 → 재발송」을 되풀이하면 6자리 코드를 끝없이 맞혀 볼 수 있었다. 실제 TOTP 수단을 코어 관리자에
 * 등록하고 재발송 API 가 거부하는지, 기존 challenge 의 시도 횟수가 그대로인지 확인한다.
 *
 * @since 1.0.3
 */
class TotpResendRefusalTest extends PluginTestCase
{
    private User $user;

    private TotpIdentityProvider $provider;

    /**
     * 확정된 자격증명을 가진 회원과 등록된 TOTP 수단을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'totp-resend@test.com']);

        $enrollment = app(TotpEnrollmentService::class);
        $secret = $enrollment->start($this->user, 'G7 Test')['secret'];
        $enrollment->confirm($this->user, Totp::codeAt($secret, Totp::timestepAt()));

        $this->provider = app(TotpIdentityProvider::class);
        app(IdentityVerificationManager::class)->register($this->provider);
    }

    /**
     * 인증 앱 수단이 「코드를 보내지 않는 수단」으로 자신을 밝히는지 확인합니다.
     *
     * @scenario case=totp_declares_no_code_delivery
     *
     * @effects totp_declares_no_code_delivery
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_totp_provider_declares_that_it_does_not_deliver_codes(): void
    {
        $this->assertFalse($this->provider->deliversChallengeCode());
    }

    /**
     * 오답을 쌓은 인증 앱 challenge 의 재발송이 거부되고 시도 횟수가 초기화되지 않는지 확인합니다.
     *
     * @scenario case=totp_resend_refused
     *
     * @effects totp_resend_refused, totp_attempts_not_reset_by_resend
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function resending_a_totp_challenge_is_refused_and_keeps_the_attempt_count(): void
    {
        $challengeId = $this->provider->requestChallenge($this->user, [
            'purpose' => IdentityVerificationPurpose::Login->value,
            'origin_type' => 'route',
            'origin_identifier' => 'auth.login',
        ])->id;

        foreach (range(1, 4) as $_) {
            $this->provider->verify($challengeId, ['code' => '000000']);
        }
        $this->assertSame(4, (int) IdentityVerificationLog::find($challengeId)->attempts);

        $response = $this->postJson('/api/auth/login/two-factor/resend', ['challenge_id' => $challengeId]);

        $response->assertStatus(422);
        $this->assertSame(__('auth.two_factor_resend_not_supported'), $response->json('message'));

        $log = IdentityVerificationLog::find($challengeId);
        $this->assertSame(4, (int) $log->attempts, '재발송이 시도 횟수를 0 으로 되돌리면 무한 추측이 열린다.');
        $this->assertSame(IdentityVerificationStatus::Sent, $log->status);
        $this->assertSame(
            1,
            IdentityVerificationLog::query()->where('user_id', $this->user->id)->where('purpose', 'login')->count(),
            '재발송이 새 challenge 를 만들면 안 된다.',
        );
    }
}
