<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\UserPasswordHistoryRepositoryInterface;
use App\Enums\PasswordChangeSource;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Models\UserPasswordHistory;
use App\Services\PasswordChangeService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 재사용 금지 테스트 (#736 D1·D11·D18·D19·A5·A15)
 *
 * 최근 N개(현재 + 이전 N-1개) 재사용 금지가 본인 비밀번호를 바꾸는 모든 경로(재설정·마이페이지·정보수정
 * API·관리자 본인)에 걸리고, 관리자가 다른 회원의 비밀번호를 정할 때는 걸리지 않는지 본다. 재사용 검사가
 * 본인 증명(토큰·현재 비밀번호) **뒤에만** 돌아 추측 오라클이 되지 않는지도 잠근다.
 */
class PasswordReuseTest extends TestCase
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
     * 현재 비밀번호 + 이전 비밀번호 3개(h1 이 가장 최근)를 가진 회원을 만듭니다.
     *
     * @param  bool  $admin  관리자로 만들지
     * @return User 회원
     */
    private function memberWithHistory(bool $admin = false): User
    {
        $user = $admin ? $this->makeAdmin([], 'Passw0rd!cur') : $this->makeMember([], 'Passw0rd!cur');
        $history = app(UserPasswordHistoryRepositoryInterface::class);

        foreach (['Passw0rd!h3', 'Passw0rd!h2', 'Passw0rd!h1'] as $old) {
            $history->push($user->id, Hash::make($old));
        }

        return $user;
    }

    /**
     * 마이페이지 비밀번호 변경을 요청합니다.
     *
     * @param  User  $user  회원
     * @param  string  $current  현재 비밀번호
     * @param  string  $new  새 비밀번호
     * @return TestResponse 응답
     */
    private function changeOwnPassword(User $user, string $current, string $new)
    {
        Sanctum::actingAs($user);

        return $this->putJson('/api/me/password', [
            'current_password' => $current,
            'password' => $new,
            'password_confirmation' => $new,
        ]);
    }

    /**
     * 재사용 금지가 꺼져 있으면 지금 쓰는 비밀번호로 "바꿔도" 통과한다(로그인 단계 외).
     *
     * @scenario entry_point=change_password, reuse_setting=off, input_state=current_password
     *
     * @effects reuse_rule_off_allows_any
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reuse_is_allowed_when_prevention_is_off(): void
    {
        $user = $this->memberWithHistory();

        $this->changeOwnPassword($user, 'Passw0rd!cur', 'Passw0rd!h1')->assertOk();
    }

    /**
     * N=3 이면 현재 + 직전 2개를 거부하고, 그보다 오래된 비밀번호는 허용한다.
     *
     * @scenario entry_point=change_password, reuse_setting=n3, input_state=recent_password
     *
     * @effects reuse_blocks_current_and_recent_n_minus_one
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function n_three_blocks_current_and_two_previous_passwords(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
        $user = $this->memberWithHistory();

        foreach (['Passw0rd!cur', 'Passw0rd!h1', 'Passw0rd!h2'] as $reused) {
            $this->changeOwnPassword($user, 'Passw0rd!cur', $reused)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
        }

        $this->changeOwnPassword($user, 'Passw0rd!cur', 'Passw0rd!h3')->assertOk();
    }

    /**
     * N=1 이면 지금 쓰는 비밀번호만 막는다.
     *
     * @scenario entry_point=change_password, reuse_setting=n1, input_state=recent_password
     *
     * @effects reuse_blocks_current_and_recent_n_minus_one
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function n_one_blocks_only_the_current_password(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 1]);
        $user = $this->memberWithHistory();

        $this->changeOwnPassword($user, 'Passw0rd!cur', 'Passw0rd!cur')->assertStatus(422);
        $this->changeOwnPassword($user, 'Passw0rd!cur', 'Passw0rd!h1')->assertOk();
    }

    /**
     * 메일 재설정에도 재사용 금지가 걸린다 — 토큰이 맞을 때.
     *
     * @scenario entry_point=reset_password, reuse_setting=n3, input_state=recent_password
     *
     * @effects reuse_applies_to_every_self_path
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reset_password_rejects_a_recent_password(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
        $user = $this->memberWithHistory();
        $token = $this->resetTokenFor($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Passw0rd!h1',
            'password_confirmation' => 'Passw0rd!h1',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    /**
     * 오라클 차단(재설정) — 틀린 토큰과 현재 비밀번호를 보내도 재사용 오류가 나오지 않는다.
     *
     * 토큰 확인 전에 재사용 검사를 돌리면 "최근에 쓴 비밀번호" 오류로 남의 비밀번호를 맞혀 볼 수 있다.
     *
     * @scenario entry_point=reset_password, reuse_setting=n3, proof=invalid
     *
     * @effects reuse_check_runs_only_after_proof
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reset_with_wrong_token_never_reveals_reuse(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
        $user = $this->memberWithHistory();
        $this->resetTokenFor($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => 'not-the-token',
            'email' => $user->email,
            'password' => 'Passw0rd!cur',
            'password_confirmation' => 'Passw0rd!cur',
        ]);

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('password', (array) $response->json('errors'), '토큰이 틀렸는데 재사용 판정을 알려 줬다');
        $this->assertTrue(Hash::check('Passw0rd!cur', $user->fresh()->password), '비밀번호가 바뀌면 안 된다');
    }

    /**
     * 오라클 차단(마이페이지) — 현재 비밀번호가 틀리면 재사용 오류를 보여 주지 않는다.
     *
     * @scenario entry_point=change_password, reuse_setting=n3, proof=invalid
     *
     * @effects reuse_check_runs_only_after_proof
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function change_password_with_wrong_current_never_reveals_reuse(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
        $user = $this->memberWithHistory();

        $response = $this->changeOwnPassword($user, 'wrong-current-1', 'Passw0rd!h1');

        $response->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->assertArrayNotHasKey('password', (array) $response->json('errors'));
    }

    /**
     * 정보수정 API 로 비밀번호를 함께 바꿔도 재사용 금지가 걸린다(D18).
     *
     * @scenario entry_point=update_profile, reuse_setting=n3, input_state=recent_password
     *
     * @effects reuse_applies_to_every_self_path
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function profile_update_rejects_a_recent_password(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
        $user = $this->memberWithHistory();
        Sanctum::actingAs($user);

        $this->putJson('/api/me', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'Passw0rd!cur',
            'password' => 'Passw0rd!h2',
            'password_confirmation' => 'Passw0rd!h2',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    /**
     * 관리자가 회원 수정 화면에서 **본인** 비밀번호를 바꾸면 재사용 금지가 걸린다(D19).
     *
     * @scenario entry_point=admin_update_user, target=self, reuse_setting=n3, input_state=recent_password
     *
     * @effects reuse_applies_to_every_self_path
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_self_update_rejects_a_recent_password(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
        $admin = $this->memberWithHistory(admin: true);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/users/'.$admin->uuid, [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_ids' => $admin->roles->pluck('id')->all(),
            'current_password' => 'Passw0rd!cur',
            'password' => 'Passw0rd!h1',
            'password_confirmation' => 'Passw0rd!h1',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    /**
     * 관리자가 **다른 회원**의 비밀번호를 정할 때는 재사용 검사를 하지 않는다(D11) — 회원이 다음 로그인 때 바꾼다.
     *
     * @scenario entry_point=admin_update_user, target=other, reuse_setting=n3, input_state=current_password
     *
     * @effects admin_assigned_password_skips_reuse
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_setting_another_members_password_skips_reuse(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
        $admin = $this->makeAdmin();
        $member = $this->memberWithHistory();
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/users/'.$member->uuid, [
            'name' => $member->name,
            'email' => $member->email,
            'role_ids' => $member->roles->pluck('id')->all(),
            'password' => 'Passw0rd!cur',
            'password_confirmation' => 'Passw0rd!cur',
        ])->assertOk();

        $this->assertTrue((bool) $member->fresh()->password_change_required, '관리자가 정해 준 비밀번호는 다음 로그인 때 바꿔야 한다');
    }

    /**
     * 관리자가 회원을 **등록**하며 정해 주는 비밀번호(W6)도 재사용 검사를 하지 않는다(D11) — 등록 요청에 재사용
     * 검사를 잘못 걸면 대상이 없으니 행위자(관리자)의 비밀번호·이력과 비교하게 되어, 관리자가 자기 비밀번호와
     * 같은 값을 정해 줄 수 없게 된다.
     *
     * @scenario entry_point=admin_create_user, target=other, reuse_setting=n3, input_state=current_password
     *
     * @effects admin_assigned_password_skips_reuse
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_creating_a_member_skips_reuse(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
        $admin = $this->memberWithHistory(true);
        Sanctum::actingAs($admin);

        foreach (['Passw0rd!cur', 'Passw0rd!h1'] as $i => $password) {
            $this->postJson('/api/admin/users', [
                'name' => '등록 회원 '.$i,
                'email' => "pw-reuse-create-{$i}@example.test",
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertSuccessful();

            $created = User::where('email', "pw-reuse-create-{$i}@example.test")->firstOrFail();
            $this->assertTrue(Hash::check($password, $created->password));
            $this->assertTrue($created->password_change_required, '등록 때 정해 준 비밀번호는 다음 로그인 때 바꿔야 한다');
        }
    }

    /**
     * 이력은 회원당 9건까지만 남는다(N 최대 10 = 현재 1 + 이전 9).
     *
     * @scenario entry_point=change_password, history=overflow
     *
     * @effects history_capped_at_nine
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function history_keeps_at_most_nine_entries(): void
    {
        $user = $this->makeMember();
        $service = app(PasswordChangeService::class);

        for ($i = 0; $i < 12; $i++) {
            $old = $user->getRawOriginal('password');
            $user->forceFill(['password' => Hash::make('Passw0rd!n'.$i)])->save();
            $service->recordChange($user, $old, PasswordChangeSource::Self_, $user);
        }

        $this->assertSame(9, UserPasswordHistory::where('user_id', $user->id)->count());
    }

    /**
     * 탈퇴·삭제하면 이력을 명시적으로 지운다(CASCADE 에 의존하지 않는다).
     *
     * @scenario lifecycle=withdraw_and_delete
     *
     * @effects history_purged_on_withdraw_and_delete
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function history_is_purged_on_withdraw_and_delete(): void
    {
        $withdrawn = $this->memberWithHistory();
        $deleted = $this->memberWithHistory();

        app(UserService::class)->withdrawUser($withdrawn);
        $this->assertSame(0, UserPasswordHistory::where('user_id', $withdrawn->id)->count(), '탈퇴 뒤 이력이 남았다');

        $deletedId = $deleted->id;
        app(UserService::class)->deleteUser($deleted);
        $this->assertSame(0, UserPasswordHistory::where('user_id', $deletedId)->count(), '삭제 뒤 이력이 남았다');
    }

    /**
     * 회원의 재설정 토큰을 심고 평문을 돌려줍니다.
     *
     * @param  User  $user  회원
     * @return string 평문 토큰
     */
    private function resetTokenFor(User $user): string
    {
        $plain = Str::random(40);

        PasswordResetToken::query()->updateOrCreate(
            ['email' => $user->email],
            ['token' => Hash::make($plain), 'created_at' => now()]
        );

        return $plain;
    }
}
