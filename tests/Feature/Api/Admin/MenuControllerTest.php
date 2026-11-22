<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Models\Attachment;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /**
     * 테스트용 관리자와 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 생성 및 할당
     *
     * @param  array  $permissions  사용자에게 부여할 권한 식별자 목록
     * @return User 관리자 역할과 테스트 역할을 가진 사용자
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
     *
     * @return static 인증 헤더를 붙인 테스트 인스턴스
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
     *
     * @param  array  $attributes  기본값을 덮을 메뉴 속성
     * @return Menu 생성된 메뉴
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
     * @param  array  $permissions  사용자에게 부여할 권한 식별자 목록
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
     *
     * @scenario endpoint=index, actor=unauthenticated
     *
     * @effects unauthenticated_request_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/menus');

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 메뉴 목록 조회 시 403 반환
     *
     * @scenario endpoint=index, actor=no_permission
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=store, actor=no_permission
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=destroy, actor=no_permission
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
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

    /**
     * 메뉴 API 엔드포인트별 요청 목록을 만듭니다.
     *
     * @param  Menu  $menu  대상 메뉴 ({menu} 경로용)
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> 엔드포인트 → [메서드, 경로, 본문]
     */
    private function endpointRequests(Menu $menu): array
    {
        return [
            'index' => ['GET', '/api/admin/menus', []],
            'hierarchy' => ['GET', '/api/admin/menus/hierarchy', []],
            'active' => ['GET', '/api/admin/menus/active', []],
            'store' => ['POST', '/api/admin/menus', ['name' => ['ko' => '새 메뉴', 'en' => 'New'], 'slug' => 'gate-new', 'url' => '/gate-new']],
            'show' => ['GET', "/api/admin/menus/{$menu->id}", []],
            'update' => ['PUT', "/api/admin/menus/{$menu->id}", ['name' => ['ko' => '수정', 'en' => 'Edit'], 'slug' => $menu->slug, 'url' => '/gate-edit']],
            'destroy' => ['DELETE', "/api/admin/menus/{$menu->id}", []],
            'update_order' => ['PUT', '/api/admin/menus/order', ['parent_menus' => [['id' => $menu->id, 'order' => 1]]]],
            'toggle_status' => ['PATCH', "/api/admin/menus/{$menu->id}/toggle-status", []],
            'icon_upload' => ['POST', '/api/admin/menus/icon-upload', []],
        ];
    }

    /**
     * 인증 없는 요청은 모든 메뉴 엔드포인트(사이드바·업로드 포함)에서 401 이다.
     *
     * @scenario endpoint=index, endpoint=hierarchy, endpoint=active, endpoint=store, endpoint=show, endpoint=update, endpoint=destroy, endpoint=update_order, endpoint=toggle_status, endpoint=icon_upload, actor=unauthenticated
     *
     * @effects unauthenticated_request_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_every_menu_endpoint_requires_authentication(): void
    {
        $menu = $this->createTestMenu(['slug' => 'gate-auth']);

        foreach ($this->endpointRequests($menu) as $endpoint => [$method, $uri, $body]) {
            $response = $this->json($method, $uri, $body, ['Accept' => 'application/json']);

            $this->assertSame(401, $response->status(), $endpoint.': '.$response->getContent());
        }

        $this->assertNotNull(Menu::find($menu->id), '인증 없는 삭제 요청이 메뉴를 지웠다');
    }

    /**
     * 엔드포인트가 요구하는 권한이 없는 관리자는 403 이다 — 조회는 read 없는 관리자, 쓰기는 read 만 있는 관리자로 잰다.
     *
     * @scenario endpoint=hierarchy, endpoint=show, endpoint=update, endpoint=update_order, endpoint=toggle_status, actor=no_permission
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_permission_gated_endpoints_reject_missing_permission(): void
    {
        $menu = $this->createTestMenu(['slug' => 'gate-perm', 'is_active' => true]);
        $requests = $this->endpointRequests($menu);
        $cases = [
            'hierarchy' => [],
            'show' => [],
            'update' => ['core.menus.read'],
            'update_order' => ['core.menus.read'],
            'toggle_status' => ['core.menus.read'],
        ];

        foreach ($cases as $endpoint => $permissions) {
            $this->app['auth']->forgetGuards();
            $token = $this->createAdminUser($permissions)->createToken('t')->plainTextToken;
            [$method, $uri, $body] = $requests[$endpoint];

            $response = $this->json($method, $uri, $body, ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);

            $this->assertSame(403, $response->status(), $endpoint.': '.$response->getContent());
        }

        $fresh = $menu->fresh();
        $this->assertSame('gate-perm', $fresh->slug);
        $this->assertTrue((bool) $fresh->is_active, '권한 없는 상태 전환이 반영됐다');
    }

    // ========================================================================
    // 메뉴 목록 테스트 (index)
    // ========================================================================

    /**
     * 메뉴 목록 조회 성공
     *
     * @scenario endpoint=index, actor=full_admin
     *
     * @effects index_lists_filters_and_sorts
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=full_admin
     *
     * @effects index_lists_filters_and_sorts
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=full_admin
     *
     * @effects index_lists_filters_and_sorts
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=full_admin
     *
     * @effects index_lists_filters_and_sorts
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=hierarchy, actor=full_admin
     *
     * @effects hierarchy_returns_nested_tree
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=hierarchy, actor=full_admin
     *
     * @effects hierarchy_returns_nested_tree
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=active, actor=full_admin
     *
     * @effects active_returns_only_active_menus
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=store, actor=full_admin
     *
     * @effects store_creates_menu_with_validation
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=store, actor=full_admin
     *
     * @effects store_creates_menu_with_validation
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=store, actor=full_admin
     *
     * @effects store_creates_menu_with_validation
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=store, actor=full_admin
     *
     * @effects store_creates_menu_with_validation
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=store, actor=full_admin
     *
     * @effects store_creates_menu_with_validation
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=store, actor=full_admin
     *
     * @effects store_creates_menu_with_validation
     *
     * @return void 반환값 없음
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
     *
     * @scenario resource=menu,endpoint=detail,observation=response_payload
     *
     * @effects detail_still_returns_full_payload, show_returns_relations_or_404
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=show, actor=full_admin
     *
     * @effects show_returns_relations_or_404
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=update, actor=full_admin
     *
     * @effects update_modifies_menu_with_validation
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=update, actor=full_admin
     *
     * @effects update_modifies_menu_with_validation
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=update, actor=full_admin
     *
     * @effects update_modifies_menu_with_validation
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=destroy, actor=full_admin
     *
     * @effects destroy_deletes_leaf_and_blocks_parent
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=destroy, actor=full_admin
     *
     * @effects destroy_deletes_leaf_and_blocks_parent
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=update_order, actor=full_admin
     *
     * @effects update_order_moves_menus_and_rejects_cycles
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=toggle_status, actor=full_admin
     *
     * @effects toggle_status_flips_is_active
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=active, actor=non_admin_role
     *
     * @effects role_assignment_limits_visible_menus
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=active, actor=non_admin_role
     *
     * @effects role_assignment_limits_visible_menus
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=active, actor=full_admin
     *
     * @effects role_assignment_limits_visible_menus, active_returns_only_active_menus
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=store, actor=full_admin
     *
     * @effects store_assigns_admin_and_creator_roles
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=full_admin
     *
     * @effects index_abilities_reflect_permissions
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=partial_permission
     *
     * @effects index_abilities_reflect_permissions
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=partial_permission
     *
     * @effects index_abilities_reflect_permissions
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=full_admin
     *
     * @effects index_abilities_reflect_permissions
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=non_admin_role
     *
     * @effects role_assignment_limits_visible_menus
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=non_admin_role
     *
     * @effects role_assignment_limits_visible_menus
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=index, actor=full_admin
     *
     * @effects role_assignment_limits_visible_menus
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=update_order, actor=full_admin
     *
     * @effects update_order_moves_menus_and_rejects_cycles
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=update_order, actor=full_admin
     *
     * @effects update_order_moves_menus_and_rejects_cycles
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=update_order, actor=full_admin
     *
     * @effects update_order_moves_menus_and_rejects_cycles
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=update_order, actor=full_admin
     *
     * @effects update_order_moves_menus_and_rejects_cycles
     *
     * @return void 반환값 없음
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
     *
     * @scenario endpoint=update_order, actor=full_admin
     *
     * @effects update_order_moves_menus_and_rejects_cycles
     *
     * @return void 반환값 없음
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

    // ========================================================================
    // 메뉴 아이콘 — 구조형 선언(class·image·svg)·업로드 (#381)
    // ========================================================================

    /** @var string 테스트용 currentColor SVG */
    private const SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 22h20z"/></svg>';

    /**
     * 메뉴 아이콘 테스트용 기본 생성 본문을 만듭니다.
     *
     * @param  string  $slug  슬러그
     * @param  array<string, mixed>  $extra  덧붙일 필드
     * @return array<string, mixed> 요청 본문
     */
    private function menuPayload(string $slug, array $extra = []): array
    {
        return array_merge([
            'name' => ['ko' => '아이콘 메뉴', 'en' => 'Icon Menu'],
            'slug' => $slug,
            'url' => '/admin/'.$slug,
        ], $extra);
    }

    /**
     * 응답 JSON 에서 slug 를 가진 메뉴 노드를 깊이와 무관하게 모읍니다.
     *
     * @param  mixed  $node  응답 조각
     * @return list<array<string, mixed>> 메뉴 노드 목록
     */
    private function collectMenuNodes(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = isset($node['slug']) && array_key_exists('icon', $node) ? [$node] : [];

        foreach ($node as $child) {
            $found = array_merge($found, $this->collectMenuNodes($child));
        }

        return $found;
    }

    /**
     * 첨부 디스크를 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    private function fakeAttachmentDisk(): void
    {
        Storage::fake(config('attachment.disk', 'attachments'));
    }

    /**
     * 조회 응답이 호환 문자열 icon 과 구조형 icon_spec 을 함께 싣는다 (class·svg·자식).
     *
     * @scenario endpoint=index, endpoint=active, endpoint=hierarchy, actor=full_admin
     *
     * @effects legacy_icon_exposed
     *
     * @return void 반환값 없음
     */
    public function test_menu_responses_include_icon_and_icon_spec(): void
    {
        $parent = $this->createTestMenu(['slug' => 'icon-parent', 'icon' => 'fas fa-users']);
        $child = $this->createTestMenu([
            'slug' => 'icon-child',
            'parent_id' => $parent->id,
            'icon' => json_encode(['type' => 'svg', 'value' => self::SAFE_SVG]),
        ]);

        $index = $this->authRequest()->getJson('/api/admin/menus');
        $index->assertOk();
        $flat = collect($this->collectMenuNodes($index->json('data')));
        $p = $flat->firstWhere('slug', 'icon-parent');
        $this->assertSame('fas fa-users', $p['icon']);
        $this->assertSame(['type' => 'class', 'value' => 'fas fa-users'], $p['icon_spec']);
        $c = $flat->firstWhere('slug', 'icon-child');
        $this->assertNull($c['icon']);
        $this->assertSame('svg', $c['icon_spec']['type']);
        $this->assertSame(self::SAFE_SVG, $c['icon_spec']['value']);

        // 사이드바는 내 역할에 지정된 메뉴만 보인다 — 관리자 역할에 read 를 준다
        $adminRole = Role::where('identifier', 'admin')->first();
        foreach ([$parent, $child] as $menu) {
            $menu->roles()->attach($adminRole->id, ['permission_type' => MenuPermissionType::Read->value]);
        }

        $active = $this->authRequest()->getJson('/api/admin/menus/active');
        $active->assertOk();
        $nav = collect($active->json('data'));
        $navParent = $nav->firstWhere('slug', 'icon-parent');
        $this->assertSame('fas fa-users', $navParent['icon']);
        $this->assertSame(['type' => 'class', 'value' => 'fas fa-users'], $navParent['icon_spec']);
        $navChild = collect($navParent['children'])->firstWhere('slug', 'icon-child');
        $this->assertNull($navChild['icon']);
        $this->assertSame('svg', $navChild['icon_spec']['type']);

        // 메뉴 관리 화면의 계층 조회도 같은 두 칸을 싣는다
        $hierarchy = $this->authRequest()->getJson('/api/admin/menus/hierarchy');
        $hierarchy->assertOk();
        $tree = collect($this->collectMenuNodes($hierarchy->json('data')));
        $hParent = $tree->firstWhere('slug', 'icon-parent');
        $this->assertSame('fas fa-users', $hParent['icon']);
        $this->assertSame(['type' => 'class', 'value' => 'fas fa-users'], $hParent['icon_spec']);
        $hChild = $tree->firstWhere('slug', 'icon-child');
        $this->assertNull($hChild['icon']);
        $this->assertSame('svg', $hChild['icon_spec']['type']);
        $this->assertSame(self::SAFE_SVG, $hChild['icon_spec']['value']);
        unset($child);
    }

    /**
     * 30KB SVG 코드가 저장된다 (TEXT 컬럼 — VARCHAR(255) 로는 담을 수 없는 길이).
     *
     * @scenario icon_type=svg, entry=api_create
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_store_accepts_large_svg_icon_spec(): void
    {
        $paths = str_repeat('<path fill="currentColor" d="M1 1h2v2H1z"/>', 700);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'.$paths.'</svg>';
        $this->assertGreaterThan(28000, strlen($svg));
        $this->assertLessThan(32768, strlen($svg));

        $response = $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('svg-big', [
            'icon_spec' => ['type' => 'svg', 'value' => $svg],
        ]));

        $response->assertStatus(201);
        $stored = Menu::where('slug', 'svg-big')->value('icon');
        $this->assertSame(['type' => 'svg', 'value' => $svg], json_decode($stored, true));
    }

    /**
     * 사이트 내부 이미지 경로가 정규 JSON 으로 저장되고 응답 url 이 채워진다.
     *
     * @scenario icon_type=image_site, entry=api_create
     *
     * @effects canonical_storage, url_presented, legacy_icon_exposed
     *
     * @return void 반환값 없음
     */
    public function test_store_accepts_site_image_path(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('site-img', [
            'icon_spec' => ['type' => 'image', 'source' => 'site', 'path' => '/qa/a.png', 'url' => 'https://evil.example/x.png'],
        ]));

        $response->assertStatus(201);
        $this->assertSame(
            '{"path":"/qa/a.png","source":"site","type":"image"}',
            Menu::where('slug', 'site-img')->value('icon')
        );
        $this->assertNull($response->json('data.icon'));
        $this->assertSame('/qa/a.png', $response->json('data.icon_spec.url'));
    }

    /**
     * 외부 주소·data:·상위 탈출·SVG 확장자 이미지는 422 다.
     *
     * @scenario icon_type=image_site, entry=api_create
     *
     * @effects external_url_rejected
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_external_or_unsafe_image_paths(): void
    {
        foreach (['https://example.com/x.png', 'http://example.com/x.png', '//example.com/x.png', 'data:image/png;base64,AAA', '/a/../b.png', '/qa/a.svg'] as $i => $path) {
            $response = $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('bad-img-'.$i, [
                'icon_spec' => ['type' => 'image', 'source' => 'site', 'path' => $path],
            ]));

            $response->assertStatus(422)->assertJsonValidationErrors(['icon_spec']);
        }
    }

    /**
     * 위험한 SVG 코드는 사유와 함께 422 다.
     *
     * @scenario icon_type=svg, entry=api_create
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_unsafe_svg(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('bad-svg', [
            'icon_spec' => ['type' => 'svg', 'value' => '<svg><script>alert(1)</script></svg>'],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['icon_spec']);
        $this->assertStringContainsString('script', (string) $response->json('errors.icon_spec.0'));
    }

    /**
     * 레거시 icon 문자열은 FA 클래스만 받는다 — 경로·SVG·배열은 422, 클래스는 통과.
     *
     * @scenario icon_type=legacy_string, entry=api_create
     *
     * @effects canonical_storage, legacy_icon_exposed
     *
     * @return void 반환값 없음
     */
    public function test_store_legacy_icon_string_accepts_class_only(): void
    {
        $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('legacy-path', ['icon' => '/storage/icons/a.png']))
            ->assertStatus(422)->assertJsonValidationErrors(['icon']);
        $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('legacy-svg', ['icon' => '<svg viewBox="0 0 1 1"></svg>']))
            ->assertStatus(422)->assertJsonValidationErrors(['icon']);
        $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('legacy-arr', ['icon' => ['type' => 'class', 'value' => 'fas fa-users']]))
            ->assertStatus(422)->assertJsonValidationErrors(['icon']);

        $ok = $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('legacy-class', ['icon' => 'fas fa-users']));
        $ok->assertStatus(201);
        $this->assertSame('fas fa-users', Menu::where('slug', 'legacy-class')->value('icon'));
        $this->assertSame('fas fa-users', $ok->json('data.icon'));
    }

    /**
     * icon 과 icon_spec 을 함께 보내면 icon_spec 이 이긴다.
     *
     * @scenario icon_type=class, entry=api_create
     *
     * @effects icon_spec_precedes_icon, canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_store_icon_spec_wins_over_icon(): void
    {
        $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('both', [
            'icon' => 'fas fa-home',
            'icon_spec' => ['type' => 'class', 'value' => 'fa-solid fa-star'],
        ]))->assertStatus(201);

        $this->assertSame('fa-solid fa-star', Menu::where('slug', 'both')->value('icon'));
    }

    /**
     * 업로드한 래스터 아이콘으로 생성하면 첨부가 메뉴에 묶이고 hash 는 서버 값이다.
     *
     * @scenario icon_type=image_attachment, entry=api_create
     *
     * @effects attachment_bound, url_presented
     *
     * @return void 반환값 없음
     */
    public function test_store_binds_uploaded_attachment(): void
    {
        $this->fakeAttachmentDisk();
        $upload = $this->authRequest()->post('/api/admin/menus/icon-upload', [
            'file' => UploadedFile::fake()->image('menu.png', 32, 32),
        ]);
        $upload->assertOk();
        $spec = $upload->json('data.icon_spec');
        $this->assertSame('attachment', $spec['source']);

        $response = $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('att-img', [
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $spec['attachment_id'], 'hash' => 'forged'],
        ]));
        $response->assertStatus(201);

        $menu = Menu::where('slug', 'att-img')->first();
        $attachment = Attachment::find($spec['attachment_id']);
        $this->assertSame(Menu::class, $attachment->attachmentable_type);
        $this->assertSame($menu->id, (int) $attachment->attachmentable_id);
        $this->assertSame($attachment->hash, json_decode($menu->icon, true)['hash']);
        $this->assertStringEndsWith('/api/attachment/'.$attachment->hash, $response->json('data.icon_spec.url'));
    }

    /**
     * 같은 아이콘을 구조형으로 다시 저장해도 사용자 수정 표식이 생기지 않고, 실제로 바꾸면 생긴다.
     *
     * @scenario icon_type=class, icon_type=svg, entry=api_update
     *
     * @effects same_value_not_marked, change_marked
     *
     * @return void 반환값 없음
     */
    public function test_update_icon_override_marks_only_real_changes(): void
    {
        $menu = $this->createTestMenu(['slug' => 'ovr', 'icon' => 'fas fa-users', 'extension_type' => ExtensionOwnerType::Core, 'extension_identifier' => 'core']);

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('ovr', [
            'icon_spec' => ['type' => 'class', 'value' => ' fas  fa-users '],
        ]))->assertOk();
        $this->assertNotContains('icon', $menu->fresh()->user_overrides ?? []);

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('ovr', [
            'icon_spec' => ['type' => 'svg', 'value' => self::SAFE_SVG],
        ]))->assertOk();
        $this->assertContains('icon', $menu->fresh()->user_overrides ?? []);
    }

    /**
     * 확장 선언 이미지(package)는 그대로 되돌려 보내면 통과하고, 바꾸면 422 다.
     *
     * @scenario icon_type=image_package, entry=api_create, entry=api_update
     *
     * @effects package_image_read_only
     *
     * @return void 반환값 없음
     */
    public function test_update_package_image_is_read_only(): void
    {
        $stored = '{"path":"assets/menu.png","source":"package","type":"image"}';
        $menu = $this->createTestMenu(['slug' => 'pkg', 'icon' => $stored, 'extension_type' => ExtensionOwnerType::Module, 'extension_identifier' => 'sirsoft-page']);

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('pkg', [
            'icon_spec' => ['type' => 'image', 'source' => 'package', 'path' => 'assets/menu.png', 'url' => '/x'],
        ]))->assertOk();
        $this->assertSame($stored, Menu::find($menu->id)->getRawOriginal('icon'));

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('pkg', [
            'icon_spec' => ['type' => 'image', 'source' => 'package', 'path' => 'assets/other.png'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['icon_spec']);

        $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('pkg-new', [
            'icon_spec' => ['type' => 'image', 'source' => 'package', 'path' => 'assets/menu.png'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['icon_spec']);
    }

    /**
     * 다른 관리자가 올리고 아직 저장하지 않은(미바인딩) 아이콘 첨부는 ID 를 알아도 내 메뉴에 쓸 수 없다.
     *
     * 게시판 첨부 연결과 같은 규칙 — 미바인딩 첨부는 올린 사람만 묶는다. 막지 않으면 남의 업로드를 가로채
     * 묶었다가 아이콘을 바꿀 때 그 첨부를 지울 수 있다(#381 규정 검토).
     *
     * @scenario icon_type=image_attachment, entry=api_create
     *
     * @effects attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_unbound_attachment_uploaded_by_another_admin(): void
    {
        $this->fakeAttachmentDisk();
        $other = $this->createAdminUser();
        $otherToken = $other->createToken('other')->plainTextToken;
        $spec = $this->withHeaders(['Authorization' => 'Bearer '.$otherToken, 'Accept' => 'application/json'])
            ->post('/api/admin/menus/icon-upload', ['file' => UploadedFile::fake()->image('o.png', 16, 16)])
            ->json('data.icon_spec');
        $this->assertSame('attachment', $spec['source']);

        $this->app['auth']->forgetGuards();
        $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('steal', [
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $spec['attachment_id']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['icon_spec']);

        $this->assertNull(Attachment::find($spec['attachment_id'])->attachmentable_id);
    }

    /**
     * 업로드 아이콘을 바꾸면 이전 첨부가 지워지고, 남의 메뉴에 묶인 첨부는 쓸 수 없다. 메뉴를 지우면 첨부도 지운다.
     *
     * @scenario icon_type=image_attachment, entry=api_update
     *
     * @effects attachment_replaced_deleted, menu_delete_purges_attachment
     *
     * @return void 반환값 없음
     */
    public function test_attachment_lifecycle_on_update_and_delete(): void
    {
        $this->fakeAttachmentDisk();
        $first = $this->authRequest()->post('/api/admin/menus/icon-upload', ['file' => UploadedFile::fake()->image('a.png', 16, 16)])->json('data.icon_spec');
        $second = $this->authRequest()->post('/api/admin/menus/icon-upload', ['file' => UploadedFile::fake()->image('b.png', 16, 16)])->json('data.icon_spec');

        $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('life', [
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $first['attachment_id']],
        ]))->assertStatus(201);
        $menu = Menu::where('slug', 'life')->first();

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('life', [
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $second['attachment_id']],
        ]))->assertOk();
        $this->assertNull(Attachment::find($first['attachment_id']));
        $this->assertNotNull(Attachment::find($second['attachment_id']));

        $other = $this->createTestMenu(['slug' => 'other-menu']);
        $this->authRequest()->putJson("/api/admin/menus/{$other->id}", $this->menuPayload('other-menu', [
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $second['attachment_id']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['icon_spec']);

        $this->authRequest()->deleteJson("/api/admin/menus/{$menu->id}")->assertOk();
        $this->assertNull(Attachment::find($second['attachment_id']));
    }

    /**
     * 아이콘 칸을 모르는 관리자 템플릿이 응답의 `icon: null` 을 그대로 되돌려 보내도 이미지·SVG 아이콘과
     * 업로드 첨부는 지워지지 않고 사용자 수정 표식도 생기지 않는다. 클래스 아이콘의 `icon: null` 은 종전대로 지운다.
     *
     * @scenario icon_type=svg, icon_type=image_attachment, icon_type=legacy_string, entry=api_update
     *
     * @effects legacy_null_keeps_structured_icon
     *
     * @return void 반환값 없음
     */
    public function test_legacy_null_icon_does_not_erase_structured_icon(): void
    {
        $svgStored = json_encode(['type' => 'svg', 'value' => self::SAFE_SVG], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $svgMenu = $this->createTestMenu(['slug' => 'keep-svg', 'icon' => $svgStored]);
        $svgBefore = Menu::find($svgMenu->id)->getRawOriginal('icon');

        $this->authRequest()->putJson("/api/admin/menus/{$svgMenu->id}", $this->menuPayload('keep-svg', [
            'name' => ['ko' => '이름만 바꿈', 'en' => 'Renamed'],
            'icon' => null,
        ]))->assertOk();
        $svgAfter = Menu::find($svgMenu->id);
        $this->assertSame($svgBefore, $svgAfter->getRawOriginal('icon'));
        $this->assertNotContains('icon', $svgAfter->user_overrides ?? []);

        $this->fakeAttachmentDisk();
        $upload = $this->authRequest()->post('/api/admin/menus/icon-upload', ['file' => UploadedFile::fake()->image('k.png', 16, 16)])->json('data.icon_spec');
        $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('keep-img', [
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $upload['attachment_id']],
        ]))->assertStatus(201);
        $imgMenu = Menu::where('slug', 'keep-img')->first();
        $imgBefore = $imgMenu->getRawOriginal('icon');

        $this->authRequest()->putJson("/api/admin/menus/{$imgMenu->id}", $this->menuPayload('keep-img', ['icon' => null]))->assertOk();
        $this->assertSame($imgBefore, Menu::find($imgMenu->id)->getRawOriginal('icon'));
        $this->assertNotNull(Attachment::find($upload['attachment_id']));

        $classMenu = $this->createTestMenu(['slug' => 'clear-class', 'icon' => 'fas fa-users']);
        $this->authRequest()->putJson("/api/admin/menus/{$classMenu->id}", $this->menuPayload('clear-class', ['icon' => null]))->assertOk();
        $this->assertNull(Menu::find($classMenu->id)->getRawOriginal('icon'));

        // 새 템플릿의 「아이콘 지우기」(icon_spec: null)는 이미지·SVG 도 지운다
        $this->authRequest()->putJson("/api/admin/menus/{$svgMenu->id}", $this->menuPayload('keep-svg', ['icon_spec' => null]))->assertOk();
        $this->assertNull(Menu::find($svgMenu->id)->getRawOriginal('icon'));
    }

    /**
     * 예전 API 로 저장된 비클래스 아이콘 문자열이 남은 메뉴도, 아이콘을 그대로 둔 수정(이름만 바꿈)은 저장된다.
     * 새 템플릿(icon_spec 되돌림)·아이콘 칸 모르는 템플릿(icon 되돌림) 두 경로 모두. 값을 바꾸면 종전대로 422.
     *
     * @scenario icon_type=legacy_string, icon_type=class, entry=api_update
     *
     * @effects unchanged_stored_icon_passes
     *
     * @return void 반환값 없음
     */
    public function test_update_keeps_unchanged_legacy_non_class_icon(): void
    {
        $legacy = '<svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>';
        $menu = $this->createTestMenu(['slug' => 'legacy-junk']);
        Menu::whereKey($menu->id)->toBase()->update(['icon' => $legacy]);
        $shown = $this->authRequest()->getJson('/api/admin/menus')->json('data');
        $row = collect($this->collectMenuNodes($shown))->firstWhere('slug', 'legacy-junk');
        $this->assertSame(['type' => 'class', 'value' => $legacy], $row['icon_spec']);

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('legacy-junk', [
            'name' => ['ko' => '이름만 바꿈', 'en' => 'Renamed'],
            'icon_spec' => $row['icon_spec'],
        ]))->assertOk();
        $this->assertSame($legacy, Menu::find($menu->id)->getRawOriginal('icon'));
        $this->assertNotContains('icon', Menu::find($menu->id)->user_overrides ?? []);

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('legacy-junk', [
            'icon' => $row['icon'],
        ]))->assertOk();
        $this->assertSame($legacy, Menu::find($menu->id)->getRawOriginal('icon'));

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('legacy-junk', [
            'icon_spec' => ['type' => 'class', 'value' => 'https://cdn.example.com/x.png'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['icon_spec']);
        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('legacy-junk', [
            'icon' => 'https://cdn.example.com/x.png',
        ]))->assertStatus(422)->assertJsonValidationErrors(['icon']);
    }

    /**
     * 회귀(#381 validate-plan 6차 D-6a): 7.2.0 이하 API 로 저장된 위험 SVG(JSON 모양 문자열)는 응답이 코드를 가린다
     * (`value: null`). 편집 폼은 그 가린 값을 되돌려 보내므로, 이름만 고친 저장이 「SVG 코드 필요」 422 로 막히지 않고
     * 저장값·수정 표식도 그대로여야 한다. 새로 위험 SVG 를 넣는 것은 종전대로 거부한다.
     *
     * @scenario icon_type=svg, entry=api_update
     *
     * @effects unsafe_svg_rejected, unchanged_stored_icon_passes, same_value_not_marked
     *
     * @return void 반환값 없음
     */
    public function test_update_round_trip_of_hidden_unsafe_stored_svg_keeps_icon(): void
    {
        $legacy = json_encode(['type' => 'svg', 'value' => '<svg onload="alert(1)"></svg>'], JSON_UNESCAPED_SLASHES);
        $menu = $this->createTestMenu(['slug' => 'legacy-svg']);
        Menu::whereKey($menu->id)->toBase()->update(['icon' => $legacy]);
        // 사이드바는 내 역할에 지정된 메뉴만 보인다 — 관리자 역할에 read 를 준다
        $menu->roles()->attach(Role::where('identifier', 'admin')->first()->id, ['permission_type' => MenuPermissionType::Read->value]);

        $active = collect($this->authRequest()->getJson('/api/admin/menus/active')->json('data'))->firstWhere('slug', 'legacy-svg');
        $this->assertSame(['type' => 'svg', 'value' => null], $active['icon_spec']);
        $row = collect($this->collectMenuNodes($this->authRequest()->getJson('/api/admin/menus')->json('data')))->firstWhere('slug', 'legacy-svg');
        $this->assertSame(['type' => 'svg', 'value' => null], $row['icon_spec']);

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('legacy-svg', [
            'name' => ['ko' => '이름만 바꿈', 'en' => 'Renamed'],
            'icon_spec' => $row['icon_spec'],
        ]))->assertOk();
        $this->assertSame($legacy, Menu::find($menu->id)->getRawOriginal('icon'));
        $this->assertNotContains('icon', Menu::find($menu->id)->user_overrides ?? []);

        // 가린 값의 되돌림만 통과 — 새 위험 SVG 를 보내면 종전대로 거부(저장값과 같은 문자열의 재전송은 C-4a 되돌림이라 무변경)
        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('legacy-svg', [
            'icon_spec' => ['type' => 'svg', 'value' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(2)</script></svg>'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['icon_spec']);
        $this->assertSame($legacy, Menu::find($menu->id)->getRawOriginal('icon'));
        // 안전한 SVG 메뉴는 value 없는 svg 를 보내면 종전대로 「SVG 코드 필요」(되돌림 예외는 가린 값에만)
        $safe = $this->createTestMenu(['slug' => 'safe-svg']);
        Menu::whereKey($safe->id)->toBase()->update(['icon' => json_encode(['type' => 'svg', 'value' => '<svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>'], JSON_UNESCAPED_SLASHES)]);
        $this->authRequest()->putJson("/api/admin/menus/{$safe->id}", $this->menuPayload('safe-svg', [
            'icon_spec' => ['type' => 'svg', 'value' => null],
        ]))->assertStatus(422)->assertJsonValidationErrors(['icon_spec']);
    }

    /**
     * 회귀(#381 validate-plan 5차 ⓔ): 업로드 아이콘의 첨부가 따로 지워진 메뉴를 이름만 고쳐 저장하면, 되돌려 보낸 아이콘이
     * 규칙은 통과하는데 저장 변환이 첨부를 못 찾아 null 로 바꿔 아이콘이 조용히 지워지고 `icon` 수정 표식이 붙었다
     * (확장 업데이트가 아이콘을 다시 넣지 않음). 되돌림이면 저장값을 그대로 둔다.
     *
     * @scenario icon_type=image_attachment, entry=api_update
     *
     * @effects unchanged_stored_icon_passes, same_value_not_marked
     *
     * @return void 반환값 없음
     */
    public function test_update_round_trip_of_icon_with_deleted_attachment_keeps_icon(): void
    {
        $this->fakeAttachmentDisk();
        $spec = $this->authRequest()->post('/api/admin/menus/icon-upload', ['file' => UploadedFile::fake()->image('gone.png', 16, 16)])->json('data.icon_spec');
        $this->authRequest()->postJson('/api/admin/menus', $this->menuPayload('gone-att', [
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $spec['attachment_id']],
        ]))->assertStatus(201);
        $menu = Menu::where('slug', 'gone-att')->first();
        $stored = $menu->getRawOriginal('icon');
        $row = collect($this->collectMenuNodes($this->authRequest()->getJson('/api/admin/menus')->json('data')))->firstWhere('slug', 'gone-att');

        Attachment::whereKey($spec['attachment_id'])->delete();

        $this->authRequest()->putJson("/api/admin/menus/{$menu->id}", $this->menuPayload('gone-att', [
            'name' => ['ko' => '이름만 바꿈', 'en' => 'Renamed'],
            'icon_spec' => $row['icon_spec'],
        ]))->assertOk();

        $fresh = Menu::find($menu->id);
        $this->assertSame($stored, $fresh->getRawOriginal('icon'));
        $this->assertNotContains('icon', $fresh->user_overrides ?? []);
    }

    /**
     * SVG 파일 업로드는 파일로 저장하지 않고 인라인 svg 코드로 돌려준다.
     *
     * @scenario icon_type=svg, entry=upload
     *
     * @effects svg_upload_not_file
     *
     * @return void 반환값 없음
     */
    public function test_upload_svg_returns_inline_code_without_attachment(): void
    {
        $before = Attachment::count();
        $response = $this->authRequest()->post('/api/admin/menus/icon-upload', [
            'file' => UploadedFile::fake()->createWithContent('icon.svg', self::SAFE_SVG),
        ]);

        $response->assertOk();
        $this->assertSame('svg', $response->json('data.icon_spec.type'));
        $this->assertSame(self::SAFE_SVG, $response->json('data.icon_spec.value'));
        $this->assertSame($before, Attachment::count());
    }

    /**
     * 위험 SVG·1MB 초과·금지 형식·png 로 이름 바꾼 SVG 업로드는 422 다.
     *
     * @scenario icon_type=svg, icon_type=image_attachment, entry=upload
     *
     * @effects unsafe_svg_rejected, upload_file_type_enforced
     *
     * @return void 반환값 없음
     */
    public function test_upload_rejects_invalid_files(): void
    {
        $this->fakeAttachmentDisk();
        $cases = [
            'unsafe_svg' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'too_big' => UploadedFile::fake()->image('big.png', 10, 10)->size(1500),
            'forbidden' => UploadedFile::fake()->createWithContent('x.txt', 'hello'),
            'disguised' => UploadedFile::fake()->createWithContent('x.png', self::SAFE_SVG),
        ];

        foreach ($cases as $label => $file) {
            $response = $this->authRequest()->post('/api/admin/menus/icon-upload', ['file' => $file]);
            $this->assertSame(422, $response->status(), $label.': '.$response->getContent());
        }
        $this->assertSame(0, Attachment::where('collection', 'menu_icons')->count());
    }

    /**
     * 업로드 권한: 메뉴 권한 없음 403, 생성만·수정만 있는 역할은 200.
     *
     * @scenario endpoint=icon_upload, actor=no_permission, actor=partial_permission
     *
     * @effects missing_permission_returns_403, icon_upload_allowed_for_create_or_update_permission
     *
     * @return void 반환값 없음
     */
    public function test_upload_permission_matrix(): void
    {
        $cases = [
            'none' => [['core.menus.read'], 403],
            'create_only' => [['core.menus.create'], 200],
            'update_only' => [['core.menus.update'], 200],
        ];

        foreach ($cases as $label => [$permissions, $expected]) {
            $this->app['auth']->forgetGuards();
            $user = $this->createNonAdminUser($permissions);
            $token = $user->createToken('t')->plainTextToken;
            $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
                ->post('/api/admin/menus/icon-upload', ['file' => UploadedFile::fake()->createWithContent('i.svg', self::SAFE_SVG)]);
            $this->assertSame($expected, $response->status(), $label.': '.$response->getContent());
        }
    }
}
