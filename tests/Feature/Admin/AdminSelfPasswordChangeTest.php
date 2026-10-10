<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 관리자 본인 비밀번호 변경 테스트 (#736 D19)
 *
 * 관리자는 헤더 프로필 메뉴 → 회원 수정 화면에서 자기 비밀번호를 바꾼다. 본인 계정이면 현재 비밀번호를
 * 확인해야 하고(자리를 비운 사이 열린 세션으로 계정을 가져가는 경로 차단), 현재 기기는 유지된다.
 * 다른 회원의 비밀번호를 정할 때는 종전대로 현재 비밀번호가 필요 없다.
 */
class AdminSelfPasswordChangeTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 관리자 회원 수정 API 를 호출합니다.
     *
     * @param  string  $token  관리자 토큰
     * @param  User  $target  수정 대상
     * @param  array<string, mixed>  $extra  덧붙일 입력
     * @return TestResponse 응답
     */
    private function update(string $token, User $target, array $extra): TestResponse
    {
        return $this->withToken($token)->putJson('/api/admin/users/'.$target->uuid, array_merge([
            'name' => $target->name,
            'email' => $target->email,
            'role_ids' => $target->roles->pluck('id')->all(),
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ], $extra));
    }

    /**
     * 본인 계정은 현재 비밀번호가 없거나 틀리면 422, 맞으면 바뀌고 현재 기기만 남는다.
     *
     * @scenario entry_point=admin_update_user, target=self, current_password=missing|wrong|correct
     *
     * @effects admin_self_change_requires_current_password
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function self_change_requires_the_current_password(): void
    {
        $admin = $this->makeAdmin();
        $current = $this->issueToken($admin, 'current');
        $this->issueToken($admin, 'other');

        $this->update($current, $admin, [])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->update($current, $admin, ['current_password' => 'wrong-pass-1'])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->assertTrue(Hash::check('Passw0rd!old', $admin->fresh()->password));

        $this->update($current, $admin, ['current_password' => 'Passw0rd!old'])->assertOk();

        $fresh = $admin->fresh();
        $this->assertTrue(Hash::check('Passw0rd!new', $fresh->password));
        $this->assertFalse($fresh->password_change_required, '본인이 정한 비밀번호는 변경 요구 대상이 아니다');
        $this->assertSame(['current'], $fresh->tokens()->pluck('name')->all());
    }

    /**
     * 다른 회원의 비밀번호는 현재 비밀번호 없이 정하고, 그 회원은 다음 로그인 때 바꿔야 한다.
     *
     * @scenario entry_point=admin_update_user, target=other, current_password=missing
     *
     * @effects admin_assignment_needs_no_current_password
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function assigning_another_members_password_needs_no_current_password(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();

        $this->update($this->issueToken($admin), $member, [])->assertOk();

        $this->assertTrue($member->fresh()->password_change_required);
    }
}
