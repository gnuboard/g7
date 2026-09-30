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

    /**
     * 권한·메시지 정의 시더를 실행하고 슈퍼 관리자와 인증 토큰을 준비한다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @return static Bearer 토큰과 JSON Accept 헤더가 설정된 테스트 인스턴스
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 메시지 정의 목록 API 가 페이지네이션 구조로 시드된 정의를 5건 이상 반환하는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects identity_message_admin_api_contract
     *
     * @return void 반환값 없음
     */
    public function test_index_returns_paginated_definitions(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/identity/messages/definitions');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['data', 'pagination']]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data.data')));
    }

    /**
     * scope_type=purpose 필터를 주면 목록에 purpose 범위 정의만 담기는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects identity_message_admin_api_contract
     *
     * @return void 반환값 없음
     */
    public function test_index_filter_by_scope_type(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/identity/messages/definitions?scope_type=purpose');

        $response->assertStatus(200);
        $items = $response->json('data.data');
        foreach ($items as $item) {
            $this->assertSame(IdentityMessageDefinition::SCOPE_PURPOSE, $item['scope_type']);
        }
    }

    /**
     * 메시지 정의 상세 API 가 해당 정의와 소속 템플릿 목록을 함께 반환하는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects identity_message_admin_api_contract
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_definition_with_templates(): void
    {
        $definition = IdentityMessageDefinition::where('scope_value', 'signup')->firstOrFail();

        $response = $this->authRequest()->getJson('/api/admin/identity/messages/definitions/'.$definition->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $definition->id)
            ->assertJsonStructure(['data' => ['templates']]);
    }

    /**
     * 템플릿 수정 시 subject 로케일 단위(dot-path)로 user_overrides 가 기록되고 is_default 가 false 로 바뀌는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects real_change_is_recorded_per_field
     *
     * @return void 반환값 없음
     */
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
        // subject/body 는 translatableTrackableFields — 수정이 dot-path sub-key 단위로
        // 기록되어야 시더 재실행·언어팩 병합이 수정된 로케일만 보존한다 (#597 교정)
        $this->assertContains('subject.ko', $template->user_overrides ?? []);
        $this->assertContains('subject.en', $template->user_overrides ?? []);
        $this->assertFalse($template->is_default, '운영자 편집 후 is_default 가 false 로 전환되어야 합니다.');
    }

    /**
     * 관리자 권한이 없는 사용자의 템플릿 수정 요청은 403 으로 거부되는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects identity_message_admin_api_contract
     *
     * @return void 반환값 없음
     */
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

    /**
     * body 없이 템플릿 수정을 요청하면 422 검증 오류가 나는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects identity_message_admin_api_contract
     *
     * @return void 반환값 없음
     */
    public function test_template_update_validates_body_required(): void
    {
        $template = IdentityMessageTemplate::first();

        $response = $this->authRequest()->patchJson(
            '/api/admin/identity/messages/templates/'.$template->id,
            ['subject' => ['ko' => '제목', 'en' => 'Subject']],
        );

        $response->assertStatus(422);
    }

    /**
     * 템플릿 미리보기가 전달한 변수를 본문에 치환해 {code} 같은 자리표시자가 남지 않는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects identity_message_admin_api_contract
     *
     * @return void 반환값 없음
     */
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

    /**
     * 템플릿 초기화 시 시더 기본값으로 복원되고 user_overrides 가 비워지며 is_default 가 true 가 되는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects identity_message_admin_api_contract
     *
     * @return void 반환값 없음
     */
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

    /**
     * 편집으로 내려간 정의의 is_default 가 템플릿 초기화 후 다시 true 로 복원되는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects identity_message_admin_api_contract
     *
     * @return void 반환값 없음
     */
    public function test_template_reset_restores_definition_is_default_flag(): void
    {
        // 시드 정의(is_default=true)의 템플릿을 운영자가 편집하면 definition.is_default 가 false 로 내려간다.
        $template = IdentityMessageTemplate::whereHas('definition', fn ($q) => $q
            ->where('scope_value', 'signup')
        )->firstOrFail();

        $definitionId = $template->definition_id;
        $this->assertTrue(
            IdentityMessageDefinition::findOrFail($definitionId)->is_default,
            '시드 정의는 초기 상태에서 is_default=true 여야 합니다.'
        );

        // 운영자 편집 → definition.is_default false 로 하강
        $this->authRequest()->patchJson(
            '/api/admin/identity/messages/templates/'.$template->id,
            [
                'subject' => ['ko' => '커스텀', 'en' => 'Custom'],
                'body' => ['ko' => '커스텀 본문', 'en' => 'Custom body'],
            ],
        )->assertStatus(200);

        $this->assertFalse(
            IdentityMessageDefinition::findOrFail($definitionId)->is_default,
            '편집 후 definition.is_default 가 false 로 전환되어야 합니다 (결함 C 전제).'
        );

        // reset → definition.is_default 가 true 로 복원되어야 한다 ('기본' 배지/reset 버튼 노출 조건 정상화)
        $this->authRequest()->postJson(
            '/api/admin/identity/messages/templates/'.$template->id.'/reset',
        )->assertStatus(200);

        $this->assertTrue(
            IdentityMessageDefinition::findOrFail($definitionId)->is_default,
            'reset 후 시드 정의의 definition.is_default 가 true 로 복원되어야 합니다 (결함 C).'
        );
    }

    /**
     * 정의 활성 토글 API 가 is_active 값을 반대로 뒤집는지 검증한다.
     *
     * @scenario surface=identity_message
     *
     * @effects identity_message_admin_api_contract
     *
     * @return void 반환값 없음
     */
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

    /**
     * 편집 창이 받은 값을 그대로 저장하면(값 변경 없음) 수정 표식·「기본」 해제가 일어나지 않는다 (#727 D5-⑤).
     *
     * @scenario surface=identity_message
     *
     * @effects same_meaning_values_are_not_overrides
     *
     * @return void 반환값 없음
     */
    public function test_template_save_without_change_keeps_default(): void
    {
        $template = IdentityMessageTemplate::whereHas('definition', fn ($q) => $q->where('scope_value', 'signup'))->firstOrFail();
        $template->forceFill(['is_default' => true, 'user_overrides' => null])->saveQuietly();

        $this->authRequest()->patchJson('/api/admin/identity/messages/templates/'.$template->id, [
            'subject' => $template->subject,
            'body' => array_map(fn ($v) => is_string($v) ? $v.' ' : $v, $template->body),
        ])->assertStatus(200);

        $template->refresh();
        $this->assertEmpty($template->user_overrides ?? []);
        $this->assertTrue((bool) $template->is_default);
    }

    /**
     * 비활성 템플릿을 편집해 저장해도(편집 창은 is_active 를 보내지 않는다) 비활성이 유지된다 (#727 W13).
     *
     * @scenario surface=identity_message
     *
     * @effects saving_template_keeps_active_state
     *
     * @return void 반환값 없음
     */
    public function test_editing_inactive_template_keeps_it_inactive(): void
    {
        $template = IdentityMessageTemplate::whereHas('definition', fn ($q) => $q->where('scope_value', 'signup'))->firstOrFail();
        $template->forceFill(['is_active' => false])->saveQuietly();

        $this->authRequest()->patchJson('/api/admin/identity/messages/templates/'.$template->id, [
            'subject' => ['ko' => '운영자 제목', 'en' => 'Custom Subject'],
            'body' => ['ko' => '본문 {code}', 'en' => 'Body {code}'],
        ])->assertStatus(200);

        $this->assertFalse((bool) $template->fresh()->is_active, '비활성 템플릿이 편집 저장으로 다시 켜졌다');
    }
}
