<?php

namespace Tests\Unit\Helpers;

use App\Enums\PermissionType;
use App\Helpers\PermissionHelper;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PermissionHelperTest extends TestCase
{
    use RefreshDatabase;

    private Permission $permission;
    private Permission $noScopePermission;
    private User $ownerUser;
    private User $otherUser;
    private User $sharedRoleUser;

    protected function setUp(): void
    {
        parent::setUp();

        // static 캐시 초기화
        $this->clearPermissionCache();

        // 스코프가 있는 권한 (메뉴 관리)
        $this->permission = Permission::create([
            'identifier' => 'test.menus.update',
            'name' => ['ko' => '메뉴 수정', 'en' => 'Update Menu'],
            'type' => PermissionType::Admin,
            'resource_route_key' => 'menu',
            'owner_key' => 'created_by',
        ]);

        // 스코프가 없는 권한 (시스템 리소스)
        $this->noScopePermission = Permission::create([
            'identifier' => 'test.settings.read',
            'name' => ['ko' => '설정 조회', 'en' => 'Read Settings'],
            'type' => PermissionType::Admin,
            'resource_route_key' => null,
            'owner_key' => null,
        ]);

        // 사용자 생성
        $this->ownerUser = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->sharedRoleUser = User::factory()->create();

        // 역할 생성 및 할당
        $role = Role::create([
            'identifier' => 'test_manager',
            'name' => ['ko' => '매니저', 'en' => 'Manager'],
            'is_active' => true,
        ]);
        $role->permissions()->attach($this->permission->id, ['scope_type' => 'self']);
        $role->permissions()->attach($this->noScopePermission->id, ['scope_type' => null]);
        $this->ownerUser->roles()->attach($role->id);
        $this->sharedRoleUser->roles()->attach($role->id);
    }

    protected function tearDown(): void
    {
        $this->clearPermissionCache();
        parent::tearDown();
    }

    // ========================================================================
    // checkScopeAccess 테스트
    // ========================================================================

    /**
     * scope=null, 타인 리소스 → true (전체 접근)
     */
    public function test_check_scope_access_null_scope_allows_all(): void
    {
        // null scope 역할 생성
        $role = Role::create([
            'identifier' => 'test_admin_full',
            'name' => ['ko' => '전체 접근', 'en' => 'Full Access'],
            'is_active' => true,
        ]);
        $role->permissions()->attach($this->permission->id, ['scope_type' => null]);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        $menu = $this->createMenu(['created_by' => $this->otherUser->id]);

        $this->assertTrue(PermissionHelper::checkScopeAccess($menu, 'test.menus.update', $user));
    }

    /**
     * scope='self', 자기 리소스 → true
     */
    public function test_check_scope_access_self_allows_own_resource(): void
    {
        $menu = $this->createMenu(['created_by' => $this->ownerUser->id]);

        $this->assertTrue(PermissionHelper::checkScopeAccess($menu, 'test.menus.update', $this->ownerUser));
    }

    /**
     * scope='self', 타인 리소스 → false
     */
    public function test_check_scope_access_self_denies_other_resource(): void
    {
        $menu = $this->createMenu(['created_by' => $this->otherUser->id]);

        $this->assertFalse(PermissionHelper::checkScopeAccess($menu, 'test.menus.update', $this->ownerUser));
    }

    /**
     * scope='role', 동일 역할 사용자의 리소스 → true
     */
    public function test_check_scope_access_role_allows_shared_role_resource(): void
    {
        // role scope 역할
        $role = Role::create([
            'identifier' => 'test_role_scope',
            'name' => ['ko' => '역할 스코프', 'en' => 'Role Scope'],
            'is_active' => true,
        ]);
        $role->permissions()->attach($this->permission->id, ['scope_type' => 'role']);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userA->roles()->attach($role->id);
        $userB->roles()->attach($role->id);

        $menu = $this->createMenu(['created_by' => $userB->id]);

        $this->assertTrue(PermissionHelper::checkScopeAccess($menu, 'test.menus.update', $userA));
    }

    /**
     * scope='role', 다른 역할 사용자의 리소스 → false
     */
    public function test_check_scope_access_role_denies_different_role_resource(): void
    {
        $role = Role::create([
            'identifier' => 'test_role_scope2',
            'name' => ['ko' => '역할 스코프2', 'en' => 'Role Scope2'],
            'is_active' => true,
        ]);
        $role->permissions()->attach($this->permission->id, ['scope_type' => 'role']);

        $userA = User::factory()->create();
        $userA->roles()->attach($role->id);

        // otherUser has no role
        $menu = $this->createMenu(['created_by' => $this->otherUser->id]);

        $this->assertFalse(PermissionHelper::checkScopeAccess($menu, 'test.menus.update', $userA));
    }

    /**
     * resource_route_key=null → true (스코프 체크 스킵)
     */
    public function test_check_scope_access_skips_when_no_resource_route_key(): void
    {
        $menu = $this->createMenu(['created_by' => $this->otherUser->id]);

        $this->assertTrue(PermissionHelper::checkScopeAccess($menu, 'test.settings.read', $this->ownerUser));
    }

    /**
     * 비인증 사용자 → true (스코프 체크 스킵)
     */
    public function test_check_scope_access_skips_for_unauthenticated_user(): void
    {
        $menu = $this->createMenu(['created_by' => $this->otherUser->id]);

        $this->assertTrue(PermissionHelper::checkScopeAccess($menu, 'test.menus.update', null));
    }

    // ========================================================================
    // applyPermissionScope 테스트
    // ========================================================================

    /**
     * scope=null → 쿼리 변경 없음
     */
    public function test_apply_scope_null_does_not_modify_query(): void
    {
        $role = Role::create([
            'identifier' => 'test_full_access',
            'name' => ['ko' => '전체', 'en' => 'Full'],
            'is_active' => true,
        ]);
        $role->permissions()->attach($this->permission->id, ['scope_type' => null]);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        // 메뉴 3개 생성
        $this->createMenu(['created_by' => $user->id]);
        $this->createMenu(['created_by' => $this->otherUser->id]);
        $this->createMenu(['created_by' => $this->ownerUser->id]);

        $query = Menu::query();
        PermissionHelper::applyPermissionScope($query, 'test.menus.update', $user);

        $this->assertSame(3, $query->count());
    }

    /**
     * scope='self' → WHERE owner_key = user_id 추가
     */
    public function test_apply_scope_self_filters_own_resources(): void
    {
        $this->createMenu(['created_by' => $this->ownerUser->id]);
        $this->createMenu(['created_by' => $this->otherUser->id]);

        $query = Menu::query();
        PermissionHelper::applyPermissionScope($query, 'test.menus.update', $this->ownerUser);

        $this->assertSame(1, $query->count());
    }

    /**
     * owner_key=null → 쿼리 변경 없음
     */
    public function test_apply_scope_no_owner_key_does_not_modify_query(): void
    {
        $this->createMenu(['created_by' => $this->ownerUser->id]);
        $this->createMenu(['created_by' => $this->otherUser->id]);

        $query = Menu::query();
        PermissionHelper::applyPermissionScope($query, 'test.settings.read', $this->ownerUser);

        $this->assertSame(2, $query->count());
    }

    /**
     * 비인증 사용자 → 쿼리 변경 없음
     */
    public function test_apply_scope_unauthenticated_does_not_modify_query(): void
    {
        $this->createMenu(['created_by' => $this->ownerUser->id]);

        $query = Menu::query();
        PermissionHelper::applyPermissionScope($query, 'test.menus.update', null);

        $this->assertSame(1, $query->count());
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    private function createMenu(array $overrides = []): Menu
    {
        return Menu::create(array_merge([
            'name' => ['ko' => '테스트 메뉴', 'en' => 'Test Menu'],
            'slug' => 'test-menu-' . uniqid(),
            'url' => '/admin/test',
            'icon' => 'fas fa-cog',
            'order' => 1,
            'is_active' => true,
        ], $overrides));
    }

    private function clearPermissionCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
