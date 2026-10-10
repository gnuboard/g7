<?php

namespace Tests\Feature\Console\Analytics;

use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsBotHit;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 전체 삭제 — 되돌릴 수 없는 길에 문이 몇 개 달려 있는지.
 *
 * 실수로 한 번 돌면 사이트의 모든 방문 기록이 사라진다. 그래서 「무엇이 없으면 돌지
 * 않는가」를 먼저 잠근다.
 */
class PurgeCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 원장과 집계를 한 벌씩 만듭니다.
     *
     * @return void 없음
     */
    private function seedLedgerAndStats(): void
    {
        $visit = AnalyticsVisit::factory()->create();
        AnalyticsAction::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
        ]);
        AnalyticsBotHit::factory()->create();
        AnalyticsStatsDaily::factory()->create();
        AnalyticsDimensionStat::factory()->create();
        AnalyticsActionName::factory()->create();
    }

    /**
     * 확인 옵션 없이는 아무것도 지우지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects purge_requires_explicit_confirmation
     */
    public function test_it_refuses_to_run_without_the_confirm_option(): void
    {
        $this->seedLedgerAndStats();

        $this->artisan('analytics:purge')->assertFailed();

        $this->assertSame(1, AnalyticsVisit::query()->count());
        $this->assertSame(1, AnalyticsStatsDaily::query()->count());
    }

    /**
     * 대화형 확인에서 거절하면 지우지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects purge_requires_explicit_confirmation
     */
    public function test_declining_the_prompt_keeps_everything(): void
    {
        $this->seedLedgerAndStats();

        $this->artisan('analytics:purge', ['--confirm' => true])
            ->expectsQuestion('방문자 통계 기록과 집계를 모두 지웁니다. 되돌릴 수 없습니다. 진행할까요? (yes/no) [no]', 'no')
            ->assertSuccessful();

        $this->assertSame(1, AnalyticsVisit::query()->count());
    }

    /**
     * 확인을 모두 통과하면 원장과 집계가 사라지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects purge_removes_ledger_and_aggregates, purge_fires_the_data_purged_hook
     */
    public function test_confirmed_purge_removes_ledger_and_aggregates(): void
    {
        $this->seedLedgerAndStats();

        $fired = [];
        HookManager::addAction('core.analytics.data_purged', function ($payload) use (&$fired): void {
            $fired[] = $payload;
        });

        $this->artisan('analytics:purge', ['--confirm' => true])
            ->expectsQuestion('방문자 통계 기록과 집계를 모두 지웁니다. 되돌릴 수 없습니다. 진행할까요? (yes/no) [no]', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, AnalyticsVisit::query()->count());
        $this->assertSame(0, AnalyticsAction::query()->count());
        $this->assertSame(0, AnalyticsBotHit::query()->count());
        $this->assertSame(0, AnalyticsStatsDaily::query()->count());
        $this->assertSame(0, AnalyticsDimensionStat::query()->count());
        $this->assertCount(1, $fired);
    }

    /**
     * 전체 삭제가 이름 사전까지 지우는지 확인합니다.
     *
     * 사전에는 방문했던 주소·검색어·내려받은 파일명이 그대로 들어 있다. 원장만 지우고
     * 사전을 남기면 운영자는 전부 지웠다고 아는데 그 이력이 DB 에 남는다.
     *
     * @return void 없음
     *
     * @scenario target=actions, row_age=within
     *
     * @effects purge_removes_the_action_name_dictionary
     */
    public function test_confirmed_purge_removes_the_action_name_dictionary(): void
    {
        $this->seedLedgerAndStats();

        $this->assertGreaterThan(0, AnalyticsActionName::query()->count());

        $this->artisan('analytics:purge', ['--confirm' => true])
            ->expectsQuestion('방문자 통계 기록과 집계를 모두 지웁니다. 되돌릴 수 없습니다. 진행할까요? (yes/no) [no]', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, AnalyticsActionName::query()->count());
    }
}
