<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use Modules\Sirsoft\Board\Services\BoardPermissionService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * BoardPermissionService 단위 테스트
 */
class BoardPermissionServiceTest extends ModuleTestCase
{
    private BoardPermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BoardPermissionService();
    }

    /**
     * 서비스 인스턴스화 테스트
     *
     * @return void
     */
    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(BoardPermissionService::class, $this->service);
    }

    /**
     * 모듈 identifier 상수 확인 (새로운 3단계 권한 구조)
     *
     * @return void
     */
    public function test_permission_prefix_constant_exists(): void
    {
        $reflection = new \ReflectionClass(BoardPermissionService::class);
        $constants = $reflection->getConstants();

        $this->assertArrayHasKey('MODULE_IDENTIFIER', $constants);
        $this->assertEquals('sirsoft-board', $constants['MODULE_IDENTIFIER']);
    }

    /**
     * 게시판 권한 생성 시 type 필드가 올바르게 설정되는지 테스트
     *
     * @return void
     */
    public function test_board_permissions_have_correct_type(): void
    {
        $board = \Modules\Sirsoft\Board\Models\Board::factory()->create([
            'slug' => 'test-board',
            'name' => ['ko' => '테스트 게시판', 'en' => 'Test Board'],
        ]);

        $this->service->ensureBoardPermissions($board);

        // 모듈 루트 권한 확인
        $moduleRoot = Permission::where('identifier', 'sirsoft-board')->first();
        $this->assertNotNull($moduleRoot);
        $this->assertEquals(\App\Enums\PermissionType::Admin, $moduleRoot->type);

        // 게시판 카테고리 권한 확인
        $categoryPermission = Permission::where('identifier', 'sirsoft-board.test-board')->first();
        $this->assertNotNull($categoryPermission);
        $this->assertEquals(\App\Enums\PermissionType::Admin, $categoryPermission->type);

        // admin.* 권한은 Admin 타입이어야 함
        $adminPermissions = Permission::where('identifier', 'like', 'sirsoft-board.test-board.admin.%')->get();
        foreach ($adminPermissions as $permission) {
            $this->assertEquals(\App\Enums\PermissionType::Admin, $permission->type,
                "Permission {$permission->identifier} should be Admin type");
        }

        // admin.*가 아닌 권한은 User 타입이어야 함
        $userPermissions = Permission::where('identifier', 'like', 'sirsoft-board.test-board.%')
            ->where('identifier', 'not like', 'sirsoft-board.test-board.admin.%')
            ->where('identifier', '!=', 'sirsoft-board.test-board')
            ->get();

        foreach ($userPermissions as $permission) {
            $this->assertEquals(\App\Enums\PermissionType::User, $permission->type,
                "Permission {$permission->identifier} should be User type");
        }
    }

    // -------------------------------------------------------------------------
    // syncModulePermissionRoles diff 방식 테스트 (4개)
    // -------------------------------------------------------------------------

    /**
     * 변경 없음: granted_at 보존 확인
     *
     * @return void
     */
    public function test_sync_module_permission_roles_no_change_preserves_granted_at(): void
    {
        $permission = Permission::create([
            'identifier' => 'sirsoft-board.reports.view.sync1',
            'name' => ['ko' => '신고 조회 sync1', 'en' => 'View Reports sync1'],
            'type' => \App\Enums\PermissionType::Admin,
        ]);

        $adminRole = Role::where('identifier', 'admin')->first();
        $grantedAt = now()->subDay()->startOfSecond();
        $permission->roles()->attach($adminRole->id, [
            'granted_at' => $grantedAt,
            'granted_by' => null,
        ]);

        // 동일한 역할로 sync (변경 없음)
        $this->service->syncModulePermissionRoles([
            'sirsoft-board.reports.view.sync1' => ['admin'],
        ]);

        $pivot = $permission->roles()->where('roles.id', $adminRole->id)->first()->pivot;
        $this->assertEquals(
            $grantedAt->format('Y-m-d H:i:s'),
            $pivot->granted_at,
            'granted_at should be preserved when no change'
        );
    }

    /**
     * 역할 추가: 추가분만 attach, 기존 granted_at 보존
     *
     * @return void
     */
    public function test_sync_module_permission_roles_adds_only_new_roles(): void
    {
        $permission = Permission::create([
            'identifier' => 'sirsoft-board.reports.view.sync2',
            'name' => ['ko' => '신고 조회 sync2', 'en' => 'View Reports sync2'],
            'type' => \App\Enums\PermissionType::Admin,
        ]);

        $adminRole = Role::where('identifier', 'admin')->first();
        $userRole = Role::where('identifier', 'user')->first();

        $grantedAt = now()->subDay()->startOfSecond();
        $permission->roles()->attach($adminRole->id, [
            'granted_at' => $grantedAt,
            'granted_by' => null,
        ]);

        // admin 유지 + user 추가
        $this->service->syncModulePermissionRoles([
            'sirsoft-board.reports.view.sync2' => ['admin', 'user'],
        ]);

        $roleIds = $permission->roles()->pluck('roles.id')->toArray();
        $this->assertContains($adminRole->id, $roleIds, 'admin role should remain');
        $this->assertContains($userRole->id, $roleIds, 'user role should be added');

        // 기존 admin의 granted_at 보존 확인
        $adminPivot = $permission->roles()->where('roles.id', $adminRole->id)->first()->pivot;
        $this->assertEquals(
            $grantedAt->format('Y-m-d H:i:s'),
            $adminPivot->granted_at,
            'existing role granted_at should be preserved'
        );
    }

    /**
     * 역할 제거: 제거분만 detach, 나머지 보존
     *
     * @return void
     */
    public function test_sync_module_permission_roles_removes_only_dropped_roles(): void
    {
        $permission = Permission::create([
            'identifier' => 'sirsoft-board.reports.view.sync3',
            'name' => ['ko' => '신고 조회 sync3', 'en' => 'View Reports sync3'],
            'type' => \App\Enums\PermissionType::Admin,
        ]);

        $adminRole = Role::where('identifier', 'admin')->first();
        $userRole = Role::where('identifier', 'user')->first();

        $grantedAt = now()->subDay()->startOfSecond();
        $permission->roles()->attach($adminRole->id, ['granted_at' => $grantedAt, 'granted_by' => null]);
        $permission->roles()->attach($userRole->id, ['granted_at' => $grantedAt, 'granted_by' => null]);

        // user 제거, admin 유지
        $this->service->syncModulePermissionRoles([
            'sirsoft-board.reports.view.sync3' => ['admin'],
        ]);

        $roleIds = $permission->roles()->pluck('roles.id')->toArray();
        $this->assertContains($adminRole->id, $roleIds, 'admin role should remain');
        $this->assertNotContains($userRole->id, $roleIds, 'user role should be removed');

        $adminPivot = $permission->roles()->where('roles.id', $adminRole->id)->first()->pivot;
        $this->assertEquals($grantedAt->format('Y-m-d H:i:s'), $adminPivot->granted_at);
    }

    /**
     * 전체 교체: 기존 전체 detach + 신규 attach
     *
     * @return void
     */
    public function test_sync_module_permission_roles_full_replacement(): void
    {
        $permission = Permission::create([
            'identifier' => 'sirsoft-board.reports.view.sync4',
            'name' => ['ko' => '신고 조회 sync4', 'en' => 'View Reports sync4'],
            'type' => \App\Enums\PermissionType::Admin,
        ]);

        $adminRole = Role::where('identifier', 'admin')->first();
        $userRole = Role::where('identifier', 'user')->first();

        $permission->roles()->attach($adminRole->id, ['granted_at' => now()->subDay(), 'granted_by' => null]);

        // admin → user로 완전 교체
        $this->service->syncModulePermissionRoles([
            'sirsoft-board.reports.view.sync4' => ['user'],
        ]);

        $roleIds = $permission->roles()->pluck('roles.id')->toArray();
        $this->assertNotContains($adminRole->id, $roleIds, 'admin role should be removed');
        $this->assertContains($userRole->id, $roleIds, 'user role should be added');
    }

    // -------------------------------------------------------------------------
    // assignRolesToPermission diff 방식 테스트 (4개) — reflection으로 호출
    // -------------------------------------------------------------------------

    /**
     * assignRolesToPermission 호출 헬퍼 (private 메서드 접근)
     *
     * @param Permission $permission
     * @param string $key
     * @param \Modules\Sirsoft\Board\Models\Board $board
     * @param array $permissions
     * @param array $defaultPermissions
     * @return void
     */
    private function callAssignRolesToPermission(
        Permission $permission,
        string $key,
        \Modules\Sirsoft\Board\Models\Board $board,
        array $permissions,
        array $defaultPermissions
    ): void {
        $method = new \ReflectionMethod(BoardPermissionService::class, 'assignRolesToPermission');
        $method->setAccessible(true);
        $method->invoke($this->service, $permission, $key, $board, $permissions, $defaultPermissions);
    }

    /**
     * 변경 없음: granted_at 보존
     *
     * @return void
     */
    public function test_assign_roles_no_change_preserves_granted_at(): void
    {
        $board = \Modules\Sirsoft\Board\Models\Board::factory()->create([
            'slug' => 'diff-test',
            'name' => ['ko' => 'diff 테스트', 'en' => 'Diff Test'],
        ]);

        $permission = Permission::create([
            'identifier' => 'sirsoft-board.diff-test.posts.list',
            'name' => ['ko' => '목록', 'en' => 'List'],
            'type' => \App\Enums\PermissionType::User,
        ]);

        $adminRole = Role::where('identifier', 'admin')->first();
        $grantedAt = now()->subDay()->startOfSecond();
        $permission->roles()->attach($adminRole->id, ['granted_at' => $grantedAt, 'granted_by' => null]);

        // 동일 역할로 재호출 (변경 없음)
        $this->callAssignRolesToPermission(
            $permission,
            'posts.list',
            $board,
            ['posts_list' => ['roles' => ['admin']]],
            []
        );

        $pivot = $permission->roles()->where('roles.id', $adminRole->id)->first()->pivot;
        $this->assertEquals(
            $grantedAt->format('Y-m-d H:i:s'),
            $pivot->granted_at,
            'granted_at should be preserved when no change'
        );
    }

    /**
     * 역할 추가: 추가분만 attach, 기존 granted_at 보존
     *
     * @return void
     */
    public function test_assign_roles_adds_only_new_roles(): void
    {
        $board = \Modules\Sirsoft\Board\Models\Board::factory()->create([
            'slug' => 'diff-test2',
            'name' => ['ko' => 'diff 테스트2', 'en' => 'Diff Test2'],
        ]);

        $permission = Permission::create([
            'identifier' => 'sirsoft-board.diff-test2.posts.list',
            'name' => ['ko' => '목록', 'en' => 'List'],
            'type' => \App\Enums\PermissionType::User,
        ]);

        $adminRole = Role::where('identifier', 'admin')->first();
        $userRole = Role::where('identifier', 'user')->first();

        $grantedAt = now()->subDay()->startOfSecond();
        $permission->roles()->attach($adminRole->id, ['granted_at' => $grantedAt, 'granted_by' => null]);

        // admin 유지 + user 추가
        $this->callAssignRolesToPermission(
            $permission,
            'posts.list',
            $board,
            ['posts_list' => ['roles' => ['admin', 'user']]],
            []
        );

        $roleIds = $permission->roles()->pluck('roles.id')->toArray();
        $this->assertContains($adminRole->id, $roleIds);
        $this->assertContains($userRole->id, $roleIds);

        $adminPivot = $permission->roles()->where('roles.id', $adminRole->id)->first()->pivot;
        $this->assertEquals($grantedAt->format('Y-m-d H:i:s'), $adminPivot->granted_at);
    }

    /**
     * 역할 제거: 제거분만 detach
     *
     * @return void
     */
    public function test_assign_roles_removes_only_dropped_roles(): void
    {
        $board = \Modules\Sirsoft\Board\Models\Board::factory()->create([
            'slug' => 'diff-test3',
            'name' => ['ko' => 'diff 테스트3', 'en' => 'Diff Test3'],
        ]);

        $permission = Permission::create([
            'identifier' => 'sirsoft-board.diff-test3.posts.list',
            'name' => ['ko' => '목록', 'en' => 'List'],
            'type' => \App\Enums\PermissionType::User,
        ]);

        $adminRole = Role::where('identifier', 'admin')->first();
        $userRole = Role::where('identifier', 'user')->first();

        $permission->roles()->attach($adminRole->id, ['granted_at' => now()->subDay(), 'granted_by' => null]);
        $permission->roles()->attach($userRole->id, ['granted_at' => now()->subDay(), 'granted_by' => null]);

        // user 제거, admin 유지
        $this->callAssignRolesToPermission(
            $permission,
            'posts.list',
            $board,
            ['posts_list' => ['roles' => ['admin']]],
            []
        );

        $roleIds = $permission->roles()->pluck('roles.id')->toArray();
        $this->assertContains($adminRole->id, $roleIds);
        $this->assertNotContains($userRole->id, $roleIds);
    }

    /**
     * defaultPermissions 폴백: permissionData 없을 때 기본값 사용
     *
     * @return void
     */
    public function test_assign_roles_falls_back_to_default_permissions(): void
    {
        $board = \Modules\Sirsoft\Board\Models\Board::factory()->create([
            'slug' => 'diff-test4',
            'name' => ['ko' => 'diff 테스트4', 'en' => 'Diff Test4'],
        ]);

        $permission = Permission::create([
            'identifier' => 'sirsoft-board.diff-test4.posts.list',
            'name' => ['ko' => '목록', 'en' => 'List'],
            'type' => \App\Enums\PermissionType::User,
        ]);

        $adminRole = Role::where('identifier', 'admin')->first();
        $userRole = Role::where('identifier', 'user')->first();

        // permissions 배열 비어있음 → defaultPermissions 폴백
        $this->callAssignRolesToPermission(
            $permission,
            'posts.list',
            $board,
            [],
            ['posts.list' => ['admin', 'user']]
        );

        $roleIds = $permission->roles()->pluck('roles.id')->toArray();
        $this->assertContains($adminRole->id, $roleIds, 'default admin role should be assigned');
        $this->assertContains($userRole->id, $roleIds, 'default user role should be assigned');
    }
}
