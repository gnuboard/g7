<?php

namespace Tests\Unit\Helpers;

use App\Enums\PermissionType;
use App\Enums\ScheduleType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionHelperScopeTest extends TestCase
{
    use RefreshDatabase;

    private Permission $permission;

    private User $scopeUser;

    private User $otherUser;

    private User $sameRoleUser;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        // static 캐시 초기화
        $this->clearPermissionCache();

        // 기본 권한 생성
        $this->permission = Permission::create([
            'identifier' => 'test.scope.read',
            'name' => ['ko' => '스코프 테스트 조회', 'en' => 'Scope Test Read'],
            'type' => PermissionType::Admin,
            'resource_route_key' => 'menu',
            'owner_key' => 'created_by',
        ]);

        // 사용자 생성
        $this->scopeUser = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->sameRoleUser = User::factory()->create();

        // 역할 생성 및 할당
        $this->role = Role::create([
            'identifier' => 'test_scope_role',
            'name' => ['ko' => '스코프 역할', 'en' => 'Scope Role'],
            'is_active' => true,
        ]);
        $this->role->permissions()->attach($this->permission->id, ['scope_type' => ScopeType::Self]);
        $this->scopeUser->roles()->attach($this->role->id);
        $this->sameRoleUser->roles()->attach($this->role->id);
    }

    protected function tearDown(): void
    {
        $this->clearPermissionCache();
        parent::tearDown();
    }

    // ========================================================================
    // applyPermissionScope — 코어: Menu (owner_key='created_by')
    // ========================================================================

    /**
     * Menu — scope=null → 전체 메뉴 조회 (쿼리 변경 없음)
     */
    public function test_apply_scope_null_returns_all_menus(): void
    {
        $this->setScopeType(null);

        $this->createMenu(['created_by' => $this->scopeUser->id]);
        $this->createMenu(['created_by' => $this->otherUser->id]);
        $this->createMenu(['created_by' => $this->sameRoleUser->id]);

        $query = Menu::query();
        PermissionHelper::applyPermissionScope($query, 'test.scope.read', $this->scopeUser);

        $this->assertSame(3, $query->count());
    }

    /**
     * Menu — scope=self → 자기가 만든 메뉴만 조회
     */
    public function test_apply_scope_self_filters_own_menus(): void
    {
        $this->createMenu(['created_by' => $this->scopeUser->id]);
        $this->createMenu(['created_by' => $this->otherUser->id]);

        $query = Menu::query();
        PermissionHelper::applyPermissionScope($query, 'test.scope.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    /**
     * Menu — scope=role → 동일 역할 사용자가 만든 메뉴만 조회
     */
    public function test_apply_scope_role_filters_same_role_menus(): void
    {
        $this->setScopeType(ScopeType::Role);

        $this->createMenu(['created_by' => $this->scopeUser->id]);
        $this->createMenu(['created_by' => $this->sameRoleUser->id]);
        $this->createMenu(['created_by' => $this->otherUser->id]);

        $query = Menu::query();
        PermissionHelper::applyPermissionScope($query, 'test.scope.read', $this->scopeUser);

        $this->assertSame(2, $query->count());
    }

    // ========================================================================
    // applyPermissionScope — 코어: User (owner_key='id')
    // ========================================================================

    /**
     * User — scope=null → 전체 사용자 조회
     */
    public function test_apply_scope_null_returns_all_users(): void
    {
        $userPerm = $this->createPermissionWithScope('test.users.read', 'user', 'id', null);

        $query = User::query();
        PermissionHelper::applyPermissionScope($query, 'test.users.read', $this->scopeUser);

        // setUp에서 3명 + 생성 안 해도 3명은 존재
        $this->assertSame(User::count(), $query->count());
    }

    /**
     * User — scope=self → 자기 자신만 조회 (WHERE id = user_id)
     */
    public function test_apply_scope_self_filters_own_user(): void
    {
        $this->createPermissionWithScope('test.users.read', 'user', 'id', ScopeType::Self);

        $query = User::query();
        PermissionHelper::applyPermissionScope($query, 'test.users.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
        $this->assertEquals($this->scopeUser->id, $query->first()->id);
    }

    /**
     * User — scope=role → 동일 역할 사용자만 조회
     */
    public function test_apply_scope_role_filters_same_role_users(): void
    {
        $this->createPermissionWithScope('test.users.read', 'user', 'id', ScopeType::Role);

        $query = User::query();
        PermissionHelper::applyPermissionScope($query, 'test.users.read', $this->scopeUser);

        // scopeUser + sameRoleUser = 2명
        $this->assertSame(2, $query->count());
        $userIds = $query->pluck('id')->toArray();
        $this->assertContains($this->scopeUser->id, $userIds);
        $this->assertContains($this->sameRoleUser->id, $userIds);
    }

    // ========================================================================
    // applyPermissionScope — 코어: Schedule (owner_key='created_by')
    // ========================================================================

    /**
     * Schedule — scope=null → 전체 스케줄 조회
     */
    public function test_apply_scope_null_returns_all_schedules(): void
    {
        $this->createPermissionWithScope('test.schedules.read', 'schedule', 'created_by', null);

        $this->createSchedule(['created_by' => $this->scopeUser->id]);
        $this->createSchedule(['created_by' => $this->otherUser->id]);

        $query = Schedule::query();
        PermissionHelper::applyPermissionScope($query, 'test.schedules.read', $this->scopeUser);

        $this->assertSame(2, $query->count());
    }

    /**
     * Schedule — scope=self → 자기가 만든 스케줄만 조회
     */
    public function test_apply_scope_self_filters_own_schedules(): void
    {
        $this->createPermissionWithScope('test.schedules.read', 'schedule', 'created_by', ScopeType::Self);

        $this->createSchedule(['created_by' => $this->scopeUser->id]);
        $this->createSchedule(['created_by' => $this->otherUser->id]);

        $query = Schedule::query();
        PermissionHelper::applyPermissionScope($query, 'test.schedules.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    /**
     * Schedule — scope=role → 동일 역할 사용자가 만든 스케줄만 조회
     */
    public function test_apply_scope_role_filters_same_role_schedules(): void
    {
        $this->createPermissionWithScope('test.schedules.read', 'schedule', 'created_by', ScopeType::Role);

        $this->createSchedule(['created_by' => $this->scopeUser->id]);
        $this->createSchedule(['created_by' => $this->sameRoleUser->id]);
        $this->createSchedule(['created_by' => $this->otherUser->id]);

        $query = Schedule::query();
        PermissionHelper::applyPermissionScope($query, 'test.schedules.read', $this->scopeUser);

        $this->assertSame(2, $query->count());
    }

    // ========================================================================
    // applyPermissionScope — owner_key=null 또는 비인증 사용자
    // ========================================================================

    /**
     * owner_key=null → 쿼리 변경 없음 (스코프 체크 스킵)
     */
    public function test_apply_scope_no_owner_key_does_not_modify_query(): void
    {
        Permission::create([
            'identifier' => 'test.system.read',
            'name' => ['ko' => '시스템 조회', 'en' => 'System Read'],
            'type' => PermissionType::Admin,
            'resource_route_key' => null,
            'owner_key' => null,
        ]);

        $this->createMenu(['created_by' => $this->scopeUser->id]);
        $this->createMenu(['created_by' => $this->otherUser->id]);

        $query = Menu::query();
        PermissionHelper::applyPermissionScope($query, 'test.system.read', $this->scopeUser);

        $this->assertSame(2, $query->count());
    }

    /**
     * 비인증 사용자 → 쿼리 변경 없음
     */
    public function test_apply_scope_unauthenticated_does_not_modify_query(): void
    {
        $this->createMenu(['created_by' => $this->scopeUser->id]);

        $query = Menu::query();
        PermissionHelper::applyPermissionScope($query, 'test.scope.read', null);

        $this->assertSame(1, $query->count());
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * 테스트용 Menu 생성 헬퍼
     *
     * @param  array  $overrides  오버라이드할 필드
     * @return Menu 생성된 메뉴
     */
    private function createMenu(array $overrides = []): Menu
    {
        return Menu::create(array_merge([
            'name' => ['ko' => '테스트 메뉴', 'en' => 'Test Menu'],
            'slug' => 'test-menu-'.uniqid(),
            'url' => '/admin/test',
            'icon' => 'fas fa-cog',
            'order' => 1,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * 테스트용 Schedule 생성 헬퍼
     *
     * @param  array  $overrides  오버라이드할 필드
     * @return Schedule 생성된 스케줄
     */
    private function createSchedule(array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'name' => 'Test Schedule '.uniqid(),
            'type' => ScheduleType::Artisan,
            'command' => 'test:command',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ], $overrides));
    }

    /**
     * 기본 permission의 scope_type 변경
     *
     * @param  ScopeType|null  $scopeType  scope_type 값
     * @return void
     */
    private function setScopeType(?ScopeType $scopeType): void
    {
        $this->role->permissions()->syncWithoutDetaching([
            $this->permission->id => ['scope_type' => $scopeType],
        ]);
        $this->clearPermissionCache();
    }

    /**
     * 별도 권한 생성 및 역할에 할당하는 헬퍼
     *
     * @param  string  $identifier  권한 식별자
     * @param  string|null  $routeKey  resource_route_key
     * @param  string|null  $ownerKey  owner_key
     * @param  ScopeType|null  $scopeType  scope_type
     * @return Permission 생성된 권한
     */
    private function createPermissionWithScope(string $identifier, ?string $routeKey, ?string $ownerKey, ?ScopeType $scopeType): Permission
    {
        $perm = Permission::create([
            'identifier' => $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'type' => PermissionType::Admin,
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);

        $this->role->permissions()->attach($perm->id, ['scope_type' => $scopeType]);
        $this->clearPermissionCache();

        return $perm;
    }

    /**
     * PermissionHelper static 캐시 초기화
     *
     * @return void
     */
    private function clearPermissionCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
