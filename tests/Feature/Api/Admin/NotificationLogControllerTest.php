<?php

namespace Tests\Feature\Api\Admin;

use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 알림 발송 이력 API 테스트
 */
class NotificationLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminWithPermissions();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 목록 조회 성공 — abilities 포함
     */
    public function test_index_returns_notification_logs_with_abilities(): void
    {
        NotificationLog::create([
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'recipient_identifier' => 'test@example.com',
            'status' => 'sent',
        ]);

        $response = $this->authRequest()->getJson('/api/admin/notification-logs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'data',
                    'pagination',
                    'abilities' => ['can_delete'],
                ],
            ])
            ->assertJsonPath('data.abilities.can_delete', true);
    }

    /**
     * 채널 필터 적용
     */
    public function test_index_filters_by_channel(): void
    {
        NotificationLog::create(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'a@test.com', 'status' => 'sent']);
        NotificationLog::create(['channel' => 'database', 'notification_type' => 'test', 'recipient_identifier' => '1', 'status' => 'sent']);

        $response = $this->authRequest()->getJson('/api/admin/notification-logs?channel=mail');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 1);
    }

    /**
     * 단건 삭제
     */
    public function test_destroy_deletes_log(): void
    {
        $log = NotificationLog::create([
            'channel' => 'mail',
            'notification_type' => 'test',
            'recipient_identifier' => 'test@example.com',
            'status' => 'sent',
        ]);

        $response = $this->authRequest()->deleteJson("/api/admin/notification-logs/{$log->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('notification_logs', ['id' => $log->id]);
    }

    /**
     * 다건 삭제
     */
    public function test_bulk_destroy(): void
    {
        $log1 = NotificationLog::create(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'a@test.com', 'status' => 'sent']);
        $log2 = NotificationLog::create(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'b@test.com', 'status' => 'sent']);

        $response = $this->authRequest()->postJson('/api/admin/notification-logs/bulk-delete', [
            'ids' => [$log1->id, $log2->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.deleted_count', 2);
    }

    /**
     * 인증된 요청 헬퍼.
     *
     * @return static
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 관리자 생성 헬퍼.
     *
     * @return User
     */
    private function createAdminWithPermissions(): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => ['ko' => '최고관리자'],
            'identifier' => 'super_admin',
            'is_admin' => true,
        ]);

        foreach (['core.notification-logs.read', 'core.notification-logs.delete'] as $identifier) {
            $perm = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier], 'type' => 'admin', 'order' => 1]
            );
            $role->permissions()->attach($perm->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
