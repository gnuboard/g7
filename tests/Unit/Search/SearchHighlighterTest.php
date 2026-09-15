<?php

namespace Tests\Unit\Search;

use App\Search\SearchHighlighter;
use PHPUnit\Framework\TestCase;

/**
 * 검색 하이라이트/평문화 공유 헬퍼 회귀 테스트.
 *
 * 게시판·상품·페이지 검색 리스너가 공유하는 SSoT 이므로, XSS 이스케이프와
 * 엔티티-태그 부활 차단을 이 한 곳에서 고정한다.
 */
class SearchHighlighterTest extends TestCase
{
    /**
     * @scenario case=highlighter_escape
     *
     * @effects highlight_escapes_markup_and_marks_keyword
     */
    public function test_highlight_escapes_markup_in_source_text(): void
    {
        $result = SearchHighlighter::highlight('<img src=x onerror=alert(1)> 날씨', '날씨');

        // 원문 태그는 이스케이프되어 실행 계약으로 나가지 않는다.
        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringContainsString('&lt;img', $result);
        $this->assertStringNotContainsString('onerror=alert(1)>', $result);
        // 검색어만 <mark> 로 감싸진다.
        $this->assertStringContainsString('<mark>날씨</mark>', $result);
    }

    /**
     * @scenario case=highlighter_escape
     *
     * @effects highlight_escapes_markup_and_marks_keyword
     */
    public function test_highlight_escapes_when_keyword_absent(): void
    {
        $result = SearchHighlighter::highlight('<script>alert(1)</script>', '없는키워드');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringNotContainsString('<mark>', $result);
    }

    /**
     * @scenario case=highlighter_escape
     *
     * @effects highlight_escapes_markup_and_marks_keyword
     */
    public function test_highlight_returns_empty_for_null_or_empty(): void
    {
        $this->assertSame('', SearchHighlighter::highlight(null, '키워드'));
        $this->assertSame('', SearchHighlighter::highlight('', '키워드'));
    }

    /**
     * @scenario case=highlighter_escape
     *
     * @effects highlight_escapes_markup_and_marks_keyword
     */
    public function test_highlight_handles_empty_keyword_by_escaping_only(): void
    {
        $result = SearchHighlighter::highlight('<b>hello</b>', '');

        $this->assertSame('&lt;b&gt;hello&lt;/b&gt;', $result);
    }

    /**
     * @scenario case=highlighter_plain_text
     *
     * @effects plain_text_never_resurrects_entity_encoded_tags
     */
    public function test_to_plain_text_strips_real_tags(): void
    {
        $result = SearchHighlighter::toPlainText('<p>hello <strong>world</strong></p>');

        $this->assertSame('hello world', $result);
    }

    /**
     * @scenario case=highlighter_plain_text
     *
     * @effects plain_text_never_resurrects_entity_encoded_tags
     */
    public function test_to_plain_text_does_not_resurrect_entity_encoded_tags(): void
    {
        // 저장 시점에 엔티티로 인코딩된 태그: strip_tags 를 먼저 돌리면 통과 후 디코드로 부활한다.
        $encoded = '&lt;script&gt;alert(1)&lt;/script&gt; 본문';

        $result = SearchHighlighter::toPlainText($encoded);

        // 디코드 후 태그 제거 순서라 부활한 태그가 남지 않는다.
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('alert(1)', $result);
        $this->assertStringContainsString('본문', $result);
    }

    /**
     * @scenario case=highlighter_plain_text
     *
     * @effects plain_text_never_resurrects_entity_encoded_tags
     */
    public function test_to_plain_text_returns_empty_for_null(): void
    {
        $this->assertSame('', SearchHighlighter::toPlainText(null));
        $this->assertSame('', SearchHighlighter::toPlainText(''));
    }
}
