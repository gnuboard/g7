<?php

namespace Tests\Feature\Auth;

use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\LoginHandoffBinding;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 관리자 로그인 인계 `POST /api/auth/admin/login/handoff`
 *
 * 외부 인증이 관리자 경로(`admin`)로 발급한 인계 코드를 관리자 로그인 화면이 교환하면 관리자 비밀번호 로그인과 같은 세 형태
 * (인증 완료 · 2단계 인증 · 비밀번호 변경)로 답한다. 관리자가 아닌 회원은 휴면 해제 없이 403, 경로가 다른 코드는 422.
 */
class AdminLoginHandoffTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use LoginHandoffBinding;
    use PasswordPolicyFixtures;

    /**
     * 메일 발송을 막고 로그인 시도 제한을 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();
    }

    /**
     * 관리자를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return User 관리자
     */
    private function admin(array $attributes = []): User
    {
        return $this->makeAdmin(array_merge(['email' => 'handoff.admin.'.Str::lower(Str::random(8)).'@example.test'], $attributes));
    }

    /**
     * 관리자 코드를 교환하면 관리자 비밀번호 로그인과 같은 모양으로 로그인된다.
     *
     * @scenario guard=admin, response=authenticated
     *
     * @effects admin_handoff_issues_token, admin_handoff_matches_login_shape
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_코드를_교환하면_관리자_로그인과_같은_모양으로_로그인된다(): void
    {
        $admin = $this->admin();
        $ticket = $this->issueBoundHandoff($admin, ['login_method' => 'external:test'], 'admin');

        $viaHandoff = $this->exchangeHandoff($ticket['code'], $ticket['bind'], 'admin')->assertOk();
        $this->assertNotEmpty($viaHandoff->json('data.token'));
        $this->assertSame($admin->uuid, $viaHandoff->json('data.user.uuid'));

        $this->app['auth']->forgetGuards();
        $viaPassword = $this->postJson('/api/auth/admin/login', ['email' => $admin->email, 'password' => 'Passw0rd!old'])->assertOk();

        $this->assertSame(array_keys($viaPassword->json('data')), array_keys($viaHandoff->json('data')));
    }

    /**
     * 2단계 인증과 비밀번호 변경이 필요하면 같은 단계 응답을 받고 관리자 2단계 경로로 마친다.
     *
     * @scenario guard=admin, response=two_factor_required|password_change_required
     *
     * @effects admin_handoff_two_factor_step, admin_handoff_password_change_step
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 단계가_필요하면_같은_단계_응답을_받는다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $ticket = $this->issueBoundHandoff($this->admin(), ['login_method' => 'external:test'], 'admin');
        $tfa = $this->exchangeHandoff($ticket['code'], $ticket['bind'], 'admin')->assertOk();
        $this->assertTrue((bool) $tfa->json('data.two_factor_required'));
        $this->assertNull($tfa->json('data.token'));

        $log = IdentityVerificationLog::find($tfa->json('data.challenge_id'));
        $log->metadata = array_merge($log->metadata ?? [], ['code_hash' => Hash::make('135790')]);
        $log->save();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/admin/login/two-factor', ['challenge_id' => $tfa->json('data.challenge_id'), 'code' => '135790'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->setSecurity(['two_factor_auth' => false]);
        $this->enableExpiry();
        $ticket = $this->issueBoundHandoff($this->admin(['password_changed_at' => now()->subDays(100)]), ['login_method' => 'external:test'], 'admin');
        $pc = $this->exchangeHandoff($ticket['code'], $ticket['bind'], 'admin')->assertOk();
        $this->assertTrue((bool) $pc->json('data.password_change_required'));
        $this->assertNull($pc->json('data.token'));
    }

    /**
     * 관리자가 아닌 휴면 회원은 403 이고 휴면이 풀리지 않는다.
     *
     * @scenario guard=admin, user=non_admin_dormant
     *
     * @effects admin_handoff_non_admin_forbidden, admin_handoff_keeps_dormant
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자가_아닌_회원은_403_이고_휴면이_유지된다(): void
    {
        $member = $this->makeMember(['email' => 'handoff.dormant.'.Str::lower(Str::random(8)).'@example.test', 'status' => 'dormant']);
        $ticket = $this->issueBoundHandoff($member, ['login_method' => 'external:test'], 'admin');

        $this->exchangeHandoff($ticket['code'], $ticket['bind'], 'admin')
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.admin_required'));

        $this->assertSame('dormant', $member->fresh()->status);
        $this->assertSame(0, $member->tokens()->count());
    }

    /**
     * 경로가 다른 코드와 재사용 코드는 같은 422 로 거절한다.
     *
     * @scenario code=wrong_guard|reused
     *
     * @effects admin_handoff_rejects_user_code, user_handoff_rejects_admin_code, admin_handoff_single_use
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 경로가_다르거나_재사용한_코드는_422(): void
    {
        $admin = $this->admin();

        $userTicket = $this->issueBoundHandoff($admin, ['login_method' => 'external:test'], 'user');
        $this->exchangeHandoff($userTicket['code'], $userTicket['bind'], 'admin')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', __('auth.login_handoff_invalid'));

        $adminTicket = $this->issueBoundHandoff($admin, ['login_method' => 'external:test'], 'admin');
        $this->exchangeHandoff($adminTicket['code'], $adminTicket['bind'], 'user')->assertStatus(422);

        $again = $this->issueBoundHandoff($admin, ['login_method' => 'external:test'], 'admin');
        $this->exchangeHandoff($again['code'], $again['bind'], 'admin')->assertOk();
        $this->exchangeHandoff($again['code'], $again['bind'], 'admin')->assertStatus(422);
    }
}
