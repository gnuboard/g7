<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Listeners;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Plugin;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 공개 캐시 비우기 리스너 테스트 (매니페스트 public-hero.yaml · admin-banners.yaml)
 *
 * 동작으로 확인한다: 공개 응답을 캐시에 담은 뒤 DB 를 훅 없이 직접 바꾸면 캐시된 옛 값이 나와야 하고,
 * 관리자 동작(훅 발행)이 일어나면 새 값이 나와야 한다.
 */
class HeroCacheListenerTest extends PluginTestCase
{
    /**
     * 관리자
     */
    private User $admin;

    /**
     * 테스트 준비 — 캐시 시간을 켠다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->setPluginSettings(['public_cache_minutes' => 10]);
        $this->admin = $this->fullAdmin();
    }

    /**
     * 배너를 등록하면 그 위치 캐시가 비워진다.
     *
     * @scenario surface=cache_cleared_on_save
     *
     * @effects cache_key_per_position_locale
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_save(): void
    {
        $this->assertSame([], $this->titles());

        $this->createBannerViaApi($this->admin, ['title' => ['ko' => '새 배너']])->assertCreated();

        $this->assertSame(['새 배너'], $this->titles());
    }

    /**
     * 배너를 영구 삭제하면 그 위치 캐시가 비워진다.
     *
     * @scenario surface=cache_cleared_on_delete
     *
     * @effects cache_key_per_position_locale
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_delete(): void
    {
        $banner = Banner::factory()->withImages()->create(['title' => ['ko' => '지울 배너']]);
        $this->assertSame(['지울 배너'], $this->titles());

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/banners/'.$banner->id))->assertOk();

        $this->assertSame([], $this->titles());
    }

    /**
     * 일괄 비노출하면 그 위치 캐시가 비워진다.
     *
     * @scenario surface=cache_cleared_on_bulk_change
     *
     * @effects cache_key_per_position_locale
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_bulk_change(): void
    {
        $banner = Banner::factory()->withImages()->create(['title' => ['ko' => '배너']]);
        $this->assertSame(['배너'], $this->titles());

        $this->actingAs($this->admin)->patchJson($this->api('/admin/banners/bulk-status'), ['ids' => [$banner->id], 'is_active' => false])->assertOk();

        $this->assertSame([], $this->titles());
    }

    /**
     * 순서를 바꾸면 그 위치 캐시가 비워진다.
     *
     * @scenario surface=cache_cleared_on_reorder
     *
     * @effects cache_key_per_position_locale
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_reorder(): void
    {
        $a = Banner::factory()->withImages()->create(['title' => ['ko' => 'A'], 'sort_order' => 0]);
        $b = Banner::factory()->withImages()->create(['title' => ['ko' => 'B'], 'sort_order' => 1]);
        $this->assertSame(['A', 'B'], $this->titles());

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/order'), ['position' => 'home_hero', 'ids' => [$b->id, $a->id]])->assertOk();

        $this->assertSame(['B', 'A'], $this->titles());
    }

    /**
     * 수정 화면에서 이미지를 바꿔 저장하면 캐시가 비워진다(새 이미지 주소가 나온다). 올리기만 해서는 비우지 않는다.
     *
     * @scenario surface=cache_cleared_on_image_change
     *
     * @effects cache_key_per_position_locale
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_image_change(): void
    {
        $banner = Banner::factory()->withImages()->create();
        $before = $this->payload()['banners'][0]['images']['pc']['url'];
        $tempKey = (string) Str::uuid();

        $newId = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');
        $this->assertSame($before, $this->payload()['banners'][0]['images']['pc']['url']);

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$banner->id), $this->bannerPayload(['temp_key' => $tempKey, 'pc_image_id' => $newId]))->assertOk();

        $this->assertNotSame($before, $this->payload()['banners'][0]['images']['pc']['url']);
    }

    /**
     * 위치 슬라이더 설정을 바꾸면 그 위치 캐시가 비워진다.
     *
     * @scenario surface=cache_cleared_on_slider_setting_change
     *
     * @effects cache_key_per_position_locale
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_slider_setting_change(): void
    {
        Banner::factory()->withImages()->create();
        $this->assertSame('slide', $this->payload()['settings']['effect']);

        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/home_hero'), ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'fade'])->assertOk();

        $this->assertSame('fade', $this->payload()['settings']['effect']);
    }

    /**
     * 이 플러그인 설정 저장 훅이 오면 전체 캐시가 비워진다(다른 플러그인 설정은 무시).
     *
     * @scenario surface=cache_cleared_on_plugin_setting_change
     *
     * @effects cache_key_per_position_locale
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_plugin_setting_change(): void
    {
        $banner = Banner::factory()->withImages()->create(['title' => ['ko' => '옛']]);
        $this->assertSame(['옛'], $this->titles());
        DB::table('hero_section_banners')->where('id', $banner->id)->update(['title' => json_encode(['ko' => '새'])]);

        HookManager::doAction('core.plugin_settings.after_save', 'sirsoft-other', [], true);
        $this->assertSame(['옛'], $this->titles(), '다른 플러그인 설정은 캐시를 건드리지 않는다');

        HookManager::doAction('core.plugin_settings.after_save', 'sirsoft-hero_section', [], true);
        $this->assertSame(['새'], $this->titles());
    }

    /**
     * 플러그인을 비활성화하면 전체 캐시가 비워진다.
     *
     * @scenario surface=cache_cleared_on_plugin_deactivate
     *
     * @effects cache_key_per_position_locale
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_plugin_deactivate(): void
    {
        $banner = Banner::factory()->withImages()->create(['title' => ['ko' => '옛']]);
        $this->assertSame(['옛'], $this->titles());
        DB::table('hero_section_banners')->where('id', $banner->id)->update(['title' => json_encode(['ko' => '새'])]);

        (new Plugin(['identifier' => 'sirsoft-hero_section', 'vendor' => 'sirsoft', 'version' => '1.0.0']))->deactivate();

        $this->assertSame(['새'], $this->titles());
    }

    /**
     * 위치를 바꾼 수정은 옛·새 위치 캐시를 모두 비운다.
     *
     * @scenario surface=update_position_change_moves_to_end
     *
     * @effects public_cache_invalidated_for_position
     *
     * @return void 반환값 없음
     */
    public function test_position_change_clears_old_and_new_positions(): void
    {
        $banner = Banner::factory()->withImages()->create(['title' => ['ko' => '이동']]);
        $this->assertSame(['이동'], $this->titles(HeroPosition::HomeHero));
        $this->assertSame([], $this->titles(HeroPosition::ShopHero));

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$banner->id), $this->bannerPayload(['position' => 'shop_hero', 'title' => ['ko' => '이동']]))->assertOk();

        $this->assertSame([], $this->titles(HeroPosition::HomeHero));
        $this->assertSame(['이동'], $this->titles(HeroPosition::ShopHero));
    }

    /**
     * 다른 위치의 변경은 이 위치 캐시를 건드리지 않는다(위치별 무효화).
     *
     * @scenario surface=cache_reused_within_ttl
     *
     * @effects public_cache_invalidated_for_position, cache_key_per_position_locale
     *
     * @return void 반환값 없음
     */
    public function test_other_position_change_keeps_cache(): void
    {
        $home = Banner::factory()->withImages()->create(['title' => ['ko' => '홈']]);
        $this->assertSame(['홈'], $this->titles(HeroPosition::HomeHero));
        DB::table('hero_section_banners')->where('id', $home->id)->update(['title' => json_encode(['ko' => '바뀐 홈'])]);

        $this->createBannerViaApi($this->admin, ['position' => 'shop_hero'])->assertCreated();

        $this->assertSame(['홈'], $this->titles(HeroPosition::HomeHero), '쇼핑 메인 변경은 홈 캐시를 비우지 않는다');
    }

    /**
     * 공개 응답 본문을 반환합니다.
     *
     * @param  HeroPosition  $position  위치
     * @return array<string, mixed> 공개 응답
     */
    private function payload(HeroPosition $position = HeroPosition::HomeHero): array
    {
        return app(PublicHeroService::class)->forPosition($position, 'ko');
    }

    /**
     * 공개 응답의 배너 제목 목록을 반환합니다.
     *
     * @param  HeroPosition  $position  위치
     * @return array<int, string> 제목 목록
     */
    private function titles(HeroPosition $position = HeroPosition::HomeHero): array
    {
        return array_column($this->payload($position)['banners'], 'title');
    }
}
