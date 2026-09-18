<?php

namespace Tests\Feature\Analytics\Api;

use App\Enums\Analytics\VisitorType;
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
        $this->visit(['visitor_key' => $key, 'referrer_name' => 'naver.com']);

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
        $this->assertStringContainsString(
            "referrer_type,referrer_name,pageviews,total_time_seconds,action_occurred_at,action_type,action_name,time_spent_seconds\r\n",
            $body,
        );
        // 액션 줄에 방문 정보가 함께 실려야 엑셀에서 한 줄만 보고도 맥락을 안다.
        $this->assertStringContainsString('naver.com', $body);
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
}
