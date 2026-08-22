<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LayoutExtension;
use App\Models\Plugin;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * LayoutExtensionService 단위 테스트
 *
 * 동적 UI 주입 시스템(Layout Extension System)의 핵심 서비스 테스트입니다.
 */
class LayoutExtensionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 레이아웃/GDPR 미들웨어 의존 테스트와 migrate:fresh 정합성을
     * 맞추기 위해 GDPR 플러그인 마이그레이션을 일관 선언한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private LayoutExtensionService $service;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트에서 사용하는 모듈/플러그인 식별자를 활성 상태로 모킹
        $this->mockActiveExtensions(
            ['sirsoft-support', 'sirsoft-test', 'sirsoft-extra', 'sirsoft-marketing', 'module-a', 'module-b'],
            ['sirsoft-test', 'sirsoft-payment']
        );

        $this->service = app(LayoutExtensionService::class);

        // 테스트용 템플릿 생성
        $this->template = Template::factory()->create([
            'identifier' => 'test-admin',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

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
        // getModuleInfo 는 편집 모드 출처 메타의 로케일 표시명 해석에 쓰인다.
        // 식별자 → "{식별자} 모듈" 형태의 표시명을 돌려주는 스텁.
        $mockModuleManager->method('getModuleInfo')->willReturnCallback(
            fn (string $id) => isset($activeModules[$id]) ? ['identifier' => $id, 'name' => $id.' 모듈'] : null
        );
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
    }

    /**
     * Extension Point 확장이 올바르게 적용되는지 테스트
     */
    public function test_applies_extension_point_components(): void
    {
        // Extension Point 확장 등록
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'components' => [
                    [
                        'id' => 'support_widget',
                        'type' => 'composite',
                        'name' => 'Card',
                        'props' => ['title' => 'Support'],
                    ],
                ],
                'data_sources' => [
                    ['id' => 'support_stats', 'endpoint' => '/api/stats'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // Extension Point가 포함된 레이아웃
        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'dashboard_widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                    'default' => [],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // Extension Point에 컴포넌트가 주입되었는지 확인
        $this->assertNotEmpty($result['components'][0]['children']);
        $this->assertEquals('support_widget', $result['components'][0]['children'][0]['id']);

        // data_sources가 병합되었는지 확인
        $this->assertCount(1, $result['data_sources']);
        $this->assertEquals('support_stats', $result['data_sources'][0]['id']);
    }

    /**
     * 편집 모드(withSourceMeta=true)에서 주입 노드의 __source 가 출처 타입/식별자를
     * 함께 담아, 편집기 오버레이가 "어느 확장인지" 표시할 수 있게 하는지 검증한다.
     */
    public function test_marks_extension_source_with_type_and_identifier_in_edit_mode(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'components' => [
                    [
                        'id' => 'support_widget',
                        'type' => 'composite',
                        'name' => 'Card',
                        'children' => [
                            ['id' => 'support_child', 'type' => 'basic', 'name' => 'Span'],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'dashboard_widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                    'default' => [],
                ],
            ],
            'data_sources' => [],
        ];

        // withSourceMeta=true 로 편집 모드 출처 메타 부여
        $result = $this->service->applyExtensions($layout, $this->template->id, true);

        $injected = $result['components'][0]['children'][0];
        $this->assertSame('extension', $injected['__source']['kind']);
        $this->assertSame('module', $injected['__source']['extensionSourceType']);
        $this->assertSame('sirsoft-support', $injected['__source']['extensionIdentifier']);
        // 로케일 표시명도 함께 부여 — 오버레이가 "표시명 (식별자)" 로 노출
        $this->assertSame('sirsoft-support 모듈', $injected['__source']['extensionName']);

        // 자식 노드에도 동일 출처 메타가 전파되어야 한다(통짜 잠금/라벨 일관성).
        $child = $injected['children'][0];
        $this->assertSame('sirsoft-support', $child['__source']['extensionIdentifier']);
        $this->assertSame('sirsoft-support 모듈', $child['__source']['extensionName']);
    }

    /**
     * 편집 모드에서 확장이 주입한 data_source 에도 출처 메타(__source)가 부여되어,
     * 데이터 소스 모달이 "어느 확장(모듈/플러그인)이 주입했는지" 표시할 수 있게 한다
     * 일반 렌더는 메타 미부여.
     */
    public function test_marks_extension_source_on_injected_data_sources_in_edit_mode(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'components' => [
                    ['id' => 'support_widget', 'type' => 'composite', 'name' => 'Card', 'props' => ['title' => 'Support']],
                ],
                'data_sources' => [
                    ['id' => 'supportConsent', 'type' => 'api', 'endpoint' => '/api/modules/sirsoft-support/consent'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                ['id' => 'dashboard_widgets', 'type' => 'extension_point', 'name' => 'admin.dashboard.widgets', 'default' => []],
            ],
            'data_sources' => [],
        ];

        // 편집 모드(withSourceMeta=true)
        $edit = $this->service->applyExtensions($layout, $this->template->id, true);
        $ds = collect($edit['data_sources'])->firstWhere('id', 'supportConsent');
        $this->assertNotNull($ds, '주입된 data_source 가 병합되어야 함');
        $this->assertSame('extension', $ds['__source']['kind']);
        $this->assertSame('module', $ds['__source']['extensionSourceType']);
        $this->assertSame('sirsoft-support', $ds['__source']['extensionIdentifier']);

        // 일반 렌더(withSourceMeta=false)는 __source 미부여(운영 화면 영향 0).
        $render = $this->service->applyExtensions($layout, $this->template->id, false);
        $dsPlain = collect($render['data_sources'])->firstWhere('id', 'supportConsent');
        $this->assertNotNull($dsPlain);
        $this->assertArrayNotHasKey('__source', $dsPlain);
    }

    /**
     * 편집 모드(withSourceMeta=true)에서 inject_props 대상 호스트 노드에 `__injectedProps`
     * 메타가 부여되는지 검증한다.
     */
    public function test_marks_injected_props_on_host_node_in_edit_mode(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-marketing',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_detail_tabs',
                        'position' => 'inject_props',
                        'props' => ['tabs' => ['_append' => [['id' => 'ext_tab', 'label' => 'Ext']]]],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                ['id' => 'user_detail_tabs', 'type' => 'composite', 'name' => 'Tabs', 'props' => ['tabs' => []]],
            ],
        ];

        // 편집 모드 — 호스트 노드에 __injectedProps 부여.
        $edit = $this->service->applyExtensions($layout, $this->template->id, true);
        $host = collect($edit['components'])->firstWhere('id', 'user_detail_tabs');
        $this->assertNotNull($host);
        $this->assertArrayHasKey('__injectedProps', $host);
        $this->assertCount(1, $host['__injectedProps']);
        $injected = $host['__injectedProps'][0];
        $this->assertSame('module', $injected['extensionSourceType']);
        $this->assertSame('sirsoft-marketing', $injected['extensionIdentifier']);
        $this->assertSame(
            ['tabs' => ['_append' => [['id' => 'ext_tab', 'label' => 'Ext']]]],
            $injected['props'],
        );
        // 호스트 props 에 실제 주입도 동작(병합) — _append 가 props.tabs 에 반영.
        $this->assertNotEmpty($host['props']['tabs']);

        // 일반 렌더(withSourceMeta=false)는 __injectedProps 미부여(운영 화면 무영향).
        $render = $this->service->applyExtensions($layout, $this->template->id, false);
        $hostPlain = collect($render['components'])->firstWhere('id', 'user_detail_tabs');
        $this->assertArrayNotHasKey('__injectedProps', $hostPlain);
    }

    /**
     * 확장의 호스트 레이아웃 후보 — overlay 는 target_layout 1개, extension_point 는
     * 그 확장점을 포함하는 레이아웃 전체.
     */
    public function test_get_extension_host_layouts_overlay_and_extension_point(): void
    {
        // overlay — target_layout + 편집 가능 컴포넌트를 실재 target_id 에 주입 → 유효 호스트.
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'admin_user_detail',
            'content' => ['components' => [['name' => 'Div', 'props' => ['id' => 'detail_panel']]]],
        ]);
        $overlay = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-marketing',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [['target_id' => 'detail_panel', 'position' => 'append_child', 'components' => [['name' => 'Div']]]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);
        $this->assertSame(['admin_user_detail'], $this->service->getExtensionHostLayouts($overlay));

        // extension_point — 그 확장점을 포함하는 레이아웃 전체 조회(2개 호스트).
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'admin_dashboard',
            'content' => ['components' => [['type' => 'extension_point', 'name' => 'dash.widgets']]],
        ]);
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'admin_home',
            'content' => ['components' => [['type' => 'extension_point', 'name' => 'dash.widgets']]],
        ]);
        $ep = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'dash.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-marketing',
            // extension_point 가 편집 가능 컴포넌트를 실제로 주입해야 시각 편집 호스트로 인정.
            'content' => ['extension_point' => 'dash.widgets', 'components' => [['name' => 'Div']]],
            'priority' => 100,
            'is_active' => true,
        ]);
        $hosts = $this->service->getExtensionHostLayouts($ep);
        $this->assertContains('admin_dashboard', $hosts);
        $this->assertContains('admin_home', $hosts);
        $this->assertCount(2, $hosts);
    }

    /**
     * -1 — 백엔드 게이트는 "주입이 성립하는가" 수준만 판정한다.
     * modals 만 주입하는 extension_point(예: 토스페이먼츠)도 주입은 성립하므로 호스트로 인정해
     * 트리에 노출한다(클릭 시 프론트가 시각 조각 유무를 판정해 디그레이드 안내 — 숨김이 아니라
     * 디그레이드). 완전 빈 content(병합할 payload 없음)만 제외한다.
     */
    public function test_get_extension_host_layouts_extension_point_requires_components(): void
    {
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'shop_checkout',
            'content' => ['components' => [['type' => 'extension_point', 'name' => 'shop.checkout.ext']]],
        ]);

        // components 없이 modals 만 주입 — 주입 성립 → 호스트 인정(트리 노출, 클릭 시 프론트
        // editability 판정으로 디그레이드).
        $modalsOnly = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'shop.checkout.ext',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-tosspayments',
            'content' => [
                'extension_point' => 'shop.checkout.ext',
                'modals' => [['id' => 'toss_modal', 'components' => [['name' => 'Div']]]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);
        $this->assertSame(['shop_checkout'], $this->service->getExtensionHostLayouts($modalsOnly));

        // 병합할 payload 가 전혀 없는 content(components 빈 배열뿐) — 주입 불성립 → 제외.
        $emptyComps = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'shop.checkout.ext',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-empty',
            'content' => ['extension_point' => 'shop.checkout.ext', 'components' => []],
            'priority' => 100,
            'is_active' => true,
        ]);
        $this->assertSame([], $this->service->getExtensionHostLayouts($emptyComps));

        // components 를 실제 주입하면 호스트로 인정.
        $withComps = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'shop.checkout.ext',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-valid',
            'content' => ['extension_point' => 'shop.checkout.ext', 'components' => [['name' => 'Div']]],
            'priority' => 100,
            'is_active' => true,
        ]);
        $this->assertSame(['shop_checkout'], $this->service->getExtensionHostLayouts($withComps));
    }

    /**
     * overlay 의 injection target_id 가 호스트에 실재할 때만 유효 호스트.
     * target_id 가 호스트에 없으면 applyExtensions 가 주입하지 못하므로 호스트 후보에서 제외한다
     * (결제 overlay 가 존재하지 않는 target 을 가리켜 "주입됨"으로 오판되던 결함 방지).
     */
    public function test_get_extension_host_layouts_overlay_requires_existing_target_id(): void
    {
        // 호스트 레이아웃: target_id 노드가 실재.
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'shop/checkout',
            'content' => ['components' => [['name' => 'Div', 'props' => ['id' => 'pay_section']]]],
        ]);

        // target_id 가 호스트에 실재 → 유효 호스트.
        $valid = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'shop/checkout',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-pay_ok',
            'content' => [
                'target_layout' => 'shop/checkout',
                'injections' => [['target_id' => 'pay_section', 'position' => 'append_child', 'components' => [['name' => 'Div']]]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);
        $this->assertSame(['shop/checkout'], $this->service->getExtensionHostLayouts($valid));

        // target_id 가 호스트에 없음 → 주입 불가 → 호스트 후보에서 제외.
        $invalid = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'shop/checkout',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-pay_broken',
            'content' => [
                'target_layout' => 'shop/checkout',
                'injections' => [['target_id' => 'missing_section', 'position' => 'append_child', 'components' => [['name' => 'Div']]]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);
        $this->assertSame([], $this->service->getExtensionHostLayouts($invalid));

        // injection 없이 modals 만 주입하는 overlay(예: 일부 결제 플러그인) — 주입은 성립하므로
        // 호스트 인정.
        $modalsOnly = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'shop/checkout',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-pay_modalsonly',
            'content' => ['target_layout' => 'shop/checkout', 'modals' => [['id' => 'pay_modal']]],
            'priority' => 100,
            'is_active' => true,
        ]);
        $this->assertSame(['shop/checkout'], $this->service->getExtensionHostLayouts($modalsOnly));

        // components 는 있으나 target_id 가 호스트에 없는 injection 만(부수 주입도 없음) → 주입
        // 불성립 → 제외.
        $compsNoTarget = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'shop/checkout',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-pay_compsnotarget',
            'content' => [
                'target_layout' => 'shop/checkout',
                'injections' => [['target_id' => 'nope', 'position' => 'append_child', 'components' => [['name' => 'Div']]]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);
        $this->assertSame([], $this->service->getExtensionHostLayouts($compsNoTarget));
    }

    /**
     * Overlay가 타겟 ID에 올바르게 주입되는지 테스트
     */
    public function test_applies_overlay_at_correct_position(): void
    {
        // Overlay 확장 등록
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-marketing',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'append_child',
                        'components' => [
                            [
                                'id' => 'marketing_tab',
                                'type' => 'composite',
                                'name' => 'Tab',
                                'props' => ['label' => 'Marketing'],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // 타겟 ID가 포함된 레이아웃
        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                [
                    'id' => 'user_tabs',
                    'type' => 'layout',
                    'name' => 'Tabs',
                    'children' => [
                        ['id' => 'basic_info_tab', 'type' => 'composite', 'name' => 'Tab'],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // append_child로 탭이 추가되었는지 확인
        $this->assertCount(2, $result['components'][0]['children']);
        $this->assertEquals('marketing_tab', $result['components'][0]['children'][1]['id']);
    }

    /**
     * Overlay 확장이 호스트 레이아웃 init_actions 뒤에 초기화 액션을 병합한다.
     *
     * 모듈이 호스트(예: _admin_base)가 모르는 _global 초기화(모듈별 표시 통화 복원 등)를
     * layout_extensions 로 기여할 수 있게 한다. 호스트 init_actions 뒤에 순서대로 추가된다.
     */
    public function test_merges_overlay_init_actions_after_host(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-marketing',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'marketing_tab', 'type' => 'composite', 'name' => 'Tab'],
                        ],
                    ],
                ],
                'init_actions' => [
                    ['handler' => 'sirsoft-marketing.initSomething', 'params' => ['x' => 1]],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                ['id' => 'user_tabs', 'type' => 'layout', 'name' => 'Tabs', 'children' => []],
            ],
            'init_actions' => [
                ['handler' => 'initTheme'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 호스트 init 뒤에 확장 init 이 추가됨 (순서 보존).
        // 결과는 표준 키 initActions 로 통합된다 (deprecated init_actions 입력도 표준 키로 흡수).
        $this->assertArrayNotHasKey('init_actions', $result, 'deprecated snake 키가 결과에 잔존하면 안 됨');
        $this->assertCount(2, $result['initActions']);
        $this->assertSame('initTheme', $result['initActions'][0]['handler']);
        $this->assertSame('sirsoft-marketing.initSomething', $result['initActions'][1]['handler']);
    }

    /**
     * 호스트가 표준 키 initActions(camelCase)를 가지고 overlay 가 deprecated init_actions(snake)를
     * 기여할 때, 두 키가 공존하지 않고 표준 initActions 단일 배열로 통합되어야 한다.
     *
     * 회귀: 두 키 공존 시 엔진(TemplateApp)의 `initActions || init_actions` OR 단락이
     * snake 배열을 통째로 무시 → overlay 가 기여한 init_actions(헤더 통화 셀렉터 복원 등)가
     * 실행되지 않아 통화/배송국가 선택기가 자체 initActions 를 가진 화면에서만 미표시되던 결함.
     * (admin_dashboard 는 자체 initActions 가 없어 정상 표시, admin_ecommerce_settings 등
     *  자체 initActions 를 가진 화면은 미표시 — Chrome MCP 실측으로 확인)
     */
    public function test_overlay_snake_init_actions_merge_into_standard_camel_key(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-marketing',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'currency_node', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                // overlay 정의는 deprecated snake 키 사용
                'init_actions' => [
                    ['handler' => 'sirsoft-marketing.initPreferredCurrency'],
                    ['handler' => 'sirsoft-marketing.initPreferredShippingCountry'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // 호스트(자식 레이아웃 병합 결과)는 표준 camel 키를 가진다
        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                ['id' => 'user_tabs', 'type' => 'layout', 'name' => 'Tabs', 'children' => []],
            ],
            'initActions' => [
                ['handler' => 'setState', 'params' => ['target' => 'local', 'q' => '']],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 두 키 공존 금지 — 표준 initActions 단일 배열로 통합
        $this->assertArrayNotHasKey('init_actions', $result, 'snake 키가 별도로 잔존하면 엔진 OR 단락으로 무시됨');
        $this->assertCount(3, $result['initActions'], '호스트 1 + overlay 2 = 3개가 한 배열로 통합되어야 함');
        $this->assertSame('setState', $result['initActions'][0]['handler']);
        $this->assertSame('sirsoft-marketing.initPreferredCurrency', $result['initActions'][1]['handler']);
        $this->assertSame('sirsoft-marketing.initPreferredShippingCountry', $result['initActions'][2]['handler']);
    }

    /**
     * 호스트에 init_actions 가 없어도 확장 init_actions 만으로 배열을 생성한다.
     */
    public function test_merges_overlay_init_actions_when_host_has_none(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-marketing',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [
                    [
                        'target_id' => 'user_tabs',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'marketing_tab', 'type' => 'composite', 'name' => 'Tab'],
                        ],
                    ],
                ],
                'init_actions' => [
                    ['handler' => 'sirsoft-marketing.initSomething'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_user_detail',
            'components' => [
                ['id' => 'user_tabs', 'type' => 'layout', 'name' => 'Tabs', 'children' => []],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 호스트에 init 이 없어도 overlay 의 deprecated snake 입력은 표준 initActions 로 흡수된다.
        $this->assertArrayNotHasKey('init_actions', $result);
        $this->assertCount(1, $result['initActions']);
        $this->assertSame('sirsoft-marketing.initSomething', $result['initActions'][0]['handler']);
    }

    /**
     * 확장 등록 테스트 (Extension Point 타입)
     */
    public function test_registers_extension_point(): void
    {
        $content = [
            'extension_point' => 'admin.dashboard.widgets',
            'priority' => 50,
            'components' => [
                ['id' => 'widget1', 'type' => 'composite', 'name' => 'Card'],
            ],
        ];

        $this->service->registerExtension(
            $content,
            LayoutSourceType::Module,
            'sirsoft-test',
            $this->template->id
        );

        $extension = LayoutExtension::first();

        $this->assertEquals(LayoutExtensionType::ExtensionPoint, $extension->extension_type);
        $this->assertEquals('admin.dashboard.widgets', $extension->target_name);
        $this->assertEquals(LayoutSourceType::Module, $extension->source_type);
        $this->assertEquals('sirsoft-test', $extension->source_identifier);
        $this->assertEquals(50, $extension->priority);
    }

    /**
     * 확장 등록 테스트 (Overlay 타입)
     */
    public function test_registers_overlay(): void
    {
        $content = [
            'target_layout' => 'admin_settings',
            'priority' => 200,
            'injections' => [
                [
                    'target_id' => 'settings_form',
                    'position' => 'append_child',
                    'components' => [],
                ],
            ],
        ];

        $this->service->registerExtension(
            $content,
            LayoutSourceType::Plugin,
            'sirsoft-payment',
            $this->template->id
        );

        $extension = LayoutExtension::first();

        $this->assertEquals(LayoutExtensionType::Overlay, $extension->extension_type);
        $this->assertEquals('admin_settings', $extension->target_name);
        $this->assertEquals(LayoutSourceType::Plugin, $extension->source_type);
        $this->assertEquals(200, $extension->priority);
    }

    /**
     * 출처별 확장 제거 테스트 (Soft Delete)
     */
    public function test_unregisters_by_source(): void
    {
        // 같은 모듈에서 여러 확장 등록
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'point1',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [],
            'is_active' => true,
        ]);

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'layout1',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [],
            'is_active' => true,
        ]);

        // 다른 모듈의 확장 (삭제되면 안 됨)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'point2',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-other',
            'content' => [],
            'is_active' => true,
        ]);

        $deletedCount = $this->service->unregisterBySource(
            LayoutSourceType::Module,
            'sirsoft-test'
        );

        $this->assertEquals(2, $deletedCount);
        $this->assertEquals(1, LayoutExtension::count()); // sirsoft-other만 남음
        $this->assertEquals(2, LayoutExtension::withTrashed()->where('deleted_at', '!=', null)->count());
    }

    /**
     * 출처별 확장 복원 테스트
     */
    public function test_restores_by_source(): void
    {
        // Soft deleted 확장 생성
        $extension = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'point1',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [],
            'is_active' => true,
        ]);
        $extension->delete();

        $this->assertEquals(0, LayoutExtension::count());

        $restoredCount = $this->service->restoreBySource(
            LayoutSourceType::Module,
            'sirsoft-test'
        );

        $this->assertEquals(1, $restoredCount);
        $this->assertEquals(1, LayoutExtension::count());
    }

    /**
     * 템플릿 오버라이드 등록 테스트
     */
    public function test_registers_template_override(): void
    {
        $content = [
            'extension_point' => 'admin.dashboard.widgets',
            'components' => [
                ['id' => 'custom_widget', 'type' => 'composite', 'name' => 'CustomCard'],
            ],
        ];

        $this->service->registerTemplateOverride(
            $content,
            'test-admin',
            'sirsoft-ecommerce',  // 오버라이드 대상 모듈
            $this->template->id
        );

        $extension = LayoutExtension::first();

        $this->assertEquals(LayoutSourceType::Template, $extension->source_type);
        $this->assertEquals('test-admin', $extension->source_identifier);
        $this->assertEquals('sirsoft-ecommerce', $extension->override_target);
    }

    /**
     * 우선순위 순서 테스트
     */
    public function test_extensions_ordered_by_priority(): void
    {
        // 우선순위 200 (나중에)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'module-b',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'components' => [['id' => 'widget_b']],
            ],
            'priority' => 200,
            'is_active' => true,
        ]);

        // 우선순위 50 (먼저)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'module-a',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'components' => [['id' => 'widget_a']],
            ],
            'priority' => 50,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 우선순위가 낮은(50) widget_a가 먼저 와야 함
        $this->assertEquals('widget_a', $result['components'][0]['children'][0]['id']);
        $this->assertEquals('widget_b', $result['components'][0]['children'][1]['id']);
    }

    /**
     * 비활성 확장은 적용되지 않는지 테스트
     */
    public function test_inactive_extensions_not_applied(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'components' => [['id' => 'inactive_widget']],
            ],
            'priority' => 100,
            'is_active' => false,  // 비활성
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 비활성 확장은 주입되지 않음
        $this->assertEmpty($result['components'][0]['children'] ?? []);
    }

    /**
     * Overlay의 prepend 위치 테스트
     */
    public function test_overlay_prepend_position(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'prepend',
                        'components' => [
                            ['id' => 'prepended_component'],
                        ],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'first_component'],
                ['id' => 'target_component'],
                ['id' => 'last_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // prepend: target_component 앞에 삽입
        $this->assertEquals('first_component', $result['components'][0]['id']);
        $this->assertEquals('prepended_component', $result['components'][1]['id']);
        $this->assertEquals('target_component', $result['components'][2]['id']);
    }

    /**
     * Overlay의 replace 위치 테스트
     */
    public function test_overlay_replace_position(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'old_component',
                        'position' => 'replace',
                        'components' => [
                            ['id' => 'new_component'],
                        ],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'first_component'],
                ['id' => 'old_component'],
                ['id' => 'last_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // replace: old_component가 new_component로 대체
        $componentIds = array_column($result['components'], 'id');
        $this->assertContains('new_component', $componentIds);
        $this->assertNotContains('old_component', $componentIds);
    }

    /**
     * data_sources 병합 테스트
     */
    public function test_merges_data_sources_from_extensions(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'components' => [],
                'data_sources' => [
                    ['id' => 'ext_data_1', 'endpoint' => '/api/ext1'],
                    ['id' => 'ext_data_2', 'endpoint' => '/api/ext2'],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                ],
            ],
            'data_sources' => [
                ['id' => 'existing_data', 'endpoint' => '/api/existing'],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertCount(3, $result['data_sources']);
        $dataSourceIds = array_column($result['data_sources'], 'id');
        $this->assertContains('existing_data', $dataSourceIds);
        $this->assertContains('ext_data_1', $dataSourceIds);
        $this->assertContains('ext_data_2', $dataSourceIds);
    }

    /**
     * 활성 모듈/플러그인의 확장이 특정 템플릿에 등록되는지 테스트
     */
    public function test_registers_all_active_extensions_to_template(): void
    {
        // 확장 JSON 파일 생성
        $moduleExtensionFile = tempnam(sys_get_temp_dir(), 'g7_ext_');
        $pluginExtensionFile = tempnam(sys_get_temp_dir(), 'g7_ext_');

        File::put($moduleExtensionFile, json_encode([
            'extension_point' => 'admin.dashboard.widgets',
            'priority' => 50,
            'components' => [
                ['id' => 'module_widget', 'type' => 'composite', 'name' => 'Card'],
            ],
        ]));

        File::put($pluginExtensionFile, json_encode([
            'target_layout' => 'admin_settings',
            'priority' => 100,
            'injections' => [
                ['target_id' => 'settings_form', 'position' => 'append_child', 'components' => []],
            ],
        ]));

        // 모듈 Mock
        $mockModule = $this->createMock(ModuleInterface::class);
        $mockModule->method('getIdentifier')->willReturn('sirsoft-testmodule');
        $mockModule->method('getLayoutExtensions')->willReturn([$moduleExtensionFile]);

        $mockModuleManager = $this->createMock(ModuleManager::class);
        $mockModuleManager->method('getActiveModules')->willReturn(['sirsoft-testmodule' => $mockModule]);

        // 플러그인 Mock
        $mockPlugin = $this->createMock(PluginInterface::class);
        $mockPlugin->method('getIdentifier')->willReturn('sirsoft-testplugin');
        $mockPlugin->method('getLayoutExtensions')->willReturn([$pluginExtensionFile]);

        $mockPluginManager = $this->createMock(PluginManager::class);
        $mockPluginManager->method('getActivePlugins')->willReturn(['sirsoft-testplugin' => $mockPlugin]);

        // 컨테이너에 Mock 바인딩
        $this->app->instance(ModuleManager::class, $mockModuleManager);
        $this->app->instance(PluginManager::class, $mockPluginManager);

        // 새 템플릿 생성
        $newTemplate = Template::factory()->create([
            'identifier' => 'test-new-admin',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $stats = $this->service->registerAllActiveExtensionsToTemplate($newTemplate->id);

        // 모듈 1건, 플러그인 1건 등록 확인
        $this->assertEquals(1, $stats['modules']);
        $this->assertEquals(1, $stats['plugins']);

        // DB에 실제 레코드가 생성되었는지 확인
        $extensions = LayoutExtension::where('template_id', $newTemplate->id)->get();
        $this->assertCount(2, $extensions);

        // 모듈 확장 확인
        $moduleExt = $extensions->firstWhere('source_identifier', 'sirsoft-testmodule');
        $this->assertEquals(LayoutExtensionType::ExtensionPoint, $moduleExt->extension_type);
        $this->assertEquals('admin.dashboard.widgets', $moduleExt->target_name);
        $this->assertEquals(LayoutSourceType::Module, $moduleExt->source_type);

        // 플러그인 확장 확인
        $pluginExt = $extensions->firstWhere('source_identifier', 'sirsoft-testplugin');
        $this->assertEquals(LayoutExtensionType::Overlay, $pluginExt->extension_type);
        $this->assertEquals('admin_settings', $pluginExt->target_name);
        $this->assertEquals(LayoutSourceType::Plugin, $pluginExt->source_type);

        // 임시 파일 정리
        @unlink($moduleExtensionFile);
        @unlink($pluginExtensionFile);
    }

    /**
     * 활성 모듈/플러그인이 없을 때 빈 결과를 반환하는지 테스트
     */
    public function test_registers_all_active_extensions_returns_empty_when_no_active_extensions(): void
    {
        $mockModuleManager = $this->createMock(ModuleManager::class);
        $mockModuleManager->method('getActiveModules')->willReturn([]);

        $mockPluginManager = $this->createMock(PluginManager::class);
        $mockPluginManager->method('getActivePlugins')->willReturn([]);

        $this->app->instance(ModuleManager::class, $mockModuleManager);
        $this->app->instance(PluginManager::class, $mockPluginManager);

        $stats = $this->service->registerAllActiveExtensionsToTemplate($this->template->id);

        $this->assertEquals(0, $stats['modules']);
        $this->assertEquals(0, $stats['plugins']);
        $this->assertEquals(0, LayoutExtension::count());
    }

    /**
     * 확장 파일이 없는 모듈/플러그인은 건너뛰는지 테스트
     */
    public function test_registers_all_active_extensions_skips_extensions_without_files(): void
    {
        // 확장 파일이 없는 모듈
        $mockModule = $this->createMock(ModuleInterface::class);
        $mockModule->method('getIdentifier')->willReturn('sirsoft-noext');
        $mockModule->method('getLayoutExtensions')->willReturn([]);

        $mockModuleManager = $this->createMock(ModuleManager::class);
        $mockModuleManager->method('getActiveModules')->willReturn(['sirsoft-noext' => $mockModule]);

        $mockPluginManager = $this->createMock(PluginManager::class);
        $mockPluginManager->method('getActivePlugins')->willReturn([]);

        $this->app->instance(ModuleManager::class, $mockModuleManager);
        $this->app->instance(PluginManager::class, $mockPluginManager);

        $stats = $this->service->registerAllActiveExtensionsToTemplate($this->template->id);

        $this->assertEquals(0, $stats['modules']);
        $this->assertEquals(0, $stats['plugins']);
        $this->assertEquals(0, LayoutExtension::count());
    }

    /**
     * Extension Point에서 modals가 호스트 레이아웃에 병합되는지 테스트
     */
    public function test_merges_modals_from_extension_point(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'modals' => [
                    [
                        'id' => 'ext_modal_1',
                        'type' => 'composite',
                        'name' => 'Modal',
                        'props' => ['title' => 'Test Modal', 'size' => 'small'],
                        'children' => [],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('modals', $result);
        $this->assertCount(1, $result['modals']);
        $this->assertEquals('ext_modal_1', $result['modals'][0]['id']);
    }

    /**
     * 호스트 레이아웃에 기존 modals가 있을 때 확장 modals가 병합되는지 테스트
     */
    public function test_merges_modals_with_existing_host_modals(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'modals' => [
                    [
                        'id' => 'ext_modal',
                        'type' => 'composite',
                        'name' => 'Modal',
                        'props' => ['title' => 'Extension Modal'],
                        'children' => [],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                ],
            ],
            'data_sources' => [],
            'modals' => [
                [
                    'id' => 'existing_modal',
                    'type' => 'composite',
                    'name' => 'Modal',
                    'props' => ['title' => 'Existing Modal'],
                    'children' => [],
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('modals', $result);
        $this->assertCount(2, $result['modals']);

        $modalIds = array_column($result['modals'], 'id');
        $this->assertContains('existing_modal', $modalIds);
        $this->assertContains('ext_modal', $modalIds);
    }

    /**
     * 확장에 modals가 없을 때 기존 동작이 유지되는지 테스트 (회귀)
     */
    public function test_extension_without_modals_does_not_add_modals_key(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'components' => [
                    ['id' => 'widget_1', 'type' => 'basic', 'name' => 'Div'],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // modals 키가 없어야 함 (확장에 modals가 없으므로)
        $this->assertArrayNotHasKey('modals', $result);

        // 기존 컴포넌트 주입은 정상 동작
        $this->assertNotEmpty($result['components'][0]['children']);
        $this->assertEquals('widget_1', $result['components'][0]['children'][0]['id']);
    }

    /**
     * 여러 확장의 modals가 모두 병합되는지 테스트
     */
    public function test_merges_modals_from_multiple_extensions(): void
    {
        // 첫 번째 확장
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'modals' => [
                    [
                        'id' => 'modal_from_module',
                        'type' => 'composite',
                        'name' => 'Modal',
                        'props' => ['title' => 'Module Modal'],
                        'children' => [],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // 두 번째 확장
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-payment',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'modals' => [
                    [
                        'id' => 'modal_from_plugin',
                        'type' => 'composite',
                        'name' => 'Modal',
                        'props' => ['title' => 'Plugin Modal'],
                        'children' => [],
                    ],
                ],
            ],
            'priority' => 200,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('modals', $result);
        $this->assertCount(2, $result['modals']);

        $modalIds = array_column($result['modals'], 'id');
        $this->assertContains('modal_from_module', $modalIds);
        $this->assertContains('modal_from_plugin', $modalIds);
    }

    // =========================================================================
    // inject_props 단위 테스트 (A-1 ~ A-10)
    // =========================================================================

    /**
     * A-1: _append 배열 병합 — 기존 배열 prop에 항목 추가
     */
    public function test_inject_props_append_adds_items_to_array_prop(): void
    {
        // 타겟 컴포넌트에 기존 tabs 배열이 있는 레이아웃
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'my_tabs',
                    'type' => 'composite',
                    'name' => 'TabNavigation',
                    'props' => [
                        'tabs' => [
                            ['id' => 'basic', 'label' => 'Basic'],
                            ['id' => 'activity', 'label' => 'Activity'],
                        ],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        // inject_props overlay 등록
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'my_tabs',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [
                                    ['id' => 'ext_verify', 'label' => 'Verify'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $tabs = $result['components'][0]['props']['tabs'];
        $this->assertCount(3, $tabs);
        $this->assertEquals('basic', $tabs[0]['id']);
        $this->assertEquals('activity', $tabs[1]['id']);
        $this->assertEquals('ext_verify', $tabs[2]['id']);
    }

    /**
     * A-2: _prepend 배열 병합 — 기존 배열 prop 앞에 항목 추가
     */
    public function test_inject_props_prepend_adds_items_before_array_prop(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'my_list',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => [
                        'items' => [
                            ['id' => 'b'],
                            ['id' => 'c'],
                        ],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'my_list',
                        'position' => 'inject_props',
                        'props' => [
                            'items' => [
                                '_prepend' => [
                                    ['id' => 'a'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $items = $result['components'][0]['props']['items'];
        $this->assertCount(3, $items);
        $this->assertEquals('a', $items[0]['id']);
        $this->assertEquals('b', $items[1]['id']);
        $this->assertEquals('c', $items[2]['id']);
    }

    /**
     * A-3: _merge 객체 병합 — 기존 객체 prop에 키-값 병합
     */
    public function test_inject_props_merge_merges_object_prop(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'my_comp',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => [
                        'style' => ['color' => 'red', 'fontSize' => '12px'],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'my_comp',
                        'position' => 'inject_props',
                        'props' => [
                            'style' => [
                                '_merge' => ['fontWeight' => 'bold', 'color' => 'blue'],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $style = $result['components'][0]['props']['style'];
        $this->assertEquals('blue', $style['color']);
        $this->assertEquals('12px', $style['fontSize']);
        $this->assertEquals('bold', $style['fontWeight']);
    }

    /**
     * A-4: 스칼라 값 덮어쓰기
     */
    public function test_inject_props_scalar_overwrites_value(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'my_btn',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [
                        'disabled' => false,
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'my_btn',
                        'position' => 'inject_props',
                        'props' => [
                            'disabled' => true,
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertTrue($result['components'][0]['props']['disabled']);
    }

    /**
     * A-5: prop 미존재 시 신규 생성 — 빈 배열에 append
     */
    public function test_inject_props_creates_new_prop_when_not_exists(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'my_comp',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => [
                        'className' => 'box',
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'my_comp',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [['id' => 'new']],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $tabs = $result['components'][0]['props']['tabs'];
        $this->assertCount(1, $tabs);
        $this->assertEquals('new', $tabs[0]['id']);
    }

    /**
     * A-6: props 자체가 없는 컴포넌트에 주입
     */
    public function test_inject_props_creates_props_when_component_has_no_props(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'no_props_comp',
                    'type' => 'basic',
                    'name' => 'Div',
                ],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'no_props_comp',
                        'position' => 'inject_props',
                        'props' => [
                            'className' => 'new-class',
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('props', $result['components'][0]);
        $this->assertEquals('new-class', $result['components'][0]['props']['className']);
    }

    /**
     * A-7: 표현식 문자열 대상에 _append 시 경고 로그 + 값 변경 없음
     */
    public function test_inject_props_append_on_expression_string_logs_warning(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'expr_comp',
                    'type' => 'composite',
                    'name' => 'TabNavigation',
                    'props' => [
                        'tabs' => '{{_local.dynamicTabs}}',
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'expr_comp',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [['id' => 'ext']],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'inject_props _append 대상이 표현식 문자열');
            });

        // 다른 로그 호출 허용
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 표현식 문자열 그대로 유지
        $this->assertEquals('{{_local.dynamicTabs}}', $result['components'][0]['props']['tabs']);
    }

    /**
     * A-8: 대상 컴포넌트 미발견 시 warning 로그
     */
    public function test_inject_props_logs_warning_when_target_not_found(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'existing_comp',
                    'type' => 'basic',
                    'name' => 'Div',
                ],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'nonexistent',
                        'position' => 'inject_props',
                        'props' => [
                            'className' => 'test',
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Layout extension target not found'
                    && $context['target_id'] === 'nonexistent'
                    && $context['position'] === 'inject_props';
            });

        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->service->applyExtensions($layout, $this->template->id);
    }

    /**
     * A-9: 깊은 중첩 컴포넌트에 inject_props 주입
     */
    public function test_inject_props_works_on_deeply_nested_component(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'level1',
                    'type' => 'basic',
                    'name' => 'Div',
                    'children' => [
                        [
                            'id' => 'level2',
                            'type' => 'basic',
                            'name' => 'Div',
                            'children' => [
                                [
                                    'id' => 'deep_tabs',
                                    'type' => 'composite',
                                    'name' => 'TabNavigation',
                                    'props' => [
                                        'tabs' => [
                                            ['id' => 'core'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'deep_tabs',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [['id' => 'ext']],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $tabs = $result['components'][0]['children'][0]['children'][0]['props']['tabs'];
        $this->assertCount(2, $tabs);
        $this->assertEquals('core', $tabs[0]['id']);
        $this->assertEquals('ext', $tabs[1]['id']);
    }

    /**
     * A-10: 동일 prop에 _append와 _merge 혼합 시 _append 우선 적용
     */
    public function test_inject_props_append_takes_precedence_over_merge_in_same_prop(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'target_comp',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => [
                        'tabs' => [['id' => 'a']],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'target_comp',
                        'position' => 'inject_props',
                        'props' => [
                            'tabs' => [
                                '_append' => [['id' => 'b']],
                                '_merge' => ['extra' => 'value'],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // _append가 우선 적용 (elseif 체인에서 첫 번째 매칭)
        $tabs = $result['components'][0]['props']['tabs'];
        $this->assertCount(2, $tabs);
        $this->assertEquals('a', $tabs[0]['id']);
        $this->assertEquals('b', $tabs[1]['id']);
    }

    // =========================================================================
    // Overlay 레이아웃 섹션 병합 단위 테스트 (B-1 ~ B-7)
    // =========================================================================

    /**
     * B-1: overlay에 computed 정의 시 레이아웃 computed에 병합
     */
    public function test_overlay_merges_computed_into_layout(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                [
                    'id' => 'container',
                    'type' => 'basic',
                    'name' => 'Div',
                ],
            ],
            'data_sources' => [],
            'computed' => [
                'fullName' => "{{user?.data?.first_name + ' ' + user?.data?.last_name}}",
            ],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'ext_comp', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'computed' => [
                    'tabCount' => '{{3 + (_local.extensionTabs?.length ?? 0)}}',
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('computed', $result);
        $this->assertArrayHasKey('fullName', $result['computed']);
        $this->assertArrayHasKey('tabCount', $result['computed']);
    }

    /**
     * B-2: overlay에 state 정의 시 레이아웃 state에 병합
     */
    public function test_overlay_merges_state_into_layout(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'container', 'type' => 'basic', 'name' => 'Div'],
            ],
            'data_sources' => [],
            'state' => [
                'loading' => false,
            ],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'ext_comp', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'state' => [
                    'extensionData' => null,
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('state', $result);
        $this->assertFalse($result['state']['loading']);
        $this->assertNull($result['state']['extensionData']);
    }

    /**
     * B-3: overlay에 modals 정의 시 레이아웃 modals에 병합
     */
    public function test_overlay_merges_modals_into_layout(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'container', 'type' => 'basic', 'name' => 'Div'],
            ],
            'data_sources' => [],
            'modals' => [
                ['id' => 'modal_a', 'type' => 'composite', 'name' => 'Modal'],
            ],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'ext_comp', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'modals' => [
                    ['id' => 'ext_modal_b', 'type' => 'composite', 'name' => 'Modal'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('modals', $result);
        $this->assertCount(2, $result['modals']);
        $modalIds = array_column($result['modals'], 'id');
        $this->assertContains('modal_a', $modalIds);
        $this->assertContains('ext_modal_b', $modalIds);
    }

    /**
     * B-4: 기존 섹션 없을 때 신규 생성
     */
    public function test_overlay_creates_computed_when_layout_has_none(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'container', 'type' => 'basic', 'name' => 'Div'],
            ],
            'data_sources' => [],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'ext_comp', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'computed' => [
                    'x' => '{{1 + 1}}',
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('computed', $result);
        $this->assertEquals('{{1 + 1}}', $result['computed']['x']);
    }

    /**
     * B-5: 하위 호환 — overlay에 computed/state/modals가 없을 때 기존 레이아웃 영향 없음
     */
    public function test_overlay_without_sections_does_not_affect_layout(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'container', 'type' => 'basic', 'name' => 'Div'],
            ],
            'data_sources' => [],
            'computed' => [
                'a' => '{{1}}',
            ],
        ];

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'ext_comp', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('computed', $result);
        $this->assertCount(1, $result['computed']);
        $this->assertEquals('{{1}}', $result['computed']['a']);
    }

    /**
     * B-6: 여러 overlay에서 동일 섹션 순차 병합
     */
    public function test_overlay_multiple_overlays_merge_computed_sequentially(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'container', 'type' => 'basic', 'name' => 'Div'],
            ],
            'data_sources' => [],
        ];

        // overlay A (priority 100)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'comp_a', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'computed' => [
                    'x' => '{{_local.a}}',
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // overlay B (priority 200)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'comp_b', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'computed' => [
                    'y' => '{{_local.b}}',
                ],
            ],
            'priority' => 200,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertArrayHasKey('computed', $result);
        $this->assertArrayHasKey('x', $result['computed']);
        $this->assertArrayHasKey('y', $result['computed']);
    }

    /**
     * B-7: 동일 키 충돌 시 후순위 overlay 우선
     */
    public function test_overlay_later_priority_overwrites_same_computed_key(): void
    {
        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'container', 'type' => 'basic', 'name' => 'Div'],
            ],
            'data_sources' => [],
        ];

        // overlay A (priority 100) — 먼저 처리
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'comp_a', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'computed' => [
                    'x' => '{{_local.first}}',
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // overlay B (priority 200) — 나중에 처리, 같은 키 덮어씀
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'container',
                        'position' => 'append_child',
                        'components' => [
                            ['id' => 'comp_b', 'type' => 'basic', 'name' => 'Div'],
                        ],
                    ],
                ],
                'computed' => [
                    'x' => '{{_local.second}}',
                ],
            ],
            'priority' => 200,
            'is_active' => true,
        ]);

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $this->assertEquals('{{_local.second}}', $result['computed']['x']);
    }

    /**
     * modals 내부의 extension_point에 플러그인 컴포넌트가 주입되는지 테스트
     */
    public function test_applies_extension_points_inside_modals(): void
    {
        // 플러그인이 address_search_slot extension_point에 등록
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'address_search_slot',
                'components' => [
                    [
                        'id' => 'postcode_button',
                        'type' => 'basic',
                        'name' => 'Button',
                        'props' => ['type' => 'button'],
                        'text' => '주소 검색',
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // 모달 내부에 extension_point가 포함된 레이아웃
        $layout = [
            'layout_name' => 'shop/checkout',
            'components' => [
                ['id' => 'main_content', 'type' => 'basic', 'name' => 'Div'],
            ],
            'data_sources' => [],
            'modals' => [
                [
                    'id' => 'address_modal',
                    'type' => 'composite',
                    'name' => 'Modal',
                    'props' => ['title' => '배송지 관리'],
                    'children' => [
                        [
                            'id' => 'zipcode_row',
                            'type' => 'basic',
                            'name' => 'Div',
                            'children' => [
                                [
                                    'id' => 'modal_address_search_slot',
                                    'type' => 'extension_point',
                                    'name' => 'address_search_slot',
                                    'props' => [
                                        'readOnlyFields' => ['zipcode', 'address'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 모달 내부 extension_point에 플러그인 컴포넌트가 주입되었는지 확인
        $modal = $result['modals'][0];
        $extensionPoint = $modal['children'][0]['children'][0];
        $this->assertEquals('extension_point', $extensionPoint['type']);
        $this->assertNotEmpty($extensionPoint['children']);
        $this->assertEquals('postcode_button', $extensionPoint['children'][0]['id']);
    }

    /**
     * modals 내부 extension_point에 extensionPointProps가 전달되는지 테스트
     */
    public function test_passes_extension_point_props_inside_modals(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'address_search_slot',
                'components' => [
                    [
                        'id' => 'postcode_button',
                        'type' => 'basic',
                        'name' => 'Button',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'shop/checkout',
            'components' => [],
            'data_sources' => [],
            'modals' => [
                [
                    'id' => 'address_modal',
                    'type' => 'composite',
                    'name' => 'Modal',
                    'children' => [
                        [
                            'id' => 'modal_search_slot',
                            'type' => 'extension_point',
                            'name' => 'address_search_slot',
                            'props' => [
                                'readOnlyFields' => ['zipcode', 'address'],
                                'onAddressSelect' => [
                                    'handler' => 'setState',
                                    'params' => ['target' => 'local'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 주입된 컴포넌트에 extensionPointProps가 전달되었는지 확인
        $extensionPoint = $result['modals'][0]['children'][0];
        $injectedComponent = $extensionPoint['children'][0];
        $this->assertArrayHasKey('extensionPointProps', $injectedComponent);
        $this->assertEquals(['zipcode', 'address'], $injectedComponent['extensionPointProps']['readOnlyFields']);
        $this->assertArrayHasKey('onAddressSelect', $injectedComponent['extensionPointProps']);
    }

    /**
     * extension_point의 callbacks가 주입 컴포넌트에 extensionPointCallbacks로 전달되는지 테스트
     */
    public function test_passes_extension_point_callbacks_to_injected_components(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'editor_slot',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'editor_slot',
                'components' => [
                    [
                        'id' => 'editor_container',
                        'type' => 'basic',
                        'name' => 'Div',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_form',
            'components' => [
                [
                    'id' => 'form_editor_slot',
                    'type' => 'extension_point',
                    'name' => 'editor_slot',
                    'props' => [
                        'readOnlyFields' => ['content'],
                    ],
                    'callbacks' => [
                        'onContentChange' => [
                            'handler' => 'setState',
                            'params' => ['target' => 'local', 'form.content' => '{{$event}}'],
                        ],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $injectedComponent = $result['components'][0]['children'][0];

        // props가 extensionPointProps로 전달되었는지 확인
        $this->assertArrayHasKey('extensionPointProps', $injectedComponent);
        $this->assertEquals(['content'], $injectedComponent['extensionPointProps']['readOnlyFields']);

        // callbacks가 extensionPointCallbacks로 전달되었는지 확인
        $this->assertArrayHasKey('extensionPointCallbacks', $injectedComponent);
        $this->assertEquals('setState', $injectedComponent['extensionPointCallbacks']['onContentChange']['handler']);
        $this->assertEquals('{{$event}}', $injectedComponent['extensionPointCallbacks']['onContentChange']['params']['form.content']);
    }

    /**
     * callbacks가 없는 extension_point에서 extensionPointCallbacks가 생성되지 않는지 테스트
     */
    public function test_no_callbacks_key_when_extension_point_has_no_callbacks(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'simple_slot',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'simple_slot',
                'components' => [
                    ['id' => 'simple_component', 'type' => 'basic', 'name' => 'Div'],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_page',
            'components' => [
                [
                    'id' => 'simple_ext_slot',
                    'type' => 'extension_point',
                    'name' => 'simple_slot',
                    'props' => ['label' => 'test'],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $injectedComponent = $result['components'][0]['children'][0];
        $this->assertArrayHasKey('extensionPointProps', $injectedComponent);
        $this->assertArrayNotHasKey('extensionPointCallbacks', $injectedComponent);
    }

    /**
     * components와 modals 양쪽에 동일 이름의 extension_point가 있을 때 모두 주입되는지 테스트
     */
    public function test_injects_into_both_components_and_modals_extension_points(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'extension_point' => 'address_search_slot',
                'components' => [
                    [
                        'id' => 'search_button',
                        'type' => 'basic',
                        'name' => 'Button',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'shop/checkout',
            'components' => [
                [
                    'id' => 'checkout_search_slot',
                    'type' => 'extension_point',
                    'name' => 'address_search_slot',
                ],
            ],
            'data_sources' => [],
            'modals' => [
                [
                    'id' => 'address_modal',
                    'type' => 'composite',
                    'name' => 'Modal',
                    'children' => [
                        [
                            'id' => 'modal_search_slot',
                            'type' => 'extension_point',
                            'name' => 'address_search_slot',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // components 내 extension_point에 주입 확인
        $componentsEP = $result['components'][0];
        $this->assertNotEmpty($componentsEP['children']);
        $this->assertEquals('search_button', $componentsEP['children'][0]['id']);

        // modals 내 extension_point에도 주입 확인
        $modalsEP = $result['modals'][0]['children'][0];
        $this->assertNotEmpty($modalsEP['children']);
        $this->assertEquals('search_button', $modalsEP['children'][0]['id']);
    }

    /**
     * Extension Point mode: replace — default를 완전 교체하는지 테스트
     */
    public function test_extension_point_mode_replace_removes_default(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'mode' => 'replace',
                'components' => [
                    ['id' => 'replacement_widget', 'type' => 'basic', 'name' => 'Div'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'dashboard_widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                    'default' => [
                        ['id' => 'fallback_message', 'type' => 'basic', 'name' => 'Div'],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $children = $result['components'][0]['children'];
        $childIds = array_column($children, 'id');

        $this->assertContains('replacement_widget', $childIds);
        $this->assertNotContains('fallback_message', $childIds, 'replace 모드에서 default가 제거되어야 함');
    }

    /**
     * Extension Point mode: prepend — default 앞에 추가되는지 테스트
     */
    public function test_extension_point_mode_prepend_inserts_before_default(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'mode' => 'prepend',
                'components' => [
                    ['id' => 'prepended_widget', 'type' => 'basic', 'name' => 'Div'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'dashboard_widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                    'default' => [
                        ['id' => 'default_widget', 'type' => 'basic', 'name' => 'Div'],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $children = $result['components'][0]['children'];
        $childIds = array_column($children, 'id');

        $this->assertEquals('prepended_widget', $childIds[0], 'prepend 모드에서 확장이 앞에 와야 함');
        $this->assertEquals('default_widget', $childIds[1], 'prepend 모드에서 default가 뒤에 유지되어야 함');
    }

    /**
     * Extension Point mode 미지정 시 기본 동작 (append) 테스트
     */
    public function test_extension_point_default_mode_appends_after_default(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'extension_point' => 'admin.dashboard.widgets',
                'components' => [
                    ['id' => 'appended_widget', 'type' => 'basic', 'name' => 'Div'],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'admin_dashboard',
            'components' => [
                [
                    'id' => 'dashboard_widgets',
                    'type' => 'extension_point',
                    'name' => 'admin.dashboard.widgets',
                    'default' => [
                        ['id' => 'default_widget', 'type' => 'basic', 'name' => 'Div'],
                    ],
                ],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $children = $result['components'][0]['children'];
        $childIds = array_column($children, 'id');

        $this->assertEquals('default_widget', $childIds[0], 'append 모드에서 default가 앞에 유지되어야 함');
        $this->assertEquals('appended_widget', $childIds[1], 'append 모드에서 확장이 뒤에 와야 함');
    }

    /**
     * 확장 출처명(트리 `🔌 {sourceLabel}`)은 DB 레코드의 다국어 name 을
     * 우선 사용한다. 설치/동기화 시 manifest.translations 필터가 ja 를 DB name 에 병합하므로,
     * 매니페스트 객체(ko/en 만 보유)가 아니라 DB name 을 읽어야 ja locale 에서 일본어로 표시된다.
     */
    public function test_resolve_source_label_reads_ja_from_db_name(): void
    {
        Plugin::factory()->create([
            'identifier' => 'sirsoft-gdpr',
            'name' => [
                'ko' => 'GDPR (일반 데이터 보호 규정)',
                'en' => 'GDPR (General Data Protection Regulation)',
                'ja' => 'GDPR (一般データ保護規則)',
            ],
            'status' => ExtensionStatus::Active->value,
        ]);

        $ref = new \ReflectionMethod($this->service, 'resolveSourceLabel');
        $ref->setAccessible(true);

        app()->setLocale('ja');
        $this->assertSame(
            'GDPR (一般データ保護規則)',
            $ref->invoke($this->service, LayoutSourceType::Plugin, 'sirsoft-gdpr'),
            'ja locale 에서 DB name 의 ja 가 표시돼야 함 (manifest ko 폴백 금지)'
        );

        app()->setLocale('ko');
        $this->assertSame(
            'GDPR (일반 데이터 보호 규정)',
            $ref->invoke($this->service, LayoutSourceType::Plugin, 'sirsoft-gdpr')
        );

        app()->setLocale('en');
        $this->assertSame(
            'GDPR (General Data Protection Regulation)',
            $ref->invoke($this->service, LayoutSourceType::Plugin, 'sirsoft-gdpr')
        );
    }

    /**
     * DB 레코드가 없으면 매니페스트 객체 getName() 으로 폴백한다(역호환, 크래시 없음).
     */
    public function test_resolve_source_label_falls_back_to_manifest_when_no_db_row(): void
    {
        $ref = new \ReflectionMethod($this->service, 'resolveSourceLabel');
        $ref->setAccessible(true);

        app()->setLocale('ja');
        $label = $ref->invoke($this->service, LayoutSourceType::Plugin, 'sirsoft-payment');

        $this->assertIsString($label);
        $this->assertNotSame('', $label);
    }
}
