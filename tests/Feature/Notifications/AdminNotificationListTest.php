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
 * 관리자 알림 목록 조회 (#519 회귀)
 *
 * 이 목록은 `$user->notifications()` 라는 **관계**를 페이지네이션한다. 상한 페이지네이션
 * 계약으로 옮기면서 그 계약이 빌더만 받도록 좁게 선언돼 있어, 화면 진입만으로 500 이
 * 났다. 이 경로에 테스트가 하나도 없어 전 계층 테스트가 green 인 채로 통과했다.
 *
 * 그래서 여기서는 두 가지를 함께 못박는다.
 *   (1) 관계 입력이 정상 응답으로 이어진다 (회귀 차단)
 *   (2) 그러면서도 상한 계약의 정확도 메타가 살아 있다 (성능 개선이 되돌아가지 않음)
 */
class AdminNotificationListTest extends TestCase
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
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'type' => PermissionType::Admin,
                ]
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
     * @param  bool  $read  읽음 여부
     */
    private function seedNotifications(User $user, int $count, bool $read = false): void
    {
        foreach (range(1, $count) as $i) {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'data' => ['message' => 'n'.$i],
                'read_at' => $read ? now() : null,
            ]);
        }
    }

    /**
     * 목록이 200 으로 응답하는지 확인 (관계 입력 회귀 차단)
     */
    public function test_admin_notification_list_returns_ok(): void
    {
        $admin = $this->adminWithNotificationRead();
        $this->seedNotifications($admin, 3);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/notifications?per_page=15&read=unread');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.data'));
    }

    /**
     * 읽음 필터가 적용되는지 확인
     */
    public function test_unread_filter_is_applied(): void
    {
        $admin = $this->adminWithNotificationRead();
        $this->seedNotifications($admin, 2, read: false);
        $this->seedNotifications($admin, 4, read: true);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/notifications?per_page=15&read=unread');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
    }

    /**
     * 다른 사람의 알림이 섞이지 않는지 확인 (관계의 소속 조건 보존)
     */
    public function test_other_users_notifications_are_not_listed(): void
    {
        $admin = $this->adminWithNotificationRead();
        $other = User::factory()->create();

        $this->seedNotifications($admin, 2);
        $this->seedNotifications($other, 5);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/notifications?per_page=15');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'), '관계의 소속 조건이 사라져 남의 알림이 섞였다');
    }

    /**
     * 상한 계약의 정확도 메타가 응답에 실리는지 확인 (성능 개선 유지)
     *
     * 회귀를 고치면서 표준 집계로 되돌리면 이 필드가 사라진다. 오류가 없어졌다는 것만으로는
     * 개선이 유지됐다고 말할 수 없다.
     */
    public function test_response_carries_total_accuracy_meta(): void
    {
        $admin = $this->adminWithNotificationRead();
        $this->seedNotifications($admin, 3);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/notifications?per_page=2');

        $response->assertOk();

        $pagination = $response->json('data.pagination');

        $this->assertIsArray($pagination, '페이지네이션 메타가 없다');
        $this->assertArrayHasKey('total_relation', $pagination, '상한 계약의 정확도 메타가 사라졌다');
        $this->assertArrayHasKey('total_is_exact', $pagination);
        $this->assertArrayHasKey('result_cap', $pagination);
        $this->assertTrue($pagination['has_more_pages']);
    }
}
