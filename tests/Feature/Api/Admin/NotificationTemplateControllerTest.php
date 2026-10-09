<?php

namespace Tests\Feature\Api\Admin;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationTemplateService;
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

    /**
     * 설정 조회·수정 권한 관리자와 토큰, 환영 알림 정의와 메일 템플릿 한 건을 준비합니다.
     *
     * @return void 반환값 없음
     */
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
     * 템플릿 제목·본문 수정이 저장됩니다.
     *
     * @scenario layer=request_validation
     *
     * @effects notification_template_update_saves_subject_and_body
     *
     * @return void 반환값 없음
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
     * 역할 수신자는 **여러 개**를 저장할 수 있습니다 (E3).
     *
     * 서버가 배열을 받지 못하면 화면이 배열을 보낸 순간 422 이거나, 통과하더라도 해석에서
     * TypeError 가 나고 그것을 try/catch 가 삼켜 **수신자 0명**이 된다.
     *
     * @scenario editing_axis=role_recipients, layer=request_validation
     *
     * @effects notification_template_accepts_multiple_roles
     *
     * @return void 반환값 없음
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
     * 기존 저장값(문자열)은 그대로 저장됩니다 — 마이그레이션 없이 읽기에서 수용하는 계약입니다.
     *
     * @scenario editing_axis=role_recipients, layer=request_validation
     *
     * @effects notification_template_still_accepts_a_scalar_role
     *
     * @return void 반환값 없음
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
     * 역할 값에 문자열이 아닌 것이 섞이면 422 입니다 — 저장되면 발송 시점에 0명으로만 드러납니다.
     *
     * @scenario editing_axis=role_recipients, layer=request_validation
     *
     * @effects notification_template_rejects_a_malformed_role_value
     *
     * @return void 반환값 없음
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
     * 역할·회원 값이 키가 붙은 객체(`{"x":"admin"}`)면 422 입니다 — 문자열만 담겼다고 받아 두면 저장값이 목록이 아닌
     * 객체가 되고, 편집 창이 그 값을 목록으로 다루다 깨지거나 발송 시점에야 드러납니다(2026-10-06 실측: 200 으로 저장됨).
     *
     * @scenario editing_axis=role_recipients, layer=request_validation
     *
     * @effects notification_template_rejects_keyed_recipient_values
     *
     * @return void 반환값 없음
     */
    public function test_update_template_rejects_keyed_object_recipient_values(): void
    {
        $before = $this->template->fresh()->recipients;

        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'role', 'value' => ['x' => 'super_admin']],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['recipients.0.value']);

        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'specific_users', 'value' => ['a' => '0190a5c2-0000-7000-8000-000000000001']],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['recipients.0.value']);

        $this->assertEquals($before, $this->template->fresh()->recipients, '거절된 요청은 저장값을 바꾸지 않아야 한다');
    }

    /**
     * 수신자 칸 검증 문구가 내부 키(`recipients.0.relation`) 대신 항목 이름으로 나옵니다.
     *
     * @scenario editing_axis=role_recipients, layer=request_validation
     *
     * @effects notification_template_recipient_errors_use_attribute_names
     *
     * @return void 반환값 없음
     */
    public function test_recipient_validation_messages_use_attribute_names(): void
    {
        $this->definition->update(['relations' => ['post_author']]);

        $response = $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'related_user', 'relation' => 'author'],
                ['type' => 'specific_users', 'value' => [123]],
            ],
        ])->assertStatus(422);

        $messages = collect($response->json('errors'))->flatten()->implode(' ');
        $this->assertStringNotContainsString('recipients.', $messages, "검증 문구에 내부 키가 보이면 안 된다: {$messages}");
        $this->assertStringContainsString(__('notification.validation.attributes.recipient_relation'), $messages);
        $this->assertStringContainsString(__('notification.validation.attributes.recipient_value'), $messages);
    }

    /**
     * 응답의 역할 값은 **언제나 배열**이고 표시 이름이 그 순서대로 붙습니다.
     *
     * 순서가 어긋나면 화면이 인덱스로 짝지을 때 다른 역할 이름이 붙는다.
     *
     * @scenario editing_axis=role_recipients, layer=resource_payload
     *
     * @effects notification_template_resource_normalizes_role_values
     *
     * @return void 반환값 없음
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
     * 「관련 사용자」의 관계 키는 **그 정의가 선언한 목록 안**이어야 합니다.
     *
     * 선언 밖의 키를 저장하면 발송 시점에 수신자가 0명이 되고 예외도 로그도 남지 않는다.
     *
     * @scenario editing_axis=related_user_relation, layer=request_validation
     *
     * @effects notification_template_accepts_a_declared_relation
     *
     * @return void 반환값 없음
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
     * 정의가 선언하지 않은 관계 키(`author`)를 고르면 422 로 거부합니다.
     *
     * 선언 목록 밖의 키는 발송 시점에 조용한 0명이 되므로 저장 단계에서 막는다.
     *
     * @scenario editing_axis=related_user_relation, layer=request_validation
     *
     * @effects notification_template_rejects_an_undeclared_relation
     *
     * @return void 반환값 없음
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
     * 선언이 비면(`relations=[]`) 어떤 관계도 통과하지 않습니다 — 화면도 그 타입 자체를 감춥니다.
     *
     * 선언 자체가 없는 `NULL`(업그레이드 직후 재동기화 전)은 막지 않으므로 빈 선언 `[]` 을 명시한다.
     *
     * @scenario editing_axis=related_user_relation, layer=request_validation
     *
     * @effects notification_template_rejects_any_relation_without_declarations
     *
     * @return void 반환값 없음
     */
    public function test_update_template_rejects_any_relation_without_declarations(): void
    {
        $this->definition->update(['relations' => []]);

        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'related_user', 'relation' => 'post_author'],
            ],
        ])->assertStatus(422);
    }

    /**
     * 정의 응답이 관계 선언을 실어야 화면이 「관련 사용자」 선택지를 세울지 판단할 수 있습니다.
     *
     * @scenario editing_axis=related_user_relation, layer=resource_payload
     *
     * @effects notification_definition_resource_carries_relations
     *
     * @return void 반환값 없음
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
     * 정의 응답이 관계마다 운영자가 읽을 이름을 싣습니다 — 선택지와 목록이 `post_author` 같은 키를 그대로 보이지 않게.
     *
     * 확장 알림은 그 확장의 `messages.notification_relations.{관계}`, 코어 알림은 `notification.relations.{관계}`
     * 에서 이름을 찾고, 없으면 키를 그대로 둡니다(선언은 있는데 이름이 없는 관계도 고를 수는 있어야 한다).
     *
     * @scenario editing_axis=related_user_relation, layer=resource_payload
     *
     * @effects notification_definition_resource_carries_relation_labels
     *
     * @return void 반환값 없음
     */
    public function test_definition_resource_carries_relation_labels(): void
    {
        app('translator')->addLines(['messages.notification_relations.post_author' => '게시글 작성자'], 'ko', 'sirsoft-board');
        $this->definition->update([
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'relations' => ['post_author', 'undeclared_name'],
        ]);

        $this->authRequest()
            ->withHeaders(['Accept-Language' => 'ko'])
            ->getJson("/api/admin/notification-definitions/{$this->definition->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.relation_labels.post_author', '게시글 작성자')
            ->assertJsonPath('data.relation_labels.undeclared_name', 'undeclared_name');
    }

    /**
     * 코어 알림의 관계 이름은 코어 언어 파일 `notification.relations.{관계}` 에서 찾습니다.
     *
     * 지금 코어 정의는 관계를 두지 않아 그 그룹이 비어 있지만, 코어 알림이 관계를 선언하는 순간 쓰일 분기다 —
     * 확장 분기만 시험하면 이 분기가 엉뚱한 네임스페이스를 보더라도 드러나지 않는다.
     *
     * @scenario editing_axis=related_user_relation, layer=resource_payload
     *
     * @effects notification_definition_resource_carries_relation_labels
     *
     * @return void 반환값 없음
     */
    public function test_core_definition_relation_labels_come_from_core_lang_group(): void
    {
        app('translator')->addLines(['notification.relations.target_user' => '대상 회원'], 'ko');
        $this->definition->update([
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'relations' => ['target_user'],
        ]);

        $this->authRequest()
            ->withHeaders(['Accept-Language' => 'ko'])
            ->getJson("/api/admin/notification-definitions/{$this->definition->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.relation_labels.target_user', '대상 회원');
    }

    /**
     * 템플릿을 편집하면 Template.is_default 와 Definition.is_default 가 모두 false 로 전환됩니다.
     *
     * 리셋 버튼 노출 근거다.
     *
     * @scenario layer=request_validation
     *
     * @effects notification_template_edit_marks_both_as_customized
     *
     * @return void 반환값 없음
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
     * 활성/비활성 토글이 템플릿 활성 상태를 뒤집습니다.
     *
     * @scenario layer=request_validation
     *
     * @effects notification_template_toggle_active
     *
     * @return void 반환값 없음
     */
    public function test_toggle_active(): void
    {
        $response = $this->authRequest()->patchJson("/api/admin/notification-templates/{$this->template->id}/toggle-active");

        $response->assertStatus(200);
        $this->assertFalse($this->template->fresh()->is_active);
    }

    /**
     * 사용자 수정본 템플릿을 기본값으로 복원합니다.
     *
     * @scenario layer=request_validation
     *
     * @effects notification_template_reset_restores_defaults
     *
     * @return void 반환값 없음
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
     * 미리보기는 definition_id 로 변수 설명을 조회해 제목·본문의 변수를 치환합니다.
     *
     * @scenario layer=resource_payload
     *
     * @effects notification_template_preview_renders_variables
     *
     * @return void 반환값 없음
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
     * 미리보기에 definition_id 가 없으면 422 검증 실패입니다.
     *
     * @scenario layer=request_validation
     *
     * @effects notification_template_preview_requires_a_definition
     *
     * @return void 반환값 없음
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
     * 설정 조회 권한만 있는 사용자의 템플릿 수정은 403 입니다.
     *
     * @scenario layer=request_validation
     *
     * @effects notification_template_update_requires_settings_permission
     *
     * @return void 반환값 없음
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
     * 관리자 토큰을 실은 인증 요청을 만드는 헬퍼입니다.
     *
     * @return static Authorization·Accept 헤더가 붙은 테스트 인스턴스
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 설정 조회·수정 권한을 가진 최고관리자 역할 사용자를 만드는 헬퍼입니다.
     *
     * @return User 생성된 관리자
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

    /**
     * GET 으로 받은 템플릿을 그대로 PUT 하면(저장만) 수정 표식·「기본」 해제가 일어나지 않는다 (#727 D5-⑤).
     *
     * @effects notification_template_unchanged_save_keeps_default
     *
     * @return void 반환값 없음
     */
    public function test_saving_unchanged_template_keeps_default_state(): void
    {
        $this->template->update(['is_default' => true, 'body' => ['ko' => "<p>안녕하세요</p>\n", 'en' => '<p>Hello</p>']]);
        $this->definition->update(['is_default' => true]);

        $response = $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => ['ko' => '환영합니다 ', 'en' => 'Welcome'],
            'body' => ['ko' => '<p>안녕하세요</p>', 'en' => '<p>Hello</p>'],
            'recipients' => null,
            'is_active' => true,
        ]);

        $response->assertStatus(200);
        $fresh = $this->template->fresh();
        $this->assertEmpty($fresh->user_overrides ?? [], '저장만 했는데 수정 표식이 생겼다');
        $this->assertTrue((bool) $fresh->is_default);
        $this->assertTrue((bool) $this->definition->fresh()->is_default);
    }

    /**
     * 본문 한 글자를 바꾸면 그 로케일만 표식되고 「기본」 이 풀린다.
     *
     * @effects notification_template_real_change_marks_locale_and_clears_default
     *
     * @return void 반환값 없음
     */
    public function test_real_body_change_marks_locale_and_clears_default(): void
    {
        $this->template->update(['is_default' => true]);

        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
            'body' => ['ko' => '<p>안녕하세요!</p>', 'en' => '<p>Hello</p>'],
        ])->assertStatus(200);

        $fresh = $this->template->fresh();
        $this->assertSame(['body.ko'], $fresh->user_overrides);
        $this->assertFalse((bool) $fresh->is_default);
    }

    /**
     * 응답 표시용 display_names 가 폼을 거쳐 되돌아와도 DB 에 저장하지 않는다.
     *
     * @effects notification_template_recipient_display_names_not_persisted
     *
     * @return void 반환값 없음
     */
    public function test_recipients_display_names_are_not_persisted(): void
    {
        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
            'body' => ['ko' => '<p>안녕하세요</p>', 'en' => '<p>Hello</p>'],
            'recipients' => [['type' => 'trigger_user', 'value' => null, 'display_names' => ['홍길동']]],
        ])->assertStatus(200);

        $this->assertStringNotContainsString('display_names', json_encode($this->template->fresh()->recipients));
    }

    /**
     * 화면이 받은 수신자(역할 값은 목록으로 맞춰 내려감)를 그대로 저장해도 「수정됨」 이 되지 않는다.
     *
     * 저장값이 `value: "admin"` 처럼 문자열 하나인 역할 규칙은 응답에서 `["admin"]` 로 바뀌어 나간다.
     * 편집 창을 열고 아무것도 바꾸지 않은 채 저장하면 그 모양으로 되돌아오므로, 뜻이 같은데도 수신자 칸이
     * 수정으로 기록되고 「기본」 이 풀렸다(2026-10-07 실측).
     *
     * @scenario editing_axis=role_recipients, layer=user_overrides
     *
     * @effects notification_template_unchanged_save_with_scalar_role_keeps_default
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_save_with_scalar_role_recipient_keeps_default(): void
    {
        $this->template->update(['recipients' => [['type' => 'role', 'value' => 'admin']]]);
        app()->instance('user_overrides.seeding', true);
        $this->template->update(['user_overrides' => null, 'is_default' => true]);
        app()->forgetInstance('user_overrides.seeding');
        $this->definition->update(['is_default' => true]);

        $recipients = $this->authRequest()
            ->getJson("/api/admin/notification-definitions/{$this->definition->id}")
            ->assertStatus(200)
            ->json('data.templates.0.recipients');
        $this->assertSame(['admin'], $recipients[0]['value']);

        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
            'body' => ['ko' => '<p>안녕하세요</p>', 'en' => '<p>Hello</p>'],
            'recipients' => $recipients,
            'is_active' => true,
        ])->assertStatus(200);

        $fresh = $this->template->fresh();
        $this->assertEmpty($fresh->user_overrides ?? [], '받은 수신자를 그대로 저장했는데 수정 표식이 생겼다');
        $this->assertTrue((bool) $fresh->is_default);
        $this->assertTrue((bool) $this->definition->fresh()->is_default);
    }

    /**
     * 켜기/끄기를 원래 값으로 되돌리면 「수정됨」 표식도 풀린다 — 그 뒤 저장만 해도 「기본」 이 유지된다.
     *
     * 종전에는 끄면 `is_active` 표식이 붙고 다시 켜도 남아, 이어서 편집 창을 열고 그대로 저장하면
     * 템플릿·정의의 「기본」 이 풀리고 [기본값 복원] 이 떴다(2026-10-07 실측).
     *
     * @scenario editing_axis=toggle_active, layer=user_overrides
     *
     * @effects notification_template_toggle_back_releases_override
     *
     * @return void 반환값 없음
     */
    public function test_toggle_back_to_declared_value_releases_override(): void
    {
        $this->template->update(['is_default' => true]);
        $this->definition->update(['is_default' => true]);

        $this->authRequest()->patchJson("/api/admin/notification-templates/{$this->template->id}/toggle-active")->assertStatus(200);
        $this->assertSame(['is_active'], $this->template->fresh()->user_overrides, '끈 것은 운영자 수정이다');

        $this->authRequest()->patchJson("/api/admin/notification-templates/{$this->template->id}/toggle-active")->assertStatus(200);
        $this->assertEmpty($this->template->fresh()->user_overrides ?? [], '선언값으로 되돌렸는데 표식이 남았다');

        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
            'body' => ['ko' => '<p>안녕하세요</p>', 'en' => '<p>Hello</p>'],
            'is_active' => true,
        ])->assertStatus(200);

        $fresh = $this->template->fresh();
        $this->assertEmpty($fresh->user_overrides ?? []);
        $this->assertTrue((bool) $fresh->is_default);
        $this->assertTrue((bool) $this->definition->fresh()->is_default);
    }

    /**
     * 고친 문구를 선언 문구로 되돌려 저장하면 템플릿과 소속 정의의 「기본」 이 함께 돌아온다.
     *
     * 템플릿 표식은 풀려 템플릿은 「기본」 이 되는데, 정의는 한 번 풀린 「기본」 을 다시 맞추지 않아 카드에서
     * 「기본」 배지가 사라지고 [초기화] 가 남았다 — 동기화 보정(소속 템플릿이 전부 기본이면 정의도 기본)과
     * 같은 규칙을 저장 직후에도 적용한다(2026-10-07 실측).
     *
     * @scenario editing_axis=revert_to_declared, layer=user_overrides
     *
     * @effects notification_template_revert_restores_definition_default
     *
     * @return void 반환값 없음
     */
    public function test_reverting_to_declared_text_restores_definition_default(): void
    {
        $declared = app(NotificationTemplateService::class)->getDefaultTemplateData('welcome', 'mail');
        $this->assertNotEmpty($declared, '환영 알림 메일 선언이 있어야 한다');

        app()->instance('user_overrides.seeding', true);
        $this->template->update([
            'subject' => $declared['subject'],
            'body' => $declared['body'],
            'recipients' => $declared['recipients'],
            'user_overrides' => null,
            'is_default' => true,
        ]);
        app()->forgetInstance('user_overrides.seeding');
        $this->definition->update(['is_default' => true]);

        $edited = $declared['subject'];
        $edited['ko'] = ($edited['ko'] ?? '').' (수정)';
        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => $edited,
            'body' => $declared['body'],
            'is_active' => true,
        ])->assertStatus(200);
        $this->assertFalse((bool) $this->definition->fresh()->is_default, '고쳐 저장하면 정의도 기본이 풀린다');

        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'subject' => $declared['subject'],
            'body' => $declared['body'],
            'is_active' => true,
        ])->assertStatus(200);

        $fresh = $this->template->fresh();
        $this->assertEmpty($fresh->user_overrides ?? [], '선언 문구로 되돌렸는데 표식이 남았다');
        $this->assertTrue((bool) $fresh->is_default);
        $this->assertTrue((bool) $this->definition->fresh()->is_default, '소속 템플릿이 전부 기본인데 정의의 「기본」 이 돌아오지 않았다');
    }

    /**
     * 운영자가 추가한(선언 없는) 정의는 템플릿을 그대로 저장해도 「기본」 으로 바뀌지 않는다 — 삭제 가능 여부가 이 값에 걸려 있다.
     *
     * @scenario editing_axis=operator_definition, layer=user_overrides
     *
     * @effects notification_template_operator_definition_stays_non_default
     *
     * @return void 반환값 없음
     */
    public function test_operator_definition_is_not_promoted_to_default_on_save(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'identity.policy.qa_custom',
            'hook_prefix' => 'core.identity',
            'extension_type' => 'core',
            'extension_identifier' => 'admin',
            'category' => 'identity',
            'name' => ['ko' => '정책 문구', 'en' => 'Policy text'],
            'channels' => ['mail'],
            'hooks' => [],
            'is_default' => false,
        ]);
        $template = NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '코드', 'en' => 'Code'],
            'body' => ['ko' => '<p>{code}</p>', 'en' => '<p>{code}</p>'],
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->authRequest()->putJson("/api/admin/notification-templates/{$template->id}", [
            'subject' => ['ko' => '코드', 'en' => 'Code'],
            'body' => ['ko' => '<p>{code}</p>', 'en' => '<p>{code}</p>'],
            'is_active' => true,
        ])->assertStatus(200);

        $this->assertFalse((bool) $definition->fresh()->is_default, '운영자가 추가한 정의가 「기본」(삭제 불가)으로 바뀌었다');
    }

    /**
     * 수신자 규칙 여러 개를 저장하면 보낸 순서 그대로 목록(JSON 배열)으로 저장되고, 다시 열 때도 같은 순서다.
     *
     * 값 칸이 없는 규칙(이벤트 유발자) 뒤에 역할 규칙이 오면, 검증 결과를 규칙 순서대로 다시 조립하면서
     * 둘째 규칙이 먼저 자리를 잡아 `{"1":…,"0":…}` 객체로 저장됐고 다시 열면 순서가 뒤집혔다(2026-10-07 실측).
     *
     * @scenario editing_axis=role_recipients, layer=request_validation
     *
     * @effects notification_template_recipients_keep_submitted_order
     *
     * @return void 반환값 없음
     */
    public function test_update_template_keeps_recipient_rule_order_as_list(): void
    {
        Role::create(['name' => ['ko' => '운영자'], 'identifier' => 'manager', 'is_admin' => true]);

        $this->authRequest()->putJson("/api/admin/notification-templates/{$this->template->id}", [
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'recipients' => [
                ['type' => 'trigger_user'],
                ['type' => 'role', 'value' => ['super_admin', 'manager']],
            ],
        ])->assertStatus(200);

        $raw = $this->template->fresh()->getRawOriginal('recipients');
        $this->assertStringStartsWith('[', $raw, '수신자 규칙은 JSON 배열로 저장한다');
        $stored = $this->template->fresh()->recipients;
        $this->assertTrue(array_is_list($stored));
        $this->assertSame(['trigger_user', 'role'], array_column($stored, 'type'));
        $this->assertSame(['super_admin', 'manager'], $stored[1]['value']);

        $recipients = $this->authRequest()
            ->getJson("/api/admin/notification-definitions/{$this->definition->id}")
            ->assertStatus(200)
            ->json('data.templates.0.recipients');

        $this->assertSame(['trigger_user', 'role'], array_column($recipients, 'type'));
    }

    /**
     * 이미 객체(`{"1":…,"0":…}`)로 저장된 수신자 규칙도 응답에서는 원래 줄 번호 순서의 목록으로 돌려준다.
     *
     * @scenario editing_axis=role_recipients, layer=resource_payload
     *
     * @effects notification_template_resource_lists_keyed_recipients_in_index_order
     *
     * @return void 반환값 없음
     */
    public function test_template_resource_lists_keyed_recipients_in_index_order(): void
    {
        NotificationTemplate::query()->whereKey($this->template->id)->update([
            'recipients' => '{"1":{"type":"role","value":["super_admin"]},"0":{"type":"trigger_user"}}',
        ]);

        $response = $this->authRequest()
            ->getJson("/api/admin/notification-definitions/{$this->definition->id}")
            ->assertStatus(200);

        $this->assertStringContainsString('"recipients":[{"type":"trigger_user"', $response->getContent());
        $recipients = $response->json('data.templates.0.recipients');
        $this->assertSame(['trigger_user', 'role'], array_column($recipients, 'type'));
    }
}
