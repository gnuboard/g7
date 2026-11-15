<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Models\User;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 일반 운영자가 슈퍼 관리자의 인증 앱을 강제 해제하지 못하는지 검증한다 (KVE 동형 G1).
 *
 * 수정 전: 강제 해제 권한만 있으면 대상의 등급을 보지 않아, 일반 운영자가 슈퍼 관리자의 2단계 인증을 끌 수 있었다.
 */
class TotpAdminResetGradeGuardTest extends PluginTestCase
{
    /**
     * 확정 자격증명을 심습니다.
     *
     * @param  User  $user  대상 회원
     * @return void 반환값 없음
     */
    private function enroll(User $user): void
    {
        TotpCredential::create(['user_id' => $user->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);
    }

    /**
     * 일반 운영자 → 슈퍼 관리자 해제는 403 이고 자격증명이 남는다 (수정 전: 200 해제).
     *
     * @scenario case=super_admin_credential_protected
     *
     * @effects super_admin_credential_protected
     *
     * @return void 반환값 없음
     */
    public function test_operator_cannot_reset_super_admin(): void
    {
        $operator = $this->createCredentialOperatorUser();
        $super = User::factory()->create(['is_super' => true]);
        $this->enroll($super);

        $this->actingAs($operator)
            ->deleteJson('/api/plugins/sirsoft-totp/admin/credentials/'.$super->uuid)
            ->assertStatus(403);

        $this->assertNotNull(TotpCredential::query()->where('user_id', $super->id)->first());
    }

    /**
     * 일반 회원의 인증 앱은 같은 운영자가 해제할 수 있다 (기존 동작 유지).
     *
     * @scenario case=super_admin_credential_protected
     *
     * @effects super_admin_credential_protected
     *
     * @return void 반환값 없음
     */
    public function test_operator_can_reset_normal_member(): void
    {
        $operator = $this->createCredentialOperatorUser();
        $member = User::factory()->create();
        $this->enroll($member);

        $this->actingAs($operator)
            ->deleteJson('/api/plugins/sirsoft-totp/admin/credentials/'.$member->uuid)
            ->assertOk()
            ->assertJsonPath('data.removed', true);
    }
}
