<?php

namespace Tests\Feature\Notifications;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 관리자 헤더 알림 센터의 읽음·삭제 권한 (G21).
 *
 * 알림 컬렉션은 회원 알림함과 공용이라 회원 알림 권한으로 abilities 를 싣는다. 관리자 알림 목록은
 * 관리자 알림 라우트(core.notifications.update/delete)와 같은 판정으로 덮어써야 헤더가 버튼을 잠근다.
 */
class AdminNotificationAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 알림 읽기 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function adminWithNotificationRead(): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'identifier' => 'notif-admin-'.$user->id,
            'name' => ['ko' => '알림 관리자', 'en' => 'Notification Admin'],
        ]);

        foreach (['admin.access', 'core.notifications.read'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return User::findOrFail($user->id);
    }

    /**
     * 알림을 만듭니다.
     *
     * @param  User  $user  수신자
     * @param  int  $count  건수
     * @return void 반환값 없음
     */
    private function seedNotifications(User $user, int $count): void
    {
        foreach (range(1, $count) as $i) {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'data' => ['message' => 'n'.$i],
                'read_at' => null,
            ]);
        }
    }

    /**
     * 목록 응답에 읽음·삭제 권한(abilities)이 실린다 — 읽기만 가진 관리자는 둘 다 false (G21)
     *
     * 헤더 알림 센터는 이 값으로 「모두 읽음」·삭제 버튼과 닫을 때의 자동 읽음 처리를 끈다.
     * 서버 쓰기 라우트는 같은 권한(core.notifications.update/delete)으로 403 을 낸다.
     *
     * @scenario endpoint=admin.notifications.index,permission=read_only|read+update
     *
     * @effects admin_notification_list_carries_write_abilities
     *
     * @return void 반환값 없음
     */
    public function test_response_carries_write_abilities(): void
    {
        $admin = $this->adminWithNotificationRead();
        $this->seedNotifications($admin, 1);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/notifications');

        $response->assertOk();
        $this->assertSame(['can_update' => false, 'can_delete' => false], $response->json('data.abilities'));

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/notifications/read-all')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/admin/notifications/all')->assertForbidden();

        // 대조군: 읽음 처리 권한을 더 받은 다른 관리자는 can_update 만 true 다.
        $updater = $this->adminWithNotificationRead();
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.notifications.update'],
            ['name' => ['ko' => 'u', 'en' => 'u'], 'type' => PermissionType::Admin]
        );
        Role::where('identifier', 'notif-admin-'.$updater->id)->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);

        $after = $this->actingAs(User::findOrFail($updater->id), 'sanctum')->getJson('/api/admin/notifications');
        $this->assertTrue($after->json('data.abilities.can_update'));
        $this->assertFalse($after->json('data.abilities.can_delete'));
    }
}
