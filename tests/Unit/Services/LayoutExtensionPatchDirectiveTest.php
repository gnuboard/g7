<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `_patch` 가 호스트에 없는 항목을 새로 붙일 때 지시자(`_append`·중첩 `_patch`)가 풀려서 들어가는지 (#599 validate).
 *
 * 대상 항목이 호스트에 없으면 패치 항목을 **원문 그대로** 끝에 붙였다. 그러면 `children: {"_append": […]}`,
 * `cellChildren: {"_patch": …}` 같은 지시자 객체가 리터럴로 레이아웃에 남아, 렌더러가 배열이어야 할 자리에서
 * 객체를 받는다. 결제 플러그인 3종의 주문목록 배지가 이 경로를 탄다 — 운영자가 편집기에서 호스트의
 * `payment_method_cell` 을 지우거나 이름을 바꾸면 드러난다.
 *
 * 같은 파일의 기존 경로(`_append` 가 표현식 문자열을 만나면 경고하고 건너뜀)와 항목 안 `_append` 의 처리도
 * 맞춘다 — 항목 안에서는 표현식을 배열로 조용히 덮어썼다.
 */
class LayoutExtensionPatchDirectiveTest extends TestCase
{
    use DatabaseTransactions;

    private LayoutExtensionService $service;

    private Template $template;

    /**
     * 확장 활성 상태를 모킹하고 서비스·템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $module = $this->createMock(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn('sirsoft-support');
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('getActiveModules')->willReturn(['sirsoft-support' => $module]);
        $this->app->instance(ModuleManager::class, $modules);
        $plugins = $this->createMock(PluginManager::class);
        $plugins->method('getActivePlugins')->willReturn([]);
        $this->app->instance(PluginManager::class, $plugins);

        $this->service = app(LayoutExtensionService::class);
        $this->template = Template::factory()->create([
            'identifier' => 'test-patch-directive-'.uniqid(),
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 컬럼 목록을 가진 호스트에 inject_props 패치를 적용합니다.
     *
     * @param  array  $columns  호스트 컬럼
     * @param  array  $patch  `columns` 에 적용할 패치 값
     * @return array 적용 뒤 컬럼
     */
    private function applyColumnsPatch(array $columns, array $patch): array
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'patch_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-support',
            'content' => [
                'target_layout' => 'patch_layout',
                'injections' => [['target_id' => 'grid', 'position' => 'inject_props', 'props' => ['columns' => $patch]]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'patch_layout',
            'components' => [['id' => 'grid', 'type' => 'composite', 'name' => 'DataGrid', 'props' => ['columns' => $columns]]],
            'data_sources' => [],
        ];

        return $this->service->applyExtensions($layout, $this->template->id)['components'][0]['props']['columns'];
    }

    /**
     * 호스트에 없는 항목을 새로 붙일 때 `_append` 는 배열로, 중첩 `_patch` 는 목록으로 풀린다.
     *
     * @scenario host_item=missing, directive=append_and_nested_patch
     *
     * @effects new_patch_item_directives_are_normalized
     *
     * @return void 반환값 없음
     */
    public function test_directives_in_a_new_item_are_unfolded(): void
    {
        $columns = $this->applyColumnsPatch(
            [['field' => 'order_number', 'label' => '주문번호']],
            ['_patch' => ['by' => 'field', 'items' => [[
                'field' => 'payment_method',
                'label' => '결제수단',
                'cellChildren' => ['_patch' => ['by' => 'name', 'items' => [[
                    'name' => 'Div',
                    'children' => ['_append' => [['name' => 'Badge', 'text' => 'TEST']]],
                ]]]],
            ]]]]
        );

        $added = $columns[1];
        $this->assertSame('payment_method', $added['field']);
        $this->assertTrue(array_is_list($added['cellChildren']), 'cellChildren 에 지시자 객체가 남았다');
        $this->assertSame('Div', $added['cellChildren'][0]['name']);
        $this->assertSame([['name' => 'Badge', 'text' => 'TEST']], $added['cellChildren'][0]['children'], 'children 에 _append 가 리터럴로 남았다');
    }

    /**
     * 항목 안 `_append` 가 표현식 문자열을 만나면 덮어쓰지 않고 건너뛴다(최상위 경로와 같은 규칙).
     *
     * @scenario host_item=present, directive=append_on_expression
     *
     * @effects item_append_on_expression_is_skipped_not_overwritten
     *
     * @return void 반환값 없음
     */
    public function test_an_item_append_on_an_expression_keeps_the_expression(): void
    {
        $columns = $this->applyColumnsPatch(
            [['field' => 'payment_method', 'cellChildren' => '{{_local.dynamicCells}}']],
            ['_patch' => ['by' => 'field', 'items' => [[
                'field' => 'payment_method',
                'cellChildren' => ['_append' => [['name' => 'Badge']]],
            ]]]]
        );

        $this->assertSame('{{_local.dynamicCells}}', $columns[0]['cellChildren'], '표현식이 배열로 조용히 덮였다');
    }

    /**
     * 호스트에 있는 항목의 `_append` 는 종전대로 뒤에 덧붙인다(비회귀).
     *
     * @scenario host_item=present, directive=append_and_nested_patch
     *
     * @effects existing_item_append_still_appends
     *
     * @return void 반환값 없음
     */
    public function test_an_existing_item_append_still_appends(): void
    {
        $columns = $this->applyColumnsPatch(
            [['field' => 'payment_method', 'cellChildren' => [['name' => 'Span']]]],
            ['_patch' => ['by' => 'field', 'items' => [[
                'field' => 'payment_method',
                'cellChildren' => ['_append' => [['name' => 'Badge']]],
            ]]]]
        );

        $this->assertSame([['name' => 'Span'], ['name' => 'Badge']], $columns[0]['cellChildren']);
    }
}
