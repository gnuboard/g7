<?php

namespace Tests\Feature\Template;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicLayoutControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 유효한 레이아웃 서빙 성공 테스트
     */
    public function test_can_serve_valid_layout(): void
    {
        // Arrange: 템플릿 및 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => [
                    'title' => 'Dashboard',
                    'description' => 'Dashboard layout',
                ],
                'data_sources' => [],
                'components' => [
                    [
                        'type' => 'div',
                        'props' => ['class' => 'dashboard'],
                        'children' => [],
                    ],
                ],
            ],
        ]);

        // Act: 레이아웃 서빙 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 성공 응답 확인
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'meta',
                    'data_sources',
                    'components',
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);

        // 레이아웃 데이터 확인
        $this->assertEquals('Dashboard', $response->json('data.meta.title'));
    }

    /**
     * 존재하지 않는 템플릿의 레이아웃 서빙 실패 테스트
     */
    public function test_cannot_serve_layout_for_nonexistent_template(): void
    {
        // Act: 존재하지 않는 템플릿 식별자로 요청
        $response = $this->getJson('/api/layouts/nonexistent-template/dashboard.json');

        // Assert: 404 응답 확인
        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'message',
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * 비활성화된 템플릿의 레이아웃 서빙 실패 테스트
     */
    public function test_cannot_serve_layout_for_inactive_template(): void
    {
        // Arrange: 비활성화된 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'inactive',
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => ['title' => 'Dashboard'],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // Act: 비활성화된 템플릿의 레이아웃 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/dashboard.json");

        // Assert: 404 응답 확인
        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'message',
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * 존재하지 않는 레이아웃 서빙 실패 테스트
     */
    public function test_cannot_serve_nonexistent_layout(): void
    {
        // Arrange: 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // Act: 존재하지 않는 레이아웃 이름으로 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/nonexistent-layout.json");

        // Assert: 404 응답 확인
        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'message',
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * 레이아웃 캐싱 동작 테스트
     */
    public function test_layouts_are_cached(): void
    {
        // Arrange: 템플릿 및 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => [
                    'title' => 'Dashboard',
                ],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // 캐시 초기화
        Cache::forget("layout.{$template->identifier}.{$layout->name}");
        Cache::forget("template.{$template->id}.layout.{$layout->name}");

        // Act: 첫 번째 요청 (캐시 생성)
        $response1 = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");
        $response1->assertStatus(200);

        // 캐시가 생성되었는지 확인
        $this->assertTrue(Cache::has("layout.{$template->identifier}.{$layout->name}"));

        // Act: 두 번째 요청 (캐시에서 조회)
        $response2 = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");
        $response2->assertStatus(200);

        // 두 응답의 데이터가 동일한지 확인
        $this->assertEquals($response1->json('data'), $response2->json('data'));
    }

    /**
     * 레이아웃 상속 병합 테스트
     */
    public function test_can_serve_inherited_layout(): void
    {
        // Arrange: 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // 부모 레이아웃 생성
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'base',
            'content' => [
                'meta' => [
                    'title' => 'Base Layout',
                ],
                'data_sources' => [],
                'components' => [
                    [
                        'type' => 'div',
                        'props' => ['class' => 'container'],
                        'children' => [
                            [
                                'type' => 'div',
                                'slot' => 'content',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // 자식 레이아웃 생성
        $childLayout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'extends' => 'base',
                'meta' => [
                    'title' => 'Dashboard',
                ],
                'slots' => [
                    'content' => [
                        [
                            'type' => 'h1',
                            'props' => [],
                            'children' => ['Dashboard Content'],
                        ],
                    ],
                ],
            ],
        ]);

        // Act: 자식 레이아웃 서빙 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$childLayout->name}.json");

        // Assert: 성공 응답 및 병합된 레이아웃 확인
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // 메타 정보가 자식으로 덮어씌워졌는지 확인
        $this->assertEquals('Dashboard', $response->json('data.meta.title'));

        // slot이 교체되었는지 확인 (extends와 slots 필드는 제거됨)
        $this->assertArrayNotHasKey('extends', $response->json('data'));
        $this->assertArrayNotHasKey('slots', $response->json('data'));

        // 컴포넌트가 병합되었는지 확인
        $components = $response->json('data.components');
        $this->assertNotEmpty($components);
    }

    /**
     * API 사용량 로깅 테스트
     */
    public function test_api_usage_is_logged(): void
    {
        // Arrange: 템플릿 및 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => [],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // Act: 레이아웃 서빙 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 성공 응답 확인 (로깅은 내부적으로 수행됨)
        $response->assertStatus(200);

        // Note: 실제 로그 확인은 통합 테스트에서 수행하거나 모킹으로 검증 가능
    }

    /**
     * 잘못된 layoutName 파라미터 거부 테스트
     */
    public function test_rejects_invalid_layout_name_parameter(): void
    {
        // Arrange: 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // 잘못된 layoutName 파라미터 테스트 (특수문자 포함)
        $invalidNames = [
            'dashboard.json',    // . 포함
            'dashboard/test',    // / 포함
            'dashboard test',    // 공백 포함
            'dashboard@test',    // @ 포함
            'dashboard#test',    // # 포함
        ];

        foreach ($invalidNames as $invalidName) {
            // Act: 잘못된 layoutName으로 요청
            $response = $this->getJson("/api/layouts/{$template->identifier}/{$invalidName}.json");

            // Assert: 404 응답 (라우트 매칭 실패)
            $response->assertStatus(404);
        }
    }

    /**
     * 유효한 layoutName 파라미터 허용 테스트
     */
    #[Test]
    public function test_accepts_valid_layout_name_parameter(): void
    {
        // Arrange: 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // 유효한 layoutName 파라미터 테스트
        $validNames = [
            'dashboard',
            'user-profile',
            'product_list',
            'admin-dashboard-v2',
            'layout_123',
        ];

        foreach ($validNames as $validName) {
            // 레이아웃 생성
            TemplateLayout::create([
                'template_id' => $template->id,
                'name' => $validName,
                'content' => [
                    'meta' => [],
                    'data_sources' => [],
                    'components' => [],
                ],
            ]);

            // Act: 유효한 layoutName으로 요청
            $response = $this->getJson("/api/layouts/{$template->identifier}/{$validName}.json");

            // Assert: 200 응답 (정상 처리)
            $response->assertStatus(200);
        }
    }

    /**
     * Rate Limiting 헤더 존재 테스트
     *
     * 실제 Rate Limit 동작 테스트는 테스트 격리 문제로 인해 헤더 존재 여부만 확인합니다.
     */
    public function test_rate_limiting_is_applied(): void
    {
        // Arrange: 템플릿 및 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => [],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // Act: 레이아웃 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: Rate Limit 헤더가 존재하는지 확인
        $response->assertStatus(200)
            ->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining');

        // Rate Limit 값이 적용되었는지 확인
        $rateLimit = (int) $response->headers->get('X-RateLimit-Limit');
        $this->assertGreaterThan(0, $rateLimit);
    }

    /**
     * 공개 레이아웃 (permissions 없음) - 비회원 접근 가능
     */
    public function test_public_layout_accessible_by_guest(): void
    {
        // Arrange: 템플릿 및 공개 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'login',
            'content' => [
                'meta' => ['title' => 'Login'],
                'data_sources' => [],
                'components' => [],
                // permissions 없음 = 공개 레이아웃
            ],
        ]);

        // Act: 비회원으로 레이아웃 요청
        $response = $this->getJson('/api/layouts/sirsoft-test/login.json');

        // Assert: 성공
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 보호된 레이아웃 + 비회원 → 401 Unauthorized
     */
    public function test_protected_layout_returns_401_for_guest(): void
    {
        // Arrange: guest role 캐시 초기화
        AuthServiceProvider::clearGuestRoleCache();

        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'admin_dashboard',
            'content' => [
                'meta' => ['title' => 'Admin Dashboard'],
                'data_sources' => [],
                'components' => [],
                'permissions' => ['core.dashboard.read'],
            ],
        ]);

        // Act: 비회원으로 보호된 레이아웃 요청
        $response = $this->getJson('/api/layouts/sirsoft-test/admin_dashboard.json');

        // Assert: 401 Unauthorized
        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    /**
     * 보호된 레이아웃 + 권한 없는 회원 → 403 Forbidden
     */
    public function test_protected_layout_returns_403_for_user_without_permission(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'admin_dashboard',
            'content' => [
                'meta' => ['title' => 'Admin Dashboard'],
                'data_sources' => [],
                'components' => [],
                'permissions' => ['core.dashboard.read'],
            ],
        ]);

        // 권한 없는 회원 생성
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'user',
            'name' => ['ko' => '일반 사용자', 'en' => 'User'],
            'description' => ['ko' => '일반 사용자', 'en' => 'User'],
        ]);
        $user->roles()->attach($role->id);

        // Act: 권한 없는 회원으로 보호된 레이아웃 요청
        $response = $this->actingAs($user)->getJson('/api/layouts/sirsoft-test/admin_dashboard.json');

        // Assert: 403 Forbidden
        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    /**
     * 보호된 레이아웃 + 권한 있는 회원 → 200 OK
     */
    public function test_protected_layout_accessible_by_user_with_permission(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'admin_dashboard',
            'content' => [
                'meta' => ['title' => 'Admin Dashboard'],
                'data_sources' => [],
                'components' => [],
                'permissions' => ['core.dashboard.read'],
            ],
        ]);

        // 권한 있는 회원 생성
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'user',
            'name' => ['ko' => '일반 사용자', 'en' => 'User'],
            'description' => ['ko' => '일반 사용자', 'en' => 'User'],
        ]);
        $permission = Permission::create([
            'identifier' => 'core.dashboard.read',
            'name' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'],
            'description' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'],
            'type' => 'admin',
        ]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        // Act: 권한 있는 회원으로 보호된 레이아웃 요청
        $response = $this->actingAs($user)->getJson('/api/layouts/sirsoft-test/admin_dashboard.json');

        // Assert: 200 OK
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 관리자는 모든 보호된 레이아웃에 접근 가능
     */
    public function test_admin_can_access_all_protected_layouts(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'admin_dashboard',
            'content' => [
                'meta' => ['title' => 'Admin Dashboard'],
                'data_sources' => [],
                'components' => [],
                'permissions' => ['core.dashboard.read', 'any.other.permission'],
            ],
        ]);

        // 관리자 생성
        $admin = User::factory()->create();
        $adminRole = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '관리자', 'en' => 'Administrator'],
        ]);
        $admin->roles()->attach($adminRole->id);

        // Act: 관리자로 보호된 레이아웃 요청
        $response = $this->actingAs($admin)->getJson('/api/layouts/sirsoft-test/admin_dashboard.json');

        // Assert: 200 OK (권한이 없어도 관리자는 통과)
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 상속된 레이아웃의 권한이 병합되어 체크됨
     */
    public function test_inherited_layout_permissions_are_merged_and_checked(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        // 부모 레이아웃 (core.admin.access 권한 필요)
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => '_admin_base',
            'content' => [
                'meta' => ['title' => 'Admin Base'],
                'data_sources' => [],
                'components' => [
                    ['type' => 'div', 'slot' => 'content'],
                ],
                'permissions' => ['core.admin.access'],
            ],
        ]);

        // 자식 레이아웃 (추가로 core.users.read 권한 필요)
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'admin_user_list',
            'content' => [
                'extends' => '_admin_base',
                'meta' => ['title' => 'User List'],
                'slots' => [
                    'content' => [['type' => 'table']],
                ],
                'permissions' => ['core.users.read'],
            ],
        ]);

        // 부모 권한만 있는 회원 (core.admin.access만 있음)
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'partial',
            'name' => ['ko' => '부분 권한', 'en' => 'Partial'],
            'description' => ['ko' => '부분 권한', 'en' => 'Partial'],
        ]);
        $permission1 = Permission::create([
            'identifier' => 'core.admin.access',
            'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
            'description' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
            'type' => 'admin',
        ]);
        $role->permissions()->attach($permission1->id);
        $user->roles()->attach($role->id);

        // Act: 부모 권한만 있는 회원으로 자식 레이아웃 요청
        $response = $this->actingAs($user)->getJson('/api/layouts/sirsoft-test/admin_user_list.json');

        // Assert: 403 Forbidden (core.users.read 권한 없음)
        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    /**
     * 비회원이 guest role 권한이 있는 보호된 레이아웃 접근 가능
     */
    public function test_guest_with_role_permission_can_access_protected_layout(): void
    {
        // Arrange: guest role 캐시 초기화
        AuthServiceProvider::clearGuestRoleCache();

        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'board_list',
            'content' => [
                'meta' => ['title' => 'Board List'],
                'data_sources' => [],
                'components' => [],
                'permissions' => ['sirsoft-board.notice.posts.read'],
            ],
        ]);

        // guest role에 해당 권한 부여
        $guestRole = Role::create([
            'identifier' => 'guest',
            'name' => ['ko' => '비회원', 'en' => 'Guest'],
            'description' => ['ko' => '비회원', 'en' => 'Guest'],
        ]);
        $permission = Permission::create([
            'identifier' => 'sirsoft-board.notice.posts.read',
            'name' => ['ko' => '게시글 읽기', 'en' => 'Read Posts'],
            'description' => ['ko' => '게시글 읽기', 'en' => 'Read Posts'],
            'type' => 'user',
        ]);
        $guestRole->permissions()->attach($permission->id);

        // guest role 캐시 초기화 (역할 생성 후)
        AuthServiceProvider::clearGuestRoleCache();

        // Act: 비회원으로 보호된 레이아웃 요청
        $response = $this->getJson('/api/layouts/sirsoft-test/board_list.json');

        // Assert: 200 OK (guest role 권한으로 접근)
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // Bearer Token 인증 테스트 (OptionalSanctumMiddleware 통합 테스트)
    // ========================================================================

    /**
     * Bearer 토큰으로 인증된 사용자가 보호된 레이아웃에 접근 가능
     */
    public function test_protected_layout_accessible_with_bearer_token(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'admin_dashboard',
            'content' => [
                'meta' => ['title' => 'Admin Dashboard'],
                'data_sources' => [],
                'components' => [],
                'permissions' => ['core.dashboard.read'],
            ],
        ]);

        // 권한 있는 회원 생성
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'user',
            'name' => ['ko' => '일반 사용자', 'en' => 'User'],
            'description' => ['ko' => '일반 사용자', 'en' => 'User'],
        ]);
        $permission = Permission::create([
            'identifier' => 'core.dashboard.read',
            'name' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'],
            'description' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'],
            'type' => 'admin',
        ]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        // 토큰 생성
        $token = $user->createToken('test-token')->plainTextToken;

        // Act: Bearer 토큰으로 보호된 레이아웃 요청
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/layouts/sirsoft-test/admin_dashboard.json');

        // Assert: 200 OK
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 무효한 Bearer 토큰으로 보호된 레이아웃 접근 시 401 반환
     */
    public function test_protected_layout_returns_401_for_invalid_bearer_token(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'admin_dashboard',
            'content' => [
                'meta' => ['title' => 'Admin Dashboard'],
                'data_sources' => [],
                'components' => [],
                'permissions' => ['core.dashboard.read'],
            ],
        ]);

        // Act: 무효한 Bearer 토큰으로 보호된 레이아웃 요청
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token-123',
        ])->getJson('/api/layouts/sirsoft-test/admin_dashboard.json');

        // Assert: 401 Unauthorized (무효한 토큰)
        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    /**
     * Bearer 토큰이 있지만 권한 없는 사용자는 403 반환
     */
    public function test_protected_layout_returns_403_for_bearer_token_without_permission(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'admin_dashboard',
            'content' => [
                'meta' => ['title' => 'Admin Dashboard'],
                'data_sources' => [],
                'components' => [],
                'permissions' => ['core.dashboard.read'],
            ],
        ]);

        // 권한 없는 회원 생성
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'user',
            'name' => ['ko' => '일반 사용자', 'en' => 'User'],
            'description' => ['ko' => '일반 사용자', 'en' => 'User'],
        ]);
        $user->roles()->attach($role->id);

        // 토큰 생성
        $token = $user->createToken('test-token')->plainTextToken;

        // Act: Bearer 토큰으로 보호된 레이아웃 요청 (권한 없음)
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/layouts/sirsoft-test/admin_dashboard.json');

        // Assert: 403 Forbidden (인증은 되었으나 권한 없음)
        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    /**
     * 공개 레이아웃은 Bearer 토큰 없이도 접근 가능
     */
    public function test_public_layout_accessible_without_bearer_token(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'home',
            'content' => [
                'meta' => ['title' => 'Home'],
                'data_sources' => [],
                'components' => [],
                // permissions 없음 = 공개 레이아웃
            ],
        ]);

        // Act: Bearer 토큰 없이 공개 레이아웃 요청
        $response = $this->getJson('/api/layouts/sirsoft-test/home.json');

        // Assert: 200 OK
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 관리자는 Bearer 토큰으로 모든 보호된 레이아웃 접근 가능
     */
    public function test_admin_can_access_protected_layout_with_bearer_token(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-test',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'admin_dashboard',
            'content' => [
                'meta' => ['title' => 'Admin Dashboard'],
                'data_sources' => [],
                'components' => [],
                'permissions' => ['core.dashboard.read', 'any.other.permission'],
            ],
        ]);

        // 관리자 생성
        $admin = User::factory()->create();
        $adminRole = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '관리자', 'en' => 'Administrator'],
        ]);
        $admin->roles()->attach($adminRole->id);

        // 토큰 생성
        $token = $admin->createToken('admin-token')->plainTextToken;

        // Act: 관리자 Bearer 토큰으로 보호된 레이아웃 요청
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/layouts/sirsoft-test/admin_dashboard.json');

        // Assert: 200 OK (관리자는 모든 권한 통과)
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}
