<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\DocumentCompiler;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 구조값 문법 G1~G5 단위 테스트 (D42 — `StructuredValueCompiler` 가 SSoT).
 *
 * 종전에 이 문법은 **코퍼스 왕복으로만** 봉인돼 있었다. 왕복은 "현재 코퍼스가 쓰는 형태" 만 지나가므로
 * ⓐ 코퍼스에 없는 조합(중첩 안의 리스트, 불리언 속성, 빈 컨테이너)과 ⓑ **에러 경로 전부**가 무검증으로
 * 남는다. 에러 경로는 작성자가 오타를 냈을 때 무엇을 보게 되는가를 정하는 자리라 왕복으로는 영영
 * 닿지 않는다 — 그래서 여기서 직접 건다.
 *
 * 검증 축은 넷이다:
 *
 *  1. **G1 속성 field-map** — 데이터소스 자식이 본문 없이 속성만 있으면 평면 맵
 *  2. **G2~G4 `<field>`/`<item>` 트리** — 어느 깊이·어느 자리에서도 같은 규칙(단일 SSoT)
 *  3. **G5 병합 지시자** — `<append>` → `_append`
 *  4. **에러 경로** — 잘못된 자식 태그 / `name` 누락 / 중복 `name` / 중복 속성
 *
 * 범위는 **G1~G5** 다. 같은 SSoT 를 쓰는 나머지 자리는 각자의 층에 있다 — G6(액션 구조값 파라미터)는
 * `ActionCompilerTest`, G7(주입 컬럼 트리)·G8(switch 배치)은 `DocumentCompilerTest`/`LayoutLinterTest`.
 */
class StructuredValueGrammarTest extends LayoutMarkupTestCase
{
    private DocumentCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new DocumentCompiler(
            ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json')),
        );
    }

    // ---------------------------------------------------------------------
    // G1 — 데이터소스 자식의 속성 field-map
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects data_source_child_attributes_compile_to_a_flat_map
     */
    public function test_data_source_child_attributes_become_a_flat_map(): void
    {
        $source = $this->dataSource('<params page="{{query.page}}" :per_page="20" :draft="null" verbose/>');

        $this->assertSame([
            'page' => '{{query.page}}',
            'per_page' => 20,
            'draft' => null,
            'verbose' => true,
        ], $this->compileDocument($source)['data_sources'][0]['params']);
    }

    /**
     * 본문 JSON 표기는 계속 컴파일된다 (불변조건 ① 하위호환 — 어느 파일도 컴파일 실패로 바뀌지 않는다).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects legacy_json_body_notation_still_compiles
     */
    public function test_legacy_json_body_still_compiles(): void
    {
        $source = $this->dataSource('<params>{"page":1,"nested":{"a":[1,2]}}</params>');

        $this->assertSame(
            ['page' => 1, 'nested' => ['a' => [1, 2]]],
            $this->compileDocument($source)['data_sources'][0]['params'],
        );
    }

    /**
     * 빈 맵은 `{}` 본문으로 적는다. **자식도 속성도 본문도 없는 컨테이너는 에러**다 — 세 표기 중
     * 어디에도 해당하지 않아 `decodeJsonBody` 로 떨어지고 빈 본문은 JSON 이 아니기 때문이다.
     *
     * 액션의 `<query/>`(속성 없으면 `[]`)와 규약이 다르다는 뜻이라 현행 동작을 여기 못박는다 —
     * 통일 여부는 이 문법의 범위를 넘는 별도 판단이고, 그때 이 단언이 변경 지점을 가리킨다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects empty_structured_container_requires_an_explicit_empty_body
     */
    public function test_empty_map_needs_an_explicit_body(): void
    {
        $this->assertSame([], $this->compileDocument($this->dataSource('<params>{}</params>'))['data_sources'][0]['params']);

        $this->assertSame(
            LayoutMarkupErrorCode::InvalidJsonBody,
            $this->compileError($this->dataSource('<params/>'))->getErrorCode(),
        );
    }

    // ---------------------------------------------------------------------
    // G2 — 중첩 `<field>` 트리
    // ---------------------------------------------------------------------

    /**
     * 중첩 맵·리스트·리프가 한 트리 안에서 섞인다 (깊이 제한 없음).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects field_tree_compiles_nested_maps_lists_and_leaves
     */
    public function test_field_tree_compiles_nested_maps_lists_and_leaves(): void
    {
        $source = $this->dataSource(
            '<fallback>'
            .'<field name="data">'
            .'<field name="items" :value="[]"/>'
            .'<field name="meta"><field name="total" :value="0"/><field name="label" value="합계"/></field>'
            .'<field name="tags"><item>a</item><item>b</item></field>'
            .'<field name="rows"><item id="r1" :active="true"/></field>'
            .'</field>'
            .'</fallback>'
        );

        $this->assertSame([
            'data' => [
                'items' => [],
                'meta' => ['total' => 0, 'label' => '합계'],
                'tags' => ['a', 'b'],
                'rows' => [['id' => 'r1', 'active' => true]],
            ],
        ], $this->compileDocument($source)['data_sources'][0]['fallback']);
    }

    /**
     * `<item>` 세 형태는 배타적이다 — 자식=중첩 / 속성=평면 객체 / 본문=문자열.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects item_element_has_three_mutually_exclusive_forms
     */
    public function test_item_three_forms_are_distinct(): void
    {
        $source = $this->dataSource(
            '<fallback><field name="mixed">'
            .'<item>문자열</item>'
            .'<item id="obj" :n="1"/>'
            .'<item><field name="deep" value="x"/></item>'
            .'<item><item>중첩리스트</item></item>'
            .'</field></fallback>'
        );

        $this->assertSame([
            'mixed' => [
                '문자열',
                ['id' => 'obj', 'n' => 1],
                ['deep' => 'x'],
                ['중첩리스트'],
            ],
        ], $this->compileDocument($source)['data_sources'][0]['fallback']);
    }

    // ---------------------------------------------------------------------
    // G3·G4 — `<prop>` 의 `<field>` / `<item>`
    // ---------------------------------------------------------------------

    /**
     * 실물 마크업 형태 그대로 — 속성명은 **원본 props 키를 그대로** 쓴다(`iconName`).
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects prop_accepts_field_map_and_item_list
     */
    public function test_prop_accepts_field_map_and_item_list(): void
    {
        $props = $this->compileFirstNode(
            '<div>'
            .'<prop name="uploadParams"><field name="temp_key" value="{{_local.tempKey}}"/></prop>'
            .'<prop name="rowActions"><item id="view" label="보기" iconName="eye"/><item id="edit" label="수정"/></prop>'
            .'</div>'
        )['props'];

        $this->assertSame(['temp_key' => '{{_local.tempKey}}'], $props['uploadParams']);
        $this->assertSame([
            ['id' => 'view', 'label' => '보기', 'iconName' => 'eye'],
            ['id' => 'edit', 'label' => '수정'],
        ], $props['rowActions']);
    }

    /**
     * `<item>` 속성명은 **어떤 변환도 거치지 않는다** — kebab 으로 적으면 kebab 키가 된다.
     *
     * 이 규칙이 작성 함정이라 여기 못박는다: 원본 props 키가 `iconName` 인데 `icon-name` 으로 적으면
     * **다른 키**가 되어 조용히 왕복이 깨진다. 변환을 걸지 않는 이유는 그 반대 방향이 더 위험하기
     * 때문이다 — 변환을 걸면 원본에 kebab 키(`icon-name`)를 쓰는 props 를 표현할 수 없게 된다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects item_attribute_names_are_used_verbatim_as_keys
     */
    public function test_item_attribute_names_are_verbatim(): void
    {
        $props = $this->compileFirstNode(
            '<div><prop name="rowActions"><item icon-name="eye" iconName="pencil"/></prop></div>'
        )['props'];

        $this->assertSame([['icon-name' => 'eye', 'iconName' => 'pencil']], $props['rowActions']);
    }

    // ---------------------------------------------------------------------
    // G5 — 병합 지시자
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects injection_props_merge_directive_compiles_to_underscore_append
     */
    public function test_append_directive_compiles_to_merge_key(): void
    {
        $doc = $this->compileDocument(
            '<extension target-layout="a"><inject target-id="t" position="inject_props"><props>'
            .'<prop name="tabs"><append><item id="ext_x" label="확장 탭"/></append></prop>'
            .'</props></inject></extension>'
        );

        $this->assertSame(
            ['_append' => [['id' => 'ext_x', 'label' => '확장 탭']]],
            $doc['injections'][0]['props']['tabs'],
        );
    }

    // ---------------------------------------------------------------------
    // 에러 경로 — 작성 오타가 무엇으로 보이는가
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects structured_value_rejects_malformed_child_notation
     */
    #[DataProvider('malformedProvider')]
    public function test_malformed_structured_value_is_rejected(string $body, LayoutMarkupErrorCode $code): void
    {
        $this->assertSame($code, $this->compileError($this->dataSource($body))->getErrorCode());
    }

    /**
     * @return array<string, array{0: string, 1: LayoutMarkupErrorCode}>
     */
    public static function malformedProvider(): array
    {
        return [
            '맵 자리에 엉뚱한 태그' => [
                '<fallback><field name="a" value="1"/><column field="x"/></fallback>',
                LayoutMarkupErrorCode::UnexpectedStructuralChild,
            ],
            '리스트 자리에 엉뚱한 태그' => [
                '<fallback><field name="a"><item>x</item><field name="b" value="1"/></field></fallback>',
                LayoutMarkupErrorCode::UnexpectedStructuralChild,
            ],
            '<field> name 누락' => [
                '<fallback><field value="1"/></fallback>',
                LayoutMarkupErrorCode::MissingRequiredAttribute,
            ],
            '<field> name 이 빈 문자열' => [
                '<fallback><field name="" value="1"/></fallback>',
                LayoutMarkupErrorCode::MissingRequiredAttribute,
            ],
            '같은 name 중복' => [
                '<fallback><field name="a" value="1"/><field name="a" value="2"/></fallback>',
                LayoutMarkupErrorCode::DuplicateAttribute,
            ],
            '중첩 트리 안의 name 중복' => [
                '<fallback><field name="a"><field name="b" value="1"/><field name="b" value="2"/></field></fallback>',
                LayoutMarkupErrorCode::DuplicateAttribute,
            ],
            '<item> 속성이 접두어만 다르게 중복' => [
                '<fallback><field name="a"><item id="x" :id="1"/></field></fallback>',
                LayoutMarkupErrorCode::DuplicateAttribute,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 데이터소스 자식 하나를 품은 최소 레이아웃 소스를 만듭니다.
     *
     * @param  string  $child  `<params>`/`<fallback>` 등 구조값 자식
     * @return string `.g7.html` 소스
     */
    private function dataSource(string $child): string
    {
        return '<layout name="x"><data-source id="s" endpoint="/api/x" method="GET">'.$child.'</data-source></layout>';
    }

    /**
     * 소스를 문서로 컴파일합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return array<string, mixed> JSON 레이아웃
     */
    private function compileDocument(string $source): array
    {
        return $this->compiler->compile((new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($source)));
    }

    /**
     * 노드 하나를 slots 안에 넣어 컴파일하고 그 노드를 반환합니다.
     *
     * @param  string  $node  노드 소스
     * @return array<string, mixed> JSON 노드
     */
    private function compileFirstNode(string $node): array
    {
        $doc = $this->compileDocument('<layout name="x"><slot name="content">'.$node.'</slot></layout>');

        return $doc['slots']['content'][0];
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
            $this->compileDocument($source);
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail("컴파일 오류를 기대했으나 통과했습니다: {$source}");
    }
}
