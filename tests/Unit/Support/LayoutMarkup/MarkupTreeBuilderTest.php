<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\MarkupElement;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;

/**
 * MarkupTreeBuilder 단위 테스트 (`.g7.html` 마크업 형식 S2 — 중첩 검출).
 *
 * 토크나이저(S1)는 중첩을 모르므로 짝 맞춤·미닫힘은 **이 층이 유일한 검출 지점**이다.
 * HTML5 파서의 오류 복구(미닫힘 자동 보정)를 쓰지 않는다는 결정이 실제로 지켜지는지,
 * 그리고 텍스트·주석이 손실 없이 실려 나가는지를 본다.
 */
class MarkupTreeBuilderTest extends LayoutMarkupTestCase
{
    private MarkupTreeBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new MarkupTreeBuilder;
    }

    // ---------------------------------------------------------------------
    // 트리 구성
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=tree,source_health=valid
     *
     * @effects nesting_is_reconstructed_with_authoring_order
     */
    public function test_builds_nested_tree_in_authoring_order(): void
    {
        $roots = $this->build('<div class="a"><span/><button/></div><div class="b"/>');

        $this->assertCount(2, $roots);
        $this->assertSame('div', $roots[0]->name);
        $this->assertCount(2, $roots[0]->children);
        $this->assertSame('span', $roots[0]->children[0]->name);
        $this->assertSame('button', $roots[0]->children[1]->name);
        $this->assertSame('b', $roots[1]->attribute('class')?->value);
    }

    /**
     * @effects nesting_is_reconstructed_with_authoring_order
     */
    public function test_void_element_is_closed_without_end_tag(): void
    {
        $roots = $this->build('<div><hr><br></div>');

        $this->assertCount(2, $roots[0]->children);
        $this->assertSame('hr', $roots[0]->children[0]->name);
        $this->assertSame('br', $roots[0]->children[1]->name);
    }

    // ---------------------------------------------------------------------
    // 중첩 오류 (침묵 보정 금지)
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=tree,source_health=invalid
     *
     * @effects unclosed_and_mismatched_tags_are_rejected_instead_of_auto_corrected
     */
    public function test_unclosed_tag_is_rejected(): void
    {
        $error = $this->buildError('<div class="a"><span/>');

        $this->assertSame(LayoutMarkupErrorCode::UnclosedTag, $error->getErrorCode());
        $this->assertSame(1, $error->getSourceLine());
    }

    /**
     * @effects unclosed_and_mismatched_tags_are_rejected_instead_of_auto_corrected
     */
    public function test_mismatched_end_tag_is_rejected_with_open_tag_line(): void
    {
        $error = $this->buildError("<div>\n  <span>\n</div>");

        $this->assertSame(LayoutMarkupErrorCode::MismatchedEndTag, $error->getErrorCode());
        // 작성자가 고쳐야 할 곳은 닫는 자리가 아니라 열어 둔 자리다 — 둘 다 알려 준다.
        $this->assertStringContainsString('2', $error->getDetail());
        $this->assertSame(3, $error->getSourceLine());
    }

    /**
     * @effects unclosed_and_mismatched_tags_are_rejected_instead_of_auto_corrected
     */
    public function test_unexpected_end_tag_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::UnexpectedEndTag,
            $this->buildError('<div/></div>')->getErrorCode(),
        );
    }

    /**
     * @effects text_outside_any_element_is_rejected
     */
    public function test_text_outside_element_is_rejected(): void
    {
        $this->assertSame(
            LayoutMarkupErrorCode::TextOutsideElement,
            $this->buildError('안내 문구<div/>')->getErrorCode(),
        );

        // 태그 사이의 인덴트 공백은 오류가 아니다.
        $this->assertCount(2, $this->build("<div/>\n  <span/>\n"));
    }

    // ---------------------------------------------------------------------
    // 텍스트 정규화
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=tree,source_health=valid
     *
     * @effects indent_whitespace_is_stripped_while_meaningful_whitespace_survives
     */
    public function test_indent_whitespace_is_stripped(): void
    {
        $roots = $this->build("<span>\n  안내 문구\n</span>");

        $this->assertSame('안내 문구', $roots[0]->text);
    }

    /**
     * @effects indent_whitespace_is_stripped_while_meaningful_whitespace_survives
     */
    public function test_whitespace_without_newline_is_meaningful_and_preserved(): void
    {
        // `<span> $t:auth.agree_required</span>` 처럼 앞 공백에 의미를 싣는 실사용이 17건 있다.
        // 무조건 trim 하면 그 값들이 조용히 바뀐다. 판정 기준은 개행 포함 여부다.
        $this->assertSame(' $t:auth.agree_required', $this->build('<span> $t:auth.agree_required</span>')[0]->text);
        $this->assertSame(' ', $this->build('<span> </span>')[0]->text);
        $this->assertSame(" — {{v.description ?? ''}}", $this->build("<span> — {{v.description ?? ''}}</span>")[0]->text);
    }

    /**
     * @effects empty_element_content_differs_from_self_closing
     */
    public function test_empty_content_and_self_closing_are_distinct(): void
    {
        // 빈 Span·빈 Th 실사용 4건이 `text: ""` 를 쓴다. 자기닫힘과 같게 뭉개면 왕복이 깨진다.
        $this->assertSame('', $this->build('<span></span>')[0]->text);
        $this->assertNull($this->build('<span/>')[0]->text);
    }

    /**
     * @effects indent_whitespace_is_stripped_while_meaningful_whitespace_survives
     */
    public function test_children_with_indent_only_have_no_text(): void
    {
        $roots = $this->build("<div>\n  <span/>\n</div>");

        $this->assertNull($roots[0]->text);
        $this->assertCount(1, $roots[0]->children);
    }

    // ---------------------------------------------------------------------
    // 주석 귀속
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=tree,source_health=valid
     *
     * @effects comment_attaches_to_the_following_element
     */
    public function test_comment_attaches_to_the_following_element(): void
    {
        $roots = $this->build("<!-- 사용자 목록 카드 -->\n<div class=\"card\"><!-- 헤더 --><span/></div>");

        $this->assertSame('사용자 목록 카드', $roots[0]->comment);
        $this->assertSame('헤더', $roots[0]->children[0]->comment);
        $this->assertNull($roots[0]->children[0]->text);
    }

    /**
     * @scenario compile_unit=tree,source_health=invalid
     *
     * @effects comment_without_a_target_is_rejected_instead_of_dropped
     */
    public function test_dangling_and_consecutive_comments_are_rejected(): void
    {
        // 주석은 노드 키라 귀속 대상이 없으면 담길 자리가 없다. 조용히 버리는 대신 알려 준다.
        $this->assertSame(
            LayoutMarkupErrorCode::DanglingComment,
            $this->buildError('<div><span/><!-- 끝 --></div>')->getErrorCode(),
        );

        $this->assertSame(
            LayoutMarkupErrorCode::DanglingComment,
            $this->buildError('<div/><!-- 문서 끝 -->')->getErrorCode(),
        );

        // 노드 comment 는 문자열 하나다. 여러 줄이 필요하면 한 주석 안에 개행으로 쓴다.
        $this->assertSame(
            LayoutMarkupErrorCode::ConsecutiveComments,
            $this->buildError('<!-- 하나 --><!-- 둘 --><div/>')->getErrorCode(),
        );

        $this->assertSame("하나\n둘", $this->build("<!-- 하나\n둘 --><div/>")[0]->comment);
    }

    /**
     * 소스를 토큰화한 뒤 트리로 세웁니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return array<int, MarkupElement> 최상위 요소 목록
     */
    private function build(string $source): array
    {
        return $this->builder->build((new MarkupTokenizer)->tokenize($source));
    }

    /**
     * 트리 구성이 실패할 것을 전제로 오류를 잡아 반환합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return CompileError 발생한 오류
     */
    private function buildError(string $source): CompileError
    {
        try {
            $this->build($source);
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail("컴파일 오류를 기대했으나 통과했습니다: {$source}");
    }
}
