<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\NodeCompiler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * NodeCompiler 단위 테스트 (`.g7.html` 마크업 형식 S2 — 매핑 사전 C).
 *
 * 검증 축은 셋이다:
 *
 *  1. **fixture 쌍** — `.g7.html` 을 컴파일한 결과가 짝 `.json` 과 **키 순서까지** 같은가.
 *     순서를 함께 보는 이유는 산출 JSON 의 결정적 직렬화가 diff·골든 테스트의 성립 조건이기
 *     때문이다(같은 입력이 매번 같은 바이트를 내야 한다).
 *  2. **표기 규칙** — 속성 3분할·지시 속성 타입·불리언 표기·외래 관용구 거부가 규정대로인가.
 *  3. **선평가 금지** — 컴파일러가 `{{}}`/`$t:` 를 어느 위치에서도 풀지 않는가. 풀어 버리면
 *     런타임이 받을 원문이 사라져 화면이 조용히 달라진다(skipBindingKeys 패리티).
 *
 * 레지스트리는 번들 `sirsoft-admin_basic/components.json` 실물을 쓴다. 합성 매니페스트로
 * 대신하면 "우리끼리만 맞는" 태그 해석을 검증하게 되어, 실제 템플릿과 어긋나도 green 이 된다.
 */
class NodeCompilerTest extends LayoutMarkupTestCase
{
    private NodeCompiler $compiler;

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new NodeCompiler(
            ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json')),
        );
    }

    // ---------------------------------------------------------------------
    // fixture 쌍 (HTML ↔ JSON)
    // ---------------------------------------------------------------------

    /**
     * 픽스처 마크업이 기대 JSON 으로 정확히 컴파일된다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects tag_resolves_to_component_name_and_type,
     *          attributes_split_into_props_directives_and_type_literals,
     *          node_json_key_order_is_deterministic
     *
     * @param  string  $fixture  픽스처 이름
     * @return void 반환값 없음
     */
    #[DataProvider('fixtureProvider')]
    public function test_fixture_pair_compiles_to_expected_json(string $fixture): void
    {
        $directory = __DIR__.'/fixtures/nodes';

        $node = $this->compile(file_get_contents("{$directory}/{$fixture}.g7.html"));
        $expected = json_decode(file_get_contents("{$directory}/{$fixture}.json"), true);

        // 배열 비교가 아니라 인코딩 문자열 비교다 — 배열 비교는 키 순서 차이를 통과시킨다.
        $this->assertSame($this->encode($expected), $this->encode($node));
    }

    /**
     * 픽스처 쌍(마크업·기대 JSON) 목록.
     *
     * @return array<string, 반환값
     */
    public static function fixtureProvider(): array
    {
        return [
            '속성 3분할' => ['attribute_trisection'],
            '반복·조건' => ['iteration_and_conditions'],
            '구조체 노드 키' => ['structural_keys'],
            '텍스트 shape' => ['text_shapes'],
        ];
    }

    // ---------------------------------------------------------------------
    // 태그 해석
    // ---------------------------------------------------------------------

    /**
     * 태그 → 이름은 표기 규칙, 종류는 `data-type`(생략 시 basic) — 템플릿 컴포넌트 목록을 보지 않는다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects tag_resolves_to_component_name_and_type
     *
     * @return void 반환값 없음
     */
    public function test_tag_resolves_name_by_notation_and_type_by_data_type(): void
    {
        $node = $this->compile('<rich-select data-type="composite" data-ref="picker"/>');

        $this->assertSame('composite', $node['type']);
        $this->assertSame('RichSelect', $node['name']);
        // `data-type` 은 노드 키로 소비되고 props 로 새지 않는다.
        $this->assertArrayNotHasKey('data-type', $node['props'] ?? []);

        $this->assertSame(['type' => 'basic', 'name' => 'Div'], array_intersect_key($this->compile('<div/>'), ['type' => 1, 'name' => 1]));
        $this->assertSame('layout', $this->compile('<container data-type="layout"/>')['type']);
    }

    /**
     * 템플릿에 없는 태그도 표기 규칙으로 컴파일된다 — 컴포넌트가 있는지는 JSON 과 같이 런타임이 판정한다.
     *
     * 템플릿마다 결과가 갈리면 설치·템플릿 교체·조각 주입이 템플릿에 묶인다(중대 설계 결함의 원인).
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects compile_output_does_not_depend_on_any_template
     *
     * @return void 반환값 없음
     */
    public function test_tag_not_in_any_template_still_compiles_by_notation(): void
    {
        $node = $this->compile('<acme-rating-stars data-type="composite" value="3"/>');

        $this->assertSame('AcmeRatingStars', $node['name']);
        $this->assertSame('composite', $node['type']);
    }

    /**
     * `data-type` 은 세 값 중 하나를 문자 그대로 적어야 한다.
     *
     * @scenario compile_unit=node,source_health=invalid
     *
     * @effects invalid_component_type_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_invalid_component_type_is_rejected(): void
    {
        $this->assertSame(LayoutMarkupErrorCode::InvalidComponentType, $this->compileError('<div data-type="widget"/>')->getErrorCode());
        $this->assertSame(LayoutMarkupErrorCode::InvalidComponentType, $this->compileError('<div :data-type="\'composite\'"/>')->getErrorCode());
    }

    /**
     * 문서 루트 전용 태그가 노드 자리에 오면 거부된다.
     *
     * @effects root_only_tag_in_node_position_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_root_only_tag_in_node_position_is_rejected(): void
    {
        // `<partial>` 은 문서 루트 봉투이고 `<fragment>` 는 React Fragment 컴포넌트다.
        // 이름이 갈려 있으므로 문맥 분기 없이 태그명만으로 판정된다.
        $this->assertSame(
            LayoutMarkupErrorCode::RootTagInNodePosition,
            $this->compileError('<partial><div/></partial>')->getErrorCode(),
        );

        $this->assertSame('Fragment', $this->compile('<fragment><div/></fragment>')['name']);
    }

    /**
     * `<include>` 는 조각 참조 노드로 컴파일된다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects include_compiles_to_a_partial_reference_node
     *
     * @return void 반환값 없음
     */
    public function test_include_compiles_to_a_partial_reference_node(): void
    {
        // `<include src>` → `{partial: src}` — 설치 시 resolveAllPartials 가 인라인한다.
        $this->assertSame(
            ['partial' => 'partials/_summary.json'],
            $this->compile('<include src="partials/_summary.g7.html"/>'),
        );
    }

    /**
     * 조각 참조는 다른 속성·자식을 받지 않는다.
     *
     * @scenario compile_unit=node,source_health=invalid
     *
     * @effects include_reference_rejects_extra_attributes_and_children
     *
     * @param  string  $source  마크업 소스
     * @param  LayoutMarkupErrorCode  $code  오류 코드
     * @return void 반환값 없음
     */
    #[DataProvider('invalidIncludeProvider')]
    public function test_include_reference_rejects_extra_attributes_and_children(string $source, LayoutMarkupErrorCode $code): void
    {
        // 참조 노드는 partial 하나로 치환되므로 다른 속성/자식은 침묵 소실된다 — 표기 오류로 세운다.
        $this->assertSame($code, $this->compileError($source)->getErrorCode());
    }

    /**
     * 잘못된 `<include>` 사례 목록.
     *
     * @return array<string, 반환값
     */
    public static function invalidIncludeProvider(): array
    {
        return [
            'src 누락' => ['<include/>', LayoutMarkupErrorCode::MissingRequiredAttribute],
            '추가 속성' => ['<include src="x" class="y"/>', LayoutMarkupErrorCode::NodeKeyAsAttribute],
            '자식 존재' => ['<include src="x"><div/></include>', LayoutMarkupErrorCode::MixedTextAndChildren],
        ];
    }

    /**
     * 문서 섹션 태그가 노드 자리에 오면 거부된다.
     *
     * @scenario compile_unit=node,source_health=invalid
     *
     * @effects document_section_tag_in_node_position_is_rejected
     *
     * @param  string  $source  마크업 소스
     * @return void 반환값 없음
     */
    #[DataProvider('sectionTagProvider')]
    public function test_document_section_tag_in_node_position_is_rejected(string $source): void
    {
        // 문서 최상위 섹션(S4 구현)은 문서 봉투 직속 전용이다. 컴포넌트 자리에 오면 이월이 아니라
        // 자리 오류이므로 "아직 다루지 않음" 이 아니라 "여기 쓸 수 없음" 으로 안내한다.
        $this->assertSame(
            LayoutMarkupErrorCode::SectionTagInNodePosition,
            $this->compileError($source)->getErrorCode(),
        );
    }

    /**
     * 노드 자리에 온 문서 섹션 태그 목록.
     *
     * @return array<string, 반환값
     */
    public static function sectionTagProvider(): array
    {
        return [
            '문서 섹션 <data-source>' => ['<data-source id="users"/>'],
            '문서 섹션 <computed>' => ['<computed name="total">{{a}}</computed>'],
            '중첩 섹션 <from-source>' => ['<from-source>src</from-source>'],
        ];
    }

    /**
     * 액션 전용 태그가 노드 자리에 오면 거부된다.
     *
     * @scenario compile_unit=node,source_health=invalid
     *
     * @effects action_tag_in_node_position_is_rejected
     *
     * @param  string  $source  마크업 소스
     * @return void 반환값 없음
     */
    #[DataProvider('actionTagProvider')]
    public function test_action_tag_in_node_position_is_rejected(string $source): void
    {
        // 액션 태그(S3 구현)는 이벤트 컨텍스트(`<on>` 안) 전용이다. 컴포넌트 자리에 오면 이월이
        // 아니라 자리 오류이므로 "아직 다루지 않음" 이 아니라 "여기 쓸 수 없음" 으로 안내한다.
        $this->assertSame(
            LayoutMarkupErrorCode::ActionTagInNodePosition,
            $this->compileError($source)->getErrorCode(),
        );
    }

    /**
     * 노드 자리에 온 액션 전용 태그 목록.
     *
     * @return array<string, 반환값
     */
    public static function actionTagProvider(): array
    {
        return [
            '<on> 루트' => ['<on event="click"><navigate path="/x"/></on>'],
            '<switch> 노드 자리' => ['<switch value="{{x}}"/>'],
            '<case> 노드 자리' => ['<case when="edit"/>'],
            '<sequence> 노드 자리' => ['<sequence/>'],
            '<success> 노드 자리' => ['<success/>'],
        ];
    }

    /**
     * 컴포넌트 이름과 겹치는 태그는 컴포넌트로 해석된다.
     *
     * @effects tags_colliding_with_component_names_resolve_to_the_component
     *
     * @return void 반환값 없음
     */
    public function test_tags_that_collide_with_component_names_resolve_to_the_component(): void
    {
        // `<modal>`·`<option>`·`<card>` 는 이월 표기이면서 **등록 컴포넌트 이름**이기도 하다.
        // 태그 해석이 먼저 성공하므로 이월 목록에 넣어도 죽은 항목이 된다 — 같은 태그를 문서
        // 섹션이냐 컴포넌트냐로 가르는 것은 위치를 아는 상위 단계(S4·S5)의 몫이다.
        $this->assertSame('Modal', $this->compile('<modal data-ref="m"/>')['name']);
        $this->assertSame('Option', $this->compile('<option value="1"/>')['name']);
        $this->assertSame('Card', $this->compile('<card/>')['name']);
    }

    /**
     * 노드 id 는 data-ref 를 적었을 때만 나온다.
     *
     * @effects auto_generated_id_is_not_emitted
     *
     * @return void 반환값 없음
     */
    public function test_id_is_emitted_only_when_data_ref_is_authored(): void
    {
        $this->assertArrayNotHasKey('id', $this->compile('<div class="card"/>'));
        $this->assertSame('user_card', $this->compile('<div data-ref="user_card"/>')['id']);

        // 반복 안에서 노드 id 를 유일하게 만드는 표현식 id 는 원문 그대로 실린다.
        $this->assertSame(
            'recent_notification_item_{{$idx}}',
            $this->compile('<div data-ref="recent_notification_item_{{$idx}}"/>')['id'],
        );
    }

    // ---------------------------------------------------------------------
    // 속성 3분할
    // ---------------------------------------------------------------------

    /**
     * 이름이 바뀌는 속성은 class·for 둘뿐이다.
     *
     * @effects attributes_split_into_props_directives_and_type_literals
     *
     * @return void 반환값 없음
     */
    public function test_class_and_for_are_the_only_renamed_attributes(): void
    {
        $node = $this->compile('<label class="lbl" for="email" data-section-id="profile" placeholder="이름"/>');

        $this->assertSame('lbl', $node['props']['className']);
        $this->assertSame('email', $node['props']['htmlFor']);
        // 별칭은 이 둘뿐이다 — 그 밖의 속성명은 원문 그대로다(G7 props 는 camel·snake 혼재).
        $this->assertSame('이름', $node['props']['placeholder']);
        // 예약되지 않은 data-* 는 진짜 DOM data 속성이라 원문 그대로 props 로 간다.
        $this->assertSame('profile', $node['props']['data-section-id']);
    }

    /**
     * 별칭 속성과 대상 속성을 함께 쓸 수 없다.
     *
     * @scenario compile_unit=attribute,source_health=invalid
     *
     * @effects attributes_split_into_props_directives_and_type_literals
     *
     * @return void 반환값 없음
     */
    public function test_alias_and_target_attribute_cannot_both_be_authored(): void
    {
        // 토크나이저의 중복 검사는 같은 이름만 본다. `for` 와 `htmlFor` 는 이름이 다르면서
        // 같은 자리를 차지하므로 여기서 잡지 않으면 나중 것이 앞엣것을 조용히 지운다.
        $error = $this->compileError('<label for="email" htmlFor="other"/>');

        $this->assertSame(LayoutMarkupErrorCode::DuplicateAttribute, $error->getErrorCode());
        $this->assertStringContainsString('htmlFor', $error->getDetail());

        $this->assertSame(
            LayoutMarkupErrorCode::DuplicateAttribute,
            $this->compileError('<div class="a" className="b"/>')->getErrorCode(),
        );
    }

    /**
     * `<prop>` 태그가 같은 이름의 속성을 덮어쓸 수 없다.
     *
     * @scenario compile_unit=structural_tag,source_health=invalid
     *
     * @effects attributes_split_into_props_directives_and_type_literals
     *
     * @return void 반환값 없음
     */
    public function test_prop_tag_cannot_overwrite_an_attribute(): void
    {
        // `<prop>` 은 속성과 같은 자리(props)를 채운다. 가드를 우회하면 class 가 조용히 사라진다.
        $error = $this->compileError('<div class="a"><prop name="className">"덮어씀"</prop></div>');

        $this->assertSame(LayoutMarkupErrorCode::DuplicateAttribute, $error->getErrorCode());
        $this->assertStringContainsString('className', $error->getDetail());
    }

    /**
     * 노드 키를 무접두 속성으로 쓰면 거부된다.
     *
     * @scenario compile_unit=attribute,source_health=invalid
     *
     * @effects node_key_written_as_attribute_is_rejected
     *
     * @param  string  $source  마크업 소스
     * @param  string  $suggestion  제안 표기
     * @return void 반환값 없음
     */
    #[DataProvider('nodeKeyAttributeProvider')]
    public function test_node_key_written_as_bare_attribute_is_rejected(string $source, string $suggestion): void
    {
        // 옛 JSON 을 손으로 옮기면 노드 키를 그대로 속성에 적기 쉽다. 무접두는 props 규칙이라
        // 조용히 통과하는데, 엔진은 노드 키만 읽으므로 아무 동작도 하지 않는 노드가 나온다.
        $error = $this->compileError($source);

        $this->assertSame(LayoutMarkupErrorCode::NodeKeyAsAttribute, $error->getErrorCode());
        $this->assertStringContainsString($suggestion, $error->getDetail());
    }

    /**
     * 무접두 속성으로 쓴 노드 키 사례 목록.
     *
     * @return array<string, 반환값
     */
    public static function nodeKeyAttributeProvider(): array
    {
        return [
            'condition (if 별칭)' => ['<div condition="{{_local.show}}"/>', 'data-if'],
            'iteration' => ['<div iteration="{{users}}"/>', 'data-each'],
            'dataKey' => ['<form dataKey="loginForm"/>', 'data-key'],
            'debounce' => ['<input debounce="300"/>', 'data-debounce'],
            'trackChanges' => ['<form trackChanges="true"/>', 'data-track-changes'],
            'slotOrder' => ['<div slotOrder="2"/>', 'data-slot-order'],
            'classMap' => ['<div classMap="{}"/>', '<class-map>'],
            'comment' => ['<div comment="설명"/>', '<!-- -->'],
            'blur_until_loaded' => ['<div blur_until_loaded="true"/>', 'data-blur-until-loaded'],
            'isolatedState' => ['<div isolatedState="{}"/>', '<isolated-state>'],
        ];
    }

    /**
     * 노드 키와 이름이 같은 실제 prop 은 그대로 통과한다.
     *
     * @effects attributes_split_into_props_directives_and_type_literals
     *
     * @return void 반환값 없음
     */
    public function test_node_key_names_that_are_real_props_still_pass(): void
    {
        // 노드 키와 이름이 같아도 props 로 실사용되는 것은 막지 않는다 — 막으면 정상 마크업이
        // 거부된다. slot(Dropdown trigger/content 배치 4건)·text(24건)·style(13건)·id·type·name.
        $this->assertSame('trigger', $this->compile('<div slot="trigger"/>')['props']['slot']);
        $this->assertSame('안내', $this->compile('<status-badge text="안내"/>')['props']['text']);
        $this->assertSame('margin-left: 1rem', $this->compile('<div style="margin-left: 1rem"/>')['props']['style']);
        $this->assertSame('email', $this->compile('<input id="email"/>')['props']['id']);
    }

    /**
     * `is` 속성은 거부된다.
     *
     * @scenario compile_unit=attribute,source_health=invalid
     *
     * @effects unsupported_standard_attribute_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_is_attribute_is_rejected(): void
    {
        // customized built-in element 용 속성이라 태그를 커스텀 엘리먼트로 해석하는 이 포맷과
        // 충돌한다 — 표준 전역 속성 통과의 유일한 예외(계획 D9).
        $this->assertSame(
            LayoutMarkupErrorCode::AttributeNotSupported,
            $this->compileError('<div is="my-element"/>')->getErrorCode(),
        );
    }

    /**
     * props 는 매니페스트 선언과 대조하지 않고 그대로 통과한다.
     *
     * @effects props_are_passed_through_without_whitelist_validation
     *
     * @return void 반환값 없음
     */
    public function test_props_are_not_validated_against_manifest_declarations(): void
    {
        // components.json 의 prop 선언은 부분 목록이다(번들 실측 커버리지 75~77%).
        // 화이트리스트로 강제하면 Button.type 같은 정상 마크업이 거부된다.
        $node = $this->compile('<button type="button" undeclaredProp="x"/>');

        $this->assertSame('button', $node['props']['type']);
        $this->assertSame('x', $node['props']['undeclaredProp']);
    }

    /**
     * 불리언 속성은 있기만 하면 참이다.
     *
     * @scenario compile_unit=attribute,source_health=valid
     *
     * @effects boolean_attribute_is_present_or_absent_and_literal_false_needs_type_prefix
     *
     * @return void 반환값 없음
     */
    public function test_boolean_attribute_is_true_when_present(): void
    {
        $this->assertTrue($this->compile('<input required/>')['props']['required']);
        $this->assertArrayNotHasKey('props', $this->compile('<input/>'));

        // 리터럴 false 를 정말 전달해야 하는 자리는 타입 리터럴로 쓴다 (`isHtml: false` 실사용 1건).
        $this->assertFalse($this->compile('<html-content :isHtml="false"/>')['props']['isHtml']);
    }

    /**
     * 불리언 속성에 문자열 값을 쓰면 거부된다.
     *
     * @scenario compile_unit=attribute,source_health=invalid
     *
     * @effects boolean_attribute_is_present_or_absent_and_literal_false_needs_type_prefix
     *
     * @return void 반환값 없음
     */
    public function test_boolean_attribute_with_literal_string_is_rejected(): void
    {
        // HTML5 는 불리언 속성의 값을 보지 않는다 — `required="false"` 는 표준상 참이라
        // 작성 의도와 정반대로 동작한다.
        $this->assertSame(
            LayoutMarkupErrorCode::BooleanAttributeFalse,
            $this->compileError('<input required="false"/>')->getErrorCode(),
        );

        $this->assertSame(
            LayoutMarkupErrorCode::BooleanAttributeFalse,
            $this->compileError('<input disabled="true"/>')->getErrorCode(),
        );

        // 표현식은 불리언 속성에서도 정상이다.
        $this->assertSame('{{!_local.canSave}}', $this->compile('<input disabled="{{!_local.canSave}}"/>')['props']['disabled']);
    }

    /**
     * 타입 리터럴 속성은 JSON 값으로 풀린다.
     *
     * @effects type_literal_holds_json_typed_values
     *
     * @return void 반환값 없음
     */
    public function test_type_literal_decodes_json_values(): void
    {
        $props = $this->compile('<data-grid :columns="[]" :pageSize="20" :meta="{&quot;a&quot;:1}" :empty="null"/>')['props'];

        $this->assertSame([], $props['columns']);
        $this->assertSame(20, $props['pageSize']);
        $this->assertSame(['a' => 1], $props['meta']);
        $this->assertNull($props['empty']);
    }

    /**
     * 타입 리터럴에 표현식을 쓰면 일반 속성을 쓰라고 제안하며 거부된다.
     *
     * @scenario compile_unit=attribute,source_health=invalid
     *
     * @effects type_literal_holds_json_typed_values
     *
     * @return void 반환값 없음
     */
    public function test_type_literal_with_expression_is_rejected_with_plain_attribute_suggestion(): void
    {
        // Vue 의 `:class="x"` 반사 작성이 예상되는 지대다.
        $error = $this->compileError('<div :class="{{_local.cls}}"/>');

        $this->assertSame(LayoutMarkupErrorCode::InvalidTypeLiteral, $error->getErrorCode());
        $this->assertStringContainsString('class="..."', $error->getDetail());
    }

    // ---------------------------------------------------------------------
    // 지시 속성
    // ---------------------------------------------------------------------

    /**
     * 타입이 고정된 지시 속성에는 타입 리터럴 접두(:)를 쓸 수 없다.
     *
     * @effects directive_value_types_are_fixed_per_key
     *
     * @return void 반환값 없음
     */
    public function test_fixed_type_directives_reject_type_literal_prefix(): void
    {
        $error = $this->compileError('<div :data-slot-order="2"/>');

        $this->assertSame(LayoutMarkupErrorCode::DirectiveRejectsTypeLiteral, $error->getErrorCode());
        $this->assertStringContainsString('data-slot-order="..."', $error->getDetail());
    }

    /**
     * 정수 지시 속성에 정수가 아닌 값을 쓰면 거부된다.
     *
     * @effects directive_value_types_are_fixed_per_key
     *
     * @return void 반환값 없음
     */
    public function test_integer_directive_rejects_non_integer(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::DirectiveNeedsInteger,
            $this->compileError('<div data-debounce="빠르게"/>')->getErrorCode(),
        );
    }

    /**
     * 값 없는 지시 속성은 거부된다.
     *
     * @effects directive_value_types_are_fixed_per_key
     *
     * @return void 반환값 없음
     */
    public function test_directive_without_value_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::DirectiveNeedsValue,
            $this->compileError('<div data-if/>')->getErrorCode(),
        );
    }

    /**
     * 괄호 없는 조건식은 `{{}}` 로 감싸진다.
     *
     * @scenario compile_unit=attribute,source_health=valid
     *
     * @effects bare_condition_is_wrapped_and_mixed_interpolation_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_bare_condition_expression_is_wrapped(): void
    {
        // 감싸지 않은 식은 엔진이 문자열 보간으로 처리해 항상 참이 된다. 작성 시점에 감싼다.
        $this->assertSame('{{!row.email}}', $this->compile('<span data-if="!row.email"/>')['if']);
        $this->assertSame('{{!row.email}}', $this->compile('<span data-if="{{!row.email}}"/>')['if']);
    }

    /**
     * 보간이 섞인 조건식은 거부된다.
     *
     * @scenario compile_unit=attribute,source_health=invalid
     *
     * @effects bare_condition_is_wrapped_and_mixed_interpolation_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_mixed_interpolation_condition_is_rejected(): void
    {
        $error = $this->compileError('<span data-if="{{_local.tab}} === \'basic\'"/>');

        $this->assertSame(LayoutMarkupErrorCode::MixedIfExpression, $error->getErrorCode());
    }

    /**
     * 불리언 조건은 타입 리터럴 표기(:data-if)로 써야 한다.
     *
     * @scenario compile_unit=attribute,source_health=invalid
     *
     * @effects literal_boolean_condition_has_one_spelling
     *
     * @return void 반환값 없음
     */
    public function test_literal_boolean_condition_must_use_the_type_literal_form(): void
    {
        // `data-if="false"` 는 자동 래핑으로 `"{{false}}"` 를, `:data-if="false"` 는 불리언 false 를
        // 낸다 — 뜻이 같은데 산출 JSON 이 갈리면 역변환이 어느 쪽을 골라야 할지 알 수 없다.
        $error = $this->compileError('<button data-if="false"/>');

        $this->assertSame(LayoutMarkupErrorCode::ConditionBooleanLiteral, $error->getErrorCode());
        $this->assertStringContainsString(':data-if', $error->getDetail());

        $this->assertSame(
            LayoutMarkupErrorCode::ConditionBooleanLiteral,
            $this->compileError('<button data-if="true"/>')->getErrorCode(),
        );

        $this->assertFalse($this->compile('<button :data-if="false"/>')['if']);
    }

    /**
     * 반복 표기(data-each)는 원문 그대로 보존된다.
     *
     * @effects each_dsl_preserves_source_verbatim
     *
     * @return void 반환값 없음
     */
    public function test_each_dsl_preserves_source_verbatim(): void
    {
        // 엔진은 `{{}}` 래핑·bare 경로·bare 복합식을 동등하게 처리하고 번들 실사용도 반씩
        // 갈린다(래핑 252 / bare 254). 한쪽으로 정규화하면 그만큼 왕복이 깨진다.
        $wrapped = $this->compile('<div data-each="user, idx in {{users?.data ?? []}}"/>')['iteration'];
        $this->assertSame(['source' => '{{users?.data ?? []}}', 'item_var' => 'user', 'index_var' => 'idx'], $wrapped);

        $bare = $this->compile('<div data-each="coupon in coupons?.data?.data || []"/>')['iteration'];
        $this->assertSame(['source' => 'coupons?.data?.data || []', 'item_var' => 'coupon'], $bare);
    }

    /**
     * 반복 표기 문법이 틀리면 거부된다.
     *
     * @scenario compile_unit=attribute,source_health=invalid
     *
     * @effects each_dsl_preserves_source_verbatim
     *
     * @return void 반환값 없음
     */
    public function test_invalid_each_syntax_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::InvalidEachSyntax,
            $this->compileError('<div data-each="{{users}}"/>')->getErrorCode(),
        );
    }

    // ---------------------------------------------------------------------
    // 외래 관용구
    // ---------------------------------------------------------------------

    /**
     * 다른 프레임워크 관용 표기는 대안을 제안하며 거부된다.
     *
     * @scenario compile_unit=attribute,source_health=invalid
     *
     * @effects foreign_framework_idioms_are_rejected_with_g7_equivalent
     *
     * @param  string  $source  마크업 소스
     * @param  string  $suggestion  제안 표기
     * @return void 반환값 없음
     */
    #[DataProvider('foreignIdiomProvider')]
    public function test_foreign_idiom_is_rejected_with_suggestion(string $source, string $suggestion): void
    {
        $error = $this->compileError($source);

        $this->assertSame(LayoutMarkupErrorCode::ForeignIdiom, $error->getErrorCode());
        $this->assertStringContainsString($suggestion, $error->getDetail());
    }

    /**
     * 다른 프레임워크 관용 표기 사례 목록.
     *
     * @return array<string, 반환값
     */
    public static function foreignIdiomProvider(): array
    {
        return [
            'Vue 조건' => ['<div v-if="x"/>', 'data-if'],
            'Vue 반복' => ['<div v-for="u in users"/>', 'data-each'],
            'Vue 이벤트' => ['<button @click="save"/>', '<on event="click">'],
            'Vue 이벤트 (v-on)' => ['<button v-on:click="save"/>', '<on event="click">'],
            'Angular 조건' => ['<div *ngIf="x"/>', 'data-if'],
            'Alpine 반복' => ['<div x-for="u in users"/>', 'data-each'],
            '무접두 조건' => ['<div if="x"/>', 'data-if'],
            '무접두 반복' => ['<div each="u in users"/>', 'data-each'],
        ];
    }

    // ---------------------------------------------------------------------
    // 구조 태그
    // ---------------------------------------------------------------------

    /**
     * 구조 태그가 제 부모 밖에 오면 거부된다.
     *
     * @scenario compile_unit=structural_tag,source_health=invalid
     *
     * @effects structural_tag_outside_its_parent_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_structural_tag_outside_its_parent_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::UnexpectedStructuralChild,
            $this->compileError('<div><variant when="x">cls</variant></div>')->getErrorCode(),
        );

        $this->assertSame(
            LayoutMarkupErrorCode::UnexpectedStructuralChild,
            $this->compileError('<class-map key="{{x}}"><div/></class-map>')->getErrorCode(),
        );
    }

    /**
     * 필수 속성이 빠진 구조 태그는 거부된다.
     *
     * @effects structural_tag_requires_its_named_attribute
     *
     * @return void 반환값 없음
     */
    public function test_structural_tag_missing_required_attribute_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::MissingRequiredAttribute,
            $this->compileError('<div><class-map base="b"><variant when="x">c</variant></class-map></div>')->getErrorCode(),
        );

        $this->assertSame(
            LayoutMarkupErrorCode::MissingRequiredAttribute,
            $this->compileError('<div><prop>[]</prop></div>')->getErrorCode(),
        );
    }

    /**
     * 같은 노드 키를 두 번 쓰면 거부된다.
     *
     * @effects duplicate_node_key_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_node_key_is_rejected(): void
    {
        $error = $this->compileError('<div><class-map key="{{a}}"/><class-map key="{{b}}"/></div>');

        $this->assertSame(LayoutMarkupErrorCode::DuplicateNodeKey, $error->getErrorCode());
    }

    /**
     * 로딩 흐림 설정은 두 표기로 동시에 쓸 수 없다.
     *
     * @effects structural_tag_requires_its_named_attribute
     *
     * @return void 반환값 없음
     */
    public function test_blur_sources_cannot_be_written_in_both_forms(): void
    {
        // 문자열형과 배열형 표기가 같은 자리를 다투는데 하나를 고르면 나머지가 조용히 사라진다.
        $error = $this->compileError('<div><blur-until-loaded sources="a" :sources="[\'b\']"/></div>');

        $this->assertSame(LayoutMarkupErrorCode::DuplicateAttribute, $error->getErrorCode());
        $this->assertStringContainsString('sources', $error->getDetail());
    }

    /**
     * 데이터소스 목록은 `<item>` 리스트로 적는다 (D51 G16 — `:sources='["a","b"]'` 속성 JSON 대체).
     *
     * @effects blur_sources_accept_item_list
     *
     * @return void 반환값 없음
     */
    public function test_blur_sources_accept_item_list(): void
    {
        $node = $this->compile('<div><blur-until-loaded><item>myPosts</item><item>myComments</item></blur-until-loaded></div>');

        $this->assertSame(
            ['enabled' => true, 'data_sources' => ['myPosts', 'myComments']],
            $node['blur_until_loaded'],
        );
    }

    /**
     * `<item>` 리스트와 속성 표기를 함께 쓰면 어느 쪽이 정본인지 알 수 없다.
     *
     * @effects blur_sources_accept_item_list
     *
     * @return void 반환값 없음
     */
    public function test_blur_sources_reject_item_list_with_attribute(): void
    {
        $error = $this->compileError('<div><blur-until-loaded sources="a"><item>b</item></blur-until-loaded></div>');

        $this->assertSame(LayoutMarkupErrorCode::DuplicateAttribute, $error->getErrorCode());
    }

    /**
     * 상태 필드는 값을 명시해야 한다.
     *
     * @effects state_tree_leaf_type_follows_type_literal_rule
     *
     * @return void 반환값 없음
     */
    public function test_state_field_requires_an_explicit_value(): void
    {
        // 값도 자식도 없으면 `{}`·`null`·`""` 중 무엇인지 작성자만 안다. 짐작하면 초기 상태가
        // 조용히 달라지므로 명시를 요구한다.
        $this->assertSame(
            LayoutMarkupErrorCode::MissingRequiredAttribute,
            $this->compileError('<div><isolated-state><field name="x"/></isolated-state></div>')->getErrorCode(),
        );

        $this->assertSame(
            LayoutMarkupErrorCode::FieldValueAndChildren,
            $this->compileError('<div><isolated-state><field name="x" :value="1"><field name="y" :value="2"/></field></isolated-state></div>')->getErrorCode(),
        );
    }

    /**
     * `<prop>` 본문은 올바른 JSON 이어야 한다.
     *
     * @scenario compile_unit=structural_tag,source_health=invalid
     *
     * @effects structural_tag_json_body_must_be_valid_json
     *
     * @return void 반환값 없음
     */
    public function test_prop_body_must_be_valid_json(): void
    {
        $error = $this->compileError('<div><prop name="rowActions">[{id: edit}]</prop></div>');

        $this->assertSame(LayoutMarkupErrorCode::InvalidJsonBody, $error->getErrorCode());
    }

    // ---------------------------------------------------------------------
    // 텍스트
    // ---------------------------------------------------------------------

    /**
     * 텍스트와 자식 태그는 함께 둘 수 없다.
     *
     * @scenario compile_unit=node,source_health=invalid
     *
     * @effects text_and_children_cannot_coexist
     *
     * @return void 반환값 없음
     */
    public function test_text_and_children_cannot_coexist(): void
    {
        $error = $this->compileError('<div>안내 문구<span/></div>');

        $this->assertSame(LayoutMarkupErrorCode::MixedTextAndChildren, $error->getErrorCode());
    }

    /**
     * 구조 태그는 텍스트 옆에 올 수 있다.
     *
     * @scenario compile_unit=structural_tag,source_health=valid
     *
     * @effects structural_tag_may_sit_next_to_text
     *
     * @return void 반환값 없음
     */
    public function test_structural_tag_may_sit_next_to_text(): void
    {
        // 구조 태그는 자식 노드가 아니라 부모의 노드 키다. 걷어내고 나면 텍스트만 남는다.
        $node = $this->compile('<span><class-map key="{{k}}"><variant when="on">active</variant></class-map>라벨</span>');

        $this->assertSame('라벨', $node['text']);
        $this->assertArrayNotHasKey('children', $node);
        $this->assertSame(['on' => 'active'], $node['classMap']['variants']);
    }

    // ---------------------------------------------------------------------
    // 선평가 금지 (skipBindingKeys 패리티)
    // ---------------------------------------------------------------------

    /**
     * 표현식과 번역 키는 손대지 않고 통과한다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects expressions_and_translation_keys_are_never_pre_evaluated
     *
     * @return void 반환값 없음
     */
    public function test_expressions_and_translation_keys_pass_through_untouched(): void
    {
        $node = $this->compile(<<<'HTML'
            <data-grid data="{{coupons?.data ?? []}}" aria-label="$t:admin.users.title">
              <prop name="expandContext">{"rowId": "{{row.id}}", "label": "$t:defer:common.edit"}</prop>
              <prop name="render">{"cell": "{{row.name}}"}</prop>
            </data-grid>
            HTML);

        $this->assertSame('{{coupons?.data ?? []}}', $node['props']['data']);
        $this->assertSame('$t:admin.users.title', $node['props']['aria-label']);
        // expandContext·render 는 런타임이 선평가에서 제외하는 키다(DEFAULT_SKIP_BINDING_KEYS).
        $this->assertSame('{{row.id}}', $node['props']['expandContext']['rowId']);
        $this->assertSame('$t:defer:common.edit', $node['props']['expandContext']['label']);
        $this->assertSame('{{row.name}}', $node['props']['render']['cell']);
    }

    /**
     * 노드 레벨 `<permissions>` (D36) — 요소별 조건 렌더 권한을 node['permissions'] 로 컴파일한다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects node_level_permissions_compiles_to_permissions_key
     *
     * @return void 반환값 없음
     */
    public function test_node_level_permissions_compiles_to_permissions_key(): void
    {
        $node = $this->compile('<div><permissions><permission>core.modules.read</permission></permissions></div>');

        $this->assertSame(['core.modules.read'], $node['permissions']);
    }

    /**
     * `<input>` 은 void 에서 빠져(D36) `<on>` 액션 자식을 담는다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects input_is_not_void_and_holds_action_children
     *
     * @return void 반환값 없음
     */
    public function test_input_is_not_void_and_holds_action_children(): void
    {
        $node = $this->compile('<input type="text"><on type="change"><set-state target="local" value="{{$event.target.value}}"/></on></input>');

        $this->assertSame('Input', $node['name']);
        $this->assertSame('change', $node['actions'][0]['type']);
        $this->assertSame('setState', $node['actions'][0]['handler']);
    }

    /**
     * 노드 레벨 `<meta>` (D36) — 편집기 메타(editor_label 등)를 node['meta'] 로 컴파일한다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects node_level_meta_compiles_to_meta_key
     *
     * @return void 반환값 없음
     */
    public function test_node_level_meta_compiles_to_meta_key(): void
    {
        $node = $this->compile('<div><meta editor_label="$t:admin.modal_label.delete"/></div>');

        $this->assertSame(['editor_label' => '$t:admin.modal_label.delete'], $node['meta']);
    }

    // ---------------------------------------------------------------------
    // 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 소스를 토큰화·트리화한 뒤 첫 루트 요소를 노드로 컴파일합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return array<string, mixed> JSON 노드
     */
    private function compile(string $source): array
    {
        $tokens = (new MarkupTokenizer)->tokenize($source);
        $roots = (new MarkupTreeBuilder)->build($tokens);

        return $this->compiler->compile($roots[0]);
    }

    /**
     * 컴파일이 실패할 것을 전제로 오류를 잡아 반환합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return CompileError 발생한 오류
     */
    private function compileError(string $source): CompileError
    {
        try {
            $this->compile($source);
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail("컴파일 오류를 기대했으나 통과했습니다: {$source}");
    }

    /**
     * 키 순서까지 비교하기 위한 정규 인코딩을 만듭니다.
     *
     * @param  array<string, mixed>  $node  노드
     * @return string 인코딩 결과
     */
    private function encode(array $node): string
    {
        return json_encode($node, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
