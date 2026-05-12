<?php

namespace Tests\Feature\Api\Admin\Identity;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 — IDV 프로바이더 API 의 read / update 권한 분리 검증.
 *
 * 회귀 가드: 운영자가 "프로바이더 설정 조회" 권한만 받은 상태에서
 * GET /api/admin/identity/providers 가 200 으로 응답해야 한다.
 * 이전에는 GET 도 `core.admin.identity.manage` 를 요구해 조회 전용 운영자에게 403 발생.
 */
class AdminIdentityProviderPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUserWithPermissions(array $identifiers): array
    {
        $user = User::factory()->create(['is_super' => false]);

        $role = Role::create([
            'identifier' => 'test-idv-role-'.uniqid(),
            'name' => ['ko' => '테스트 IDV 역할', 'en' => 'Test IDV Role'],
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

        return [$user->fresh(), $user->createToken('test')->plainTextToken];
    }

    private function auth(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    /**
     * 회귀: providers.read 권한만 가진 운영자가 GET 으로 200 을 받아야 한다.
     * 현재 라우트가 `.manage` 만 체크하므로 fail (403) → 수정 후 green (200).
     */
    public function test_user_with_only_providers_read_can_list_providers(): void
    {
        [, $token] = $this->makeUserWithPermissions(['core.admin.identity.providers.read']);

        $response = $this->withHeaders($this->auth($token))
            ->getJson('/api/admin/identity/providers');

        $response->assertStatus(200);
    }

    /**
     * 권한이 전혀 없는 운영자는 403.
     */
    public function test_user_with_no_identity_permissions_cannot_list_providers(): void
    {
        [, $token] = $this->makeUserWithPermissions([]);

        $response = $this->withHeaders($this->auth($token))
            ->getJson('/api/admin/identity/providers');

        $response->assertStatus(403);
    }

    /**
     * providers.update 만 가진 운영자는 GET 차단 (read 가 없으므로).
     */
    public function test_user_with_only_providers_update_cannot_list_providers(): void
    {
        [, $token] = $this->makeUserWithPermissions(['core.admin.identity.providers.update']);

        $response = $this->withHeaders($this->auth($token))
            ->getJson('/api/admin/identity/providers');

        $response->assertStatus(403);
    }

    /**
     * read + update 둘 다 있으면 통과.
     */
    public function test_user_with_both_read_and_update_can_list_providers(): void
    {
        [, $token] = $this->makeUserWithPermissions([
            'core.admin.identity.providers.read',
            'core.admin.identity.providers.update',
        ]);

        $response = $this->withHeaders($this->auth($token))
            ->getJson('/api/admin/identity/providers');

        $response->assertStatus(200);
    }
}
