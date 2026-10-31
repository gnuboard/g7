<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use stdClass;
use Tests\TestCase;

/**
 * 반복 렌더 템플릿 안 확장점의 빈 객체 내성 테스트 (#758 — 5-2 PHP 소비자 대조)
 *
 * DataGrid 셀 템플릿(`props.columns[].cellChildren`)·확장 행 템플릿(`props.expandChildren`)은 props 아래라
 * 보존 범위 확대(Q3) 뒤로 「값 자리」가 됐다. 그 안의 `{}`(확장점 `default: {}`, 빈 노드 `{}`)는 이제 빈
 * stdClass 로 서빙 병합에 들어온다. 확장점 처리가 그것을 배열로 다루면 `array_merge()` TypeError·배열 접근
 * Error 로 레이아웃 응답 전체가 500 이 된다 — 수정 전에는 같은 입력이 빈 배열이라 통과하던 레이아웃이다.
 *
 * @see tests/scenarios/layout-serving-empty-object.yaml
 */
class EmbeddedExtensionPointEmptyObjectTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 레이아웃 서빙 경로가 GDPR 미들웨어를 거치므로 그 마이그레이션을 포함한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    /**
     * 셀·확장 행 템플릿 안 확장점의 `default: {}` 와 빈 노드 `{}` 가 있어도 응답이 200 이고,
     * 확장이 주입한 컴포넌트는 목록으로, 주입이 없는 확장점은 빈 목록으로 나간다.
     *
     * @scenario position=expanded_value
     *
     * @effects embedded_extension_point_tolerates_empty_objects
     *
     * @return void 반환값 없음
     */
    public function test_embedded_extension_points_tolerate_empty_objects(): void
    {
        $activeModule = $this->createMock(ModuleInterface::class);
        $activeModule->method('getIdentifier')->willReturn('sirsoft-ecommerce');
        $mockModuleManager = $this->createMock(ModuleManager::class);
        $mockModuleManager->method('getActiveModules')->willReturn(['sirsoft-ecommerce' => $activeModule]);
        $this->app->instance(ModuleManager::class, $mockModuleManager);

        $template = Template::factory()->create([
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'grid_with_points',
            'content' => [
                'layout_name' => 'grid_with_points',
                'components' => [[
                    'id' => 'grid',
                    'type' => 'composite',
                    'name' => 'DataGrid',
                    'props' => [
                        'columns' => [[
                            'field' => 'name',
                            'cellChildren' => [
                                new stdClass,
                                ['id' => 'cell_point', 'type' => 'extension_point', 'name' => 'probe_cell_point', 'default' => new stdClass],
                            ],
                        ]],
                        'expandChildren' => [
                            ['id' => 'expand_point', 'type' => 'extension_point', 'name' => 'probe_idle_point', 'default' => new stdClass],
                        ],
                    ],
                ]],
            ],
            'is_active' => true,
        ]);

        LayoutExtension::create([
            'template_id' => $template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'probe_cell_point',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'extension_point' => 'probe_cell_point',
                'components' => [['id' => 'ext_cell', 'type' => 'basic', 'name' => 'Span']],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $data = json_decode(
            $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json")->assertOk()->getContent()
        )->data;

        $props = $data->components[0]->props;
        $cell = $props->columns[0]->cellChildren;

        $this->assertEquals(new stdClass, $cell[0], '빈 노드는 작성한 그대로 둔다');
        $this->assertSame(['ext_cell'], array_map(static fn ($c) => $c->id, $cell[1]->children), '주입 컴포넌트는 목록으로');
        $this->assertSame([], $props->expandChildren[0]->children, '주입이 없는 확장점의 빈 default 는 빈 목록');
    }
}
