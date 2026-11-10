<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Public;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 공개 히어로섹션 API 테스트 (매니페스트 public-hero.yaml — 노출·경계·언어·응답 모양)
 *
 * 판정을 보는 케이스라 캐시는 끈다(0분). 캐시 상한·재판정은 PublicHeroServiceTest, 캐시 비우기는 HeroCacheListenerTest.
 */
class HeroControllerTest extends PluginTestCase
{
    /**
     * 기준 시작 (UTC)
     */
    private const START = '2026-12-01 00:00:00';

    /**
     * 기준 종료 (UTC)
     */
    private const END = '2026-12-10 00:00:00';

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
     * 테스트 정리 — 고정 시각을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 노출중 배너만 sort_order 순으로 나온다.
     *
     * @scenario surface=returns_active_only_in_sort_order
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_returns_active_only_in_sort_order(): void
    {
        $second = Banner::factory()->withImages()->create(['sort_order' => 1, 'title' => ['ko' => '둘']]);
        $first = Banner::factory()->withImages()->create(['sort_order' => 0, 'title' => ['ko' => '하나']]);
        Banner::factory()->inactive()->withImages()->create(['sort_order' => 2]);

        $banners = $this->hero()->assertOk()->json('data.banners');

        $this->assertSame([$first->id, $second->id], array_column($banners, 'id'));
    }

    /**
     * 비활성 배너는 나오지 않는다.
     *
     * @scenario surface=excludes_inactive
     *
     * @return void 반환값 없음
     */
    public function test_excludes_inactive(): void
    {
        Banner::factory()->inactive()->withImages()->create();

        $this->assertSame([], $this->hero()->json('data.banners'));
    }

    /**
     * 시작 전(예약) 배너는 나오지 않는다.
     *
     * @scenario surface=excludes_scheduled
     *
     * @return void 반환값 없음
     */
    public function test_excludes_scheduled(): void
    {
        Banner::factory()->scheduled()->withImages()->create();

        $this->assertSame([], $this->hero()->json('data.banners'));
    }

    /**
     * 종료된 배너는 나오지 않는다.
     *
     * @scenario surface=excludes_ended
     *
     * @return void 반환값 없음
     */
    public function test_excludes_ended(): void
    {
        Banner::factory()->ended()->withImages()->create();

        $this->assertSame([], $this->hero()->json('data.banners'));
    }

    /**
     * 시작 1초 전에는 나오지 않는다.
     *
     * @scenario surface=start_boundary_just_before_excluded
     *
     * @return void 반환값 없음
     */
    public function test_start_boundary_just_before(): void
    {
        $this->rangeBanner();
        Carbon::setTestNow(Carbon::parse(self::START, 'UTC')->subSecond());

        $this->assertSame([], $this->hero()->json('data.banners'));
    }

    /**
     * 시작 정각부터(1초 뒤 포함) 나온다.
     *
     * @scenario surface=start_boundary_just_after_included
     *
     * @return void 반환값 없음
     */
    public function test_start_boundary_just_after(): void
    {
        $banner = $this->rangeBanner();

        foreach ([0, 1] as $seconds) {
            Carbon::setTestNow(Carbon::parse(self::START, 'UTC')->addSeconds($seconds));
            $this->assertSame([$banner->id], array_column($this->hero()->json('data.banners'), 'id'), "+{$seconds}s");
        }
    }

    /**
     * 종료 1초 전·정각에는 나온다.
     *
     * @scenario surface=end_boundary_just_before_included
     *
     * @return void 반환값 없음
     */
    public function test_end_boundary_just_before(): void
    {
        $banner = $this->rangeBanner();

        foreach ([1, 0] as $seconds) {
            Carbon::setTestNow(Carbon::parse(self::END, 'UTC')->subSeconds($seconds));
            $this->assertSame([$banner->id], array_column($this->hero()->json('data.banners'), 'id'), "-{$seconds}s");
        }
    }

    /**
     * 종료 1초 뒤에는 나오지 않는다.
     *
     * @scenario surface=end_boundary_just_after_excluded
     *
     * @return void 반환값 없음
     */
    public function test_end_boundary_just_after(): void
    {
        $this->rangeBanner();
        Carbon::setTestNow(Carbon::parse(self::END, 'UTC')->addSecond());

        $this->assertSame([], $this->hero()->json('data.banners'));
    }

    /**
     * 배너가 없는 위치는 빈 목록과 설정을 준다(구좌 숨김은 화면이 판단).
     *
     * @scenario surface=empty_position_returns_empty_list
     *
     * @return void 반환값 없음
     */
    public function test_empty_position_returns_empty_list(): void
    {
        $data = $this->hero('shop_hero')->assertOk()->json('data');

        $this->assertSame('shop_hero', $data['position']);
        $this->assertSame([], $data['banners']);
        $this->assertSame(PositionSetting::DEFAULTS, $data['settings']);
    }

    /**
     * 모르는 위치·빠진 위치는 422 다.
     *
     * @scenario surface=unknown_position_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_unknown_position_returns_422(): void
    {
        $this->hero('side_bar')->assertStatus(422);
        $this->getJson($this->api('/hero'))->assertStatus(422);
    }

    /**
     * 요청 언어(Accept-Language)의 문구를 준다.
     *
     * @scenario surface=text_in_requested_locale
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_text_in_requested_locale(): void
    {
        Banner::factory()->withImages()->create(['title' => ['ko' => '가을', 'en' => 'Autumn'], 'button_text' => ['ko' => '보기', 'en' => 'Shop']]);

        $row = $this->hero('home_hero', 'en')->json('data.banners.0');

        $this->assertSame('Autumn', $row['title']);
        $this->assertSame('Shop', $row['button_text']);
    }

    /**
     * 요청 언어 문구가 없으면 사이트 기본 언어 문구로 대신한다.
     *
     * @scenario surface=text_falls_back_to_site_default_locale
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_text_falls_back_to_site_default(): void
    {
        Banner::factory()->withImages()->create(['title' => ['ko' => '한국어만']]);

        $this->assertSame('한국어만', $this->hero('home_hero', 'en')->json('data.banners.0.title'));
    }

    /**
     * 요청 언어·기본 언어 모두 없으면 en 으로 대신한다.
     *
     * @scenario surface=text_falls_back_to_en
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_text_falls_back_to_en(): void
    {
        Banner::factory()->withImages()->create(['title' => ['ko' => '제목'], 'description' => ['en' => 'English only']]);

        $this->assertSame('English only', $this->hero('home_hero', 'ko')->json('data.banners.0.description'));
    }

    /**
     * alt 가 비면 제목으로 대신한다.
     *
     * @scenario surface=alt_falls_back_to_title
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_alt_falls_back_to_title(): void
    {
        Banner::factory()->withImages()->create(['title' => ['ko' => '대체될 제목'], 'image_alt' => null]);
        Banner::factory()->withImages()->create(['title' => ['ko' => '제목'], 'image_alt' => ['ko' => '설명 문구'], 'sort_order' => 1]);

        $rows = $this->hero()->json('data.banners');

        $this->assertSame('대체될 제목', $rows[0]['image_alt']);
        $this->assertSame('설명 문구', $rows[1]['image_alt']);
    }

    /**
     * 모바일 이미지가 없으면 mobile 은 null 이다(화면이 PC 이미지를 쓴다).
     *
     * @scenario surface=mobile_image_null_when_missing
     *
     * @return void 반환값 없음
     */
    public function test_mobile_image_null_when_missing(): void
    {
        Banner::factory()->withImages(mobile: false)->create();

        $images = $this->hero()->json('data.banners.0.images');

        $this->assertNotNull($images['pc']);
        $this->assertNull($images['mobile']);
    }

    /**
     * 위치 슬라이더 설정을 함께 준다.
     *
     * @scenario surface=includes_position_slider_settings
     *
     * @return void 반환값 없음
     */
    public function test_includes_slider_settings(): void
    {
        PositionSetting::query()->create(['position' => 'home_hero', 'autoplay' => false, 'interval_seconds' => 7, 'effect' => 'fade']);

        $this->assertSame(['autoplay' => false, 'interval_seconds' => 7, 'effect' => 'fade', 'width' => 'content', 'header_overlay' => false, 'pagination' => 'dots'], $this->hero()->json('data.settings'));
    }

    /**
     * 이미지 가로·세로를 함께 준다(화면 밀림 방지용).
     *
     * @scenario surface=includes_image_dimensions
     *
     * @return void 반환값 없음
     */
    public function test_includes_image_dimensions(): void
    {
        Banner::factory()->withImages(mobile: true)->create();

        $images = $this->hero()->json('data.banners.0.images');

        $this->assertSame(['width' => 1920, 'height' => 640], ['width' => $images['pc']['width'], 'height' => $images['pc']['height']]);
        $this->assertSame(['width' => 750, 'height' => 1000], ['width' => $images['mobile']['width'], 'height' => $images['mobile']['height']]);
    }

    /**
     * 공개 응답의 이미지에는 직접 주소 키(`direct_url`)가 있다 — 기본 디스크 행은 null 이고 `url` 은 공개 API 주소다 (#878).
     *
     * @scenario surface=direct_url
     *
     * @effects hero_direct_url_null_for_streaming_disk
     *
     * @return void 반환값 없음
     */
    public function test_public_images_carry_direct_url_key(): void
    {
        Banner::factory()->withImages(mobile: true)->create();

        $images = $this->hero()->json('data.banners.0.images');

        $this->assertArrayHasKey('direct_url', $images['pc']);
        $this->assertNull($images['pc']['direct_url']);
        $this->assertArrayHasKey('direct_url', $images['mobile']);
        $this->assertStringStartsWith('/api/plugins/sirsoft-hero_section/images/', $images['pc']['url']);
    }

    /**
     * 운영 정보(사용 여부·기간·순서·작성자·내부 판정값)는 싣지 않는다.
     *
     * @scenario surface=omits_internal_fields
     *
     * @return void 반환값 없음
     */
    public function test_omits_internal_fields(): void
    {
        Banner::factory()->running()->withImages()->create(['created_by' => 1]);

        $row = $this->hero()->json('data.banners.0');

        foreach (['is_active', 'display_period', 'starts_at', 'ends_at', 'sort_order', 'created_by', 'updated_by', '_period', 'position'] as $key) {
            $this->assertArrayNotHasKey($key, $row, $key);
        }
        $this->assertArrayNotHasKey('path', $row['images']['pc']);
    }

    /**
     * 사이트 시간대(서울)로 입력한 시작 시각에서 노출 판정·캐시 경계·관리자 표시가 같은 시각을 가리킨다.
     *
     * @scenario surface=site_timezone_boundary_consistent
     *
     * @effects cache_never_outlives_period_boundary
     *
     * @return void 반환값 없음
     */
    public function test_site_timezone_boundary_consistent(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        $admin = $this->fullAdmin();
        $id = $this->createBannerViaApi($admin, ['display_period' => 'range', 'starts_at' => '2026-12-01T09:00', 'ends_at' => '2026-12-31T09:00'])
            ->assertCreated()->json('data.id');

        // 서울 12-01 08:59:59 = UTC 11-30 23:59:59 — 아직 시작 전
        $before = Carbon::parse('2026-11-30 23:59:59', 'UTC');
        Carbon::setTestNow($before);
        $this->assertSame([], $this->hero()->json('data.banners'));
        $this->assertSame('2026-12-01 09:00:00', $this->actingAs($admin)->getJson($this->api('/admin/banners/'.$id))->json('data.starts_at_display'));
        $this->assertSame(1, app(PublicHeroService::class)->ttlSeconds(Placement::of('home_hero'), $before, 10), '캐시는 시작 1초 전까지만');

        Carbon::setTestNow(Carbon::parse('2026-12-01 00:00:00', 'UTC'));
        $this->assertSame([$id], array_column($this->hero()->json('data.banners'), 'id'));
    }

    /**
     * 공개 API 를 부릅니다.
     *
     * @param  string  $position  위치 키
     * @param  string  $locale  요청 언어
     * @return TestResponse 응답
     */
    private function hero(string $position = 'home_hero', string $locale = 'ko'): TestResponse
    {
        return $this->getJson($this->api('/hero?position='.$position), ['Accept-Language' => $locale]);
    }

    /**
     * 비로그인 방문자에게는 수정 권한이 없다 — 사용자 화면에 「관리자에서 수정」 단추를 그리지 않는다.
     *
     * @scenario surface=edit_ability_guest_is_false
     *
     * @return void 반환값 없음
     */
    public function test_guest_has_no_edit_ability(): void
    {
        Banner::factory()->withImages()->create();

        $this->hero()->assertOk()->assertJsonPath('data.abilities.can_update', false);
    }

    /**
     * 배너 수정 권한이 있는 회원이 로그인해 받으면 수정 권한이 참이다.
     *
     * @scenario surface=edit_ability_follows_update_permission
     *
     * @return void 반환값 없음
     */
    public function test_banner_editor_has_edit_ability(): void
    {
        Banner::factory()->withImages()->create();
        $editor = $this->adminWith(['sirsoft-hero_section.banners.read', 'sirsoft-hero_section.banners.update']);

        $this->heroAs($editor)->assertOk()->assertJsonPath('data.abilities.can_update', true);
    }

    /**
     * 조회 권한만 있는 회원은 수정 권한이 없다.
     *
     * @scenario surface=edit_ability_follows_update_permission
     *
     * @return void 반환값 없음
     */
    public function test_reader_has_no_edit_ability(): void
    {
        Banner::factory()->withImages()->create();
        $reader = $this->adminWith(['sirsoft-hero_section.banners.read']);

        $this->heroAs($reader)->assertOk()->assertJsonPath('data.abilities.can_update', false);
    }

    /**
     * 공개 캐시는 방문자 공용이라 수정 권한을 담지 않는다 — 편집자가 먼저 받아 캐시가 채워져도 다음 비로그인 방문자는 거짓이다.
     *
     * @scenario surface=edit_ability_not_cached_across_visitors
     *
     * @return void 반환값 없음
     */
    public function test_edit_ability_is_not_cached_across_visitors(): void
    {
        $this->setPluginSettings(['public_cache_minutes' => 10]);
        Banner::factory()->withImages()->create();
        $editor = $this->adminWith(['sirsoft-hero_section.banners.read', 'sirsoft-hero_section.banners.update']);

        $this->heroAs($editor)->assertJsonPath('data.abilities.can_update', true);
        $this->app['auth']->forgetGuards();

        $this->hero()->assertJsonPath('data.abilities.can_update', false);
    }

    /**
     * 로그인한 회원의 토큰을 실어 공개 API 를 받습니다.
     *
     * @param  User  $user  회원
     * @return TestResponse 응답
     */
    private function heroAs(User $user): TestResponse
    {
        $token = $user->createToken('hero-test')->plainTextToken;

        return $this->getJson($this->api('/hero?position=home_hero'), ['Accept-Language' => 'ko', 'Authorization' => 'Bearer '.$token]);
    }

    /**
     * 기준 기간(START~END)의 기간 지정 배너를 만듭니다.
     *
     * @return Banner 배너
     */
    private function rangeBanner(): Banner
    {
        return Banner::factory()->withImages()->create([
            'display_period' => 'range',
            'starts_at' => Carbon::parse(self::START, 'UTC'),
            'ends_at' => Carbon::parse(self::END, 'UTC'),
        ]);
    }
}
