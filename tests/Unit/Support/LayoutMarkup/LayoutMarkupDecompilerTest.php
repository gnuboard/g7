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

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
        $this->decompiler = new LayoutMarkupDecompiler($this->registry);
    }

    /**
     * 문서 계층별 픽스처가 역변환·재컴파일 뒤 원본과 같다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_round_trips_document_layers,
     *          round_trip_is_deterministic_after_normalization
     *
     * @param  string  $fixture  픽스처 이름
     * @return void 반환값 없음
     */
    #[DataProvider('documentFixtureProvider')]
    public function test_document_fixture_round_trips(string $fixture): void
    {
        $json = json_decode(file_get_contents(__DIR__."/fixtures/document/{$fixture}.json"), true);

        $this->assertRoundTrips($json);
    }

    /**
     * 문서 계층 픽스처 이름 목록.
     *
     * @return array<int, array<int, string>> 픽스처 이름 목록
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * 자식이 하나인 명시 `sequence` 는 래퍼를 잃지 않는다.
     *
     * 컴파일러는 블록 자식이 하나면 그 액션에 트리거만 얹고 sequence 로 감싸지 않는다
     * ([ActionCompiler::compileBlockBody](app/Support/LayoutMarkup/ActionCompiler.php#L280)).
     * 그래서 역변환기가 단일 자식 sequence 를 펼치면 되컴파일 때 래퍼가 복원되지 않아 왕복이 깨진다 —
     * 자식이 둘 이상일 때만 암묵 sequence 로 펼치고, 하나면 `<sequence>` 를 명시한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects single_child_sequence_keeps_wrapper_through_round_trip
     *
     * @return void 반환값 없음
     */
    public function test_single_child_sequence_keeps_its_wrapper(): void
    {
        $json = [
            'layout_name' => 'single_seq',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Input',
                    'actions' => [[
                        'type' => 'change',
                        'handler' => 'sequence',
                        'actions' => [[
                            'handler' => 'setState',
                            'params' => ['target' => 'local', 'form' => '{{$event.target.checked}}'],
                        ]],
                    ]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<sequence>', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 자식이 둘 이상인 sequence 는 종전대로 암묵 sequence 로 펼친다 (대조군).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects multi_child_sequence_stays_implicit
     *
     * @return void 반환값 없음
     */
    public function test_multi_child_sequence_stays_implicit(): void
    {
        $json = [
            'layout_name' => 'multi_seq',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Input',
                    'actions' => [[
                        'type' => 'change',
                        'handler' => 'sequence',
                        'actions' => [
                            ['handler' => 'setState', 'params' => ['target' => 'local', 'a' => '1']],
                            ['handler' => 'setState', 'params' => ['target' => 'local', 'b' => '2']],
                        ],
                    ]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringNotContainsString('<sequence>', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `conditions` 분기의 `then` 이 **단일 객체**여도 왕복한다 (G9).
     *
     * 엔진은 `then` 을 객체·배열 두 형태로 받는다(ActionDispatcher::handleConditions — 배열이면
     * sequence 처럼 순차 실행, 객체면 단일 실행). 전 코퍼스 실측도 객체 46 · 배열 16 으로 **객체가
     * 다수형**인데, 역변환기가 `then` 을 배열로만 보고 `array_map(fn (array $a))` 를 걸어 객체가 오면
     * TypeError 로 죽었고 그 노드가 통째로 `<json>` 으로 탈출했다(W3 3파일 · W4 · W6 동일 계열).
     *
     * 표기 규칙은 `<case>`/`<default>` 의 블록 본문 관용과 같다 — 자식 하나면 객체, 둘 이상이면 배열.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects single_action_condition_branch_round_trips_as_object
     *
     * @return void 반환값 없음
     */
    public function test_single_action_condition_branch_round_trips_as_object(): void
    {
        $json = [
            'layout_name' => 'cond_then_object',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Input',
                    'actions' => [[
                        'type' => 'change',
                        'handler' => 'conditions',
                        'conditions' => [
                            [
                                'if' => '{{$event.target.checked}}',
                                'then' => ['handler' => 'setState', 'params' => ['target' => 'global', 'flag' => true]],
                            ],
                            [
                                'then' => ['handler' => 'setState', 'params' => ['target' => 'global', 'flag' => false]],
                            ],
                        ],
                    ]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<when', $markup);
        $this->assertStringContainsString('<else>', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 액션이 둘 이상인 분기는 `then` 배열을 유지한다 (G9 대조군).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects multi_action_condition_branch_keeps_then_array
     *
     * @return void 반환값 없음
     */
    public function test_multi_action_condition_branch_keeps_then_array(): void
    {
        $json = [
            'layout_name' => 'cond_then_array',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Input',
                    'actions' => [[
                        'type' => 'change',
                        'handler' => 'conditions',
                        'conditions' => [[
                            'if' => '{{$event.target.checked}}',
                            'then' => [
                                ['handler' => 'setState', 'params' => ['target' => 'global', 'a' => '1']],
                                ['handler' => 'closeModal'],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 단일 원소 `then` 배열은 객체로 수렴한다 ("입력만 받고 정규화" — 콜백 단일배열 선례와 동일).
     *
     * `then:[{x}]` 는 액션 하나짜리 sequence 라 `then:{x}` 와 런타임 동치이고, 컴파일 정본은 객체다.
     * 그래서 이관은 객체로 수렴하며 왕복 오라클이 양쪽을 같게 본다
     * ([LayoutMarkupRoundTrip::normalize](app/Support/LayoutMarkup/LayoutMarkupRoundTrip.php)).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects single_element_then_array_converges_to_object
     *
     * @return void 반환값 없음
     */
    public function test_single_element_then_array_converges_to_object(): void
    {
        $json = [
            'layout_name' => 'cond_then_single_array',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Input',
                    'actions' => [[
                        'type' => 'change',
                        'handler' => 'conditions',
                        'conditions' => [[
                            'if' => '{{$event.target.checked}}',
                            'then' => [['handler' => 'closeModal']],
                        ]],
                    ]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `params.actions` 를 갖는 비-제어 핸들러는 `<actions>` 블록으로 왕복한다 (G10).
     *
     * `startInterval` 은 `params.actions` 를 **매 tick 실행 목록**으로 읽는다
     * (ActionDispatcher::handleStartInterval). 그런데 `actions` 는 제어 핸들러의 레거시 params 배치
     * 이름이기도 해서, 역변환기가 그 이름을 보고 "제어 자식이니 control() 이 찍는다" 고 건너뛰었다 —
     * 제어 핸들러가 아닌 `startInterval` 에서는 아무도 찍지 않아 **키가 통째로 소실**되고,
     * 그 섹션이 `<json>` 으로 탈출했다(W3 identity_challenge).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects interval_tick_actions_round_trip_as_action_block
     *
     * @return void 반환값 없음
     */
    public function test_interval_tick_actions_round_trip_as_action_block(): void
    {
        $json = [
            'layout_name' => 'interval_tick',
            'init_actions' => [[
                'handler' => 'startInterval',
                'params' => [
                    'id' => 'ticker',
                    'intervalMs' => 1000,
                    'actions' => [
                        ['handler' => 'setState', 'params' => ['target' => 'local', 'n' => '{{_local.n + 1}}']],
                        ['handler' => 'refetchDataSource', 'params' => ['dataSourceId' => 'items']],
                    ],
                ],
            ]],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<actions>', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 제어 핸들러의 `params.actions` 는 종전대로 top-level 로 수렴한다 (G10 대조군).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects control_handler_params_actions_still_converge_to_top_level
     *
     * @return void 반환값 없음
     */
    public function test_control_handler_params_actions_still_converge_to_top_level(): void
    {
        $json = [
            'layout_name' => 'legacy_params_sequence',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Button',
                    'actions' => [[
                        'type' => 'click',
                        'handler' => 'sequence',
                        'params' => [
                            'actions' => [
                                ['handler' => 'setState', 'params' => ['target' => 'local', 'a' => '1']],
                                ['handler' => 'setState', 'params' => ['target' => 'local', 'b' => '2']],
                            ],
                        ],
                    ]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringNotContainsString('<actions>', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 데이터소스의 **임의 이름 구조값 키**는 `<field name>` 자식으로 왕복한다 (D42 G11).
     *
     * `<data-source>` 는 `data`/`params`/`headers`/`fallback` 넷만 구조 태그를 갖고 나머지 키는 전부
     * 속성으로 나갔다. 값이 객체·배열이면 그 속성이 통짜 JSON(`:errorCondition='{…}'`)이 되어
     * D2("속성 JSON 추방")를 어긴다 — 액션의 `<field name>`(G6)과 **같은 SSoT** 로 자식에 적는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects data_source_structured_keys_emit_field_tags
     *
     * @return void 반환값 없음
     */
    public function test_data_source_structured_keys_emit_field_tags(): void
    {
        $json = [
            'layout_name' => 'ds_structured',
            'data_sources' => [[
                'id' => 'roles',
                'endpoint' => '/api/admin/roles',
                'method' => 'GET',
                'depends_on' => ['users', 'menus'],
                'errorCondition' => ['if' => '{{response.data.length === 0}}', 'errorCode' => 404],
            ]],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<field name="depends_on">', $markup);
        $this->assertStringContainsString('<field name="errorCondition"', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertSame([], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
        $this->assertRoundTrips($json);
    }

    /**
     * `defines` 값도 구조값이면 `<field>`/`<item>` 트리로 나간다 (D42 G12).
     *
     * `<define>` 은 JSON 본문 블록이라 D41 계량에서 "설계된 정본" 으로 빠졌으나, D42 가 태그 예외를
     * 폐기하면서 계량 대상이 됐다. 표현 가능한 값(문자열 리스트·평면 객체 리스트·중첩 객체)은
     * 다른 자리와 같은 표기로 적고, 표현 불가한 값만 JSON 본문으로 남는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects define_structured_value_emits_item_tags
     *
     * @return void 반환값 없음
     */
    public function test_define_structured_value_emits_item_tags(): void
    {
        $json = [
            'layout_name' => 'define_structured',
            'defines' => [
                'countryList' => [
                    ['code' => 'KR', 'name' => '$t:countries.KR'],
                    ['code' => 'US', 'name' => '$t:countries.US'],
                ],
                'sizes' => ['s', 'm', 'l'],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('<define name="countryList">', $markup);
        $this->assertStringContainsString('<item code="KR"', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertSame([], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
        $this->assertRoundTrips($json);
    }

    /**
     * 표현 불가한 `defines` 값(혼합 스칼라 리스트)은 종전대로 JSON 본문으로 남는다 (G12 대조군).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects unrepresentable_define_value_stays_json_body
     *
     * @return void 반환값 없음
     */
    public function test_unrepresentable_define_value_stays_json_body(): void
    {
        $json = [
            'layout_name' => 'define_scalar_list',
            'defines' => ['steps' => [1, 2, 3]],
        ];

        $markup = $this->decompiler->decompile($json);
        $this->assertStringContainsString('[1,2,3]', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 구조값 **리스트**도 어느 자리에서든 `<item>` 으로 나간다 (D51 G17).
     *
     * 종전 `structuredValueElement` 는 맵만 `<field>` 트리로 풀고 **리스트는 무조건 JSON 본문**으로
     * 냈다 — 표기가 있는데도 `<data-sources>["posts"]</data-sources>` 처럼 통짜 JSON 이 남던
     * 비대칭이다. G14 가 `<seo>` 를 열면서 드러났고, 같은 헬퍼를 쓰는 데이터소스 `<data>`·`<fallback>`
     * 자리도 같은 결함을 공유하고 있었다. 두 호출처(문서 `<seo>` / 데이터소스 본문)를 함께 세운다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects structured_value_list_emits_item_tags
     *
     * @return void 반환값 없음
     */
    public function test_structured_value_list_emits_item_tags(): void
    {
        $json = [
            'layout_name' => 'structured_list',
            'meta' => [
                'seo' => [
                    'enabled' => true,
                    'data_sources' => ['posts', 'boards'],
                    'extensions' => [['type' => 'module', 'id' => 'sirsoft-board']],
                ],
            ],
            'data_sources' => [
                ['id' => 'roles', 'type' => 'static', 'data' => [['code' => 'admin'], ['code' => 'user']]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        // 직렬화기의 인덴트를 걷어내고 구조만 본다 — 들여쓰기 폭이 바뀌어도 이 단언은 살아야 한다.
        $flat = preg_replace('/\s+</', '<', $markup);

        // 문자열 리스트 → 본문 `<item>` / 평면 객체 리스트 → 속성 `<item>`
        $this->assertStringContainsString('<data-sources><item>posts</item><item>boards</item></data-sources>', $flat);
        $this->assertStringContainsString('<extensions><item type="module" id="sirsoft-board"/></extensions>', $flat);
        $this->assertStringContainsString('<data><item code="admin"/><item code="user"/></data>', $flat);

        $this->assertStringNotContainsString('<json', $markup);
        $this->assertSame([], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
        $this->assertRoundTrips($json);
    }

    /**
     * 표기 불가한 리스트는 종전대로 JSON 본문으로 남는다 (G17 대조군).
     *
     * 문자열 아닌 스칼라 원소(`[5, 4, 3]`)는 `<item :value="5"/>` 가 객체 `{"value":5}` 와 구별되지
     * 않아 **D42 가 표기를 주지 않기로 확정한** 형태다({@see EmitsMarkup::isStructuredValue}).
     * 이 분기를 세우지 않으면 G17 이 "모든 리스트를 `<item>` 으로" 로 과확장돼도 아무도 못 잡는다 —
     * 그 상태에서 왕복은 깨지고 코퍼스에는 표본(`shop/show` 별점 눈금)이 대장으로만 등재돼 있다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects structured_value_list_emits_item_tags
     *
     * @return void 반환값 없음
     */
    public function test_unrepresentable_structured_list_stays_json_body(): void
    {
        $json = [
            'layout_name' => 'structured_list_scalar',
            'data_sources' => [
                ['id' => 'stars', 'type' => 'static', 'data' => [5, 4, 3, 2, 1]],
                ['id' => 'empty', 'type' => 'static', 'data' => []],
            ],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<data>[5,4,3,2,1]</data>', $markup);
        $this->assertStringContainsString('<data>[]</data>', $markup);
        $this->assertStringNotContainsString('<item', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 액션의 `params.body`/`params.query` 는 `<body>`/`<query>` 로 나간다 (D41).
     *
     * 속성에 JSON 을 박으면 D2("속성 JSON 추방")를 어기는데, escape 계량은 `<json>` 태그만 세므로
     * escape-0 판정을 받고도 원시 JSON 이 그대로 남는다 — 검수에서 직접 발견한 부류다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects action_field_map_params_emit_first_class_tags
     *
     * @return void 반환값 없음
     */
    public function test_action_body_and_query_emit_field_map_tags(): void
    {
        $json = [
            'layout_name' => 'field_map',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Button',
                    'actions' => [[
                        'type' => 'click',
                        'handler' => 'apiCall',
                        'target' => '/api/admin/pages',
                        'params' => [
                            'body' => ['title' => '{{_local.title}}', 'published' => true],
                            'query' => ['page' => 1],
                        ],
                    ]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<body title="{{_local.title}}" :published="true"/>', $markup);
        $this->assertStringContainsString('<query :page="1"/>', $markup);
        $this->assertStringNotContainsString(':body=', $markup);
        $this->assertStringNotContainsString(':query=', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 값이 다시 객체면 `<body>` 속성 field-map 으로는 못 내리지만, `<field>` 트리로 내린다 (D42 G6).
     *
     * 종전에는 이 자리가 `:body='{"meta":{"a":1}}'` 속성 JSON 으로 남았다 — 소스 파일에 통짜 JSON 이
     * 드러나는 형태다. 중첩 구조값 문법을 세운 뒤로는 태그로 내려가고, 속성 JSON 은 나오지 않는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects nested_object_field_map_becomes_field_tree
     *
     * @return void 반환값 없음
     */
    public function test_nested_object_body_becomes_field_tree(): void
    {
        $json = [
            'layout_name' => 'nested_body',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Button',
                    'actions' => [[
                        'type' => 'click',
                        'handler' => 'apiCall',
                        'target' => '/api/admin/pages',
                        'params' => ['body' => ['meta' => ['a' => 1]]],
                    ]],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringNotContainsString(':body=', $markup);
        $this->assertStringContainsString('<field name="body">', $markup);
        $this->assertStringContainsString('<field name="meta">', $markup);
        $this->assertStringContainsString('<field name="a" :value="1"/>', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `switch` 의 두 배치가 각각 제 표기로 나간다 — `params.value`→`on`, top-level `value`→`value` (D42 G8).
     *
     * 컴파일러만 갈라 놓고 방출기를 안 가르면 왕복이 깨진다(F8 함정: 정규화·방출은 짝으로).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects switch_subject_placement_is_expressible_in_both_forms
     *
     * @return void 반환값 없음
     */
    public function test_switch_subject_placements_emit_distinct_attributes(): void
    {
        $build = static fn (array $action): array => [
            'layout_name' => 'switch_subject',
            'slots' => ['content' => [[
                'type' => 'basic',
                'name' => 'Div',
                'actions' => [$action + ['event' => 'onItemClick', 'handler' => 'switch', 'cases' => ['a' => ['handler' => 'refresh']]]],
            ]]],
        ];

        $canonical = $build(['params' => ['value' => '{{$args[0]}}']]);
        $markup = $this->decompiler->decompile($canonical);

        $this->assertStringContainsString('<switch on="{{$args[0]}}">', $markup);
        $this->assertRoundTrips($canonical);

        $legacy = $build(['value' => '{{$args[0]}}']);
        $markup = $this->decompiler->decompile($legacy);

        $this->assertStringContainsString('<switch value="{{$args[0]}}">', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($legacy);
    }

    /**
     * 확장 주입 `<props>` 의 `columns` 는 `<column>`/`<cell>` 로 나간다 (D42 G7).
     *
     * 종전에는 임베딩 소유자를 못 찾아 `<props>` 본문에 컬럼 트리가 통째 JSON 으로 남았다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects injection_props_accept_datagrid_column_tree
     *
     * @return void 반환값 없음
     */
    public function test_injection_column_tree_emits_column_tags(): void
    {
        $json = [
            'target_layout' => 'admin_ecommerce_order_list',
            'injections' => [[
                'target_id' => 'grid',
                'position' => 'inject_props',
                'props' => ['columns' => [[
                    'field' => 'no',
                    'header' => 'NO',
                    'sortable' => false,
                    'cellChildren' => [['type' => 'basic', 'name' => 'Span', 'text' => '{{row.no}}']],
                ]]],
            ]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<prop name="columns">', $markup);
        $this->assertStringContainsString('<column field="no" header="NO" :sortable="false">', $markup);
        $this->assertStringContainsString('<cell>', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 컬럼으로 되돌릴 수 없는 `columns`(원소에 `field` 없음)는 종전 표기를 유지한다 (대조군).
     *
     * 되컴파일이 `field` 를 필수로 요구하므로, 그대로 찍으면 주입 props 전체가 탈출한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects unrepresentable_column_list_falls_back_without_breaking_round_trip
     *
     * @return void 반환값 없음
     */
    public function test_column_list_without_field_falls_back(): void
    {
        $json = [
            'target_layout' => 'admin_ecommerce_order_list',
            'injections' => [[
                'target_id' => 'grid',
                'position' => 'inject_props',
                'props' => ['columns' => [['header' => 'NO']]],
            ]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringNotContainsString('<column', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 조건부 값(`$switch`)은 `<switch-value>` 로 나간다 (D41 — 설계 검토 지적 건).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects switch_value_prop_emits_first_class_tag
     *
     * @return void 반환값 없음
     */
    public function test_switch_value_prop_emits_switch_value_tag(): void
    {
        $json = [
            'layout_name' => 'switch_prop',
            'slots' => [
                'content' => [[
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => [
                        'title' => [
                            '$switch' => '{{_global.modalType}}',
                            '$cases' => ['a' => '$t:x.a', 'b' => '$t:x.b'],
                            '$default' => "{{_global.modalType ?? ''}}",
                        ],
                    ],
                ]],
            ],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<switch-value prop="title" on="{{_global.modalType}}">', $markup);
        $this->assertStringContainsString('<case when="a">$t:x.a</case>', $markup);
        $this->assertStringContainsString('<default>', $markup);
        $this->assertStringNotContainsString('$switch', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 노드 레벨 권한 첫 급부 왕복 (D36) — 노드 안 `<permissions>` 로 조건 렌더 권한을 적는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects node_level_permissions_round_trip_first_class
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * `columns` props 는 소유 컴포넌트 이름과 무관하게 `<column>` 으로 되돌린다 — 표현할 수 없을 때만 `<prop>` 폴백.
     *
     * 컴파일러가 `<column>` 을 부모 이름 없이 받으므로(2026-09-28 결정) DataGrid 밖(ColumnSettings 류)도 같은
     * 태그로 왕복한다. `field` 가 없는 원소처럼 태그가 되돌리지 못하는 값은 종전 `<prop>` 으로 물러서고,
     * 어느 쪽이든 노드 props 가 통째로 `<json>` 탈출하면 안 된다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects columns_embedding_is_independent_of_owner_name
     *
     * @return void 반환값 없음
     */
    public function test_columns_props_embed_under_any_owner(): void
    {
        $columns = [['field' => 'a', 'header' => 'A', 'required' => true]];

        $grid = ['layout_name' => 'g', 'components' => [['type' => 'composite', 'name' => 'DataGrid', 'props' => ['columns' => $columns]]]];
        $this->assertStringContainsString('<column', $this->decompiler->decompile($grid));
        $this->assertRoundTrips($grid);

        $other = ['layout_name' => 'o', 'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['columns' => $columns]]]];
        $markup = $this->decompiler->decompile($other);
        $this->assertStringContainsString('<column field="a"', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($other);

        // 태그가 되돌리지 못하는 값(field 없음)은 `<prop>` 폴백 — 탈출 없이 왕복한다.
        $bare = ['layout_name' => 'b', 'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['columns' => [['header' => 'A']]]]]];
        $markup = $this->decompiler->decompile($bare);
        $this->assertStringNotContainsString('<column', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertRoundTrips($bare);
    }

    /**
     * 종전 부모가 아닌 컴포넌트의 hoist props 는 부모 무관 표기(`<prop-card>`·`<prop-footer>`·`<options>` 등)로 되돌린다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_emits_parent_independent_hoist_tags_for_other_parents
     *
     * @return void 반환값 없음
     */
    public function test_other_parents_decompile_to_parent_independent_hoist_tags(): void
    {
        $span = ['type' => 'basic', 'name' => 'Span', 'props' => ['text' => '{{row.t}}']];
        $json = ['layout_name' => 'h', 'components' => [[
            'type' => 'composite',
            'name' => 'MyTable',
            'props' => [
                'cardColumns' => [['id' => 'c1', 'cellChildren' => [$span]]],
                'footerCells' => [['field' => 'total', 'text' => '합계']],
                'expandChildren' => [$span],
                'titlePrefix' => [$span],
                'footer' => [['type' => 'basic', 'name' => 'Button', 'text' => '저장']],
                'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B', 'disabled' => true]],
            ],
        ]]];

        $markup = $this->decompiler->decompile($json);

        foreach (['<prop-card id="c1">', '<footer-cell field="total"', '<expand>', '<title-prefix>', '<prop-footer>', '<options>'] as $tag) {
            $this->assertStringContainsString($tag, $markup);
        }

        $this->assertStringNotContainsString('<prop name=', $markup);
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertStringNotContainsString('<card ', $markup);
        $this->assertStringNotContainsString('<footer>', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * 종전 부모(DataGrid·CardGrid·Modal·Select)의 역변환 산출은 옛 표기 그대로다 — 기존 소스와 한 글자도 다르지 않다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_keeps_legacy_tags_for_legacy_parents
     *
     * @return void 반환값 없음
     */
    public function test_legacy_parents_keep_legacy_hoist_tags(): void
    {
        $span = ['type' => 'basic', 'name' => 'Span'];
        // 문서 직속 `<modal>` 은 modals 섹션이므로 노드 자리(Div 안)에 둔다.
        $json = ['layout_name' => 'l', 'components' => [['type' => 'basic', 'name' => 'Div', 'children' => [
            ['type' => 'composite', 'name' => 'CardGrid', 'props' => ['cardColumns' => [['id' => 'c1', 'cellChildren' => [$span]]]]],
            ['type' => 'composite', 'name' => 'Modal', 'props' => ['footer' => [$span]]],
            ['type' => 'basic', 'name' => 'Select', 'props' => ['options' => [['value' => 'a', 'label' => 'A']]]],
        ]]]];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<card id="c1">', $markup);
        $this->assertStringContainsString('<footer>', $markup);
        $this->assertStringContainsString('<options>', $markup);
        $this->assertStringNotContainsString('<prop-card', $markup);
        $this->assertStringNotContainsString('<prop-footer', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `sortable.wrapperElement` 는 엔진 정식 소비 키다(DynamicRenderer:2910) — 속성으로 왕복한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects sortable_wrapper_element_round_trips
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * 사어 키는 이관이 옮기지 않는다 — 탈출구도 타지 않고 그냥 사라진다 (설계 결정 2026-07-20).
     *
     * `schema`/`pageConfig` 는 plugin_settings 잔재로 프론트 참조 0건(설정 검증은 PHP
     * `getSettingsSchema()` 별도 축), `_comment_*` 변형은 서빙 strip 대상도 아니면서
     * (LayoutService 는 `comment`/`_comment` 두 키만 걷는다) 읽히지도 않는 잡음이다.
     *
     * @scenario compile_unit=decompile,source_health=defect
     *
     * @effects dead_keys_are_dropped_not_escaped
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * 상태 트리의 `<field>` 도 `<item>` 자식을 받는다 (D53 G18).
     *
     * 종전에는 `<field>` 컴파일러가 **두 벌**이었다 — `StructuredValueCompiler`(G3·G4·G17, `<item>`
     * 지원)와 `StateTreeCompiler`(`field` 이외 자식 전부 거부). 같은 태그가 자리마다 다른 능력을
     * 가지면 작성자는 어디서 무엇이 되는지 외워야 하고, 실제로 상태 트리의 문자열 리스트가
     * `:value='["a","b"]'` 통짜 JSON 으로 남고 있었다(ecommerce 13건/6파일).
     *
     * 해소는 표기 신설이 아니라 **통일**이다 — 상태 트리가 같은 SSoT 를 태운다(G14 가 `compileSeo` 를
     * 기존 `compileStructuredValue` 로 통일한 것과 같은 규율).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects state_tree_field_accepts_item_children
     *
     * @return void 반환값 없음
     */
    public function test_state_tree_string_list_emits_item_tags(): void
    {
        $json = [
            'layout_name' => 'state_list',
            'initLocal' => [
                'visibleColumns' => ['order_no', 'buyer', 'amount'],
                'filter' => ['statuses' => ['paid', 'shipped']],
            ],
        ];

        $markup = $this->decompiler->decompile($json);
        $flat = preg_replace('/\s+</', '<', $markup);

        $this->assertStringContainsString(
            '<field name="visibleColumns"><item>order_no</item><item>buyer</item><item>amount</item></field>',
            $flat,
        );
        $this->assertStringContainsString(
            '<field name="statuses"><item>paid</item><item>shipped</item></field>',
            $flat,
        );
        $this->assertStringNotContainsString('<json', $markup);
        $this->assertSame([], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
        $this->assertRoundTrips($json);
    }

    /**
     * 표기 불가한 상태 트리 리스트는 종전대로 `:value` 리터럴로 남는다 (G18 대조군).
     *
     * `["", "", ""]`(빈 문자열 원소)·`[null, null]`(스칼라 원소)은 `<item>` 이 객체와 구별되지 않아
     * **D42 가 표기를 주지 않기로 확정한** 형태다({@see EmitsMarkup::isStructuredValue}). 이 분기를
     * 세우지 않으면 G18 이 "모든 리스트를 `<item>` 으로" 로 과확장돼도 아무도 못 잡는다 — 그 상태에서
     * 왕복이 깨지고, 코퍼스에는 표본(ecommerce `selectedCategories`)이 대장으로만 등재돼 있다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects state_tree_field_accepts_item_children
     *
     * @return void 반환값 없음
     */
    public function test_unrepresentable_state_tree_list_stays_value_literal(): void
    {
        $json = [
            'layout_name' => 'state_list_scalar',
            'initLocal' => [
                'selectedCategories' => [null, null, null, null],
                'blanks' => ['', '', ''],
                'empty' => [],
            ],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString(':value="[null,null,null,null]"', $markup);
        $this->assertStringContainsString(':value=\'["","",""]\'', $markup);
        $this->assertStringContainsString(':value="[]"', $markup);
        $this->assertStringNotContainsString('<item', $markup);
        $this->assertRoundTrips($json);
    }

    /**
     * `state` 별칭 승격 — 단독이면 정본 `initLocal` 로 방출된다(엔진 `initLocal || state` 동치).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects state_alias_promoted_to_init_local
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * named action 의 `comment` 는 `<action name>` 래퍼 앞에 **한 번만** 찍히고, 프로덕션 컴파일이 받는다.
     *
     * [case:layout-markup-named-action-comment-duplicate] 종전에는 래퍼와 안쪽 핸들러 태그가 같은 배열을 읽어 같은 주석을 두 번 달았고,
     * `DocumentCompiler` 를 직접 부르던 왕복 오라클은 통과시켰지만 설치·편집기가 타는 프로덕션 진입점
     * (`LayoutMarkupCompiler`)은 `comment_not_attachable` 로 거부했다 — 코퍼스 12파일이 HTML 탭에서
     * 저장 불가였다. 세 형태(단일 핸들러·sequence·actionRef) 모두 주석 1회 + 프로덕션 왕복을 잠근다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects named_action_comment_is_emitted_once_and_survives_production_compile
     *
     * @param  array<string, mixed>  $action  주석을 단 이름 있는 액션 JSON
     * @return void 반환값 없음
     */
    #[DataProvider('commentedNamedActionProvider')]
    public function test_named_action_comment_is_emitted_once_and_round_trips_in_production(array $action): void
    {
        $json = [
            'layout_name' => 'named_comment',
            'version' => '1.0.0',
            'named_actions' => [
                'other' => ['handler' => 'setState', 'params' => ['target' => 'local', 'z' => '1']],
                'probe' => $action,
            ],
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertSame(1, substr_count($markup, $action['comment']), "주석이 한 번만 찍혀야 합니다:
{$markup}");
        $this->assertStringNotContainsString('<json', $markup);

        // 오라클도 프로덕션 진입점이다 — 주석 귀속 검사·강검증·lint 를 지난 뒤의 왕복 동등을 본다.
        $result = (new LayoutMarkupRoundTrip)->verify($json, $this->registry);

        $this->assertNull($result['error'], (string) $result['error']);
        $this->assertTrue($result['ok'], "프로덕션 왕복 불일치:
{$markup}");
    }

    /**
     * 주석을 단 이름 있는 액션 사례 목록.
     *
     * @return array<string, array<int, array<string, mixed>>> 사례 이름 → 액션 JSON
     */
    public static function commentedNamedActionProvider(): array
    {
        return [
            'single handler' => [[
                'comment' => '검색 실행 — 페이지는 1로',
                'handler' => 'setState',
                'params' => ['target' => 'local', 'page' => 1],
            ]],
            'sequence' => [[
                'comment' => '검색 진입점 — 펼침 상태를 먼저 비운다',
                'handler' => 'sequence',
                'actions' => [
                    ['handler' => 'setState', 'params' => ['target' => 'local', 'a' => '1']],
                    ['handler' => 'setState', 'params' => ['target' => 'local', 'b' => '2']],
                ],
            ]],
            'actionRef' => [[
                'comment' => '다른 이름 액션으로 위임',
                'actionRef' => 'other',
            ]],
        ];
    }

    /**
     * 인라인 모달의 `layout_name`(설치가 조각을 인라인하며 붙인 이름)은 `<modal name>` 으로 되돌아간다 —
     * 종전엔 `<json key="layout_name">` 탈출구였다(DB 6문서·10건).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects inline_modal_layout_name_round_trips_as_modal_name_attribute
     *
     * @return void 반환값 없음
     */
    public function test_inline_modal_layout_name_round_trips_as_name_attribute(): void
    {
        $json = [
            'layout_name' => 'modal_name',
            'version' => '1.0.0',
            'components' => [['type' => 'basic', 'name' => 'Div']],
            'modals' => [[
                'comment' => '삭제 확인',
                'layout_name' => 'partials/_confirm',
                'id' => 'confirm',
                'type' => 'composite',
                'name' => 'Modal',
                'props' => ['title' => '확인'],
            ]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<modal name="partials/_confirm">', $markup);
        $this->assertStringNotContainsString('<json', $markup);

        $result = (new LayoutMarkupRoundTrip)->verify($json, $this->registry);

        $this->assertNull($result['error'], (string) $result['error']);
        $this->assertTrue($result['ok'], "프로덕션 왕복 불일치:
{$markup}");
    }

    /**
     * 인라인 모달 본문의 `data_sources`(창이 열릴 때 받는 것)는 본문 루트의 `<data-source>` 자식으로
     * 되돌아간다 — 종전엔 `<json key="data_sources">` 탈출구였다(설치본 7건).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects inline_modal_data_sources_round_trip_as_body_data_source_children
     *
     * @return void 반환값 없음
     */
    public function test_inline_modal_data_sources_round_trip_as_data_source_children(): void
    {
        $json = [
            'layout_name' => 'modal_sources',
            'version' => '1.0.0',
            'components' => [['type' => 'basic', 'name' => 'Div']],
            'modals' => [[
                'comment' => '상세 창 — 열릴 때 단건을 받는다',
                'layout_name' => 'partials/_detail',
                'data_sources' => [[
                    'id' => 'moduleDetail',
                    'type' => 'api',
                    'endpoint' => '/api/admin/modules/{{_global.selected.identifier}}',
                    'auth_required' => true,
                    'initGlobal' => 'selected',
                ]],
                'id' => 'detail',
                'type' => 'composite',
                'name' => 'Modal',
                'props' => ['title' => '상세'],
                'children' => [['type' => 'basic', 'name' => 'Div', 'text' => '본문']],
            ]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('<modal name="partials/_detail">', $markup);
        $this->assertStringContainsString('<data-source id="moduleDetail"', $markup);
        $this->assertStringNotContainsString('<json', $markup);

        $result = (new LayoutMarkupRoundTrip)->verify($json, $this->registry);

        $this->assertNull($result['error'], (string) $result['error']);
        $this->assertTrue($result['ok'], "프로덕션 왕복 불일치:\n{$markup}");
    }

    /**
     * 설치가 조각을 **노드 자리에** 인라인하며 남긴 `layout_name` 은 `data-layout-name` 으로 되돌아간다 —
     * 종전엔 `<json key="layout_name">` 탈출구였다(설치본 7건).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects inlined_node_layout_name_round_trips_as_data_layout_name
     *
     * @return void 반환값 없음
     */
    public function test_inlined_node_layout_name_round_trips_as_directive(): void
    {
        $json = [
            'layout_name' => 'host',
            'version' => '1.0.0',
            'components' => [[
                'type' => 'basic',
                'name' => 'Div',
                'children' => [[
                    'comment' => '왼쪽 패널 — 설치가 조각을 여기에 펼쳤다',
                    'layout_name' => 'panel_menu_list',
                    'meta' => ['is_partial' => true],
                    'id' => 'panel',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => ['className' => 'admin-card'],
                ]],
            ]],
        ];

        $markup = $this->decompiler->decompile($json);

        $this->assertStringContainsString('data-layout-name="panel_menu_list"', $markup);
        $this->assertStringNotContainsString('<json', $markup);

        $result = (new LayoutMarkupRoundTrip)->verify($json, $this->registry);

        $this->assertNull($result['error'], (string) $result['error']);
        $this->assertTrue($result['ok'], "프로덕션 왕복 불일치:\n{$markup}");
    }

    /**
     * JSON 레이아웃이 `compile(decompile(json))` 후 (정규화 기준) 동일함을 단언합니다.
     *
     * @param  array<string, mixed>  $json  원본 JSON
     * @return void 반환값 없음
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
