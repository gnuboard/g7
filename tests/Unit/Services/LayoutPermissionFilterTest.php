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

    /**
     * 역할·권한·사용자 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 게스트 역할 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AuthServiceProvider::clearGuestRoleCache();
        parent::tearDown();
    }

    /**
     * permissions 없는 컴포넌트는 항상 포함됩니다.
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=modal_filter
     *
     * @effects layout_modals_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=modal_filter
     *
     * @effects layout_modals_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=define_filter
     *
     * @effects layout_defines_filtered_by_permission
     *
     * @return void 반환값 없음
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
     * 이름 붙은 defines(`<define name="…">` — 레이아웃이 `_defines.이름` 으로 읽는 형태)는 키를 잃지 않는다. 종전에는 필터가 결과를 목록으로 다시 쌓아(`$filtered[] = …`) 이름이 사라졌고, 화면의 `_defines.countryList` 가 undefined 가 되어 국가 선택지·계정 상태 체크박스가 비었다 (#705 브라우저 실측 회귀). 값이 데이터 목록·스칼라 맵인 define 은 필터 대상이 아니다.
     *
     * @scenario case=define_filter
     *
     * @effects layout_named_defines_preserved
     *
     * @return void 반환값 없음
     */
    public function test_named_defines_keep_their_names_and_values(): void
    {
        $layout = [
            'components' => [],
            'defines' => [
                'countryList' => [
                    ['code' => 'KR', 'name' => '$t:countries.KR'],
                    ['code' => 'JP', 'name' => '$t:countries.JP'],
                ],
                'currencyToFlag' => ['KRW' => 'kr', 'USD' => 'us'],
                'adminOnly' => [
                    'permissions' => ['core.dashboard.edit'],
                    'children' => [['type' => 'basic', 'name' => 'Div', 'text' => '관리자']],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $this->assertSame(['countryList', 'currencyToFlag'], array_keys($result['defines']));
        $this->assertSame('JP', $result['defines']['countryList'][1]['code']);
        $this->assertSame('us', $result['defines']['currencyToFlag']['USD']);
    }

    /**
     * 필터링 후 permissions 속성이 제거되어 클라이언트에 노출되지 않습니다.
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=component_filter
     *
     * @effects layout_components_filtered_by_permission
     *
     * @return void 반환값 없음
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

    /**
     * 실제 모달 형태(`children`)의 자식 컴포넌트 permissions 도 걸러지고 속성이 지워진다 (G25 #9).
     *
     * 번들 모달은 `components` 가 아니라 `children` 을 쓴다. 이전 필터는 `components` 만 재귀해서
     * 모달 자식의 permissions 가 평가도 제거도 되지 않고 클라이언트로 나갔다.
     *
     * @scenario case=modal_children_filter
     *
     * @effects layout_modal_children_filtered_by_permission
     *
     * @return void 반환값 없음
     */
    public function test_modal_children_are_filtered(): void
    {
        $layout = [
            'components' => [],
            'modals' => [
                [
                    'id' => 'children_modal',
                    'type' => 'composite',
                    'name' => 'Modal',
                    'children' => [
                        ['type' => 'basic', 'name' => 'Span', 'text' => '공개'],
                        ['type' => 'basic', 'name' => 'Button', 'id' => 'secret', 'permissions' => ['core.dashboard.edit']],
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $children = $result['modals'][0]['children'];
        $this->assertCount(1, $children);
        $this->assertSame('공개', $children[0]['text']);
    }

    /**
     * `children` 에 컴포넌트 모양이 아닌 원소가 섞여도 형제 컴포넌트의 permissions 는 걸러진다.
     *
     * 원소가 모두 type·name 을 가진 목록만 컴포넌트 트리로 보면, 모양이 다른 원소 하나 때문에 같은
     * 목록의 권한 컴포넌트가 평가도 제거도 되지 않고 무권한자에게 나간다.
     *
     * @scenario case=modal_children_filter
     *
     * @effects layout_children_with_non_component_entry_still_filtered
     *
     * @return void 반환값 없음
     */
    public function test_children_with_non_component_entry_are_still_filtered(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'layout',
                    'name' => 'Container',
                    'children' => [
                        ['type' => 'basic', 'text' => '이름 없는 원소'],
                        ['type' => 'basic', 'name' => 'Button', 'id' => 'secret', 'permissions' => ['core.dashboard.edit']],
                        ['type' => 'basic', 'name' => 'Span', 'id' => 'open'],
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);

        $ids = array_column($result['components'][0]['children'], 'id');
        $this->assertNotContains('secret', $ids);
        $this->assertContains('open', $ids);
        $this->assertCount(2, $result['components'][0]['children']);
    }

    /**
     * 표 셀(`props.columns[].cellChildren`)·노드의 `expandChildren` 안 컴포넌트 permissions 도 걸러진다 (G25 #10).
     *
     * @scenario case=props_embedded_children_filter
     *
     * @effects layout_props_embedded_children_filtered_by_permission
     *
     * @return void 반환값 없음
     */
    public function test_props_embedded_component_lists_are_filtered(): void
    {
        $layout = [
            'components' => [
                [
                    'type' => 'composite',
                    'name' => 'DataGrid',
                    'props' => [
                        'columns' => [
                            [
                                'field' => 'name',
                                'cellChildren' => [
                                    ['type' => 'basic', 'name' => 'Span', 'text' => '이름'],
                                    ['type' => 'basic', 'name' => 'Button', 'id' => 'cell_secret', 'permissions' => ['core.dashboard.edit']],
                                ],
                            ],
                        ],
                    ],
                    'expandChildren' => [
                        ['type' => 'basic', 'name' => 'Button', 'id' => 'expand_secret', 'permissions' => ['core.dashboard.edit']],
                        ['type' => 'basic', 'name' => 'Span', 'text' => '펼침'],
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->filterComponentsByPermissions($layout, $this->regularUser);
        $grid = $result['components'][0];

        $this->assertCount(1, $grid['props']['columns'][0]['cellChildren']);
        $this->assertSame('이름', $grid['props']['columns'][0]['cellChildren'][0]['text']);
        $this->assertCount(1, $grid['expandChildren']);
        $this->assertSame('펼침', $grid['expandChildren'][0]['text']);
        $this->assertStringNotContainsString('core.dashboard.edit', json_encode($result));
    }
}
