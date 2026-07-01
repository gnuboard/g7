<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateCustomTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TemplateCustomTranslationController API 테스트.
 *
 * 커스텀 다국어 키 CRUD / 권한 경계 / 키 자동 생성 / seq 충돌 / 낙관적 잠금(409)을
 * 검증합니다.
 */
class TemplateCustomTranslationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 레이아웃/템플릿 요청 경로가 GDPR 정책 테이블을 조회하므로
     * 해당 플러그인 마이그레이션을 테스트 DB 에 포함시킨다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private User $adminUser;

    private User $normalUser;

    private Template $template;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'core.templates.read',
            'core.templates.layouts.edit',
        ]);
        $this->token = $this->adminUser->createToken('test-token')->plainTextToken;

        $this->normalUser = User::factory()->create();
        $this->template = Template::factory()->create();
    }

    /**
     * 관리자 사용자 생성 (필요한 권한 포함)
     *
     * @param  array<string>  $permissions  부여할 권한 식별자 목록
     */
    private function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $permissionIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permissionIdentifier],
                [
                    'name' => json_encode(['ko' => $permissionIdentifier, 'en' => $permissionIdentifier]),
                    'description' => json_encode(['ko' => $permissionIdentifier, 'en' => $permissionIdentifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
                'is_active' => true,
            ]
        );

        $testRole->permissions()->sync($permissionIds);
        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    private function endpoint(string $suffix = ''): string
    {
        return "/api/admin/templates/{$this->template->identifier}/custom-translations{$suffix}";
    }

    // ---- store: 키 자동 생성 ----

    public function test_store_creates_key_with_auto_generated_translation_key(): void
    {
        $response = $this->authRequest()->postJson($this->endpoint(), [
            'layout_name' => 'board/list',
            'locale' => 'ko',
            'value' => '안녕하세요',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.translation_key', 'custom.board_list.1');
        $response->assertJsonPath('data.values.ko', '안녕하세요');
        // 모든 활성 로케일에 폴백 시드
        $response->assertJsonPath('data.values.en', '안녕하세요');
        $response->assertJsonPath('data.lock_version', 0);

        $this->assertDatabaseHas('template_custom_translations', [
            'template_id' => $this->template->id,
            'translation_key' => 'custom.board_list.1',
            'status' => 'active',
        ]);
    }

    public function test_store_increments_seq_for_same_layout(): void
    {
        $this->authRequest()->postJson($this->endpoint(), [
            'layout_name' => 'home',
            'locale' => 'ko',
            'value' => '첫번째',
        ])->assertStatus(201)->assertJsonPath('data.translation_key', 'custom.home.1');

        $this->authRequest()->postJson($this->endpoint(), [
            'layout_name' => 'home',
            'locale' => 'ko',
            'value' => '두번째',
        ])->assertStatus(201)->assertJsonPath('data.translation_key', 'custom.home.2');
    }

    public function test_store_validation_fails_when_value_missing(): void
    {
        $response = $this->authRequest()->postJson($this->endpoint(), [
            'layout_name' => 'home',
            'locale' => 'ko',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['value']);
    }

    // ---- index ----

    public function test_index_returns_keys_filtered_by_layout(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => 'active',
            'lock_version' => 0,
        ]);
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'board/list',
            'translation_key' => 'custom.board_list.1',
            'values' => ['ko' => 'B', 'en' => 'B'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $response = $this->authRequest()->getJson($this->endpoint('?layout_name=home'));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.translation_key', 'custom.home.1');
    }

    // ---- update: 낙관적 잠금 ----

    public function test_update_changes_values_and_increments_lock_version(): void
    {
        $row = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '구', 'en' => 'old'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $response = $this->authRequest()->putJson($this->endpoint("/{$row->id}"), [
            'values' => ['ko' => '신', 'en' => 'new'],
            'expected_lock_version' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.values.ko', '신');
        $response->assertJsonPath('data.lock_version', 1);

        $this->assertDatabaseHas('template_custom_translations', [
            'id' => $row->id,
            'lock_version' => 1,
        ]);
    }

    public function test_update_returns_409_on_lock_version_conflict(): void
    {
        $row = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => 'active',
            'lock_version' => 3,
        ]);

        $response = $this->authRequest()->putJson($this->endpoint("/{$row->id}"), [
            'values' => ['ko' => 'B', 'en' => 'B'],
            'expected_lock_version' => 1, // stale
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('errors.error', 'concurrent_modification');
        $response->assertJsonPath('errors.current_version', 3);
        $response->assertJsonPath('errors.your_version', 1);

        // 값이 변경되지 않았는지 확인
        $this->assertDatabaseHas('template_custom_translations', [
            'id' => $row->id,
            'lock_version' => 3,
        ]);
    }

    public function test_update_concurrent_edit_one_succeeds_one_conflicts(): void
    {
        $row = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        // 두 클라이언트 모두 expected=0 보유
        $first = $this->authRequest()->putJson($this->endpoint("/{$row->id}"), [
            'values' => ['ko' => 'first', 'en' => 'first'],
            'expected_lock_version' => 0,
        ]);
        $first->assertStatus(200);

        $second = $this->authRequest()->putJson($this->endpoint("/{$row->id}"), [
            'values' => ['ko' => 'second', 'en' => 'second'],
            'expected_lock_version' => 0,
        ]);
        $second->assertStatus(409);

        $this->assertDatabaseHas('template_custom_translations', [
            'id' => $row->id,
            'lock_version' => 1,
        ]);
    }

    public function test_update_validation_fails_when_expected_lock_version_missing(): void
    {
        $row = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $response = $this->authRequest()->putJson($this->endpoint("/{$row->id}"), [
            'values' => ['ko' => 'B', 'en' => 'B'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['expected_lock_version']);
    }

    // ---- destroy ----

    public function test_destroy_removes_key(): void
    {
        $row = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $response = $this->authRequest()->deleteJson($this->endpoint("/{$row->id}"));

        $response->assertStatus(200);
        $this->assertDatabaseMissing('template_custom_translations', ['id' => $row->id]);
    }

    public function test_update_rejects_key_from_other_template(): void
    {
        $otherTemplate = Template::factory()->create();
        $row = TemplateCustomTranslation::create([
            'template_id' => $otherTemplate->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $response = $this->authRequest()->putJson($this->endpoint("/{$row->id}"), [
            'values' => ['ko' => 'B', 'en' => 'B'],
            'expected_lock_version' => 0,
        ]);

        $response->assertStatus(404);
    }

    // ---- bulkDestroy: 일괄 삭제 ----

    public function test_bulk_destroy_removes_multiple_keys(): void
    {
        $a = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => 'orphaned',
            'lock_version' => 0,
        ]);
        $b = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.2',
            'values' => ['ko' => 'B', 'en' => 'B'],
            'status' => 'orphaned',
            'lock_version' => 0,
        ]);

        $response = $this->authRequest()->deleteJson($this->endpoint(), [
            'ids' => [$a->id, $b->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted', 2);
        $this->assertDatabaseMissing('template_custom_translations', ['id' => $a->id]);
        $this->assertDatabaseMissing('template_custom_translations', ['id' => $b->id]);
    }

    public function test_bulk_destroy_ignores_keys_from_other_template(): void
    {
        $otherTemplate = Template::factory()->create();
        $own = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => 'active',
            'lock_version' => 0,
        ]);
        $foreign = TemplateCustomTranslation::create([
            'template_id' => $otherTemplate->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'X', 'en' => 'X'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $response = $this->authRequest()->deleteJson($this->endpoint(), [
            'ids' => [$own->id, $foreign->id],
        ]);

        $response->assertStatus(200);
        // 본 템플릿 소속만 삭제 — 교차 템플릿 행은 보존.
        $response->assertJsonPath('data.deleted', 1);
        $this->assertDatabaseMissing('template_custom_translations', ['id' => $own->id]);
        $this->assertDatabaseHas('template_custom_translations', ['id' => $foreign->id]);
    }

    public function test_bulk_destroy_validation_fails_when_ids_missing(): void
    {
        $response = $this->authRequest()->deleteJson($this->endpoint(), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    public function test_bulk_destroy_forbidden_without_edit_permission(): void
    {
        $row = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => 'orphaned',
            'lock_version' => 0,
        ]);
        $token = $this->normalUser->createToken('t')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->deleteJson($this->endpoint(), ['ids' => [$row->id]]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('template_custom_translations', ['id' => $row->id]);
    }

    // ---- 권한 경계 ----

    public function test_store_forbidden_without_edit_permission(): void
    {
        $token = $this->normalUser->createToken('t')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson($this->endpoint(), [
            'layout_name' => 'home',
            'locale' => 'ko',
            'value' => 'x',
        ]);

        $response->assertStatus(403);
    }

    public function test_index_unauthenticated_returns_401(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->endpoint());

        $response->assertStatus(401);
    }
}
