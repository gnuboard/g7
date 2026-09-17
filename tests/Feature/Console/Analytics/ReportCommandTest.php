<?php

namespace Tests\Feature\Console\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\VisitorType;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Services\Analytics\ReportMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 정기 리포트 — 켠 사람에게만, 지난 기간을.
 *
 * 설정을 끈 운영자에게 메일이 계속 가면 그 알림 전체를 신뢰하지 않게 된다. 그래서
 * 「꺼져 있으면 아무 일도 없다」를 먼저 잠근다.
 */
class ReportCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비 — 표준시를 UTC 로 고정.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'UTC']);
    }

    /**
     * 기간 안에 일 집계를 심습니다.
     *
     * @param  string  $date  일자 (Y-m-d)
     * @param  int  $visits  방문 수
     * @return void 없음
     */
    private function daily(string $date, int $visits): void
    {
        AnalyticsStatsDaily::factory()->create([
            'date' => $date,
            'visitor_type' => VisitorType::Guest->value,
            'visits' => $visits,
            'visitors' => $visits,
            'pageviews' => $visits * 2,
        ]);
    }

    /**
     * 설정이 꺼져 있으면 아무것도 발화하지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=superadmin, period=valid
     *
     * @effects report_is_silent_when_the_setting_is_off
     */
    public function test_a_disabled_report_sends_nothing(): void
    {
        config(['g7_settings.core.analytics.report_weekly_enabled' => false]);

        $fired = 0;
        HookManager::addAction('core.analytics.weekly_report_ready', function () use (&$fired): void {
            $fired++;
        });

        $this->artisan('analytics:report', ['--period' => 'weekly'])->assertSuccessful();

        $this->assertSame(0, $fired);
    }

    /**
     * 켜져 있으면 지난 주 숫자로 훅이 발화하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=superadmin, period=valid
     *
     * @effects weekly_report_fires_with_last_period_numbers
     */
    public function test_an_enabled_weekly_report_fires_with_last_weeks_numbers(): void
    {
        config(['g7_settings.core.analytics.report_weekly_enabled' => true]);

        [$from, $to] = app(ReportMailService::class)->periodRange('weekly');
        $this->daily($from, 10);
        $this->daily($to, 5);

        $fired = [];
        HookManager::addAction('core.analytics.weekly_report_ready', function ($payload) use (&$fired): void {
            $fired[] = $payload;
        });

        $this->artisan('analytics:report', ['--period' => 'weekly'])->assertSuccessful();

        $this->assertCount(1, $fired);
        $this->assertSame(15, $fired[0]['visits']);
        $this->assertSame(30, $fired[0]['pageviews']);
        $this->assertSame($from.' ~ '.$to, $fired[0]['period_label']);
    }

    /**
     * 월간 리포트가 자기 훅으로만 발화하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=superadmin, period=valid
     *
     * @effects each_period_fires_only_its_own_hook
     */
    public function test_monthly_report_does_not_fire_the_weekly_hook(): void
    {
        config([
            'g7_settings.core.analytics.report_weekly_enabled' => true,
            'g7_settings.core.analytics.report_monthly_enabled' => true,
        ]);

        $weekly = 0;
        $monthly = 0;
        HookManager::addAction('core.analytics.weekly_report_ready', function () use (&$weekly): void {
            $weekly++;
        });
        HookManager::addAction('core.analytics.monthly_report_ready', function () use (&$monthly): void {
            $monthly++;
        });

        $this->artisan('analytics:report', ['--period' => 'monthly'])->assertSuccessful();

        $this->assertSame(0, $weekly, '한 훅에 두 정의를 걸면 주간·월간이 함께 나간다 — 훅을 갈라 둔 이유다');
        $this->assertSame(1, $monthly);
    }

    /**
     * 직전 기간이 0 이면 증감률이 비는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=superadmin, period=valid
     *
     * @effects change_percent_is_empty_when_the_previous_period_was_zero
     */
    public function test_change_percent_is_null_without_a_previous_period(): void
    {
        config(['g7_settings.core.analytics.report_weekly_enabled' => true]);

        [$from] = app(ReportMailService::class)->periodRange('weekly');
        $this->daily($from, 10);

        $fired = [];
        HookManager::addAction('core.analytics.weekly_report_ready', function ($payload) use (&$fired): void {
            $fired[] = $payload;
        });

        $this->artisan('analytics:report', ['--period' => 'weekly'])->assertSuccessful();

        $this->assertNull($fired[0]['change_pct'], '0 에서 늘어난 것을 무한 % 증가로 적으면 읽는 사람이 오해한다');
    }

    /**
     * 기간 이름이 잘못되면 실패 코드로 끝나는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=superadmin, period=inverted
     *
     * @effects report_rejects_an_unknown_period
     */
    public function test_an_unknown_period_fails(): void
    {
        $this->artisan('analytics:report', ['--period' => 'daily'])->assertFailed();
    }

    /**
     * 지난 주 기간이 오늘을 포함하지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=superadmin, period=valid
     *
     * @effects report_period_covers_the_previous_period_not_today
     */
    public function test_the_period_is_the_previous_one_not_today(): void
    {
        [$from, $to] = app(ReportMailService::class)->periodRange('weekly');

        $this->assertTrue(
            Carbon::parse($to)->lessThan(Carbon::parse(AnalyticsClock::today())),
            '리포트는 지난 기간을 돌아보는 것이라 오늘을 담지 않는다',
        );
        $this->assertSame(6, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)));
    }
}
