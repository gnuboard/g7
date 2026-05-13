<?php

namespace Tests\Feature\Api\Admin\Identity;

use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 — IDV 메시지 정의/템플릿 컨트롤러 통합 테스트.
 *
 * golden path + 권한 경계 + user_overrides 기록 검증.
 */
class AdminIdentityMessageControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityMessageDefinitionSeeder::class);

        $this->admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }
        $this->admin = $this->admin->fresh();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 인증 헤더 헬퍼.
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    public function test_index_returns_paginated_definitions(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/identity/messages/definitions');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['data', 'pagination']]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data.data')));
    }

    public function test_index_filter_by_scope_type(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/identity/messages/definitions?scope_type=purpose');

        $response->assertStatus(200);
        $items = $response->json('data.data');
        foreach ($items as $item) {
            $this->assertSame(IdentityMessageDefinition::SCOPE_PURPOSE, $item['scope_type']);
        }
    }

    public function test_show_returns_definition_with_templates(): void
    {
        $definition = IdentityMessageDefinition::where('scope_value', 'signup')->firstOrFail();

        $response = $this->authRequest()->getJson('/api/admin/identity/messages/definitions/'.$definition->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $definition->id)
            ->assertJsonStructure(['data' => ['templates']]);
    }

    public function test_template_update_records_user_overrides(): void
    {
        $template = IdentityMessageTemplate::whereHas('definition', fn ($q) => $q
            ->where('scope_value', 'signup')
        )->firstOrFail();

        $response = $this->authRequest()->patchJson(
            '/api/admin/identity/messages/templates/'.$template->id,
            [
                'subject' => ['ko' => '운영자 제목', 'en' => 'Custom Subject'],
                'body' => ['ko' => '본문 {code}', 'en' => 'Body {code}'],
                'is_active' => true,
            ],
        );

        $response->assertStatus(200);

        $template->refresh();
        $this->assertSame('운영자 제목', $template->subject['ko']);
        $this->assertContains('subject', $template->user_overrides ?? []);
        $this->assertFalse($template->is_default, '운영자 편집 후 is_default 가 false 로 전환되어야 합니다.');
    }

    public function test_template_update_requires_admin_permission(): void
    {
        $template = IdentityMessageTemplate::first();

        $regularUser = User::factory()->create(['is_super' => false]);
        $regularToken = $regularUser->createToken('regular')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$regularToken,
            'Accept' => 'application/json',
        ])->patchJson(
            '/api/admin/identity/messages/templates/'.$template->id,
            ['body' => ['ko' => '본문', 'en' => 'Body']],
        );

        $response->assertStatus(403);
    }

    public function test_template_update_validates_body_required(): void
    {
        $template = IdentityMessageTemplate::first();

        $response = $this->authRequest()->patchJson(
            '/api/admin/identity/messages/templates/'.$template->id,
            ['subject' => ['ko' => '제목', 'en' => 'Subject']],
        );

        $response->assertStatus(422);
    }

    public function test_template_preview_renders_variables(): void
    {
        $template = IdentityMessageTemplate::whereHas('definition', fn ($q) => $q
            ->where('scope_value', 'signup')
        )->firstOrFail();

        $response = $this->authRequest()->postJson(
            '/api/admin/identity/messages/templates/preview',
            [
                'template_id' => $template->id,
                'data' => [
                    'code' => '999111',
                    'expire_minutes' => 15,
                    'app_name' => 'TestApp',
                    'site_url' => 'https://test.example',
                ],
                'locale' => 'ko',
            ],
        );

        $response->assertStatus(200);
        $body = $response->json('data.body');
        $this->assertStringContainsString('999111', $body);
        $this->assertStringContainsString('15', $body);
        $this->assertStringContainsString('TestApp', $body);
        $this->assertStringNotContainsString('{code}', $body);
    }

    public function test_template_reset_restores_defaults_and_clears_overrides(): void
    {
        $template = IdentityMessageTemplate::whereHas('definition', fn ($q) => $q
            ->where('scope_value', 'signup')
        )->firstOrFail();

        $template->update([
            'subject' => ['ko' => '커스텀', 'en' => 'Custom'],
            'body' => ['ko' => '커스텀 본문', 'en' => 'Custom body'],
        ]);
        $template->refresh();
        $this->assertNotEmpty($template->user_overrides);

        $response = $this->authRequest()->postJson(
            '/api/admin/identity/messages/templates/'.$template->id.'/reset',
        );

        $response->assertStatus(200);

        $template->refresh();
        $this->assertNotSame('커스텀', $template->subject['ko'], '시더 기본값으로 복원되어야 합니다.');
        $this->assertSame([], $template->user_overrides ?? [], 'reset 후 user_overrides 비워져야 합니다.');
        $this->assertTrue($template->is_default);
    }

    public function test_definition_toggle_active_flips_state(): void
    {
        $definition = IdentityMessageDefinition::where('scope_value', 'signup')->firstOrFail();
        $original = $definition->is_active;

        $response = $this->authRequest()->patchJson(
            '/api/admin/identity/messages/definitions/'.$definition->id.'/toggle-active',
        );

        $response->assertStatus(200);

        $definition->refresh();
        $this->assertSame(! $original, $definition->is_active);
    }
}
