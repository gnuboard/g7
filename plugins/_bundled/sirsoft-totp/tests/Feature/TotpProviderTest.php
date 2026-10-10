<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationChannel;
use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Identity\TotpIdentityProvider;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;
use Plugins\Sirsoft\Totp\Support\Totp;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * TOTP 프로바이더의 코어 IDV 계약 준수 테스트.
 *
 * 이 프로바이더는 **아무것도 발송하지 않으면서** 메일 프로바이더와 같은 계약을 따라야
 * 합니다. 상태 가드 시퀀스가 어긋나면 코어와 프론트가 두 수단을 다르게 다루게 되고,
 * 특히 challenge 를 `Sent` 로 기록하지 않으면 코어가 "발송 실패" 로 읽어 로그인을 막습니다.
 *
 * @since 1.0.0
 */
class TotpProviderTest extends PluginTestCase
{
    private User $user;

    private TotpIdentityProvider $provider;

    private string $secret;

    /**
     * 테스트 환경을 준비합니다 — 확정된 자격증명을 가진 사용자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'totp-provider@test.com']);

        $enrollment = app(TotpEnrollmentService::class);
        $this->secret = $enrollment->start($this->user, 'G7 Test')['secret'];
        $enrollment->confirm($this->user, Totp::codeAt($this->secret, Totp::timestepAt()));

        $this->provider = app(TotpIdentityProvider::class);
    }

    /**
     * 로그인 목적의 challenge 를 발행하고 그 id 를 돌려줍니다.
     *
     * @return string 발행된 challenge UUID
     */
    private function issueChallenge(): string
    {
        return $this->provider->requestChallenge($this->user, [
            'purpose' => IdentityVerificationPurpose::Login->value,
            'origin_type' => 'route',
            'origin_identifier' => 'auth.login',
        ])->id;
    }

    /**
     * 현재 유효한 코드를 만듭니다 (리플레이 방지를 피해 다음 timestep 사용).
     *
     * @param  int  $offset  timestep 오프셋
     * @return string 6자리 코드
     */
    private function codeAt(int $offset = 1): string
    {
        return Totp::codeAt($this->secret, Totp::timestepAt() + $offset);
    }

    /**
     * 프로바이더가 app 채널을 선언하고 로그인 목적만 지원하는지 확인합니다.
     *
     * @scenario provider=totp, contract=identity
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_declares_the_app_channel_for_the_login_purpose_only(): void
    {
        $this->assertSame('totp', $this->provider->getId());
        $this->assertSame([IdentityVerificationChannel::App->value], $this->provider->getChannels());
        $this->assertTrue($this->provider->supportsPurpose(IdentityVerificationPurpose::Login->value));

        // 가입·비밀번호 재설정은 아직 자격증명이 없는 시점이라 이 수단으로 확인할 수 없다.
        $this->assertFalse($this->provider->supportsPurpose(IdentityVerificationPurpose::Signup->value));
        $this->assertFalse($this->provider->supportsPurpose(IdentityVerificationPurpose::PasswordReset->value));
    }

    /**
     * 확정된 자격증명이 있는 사용자에게만 challenge 를 발행할 수 있다고 답하는지 확인합니다.
     *
     * @scenario provider=totp, user_scope=confirmed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_can_only_challenge_users_with_a_confirmed_credential(): void
    {
        $this->assertTrue($this->provider->canChallengeUser($this->user));

        $stranger = User::factory()->create(['email' => 'no-totp@test.com']);
        $this->assertFalse($this->provider->canChallengeUser($stranger));

        // 발급만 하고 확정하지 않은 사용자도 대상이 아니다 — 앱에 시크릿이 들어갔다는 증거가 없다.
        $pending = User::factory()->create(['email' => 'pending-totp@test.com']);
        app(TotpEnrollmentService::class)->start($pending, 'G7 Test');
        $this->assertFalse($this->provider->canChallengeUser($pending));
    }

    /**
     * 발송 단계가 없는 수단이므로 challenge 를 Sent 로 즉시 기록해 코어가 발송 실패로 읽지 않는지 확인합니다.
     *
     * @scenario provider=totp, challenge=request
     *
     * @effects challenge_is_recorded_as_sent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function requesting_a_challenge_records_it_as_sent_immediately(): void
    {
        $challengeId = $this->issueChallenge();

        $log = IdentityVerificationLog::find($challengeId);

        $this->assertNotNull($log);
        // 발송 단계가 없다 — Requested 로 두면 코어가 "발송 실패" 로 읽어 로그인을 차단한다.
        $this->assertSame(IdentityVerificationStatus::Sent->value, $log->status->value);
        $this->assertSame(IdentityVerificationChannel::App->value, $log->channel);
        $this->assertSame((int) $this->user->id, (int) $log->user_id);
    }

    /**
     * 미등록 사용자에게 완료할 수 없는 challenge 를 만들지 않는지 확인합니다.
     *
     * @scenario provider=totp, challenge=request, subject=unenrolled
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function requesting_a_challenge_for_an_unenrolled_user_is_refused(): void
    {
        $stranger = User::factory()->create(['email' => 'stranger@test.com']);

        $this->expectException(\InvalidArgumentException::class);

        // 완료할 수 없는 challenge 를 만들면 그 계정은 그대로 잠긴다.
        $this->provider->requestChallenge($stranger, ['purpose' => IdentityVerificationPurpose::Login->value]);
    }

    /**
     * 올바른 앱 코드가 challenge 를 검증 완료 상태로 바꾸는지 확인합니다.
     *
     * @scenario provider=totp, verify=valid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_valid_app_code_verifies_the_challenge(): void
    {
        $challengeId = $this->issueChallenge();

        $result = $this->provider->verify($challengeId, ['code' => $this->codeAt()]);

        $this->assertTrue($result->success);
        $this->assertSame(IdentityVerificationStatus::Verified->value, IdentityVerificationLog::find($challengeId)->status->value);
    }

    /**
     * 한 번 쓴 코드를 새 challenge 로 재사용할 수 없는지 확인합니다.
     *
     * @scenario provider=totp, verify=replay
     *
     * @effects a_used_code_cannot_be_reused_on_a_new_challenge
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_same_code_cannot_verify_a_second_challenge(): void
    {
        $code = $this->codeAt();

        $this->assertTrue($this->provider->verify($this->issueChallenge(), ['code' => $code])->success);

        // 같은 코드를 새 challenge 로 다시 제출해도 통과하면 안 된다 — 어깨너머로 본 코드가
        // 30초 동안 재사용 가능해진다.
        $second = $this->provider->verify($this->issueChallenge(), ['code' => $code]);

        $this->assertFalse($second->success);
        $this->assertSame('INVALID_CODE', $second->failureCode);
    }

    /**
     * 백업 코드로도 검증이 되고 그 코드가 1회용으로 소진되는지 확인합니다.
     *
     * @scenario provider=totp, verify=backup_code
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_backup_code_also_verifies_the_challenge(): void
    {
        $codes = app(TotpEnrollmentService::class)->regenerateBackupCodes($this->user, $this->codeAt());

        $result = $this->provider->verify($this->issueChallenge(), ['code' => $codes[0]]);

        $this->assertTrue($result->success);

        // 1회용 — 두 번째 challenge 에는 같은 백업 코드가 통하지 않아야 한다.
        $this->assertFalse($this->provider->verify($this->issueChallenge(), ['code' => $codes[0]])->success);
    }

    /**
     * 틀린 코드가 검증을 통과하지 못하고 시도 횟수만 오르는지 확인합니다.
     *
     * @scenario provider=totp, verify=invalid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_wrong_code_fails_without_verifying(): void
    {
        $challengeId = $this->issueChallenge();

        $result = $this->provider->verify($challengeId, ['code' => '000000']);

        $this->assertFalse($result->success);
        $this->assertSame('INVALID_CODE', $result->failureCode);
        $this->assertSame(1, IdentityVerificationLog::find($challengeId)->attempts);
    }

    /**
     * 시도를 소진하면 challenge 가 잠기고 그 뒤에는 올바른 코드도 통하지 않는지 확인합니다.
     *
     * @scenario provider=totp, verify=max_attempts
     *
     * @effects exhausted_challenge_is_failed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function exhausting_the_attempts_locks_the_challenge(): void
    {
        config(['settings.identity.max_attempts' => 3]);

        $challengeId = $this->issueChallenge();

        $this->provider->verify($challengeId, ['code' => '000000']);
        $this->provider->verify($challengeId, ['code' => '000000']);
        $last = $this->provider->verify($challengeId, ['code' => '000000']);

        // 막 소진된 응답에서 안내하지 않으면 그 문구가 사용자에게 영영 도달하지 못한다.
        $this->assertSame('MAX_ATTEMPTS', $last->failureCode);
        $this->assertSame(IdentityVerificationStatus::Failed->value, IdentityVerificationLog::find($challengeId)->status->value);

        // 잠긴 뒤에는 올바른 코드도 통하지 않아야 한다.
        $this->assertFalse($this->provider->verify($challengeId, ['code' => $this->codeAt()])->success);
    }

    /**
     * 만료된 challenge 가 검증되지 않고 만료 상태로 전환되는지 확인합니다.
     *
     * @scenario provider=totp, verify=expired
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_expired_challenge_cannot_be_verified(): void
    {
        $challengeId = $this->issueChallenge();

        app(IdentityVerificationLogRepositoryInterface::class)->updateById($challengeId, [
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $result = $this->provider->verify($challengeId, ['code' => $this->codeAt()]);

        $this->assertFalse($result->success);
        $this->assertSame('EXPIRED', $result->failureCode);
        $this->assertSame(IdentityVerificationStatus::Expired->value, IdentityVerificationLog::find($challengeId)->status->value);
    }

    /**
     * 다른 프로바이더가 발행한 challenge 를 가로채 검증하지 않는지 확인합니다.
     *
     * @scenario provider=totp, verify=wrong_provider
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_refuses_a_challenge_issued_by_another_provider(): void
    {
        $log = app(IdentityVerificationLogRepositoryInterface::class)->create([
            'provider_id' => 'g7:core.mail',
            'purpose' => IdentityVerificationPurpose::Login->value,
            'channel' => IdentityVerificationChannel::Email->value,
            'user_id' => $this->user->id,
            'target_hash' => hash('sha256', 'x'),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'metadata' => [],
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $result = $this->provider->verify($log->id, ['code' => $this->codeAt()]);

        $this->assertFalse($result->success);
        $this->assertSame('WRONG_PROVIDER', $result->failureCode);
    }

    /**
     * 존재하지 않는 challenge 에 대해 정해진 실패 코드로 답하는지 확인합니다.
     *
     * @scenario provider=totp, verify=not_found
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_unknown_challenge_is_reported_as_not_found(): void
    {
        $result = $this->provider->verify('00000000-0000-0000-0000-000000000000', ['code' => '123456']);

        $this->assertFalse($result->success);
        $this->assertSame('NOT_FOUND', $result->failureCode);
    }

    /**
     * 취소된 challenge 가 다시 검증되지 않는지 확인합니다.
     *
     * @scenario provider=totp, verify=cancelled
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_cancelled_challenge_cannot_be_verified(): void
    {
        $challengeId = $this->issueChallenge();

        $this->assertTrue($this->provider->cancel($challengeId));

        $result = $this->provider->verify($challengeId, ['code' => $this->codeAt()]);

        $this->assertFalse($result->success);
        $this->assertSame('INVALID_STATE', $result->failureCode);
    }

    /**
     * 메일 폴백 허용 여부가 플러그인 설정값을 그대로 따르는지 확인합니다.
     *
     * @scenario provider=totp, mail_fallback=setting
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_mail_fallback_answer_follows_the_plugin_setting(): void
    {
        // 기본은 허용 — 미등록 관리자의 콜드스타트 락아웃을 구조적으로 배제한다.
        $this->assertTrue($this->provider->allowsMailFallback());

        $this->assertFalse($this->provider->withConfig(['allow_mail_fallback' => false])->allowsMailFallback());
        $this->assertTrue($this->provider->withConfig(['allow_mail_fallback' => true])->allowsMailFallback());
    }
}
