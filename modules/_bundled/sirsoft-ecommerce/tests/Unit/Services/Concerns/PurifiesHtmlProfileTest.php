<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services\Concerns;

use Modules\Sirsoft\Ecommerce\Services\Concerns\PurifiesHtml;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 저장 정화 강도 — 상품 설명(`content`)은 코어 「본문 콘텐츠」 규칙을 따르고, 그 밖(`baseline`)은 종전 허용 목록 그대로다 (#883 5단계)
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
     * `content` 는 그림 설명·허용 서비스 외부 영상을 남기고 허용 밖 서비스는 링크로, 스크립트는 지운다. `baseline` 은 종전대로 지운다.
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
        $this->assertStringContainsString('href="https://unknown.example/v/1"', $content, '허용 밖 서비스는 링크');
        $this->assertStringNotContainsString('<oembed url="https://unknown.example', $content);
        $this->assertStringNotContainsString('<script', $content);

        $baseline = $this->purifier()->run($html, 'baseline');
        $this->assertStringNotContainsString('<figcaption', $baseline);
        $this->assertStringNotContainsString('<oembed', $baseline);
        $this->assertStringNotContainsString('<script', $baseline);
    }

    /**
     * 운영자 추가 태그는 상품 설명 저장에서도 남는다 — 게시판 표시·봇 정화기와 같은 규칙(D18). 후보를 모두 꺼도 추가 태그만으로 정의가 선다.
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
            config(['g7_settings.core.content' => array_merge(\App\Support\Content\ContentHtmlPolicy::defaults(), $override, ['custom_tags' => ['details', 'summary']])]);

            $content = $this->purifier()->run($html, 'content');
            $this->assertStringContainsString('<details>', $content, $label.': 추가 태그 details 가 남아야 한다');
            $this->assertStringContainsString('<summary>더 보기</summary>', $content, $label.': 추가 태그 summary 가 남아야 한다');
            $this->assertStringNotContainsString('onclick', $content, $label.': 이벤트 속성은 지운다');
        }

        $baseline = $this->purifier()->run($html, 'baseline');
        $this->assertStringNotContainsString('<details', $baseline, '후기 정화는 추가 태그를 받지 않는다');
    }
}
