<?php

namespace Tests\Feature\Console\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\VisitorType;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\NotificationDefinition;
use App\Services\Analytics\ReportMailService;
use App\Services\NotificationDefinitionService;
use Database\Seeders\NotificationDefinitionSeeder;
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
     * 설정은 켜져 있어도 알림 정의가 꺼져 있으면 메일이 나가지 않는다(두 게이트는 별개).
     *
     * 설정 끔(위 케이스)과 정의 비활성은 다른 스위치다. 발송 게이트는 「부팅 때 활성 정의만 훅에
     * 구독한다」이고, 그 게이트가 리포트 서비스 쪽으로 옮겨지면 이 케이스가 red 가 된다. 정의를 켠
     * 대조군이 실제로 한 통을 보내는지도 함께 본다 — 대조군이 없으면 「아무것도 안 나감」이 발송
     * 경로 자체의 고장일 수도 있다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=superadmin, period=valid
     *
     * @effects report_is_silent_when_the_definition_is_inactive
     */
    public function test_an_inactive_definition_sends_no_mail(): void
    {
        $this->seed(NotificationDefinitionSeeder::class);

        $subscribers = function (): int {
            HookManager::resetAll();
            app(NotificationHookListener::class)->registerDynamicHooks();

            return count(HookManager::getHooks()['core.analytics.weekly_report_ready'] ?? []);
        };

        // 대조군 — 켜진 정의는 리포트 훅을 구독한다(발송 경로가 살아 있다).
        $this->assertGreaterThan(0, $subscribers(), '켜진 정의가 리포트 훅을 구독하지 않는다');

        // 관리자 화면의 끄기와 같은 경로(정의 캐시 무효화 포함)로 끄면 발송 구독이 없다.
        app(NotificationDefinitionService::class)->toggleActive(
            NotificationDefinition::query()->where('type', 'analytics_weekly_report')->firstOrFail(),
        );
        $this->assertSame(0, $subscribers(), '꺼진 정의가 여전히 리포트 훅을 구독한다');
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
