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
 * 관리자 알림 템플릿 API 테스트
 */
class NotificationTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    private NotificationDefinition $definition;

    private NotificationTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminWithPermissions();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;

        $this->definition = NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '환영', 'en' => 'Welcome'],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
        ]);

        $this->template = NotificationTemplate::create([
            'definition_id' => $this->definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
            'body' => ['ko' => '<p>안녕하세요</p>', 'en' => '<p>Hello</p>'],
            'is_active' => true,
        ]);
    }

    /**
     * 템플릿 수정 성공
     */
    public function test_update_template(): void
    {
        $response = $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => ['ko' => '수정된 제목', 'en' => 'Updated Subject'],
            'body' => ['ko' => '<p>수정됨</p>', 'en' => '<p>Updated</p>'],
            'is_active' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('수정된 제목', $this->template->fresh()->subject['ko']);
    }

    /**
     * 템플릿 편집 시 Template.is_default + Definition.is_default 모두 false로 전환
     * (리셋 버튼 노출 근거)
     */
    public function test_update_template_marks_template_and_definition_as_customized(): void
    {
        // 초기 상태: 템플릿·정의 모두 기본 상태
        $this->template->update(['is_default' => true]);
        $this->template->definition->update(['is_default' => true]);

        $response = $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => ['ko' => '커스텀 제목', 'en' => 'Custom'],
            'body' => ['ko' => '본문', 'en' => 'Body'],
        ]);

        $response->assertStatus(200);

        $this->assertFalse($this->template->fresh()->is_default, '템플릿이 커스터마이징 상태로 전환되어야 함');
        $this->assertFalse($this->template->definition->fresh()->is_default, '정의도 커스터마이징 상태로 전환되어야 함');
    }

    /**
     * 활성/비활성 토글
     */
    public function test_toggle_active(): void
    {
        $response = $this->authRequest()->patchJson("/api/admin/notification-templates/{$this->template->id}/toggle-active");

        $response->assertStatus(200);
        $this->assertFalse($this->template->fresh()->is_active);
    }

    /**
     * 기본값 복원
     */
    public function test_reset_template(): void
    {
        $this->template->update([
            'subject' => ['ko' => '사용자 수정본'],
            'is_default' => false,
        ]);

        $response = $this->authRequest()->postJson("/api/admin/notification-templates/{$this->template->id}/reset");

        $response->assertStatus(200);
    }

    /**
     * 미리보기 성공 — definition_id로 변수 조회 후 치환
     */
    public function test_preview_template(): void
    {
        $this->definition->update([
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
            ],
        ]);

        $response = $this->authRequest()->postJson('/api/admin/notification-templates/preview', [
            'definition_id' => $this->definition->id,
            'subject' => ['ko' => '[{app_name}] 환영합니다', 'en' => '[{app_name}] Welcome'],
            'body' => ['ko' => '<p>{name}님 환영합니다</p>', 'en' => '<p>Welcome {name}</p>'],
            'locale' => 'ko',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertStringContainsString('[사이트명]', $data['subject']);
        $this->assertStringContainsString('[수신자 이름]', $data['body']);
    }

    /**
     * 미리보기 — definition_id 누락 시 422 검증 실패
     */
    public function test_preview_requires_definition_id(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/notification-templates/preview', [
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '<p>본문</p>'],
        ]);

        $response->assertStatus(422);
    }

    /**
     * 권한 없는 사용자 — 403
     */
    public function test_update_requires_settings_update_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => ['ko' => '읽기전용'], 'identifier' => 'readonly']);
        $readPerm = Permission::firstOrCreate(
            ['identifier' => 'core.settings.read'],
            ['name' => ['ko' => '설정 조회'], 'type' => 'admin', 'order' => 1]
        );
        $role->permissions()->attach($readPerm->id);
        $user->roles()->attach($role->id);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => ['ko' => '변경 시도'],
            'body' => ['ko' => '<p>변경</p>'],
            'is_active' => true,
        ]);

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
