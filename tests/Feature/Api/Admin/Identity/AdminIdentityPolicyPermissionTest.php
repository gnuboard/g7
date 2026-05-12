<?php

namespace Tests\Feature\Api\Admin\Identity;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\IdentityPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 — IDV 정책 API 의 read / update 권한 분리 검증.
 *
 * 회귀 가드: 운영자가 "정책 조회" 권한만 받은 상태에서
 * GET /api/admin/identity/policies 가 200 으로 응답해야 한다.
 * 수정/생성/삭제는 `core.admin.identity.policies.update` 권한 필요.
 */
class AdminIdentityPolicyPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUserWithPermissions(array $identifiers): string
    {
        $user = User::factory()->create(['is_super' => false]);

        $role = Role::create([
            'identifier' => 'test-pol-role-'.uniqid(),
            'name' => ['ko' => '테스트 정책 역할', 'en' => 'Test Policy Role'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);

        foreach ($identifiers as $identifier) {
            $perm = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => '', 'en' => ''],
                    'type' => PermissionType::Admin,
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'order' => 0,
                ]
            );
            $role->permissions()->attach($perm->id, [
                'granted_at' => now(),
                'granted_by' => null,
            ]);
        }

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh()->createToken('test')->plainTextToken;
    }

    private function auth(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    private function makePolicy(): IdentityPolicy
    {
        return IdentityPolicy::create([
            'key' => 'perm.test.'.uniqid(),
            'scope' => 'route',
            'target' => 'api.perm.test',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);
    }

    public function test_policies_read_only_user_can_list(): void
    {
        $token = $this->makeUserWithPermissions(['core.admin.identity.policies.read']);

        $response = $this->withHeaders($this->auth($token))
            ->getJson('/api/admin/identity/policies');

        $response->assertStatus(200);
    }

    public function test_policies_read_only_user_cannot_create(): void
    {
        $token = $this->makeUserWithPermissions(['core.admin.identity.policies.read']);

        $response = $this->withHeaders($this->auth($token))
            ->postJson('/api/admin/identity/policies', [
                'key' => 'forbidden.create',
                'scope' => 'route',
                'target' => 'api.forbid',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'applies_to' => 'both',
                'fail_mode' => 'block',
            ]);

        $response->assertStatus(403);
    }

    public function test_policies_read_only_user_cannot_update(): void
    {
        $token = $this->makeUserWithPermissions(['core.admin.identity.policies.read']);
        $policy = $this->makePolicy();

        $response = $this->withHeaders($this->auth($token))
            ->putJson("/api/admin/identity/policies/{$policy->id}", ['grace_minutes' => 42]);

        $response->assertStatus(403);
    }

    public function test_policies_read_only_user_cannot_delete(): void
    {
        $token = $this->makeUserWithPermissions(['core.admin.identity.policies.read']);
        $policy = $this->makePolicy();

        $response = $this->withHeaders($this->auth($token))
            ->deleteJson("/api/admin/identity/policies/{$policy->id}");

        $response->assertStatus(403);
    }

    public function test_policies_update_only_user_cannot_list(): void
    {
        $token = $this->makeUserWithPermissions(['core.admin.identity.policies.update']);

        $response = $this->withHeaders($this->auth($token))
            ->getJson('/api/admin/identity/policies');

        $response->assertStatus(403);
    }

    public function test_policies_update_only_user_can_create(): void
    {
        $token = $this->makeUserWithPermissions(['core.admin.identity.policies.update']);

        $response = $this->withHeaders($this->auth($token))
            ->postJson('/api/admin/identity/policies', [
                'key' => 'update.only.create',
                'scope' => 'route',
                'target' => 'api.upd.only',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'applies_to' => 'both',
                'fail_mode' => 'block',
            ]);

        $response->assertStatus(201);
    }

    public function test_policies_no_permission_user_blocked_on_all(): void
    {
        $token = $this->makeUserWithPermissions([]);
        $policy = $this->makePolicy();

        $this->withHeaders($this->auth($token))
            ->getJson('/api/admin/identity/policies')
            ->assertStatus(403);

        $this->withHeaders($this->auth($token))
            ->postJson('/api/admin/identity/policies', ['key' => 'no.perm'])
            ->assertStatus(403);

        $this->withHeaders($this->auth($token))
            ->putJson("/api/admin/identity/policies/{$policy->id}", ['grace_minutes' => 1])
            ->assertStatus(403);

        $this->withHeaders($this->auth($token))
            ->deleteJson("/api/admin/identity/policies/{$policy->id}")
            ->assertStatus(403);
    }

    public function test_policies_full_access_user_can_all(): void
    {
        $token = $this->makeUserWithPermissions([
            'core.admin.identity.policies.read',
            'core.admin.identity.policies.update',
        ]);
        $policy = $this->makePolicy();

        $this->withHeaders($this->auth($token))
            ->getJson('/api/admin/identity/policies')
            ->assertStatus(200);

        $this->withHeaders($this->auth($token))
            ->putJson("/api/admin/identity/policies/{$policy->id}", ['grace_minutes' => 33])
            ->assertStatus(200);

        $this->withHeaders($this->auth($token))
            ->deleteJson("/api/admin/identity/policies/{$policy->id}")
            ->assertStatus(200);
    }
}
