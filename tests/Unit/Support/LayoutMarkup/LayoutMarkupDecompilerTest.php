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
     * 노드 레벨 편집기 메타 첫 급부 왕복 (D36) — 노드 안 `<meta>` 로 editor_label 을 적는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects node_level_meta_round_trip_first_class
     */
    public function test_node_level_meta_round_trip_first_class(): void
    {
        $json = [
            'layout_name' => 'node_meta',
            'slots' => [
                'content' => [[
                    'id' => 'delete_modal',
                    'type' => 'composite',
                    'name' => 'Modal',
                    'meta' => ['editor_label' => '$t:admin.modal_label.delete'],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<meta editor_label="$t:admin.modal_label.delete"/>', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 빈 섹션 컨테이너는 방출하지 않는다 — 엔진은 "빈 목록"과 "키 부재"를 구분하지 않는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects empty_section_containers_are_omitted
     */
    public function test_empty_section_containers_round_trip_without_escape(): void
    {
        $json = [
            'layout_name' => 'empties',
            'data_sources' => [],
            'modals' => [],
            'computed' => [],
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * partial 의 최상위 `layout_name` 은 `<partial name>` 으로 왕복한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects partial_layout_name_round_trips
     */
    public function test_partial_layout_name_round_trips(): void
    {
        $json = [
            'meta' => ['is_partial' => true],
            'layout_name' => 'panel_detail',
            'type' => 'basic',
            'name' => 'Div',
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<partial name="panel_detail"', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * responsive 오버라이드는 구조 노드 키(classMap)와 주석도 담는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects responsive_override_carries_structural_keys
     */
    public function test_responsive_override_carries_class_map_and_comment(): void
    {
        $json = [
            'layout_name' => 'resp',
            'components' => [[
                'type' => 'basic',
                'name' => 'Div',
                'responsive' => [
                    'portable' => ['classMap' => ['base' => 'text-lg', 'key' => '{{_local.s ?? \'\'}}', 'default' => 'text-gray-600']],
                    'mobile' => ['comment' => '첫 줄 전체 차지', 'props' => ['className' => 'w-full']],
                ],
            ]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `columns` props 는 **DataGrid 일 때만** `<column>` 임베딩으로 찍는다.
     *
     * 컴파일러가 `<column>` 의 부모를 DataGrid 로 강제하므로(embedding_tag_wrong_parent), 게이트 없이
     * 찍으면 되컴파일이 죽어 노드 props 가 통째로 탈출한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects columns_embedding_is_gated_by_owner_component
     */
    public function test_columns_props_embed_only_under_data_grid(): void
    {
        $columns = [['field' => 'a', 'header' => 'A', 'required' => true]];

        $grid = ['layout_name' => 'g', 'components' => [['type' => 'composite', 'name' => 'DataGrid', 'props' => ['columns' => $columns]]]];
        $this->assertStringContainsString('<column', $this->decompiler->decompile($grid));
        $this->assertRoundTrips($grid);

        // DataGrid 가 아닌 소유자(ColumnSettings 류)는 `<prop>` 폴백 — 탈출 없이 왕복해야 한다.
        $other = ['layout_name' => 'o', 'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['columns' => $columns]]]];
        $markup = $this->decompiler->decompile($other);
        $this->assertStringNotContainsString('<column', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($other);
    }

    /**
     * `sortable.wrapperElement` 는 엔진 정식 소비 키다(DynamicRenderer:2910) — 속성으로 왕복한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects sortable_wrapper_element_round_trips
     */
    public function test_sortable_wrapper_element_round_trips(): void
    {
        $json = [
            'layout_name' => 'srt',
            'components' => [[
                'type' => 'basic',
                'name' => 'Div',
                'sortable' => [
                    'source' => '{{_local.rows ?? []}}', 'itemKey' => 'code', 'strategy' => 'verticalList',
                    'handle' => '[data-drag-handle]', 'itemVar' => '$c', 'indexVar' => '$i', 'wrapperElement' => 'tr',
                ],
            ]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('wrapper-element="tr"', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `condition` 별칭 승격 — 감싼 식은 정본 `data-if` 로 방출된다(같은 평가기, `if` 우선).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects condition_alias_promoted_to_if
     */
    public function test_wrapped_condition_alias_is_promoted_to_if(): void
    {
        $json = [
            'layout_name' => 'cond',
            'components' => [['type' => 'basic', 'name' => 'Div', 'condition' => "{{_local.mode === 'detail'}}"]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('data-if=', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `condition` 별칭 미승격 — 맨 문자열은 승격하면 동작이 바뀐다.
     *
     * 맨 문자열 condition 은 보간만 거쳐 `Boolean("...")` = 항상 참인 잠복 결함이다. `data-if` 로
     * 접으면 컴파일러가 식으로 감싸 **실제 평가되기 시작**하므로 기계 이관이 건드릴 수 없다
     * (결함 트랙 S16 U-COND). 접지 않고 `<json>` 으로 남겨 계량한다.
     *
     * @scenario compile_unit=decompile,source_health=defect
     *
     * @effects bare_condition_is_not_promoted
     */
    public function test_bare_condition_is_not_promoted(): void
    {
        $json = [
            'layout_name' => 'cond_bare',
            'components' => [['type' => 'basic', 'name' => 'Div', 'condition' => "_local.mode === 'detail'"]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<json key="condition">', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 표기 탈출구 — 미지 최상위 키(결함 인벤토리 `bindings`)는 layout 안에서 `<json key>` 섹션으로 보존.
     *
     * 사어 키(`schema`/`pageConfig`)는 이 테스트에 쓰지 않는다 — 그것들은 이관이 **버리는** 대상이라
     * 탈출구를 타지 않는다(normalizeDocument). 탈출구는 "옮겨야 하는데 표기가 없는" 키의 것이다.
     *
     * @scenario compile_unit=decompile,source_health=defect
     *
     * @effects escape_hatch_preserves_unrepresentable_top_level_key
     */
    public function test_escape_hatch_preserves_unrepresentable_top_level_key(): void
    {
        $json = [
            'layout_name' => 'topdefect',
            'bindings' => ['foo' => 'bar'],
            'computed' => ['total' => '{{a + b}}'],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<json key="bindings">', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 사어 키는 이관이 옮기지 않는다 — 탈출구도 타지 않고 그냥 사라진다 (PO 확정 07-20).
     *
     * `schema`/`pageConfig` 는 plugin_settings 잔재로 프론트 참조 0건(설정 검증은 PHP
     * `getSettingsSchema()` 별도 축), `_comment_*` 변형은 서빙 strip 대상도 아니면서
     * (LayoutService 는 `comment`/`_comment` 두 키만 걷는다) 읽히지도 않는 잡음이다.
     *
     * @scenario compile_unit=decompile,source_health=defect
     *
     * @effects dead_keys_are_dropped_not_escaped
     */
    public function test_dead_keys_are_dropped_instead_of_escaped(): void
    {
        $json = [
            'layout_name' => 'dead',
            'schema' => ['fields' => []],
            'pageConfig' => ['foo' => 'bar'],
            'components' => [[
                'type' => 'basic',
                'name' => 'Div',
                '_comment_hidden' => '예전 담당자 메모',
                '_comment_removed' => '삭제된 항목',
            ]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringNotContainsString('<json', $markup);
        $this->assertStringNotContainsString('schema', $markup);
        $this->assertStringNotContainsString('_comment', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `modals` 객체-맵 이형은 배열로 접는다 (엔진은 배열 전용, 맵 키는 각 모달 `id` 로 중복 보유).
     *
     * @scenario compile_unit=decompile,source_health=defect
     *
     * @effects modals_object_map_normalized_to_list
     */
    public function test_modals_object_map_is_normalized_to_list(): void
    {
        $json = [
            'layout_name' => 'mm',
            'modals' => [
                'edit_modal' => ['id' => 'edit_modal', 'type' => 'composite', 'name' => 'Modal', 'props' => ['title' => 'T']],
            ],
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `state` 별칭 승격 — 단독이면 정본 `initLocal` 로 방출된다(엔진 `initLocal || state` 동치).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects state_alias_promoted_to_init_local
     */
    public function test_state_alias_is_promoted_to_init_local(): void
    {
        $json = [
            'layout_name' => 'alias',
            'state' => ['foo' => 'bar'],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<init-local>', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `state` 별칭 미승격 — `initLocal` 과 병존하면 엔진이 `state` 를 무시하므로 죽은 키다.
     *
     * 승격하면 initLocal 을 덮어써 동작이 바뀐다 — 접지 않고 `<json>` 으로 남겨 결함으로 계량한다.
     *
     * @scenario compile_unit=decompile,source_health=defect
     *
     * @effects state_alias_not_promoted_when_init_local_present
     */
    public function test_state_alias_is_not_promoted_when_init_local_present(): void
    {
        $json = [
            'layout_name' => 'alias_conflict',
            'initLocal' => ['kept' => 'yes'],
            'state' => ['dead' => 'ignored'],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<init-local>', $markup);
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

        // 왕복 오라클(LayoutMarkupRoundTrip::verify)과 **같은 기준**으로 비교한다 — 문서 층 별칭
        // 정규화(normalizeDocument)를 빠뜨리면 오라클은 통과시키는 문서를 여기서만 실패시킨다.
        $this->assertSame(
            LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(
                LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($json))
            )),
            LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(
                LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($recompiled))
            )),
            "왕복 불일치:\n{$markup}",
        );
    }
}
