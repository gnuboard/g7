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
    use RegistersMediaSourceRoutes;

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
     * 마무리 — 외부 출처 video 제거·자기 서빙 video(등록된 서빙 라우트) 유지, 후보 속성만, on*·javascript: 제거, oembed 는 저장 시 http(s)
     * 주소면 서비스를 몰라도 원문 보존(#887), 봇은 늘 링크.
     *
     * @return void 반환값 없음
     *
     * @scenario case=finalize
     *
     * @effects external_media_removed, own_media_kept, event_attrs_stripped, oembed_store_vs_bot, unknown_oembed_kept_on_store
     */
    public function test_finalize_store_and_bot(): void
    {
        self::registerBoardMediaRoute();
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
        $this->assertStringContainsString('<oembed url="https://unknown.test/v/1"></oembed>', $stored, '모르는 서비스도 저장 원문은 그대로(#887) — 재생·링크는 표시가 정한다');
        $this->assertStringNotContainsString('<a href="https://unknown.test/v/1"', $stored);

        $bot = $policy->finalize($html, 'bot');
        $this->assertStringNotContainsString('<oembed', $bot);
        $this->assertStringContainsString('href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', $bot);
    }

    /**
     * 「그림 캡션」 끔 — 표시에서만 캡션(figcaption)을 내용째 지우고 그림은 남긴다. 편집기 금지 목록(forbid_tags)에는 넣지 않아
     * 저장 원문이 그대로이고(다시 켜면 복원), 저장 마무리(`store`)도 캡션을 건드리지 않는다. 봇 화면(`bot`)은 사람 화면과 같이 지운다 (#887).
     *
     * @return void 반환값 없음
     *
     * @scenario case=caption_off
     *
     * @effects caption_off_drops_caption_keeps_image
     */
    public function test_caption_off_drops_caption_on_display_only(): void
    {
        $this->assertSame([], ContentHtmlPolicy::fromSettings([])->toFrontendArray()['drop_tags'], '켬(기본)은 지울 태그 없음');

        $policy = ContentHtmlPolicy::fromSettings(['allow_figure_caption' => false]);
        $front = $policy->toFrontendArray();
        $this->assertSame(['figcaption'], $front['drop_tags']);
        $this->assertNotContains('figcaption', $front['forbid_tags'], '편집기는 캡션을 계속 받는다(저장 원문 보존)');
        $this->assertNotContains('figcaption', $front['extra_tags']);
        $this->assertSame(['oembed', 'figure'], $front['extra_tags'], '외부 영상 후보의 figure 는 남는다');

        $html = '<figure class="image"><img src="/a.png" alt="a"><figcaption>캡션887</figcaption></figure><p>본문</p>';

        $bot = $policy->finalize($html, 'bot');
        $this->assertStringNotContainsString('figcaption', $bot);
        $this->assertStringNotContainsString('캡션887', $bot);
        $this->assertStringContainsString('<figure class="image"><img src="/a.png" alt="a"></figure>', $bot);
        $this->assertStringContainsString('<p>본문</p>', $bot);

        $this->assertStringContainsString('<figcaption>캡션887</figcaption>', $policy->finalize($html, 'store'), '저장 마무리는 캡션을 지우지 않는다');
        $this->assertStringContainsString('<figcaption>캡션887</figcaption>', ContentHtmlPolicy::fromSettings([])->finalize($html, 'bot'), '켜면 봇 화면도 캡션 유지');

        $custom = ContentHtmlPolicy::fromSettings(['allow_figure_caption' => false, 'custom_tags' => ['figcaption']]);
        $this->assertSame([], $custom->toFrontendArray()['drop_tags'], '운영자가 추가 태그로 직접 허용하면 지우지 않는다');
    }

    /**
     * 「외부 영상 삽입」 끔 — 화면 규칙에 oembed 규칙·서비스가 없고, 봇 화면은 원래 주소 nofollow 링크(빈 상자 금지). 사람 화면은
     * 런타임 `renderEmbeds` 가 같은 링크를 만든다(`ContentPolicyRuntime.test.ts` 「외부 영상 삽입을 끄면」) (#887).
     *
     * @return void 반환값 없음
     *
     * @scenario case=embed_off
     *
     * @effects embed_off_becomes_link
     */
    public function test_embed_off_becomes_link(): void
    {
        $policy = ContentHtmlPolicy::fromSettings(['allow_external_embed' => false]);
        $front = $policy->toFrontendArray();
        $this->assertFalse($front['allow_external_embed']);
        $this->assertSame([], $front['embed_providers']);
        $this->assertArrayNotHasKey('oembed', $front['attributes']);

        $bot = $policy->finalize('<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>', 'bot');
        $this->assertSame('<figure class="media"><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" rel="noopener noreferrer nofollow" target="_blank">https://www.youtube.com/watch?v=dQw4w9WgXcQ</a></figure>', $bot);
    }

    /**
     * 저장용 규칙(`forStorage()`) — 후보 스위치·외부 영상 서비스를 모두 꺼도 저장 정화는 그 요소를 지우지 않는다(표시에서만 따름, #887).
     * 운영자 추가 태그는 설정 그대로. 저장 마무리는 모르는 서비스 oembed 를 원문 보존(url 외 속성만 정리), `javascript:` oembed 와
     * 출처 불일치 미디어는 지운다. 같은 설정의 표시 규칙(`current()`)은 캡션을 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=for_storage
     *
     * @effects storage_policy_ignores_switches, unknown_oembed_kept_on_store, external_media_removed, own_media_kept
     */
    public function test_for_storage_ignores_switches_and_keeps_original(): void
    {
        self::registerBoardMediaRoute();
        config(['g7_settings.core.content' => [
            'allow_figure_caption' => false,
            'allow_uploaded_video' => false,
            'allow_uploaded_audio' => false,
            'allow_external_embed' => false,
            'embed_presets' => ['youtube' => false, 'vimeo' => false, 'tiktok' => false],
            'custom_tags' => ['svg'],
        ] + ContentHtmlPolicy::defaults()]);

        $storage = ContentHtmlPolicy::forStorage();
        $this->assertSame(array_keys(ContentHtmlPolicy::CANDIDATES), $storage->enabledCandidates(), '후보 스위치와 무관하게 전부 받는다');
        $this->assertSame(['svg'], $storage->customTags());
        $this->assertSame([], $storage->displayDropTags());
        $this->assertSame(['youtube', 'vimeo', 'tiktok'], array_column($storage->embedProviders(), 'id'));
        $this->assertSame(['figcaption'], ContentHtmlPolicy::current()->displayDropTags(), '같은 설정의 표시 규칙은 캡션을 지운다');

        $html = '<figure class="image"><img src="/a.png" alt="a"><figcaption>캡션887</figcaption></figure>'
            .'<p>앞 <audio src="/api/modules/sirsoft-board/boards/free/content-media/BBBBBBBBBBBB" controls autoplay></audio> 뒤</p>'
            .'<video src="https://evil.test/a.mp4" controls></video>'
            .'<figure class="media"><oembed url="https://unknown.test/v/1" data-x="1" onclick="x()"></oembed></figure>'
            .'<figure class="media"><oembed url="javascript:alert(1)"></oembed></figure>';

        $stored = $storage->finalize($html, 'store');
        $this->assertStringContainsString('<figcaption>캡션887</figcaption>', $stored);
        $this->assertStringContainsString('<p>앞 <audio src="/api/modules/sirsoft-board/boards/free/content-media/BBBBBBBBBBBB" controls></audio> 뒤</p>', $stored);
        $this->assertStringNotContainsString('evil.test', $stored);
        $this->assertStringContainsString('<figure class="media"><oembed url="https://unknown.test/v/1"></oembed></figure>', $stored, 'url 외 속성만 정리');
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringNotContainsString('<a ', $stored, '저장은 oembed 를 링크로 바꾸지 않는다');
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
