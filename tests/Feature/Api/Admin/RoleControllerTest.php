<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AdminRoleController 테스트
 *
 * 역할 관리 API 엔드포인트를 테스트합니다.
 */
class RoleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 생성 및 할당
     *
     * @param array $permissions 사용자에게 부여할 권한 식별자 목록
     */
    private function createAdminUser(array $permissions = ['core.permissions.read', 'core.permissions.create', 'core.permissions.update', 'core.permissions.delete', 'core.users.read']): User
    {
        $user = User::factory()->create();

        // 권한 생성
        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier.' Permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        // 고유한 식별자로 역할 생성 (테스트별 격리를 위해)
        $roleIdentifier = 'admin_test_'.uniqid();
        $testRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        // admin 역할도 추가 (admin 미들웨어 통과용)
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        // 테스트용 역할에 권한 할당
        $testRole->permissions()->sync($permissionIds);

        // 사용자에게 admin 역할과 테스트용 역할 모두 할당
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($testRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼 메서드
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 테스트용 역할 생성 헬퍼
     */
    private function createTestRole(array $attributes = []): Role
    {
        return Role::create(array_merge([
            'identifier' => 'test_role_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 역할', 'en' => 'Test Role']),
            'description' => json_encode(['ko' => '테스트 역할 설명', 'en' => 'Test Role Description']),
            'is_active' => true,
        ], $attributes));
    }

    /**
     * 테스트용 권한 생성 헬퍼
     */
    private function createTestPermission(string $identifier = null): Permission
    {
        return Permission::create([
            'identifier' => $identifier ?? 'test.permission.'.uniqid(),
            'name' => json_encode(['ko' => '테스트 권한', 'en' => 'Test Permission']),
            'description' => json_encode(['ko' => '테스트 권한 설명', 'en' => 'Test Permission Description']),
            'type' => 'admin',
        ]);
    }

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    /**
     * 인증 없이 역할 목록 조회 시 401 반환
     */
    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/roles');

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 역할 목록 조회 시 403 반환
     */
    public function test_index_returns_403_without_permission(): void
    {
        // 권한 없는 관리자 생성
        $user = User::factory()->create();
        $adminRole = Role::where('identifier', 'admin')->first();

        // 기존 권한 분리
        $readPermission = Permission::where('identifier', 'core.permissions.read')->first();
        if ($adminRole && $readPermission) {
            $adminRole->permissions()->detach($readPermission->id);
        }

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/roles');

        $response->assertStatus(403);
    }

    /**
     * 생성 권한 없이 역할 생성 시 403 반환
     */
    public function test_store_returns_403_without_create_permission(): void
    {
        // read 권한만 있는 관리자 생성
        $user = $this->createAdminUser(['core.permissions.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/roles', [
            'name' => ['ko' => '새 역할', 'en' => 'New Role'],
        ]);

        $response->assertStatus(403);
    }

    // ========================================================================
    // 역할 목록 테스트 (index)
    // ========================================================================

    /**
     * 페이지네이션된 역할 목록 조회 성공
     */
    public function test_index_returns_paginated_roles(): void
    {
        // 테스트 역할 생성
        $this->createTestRole();
        $this->createTestRole();

        $response = $this->authRequest()->getJson('/api/admin/roles');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'abilities',
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                    ],
                ],
            ]);
    }

    /**
     * 검색 필터 테스트
     */
    public function test_index_supports_search_filter(): void
    {
        $this->createTestRole(['name' => json_encode(['ko' => '특별 역할', 'en' => 'Special Role'])]);
        $this->createTestRole(['name' => json_encode(['ko' => '일반 역할', 'en' => 'General Role'])]);

        $response = $this->authRequest()->getJson('/api/admin/roles?search=특별');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * is_active 필터 테스트
     */
    public function test_index_supports_is_active_filter(): void
    {
        $this->createTestRole(['is_active' => true]);
        $this->createTestRole(['is_active' => false]);

        $response = $this->authRequest()->getJson('/api/admin/roles?is_active=true');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // 활성 역할 테스트 (active)
    // ========================================================================

    /**
     * 활성화된 역할만 조회
     */
    public function test_active_returns_only_active_roles(): void
    {
        $activeRole = $this->createTestRole(['is_active' => true]);
        $inactiveRole = $this->createTestRole(['is_active' => false]);

        $response = $this->authRequest()->getJson('/api/admin/roles/active');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 활성화된 역할이 포함되어 있는지 확인
        $identifiers = collect($response->json('data.data'))->pluck('identifier')->toArray();
        $this->assertContains($activeRole->identifier, $identifiers);
        $this->assertNotContains($inactiveRole->identifier, $identifiers);
    }

    /**
     * core.permissions.read 권한이 있으면 전체 활성 역할을 반환해야 합니다.
     */
    public function test_active_returns_all_roles_with_permissions_read(): void
    {
        $extraRole = $this->createTestRole(['is_active' => true]);

        $response = $this->authRequest()->getJson('/api/admin/roles/active');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $identifiers = collect($response->json('data.data'))->pluck('identifier')->toArray();
        $this->assertContains($extraRole->identifier, $identifiers);
    }

    /**
     * core.permissions.read 권한이 없으면 자기에게 부여된 역할만 반환해야 합니다.
     */
    public function test_active_returns_only_own_roles_without_permissions_read(): void
    {
        // core.permissions.read 권한 없이 admin 미들웨어만 통과하는 사용자
        $limitedUser = $this->createAdminUser(['core.users.read']);
        $limitedToken = $limitedUser->createToken('test-token')->plainTextToken;

        // 사용자에게 부여되지 않은 활성 역할 생성
        $unassignedRole = $this->createTestRole(['is_active' => true]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$limitedToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/roles/active');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $identifiers = collect($response->json('data.data'))->pluck('identifier')->toArray();

        // 부여되지 않은 역할은 포함되지 않아야 함
        $this->assertNotContains($unassignedRole->identifier, $identifiers);

        // 사용자의 역할(admin)은 포함되어야 함
        $this->assertContains('admin', $identifiers);
    }

    /**
     * roles/active 응답에 can_assign_roles abilities 포함 확인
     */
    public function test_active_returns_abilities_with_can_assign_roles(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/roles/active');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'abilities' => ['can_assign_roles'],
                ],
            ]);
    }

    // ========================================================================
    // 역할 상세 테스트 (show)
    // ========================================================================

    /**
     * 역할 상세 조회 성공 (권한 포함)
     */
    public function test_show_returns_role_with_permissions(): void
    {
        $role = $this->createTestRole();
        $permission = $this->createTestPermission();
        $role->permissions()->attach($permission->id);

        $response = $this->authRequest()->getJson("/api/admin/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'identifier',
                    'name',
                    'description',
                    'extension_type',
                    'is_active',
                ],
            ]);
    }

    /**
     * 역할 상세 조회 시 extension_name 필드 포함 확인
     */
    public function test_show_returns_extension_name_field(): void
    {
        $role = $this->createTestRole();

        $response = $this->authRequest()->getJson("/api/admin/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.extension_name', null); // user-created role → null
    }

    /**
     * 코어 역할 조회 시 extension_name null 확인
     */
    public function test_show_returns_null_extension_name_for_core_role(): void
    {
        $coreRole = $this->createTestRole([
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);

        $response = $this->authRequest()->getJson("/api/admin/roles/{$coreRole->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.extension_type', 'core')
            ->assertJsonPath('data.extension_name', null);
    }

    /**
     * 존재하지 않는 역할 조회 시 404 반환
     */
    public function test_show_returns_404_for_nonexistent_role(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/roles/99999');

        $response->assertStatus(404);
    }

    // ========================================================================
    // 역할 생성 테스트 (store)
    // ========================================================================

    /**
     * 역할 생성 성공
     */
    public function test_store_creates_role_successfully(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'identifier' => 'new_role_'.uniqid(),
            'name' => ['ko' => '새 역할', 'en' => 'New Role'],
            'description' => ['ko' => '새 역할 설명', 'en' => 'New Role Description'],
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        // 역할이 생성되었는지 확인 (응답의 ID로 조회)
        $roleId = $response->json('data.id');
        $role = Role::find($roleId);
        $this->assertNotNull($role);
        $this->assertEquals('새 역할', $role->name['ko']);
    }

    /**
     * 권한과 함께 역할 생성 성공
     */
    public function test_store_creates_role_with_permissions(): void
    {
        $permission1 = $this->createTestPermission('test.perm.one');
        $permission2 = $this->createTestPermission('test.perm.two');

        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'identifier' => 'role_with_perms_'.uniqid(),
            'name' => ['ko' => '권한 포함 역할', 'en' => 'Role with Permissions'],
            'permissions' => [
                ['id' => $permission1->id, 'scope_type' => null],
                ['id' => $permission2->id, 'scope_type' => null],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        // 권한이 할당되었는지 확인
        $roleId = $response->json('data.id');
        $role = Role::find($roleId);
        $this->assertCount(2, $role->permissions);
    }

    /**
     * name 필수 검증
     */
    public function test_store_validates_name_required(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'description' => ['ko' => '설명만', 'en' => 'Description only'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * identifier를 지정하여 역할 생성 성공
     */
    public function test_store_creates_role_with_custom_identifier(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'name' => ['ko' => 'ID 지정 역할', 'en' => 'Role with ID'],
            'identifier' => 'custom_role_id',
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $roleId = $response->json('data.id');
        $role = Role::find($roleId);
        $this->assertEquals('custom_role_id', $role->identifier);
    }

    /**
     * identifier 미지정 시 422 반환 (필수 필드)
     */
    public function test_store_validates_identifier_required(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'name' => ['ko' => '자동 생성', 'en' => 'Auto Generated'],
            'is_active' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['identifier']);
    }

    /**
     * identifier 중복 시 422 반환
     */
    public function test_store_validates_identifier_unique(): void
    {
        $this->createTestRole(['identifier' => 'duplicate_id']);

        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'name' => ['ko' => '중복 ID 역할', 'en' => 'Duplicate ID Role'],
            'identifier' => 'duplicate_id',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['identifier']);
    }

    /**
     * identifier 형식 검증 (대문자 포함 시 실패)
     */
    public function test_store_validates_identifier_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'name' => ['ko' => '형식 오류', 'en' => 'Bad Format'],
            'identifier' => 'Invalid-ID',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['identifier']);
    }

    /**
     * identifier 형식 검증 (숫자로 시작 시 실패)
     */
    public function test_store_validates_identifier_must_start_with_letter(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'name' => ['ko' => '숫자 시작', 'en' => 'Starts with number'],
            'identifier' => '1invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['identifier']);
    }

    /**
     * 다국어 name 형식 검증
     */
    public function test_store_validates_translatable_name_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'name' => ['ko' => '', 'en' => ''], // 빈 문자열
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * permissions.*.id 존재 검증
     */
    public function test_store_validates_permission_ids_exist(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'name' => ['ko' => '새 역할', 'en' => 'New Role'],
            'permissions' => [
                ['id' => 99999, 'scope_type' => null],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0.id']);
    }

    /**
     * 문자열 name을 다국어 배열로 자동 변환
     */
    public function test_store_converts_string_name_to_translatable(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/roles', [
            'identifier' => 'string_name_role_'.uniqid(),
            'name' => '단순 문자열 역할', // 문자열로 전달
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        // 다국어 형식으로 저장되었는지 확인
        $roleId = $response->json('data.id');
        $role = Role::find($roleId);
        // Role 모델의 name은 array로 캐스팅되므로 직접 배열로 접근
        $name = $role->name;
        $this->assertIsArray($name);
        $this->assertArrayHasKey('ko', $name);
        $this->assertArrayHasKey('en', $name);
    }

    // ========================================================================
    // 역할 수정 테스트 (update)
    // ========================================================================

    /**
     * 역할 수정 성공
     */
    public function test_update_modifies_role_successfully(): void
    {
        $role = $this->createTestRole();

        $response = $this->authRequest()->putJson("/api/admin/roles/{$role->id}", [
            'name' => ['ko' => '수정된 역할', 'en' => 'Updated Role'],
            'is_active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'is_active' => false,
        ]);
    }

    /**
     * 권한 동기화 테스트
     */
    public function test_update_syncs_permissions(): void
    {
        $role = $this->createTestRole();
        $oldPermission = $this->createTestPermission('old.permission');
        $newPermission = $this->createTestPermission('new.permission');

        // 기존 권한 할당
        $role->permissions()->attach($oldPermission->id);

        // 새 권한으로 교체
        $response = $this->authRequest()->putJson("/api/admin/roles/{$role->id}", [
            'name' => ['ko' => '수정된 역할', 'en' => 'Updated Role'],
            'permissions' => [
                ['id' => $newPermission->id, 'scope_type' => null],
            ],
        ]);

        $response->assertStatus(200);

        // 권한이 교체되었는지 확인
        $role->refresh();
        $permissionIds = $role->permissions->pluck('id')->toArray();
        $this->assertNotContains($oldPermission->id, $permissionIds);
        $this->assertContains($newPermission->id, $permissionIds);
    }

    // ========================================================================
    // 역할 삭제 테스트 (destroy)
    // ========================================================================

    /**
     * 역할 삭제 성공
     */
    public function test_destroy_deletes_role_successfully(): void
    {
        $role = $this->createTestRole();

        $response = $this->authRequest()->deleteJson("/api/admin/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('roles', [
            'id' => $role->id,
        ]);
    }

    /**
     * 시스템 역할 삭제 시 403 반환
     */
    public function test_destroy_returns_403_for_core_role(): void
    {
        $systemRole = $this->createTestRole([
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);

        $response = $this->authRequest()->deleteJson("/api/admin/roles/{$systemRole->id}");

        $response->assertStatus(403);

        // 역할이 삭제되지 않았는지 확인
        $this->assertDatabaseHas('roles', [
            'id' => $systemRole->id,
        ]);
    }

    /**
     * 모듈 소유 역할 삭제 시 403 반환
     */
    public function test_destroy_returns_403_for_module_owned_role(): void
    {
        $moduleRole = $this->createTestRole([
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
        ]);

        $response = $this->authRequest()->deleteJson("/api/admin/roles/{$moduleRole->id}");

        $response->assertStatus(403);

        // 역할이 삭제되지 않았는지 확인
        $this->assertDatabaseHas('roles', [
            'id' => $moduleRole->id,
        ]);
    }

    /**
     * 플러그인 소유 역할 삭제 시 403 반환
     */
    public function test_destroy_returns_403_for_plugin_owned_role(): void
    {
        $pluginRole = $this->createTestRole([
            'extension_type' => ExtensionOwnerType::Plugin,
            'extension_identifier' => 'sirsoft-payment',
        ]);

        $response = $this->authRequest()->deleteJson("/api/admin/roles/{$pluginRole->id}");

        $response->assertStatus(403);

        // 역할이 삭제되지 않았는지 확인
        $this->assertDatabaseHas('roles', [
            'id' => $pluginRole->id,
        ]);
    }

    /**
     * 사용자 생성 역할(extension_type=null) 삭제 성공
     */
    public function test_destroy_succeeds_for_user_created_role(): void
    {
        $userRole = $this->createTestRole(); // extension_type is null by default

        $response = $this->authRequest()->deleteJson("/api/admin/roles/{$userRole->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('roles', [
            'id' => $userRole->id,
        ]);
    }

    /**
     * 존재하지 않는 역할 삭제 시 404 반환
     */
    public function test_destroy_returns_404_for_nonexistent_role(): void
    {
        $response = $this->authRequest()->deleteJson('/api/admin/roles/99999');

        $response->assertStatus(404);
    }

    // ========================================================================
    // 역할 상태 토글 테스트 (toggleStatus)
    // ========================================================================

    /**
     * 활성 역할을 비활성으로 토글
     */
    public function test_toggle_status_deactivates_active_role(): void
    {
        $role = $this->createTestRole(['is_active' => true]);

        $response = $this->authRequest()->patchJson("/api/admin/roles/{$role->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'is_active' => false,
        ]);
    }

    /**
     * 비활성 역할을 활성으로 토글
     */
    public function test_toggle_status_activates_inactive_role(): void
    {
        $role = $this->createTestRole(['is_active' => false]);

        $response = $this->authRequest()->patchJson("/api/admin/roles/{$role->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'is_active' => true,
        ]);
    }

    /**
     * 토글 후 응답에 최신 is_active 값이 포함되는지 확인
     */
    public function test_toggle_status_returns_updated_resource(): void
    {
        $role = $this->createTestRole(['is_active' => true]);

        $response = $this->authRequest()->patchJson("/api/admin/roles/{$role->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    /**
     * update 권한 없이 토글 시 403 반환
     */
    public function test_toggle_status_returns_403_without_update_permission(): void
    {
        $user = $this->createAdminUser(['core.permissions.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $role = $this->createTestRole();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->patchJson("/api/admin/roles/{$role->id}/toggle-status");

        $response->assertStatus(403);
    }

    /**
     * 존재하지 않는 역할 토글 시 404 반환
     */
    public function test_toggle_status_returns_404_for_nonexistent_role(): void
    {
        $response = $this->authRequest()->patchJson('/api/admin/roles/99999/toggle-status');

        $response->assertStatus(404);
    }

    /**
     * 응답에 can_toggle_status ability 포함 확인
     */
    public function test_toggle_status_response_includes_abilities(): void
    {
        $role = $this->createTestRole();

        $response = $this->authRequest()->patchJson("/api/admin/roles/{$role->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'abilities' => ['can_toggle_status'],
                ],
            ]);
    }
}
