<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GenericNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 사용자 알림 API 컨트롤러 테스트
 */
class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUserWithPermissions([], [
            'core.notifications.read',
            'core.notifications.update',
            'core.notifications.delete',
        ]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    /**
     * 권한이 부여된 사용자 생성 헬퍼.
     *
     * @param array $attributes 사용자 속성
     * @param array $permissions 권한 식별자 배열
     * @return User
     */
    private function createUserWithPermissions(array $attributes = [], array $permissions = []): User
    {
        $user = User::factory()->create($attributes);

        if (! empty($permissions)) {
            $permissionIds = [];
            foreach ($permissions as $permIdentifier) {
                $permission = Permission::firstOrCreate(
                    ['identifier' => $permIdentifier],
                    [
                        'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                        'description' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                        'extension_type' => 'core',
                        'extension_identifier' => 'core',
                        'type' => 'user',
                        'order' => 0,
                    ]
                );
                $permissionIds[] = $permission->id;
            }

            $role = Role::firstOrCreate(
                ['identifier' => 'test-user-role'],
                [
                    'name' => json_encode(['ko' => '테스트 유저', 'en' => 'Test User']),
                    'description' => json_encode(['ko' => '테스트용 역할', 'en' => 'Test role']),
                    'is_active' => true,
                ]
            );
            $role->permissions()->syncWithoutDetaching($permissionIds);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
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
     * 알림 목록 조회
     */
    public function test_index_returns_notifications(): void
    {
        $this->createNotification($this->user);
        $this->createNotification($this->user);

        $response = $this->authRequest()->getJson('/api/user/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * 미읽음 수 조회
     */
    public function test_unread_count(): void
    {
        $this->createNotification($this->user);
        $this->createNotification($this->user, readAt: now());

        $response = $this->authRequest()->getJson('/api/user/notifications/unread-count');

        $response->assertStatus(200)
            ->assertJsonPath('data.unread_count', 1);
    }

    /**
     * 알림 읽음 처리
     */
    public function test_mark_as_read(): void
    {
        $notification = $this->createNotification($this->user);

        $response = $this->authRequest()->patchJson("/api/user/notifications/{$notification->id}/read");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /**
     * 전체 읽음 처리
     */
    public function test_mark_all_as_read(): void
    {
        $this->createNotification($this->user);
        $this->createNotification($this->user);

        $response = $this->authRequest()->postJson('/api/user/notifications/read-all');

        $response->assertStatus(200)
            ->assertJsonPath('data.marked_count', 2);
    }

    /**
     * 알림 삭제
     */
    public function test_destroy_notification(): void
    {
        $notification = $this->createNotification($this->user);

        $response = $this->authRequest()->deleteJson("/api/user/notifications/{$notification->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    /**
     * 비인증 사용자 접근 거부
     */
    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/user/notifications');

        $response->assertStatus(401);
    }

    /**
     * 테스트용 알림 생성 헬퍼.
     *
     * @param User $user
     * @param \DateTimeInterface|null $readAt
     * @return \Illuminate\Notifications\DatabaseNotification
     */
    private function createNotification(User $user, ?\DateTimeInterface $readAt = null)
    {
        return $user->notifications()->create([
            'id' => Str::uuid(),
            'type' => GenericNotification::class,
            'data' => ['type' => 'test', 'subject' => 'Test Notification'],
            'read_at' => $readAt,
        ]);
    }
}
