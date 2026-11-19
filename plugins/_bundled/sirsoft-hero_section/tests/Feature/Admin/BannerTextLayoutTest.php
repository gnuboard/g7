<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Services\PositionSettingService;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 문구 자리·머리글·등장 효과·바탕 밝기(배너 표현값)와 메뉴 겹치기(위치 설정) — 저장·검증·공개 응답·힌트.
 * (매니페스트 text-layout-overlay.yaml)
 */
class BannerTextLayoutTest extends PluginTestCase
{
    /**
     * 관리자
     */
    private User $admin;

    /**
     * 테스트 준비 — 파일은 가짜 디스크에 쓴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->admin = $this->fullAdmin();
    }

    /**
     * 머리글(다국어)·보이기·색과 문구 표현값을 저장하고, 수정 화면·공개 응답에 그대로 싣는다(지연은 0.1초 단위 숫자, 자리는 정수).
     *
     * @scenario surface=text_layout_saved
     *
     * @effects eyebrow_persisted, editor_position_persisted, public_payload_text_layout
     *
     * @return void 반환값 없음
     */
    public function test_saves_eyebrow_and_text_layout_and_publishes_them(): void
    {
        $presentation = [
            'pc_x' => 30, 'overlay' => 'dark',
            'text_pc_x' => '50', 'text_pc_y' => 0, 'text_mobile_x' => 100, 'text_mobile_y' => 40, 'text_width' => '60',
            'animation' => 'letters', 'animation_speed' => 'fast', 'animation_delay' => '0.3', 'tone' => 'light',
        ];
        $id = $this->createBannerViaApi($this->admin, [
            'eyebrow' => ['ko' => ' 가을 신상 ', 'en' => 'New in'],
            'eyebrow_visible' => false,
            'eyebrow_color' => '#FDE68A',
            'presentation' => $presentation,
        ])->assertCreated()->json('data.id');

        $detail = $this->actingAs($this->admin)->getJson($this->api("/admin/banners/{$id}"))->assertOk()->json('data');
        $this->assertSame(['ko' => '가을 신상', 'en' => 'New in'], $detail['eyebrow']);
        $this->assertFalse($detail['eyebrow_visible']);
        $this->assertSame('#FDE68A', $detail['eyebrow_color']);
        $this->assertSame([
            'pc_x' => 30, 'pc_y' => 50, 'mobile_x' => 50, 'mobile_y' => 50, 'overlay' => 'dark',
            'text_pc_x' => 50, 'text_pc_y' => 0, 'text_mobile_x' => 100, 'text_mobile_y' => 40, 'text_width' => 60,
            'animation' => 'letters', 'animation_speed' => 'fast', 'animation_delay' => 0.3, 'tone' => 'light',
            // 보내지 않은 구성·바탕색·기기별 폭·그림 효과는 종전 모습의 기본값으로 응답한다
            'layout' => 'background', 'background_color' => null, 'text_width_pc' => null, 'text_width_mobile' => null, 'media_animation' => 'none',
        ], $detail['presentation']);

        $public = $this->getJson($this->api('/hero?position=home_hero&locale=en'))->assertOk()->json('data.banners.0');
        $this->assertSame('New in', $public['eyebrow']);
        $this->assertFalse($public['eyebrow_visible']);
        $this->assertSame('#FDE68A', $public['eyebrow_color']);
        $this->assertSame(60, $public['presentation']['text_width']);
        $this->assertSame('light', $public['presentation']['tone']);
    }

    /**
     * 표현값을 보내지 않은 기존 배너는 종전 화면(왼쪽 아래 전폭·효과 없음·어두움)과 머리글 없음으로 응답한다.
     * 머리글 보이기·색을 보내지 않은 수정은 저장한 값을 지킨다.
     *
     * @scenario surface=text_layout_defaults
     *
     * @return void 반환값 없음
     */
    public function test_existing_banner_keeps_previous_look_and_omitted_eyebrow_style(): void
    {
        $banner = Banner::factory()->withImages()->create(['presentation' => ['overlay' => 'dark'], 'eyebrow_color' => '#000000']);

        $public = $this->getJson($this->api('/hero?position=home_hero'))->assertOk()->json('data.banners.0');
        $this->assertSame('', $public['eyebrow']);
        $this->assertTrue($public['eyebrow_visible']);
        $this->assertSame(Banner::DEFAULT_PRESENTATION['text_pc_y'], $public['presentation']['text_pc_y']);
        $this->assertEquals(['text_pc_x' => 0, 'text_pc_y' => 100, 'text_width' => 100, 'animation' => 'none', 'tone' => 'dark', 'overlay' => 'dark'], array_intersect_key($public['presentation'], array_flip(['text_pc_x', 'text_pc_y', 'text_width', 'animation', 'tone', 'overlay'])));

        $revision = $this->actingAs($this->admin)->getJson($this->api("/admin/banners/{$banner->id}"))->json('data.revision');
        $this->actingAs($this->admin)->putJson($this->api("/admin/banners/{$banner->id}"), $this->bannerPayload(['revision' => $revision]))->assertOk();
        $this->assertSame('#000000', $banner->fresh()->eyebrow_color);
        $this->assertTrue($banner->fresh()->eyebrow_visible);
    }

    /**
     * 범위·종류를 벗어난 문구 표현값과 너무 긴 머리글·잘못된 색, 모르는 표현 키는 422 다.
     *
     * @scenario surface=text_layout_validation
     *
     * @return void 반환값 없음
     */
    public function test_rejects_invalid_text_layout_values(): void
    {
        $cases = [
            'presentation.text_pc_x' => ['presentation' => ['text_pc_x' => 101]],
            'presentation.text_mobile_y' => ['presentation' => ['text_mobile_y' => -1]],
            'presentation.text_width' => ['presentation' => ['text_width' => 20]],
            'presentation.animation' => ['presentation' => ['animation' => 'spin']],
            'presentation.animation_speed' => ['presentation' => ['animation_speed' => 'turbo']],
            'presentation.animation_delay' => ['presentation' => ['animation_delay' => 2.5]],
            'presentation.tone' => ['presentation' => ['tone' => 'grey']],
            'presentation' => ['presentation' => ['text_x' => 10]],
            'eyebrow' => ['eyebrow' => ['ko' => str_repeat('가', 51)]],
            'eyebrow_color' => ['eyebrow_color' => 'yellow'],
        ];
        foreach ($cases as $field => $overrides) {
            $this->createBannerViaApi($this->admin, $overrides)->assertStatus(422)->assertJsonValidationErrors([$field]);
        }

        // 편집 화면이 쓰는 입력 범위는 저장 규칙과 같은 값이다
        $editor = $this->actingAs($this->admin)->getJson($this->api('/admin/position-settings'))->assertOk()->json('data.editor');
        $this->assertSame(['min' => Banner::TEXT_WIDTH_MIN, 'max' => Banner::TEXT_WIDTH_MAX], $editor['text_width_limits']);
        $this->assertSame(['min' => 0, 'max' => Banner::ANIMATION_DELAY_MAX], $editor['animation_delay_limits']);

        // 0.1초 단위만
        $this->createBannerViaApi($this->admin, ['presentation' => ['animation_delay' => 0.25]])->assertStatus(422)->assertJsonValidationErrors(['presentation.animation_delay']);
        $this->createBannerViaApi($this->admin, ['presentation' => ['animation_delay' => 2, 'text_width' => 30]])->assertCreated();
    }

    /**
     * 메뉴 겹치기는 저장되고 공개 응답 설정에 실리며, 빼고 저장하면 지금 값을 지킨다. 화면 전체 폭에서만 받는 동안 힌트(slot_overlays)가 켜진다.
     *
     * @scenario surface=position_settings_header_overlay
     *
     * @effects position_setting_overlay_persisted, overlay_requires_full_width
     *
     * @return void 반환값 없음
     */
    public function test_header_overlay_saved_kept_and_hinted_only_for_full_width(): void
    {
        $this->registerPluginInstance();
        $url = $this->api('/admin/position-settings/home_hero');
        $base = ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide'];

        $this->actingAs($this->admin)->putJson($url, $base + ['header_overlay' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors(['header_overlay']);
        $this->actingAs($this->admin)->putJson($url, $base + ['width' => 'content', 'header_overlay' => true])
            ->assertOk()->assertJsonPath('data.settings.header_overlay', true);
        $this->assertSame(['home_hero' => false, 'shop_hero' => false], app(PositionSettingService::class)->slotOverlays());

        $this->actingAs($this->admin)->putJson($url, $base + ['width' => 'full'])->assertOk()->assertJsonPath('data.settings.header_overlay', true);
        $this->assertTrue(PositionSetting::query()->where('position', 'home_hero')->first()->toSettings()['header_overlay']);
        $this->assertTrue($this->getJson($this->api('/hero?position=home_hero'))->assertOk()->json('data.settings.header_overlay'));
        $this->assertSame(['home_hero' => true, 'shop_hero' => false], app(PositionSettingService::class)->slotOverlays());
        $this->assertSame(['home_hero' => true, 'shop_hero' => false], app(PluginSettingsService::class)->get('sirsoft-hero_section')['slot_overlays'] ?? null);

        $this->actingAs($this->admin)->putJson($url, $base + ['header_overlay' => false])->assertOk();
        $this->assertSame(['home_hero' => false, 'shop_hero' => false], app(PluginSettingsService::class)->get('sirsoft-hero_section')['slot_overlays'] ?? null);
    }
}
