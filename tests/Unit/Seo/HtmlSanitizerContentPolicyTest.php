<?php

namespace Tests\Unit\Seo;

use App\Seo\HtmlSanitizer;
use App\Support\Content\ContentHtmlPolicy;
use Tests\TestCase;

/**
 * 검색엔진(봇) 정화기가 코어 「본문 콘텐츠」 규칙을 따른다 — 후보 태그·운영자 추가 태그·외부 영상 (#883 5단계)
 *
 * 같은 표본을 클라이언트 `ContentPolicyRuntime.test.ts` 「운영자 추가 태그 svg」 사례가 표시기 쪽에서 판정한다(대표 패리티).
 *
 * @scenario-manifest tests/scenarios/content-render-policy.yaml
 */
class HtmlSanitizerContentPolicyTest extends TestCase
{
    /** 두 정화기가 함께 쓰는 표본 */
    public const SAMPLE = '<figure class="image"><img src="/a.png" alt="a"><figcaption>설명</figcaption></figure>'
        .'<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'
        .'<svg width="10" height="10"><circle r="4"></circle></svg>'
        .'<script>alert(1)</script><iframe src="https://evil.example/x"></iframe>';

    /**
     * 켜진 후보·추가 태그는 남고, 외부 영상은 nofollow 링크, 스크립트·사용자 iframe 은 지운다. 추가 태그를 빼면 svg 도 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=finalize
     *
     * @effects oembed_store_vs_bot, svg_allowed
     */
    public function test_bot_sanitizer_follows_saved_content_policy(): void
    {
        config(['g7_settings.core.content' => ['custom_tags' => ['svg']] + ContentHtmlPolicy::defaults()]);
        $out = (new HtmlSanitizer)->sanitize(self::SAMPLE);

        $this->assertStringContainsString('<figcaption>설명</figcaption>', $out);
        $this->assertStringContainsString('<svg', $out);
        $this->assertMatchesRegularExpression('#<a [^>]*href="https://www\.youtube\.com/watch\?v=dQw4w9WgXcQ"[^>]*rel="noopener noreferrer nofollow"#', $out);
        $this->assertStringNotContainsString('<oembed', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('evil.example', $out);

        config(['g7_settings.core.content' => ContentHtmlPolicy::defaults()]);
        $this->assertStringNotContainsString('<svg', (new HtmlSanitizer)->sanitize(self::SAMPLE));
    }
}
