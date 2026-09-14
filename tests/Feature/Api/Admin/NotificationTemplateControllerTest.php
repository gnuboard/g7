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
     *
     * @effects notification_template_update_saves_subject_and_body
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
     * 역할 수신자는 **여러 개**를 저장할 수 있다 (E3).
     *
     * 서버가 배열을 받지 못하면 화면이 배열을 보낸 순간 422 이거나, 통과하더라도 해석에서
     * TypeError 가 나고 그것을 try/catch 가 삼켜 **수신자 0명**이 된다.
     *
     * @effects notification_template_accepts_multiple_roles
     */
    public function test_update_template_accepts_multiple_role_identifiers(): void
    {
        Role::create(['name' => ['ko' => '운영자'], 'identifier' => 'manager', 'is_admin' => true]);

        $response = $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'role', 'value' => ['super_admin', 'manager']],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame(['super_admin', 'manager'], $this->template->fresh()->recipients[0]['value']);
    }

    /**
     * 기존 저장값(문자열)은 그대로 저장된다 — 마이그레이션 없이 읽기에서 수용하는 계약이다.
     *
     * @effects notification_template_still_accepts_a_scalar_role
     */
    public function test_update_template_still_accepts_a_scalar_role_identifier(): void
    {
        $response = $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'role', 'value' => 'super_admin'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame('super_admin', $this->template->fresh()->recipients[0]['value']);
    }

    /**
     * 역할 값에 문자열이 아닌 것이 섞이면 422 — 저장되면 발송 시점에 0명으로만 드러난다.
     *
     * @effects notification_template_rejects_a_malformed_role_value
     */
    public function test_update_template_rejects_a_malformed_role_value(): void
    {
        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'role', 'value' => [['identifier' => 'super_admin']]],
            ],
        ])->assertStatus(422);
    }

    /**
     * 응답의 역할 값은 **언제나 배열**이고 표시 이름이 그 순서대로 붙는다.
     *
     * 순서가 어긋나면 화면이 인덱스로 짝지을 때 다른 역할 이름이 붙는다.
     *
     * @effects notification_template_resource_normalizes_role_values
     */
    public function test_template_resource_normalizes_role_values(): void
    {
        Role::create(['name' => ['ko' => '운영자'], 'identifier' => 'manager', 'is_admin' => true]);
        $this->template->update(['recipients' => [['type' => 'role', 'value' => 'super_admin']]]);

        $response = $this->authRequest()
            ->getJson("/api/admin/notification-definitions/{$this->definition->id}")
            ->assertStatus(200);

        $recipients = $response->json('data.templates.0.recipients');

        $this->assertSame(['super_admin'], $recipients[0]['value']);
        $this->assertSame(['최고관리자'], $recipients[0]['display_names']);
    }

    /**
     * 「관련 사용자」의 관계 키는 **그 정의가 선언한 목록 안**이어야 한다.
     *
     * 선언 밖의 키를 저장하면 발송 시점에 수신자가 0명이 되고 예외도 로그도 남지 않는다.
     *
     * @effects notification_template_accepts_a_declared_relation
     */
    public function test_update_template_accepts_a_declared_relation(): void
    {
        $this->definition->update(['relations' => ['post_author', 'board_managers']]);

        $response = $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'related_user', 'relation' => 'post_author'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame('post_author', $this->template->fresh()->recipients[0]['relation']);
    }

    /**
     * @effects notification_template_rejects_an_undeclared_relation
     */
    public function test_update_template_rejects_an_undeclared_relation(): void
    {
        $this->definition->update(['relations' => ['post_author']]);

        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'related_user', 'relation' => 'author'],
            ],
        ])->assertStatus(422);
    }

    /**
     * 선언이 비면 어떤 관계도 통과하지 않는다 — 화면도 그 타입 자체를 감춘다.
     *
     * @effects notification_template_rejects_any_relation_without_declarations
     */
    public function test_update_template_rejects_any_relation_without_declarations(): void
    {
        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'related_user', 'relation' => 'post_author'],
            ],
        ])->assertStatus(422);
    }

    /**
     * 정의 응답이 선언을 실어야 화면이 「관련 사용자」 선택지를 세울지 판단할 수 있다.
     *
     * @effects notification_definition_resource_carries_relations
     */
    public function test_definition_resource_carries_relations(): void
    {
        $this->definition->update(['relations' => ['post_author']]);

        $this->authRequest()
            ->getJson("/api/admin/notification-definitions/{$this->definition->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.relations', ['post_author']);
    }

    /**
     * 템플릿 편집 시 Template.is_default + Definition.is_default 모두 false로 전환
     * (리셋 버튼 노출 근거)
     *
     * @effects notification_template_edit_marks_both_as_customized
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
     *
     * @effects notification_template_toggle_active
     */
    public function test_toggle_active(): void
    {
        $response = $this->authRequest()->patchJson("/api/admin/notification-templates/{$this->template->id}/toggle-active");

        $response->assertStatus(200);
        $this->assertFalse($this->template->fresh()->is_active);
    }

    /**
     * 기본값 복원
     *
     * @effects notification_template_reset_restores_defaults
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
     *
     * @effects notification_template_preview_renders_variables
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
     *
     * @effects notification_template_preview_requires_a_definition
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
     *
     * @effects notification_template_update_requires_settings_permission
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
            'Authorization' => 'Bearer '.$token,
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
            'Authorization' => 'Bearer '.$this->token,
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
