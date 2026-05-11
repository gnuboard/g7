<?php

namespace Tests\Feature\Middleware;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private User $normalUser;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 역할 생성
        $this->adminRole = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '시스템 관리자', 'en' => 'System administrator'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        // 관리자 역할에 type=admin 권한을 하나 이상 attach.
        // User::isAdmin() 은 role identifier 가 'admin' 인지가 아니라,
        // 사용자의 role 중에 type='admin' 권한이 있는지로 판정한다.
        $adminPermission = Permission::create([
            'identifier' => 'core.admin.access',
            'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
            'description' => ['ko' => '관리자 페이지 접근 권한', 'en' => 'Admin page access permission'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin->value,
        ]);
        $this->adminRole->permissions()->attach($adminPermission->id);

        // 관리자 사용자 생성
        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach($this->adminRole->id);

        // 일반 사용자 생성
        $this->normalUser = User::factory()->create();

        // 테스트 라우트 등록
        Route::middleware(['api', 'auth:sanctum', 'admin'])->get('/api/test-admin-route', function () {
            return response()->json(['message' => 'Admin access granted']);
        });
    }

    /**
     * 관리자 사용자는 관리자 전용 라우트에 접근할 수 있어야 합니다.
     */
    public function test_admin_user_can_access_admin_route(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/test-admin-route');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Admin access granted']);
    }

    /**
     * 일반 사용자는 관리자 전용 라우트에 접근 시 403 응답을 받아야 합니다.
     */
    public function test_normal_user_receives_403_on_admin_route(): void
    {
        $response = $this->actingAs($this->normalUser)
            ->getJson('/api/test-admin-route');

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * 인증되지 않은 사용자는 관리자 전용 라우트에 접근 시 401 응답을 받아야 합니다.
     */
    public function test_unauthenticated_user_receives_401_on_admin_route(): void
    {
        $response = $this->getJson('/api/test-admin-route');

        $response->assertStatus(401);
    }

    /**
     * User 모델의 isAdmin() 메서드가 정상 동작해야 합니다.
     */
    public function test_is_admin_method_works_correctly(): void
    {
        $this->assertTrue($this->adminUser->isAdmin());
        $this->assertFalse($this->normalUser->isAdmin());
    }

    /**
     * User 모델의 hasRole() 메서드가 정상 동작해야 합니다.
     */
    public function test_has_role_method_works_correctly(): void
    {
        $this->assertTrue($this->adminUser->hasRole('admin'));
        $this->assertFalse($this->normalUser->hasRole('admin'));
    }

    /**
     * 실제 관리자 API 엔드포인트가 AdminMiddleware로 보호되어야 합니다.
     */
    public function test_actual_admin_api_is_protected(): void
    {
        // 관리자 사용자로 접근 - 성공
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/admin/auth/user');

        $response->assertStatus(200);

        // 일반 사용자로 접근 - 403
        $response = $this->actingAs($this->normalUser)
            ->getJson('/api/admin/auth/user');

        $response->assertStatus(403);
    }
}
