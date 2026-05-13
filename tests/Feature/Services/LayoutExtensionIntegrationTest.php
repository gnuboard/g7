<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LayoutExtension 통합 테스트
 *
 * 모듈/플러그인 설치 및 제거 시 Extension 등록/삭제 전체 흐름을 테스트합니다.
 */
class LayoutExtensionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private LayoutExtensionService $service;

    private LayoutExtensionRepositoryInterface $repository;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(LayoutExtensionService::class);
        $this->repository = $this->app->make(LayoutExtensionRepositoryInterface::class);
        $this->template = Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'status' => 'active',
        ]);
    }

    /**
     * 모듈 Extension 등록 및 레이아웃 적용 전체 흐름 테스트
     */
    public function test_module_extension_registration_and_layout_application_flow(): void
    {
        $this->mockActiveExtensions(['sirsoft-ecommerce'], []);

        // 1. 모듈이 Extension Point 등록
        $extensionPointData = [
            'extension_point' => 'sidebar-top',
            'components' => [
                [
                    'id' => 'ecommerce-cart-widget',
                    'type' => 'composite',
                    'name' => 'CartWidget',
                    'props' => ['position' => 'sidebar'],
                ],
            ],
            'priority' => 20,
        ];

        $this->service->registerExtension(
            $extensionPointData,
            LayoutSourceType::Module,
            'sirsoft-ecommerce',
            $this->template->id
        );

        $this->assertDatabaseHas('template_layout_extensions', [
            'extension_type' => LayoutExtensionType::ExtensionPoint->value,
            'target_name' => 'sidebar-top',
            'source_identifier' => 'sirsoft-ecommerce',
            'is_active' => true,
        ]);

        // 2. 레이아웃에 Extension Point 적용
        $layout = [
            'layout_name' => 'admin-dashboard',
            'components' => [
                [
                    'id' => 'sidebar',
                    'type' => 'layout',
                    'name' => 'Sidebar',
                    'children' => [
                        [
                            'id' => 'sidebar-top',
                            'type' => 'extension_point',
                            'name' => 'sidebar-top',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 3. Extension이 적용되었는지 확인
        $sidebarTopChildren = $result['components'][0]['children'][0]['children'] ?? [];
        $this->assertCount(1, $sidebarTopChildren);
        $this->assertEquals('ecommerce-cart-widget', $sidebarTopChildren[0]['id']);
    }

    /**
     * 모듈 비활성화 시 Extension soft delete 및 재활성화 시 복원 테스트
     */
    public function test_module_deactivation_and_reactivation_preserves_extensions(): void
    {
        // 1. 모듈 Extension 등록
        $this->service->registerExtension(
            [
                'extension_point' => 'header-actions',
                'components' => [['id' => 'cart-icon', 'type' => 'basic', 'name' => 'Icon']],
            ],
            LayoutSourceType::Module,
            'sirsoft-ecommerce',
            $this->template->id
        );

        $this->assertEquals(1, LayoutExtension::count());

        // 2. 모듈 비활성화 (soft delete)
        $deleted = $this->service->unregisterBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');
        $this->assertEquals(1, $deleted);
        $this->assertEquals(0, LayoutExtension::count());
        $this->assertEquals(1, LayoutExtension::onlyTrashed()->count());

        // 3. 모듈 재활성화 (복원)
        $restored = $this->service->restoreBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');
        $this->assertEquals(1, $restored);
        $this->assertEquals(1, LayoutExtension::count());
    }

    /**
     * Overlay를 통한 기존 컴포넌트에 주입 테스트
     */
    public function test_overlay_injection_into_existing_component(): void
    {
        $this->mockActiveExtensions(['sirsoft-ecommerce'], []);

        // 1. Overlay 등록 (기존 컴포넌트에 자식 추가)
        $overlayData = [
            'target_layout' => 'admin/settings',
            'injections' => [
                [
                    'target_id' => 'settings-form',
                    'position' => 'append_child',
                    'components' => [
                        [
                            'id' => 'ecommerce-settings-section',
                            'type' => 'composite',
                            'name' => 'SettingsSection',
                            'props' => ['title' => 'Ecommerce Settings'],
                        ],
                    ],
                ],
            ],
        ];

        $this->service->registerExtension(
            $overlayData,
            LayoutSourceType::Module,
            'sirsoft-ecommerce',
            $this->template->id
        );

        // 2. 레이아웃에 Overlay 적용
        $layout = [
            'layout_name' => 'admin/settings',
            'components' => [
                [
                    'id' => 'settings-form',
                    'type' => 'composite',
                    'name' => 'Form',
                    'children' => [
                        ['id' => 'general-settings', 'type' => 'basic', 'name' => 'Div'],
                    ],
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 3. Overlay가 적용되었는지 확인
        $formChildren = $result['components'][0]['children'] ?? [];
        $this->assertCount(2, $formChildren);
        $this->assertEquals('ecommerce-settings-section', $formChildren[1]['id']);
    }

    /**
     * 템플릿 오버라이드가 모듈 Extension보다 우선순위가 높은지 테스트
     */
    public function test_template_override_has_higher_priority_than_module(): void
    {
        $this->mockActiveExtensions(['sirsoft-ecommerce'], []);

        // 1. 모듈 Extension 등록
        $this->service->registerExtension(
            [
                'extension_point' => 'sidebar-top',
                'components' => [
                    [
                        'id' => 'module-widget',
                        'type' => 'basic',
                        'name' => 'ModuleWidget',
                    ],
                ],
                'priority' => 50,
            ],
            LayoutSourceType::Module,
            'sirsoft-ecommerce',
            $this->template->id
        );

        // 2. 템플릿 오버라이드 등록 (모듈 Extension을 대체)
        $this->service->registerTemplateOverride(
            [
                'extension_point' => 'sidebar-top',
                'components' => [
                    [
                        'id' => 'template-widget',
                        'type' => 'basic',
                        'name' => 'TemplateWidget',
                    ],
                ],
                'priority' => 10,
            ],
            'sirsoft-admin_basic',
            'sirsoft-ecommerce',
            $this->template->id
        );

        // 3. 레이아웃에 Extension 적용
        $layout = [
            'layout_name' => 'dashboard',
            'components' => [
                [
                    'id' => 'sidebar-top',
                    'type' => 'extension_point',
                    'name' => 'sidebar-top',
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 4. 템플릿 오버라이드만 적용되고 모듈 Extension은 제외됨
        $extensionPointChildren = $result['components'][0]['children'] ?? [];
        $this->assertCount(1, $extensionPointChildren);
        $this->assertEquals('template-widget', $extensionPointChildren[0]['id']);
    }

    /**
     * 다중 모듈의 Extension이 우선순위대로 정렬되는지 테스트
     */
    public function test_multiple_module_extensions_sorted_by_priority(): void
    {
        $this->mockActiveExtensions(
            ['sirsoft-analytics', 'sirsoft-ecommerce'],
            ['sirsoft-social']
        );

        // 1. 여러 모듈의 Extension 등록 (다른 우선순위)
        $this->service->registerExtension(
            [
                'extension_point' => 'footer-widgets',
                'components' => [['id' => 'analytics-widget', 'type' => 'basic', 'name' => 'AnalyticsWidget']],
                'priority' => 30,
            ],
            LayoutSourceType::Module,
            'sirsoft-analytics',
            $this->template->id
        );

        $this->service->registerExtension(
            [
                'extension_point' => 'footer-widgets',
                'components' => [['id' => 'ecommerce-widget', 'type' => 'basic', 'name' => 'EcommerceWidget']],
                'priority' => 10, // 더 높은 우선순위 (낮은 숫자)
            ],
            LayoutSourceType::Module,
            'sirsoft-ecommerce',
            $this->template->id
        );

        $this->service->registerExtension(
            [
                'extension_point' => 'footer-widgets',
                'components' => [['id' => 'social-widget', 'type' => 'basic', 'name' => 'SocialWidget']],
                'priority' => 20,
            ],
            LayoutSourceType::Plugin,
            'sirsoft-social',
            $this->template->id
        );

        // 2. 레이아웃에 Extension 적용
        $layout = [
            'layout_name' => 'footer',
            'components' => [
                [
                    'id' => 'footer-widgets',
                    'type' => 'extension_point',
                    'name' => 'footer-widgets',
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 3. 우선순위 순으로 정렬 확인 (10, 20, 30)
        $widgets = $result['components'][0]['children'] ?? [];
        $this->assertCount(3, $widgets);
        $this->assertEquals('ecommerce-widget', $widgets[0]['id']); // priority 10
        $this->assertEquals('social-widget', $widgets[1]['id']);    // priority 20
        $this->assertEquals('analytics-widget', $widgets[2]['id']); // priority 30
    }

    /**
     * data_sources 병합 테스트
     */
    public function test_data_sources_merged_from_extensions(): void
    {
        $this->mockActiveExtensions(['sirsoft-ecommerce'], []);

        // 1. data_sources가 포함된 Extension 등록
        $this->service->registerExtension(
            [
                'extension_point' => 'dashboard-widgets',
                'components' => [['id' => 'sales-chart', 'type' => 'composite', 'name' => 'SalesChart']],
                'data_sources' => [
                    'salesData' => [
                        'endpoint' => '/api/ecommerce/sales',
                        'method' => 'GET',
                    ],
                ],
            ],
            LayoutSourceType::Module,
            'sirsoft-ecommerce',
            $this->template->id
        );

        // 2. 레이아웃에 Extension 적용
        $layout = [
            'layout_name' => 'dashboard',
            'data_sources' => [
                'users' => ['endpoint' => '/api/users', 'method' => 'GET'],
            ],
            'components' => [
                [
                    'id' => 'dashboard-widgets',
                    'type' => 'extension_point',
                    'name' => 'dashboard-widgets',
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 3. data_sources가 병합되었는지 확인
        $this->assertArrayHasKey('data_sources', $result);
        $this->assertArrayHasKey('users', $result['data_sources']);
        $this->assertArrayHasKey('salesData', $result['data_sources']);
    }

    /**
     * 비활성 Extension이 레이아웃에 적용되지 않는지 테스트
     */
    public function test_inactive_extensions_not_applied_to_layout(): void
    {
        // sirsoft-active 만 활성 — sirsoft-inactive 는 activeModules 에 없음
        $this->mockActiveExtensions(['sirsoft-active'], []);

        // 1. 활성 Extension 등록
        $this->service->registerExtension(
            [
                'extension_point' => 'sidebar-top',
                'components' => [['id' => 'active-widget', 'type' => 'basic', 'name' => 'ActiveWidget']],
            ],
            LayoutSourceType::Module,
            'sirsoft-active',
            $this->template->id
        );

        // 2. 비활성 Extension 생성
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-inactive',
            'content' => ['components' => [['id' => 'inactive-widget', 'type' => 'basic', 'name' => 'InactiveWidget']]],
            'is_active' => false,
        ]);

        // 3. 레이아웃에 Extension 적용
        $layout = [
            'layout_name' => 'sidebar',
            'components' => [
                [
                    'id' => 'sidebar-top',
                    'type' => 'extension_point',
                    'name' => 'sidebar-top',
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 4. 활성 Extension만 적용됨
        $widgets = $result['components'][0]['children'] ?? [];
        $this->assertCount(1, $widgets);
        $this->assertEquals('active-widget', $widgets[0]['id']);
    }

    /**
     * 모듈 완전 삭제 시 Extension 영구 삭제 테스트
     */
    public function test_module_uninstall_permanently_deletes_extensions(): void
    {
        // 1. 모듈 Extension 등록
        $this->service->registerExtension(
            [
                'extension_point' => 'header-actions',
                'components' => [['id' => 'cart-icon', 'type' => 'basic', 'name' => 'Icon']],
            ],
            LayoutSourceType::Module,
            'sirsoft-ecommerce',
            $this->template->id
        );

        // 2. 모듈 비활성화 (soft delete)
        $this->service->unregisterBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');

        // 3. 모듈 완전 제거 (force delete via repository)
        $deleted = $this->repository->forceDeleteBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');

        $this->assertEquals(1, $deleted);
        $this->assertEquals(0, LayoutExtension::withTrashed()->count());
    }

    /**
     * 템플릿 삭제 시 관련 Extension 모두 삭제 테스트
     */
    public function test_template_deletion_cascades_to_extensions(): void
    {
        // 1. Extension 등록
        $this->service->registerExtension(
            [
                'extension_point' => 'header-actions',
                'components' => [['id' => 'widget1', 'type' => 'basic', 'name' => 'Widget']],
            ],
            LayoutSourceType::Module,
            'sirsoft-ecommerce',
            $this->template->id
        );

        $this->service->registerExtension(
            [
                'target_layout' => 'admin/dashboard',
                'injections' => [
                    ['target_id' => 'main', 'position' => 'append_child', 'components' => []],
                ],
            ],
            LayoutSourceType::Plugin,
            'sirsoft-analytics',
            $this->template->id
        );

        $this->assertEquals(2, LayoutExtension::count());

        // 2. 템플릿 삭제
        $this->template->delete();

        // 3. 관련 Extension도 cascade 삭제됨
        $this->assertEquals(0, LayoutExtension::count());
    }

    // =========================================================================
    // inject_props + 섹션 병합 통합 테스트 (C-1 ~ C-7)
    // =========================================================================

    /**
     * ModuleManager/PluginManager를 모킹하여 테스트 식별자를 활성 상태로 설정
     *
     * @param  array  $moduleIdentifiers  활성 모듈 식별자 목록
     * @param  array  $pluginIdentifiers  활성 플러그인 식별자 목록
     */
    private function mockActiveExtensions(array $moduleIdentifiers, array $pluginIdentifiers): void
    {
        $activeModules = [];
        foreach ($moduleIdentifiers as $identifier) {
            $mock = $this->createMock(ModuleInterface::class);
            $mock->method('getIdentifier')->willReturn($identifier);
            $activeModules[$identifier] = $mock;
        }

        $mockModuleManager = $this->createMock(ModuleManager::class);
        $mockModuleManager->method('getActiveModules')->willReturn($activeModules);
        $this->app->instance(ModuleManager::class, $mockModuleManager);

        $activePlugins = [];
        foreach ($pluginIdentifiers as $identifier) {
            $mock = $this->createMock(PluginInterface::class);
            $mock->method('getIdentifier')->willReturn($identifier);
            $activePlugins[$identifier] = $mock;
        }

        $mockPluginManager = $this->createMock(PluginManager::class);
        $mockPluginManager->method('getActivePlugins')->willReturn($activePlugins);
        $this->app->instance(PluginManager::class, $mockPluginManager);

        // 모킹 후 서비스 재생성 (캐시된 활성 모듈 목록 초기화)
        $this->service = $this->app->make(LayoutExtensionService::class);
    }

    /**
     * C-1: inject_props overlay 등록 → 적용 E2E
     */
    public function test_inject_props_registration_and_application_flow(): void
    {
        $this->mockActiveExtensions(['sirsoft-support'], []);

        // 1. inject_props overlay 등록
        $this->service->registerExtension(
            [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [
                                    ['id' => 'ext_verify', 'label' => 'Verification', 'iconName' => 'shield'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            LayoutSourceType::Module,
            'sirsoft-support',
            $this->template->id
        );

        // 2. DB 등록 확인
        $this->assertDatabaseHas('template_layout_extensions', [
            'extension_type' => LayoutExtensionType::Overlay->value,
            'target_name' => 'admin_user_detail',
            'source_identifier' => 'sirsoft-support',
        ]);

        // 3. 레이아웃 적용
        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                [
                    'id' => 'user_tabs',
                    'type' => 'composite',
                    'name' => 'TabNavigation',
                    'props' => [
                        'tabs' => [
                            ['id' => 'basic', 'label' => 'Basic'],
                        ],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $tabs = $result['components'][0]['props']['tabs'];
        $this->assertCount(2, $tabs);
        $this->assertEquals('basic', $tabs[0]['id']);
        $this->assertEquals('ext_verify', $tabs[1]['id']);
    }

    /**
     * C-2: inject_props + append_child 복합 injection
     */
    public function test_inject_props_and_component_injection_combined(): void
    {
        $this->mockActiveExtensions(['sirsoft-support'], []);

        $this->service->registerExtension(
            [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [
                                    ['id' => 'ext_verify', 'label' => 'Verification'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'target_id' => 'tab_content',
                        'position' => 'append_child',
                        'components' => [
                            [
                                'id' => 'ext_verify_content',
                                'type' => 'basic',
                                'name' => 'Div',
                            ],
                        ],
                    ],
                ],
            ],
            LayoutSourceType::Module,
            'sirsoft-support',
            $this->template->id
        );

        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                [
                    'id' => 'user_tabs',
                    'type' => 'composite',
                    'name' => 'TabNavigation',
                    'props' => [
                        'tabs' => [['id' => 'basic']],
                    ],
                ],
                [
                    'id' => 'tab_content',
                    'type' => 'basic',
                    'name' => 'Div',
                    'children' => [],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // inject_props 적용 확인
        $tabs = $result['components'][0]['props']['tabs'];
        $this->assertCount(2, $tabs);
        $this->assertEquals('ext_verify', $tabs[1]['id']);

        // append_child 적용 확인
        $children = $result['components'][1]['children'];
        $this->assertCount(1, $children);
        $this->assertEquals('ext_verify_content', $children[0]['id']);
    }

    /**
     * C-3: 비활성 모듈의 inject_props 미적용
     */
    public function test_inactive_module_inject_props_not_applied(): void
    {
        // 비활성 모듈의 확장 등록
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'nonexistent-module',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [['id' => 'ghost_tab']],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                [
                    'id' => 'user_tabs',
                    'type' => 'composite',
                    'name' => 'TabNavigation',
                    'props' => [
                        'tabs' => [['id' => 'basic']],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 비활성 모듈의 inject_props가 적용되지 않음
        $tabs = $result['components'][0]['props']['tabs'];
        $this->assertCount(1, $tabs);
        $this->assertEquals('basic', $tabs[0]['id']);
    }

    /**
     * C-4: 여러 확장의 inject_props 순차 적용 (priority 순)
     */
    public function test_multiple_inject_props_applied_by_priority_order(): void
    {
        $this->mockActiveExtensions(['sirsoft-support'], ['sirsoft-test']);

        // 모듈 A (priority 100)
        $this->service->registerExtension(
            [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [['id' => 'tab_a']],
                            ],
                        ],
                    ],
                ],
                'priority' => 100,
            ],
            LayoutSourceType::Module,
            'sirsoft-support',
            $this->template->id
        );

        // 모듈 B (priority 200)
        $this->service->registerExtension(
            [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [['id' => 'tab_b']],
                            ],
                        ],
                    ],
                ],
                'priority' => 200,
            ],
            LayoutSourceType::Plugin,
            'sirsoft-test',
            $this->template->id
        );

        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                [
                    'id' => 'user_tabs',
                    'type' => 'composite',
                    'name' => 'TabNavigation',
                    'props' => [
                        'tabs' => [['id' => 'core']],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $tabs = $result['components'][0]['props']['tabs'];
        $this->assertCount(3, $tabs);
        $this->assertEquals('core', $tabs[0]['id']);
        $this->assertEquals('tab_a', $tabs[1]['id']);
        $this->assertEquals('tab_b', $tabs[2]['id']);
    }

    /**
     * C-5: overlay computed + modals 병합 E2E
     */
    public function test_overlay_computed_and_modals_merge_e2e(): void
    {
        $this->mockActiveExtensions(['sirsoft-support'], []);

        $this->service->registerExtension(
            [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'ext_widget', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'computed' => [
                    'extTabCount' => '{{3}}',
                ],
                'modals' => [
                    ['id' => 'ext_confirm_modal', 'type' => 'composite', 'name' => 'Modal'],
                ],
            ],
            LayoutSourceType::Module,
            'sirsoft-support',
            $this->template->id
        );

        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                [
                    'id' => 'container',
                    'type' => 'basic',
                    'name' => 'Div',
                    'children' => [],
                ],
            ],
            'data_sources' => [],
            'computed' => ['userName' => '{{user?.data?.name}}'],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // computed 병합 확인
        $this->assertArrayHasKey('userName', $result['computed']);
        $this->assertArrayHasKey('extTabCount', $result['computed']);

        // modals 병합 확인
        $this->assertArrayHasKey('modals', $result);
        $modalIds = array_column($result['modals'], 'id');
        $this->assertContains('ext_confirm_modal', $modalIds);
    }

    /**
     * C-6: Extension Point modals + Overlay modals 동시 병합
     */
    public function test_extension_point_and_overlay_modals_both_merged(): void
    {
        $this->mockActiveExtensions(['sirsoft-support'], ['sirsoft-payment']);

        // Extension Point 확장 (modals 포함)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'user_detail_extensions',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'extension_point' => 'user_detail_extensions',
                'components' => [
                    ['id' => 'ep_widget', 'type' => 'basic', 'name' => 'Div'],
                ],
                'modals' => [
                    ['id' => 'ep_modal', 'type' => 'composite', 'name' => 'Modal'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // Overlay 확장 (modals 포함)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-payment',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'ov_widget', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'modals' => [
                    ['id' => 'ov_modal', 'type' => 'composite', 'name' => 'Modal'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                [
                    'type' => 'extension_point',
                    'name' => 'user_detail_extensions',
                    'default' => [],
                ],
                [
                    'id' => 'container',
                    'type' => 'basic',
                    'name' => 'Div',
                    'children' => [],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('modals', $result);
        $modalIds = array_column($result['modals'], 'id');
        $this->assertContains('ep_modal', $modalIds);
        $this->assertContains('ov_modal', $modalIds);
    }

    /**
     * C-7: inject_props + overlay state/computed 종합 시나리오
     */
    public function test_inject_props_with_state_and_computed_comprehensive(): void
    {
        $this->mockActiveExtensions(['sirsoft-support'], []);

        $this->service->registerExtension(
            [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [
                                    ['id' => 'ext_verify', 'label' => 'Verify'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'target_id' => 'tab_content',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'verify_content', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'state' => [
                    'verificationData' => null,
                ],
                'computed' => [
                    'isVerified' => '{{user?.data?.identity_verified ?? false}}',
                ],
                'data_sources' => [
                    [
                        'id' => 'verification_info',
                        'type' => 'api',
                        'endpoint' => '/api/verification/{{route.id}}',
                    ],
                ],
            ],
            LayoutSourceType::Module,
            'sirsoft-support',
            $this->template->id
        );

        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                [
                    'id' => 'user_tabs',
                    'type' => 'composite',
                    'name' => 'TabNavigation',
                    'props' => [
                        'tabs' => [['id' => 'basic']],
                    ],
                ],
                [
                    'id' => 'tab_content',
                    'type' => 'basic',
                    'name' => 'Div',
                    'children' => [],
                ],
            ],
            'data_sources' => [
                ['id' => 'user', 'type' => 'api', 'endpoint' => '/api/users/{{route.id}}'],
            ],
            'state' => ['loading' => false],
            'computed' => ['userName' => '{{user?.data?.name}}'],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // inject_props 확인
        $tabs = $result['components'][0]['props']['tabs'];
        $this->assertCount(2, $tabs);
        $this->assertEquals('ext_verify', $tabs[1]['id']);

        // component injection 확인
        $children = $result['components'][1]['children'];
        $this->assertCount(1, $children);
        $this->assertEquals('verify_content', $children[0]['id']);

        // state 병합 확인
        $this->assertFalse($result['state']['loading']);
        $this->assertNull($result['state']['verificationData']);

        // computed 병합 확인
        $this->assertArrayHasKey('userName', $result['computed']);
        $this->assertArrayHasKey('isVerified', $result['computed']);

        // data_sources 병합 확인
        $dsIds = array_column($result['data_sources'], 'id');
        $this->assertContains('user', $dsIds);
        $this->assertContains('verification_info', $dsIds);
    }
}
