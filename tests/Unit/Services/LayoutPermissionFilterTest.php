<?php

namespace Tests\Unit\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LayoutService 컴포넌트별 권한 필터링 테스트
 *
 * 레이아웃 JSON의 컴포넌트에 permissions 속성을 정의하면
 * 서빙 시 사용자 권한에 따라 해당 컴포넌트를 제거합니다.
 */
class LayoutPermissionFilterTest extends TestCase
{
    use RefreshDatabase;

    private LayoutService $layoutService;

    private Role $adminRole;

    private Role $userRole;

    private User $adminUser;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->layoutService = app(LayoutService::class);

        AuthServiceProvider::clearGuestRoleCache();

        // 역할 생성
        $this->adminRole = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '시스템 관리자', 'en' => 'System Administrator'],
        ]);

        $this->userRole = Role::create([
            'identifier' => 'user',
            'name' => ['ko' => '일반 사용자', 'en' => 'Regular User'],
            'description' => ['ko' => '일반 사용자', 'en' => 'Regular User'],
        ]);

        // 권한 생성 및 부여
        $viewPermission = Permission::create([
            'identifier' => 'core.dashboard.view',
            'name' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'],
            'description' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'],
            'type' => 'admin',
        ]);

        $editPermission = Permission::create([
            'identifier' => 'core.dashboard.edit',
            'name' => ['ko' => '대시보드 편집', 'en' => 'Edit Dashboard'],
            'description' => ['ko' => '대시보드 편집', 'en' => 'Edit Dashboard'],
            'type' => 'admin',
        ]);

        // adminRole에 view, edit 권한 부여 (바이패스 없이 명시 할당)
        $this->adminRole->permissions()->attach([
            $viewPermission->id,
            $editPermission->id,
        ]);

        // userRole에 view 권한만 부여
        $this->userRole->permissions()->attach($viewPermission->id);

        // 사용자 생성 및 역할 할당
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
     * permissions 없는 컴포넌트는 항상 포함됩니다.
     */
    public function test_component_without_permissions_is_always_included(): void
    {
        $layout = [
            'components' => [
                ['type' => 'basic', 'name' => 'Div', 'id' => 'container'],
                ['type' => 'basic', 'name' => 'H1', 'text' => '제목'],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(2, $result['components']);
    }

    /**
     * 단일 permissions — 권한 있으면 포함됩니다.
     */
    public function test_component_with_permission_is_included_when_user_has_permission(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'dashboard_widget',
                    'permissions' => ['core.dashboard.view'],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(1, $result['components']);
        $this->assertEquals('dashboard_widget', $result['components'][0]['id']);
    }

    /**
     * 단일 permissions — 권한 없으면 제거됩니다.
     */
    public function test_component_with_permission_is_removed_when_user_lacks_permission(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'admin_widget',
                    'permissions' => ['core.dashboard.edit'],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(0, $result['components']);
    }

    /**
     * 복수 permissions — AND 조건 (모두 충족해야 포함).
     */
    public function test_multiple_permissions_require_all_and_condition(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'restricted',
                    'permissions' => ['core.dashboard.view', 'core.dashboard.edit'],
                ],
            ],
        ];

        // regularUser는 view만 있고 edit는 없으므로 제거
        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);
        $this->assertCount(0, $result['components']);
    }

    /**
     * 중첩 children 재귀 필터링.
     */
    public function test_nested_children_are_recursively_filtered(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'container',
                    'children' => [
                        ['type' => 'basic', 'name' => 'H1', 'text' => '공개'],
                        [
                            'type' => 'basic',
                            'name' => 'Button',
                            'id' => 'edit_btn',
                            'permissions' => ['core.dashboard.edit'],
                            'text' => '수정',
                        ],
                        ['type' => 'basic', 'name' => 'Span', 'text' => '정보'],
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(1, $result['components']);
        $this->assertCount(2, $result['components'][0]['children']);
        $this->assertEquals('공개', $result['components'][0]['children'][0]['text']);
        $this->assertEquals('정보', $result['components'][0]['children'][1]['text']);
    }

    /**
     * 상위 제거 시 하위는 평가하지 않고 전체 제거됩니다.
     */
    public function test_parent_removal_removes_all_children(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'parent',
                    'permissions' => ['core.dashboard.edit'],
                    'children' => [
                        ['type' => 'basic', 'name' => 'H1', 'text' => '자식'],
                        [
                            'type' => 'basic',
                            'name' => 'Button',
                            'permissions' => ['core.dashboard.view'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        // 상위가 edit 권한 필요하므로 전체 제거
        $this->assertCount(0, $result['components']);
    }

    /**
     * 상위 통과 + 하위 독립 평가.
     */
    public function test_parent_passes_child_evaluated_independently(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'parent',
                    'permissions' => ['core.dashboard.view'],
                    'children' => [
                        ['type' => 'basic', 'name' => 'Span', 'text' => '공개 자식'],
                        [
                            'type' => 'basic',
                            'name' => 'Button',
                            'id' => 'edit_btn',
                            'permissions' => ['core.dashboard.edit'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(1, $result['components']);
        $this->assertCount(1, $result['components'][0]['children']);
        $this->assertEquals('공개 자식', $result['components'][0]['children'][0]['text']);
    }

    /**
     * modals 자체에 permissions → 모달 항목 전체 제거.
     */
    public function test_modal_with_permissions_is_removed_when_user_lacks_permission(): void
    {
        $layout = [
            'components' => [],
            'modals' => [
                [
                    'id' => 'public_modal',
                    'components' => [
                        ['type' => 'basic', 'name' => 'Div', 'text' => '공개 모달'],
                    ],
                ],
                [
                    'id' => 'admin_modal',
                    'permissions' => ['core.dashboard.edit'],
                    'components' => [
                        ['type' => 'basic', 'name' => 'Div', 'text' => '관리자 모달'],
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(1, $result['modals']);
        $this->assertEquals('public_modal', $result['modals'][0]['id']);
    }

    /**
     * modals 내부 컴포넌트 필터링.
     */
    public function test_modal_internal_components_are_filtered(): void
    {
        $layout = [
            'components' => [],
            'modals' => [
                [
                    'id' => 'mixed_modal',
                    'components' => [
                        ['type' => 'basic', 'name' => 'Span', 'text' => '공개 내용'],
                        [
                            'type' => 'basic',
                            'name' => 'Button',
                            'id' => 'delete_btn',
                            'permissions' => ['core.dashboard.edit'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(1, $result['modals']);
        $this->assertCount(1, $result['modals'][0]['components']);
        $this->assertEquals('공개 내용', $result['modals'][0]['components'][0]['text']);
    }

    /**
     * defines 내 컴포넌트 필터링.
     */
    public function test_defines_are_filtered(): void
    {
        $layout = [
            'components' => [],
            'defines' => [
                [
                    'id' => 'public_define',
                    'children' => [
                        ['type' => 'basic', 'name' => 'Div', 'text' => '공개'],
                    ],
                ],
                [
                    'id' => 'admin_define',
                    'permissions' => ['core.dashboard.edit'],
                    'children' => [
                        ['type' => 'basic', 'name' => 'Div', 'text' => '관리자'],
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(1, $result['defines']);
        $this->assertEquals('public_define', $result['defines'][0]['id']);
    }

    /**
     * 필터링 후 permissions 속성이 제거되어 클라이언트에 노출되지 않습니다.
     */
    public function test_permissions_attribute_is_removed_after_filtering(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'widget',
                    'permissions' => ['core.dashboard.view'],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'permissions' => ['core.dashboard.view'],
                        ],
                    ],
                ],
            ],
            'modals' => [
                [
                    'id' => 'modal1',
                    'permissions' => ['core.dashboard.view'],
                    'components' => [],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        // 컴포넌트 permissions 속성 제거 확인
        $this->assertArrayNotHasKey('permissions', $result['components'][0]);
        $this->assertArrayNotHasKey('permissions', $result['components'][0]['children'][0]);

        // 모달 permissions 속성 제거 확인
        $this->assertArrayNotHasKey('permissions', $result['modals'][0]);
    }

    /**
     * guest 사용자 (null) 처리.
     */
    public function test_guest_user_filtering(): void
    {
        $layout = [
            'components' => [
                ['type' => 'basic', 'name' => 'Div', 'text' => '공개'],
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'member_only',
                    'permissions' => ['core.dashboard.view'],
                ],
            ],
        ];

        // guest (null) — core.dashboard.view 권한 없음
        $result = $this->layoutService->filterComponentsByPermissions($layout, null);

        $this->assertCount(1, $result['components']);
        $this->assertEquals('공개', $result['components'][0]['text']);
    }

    /**
     * admin 역할 → 명시 할당된 권한의 컴포넌트만 통과.
     */
    public function test_admin_user_passes_assigned_components(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'permissions' => ['core.dashboard.view'],
                ],
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'permissions' => ['core.dashboard.edit'],
                ],
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'permissions' => ['unassigned.permission'],
                ],
            ],
            'modals' => [
                [
                    'id' => 'admin_modal',
                    'permissions' => ['core.dashboard.edit'],
                    'components' => [],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->adminUser);

        // admin에 명시 할당된 view, edit 권한의 컴포넌트만 통과 (unassigned는 필터링)
        $this->assertCount(2, $result['components']);
        $this->assertCount(1, $result['modals']);
    }

    /**
     * 빈 layout (components, modals, defines 없음) 처리.
     */
    public function test_empty_layout_returns_unchanged(): void
    {
        $layout = [
            'meta' => ['title' => 'Test'],
            'data_sources' => [],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertEquals($layout, $result);
    }

    /**
     * 빈 permissions 배열은 무시됩니다 (권한 요구 없음).
     */
    public function test_empty_permissions_array_is_treated_as_no_requirement(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'widget',
                    'permissions' => [],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(1, $result['components']);
    }

    /**
     * 배열 인덱스가 재정렬됩니다 (중간 요소 제거 후).
     */
    public function test_array_indices_are_reindexed_after_filtering(): void
    {
        $layout = [
            'components' => [
                ['type' => 'basic', 'name' => 'Div', 'id' => 'first'],
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'id' => 'removed',
                    'permissions' => ['core.dashboard.edit'],
                ],
                ['type' => 'basic', 'name' => 'Div', 'id' => 'last'],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertCount(2, $result['components']);
        // 연속적인 인덱스 확인 (0, 1)
        $this->assertArrayHasKey(0, $result['components']);
        $this->assertArrayHasKey(1, $result['components']);
        $this->assertEquals('first', $result['components'][0]['id']);
        $this->assertEquals('last', $result['components'][1]['id']);
    }
}
