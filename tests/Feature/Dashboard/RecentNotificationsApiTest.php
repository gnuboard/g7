<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ExtensionOwnerType;
use App\Enums\NotificationLogStatus;
use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 대시보드 최근 알림 API 테스트
 *
 * GET /api/admin/dashboard/recent-notifications
 *  - 가드: admin 미들웨어 + permission:admin,core.notification-logs.read
 */
class RecentNotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/dashboard/recent-notifications';

    /**
     * 관리자 사용자를 생성하고 지정 권한을 부여합니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 생성된 관리자 사용자
     */
    private function makeAdmin(array $permissions = ['core.notification-logs.read']): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $role = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'is_active' => true,
        ]);
        $adminBaseRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $sync = [];
        foreach ($permissionIds as $id) {
            $sync[$id] = ['scope_type' => null];
        }
        $role->permissions()->sync($sync);

        $user->roles()->attach($adminBaseRole->id, ['assigned_at' => now()]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    #[Test]
    public function test_requires_authentication(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    #[Test]
    public function test_rejects_admin_without_permission(): void
    {
        $admin = $this->makeAdmin([]);

        $this->actingAs($admin)->getJson(self::URL)->assertStatus(403);
    }

    #[Test]
    public function test_returns_recent_notifications_for_authorized_admin(): void
    {
        $admin = $this->makeAdmin();

        NotificationLog::create([
            'channel' => 'mail',
            'notification_type' => 'order_confirmed',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'recipient_name' => '홍길동',
            'recipient_identifier' => 'test@example.com',
            'subject' => '주문이 확인되었습니다',
            'status' => NotificationLogStatus::Sent,
            'sent_at' => now(),
        ]);

        $this->actingAs($admin)->getJson(self::URL)
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => [['id', 'type', 'channel', 'recipient', 'subject', 'status', 'time']]])
            ->assertJsonPath('data.0.type', 'order_confirmed')
            ->assertJsonPath('data.0.channel', 'mail')
            ->assertJsonPath('data.0.status', 'sent');
    }
}
