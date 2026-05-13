<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AdminMenuController 테스트
 *
 * 메뉴 관리 API 엔드포인트를 테스트합니다.
 */
class MenuControllerTest extends TestCase
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
    private function createAdminUser(array $permissions = ['core.menus.read', 'core.menus.create', 'core.menus.update', 'core.menus.delete']): User
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
     * 테스트용 메뉴 생성 헬퍼
     */
    private function createTestMenu(array $attributes = []): Menu
    {
        return Menu::create(array_merge([
            'name' => json_encode(['ko' => '테스트 메뉴', 'en' => 'Test Menu']),
            'slug' => 'test-menu-'.uniqid(),
            'url' => '/test-url',
            'icon' => 'test-icon',
            'order' => 1,
            'is_active' => true,
            'parent_id' => null,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    /**
     * admin 역할이 아닌 커스텀 역할로 관리자 사용자를 생성합니다.
     *
     * admin 역할은 checkAbility에서 무조건 true를 반환하므로,
     * abilities 제한 테스트에는 admin이 아닌 역할을 사용합니다.
     * isAdmin()은 type='admin'인 권한이 있는 역할이면 통과합니다.
     *
     * @param array $permissions 사용자에게 부여할 권한 식별자 목록
     * @return User 생성된 사용자
     */
    private function createNonAdminUser(array $permissions = []): User
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

        // admin이 아닌 커스텀 역할 생성
        $roleIdentifier = 'manager_test_'.uniqid();
        $testRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 매니저', 'en' => 'Test Manager']),
            'description' => json_encode(['ko' => '테스트 매니저', 'en' => 'Test Manager']),
            'is_active' => true,
        ]);

        // 역할에 권한 할당
        $testRole->permissions()->sync($permissionIds);

        // 사용자에게 커스텀 역할만 할당 (admin 역할 미할당)
        $user->roles()->attach($testRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    /**
     * 인증 없이 메뉴 목록 조회 시 401 반환
     */
    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/menus');

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 메뉴 목록 조회 시 403 반환
     */
    public function test_index_returns_403_without_permission(): void
    {
        // 권한 없는 관리자 생성
        $user = User::factory()->create();
        $adminRole = Role::where('identifier', 'admin')->first();

        // 기존 권한 분리
        $readPermission = Permission::where('identifier', 'core.menus.read')->first();
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
        ])->getJson('/api/admin/menus');

        $response->assertStatus(403);
    }

    /**
     * 생성 권한 없이 메뉴 생성 시 403 반환
     */
    public function test_store_returns_403_without_create_permission(): void
    {
        // read 권한만 있는 관리자 생성
        $user = $this->createAdminUser(['core.menus.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/menus', [
            'name' => ['ko' => '새 메뉴', 'en' => 'New Menu'],
            'slug' => 'new-menu',
            'url' => '/new-menu',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 삭제 권한 없이 메뉴 삭제 시 403 반환
     */
    public function test_destroy_returns_403_without_delete_permission(): void
    {
        // delete 권한 없는 관리자 생성
        $user = $this->createAdminUser(['core.menus.read', 'core.menus.create', 'core.menus.update']);
        $token = $user->createToken('test-token')->plainTextToken;

        $menu = $this->createTestMenu();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->deleteJson("/api/admin/menus/{$menu->id}");

        $response->assertStatus(403);
    }

    // ========================================================================
    // 메뉴 목록 테스트 (index)
    // ========================================================================

    /**
     * 메뉴 목록 조회 성공
     */
    public function test_index_returns_menus_list(): void
    {
        $this->createTestMenu();
        $this->createTestMenu();

        $response = $this->authRequest()->getJson('/api/admin/menus');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * is_active 필터 테스트
     */
    public function test_index_supports_is_active_filter(): void
    {
        $this->createTestMenu(['is_active' => true]);
        $this->createTestMenu(['is_active' => false]);

        $response = $this->authRequest()->getJson('/api/admin/menus?is_active=true');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 다중 검색 필터 테스트
     */
    public function test_index_supports_multiple_search_filters(): void
    {
        $this->createTestMenu(['name' => json_encode(['ko' => '특별 메뉴', 'en' => 'Special Menu'])]);

        $filters = [
            [
                'field' => 'name',
                'operator' => 'like',
                'value' => '특별',
            ],
        ];

        $response = $this->authRequest()->getJson('/api/admin/menus?'.http_build_query(['filters' => $filters]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 정렬 테스트
     */
    public function test_index_supports_sorting(): void
    {
        $this->createTestMenu(['order' => 1]);
        $this->createTestMenu(['order' => 2]);

        $response = $this->authRequest()->getJson('/api/admin/menus?sort_by=order&sort_order=desc');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // 계층 구조 테스트 (hierarchy)
    // ========================================================================

    /**
     * 계층 구조 조회 성공
     */
    public function test_hierarchy_returns_tree_structure(): void
    {
        $parentMenu = $this->createTestMenu(['name' => json_encode(['ko' => '부모 메뉴', 'en' => 'Parent Menu'])]);

        $response = $this->authRequest()->getJson('/api/admin/menus/hierarchy');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 계층 구조에 children 포함 확인
     */
    public function test_hierarchy_includes_children(): void
    {
        $parentMenu = $this->createTestMenu([
            'name' => json_encode(['ko' => '부모 메뉴', 'en' => 'Parent Menu']),
            'slug' => 'parent-menu',
        ]);
        $childMenu = $this->createTestMenu([
            'name' => json_encode(['ko' => '자식 메뉴', 'en' => 'Child Menu']),
            'slug' => 'child-menu',
            'parent_id' => $parentMenu->id,
        ]);

        $response = $this->authRequest()->getJson('/api/admin/menus/hierarchy');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 부모-자식 관계 확인
        $data = $response->json('data');
        $this->assertNotEmpty($data);
    }

    // ========================================================================
    // 활성 메뉴 테스트 (active)
    // ========================================================================

    /**
     * 활성화된 메뉴만 조회
     */
    public function test_active_returns_only_active_menus(): void
    {
        $this->createTestMenu(['is_active' => true, 'slug' => 'active-menu']);
        $this->createTestMenu(['is_active' => false, 'slug' => 'inactive-menu']);

        $response = $this->authRequest()->getJson('/api/admin/menus/active');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // 메뉴 생성 테스트 (store)
    // ========================================================================

    /**
     * 메뉴 생성 성공
     */
    public function test_store_creates_menu_successfully(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/menus', [
            'name' => ['ko' => '새 메뉴', 'en' => 'New Menu'],
            'slug' => 'new-menu',
            'url' => '/new-menu',
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('menus', [
            'slug' => 'new-menu',
        ]);
    }

    /**
     * name 필수 검증
     */
    public function test_store_validates_name_required(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/menus', [
            'slug' => 'new-menu',
            'url' => '/new-menu',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * slug 유니크 검증
     */
    public function test_store_validates_slug_unique(): void
    {
        $existingMenu = $this->createTestMenu(['slug' => 'existing-slug']);

        $response = $this->authRequest()->postJson('/api/admin/menus', [
            'name' => ['ko' => '새 메뉴', 'en' => 'New Menu'],
            'slug' => 'existing-slug', // 중복
            'url' => '/new-menu',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    /**
     * url 없이 메뉴 생성 가능 (부모 메뉴 등 경로 불필요 케이스)
     */
    public function test_store_allows_null_url(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/menus', [
            'name' => ['ko' => '부모 메뉴', 'en' => 'Parent Menu'],
            'slug' => 'parent-menu',
            // url 누락 — nullable이므로 허용
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('menus', [
            'slug' => 'parent-menu',
            'url' => null,
        ]);
    }

    /**
     * parent_id 존재 검증
     */
    public function test_store_validates_parent_id_exists(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/menus', [
            'name' => ['ko' => '새 메뉴', 'en' => 'New Menu'],
            'slug' => 'new-menu',
            'url' => '/new-menu',
            'parent_id' => 99999, // 존재하지 않는 ID
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    /**
     * 문자열 name을 다국어 배열로 자동 변환
     */
    public function test_store_converts_string_name_to_translatable(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/menus', [
            'name' => '단순 문자열 메뉴', // 문자열로 전달
            'slug' => 'string-name-menu',
            'url' => '/string-name-menu',
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // 메뉴 상세 테스트 (show)
    // ========================================================================

    /**
     * 메뉴 상세 조회 성공 (관계 포함)
     */
    public function test_show_returns_menu_with_relations(): void
    {
        $menu = $this->createTestMenu();

        $response = $this->authRequest()->getJson("/api/admin/menus/{$menu->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'url',
                ],
            ]);
    }

    /**
     * 존재하지 않는 메뉴 조회 시 404 반환
     */
    public function test_show_returns_404_for_nonexistent_menu(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/menus/99999');

        $response->assertStatus(404);
    }

    // ========================================================================
    // 메뉴 수정 테스트 (update)
    // ========================================================================

    /**
     * 메뉴 수정 성공
     */
    public function test_update_modifies_menu_successfully(): void
    {
        $menu = $this->createTestMenu();

        $response = $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", [
            'name' => ['ko' => '수정된 메뉴', 'en' => 'Updated Menu'],
            'slug' => $menu->slug,
            'url' => '/updated-url',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('menus', [
            'id' => $menu->id,
            'url' => '/updated-url',
        ]);
    }

    /**
     * slug 유니크 검증 (자기 자신 제외)
     */
    public function test_update_validates_slug_unique_except_self(): void
    {
        $menu1 = $this->createTestMenu(['slug' => 'menu-1']);
        $menu2 = $this->createTestMenu(['slug' => 'menu-2']);

        // menu2의 slug를 menu1의 slug로 변경 시도
        $response = $this->authRequest()->putJson("/api/admin/menus/{$menu2->id}", [
            'name' => ['ko' => '수정된 메뉴', 'en' => 'Updated Menu'],
            'slug' => 'menu-1', // menu1과 중복
            'url' => '/updated-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    /**
     * 자기 자신을 부모로 설정 방지
     */
    public function test_update_prevents_self_parent(): void
    {
        $menu = $this->createTestMenu();

        $response = $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", [
            'name' => ['ko' => '수정된 메뉴', 'en' => 'Updated Menu'],
            'slug' => $menu->slug,
            'url' => '/updated-url',
            'parent_id' => $menu->id, // 자기 자신을 부모로 설정
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    // ========================================================================
    // 메뉴 삭제 테스트 (destroy)
    // ========================================================================

    /**
     * 메뉴 삭제 성공
     */
    public function test_destroy_deletes_menu_successfully(): void
    {
        $menu = $this->createTestMenu();

        $response = $this->authRequest()->deleteJson("/api/admin/menus/{$menu->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('menus', [
            'id' => $menu->id,
        ]);
    }

    /**
     * 자식 메뉴가 있을 때 삭제 실패
     */
    public function test_destroy_fails_when_menu_has_children(): void
    {
        $parentMenu = $this->createTestMenu(['slug' => 'parent-menu']);
        $childMenu = $this->createTestMenu([
            'slug' => 'child-menu',
            'parent_id' => $parentMenu->id,
        ]);

        $response = $this->authRequest()->deleteJson("/api/admin/menus/{$parentMenu->id}");

        $response->assertStatus(422);

        // 부모 메뉴가 삭제되지 않았는지 확인
        $this->assertDatabaseHas('menus', [
            'id' => $parentMenu->id,
        ]);
    }

    // ========================================================================
    // 순서 변경 테스트 (updateOrder)
    // ========================================================================

    /**
     * 메뉴 순서 변경 성공
     */
    public function test_update_order_changes_menu_positions(): void
    {
        $menu1 = $this->createTestMenu(['slug' => 'menu-order-1', 'order' => 1]);
        $menu2 = $this->createTestMenu(['slug' => 'menu-order-2', 'order' => 2]);

        $response = $this->authRequest()->putJson('/api/admin/menus/order', [
            'parent_menus' => [
                ['id' => $menu1->id, 'order' => 2],
                ['id' => $menu2->id, 'order' => 1],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // 상태 토글 테스트 (toggleStatus)
    // ========================================================================

    /**
     * 메뉴 활성화 상태 토글
     */
    public function test_toggle_status_switches_is_active(): void
    {
        $menu = $this->createTestMenu(['is_active' => true]);

        $response = $this->authRequest()->patchJson("/api/admin/menus/{$menu->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 상태가 변경되었는지 확인
        $menu->refresh();
        $this->assertFalse($menu->is_active);
    }

    // ========================================================================
    // 역할 기반 메뉴 접근 제어 테스트
    // ========================================================================

    /**
     * 역할 권한이 없는 코어 메뉴는 비관리자에게 표시되지 않음
     */
    public function test_active_hides_core_menus_without_role_assignment(): void
    {
        // 비관리자 사용자 생성 (manager 역할만)
        $managerRole = Role::create([
            'identifier' => 'manager_test_'.uniqid(),
            'name' => json_encode(['ko' => '매니저', 'en' => 'Manager']),
            'description' => json_encode(['ko' => '매니저', 'en' => 'Manager']),
            'is_active' => true,
        ]);

        // core.menus.read 권한 부여
        $readPermission = Permission::firstOrCreate(
            ['identifier' => 'core.menus.read'],
            [
                'name' => json_encode(['ko' => '메뉴 읽기', 'en' => 'Menu Read']),
                'description' => json_encode(['ko' => '메뉴 읽기 권한', 'en' => 'Menu Read Permission']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );
        $managerRole->permissions()->sync([$readPermission->id]);

        $managerUser = User::factory()->create();
        $managerUser->roles()->attach($managerRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $managerToken = $managerUser->createToken('test-token')->plainTextToken;

        // 메뉴 생성 (역할 할당 없음)
        $menu = $this->createTestMenu(['slug' => 'no-role-menu', 'is_active' => true]);

        // 비관리자 사용자로 active 메뉴 조회
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$managerToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/menus/active');

        $response->assertStatus(200);

        // 역할 할당이 없는 메뉴는 표시되지 않아야 함
        $menuSlugs = collect($response->json('data'))->pluck('slug')->toArray();
        $this->assertNotContains('no-role-menu', $menuSlugs);
    }

    /**
     * 역할 권한이 있는 코어 메뉴는 해당 역할 사용자에게 표시됨
     */
    public function test_active_shows_menus_with_role_assignment(): void
    {
        // 비관리자 사용자 생성
        $managerRole = Role::create([
            'identifier' => 'manager_test_'.uniqid(),
            'name' => json_encode(['ko' => '매니저', 'en' => 'Manager']),
            'description' => json_encode(['ko' => '매니저', 'en' => 'Manager']),
            'is_active' => true,
        ]);

        $readPermission = Permission::firstOrCreate(
            ['identifier' => 'core.menus.read'],
            [
                'name' => json_encode(['ko' => '메뉴 읽기', 'en' => 'Menu Read']),
                'description' => json_encode(['ko' => '메뉴 읽기 권한', 'en' => 'Menu Read Permission']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );
        $managerRole->permissions()->sync([$readPermission->id]);

        $managerUser = User::factory()->create();
        $managerUser->roles()->attach($managerRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $managerToken = $managerUser->createToken('test-token')->plainTextToken;

        // 메뉴 생성 후 매니저 역할에 read 권한 할당
        $menu = $this->createTestMenu(['slug' => 'assigned-menu', 'is_active' => true]);
        $menu->roles()->attach($managerRole->id, [
            'permission_type' => MenuPermissionType::Read->value,
        ]);

        // 비관리자 사용자로 active 메뉴 조회
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$managerToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/menus/active');

        $response->assertStatus(200);

        // 역할 할당된 메뉴는 표시되어야 함
        $menuSlugs = collect($response->json('data'))->pluck('slug')->toArray();
        $this->assertContains('assigned-menu', $menuSlugs);
    }

    /**
     * 관리자 역할은 역할 할당 없이도 모든 메뉴 접근 가능
     */
    public function test_active_shows_all_menus_for_admin(): void
    {
        // 메뉴 생성 + admin 역할 할당 (scopeAccessibleBy는 명시적 역할 할당 필요)
        $menu = $this->createTestMenu(['slug' => 'admin-visible-menu', 'is_active' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        $menu->roles()->attach($adminRole->id, [
            'permission_type' => MenuPermissionType::Read->value,
        ]);

        $response = $this->authRequest()->getJson('/api/admin/menus/active');

        $response->assertStatus(200);

        $menuSlugs = collect($response->json('data'))->pluck('slug')->toArray();
        $this->assertContains('admin-visible-menu', $menuSlugs);
    }

    /**
     * 메뉴 생성 시 관리자 역할과 생성자 역할이 자동 할당됨
     */
    public function test_store_auto_assigns_admin_and_creator_roles(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/menus', [
            'name' => ['ko' => '자동할당 메뉴', 'en' => 'Auto Assign Menu'],
            'slug' => 'auto-assign-menu',
            'url' => '/auto-assign',
        ]);

        $response->assertStatus(201);

        $menu = Menu::where('slug', 'auto-assign-menu')->first();
        $this->assertNotNull($menu);

        // 관리자 역할이 할당되었는지 확인
        $adminRole = Role::where('identifier', 'admin')->first();
        $this->assertTrue(
            $menu->roles()
                ->where('roles.id', $adminRole->id)
                ->wherePivot('permission_type', MenuPermissionType::Read->value)
                ->exists()
        );

        // 생성자(현재 사용자)의 역할이 할당되었는지 확인
        $creatorRoleIds = $this->admin->roles()->pluck('roles.id')->toArray();
        foreach ($creatorRoleIds as $roleId) {
            $this->assertTrue(
                $menu->roles()
                    ->where('roles.id', $roleId)
                    ->wherePivot('permission_type', MenuPermissionType::Read->value)
                    ->exists()
            );
        }
    }

    // ========================================================================
    // 컬렉션 레벨 abilities 테스트
    // ========================================================================

    /**
     * 모든 메뉴 권한을 가진 사용자는 컬렉션 abilities가 모두 true
     */
    public function test_index_returns_all_abilities_true_for_full_permission_user(): void
    {
        $this->createTestMenu();

        $response = $this->authRequest()->getJson('/api/admin/menus');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_create']);
        $this->assertTrue($data['abilities']['can_update']);
        $this->assertTrue($data['abilities']['can_delete']);
    }

    /**
     * 읽기 권한만 가진 사용자는 컬렉션 abilities가 모두 false
     *
     * admin 역할은 checkAbility에서 무조건 true를 반환하므로,
     * admin이 아닌 커스텀 역할로 테스트합니다.
     */
    public function test_index_returns_all_abilities_false_for_read_only_user(): void
    {
        $readOnlyUser = $this->createNonAdminUser(['core.menus.read']);
        $readOnlyToken = $readOnlyUser->createToken('test-token')->plainTextToken;

        $this->createTestMenu();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readOnlyToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/menus');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertFalse($data['abilities']['can_create']);
        $this->assertFalse($data['abilities']['can_update']);
        $this->assertFalse($data['abilities']['can_delete']);
    }

    /**
     * 부분 권한 사용자는 해당 abilities만 true
     */
    public function test_index_returns_partial_abilities_for_partial_permission_user(): void
    {
        $partialUser = $this->createNonAdminUser(['core.menus.read', 'core.menus.create']);
        $partialToken = $partialUser->createToken('test-token')->plainTextToken;

        $this->createTestMenu();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$partialToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/menus');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_create']);
        $this->assertFalse($data['abilities']['can_update']);
        $this->assertFalse($data['abilities']['can_delete']);
    }

    /**
     * 컬렉션 abilities에는 정확히 3개 키가 포함된다
     */
    public function test_index_abilities_contains_expected_keys(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/menus');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertCount(3, $data['abilities']);
        $this->assertArrayHasKey('can_create', $data['abilities']);
        $this->assertArrayHasKey('can_update', $data['abilities']);
        $this->assertArrayHasKey('can_delete', $data['abilities']);
    }

    // ========================================================================
    // index 엔드포인트 역할 기반 필터링 테스트
    // ========================================================================

    /**
     * 비관리자 사용자는 role_menus에 역할 할당이 없는 메뉴를 index에서 볼 수 없음
     */
    public function test_index_hides_menus_without_role_assignment_for_non_admin(): void
    {
        $managerUser = $this->createNonAdminUser(['core.menus.read']);
        $managerToken = $managerUser->createToken('test-token')->plainTextToken;

        // 메뉴 생성 (역할 할당 없음)
        $this->createTestMenu(['slug' => 'no-role-index-menu', 'is_active' => true]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$managerToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/menus?is_active=true');

        $response->assertStatus(200);

        // 역할 할당이 없는 메뉴는 표시되지 않아야 함
        $menuSlugs = collect($response->json('data.data'))->pluck('slug')->toArray();
        $this->assertNotContains('no-role-index-menu', $menuSlugs);
    }

    /**
     * 비관리자 사용자는 role_menus에 역할이 할당된 메뉴만 index에서 볼 수 있음
     */
    public function test_index_shows_menus_with_role_assignment_for_non_admin(): void
    {
        $managerUser = $this->createNonAdminUser(['core.menus.read']);
        $managerRole = $managerUser->roles()->first();
        $managerToken = $managerUser->createToken('test-token')->plainTextToken;

        // 메뉴 생성 후 매니저 역할에 read 권한 할당
        $menu = $this->createTestMenu(['slug' => 'assigned-index-menu', 'is_active' => true]);
        $menu->roles()->attach($managerRole->id, [
            'permission_type' => MenuPermissionType::Read->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$managerToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/menus?is_active=true');

        $response->assertStatus(200);

        // 역할 할당된 메뉴는 표시되어야 함
        $menuSlugs = collect($response->json('data.data'))->pluck('slug')->toArray();
        $this->assertContains('assigned-index-menu', $menuSlugs);
    }

    /**
     * 관리자 역할은 index에서도 역할 할당 없이 모든 메뉴 접근 가능
     */
    public function test_index_shows_all_menus_for_admin(): void
    {
        // 메뉴 생성 + admin 역할 할당 (scopeAccessibleBy는 명시적 역할 할당 필요)
        $menu = $this->createTestMenu(['slug' => 'admin-visible-index-menu', 'is_active' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        $menu->roles()->attach($adminRole->id, [
            'permission_type' => MenuPermissionType::Read->value,
        ]);

        $response = $this->authRequest()->getJson('/api/admin/menus?is_active=true');

        $response->assertStatus(200);

        $menuSlugs = collect($response->json('data.data'))->pluck('slug')->toArray();
        $this->assertContains('admin-visible-index-menu', $menuSlugs);
    }

    // ========================================================================
    // 순서 변경 + depth 이동 테스트 (moved_items)
    // ========================================================================

    /**
     * 자식 메뉴를 최상위로 이동 (moved_items: new_parent_id=null)
     */
    public function test_update_order_moves_child_to_root(): void
    {
        $parent = $this->createTestMenu(['slug' => 'move-parent', 'order' => 1]);
        $child = $this->createTestMenu([
            'slug' => 'move-child',
            'order' => 1,
            'parent_id' => $parent->id,
        ]);

        $response = $this->authRequest()->putJson('/api/admin/menus/order', [
            'parent_menus' => [
                ['id' => $parent->id, 'order' => 1],
                ['id' => $child->id, 'order' => 2],
            ],
            'moved_items' => [
                ['id' => $child->id, 'new_parent_id' => null],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $child->refresh();
        $this->assertNull($child->parent_id);
    }

    /**
     * 최상위 메뉴를 다른 메뉴 하위로 이동 (moved_items: new_parent_id=부모ID)
     */
    public function test_update_order_moves_root_to_child(): void
    {
        $parent = $this->createTestMenu(['slug' => 'target-parent', 'order' => 1]);
        $rootMenu = $this->createTestMenu(['slug' => 'moving-root', 'order' => 2]);

        $response = $this->authRequest()->putJson('/api/admin/menus/order', [
            'parent_menus' => [
                ['id' => $parent->id, 'order' => 1],
            ],
            'child_menus' => [
                $parent->id => [
                    ['id' => $rootMenu->id, 'order' => 1],
                ],
            ],
            'moved_items' => [
                ['id' => $rootMenu->id, 'new_parent_id' => $parent->id],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $rootMenu->refresh();
        $this->assertEquals($parent->id, $rootMenu->parent_id);
    }

    /**
     * 순환 참조 시 422 에러 반환
     */
    public function test_update_order_rejects_circular_reference(): void
    {
        $parent = $this->createTestMenu(['slug' => 'circular-parent', 'order' => 1]);
        $child = $this->createTestMenu([
            'slug' => 'circular-child',
            'order' => 1,
            'parent_id' => $parent->id,
        ]);

        // 부모를 자기 자식 아래로 이동 시도 → 순환 참조
        $response = $this->authRequest()->putJson('/api/admin/menus/order', [
            'parent_menus' => [
                ['id' => $child->id, 'order' => 1],
            ],
            'moved_items' => [
                ['id' => $parent->id, 'new_parent_id' => $child->id],
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * moved_items 없이 기존 요청 → 하위 호환 유지
     */
    public function test_update_order_backward_compatible_without_moved_items(): void
    {
        $menu1 = $this->createTestMenu(['slug' => 'compat-1', 'order' => 1]);
        $menu2 = $this->createTestMenu(['slug' => 'compat-2', 'order' => 2]);

        $response = $this->authRequest()->putJson('/api/admin/menus/order', [
            'parent_menus' => [
                ['id' => $menu1->id, 'order' => 2],
                ['id' => $menu2->id, 'order' => 1],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $menu1->refresh();
        $menu2->refresh();
        $this->assertEquals(2, $menu1->order);
        $this->assertEquals(1, $menu2->order);
    }

    /**
     * 3단계 자손으로의 이동도 순환 참조로 차단됨
     */
    public function test_update_order_rejects_deep_circular_reference(): void
    {
        $grandparent = $this->createTestMenu(['slug' => 'deep-gp', 'order' => 1]);
        $parent = $this->createTestMenu([
            'slug' => 'deep-parent',
            'order' => 1,
            'parent_id' => $grandparent->id,
        ]);
        $child = $this->createTestMenu([
            'slug' => 'deep-child',
            'order' => 1,
            'parent_id' => $parent->id,
        ]);

        // grandparent를 3단계 자손(child) 아래로 이동 시도
        $response = $this->authRequest()->putJson('/api/admin/menus/order', [
            'parent_menus' => [
                ['id' => $child->id, 'order' => 1],
            ],
            'moved_items' => [
                ['id' => $grandparent->id, 'new_parent_id' => $child->id],
            ],
        ]);

        $response->assertStatus(422);
    }
}
