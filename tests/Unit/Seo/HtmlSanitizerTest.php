<?php

namespace Tests\Unit\Seo;

use App\Extension\HookManager;
use App\Seo\HtmlSanitizer;
use App\Thumbnails\ThumbnailPresetRegistry;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 봇 화면 HTML 정화기 검증.
 *
 * 배경: 봇 화면은 사용자 작성 본문(게시글·답변글·페이지·상품 설명)을 그대로 삽입하고
 * 있었고, 주소에 봇 파라미터를 붙이면 저장된 `<script>` 가 실제로 실행됐다. 일반 화면은
 * DOMPurify 로 정화한 결과를 그리므로 봇 화면도 같은 강도로 정화해야 한다.
 *
 * 차단 목록의 근거는 `HtmlContent.tsx` 의 DOMPurify 설정이다.
 *
 * @see HtmlSanitizer
 */
class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    /**
     * 테스트 초기화 — 정화기를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 본문 이미지 썸네일 판정이 읽는 설정을 고정한다 — 운영자 저장값이 스며들지 않게
        // (`mem:core-settings-boot-config-leaks-into-unit-tests`).
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.content_preset', 'lg');
        Config::set('g7_settings.core.seo.og_image_default_width', 1200);
        Config::set('g7_settings.core.seo.og_image_default_height', 630);
        HookManager::clearFilter('core.thumbnail.filter_presets');
        HookManager::clearFilter('core.thumbnail.filter_url_patterns');
        HookManager::clearFilter('core.thumbnail.filter_url');
        app(ThumbnailPresetRegistry::class)->flush();

        $this->sanitizer = new HtmlSanitizer;
    }

    /**
     * 썸네일 필터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.thumbnail.filter_presets');
        HookManager::clearFilter('core.thumbnail.filter_url_patterns');
        HookManager::clearFilter('core.thumbnail.filter_url');
        app(ThumbnailPresetRegistry::class)->flush();

        parent::tearDown();
    }

    /**
     * 등록된 서빙 경로의 본문 이미지는 본문 규격 src·숫자 규격 srcset·sizes·지연 로딩을 받는다.
     *
     * @effects content_image_uses_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_content_image_on_serving_path_gets_thumbnail_attributes(): void
    {
        $html = $this->sanitizer->sanitizeContent('<p><img src="/api/attachment/abc" alt="그림" width="2000"></p>');

        $this->assertStringContainsString('src="/api/attachment/abc?size=lg"', $html);
        $this->assertStringContainsString(
            'srcset="/api/attachment/abc?size=md 640w, /api/attachment/abc?size=lg 1280w, /api/attachment/abc?size=xl 1920w"',
            $html
        );
        $this->assertStringContainsString('sizes="'.HtmlSanitizer::DEFAULT_IMAGE_SIZES.'"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
    }

    /**
     * srcset 은 img width(정수) 이하 후보만 — 원본보다 큰 후보를 두면 서버가 원본을 보내 브라우저가 작게 그린다.
     * width 를 모르면 srcset·sizes 없이 src 만 본문 규격이고, 작성자 srcset 은 건드리지 않는다. 사람 화면
     * `contentImages.ts` 와 같은 규칙이다(같은 입력으로 `ThumbnailComponents.test.tsx` 가 잠근다).
     *
     * @effects content_image_srcset_limited_to_original_width
     *
     * @return void 반환값 없음
     */
    public function test_content_image_srcset_limited_to_original_width(): void
    {
        $html = $this->sanitizer->sanitizeContent(
            '<img src="/api/attachment/w1" width="2000">'
            .'<img src="/api/attachment/w2" width="1280">'
            .'<img src="/api/attachment/w3" width="500">'
            .'<img src="/api/attachment/w4">'
            .'<img src="/api/attachment/w5" width="50%" srcset="/api/attachment/w5 1x">'
            .'<img src="/api/attachment/w6" width=" 700 ">'
        );

        $this->assertStringContainsString(
            'srcset="/api/attachment/w1?size=md 640w, /api/attachment/w1?size=lg 1280w, /api/attachment/w1?size=xl 1920w"',
            $html
        );
        $this->assertStringContainsString('srcset="/api/attachment/w2?size=md 640w, /api/attachment/w2?size=lg 1280w"', $html);
        $this->assertStringContainsString('srcset="/api/attachment/w5 1x"', $html);
        $this->assertStringContainsString('srcset="/api/attachment/w6?size=md 640w"', $html);

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>');
        $images = [];
        foreach ($document->getElementsByTagName('img') as $img) {
            $images[$img->getAttribute('src')] = $img;
        }

        foreach (['w1', 'w2', 'w3', 'w4', 'w5', 'w6'] as $name) {
            $this->assertArrayHasKey('/api/attachment/'.$name.'?size=lg', $images, $name.' src 는 본문 규격');
        }
        foreach (['w3', 'w4'] as $name) {
            $img = $images['/api/attachment/'.$name.'?size=lg'];
            $this->assertFalse($img->hasAttribute('srcset'), $name.' 남는 후보가 없으면 srcset 없음');
            $this->assertFalse($img->hasAttribute('sizes'), $name.' srcset 이 없으면 sizes 없음');
        }
    }

    /**
     * 호출자가 준 sizes 를 쓰고, 작성자가 적은 sizes·loading 은 존중한다.
     *
     * @effects content_image_uses_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_content_image_respects_given_sizes_and_author_loading(): void
    {
        $custom = $this->sanitizer->sanitizeContent('<img src="/api/attachment/abc" width="1920">', '(min-width: 640px) 600px, 100vw');
        $this->assertStringContainsString('sizes="(min-width: 640px) 600px, 100vw"', $custom);

        $authored = $this->sanitizer->sanitizeContent('<img src="/api/attachment/abc" width="800" sizes="50vw" loading="eager">');
        $this->assertStringContainsString('sizes="50vw"', $authored);
        $this->assertStringContainsString('loading="eager"', $authored);
        $this->assertStringNotContainsString('loading="lazy"', $authored);
    }

    /**
     * 서명 쿼리는 원문 그대로 두고 size 만 덧붙인다.
     *
     * @effects content_image_uses_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_content_image_keeps_signed_query(): void
    {
        $html = $this->sanitizer->sanitizeContent('<img src="/api/attachment/abc?expires=1&amp;signature=a%2Bb">');

        $this->assertStringContainsString('src="/api/attachment/abc?expires=1&amp;signature=a%2Bb&amp;size=lg"', $html);
    }

    /**
     * 외부·정적·data: 이미지는 무변경이다.
     *
     * @effects content_image_outside_patterns_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_content_image_outside_patterns_is_unchanged(): void
    {
        foreach ([
            '<img src="https://cdn.example.com/a.jpg">',
            '<img src="/storage/attachments/a.jpg">',
            '<img src="data:image/png;base64,AAAA">',
        ] as $input) {
            $html = $this->sanitizer->sanitizeContent($input);

            $this->assertStringNotContainsString('size=', $html);
            $this->assertStringNotContainsString('srcset', $html);
            $this->assertStringNotContainsString('loading=', $html);
        }
    }

    /**
     * 기능이 꺼져 있으면 본문 이미지도 무변경이고, sanitize() 단독 호출은 본문 처리를 하지 않는다.
     *
     * @effects content_image_outside_patterns_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_content_image_unchanged_when_disabled_or_plain_sanitize(): void
    {
        $this->assertStringNotContainsString('size=', $this->sanitizer->sanitize('<img src="/api/attachment/abc">'));

        Config::set('core.thumbnail.enabled', false);
        $html = $this->sanitizer->sanitizeContent('<img src="/api/attachment/abc">');

        $this->assertStringContainsString('src="/api/attachment/abc"', $html);
        $this->assertStringNotContainsString('srcset', $html);
    }

    /**
     * srcset 은 후보마다 스킴을 검사한다 — 두 번째 후보의 실행 스킴도 제거된다.
     *
     * @effects dangerous_url_schemes_are_blocked
     *
     * @return void 반환값 없음
     */
    public function test_srcset_with_executable_candidate_is_removed(): void
    {
        $html = $this->sanitizer->sanitize('<img src="/a.png" srcset="/a.png 1x, javascript:alert(1) 2x">');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('srcset', $html);

        $kept = $this->sanitizer->sanitize('<img src="/a.png" srcset="/a.png 1x, /b.png 2x">');
        $this->assertStringContainsString('srcset="/a.png 1x, /b.png 2x"', $kept);
    }

    /**
     * script 태그는 내용까지 제거된다.
     *
     * @effects active_content_is_removed, html_mode_content_sanitized
     *
     * @return void 반환값 없음
     */
    public function test_script_tag_is_removed_with_its_content(): void
    {
        $html = $this->sanitizer->sanitize('<p>본문</p><script>window.__x=1</script>');

        $this->assertStringContainsString('<p>본문</p>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('window.__x', $html);
    }

    /**
     * 이벤트 핸들러 속성은 태그를 남기고 속성만 제거된다.
     *
     * @effects event_handler_attributes_are_stripped
     *
     * @return void 반환값 없음
     */
    public function test_event_handler_attributes_are_stripped(): void
    {
        $html = $this->sanitizer->sanitize('<img src="a.png" onerror="window.__x=1" alt="설명">');

        $this->assertStringContainsString('src="a.png"', $html);
        $this->assertStringContainsString('alt="설명"', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    /**
     * 목록에 없는 이벤트 핸들러(on* 전량)도 제거된다.
     *
     * @effects event_handler_attributes_are_stripped
     *
     * @return void 반환값 없음
     */
    public function test_unlisted_event_handlers_are_stripped(): void
    {
        $html = $this->sanitizer->sanitize('<div onanimationstart="window.__x=1" onpointerover="y()">내용</div>');

        $this->assertStringContainsString('내용', $html);
        $this->assertStringNotContainsString('onanimationstart', $html);
        $this->assertStringNotContainsString('onpointerover', $html);
    }

    /**
     * 외부 콘텐츠 삽입 태그는 제거된다.
     *
     * @effects active_content_is_removed
     *
     * @return void 반환값 없음
     */
    public function test_embedding_tags_are_removed(): void
    {
        $html = $this->sanitizer->sanitize(
            '<iframe src="https://e.test"></iframe><object data="x"></object><embed src="y">본문'
        );

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<object', $html);
        $this->assertStringNotContainsString('<embed', $html);
        $this->assertStringContainsString('본문', $html);
    }

    /**
     * 폼 요소는 벗기되 내부 텍스트는 남긴다 (피싱 방지 + 본문 보존).
     *
     * @effects active_content_is_removed, safe_markup_and_text_are_preserved
     *
     * @return void 반환값 없음
     */
    public function test_form_elements_are_unwrapped_but_text_is_kept(): void
    {
        $html = $this->sanitizer->sanitize('<form action="https://e.test"><p>안내 문구</p></form>');

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('<p>안내 문구</p>', $html);
    }

    /**
     * javascript: 스킴 링크는 href 만 제거되고 글자는 남는다.
     *
     * @effects dangerous_url_schemes_are_blocked
     *
     * @return void 반환값 없음
     */
    public function test_javascript_scheme_href_is_removed(): void
    {
        $html = $this->sanitizer->sanitize('<a href="javascript:alert(1)">링크</a>');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('링크', $html);
    }

    /**
     * 제어문자를 끼워 넣은 스킴 우회도 차단된다.
     *
     * @effects dangerous_url_schemes_are_blocked
     *
     * @return void 반환값 없음
     */
    public function test_obfuscated_javascript_scheme_is_removed(): void
    {
        $html = $this->sanitizer->sanitize("<a href=\"java\tscript:alert(1)\">링크</a>");

        $this->assertStringNotContainsString('script:', $html);
        $this->assertStringContainsString('링크', $html);
    }

    /**
     * 상대 경로·앵커·정상 스킴 링크는 보존된다.
     *
     * @effects safe_markup_and_text_are_preserved
     *
     * @return void 반환값 없음
     */
    public function test_normal_urls_are_preserved(): void
    {
        $html = $this->sanitizer->sanitize(
            '<a href="/board/free/1">내부</a><a href="#anchor">앵커</a><a href="mailto:a@b.test">메일</a>'
        );

        $this->assertStringContainsString('href="/board/free/1"', $html);
        $this->assertStringContainsString('href="#anchor"', $html);
        $this->assertStringContainsString('href="mailto:a@b.test"', $html);
    }

    /**
     * 외부 링크에는 rel 이 보강된다 (일반 화면 후처리와 동일).
     *
     * @effects safe_markup_and_text_are_preserved
     *
     * @return void 반환값 없음
     */
    public function test_external_link_gets_rel_attribute(): void
    {
        $html = $this->sanitizer->sanitize('<a href="https://external.test">외부</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    /**
     * 이미지 data URL 은 허용하고 그 외 data URL 은 차단한다.
     *
     * @effects dangerous_url_schemes_are_blocked, safe_markup_and_text_are_preserved
     *
     * @return void 반환값 없음
     */
    public function test_data_url_is_allowed_for_images_only(): void
    {
        $allowed = $this->sanitizer->sanitize('<img src="data:image/png;base64,AAAA" alt="a">');
        $blocked = $this->sanitizer->sanitize('<img src="data:text/html;base64,AAAA" alt="b">');

        $this->assertStringContainsString('data:image/png', $allowed);
        $this->assertStringNotContainsString('data:text/html', $blocked);
    }

    /**
     * 일반 서식 태그와 한글 텍스트는 그대로 보존된다.
     *
     * @effects ordinary_formatting_survives_sanitization, safe_markup_and_text_are_preserved
     *
     * @return void 반환값 없음
     */
    public function test_formatting_markup_and_multibyte_text_survive(): void
    {
        $source = '<h3>제목</h3><p><strong>굵게</strong> 그리고 <em>기울임</em></p>'
            .'<ul><li>첫째</li><li>둘째</li></ul><blockquote>인용문</blockquote>';

        $html = $this->sanitizer->sanitize($source);

        foreach (['<h3>제목</h3>', '<strong>굵게</strong>', '<em>기울임</em>', '<li>첫째</li>', '<blockquote>인용문</blockquote>'] as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }
    }

    /**
     * 표 마크업(게시글에서 흔함)은 보존된다.
     *
     * @effects ordinary_formatting_survives_sanitization
     *
     * @return void 반환값 없음
     */
    public function test_table_markup_is_preserved(): void
    {
        $html = $this->sanitizer->sanitize('<table><tbody><tr><td>값</td></tr></tbody></table>');

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<td>값</td>', $html);
    }

    /**
     * 빈 문자열/공백은 빈 문자열을 반환한다.
     *
     * @effects safe_markup_and_text_are_preserved
     *
     * @return void 반환값 없음
     */
    public function test_blank_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
        $this->assertSame('', $this->sanitizer->sanitize("   \n "));
    }

    /**
     * 태그가 없는 평문은 그대로 남는다.
     *
     * @effects safe_markup_and_text_are_preserved
     *
     * @return void 반환값 없음
     */
    public function test_plain_text_passes_through(): void
    {
        $html = $this->sanitizer->sanitize('태그 없는 본문입니다.');

        $this->assertStringContainsString('태그 없는 본문입니다.', $html);
    }

    /**
     * SVG 는 스크립트 실행 벡터라 통째로 제거된다.
     *
     * @effects active_content_is_removed
     *
     * @return void 반환값 없음
     */
    public function test_svg_is_removed(): void
    {
        $html = $this->sanitizer->sanitize('<svg><script>window.__x=1</script></svg><p>본문</p>');

        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('window.__x', $html);
        $this->assertStringContainsString('<p>본문</p>', $html);
    }

    /**
     * 주석은 제거된다.
     *
     * @effects active_content_is_removed
     *
     * @return void 반환값 없음
     */
    public function test_comments_are_removed(): void
    {
        $html = $this->sanitizer->sanitize('<!-- 숨은 주석 --><p>본문</p>');

        $this->assertStringNotContainsString('숨은 주석', $html);
        $this->assertStringContainsString('<p>본문</p>', $html);
    }

    /**
     * 중첩 구조 안쪽의 스크립트도 제거된다.
     *
     * @effects active_content_is_removed
     *
     * @return void 반환값 없음
     */
    public function test_nested_script_is_removed(): void
    {
        $html = $this->sanitizer->sanitize('<div><section><p>본문</p><script>window.__x=1</script></section></div>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<p>본문</p>', $html);
    }
}
