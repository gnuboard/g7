<?php

namespace Tests\Feature\Api\Public;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 레이아웃 서빙 빈 객체 보존 테스트
 *
 * GET /api/layouts/{template}/{layout}.json
 *
 * 원본·DB 행이 `{}` 인 자리가 응답에서 `[]` 로 바뀌면 화면이 키-값 상태를 배열로 받는다
 * (7.1.0 실측 — 게시판 환경설정 `_local.expandedNotifications` 가 배열).
 *
 * 트러블슈팅 `[case:backend-52]` 의 회귀 그물이다.
 */
class LayoutServingEmptyObjectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 레이아웃 서빙 경로가 GDPR 미들웨어를 거치므로 그 마이그레이션을 포함한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    /**
     * 활성 템플릿을 만듭니다.
     *
     * @return Template 템플릿
     */
    private function createTemplate(): Template
    {
        return Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);
    }

    /**
     * 부모·자식 병합 뒤에도 상태 초기값·대체값·액션 인자의 빈 객체가 `{}` 로 나간다.
     *
     * @scenario position=init_state_value
     *
     * @effects init_state_empty_object_preserved, fallback_empty_object_preserved, action_params_empty_object_preserved, empty_list_stays_list, empty_object_served_as_object, empty_array_served_as_array
     *
     * @return void 반환값 없음
     */
    public function test_merged_layout_keeps_empty_objects_in_value_positions(): void
    {
        $template = $this->createTemplate();

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => '_base',
            'content' => [
                'meta' => ['title' => 'Base'],
                'initLocal' => ['openVisits' => new \stdClass],
                'components' => [['id' => 'slot_root', 'type' => 'basic', 'name' => 'Div', 'slot' => 'content']],
            ],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'settings_page',
            'content' => [
                'extends' => '_base',
                'initLocal' => [
                    'expandedNotifications' => new \stdClass,
                    'selectedIds' => [],
                ],
                'data_sources' => [[
                    'id' => 'stats',
                    'type' => 'api',
                    'endpoint' => '/api/stats',
                    'fallback' => ['data' => ['targets' => new \stdClass]],
                ]],
                'slots' => [
                    'content' => [[
                        'id' => 'reset_button',
                        'type' => 'basic',
                        'name' => 'Button',
                        'actions' => [[
                            'type' => 'click',
                            'handler' => 'navigate',
                            'params' => ['path' => '/admin', 'query' => new \stdClass],
                        ]],
                    ]],
                ],
            ],
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertOk();
        $data = json_decode($response->getContent())->data;

        $this->assertEquals(new \stdClass, $data->initLocal->expandedNotifications, '자식 initLocal 빈 객체');
        $this->assertEquals(new \stdClass, $data->initLocal->openVisits, '부모 initLocal 빈 객체');
        $this->assertSame([], $data->initLocal->selectedIds, '빈 배열은 배열 그대로');
        $this->assertEquals(new \stdClass, $data->data_sources[0]->fallback->data->targets);
        $this->assertStringContainsString('"query":{}', $response->getContent());
    }

    /**
     * 비어 있는 루트 맵 섹션은 `{}` 로 나간다.
     *
     * @scenario position=root_section
     *
     * @effects root_map_section_restored
     *
     * @return void 반환값 없음
     */
    public function test_empty_root_map_sections_are_served_as_objects(): void
    {
        $template = $this->createTemplate();

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'plain_page',
            'content' => [
                'meta' => new \stdClass,
                'initLocal' => new \stdClass,
                'data_sources' => [],
                'components' => [['id' => 'root', 'type' => 'basic', 'name' => 'Div']],
            ],
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertOk();
        $data = json_decode($response->getContent())->data;

        $this->assertEquals(new \stdClass, $data->meta);
        $this->assertEquals(new \stdClass, $data->initLocal);
        $this->assertSame([], $data->data_sources, '목록 섹션은 배열 그대로');
    }

    /**
     * 확장 조각이 얹은 노드의 액션 인자 빈 객체도 `{}` 로 나간다.
     *
     * @scenario position=params_fallback_value
     *
     * @effects action_params_empty_object_preserved
     *
     * @return void 반환값 없음
     */
    public function test_overlay_injected_node_keeps_empty_object_params(): void
    {
        $activeModule = $this->createMock(ModuleInterface::class);
        $activeModule->method('getIdentifier')->willReturn('sirsoft-ecommerce');
        $mockModuleManager = $this->createMock(ModuleManager::class);
        $mockModuleManager->method('getActiveModules')->willReturn(['sirsoft-ecommerce' => $activeModule]);
        $this->app->instance(ModuleManager::class, $mockModuleManager);

        $template = $this->createTemplate();

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'host_with_extension',
            'content' => [
                'layout_name' => 'host_with_extension',
                'meta' => ['title' => 'Host Layout'],
                'data_sources' => [],
                'components' => [
                    ['id' => 'host_anchor', 'type' => 'basic', 'name' => 'Div', 'children' => []],
                ],
            ],
            'is_active' => true,
        ]);

        LayoutExtension::create([
            'template_id' => $template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'host_with_extension',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'host_with_extension',
                'injections' => [[
                    'target_id' => 'host_anchor',
                    'position' => 'append_child',
                    'components' => [[
                        'id' => 'ext_injected',
                        'type' => 'basic',
                        'name' => 'Button',
                        'actions' => [[
                            'type' => 'click',
                            'handler' => 'setState',
                            'params' => ['target' => 'local', 'selection' => new \stdClass],
                        ]],
                    ]],
                ]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('ext_injected', $body, '확장 조각이 병합되지 않았다');
        $this->assertStringContainsString('"selection":{}', $body);
    }

    /**
     * 이슈 #758 원 시나리오 — `init_actions[].params` 의 `filters: {}`·`items: []` 가 그대로 나간다.
     *
     * @scenario position=params_fallback_value
     *
     * @effects empty_object_served_as_object, empty_array_served_as_array
     *
     * @return void 반환값 없음
     */
    public function test_served_layout_keeps_empty_objects(): void
    {
        $template = $this->createTemplate();

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'product_show',
            'content' => [
                'init_actions' => [[
                    'handler' => 'setState',
                    'params' => ['target' => 'local', 'filters' => new \stdClass, 'items' => []],
                ]],
                'components' => [['id' => 'root', 'type' => 'basic', 'name' => 'Div']],
            ],
        ]);

        $body = $this->getJson("/api/layouts/{$template->identifier}/product_show.json")->assertOk()->getContent();

        $this->assertStringContainsString('"filters":{}', $body);
        $this->assertStringContainsString('"items":[]', $body);
    }

    /**
     * 확대한 값 자리(props 값·responsive props 값·isolatedState 값·data_sources data/headers 아래·defines·computed 값)의
     * 빈 객체가 `{}` 로 나가고, 구조 키 자기 자신(`props: {}`)은 배열 그대로다. 두 번째 요청(캐시)도 같다.
     *
     * @scenario position=expanded_value
     *
     * @effects expanded_value_positions_preserved, structural_self_empty_stays_array, cached_response_keeps_empty_objects
     *
     * @return void 반환값 없음
     */
    public function test_expanded_value_positions_are_served_as_objects(): void
    {
        $template = $this->createTemplate();

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'expanded_page',
            'content' => [
                'defines' => ['emptyMap' => new \stdClass],
                'computed' => ['picked' => new \stdClass],
                'data_sources' => [[
                    'id' => 'static_src',
                    'type' => 'static',
                    'data' => ['byId' => new \stdClass],
                    'headers' => ['X-Probe' => 'a', 'X-Map' => new \stdClass],
                ]],
                'components' => [
                    [
                        'id' => 'host',
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => ['dataMap' => new \stdClass],
                        'isolatedState' => ['sel' => new \stdClass],
                        'responsive' => ['mobile' => ['props' => ['mobileMap' => new \stdClass]]],
                    ],
                    ['id' => 'plain', 'type' => 'basic', 'name' => 'Div', 'props' => new \stdClass],
                ],
            ],
        ]);

        foreach (['첫 요청', '캐시 요청'] as $label) {
            $data = json_decode($this->getJson("/api/layouts/{$template->identifier}/expanded_page.json")->assertOk()->getContent())->data;

            $this->assertEquals(new \stdClass, $data->defines->emptyMap, "{$label}: defines 값");
            $this->assertEquals(new \stdClass, $data->computed->picked, "{$label}: computed 값");
            $this->assertEquals(new \stdClass, $data->data_sources[0]->data->byId, "{$label}: data_sources data 아래");
            $this->assertEquals(new \stdClass, $data->data_sources[0]->headers->{'X-Map'}, "{$label}: data_sources headers 아래");
            $this->assertEquals(new \stdClass, $data->components[0]->props->dataMap, "{$label}: props 값");
            $this->assertEquals(new \stdClass, $data->components[0]->isolatedState->sel, "{$label}: isolatedState 값");
            $this->assertEquals(new \stdClass, $data->components[0]->responsive->mobile->props->mobileMap, "{$label}: responsive props 값");
            $this->assertSame([], $data->components[1]->props, "{$label}: props 자기 자신은 배열 그대로");
        }
    }

    /**
     * 확장 지점 확장의 props 값과 overlay `inject_props` 값의 빈 객체도 `{}` 로 나간다.
     *
     * @scenario position=expanded_value
     *
     * @effects expanded_value_positions_preserved
     *
     * @return void 반환값 없음
     */
    public function test_extension_props_keep_empty_objects(): void
    {
        $activeModule = $this->createMock(ModuleInterface::class);
        $activeModule->method('getIdentifier')->willReturn('sirsoft-ecommerce');
        $mockModuleManager = $this->createMock(ModuleManager::class);
        $mockModuleManager->method('getActiveModules')->willReturn(['sirsoft-ecommerce' => $activeModule]);
        $this->app->instance(ModuleManager::class, $mockModuleManager);

        $template = $this->createTemplate();

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'host_with_props',
            'content' => [
                'layout_name' => 'host_with_props',
                'components' => [
                    ['id' => 'props_host', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'x']],
                    ['id' => 'point_host', 'type' => 'extension_point', 'name' => 'probe_point', 'props' => ['hostMap' => new \stdClass]],
                ],
            ],
            'is_active' => true,
        ]);

        LayoutExtension::create([
            'template_id' => $template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'host_with_props',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'host_with_props',
                'injections' => [[
                    'target_id' => 'props_host',
                    'position' => 'inject_props',
                    'props' => ['injectedMap' => new \stdClass, 'mergedMap' => ['_merge' => new \stdClass]],
                ]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        LayoutExtension::create([
            'template_id' => $template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'probe_point',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'extension_point' => 'probe_point',
                'components' => [['id' => 'ext_child', 'type' => 'basic', 'name' => 'Div', 'props' => ['childMap' => new \stdClass]]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $data = json_decode($this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json")->assertOk()->getContent())->data;

        $this->assertEquals(new \stdClass, $data->components[0]->props->injectedMap, 'inject_props 값');
        $this->assertEquals(new \stdClass, $data->components[0]->props->mergedMap, 'inject_props _merge 빈 맵');
        $child = $data->components[1]->children[0];
        $this->assertSame('ext_child', $child->id);
        $this->assertEquals(new \stdClass, $child->props->childMap, '확장 지점 확장 props 값');
        $this->assertEquals(new \stdClass, $child->extensionPointProps->hostMap, '호스트 props 값을 이어받은 extensionPointProps');
    }
}
