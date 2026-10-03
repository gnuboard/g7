<?php

namespace Tests\Feature\Auth;

use App\Models\PasswordResetToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 변경 시 다른 기기 로그아웃 테스트 (#736 D17)
 *
 * 본인이 바꾸면 지금 쓰는 기기만 남기고 나머지 토큰을 폐기한다. 메일 재설정·관리자 지정은 탈취 대응일
 * 수 있어 전부 폐기한다. "현재 토큰 생존" 은 저장된 토큰으로만 잴 수 있어 `withToken()` 으로 요청한다.
 */
class PasswordChangeSessionRevokeTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 메일 발송을 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * 본인 변경은 현재 기기 토큰을 남기고 다른 기기 토큰을 지운다.
     *
     * @scenario entry_point=change_password, sessions=current_and_other
     *
     * @effects self_change_keeps_current_device_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function self_change_keeps_only_the_current_device(): void
    {
        $user = $this->makeMember();
        $current = $this->issueToken($user, 'current');
        $this->issueToken($user, 'other');

        $this->withToken($current)->putJson('/api/me/password', [
            'current_password' => 'Passw0rd!old',
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertOk();

        $this->assertSame(['current'], $user->tokens()->pluck('name')->all());
    }

    /**
     * 메일 재설정은 모든 기기를 로그아웃시킨다.
     *
     * @scenario entry_point=reset_password, sessions=current_and_other
     *
     * @effects reset_and_admin_revoke_every_session
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reset_revokes_every_session(): void
    {
        $user = $this->makeMember();
        $this->issueToken($user, 'a');
        $this->issueToken($user, 'b');
        $plain = Str::random(40);
        PasswordResetToken::query()->updateOrCreate(['email' => $user->email], ['token' => Hash::make($plain), 'created_at' => now()]);

        $this->postJson('/api/auth/reset-password', [
            'token' => $plain,
            'email' => $user->email,
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * 관리자가 다른 회원의 비밀번호를 정하면 그 회원의 모든 기기를 로그아웃시킨다.
     *
     * @scenario entry_point=admin_update_user, target=other, sessions=current_and_other
     *
     * @effects reset_and_admin_revoke_every_session
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_assignment_revokes_every_session_of_the_member(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();
        $this->issueToken($member, 'a');
        $adminToken = $this->issueToken($admin, 'admin');

        $this->withToken($adminToken)->putJson('/api/admin/users/'.$member->uuid, [
            'name' => $member->name,
            'email' => $member->email,
            'role_ids' => $member->roles->pluck('id')->all(),
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertOk();

        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(1, $admin->tokens()->count(), '관리자 자신의 세션은 그대로다');
    }
}
