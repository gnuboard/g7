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
