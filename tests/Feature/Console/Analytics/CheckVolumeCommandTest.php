<?php

namespace Tests\Feature\Console\Analytics;

use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 원장 용량 경고 — 언제 알리고 언제 조용한지.
 *
 * 알림이 너무 잦으면 운영자는 그 알림 전체를 무시하게 되고, 없으면 디스크가 찰 때까지
 * 아무도 모른다. 한계를 넘는 순간과 하루 한 번이라는 간격을 잠근다.
 */
class CheckVolumeCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 한계를 넘으면 경고 훅이 한 번 발화하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects crossing_the_threshold_fires_the_limit_hook, volume_check_reports_current_ledger_size
     */
    public function test_crossing_the_threshold_fires_the_hook(): void
    {
        config([
            'g7_settings.core.analytics.ledger_warning_visits' => 2,
            'g7_settings.core.analytics.ledger_warning_actions' => 1000000,
        ]);

        AnalyticsVisit::factory()->count(3)->create();

        $fired = [];
        HookManager::addAction('core.analytics.ledger_limit_exceeded', function ($payload) use (&$fired): void {
            $fired[] = $payload;
        });

        $this->artisan('analytics:check-volume')->assertSuccessful();

        $this->assertCount(1, $fired);
        $this->assertSame(3, $fired[0]['visits']);
        $this->assertSame(2, $fired[0]['threshold_visits']);
    }

    /**
     * 한계 안이면 아무것도 알리지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects staying_under_the_threshold_fires_nothing
     */
    public function test_staying_under_the_threshold_is_silent(): void
    {
        config([
            'g7_settings.core.analytics.ledger_warning_visits' => 500000,
            'g7_settings.core.analytics.ledger_warning_actions' => 2000000,
        ]);

        AnalyticsVisit::factory()->count(2)->create();

        $fired = 0;
        HookManager::addAction('core.analytics.ledger_limit_exceeded', function () use (&$fired): void {
            $fired++;
        });

        $this->artisan('analytics:check-volume')->assertSuccessful();

        $this->assertSame(0, $fired);
    }

    /**
     * 같은 날 두 번 돌아도 경고가 한 번만 나가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects the_same_warning_is_sent_once_a_day
     */
    public function test_the_same_warning_goes_out_once_a_day(): void
    {
        config(['g7_settings.core.analytics.ledger_warning_visits' => 1]);

        AnalyticsVisit::factory()->count(2)->create();

        $fired = 0;
        HookManager::addAction('core.analytics.ledger_limit_exceeded', function () use (&$fired): void {
            $fired++;
        });

        $this->artisan('analytics:check-volume')->assertSuccessful();
        $this->artisan('analytics:check-volume')->assertSuccessful();

        $this->assertSame(1, $fired, '같은 경고가 하루에 두 번 나가면 운영자가 알림 전체를 무시하게 된다');
    }
}
