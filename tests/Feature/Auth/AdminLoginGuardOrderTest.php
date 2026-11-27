<?php

namespace Tests\Feature\Auth;

use App\Auth\DTO\PasswordChangeRequirement;
use App\Enums\PasswordChangeReason;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\PasswordChangeChallengeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 관리자 로그인 관문 순서 (K11 · R12)
 *
 * 관리자 로그인 화면에 관리자가 아닌 회원이 맞는 비밀번호로 들어오면, 휴면 해제·로그인 완료 훅·2단계 인증 메일 **전에**
 * 403 으로 거절해야 한다. 종전에는 토큰을 발급하고 휴면을 푼 뒤 컨트롤러가 회수하며 403 을 냈다.
 */
class AdminLoginGuardOrderTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /** 로그인 완료 훅 발화 수 */
    private int $afterLogin = 0;

    /** 2단계 인증 요청 훅 발화 수 */
    private int $twoFactorRequested = 0;

    /**
     * 메일 발송을 막고 훅 발화를 셉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();

        $this->afterLogin = 0;
        $this->twoFactorRequested = 0;
        HookManager::addAction('core.auth.after_login', function () {
            $this->afterLogin++;
        }, 99);
        HookManager::addAction('core.auth.two_factor_requested', function () {
            $this->twoFactorRequested++;
        }, 99);
    }

    /**
     * 관리자가 아닌 휴면 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function dormantMember(): User
    {
        return $this->makeMember([
            'email' => 'dormant.'.Str::lower(Str::random(8)).'@example.test',
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(30),
        ]);
    }

    /**
     * 관리자가 아닌 휴면 회원이 관리자 로그인 화면에 들어오면 휴면이 풀리지 않고 훅·토큰 없이 403 이다.
     *
     * @scenario guard=admin, role=member, status=dormant, two_factor=off
     *
     * @effects admin_login_rejects_before_dormancy_restore, no_after_login_for_rejected_admin_login, no_token_for_rejected_admin_login
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비관리자_휴면_회원은_휴면이_풀리지_않고_거절된다(): void
    {
        $user = $this->dormantMember();

        $this->postJson('/api/auth/admin/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])
            ->assertStatus(403)
            ->assertJsonPath('message', __('auth.admin_required'));

        $this->assertSame(UserStatus::Dormant->value, $user->fresh()->status);
        $this->assertSame(0, $this->afterLogin);
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * 2단계 인증이 켜져 있어도 관리자가 아닌 회원에게는 인증번호를 보내지 않고 거절한다.
     *
     * @scenario guard=admin, role=member, two_factor=on
     *
     * @effects no_two_factor_mail_for_non_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이단계_인증이_켜져도_비관리자에게_인증번호를_보내지_않는다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $user = $this->dormantMember();

        $this->postJson('/api/auth/admin/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])->assertStatus(403);

        $this->assertSame(0, $this->twoFactorRequested);
        $this->assertSame(UserStatus::Dormant->value, $user->fresh()->status);
    }

    /**
     * 사용자 로그인에서 받은 2단계 인증 challenge 를 관리자 완료 경로에 들고 와도 세션 전에 거절한다.
     *
     * @scenario guard=admin, path=two_factor_complete, role=member
     *
     * @effects admin_two_factor_complete_rejects_before_session
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_2단계_완료_경로도_세션_전에_거절한다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $user = $this->dormantMember();

        $challengeId = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])
            ->assertOk()
            ->json('data.challenge_id');
        $log = IdentityVerificationLog::find($challengeId);
        $log->metadata = array_merge($log->metadata ?? [], ['code_hash' => Hash::make('135790')]);
        $log->save();

        $this->postJson('/api/auth/admin/login/two-factor', ['challenge_id' => $challengeId, 'code' => '135790'])
            ->assertStatus(403);

        $this->assertSame(UserStatus::Dormant->value, $user->fresh()->status);
        $this->assertSame(0, $this->afterLogin);
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * 관리자 경로 비밀번호 변경 단계를 마치거나 미루는 요청도 비관리자면 휴면 해제·로그인 완료 훅·토큰 전에 403 이다.
     *
     * @scenario guard=admin, role=member, status=dormant, step=password_change_complete|password_change_postpone
     *
     * @effects admin_password_change_completion_rejects_before_session
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_비밀번호_변경_완료_경로도_세션_전에_거절한다(): void
    {
        $user = $this->dormantMember();
        $challenges = app(PasswordChangeChallengeService::class);

        $completeId = $challenges->issue($user, 'admin', new PasswordChangeRequirement(PasswordChangeReason::AdminAssigned));
        $this->postJson('/api/auth/admin/login/password-change', [
            'challenge_id' => $completeId,
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertStatus(403);

        $postponeId = $challenges->issue($user, 'admin', new PasswordChangeRequirement(PasswordChangeReason::Expired));
        $this->postJson('/api/auth/admin/login/password-change/postpone', ['challenge_id' => $postponeId])
            ->assertStatus(403);

        $this->assertSame(UserStatus::Dormant->value, $user->fresh()->status);
        $this->assertSame(0, $this->afterLogin);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(Hash::check('Passw0rd!old', $user->fresh()->password), '비관리자의 비밀번호가 관리자 경로로 바뀌었다');
    }

    /**
     * 사용자 로그인에서 받은 2단계 인증 challenge 로 관리자 재발송을 부르면 인증번호를 다시 보내지 않고 403 이다.
     *
     * 원 challenge 도 취소되지 않아 사용자 로그인 화면에서 받은 인증번호는 그대로 쓸 수 있다.
     *
     * @scenario guard=admin, path=two_factor_resend, role=member
     *
     * @effects admin_two_factor_resend_rejects_before_mail
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_재발송_경로는_비관리자에게_인증번호를_다시_보내지_않는다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $user = $this->makeMember(['email' => 'resend.'.Str::lower(Str::random(8)).'@example.test']);

        $challengeId = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])
            ->assertOk()
            ->json('data.challenge_id');
        $this->assertSame(1, $this->twoFactorRequested);
        $statusBefore = IdentityVerificationLog::find($challengeId)->status;

        $this->postJson('/api/auth/admin/login/two-factor/resend', ['challenge_id' => $challengeId])
            ->assertStatus(403)
            ->assertJsonPath('message', __('auth.admin_required'));

        $this->assertSame(1, $this->twoFactorRequested, '비관리자에게 관리자 경로로 인증번호를 다시 보냈다');
        $this->assertSame($statusBefore, IdentityVerificationLog::find($challengeId)->status, '관리자 경로 재발송이 원 challenge 를 취소했다');
    }

    /**
     * 관리자는 종전대로 관리자 로그인에 성공한다 (회귀 대조).
     *
     * @scenario guard=admin, role=admin
     *
     * @effects admin_login_succeeds
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자는_종전대로_로그인한다(): void
    {
        $admin = $this->makeAdmin(['email' => 'admin.'.Str::lower(Str::random(8)).'@example.test']);

        $this->postJson('/api/auth/admin/login', ['email' => $admin->email, 'password' => 'Passw0rd!old'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertSame(1, $this->afterLogin);
    }
}
