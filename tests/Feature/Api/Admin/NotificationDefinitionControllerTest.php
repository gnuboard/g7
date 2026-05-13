<?php

namespace Tests\Feature\Api\Admin;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 알림 정의 API 테스트
 */
class NotificationDefinitionControllerTest extends TestCase
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
     * 목록 조회 성공 — pagination + abilities 포함
     */
    public function test_index_returns_definitions_with_pagination_and_abilities(): void
    {
        NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '환영', 'en' => 'Welcome'],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
        ]);

        $response = $this->authRequest()->getJson('/api/admin/notification-definitions');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'data' => [['id', 'type', 'name', 'channels', 'is_active']],
                    'pagination' => ['current_page', 'last_page', 'total'],
                    'abilities' => ['can_update'],
                ],
            ]);
    }

    /**
     * 목록 조회 — templates 없는 definition은 빈 배열 반환 (json 직렬화 에러 없음)
     */
    public function test_index_serializes_with_empty_templates(): void
    {
        NotificationDefinition::create([
            'type' => 'test_no_templates',
            'hook_prefix' => 'core',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '테스트'],
            'channels' => ['mail'],
            'hooks' => [],
        ]);

        $response = $this->authRequest()->getJson('/api/admin/notification-definitions');

        $response->assertStatus(200)
            ->assertJsonPath('data.data.0.templates', []);
    }

    /**
     * 상세 조회 — templates 관계 로드됨
     */
    public function test_show_returns_definition_with_templates(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '환영', 'en' => 'Welcome'],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
            'body' => ['ko' => '본문', 'en' => 'Body'],
        ]);

        $response = $this->authRequest()->getJson("/api/admin/notification-definitions/{$definition->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.type', 'welcome')
            ->assertJsonStructure([
                'data' => ['id', 'type', 'name', 'templates'],
            ]);

        $this->assertNotNull($response->json('data.templates'));
    }

    /**
     * 활성/비활성 토글
     */
    public function test_toggle_active(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'test_toggle',
            'hook_prefix' => 'core',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '토글 테스트'],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
        ]);

        $response = $this->authRequest()->patchJson("/api/admin/notification-definitions/{$definition->id}/toggle-active");

        $response->assertStatus(200);
        $this->assertFalse($definition->fresh()->is_active);
    }

    /**
     * 정의 리셋 — 소속 템플릿 일괄 기본값 복원 + 정의도 기본 상태로 복구
     */
    public function test_reset_restores_all_templates_to_defaults(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '환영'],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
            'is_default' => false,
        ]);

        $template = NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '커스터마이징된 제목'],
            'body' => ['ko' => '커스터마이징된 본문'],
            'is_default' => false,
            'user_overrides' => ['subject' => true],
        ]);

        $response = $this->authRequest()->postJson("/api/admin/notification-definitions/{$definition->id}/reset");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue($definition->fresh()->is_default, '리셋 후 정의는 기본 상태로 복구되어야 함');
        $this->assertTrue($template->fresh()->is_default, '리셋 후 템플릿도 기본 상태로 복구되어야 함');
    }

    /**
     * 정의 리셋 — 권한 없는 사용자 403
     */
    public function test_reset_requires_settings_update_permission(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'test_reset_perm',
            'hook_prefix' => 'core',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '테스트'],
            'channels' => ['mail'],
            'hooks' => [],
        ]);

        $user = User::factory()->create();
        $role = Role::create(['name' => ['ko' => '일반'], 'identifier' => 'user_reset_test']);
        $user->roles()->attach($role->id);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson("/api/admin/notification-definitions/{$definition->id}/reset");

        $response->assertStatus(403);
    }

    /**
     * 권한 없는 사용자 — 403
     */
    public function test_index_requires_settings_read_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => ['ko' => '일반'], 'identifier' => 'user_basic']);
        $user->roles()->attach($role->id);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/notification-definitions');

        $response->assertStatus(403);
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

        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
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
