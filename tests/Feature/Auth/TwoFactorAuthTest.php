<?php

namespace Tests\Feature\Auth;

use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * [case:backend-29] 로그인 2단계 인증 테스트
 *
 * `security.two_factor_auth` 는 설정 화면에만 있고 구현이 없어, 켜도 아무 일도 일어나지
 * 않았습니다. 관리자는 2단계 인증이 걸린 줄 알지만 실제로는 비밀번호 하나로 로그인됩니다.
 *
 * 코어 본인인증(IDV) 인프라를 재사용하며, 이 흐름의 불변조건은 하나입니다 —
 * **코드 확인을 마치기 전에는 토큰이 발급되지 않는다.**
 */
class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // 인증번호 메일을 구성하려면 본인인증 메시지 정의가 있어야 한다. 실제 설치에는 있고
        // RefreshDatabase 직후에는 비어 있으므로, 없는 상태를 그대로 두면 "코드 발송 실패" 가 되어
        // 게이트 동작이 아니라 시드 부재를 측정하게 된다.
        $this->seed(IdentityMessageDefinitionSeeder::class);

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
     * @scenario two_factor=off, controller=user
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
     * @scenario two_factor=on, controller=user, delivery=sent
     *
     * @effects challenge_response_has_no_token, challenge_response_has_no_user
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
     * @scenario two_factor=on, controller=user, code=valid
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
     * @scenario two_factor=on, controller=user, code=invalid
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
     * @scenario two_factor=on, controller=user, code=invalid
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
     * @scenario two_factor=on, controller=user, delivery=failed
     *
     * @effects delivery_failure_503_with_reason
     */
    #[Test]
    public function login_fails_clearly_when_the_code_cannot_be_delivered(): void
    {
        $this->setTwoFactor(true);

        // 메일 발송이 실패하면 프로바이더가 challenge 를 failed 로 표시한다. 그 상태를 무시하면
        // 사용자는 "인증번호를 보냈습니다" 안내를 받고 완료할 수 없는 challenge 를 들고 막힌다.
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP unavailable'));
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unavailable'));

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
     * @scenario two_factor=on, controller=admin, actor=admin, delivery=sent
     *
     * @effects admin_login_returns_challenge_not_500
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
     * @scenario two_factor=on, controller=admin, actor=admin, code=valid
     *
     * @effects admin_two_factor_issues_token_for_admin
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
     * @scenario two_factor=on, controller=admin, actor=non_admin, code=valid
     *
     * @effects non_admin_two_factor_revokes_token
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
     * @scenario two_factor=off, controller=admin, actor=non_admin
     *
     * @effects non_admin_admin_login_revokes_token
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
     * @scenario two_factor=on, controller=user, resend=active
     *
     * @effects resend_cancels_previous_challenge
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
     * @scenario two_factor=on, controller=user, resend=verified
     *
     * @effects resend_rejects_verified_challenge
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
     * @scenario two_factor=on, controller=user, resend=expired
     *
     * @effects resend_rejects_expired_challenge
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
     * @scenario two_factor=on, controller=user, resend=cancelled
     *
     * @effects resend_rejects_cancelled_challenge
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
     * @scenario two_factor=on, controller=user, resend=active, lock_state=active
     *
     * @effects resend_refused_while_locked
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
     * @scenario two_factor=on, controller=admin, actor=admin, resend=active
     *
     * @effects admin_resend_returns_new_challenge
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
     * @scenario two_factor=on, controller=admin, actor=non_admin, resend=active
     *
     * @effects admin_resend_refuses_non_admin
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
     * @param  string  $challengeId  challenge UUID
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
