<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Models\User;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 배너 구성(배경형·프로모션형)·바탕색·기기별 문구 폭·그림 등장 효과·순차 등장·제목 줄바꿈 — 저장·검증·공개 응답과 옛 배너 호환.
 * (매니페스트 promo-layout.yaml)
 */
class BannerPromoLayoutTest extends PluginTestCase
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
     * 프로모션형 표현값과 제목 줄바꿈을 저장하고 수정 화면·공개 응답에 그대로 싣는다. 다른 표현값(초점·문구 자리·바탕·밝기)은 함께 남는다.
     *
     * @scenario surface=promo_layout_saved
     *
     * @return void 반환값 없음
     */
    public function test_saves_promo_presentation_and_multiline_title(): void
    {
        $presentation = [
            'layout' => 'promo', 'background_color' => '#F4F5F8', 'text_width_pc' => 40, 'text_width_mobile' => 100, 'media_animation' => 'right',
            'animation' => 'sequence', 'animation_speed' => 'normal', 'animation_delay' => 0, 'pc_x' => 70, 'text_pc_y' => 50, 'text_mobile_y' => 100,
            'overlay' => 'light', 'tone' => 'light',
        ];
        $id = $this->createBannerViaApi($this->admin, [
            'title' => ['ko' => "가을 맞이\n커뮤니티 이벤트", 'en' => "Autumn\ncommunity event"],
            'description' => ['ko' => "글을 남기면\n추첨으로 선물", 'en' => "Leave a post\nto win a gift"],
            'presentation' => $presentation,
        ])->assertCreated()->json('data.id');

        $detail = $this->actingAs($this->admin)->getJson($this->api("/admin/banners/{$id}"))->assertOk()->json('data');
        $this->assertSame("가을 맞이\n커뮤니티 이벤트", $detail['title']['ko']);
        $this->assertSame(array_replace(Banner::DEFAULT_PRESENTATION, $presentation), $detail['presentation']);

        // 공개 응답은 요청 언어(시험 환경 en)의 문구 — 줄바꿈을 그대로 싣는다(글자로만, HTML 아님)
        $public = $this->getJson($this->api('/hero?position=home_hero'))->assertOk()->json('data.banners.0');
        $this->assertSame("Autumn\ncommunity event", $public['title']);
        $this->assertSame("Leave a post\nto win a gift", $public['description']);
        $this->assertSame(['promo', '#F4F5F8', 40, 100, 'right', 'sequence', 70, 'light'], [
            $public['presentation']['layout'], $public['presentation']['background_color'], $public['presentation']['text_width_pc'],
            $public['presentation']['text_width_mobile'], $public['presentation']['media_animation'], $public['presentation']['animation'],
            $public['presentation']['pc_x'], $public['presentation']['overlay'],
        ]);
    }

    /**
     * 새 키가 없는 옛 배너는 배경형·바탕색 없음·공통 폭(기기별 null)·그림 효과 없음으로 응답하고, 기존 비기본값(효과·속도·문구 자리·폭)은 그대로다.
     * 응답만으로 저장값을 다시 쓰지 않는다.
     *
     * @scenario surface=promo_layout_legacy_defaults
     *
     * @effects legacy_banner_not_rewritten
     *
     * @return void 반환값 없음
     */
    public function test_legacy_banner_reads_as_background_without_rewriting(): void
    {
        $stored = ['animation' => 'letters', 'animation_speed' => 'slow', 'text_pc_y' => 40, 'text_width' => 60, 'overlay' => 'dark', 'tone' => 'light'];
        $banner = Banner::factory()->withImages()->create(['presentation' => $stored]);

        $public = $this->getJson($this->api('/hero?position=home_hero'))->assertOk()->json('data.banners.0.presentation');
        $this->assertSame(['background', null, null, null, 'none'], [$public['layout'], $public['background_color'], $public['text_width_pc'], $public['text_width_mobile'], $public['media_animation']]);
        $this->assertSame(['letters', 'slow', 40, 60, 'dark', 'light'], [$public['animation'], $public['animation_speed'], $public['text_pc_y'], $public['text_width'], $public['overlay'], $public['tone']]);
        $this->actingAs($this->admin)->getJson($this->api("/admin/banners/{$banner->id}"))->assertOk();
        $after = $banner->fresh()->presentation;
        ksort($after);
        ksort($stored);
        $this->assertSame($stored, $after, '읽기만으로 표현값을 다시 저장하지 않는다(새 키가 붙지 않는다)');
    }

    /**
     * 프로모션형에서 배경형으로 돌아가도 다른 표현값은 그대로 남는다(구성만 바뀐다).
     *
     * @scenario surface=promo_layout_switch_back
     *
     * @effects other_presentation_kept_on_layout_switch
     *
     * @return void 반환값 없음
     */
    public function test_switching_back_to_background_keeps_other_values(): void
    {
        $presentation = ['layout' => 'promo', 'text_pc_x' => 100, 'text_width' => 70, 'animation' => 'zoom', 'pc_y' => 20];
        $id = $this->createBannerViaApi($this->admin, ['presentation' => $presentation])->assertCreated()->json('data.id');
        $revision = $this->actingAs($this->admin)->getJson($this->api("/admin/banners/{$id}"))->json('data.revision');

        $this->actingAs($this->admin)->putJson($this->api("/admin/banners/{$id}"), $this->bannerPayload([
            'revision' => $revision, 'presentation' => ['layout' => 'background'] + $presentation,
        ]))->assertOk();

        $saved = Banner::query()->find($id)->presentation;
        $this->assertSame(['background', 100, 70, 'zoom', 20], [$saved['layout'], $saved['text_pc_x'], $saved['text_width'], $saved['animation'], $saved['pc_y']]);
    }

    /**
     * 모르는 구성·그림 효과·잘못된 색·범위 밖 기기별 폭은 422 다. 바탕색·기기별 폭은 비울(null) 수 있다.
     *
     * @scenario surface=promo_layout_validation
     *
     * @return void 반환값 없음
     */
    public function test_rejects_invalid_promo_values(): void
    {
        foreach ([
            ['layout' => 'split'],
            ['media_animation' => 'spin'],
            ['background_color' => 'red'],
            ['background_color' => '#FFF'],
            ['text_width_pc' => 20],
            ['text_width_mobile' => 101],
            ['animation' => 'sequential'],
        ] as $bad) {
            $this->createBannerViaApi($this->admin, ['presentation' => $bad])->assertStatus(422)->assertJsonValidationErrors(['presentation.'.array_key_first($bad)]);
        }

        $this->createBannerViaApi($this->admin, ['presentation' => ['layout' => 'promo', 'background_color' => null, 'text_width_pc' => null, 'text_width_mobile' => null]])->assertCreated();
    }
}
