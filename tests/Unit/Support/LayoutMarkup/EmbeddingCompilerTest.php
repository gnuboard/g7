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
 * 임베딩 컴파일 단위 테스트 (`.g7.html` 저작 포맷 S5 — 매핑 사전 D).
 *
 * 검증 축은 넷이다:
 *
 *  1. **fixture 쌍** — 서브트리 hoist(cellChildren/expandChildren/…)·노드 키 hoist(responsive/
 *     component_layout/itemTemplate)·extension_point·정적 옵션 리프트가 실측 JSON shape 와 키
 *     순서까지 같은가.
 *  2. **부모 문맥** — `<column>` 은 DataGrid 안에서만, `<title-prefix>` 는 Modal 안에서만 뜻이
 *     있다. 엉뚱한 부모 아래 오면 자리 오류로 안내하는가(조용히 props 로 흘리지 않는가).
 *  3. **서브트리 hoist vs 표현식-리프 경계** — `<expand>` 는 노드 트리를 hoist 하지만
 *     expandContext 는 `<prop>` 이 맡는다. 둘을 섞지 않는가.
 *  4. **선평가 금지** — hoist 한 서브트리·switch-value·옵션 안의 `{{}}`/`$t:defer:` 를 어느
 *     위치에서도 풀지 않는가(skipBindingKeys 패리티).
 *
 * 레지스트리는 번들 `sirsoft-admin_basic/components.json` 실물을 쓴다 — 합성 매니페스트로 대신하면
 * DataGrid·RichSelect·CardGrid 등의 실제 태그 해석과 어긋나도 green 이 된다.
 */
class EmbeddingCompilerTest extends LayoutMarkupTestCase
{
    private NodeCompiler $compiler;

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
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects subtree_is_hoisted_to_its_props_or_node_key,
     *          static_options_are_lifted_conditionally,
     *          expressions_and_translation_keys_are_never_pre_evaluated
     */
    #[DataProvider('fixtureProvider')]
    public function test_fixture_pair_compiles_to_expected_json(string $fixture): void
    {
        $directory = __DIR__.'/fixtures/embedding';

        $node = $this->compile(file_get_contents("{$directory}/{$fixture}.g7.html"));
        $expected = json_decode(file_get_contents("{$directory}/{$fixture}.json"), true);

        // 배열 비교가 아니라 인코딩 문자열 비교다 — 배열 비교는 키 순서 차이를 통과시킨다.
        $this->assertSame($this->encode($expected), $this->encode($node));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtureProvider(): array
    {
        return [
            'DataGrid 서브트리 hoist' => ['datagrid'],
            '노드 키 hoist' => ['node_keys'],
            'extension_point·옵션·switch-value' => ['extension_and_options'],
        ];
    }

    // ---------------------------------------------------------------------
    // 부모 문맥
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=embedding,source_health=invalid
     *
     * @effects embedding_tag_outside_its_parent_is_rejected
     */
    #[DataProvider('wrongParentProvider')]
    public function test_embedding_tag_outside_its_parent_is_rejected(string $source, string $parent): void
    {
        // 조용히 props 로 흘리면 화면에 아무 영향도 주지 않은 채 사라진다 — 자리 오류로 잡는다.
        $error = $this->compileError($source);

        $this->assertSame(LayoutMarkupErrorCode::EmbeddingTagWrongParent, $error->getErrorCode());
        $this->assertStringContainsString($parent, $error->getDetail());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function wrongParentProvider(): array
    {
        return [
            'column 은 data-grid 안' => ['<div><column field="x"/></div>', 'data-grid'],
            'expand 는 data-grid 안' => ['<div><expand><span/></expand></div>', 'data-grid'],
            'footer-cell 은 data-grid 안' => ['<div><footer-cell field="x"/></div>', 'data-grid'],
            'title-prefix 는 modal 안' => ['<div><title-prefix><span/></title-prefix></div>', 'modal'],
            'cell 은 column 안' => ['<div><cell><span/></cell></div>', 'column'],
            'default 는 extension-point 안' => ['<div><default><span/></default></div>', 'extension-point'],
            'callback 은 extension-point 안' => ['<div><callback name="x"><navigate path="/y"/></callback></div>', 'extension-point'],
        ];
    }

    /**
     * @effects card_is_a_hoist_only_under_a_grid_parent
     */
    public function test_card_is_the_component_outside_a_grid_but_a_hoist_inside_one(): void
    {
        // `<card>` 는 등록 컴포넌트(Card)이면서 cardColumns hoist 이기도 하다 — 부모 문맥이 가른다.
        $this->assertSame('Card', $this->compile('<card/>')['name']);
        $this->assertSame('Card', $this->compile('<div><card/></div>')['children'][0]['name']);

        $grid = $this->compile('<data-grid data="{{r}}"><card id="c1"><span text="{{row.t}}"/></card></data-grid>');
        $this->assertSame('c1', $grid['props']['cardColumns'][0]['id']);
    }

    // ---------------------------------------------------------------------
    // 필수 속성·단일 노드
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=embedding,source_health=invalid
     *
     * @effects embedding_tag_requires_its_named_attribute
     */
    #[DataProvider('missingAttributeProvider')]
    public function test_embedding_tag_missing_required_attribute_is_rejected(string $source): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::MissingRequiredAttribute,
            $this->compileError($source)->getErrorCode(),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingAttributeProvider(): array
    {
        return [
            'column field' => ['<data-grid><column header="x"/></data-grid>'],
            'card id' => ['<data-grid><card><span/></card></data-grid>'],
            'footer-cell field' => ['<data-grid><footer-cell text="x"/></data-grid>'],
            'responsive on' => ['<div><responsive><span/></responsive></div>'],
            'template slot' => ['<rich-select><template><span/></template></rich-select>'],
            'item-template 부재는 아님 — sortable source' => ['<div><sortable item-key="id"/></div>'],
            'switch-value prop' => ['<div><switch-value on="{{x}}"><default>a</default></switch-value></div>'],
            'switch-value on' => ['<div><switch-value prop="title"><default>a</default></switch-value></div>'],
            'extension-point name' => ['<extension-point><default><span/></default></extension-point>'],
        ];
    }

    /**
     * @scenario compile_unit=embedding,source_health=invalid
     *
     * @effects item_template_holds_exactly_one_node
     */
    public function test_item_template_requires_exactly_one_node(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::EmbeddingSingleNodeRequired,
            $this->compileError('<div><item-template><span/><span/></item-template></div>')->getErrorCode(),
        );

        // 정확히 하나면 단일 노드 객체(배열 아님)로 방출한다.
        $node = $this->compile('<div><item-template><span text="{{x}}"/></item-template></div>');
        $this->assertSame('Span', $node['itemTemplate']['name']);
    }

    // ---------------------------------------------------------------------
    // 중복 방지
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=embedding,source_health=invalid
     *
     * @effects duplicate_hoist_key_is_rejected
     */
    #[DataProvider('duplicateProvider')]
    public function test_duplicate_hoist_key_is_rejected(string $source, LayoutMarkupErrorCode $code): void
    {
        $this->assertSame($code, $this->compileError($source)->getErrorCode());
    }

    /**
     * @return array<string, array{0: string, 1: LayoutMarkupErrorCode}>
     */
    public static function duplicateProvider(): array
    {
        return [
            '같은 브레이크포인트 두 번' => [
                '<div><responsive on="portable"><span/></responsive><responsive on="portable"><span/></responsive></div>',
                LayoutMarkupErrorCode::DuplicateNodeKey,
            ],
            '같은 슬롯 두 번' => [
                '<rich-select><template slot="item"><span/></template><template slot="item"><span/></template></rich-select>',
                LayoutMarkupErrorCode::DuplicateNodeKey,
            ],
            'expand 두 번' => [
                '<data-grid><expand><span/></expand><expand><span/></expand></data-grid>',
                LayoutMarkupErrorCode::DuplicateNodeKey,
            ],
            'switch-value 같은 case' => [
                '<div><switch-value prop="c" on="{{x}}"><case when="a">1</case><case when="a">2</case></switch-value></div>',
                LayoutMarkupErrorCode::DuplicateCaseKey,
            ],
            'columns 와 :columns 충돌' => [
                '<data-grid :columns="[]"><column field="x"/></data-grid>',
                LayoutMarkupErrorCode::DuplicateAttribute,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // 정적 옵션 리프트 (조건부)
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects static_options_are_lifted_conditionally
     */
    public function test_static_options_lift_only_without_multiple_or_size(): void
    {
        // 전 자식이 정적 <option> 이고 multiple/size 가 없을 때만 options 배열로 끌어올린다.
        $lifted = $this->compile('<select><option value="a">$t:x.a</option><option value="b" disabled>$t:x.b</option></select>');
        $this->assertSame(
            [['value' => 'a', 'label' => '$t:x.a'], ['value' => 'b', 'label' => '$t:x.b', 'disabled' => true]],
            $lifted['props']['options'],
        );

        // multiple 이 있으면 리프트하지 않고 Option 을 일반 children 노드로 방출한다(기능 축소 방지).
        $kept = $this->compile('<select multiple><option value="a">$t:x.a</option></select>');
        $this->assertArrayNotHasKey('options', $kept['props']);
        $this->assertSame('Option', $kept['children'][0]['name']);
    }

    // ---------------------------------------------------------------------
    // 선평가 금지 (skipBindingKeys 패리티)
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects expressions_and_translation_keys_are_never_pre_evaluated
     */
    public function test_expressions_in_hoisted_subtrees_pass_through_untouched(): void
    {
        $node = $this->compile(<<<'HTML'
            <data-grid>
              <column field="name">
                <cell><span text="{{row.localized_name}}" aria-label="$t:defer:common.edit"/></cell>
              </column>
              <switch-value prop="color" on="{{row.status}}"><case when="on">{{row.color}}</case></switch-value>
            </data-grid>
            HTML);

        // cellChildren 안의 표현식·$t:defer: 는 원문 그대로 — 런타임이 받을 값을 미리 풀지 않는다.
        $cell = $node['props']['columns'][0]['cellChildren'][0];
        $this->assertSame('{{row.localized_name}}', $cell['props']['text']);
        $this->assertSame('$t:defer:common.edit', $cell['props']['aria-label']);

        // switch-value 의 $switch 선택식·케이스 값도 원문 그대로다.
        $this->assertSame('{{row.status}}', $node['props']['color']['$switch']);
        $this->assertSame('{{row.color}}', $node['props']['color']['$cases']['on']);
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
