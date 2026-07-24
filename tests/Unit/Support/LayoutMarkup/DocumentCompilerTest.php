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
 * DocumentCompiler 단위 테스트 (`.g7.html` 저작 포맷 S4 — 매핑 사전 A·B).
 *
 * 검증 축은 셋이다:
 *
 *  1. **계층별 fixture 쌍** — 저작 파일 다섯 계층(본 레이아웃·standalone·partial·주입 fragment·
 *     오버라이드)이 각각 짝 `.json` 과 **키 순서까지** 같은가. 문서 봉투가 하위 층(노드·액션·상태)을
 *     올바로 조립하는지를 종단으로 본다.
 *  2. **루트 판별** — `<layout>`/`<partial>`/`<extension>`/`<override>` 가 서로 다른 봉투로 갈리고,
 *     빈 문서·복수 루트·미지 루트가 거부되는가.
 *  3. **섹션 표기 규칙** — 싱글턴 섹션 중복·미지 속성·상태 초기화 표기·fragment 대상 충돌이
 *     규정대로 에러인가.
 *
 * 레지스트리는 번들 `sirsoft-admin_basic/components.json` 실물을 쓴다 — 합성 매니페스트로 대신하면
 * "우리끼리만 맞는" 태그 해석을 검증하게 되어, 실제 템플릿과 어긋나도 green 이 된다.
 */
class DocumentCompilerTest extends LayoutMarkupTestCase
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
    // 계층별 fixture 쌍 (HTML ↔ JSON)
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects document_root_selects_layout_partial_extension_or_override,
     *          top_level_sections_emit_as_json_keys,
     *          state_init_tree_emits_as_init_objects,
     *          document_json_key_order_is_deterministic
     */
    #[DataProvider('fixtureProvider')]
    public function test_layer_fixture_compiles_to_expected_json(string $fixture): void
    {
        $directory = __DIR__.'/fixtures/document';

        $document = $this->compile(file_get_contents("{$directory}/{$fixture}.g7.html"));
        $expected = json_decode(file_get_contents("{$directory}/{$fixture}.json"), true);

        // 배열 비교가 아니라 인코딩 문자열 비교다 — 배열 비교는 키 순서 차이를 통과시킨다.
        $this->assertSame($this->encode($expected), $this->encode($document));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtureProvider(): array
    {
        return [
            '① 본 레이아웃 (extends+slots+meta+data-source+init+modal)' => ['layout_full'],
            '① 최상위 섹션 전량 (computed·permissions·defines·error-handling·headers·overlay·script·named·init)' => ['layout_sections'],
            '① standalone (slot 노드·meta.seo·static·데이터소스 init)' => ['layout_standalone'],
            '② partial (루트=노드·is_partial·모달 data-source)' => ['partial_modal'],
            '④ fragment target-layout (inject_props·append_child)' => ['fragment_inject'],
            '④ fragment extension-point (mode·priority·부수 data-source)' => ['fragment_extension_point'],
            '⑤ 템플릿 오버라이드 (slots·meta·version-constraint)' => ['override'],
        ];
    }

    // ---------------------------------------------------------------------
    // 루트 판별
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects document_root_selects_layout_partial_extension_or_override
     */
    public function test_layout_root_produces_layout_name(): void
    {
        $this->assertSame('home', $this->compile('<layout name="home"/>')['layout_name']);
    }

    /**
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects document_root_selects_layout_partial_extension_or_override
     */
    public function test_partial_root_marks_is_partial(): void
    {
        $document = $this->compile('<partial><div class="x"/></partial>');

        $this->assertTrue($document['meta']['is_partial']);
        $this->assertSame('Div', $document['name']);
    }

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects empty_or_multiple_or_unknown_document_root_is_rejected
     */
    public function test_empty_document_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::EmptyDocument,
            $this->compileError("   \n  ")->getErrorCode(),
        );
    }

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects empty_or_multiple_or_unknown_document_root_is_rejected
     */
    public function test_multiple_document_roots_are_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::MultipleDocumentRoots,
            $this->compileError('<layout name="a"/><layout name="b"/>')->getErrorCode(),
        );
    }

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects empty_or_multiple_or_unknown_document_root_is_rejected
     */
    public function test_unknown_document_root_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::UnknownDocumentRoot,
            $this->compileError('<div class="x"/>')->getErrorCode(),
        );
    }

    // ---------------------------------------------------------------------
    // 섹션 표기 규칙
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects duplicate_singleton_section_is_rejected
     */
    public function test_duplicate_meta_section_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::DuplicateSection,
            $this->compileError('<layout name="a"><meta title="x"/><meta title="y"/></layout>')->getErrorCode(),
        );
    }

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects duplicate_singleton_section_is_rejected
     */
    public function test_duplicate_computed_name_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::DuplicateSection,
            $this->compileError('<layout name="a"><computed name="t">{{a}}</computed><computed name="t">{{b}}</computed></layout>')->getErrorCode(),
        );
    }

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects unknown_root_attribute_is_rejected
     */
    public function test_unknown_layout_attribute_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::UnknownRootAttribute,
            $this->compileError('<layout name="a" foo="bar"/>')->getErrorCode(),
        );
    }

    // ---------------------------------------------------------------------
    // `<seo>` 구조값 자식 (D51 G14)
    // ---------------------------------------------------------------------

    /**
     * 평면 스칼라 맵은 속성 field-map 으로 적는다 (`<og type="website"/>`).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects seo_structured_children_use_structured_value_grammar
     */
    public function test_seo_child_reads_attribute_field_map(): void
    {
        $document = $this->compile(
            '<layout name="a"><meta><seo :enabled="true"><og type="website" site_name="G7"/></seo></meta></layout>',
        );

        $this->assertSame(['type' => 'website', 'site_name' => 'G7'], $document['meta']['seo']['og']);
    }

    /**
     * 임의 깊이·비-속성 키는 `<field>` 트리로 적는다 (`@type` 은 속성명이 될 수 없다).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects seo_structured_children_use_structured_value_grammar
     */
    public function test_seo_child_reads_field_tree(): void
    {
        $document = $this->compile(
            '<layout name="a"><meta><seo><structured-data><field name="@type" value="ItemList"/>'
            .'<field name="publisher"><field name="name" value="G7"/></field></structured-data></seo></meta></layout>',
        );

        $this->assertSame(
            ['@type' => 'ItemList', 'publisher' => ['name' => 'G7']],
            $document['meta']['seo']['structured_data'],
        );
    }

    /**
     * 문자열 리스트는 `<item>` 으로 적는다 — JSON 본문 표기도 하위 호환으로 계속 받는다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects seo_structured_children_use_structured_value_grammar
     */
    public function test_seo_child_reads_item_list_and_json_body(): void
    {
        $items = $this->compile(
            '<layout name="a"><meta><seo><data-sources><item>posts</item><item>boards</item></data-sources></seo></meta></layout>',
        );
        $body = $this->compile(
            '<layout name="a"><meta><seo><data-sources>["posts", "boards"]</data-sources></seo></meta></layout>',
        );

        $this->assertSame(['posts', 'boards'], $items['meta']['seo']['data_sources']);
        $this->assertSame(['posts', 'boards'], $body['meta']['seo']['data_sources']);
    }

    // ---------------------------------------------------------------------
    // 상태 초기화 표기
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects state_init_tree_emits_as_init_objects
     */
    public function test_init_local_field_tree_emits_plain_object(): void
    {
        $document = $this->compile('<layout name="a"><init-local><field name="ids" :value="[]"/></init-local></layout>');

        $this->assertSame([], $document['initLocal']['ids']);
    }

    /**
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects state_init_tree_emits_as_init_objects
     */
    public function test_keyed_init_block_with_merge_emits_merge_key(): void
    {
        $document = $this->compile(
            '<layout name="a"><init-local key="cart" merge="deep"><field name="items" :value="[]"/></init-local></layout>',
        );

        $this->assertSame('deep', $document['initLocal']['cart']['_merge']);
        $this->assertSame([], $document['initLocal']['cart']['items']);
    }

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects state_source_without_key_is_rejected
     */
    public function test_from_source_without_key_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::StateSourceNeedsKey,
            $this->compileError('<layout name="a"><init-local><from-source>src</from-source></init-local></layout>')->getErrorCode(),
        );
    }

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects state_field_and_source_mixed_is_rejected
     */
    public function test_field_and_source_mixed_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::StateFieldSourceMixed,
            $this->compileError('<layout name="a"><init-local key="x"><field name="a" :value="1"/><from-source>s</from-source></init-local></layout>')->getErrorCode(),
        );
    }

    // ---------------------------------------------------------------------
    // fragment 봉투
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects fragment_target_conflict_or_missing_is_rejected
     */
    public function test_fragment_with_both_targets_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::FragmentTargetConflict,
            $this->compileError('<extension target-layout="a" extension-point="b"/>')->getErrorCode(),
        );
    }

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects fragment_target_conflict_or_missing_is_rejected
     */
    public function test_fragment_without_target_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::FragmentTargetMissing,
            $this->compileError('<extension><div class="x"/></extension>')->getErrorCode(),
        );
    }

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects fragment_inject_props_and_components_are_exclusive
     */
    public function test_inject_with_props_and_components_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::FragmentInjectAmbiguous,
            $this->compileError(
                '<extension target-layout="a"><inject target-id="t"><props>{"x":1}</props><div class="y"/></inject></extension>',
            )->getErrorCode(),
        );
    }

    /**
     * D42 G7 — 확장 주입 `<props>` 의 `<prop name="columns">` 가 `<column>`/`<cell>` 컬럼 트리를 받는다.
     *
     * 주입 fragment 에는 부모 컴포넌트가 없어(대상 노드가 다른 레이아웃에 있다) 소유자 게이트로
     * DataGrid 를 확인할 수단이 없다 — `<prop name="columns">` 컨테이너가 그 자리를 대신한다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects injection_props_accept_datagrid_column_tree
     */
    public function test_injection_props_accept_column_tree(): void
    {
        $doc = $this->compile(
            '<extension target-layout="admin_ecommerce_order_list"><inject target-id="grid" position="inject_props"><props>'
            .'<prop name="columns">'
            .'<column field="no" header="NO" width="60px" :sortable="false"><cell><span>{{row.no}}</span></cell></column>'
            .'<column field="name" header="이름"/>'
            .'</prop>'
            .'</props></inject></extension>',
        );

        $columns = $doc['injections'][0]['props']['columns'];

        $this->assertCount(2, $columns);
        $this->assertSame('no', $columns[0]['field']);
        $this->assertFalse($columns[0]['sortable']);
        $this->assertSame('Span', $columns[0]['cellChildren'][0]['name']);
        $this->assertArrayNotHasKey('cellChildren', $columns[1]);
    }

    /**
     * D42 G7 대조군 — 게이트가 열리는 자리는 **주입 `<props>` 의 `<prop name="columns">` 하나뿐**이다
     * (불변조건 4 — DataGrid 밖으로 새지 않는다).
     *
     * 세 대조군을 함께 고정한다. 컴파일 수용 범위가 방출기(`columns` 키에만 `<column>` 을 낸다)보다
     * 넓어지면 그 차이만큼은 **저작할 수는 있는데 되돌아오지 않는** 표기가 된다.
     *
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects column_tree_is_rejected_outside_injection_props
     */
    #[DataProvider('columnTreeRejectionProvider')]
    public function test_column_tree_outside_the_columns_container_is_rejected(string $source): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::UnexpectedStructuralChild,
            $this->compileError($source)->getErrorCode(),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function columnTreeRejectionProvider(): array
    {
        return [
            '노드 자리의 <prop name="columns">' => [
                '<layout name="x"><slot name="content"><div><prop name="columns"><column field="no"/></prop></div></slot></layout>',
            ],
            '주입 <props> 이지만 다른 이름 (오타 포함)' => [
                '<extension target-layout="a"><inject target-id="t" position="inject_props"><props><prop name="colums"><column field="no"/></prop></props></inject></extension>',
            ],
            '주입 <props> 의 <append> 안 (항목 리스트 자리)' => [
                '<extension target-layout="a"><inject target-id="t" position="inject_props"><props><prop name="columns"><append><column field="no"/></append></prop></props></inject></extension>',
            ],
        ];
    }

    /**
     * S8 A1 — 확장 주입 fragment 가 `<init>`(주입 시 실행할 초기 액션)을 받는다.
     * 예: 통화 선택기 주입 시 현재 통화 로드(header-currency-selector-admin).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects fragment_init_actions_emit_for_injection
     */
    public function test_fragment_target_layout_accepts_init_actions(): void
    {
        $doc = $this->compile(
            '<extension target-layout="admin_user_list"><init><set-state target="_local" ready="true"/></init></extension>',
        );

        $this->assertArrayHasKey('init_actions', $doc);
        $this->assertCount(1, $doc['init_actions']);
        $this->assertSame('setState', $doc['init_actions'][0]['handler']);
    }

    /**
     * S8 A1 — extension-point 형도 `<init>` 을 받는다(두 형태 모두 허용).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects fragment_init_actions_emit_for_injection
     */
    public function test_fragment_extension_point_accepts_init_actions(): void
    {
        $doc = $this->compile(
            '<extension extension-point="dashboard.widgets"><init><set-state target="_local" ready="true"/></init><div class="x"/></extension>',
        );

        $this->assertArrayHasKey('init_actions', $doc);
    }

    // ---------------------------------------------------------------------
    // 권한 (평면 배열 + 조건형 {or/and}) — S8 A2
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects permissions_flat_emits_string_array
     */
    public function test_permissions_flat_emits_string_array(): void
    {
        $doc = $this->compile(
            '<layout name="t"><permissions><permission>a</permission><permission>b</permission></permissions></layout>',
        );

        $this->assertSame(['a', 'b'], $doc['permissions']);
    }

    /**
     * S8 A2 — 조건형 권한 `<any-of>` → `{"or": [...]}` (예: 생성 OR 수정 권한).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects permissions_conditional_emits_or_and_object
     */
    public function test_permissions_any_of_emits_or_object(): void
    {
        $doc = $this->compile(
            '<layout name="t"><permissions><any-of><permission>a</permission><permission>b</permission></any-of></permissions></layout>',
        );

        $this->assertSame(['or' => ['a', 'b']], $doc['permissions']);
    }

    /**
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects permissions_conditional_emits_or_and_object
     */
    public function test_permissions_all_of_emits_and_object(): void
    {
        $doc = $this->compile(
            '<layout name="t"><permissions><all-of><permission>a</permission><permission>b</permission></all-of></permissions></layout>',
        );

        $this->assertSame(['and' => ['a', 'b']], $doc['permissions']);
    }

    /**
     * 평면 권한과 조건 블록을 섞으면 거부한다(둘 중 하나만).
     *
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects permissions_mixed_form_is_rejected
     */
    public function test_permissions_mixed_flat_and_conditional_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::UnexpectedStructuralChild,
            $this->compileError(
                '<layout name="t"><permissions><permission>a</permission><any-of><permission>b</permission></any-of></permissions></layout>',
            )->getErrorCode(),
        );
    }

    // ---------------------------------------------------------------------
    // partial 봉투
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects partial_root_must_be_a_single_node
     */
    public function test_partial_with_two_root_nodes_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::PartialRootNotSingleNode,
            $this->compileError('<partial><div class="a"/><div class="b"/></partial>')->getErrorCode(),
        );
    }

    // ---------------------------------------------------------------------
    // 섹션 주석 왕복 (S16 — `comment` 방출기 짝)
    // ---------------------------------------------------------------------

    /**
     * 봉투 앞 주석이 문서의 `comment` 가 됩니다.
     *
     * 이 자리가 비어 있어서 최상위 `comment` 가 매번 `<json>` 탈출구로 새고 있었다 — 노드·데이터소스는
     * 진작 주석을 왕복시키는데 문서 루트만 빠져 있던 비대칭이다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects section_comments_round_trip_without_escape
     */
    public function test_leading_comment_becomes_document_comment(): void
    {
        $document = $this->compile('<!-- 문서 설명 --><layout name="x"/>');

        $this->assertSame('문서 설명', $document['comment']);
    }

    /**
     * `<partial>` 루트의 주석은 노드 주석으로 남습니다 (문서 주석이 덮어쓰지 않는다).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects section_comments_round_trip_without_escape
     */
    public function test_partial_root_comment_stays_on_the_node(): void
    {
        $document = $this->compile('<!-- 조각 설명 --><partial><div class="a"/></partial>');

        $this->assertSame('조각 설명', $document['comment']);
        $this->assertTrue($document['meta']['is_partial']);
        $this->assertSame('Div', $document['name']);
    }

    /**
     * `<global-headers>` 의 `<match>` 앞 주석이 그 항목의 `comment` 가 됩니다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects section_comments_round_trip_without_escape
     */
    public function test_global_header_match_keeps_its_comment(): void
    {
        $document = $this->compile(
            '<layout name="x"><global-headers><!-- 비회원 토큰 --><match pattern="/api/guest/*" X-Token="{{t}}"/></global-headers></layout>',
        );

        $this->assertSame('비회원 토큰', $document['globalHeaders'][0]['comment']);
        $this->assertSame('/api/guest/*', $document['globalHeaders'][0]['pattern']);
    }

    /**
     * `<error-handling>` 과 `<action name>` 앞 주석이 각 섹션의 `comment` 가 됩니다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects section_comments_round_trip_without_escape
     */
    public function test_error_handling_and_named_action_keep_their_comments(): void
    {
        $document = $this->compile(
            '<layout name="x">'
            .'<!-- 에러 분기 설명 --><error-handling><on-code code="404"><navigate path="/login"/></on-code></error-handling>'
            .'<!-- 계좌 선택 --><action name="selectBank"><set-state target="local" bank="{{b}}"/></action>'
            .'</layout>',
        );

        $this->assertSame('에러 분기 설명', $document['errorHandling']['comment']);
        $this->assertArrayHasKey('404', $document['errorHandling']);
        $this->assertSame('계좌 선택', $document['named_actions']['selectBank']['comment']);
    }

    /**
     * `errorHandling` 의 `comment` 는 상태 코드로 오인되지 않습니다.
     *
     * 방출기가 `comment` 를 코드로 착각하면 문자열이 액션 자리로 흘러가 TypeError 로 죽는다 —
     * 컴파일러가 이 키를 읽기 시작한 직후 실제로 그렇게 터졌다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects section_comments_round_trip_without_escape
     */
    public function test_error_handling_comment_is_not_treated_as_a_status_code(): void
    {
        $document = $this->compile(
            '<layout name="x"><!-- 설명 --><error-handling><on-code code="404"><navigate path="/login"/></on-code></error-handling></layout>',
        );

        // PHP 는 `'404'` 를 정수 키로 접으므로 키 목록 비교가 아니라 존재 여부로 본다.
        $this->assertArrayHasKey('comment', $document['errorHandling']);
        $this->assertArrayHasKey('404', $document['errorHandling']);
        $this->assertIsString($document['errorHandling']['comment']);
        $this->assertIsArray($document['errorHandling']['404']);
    }

    // ---------------------------------------------------------------------
    // 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 소스를 토큰화·트리화한 뒤 문서로 컴파일합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return array<string, mixed> JSON 레이아웃
     */
    private function compile(string $source): array
    {
        $tokens = (new MarkupTokenizer)->tokenize($source);
        $roots = (new MarkupTreeBuilder)->build($tokens);

        return $this->compiler->compile($roots);
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
     * 결정적 직렬화로 인코딩합니다 (키 순서까지 비교).
     *
     * @param  array<string, mixed>  $document  문서
     * @return string 인코딩된 JSON
     */
    private function encode(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
