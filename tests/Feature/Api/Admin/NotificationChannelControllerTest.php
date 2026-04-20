<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 알림 채널 API 컨트롤러 테스트
 */
class NotificationChannelControllerTest extends TestCase
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
     * 채널 목록이 기본 채널(mail, database)을 포함하는지 확인
     */
    public function test_index_returns_default_channels(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/notification-channels');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $channels = $response->json('data.channels');
        $channelIds = array_column($channels, 'id');

        $this->assertContains('mail', $channelIds);
        $this->assertContains('database', $channelIds);
    }

    /**
     * 각 채널에 id, name, icon이 포함되는지 확인
     */
    public function test_channels_have_required_fields(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/notification-channels');

        $channels = $response->json('data.channels');

        foreach ($channels as $channel) {
            $this->assertArrayHasKey('id', $channel);
            $this->assertArrayHasKey('name', $channel);
            $this->assertArrayHasKey('icon', $channel);
        }
    }

    /**
     * 비인증 사용자 접근 거부
     */
    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/admin/notification-channels');

        $response->assertStatus(401);
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

        $perm = Permission::firstOrCreate(
            ['identifier' => 'core.settings.read'],
            ['name' => ['ko' => '환경설정 조회'], 'type' => 'admin', 'order' => 1]
        );
        $role->permissions()->attach($perm->id);
        $user->roles()->attach($role->id);

        return $user;
    }
}
