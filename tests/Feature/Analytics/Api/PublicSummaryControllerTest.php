<?php

namespace Tests\Feature\Analytics\Api;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\VisitorType;
use App\Models\Analytics\AnalyticsStatsDaily;
use Illuminate\Support\Carbon;

/**
 * 공개 요약 API — 사용자 화면 카운터가 읽는 네 숫자.
 *
 * 로그인 없이 누구나 부른다. 그래서 무엇이 나가고 무엇이 나가지 않는지, 그리고 꺼졌을
 * 때 어떻게 답하는지가 계약이다.
 */
class PublicSummaryControllerTest extends AnalyticsApiTestCase
{
    /**
     * 네 숫자만 나가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=summary, role=none
     *
     * @effects public_summary_returns_four_numbers
     */
    public function test_only_four_numbers_are_exposed(): void
    {
        $yesterday = Carbon::parse(AnalyticsClock::today())->subDay()->toDateString();
        $this->daily($yesterday, ['visitors' => 12]);
        $this->visit();

        $response = $this->getJson('/api/analytics/summary');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame(['today', 'yesterday', 'max', 'total'], array_keys($data));
        $this->assertSame(12, $data['yesterday']);
        $this->assertSame(1, $data['today']);
    }

    /**
     * 로그인 없이 열리는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=summary, role=none
     *
     * @effects public_summary_returns_four_numbers
     */
    public function test_it_is_reachable_without_logging_in(): void
    {
        $this->getJson('/api/analytics/summary')->assertOk();
    }

    /**
     * 꺼져 있으면 0 이 아니라 404 인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=summary, role=none
     *
     * @effects public_summary_is_not_found_when_disabled
     */
    public function test_a_disabled_summary_is_not_found(): void
    {
        config(['g7_settings.core.analytics.public_summary_enabled' => false]);

        // 끈 기능을 0 으로 답하면 위젯이 아무도 오지 않았다고 그린다 — 404 여야 한다.
        $this->getJson('/api/analytics/summary')->assertNotFound();
    }

    /**
     * 봇이 사람 숫자에 섞이지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=summary, role=none
     *
     * @effects public_summary_returns_four_numbers
     */
    public function test_bots_are_not_counted_as_people(): void
    {
        $yesterday = Carbon::parse(AnalyticsClock::today())->subDay()->toDateString();

        $this->daily($yesterday, ['visitor_type' => VisitorType::Guest->value, 'visitors' => 5]);
        $this->daily($yesterday, ['visitor_type' => VisitorType::Bot->value, 'visitors' => 500]);

        $response = $this->getJson('/api/analytics/summary');

        $response->assertOk();
        $this->assertSame(5, $response->json('data.yesterday'), '사이드바 카운터에 봇이 섞이면 숫자가 무의미해진다');
    }

    /**
     * 두 번째 호출이 집계를 다시 세지 않고 캐시를 읽는지 확인합니다.
     *
     * 이 API 는 로그인 없이 누구나 부르므로 매 호출이 원장을 훑으면 사람이 몰릴 때
     * 그 집계가 사이트를 함께 느리게 만든다.
     *
     * @return void 없음
     *
     * @scenario endpoint=summary, role=none
     *
     * @effects public_summary_is_cached_between_requests
     */
    public function test_the_second_call_is_served_from_cache(): void
    {
        $yesterday = Carbon::parse(AnalyticsClock::today())->subDay()->toDateString();
        $this->daily($yesterday, ['visitors' => 7]);

        $this->getJson('/api/analytics/summary')->assertOk();

        // 캐시가 없다면 이 변경이 곧바로 보인다. 캐시가 살아 있으면 옛 숫자가 나온다.
        AnalyticsStatsDaily::query()->where('date', $yesterday)->update(['visitors' => 999]);

        $second = $this->getJson('/api/analytics/summary');

        $second->assertOk();
        $this->assertSame(7, $second->json('data.yesterday'), '두 번째 호출이 원장을 다시 세면 캐시가 동작하지 않는 것이다');
    }

    /**
     * 분당 한도를 넘기면 429 로 막히는지 확인합니다.
     *
     * 공개 경로라 한도가 없으면 한 대의 클라이언트가 집계 캐시 갱신을 계속 유발할 수 있다.
     *
     * @return void 없음
     *
     * @scenario endpoint=summary, role=none
     *
     * @effects public_summary_is_rate_limited
     */
    public function test_it_throttles_after_sixty_requests_per_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/analytics/summary')->assertOk();
        }

        $this->getJson('/api/analytics/summary')->assertStatus(429);
    }
}
