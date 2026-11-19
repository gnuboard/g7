<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Support;

use Plugins\Sirsoft\SocialLogin\Support\HtmlPage;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 단독 HTML 페이지(테스트 결과·데이터 삭제 상태·모의 동의 화면)가 인라인 스타일을 쓰지 않는다 — 스타일 보안 정책 호환.
 *
 * 사이트가 `style-src 'self'; style-src-attr 'none'` 을 걸면 `<style>` 요소와 `style="…"` 속성이 막힌다(코어 #163, 7.1.6).
 * 종전 `HtmlPage::render()` 는 모양을 `<style>` 블록으로 실어 그 정책에서 화면이 무스타일로 떴다. 규칙은 같은 출처 정적
 * 파일(`assets/pages/html-page.css`)로 옮기고 `<head>` 에는 `<link rel="stylesheet">` 하나만 둔다.
 *
 * @since 1.0.0
 */
class HtmlPageNoInlineStyleTest extends PluginTestCase
{
    /**
     * 페이지에 `<style>` 요소·style 속성이 없고, `<head>` 에 플러그인 정적 CSS 링크가 하나 있다.
     *
     * @scenario page=html_page
     *
     * @effects standalone_page_has_no_inline_style, standalone_page_links_stylesheet_in_head
     *
     * @return void 반환값 없음
     */
    public function test_page_has_no_inline_style_and_links_static_stylesheet(): void
    {
        $html = (string) HtmlPage::render('제목 "<b>"', '<p class="ok">본문</p>'.HtmlPage::definitionList(['키' => '값']))->getContent();

        $this->assertSame(0, preg_match('/<style\b/i', $html), '<style> 요소가 남아 있다');
        $this->assertSame(0, preg_match('/<[a-z][^>]*\sstyle\s*=/i', $html), 'style 속성이 남아 있다');

        $head = substr($html, 0, (int) strpos($html, '</head>'));
        $this->assertSame(1, substr_count($head, '<link rel="stylesheet"'), '<head> 에 스타일시트 링크가 정확히 1개여야 한다');
        $this->assertMatchesRegularExpression(
            '#<link rel="stylesheet" href="[^"]*sirsoft-social_login[^"]*assets/pages/html-page\.css[^"]*">#',
            $head,
        );
        $this->assertStringContainsString('<title>제목 &quot;&lt;b&gt;&quot;</title>', $html, '제목 이스케이프는 종전 그대로');
    }

    /**
     * 옮긴 CSS 파일에 종전 `<style>` 블록의 규칙이 그대로 있다(색 변수·다크·본문 상자·정의 목록·상태 색·단추).
     *
     * @scenario page=stylesheet_file
     *
     * @effects stylesheet_file_keeps_original_rules
     *
     * @return void 반환값 없음
     */
    public function test_stylesheet_file_keeps_original_rules(): void
    {
        $path = dirname(__DIR__, 3).'/'.HtmlPage::STYLESHEET_PATH;
        $this->assertFileExists($path);

        $css = (string) file_get_contents($path);
        foreach ([
            ':root { color-scheme: light dark;',
            '@media (prefers-color-scheme: dark)',
            'main { max-width: 560px;',
            'dl { display: grid;',
            '.ok { color: var(--ok);',
            '.bad { color: var(--bad);',
            'button.primary {',
            'p.note {',
        ] as $needle) {
            $this->assertStringContainsString($needle, $css, "html-page.css 에 {$needle} 규칙이 없다");
        }
    }
}
