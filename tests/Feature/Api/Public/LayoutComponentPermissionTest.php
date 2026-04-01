<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 레이아웃 서빙 API 컴포넌트별 권한 필터링 통합 테스트
 *
 * 레이아웃 서빙 시 컴포넌트의 permissions 속성에 따라
 * 사용자별로 다른 JSON이 서빙되는지 검증합니다.
 */
class LayoutComponentPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Template $template;

    private Role $adminRole;

    private Role $userRole;

    private User $adminUser;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        AuthServiceProvider::clearGuestRoleCache();

        // 템플릿 생성
        $this->template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자', 'en' => 'Basic Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        // 역할 생성
        $this->adminRole = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Admin'],
            'description' => ['ko' => '관리자', 'en' => 'Admin'],
        ]);

        $this->userRole = Role::create([
            'identifier' => 'user',
            'name' => ['ko' => '사용자', 'en' => 'User'],
            'description' => ['ko' => '사용자', 'en' => 'User'],
        ]);

        // 권한 생성
        $viewPermission = Permission::create([
            'identifier' => 'core.dashboard.view',
            'name' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'],
            'description' => ['ko' => '조회', 'en' => 'View'],
            'type' => 'admin',
        ]);

        $editPermission = Permission::create([
            'identifier' => 'core.dashboard.edit',
            'name' => ['ko' => '대시보드 편집', 'en' => 'Edit Dashboard'],
            'description' => ['ko' => '편집', 'en' => 'Edit'],
            'type' => 'admin',
        ]);

        // adminRole에 view, edit 권한 부여 (바이패스 없이 명시 할당)
        $this->adminRole->permissions()->attach([
            $viewPermission->id,
            $editPermission->id,
        ]);

        $this->userRole->permissions()->attach($viewPermission->id);

        // 사용자 생성
        $this->adminUser = User::factory()->create(['name' => 'Admin']);
        $this->adminUser->roles()->attach($this->adminRole->id);

        $this->regularUser = User::factory()->create(['name' => 'Regular']);
        $this->regularUser->roles()->attach($this->userRole->id);

        AuthServiceProvider::clearGuestRoleCache();
    }

    protected function tearDown(): void
    {
        AuthServiceProvider::clearGuestRoleCache();
        parent::tearDown();
    }

    /**
     * admin 사용자는 모든 컴포넌트를 포함한 레이아웃을 받습니다.
     */
    public function test_admin_receives_all_components(): void
    {
        $this->createTestLayout();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/layouts/{$this->template->identifier}/test_dashboard.json");

        $response->assertStatus(200);

        $components = $response->json('data.components');

        // admin은 모든 컴포넌트를 받음
        $this->assertCount(2, $components);

        // permissions 속성은 제거되어야 함
        $this->assertArrayNotHasKey('permissions', $components[0]);
    }

    /**
     * 권한 없는 사용자는 해당 컴포넌트가 제거된 레이아웃을 받습니다.
     */
    public function test_user_without_permission_receives_filtered_components(): void
    {
        $this->createTestLayout();

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/layouts/{$this->template->identifier}/test_dashboard.json");

        $response->assertStatus(200);

        $components = $response->json('data.components');

        // regularUser는 core.dashboard.edit 권한이 없으므로 admin_widget 제거
        $this->assertCount(1, $components);
        $this->assertEquals('public_content', $components[0]['id']);
    }

    /**
     * guest 사용자는 권한이 필요한 모든 컴포넌트가 제거됩니다.
     */
    public function test_guest_receives_only_unrestricted_components(): void
    {
        $this->createTestLayout();

        $response = $this->getJson("/api/layouts/{$this->template->identifier}/test_dashboard.json");

        $response->assertStatus(200);

        $components = $response->json('data.components');

        // guest는 모든 권한이 없으므로 공개 컴포넌트만
        $this->assertCount(1, $components);
        $this->assertEquals('public_content', $components[0]['id']);
    }

    /**
     * 캐시된 레이아웃에서도 사용자별 필터링이 정상 동작합니다.
     */
    public function test_cached_layout_is_filtered_per_user(): void
    {
        $this->createTestLayout();

        // 1. admin으로 먼저 요청 (캐시 생성)
        $adminResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/layouts/{$this->template->identifier}/test_dashboard.json");
        $adminResponse->assertStatus(200);
        $this->assertCount(2, $adminResponse->json('data.components'));

        // 2. regularUser로 같은 레이아웃 요청 (캐시 히트, 하지만 다르게 필터링)
        $userResponse = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/layouts/{$this->template->identifier}/test_dashboard.json");
        $userResponse->assertStatus(200);
        $this->assertCount(1, $userResponse->json('data.components'));
    }

    /**
     * modals 내 컴포넌트도 필터링됩니다.
     */
    public function test_modals_are_filtered_in_served_layout(): void
    {
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'test_modal',
            'content' => [
                'meta' => ['title' => 'Test Modal'],
                'data_sources' => [],
                'components' => [
                    ['type' => 'basic', 'name' => 'Div', 'id' => 'main'],
                ],
                'modals' => [
                    [
                        'id' => 'public_modal',
                        'components' => [
                            ['type' => 'basic', 'name' => 'Div', 'text' => '공개'],
                        ],
                    ],
                    [
                        'id' => 'admin_modal',
                        'permissions' => ['core.dashboard.edit'],
                        'components' => [
                            ['type' => 'basic', 'name' => 'Div', 'text' => '관리자'],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/layouts/{$this->template->identifier}/test_modal.json");

        $response->assertStatus(200);

        $modals = $response->json('data.modals');
        $this->assertCount(1, $modals);
        $this->assertEquals('public_modal', $modals[0]['id']);
    }

    /**
     * 테스트용 레이아웃 생성 헬퍼
     */
    private function createTestLayout(): TemplateLayout
    {
        return TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'test_dashboard',
            'content' => [
                'meta' => ['title' => 'Dashboard'],
                'data_sources' => [],
                'components' => [
                    [
                        'type' => 'basic',
                        'name' => 'Div',
                        'id' => 'public_content',
                        'text' => '공개 콘텐츠',
                    ],
                    [
                        'type' => 'basic',
                        'name' => 'Div',
                        'id' => 'admin_widget',
                        'permissions' => ['core.dashboard.edit'],
                        'children' => [
                            ['type' => 'basic', 'name' => 'H2', 'text' => '관리자 위젯'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
