<?php

namespace Plugins\Sirsoft\Popup\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 관리자 팝업 API 테스트 (매니페스트 admin-popups.yaml — 목록·등록·수정·삭제·일괄·복사·순서·표시 방식)
 *
 * 검증 규칙 자체는 `PopupRequestTest`, 이미지 업로드는 `PopupImageControllerTest` 가 본다.
 */
/** [case:backend-popup-visually-empty] 표시 내용 없는 본문 등록·수정 회귀. */
class PopupControllerTest extends PluginTestCase
{
    /**
     * 조회·등록수정·삭제 권한을 가진 관리자
     */
    private User $admin;

    /**
     * 테스트 준비 — 이미지 파일은 가짜 디스크에 쓴다.
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
     * 상태 필터 — 노출중·예약·종료·비활성을 고른 것만.
     *
     * @scenario surface=list_filter_status
     *
     * @return void 반환값 없음
     */
    public function test_list_filters_by_status(): void
    {
        $active = Popup::factory()->create();
        $scheduled = Popup::factory()->scheduled()->create();
        $ended = Popup::factory()->ended()->create();
        $inactive = Popup::factory()->inactive()->create();

        foreach (['active' => $active, 'scheduled' => $scheduled, 'ended' => $ended, 'inactive' => $inactive] as $status => $popup) {
            $ids = $this->actingAs($this->admin)->getJson($this->api('/admin/popups?status='.$status))->assertOk()->json('data.data.*.id');
            $this->assertSame([$popup->id], $ids, $status);
        }
    }

    /**
     * 상태 필터와 쪽 넘김의 총건수·마지막 쪽이 SQL 결과와 같다(PHP 에서 거르지 않는다).
     *
     * @scenario surface=list_status_filter_paginates_in_sql
     *
     * @return void 반환값 없음
     */
    public function test_status_filter_paginates_in_sql(): void
    {
        Popup::factory()->count(5)->create();
        Popup::factory()->count(3)->scheduled()->create();

        $response = $this->actingAs($this->admin)->getJson($this->api('/admin/popups?status=active&per_page=2'))->assertOk();

        $this->assertSame(5, $response->json('data.pagination.total'));
        $this->assertSame(3, $response->json('data.pagination.last_page'));
        $this->assertCount(2, $response->json('data.data'));
    }

    /**
     * 상태 정렬은 노출중 → 예약 → 종료 → 비활성, 같은 상태 안은 순서·id 로 전순서.
     *
     * @scenario surface=list_sort_by_status_total_order
     *
     * @return void 반환값 없음
     */
    public function test_list_sorts_by_status_with_total_order(): void
    {
        $inactive = Popup::factory()->inactive()->create(['sort_order' => 0]);
        $ended = Popup::factory()->ended()->create(['sort_order' => 1]);
        $scheduled = Popup::factory()->scheduled()->create(['sort_order' => 2]);
        $activeB = Popup::factory()->create(['sort_order' => 4]);
        $activeA = Popup::factory()->create(['sort_order' => 3]);

        $ids = $this->actingAs($this->admin)->getJson($this->api('/admin/popups?sort_by=status&sort_order=asc'))
            ->assertOk()->json('data.data.*.id');

        $this->assertSame([$activeA->id, $activeB->id, $scheduled->id, $ended->id, $inactive->id], $ids);
    }

    /**
     * 기기 필터는 「그 기기에 보이는 팝업」 — 전체 기기 팝업도 함께 나온다.
     *
     * @scenario surface=list_filter_device
     *
     * @return void 반환값 없음
     */
    public function test_list_filters_by_device_visibility(): void
    {
        $all = Popup::factory()->create();
        $pc = Popup::factory()->device('pc')->create();
        $mobile = Popup::factory()->device('mobile')->create();

        $ids = $this->actingAs($this->admin)->getJson($this->api('/admin/popups?device=mobile'))->assertOk()->json('data.data.*.id');
        sort($ids);

        $this->assertSame([$all->id, $mobile->id], $ids);
        $this->assertNotContains($pc->id, $ids);
    }

    /**
     * 관리용 제목으로 찾는다(LIKE 특수문자는 글자로).
     *
     * @scenario surface=list_search_admin_title
     *
     * @return void 반환값 없음
     */
    public function test_list_searches_admin_title(): void
    {
        $hit = Popup::factory()->create(['title' => '추석 연휴 배송 안내 100%']);
        Popup::factory()->create(['title' => '가을 감사 이벤트']);

        $ids = $this->actingAs($this->admin)->getJson($this->api('/admin/popups?search='.urlencode('100%')))->assertOk()->json('data.data.*.id');

        $this->assertSame([$hit->id], $ids);
    }

    /**
     * 상태별 건수와 전체 건수를 싣는다.
     *
     * @scenario surface=list_statistics_by_status
     *
     * @return void 반환값 없음
     */
    public function test_list_returns_statistics(): void
    {
        Popup::factory()->count(2)->create();
        Popup::factory()->scheduled()->create();
        Popup::factory()->inactive()->create();

        $stats = $this->actingAs($this->admin)->getJson($this->api('/admin/popups'))->assertOk()->json('data.statistics');

        $this->assertSame(4, $stats['total']);
        $this->assertSame(['active' => 2, 'scheduled' => 1, 'ended' => 0, 'inactive' => 1], $stats['statuses']);
    }

    /**
     * 목록 단추 표시는 abilities 로 — 조회만 가진 관리자는 등록·수정·삭제가 false.
     *
     * @scenario surface=list_collection_abilities
     *
     * @effects response_shape_with_abilities
     *
     * @return void 반환값 없음
     */
    public function test_list_abilities_follow_permissions(): void
    {
        Popup::factory()->create();

        $full = $this->actingAs($this->admin)->getJson($this->api('/admin/popups'))->assertOk()->json('data');
        $this->assertSame(['can_create' => true, 'can_update' => true, 'can_delete' => true], $full['abilities']);
        $this->assertTrue($full['data'][0]['abilities']['can_update']);

        $reader = $this->adminWith(['sirsoft-popup.popups.read']);
        $read = $this->actingAs($reader)->getJson($this->api('/admin/popups'))->assertOk()->json('data');
        $this->assertSame(['can_create' => false, 'can_update' => false, 'can_delete' => false], $read['abilities']);
        $this->assertFalse($read['data'][0]['abilities']['can_delete']);
    }

    /**
     * 「지금 노출 중」은 노출중 팝업만 캐러셀 순서대로 싣는다(썸네일·기기 포함).
     *
     * @scenario surface=summary_returns_active_in_sort_order
     *
     * @return void 반환값 없음
     */
    public function test_summary_returns_active_in_sort_order(): void
    {
        $second = Popup::factory()->withImages()->create(['sort_order' => 2]);
        $first = Popup::factory()->device('mobile')->create(['sort_order' => 1]);
        Popup::factory()->scheduled()->create(['sort_order' => 0]);
        Popup::factory()->inactive()->create(['sort_order' => 0]);

        $rows = $this->actingAs($this->admin)->getJson($this->api('/admin/popups/summary'))->assertOk()->json('data.data');

        $this->assertSame([$first->id, $second->id], array_column($rows, 'id'));
        $this->assertSame('mobile', $rows[0]['device']);
        $this->assertNotNull($rows[1]['thumbnail']['url']);
    }

    /**
     * 순서 변경 창은 상태와 관계없이 팝업 전체를 순서대로 받는다.
     *
     * @scenario surface=order_list_returns_all
     *
     * @return void 반환값 없음
     */
    public function test_order_list_returns_all(): void
    {
        $b = Popup::factory()->inactive()->create(['sort_order' => 1]);
        $a = Popup::factory()->ended()->create(['sort_order' => 0]);

        $ids = $this->actingAs($this->admin)->getJson($this->api('/admin/popups/order'))->assertOk()->json('data.data.*.id');

        $this->assertSame([$a->id, $b->id], $ids);
    }

    /**
     * 등록하면 맨 뒤 순서가 되고 등록자가 남는다.
     *
     * @scenario surface=store_appends_to_end
     *
     * @effects db_state_matches_request
     *
     * @return void 반환값 없음
     */
    public function test_store_appends_to_end(): void
    {
        Popup::factory()->create(['sort_order' => 4]);

        $data = $this->createPopupViaApi($this->admin)->assertCreated()->json('data');

        $this->assertSame(5, $data['sort_order']);
        $popup = Popup::query()->find($data['id']);
        $this->assertSame($this->admin->id, $popup->created_by);
        $this->assertSame('가을 감사 이벤트', $popup->title);
        $this->assertSame(['ko' => '가을 감사 이벤트 안내', 'en' => 'Autumn thanks event'], $popup->image_alt);
    }

    /**
     * 등록하면 임시 묶음의 PC·모바일 이미지가 팝업에 연결되고 temp_key 가 비워진다.
     *
     * @scenario surface=store_links_temp_images
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_store_links_temp_images(): void
    {
        $data = $this->createPopupViaApi($this->admin, [], mobile: true)->assertCreated()->json('data');

        $this->assertNotNull($data['images']['pc']);
        $this->assertNotNull($data['images']['mobile']);
        $this->assertSame(2, PopupImage::query()->where('popup_id', $data['id'])->whereNull('temp_key')->count());
    }

    /**
     * 직접 작성형은 이미지·alt·링크를 저장하지 않는다(임시 묶음 이미지도 붙이지 않는다).
     *
     * @scenario surface=store_html_type_excludes_image_fields
     *
     * @return void 반환값 없음
     */
    public function test_store_html_type_excludes_image_fields(): void
    {
        $tempKey = (string) Str::uuid();
        $this->uploadImage($this->admin, 'pc', $tempKey)->assertCreated();

        $data = $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $this->htmlPopupPayload([
            'temp_key' => $tempKey,
            'image_alt' => ['ko' => '남으면 안 됨'],
            'link_url' => '/should-not-stay',
            'link_new_window' => true,
        ]))->assertCreated()->json('data');

        $popup = Popup::query()->find($data['id']);
        $this->assertSame('html', $popup->content_type);
        $this->assertNull($popup->image_alt);
        $this->assertNull($popup->link_url);
        $this->assertFalse($popup->link_new_window);
        $this->assertSame(0, PopupImage::query()->where('popup_id', $popup->id)->count());
        $this->assertStringContainsString('<h2>점검 안내</h2>', $popup->body_html['ko']);
    }

    /**
     * 경로는 끝 `/` 를 떼고 중복을 지워 저장한다. 전체 페이지면 경로를 비운다.
     *
     * @scenario surface=store_routes_normalized_and_deduplicated
     *
     * @return void 반환값 없음
     */
    public function test_store_routes_normalized_and_deduplicated(): void
    {
        $routed = $this->createPopupViaApi($this->admin, ['page_scope' => 'routes', 'routes' => [' /shop/ ', '/shop', '/', '/page//']])->json('data');
        // `/page//` 는 끝 `/` 를 모두 떼면 `/page` 가 된다
        $this->assertSame(['/shop', '/', '/page'], Popup::query()->find($routed['id'])->routes);

        $all = $this->createPopupViaApi($this->admin, ['page_scope' => 'all', 'routes' => ['/ignored']])->assertCreated()->json('data');
        $this->assertNull(Popup::query()->find($all['id'])->routes);
    }

    /**
     * 직접 작성형 본문은 저장 때 정화된다(script·이벤트 속성·javascript 링크 제거).
     *
     * @scenario surface=body_html_sanitized_on_store
     *
     * @effects db_state_matches_request
     *
     * @return void 반환값 없음
     */
    public function test_body_html_sanitized_on_store(): void
    {
        $data = $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $this->htmlPopupPayload([
            'body_html' => ['ko' => '<p onclick="x()">안내</p><script>alert(1)</script><a href="javascript:alert(1)">링크</a>'],
        ]))->assertCreated()->json('data');

        $stored = Popup::query()->find($data['id'])->body_html['ko'];
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringContainsString('안내', $stored);
    }

    /**
     * 정화로 내용이 바뀌면 응답에 sanitized=true, 그대로면 false.
     *
     * @scenario surface=body_html_sanitized_flag_in_response
     *
     * @effects response_shape_with_abilities
     *
     * @return void 반환값 없음
     */
    public function test_sanitized_flag_in_response(): void
    {
        $dirty = $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $this->htmlPopupPayload([
            'body_html' => ['ko' => '<p>안내</p><iframe src="https://evil.example"></iframe>'],
        ]))->assertCreated();
        $this->assertTrue($dirty->json('data.sanitized'));

        $clean = $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $this->htmlPopupPayload([
            'body_html' => ['ko' => '<p><strong>안내</strong></p>'],
        ]))->assertCreated();
        $this->assertFalse($clean->json('data.sanitized'));
        $this->assertTrue($clean->json('data.abilities.can_update'));
    }

    /**
     * 정화하고 나면 비는 본문(허용하지 않는 요소만 있음)은 등록·수정 모두 422 이고 저장하지 않는다 — 빈 팝업이 뜨지 않게.
     *
     * @scenario surface=body_emptied_by_sanitize_rejected
     *
     * @return void 반환값 없음
     */
    public function test_body_emptied_by_sanitize_is_rejected(): void
    {
        $before = Popup::query()->count();
        $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $this->htmlPopupPayload([
            'body_html' => ['ko' => '<iframe src="https://evil.example"></iframe>'],
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'body_html' => __('sirsoft-popup::validation.body_html.emptied_by_sanitize', ['locale' => config('app.locale_names.ko', 'ko')]),
        ]);
        $this->assertSame($before, Popup::query()->count());

        // 기본 언어가 아닌 언어도 정화 뒤 비면 막는다(그 언어 방문자에게 빈 팝업이 뜬다)
        $popup = Popup::factory()->html()->create();
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->htmlPopupPayload([
            'body_html' => ['ko' => '<p>정상 본문</p>', 'en' => '<script>alert(1)</script>'],
            'revision' => 0,
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'body_html' => __('sirsoft-popup::validation.body_html.emptied_by_sanitize', ['locale' => config('app.locale_names.en', 'en')]),
        ]);
        $this->assertSame(['ko' => '<p>공지</p>'], $popup->fresh()->body_html);
    }

    /**
     * 정화 뒤 빈 본문 안내는 화면의 언어 탭과 같은 언어 이름으로 알린다(「English 본문에 …」) — 코드(`en`)만으로는 어느 탭인지 바로 알기 어렵다.
     * 이름을 정하지 않은 언어는 코드로 알린다.
     *
     * @scenario surface=body_emptied_message_names_language
     *
     * @effects validation_messages_name_languages
     *
     * @return void 반환값 없음
     */
    public function test_emptied_body_message_names_the_language(): void
    {
        config([
            'app.translatable_locales' => ['ko', 'en', 'ja', 'xx'],
            'app.locale_names' => ['ko' => '한국어', 'en' => 'English', 'ja' => '日本語'],
        ]);
        $cases = [
            'ko' => ['ko' => '<script>alert(1)</script>'],
            'en' => ['ko' => '<p>정상 본문</p>', 'en' => '<script>x</script>'],
            'ja' => ['ko' => '<p>정상 본문</p>', 'ja' => '<p>&nbsp;</p><p> </p>'],
            'xx' => ['ko' => '<p>정상 본문</p>', 'xx' => '<iframe src="https://evil.example"></iframe>'],
        ];
        $expected = ['ko' => '한국어 본문에', 'en' => 'English 본문에', 'ja' => '日本語 본문에', 'xx' => 'xx 본문에'];
        foreach ($cases as $locale => $body) {
            $message = $this->withHeader('Accept-Language', 'ko')->actingAs($this->admin)
                ->postJson($this->api('/admin/popups'), $this->htmlPopupPayload(['body_html' => $body]))
                ->assertStatus(422)->json('errors.body_html.0');
            $this->assertStringStartsWith($expected[$locale], (string) $message, $locale);
        }

        $english = $this->withHeader('Accept-Language', 'en')->actingAs($this->admin)
            ->postJson($this->api('/admin/popups'), $this->htmlPopupPayload(['body_html' => $cases['en']]))
            ->assertStatus(422)->json('errors.body_html.0');
        $this->assertStringStartsWith('The English content has nothing to display.', (string) $english);
    }

    /**
     * 사이트 시간대로 받은 기간은 UTC 로 저장된다.
     *
     * @scenario surface=period_site_timezone_stored_utc
     *
     * @return void 반환값 없음
     */
    public function test_period_is_stored_in_utc(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        $data = $this->createPopupViaApi($this->admin, [
            'display_period' => 'range',
            'starts_at' => '2026-12-01T09:00',
            'ends_at' => '2026-12-31T23:59',
        ])->assertCreated()->json('data');

        $popup = Popup::query()->find($data['id']);
        $this->assertSame('2026-12-01 00:00:00', $popup->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 14:59:00', $popup->ends_at->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * 관리자 응답의 기간은 사이트 시간대로 보이고, 상태 판정은 UTC 로 같은 시각을 가리킨다.
     *
     * @scenario surface=admin_period_display_in_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_period_is_displayed_in_site_timezone(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        $popup = Popup::factory()->create([
            'display_period' => 'range',
            'starts_at' => Carbon::parse('2026-12-01 00:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-12-31 14:59:00', 'UTC'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 23:59:59', 'UTC'));

        $data = $this->actingAs($this->admin)->getJson($this->api('/admin/popups/'.$popup->id))->assertOk()->json('data');

        $this->assertSame('2026-12-01T09:00', $data['starts_at_local']);
        $this->assertSame('2026-12-01 09:00:00', $data['starts_at_display']);
        $this->assertSame('2026-12-31T23:59', $data['ends_at_local']);
        // 사이트 시간대로 12-01 08:59:59 — 아직 시작 전
        $this->assertSame('scheduled', $data['status']);
    }

    /**
     * 수정은 바뀐 필드를 활동 로그 changes 에 남기고 세대 번호를 올린다.
     *
     * @scenario surface=update_detects_changes_for_log
     *
     * @effects db_state_matches_request
     *
     * @return void 반환값 없음
     */
    public function test_update_detects_changes_for_log(): void
    {
        $popup = Popup::factory()->withImages()->create(['link_url' => '/old']);

        $data = $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->popupPayload(['link_url' => '/new', 'revision' => 0]))
            ->assertOk()->json('data');

        $this->assertSame(1, $data['revision']);
        $log = DB::table('activity_logs')->where('action', 'popup.update')->where('loggable_id', $popup->id)->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('link_url', (string) $log->changes);
    }

    /**
     * 언어팩을 꺼서 지원 목록에서 빠진 언어의 번역이 저장돼 있어도 수정이 막히지 않고, 그 번역은 지우지 않는다.
     *
     * 수정 화면은 불러온 본문·대체 문구를 언어 키째 다시 보낸다. 저장돼 있지 않던 지원 밖 언어를 새로 넣는 것은
     * 등록처럼 막는다(`store_rejects_inactive_locale_key`).
     *
     * @scenario surface=update_keeps_inactive_locale_translations
     *
     * @return void 반환값 없음
     */
    public function test_update_keeps_inactive_locale_translations(): void
    {
        config(['app.translatable_locales' => ['ko', 'en']]);
        $html = Popup::factory()->html(['ko' => '<p>공지</p>', 'ja' => '<p>お知らせ</p>'])->create();

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$html->id), $this->htmlPopupPayload([
            'title' => '제목만 바꿈',
            'body_html' => ['ko' => '<p>공지</p>', 'ja' => '<p>お知らせ</p>'],
            'revision' => 0,
        ]))->assertOk();
        $this->assertSame('제목만 바꿈', $html->fresh()->title);
        $this->assertSame('<p>お知らせ</p>', $html->fresh()->body_html['ja']);

        // 화면이 그 언어를 빼고 보내도 지우지 않는다 — 언어팩을 다시 켜면 그대로 보인다
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$html->id), $this->htmlPopupPayload([
            'body_html' => ['ko' => '<p>공지 수정</p>'],
            'revision' => 1,
        ]))->assertOk();
        $this->assertSame(['ko' => '<p>공지 수정</p>', 'ja' => '<p>お知らせ</p>'], $html->fresh()->body_html);

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$html->id), $this->htmlPopupPayload([
            'body_html' => ['ko' => '<p>공지 수정</p>', 'xx' => '<p>새 언어</p>'],
            'revision' => 2,
        ]))->assertStatus(422)->assertJsonValidationErrors('body_html');

        $image = Popup::factory()->withImages()->create(['image_alt' => ['ko' => '대체', 'ja' => '代替']]);
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$image->id), $this->popupPayload([
            'image_alt' => ['ko' => '대체 수정', 'ja' => '代替'],
            'revision' => 0,
        ]))->assertOk();
        $this->assertSame(['ko' => '대체 수정', 'ja' => '代替'], $image->fresh()->image_alt);
    }

    /**
     * 화면이 받은 세대 번호가 지금과 다르면 409 이고 아무것도 바뀌지 않는다(화면은 최신 상세를 다시 읽어 비교한다).
     *
     * @scenario surface=update_revision_mismatch_returns_409
     *
     * @return void 반환값 없음
     */
    public function test_update_revision_mismatch_returns_409(): void
    {
        $popup = Popup::factory()->withImages()->create(['title' => '처음 제목']);
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->popupPayload(['title' => '먼저 저장', 'revision' => 0]))->assertOk();

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->popupPayload(['title' => '늦은 저장', 'revision' => 0]))
            ->assertStatus(409);

        $this->assertSame('먼저 저장', $popup->fresh()->title);
        $this->assertSame(1, $this->actingAs($this->admin)->getJson($this->api('/admin/popups/'.$popup->id))->json('data.revision'));
    }

    /**
     * 검증을 통과한 뒤 잠금을 잡기 전에 다른 관리자가 저장해도 잠금 아래에서 세대 번호를 다시 확인해 409 로 막는다.
     *
     * 검증 단계 확인과 잠금 사이의 틈은 `filter_update_data`(트랜잭션 전에 발행) 리스너가 세대 번호를 올려 재현한다.
     *
     * @scenario surface=update_revision_rechecked_under_lock
     *
     * @return void 반환값 없음
     */
    public function test_update_revision_rechecked_under_lock(): void
    {
        $popup = Popup::factory()->withImages()->create(['title' => '처음 제목']);
        HookManager::addFilter('sirsoft-popup.popup.filter_update_data', function (array $data) use ($popup) {
            DB::table('popups')->where('id', $popup->id)->update(['title' => '틈에 저장', 'lock_version' => 1]);

            return $data;
        });

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->popupPayload(['title' => '늦은 저장', 'revision' => 0]))
            ->assertStatus(409)
            ->assertJsonPath('message', __('sirsoft-popup::messages.popup.conflict'));

        $this->assertSame('틈에 저장', $popup->fresh()->title);
        $this->assertSame(1, $popup->fresh()->lock_version);
    }

    /**
     * 수정 화면에서 올린 새 PC 이미지는 저장 때 붙고 옛 파일은 지워진다.
     *
     * @scenario surface=update_replaces_image_and_deletes_old_file
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_update_replaces_image_and_deletes_old_file(): void
    {
        $id = $this->createPopupViaApi($this->admin)->assertCreated()->json('data.id');
        $oldPath = PopupImage::query()->where('popup_id', $id)->value('path');
        $this->imageStorage()->assertExists('sirsoft-popup/images/'.$oldPath);

        $tempKey = (string) Str::uuid();
        $newId = $this->uploadImage($this->admin, 'pc', $tempKey)->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$id), $this->popupPayload(['temp_key' => $tempKey, 'pc_image_id' => $newId]))->assertOk();

        $this->assertSame($newId, PopupImage::query()->where('popup_id', $id)->where('device', 'pc')->value('id'));
        $this->imageStorage()->assertMissing('sirsoft-popup/images/'.$oldPath);
    }

    /**
     * 이미지형을 직접 작성형으로 바꿔 저장하면 이미지 행·파일을 지운다.
     *
     * @scenario surface=update_switch_to_html_removes_images
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_update_switch_to_html_removes_images(): void
    {
        $id = $this->createPopupViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');
        $paths = PopupImage::query()->where('popup_id', $id)->pluck('path')->all();

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$id), $this->htmlPopupPayload())->assertOk();

        $this->assertSame(0, PopupImage::query()->where('popup_id', $id)->count());
        foreach ($paths as $path) {
            $this->imageStorage()->assertMissing('sirsoft-popup/images/'.$path);
        }
        $this->assertNull(Popup::query()->find($id)->image_alt);
    }

    /**
     * 상세는 다국어 원본·이미지 2종·세대 번호·abilities 를 싣는다.
     *
     * @scenario surface=show_returns_raw_translations_images_revision
     *
     * @effects response_shape_with_abilities
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_raw_translations_images_revision(): void
    {
        $id = $this->createPopupViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');

        $data = $this->actingAs($this->admin)->getJson($this->api('/admin/popups/'.$id))->assertOk()->json('data');

        $this->assertSame(['ko' => '가을 감사 이벤트 안내', 'en' => 'Autumn thanks event'], $data['image_alt']);
        $this->assertSame(['ko', 'en'], $data['filled_locales']);
        $this->assertSame('pc', $data['images']['pc']['device']);
        $this->assertSame('mobile', $data['images']['mobile']['device']);
        $this->assertSame(0, $data['revision']);
        $this->assertArrayNotHasKey('created_by', $data);
        $this->assertTrue($data['abilities']['can_delete']);
    }

    /**
     * 영구 삭제는 PC·모바일 파일과 행을 모두 지운다.
     *
     * @scenario surface=destroy_removes_pc_and_mobile_files
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_destroy_removes_pc_and_mobile_files(): void
    {
        $id = $this->createPopupViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');
        $paths = PopupImage::query()->where('popup_id', $id)->pluck('path')->all();
        $this->assertCount(2, $paths);

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popups/'.$id))->assertOk();

        $this->assertNull(Popup::query()->find($id));
        $this->assertSame(0, PopupImage::query()->whereIn('path', $paths)->count());
        foreach ($paths as $path) {
            $this->imageStorage()->assertMissing('sirsoft-popup/images/'.$path);
        }
    }

    /**
     * 모바일 이미지가 없는 팝업·직접 작성형 팝업도 오류 없이 지운다.
     *
     * @scenario surface=destroy_without_mobile_image_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_destroy_without_mobile_image_succeeds(): void
    {
        $id = $this->createPopupViaApi($this->admin)->assertCreated()->json('data.id');
        $html = Popup::factory()->html()->create();

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popups/'.$id))->assertOk();
        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popups/'.$html->id))->assertOk();

        $this->assertSame(0, Popup::query()->whereIn('id', [$id, $html->id])->count());
    }

    /**
     * 일괄 비노출은 고른 팝업마다 바뀌고 세대 번호가 오른다.
     *
     * @scenario surface=bulk_status_updates_each
     *
     * @effects db_state_matches_request
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_updates_each(): void
    {
        [$a, $b] = Popup::factory()->count(2)->create();
        $untouched = Popup::factory()->create();

        $this->actingAs($this->admin)->patchJson($this->api('/admin/popups/bulk-status'), ['ids' => [$a->id, $b->id], 'is_active' => false])
            ->assertOk()->assertJsonPath('data.count', 2);

        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);
        $this->assertSame(1, $a->fresh()->lock_version);
        $this->assertTrue($untouched->fresh()->is_active);
    }

    /**
     * 복사본은 비활성으로 맨 뒤에(원래 순서대로), 나머지 값은 원본 그대로.
     *
     * @scenario surface=bulk_copy_creates_inactive_copies_at_end
     *
     * @effects db_state_matches_request
     *
     * @return void 반환값 없음
     */
    public function test_bulk_copy_creates_inactive_copies_at_end(): void
    {
        $second = Popup::factory()->html(['ko' => '<p>둘째</p>'])->routes(['/shop'])->audience('member')->create(['sort_order' => 1, 'title' => '둘째']);
        $first = Popup::factory()->html(['ko' => '<p>첫째</p>'])->create(['sort_order' => 0, 'title' => '첫째']);

        $copies = $this->actingAs($this->admin)->withHeader('Accept-Language', 'ko')->postJson($this->api('/admin/popups/bulk-copy'), ['ids' => [$second->id, $first->id]])
            ->assertOk()->assertJsonPath('data.count', 2)->json('data.copies');

        $copyOfFirst = Popup::query()->find($copies[$first->id]);
        $copyOfSecond = Popup::query()->find($copies[$second->id]);
        $this->assertFalse($copyOfFirst->is_active);
        $this->assertSame('첫째 (복사)', $copyOfFirst->title);
        $this->assertSame([2, 3], [$copyOfFirst->sort_order, $copyOfSecond->sort_order]);
        $this->assertSame(['/shop'], $copyOfSecond->routes);
        $this->assertSame('member', $copyOfSecond->audience);
        $this->assertSame(['ko' => '<p>둘째</p>'], $copyOfSecond->body_html);
        $this->assertTrue($second->fresh()->is_active);
    }

    /**
     * 이미지형 복사는 파일까지 새 해시로 복제한다 — 원본을 지워도 복사본 파일은 남는다.
     *
     * @scenario surface=bulk_copy_duplicates_image_files
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_bulk_copy_duplicates_image_files(): void
    {
        $sourceId = $this->createPopupViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');
        $sourceHashes = PopupImage::query()->where('popup_id', $sourceId)->pluck('hash')->all();

        $copyId = $this->actingAs($this->admin)->postJson($this->api('/admin/popups/bulk-copy'), ['ids' => [$sourceId]])
            ->assertOk()->json('data.copies.'.$sourceId);
        $copyImages = PopupImage::query()->where('popup_id', $copyId)->get();

        $this->assertCount(2, $copyImages);
        $this->assertEmpty(array_intersect($sourceHashes, $copyImages->pluck('hash')->all()));

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popups/'.$sourceId))->assertOk();
        foreach ($copyImages as $image) {
            $this->imageStorage()->assertExists('sirsoft-popup/images/'.$image->path);
        }
    }

    /**
     * 복사본 제목은 접미까지 100자를 넘지 않게 앞부분을 자른다(접미는 관리자 요청 언어 — 여기서는 한국어).
     *
     * @scenario surface=bulk_copy_title_suffix_truncated_to_limit
     *
     * @return void 반환값 없음
     */
    public function test_bulk_copy_title_suffix_truncated_to_limit(): void
    {
        $source = Popup::factory()->html()->create(['title' => str_repeat('가', 100)]);

        $copyId = $this->actingAs($this->admin)->withHeader('Accept-Language', 'ko')->postJson($this->api('/admin/popups/bulk-copy'), ['ids' => [$source->id]])
            ->assertOk()->json('data.copies.'.$source->id);

        $title = Popup::query()->find($copyId)->title;
        $this->assertSame(100, mb_strlen($title));
        $this->assertStringEndsWith(' (복사)', $title);
    }

    /**
     * 이미지 파일이 없어 복제가 실패한 팝업만 되돌리고 나머지는 복사한다.
     *
     * @scenario surface=bulk_copy_file_failure_rolls_back_that_item
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_bulk_copy_file_failure_rolls_back_that_item(): void
    {
        // 행만 있고 파일은 없는 이미지 — 복제가 실패한다
        $broken = Popup::factory()->withImages()->create(['title' => '깨진 이미지']);
        $fine = Popup::factory()->html()->create(['title' => '정상']);
        $before = Popup::query()->count();

        $response = $this->actingAs($this->admin)->postJson($this->api('/admin/popups/bulk-copy'), ['ids' => [$broken->id, $fine->id]])->assertOk();

        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame([$broken->id], $response->json('data.failed_ids'));
        $this->assertSame($before + 1, Popup::query()->count());
        $this->assertSame(0, Popup::query()->where('title', '깨진 이미지 (복사)')->count());
    }

    /**
     * 복사 파일을 쓴 뒤 이미지 행 저장이 실패하면 그 복사 파일도 지운다 — 행이 없는 파일은 정리 명령이 찾지 못한다.
     *
     * @scenario surface=bulk_copy_db_failure_removes_copied_file
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_bulk_copy_db_failure_removes_copied_file(): void
    {
        $popupId = $this->createPopupViaApi($this->admin)->assertCreated()->json('data.id');
        $files = $this->imageFiles();
        $repository = \Mockery::mock(app(PopupImageRepositoryInterface::class));
        $repository->shouldReceive('create')->andThrow(new \RuntimeException('DB 연결 끊김'));
        $this->app->instance(PopupImageService::class, new PopupImageService($repository, $this->imageStorageDriver()));

        $this->actingAs($this->admin)->postJson($this->api('/admin/popups/bulk-copy'), ['ids' => [$popupId]])
            ->assertOk()
            ->assertJsonPath('data.failed_ids', [$popupId]);
        $this->assertSame($files, $this->imageFiles());
    }

    /**
     * 일괄 삭제는 고른 팝업과 파일을 모두 지운다.
     *
     * @scenario surface=bulk_delete_removes_each_with_files
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_bulk_delete_removes_each_with_files(): void
    {
        $a = $this->createPopupViaApi($this->admin, [], mobile: true)->json('data.id');
        $b = $this->createPopupViaApi($this->admin)->json('data.id');
        $paths = PopupImage::query()->whereIn('popup_id', [$a, $b])->pluck('path')->all();
        $this->assertCount(3, $paths);

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popups/bulk'), ['ids' => [$a, $b]])->assertOk()->assertJsonPath('data.count', 2);

        $this->assertSame(0, Popup::query()->whereIn('id', [$a, $b])->count());
        foreach ($paths as $path) {
            $this->imageStorage()->assertMissing('sirsoft-popup/images/'.$path);
        }
    }

    /**
     * 일괄 요청의 id 목록 검증 — 비었거나·없는 id·중복·100개 초과는 422.
     *
     * @scenario surface=bulk_ids_validation
     *
     * @return void 반환값 없음
     */
    public function test_bulk_ids_validation(): void
    {
        $popup = Popup::factory()->create();

        foreach (['/admin/popups/bulk-copy' => 'postJson', '/admin/popups/bulk' => 'deleteJson'] as $uri => $method) {
            $this->actingAs($this->admin)->{$method}($this->api($uri), ['ids' => []])->assertStatus(422);
            $this->actingAs($this->admin)->{$method}($this->api($uri), ['ids' => [999999]])->assertStatus(422);
            $this->actingAs($this->admin)->{$method}($this->api($uri), ['ids' => [$popup->id, $popup->id]])->assertStatus(422);
            $this->actingAs($this->admin)->{$method}($this->api($uri), ['ids' => range(1, 101)])->assertStatus(422);
        }
        $this->actingAs($this->admin)->patchJson($this->api('/admin/popups/bulk-status'), ['ids' => [$popup->id]])->assertStatus(422);
    }

    /**
     * 순서 변경은 팝업 전체를 보내야 한다(빠짐·없는 id 거부).
     *
     * @scenario surface=reorder_requires_complete_set
     *
     * @return void 반환값 없음
     */
    public function test_reorder_requires_complete_set(): void
    {
        [$a, $b] = Popup::factory()->count(2)->create();

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/order'), ['ids' => [$a->id]])->assertStatus(422);
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/order'), ['ids' => [$a->id, $b->id, 999999]])->assertStatus(422);
    }

    /**
     * 순서 변경은 보낸 순서대로 0 부터 다시 매기고 before/after 훅을 발행한다.
     *
     * @scenario surface=reorder_assigns_sequential_order
     *
     * @effects hooks_dispatched
     *
     * @return void 반환값 없음
     */
    public function test_reorder_assigns_sequential_order(): void
    {
        $a = Popup::factory()->create(['sort_order' => 0]);
        $b = Popup::factory()->create(['sort_order' => 1]);
        $c = Popup::factory()->create(['sort_order' => 2]);
        $fired = [];
        HookManager::addAction('sirsoft-popup.popup.after_reorder', function (array $ids) use (&$fired) {
            $fired = $ids;
        });

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/order'), ['ids' => [$c->id, $a->id, $b->id]])->assertOk();

        $this->assertSame([0, 1, 2], [$c->fresh()->sort_order, $a->fresh()->sort_order, $b->fresh()->sort_order]);
        $this->assertSame([$c->id, $a->id, $b->id], $fired);
    }

    /**
     * 표시 방식 조회는 설정·업로드 한도 3값·설정 화면 이동 가능 여부를 싣는다.
     *
     * @scenario surface=display_settings_returns_settings_and_upload_limits
     *
     * @return void 반환값 없음
     */
    public function test_display_settings_returns_settings_and_upload_limits(): void
    {
        $this->setPluginSettings(['pc_width' => 600, 'mobile_style' => 'center', 'autoplay_interval' => 99, 'max_upload_mb' => 3]);

        $data = $this->actingAs($this->admin)->getJson($this->api('/admin/display-settings'))->assertOk()->json('data');

        $this->assertSame(600, $data['settings']['pc_width']);
        $this->assertSame('center', $data['settings']['mobile_style']);
        // 범위 밖 값은 기본값으로 읽는다
        $this->assertSame(6, $data['settings']['autoplay_interval']);
        $this->assertSame(3, $data['upload']['max_mb']);
        $this->assertArrayHasKey('server_limit_mb', $data['upload']);
        $this->assertArrayHasKey('effective_mb', $data['upload']);
        $this->assertFalse($data['can_update_settings']);
    }

    /**
     * 등록은 before → filter → after 훅 순서로 발행하고, filter 가 바꾼 값이 저장된다.
     *
     * @scenario surface=hooks_fire_before_and_after
     *
     * @effects hooks_dispatched
     *
     * @return void 반환값 없음
     */
    public function test_hooks_fire_before_and_after(): void
    {
        $fired = [];
        HookManager::addAction('sirsoft-popup.popup.before_create', function () use (&$fired) {
            $fired[] = 'before';
        });
        HookManager::addFilter('sirsoft-popup.popup.filter_create_data', function (array $data) use (&$fired) {
            $fired[] = 'filter';
            $data['title'] = $data['title'].' (필터)';

            return $data;
        });
        HookManager::addAction('sirsoft-popup.popup.after_create', function () use (&$fired) {
            $fired[] = 'after';
        });

        $id = $this->createPopupViaApi($this->admin)->assertCreated()->json('data.id');

        $this->assertSame(['before', 'filter', 'after'], $fired);
        $this->assertSame('가을 감사 이벤트 (필터)', Popup::query()->find($id)->title);
    }

    /**
     * 화면상 빈 본문을 등록·수정에서 대칭으로 막습니다.
     *
     * @scenario surface=body_visually_empty
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_visually_empty_bodies_are_rejected_on_store_and_update(): void
    {
        $popup = Popup::factory()->html()->create();
        foreach (['', '   ', '<p><br></p>', '<p>&nbsp;</p>', '<p> </p><p><br></p>', '<p>&#160; &#x200B;</p>', '<script>alert(1)</script>', '<iframe src="/blank"></iframe>'] as $html) {
            $payload = $this->htmlPopupPayload(['body_html' => ['ko' => $html]]);
            $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $payload)
                ->assertStatus(422)->assertJsonValidationErrors('body_html');
            $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $payload + ['revision' => 0])
                ->assertStatus(422)->assertJsonValidationErrors('body_html');
        }
        $this->assertSame(['ko' => '<p>공지</p>'], $popup->fresh()->body_html);
    }

    /**
     * 텍스트·이미지와 비워 둔 선택 언어는 허용합니다.
     *
     * @scenario surface=body_visible_with_optional_locale
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_visible_text_and_images_with_empty_optional_locale_are_accepted(): void
    {
        foreach (['<p>공지</p>', '<p><img src="/notice.png" alt="안내"></p>'] as $html) {
            $payload = $this->htmlPopupPayload(['body_html' => ['ko' => $html, 'en' => '']]);
            $id = $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $payload)->assertCreated()->json('data.id');
            $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$id), $payload + ['revision' => 0])->assertOk();
        }
        $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $this->htmlPopupPayload([
            'body_html' => ['ko' => '<p>기본</p>', 'en' => '<p>&nbsp;</p>'],
        ]))->assertStatus(422)->assertJsonValidationErrors('body_html');
    }
}
