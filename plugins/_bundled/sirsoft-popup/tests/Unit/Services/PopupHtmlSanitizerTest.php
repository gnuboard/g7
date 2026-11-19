<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Services;

use Plugins\Sirsoft\Popup\Services\PopupHtmlSanitizer;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 직접 작성형 본문 정화 테스트 (매니페스트 html-sanitizer.yaml)
 *
 * 저장본 = 정화본. 위험 요소는 지우고 CKEditor 서식은 남긴다(결정 R6).
 */
class PopupHtmlSanitizerTest extends PluginTestCase
{
    /**
     * 정화기
     */
    private PopupHtmlSanitizer $sanitizer;

    /**
     * 테스트 준비.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new PopupHtmlSanitizer;
    }

    /**
     * script 태그를 지운다.
     *
     * @scenario surface=removes_script_tag
     *
     * @effects stored_html_is_safe
     *
     * @return void 반환값 없음
     */
    public function test_removes_script_tag(): void
    {
        $this->assertSame('<p>안내</p>', $this->sanitizer->sanitize('<p>안내</p><script>alert(1)</script>'));
    }

    /**
     * on* 이벤트 속성을 지운다.
     *
     * @scenario surface=removes_event_handler_attributes
     *
     * @effects stored_html_is_safe
     *
     * @return void 반환값 없음
     */
    public function test_removes_event_handler_attributes(): void
    {
        $html = $this->sanitizer->sanitize('<p onclick="x()" onmouseover="y()">안내</p><img src="/a.png" alt="a" onerror="z()">');

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    /**
     * javascript: 링크를 지운다(글자는 남는다).
     *
     * @scenario surface=removes_javascript_href
     *
     * @effects stored_html_is_safe
     *
     * @return void 반환값 없음
     */
    public function test_removes_javascript_href(): void
    {
        $html = $this->sanitizer->sanitize('<p><a href="javascript:alert(1)">링크</a> <a href="JaVaScRiPt:alert(1)">둘</a></p>');

        $this->assertStringNotContainsString('javascript', strtolower($html));
        $this->assertStringContainsString('링크', $html);
    }

    /**
     * iframe·object·embed·form 을 지운다.
     *
     * @scenario surface=removes_iframe_and_object
     *
     * @effects stored_html_is_safe
     *
     * @return void 반환값 없음
     */
    public function test_removes_iframe_and_object(): void
    {
        $html = $this->sanitizer->sanitize('<iframe src="https://evil.example"></iframe><object data="x"></object><embed src="y"><form action="/z"><input name="a"></form><p>남김</p>');

        foreach (['<iframe', '<object', '<embed', '<form', '<input'] as $tag) {
            $this->assertStringNotContainsString($tag, $html);
        }
        $this->assertStringContainsString('<p>남김</p>', $html);
    }

    /**
     * SVG 와 그 안의 스크립트를 지운다.
     *
     * @scenario surface=removes_svg_script
     *
     * @effects stored_html_is_safe
     *
     * @return void 반환값 없음
     */
    public function test_removes_svg_script(): void
    {
        $html = $this->sanitizer->sanitize('<svg onload="alert(1)"><script>alert(2)</script></svg><p>본문</p>');

        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('alert', $html);
    }

    /**
     * 허용하지 않는 style 속성(position·z-index·background-image·expression)을 지운다 — 화면을 덮는 요소를 만들지 못하게.
     *
     * @scenario surface=strips_disallowed_style_properties
     *
     * @effects stored_html_is_safe
     *
     * @return void 반환값 없음
     */
    public function test_strips_disallowed_style_properties(): void
    {
        $html = $this->sanitizer->sanitize('<div style="position:fixed;top:0;left:0;z-index:9999;background-image:url(x);width:expression(alert(1));color:#ff0000">덮기</div>');

        foreach (['position', 'z-index', 'background-image', 'expression', 'top:'] as $bad) {
            $this->assertStringNotContainsString($bad, $html);
        }
        $this->assertStringContainsString('color:#ff0000', str_replace(' ', '', $html));
    }

    /**
     * 허용한 style 속성(글자색·배경색·정렬·글자 크기·굵기·밑줄)은 남긴다.
     *
     * @scenario surface=keeps_allowed_style_properties
     *
     * @return void 반환값 없음
     */
    public function test_keeps_allowed_style_properties(): void
    {
        $html = str_replace(' ', '', $this->sanitizer->sanitize('<p style="color:#111111;background-color:#ffff00;text-align:center;font-size:18px;font-weight:bold;text-decoration:underline;">강조</p>'));

        foreach (['color:#111111', 'background-color:#ffff00', 'text-align:center', 'font-size:18px', 'font-weight:bold', 'text-decoration:underline'] as $kept) {
            $this->assertStringContainsString($kept, $html);
        }
    }

    /**
     * 제목(h2~h4)·목록·표·인용·구분선·figure 는 남긴다.
     *
     * @scenario surface=keeps_headings_lists_tables
     *
     * @return void 반환값 없음
     */
    public function test_keeps_headings_lists_tables(): void
    {
        $source = '<h2>제목</h2><h3>소제목</h3><ul><li>하나</li></ul><ol><li>둘</li></ol>'
            .'<figure class="table"><table><thead><tr><th>머리</th></tr></thead><tbody><tr><td>칸</td></tr></tbody></table></figure>'
            .'<blockquote>인용</blockquote><hr>';
        $html = $this->sanitizer->sanitize($source);

        foreach (['<h2>', '<h3>', '<ul>', '<ol>', '<li>', '<table>', '<th>', '<td>', '<blockquote>', '<hr', '<figure class="table">'] as $tag) {
            $this->assertStringContainsString($tag, $html);
        }
    }

    /**
     * 새 창 링크에는 rel="noopener noreferrer" 를 붙인다.
     *
     * @scenario surface=forces_noopener_on_blank_target
     *
     * @effects stored_html_is_safe
     *
     * @return void 반환값 없음
     */
    public function test_forces_noopener_on_blank_target(): void
    {
        $html = $this->sanitizer->sanitize('<p><a href="https://example.com" target="_blank">외부</a></p>');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/rel="[^"]*noreferrer[^"]*"/', $html);
    }

    /**
     * class 는 CKEditor 표시 클래스만 남기고 나머지는 뗀다.
     *
     * @scenario surface=keeps_ckeditor_classes_only
     *
     * @effects stored_html_is_safe
     *
     * @return void 반환값 없음
     */
    public function test_keeps_ckeditor_classes_only(): void
    {
        $html = $this->sanitizer->sanitize('<figure class="image image-style-side fixed inset-0"><img src="/a.png" alt="a"></figure><span class="text-big hidden">큰 글자</span>');

        $this->assertStringContainsString('image', $html);
        $this->assertStringContainsString('image-style-side', $html);
        $this->assertStringContainsString('text-big', $html);
        $this->assertStringNotContainsString('fixed', $html);
        $this->assertStringNotContainsString('inset-0', $html);
        $this->assertStringNotContainsString('hidden', $html);
    }

    /**
     * 이미지는 사이트 내부·http(s) 주소만 남긴다(data: 는 지운다).
     *
     * @scenario surface=keeps_internal_and_https_images
     *
     * @return void 반환값 없음
     */
    public function test_keeps_internal_and_https_images(): void
    {
        $html = $this->sanitizer->sanitize('<p><img src="/api/plugins/sirsoft-ckeditor5/images/abc" alt="내부"><img src="https://cdn.example.com/a.png" alt="외부"><img src="data:image/png;base64,AAAA" alt="데이터"></p>');

        $this->assertStringContainsString('src="/api/plugins/sirsoft-ckeditor5/images/abc"', $html);
        $this->assertStringContainsString('src="https://cdn.example.com/a.png"', $html);
        $this->assertStringNotContainsString('data:image', $html);
    }

    /**
     * 언어별 정화 결과와 「바뀜」 여부를 돌려준다(공백 차이만이면 바뀌지 않음).
     *
     * @scenario surface=reports_changed_flag
     *
     * @return void 반환값 없음
     */
    public function test_reports_changed_flag(): void
    {
        $dirty = $this->sanitizer->sanitizeTranslations(['ko' => '<p>안내</p><script>x</script>', 'en' => '<p>Notice</p>']);
        $this->assertTrue($dirty['changed']);
        $this->assertSame(['ko' => '<p>안내</p>', 'en' => '<p>Notice</p>'], $dirty['bodies']);

        $clean = $this->sanitizer->sanitizeTranslations(['ko' => "<p>안내</p>\n<p>둘째 줄<br>셋째</p>"]);
        $this->assertFalse($clean['changed']);
    }
}
