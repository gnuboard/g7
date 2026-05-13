<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Laravel Gate 시스템 및 통합 권한 체크 테스트
 *
 * 회원/비회원(guest role) 모두 권한 체크 가능한지 검증합니다.
 * - 인증된 사용자: Gate::allows() 사용
 * - 비회원: AuthServiceProvider::checkPermission() 사용 (Gate::before는 guest에 호출되지 않음)
 */
class GatePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected Role $adminRole;

    protected Role $userRole;

    protected Role $guestRole;

    protected User $adminUser;

    protected User $regularUser;

    protected Permission $viewPermission;

    protected Permission $editPermission;

    protected Permission $guestPermission;

    protected function setUp(): void
    {
        parent::setUp();

        // guest role 캐시 초기화
        AuthServiceProvider::clearGuestRoleCache();

        // 역할 생성
        $this->adminRole = Role::create([
            'identifier' => 'admin',
            'name' => [
                'ko' => '관리자',
                'en' => 'Administrator',
            ],
            'description' => [
                'ko' => '시스템 관리자',
                'en' => 'System Administrator',
            ],
        ]);

        $this->userRole = Role::create([
            'identifier' => 'user',
            'name' => [
                'ko' => '일반 사용자',
                'en' => 'Regular User',
            ],
            'description' => [
                'ko' => '일반 사용자',
                'en' => 'Regular User',
            ],
        ]);

        $this->guestRole = Role::create([
            'identifier' => 'guest',
            'name' => [
                'ko' => '비회원',
                'en' => 'Guest',
            ],
            'description' => [
                'ko' => '비회원 사용자',
                'en' => 'Guest User',
            ],
        ]);

        // 권한 생성
        $this->viewPermission = Permission::create([
            'identifier' => 'test.resource.view',
            'name' => [
                'ko' => '리소스 조회',
                'en' => 'View Resource',
            ],
            'description' => [
                'ko' => '리소스를 조회할 수 있습니다.',
                'en' => 'Can view resource.',
            ],
            'type' => 'admin',
        ]);

        $this->editPermission = Permission::create([
            'identifier' => 'test.resource.edit',
            'name' => [
                'ko' => '리소스 편집',
                'en' => 'Edit Resource',
            ],
            'description' => [
                'ko' => '리소스를 편집할 수 있습니다.',
                'en' => 'Can edit resource.',
            ],
            'type' => 'admin',
        ]);

        // 비회원용 권한 (guest role에만 부여)
        $this->guestPermission = Permission::create([
            'identifier' => 'test.public.access',
            'name' => [
                'ko' => '공개 접근',
                'en' => 'Public Access',
            ],
            'description' => [
                'ko' => '공개 리소스에 접근할 수 있습니다.',
                'en' => 'Can access public resource.',
            ],
            'type' => 'user',
        ]);

        // 역할에 권한 부여
        // adminRole에 view, edit 권한 부여 (바이패스 없이 명시 할당)
        $this->adminRole->permissions()->attach([
            $this->viewPermission->id,
            $this->editPermission->id,
            $this->guestPermission->id,
        ]);

        // userRole에 view 권한만 부여
        $this->userRole->permissions()->attach($this->viewPermission->id);

        // guestRole에 public access 권한 부여
        $this->guestRole->permissions()->attach($this->guestPermission->id);

        // 사용자 생성
        $this->adminUser = User::factory()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin User',
        ]);

        $this->regularUser = User::factory()->create([
            'email' => 'user@example.com',
            'name' => 'Regular User',
        ]);

        // 역할 할당
        $this->adminUser->roles()->attach($this->adminRole->id);
        $this->regularUser->roles()->attach($this->userRole->id);

        // guest role 캐시 초기화 (역할 생성 후)
        AuthServiceProvider::clearGuestRoleCache();
    }

    protected function tearDown(): void
    {
        // 테스트 종료 시 캐시 초기화
        AuthServiceProvider::clearGuestRoleCache();

        parent::tearDown();
    }

    /**
     * 관리자(admin role)는 명시적으로 할당된 권한만 통과합니다.
     */
    public function test_admin_user_passes_assigned_permissions(): void
    {
        $this->actingAs($this->adminUser);

        // admin role에 명시 할당된 권한은 통과
        $this->assertTrue(Gate::allows('test.resource.view'));
        $this->assertTrue(Gate::allows('test.resource.edit'));
        $this->assertTrue(Gate::allows('test.public.access'));

        // 할당되지 않은 임의 권한은 실패
        $this->assertFalse(Gate::allows('any.random.permission'));

        // checkPermission()도 동일하게 동작
        $this->assertTrue(AuthServiceProvider::checkPermission('test.resource.view'));
        $this->assertFalse(AuthServiceProvider::checkPermission('any.random.permission'));
    }

    /**
     * 회원이 권한이 있으면 Gate::allows()가 true를 반환합니다.
     */
    public function test_regular_user_with_permission_passes_gate_check(): void
    {
        $this->actingAs($this->regularUser);

        // regularUser는 userRole을 가지고 있고, userRole은 view 권한을 가짐
        $this->assertTrue(Gate::allows('test.resource.view'));

        // checkPermission()도 동일하게 동작
        $this->assertTrue(AuthServiceProvider::checkPermission('test.resource.view'));
    }

    /**
     * 회원이 권한이 없으면 Gate::allows()가 false를 반환합니다.
     */
    public function test_regular_user_without_permission_fails_gate_check(): void
    {
        $this->actingAs($this->regularUser);

        // regularUser는 edit 권한이 없음
        $this->assertFalse(Gate::allows('test.resource.edit'));
        $this->assertFalse(Gate::allows('nonexistent.permission'));

        // checkPermission()도 동일하게 동작
        $this->assertFalse(AuthServiceProvider::checkPermission('test.resource.edit'));
    }

    /**
     * 비회원(guest)이 guest role 권한이 있으면 checkPermission()이 true를 반환합니다.
     *
     * 참고: Laravel Gate::before()는 비회원에 대해 호출되지 않으므로
     * AuthServiceProvider::checkPermission()을 사용해야 합니다.
     */
    public function test_guest_with_permission_passes_check_permission(): void
    {
        // 비회원 상태 명시적 설정
        Auth::logout();

        // 디버깅: guest role과 권한 확인
        $guestRole = Role::where('identifier', 'guest')->with('permissions')->first();
        $this->assertNotNull($guestRole, 'Guest role should exist');
        $this->assertTrue(
            $guestRole->permissions->contains('identifier', 'test.public.access'),
            'Guest role should have test.public.access permission'
        );

        // checkPermission()을 사용하여 비회원 권한 체크
        $this->assertTrue(
            AuthServiceProvider::checkPermission('test.public.access'),
            'Guest should have test.public.access permission'
        );
    }

    /**
     * 비회원(guest)이 guest role 권한이 없으면 checkPermission()이 false를 반환합니다.
     */
    public function test_guest_without_permission_fails_check_permission(): void
    {
        // 비회원 상태 명시적 설정
        Auth::logout();

        // guest role에 부여되지 않은 권한
        $this->assertFalse(AuthServiceProvider::checkPermission('test.resource.view'));
        $this->assertFalse(AuthServiceProvider::checkPermission('test.resource.edit'));
        $this->assertFalse(AuthServiceProvider::checkPermission('nonexistent.permission'));
    }

    /**
     * guest role이 없는 경우 비회원은 모든 권한 체크에 실패합니다.
     */
    public function test_guest_fails_all_permissions_when_guest_role_does_not_exist(): void
    {
        // guest role 삭제
        $this->guestRole->delete();

        // 캐시 초기화
        AuthServiceProvider::clearGuestRoleCache();

        // 비회원 상태 명시적 설정
        Auth::logout();

        $this->assertFalse(AuthServiceProvider::checkPermission('test.public.access'));
        $this->assertFalse(AuthServiceProvider::checkPermission('test.resource.view'));
    }

    /**
     * Gate::check()로도 인증된 사용자의 권한을 체크할 수 있습니다.
     */
    public function test_gate_check_works_same_as_allows_for_authenticated_users(): void
    {
        $this->actingAs($this->regularUser);

        $this->assertTrue(Gate::check('test.resource.view'));
        $this->assertFalse(Gate::check('test.resource.edit'));
    }

    /**
     * User 모델의 can() 메서드로도 Gate 체크가 가능합니다.
     */
    public function test_user_can_method_uses_gate(): void
    {
        // 권한이 있는 경우
        $this->assertTrue($this->regularUser->can('test.resource.view'));

        // 권한이 없는 경우
        $this->assertFalse($this->regularUser->can('test.resource.edit'));

        // 관리자는 명시 할당된 권한만 통과
        $this->assertTrue($this->adminUser->can('test.resource.view'));
        $this->assertTrue($this->adminUser->can('test.resource.edit'));
        $this->assertFalse($this->adminUser->can('any.permission'));
    }

    /**
     * Gate::forUser()로 특정 사용자의 권한을 체크할 수 있습니다.
     */
    public function test_gate_for_user_checks_specific_user_permission(): void
    {
        // 특정 사용자의 권한 체크
        $this->assertTrue(Gate::forUser($this->adminUser)->allows('test.resource.view'));
        $this->assertFalse(Gate::forUser($this->adminUser)->allows('any.permission'));
        $this->assertTrue(Gate::forUser($this->regularUser)->allows('test.resource.view'));
        $this->assertFalse(Gate::forUser($this->regularUser)->allows('test.resource.edit'));
    }

    /**
     * 권한이 새로 추가되면 해당 역할에 연결된 사용자도 권한을 갖습니다.
     */
    public function test_newly_added_permission_is_reflected_immediately(): void
    {
        $this->actingAs($this->regularUser);

        // 새 권한 생성 및 부여
        $newPermission = Permission::create([
            'identifier' => 'test.new.permission',
            'name' => ['ko' => '새 권한', 'en' => 'New Permission'],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'type' => 'admin',
        ]);

        // 초기 상태에서는 권한 없음
        $this->assertFalse(Gate::allows('test.new.permission'));

        // 역할에 권한 부여
        $this->userRole->permissions()->attach($newPermission->id);

        // 이제 권한 있음
        $this->assertTrue(Gate::allows('test.new.permission'));
    }

    /**
     * 비회원에게 새 권한이 추가되면 캐시 클리어 후 즉시 반영됩니다.
     */
    public function test_guest_permission_is_reflected_after_cache_clear(): void
    {
        // 비회원 상태 명시적 설정
        Auth::logout();

        // 새 권한 생성
        $newPermission = Permission::create([
            'identifier' => 'test.guest.new',
            'name' => ['ko' => '새 비회원 권한', 'en' => 'New Guest Permission'],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'type' => 'user',
        ]);

        // 초기 상태에서는 권한 없음
        $this->assertFalse(AuthServiceProvider::checkPermission('test.guest.new'));

        // guest role에 권한 부여
        $this->guestRole->permissions()->attach($newPermission->id);

        // 캐시 초기화
        AuthServiceProvider::clearGuestRoleCache();

        // 이제 권한 있음
        $this->assertTrue(AuthServiceProvider::checkPermission('test.guest.new'));
    }

    /**
     * checkPermissions()로 여러 권한을 한 번에 체크할 수 있습니다. (AND 조건)
     */
    public function test_check_permissions_requires_all_permissions(): void
    {
        $this->actingAs($this->regularUser);

        // view 권한만 있음
        $this->assertTrue(AuthServiceProvider::checkPermissions(['test.resource.view']));
        $this->assertFalse(AuthServiceProvider::checkPermissions(['test.resource.view', 'test.resource.edit']));
    }

    /**
     * 관리자는 checkPermissions()에서 명시 할당된 권한만 통과합니다.
     */
    public function test_admin_passes_check_permissions(): void
    {
        $this->actingAs($this->adminUser);

        // 명시 할당된 권한은 통과
        $this->assertTrue(AuthServiceProvider::checkPermissions([
            'test.resource.view',
            'test.resource.edit',
        ]));

        // 할당되지 않은 권한이 포함되면 실패
        $this->assertFalse(AuthServiceProvider::checkPermissions([
            'test.resource.view',
            'any.random.permission',
        ]));
    }

    /**
     * 비회원도 checkPermissions()로 여러 권한을 체크할 수 있습니다.
     */
    public function test_guest_check_permissions(): void
    {
        Auth::logout();

        // guest role에 하나의 권한만 있음
        $this->assertTrue(AuthServiceProvider::checkPermissions(['test.public.access']));

        // 여러 권한 중 하나라도 없으면 실패
        $this->assertFalse(AuthServiceProvider::checkPermissions([
            'test.public.access',
            'test.resource.view',
        ]));
    }
}
