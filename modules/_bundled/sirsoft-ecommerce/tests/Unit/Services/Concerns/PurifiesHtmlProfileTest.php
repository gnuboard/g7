<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services\Concerns;

use App\Support\Content\ContentHtmlPolicy;
use Modules\Sirsoft\Ecommerce\Services\Concerns\PurifiesHtml;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 저장 정화 강도 — 상품 설명(`content`)은 코어 「본문 콘텐츠」 후보 요소 전부와 운영자 추가 태그를 받고(후보 스위치는 표시에서만, #887),
 * 그 밖(`baseline`)은 종전 허용 목록 그대로다 (#883 5단계)
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/content-media.yaml
 */
class PurifiesHtmlProfileTest extends ModuleTestCase
{
    /**
     * 트레이트를 쓰는 시험용 객체.
     *
     * @return object 정화 함수를 공개한 객체
     */
    private function purifier(): object
    {
        return new class
        {
            use PurifiesHtml;

            /**
             * 정화 함수 공개.
             *
             * @param  string  $html  정화할 본문
             * @param  string  $profile  정화 강도
             * @return string 정화된 HTML
             */
            public function run(string $html, string $profile): string
            {
                return $this->purifyHtml($html, $profile);
            }
        };
    }

    /**
     * `content` 는 그림 설명·외부 영상을 남기고(어느 서비스에도 맞지 않는 주소도 원문 그대로 — 표시가 판단, #887), 스크립트는 지운다.
     * `baseline` 은 종전대로 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=store_purify
     *
     * @effects content_profile_keeps_caption_and_embed, baseline_profile_unchanged
     */
    public function test_content_profile_follows_core_policy_and_baseline_unchanged(): void
    {
        $html = '<figure class="image"><img src="/a.png" alt="a"><figcaption>설명</figcaption></figure>'
            .'<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'
            .'<figure class="media"><oembed url="https://unknown.example/v/1"></oembed></figure>'
            .'<script>alert(1)</script>';

        $content = $this->purifier()->run($html, 'content');
        $this->assertStringContainsString('<figcaption>설명</figcaption>', $content);
        $this->assertStringContainsString('<oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ">', $content);
        // #887 G6: 저장 규칙이 코어 `ContentHtmlPolicy::forStorage()` 한 곳으로 모이며 모르는 서비스 oembed 도 원문 보존(종전: 링크로 영구 변환)
        $this->assertStringContainsString('<oembed url="https://unknown.example/v/1">', $content, '모르는 서비스 외부 영상도 원문 그대로 저장');
        $this->assertStringNotContainsString('href="https://unknown.example/v/1"', $content, '저장 때 링크로 바꾸지 않는다');
        $this->assertStringNotContainsString('<script', $content);

        $baseline = $this->purifier()->run($html, 'baseline');
        $this->assertStringNotContainsString('<figcaption', $baseline);
        $this->assertStringNotContainsString('<oembed', $baseline);
        $this->assertStringNotContainsString('<script', $baseline);
    }

    /**
     * 운영자 추가 태그는 상품 설명 저장에서도 남는다 — 게시판 표시·봇 정화기와 같은 규칙(D18). 후보 스위치를 모두 꺼도 같다.
     * 이벤트 속성은 늘 지우고, `baseline`(후기)은 추가 태그를 받지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=store_purify
     *
     * @effects content_profile_keeps_custom_tags
     */
    public function test_content_profile_keeps_operator_custom_tags(): void
    {
        $html = '<details onclick="alert(1)"><summary>더 보기</summary><p>본문</p></details>';
        $candidatesOff = ['allow_figure_caption' => false, 'allow_uploaded_video' => false, 'allow_uploaded_audio' => false, 'allow_external_embed' => false];

        foreach (['후보 끔' => $candidatesOff, '후보 켬' => []] as $label => $override) {
            config(['g7_settings.core.content' => array_merge(ContentHtmlPolicy::defaults(), $override, ['custom_tags' => ['details', 'summary']])]);

            $content = $this->purifier()->run($html, 'content');
            $this->assertStringContainsString('<details>', $content, $label.': 추가 태그 details 가 남아야 한다');
            $this->assertStringContainsString('<summary>더 보기</summary>', $content, $label.': 추가 태그 summary 가 남아야 한다');
            $this->assertStringNotContainsString('onclick', $content, $label.': 이벤트 속성은 지운다');
        }

        $baseline = $this->purifier()->run($html, 'baseline');
        $this->assertStringNotContainsString('<details', $baseline, '후기 정화는 추가 태그를 받지 않는다');
    }

    /**
     * 「본문 콘텐츠」 후보 스위치·외부 영상 서비스를 모두 꺼도 저장 정화는 그림 캡션·외부 영상·업로드 동영상/오디오를 지우지 않는다 (#887).
     *
     * 켜고 끄기는 표시에서만 따른다 — 끈 동안 저장해도 원문이 남아 다시 켜면 돌아온다(게시판·페이지와 같음). 위험 요소와 미디어 출처
     * 검사(이 사이트 서빙 주소만)는 스위치와 무관하게 저장에서 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=store_purify
     *
     * @effects content_profile_keeps_candidates_when_switched_off
     */
    public function test_content_profile_keeps_candidates_when_switches_off(): void
    {
        $media = '/api/modules/sirsoft-ecommerce/content-media/AbCdEf123456';
        $html = '<figure class="image"><img src="/a.png" alt="a"><figcaption>설명</figcaption></figure>'
            .'<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'
            .'<figure class="media"><oembed url="https://www.tiktok.com/@shop/video/1234567890"></oembed></figure>'
            .'<figure class="media"><oembed url="https://unknown.example/v/1"></oembed></figure>'
            .'<video controls src="'.$media.'"><track kind="captions" src="'.$media.'"></video>'
            .'<audio controls src="'.$media.'"></audio>'
            .'<video src="https://evil.example/x.mp4"></video>'
            .'<p><a href="javascript:alert(1)">링크</a><img src="/b.png" onerror="alert(1)"></p><script>alert(1)</script>';

        $allOff = [
            'allow_figure_caption' => false, 'allow_uploaded_video' => false, 'allow_uploaded_audio' => false, 'allow_external_embed' => false,
            'embed_presets' => ['youtube' => false, 'vimeo' => false, 'tiktok' => false],
        ];

        foreach (['후보 끔' => $allOff, '후보 켬' => ['allow_uploaded_video' => true, 'allow_uploaded_audio' => true]] as $label => $override) {
            config(['g7_settings.core.content' => array_merge(ContentHtmlPolicy::defaults(), $override)]);

            $content = $this->purifier()->run($html, 'content');
            $this->assertStringContainsString('<figure class="image">', $content, $label.': 그림 캡션 figure 가 남아야 한다');
            $this->assertStringContainsString('<figcaption>설명</figcaption>', $content, $label.': 캡션이 남아야 한다');
            $this->assertStringContainsString('<oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ">', $content, $label.': 외부 영상이 남아야 한다');
            $this->assertStringContainsString('<oembed url="https://www.tiktok.com/@shop/video/1234567890">', $content, $label.': 끈 서비스의 외부 영상도 남아야 한다');
            // #887 G6: 모르는 서비스 oembed 도 저장 원문 보존(종전: 링크) — 나중에 그 서비스를 직접 추가하면 표시가 플레이어로 돌아온다
            $this->assertStringContainsString('<oembed url="https://unknown.example/v/1">', $content, $label.': 어느 서비스에도 맞지 않는 주소도 원문 그대로');
            $this->assertMatchesRegularExpression('#<video[^>]*src="'.preg_quote($media, '#').'"#', $content, $label.': 업로드 동영상이 남아야 한다');
            $this->assertMatchesRegularExpression('#<track[^>]*src="'.preg_quote($media, '#').'"#', $content, $label.': 자막 트랙이 남아야 한다');
            $this->assertMatchesRegularExpression('#<audio[^>]*src="'.preg_quote($media, '#').'"#', $content, $label.': 업로드 오디오가 남아야 한다');
            $this->assertStringNotContainsString('evil.example', $content, $label.': 서빙 주소가 아닌 미디어는 지운다');
            $this->assertStringNotContainsString('javascript:', $content, $label.': javascript: 주소는 지운다');
            $this->assertStringNotContainsString('onerror', $content, $label.': 이벤트 속성은 지운다');
            $this->assertStringNotContainsString('<script', $content, $label.': 스크립트는 지운다');
        }
    }

    /**
     * 문단 안의 오디오·동영상·인라인 추가 태그는 편집기가 만든 자리 그대로 저장한다 — 문단을 닫고 밖으로 빼며 빈 `<p></p>` 를 남기지 않는다 (#887).
     *
     * 인라인·블록은 HTML 표준 「문구 콘텐츠」 분류를 따른다 — mark·하이픈 사용자 정의 요소는 인라인, details 는 지금처럼 블록.
     * HTMLPurifier 가 원래 아는 태그(sub)는 그 표준 정의를 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=store_purify
     *
     * @effects content_profile_keeps_inline_position
     */
    public function test_content_profile_keeps_inline_media_and_tags_inside_paragraph(): void
    {
        $media = '/api/modules/sirsoft-ecommerce/content-media/AbCdEf123456';
        config(['g7_settings.core.content' => array_merge(ContentHtmlPolicy::defaults(), [
            'custom_tags' => ['mark', 'sub', 'g7-note', 'details', 'summary'],
        ])]);

        $content = $this->purifier()->run(
            '<p><audio controls src="'.$media.'"></audio></p>'
            .'<p>앞 <video controls src="'.$media.'"></video> 뒤</p>'
            .'<p><mark>강조</mark></p>'
            .'<p>H<sub>2</sub>O <g7-note>메모</g7-note></p>'
            .'<details><summary>더 보기</summary><p>본문</p></details>',
            'content'
        );

        $this->assertMatchesRegularExpression('#<p><audio[^>]*src="'.preg_quote($media, '#').'"[^>]*></audio></p>#', $content, '문단 안 오디오는 그 자리');
        $this->assertMatchesRegularExpression('#<p>앞 <video[^>]*></video> 뒤</p>#', $content, '문단 안 동영상은 그 자리');
        $this->assertStringContainsString('<p><mark>강조</mark></p>', $content, '문단 안 mark 는 그 자리');
        $this->assertStringContainsString('<p>H<sub>2</sub>O <g7-note>메모</g7-note></p>', $content, '표준 인라인·사용자 정의 요소는 그 자리');
        $this->assertStringContainsString('<details><summary>더 보기</summary><p>본문</p></details>', $content, 'details 는 블록 그대로');
        $this->assertStringNotContainsString('<p></p>', $content, '빈 문단을 만들지 않는다');
    }
}
