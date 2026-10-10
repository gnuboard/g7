<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 운영자 본인 계정 삭제 차단 (S4, 제품 결정 2026-10-09).
 *
 * 회원 삭제 권한을 가진 비-슈퍼 운영자가 회원 목록 단건 삭제로 자기 계정을 지우면 관리 화면을
 * 쓰던 세션이 그대로 끊긴다. 탈퇴는 마이페이지 경로로만 하게 하고, 관리자 화면에서는 서버가
 * 거부하며 응답의 can_delete 도 false 로 내려 목록 행 [삭제]를 잠근다.
 */
class UserSelfDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 회원 조회·삭제 권한을 가진 비-슈퍼 운영자를 만듭니다.
     *
     * @return User 운영자
     */
    private function operator(): User
    {
        $ids = [];
        foreach (['core.users.read', 'core.users.delete'] as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'self_delete_test_'.uniqid(),
            'name' => ['ko' => '운영자', 'en' => 'Operator'],
            'is_active' => true,
        ]);
        $role->permissions()->sync($ids);

        $user = User::factory()->create(['is_super' => false]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 본인 계정 단건 삭제는 서버가 거부하고 계정이 남는다 — 다른 회원 삭제는 그대로 된다
     *
     * @scenario endpoint=admin.users.destroy,target=self|other,actor=non_super_operator
     *
     * @effects self_delete_is_rejected_and_account_remains
     *
     * @return void 반환값 없음
     */
    public function test_operator_cannot_delete_own_account(): void
    {
        $operator = $this->operator();
        $other = User::factory()->create();

        // 일반 「삭제 실패」 가 아니라 본인 삭제 차단 안내가 나가야 한다
        $this->actingAs($operator)->deleteJson("/api/admin/users/{$operator->uuid}")
            ->assertStatus(422)
            ->assertJsonPath('message', __('exceptions.cannot_delete_self'));
        $this->assertNotNull(User::find($operator->id));

        $this->actingAs($operator)->deleteJson("/api/admin/users/{$other->uuid}")
            ->assertOk();
        $this->assertNull(User::find($other->id));
    }

    /**
     * 목록·상세 응답에서 본인 행의 can_delete 는 false, 다른 회원은 true 다
     *
     * @scenario endpoint=admin.users.index|admin.users.show,target=self|other,actor=non_super_operator
     *
     * @effects self_row_can_delete_is_false
     *
     * @return void 반환값 없음
     */
    public function test_self_row_can_delete_is_false(): void
    {
        $operator = $this->operator();
        $other = User::factory()->create();

        $list = $this->actingAs($operator)->getJson('/api/admin/users?per_page=50')->assertOk();
        $rows = collect($list->json('data.data'));

        $this->assertFalse($rows->firstWhere('uuid', $operator->uuid)['abilities']['can_delete']);
        $this->assertTrue($rows->firstWhere('uuid', $other->uuid)['abilities']['can_delete']);

        $show = $this->actingAs($operator)->getJson("/api/admin/users/{$operator->uuid}")->assertOk();
        $this->assertFalse($show->json('data.abilities.can_delete'));
    }
}
