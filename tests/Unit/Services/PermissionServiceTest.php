<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PermissionService 테스트
 *
 * PermissionService의 타입별 그룹화 기능을 검증합니다.
 */
class PermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
    }

    // ========================================================================
    // getPermissionTree() 타입별 그룹화 테스트
    // ========================================================================

    /**
     * getPermissionTree()가 올바른 구조를 반환하는지 확인
     */
    public function test_get_permission_tree_returns_correct_structure(): void
    {
        $result = $this->permissionService->getPermissionTree();

        // 필수 키 확인
        $this->assertArrayHasKey('permissions', $result);
        $this->assertArrayHasKey('types', $result);
        $this->assertArrayHasKey('default_type', $result);
    }

    /**
     * types 배열에 admin과 user가 포함되어 있는지 확인
     */
    public function test_get_permission_tree_includes_all_permission_types(): void
    {
        $result = $this->permissionService->getPermissionTree();

        $this->assertContains('admin', $result['types']);
        $this->assertContains('user', $result['types']);
    }

    /**
     * default_type이 admin인지 확인
     */
    public function test_get_permission_tree_default_type_is_admin(): void
    {
        $result = $this->permissionService->getPermissionTree();

        $this->assertEquals('admin', $result['default_type']);
    }

    /**
     * permissions 배열이 타입별로 그룹화되어 있는지 확인
     */
    public function test_get_permission_tree_groups_by_type(): void
    {
        $result = $this->permissionService->getPermissionTree();

        // 각 타입별 그룹 존재 확인
        $this->assertArrayHasKey('admin', $result['permissions']);
        $this->assertArrayHasKey('user', $result['permissions']);

        // 각 그룹에 label과 permissions 키 존재 확인
        $this->assertArrayHasKey('label', $result['permissions']['admin']);
        $this->assertArrayHasKey('permissions', $result['permissions']['admin']);
        $this->assertArrayHasKey('label', $result['permissions']['user']);
        $this->assertArrayHasKey('permissions', $result['permissions']['user']);
    }

    /**
     * 각 타입 그룹에 icon 필드가 포함되어 있는지 확인
     */
    public function test_get_permission_tree_includes_icon_for_each_type(): void
    {
        $result = $this->permissionService->getPermissionTree();

        $this->assertArrayHasKey('icon', $result['permissions']['admin']);
        $this->assertArrayHasKey('icon', $result['permissions']['user']);
        $this->assertEquals('cog', $result['permissions']['admin']['icon']);
        $this->assertEquals('user', $result['permissions']['user']['icon']);
    }

    /**
     * admin 타입 권한이 admin 그룹에 포함되는지 확인
     */
    public function test_admin_permissions_are_grouped_under_admin(): void
    {
        // admin 타입 루트 권한 생성
        Permission::create([
            'identifier' => 'test-module',
            'name' => ['ko' => '테스트 모듈', 'en' => 'Test Module'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
        ]);

        $result = $this->permissionService->getPermissionTree();

        // admin 그룹에 권한이 있는지 확인
        $adminPermissions = $result['permissions']['admin']['permissions'];
        $identifiers = array_column($adminPermissions, 'identifier');

        $this->assertContains('test-module', $identifiers);
    }

    /**
     * user 타입 권한이 user 그룹에 포함되는지 확인
     */
    public function test_user_permissions_are_grouped_under_user(): void
    {
        // user 타입 루트 권한 생성
        Permission::create([
            'identifier' => 'user-module',
            'name' => ['ko' => '사용자 모듈', 'en' => 'User Module'],
            'type' => PermissionType::User,
            'parent_id' => null,
        ]);

        $result = $this->permissionService->getPermissionTree();

        // user 그룹에 권한이 있는지 확인
        $userPermissions = $result['permissions']['user']['permissions'];
        $identifiers = array_column($userPermissions, 'identifier');

        $this->assertContains('user-module', $identifiers);
    }

    /**
     * 권한 노드에 필수 필드가 포함되어 있는지 확인
     */
    public function test_permission_node_has_required_fields(): void
    {
        // 권한 생성
        Permission::create([
            'identifier' => 'test-node',
            'name' => ['ko' => '테스트 노드', 'en' => 'Test Node'],
            'description' => ['ko' => '테스트 설명', 'en' => 'Test Description'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
        ]);

        $result = $this->permissionService->getPermissionTree();
        $adminPermissions = $result['permissions']['admin']['permissions'];

        // test-node 찾기
        $testNode = collect($adminPermissions)->firstWhere('identifier', 'test-node');

        $this->assertNotNull($testNode);
        $this->assertArrayHasKey('id', $testNode);
        $this->assertArrayHasKey('identifier', $testNode);
        $this->assertArrayHasKey('name', $testNode);
        $this->assertArrayHasKey('name_raw', $testNode);
        $this->assertArrayHasKey('description', $testNode);
        $this->assertArrayHasKey('description_raw', $testNode);
        $this->assertArrayHasKey('extension_type', $testNode);
        $this->assertArrayHasKey('type', $testNode);
        $this->assertArrayHasKey('is_assignable', $testNode);
        $this->assertArrayHasKey('children', $testNode);
        $this->assertArrayHasKey('leaf_count', $testNode);
    }

    /**
     * 계층 구조가 올바르게 포함되는지 확인
     */
    public function test_permission_tree_includes_children(): void
    {
        // 부모 권한 생성
        $parent = Permission::create([
            'identifier' => 'parent-module',
            'name' => ['ko' => '부모 모듈', 'en' => 'Parent Module'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
        ]);

        // 자식 권한 생성
        Permission::create([
            'identifier' => 'parent-module.child',
            'name' => ['ko' => '자식 권한', 'en' => 'Child Permission'],
            'type' => PermissionType::Admin,
            'parent_id' => $parent->id,
        ]);

        $result = $this->permissionService->getPermissionTree();
        $adminPermissions = $result['permissions']['admin']['permissions'];

        // 부모 노드 찾기
        $parentNode = collect($adminPermissions)->firstWhere('identifier', 'parent-module');

        $this->assertNotNull($parentNode);
        $this->assertNotEmpty($parentNode['children']);
        $this->assertEquals('parent-module.child', $parentNode['children'][0]['identifier']);
    }

    /**
     * 리프 노드 개수가 올바르게 계산되는지 확인
     */
    public function test_leaf_count_is_calculated_correctly(): void
    {
        // 부모 권한 생성
        $parent = Permission::create([
            'identifier' => 'count-test',
            'name' => ['ko' => '카운트 테스트', 'en' => 'Count Test'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
        ]);

        // 자식 권한 3개 생성 (리프 노드)
        for ($i = 1; $i <= 3; $i++) {
            Permission::create([
                'identifier' => "count-test.child{$i}",
                'name' => ['ko' => "자식 {$i}", 'en' => "Child {$i}"],
                'type' => PermissionType::Admin,
                'parent_id' => $parent->id,
            ]);
        }

        $result = $this->permissionService->getPermissionTree();
        $adminPermissions = $result['permissions']['admin']['permissions'];

        // 부모 노드 찾기
        $parentNode = collect($adminPermissions)->firstWhere('identifier', 'count-test');

        $this->assertEquals(3, $parentNode['leaf_count']);
    }

    /**
     * 코어 권한이 항상 먼저 정렬되는지 확인
     */
    public function test_core_permissions_are_sorted_first(): void
    {
        // 비코어 모듈 권한 먼저 생성
        Permission::create([
            'identifier' => 'zzz-module',
            'name' => ['ko' => 'ZZZ 모듈', 'en' => 'ZZZ Module'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
            'order' => 1,
        ]);

        // 코어 모듈 권한 생성
        Permission::create([
            'identifier' => 'core',
            'name' => ['ko' => '코어', 'en' => 'Core'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
            'parent_id' => null,
            'order' => 1,
        ]);

        $result = $this->permissionService->getPermissionTree();
        $adminPermissions = $result['permissions']['admin']['permissions'];

        // 첫 번째 권한이 코어인지 확인
        $this->assertEquals('core', $adminPermissions[0]['identifier']);
    }

    /**
     * 빈 권한 목록에서도 올바른 구조를 반환하는지 확인
     */
    public function test_get_permission_tree_with_no_permissions(): void
    {
        // 권한 없이 호출
        $result = $this->permissionService->getPermissionTree();

        $this->assertArrayHasKey('permissions', $result);
        $this->assertArrayHasKey('types', $result);
        $this->assertArrayHasKey('default_type', $result);

        // 빈 permissions 배열 확인
        $this->assertEmpty($result['permissions']['admin']['permissions']);
        $this->assertEmpty($result['permissions']['user']['permissions']);
    }

    /**
     * is_assignable이 자식이 없는 노드에서 true인지 확인
     */
    public function test_is_assignable_is_true_for_leaf_nodes(): void
    {
        // 리프 노드 (자식 없음)
        Permission::create([
            'identifier' => 'leaf-permission',
            'name' => ['ko' => '리프 권한', 'en' => 'Leaf Permission'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
        ]);

        $result = $this->permissionService->getPermissionTree();
        $adminPermissions = $result['permissions']['admin']['permissions'];

        $leafNode = collect($adminPermissions)->firstWhere('identifier', 'leaf-permission');

        $this->assertTrue($leafNode['is_assignable']);
    }

    /**
     * 부모가 admin 타입이고 자식이 user 타입일 때 user 그룹에 올바르게 표시되는지 확인
     */
    public function test_mixed_type_tree_user_children_appear_under_user_group(): void
    {
        // admin 타입 루트 생성
        $root = Permission::create([
            'identifier' => 'test-module',
            'name' => ['ko' => '테스트 모듈', 'en' => 'Test Module'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
        ]);

        // admin 타입 중간 노드 생성
        $middle = Permission::create([
            'identifier' => 'test-module.board',
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'type' => PermissionType::Admin,
            'parent_id' => $root->id,
        ]);

        // user 타입 리프 노드 생성
        Permission::create([
            'identifier' => 'test-module.board.read',
            'name' => ['ko' => '읽기', 'en' => 'Read'],
            'type' => PermissionType::User,
            'parent_id' => $middle->id,
        ]);

        Permission::create([
            'identifier' => 'test-module.board.write',
            'name' => ['ko' => '쓰기', 'en' => 'Write'],
            'type' => PermissionType::User,
            'parent_id' => $middle->id,
        ]);

        $result = $this->permissionService->getPermissionTree();

        // user 그룹에 부모 구조가 포함되어야 함
        $userPermissions = $result['permissions']['user']['permissions'];
        $this->assertNotEmpty($userPermissions, 'user 그룹에 권한이 있어야 합니다');

        // 루트 노드가 user 그룹에 포함
        $rootNode = collect($userPermissions)->firstWhere('identifier', 'test-module');
        $this->assertNotNull($rootNode, 'user 그룹에 루트 노드가 있어야 합니다');

        // 중간 노드 확인
        $this->assertNotEmpty($rootNode['children']);
        $middleNode = collect($rootNode['children'])->firstWhere('identifier', 'test-module.board');
        $this->assertNotNull($middleNode);

        // user 타입 리프 노드 확인
        $leafIdentifiers = array_column($middleNode['children'], 'identifier');
        $this->assertContains('test-module.board.read', $leafIdentifiers);
        $this->assertContains('test-module.board.write', $leafIdentifiers);

        // leaf_count가 user 타입 리프만 카운트
        $this->assertEquals(2, $rootNode['leaf_count']);
    }

    /**
     * 혼합 타입 트리에서 admin 그룹에 user 타입 리프가 포함되지 않는지 확인
     */
    public function test_mixed_type_tree_admin_group_excludes_user_leaves(): void
    {
        // admin 타입 루트 생성
        $root = Permission::create([
            'identifier' => 'mixed-module',
            'name' => ['ko' => '혼합 모듈', 'en' => 'Mixed Module'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
        ]);

        // admin 타입 리프 생성
        Permission::create([
            'identifier' => 'mixed-module.admin-leaf',
            'name' => ['ko' => '관리자 리프', 'en' => 'Admin Leaf'],
            'type' => PermissionType::Admin,
            'parent_id' => $root->id,
        ]);

        // user 타입 리프 생성
        Permission::create([
            'identifier' => 'mixed-module.user-leaf',
            'name' => ['ko' => '사용자 리프', 'en' => 'User Leaf'],
            'type' => PermissionType::User,
            'parent_id' => $root->id,
        ]);

        $result = $this->permissionService->getPermissionTree();

        // admin 그룹: admin 리프만 포함
        $adminPermissions = $result['permissions']['admin']['permissions'];
        $adminRoot = collect($adminPermissions)->firstWhere('identifier', 'mixed-module');
        $this->assertNotNull($adminRoot);
        $adminLeafIds = array_column($adminRoot['children'], 'identifier');
        $this->assertContains('mixed-module.admin-leaf', $adminLeafIds);
        $this->assertNotContains('mixed-module.user-leaf', $adminLeafIds);
        $this->assertEquals(1, $adminRoot['leaf_count']);

        // user 그룹: user 리프만 포함
        $userPermissions = $result['permissions']['user']['permissions'];
        $userRoot = collect($userPermissions)->firstWhere('identifier', 'mixed-module');
        $this->assertNotNull($userRoot);
        $userLeafIds = array_column($userRoot['children'], 'identifier');
        $this->assertContains('mixed-module.user-leaf', $userLeafIds);
        $this->assertNotContains('mixed-module.admin-leaf', $userLeafIds);
        $this->assertEquals(1, $userRoot['leaf_count']);
    }

    /**
     * 특정 타입의 리프가 없는 브랜치는 해당 타입 그룹에서 제외되는지 확인
     */
    public function test_branch_without_matching_type_is_excluded(): void
    {
        // admin 전용 브랜치
        $adminRoot = Permission::create([
            'identifier' => 'admin-only',
            'name' => ['ko' => '관리자 전용', 'en' => 'Admin Only'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
        ]);

        Permission::create([
            'identifier' => 'admin-only.manage',
            'name' => ['ko' => '관리', 'en' => 'Manage'],
            'type' => PermissionType::Admin,
            'parent_id' => $adminRoot->id,
        ]);

        $result = $this->permissionService->getPermissionTree();

        // user 그룹에 이 브랜치가 없어야 함
        $userPermissions = $result['permissions']['user']['permissions'];
        $userRoot = collect($userPermissions)->firstWhere('identifier', 'admin-only');
        $this->assertNull($userRoot, 'admin 전용 브랜치는 user 그룹에 없어야 합니다');

        // admin 그룹에는 존재
        $adminPermissions = $result['permissions']['admin']['permissions'];
        $adminRootNode = collect($adminPermissions)->firstWhere('identifier', 'admin-only');
        $this->assertNotNull($adminRootNode);
    }

    /**
     * is_assignable이 자식이 있는 노드에서 false인지 확인
     */
    public function test_is_assignable_is_false_for_parent_nodes(): void
    {
        // 부모 노드
        $parent = Permission::create([
            'identifier' => 'parent-permission',
            'name' => ['ko' => '부모 권한', 'en' => 'Parent Permission'],
            'type' => PermissionType::Admin,
            'parent_id' => null,
        ]);

        // 자식 노드
        Permission::create([
            'identifier' => 'parent-permission.child',
            'name' => ['ko' => '자식 권한', 'en' => 'Child Permission'],
            'type' => PermissionType::Admin,
            'parent_id' => $parent->id,
        ]);

        $result = $this->permissionService->getPermissionTree();
        $adminPermissions = $result['permissions']['admin']['permissions'];

        $parentNode = collect($adminPermissions)->firstWhere('identifier', 'parent-permission');

        $this->assertFalse($parentNode['is_assignable']);
    }
}
