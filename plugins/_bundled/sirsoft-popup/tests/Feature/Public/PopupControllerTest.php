<?php

namespace Plugins\Sirsoft\Popup\Tests\Feature\Public;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Services\PublicPopupService;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 공개 팝업 API 테스트 (매니페스트 public-popups.yaml — 응답 케이스)
 *
 * 사용자 레이어가 부르는 공개 API. 노출중 + 노출 대상(손님/회원)이 맞는 팝업만 순서대로, 요청 언어로 alt·본문을 고른다.
 * 경로·기기·보지 않기는 브라우저가 거른다(popup-layer·route-matching 매니페스트). 캐시는 `PublicPopupServiceTest` 가 본다.
 */
class PopupControllerTest extends PluginTestCase
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
     * 노출중 팝업만 sort_order 순으로 나온다.
     *
     * @scenario surface=returns_active_only_in_sort_order
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_returns_active_only_in_sort_order(): void
    {
        $second = Popup::factory()->withImages()->create(['sort_order' => 2]);
        $first = Popup::factory()->html()->create(['sort_order' => 1]);

        $this->assertSame([$first->id, $second->id], array_column($this->popups()->json('data.popups'), 'id'));
    }

    /**
     * 비활성·예약·종료 팝업은 나오지 않는다.
     *
     * @scenario surface=excludes_inactive_scheduled_ended
     *
     * @return void 반환값 없음
     */
    public function test_excludes_inactive_scheduled_ended(): void
    {
        Popup::factory()->inactive()->create();
        Popup::factory()->scheduled()->create();
        Popup::factory()->ended()->create();
        $active = Popup::factory()->html()->create();

        $this->assertSame([$active->id], array_column($this->popups()->json('data.popups'), 'id'));
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
        $this->rangePopup();
        Carbon::setTestNow(Carbon::parse(self::START, 'UTC')->subSecond());

        $this->assertSame([], $this->popups()->json('data.popups'));
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
        $popup = $this->rangePopup();

        foreach ([0, 1] as $seconds) {
            Carbon::setTestNow(Carbon::parse(self::START, 'UTC')->addSeconds($seconds));
            $this->assertSame([$popup->id], array_column($this->popups()->json('data.popups'), 'id'), "+{$seconds}s");
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
        $popup = $this->rangePopup();

        foreach ([1, 0] as $seconds) {
            Carbon::setTestNow(Carbon::parse(self::END, 'UTC')->subSeconds($seconds));
            $this->assertSame([$popup->id], array_column($this->popups()->json('data.popups'), 'id'), "-{$seconds}s");
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
        $this->rangePopup();
        Carbon::setTestNow(Carbon::parse(self::END, 'UTC')->addSecond());

        $this->assertSame([], $this->popups()->json('data.popups'));
    }

    /**
     * 비회원만 팝업은 회원에게 나오지 않는다(손님에게는 나온다).
     *
     * @scenario surface=audience_guest_only_hidden_for_member
     *
     * @return void 반환값 없음
     */
    public function test_audience_guest_only_hidden_for_member(): void
    {
        $guestOnly = Popup::factory()->html()->audience('guest')->create();
        $all = Popup::factory()->html()->create();

        $this->assertSame([$guestOnly->id, $all->id], $this->sortedIds($this->popups()));
        $this->assertSame([$all->id], $this->sortedIds($this->popups('ko', $this->memberToken())));
    }

    /**
     * 회원만 팝업은 손님에게 나오지 않는다(회원에게는 나온다).
     *
     * @scenario surface=audience_member_only_hidden_for_guest
     *
     * @return void 반환값 없음
     */
    public function test_audience_member_only_hidden_for_guest(): void
    {
        $memberOnly = Popup::factory()->html()->audience('member')->create();
        $all = Popup::factory()->html()->create();

        $this->assertSame([$all->id], $this->sortedIds($this->popups()));
        $this->assertSame([$memberOnly->id, $all->id], $this->sortedIds($this->popups('ko', $this->memberToken())));
    }

    /**
     * 정지 회원은 손님으로 본다(회원만 팝업 대신 비회원만 팝업이 나온다).
     *
     * @scenario surface=suspended_member_treated_as_guest
     *
     * @return void 반환값 없음
     */
    public function test_suspended_member_treated_as_guest(): void
    {
        $memberOnly = Popup::factory()->html()->audience('member')->create();
        $guestOnly = Popup::factory()->html()->audience('guest')->create();
        $user = User::factory()->create(['status' => UserStatus::Blocked->value]);

        $ids = $this->sortedIds($this->popups('ko', $user->createToken('test')->plainTextToken));

        $this->assertSame([$guestOnly->id], $ids);
        $this->assertNotContains($memberOnly->id, $ids);
    }

    /**
     * 노출할 팝업이 없어도 표시 방식은 함께 준다(빈 목록).
     *
     * @scenario surface=empty_returns_empty_list_with_settings
     *
     * @return void 반환값 없음
     */
    public function test_empty_returns_empty_list_with_settings(): void
    {
        $data = $this->popups()->assertOk()->json('data');

        $this->assertSame([], $data['popups']);
        $this->assertArrayHasKey('pc_width', $data['settings']);
    }

    /**
     * 표시 방식 설정(위치·크기·모바일 형태·배경·자동 넘김·닫기 옵션)을 싣는다.
     *
     * @scenario surface=includes_display_settings
     *
     * @return void 반환값 없음
     */
    public function test_includes_display_settings(): void
    {
        $this->setPluginSettings(['public_cache_minutes' => 0, 'pc_position' => 'coords', 'pc_left' => 40, 'mobile_style' => 'center', 'dismiss_mode' => 'days', 'dismiss_days' => 3]);

        $settings = $this->popups()->json('data.settings');

        $this->assertSame('coords', $settings['pc_position']);
        $this->assertSame(40, $settings['pc_left']);
        $this->assertSame('center', $settings['mobile_style']);
        $this->assertSame('days', $settings['dismiss_mode']);
        $this->assertSame(3, $settings['dismiss_days']);
        $this->assertTrue($settings['dim_background']);
        $this->assertSame(6, $settings['autoplay_interval']);
    }

    /**
     * 노출 페이지 범위·경로·기기를 실어 브라우저가 거르게 한다.
     *
     * @scenario surface=includes_routes_scope_and_device
     *
     * @return void 반환값 없음
     */
    public function test_includes_routes_scope_and_device(): void
    {
        Popup::factory()->html()->routes(['/shop', '/'])->device('mobile')->create();

        $row = $this->popups()->json('data.popups.0');

        $this->assertSame('routes', $row['page_scope']);
        $this->assertSame(['/shop', '/'], $row['routes']);
        $this->assertSame('mobile', $row['device']);
    }

    /**
     * 요청 언어의 alt 를 준다.
     *
     * @scenario surface=alt_in_requested_locale
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_alt_in_requested_locale(): void
    {
        Popup::factory()->withImages()->withAlts(['ko' => '가을 이벤트', 'en' => 'Autumn event'])->create();

        $this->assertSame('Autumn event', $this->popups('en')->json('data.popups.0.image_alt'));
    }

    /**
     * 요청 언어 alt 가 없으면 사이트 기본 언어 alt 로 대신한다.
     *
     * @scenario surface=alt_falls_back_to_default_locale
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_alt_falls_back_to_default_locale(): void
    {
        Popup::factory()->withImages()->withAlts(['ko' => '한국어만'])->create();

        $this->assertSame('한국어만', $this->popups('en')->json('data.popups.0.image_alt'));
    }

    /**
     * alt 가 전혀 없으면 관리용 제목으로 대신한다(링크 팝업의 이름이 비지 않게).
     *
     * @scenario surface=alt_falls_back_to_admin_title
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_alt_falls_back_to_admin_title(): void
    {
        Popup::factory()->withImages()->create(['image_alt' => null, 'title' => '가을 감사 이벤트']);

        $this->assertSame('가을 감사 이벤트', $this->popups()->json('data.popups.0.image_alt'));
    }

    /**
     * 요청 언어의 본문을 준다.
     *
     * @scenario surface=body_in_requested_locale
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_body_in_requested_locale(): void
    {
        Popup::factory()->html(['ko' => '<p>점검</p>', 'en' => '<p>Maintenance</p>'])->create();

        $this->assertSame('<p>Maintenance</p>', $this->popups('en')->json('data.popups.0.body_html'));
    }

    /**
     * 요청 언어 본문이 없으면 사이트 기본 언어 본문으로 대신한다.
     *
     * @scenario surface=body_falls_back_to_default_locale
     *
     * @effects public_payload_localized
     *
     * @return void 반환값 없음
     */
    public function test_body_falls_back_to_default_locale(): void
    {
        Popup::factory()->html(['ko' => '<p>한국어 공지</p>'])->create();

        $this->assertSame('<p>한국어 공지</p>', $this->popups('ja')->json('data.popups.0.body_html'));
    }

    /**
     * 모바일 이미지가 없으면 null — 사용자 레이어가 PC 이미지를 쓴다.
     *
     * @scenario surface=mobile_image_null_when_missing
     *
     * @return void 반환값 없음
     */
    public function test_mobile_image_null_when_missing(): void
    {
        Popup::factory()->withImages()->create();

        $image = $this->popups()->json('data.popups.0.image');

        $this->assertNotNull($image['pc']);
        $this->assertNull($image['mobile']);
    }

    /**
     * 이미지 가로·세로를 실어 화면이 자리를 먼저 잡게 한다.
     *
     * @scenario surface=includes_image_dimensions
     *
     * @return void 반환값 없음
     */
    public function test_includes_image_dimensions(): void
    {
        Popup::factory()->withImages(mobile: true)->create();

        $image = $this->popups()->json('data.popups.0.image');

        $this->assertSame([480, 520], [$image['pc']['width'], $image['pc']['height']]);
        $this->assertSame([750, 900], [$image['mobile']['width'], $image['mobile']['height']]);
        $this->assertStringStartsWith('/api/plugins/sirsoft-popup/images/', $image['pc']['url']);
    }

    /**
     * 운영 정보(관리용 제목·사용 여부·기간·작성자·저장 경로)는 싣지 않는다.
     *
     * @scenario surface=omits_internal_fields
     *
     * @return void 반환값 없음
     */
    public function test_omits_internal_fields(): void
    {
        Popup::factory()->withImages()->running()->create(['created_by' => 1]);

        $row = $this->popups()->json('data.popups.0');

        foreach (['title', 'is_active', 'starts_at', 'ends_at', 'display_period', 'created_by', 'updated_by', 'sort_order', 'audience', '_period'] as $field) {
            $this->assertArrayNotHasKey($field, $row, $field);
        }
        $this->assertArrayNotHasKey('path', $row['image']['pc']);
    }

    /**
     * 검색엔진 봇에게는 빈 목록을 준다(코어 봇 판정 훅으로 흉내).
     *
     * @scenario surface=bot_receives_empty_list
     *
     * @return void 반환값 없음
     */
    public function test_bot_receives_empty_list(): void
    {
        Popup::factory()->html()->create();
        HookManager::addFilter('core.seo.resolve_is_bot', fn () => true);

        $data = $this->popups()->assertOk()->json('data');

        $this->assertSame([], $data['popups']);
        $this->assertArrayHasKey('settings', $data);
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
        $id = $this->createPopupViaApi($admin, ['display_period' => 'range', 'starts_at' => '2026-12-01T09:00', 'ends_at' => '2026-12-31T09:00'])
            ->assertCreated()->json('data.id');

        // 서울 12-01 08:59:59 = UTC 11-30 23:59:59 — 아직 시작 전
        $before = Carbon::parse('2026-11-30 23:59:59', 'UTC');
        Carbon::setTestNow($before);
        $this->assertSame([], $this->popups()->json('data.popups'));
        $this->assertSame('2026-12-01 09:00:00', $this->actingAs($admin)->getJson($this->api('/admin/popups/'.$id))->json('data.starts_at_display'));
        $this->assertSame(1, app(PublicPopupService::class)->ttlSeconds($before, 10), '캐시는 시작 1초 전까지만');

        Carbon::setTestNow(Carbon::parse('2026-12-01 00:00:00', 'UTC'));
        $this->assertSame([$id], array_column($this->popups()->json('data.popups'), 'id'));
    }

    /**
     * 공개 API 를 부릅니다.
     *
     * @param  string  $locale  요청 언어
     * @param  string|null  $token  회원 토큰 (null 이면 손님)
     * @return TestResponse 응답
     */
    private function popups(string $locale = 'ko', ?string $token = null): TestResponse
    {
        $headers = ['Accept-Language' => $locale];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        // 앞 요청의 인증 상태가 남지 않게 매번 가드를 비운다
        $this->app['auth']->forgetGuards();

        return $this->getJson($this->api('/popups'), $headers)->assertOk();
    }

    /**
     * 활성 회원 토큰을 만듭니다.
     *
     * @return string 토큰
     */
    private function memberToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    /**
     * 응답의 팝업 id 를 정렬해 돌려줍니다.
     *
     * @param  TestResponse  $response  응답
     * @return array<int, int> 팝업 id
     */
    private function sortedIds(TestResponse $response): array
    {
        $ids = array_column($response->json('data.popups'), 'id');
        sort($ids);

        return $ids;
    }

    /**
     * 기준 기간(START~END)의 기간 지정 팝업을 만듭니다.
     *
     * @return Popup 팝업
     */
    private function rangePopup(): Popup
    {
        return Popup::factory()->html()->period(Carbon::parse(self::START, 'UTC'), Carbon::parse(self::END, 'UTC'))->create();
    }

    /** 0건에도 다음 예약 시각을 제공해 열린 화면이 갱신할 수 있다.
     * @return void 반환값 없음
     *
     * @scenario surface=public_refresh_timing
     *
     * @effects files_created_or_removed
     */
    public function test_public_payload_includes_next_transition_and_server_time_when_empty(): void
    {
        Carbon::setTestNow(Carbon::parse(self::START)->subSeconds(10));
        $this->rangePopup();
        $response = $this->popups()->assertOk();
        $this->assertSame([], $response->json('data.popups'));
        $this->assertSame(now()->toISOString(), $response->json('data.server_time'));
        $this->assertSame(Carbon::parse(self::START)->toISOString(), $response->json('data.refresh_at'));
    }
}
