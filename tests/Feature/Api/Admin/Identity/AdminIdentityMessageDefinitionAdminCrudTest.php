<?php

namespace Tests\Feature\Api\Admin\Identity;

use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use App\Models\IdentityPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 — IDV 메시지 정의 admin CRUD (#297).
 *
 * 정책 매핑 정의 운영자 추가/삭제 + 시드 정의 보호 가드 검증.
 */
class AdminIdentityMessageDefinitionAdminCrudTest extends TestCase
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

    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    private function makeAdminPolicy(string $key = 'admin.test.policy'): IdentityPolicy
    {
        return IdentityPolicy::create([
            'key' => $key,
            'scope' => 'route',
            'target' => 'api.test.route',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'conditions' => [],
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]);
    }

    private function validPayload(string $policyKey = 'admin.test.policy'): array
    {
        return [
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_POLICY,
            'scope_value' => $policyKey,
            'name' => ['ko' => '커스텀 정책 메시지', 'en' => 'Custom Policy Message'],
            'description' => ['ko' => '운영자 정책', 'en' => 'Admin policy'],
            'channels' => ['mail'],
            'variables' => [['key' => 'code', 'description' => '인증 코드']],
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => ['ko' => '인증 코드 안내', 'en' => 'Verification Code'],
                    'body' => ['ko' => '<p>코드: {code}</p>', 'en' => '<p>Code: {code}</p>'],
                ],
            ],
        ];
    }

    public function test_admin_creates_definition_for_admin_policy(): void
    {
        $this->makeAdminPolicy();

        $response = $this->authRequest()
            ->postJson('/api/admin/identity/messages/definitions', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.scope_type', 'policy');
        $response->assertJsonPath('data.scope_value', 'admin.test.policy');
        $response->assertJsonPath('data.is_default', false);
        $response->assertJsonPath('data.is_active', true);
        $response->assertJsonPath('data.abilities.can_delete', true);

        $this->assertDatabaseHas('identity_message_definitions', [
            'provider_id' => 'g7:core.mail',
            'scope_type' => 'policy',
            'scope_value' => 'admin.test.policy',
            'is_default' => false,
            'extension_identifier' => 'admin',
        ]);

        $definition = IdentityMessageDefinition::where('scope_value', 'admin.test.policy')->firstOrFail();
        $this->assertCount(1, $definition->templates);
        $this->assertSame('mail', $definition->templates[0]->channel);
    }

    public function test_rejects_non_admin_policy_key(): void
    {
        // admin.test.policy 정책 미생성 → 매칭 실패
        $response = $this->authRequest()
            ->postJson('/api/admin/identity/messages/definitions', $this->validPayload());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope_value']);
    }

    public function test_rejects_core_source_policy_key(): void
    {
        // 코어 시드 정책(source_type='core')은 매칭 거부
        IdentityPolicy::create([
            'key' => 'core.seeded.policy',
            'scope' => 'route',
            'target' => 'api.core.route',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 0,
            'conditions' => [],
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_type' => 'core',
            'source_identifier' => 'core',
        ]);

        $payload = $this->validPayload('core.seeded.policy');

        $response = $this->authRequest()
            ->postJson('/api/admin/identity/messages/definitions', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope_value']);
    }

    public function test_rejects_non_policy_scope_type(): void
    {
        $this->makeAdminPolicy();

        foreach (['provider_default', 'purpose'] as $scopeType) {
            $payload = $this->validPayload();
            $payload['scope_type'] = $scopeType;

            $response = $this->authRequest()
                ->postJson('/api/admin/identity/messages/definitions', $payload);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['scope_type']);
        }
    }

    public function test_rejects_unknown_provider(): void
    {
        $this->makeAdminPolicy();

        $payload = $this->validPayload();
        $payload['provider_id'] = 'unknown:provider';

        $response = $this->authRequest()
            ->postJson('/api/admin/identity/messages/definitions', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['provider_id']);
    }

    public function test_rejects_duplicate_provider_scope_combination(): void
    {
        $this->makeAdminPolicy();

        // 첫 정의 생성
        $this->authRequest()
            ->postJson('/api/admin/identity/messages/definitions', $this->validPayload())
            ->assertStatus(201);

        // 동일 (provider, scope_type, scope_value) 두 번째 생성 → 422
        $response = $this->authRequest()
            ->postJson('/api/admin/identity/messages/definitions', $this->validPayload());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope_value']);
    }

    public function test_admin_deletes_admin_added_definition(): void
    {
        $this->makeAdminPolicy();

        $this->authRequest()
            ->postJson('/api/admin/identity/messages/definitions', $this->validPayload())
            ->assertStatus(201);

        $definition = IdentityMessageDefinition::where('scope_value', 'admin.test.policy')->firstOrFail();
        $templateId = $definition->templates[0]->id;

        $response = $this->authRequest()
            ->deleteJson('/api/admin/identity/messages/definitions/'.$definition->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseMissing('identity_message_definitions', ['id' => $definition->id]);
        // FK cascadeOnDelete 로 자식 templates 도 함께 삭제되어야 함
        $this->assertDatabaseMissing('identity_message_templates', ['id' => $templateId]);
    }

    public function test_rejects_deletion_of_seeded_definition(): void
    {
        // 시더에서 생성된 default 정의 (provider_default scope) 삭제 시도
        $seededDefinition = IdentityMessageDefinition::where('is_default', true)->firstOrFail();

        $response = $this->authRequest()
            ->deleteJson('/api/admin/identity/messages/definitions/'.$seededDefinition->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('identity_message_definitions', ['id' => $seededDefinition->id]);
    }

    public function test_resource_can_delete_is_false_for_seeded_definitions(): void
    {
        $response = $this->authRequest()
            ->getJson('/api/admin/identity/messages/definitions');

        $response->assertStatus(200);

        $rows = $response->json('data.data');
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            if ($row['is_default'] === true) {
                $this->assertSame(false, $row['abilities']['can_delete'] ?? null,
                    "Seeded definition {$row['provider_id']}/{$row['scope_value']} should not be deletable");
            }
        }
    }

    public function test_unauthorized_user_cannot_create(): void
    {
        $this->makeAdminPolicy();

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/admin/identity/messages/definitions', $this->validPayload());

        $response->assertStatus(401);
    }

    public function test_returns_404_for_unknown_definition_destroy(): void
    {
        $response = $this->authRequest()
            ->deleteJson('/api/admin/identity/messages/definitions/999999');

        $response->assertStatus(404);
    }

    /**
     * 컬렉션 응답에 can_create abilities 키가 발행된다 (운영자/슈퍼관리자).
     *
     * 회귀 (#361): 컬렉션 abilityMap 이 can_update 만 발행하던 시기에는
     * 레이아웃의 "정의 추가" 버튼이 `abilities?.can_create !== true` 가드로
     * 항상 disabled 되었다. 컬렉션 abilityMap 보강 검증.
     */
    public function test_collection_publishes_can_create_ability(): void
    {
        $response = $this->authRequest()
            ->getJson('/api/admin/identity/messages/definitions');

        $response->assertStatus(200);
        $response->assertJsonPath('data.abilities.can_create', true);
        $response->assertJsonPath('data.abilities.can_update', true);
    }

    /**
     * 조회 권한(`*.read`)만 부여된 사용자는 컬렉션 abilities + per-item abilities 가
     * 모두 false 로 발행되어, 프론트에서 편집/Toggle/Reset/삭제/정의 추가 액션을
     * 잠가야 한다.
     *
     * 회귀 (#361): 레이아웃이 abilities 가드 없이 항상 버튼을 노출하던 결함.
     */
    public function test_read_only_user_receives_falsey_abilities(): void
    {
        $readPermission = Permission::where('identifier', 'core.admin.identity.messages.read')->firstOrFail();
        $viewerRole = Role::create([
            'identifier' => 'idv-msg-viewer',
            'name' => ['ko' => 'IDV 메시지 조회 전용', 'en' => 'IDV Message Viewer'],
            'description' => ['ko' => '본인인증 메시지 조회 전용', 'en' => 'IDV Message read-only'],
            'is_system' => false,
        ]);
        $viewerRole->permissions()->attach($readPermission->id);

        $viewer = User::factory()->create(['is_super' => false]);
        $viewer->roles()->attach($viewerRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $viewerToken = $viewer->createToken('viewer-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$viewerToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/identity/messages/definitions');

        $response->assertStatus(200);

        // 컬렉션 abilities — 정의 추가 가드용
        $response->assertJsonPath('data.abilities.can_create', false);
        $response->assertJsonPath('data.abilities.can_update', false);

        // per-item abilities — 편집/Toggle/Reset/삭제 가드용
        $rows = $response->json('data.data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(false, $row['abilities']['can_update'] ?? null,
                "Read-only user must not receive can_update=true (def {$row['provider_id']}/{$row['scope_value']})");
            $this->assertSame(false, $row['abilities']['can_delete'] ?? null,
                "Read-only user must not receive can_delete=true (def {$row['provider_id']}/{$row['scope_value']})");
        }
    }
}
