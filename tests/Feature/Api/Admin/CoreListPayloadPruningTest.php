<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 코어 목록 응답의 하위 컬렉션 프루닝 회귀 (#518 / 공개 #76)
 *
 * 목록 화면이 쓰지 않는 하위 컬렉션이 행마다 통째로 직렬화되던 문제를 고정한다. 두 패턴이
 * 원인이었다.
 *   1. Resource 는 `whenLoaded`/`relationLoaded` 로 방어하는데 Repository 가 목록 쿼리에서
 *      무조건 관계를 로드해 가드가 항상 참이 됨 (= 가짜 가드)
 *   2. 목록/상세 Resource 미분리
 *
 * 기능 축소가 아님을 함께 고정한다 — 목록에서 뺀 값은 단건 조회가 여전히 공급한다.
 */
class CoreListPayloadPruningTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'core.permissions.read',
            'core.permissions.create',
            'core.permissions.update',
            'core.menus.read',
        ]);
        $this->token = $this->adminUser->createToken('test-token')->plainTextToken;
    }

    /**
     * 필요한 권한을 가진 관리자를 만듭니다.
     *
     * @param  array<string>  $permissions  부여할 권한 식별자
     * @return User 생성된 사용자
     */
    private function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
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

    /** 인증 헤더가 붙은 요청 헬퍼 */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    // ==================== 역할 목록 (F) ====================

    /**
     * 역할 목록에는 권한 집합이 실리지 않는다.
     *
     * 목록 화면이 쓰는 행 필드는 이름·설명·사용자 수·활성 여부·확장 정보뿐이다. 권한을 로드하면
     * Resource 가 같은 집합을 `permissions`(계층 트리)·`permission_ids`·`permission_values`
     * 세 형태로 중복 직렬화한다. 권한 편집은 단건 조회가 공급한다.
     *
     * @scenario resource=role,endpoint=list,observation=response_payload
     *
     * @effects list_omits_role_permissions
     */
    public function test_role_list_omits_permission_payload(): void
    {
        $role = Role::create([
            'identifier' => 'list_prune_role',
            'name' => json_encode(['ko' => '목록 프루닝', 'en' => 'List Pruning']),
            'description' => json_encode(['ko' => '설명', 'en' => 'Description']),
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->limit(2)->pluck('id')->all());

        $response = $this->authRequest()->getJson('/api/admin/roles');

        $response->assertStatus(200);

        $rows = $response->json('data.data') ?? $response->json('data');

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            foreach (['permissions', 'permission_ids', 'permission_values'] as $heavy) {
                $this->assertArrayNotHasKey($heavy, $row, "역할 목록에 {$heavy} 가 실리면 안 된다");
            }

            // 뺀 값의 대체 경로: 권한 트리를 싣지 않아도 규모는 집계로 보인다.
            $this->assertArrayHasKey('permissions_count', $row, '권한 개수 집계가 목록에 실려야 한다');
            $this->assertIsInt($row['permissions_count']);
        }

        $target = collect($rows)->firstWhere('identifier', 'list_prune_role');

        $this->assertNotNull($target, '생성한 역할이 목록에 있어야 한다');
        $this->assertSame(
            $role->permissions()->count(),
            $target['permissions_count'],
            '집계 값이 실제 할당된 권한 수와 일치해야 한다',
        );
    }

    /**
     * 권한이 0건인 역할도 집계가 0 으로 실려야 한다.
     *
     * COUNT 는 0건에서 0 을 돌려주므로 값 검사(`!== null`)로는 "집계 안 함" 과 구분되지 않는다.
     * Resource 는 별칭 존재 여부로 판정하므로 0 이 그대로 실린다.
     *
     * @scenario resource=role,endpoint=list,observation=response_payload
     *
     * @effects list_omits_role_permissions
     */
    public function test_role_list_permission_count_is_zero_not_missing_when_role_has_none(): void
    {
        Role::create([
            'identifier' => 'list_prune_role_empty',
            'name' => json_encode(['ko' => '권한 없음', 'en' => 'No Permission']),
            'description' => json_encode(['ko' => '설명', 'en' => 'Description']),
            'is_active' => true,
        ]);

        $response = $this->authRequest()->getJson('/api/admin/roles');

        $response->assertStatus(200);

        $rows = $response->json('data.data') ?? $response->json('data');
        $target = collect($rows)->firstWhere('identifier', 'list_prune_role_empty');

        $this->assertNotNull($target, '생성한 역할이 목록에 있어야 한다');
        $this->assertArrayHasKey('permissions_count', $target);
        $this->assertSame(0, $target['permissions_count']);
    }

    /**
     * 역할 목록이 쓰는 표시 필드는 그대로 남는다 (기능 축소 아님).
     */
    public function test_role_list_keeps_display_fields(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/roles');

        $response->assertStatus(200);

        $row = ($response->json('data.data') ?? $response->json('data'))[0];

        foreach (['id', 'name', 'description', 'is_active', 'users_count'] as $field) {
            $this->assertArrayHasKey($field, $row, "목록 화면이 쓰는 {$field} 가 사라졌다");
        }
    }

    /**
     * 목록에서 뺀 권한 집합은 단건 조회가 여전히 공급한다.
     *
     * @scenario resource=role,endpoint=detail,observation=response_payload
     *
     * @effects detail_still_returns_full_payload
     */
    public function test_role_detail_still_provides_permissions(): void
    {
        $role = Role::create([
            'identifier' => 'detail_keeps_perms',
            'name' => json_encode(['ko' => '상세 유지', 'en' => 'Detail Keeps']),
            'description' => json_encode(['ko' => '설명', 'en' => 'Description']),
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->limit(2)->pluck('id')->all());

        $response = $this->authRequest()->getJson("/api/admin/roles/{$role->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('permission_ids', $response->json('data'));
        $this->assertArrayHasKey('permissions', $response->json('data'));
    }

    /**
     * 역할 수가 늘어도 권한 테이블 조회가 늘지 않는다 (행당 재조회 부재).
     *
     * 종전에는 행마다 계층 트리를 만들며 상위/카테고리 권한을 다시 조회했다.
     *
     * @effects query_count_does_not_scale_with_row_count
     */
    public function test_role_list_query_count_does_not_grow_with_rows(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $role = Role::create([
                'identifier' => 'perf_role_'.$i,
                'name' => json_encode(['ko' => '역할'.$i, 'en' => 'Role '.$i]),
                'description' => json_encode(['ko' => '설명', 'en' => 'Desc']),
                'is_active' => true,
            ]);
            $role->permissions()->sync(Permission::query()->limit(2)->pluck('id')->all());
        }

        // 워밍 (권한/설정 캐시)
        $this->authRequest()->getJson('/api/admin/roles?per_page=100')->assertOk();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->authRequest()->getJson('/api/admin/roles?per_page=100')->assertOk();
        $baseline = count(array_filter($queries, fn (string $sql) => $this->isRolePermissionEagerLoad($sql)));

        for ($i = 3; $i < 12; $i++) {
            $role = Role::create([
                'identifier' => 'perf_role_'.$i,
                'name' => json_encode(['ko' => '역할'.$i, 'en' => 'Role '.$i]),
                'description' => json_encode(['ko' => '설명', 'en' => 'Desc']),
                'is_active' => true,
            ]);
            $role->permissions()->sync(Permission::query()->limit(2)->pluck('id')->all());
        }

        $queries = [];
        $this->authRequest()->getJson('/api/admin/roles?per_page=100')->assertOk();
        $grown = count(array_filter($queries, fn (string $sql) => $this->isRolePermissionEagerLoad($sql)));

        $this->assertSame(
            $baseline,
            $grown,
            "역할 수에 비례해 권한 조회가 늘었다 (기준: {$baseline}, 증가 후: {$grown})"
        );
    }

    // ==================== 메뉴 목록 (M) ====================

    /**
     * 메뉴 목록의 하위 메뉴에는 역할 배지가 실리지 않는다.
     *
     * 상위 메뉴마다 하위 전체의 역할 피벗까지 직렬화되던 것을 걷어낸다. 대신 목록은 `roles` 키
     * 자체를 생략하고, 그 값은 단건 조회가 공급한다 (기능 축소가 아님을 같은 테스트에서 확인).
     *
     * @scenario resource=menu,endpoint=list,observation=response_payload
     *
     * @effects list_omits_menu_child_roles, menu_detail_provides_child_roles
     */
    public function test_menu_list_child_omits_roles_while_parent_keeps_them(): void
    {
        // 목록은 사용자가 접근 가능한 메뉴만 돌려주므로(accessibleBy), 테스트 사용자가 실제로
        // 가진 역할에 연결한다.
        $role = Role::where('identifier', 'admin')->firstOrFail();

        $parent = Menu::create([
            'name' => ['ko' => '부모 메뉴', 'en' => 'Parent Menu'],
            'slug' => 'parent-menu-prune',
            'url' => '/parent',
            'order' => 1,
            'is_active' => true,
        ]);
        $child = Menu::create([
            'name' => ['ko' => '자식 메뉴', 'en' => 'Child Menu'],
            'slug' => 'child-menu-prune',
            'url' => '/parent/child',
            'parent_id' => $parent->id,
            'order' => 1,
            'is_active' => true,
        ]);

        $parent->roles()->attach($role->id, ['permission_type' => 'read']);
        $child->roles()->attach($role->id, ['permission_type' => 'read']);

        $response = $this->authRequest()->getJson('/api/admin/menus?with_children=true');

        $response->assertStatus(200);

        $rows = $response->json('data.data') ?? $response->json('data');
        $parentRow = collect($rows)->firstWhere('slug', 'parent-menu-prune');

        $this->assertNotNull($parentRow, '부모 메뉴가 목록에 있어야 한다');

        // 상위 메뉴의 역할 배지는 유지 (화면이 쓴다)
        $this->assertNotEmpty($parentRow['roles'] ?? [], '상위 메뉴의 역할은 유지되어야 한다');

        // 하위 메뉴는 표시 정보만
        $childRow = collect($parentRow['children'] ?? [])->firstWhere('slug', 'child-menu-prune');
        $this->assertNotNull($childRow, '하위 메뉴는 계속 내려와야 한다 (개수 표시에 필요)');
        $this->assertArrayNotHasKey(
            'roles',
            $childRow,
            '하위 메뉴의 역할은 목록에서 빠져야 한다 — 빈 배열로 내려주면 "역할 제한 없음" 이라는 '
            .'사실이 아닌 단언이 되어 화면이 그대로 저장에 실어 보낸다',
        );

        // 프루닝한 값은 대체 경로가 있어야 한다 — 단건 조회가 역할을 돌려준다.
        $detail = $this->authRequest()->getJson("/api/admin/menus/{$child->id}");
        $detail->assertStatus(200);

        $detailRoles = $detail->json('data.roles');
        $this->assertIsArray($detailRoles, '단건 조회는 역할을 돌려줘야 한다 (지연 로드 경로)');
        $this->assertSame(
            [$role->id],
            collect($detailRoles)->pluck('id')->all(),
            '단건 조회의 역할이 실제 부여된 역할과 일치해야 한다',
        );
    }

    /**
     * 역할→권한 **관계 eager load** 쿼리인지 판정합니다.
     *
     * 권한 체크(`hasPermission`)도 같은 피벗 테이블을 참조하지만 그쪽은 `exists(...)` 서브쿼리이며,
     * 행마다 실행되는 그 N+1 은 별도 이슈(#519)가 소유한다. 이 테스트는 목록 쿼리가 권한 집합을
     * 다시 끌어오지 않는지만 본다.
     *
     * @param  string  $sql  실행된 SQL
     * @return bool 관계 eager load 여부
     */
    private function isRolePermissionEagerLoad(string $sql): bool
    {
        return str_contains($sql, 'role_permissions') && ! str_contains($sql, 'exists(');
    }
}
