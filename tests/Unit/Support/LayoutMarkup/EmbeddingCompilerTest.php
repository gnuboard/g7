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
 * 임베딩 컴파일 단위 테스트 (`.g7.html` 마크업 형식 S5 — 매핑 사전 D).
 *
 * 검증 축은 넷이다:
 *
 *  1. **fixture 쌍** — 서브트리 hoist(cellChildren/expandChildren/…)·노드 키 hoist(responsive/
 *     component_layout/itemTemplate)·extension_point·`<options>` 데이터 래퍼가 실측 JSON shape 와
 *     키 순서까지 같은가.
 *  2. **부모 문맥** — hoist 태그(`<column>`·`<title-prefix>`·`<prop-footer>` 등)는 부모 컴포넌트 **이름과
 *     무관하게** 같은 props 키로 끌어올린다(2026-09-28 결정). 이름이 겹치는 옛 표기(`<card>`·`<footer>`)는
 *     종전 부모 아래에서만 hoist 이고, 홀로 뜻이 없는 태그(`<cell>`·`<default>`)는 자리 오류로 안내한다.
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
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects subtree_is_hoisted_to_its_props_or_node_key,
     *          options_wrapper_always_compiles_to_options_prop,
     *          expressions_and_translation_keys_are_never_pre_evaluated
     *
     * @param  string  $fixture  픽스처 이름
     * @return void 반환값 없음
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
     * 픽스처 쌍(마크업·기대 JSON) 목록.
     *
     * @return array<string, 반환값
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
     * 임베딩 태그가 제 부모 밖에 오면 거부된다.
     *
     * @scenario compile_unit=embedding,source_health=invalid
     *
     * @effects embedding_tag_outside_its_parent_is_rejected
     *
     * @param  string  $source  마크업 소스
     * @param  string  $parent  부모 태그
     * @return void 반환값 없음
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
     * 제 부모가 아닌 곳에 온 임베딩 태그 사례 목록.
     *
     * @return array<string, 반환값
     */
    public static function wrongParentProvider(): array
    {
        return [
            'cell 은 column 안' => ['<div><cell><span/></cell></div>', 'column'],
            'default 는 extension-point 안' => ['<div><default><span/></default></div>', 'extension-point'],
            'callback 은 extension-point 안' => ['<div><callback name="x"><navigate path="/y"/></callback></div>', 'extension-point'],
        ];
    }

    /**
     * `<card>` 는 그리드 밖에서는 컴포넌트, 그리드 안에서는 끌어올림이다.
     *
     * @effects card_is_a_hoist_only_under_a_grid_parent
     *
     * @return void 반환값 없음
     */
    public function test_card_is_the_component_outside_a_grid_but_a_hoist_inside_one(): void
    {
        // `<card>` 는 등록 컴포넌트(Card)이면서 cardColumns hoist 이기도 하다 — 부모 문맥이 가른다.
        $this->assertSame('Card', $this->compile('<card/>')['name']);
        $this->assertSame('Card', $this->compile('<div><card/></div>')['children'][0]['name']);

        $grid = $this->compile('<data-grid data="{{r}}"><card id="c1"><span text="{{row.t}}"/></card></data-grid>');
        $this->assertSame('c1', $grid['props']['cardColumns'][0]['id']);
    }

    /**
     * S8 — `<footer>` 는 Modal 부모일 때만 footer 임베딩(→ props.footer), 그 밖에서는 `Footer` 컴포넌트.
     * (`<card>` 와 동일한 부모-문맥 게이팅 — footer 는 등록 컴포넌트명과 겹치므로 CONTEXT_FREE 가 아니다.)
     *
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects subtree_is_hoisted_to_its_props_or_node_key
     *
     * @return void 반환값 없음
     */
    public function test_footer_is_a_modal_hoist_only_under_a_modal_parent(): void
    {
        // Modal 안: footer 서브트리를 props.footer 로 끌어올린다.
        $modal = $this->compile('<modal title="t"><footer><button type="button" text="저장"/></footer></modal>');
        $this->assertSame('Modal', $modal['name']);
        $this->assertCount(1, $modal['props']['footer']);
        $this->assertSame('Button', $modal['props']['footer'][0]['name']);

        // Modal 밖: 임베딩이 아니라 컴포넌트 해석 경로로 넘어간다(조용한 props 흡수가 아니다).
        // 템플릿과 무관하게 표기 규칙으로 `Footer` 컴포넌트 노드가 된다 — 그 컴포넌트가 있는지는 JSON 과
        // 같이 런타임(활성 템플릿)이 판정한다.
        $div = $this->compile('<div><footer><span/></footer></div>');
        $this->assertArrayNotHasKey('props', $div);
        $this->assertSame('Footer', $div['children'][0]['name']);
        $this->assertSame('Span', $div['children'][0]['children'][0]['name']);
    }

    /**
     * hoist 태그는 부모 컴포넌트 이름과 무관하게 같은 props 키로 끌어올린다.
     *
     * 번들 템플릿의 `DataGrid`·`Modal` 이 아닌 임의 이름(`<my-table>`) 아래에서도 결과가 같아야 다른 템플릿의
     * 같은 역할 컴포넌트가 이 표기를 쓸 수 있다(2026-09-28 결정).
     *
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects hoist_tags_compile_regardless_of_parent_name
     *
     * @return void 반환값 없음
     */
    public function test_hoist_tags_compile_under_any_parent_name(): void
    {
        $node = $this->compile(
            '<my-table data-type="composite">'
            .'<column field="name" header="이름"><cell><span text="{{row.name}}"/></cell></column>'
            .'<expand><span text="{{row.detail}}"/></expand>'
            .'<sub-row><span/></sub-row>'
            .'<footer-cell field="total" text="합계"/>'
            .'<footer-card><span/></footer-card>'
            .'<prop-card id="c1"><span text="{{row.t}}"/></prop-card>'
            .'<title-prefix><span/></title-prefix>'
            .'<title-suffix><span/></title-suffix>'
            .'<prop-footer><button text="저장"/></prop-footer>'
            .'</my-table>',
        );

        $this->assertSame('MyTable', $node['name']);
        $this->assertArrayNotHasKey('children', $node, 'hoist 태그가 자식 컴포넌트로 새면 안 된다');
        $this->assertSame(
            ['columns', 'expandChildren', 'subRowChildren', 'footerCells', 'footerCardChildren', 'cardColumns', 'titlePrefix', 'titleSuffix', 'footer'],
            array_keys($node['props']),
        );
        $this->assertSame('{{row.name}}', $node['props']['columns'][0]['cellChildren'][0]['props']['text']);
        $this->assertSame(['id' => 'c1', 'cellChildren' => [['type' => 'basic', 'name' => 'Span', 'props' => ['text' => '{{row.t}}']]]], $node['props']['cardColumns'][0]);
        $this->assertSame('Button', $node['props']['footer'][0]['name']);
    }

    /**
     * 옛 표기 `<card>`·`<footer>` 는 종전 부모 아래에서 새 표기와 **같은 JSON** 을 낸다.
     *
     * 기존 소스를 고치지 않고도 산출이 한 글자도 바뀌지 않아야 하고, 새 표기로 옮겨 써도 결과가 같아야 한다.
     * 종전 부모 밖에서는 옛 표기가 컴포넌트(Card·Footer)로 남는다.
     *
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects legacy_card_and_footer_keep_their_meaning_under_legacy_parents
     *
     * @return void 반환값 없음
     */
    public function test_legacy_card_and_footer_equal_new_tags_under_legacy_parents(): void
    {
        $this->assertSame(
            $this->encode($this->compile('<data-grid><card id="c1"><span/></card></data-grid>')),
            $this->encode($this->compile('<data-grid><prop-card id="c1"><span/></prop-card></data-grid>')),
        );
        $this->assertSame(
            $this->encode($this->compile('<card-grid><card id="c1"><span/></card></card-grid>')),
            $this->encode($this->compile('<card-grid><prop-card id="c1"><span/></prop-card></card-grid>')),
        );
        $this->assertSame(
            $this->encode($this->compile('<modal title="t"><footer><button/></footer></modal>')),
            $this->encode($this->compile('<modal title="t"><prop-footer><button/></prop-footer></modal>')),
        );

        // 종전 부모 밖의 옛 표기는 컴포넌트 — 새 표기만 부모와 무관하게 hoist 한다.
        $other = $this->compile('<my-table><card id="c1"/><footer><span/></footer></my-table>');
        $this->assertArrayNotHasKey('props', $other);
        $this->assertSame(['Card', 'Footer'], array_column($other['children'], 'name'));
    }

    // ---------------------------------------------------------------------
    // 필수 속성·단일 노드
    // ---------------------------------------------------------------------

    /**
     * 필수 속성이 빠진 임베딩 태그는 거부된다.
     *
     * @scenario compile_unit=embedding,source_health=invalid
     *
     * @effects embedding_tag_requires_its_named_attribute
     *
     * @param  string  $source  마크업 소스
     * @return void 반환값 없음
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
     * 필수 속성이 빠진 임베딩 태그 사례 목록.
     *
     * @return array<string, 반환값
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
     * `<item-template>` 은 노드를 정확히 하나 담아야 한다.
     *
     * @scenario compile_unit=embedding,source_health=invalid
     *
     * @effects item_template_holds_exactly_one_node
     *
     * @return void 반환값 없음
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
     * 같은 끌어올림 키를 두 번 쓰면 거부된다.
     *
     * @scenario compile_unit=embedding,source_health=invalid
     *
     * @effects duplicate_hoist_key_is_rejected
     *
     * @param  string  $source  마크업 소스
     * @param  LayoutMarkupErrorCode  $code  오류 코드
     * @return void 반환값 없음
     */
    #[DataProvider('duplicateProvider')]
    public function test_duplicate_hoist_key_is_rejected(string $source, LayoutMarkupErrorCode $code): void
    {
        $this->assertSame($code, $this->compileError($source)->getErrorCode());
    }

    /**
     * 같은 끌어올림 키를 두 번 쓰는 사례 목록.
     *
     * @return array<string, 반환값
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
            'prop-footer 두 번' => [
                '<my-dialog><prop-footer><span/></prop-footer><prop-footer><span/></prop-footer></my-dialog>',
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
    // 옵션 표기 — `<options>` 데이터 래퍼 vs 직속 `<option>` 노드 (G13)
    // ---------------------------------------------------------------------

    /**
     * `<options>` 안의 `<option>` 은 데이터다 — 언제나 `props.options` 로 접힌다.
     *
     * Select 는 `options` 유무로 **렌더 컴포넌트 자체가 갈린다**(있으면 커스텀 드롭다운, 없으면
     * 네이티브 `<select>`+children). 두 shape 는 런타임 동치가 아니므로 표기가 결정적이어야 한다.
     *
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects options_wrapper_always_compiles_to_options_prop
     *
     * @return void 반환값 없음
     */
    public function test_options_wrapper_compiles_to_options_prop(): void
    {
        $node = $this->compile('<select><options><option value="a">$t:x.a</option><option value="b" disabled>$t:x.b</option></options></select>');

        $this->assertSame(
            [['value' => 'a', 'label' => '$t:x.a'], ['value' => 'b', 'label' => '$t:x.b', 'disabled' => true]],
            $node['props']['options'],
        );
        $this->assertArrayNotHasKey('children', $node);
    }

    /**
     * 형제 `<on>`·구조 태그가 있어도 래퍼는 그대로 접히고, 그 형제도 유실되지 않는다.
     *
     * 종전 휴리스틱은 "전 자식이 `<option>` 일 때만" 접었기 때문에 이벤트 핸들러가 달린
     * `<select>`(=실사용 대부분)에서 리프트가 통째로 불발했다 — 원본 `props.options` 가
     * `children:[Option]` 으로 뒤바뀌어 네이티브 select 로 렌더되는 회귀였다(실측 44건).
     *
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects options_wrapper_lifts_alongside_sibling_actions
     *
     * @return void 반환값 없음
     */
    public function test_options_wrapper_lifts_even_with_sibling_action(): void
    {
        $node = $this->compile(<<<'HTML'
            <select value="{{_local.f}}">
              <options><option value="all">$t:x.all</option></options>
              <on type="change"><set-state f="{{$event.target.value}}"/></on>
            </select>
            HTML);

        $this->assertSame([['value' => 'all', 'label' => '$t:x.all']], $node['props']['options']);
        $this->assertArrayNotHasKey('children', $node);
        $this->assertNotEmpty($node['actions'] ?? []);
    }

    /**
     * 래퍼 없는 직속 `<option>` 은 Option 컴포넌트 노드다 — 절대 접지 않는다.
     *
     * 종전 휴리스틱은 이쪽도 접어버려서, 네이티브 select 로 작성된 레이아웃이 커스텀
     * 드롭다운으로 바뀌었다(실측 2건 — 반대 방향 회귀).
     *
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects bare_option_children_stay_component_nodes
     *
     * @return void 반환값 없음
     */
    public function test_bare_option_children_are_never_lifted(): void
    {
        $node = $this->compile('<select name="toolbar"><option value="a">$t:x.a</option><option value="b">$t:x.b</option></select>');

        $this->assertArrayNotHasKey('options', $node['props'] ?? []);
        $this->assertCount(2, $node['children']);
        $this->assertSame('Option', $node['children'][0]['name']);
    }

    /**
     * `<options>` 와 `:options` 는 같은 자리를 다툰다 — 침묵 덮어쓰기 대신 충돌로 잡는다.
     *
     * @scenario compile_unit=embedding,source_health=invalid
     *
     * @effects options_wrapper_conflicts_with_options_attribute
     *
     * @return void 반환값 없음
     */
    public function test_options_wrapper_conflicts_with_options_attribute(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::DuplicateAttribute,
            $this->compileError('<select :options="[]"><options><option value="a">a</option></options></select>')->getErrorCode(),
        );
    }

    // ---------------------------------------------------------------------
    // 선평가 금지 (skipBindingKeys 패리티)
    // ---------------------------------------------------------------------

    /**
     * 끌어올린 하위 트리 안의 표현식은 손대지 않고 통과한다.
     *
     * @scenario compile_unit=embedding,source_health=valid
     *
     * @effects expressions_and_translation_keys_are_never_pre_evaluated
     *
     * @return void 반환값 없음
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
