<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 역할 API — 비회원 역할에는 권한 범위(본인·소유역할)를 지정할 수 없다 (7.1.1 — D7).
 *
 * 비회원에게는 「본인」 을 판정할 신원이 없어 범위를 저장해도 실제로는 「전체」 였다. 역할 수정·생성 요청은 범위가
 * 실린 항목마다 422(`permissions.N.scope_type`)로 거부하고, 일반 역할과 비회원을 복제한 새 역할은 지금처럼 저장한다.
 * 기존 RoleControllerTest(RefreshDatabase)와 달리 시드한 코어 역할 위에서 트랜잭션으로 격리한다.
 */
class RoleGuestScopeTest extends TestCase
{
    use DatabaseTransactions;

    private User $superAdmin;

    private Role $guest;

    /**
     * 코어 역할·권한을 심고 슈퍼 관리자를 준비합니다(코어 소유 역할은 슈퍼 관리자만 수정한다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->guest = Role::where('identifier', Role::GUEST_IDENTIFIER)->firstOrFail();

        $this->superAdmin = User::factory()->create(['is_super' => true]);
        $this->superAdmin->roles()->attach(Role::where('identifier', 'admin')->value('id'), ['assigned_at' => now()]);
    }

    /**
     * 권한 식별자로 ID 를 찾습니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return int 권한 ID
     */
    private function permissionId(string $identifier): int
    {
        return (int) Permission::where('identifier', $identifier)->value('id');
    }

    /**
     * 역할의 권한별 범위를 돌려줍니다.
     *
     * @param  Role  $role  역할
     * @return array<string, string|null> 식별자 => 범위
     */
    private function grantsOf(Role $role): array
    {
        return DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $role->id)
            ->orderBy('permissions.identifier')
            ->pluck('role_permissions.scope_type', 'permissions.identifier')
            ->map(fn ($scope) => $scope === null ? null : (string) $scope)
            ->all();
    }

    /**
     * 비회원 역할 수정에 범위가 실리면 실린 항목마다 422 이고 아무것도 저장되지 않는다.
     *
     * @scenario case=update_guest_with_scope
     *
     * @effects guest_scope_rejected_422, nothing_saved_on_reject
     *
     * @return void 반환값 없음
     */
    public function test_update_guest_role_with_scope_returns_422_per_item(): void
    {
        $before = $this->grantsOf($this->guest);

        $response = $this->actingAs($this->superAdmin)->putJson("/api/admin/roles/{$this->guest->id}", [
            'permissions' => [
                ['id' => $this->permissionId('core.identity.request'), 'scope_type' => null],
                ['id' => $this->permissionId('core.users.read'), 'scope_type' => 'self'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.1.scope_type'])
            ->assertJsonMissingValidationErrors(['permissions.0.scope_type']);
        $this->assertSame(__('role.validation.guest_scope_not_allowed'), $response->json('errors')['permissions.1.scope_type'][0]);
        $this->assertSame($before, $this->grantsOf($this->guest->fresh()));
    }

    /**
     * 비회원 역할도 범위 없이(전체) 주는 수정은 통과한다.
     *
     * @scenario case=update_guest_without_scope
     *
     * @effects guest_unscoped_grant_saved
     *
     * @return void 반환값 없음
     */
    public function test_update_guest_role_without_scope_succeeds(): void
    {
        $response = $this->actingAs($this->superAdmin)->putJson("/api/admin/roles/{$this->guest->id}", [
            'permissions' => [['id' => $this->permissionId('core.identity.request'), 'scope_type' => null]],
        ]);

        $response->assertStatus(200);
        $this->assertSame(['core.identity.request' => null], $this->grantsOf($this->guest->fresh()));
    }

    /**
     * 일반 역할은 지금처럼 범위를 저장한다(무회귀).
     *
     * @scenario case=update_regular_with_scope
     *
     * @effects regular_role_scope_saved
     *
     * @return void 반환값 없음
     */
    public function test_update_regular_role_with_scope_succeeds(): void
    {
        $role = Role::create([
            'identifier' => 'scope_regular_'.uniqid(),
            'name' => ['ko' => '일반', 'en' => 'Regular'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)->putJson("/api/admin/roles/{$role->id}", [
            'permissions' => [['id' => $this->permissionId('core.users.read'), 'scope_type' => 'self']],
        ]);

        $response->assertStatus(200);
        $this->assertSame(['core.users.read' => 'self'], $this->grantsOf($role));
    }

    /**
     * 비회원 역할을 복제해 만든 새 역할은 비회원이 아니므로 범위를 저장한다.
     *
     * @scenario case=store_clone_of_guest_with_scope
     *
     * @effects regular_role_scope_saved, supports_permission_scope_flag
     *
     * @return void 반환값 없음
     */
    public function test_store_new_role_cloned_from_guest_with_scope_succeeds(): void
    {
        $response = $this->actingAs($this->superAdmin)->postJson('/api/admin/roles', [
            'identifier' => 'guest_copy_'.substr(uniqid(), -6),
            'name' => ['ko' => '비회원 복제', 'en' => 'Guest copy'],
            'permissions' => [['id' => $this->permissionId('core.users.read'), 'scope_type' => 'role']],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.supports_permission_scope', true);
        $this->assertSame(['core.users.read' => 'role'], $this->grantsOf(Role::findOrFail($response->json('data.id'))));
    }

    /**
     * 생성 요청의 식별자가 guest 면(고유 규칙과 별개로) 범위 항목도 거부한다 — 방어.
     *
     * @scenario case=store_guest_identifier_with_scope
     *
     * @effects guest_scope_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_store_with_guest_identifier_and_scope_rejects_scope(): void
    {
        $response = $this->actingAs($this->superAdmin)->postJson('/api/admin/roles', [
            'identifier' => Role::GUEST_IDENTIFIER,
            'name' => ['ko' => '비회원', 'en' => 'Guest'],
            'permissions' => [['id' => $this->permissionId('core.users.read'), 'scope_type' => 'self']],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['permissions.0.scope_type']);
    }

    /**
     * 역할 상세 응답의 supports_permission_scope — 비회원만 false.
     *
     * @scenario case=resource_flag
     *
     * @effects supports_permission_scope_flag
     *
     * @return void 반환값 없음
     */
    public function test_show_exposes_supports_permission_scope(): void
    {
        $manager = Role::where('identifier', 'manager')->firstOrFail();

        $this->actingAs($this->superAdmin)->getJson("/api/admin/roles/{$this->guest->id}")
            ->assertStatus(200)->assertJsonPath('data.supports_permission_scope', false);
        $this->actingAs($this->superAdmin)->getJson("/api/admin/roles/{$manager->id}")
            ->assertStatus(200)->assertJsonPath('data.supports_permission_scope', true);
    }

    /**
     * 권한 트리의 각 노드는 범위 지원 여부(scope_supported)를 싣는다 — 메타가 둘 다 있을 때만 true.
     *
     * @scenario case=tree_flag
     *
     * @effects scope_supported_tree_flag
     *
     * @return void 반환값 없음
     */
    public function test_permission_tree_nodes_expose_scope_supported(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/admin/permissions');
        $response->assertStatus(200);

        $flags = [];
        $walk = function (array $nodes) use (&$walk, &$flags): void {
            foreach ($nodes as $node) {
                if (is_array($node) && isset($node['identifier'])) {
                    $flags[$node['identifier']] = $node['scope_supported'] ?? 'missing';
                    $walk($node['children'] ?? []);
                } elseif (is_array($node)) {
                    $walk($node);
                }
            }
        };
        $walk((array) $response->json('data'));

        $this->assertTrue($flags['core.users.read'] ?? null, 'resource_route_key·owner_key 가 있는 권한');
        $this->assertFalse($flags['core.notification-logs.read'] ?? null, '메타가 없는 권한');
    }
}
