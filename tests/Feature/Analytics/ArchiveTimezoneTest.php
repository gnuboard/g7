<?php

namespace Tests\Feature\Analytics;

use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\VisitorType;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsStatsHourly;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\ArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 집계 표준시 — 같은 원장이 사이트 표준시에 따라 다른 날로 묶이는지.
 *
 * 원장은 UTC 로 적히고 집계는 사이트 표준시로 묶인다. 이 두 기준이 섞이면 한국 새벽
 * 방문이 어제로 가고, 운영자는 「오늘 방문자」가 왜 적은지 알 수 없다. 서머타임이 있는
 * 표준시에서는 배치가 두 번 돌 수 있으므로 멱등도 함께 잠근다.
 */
class ArchiveTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 주어진 UTC 시각에 방문 하나와 페이지뷰 하나를 만듭니다.
     *
     * @param  string  $occurredAtUtc  발생 시각 (UTC, Y-m-d H:i:s)
     * @param  string  $visitDate  방문 일자 (Y-m-d, 사이트 표준시 기준)
     * @return AnalyticsVisit 만들어진 방문
     */
    private function visitAt(string $occurredAtUtc, string $visitDate): AnalyticsVisit
    {
        $moment = Carbon::parse($occurredAtUtc, 'UTC');

        $name = AnalyticsActionName::query()->firstOrCreate(
            ['type' => 'url', 'name_hash' => md5('url|example.test/')],
            ['name' => 'example.test/'],
        );

        $visit = AnalyticsVisit::factory()->create([
            'visit_date' => $visitDate,
            'first_action_at' => $moment,
            'last_action_at' => $moment,
            'visitor_type' => VisitorType::Guest,
            'actions_count' => 1,
            'pageviews_count' => 1,
            'total_time_seconds' => 0,
            'is_bounce' => true,
            'entry_action_name_id' => $name->id,
            'exit_action_name_id' => $name->id,
        ]);

        AnalyticsAction::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'action_type' => ActionType::Pageview,
            'action_name_id' => $name->id,
            'occurred_at' => $moment,
            'time_spent_seconds' => 0,
        ]);

        return $visit;
    }

    /**
     * 같은 UTC 시각이 서울 기준으로 다음 날 아침 시각에 집계되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, timezone=Asia/Seoul, data=single_type, trigger=manual
     *
     * @effects site_timezone_decides_which_day_a_visit_belongs_to, hourly_rows_use_site_local_hours
     */
    public function test_seoul_timezone_puts_a_late_utc_action_into_the_next_local_day(): void
    {
        config(['g7_settings.core.general.timezone' => 'Asia/Seoul']);

        // UTC 2026-09-10 22:30 = 서울 2026-09-11 07:30
        $this->visitAt('2026-09-10 22:30:00', '2026-09-11');

        app(ArchiveService::class)->archiveDay('2026-09-11');

        $hourly = AnalyticsStatsHourly::query()
            ->where('visitor_type', VisitorType::Guest->value)
            ->where('pageviews', '>', 0)
            ->first();

        $this->assertNotNull($hourly);
        $this->assertSame('2026-09-11 07:00:00', Carbon::parse($hourly->hour_start)->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) AnalyticsStatsDaily::query()->where('date', '2026-09-11')->value('visits'));
    }

    /**
     * 같은 UTC 시각이 뉴욕 기준으로는 전날 저녁으로 집계되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, timezone=America/New_York, data=single_type, trigger=manual
     *
     * @effects two_timezones_bucket_the_same_utc_action_differently
     */
    public function test_new_york_timezone_puts_the_same_action_into_the_previous_local_day(): void
    {
        config(['g7_settings.core.general.timezone' => 'America/New_York']);

        // UTC 2026-09-10 22:30 = 뉴욕 2026-09-10 18:30 (EDT)
        $this->visitAt('2026-09-10 22:30:00', '2026-09-10');

        app(ArchiveService::class)->archiveDay('2026-09-10');

        $hourly = AnalyticsStatsHourly::query()
            ->where('visitor_type', VisitorType::Guest->value)
            ->where('pageviews', '>', 0)
            ->first();

        $this->assertNotNull($hourly);
        $this->assertSame('2026-09-10 18:00:00', Carbon::parse($hourly->hour_start)->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) AnalyticsStatsDaily::query()->where('date', '2026-09-10')->value('visits'));
    }

    /**
     * 서머타임 전환일에 두 번 집계해도 행이 늘지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, timezone=America/New_York, data=single_type, trigger=manual
     *
     * @effects running_twice_on_a_dst_transition_day_is_idempotent
     */
    public function test_running_twice_on_a_dst_transition_day_stays_idempotent(): void
    {
        config(['g7_settings.core.general.timezone' => 'America/New_York']);

        // 2026-11-01 은 뉴욕의 서머타임 해제일이라 그 날이 25시간이다.
        $this->visitAt('2026-11-01 05:30:00', '2026-11-01');

        $service = app(ArchiveService::class);
        $service->archiveDay('2026-11-01');
        $firstHourly = AnalyticsStatsHourly::query()->count();
        $firstDaily = AnalyticsStatsDaily::query()->count();

        $service->archiveDay('2026-11-01');

        $this->assertSame($firstHourly, AnalyticsStatsHourly::query()->count(), '전환일을 두 번 집계해도 시간 행이 늘지 않는다');
        $this->assertSame($firstDaily, AnalyticsStatsDaily::query()->count());
        $this->assertSame(1, (int) AnalyticsStatsDaily::query()->where('date', '2026-11-01')->value('visits'));
    }
}
