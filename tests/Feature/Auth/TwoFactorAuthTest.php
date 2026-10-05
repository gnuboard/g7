<?php

namespace Tests\Feature\Auth;

use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Models\IdentityVerificationLog;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * [case:backend-30] 로그인 2단계 인증 테스트
 *
 * `security.two_factor_auth` 는 설정 화면에만 있고 구현이 없어, 켜도 아무 일도 일어나지
 * 않았습니다. 관리자는 2단계 인증이 걸린 줄 알지만 실제로는 비밀번호 하나로 로그인됩니다.
 *
 * 코어 본인인증(IDV) 인프라를 재사용하며, 이 흐름의 불변조건은 하나입니다 —
 * **코드 확인을 마치기 전에는 토큰이 발급되지 않는다.**
 */
class TwoFactorAuthTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    private User $user;

    /**
     * 메일을 격리하고 본인인증 알림 정의·메일 채널을 준비한 뒤 테스트 사용자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // 인증번호 메일은 알림 시스템(본인인증 알림 정의 + 메일 채널)을 거친다. 실제 설치에는 정의가 있고
        // 메일이 설정돼 있지만 RefreshDatabase 직후에는 둘 다 없으므로, 그대로 두면 "코드 발송 실패" 가
        // 되어 게이트 동작이 아니라 준비 부재를 측정하게 된다.
        $this->prepareIdentityNotifications();

        $this->user = User::factory()->create([
            'email' => 'two-factor@test.com',
            'password' => Hash::make('Passw0rd!2fa'),
            'status' => UserStatus::Active->value,
        ]);
    }

    /**
     * 2단계 인증 설정을 켜거나 끕니다.
     *
     * @param  bool  $enabled  활성화 여부
     * @return void 반환값 없음
     */
    private function setTwoFactor(bool $enabled): void
    {
        config(['g7_settings.core.security.two_factor_auth' => $enabled]);
    }

    /**
     * 로그인을 시도합니다.
     *
     * @return TestResponse 로그인 응답
     */
    private function login()
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'two-factor@test.com',
            'password' => 'Passw0rd!2fa',
        ]);
    }

    /**
     * 2단계 인증이 꺼져 있으면 비밀번호 로그인이 곧바로 토큰을 발급한다.
     *
     * @scenario two_factor=off, controller=user
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function login_issues_a_token_directly_when_two_factor_is_off(): void
    {
        $this->setTwoFactor(false);

        $response = $this->login();

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'), '2단계 인증이 꺼져 있으면 종전대로 바로 로그인되어야 합니다.');
    }

    /**
     * 2단계 인증이 켜져 있으면 로그인 응답은 토큰 없이 two_factor_required 와 challenge_id 만 돌려준다.
     *
     * @scenario two_factor=on, controller=user, delivery=sent
     *
     * @effects challenge_response_has_no_token, challenge_response_has_no_user
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function login_withholds_the_token_when_two_factor_is_on(): void
    {
        $this->setTwoFactor(true);

        $response = $this->login();

        $response->assertStatus(200);

        $this->assertTrue(
            (bool) $response->json('data.two_factor_required'),
            '2단계 인증이 켜져 있는데 추가 확인 없이 응답했습니다.'
        );
        $this->assertNull(
            $response->json('data.token'),
            '코드 확인 전에 토큰이 발급되면 2단계 인증이 없는 것과 같습니다.'
        );
        $this->assertNotEmpty($response->json('data.challenge_id'));
    }

    /**
     * 발급된 challenge 의 코드를 맞히면 토큰과 사용자 정보가 발급된다.
     *
     * @scenario two_factor=on, controller=user, code=valid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function verifying_the_challenge_completes_the_login(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->login()->json('data.challenge_id');

        $response = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->issuedCode($challengeId),
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'), '코드 확인에 성공했는데 토큰이 발급되지 않았습니다.');
        $this->assertSame('two-factor@test.com', $response->json('data.user.email'));
    }

    /**
     * 틀린 코드로 2단계 인증을 완료하려 하면 401 이고 토큰이 없다.
     *
     * @scenario two_factor=on, controller=user, code=invalid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_wrong_code_does_not_issue_a_token(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->login()->json('data.challenge_id');

        $response = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => '000000',
        ]);

        $response->assertStatus(401);
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 비밀번호 재설정 등 다른 목적으로 발급된 challenge 로는 코드가 맞아도 로그인할 수 없다(401).
     *
     * @scenario two_factor=on, controller=user, code=invalid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_challenge_issued_for_another_purpose_cannot_be_used_to_log_in(): void
    {
        $this->setTwoFactor(true);

        // 가입·비밀번호 재설정 등 다른 용도로 발급된 challenge 를 들고 와 로그인할 수 있으면,
        // 그 흐름 하나만 통과해도 남의 계정에 들어갈 수 있다.
        $foreign = app(IdentityVerificationService::class)->start(
            IdentityVerificationPurpose::PasswordReset->value,
            $this->user,
            ['origin_type' => 'route', 'origin_identifier' => 'test']
        );

        $response = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $foreign->id,
            'code' => $this->issuedCode($foreign->id),
        ]);

        $response->assertStatus(401);
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 인증번호 메일 전송이 예외로 실패하면 401 이 아니라 503 과 발송 실패 문구로 답하고 토큰을 내주지 않는다.
     *
     * @scenario two_factor=on, controller=user, delivery=failed
     *
     * @effects delivery_failure_503_with_reason
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function login_fails_clearly_when_the_code_cannot_be_delivered(): void
    {
        $this->setTwoFactor(true);

        // 메일 발송이 실패하면 프로바이더가 challenge 를 failed 로 표시한다. 그 상태를 무시하면
        // 사용자는 "인증번호를 보냈습니다" 안내를 받고 완료할 수 없는 challenge 를 들고 막힌다.
        // 알림 메일 채널은 메일러를 `mailer()` 로 꺼낸다 — 거기서 전송 계층 예외를 낸다.
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('SMTP unavailable'));

        $response = $this->login();

        // 자격 증명은 올바르다 — 401 로 답하면 사용자는 비밀번호를 의심하며 같은 실패를
        // 반복하고, 운영자는 메일 설정이 깨진 사실을 알 방법이 없다.
        $response->assertStatus(503);
        $this->assertSame(__('auth.two_factor_delivery_failed'), $response->json('message'));
        $this->assertNull(
            $response->json('data.token'),
            '발송 실패 시 2단계 인증을 건너뛰고 로그인시키면 보안 통제가 조용히 열립니다.'
        );
    }

    /**
     * 「알림 채널 관리」에서 메일을 끄면 인증번호를 보내지 않는다 — 503, 발송 로그 skipped, 토큰 없음.
     *
     * 7.1.0 전에는 본인인증 메일이 채널 토글을 무시하고 나갔다(#699). 이제 토글을 따르므로, 메일을 끈
     * 사이트에서 TOTP 를 등록하지 않은 계정은 로그인할 수 없다(관리 화면이 끄기 전에 확인을 받는다).
     *
     * @scenario two_factor=on, controller=user, delivery=failed
     *
     * @effects mail_channel_off_blocks_two_factor_503
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function login_fails_with_503_when_the_mail_channel_is_turned_off(): void
    {
        $this->setTwoFactor(true);
        $this->setCoreMailChannelActive(false);

        $response = $this->login();

        $response->assertStatus(503);
        $this->assertSame(__('auth.two_factor_delivery_failed'), $response->json('message'));
        $this->assertNull($response->json('data.token'));

        $log = NotificationLog::where('notification_type', 'identity.purpose.login')->latest('id')->first();
        $this->assertNotNull($log, '메일을 끈 사이트의 본인인증 발송 시도가 발송 로그에 남아야 한다.');
        $this->assertSame('skipped', $log->status->value ?? $log->status);
        $this->assertSame(__('notification.channel_disabled_by_extension'), $log->error_message);
    }

    /**
     * 로그인 인증번호는 로그인 전용 정의(identity.purpose.login)로 나간다 — 범용 문구가 아니다.
     *
     * @scenario two_factor=on, controller=user, delivery=sent
     *
     * @effects login_code_uses_login_definition
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function login_code_is_sent_with_the_login_definition(): void
    {
        $this->setTwoFactor(true);

        $this->login()->assertStatus(200);

        $log = NotificationLog::where('channel', 'mail')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('identity.purpose.login', $log->notification_type);
        $this->assertSame('sent', $log->status->value ?? $log->status);
        $this->assertSame($this->user->id, (int) $log->recipient_user_id);
    }

    /**
     * 관리자 로그인도 2단계 인증이 켜져 있으면 500 없이 토큰 대신 challenge 를 돌려준다.
     *
     * @scenario two_factor=on, controller=admin, actor=admin, delivery=sent
     *
     * @effects admin_login_returns_challenge_not_500
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_login_returns_a_challenge_instead_of_failing(): void
    {
        $this->setTwoFactor(true);
        $this->makeAdmin($this->user);

        $response = $this->postJson('/api/auth/admin/login', [
            'email' => 'two-factor@test.com',
            'password' => 'Passw0rd!2fa',
        ]);

        // 관리자 로그인이 2단계 인증에서 500 이 되면 설정을 되돌릴 수단까지 사라진다.
        $response->assertStatus(200);
        $this->assertTrue((bool) $response->json('data.two_factor_required'));
        $this->assertNull($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.challenge_id'));
    }

    /**
     * 관리자가 관리자 2단계 인증 경로에서 코드를 맞히면 토큰이 발급된다.
     *
     * @scenario two_factor=on, controller=admin, actor=admin, code=valid
     *
     * @effects admin_two_factor_issues_token_for_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_two_factor_completes_the_login(): void
    {
        $this->setTwoFactor(true);
        $this->makeAdmin($this->user);

        $challengeId = $this->postJson('/api/auth/admin/login', [
            'email' => 'two-factor@test.com',
            'password' => 'Passw0rd!2fa',
        ])->json('data.challenge_id');

        $response = $this->postJson('/api/auth/admin/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->issuedCode($challengeId),
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * 관리자가 아닌 사용자가 관리자 2단계 인증을 완료하면 403 이고 발급된 토큰이 회수돼 남지 않는다.
     *
     * @scenario two_factor=on, controller=admin, actor=non_admin, code=valid
     *
     * @effects non_admin_two_factor_revokes_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_non_admin_completing_admin_two_factor_keeps_no_token(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->postJson('/api/auth/admin/login', [
            'email' => 'two-factor@test.com',
            'password' => 'Passw0rd!2fa',
        ])->json('data.challenge_id');

        $response = $this->postJson('/api/auth/admin/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->issuedCode($challengeId),
        ]);

        $response->assertStatus(403);
        // 코드 확인 시점에 토큰이 이미 발급된다 — 회수하지 않으면 관리자가 아닌 사용자가
        // 응답만 403 을 받을 뿐 유효한 세션을 손에 쥔다.
        $this->assertSame(0, $this->user->tokens()->count());
    }

    /**
     * 2단계 인증이 꺼진 상태에서 관리자가 아닌 사용자의 관리자 로그인은 403 이고 토큰이 남지 않는다.
     *
     * @scenario two_factor=off, controller=admin, actor=non_admin
     *
     * @effects non_admin_admin_login_revokes_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_non_admin_rejected_at_admin_login_keeps_no_token(): void
    {
        $this->setTwoFactor(false);

        $response = $this->postJson('/api/auth/admin/login', [
            'email' => 'two-factor@test.com',
            'password' => 'Passw0rd!2fa',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, $this->user->tokens()->count());
    }

    /**
     * 재발송은 새 challenge 를 발급하고 앞선 challenge 를 cancelled 로 바꾸며 토큰은 내주지 않는다.
     *
     * @scenario two_factor=on, controller=user, resend=active
     *
     * @effects resend_cancels_previous_challenge
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function resending_cancels_the_previous_challenge_and_issues_a_new_one(): void
    {
        $this->setTwoFactor(true);

        $first = $this->login()->json('data.challenge_id');

        $response = $this->postJson('/api/auth/login/two-factor/resend', [
            'challenge_id' => $first,
        ]);

        $response->assertStatus(200);
        $second = $response->json('data.challenge_id');

        $this->assertNotEmpty($second);
        $this->assertNotSame($first, $second, '재발송이 같은 challenge 를 돌려주면 새 코드가 발송되지 않은 것입니다.');
        $this->assertNull($response->json('data.token'));

        // 앞선 코드가 계속 통하면 유효한 코드가 여러 개 살아 있어 대입 시도의 표적이 넓어진다.
        $this->assertSame(
            IdentityVerificationStatus::Cancelled->value,
            IdentityVerificationLog::find($first)->status->value
        );
    }

    /**
     * 이미 검증을 마친 challenge 는 재발송할 수 없다(422).
     *
     * @scenario two_factor=on, controller=user, resend=verified
     *
     * @effects resend_rejects_verified_challenge
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_verified_challenge_cannot_be_resent(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->login()->json('data.challenge_id');

        $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->issuedCode($challengeId),
        ])->assertStatus(200);

        $this->postJson('/api/auth/login/two-factor/resend', [
            'challenge_id' => $challengeId,
        ])->assertStatus(422);
    }

    /**
     * 만료된 challenge 는 재발송할 수 없다(422).
     *
     * @scenario two_factor=on, controller=user, resend=expired
     *
     * @effects resend_rejects_expired_challenge
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_expired_challenge_cannot_be_resent(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->login()->json('data.challenge_id');

        $log = IdentityVerificationLog::find($challengeId);
        $log->expires_at = now()->subMinute();
        $log->save();

        $this->postJson('/api/auth/login/two-factor/resend', [
            'challenge_id' => $challengeId,
        ])->assertStatus(422);
    }

    /**
     * 취소된 challenge 는 재발송할 수 없다(422).
     *
     * @scenario two_factor=on, controller=user, resend=cancelled
     *
     * @effects resend_rejects_cancelled_challenge
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_cancelled_challenge_cannot_be_resent(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->login()->json('data.challenge_id');

        app(IdentityVerificationService::class)->cancel($challengeId);

        $this->postJson('/api/auth/login/two-factor/resend', [
            'challenge_id' => $challengeId,
        ])->assertStatus(422);
    }

    /**
     * challenge 를 받은 뒤 계정이 잠기면 재발송이 423 으로 거절된다.
     *
     * @scenario two_factor=on, controller=user, resend=active, lock_state=active
     *
     * @effects resend_refused_while_locked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function resending_is_refused_while_the_account_is_locked(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->login()->json('data.challenge_id');

        // challenge 를 받아 둔 뒤 잠긴 계정에는 새 코드를 보내지 않는다.
        $this->user->forceFill(['locked_until' => now()->addMinutes(10)])->save();

        $this->postJson('/api/auth/login/two-factor/resend', [
            'challenge_id' => $challengeId,
        ])->assertStatus(423);
    }

    /**
     * 관리자는 관리자 재발송 경로로 새 challenge 를 받고, 응답에 토큰·사용자 정보는 실리지 않는다.
     *
     * @scenario two_factor=on, controller=admin, actor=admin, resend=active
     *
     * @effects admin_resend_returns_new_challenge
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_admin_can_resend_through_the_admin_endpoint(): void
    {
        $this->setTwoFactor(true);
        $this->makeAdmin($this->user);

        $challengeId = $this->postJson('/api/auth/admin/login', [
            'email' => 'two-factor@test.com',
            'password' => 'Passw0rd!2fa',
        ])->json('data.challenge_id');

        $response = $this->postJson('/api/auth/admin/login/two-factor/resend', [
            'challenge_id' => $challengeId,
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.challenge_id'));
        $this->assertNotSame($challengeId, $response->json('data.challenge_id'));
        $this->assertNull($response->json('data.token'));
        // 해석된 사용자는 관리자 등급 판정에만 쓰고 응답에는 실지 않는다.
        $this->assertNull($response->json('data.user'));
    }

    /**
     * 관리자가 아닌 사용자는 관리자 재발송 경로에서 403 을 받고 토큰이 남지 않는다.
     *
     * @scenario two_factor=on, controller=admin, actor=non_admin, resend=active
     *
     * @effects admin_resend_refuses_non_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_non_admin_cannot_resend_through_the_admin_endpoint(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->postJson('/api/auth/admin/login', [
            'email' => 'two-factor@test.com',
            'password' => 'Passw0rd!2fa',
        ])->json('data.challenge_id');

        // 완료할 수 없는 상대에게 새 인증번호를 계속 보내지 않는다.
        $this->postJson('/api/auth/admin/login/two-factor/resend', [
            'challenge_id' => $challengeId,
        ])->assertStatus(403);

        $this->assertSame(0, $this->user->tokens()->count());
    }

    /**
     * 사용자에게 admin 역할을 부여합니다.
     *
     * @param  User  $user  대상 사용자
     * @return void 반환값 없음
     */
    private function makeAdmin(User $user): void
    {
        // isAdmin() 은 역할 존재가 아니라 admin 타입 권한 보유로 판정한다 —
        // 역할만 만들어 붙이면 관리자로 인정되지 않아 403 을 측정하게 된다.
        $this->seed(RolePermissionSeeder::class);

        $role = Role::where('identifier', 'admin')->firstOrFail();

        $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now(), 'assigned_by' => null]]);
        $user->refresh();
    }

    /**
     * challenge 에 알려진 인증 코드를 심고 그 값을 돌려줍니다.
     *
     * 발급된 코드는 해시로만 저장되므로 되읽을 수 없습니다. 기존 IDV 테스트와 같은 방식으로
     * 알려진 값의 해시를 심어 "코드가 맞을 때" 를 재현합니다 — 검증 대상은 코드 생성이 아니라
     * 로그인 게이트(코드 확인 전 토큰 미발급 / 확인 후 발급)입니다.
     *
     * @param  string  $challengeId  인증 요청(challenge) UUID
     * @return string 심어 둔 인증 코드
     */
    private function issuedCode(string $challengeId): string
    {
        $code = '135790';

        $log = IdentityVerificationLog::find($challengeId);
        $metadata = $log->metadata ?? [];
        $metadata['code_hash'] = Hash::make($code);

        $log->metadata = $metadata;
        $log->save();

        return $code;
    }
}
