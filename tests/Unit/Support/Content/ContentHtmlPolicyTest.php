<?php

namespace Tests\Unit\Support\Content;

use App\Rules\ContentCustomTagsRule;
use App\Support\Content\ContentHtmlPolicy;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 본문 HTML 규칙 — 후보 켜고 끔·추가 태그 거절·마무리(미디어 출처·속성·외부 영상) (#883 5단계)
 *
 * @scenario-manifest tests/scenarios/content-render-policy.yaml
 */
class ContentHtmlPolicyTest extends TestCase
{
    /**
     * 기본값은 결함 고침만 켠다 — 캡션·외부 영상(유튜브·비메오) 켬, 업로드 동영상·오디오 끔 (D21). 켠 후보 태그는 금지 목록에서 빠진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=defaults
     *
     * @effects defaults_fix_only, candidates_leave_forbid_list
     */
    public function test_defaults_and_candidates(): void
    {
        $policy = ContentHtmlPolicy::fromSettings([]);
        $this->assertContains('video', $policy->forbiddenTags());
        $this->assertSame(['figure', 'figcaption', 'oembed'], array_keys($policy->attributeRules()));
        $this->assertSame(['youtube', 'vimeo'], array_column($policy->toFrontendArray()['embed_providers'], 'id'));

        $video = ContentHtmlPolicy::fromSettings(['allow_uploaded_video' => true]);
        $this->assertNotContains('video', $video->forbiddenTags());
        $this->assertContains('source', $video->allowedExtraTags());
        $this->assertContains('script', $video->forbiddenTags());
    }

    /**
     * 추가 태그 — D19 목록은 사유와 함께 거절, svg 는 허용(경고는 화면 몫), 형식이 틀리면 거절.
     *
     * @return void 반환값 없음
     *
     * @scenario case=custom_tags
     *
     * @effects rejected_custom_tag_with_reason, svg_allowed
     */
    public function test_custom_tag_rule(): void
    {
        $this->assertFalse(Validator::make(['t' => ['iframe']], ['t' => [new ContentCustomTagsRule]])->passes());
        $this->assertFalse(Validator::make(['t' => ['Bad Tag']], ['t' => [new ContentCustomTagsRule]])->passes());
        $this->assertTrue(Validator::make(['t' => ['svg', 'details']], ['t' => [new ContentCustomTagsRule]])->passes());

        $messages = Validator::make(['t' => ['form']], ['t' => [new ContentCustomTagsRule]])->errors()->all();
        $this->assertStringContainsString('form', $messages[0]);
        $this->assertSame(['svg'], ContentHtmlPolicy::fromSettings(['custom_tags' => ['svg', 'script']])->customTags(), '읽을 때도 거절 목록을 거른다');
    }

    /**
     * 마무리 — 외부 출처 video 제거·자기 서빙 video 유지, 후보 속성만, on*·javascript: 제거, oembed 는 저장 시 허용 서비스면 유지 아니면 링크, 봇은 늘 링크.
     *
     * @return void 반환값 없음
     *
     * @scenario case=finalize
     *
     * @effects external_media_removed, own_media_kept, event_attrs_stripped, oembed_store_vs_bot
     */
    public function test_finalize_store_and_bot(): void
    {
        $policy = ContentHtmlPolicy::fromSettings(['allow_uploaded_video' => true]);
        $html = '<video src="https://evil.test/a.mp4" controls></video>'
            .'<video src="/api/modules/sirsoft-board/boards/free/content-media/AAAAAAAAAAAA?expires=1&amp;signature=z" controls onloadstart="x()" autoplay></video>'
            .'<p onclick="x()"><a href="javascript:alert(1)">a</a></p>'
            .'<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'
            .'<figure class="media"><oembed url="https://unknown.test/v/1"></oembed></figure>';

        $stored = $policy->finalize($html, 'store');
        $this->assertStringNotContainsString('evil.test', $stored);
        $this->assertStringContainsString('content-media/AAAAAAAAAAAA', $stored);
        $this->assertStringNotContainsString('onloadstart', $stored);
        $this->assertStringNotContainsString('autoplay', $stored, '후보 태그는 정해진 속성만');
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringContainsString('<oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed>', $stored);
        $this->assertStringContainsString('<a href="https://unknown.test/v/1" rel="noopener noreferrer nofollow"', $stored);

        $bot = $policy->finalize($html, 'bot');
        $this->assertStringNotContainsString('<oembed', $bot);
        $this->assertStringContainsString('href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', $bot);
    }

    /**
     * HTMLPurifier 정의 해시는 규칙이 바뀌면 달라진다(정의 캐시가 설정 변경을 따라간다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=purifier_definition
     *
     * @effects definition_rev_follows_settings
     */
    public function test_purifier_definition_follows_settings(): void
    {
        $a = ContentHtmlPolicy::fromSettings([])->htmlPurifierDefinition();
        $b = ContentHtmlPolicy::fromSettings(['allow_uploaded_video' => true])->htmlPurifierDefinition();

        $this->assertNotSame($a['id'], $b['id']);
        $this->assertArrayHasKey('video', $b['elements']);
    }
}
