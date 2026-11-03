<?php

namespace Plugins\Sirsoft\Popup\Tests\Feature\Public;

use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 공개 응답의 이미지 위 글 상자 테스트 (매니페스트 image-overlays.yaml — public_*)
 *
 * 상자 글은 요청 언어 → 사이트 기본 언어로 골라 문자열 하나로 싣고, 글이 빈 상자는 뺀다.
 * 모바일 배치는 모바일 이미지가 있을 때만 싣는다.
 */
class PublicPopupOverlayTest extends PluginTestCase
{
    /**
     * 테스트 준비 — 캐시를 끈다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->setPluginSettings(['public_cache_minutes' => 0]);
    }

    /**
     * 저장 모양 상자 한 개를 만듭니다.
     *
     * @param  array<string, mixed>  $override  바꿀 값
     * @return array<string, mixed> 상자
     */
    private function box(array $override = []): array
    {
        return array_replace([
            'id' => 'a1b2c3d4', 'x' => 8, 'y' => 62, 'w' => 84,
            'text' => ['ko' => '가을 할인', 'en' => 'Autumn sale'],
            'size' => 6.5, 'color' => '#FFFFFF', 'bold' => true, 'align' => 'center',
            'bg' => ['color' => '#000000', 'opacity' => 40], 'shadow' => true,
        ], $override);
    }

    /**
     * 요청 언어 글을 문자열로 싣고, 서식은 그대로 싣는다.
     *
     * @scenario surface=public_picks_request_locale_text
     *
     * @effects public_response_has_localized_boxes
     *
     * @return void 반환값 없음
     */
    public function test_public_picks_request_locale_text(): void
    {
        Popup::factory()->withImages()->withOverlays([$this->box()])->create();

        $box = $this->popups('en')->json('data.popups.0.overlays.pc.0');

        $this->assertSame('Autumn sale', $box['text']);
        $this->assertEquals(['id' => 'a1b2c3d4', 'x' => 8, 'y' => 62, 'w' => 84, 'size' => 6.5, 'color' => '#FFFFFF', 'bold' => true, 'align' => 'center', 'bg' => ['color' => '#000000', 'opacity' => 40], 'shadow' => true],
            array_diff_key($box, ['text' => true]));
        $this->assertSame('가을 할인', $this->popups('ko')->json('data.popups.0.overlays.pc.0.text'));
    }

    /**
     * 요청 언어 글이 없으면 사이트 기본 언어 글.
     *
     * @scenario surface=public_falls_back_to_default_locale_text
     *
     * @effects public_response_has_localized_boxes
     *
     * @return void 반환값 없음
     */
    public function test_public_falls_back_to_default_locale_text(): void
    {
        Popup::factory()->withImages()->withOverlays([$this->box(['text' => ['ko' => '한국어만']])])->create();

        $this->assertSame('한국어만', $this->popups('en')->json('data.popups.0.overlays.pc.0.text'));
    }

    /**
     * 고른 글이 비는 상자는 뺀다 — 남은 상자가 없으면 overlays 는 null.
     *
     * @scenario surface=public_drops_box_without_text
     *
     * @return void 반환값 없음
     */
    public function test_public_drops_box_without_text(): void
    {
        Popup::factory()->withImages()->withOverlays([
            $this->box(['id' => 'emptybox', 'text' => ['ko' => '  ']]),
            $this->box(['id' => 'keptbox1']),
        ])->create(['sort_order' => 1]);
        Popup::factory()->withImages()->withOverlays([$this->box(['text' => []])])->create(['sort_order' => 2]);

        $popups = $this->popups()->json('data.popups');

        $this->assertSame(['keptbox1'], array_column($popups[0]['overlays']['pc'], 'id'));
        $this->assertNull($popups[1]['overlays']);
    }

    /**
     * 모바일 배치는 모바일 이미지가 있을 때만 싣는다.
     *
     * @scenario surface=public_mobile_overlays_only_with_mobile_image
     *
     * @return void 반환값 없음
     */
    public function test_public_mobile_overlays_only_with_mobile_image(): void
    {
        $mobile = [$this->box(['id' => 'mobile01', 'y' => 10])];
        Popup::factory()->withImages(mobile: true)->withOverlays([$this->box()], $mobile)->create(['sort_order' => 1]);
        Popup::factory()->withImages()->withOverlays([$this->box()], $mobile)->create(['sort_order' => 2]);

        $popups = $this->popups()->json('data.popups');

        $this->assertSame('mobile01', $popups[0]['overlays']['mobile'][0]['id']);
        $this->assertNull($popups[1]['overlays']['mobile']);
        $this->assertSame('a1b2c3d4', $popups[1]['overlays']['pc'][0]['id']);
    }

    /**
     * 직접 작성형은 글 상자를 싣지 않는다(행에 값이 남아 있어도).
     *
     * @scenario surface=public_html_type_has_no_overlays
     *
     * @return void 반환값 없음
     */
    public function test_public_html_type_has_no_overlays(): void
    {
        Popup::factory()->html()->withOverlays([$this->box()])->create();

        $this->assertNull($this->popups()->json('data.popups.0.overlays'));
    }

    /**
     * 글 상자가 없는 이미지형은 overlays null.
     *
     * @scenario surface=public_null_when_no_overlays
     *
     * @return void 반환값 없음
     */
    public function test_public_null_when_no_overlays(): void
    {
        Popup::factory()->withImages()->create();

        $this->assertNull($this->popups()->json('data.popups.0.overlays'));
    }

    /**
     * 공개 API 를 손님으로 부릅니다.
     *
     * @param  string  $locale  요청 언어
     * @return TestResponse 응답
     */
    private function popups(string $locale = 'ko'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($this->api('/popups'), ['Accept-Language' => $locale])->assertOk();
    }
}
