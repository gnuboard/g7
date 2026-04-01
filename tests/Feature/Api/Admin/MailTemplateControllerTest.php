<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\MailTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * MailTemplateController Feature 테스트
 *
 * 메일 템플릿 API 엔드포인트의 인증, 권한, CRUD 동작을 검증합니다.
 */
class MailTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 생성 및 할당
     *
     * @param array $permissions 사용자에게 부여할 권한 식별자 목록
     * @return User
     */
    private function createAdminUser(array $permissions = ['core.settings.read', 'core.settings.update']): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier.' Permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $roleIdentifier = 'admin_test_'.uniqid();
        $adminRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        $adminBaseRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $adminRole->permissions()->sync($permissionIds);

        $user->roles()->attach($adminBaseRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증 헤더와 함께 요청
     *
     * @return $this
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/mail-templates');
        $response->assertStatus(401);
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->createAdminUser([]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/mail-templates');

        $response->assertStatus(403);
    }

    public function test_update_returns_403_without_update_permission(): void
    {
        $user = $this->createAdminUser(['core.settings.read']);
        $token = $user->createToken('test-token')->plainTextToken;
        $template = MailTemplate::factory()->withType('welcome')->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->putJson("/api/admin/mail-templates/{$template->id}", [
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '<p>본문</p>'],
        ]);

        $response->assertStatus(403);
    }

    // ========================================================================
    // index
    // ========================================================================

    public function test_index_returns_paginated_template_list(): void
    {
        MailTemplate::factory()->withType('welcome')->create();
        MailTemplate::factory()->withType('reset_password')->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-templates');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                    ],
                ],
            ]);

        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(2, $response->json('data.pagination.total'));
    }

    public function test_index_paginates_with_per_page(): void
    {
        MailTemplate::factory()->count(5)->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-templates?per_page=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(5, $response->json('data.pagination.total'));
        $this->assertEquals(3, $response->json('data.pagination.last_page'));
    }

    public function test_index_filters_by_subject_search(): void
    {
        MailTemplate::factory()->withType('welcome')->create([
            'subject' => ['ko' => '환영 메일', 'en' => 'Welcome'],
        ]);
        MailTemplate::factory()->withType('reset_password')->create([
            'subject' => ['ko' => '비밀번호 재설정', 'en' => 'Reset Password'],
        ]);

        $response = $this->authRequest()->getJson('/api/admin/mail-templates?search=환영&search_type=subject');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_filters_by_body_search(): void
    {
        MailTemplate::factory()->withType('welcome')->create([
            'body' => ['ko' => '<p>가입을 축하합니다</p>', 'en' => '<p>Congratulations</p>'],
        ]);
        MailTemplate::factory()->withType('reset_password')->create([
            'body' => ['ko' => '<p>비밀번호를 재설정하세요</p>', 'en' => '<p>Reset your password</p>'],
        ]);

        $response = $this->authRequest()->getJson('/api/admin/mail-templates?search=축하&search_type=body');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_filters_by_all_search_type(): void
    {
        MailTemplate::factory()->withType('welcome')->create([
            'subject' => ['ko' => '환영', 'en' => 'Welcome'],
            'body' => ['ko' => '<p>본문</p>', 'en' => '<p>Body</p>'],
        ]);
        MailTemplate::factory()->withType('reset_password')->create([
            'subject' => ['ko' => '재설정', 'en' => 'Reset'],
            'body' => ['ko' => '<p>환영합니다</p>', 'en' => '<p>Welcome</p>'],
        ]);

        $response = $this->authRequest()->getJson('/api/admin/mail-templates?search=환영&search_type=all');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    // ========================================================================
    // update
    // ========================================================================

    public function test_update_saves_template(): void
    {
        $template = MailTemplate::factory()->withType('welcome')->create();

        $response = $this->authRequest()->putJson("/api/admin/mail-templates/{$template->id}", [
            'subject' => ['ko' => '수정된 제목', 'en' => 'Updated Subject'],
            'body' => ['ko' => '<p>수정된 본문</p>', 'en' => '<p>Updated Body</p>'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $template->refresh();
        $this->assertEquals('수정된 제목', $template->subject['ko']);
    }

    public function test_update_validates_required_subject_ko(): void
    {
        $template = MailTemplate::factory()->withType('welcome')->create();

        $response = $this->authRequest()->putJson("/api/admin/mail-templates/{$template->id}", [
            'subject' => ['en' => 'Only English'],
            'body' => ['ko' => '<p>본문</p>'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['subject.ko']);
    }

    public function test_update_validates_required_body_ko(): void
    {
        $template = MailTemplate::factory()->withType('welcome')->create();

        $response = $this->authRequest()->putJson("/api/admin/mail-templates/{$template->id}", [
            'subject' => ['ko' => '제목'],
            'body' => ['en' => '<p>English only</p>'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['body.ko']);
    }

    // ========================================================================
    // toggleActive
    // ========================================================================

    public function test_toggle_active_flips_state(): void
    {
        $template = MailTemplate::factory()->create(['is_active' => true]);

        $response = $this->authRequest()->patchJson("/api/admin/mail-templates/{$template->id}/toggle-active");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $template->refresh();
        $this->assertFalse($template->is_active);
    }

    // ========================================================================
    // preview
    // ========================================================================

    public function test_preview_returns_rendered_result(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/mail-templates/preview', [
            'subject' => 'Hello {name}',
            'body' => '<p>Welcome to {app_name}</p>',
            'variables' => [
                ['key' => 'name'],
                ['key' => 'app_name'],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['subject', 'body']]);
    }

    // ========================================================================
    // reset
    // ========================================================================

    public function test_reset_restores_default_data(): void
    {
        $template = MailTemplate::factory()->withType('welcome')->create([
            'subject' => ['ko' => '사용자 수정'],
            'body' => ['ko' => '<p>수정됨</p>'],
            'is_default' => false,
        ]);

        $response = $this->authRequest()->postJson("/api/admin/mail-templates/{$template->id}/reset");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $template->refresh();
        $this->assertTrue($template->is_default);
    }

    public function test_reset_returns_404_for_unknown_type(): void
    {
        $template = MailTemplate::factory()->withType('nonexistent_type')->create();

        $response = $this->authRequest()->postJson("/api/admin/mail-templates/{$template->id}/reset");

        $response->assertStatus(404);
    }

}
