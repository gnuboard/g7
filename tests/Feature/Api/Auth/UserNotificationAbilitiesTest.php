<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 사용자 알림 목록 응답의 abilities 회귀 테스트 (7.1.0 abilities 게이트 G27)
 *
 * 알림함·헤더 알림센터의 읽음·삭제 버튼이 잠글 근거가 없어, 읽음·삭제 권한을 회수한 회원에게도 버튼이
 * 열린 채 눌리면 403 이 났다. 목록 응답(컬렉션)이 라우트와 같은 권한으로 can_update·can_delete 를 싣는다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/user-notification-abilities.yaml 참조.
 */
class UserNotificationAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 주어진 권한만 가진 회원을 만듭니다.
     *
     * @param  array<int, string>  $identifiers  권한 식별자
     * @return User 회원
     */
    private function memberWith(array $identifiers): User
    {
        $role = Role::create([
            'identifier' => 'g27_role_'.uniqid(),
            'name' => ['ko' => '알림 역할', 'en' => 'Notification Role'],
            'is_active' => true,
        ]);

        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'user', 'extension_type' => 'core', 'extension_identifier' => 'core']
            );
            $role->permissions()->attach($permission->id);
        }

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 읽기 권한만 있으면 can_update·can_delete 가 false 로 온다.
     *
     * @scenario case=read_only_member
     *
     * @effects notification_list_reports_abilities
     *
     * @return void 반환값 없음
     */
    public function test_list_reports_false_abilities_for_read_only_member(): void
    {
        $member = $this->memberWith(['core.user-notifications.read']);

        $this->actingAs($member)->getJson('/api/user/notifications')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_update', false)
            ->assertJsonPath('data.abilities.can_delete', false);
    }

    /**
     * 읽음·삭제 권한이 있으면 true 로 온다.
     *
     * @scenario case=full_member
     *
     * @effects notification_list_reports_abilities
     *
     * @return void 반환값 없음
     */
    public function test_list_reports_true_abilities_for_full_member(): void
    {
        $member = $this->memberWith(['core.user-notifications.read', 'core.user-notifications.update', 'core.user-notifications.delete']);

        $this->actingAs($member)->getJson('/api/user/notifications')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_update', true)
            ->assertJsonPath('data.abilities.can_delete', true);
    }
}
