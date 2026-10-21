<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Signup;

use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityMessageDispatcher;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;
use Tests\Support\Concerns\CapturesSentMail;

/**
 * 소셜 가입의 이메일 확인 — 코어 본인인증 메일(목적 signup, 대상 = 입력 이메일)로 인증번호를 보내고 확인한다.
 * 가입 완료는 인증번호로 확인한 그 이메일만 받는다.
 *
 * @since 1.0.0
 */
class EmailVerificationTest extends PluginTestCase
{
    use CapturesSentMail;

    /** @var string 이메일 없는 카카오 계정의 가입 대기 토큰 */
    private string $token;

    /**
     * 이메일 없는 카카오 계정으로 가입 대기를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        $this->prepareIdentityNotifications();
        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);
        $this->enableKakao();

        $response = $this->kakaoRoundTrip($this->kakaoProfile('k-ev', null));
        $this->token = $this->redirectQuery($response)['social_pending'];
    }

    /**
     * 인증번호를 요청합니다.
     *
     * @param  string  $email  이메일
     * @return TestResponse 응답
     */
    private function requestCode(string $email): TestResponse
    {
        return $this->postJson('/api/plugins/sirsoft-social_login/signup/email/request', ['pending_token' => $this->token, 'email' => $email]);
    }

    /**
     * 인증번호를 확인합니다.
     *
     * @param  string  $challengeId  요청 ID
     * @param  string  $code  인증번호
     * @return TestResponse 응답
     */
    private function verifyCode(string $challengeId, string $code): TestResponse
    {
        return $this->postJson('/api/plugins/sirsoft-social_login/signup/email/verify', ['pending_token' => $this->token, 'challenge_id' => $challengeId, 'code' => $code]);
    }

    /**
     * 인증번호 요청은 코어 본인인증 요청 행(목적 signup, 대상 = 입력 이메일 해시)이고, 확인하면 그 이메일로 가입된다.
     *
     * @scenario email=input, code=correct
     *
     * @effects signup_code_is_core_challenge, verified_email_used_for_signup, confirmed_email_marked_verified
     *
     * @return void 반환값 없음
     */
    public function test_code_is_core_challenge_and_verified_email_signs_up(): void
    {
        $email = $this->uniqueEmail('ev');

        $challengeId = (string) $this->requestCode($email)->assertOk()->json('data.challenge_id');

        $log = IdentityVerificationLog::query()->findOrFail($challengeId);
        $this->assertSame('signup', $log->purpose);
        $this->assertSame('g7:core.mail', $log->provider_id);
        $this->assertSame(hash('sha256', mb_strtolower($email)), $log->target_hash);

        $this->verifyCode($challengeId, $this->plantCode($challengeId))->assertOk()->assertJsonPath('data.verified_email', $email);

        $this->postJson('/api/plugins/sirsoft-social_login/signup/complete', [
            'pending_token' => $this->token, 'email' => $email, 'agree_terms' => true, 'agree_privacy' => true,
        ])->assertOk();
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertNotNull($user->email_verified_at, '인증번호로 확인한 이메일은 인증된 이메일로 기록한다');
    }

    /**
     * 이미 가입된 이메일은 인증번호를 보내지 않고, 틀린 인증번호·다른 요청 ID 는 거절한다.
     *
     * @scenario email=taken|input, code=wrong|other_challenge
     *
     * @effects taken_email_refused, wrong_code_refused, challenge_mismatch_refused
     *
     * @return void 반환값 없음
     */
    public function test_taken_email_and_wrong_codes_are_refused(): void
    {
        $taken = User::factory()->create(['email' => $this->uniqueEmail('taken')]);
        $this->requestCode($taken->email)->assertStatus(422)->assertJsonPath('errors.code', 'email_taken');

        $challengeId = (string) $this->requestCode($this->uniqueEmail('ev2'))->assertOk()->json('data.challenge_id');
        $this->plantCode($challengeId);

        $this->verifyCode($challengeId, '000000')->assertStatus(422)->assertJsonPath('errors.code', 'invalid_code');
        $this->verifyCode('00000000-0000-0000-0000-000000000000', '135790')->assertStatus(422)->assertJsonPath('errors.code', 'challenge_mismatch');
    }

    /**
     * 인증번호 유효 시간이 지나면 맞는 번호를 넣어도 확인되지 않고, 확인 이메일도 생기지 않는다.
     *
     * @scenario email=input, code=expired
     *
     * @effects expired_code_refused
     *
     * @return void 반환값 없음
     */
    public function test_expired_code_is_refused(): void
    {
        $email = $this->uniqueEmail('ev-exp');
        $challengeId = (string) $this->requestCode($email)->assertOk()->json('data.challenge_id');
        $code = $this->plantCode($challengeId);
        $expiresAt = IdentityVerificationLog::query()->findOrFail($challengeId)->expires_at;
        $this->assertNotNull($expiresAt);

        $this->travelTo($expiresAt->copy()->addSecond());

        $this->verifyCode($challengeId, $code)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_code');
        $this->getJson('/api/plugins/sirsoft-social_login/signup/pending?pending_token='.$this->token)
            ->assertOk()->assertJsonPath('data.email.verified', false);
    }

    /**
     * 가입 완료의 이메일이 확인한 이메일과 다르면 거절한다(다른 주소로 바꿔치기 금지).
     *
     * @scenario email=verified_then_changed
     *
     * @effects completion_email_must_match_verified
     *
     * @return void 반환값 없음
     */
    public function test_completion_email_must_match_verified_email(): void
    {
        $email = $this->uniqueEmail('ev3');
        $challengeId = (string) $this->requestCode($email)->json('data.challenge_id');
        $this->verifyCode($challengeId, $this->plantCode($challengeId))->assertOk();

        $this->postJson('/api/plugins/sirsoft-social_login/signup/complete', [
            'pending_token' => $this->token, 'email' => $this->uniqueEmail('swap'), 'agree_terms' => true, 'agree_privacy' => true,
        ])->assertStatus(422)->assertJsonPath('errors.code', 'email_not_verified');
    }

    /**
     * 제공자 이메일이 확정된 가입(검증된 이메일)은 이메일 입력·인증번호 요청을 받지 않는다.
     *
     * @scenario email=locked
     *
     * @effects locked_email_refuses_code
     *
     * @return void 반환값 없음
     */
    public function test_locked_email_refuses_code_request(): void
    {
        $response = $this->kakaoRoundTrip($this->kakaoProfile('k-ev-locked', $this->uniqueEmail('locked')));
        $this->token = $this->redirectQuery($response)['social_pending'];

        $this->requestCode($this->uniqueEmail('other'))->assertStatus(422)->assertJsonPath('errors.code', 'email_locked');
    }

    /**
     * 인증번호 메일을 보내지 못하면 「보냈습니다」가 아니라 503 과 관리자 문의 안내로 답하고, 시도 기록에 `mail_failed` 를 남긴다.
     * 메일 설정이 깨진 사이트에서 가입자가 오지 않을 인증번호를 기다리지 않게 하고, 운영자가 원인을 찾을 수 있게 한다.
     *
     * @scenario email=input, mail=failed
     *
     * @effects signup_mail_failure_surfaced
     *
     * @return void 반환값 없음
     */
    public function test_mail_failure_is_reported_not_swallowed(): void
    {
        $this->mock(IdentityMessageDispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andReturn(false));

        $response = $this->requestCode($this->uniqueEmail('ev-fail'))->assertStatus(503);

        $this->assertSame('mail_failed', $response->json('errors.code'));
        $this->assertSame(__('sirsoft-social_login::exceptions.flow.mail_failed'), $response->json('message'));
        $this->assertTrue(SocialLoginAttempt::query()->where('provider_key', 'kakao')->where('error_code', 'mail_failed')->exists());
    }
}
