<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AdminPermissionController 테스트
 *
 * 권한 목록 API 엔드포인트를 테스트합니다.
 */
class PermissionControllerTest extends TestCase
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
    private function createAdminUser(array $permissions = ['core.permissions.read']): User
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

        // 고유한 식별자로 테스트용 역할 생성 (테스트별 격리를 위해)
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
     * 테스트용 권한 생성 헬퍼 (계층형 구조)
     */
    private function createHierarchicalPermissions(): void
    {
        // 모듈 레벨 (core)
        // 카테고리 레벨 (users)
        // 리프 노드 (view, create, update, delete)
        $permissions = [
            'core.users.view',
            'core.users.create',
            'core.users.update',
            'core.users.delete',
            'core.roles.view',
            'core.roles.create',
        ];

        foreach ($permissions as $identifier) {
            Permission::create([
                'identifier' => $identifier,
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier.' 권한', 'en' => $identifier.' Permission']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]);
        }
    }

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    /**
     * 인증 없이 권한 목록 조회 시 401 반환
     */
    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/permissions');

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 권한 목록 조회 시 403 반환
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
        ])->getJson('/api/admin/permissions');

        $response->assertStatus(403);
    }

    // ========================================================================
    // 권한 트리 조회 테스트 (index)
    // ========================================================================

    /**
     * 계층형 권한 트리 조회 성공
     */
    public function test_index_returns_hierarchical_permission_tree(): void
    {
        $this->createHierarchicalPermissions();

        $response = $this->authRequest()->getJson('/api/admin/permissions');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 권한 트리 응답 구조 검증
     */
    public function test_index_returns_correct_tree_structure(): void
    {
        $this->createHierarchicalPermissions();

        $response = $this->authRequest()->getJson('/api/admin/permissions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data' => [
                        'permissions',
                        'types',
                        'default_type',
                    ],
                ],
            ]);
    }

    /**
     * is_assignable 필드 포함 확인 (리프 노드만 true)
     */
    public function test_index_includes_is_assignable_field(): void
    {
        $this->createHierarchicalPermissions();

        $response = $this->authRequest()->getJson('/api/admin/permissions');

        $response->assertStatus(200);

        // 타입별 그룹화된 응답에서 admin 권한 목록 가져오기
        $permissions = $response->json('data.data.permissions.admin.permissions');
        $this->assertNotEmpty($permissions);

        // 각 항목에 is_assignable 필드가 있는지 확인
        foreach ($permissions as $item) {
            $this->assertArrayHasKey('is_assignable', $item);
        }
    }

    /**
     * leaf_count 필드 포함 확인
     */
    public function test_index_includes_leaf_count_field(): void
    {
        $this->createHierarchicalPermissions();

        $response = $this->authRequest()->getJson('/api/admin/permissions');

        $response->assertStatus(200);

        // 타입별 그룹화된 응답에서 admin 권한 목록 가져오기
        $permissions = $response->json('data.data.permissions.admin.permissions');
        $this->assertNotEmpty($permissions);

        // 각 항목에 leaf_count 필드가 있는지 확인
        foreach ($permissions as $item) {
            $this->assertArrayHasKey('leaf_count', $item);
        }
    }

    /**
     * 지역화된 name 반환 확인
     */
    public function test_index_returns_localized_names(): void
    {
        $this->createHierarchicalPermissions();

        $response = $this->authRequest()->getJson('/api/admin/permissions');

        $response->assertStatus(200);

        // 타입별 그룹화된 응답에서 admin 권한 목록 가져오기
        $permissions = $response->json('data.data.permissions.admin.permissions');
        $this->assertNotEmpty($permissions);

        // 각 항목에 name 필드가 문자열로 반환되는지 확인
        foreach ($permissions as $item) {
            $this->assertArrayHasKey('name', $item);
            // name은 문자열로 반환됨 (현재 로케일 값)
            $this->assertIsString($item['name']);
        }
    }

    /**
     * 각 권한 노드에 children 필드 포함 확인
     */
    public function test_index_includes_children_for_non_leaf_nodes(): void
    {
        $this->createHierarchicalPermissions();

        $response = $this->authRequest()->getJson('/api/admin/permissions');

        $response->assertStatus(200);

        // 타입별 그룹화된 응답에서 admin 권한 목록 가져오기
        $permissions = $response->json('data.data.permissions.admin.permissions');
        $this->assertNotEmpty($permissions);

        // 모든 노드에 children 필드가 존재하는지 확인
        foreach ($permissions as $item) {
            $this->assertArrayHasKey('children', $item);
        }
    }
}
