<?php

namespace Tests\Feature\Analytics;

use App\Enums\Analytics\ActionType;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 사이트 내부 검색 훅 — 검색 API 가 검색어·탭·결과 건수를 방문에 붙인다.
 *
 * 결과 건수는 서버만 아는 값이라 브라우저 비콘으로는 남길 수 없다. 검색 API 의 훅
 * 발화부터 리스너의 적재까지가 한 줄로 이어져야 인기 검색어 화면이 채워진다.
 */
class SiteSearchTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비 — 즉시 적재.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

    }

    /**
     * 검색 API 호출이 site_search 액션과 결과 건수·탭을 남긴다.
     *
     * @scenario source=search, visitor=guest, visit_state=new
     *
     * @effects site_search_action_stores_result_count_and_tab, site_search_hook_records_query_and_result_count
     *
     * @return void 없음
     */
    public function test_search_api_records_site_search_action(): void
    {
        $this->getJson('/api/search?q=그누보드&type=board')->assertOk();

        $action = AnalyticsAction::where('action_type', ActionType::SiteSearch->value)->first();

        $this->assertNotNull($action, '검색 훅이 방문에 액션을 붙여야 한다');
        $this->assertSame(0, $action->search_result_count, '결과 건수는 서버 값 그대로(모듈 없는 환경은 0)');
        $this->assertSame('board', $action->search_tab);

        $name = AnalyticsActionName::find($action->action_name_id);
        $this->assertSame('search', $name->type, '검색어는 사전의 search 유형이다');
        $this->assertSame('그누보드', $name->name);
    }

    /**
     * 빈 검색어는 남길 것이 없다.
     *
     * @scenario source=search, exclusion=none
     *
     * @effects site_search_hook_records_query_and_result_count
     *
     * @return void 없음
     */
    public function test_empty_query_records_nothing(): void
    {
        $this->getJson('/api/search?q=');

        $this->assertSame(0, AnalyticsAction::where('action_type', ActionType::SiteSearch->value)->count());
    }

    /**
     * 검색어 수집을 끄면 훅이 와도 적재하지 않는다.
     *
     * @scenario source=search, exclusion=disabled
     *
     * @effects site_search_hook_records_query_and_result_count
     *
     * @return void 없음
     */
    public function test_setting_off_records_nothing(): void
    {
        config(['g7_settings.core.analytics.site_search_enabled' => false]);

        $this->getJson('/api/search?q=그누보드')->assertOk();

        $this->assertSame(0, AnalyticsAction::count());
    }
}
