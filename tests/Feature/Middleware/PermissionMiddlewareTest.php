<?php

namespace Tests\Feature\Middleware;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Enums\ScheduleType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $userWithAdminPermission;

    private User $userWithUserPermission;

    private User $userWithoutPermission;

    private Permission $adminPermission;

    private Permission $userPermission;

    private Permission $additionalAdminPermission;

    private Role $adminRole;

    private Role $userRole;

    protected function setUp(): void
    {
        parent::setUp();

        // PermissionHelper static 캐시 초기화 (테스트 간 격리)
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        // PermissionMiddleware guest role 캐시 초기화
        $middlewareReflection = new \ReflectionClass(\App\Http\Middleware\PermissionMiddleware::class);
        $guestProp = $middlewareReflection->getProperty('guestRoleCache');
        $guestProp->setAccessible(true);
        $guestProp->setValue(null, null);

        // 테스트용 admin 권한 생성
        $this->adminPermission = Permission::create([
            'identifier' => 'test.permission',
            'name' => ['ko' => '테스트 관리자 권한', 'en' => 'Test Admin Permission'],
            'description' => ['ko' => '테스트용 관리자 권한', 'en' => 'Test admin permission'],
            'type' => PermissionType::Admin,
        ]);

        // 테스트용 user 권한 생성 (같은 identifier로 user 타입)
        $this->userPermission = Permission::create([
            'identifier' => 'test.user.permission',
            'name' => ['ko' => '테스트 사용자 권한', 'en' => 'Test User Permission'],
            'description' => ['ko' => '테스트용 사용자 권한', 'en' => 'Test user permission'],
            'type' => PermissionType::User,
        ]);

        // 테스트용 추가 admin 권한 생성
        $this->additionalAdminPermission = Permission::create([
            'identifier' => 'test.additional',
            'name' => ['ko' => '추가 관리자 권한', 'en' => 'Additional Admin Permission'],
            'description' => ['ko' => '추가 테스트용 관리자 권한', 'en' => 'Additional admin test permission'],
            'type' => PermissionType::Admin,
        ]);

        // 테스트용 admin 역할 생성
        $this->adminRole = Role::create([
            'identifier' => 'test_admin_role',
            'name' => ['ko' => '테스트 관리자 역할', 'en' => 'Test Admin Role'],
            'description' => ['ko' => '테스트용 관리자 역할', 'en' => 'Test admin role'],
            'is_active' => true,
        ]);

        // 테스트용 user 역할 생성
        $this->userRole = Role::create([
            'identifier' => 'test_user_role',
            'name' => ['ko' => '테스트 사용자 역할', 'en' => 'Test User Role'],
            'description' => ['ko' => '테스트용 사용자 역할', 'en' => 'Test user role'],
            'is_active' => true,
        ]);

        // 역할에 권한 할당
        $this->adminRole->permissions()->attach([$this->adminPermission->id, $this->additionalAdminPermission->id]);
        $this->userRole->permissions()->attach([$this->userPermission->id]);

        // admin 권한이 있는 사용자 생성
        $this->userWithAdminPermission = User::factory()->create();
        $this->userWithAdminPermission->roles()->attach($this->adminRole->id);

        // user 권한이 있는 사용자 생성
        $this->userWithUserPermission = User::factory()->create();
        $this->userWithUserPermission->roles()->attach($this->userRole->id);

        // 권한이 없는 사용자 생성
        $this->userWithoutPermission = User::factory()->create();

        // 테스트 라우트 등록 (새 형식: permission:type,identifier)
        Route::middleware(['api', 'permission:admin,test.permission'])->get('/api/test-admin-permission', function () {
            return response()->json(['message' => 'Access granted']);
        });

        Route::middleware(['api', 'permission:user,test.user.permission'])->get('/api/test-user-permission', function () {
            return response()->json(['message' => 'Access granted']);
        });

        Route::middleware(['api', 'permission:admin,test.permission|test.additional'])->get('/api/test-multiple-permissions', function () {
            return response()->json(['message' => 'Access granted']);
        });

        Route::middleware(['api', 'permission:admin,test.permission|test.additional,false'])->get('/api/test-or-permissions', function () {
            return response()->json(['message' => 'Access granted']);
        });
    }

    /**
     * admin 권한이 있는 사용자는 admin 권한 체크 라우트에 정상 접근할 수 있어야 합니다.
     */
    public function test_user_with_admin_permission_can_access_admin_route(): void
    {
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-admin-permission');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Access granted']);
    }

    /**
     * user 권한만 있는 사용자는 admin 권한 체크 라우트에서 403 응답을 받아야 합니다.
     * (같은 identifier라도 type이 다르면 접근 불가)
     */
    public function test_user_with_user_permission_cannot_access_admin_route(): void
    {
        $response = $this->actingAs($this->userWithUserPermission)
            ->getJson('/api/test-admin-permission');

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * user 권한이 있는 사용자는 user 권한 체크 라우트에 정상 접근할 수 있어야 합니다.
     */
    public function test_user_with_user_permission_can_access_user_route(): void
    {
        $response = $this->actingAs($this->userWithUserPermission)
            ->getJson('/api/test-user-permission');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Access granted']);
    }

    /**
     * admin 권한만 있는 사용자는 user 권한 체크 라우트에서 403 응답을 받아야 합니다.
     */
    public function test_user_with_admin_permission_cannot_access_user_route(): void
    {
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-user-permission');

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * 권한이 없는 사용자는 403 응답을 받아야 합니다.
     */
    public function test_user_without_permission_receives_403(): void
    {
        $response = $this->actingAs($this->userWithoutPermission)
            ->getJson('/api/test-admin-permission');

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * 인증되지 않은 사용자는 401 응답을 받아야 합니다.
     */
    public function test_unauthenticated_user_receives_401(): void
    {
        $response = $this->getJson('/api/test-admin-permission');

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * 유효하지 않은 권한 타입으로 접근 시 403 응답을 받아야 합니다.
     */
    public function test_invalid_permission_type_receives_403(): void
    {
        Route::middleware(['api', 'permission:invalid,test.permission'])->get('/api/test-invalid-type', function () {
            return response()->json(['message' => 'Access granted']);
        });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-invalid-type');

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * 다중 권한 (AND 로직) - 모든 권한을 가진 경우 접근 가능해야 합니다.
     */
    public function test_multiple_permissions_with_and_logic_grants_access(): void
    {
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-multiple-permissions');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Access granted']);
    }

    /**
     * 다중 권한 (AND 로직) - 하나라도 권한이 없으면 403 응답을 받아야 합니다.
     */
    public function test_multiple_permissions_with_and_logic_denies_without_all(): void
    {
        // 하나의 권한만 가진 역할 생성
        $partialRole = Role::create([
            'identifier' => 'partial_role',
            'name' => ['ko' => '일부 권한 역할', 'en' => 'Partial Role'],
            'description' => ['ko' => '일부 권한만 가진 역할', 'en' => 'Role with partial permissions'],
            'is_active' => true,
        ]);
        $partialRole->permissions()->attach($this->adminPermission->id);

        /** @var User $partialUser */
        $partialUser = User::factory()->create();
        $partialUser->roles()->attach($partialRole->id);

        $response = $this->actingAs($partialUser)
            ->getJson('/api/test-multiple-permissions');

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * 다중 권한 (OR 로직) - 하나의 권한만 있어도 접근 가능해야 합니다.
     */
    public function test_multiple_permissions_with_or_logic_grants_access(): void
    {
        // 하나의 권한만 가진 역할 생성
        $singlePermRole = Role::create([
            'identifier' => 'single_perm_role',
            'name' => ['ko' => '단일 권한 역할', 'en' => 'Single Permission Role'],
            'description' => ['ko' => '하나의 권한만 가진 역할', 'en' => 'Role with single permission'],
            'is_active' => true,
        ]);
        $singlePermRole->permissions()->attach($this->adminPermission->id);

        /** @var User $singlePermUser */
        $singlePermUser = User::factory()->create();
        $singlePermUser->roles()->attach($singlePermRole->id);

        $response = $this->actingAs($singlePermUser)
            ->getJson('/api/test-or-permissions');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Access granted']);
    }

    /**
     * 다중 권한 (OR 로직) - 어떤 권한도 없으면 403 응답을 받아야 합니다.
     */
    public function test_multiple_permissions_with_or_logic_denies_without_any(): void
    {
        $response = $this->actingAs($this->userWithoutPermission)
            ->getJson('/api/test-or-permissions');

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * 역할을 통한 권한 확인이 정상 동작해야 합니다 (type 포함).
     */
    public function test_permission_check_through_role_with_type(): void
    {
        // admin 권한 체크
        $this->assertTrue($this->userWithAdminPermission->hasPermission('test.permission', PermissionType::Admin));
        $this->assertFalse($this->userWithAdminPermission->hasPermission('test.permission', PermissionType::User));

        // user 권한 체크
        $this->assertTrue($this->userWithUserPermission->hasPermission('test.user.permission', PermissionType::User));
        $this->assertFalse($this->userWithUserPermission->hasPermission('test.user.permission', PermissionType::Admin));

        // type 없이 호출하면 어떤 타입이든 확인
        $this->assertTrue($this->userWithAdminPermission->hasPermission('test.permission'));
        $this->assertTrue($this->userWithUserPermission->hasPermission('test.user.permission'));
        $this->assertFalse($this->userWithoutPermission->hasPermission('test.permission'));
    }

    /**
     * 존재하지 않는 권한으로 접근 시 403 응답을 받아야 합니다.
     */
    public function test_nonexistent_permission_denies_access(): void
    {
        Route::middleware(['api', 'permission:admin,nonexistent.permission'])->get('/api/test-nonexistent', function () {
            return response()->json(['message' => 'Access granted']);
        });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-nonexistent');

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * hasPermissions 메서드가 type과 함께 정상 동작해야 합니다.
     */
    public function test_has_permissions_with_type(): void
    {
        // admin 권한 여러 개 체크 (AND)
        $this->assertTrue(
            $this->userWithAdminPermission->hasPermissions(
                ['test.permission', 'test.additional'],
                true,
                PermissionType::Admin
            )
        );

        // user 타입으로 체크하면 실패
        $this->assertFalse(
            $this->userWithAdminPermission->hasPermissions(
                ['test.permission', 'test.additional'],
                true,
                PermissionType::User
            )
        );

        // OR 로직으로 체크
        $this->assertTrue(
            $this->userWithAdminPermission->hasPermissions(
                ['test.permission', 'nonexistent.permission'],
                false,
                PermissionType::Admin
            )
        );
    }

    /**
     * Bearer 토큰을 통한 인증이 정상 동작해야 합니다.
     */
    public function test_authenticates_user_via_bearer_token(): void
    {
        // 라우트 등록 (optional.sanctum이 Bearer 토큰을 파싱하고 인증 처리)
        Route::middleware(['api', 'optional.sanctum', 'permission:user,test.user.permission'])->get('/api/test-bearer-auth', function () {
            return response()->json(['message' => 'Authenticated']);
        });

        // 토큰 생성
        $token = $this->userWithUserPermission->createToken('test-token')->plainTextToken;

        // Bearer 토큰으로 요청
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/test-bearer-auth');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Authenticated']);
    }

    /**
     * guest role에 권한이 부여된 경우 비회원도 접근할 수 있어야 합니다.
     */
    public function test_guest_with_permission_can_access(): void
    {
        // guest role 생성
        $guestRole = Role::create([
            'identifier' => 'guest',
            'name' => ['ko' => '비회원', 'en' => 'Guest'],
            'description' => ['ko' => '비회원 역할', 'en' => 'Guest role'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        // public 권한 생성
        $publicPermission = Permission::create([
            'identifier' => 'test.public',
            'name' => ['ko' => '공개 권한', 'en' => 'Public Permission'],
            'description' => ['ko' => '공개 권한', 'en' => 'Public permission'],
            'type' => PermissionType::User,
        ]);

        $guestRole->permissions()->attach($publicPermission->id);

        // 라우트 등록
        Route::middleware(['api', 'permission:user,test.public'])->get('/api/test-guest-access', function () {
            return response()->json(['message' => 'Public access']);
        });

        // 인증 없이 요청
        $response = $this->getJson('/api/test-guest-access');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Public access']);
    }

    /**
     * guest role에 권한이 없는 경우 비회원은 401 응답을 받아야 합니다.
     */
    public function test_guest_without_permission_receives_401(): void
    {
        // guest role 생성 (권한 없음)
        Role::create([
            'identifier' => 'guest',
            'name' => ['ko' => '비회원', 'en' => 'Guest'],
            'description' => ['ko' => '비회원 역할', 'en' => 'Guest role'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        // 라우트 등록
        Route::middleware(['api', 'permission:user,test.private'])->get('/api/test-guest-denied', function () {
            return response()->json(['message' => 'Access granted']);
        });

        // 인증 없이 요청
        $response = $this->getJson('/api/test-guest-denied');

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'message']);
    }

    // ========================================================================
    // 동적 파라미터 치환 테스트
    // ========================================================================

    /**
     * 동적 파라미터 치환이 정상 동작해야 합니다.
     */
    public function test_resolves_dynamic_permission_parameters(): void
    {
        // 동적 권한 생성
        $dynamicPermission = Permission::create([
            'identifier' => 'test-module.notice.posts.create',
            'name' => ['ko' => '공지 게시글 생성', 'en' => 'Create Notice Posts'],
            'description' => ['ko' => '공지 게시글 생성 권한', 'en' => 'Permission to create notice posts'],
            'type' => PermissionType::User,
        ]);

        $this->userRole->permissions()->attach($dynamicPermission->id);

        // 동적 라우트 등록 (optional.sanctum이 Bearer 토큰을 파싱하고 인증 처리)
        Route::middleware(['api', 'optional.sanctum', 'permission:user,test-module.{slug}.posts.create'])->post('/api/boards/{slug}/posts', function () {
            return response()->json(['message' => 'Post created']);
        });

        // 토큰 생성
        $token = $this->userWithUserPermission->createToken('test-token')->plainTextToken;

        // 동적 파라미터가 포함된 요청
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/boards/notice/posts', ['title' => 'Test']);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Post created']);
    }

    // ========================================================================
    // scope_type 기반 상세 접근 체크 — User (owner_key='id')
    // ========================================================================

    /**
     * scope=null — 타인 사용자 상세 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_null_allows_access_to_other_user(): void
    {
        // 권한에 resource_route_key, owner_key 설정
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        // scope_type=null (전체 접근) 설정
        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => null],
        ]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-scope-user/{user}', function (User $user) {
                return response()->json(['message' => 'OK', 'user_id' => $user->id]);
            });

        // 타인 사용자 접근 → 200 (전체 접근)
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-scope-user/'.$this->userWithoutPermission->uuid);

        $response->assertStatus(200);
    }

    /**
     * scope=self — 자기 자신 상세 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_user(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Self],
        ]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-scope-self-user/{user}', function (User $user) {
                return response()->json(['message' => 'OK']);
            });

        // 자기 자신 접근 → 200
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-scope-self-user/'.$this->userWithAdminPermission->uuid);

        $response->assertStatus(200);
    }

    /**
     * scope=self — 타인 사용자 상세 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_user(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Self],
        ]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-scope-self-deny/{user}', function (User $user) {
                return response()->json(['message' => 'OK']);
            });

        // 타인 사용자 접근 → 403
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-scope-self-deny/'.$this->userWithoutPermission->uuid);

        $response->assertStatus(403);
    }

    /**
     * scope=role — 동일 역할 사용자 상세 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_role_allows_access_to_same_role_user(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Role],
        ]);

        // 동일 역할 사용자 생성
        /** @var User $sameRoleUser */
        $sameRoleUser = User::factory()->create();
        $sameRoleUser->roles()->attach($this->adminRole->id);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-scope-role-user/{user}', function (User $user) {
                return response()->json(['message' => 'OK']);
            });

        // 동일 역할 사용자 접근 → 200
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-scope-role-user/'.$sameRoleUser->uuid);

        $response->assertStatus(200);
    }

    /**
     * scope=role — 다른 역할 사용자 상세 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_role_denies_access_to_different_role_user(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Role],
        ]);

        // 다른 역할 사용자 (역할 없는 사용자)
        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-scope-role-deny/{user}', function (User $user) {
                return response()->json(['message' => 'OK']);
            });

        // 역할이 다른 사용자 접근 → 403
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-scope-role-deny/'.$this->userWithoutPermission->uuid);

        $response->assertStatus(403);
    }

    // ========================================================================
    // scope_type 기반 상세 접근 체크 — Menu (owner_key='created_by')
    // ========================================================================

    /**
     * scope=null — 타인이 만든 메뉴 수정 시 200 응답이어야 합니다.
     */
    public function test_scope_null_allows_access_to_other_menu(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'menu',
            'owner_key' => 'created_by',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => null],
        ]);

        $menu = $this->createMenu(['created_by' => $this->userWithoutPermission->id]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->put('/api/test-scope-menu/{menu}', function (Menu $menu) {
                return response()->json(['message' => 'OK']);
            });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->putJson('/api/test-scope-menu/'.$menu->id);

        $response->assertStatus(200);
    }

    /**
     * scope=self — 자기가 만든 메뉴 수정 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_menu(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'menu',
            'owner_key' => 'created_by',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Self],
        ]);

        $menu = $this->createMenu(['created_by' => $this->userWithAdminPermission->id]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->put('/api/test-scope-self-menu/{menu}', function (Menu $menu) {
                return response()->json(['message' => 'OK']);
            });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->putJson('/api/test-scope-self-menu/'.$menu->id);

        $response->assertStatus(200);
    }

    /**
     * scope=self — 타인이 만든 메뉴 수정 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_menu(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'menu',
            'owner_key' => 'created_by',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Self],
        ]);

        $menu = $this->createMenu(['created_by' => $this->userWithoutPermission->id]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->put('/api/test-scope-self-menu-deny/{menu}', function (Menu $menu) {
                return response()->json(['message' => 'OK']);
            });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->putJson('/api/test-scope-self-menu-deny/'.$menu->id);

        $response->assertStatus(403);
    }

    /**
     * scope=role — 동일 역할 사용자가 만든 메뉴 수정 시 200 응답이어야 합니다.
     */
    public function test_scope_role_allows_access_to_same_role_menu(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'menu',
            'owner_key' => 'created_by',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Role],
        ]);

        /** @var User $sameRoleUser */
        $sameRoleUser = User::factory()->create();
        $sameRoleUser->roles()->attach($this->adminRole->id);

        $menu = $this->createMenu(['created_by' => $sameRoleUser->id]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->put('/api/test-scope-role-menu/{menu}', function (Menu $menu) {
                return response()->json(['message' => 'OK']);
            });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->putJson('/api/test-scope-role-menu/'.$menu->id);

        $response->assertStatus(200);
    }

    /**
     * scope=role — 다른 역할 사용자가 만든 메뉴 수정 시 403 응답이어야 합니다.
     */
    public function test_scope_role_denies_access_to_different_role_menu(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'menu',
            'owner_key' => 'created_by',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Role],
        ]);

        // 다른 역할 사용자가 만든 메뉴
        $menu = $this->createMenu(['created_by' => $this->userWithoutPermission->id]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->put('/api/test-scope-role-menu-deny/{menu}', function (Menu $menu) {
                return response()->json(['message' => 'OK']);
            });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->putJson('/api/test-scope-role-menu-deny/'.$menu->id);

        $response->assertStatus(403);
    }

    // ========================================================================
    // scope_type — resource_route_key=null 리소스 (스코프 체크 스킵)
    // ========================================================================

    /**
     * resource_route_key=null — scope 설정과 무관하게 권한만 있으면 200 응답이어야 합니다.
     */
    public function test_scope_skipped_when_no_resource_route_key(): void
    {
        // resource_route_key/owner_key가 null인 권한 (시스템 리소스)
        $this->adminPermission->update([
            'resource_route_key' => null,
            'owner_key' => null,
        ]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-scope-no-route-key', function () {
                return response()->json(['message' => 'OK']);
            });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-scope-no-route-key');

        $response->assertStatus(200);
    }

    // ========================================================================
    // scope_type — 목록 엔드포인트 (모델 바인딩 없음 → 스코프 체크 스킵)
    // ========================================================================

    /**
     * 목록 엔드포인트 — scope=self 설정이어도 모델 바인딩 없으면 200 응답이어야 합니다.
     */
    public function test_scope_skipped_for_list_endpoint(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Self],
        ]);

        // 모델 바인딩 없는 목록 엔드포인트
        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-scope-list', function () {
                return response()->json(['message' => 'List OK']);
            });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-scope-list');

        $response->assertStatus(200);
    }

    // ========================================================================
    // scope_type — union 정책 테스트 (복수 역할)
    // ========================================================================

    /**
     * union 정책 — 역할A(self) + 역할B(role) → role 적용 → 동일 역할 리소스 접근 200이어야 합니다.
     */
    public function test_union_policy_self_plus_role_uses_role(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        // 역할A에 scope_type='self' 설정
        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Self],
        ]);

        // 역할B 생성 후 scope_type='role' 설정
        $roleB = Role::create([
            'identifier' => 'test_role_b',
            'name' => ['ko' => '역할B', 'en' => 'Role B'],
            'description' => ['ko' => '역할B', 'en' => 'Role B'],
            'is_active' => true,
        ]);
        $roleB->permissions()->attach($this->adminPermission->id, ['scope_type' => ScopeType::Role]);

        // 사용자에 두 역할 모두 할당
        $this->userWithAdminPermission->roles()->attach($roleB->id);

        // 동일 역할 사용자 생성
        /** @var User $sameRoleUser */
        $sameRoleUser = User::factory()->create();
        $sameRoleUser->roles()->attach($this->adminRole->id);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-union-self-role/{user}', function (User $user) {
                return response()->json(['message' => 'OK']);
            });

        // union: self + role → role → 동일 역할 사용자 접근 200
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-union-self-role/'.$sameRoleUser->uuid);

        $response->assertStatus(200);
    }

    /**
     * union 정책 — 역할A(role) + 역할B(null) → null 적용 → 타인 리소스 접근 200이어야 합니다.
     */
    public function test_union_policy_role_plus_null_uses_null(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        // 역할A에 scope_type='role' 설정
        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Role],
        ]);

        // 역할B 생성 후 scope_type=null (전체) 설정
        $roleB = Role::create([
            'identifier' => 'test_role_b_null',
            'name' => ['ko' => '역할B', 'en' => 'Role B'],
            'description' => ['ko' => '역할B', 'en' => 'Role B'],
            'is_active' => true,
        ]);
        $roleB->permissions()->attach($this->adminPermission->id, ['scope_type' => null]);

        $this->userWithAdminPermission->roles()->attach($roleB->id);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-union-role-null/{user}', function (User $user) {
                return response()->json(['message' => 'OK']);
            });

        // union: role + null → null → 아무나 접근 200
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-union-role-null/'.$this->userWithoutPermission->uuid);

        $response->assertStatus(200);
    }

    /**
     * union 정책 — 역할A(self) + 역할B(self) → self 유지 → 타인 리소스 접근 403이어야 합니다.
     */
    public function test_union_policy_self_plus_self_remains_self(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Self],
        ]);

        // 역할B도 self
        $roleB = Role::create([
            'identifier' => 'test_role_b_self',
            'name' => ['ko' => '역할B', 'en' => 'Role B'],
            'description' => ['ko' => '역할B', 'en' => 'Role B'],
            'is_active' => true,
        ]);
        $roleB->permissions()->attach($this->adminPermission->id, ['scope_type' => ScopeType::Self]);

        $this->userWithAdminPermission->roles()->attach($roleB->id);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-union-self-self/{user}', function (User $user) {
                return response()->json(['message' => 'OK']);
            });

        // union: self + self → self → 타인 접근 403
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-union-self-self/'.$this->userWithoutPermission->uuid);

        $response->assertStatus(403);
    }

    /**
     * union 정책 — 역할A(self) + 역할B(null) → null 적용 → 타인 리소스 접근 200이어야 합니다.
     */
    public function test_union_policy_self_plus_null_uses_null(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Self],
        ]);

        $roleB = Role::create([
            'identifier' => 'test_role_b_null2',
            'name' => ['ko' => '역할B', 'en' => 'Role B'],
            'description' => ['ko' => '역할B', 'en' => 'Role B'],
            'is_active' => true,
        ]);
        $roleB->permissions()->attach($this->adminPermission->id, ['scope_type' => null]);

        $this->userWithAdminPermission->roles()->attach($roleB->id);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-union-self-null/{user}', function (User $user) {
                return response()->json(['message' => 'OK']);
            });

        // union: self + null → null → 200
        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-union-self-null/'.$this->userWithoutPermission->uuid);

        $response->assertStatus(200);
    }

    // ========================================================================
    // scope_type — 권한 미보유 시 scope 도달 전 403
    // ========================================================================

    /**
     * 권한 미보유 시 — scope 체크 도달 전 권한 체크에서 403이어야 합니다.
     */
    public function test_scope_not_reached_without_permission(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'user',
            'owner_key' => 'id',
        ]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-scope-no-perm/{user}', function (User $user) {
                return response()->json(['message' => 'OK']);
            });

        // 권한 없는 사용자 → 권한 체크에서 403 (scope 미도달)
        $response = $this->actingAs($this->userWithoutPermission)
            ->getJson('/api/test-scope-no-perm/'.$this->userWithoutPermission->uuid);

        $response->assertStatus(403);
    }

    // ========================================================================
    // scope_type 기반 상세 접근 체크 — Schedule (owner_key='created_by')
    // ========================================================================

    /**
     * scope=null — 타인이 만든 스케줄 수정 시 200 응답이어야 합니다.
     */
    public function test_scope_null_allows_access_to_other_schedule(): void
    {
        $this->setupScopePermission('schedule', 'created_by', null);
        $schedule = $this->createSchedule(['created_by' => $this->userWithoutPermission->id]);
        $path = $this->registerScopeRoute('schedule', 'null-schedule', Schedule::class);

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson("{$path}/{$schedule->id}");

        $response->assertStatus(200);
    }

    /**
     * scope=self — 자기가 만든 스케줄 수정 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_schedule(): void
    {
        $this->setupScopePermission('schedule', 'created_by', ScopeType::Self);
        $schedule = $this->createSchedule(['created_by' => $this->userWithAdminPermission->id]);
        $path = $this->registerScopeRoute('schedule', 'self-own-schedule', Schedule::class);

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson("{$path}/{$schedule->id}");

        $response->assertStatus(200);
    }

    /**
     * scope=self — 타인이 만든 스케줄 수정 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_schedule(): void
    {
        $this->setupScopePermission('schedule', 'created_by', ScopeType::Self);
        $schedule = $this->createSchedule(['created_by' => $this->userWithoutPermission->id]);
        $path = $this->registerScopeRoute('schedule', 'self-deny-schedule', Schedule::class);

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson("{$path}/{$schedule->id}");

        $response->assertStatus(403);
    }

    /**
     * scope=role — 동일 역할 사용자가 만든 스케줄 수정 시 200 응답이어야 합니다.
     */
    public function test_scope_role_allows_access_to_same_role_schedule(): void
    {
        $this->setupScopePermission('schedule', 'created_by', ScopeType::Role);

        /** @var User $sameRoleUser */
        $sameRoleUser = User::factory()->create();
        $sameRoleUser->roles()->attach($this->adminRole->id);

        $schedule = $this->createSchedule(['created_by' => $sameRoleUser->id]);
        $path = $this->registerScopeRoute('schedule', 'role-schedule', Schedule::class);

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson("{$path}/{$schedule->id}");

        $response->assertStatus(200);
    }

    /**
     * scope=role — 다른 역할 사용자가 만든 스케줄 수정 시 403 응답이어야 합니다.
     */
    public function test_scope_role_denies_access_to_different_role_schedule(): void
    {
        $this->setupScopePermission('schedule', 'created_by', ScopeType::Role);
        $schedule = $this->createSchedule(['created_by' => $this->userWithoutPermission->id]);
        $path = $this->registerScopeRoute('schedule', 'role-deny-schedule', Schedule::class);

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson("{$path}/{$schedule->id}");

        $response->assertStatus(403);
    }

    // ========================================================================
    // scope_type — 추가 목록 엔드포인트 테스트
    // ========================================================================

    /**
     * scope=role — 목록 엔드포인트에서 모델 바인딩 없으면 200 응답이어야 합니다.
     */
    public function test_scope_role_skipped_for_list_endpoint(): void
    {
        $this->adminPermission->update([
            'resource_route_key' => 'product',
            'owner_key' => 'created_by',
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => ScopeType::Role],
        ]);

        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get('/api/test-scope-role-list', function () {
                return response()->json(['message' => 'List OK']);
            });

        $response = $this->actingAs($this->userWithAdminPermission)
            ->getJson('/api/test-scope-role-list');

        $response->assertStatus(200);
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * 테스트용 Menu 생성 헬퍼 (factory의 module_id 컬럼 이슈 회피)
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
     * 테스트용 권한에 scope 설정 헬퍼
     *
     * @param  string  $routeKey  resource_route_key 값
     * @param  string  $ownerKey  owner_key 값
     * @param  ScopeType|null  $scopeType  scope_type 값
     * @return void
     */
    private function setupScopePermission(string $routeKey, string $ownerKey, ?ScopeType $scopeType): void
    {
        $this->adminPermission->update([
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);

        $this->adminRole->permissions()->syncWithoutDetaching([
            $this->adminPermission->id => ['scope_type' => $scopeType],
        ]);

        // static 캐시 초기화 (permission 변경 반영)
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * 모델 바인딩 포함 테스트 라우트 등록 헬퍼
     *
     * @param  string  $routeKey  라우트 파라미터명
     * @param  string  $suffix  라우트 경로 구분용 접미사
     * @param  string  $modelClass  바인딩할 모델 FQCN
     * @return string 등록된 라우트 경로 (파라미터 제외)
     */
    private function registerScopeRoute(string $routeKey, string $suffix, string $modelClass): string
    {
        // 명시적 라우트 모델 바인딩 (모듈 모델 클래스 해석 보장)
        Route::bind($routeKey, fn ($value) => $modelClass::findOrFail($value));

        $path = "/api/test-scope-{$suffix}";
        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.permission'])
            ->get("{$path}/{{$routeKey}}", fn (Model $model) => response()->json(['message' => 'OK']));

        return $path;
    }
}
