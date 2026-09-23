<?php

namespace Tests\Feature\Analytics\Api;

use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\VisitorType;
use App\Models\Analytics\AnalyticsAction;
use App\Models\User;
use App\Services\Analytics\CsvExporter;
use Illuminate\Support\Carbon;

/**
 * 방문자 API — 목록에서 사람을 고르고, 그 사람의 활동을 펼친다.
 *
 * 방문자 화면은 두 걸음이다. 목록이 없으면 운영자는 방문자 키를 어디서도 얻을 수 없어
 * 단건 화면에 닿지 못한다 — 두 걸음을 각각 잠근다.
 *
 * @scenario endpoint=visitors, role=privacy, period=valid
 */
class VisitorControllerTest extends AnalyticsApiTestCase
{
    /**
     * 목록이 사람 단위로 묶여 나오는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_list_groups_visits_by_person
     */
    public function test_the_list_groups_visits_by_visitor(): void
    {
        $key = str_repeat('a', 32);

        // 같은 사람이 두 번 왔다 — 목록에는 한 줄로, 방문 2로 보여야 한다.
        $this->visit(['visitor_key' => $key, 'pageviews_count' => 1]);
        $this->visit(['visitor_key' => $key, 'pageviews_count' => 1]);
        $this->visit(['visitor_key' => str_repeat('b', 32), 'pageviews_count' => 1]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors?preset=today');

        $response->assertOk();
        $rows = $response->json('data.data');

        $this->assertCount(2, $rows, '방문 3건이 사람 2명으로 묶인다');

        $first = collect($rows)->firstWhere('visitor_key', $key);
        $this->assertSame(2, $first['visits']);
        $this->assertSame(2, $first['pageviews']);
    }

    /**
     * 검색이 방문자 키·주소·회원 번호 셋 모두에 걸리는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_list_search_matches_key_address_or_member
     */
    public function test_search_matches_key_address_or_member(): void
    {
        $key = str_repeat('c', 32);
        $this->visit(['visitor_key' => $key, 'ip' => '203.0.113.77', 'user_id' => 4242]);
        $this->visit(['visitor_key' => str_repeat('d', 32), 'ip' => '198.51.100.1', 'user_id' => null]);

        $admin = $this->privacyAdmin();

        foreach ([substr($key, 0, 8), '203.0.113.77', '4242'] as $term) {
            $response = $this->actingAs($admin)
                ->getJson('/api/admin/analytics/visitors?preset=today&q='.$term);

            $response->assertOk();
            $this->assertCount(1, $response->json('data.data'), "검색어 {$term} 이 한 사람을 찾아야 한다");
            $this->assertSame($key, $response->json('data.data.0.visitor_key'));
        }
    }

    /**
     * 방문자 유형 필터가 그 유형만 남기는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_list_filters_by_visitor_type
     */
    public function test_the_type_filter_narrows_the_list(): void
    {
        $this->visit(['visitor_key' => str_repeat('e', 32), 'visitor_type' => VisitorType::Guest]);
        $this->visit(['visitor_key' => str_repeat('f', 32), 'visitor_type' => VisitorType::Member]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors?preset=today&type=member');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('member', $response->json('data.data.0.visitor_type'));

        $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors?preset=today&type=nobody')
            ->assertStatus(422);
    }

    /**
     * 목록이 쪽으로 나뉘는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_list_pages
     */
    public function test_the_list_pages(): void
    {
        foreach (range(1, 3) as $n) {
            $this->visit(['visitor_key' => str_repeat((string) $n, 32)]);
        }

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors?preset=today&per_page=2');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
        $this->assertTrue($response->json('data.pagination.has_more'));
    }

    /**
     * 방문 타임라인이 전체 유입 주소를 싣는지 확인합니다.
     *
     * 집계표는 유입처를 호스트까지만 쌓아 「naver.com 에서 왔다」까지만 말한다. 어느 검색
     * 결과에서 왔는지는 원장의 `referrer_url` 에만 있는데, 이 값은 모으기만 하고 어느 화면·
     * 응답에도 실리지 않아 운영자가 볼 길이 없었다(#682 라운드 4, 검수 지적 ⑥).
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_detail_carries_the_full_referrer_url
     */
    public function test_the_detail_carries_the_full_referrer_url(): void
    {
        $key = str_repeat('e', 32);
        $this->visit([
            'visitor_key' => $key,
            'referrer_name' => 'google.com',
            'referrer_url' => 'https://google.com/search?q=gnuboard7',
        ]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors/'.$key);

        $response->assertOk();
        $visit = $response->json('data.visits.0');

        $this->assertArrayHasKey('referrer_url', $visit, '전체 주소가 응답에 없으면 화면이 그릴 수 없다');
        $this->assertSame('https://google.com/search?q=gnuboard7', $visit['referrer_url']);
        $this->assertSame('google.com', $visit['referrer_name'], '호스트 요약도 함께 남는다');
    }

    /**
     * 회원 방문자에 회원 정보가 붙는지 확인합니다.
     *
     * 목록은 방문자 키와 회원 번호만 실어 「누가 왔는지」를 답하지 못했다. 화면이 이름을
     * 쓰고 「회원 정보 보기」로 건너뛰려면 이름과 uuid 가 응답에 있어야 한다. 비회원은
     * 붙일 것이 없으므로 null 이다(#682 라운드 4, 검수 지적 ⑦).
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_list_carries_member_identity_for_logged_in_visitors
     */
    public function test_the_list_carries_member_identity(): void
    {
        $member = User::factory()->create(['name' => '방문한 회원']);

        $memberKey = str_repeat('f', 32);
        $guestKey = str_repeat('0', 32);
        $this->visit(['visitor_key' => $memberKey, 'user_id' => $member->id]);
        $this->visit(['visitor_key' => $guestKey, 'user_id' => null]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors?preset=today');

        $response->assertOk();
        $rows = collect($response->json('data.data'));

        $memberRow = $rows->firstWhere('visitor_key', $memberKey);
        $this->assertNotNull($memberRow['user'], '회원 방문에는 회원이 붙는다');
        $this->assertSame('방문한 회원', $memberRow['user']['name']);
        $this->assertSame((string) $member->uuid, $memberRow['user']['uuid'], 'uuid 가 없으면 회원 화면으로 갈 수 없다');
        $this->assertSame($member->email, $memberRow['user']['email']);

        $guestRow = $rows->firstWhere('visitor_key', $guestKey);
        $this->assertNull($guestRow['user'], '비회원 방문에 붙일 회원은 없다');
    }

    /**
     * 지워진 회원의 기록에는 회원이 붙지 않는지 확인합니다.
     *
     * 번호는 원장에 남지만 회원 행은 사라진다 — 그때 이름을 지어내면 안 되고, 목록이
     * 터져서도 안 된다. 화면은 이 경우 번호만 보인다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_list_leaves_member_null_when_the_account_is_gone
     */
    public function test_a_removed_member_leaves_only_the_number(): void
    {
        $key = str_repeat('9', 32);
        $this->visit(['visitor_key' => $key, 'user_id' => 987654]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors?preset=today');

        $response->assertOk();
        $row = collect($response->json('data.data'))->firstWhere('visitor_key', $key);

        $this->assertSame(987654, $row['user_id']);
        $this->assertNull($row['user']);
    }

    /**
     * 단건 조회가 경로의 방문자 키로 그 사람의 활동을 펼치는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_detail_expands_one_persons_timeline
     */
    public function test_the_detail_expands_one_persons_timeline(): void
    {
        $key = str_repeat('9', 32);
        $this->visit(['visitor_key' => $key]);
        $this->visit(['visitor_key' => str_repeat('8', 32)]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors/'.$key);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.visits'), '고른 사람의 방문만 나온다');
        $this->assertSame($key, $response->json('data.visits.0.visitor_key'));
        $this->assertCount(1, $response->json('data.visits.0.actions'));
    }

    /**
     * 타임라인이 화면에서 읽히는 이름을 함께 싣는지 확인합니다.
     *
     * 저장 이름은 정보주체 내보내기 파일이 그대로 싣는 값이라 손대지 않고, 화면이 쓸 이름만 덧붙입니다 —
     * 한국어 주소가 「%EB%B0%A9…」로 보이면 운영자가 읽을 수 없습니다(브라우저 실측 §8 M5-T3).
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects page_labels_are_readable_for_display
     */
    public function test_the_timeline_carries_a_readable_name_for_the_screen(): void
    {
        config(['g7_settings.core.general.site_url' => 'https://example.test']);

        $key = str_repeat('6', 32);
        $visit = $this->visit(['visitor_key' => $key]);
        $raw = 'example.test/search?q=%EB%B0%A9%EB%AC%B8%EC%9E%90';

        AnalyticsAction::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $key,
            'action_type' => ActionType::Pageview,
            'action_name_id' => $this->actionName('url', $raw)->id,
            'occurred_at' => Carbon::now('UTC'),
        ]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors/'.$key);

        $response->assertOk();
        $actions = $response->json('data.visits.0.actions');

        $this->assertContains($raw, array_column($actions, 'name'), '저장 이름은 그대로 — 내보내기 파일이 읽는 값이다');
        $this->assertContains('/search?q=방문자', array_column($actions, 'name_display'));
    }

    /**
     * 방문자 활동 내역을 CSV 로 내려받을 수 있는지 확인합니다.
     *
     * 정보주체가 열람을 요구하면 운영자는 그 사람의 기록을 건네줘야 합니다. 화면으로만
     * 볼 수 있으면 건넬 수단이 없습니다. 액션 하나가 한 줄이고 방문 정보는 줄마다
     * 되풀이됩니다(부록 D).
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid, format=csv
     *
     * @effects csv_export_starts_with_a_byte_order_mark, visitor_detail_can_be_downloaded_as_csv
     */
    public function test_the_detail_can_be_downloaded_as_csv(): void
    {
        $key = str_repeat('7', 32);
        $this->visit([
            'visitor_key' => $key,
            'referrer_name' => 'naver.com',
            'referrer_url' => 'https://search.naver.com/search.naver?query=g7',
        ]);

        $response = $this->actingAs($this->privacyAdmin())
            ->get('/api/admin/analytics/visitors/'.$key.'?format=csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'analytics-visitor-'.$key,
            (string) $response->headers->get('content-disposition'),
        );

        $body = $response->streamedContent();

        $this->assertStringStartsWith(CsvExporter::BOM, $body, 'BOM 이 없으면 엑셀에서 한글이 전부 깨진다');
        // 전체 유입 주소는 집계표에 없는 값이라 CSV 에도 실어야 한다 — 호스트만으로는
        // 어느 글에서 왔는지 알 수 없다(#682 라운드 4, 검수 지적 ⑥).
        $this->assertStringContainsString(
            "referrer_type,referrer_name,referrer_url,pageviews,total_time_seconds,action_occurred_at,action_type,action_name,time_spent_seconds\r\n",
            $body,
        );
        // 액션 줄에 방문 정보가 함께 실려야 엑셀에서 한 줄만 보고도 맥락을 안다.
        $this->assertStringContainsString('naver.com', $body);
        $this->assertStringContainsString('https://search.naver.com/search.naver?query=g7', $body);
        $this->assertStringContainsString('pageview', $body);
    }

    /**
     * 읽기 권한만으로는 방문자 CSV 도 받을 수 없는지 확인합니다.
     *
     * 내려받기는 화면보다 더 샌다 — 파일은 그대로 남아 옮겨 다닌다. 권한 판정이
     * `format` 뒤에 있으면 목록은 막히는데 파일은 빠져나가는 구멍이 생긴다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=read, format=csv
     *
     * @effects visitor_views_require_the_privacy_permission
     */
    public function test_read_permission_cannot_download_the_detail_csv(): void
    {
        $key = str_repeat('6', 32);
        $this->visit(['visitor_key' => $key]);

        $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/visitors/'.$key.'?format=csv')
            ->assertForbidden();
    }

    /**
     * 익명 모드에서 단건·목록이 모두 한계를 안내하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_views_announce_the_anonymous_mode_limit
     */
    public function test_anonymous_mode_notice_is_carried(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'anonymous']);

        $key = str_repeat('7', 32);
        $this->visit(['visitor_key' => $key]);

        $admin = $this->privacyAdmin();

        $list = $this->actingAs($admin)->getJson('/api/admin/analytics/visitors?preset=today');
        $list->assertOk();
        $this->assertSame('anonymous', $list->json('data.mode'));
        $this->assertNotNull(
            $list->json('data.mode_notice'),
            '익명 모드에서는 같은 사람의 방문이 하루 안에서만 묶인다는 사실을 화면이 알려야 한다',
        );

        $detail = $this->actingAs($admin)->getJson('/api/admin/analytics/visitors/'.$key);
        $detail->assertOk();
        $this->assertNotNull($detail->json('data.mode_notice'));
    }

    /**
     * 조회 권한만으로는 방문자 목록에 닿지 못하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=read
     *
     * @effects read_permission_cannot_reach_privacy_endpoints
     */
    public function test_read_permission_cannot_reach_the_list(): void
    {
        $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/visitors?preset=today')
            ->assertStatus(403);
    }

    /**
     * 상세가 방문자 카드를 한 덩어리로 내는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_detail_carries_a_single_profile_card
     */
    public function test_the_detail_carries_a_visitor_card(): void
    {
        $key = str_repeat('d', 32);
        $this->visit([
            'visitor_key' => $key,
            'visitor_type' => VisitorType::Member,
            'country_code' => 'KR',
            'browser_name' => 'Chrome',
        ]);

        $card = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors/'.$key)
            ->assertOk()
            ->json('data.visitor');

        // 화면의 방문자 카드는 이 한 덩어리를 읽는다 — 방문 목록을 뒤져 첫 행을
        // 골라내야 한다면 기간 안에 방문이 없을 때 카드가 통째로 빈다.
        $this->assertIsArray($card, '상세 응답에 방문자 카드가 있어야 한다');
        $this->assertSame($key, $card['visitor_key']);
        $this->assertSame('member', $card['visitor_type']);
        $this->assertSame('KR', $card['country_code']);
        $this->assertSame('Chrome', $card['browser_name']);
        $this->assertSame(1, $card['visits']);
    }

    /**
     * 카드가 회원과 재방문 여부를 함께 내는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_card_carries_member_and_returning
     */
    public function test_the_card_carries_member_and_returning(): void
    {
        // 목록에는 회원 이름이 있는데 상세 카드에는 없어서, 이름을 보고 들어온 운영자가 그 사람을
        // 카드에서 다시 잃었다. 재방문 여부도 와이어프레임에 있는데 어느 응답에도 없었다
        // (#682 S3 라운드 5, 계획서 §4.11 방문자 상세).
        $member = User::factory()->create(['name' => '두 번 온 회원']);
        $key = str_repeat('e', 32);

        // 같은 사람의 방문 두 건 — 재방문이다.
        $this->visit(['visitor_key' => $key, 'user_id' => $member->id]);
        $this->visit(['visitor_key' => $key, 'user_id' => $member->id]);

        $card = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors/'.$key)
            ->assertOk()
            ->json('data.visitor');

        $this->assertSame($member->id, $card['user_id']);
        $this->assertNotNull($card['user'], '카드에 회원이 붙어야 한다');
        $this->assertSame('두 번 온 회원', $card['user']['name']);
        $this->assertSame((string) $member->uuid, $card['user']['uuid'], 'uuid 가 없으면 회원 화면으로 갈 수 없다');
        $this->assertTrue($card['is_returning'], '방문이 두 건이면 다시 온 사람이다');
    }

    /**
     * 비회원 한 번 방문은 회원이 비고 재방문이 아닌지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_card_carries_member_and_returning
     */
    public function test_a_guest_single_visit_card_has_no_member_and_is_not_returning(): void
    {
        $key = str_repeat('9', 32);
        $this->visit(['visitor_key' => $key, 'user_id' => null]);

        $card = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors/'.$key)
            ->assertOk()
            ->json('data.visitor');

        $this->assertNull($card['user_id']);
        $this->assertNull($card['user'], '비회원 방문에 붙일 회원은 없다');
        $this->assertFalse($card['is_returning'], '한 번만 온 사람은 재방문이 아니다');
    }

    /**
     * 기간 안에 방문이 없어도 카드가 비지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_card_survives_an_empty_period
     */
    public function test_the_card_survives_a_period_with_no_visits(): void
    {
        $key = str_repeat('e', 32);

        $card = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors/'.$key)
            ->assertOk()
            ->json('data.visitor');

        // 목록에서 고른 사람인데 기간을 좁히면 방문이 0건이 될 수 있다. 그때도
        // 「누구를 보고 있는지」는 화면에 남아야 한다.
        $this->assertSame($key, $card['visitor_key']);
        $this->assertSame(0, $card['visits']);
    }

    /**
     * 익명 모드에서 프로필 조회 창이 당일로 좁혀지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects anonymous_profile_lookup_is_clamped_to_today
     */
    public function test_anonymous_profiles_are_limited_to_today(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'anonymous']);

        $key = str_repeat('f', 32);
        $today = Carbon::now('UTC');
        $earlier = $today->copy()->subDays(3);

        $this->visit(['visitor_key' => $key]);

        // 익명 키는 날마다 새로 만들어진다 — 사흘 전의 같은 문자열은 다른 사람이다.
        $this->visit([
            'visitor_key' => $key,
            'visit_date' => $earlier->toDateString(),
            'first_action_at' => $earlier,
            'last_action_at' => $earlier,
        ]);

        $visits = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors/'.$key.'?from='.$earlier->toDateString().'&to='.$today->toDateString())
            ->assertOk()
            ->json('data.visits');

        // 과거 구간을 달라고 해도 오늘 것만 나와야 한다. 섞이면 남의 기록이
        // 한 사람의 프로필로 보인다.
        $this->assertCount(1, $visits, '익명 모드는 하루를 넘겨 사람을 잇지 않는다');
        $this->assertSame(
            $today->toDateString(),
            Carbon::parse($visits[0]['first_action_at'])->toDateString(),
        );
    }

    /**
     * 상세 응답이 마스킹 사실을 함께 싣는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects masked_ip_is_labelled_as_masked
     */
    public function test_the_detail_tells_the_screen_the_address_is_masked(): void
    {
        // 화면이 찍는 주소는 설정대로 뒤 옥텟을 지운 값이다. 그 사실을 함께 내려보내지 않으면
        // 운영자가 마스킹된 값을 원래 주소로 읽고, 정보주체가 「내 접속 기록이 맞느냐」 물었을 때
        // 그 주소로 답한다(#682 S3 라운드 6, 계획서 §4.10 「IP(마스킹 표시)」).
        $key = str_repeat('f', 32);
        $this->visit(['visitor_key' => $key]);

        config(['g7_settings.core.analytics.ip_mask_octets' => 2]);

        $this->assertSame(
            2,
            $this->actingAs($this->privacyAdmin())
                ->getJson('/api/admin/analytics/visitors/'.$key)
                ->assertOk()
                ->json('data.ip_mask_octets'),
            '마스킹 옥텟 수를 싣지 않으면 화면이 마스킹 사실을 알 길이 없다',
        );
    }

    /**
     * 단건 CSV 파일명에 기간이 들어가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy, period=valid
     *
     * @effects visitor_csv_filename_carries_the_period
     */
    public function test_the_single_visitor_csv_filename_carries_the_period(): void
    {
        // 부록 D 의 파일명 규약은 `analytics-{report}-{from}_{to}.csv` 다. 기간이 없으면 같은
        // 사람을 두 기간으로 내려받았을 때 앞 파일을 덮어, 정보주체에게 건넬 때 어느 구간인지
        // 알 수 없다(#682 S3 라운드 6).
        $key = str_repeat('9', 32);
        $this->visit(['visitor_key' => $key]);

        $disposition = $this->actingAs($this->privacyAdmin())
            ->get('/api/admin/analytics/visitors/'.$key.'?format=csv&from=2026-09-01&to=2026-09-10')
            ->assertOk()
            ->headers->get('content-disposition');

        $this->assertStringContainsString('2026-09-01_2026-09-10', (string) $disposition);
    }
}
