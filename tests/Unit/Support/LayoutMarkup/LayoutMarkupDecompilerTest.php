<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\DocumentCompiler;
use App\Support\LayoutMarkup\LayoutMarkupDecompiler;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 역변환기(S9) 단위 테스트 — `compile(decompile(json)) ≡ json` 왕복 동등성.
 *
 * 역변환기의 정확성은 왕복으로만 봉인된다. 각 테스트는 JSON 을 `.g7.html` 로 되돌리고(decompile→
 * serialize) 다시 컴파일해(tokenize→tree→document) 정규화 후 deep-equal 을 확인한다. 계층별 fixture
 * (문서 5계층)와 크래프트 케이스(상태 초기화 순서·값 shape·표기 탈출구)를 함께 덮는다.
 *
 * 레지스트리는 번들 `sirsoft-admin_basic/components.json` 실물을 쓴다(컴파일러 테스트와 동일 규율).
 */
class LayoutMarkupDecompilerTest extends LayoutMarkupTestCase
{
    private ComponentRegistry $registry;

    private LayoutMarkupDecompiler $decompiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
        $this->decompiler = new LayoutMarkupDecompiler($this->registry);
    }

    /**
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_round_trips_document_layers,
     *          round_trip_is_deterministic_after_normalization
     */
    #[DataProvider('documentFixtureProvider')]
    public function test_document_fixture_round_trips(string $fixture): void
    {
        $json = json_decode(file_get_contents(__DIR__."/fixtures/document/{$fixture}.json"), true);

        $this->assertRoundTrips($json);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function documentFixtureProvider(): array
    {
        return [
            ['layout_full'],
            ['layout_sections'],
            ['layout_standalone'],
            ['partial_modal'],
            ['override'],
            ['fragment_extension_point'],
            ['fragment_inject'],
        ];
    }

    /**
     * 상태 초기화 트리 왕복 — 키 순서·`_merge` 루트 위치·`:value` 리프 타입 보존 (계획서 최대 리스크 지점).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects state_init_tree_round_trips_with_key_order_and_merge_and_leaf_types
     */
    public function test_state_init_tree_round_trips_with_order_and_merge_and_leaf_types(): void
    {
        $json = [
            'layout_name' => 'state_demo',
            'initGlobal' => [
                'dashboardReady' => false,
                'counters' => ['userCount' => 0, 'pending' => 3, '_merge' => 'deep'],
                'selection' => [null, null, 'x'],
                'nested' => ['keyword' => '', 'onlyInStock' => true, 'depth' => ['level' => 2]],
                'emptyBag' => [],
            ],
        ];

        // 되돌린 마크업이 `<field>` 트리 + `:value` 타입 리터럴 + `_merge` 필드를 순서대로 방출하는지.
        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<field name="dashboardReady" :value="false"/>', $markup);
        $this->assertStringContainsString('<field name="userCount" :value="0"/>', $markup);
        $this->assertStringContainsString('<field name="_merge" value="deep"/>', $markup);
        $this->assertStringContainsString('<field name="selection" :value=\'[null,null,"x"]\'/>', $markup);
        $this->assertStringContainsString('<field name="keyword" value=""/>', $markup);

        $this->assertRoundTrips($json);
    }

    /**
     * 값 shape 왕복 — 문자열/숫자/불리언/null/배열/객체 props 가 각 표기로 되돌아 컴파일 동일.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects value_shapes_round_trip_through_their_notations
     */
    public function test_value_shapes_round_trip(): void
    {
        $json = [
            'layout_name' => 'shapes',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'DataGrid',
                    'props' => [
                        'label' => '$t:x',
                        'pageSize' => 20,
                        'serverSidePagination' => true,
                        'fallback' => null,
                        'rowActions' => [['id' => 'edit', 'label' => '$t:common.edit']],
                        'expandContext' => ['rowId' => '{{row.id}}'],
                    ],
                ]],
            ],
        ];

        $this->assertRoundTrips($json);
    }

    /**
     * 표기 탈출구 — 첫 급부 문법이 표현 못하는 노드 키(결함 `name_attr`)를 `<json key>` 로 보존.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects escape_hatch_preserves_unrepresentable_node_key
     */
    public function test_escape_hatch_preserves_unrepresentable_node_key(): void
    {
        $json = [
            'layout_name' => 'defect',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Div',
                    'name_attr' => 'title',
                    'props' => ['className' => 'x'],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<json key="name_attr">', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * Input 액션 첫 급부 왕복 (D36) — `<input>` 이 void 에서 빠져 `<on>` 자식을 직접 담는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects input_with_actions_round_trips_first_class
     */
    public function test_input_with_actions_round_trips_first_class(): void
    {
        $json = [
            'layout_name' => 'input_actions',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Input',
                    'props' => ['type' => 'text'],
                    'actions' => [[
                        'type' => 'change',
                        'handler' => 'setState',
                        'params' => ['target' => 'local', 'value' => '{{$event.target.value}}'],
                    ]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<input type="text">', $markup);
        $this->assertStringContainsString('<on type="change">', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 노드 레벨 권한 첫 급부 왕복 (D36) — 노드 안 `<permissions>` 로 조건 렌더 권한을 적는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects node_level_permissions_round_trip_first_class
     */
    public function test_node_level_permissions_round_trip_first_class(): void
    {
        $json = [
            'layout_name' => 'node_perms',
            'slots' => [
                'content' => [[
                    'id' => 'badge',
                    'type' => 'basic',
                    'name' => 'Div',
                    'permissions' => ['core.modules.read'],
                    'props' => ['className' => 'badge'],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<permissions>', $markup);
        $this->assertStringContainsString('<permission>core.modules.read</permission>', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 표기 탈출구 — 미지 최상위 키(state)는 layout 안에서 `<json key>` 섹션으로 보존.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects escape_hatch_preserves_unrepresentable_top_level_key
     */
    public function test_escape_hatch_preserves_unrepresentable_top_level_key(): void
    {
        $json = [
            'layout_name' => 'topdefect',
            'state' => ['foo' => 'bar'],
            'computed' => ['total' => '{{a + b}}'],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<json key="state">', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 액션 3계통·제어 흐름 왕복 — 이벤트/switch/case/sequence·콜백·top-level vs params 배치.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects action_branches_and_control_flow_round_trip
     */
    public function test_actions_and_control_flow_round_trip(): void
    {
        $json = [
            'layout_name' => 'actions',
            'slots' => [
                'content' => [[
                    'id' => 'grid',
                    'type' => 'composite',
                    'name' => 'DataGrid',
                    'actions' => [[
                        'event' => 'onRowAction',
                        'handler' => 'switch',
                        'params' => ['value' => '{{$args[0]}}'],
                        'cases' => [
                            'edit' => ['handler' => 'navigate', 'params' => ['path' => '/x/{{$args[1].id}}']],
                            'delete' => ['handler' => 'openModal', 'target' => 'confirm'],
                        ],
                    ]],
                ]],
            ],
        ];

        $this->assertRoundTrips($json);
    }

    /**
     * 되컴파일 throw 시 정상 키(id/props)를 딸려 blob 하지 않고 결함 키만 최소 격리한다 (감사 정정).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects throw_isolation_escapes_only_the_defect_key_not_standard_keys
     */
    public function test_compile_throw_escapes_only_defect_key_not_standard_keys(): void
    {
        $json = [
            'layout_name' => 'coarse',
            'slots' => [
                'content' => [[
                    'id' => 'row',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => ['className' => 'card'],
                    // 빈 sequence → 되컴파일 시 EmptyActionBlock throw.
                    'actions' => [['type' => 'click', 'handler' => 'sequence', 'actions' => []]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('data-ref="row"', $markup);
        $this->assertStringContainsString('class="card"', $markup);
        $this->assertStringContainsString('<json key="actions">', $markup);
        $this->assertStringNotContainsString('<json key="id">', $markup);
        $this->assertStringNotContainsString('<json key="props">', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * JSON 레이아웃이 `compile(decompile(json))` 후 (정규화 기준) 동일함을 단언합니다.
     *
     * @param  array<string, mixed>  $json  원본 JSON
     */
    private function assertRoundTrips(array $json): void
    {
        $markup = $this->decompiler->decompile($json);
        $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($markup), null);
        $recompiled = (new DocumentCompiler($this->registry))->compile($roots);

        $this->assertSame(
            LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize($json))),
            LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize($recompiled))),
            "왕복 불일치:\n{$markup}",
        );
    }
}
