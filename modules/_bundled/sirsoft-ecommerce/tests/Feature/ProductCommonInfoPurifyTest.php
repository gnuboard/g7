<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Support\Content\ContentHtmlPolicy;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Services\ProductCommonInfoService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 공통 정보 저장 정화 — 상품 설명과 같은 규칙 (#887 Q8)
 *
 * 종전에는 공통 정보 본문을 정화 없이 저장했다. 이제 HTML 모드 본문은 로케일마다 상품 설명과 같은 저장 정화(`PurifiesHtml` 의
 * `content` 프로필 — 코어 `ContentHtmlPolicy::forStorage()`)를 거친다: 위험 요소(스크립트·이벤트 속성·`javascript:` 주소)는 지우고,
 * 코어 「본문 콘텐츠」 후보(그림 캡션·업로드 동영상·오디오·외부 영상)·운영자 추가 태그는 스위치와 무관하게 남기며(결정 5), 문단 안
 * 미디어·인라인 태그는 넣은 자리에 남기고(결정 6), 모르는 서비스 외부 영상도 원문 그대로 둔다. 텍스트 모드는 손대지 않는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/product-common-info-purify.yaml
 */
class ProductCommonInfoPurifyTest extends ModuleTestCase
{
    /** 이 모듈 서빙 주소 모양의 본문 미디어 주소 */
    private const MEDIA = '/api/modules/sirsoft-ecommerce/content-media/AbCdEf123456';

    /**
     * 저장소를 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
    }

    /**
     * HTML 모드 공통 정보를 만들면 로케일마다 정화해 저장한다 — 스크립트·이벤트 속성·`javascript:` 주소·다른 출처 미디어는 지우고,
     * 문단 안 오디오·mark 는 그 자리에, 모르는 서비스 외부 영상은 원문 그대로 남긴다. 후보 스위치를 모두 꺼도 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=create_html
     *
     * @effects script_removed, inline_position_kept, unknown_embed_kept, switches_ignored_on_store
     */
    public function test_create_purifies_html_content_like_product_description(): void
    {
        $allOff = [
            'allow_figure_caption' => false, 'allow_uploaded_video' => false, 'allow_uploaded_audio' => false, 'allow_external_embed' => false,
            'embed_presets' => ['youtube' => false, 'vimeo' => false],
        ];

        foreach (['후보 끔' => $allOff, '후보 켬' => ['allow_uploaded_video' => true, 'allow_uploaded_audio' => true]] as $label => $override) {
            config(['g7_settings.core.content' => array_merge(ContentHtmlPolicy::defaults(), $override, ['custom_tags' => ['mark']])]);

            $commonInfo = app(ProductCommonInfoService::class)->createCommonInfo([
                'name' => ['ko' => '안내', 'en' => 'Info'],
                'content' => ['ko' => $this->html('가'), 'en' => $this->html('A')],
                'content_mode' => 'html',
                'is_default' => false,
                'is_active' => true,
            ]);

            foreach (['ko' => '가', 'en' => 'A'] as $locale => $word) {
                $stored = (string) ($commonInfo->content[$locale] ?? '');
                $this->assertStringNotContainsString('<script', $stored, "{$label} {$locale}: 스크립트는 지운다");
                $this->assertStringNotContainsString('onerror', $stored, "{$label} {$locale}: 이벤트 속성은 지운다");
                $this->assertStringNotContainsString('javascript:', $stored, "{$label} {$locale}: javascript: 주소는 지운다");
                $this->assertStringNotContainsString('evil.example', $stored, "{$label} {$locale}: 서빙 주소가 아닌 미디어는 지운다");
                $this->assertMatchesRegularExpression('#<p>'.$word.' <audio[^>]*src="'.preg_quote(self::MEDIA, '#').'"[^>]*></audio> 끝</p>#', $stored, "{$label} {$locale}: 문단 안 오디오는 그 자리");
                $this->assertStringContainsString('<p><mark>강조</mark></p>', $stored, "{$label} {$locale}: 문단 안 추가 태그는 그 자리");
                $this->assertStringContainsString('<figcaption>설명</figcaption>', $stored, "{$label} {$locale}: 그림 캡션은 남긴다");
                $this->assertStringContainsString('<oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ">', $stored, "{$label} {$locale}: 끈 서비스 외부 영상도 남긴다");
                $this->assertStringContainsString('<oembed url="https://unknown.example/v/1">', $stored, "{$label} {$locale}: 모르는 서비스 외부 영상도 원문 그대로");
                $this->assertStringNotContainsString('<p></p>', $stored, "{$label} {$locale}: 빈 문단을 만들지 않는다");
            }
        }
    }

    /**
     * 수정도 같은 정화를 한다 — 모드를 보내지 않은 수정은 저장된 모드(html)를 따른다. 텍스트 모드 본문은 정화하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=update
     *
     * @effects update_uses_stored_mode, text_mode_untouched
     */
    public function test_update_purifies_by_stored_mode_and_text_mode_is_untouched(): void
    {
        $service = app(ProductCommonInfoService::class);
        $html = $service->createCommonInfo([
            'name' => ['ko' => '안내', 'en' => 'Info'],
            'content' => ['ko' => '<p>처음</p>'],
            'content_mode' => 'html',
            'is_default' => false,
            'is_active' => true,
        ]);

        $updated = $service->updateCommonInfo($html->id, ['content' => ['ko' => '<p onclick="x()">고침</p><script>alert(1)</script>']]);
        $this->assertSame('<p>고침</p>', (string) $updated->content['ko'], '모드를 보내지 않은 수정도 저장된 html 모드로 정화');

        $text = $service->createCommonInfo([
            'name' => ['ko' => '글', 'en' => 'Text'],
            'content' => ['ko' => '1 < 2 & <b>그대로</b>'],
            'content_mode' => 'text',
            'is_default' => false,
            'is_active' => true,
        ]);
        $this->assertSame('1 < 2 & <b>그대로</b>', (string) $text->content['ko'], '텍스트 모드는 정화하지 않는다');
    }

    /**
     * 정화할 본문.
     *
     * @param  string  $word  문단 앞 글자
     * @return string 정화 전 본문 마크업
     */
    private function html(string $word): string
    {
        return '<p>'.$word.' <audio controls src="'.self::MEDIA.'"></audio> 끝</p>'
            .'<p><mark>강조</mark></p>'
            .'<figure class="image"><img src="/a.png" alt="a"><figcaption>설명</figcaption></figure>'
            .'<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'
            .'<figure class="media"><oembed url="https://unknown.example/v/1"></oembed></figure>'
            .'<video src="https://evil.example/x.mp4"></video>'
            .'<p><a href="javascript:alert(1)">링크</a><img src="/b.png" onerror="alert(1)"></p><script>alert(1)</script>';
    }
}
