<?php

namespace Tests\Unit\Support\Content;

use App\Support\Content\HtmlElementCategories;
use PHPUnit\Framework\TestCase;

/**
 * HTML 요소 분류 — 저장 정화기가 HTMLPurifier 정의에 더할 요소의 인라인·블록 판정 (#887)
 *
 * @scenario-manifest tests/scenarios/content-render-policy.yaml
 */
class HtmlElementCategoriesTest extends TestCase
{
    /**
     * 문구 콘텐츠(audio·video·mark 등)·하이픈 사용자 정의 요소·미디어 빈 요소(source·track·oembed)는 인라인, 흐름 콘텐츠(figure·details 등)는 블록.
     *
     * @scenario case=element_categories
     *
     * @effects phrasing_content_inline
     *
     * @return void 반환값 없음
     */
    public function test_purifier_element_type(): void
    {
        foreach (['audio', 'video', 'mark', 'svg', 'span', 'my-widget', 'source', 'track', 'oembed', 'VIDEO'] as $inline) {
            $this->assertSame('Inline', HtmlElementCategories::purifierElementType($inline), $inline);
        }

        foreach (['figure', 'figcaption', 'details', 'section', 'div', 'table'] as $block) {
            $this->assertSame('Block', HtmlElementCategories::purifierElementType($block), $block);
        }

        $this->assertTrue(HtmlElementCategories::isPhrasingContent('ruby'));
        $this->assertFalse(HtmlElementCategories::isPhrasingContent('source'), 'source 는 표준 분류가 없다 — 정의 종류만 인라인');
    }
}
