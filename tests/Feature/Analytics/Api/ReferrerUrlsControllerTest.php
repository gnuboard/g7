<?php

namespace Tests\Feature\Analytics\Api;

/**
 * 유입처 전체 주소 API — 도메인 아래에 실제 주소를 펼친다.
 *
 * 집계표(`analytics_dimension_stats`)는 유입처를 호스트까지만 쌓는다. 그래서 유입 사이트
 * 목록은 「google.com 에서 340」까지만 말하고, 어느 검색 결과·어느 글에서 왔는지는 답하지
 * 못한다. 전체 주소는 원장의 `referrer_url` 에만 있으므로 이 API 만 원장을 직접 읽는다.
 * (#682 라운드 4, PO 지적 ⑥ — 「도메인만 나오면 어디서 왔는지 모르지 않겠어?」)
 *
 * @scenario endpoint=referrer_urls, role=read, period=valid
 */
class ReferrerUrlsControllerTest extends AnalyticsApiTestCase
{
    /**
     * 한 도메인의 주소들이 방문 수 순으로 묶여 나오는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=referrer_urls, role=read, period=valid
     *
     * @effects referrer_urls_group_by_full_url_within_host
     */
    public function test_urls_of_one_host_are_grouped_and_ranked(): void
    {
        // 같은 도메인 안에서 주소가 둘로 갈린다 — 집계표라면 한 줄(google.com)로만 보인다.
        $this->visit([
            'referrer_name' => 'google.com',
            'referrer_url' => 'https://google.com/search?q=gnuboard',
            'pageviews_count' => 3,
        ]);
        $this->visit([
            'referrer_name' => 'google.com',
            'referrer_url' => 'https://google.com/search?q=gnuboard',
            'pageviews_count' => 2,
        ]);
        $this->visit([
            'referrer_name' => 'google.com',
            'referrer_url' => 'https://google.com/search?q=laravel',
            'pageviews_count' => 1,
        ]);
        // 다른 도메인은 섞이지 않는다.
        $this->visit([
            'referrer_name' => 'example.org',
            'referrer_url' => 'https://example.org/post/1',
            'pageviews_count' => 9,
        ]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/referrer-urls?preset=today&host=google.com');

        $response->assertOk();
        $rows = $response->json('data.data');

        $this->assertCount(2, $rows, '한 도메인 안의 서로 다른 주소만 나온다');
        $this->assertSame('https://google.com/search?q=gnuboard', $rows[0]['url']);
        $this->assertSame(2, $rows[0]['visits']);
        $this->assertSame(5, $rows[0]['hits']);
        $this->assertSame('https://google.com/search?q=laravel', $rows[1]['url']);
        $this->assertSame(1, $rows[1]['visits']);
        $this->assertSame('google.com', $response->json('data.host'));
    }

    /**
     * 주소가 비어 있는 방문은 목록에서 빠지는지 확인합니다.
     *
     * 유입 URL 익명화를 켠 뒤에 들어온 방문은 저장 시점에 도메인까지만 남고, 직접 유입은
     * 주소 자체가 없다. 그 행이 빈 줄로 목록에 서면 「주소를 모른다」가 「주소가 빈 문자열」로
     * 보인다.
     *
     * @return void 없음
     *
     * @scenario endpoint=referrer_urls, role=read, period=valid
     *
     * @effects referrer_urls_skip_visits_without_a_stored_url
     */
    public function test_visits_without_a_stored_url_are_skipped(): void
    {
        $this->visit([
            'referrer_name' => 'naver.com',
            'referrer_url' => 'https://search.naver.com/search.naver?query=g7',
        ]);
        $this->visit(['referrer_name' => 'naver.com', 'referrer_url' => null]);
        $this->visit(['referrer_name' => 'naver.com', 'referrer_url' => '']);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/referrer-urls?preset=today&host=naver.com');

        $response->assertOk();
        $rows = $response->json('data.data');

        $this->assertCount(1, $rows, '주소가 없는 방문은 목록에 서지 않는다');
        $this->assertSame('https://search.naver.com/search.naver?query=g7', $rows[0]['url']);
    }

    /**
     * 쪽 나눔이 다음 쪽 유무까지 알려 주는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=referrer_urls, role=read, period=valid
     *
     * @effects referrer_urls_paginate_with_has_more
     */
    public function test_the_list_paginates(): void
    {
        foreach (range(1, 3) as $i) {
            $this->visit([
                'referrer_name' => 'example.org',
                'referrer_url' => 'https://example.org/post/'.$i,
            ]);
        }

        $admin = $this->readAdmin();

        $first = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/referrer-urls?preset=today&host=example.org&per_page=2');
        $first->assertOk();
        $this->assertCount(2, $first->json('data.data'));
        $this->assertTrue($first->json('data.pagination.has_more'), '남은 주소가 있으면 알린다');

        $second = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/referrer-urls?preset=today&host=example.org&per_page=2&page=2');
        $second->assertOk();
        $this->assertCount(1, $second->json('data.data'));
        $this->assertFalse($second->json('data.pagination.has_more'));
    }

    /**
     * 호스트를 주지 않으면 거절하는지 확인합니다.
     *
     * 호스트가 없으면 원장 전체를 주소별로 묶는 질의가 되어 버린다 — 목록에서 고른 한
     * 도메인 아래를 펼치는 것이 이 API 의 전부다.
     *
     * @return void 없음
     *
     * @scenario endpoint=referrer_urls, role=read
     *
     * @effects referrer_urls_require_a_host
     */
    public function test_a_host_is_required(): void
    {
        $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/referrer-urls?preset=today')
            ->assertStatus(422);
    }

    /**
     * 조회 권한이 없으면 막히는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=referrer_urls, role=update, period=valid
     *
     * @effects referrer_urls_require_analytics_read
     */
    public function test_reading_requires_the_analytics_read_permission(): void
    {
        $this->actingAs($this->updateAdmin())
            ->getJson('/api/admin/analytics/referrer-urls?preset=today&host=google.com')
            ->assertStatus(403);
    }
}
