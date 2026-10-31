<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Feature;

use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RunTrigger;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Services\ExchangeRateUpdateService;
use Plugins\Sirsoft\Koreaexim\Services\RateRunService;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

/**
 * 정기 갱신 시각의 원천 = 스케줄 관리의 정기 갱신 행 (1.0.2)
 *
 * 운영자가 스케줄 관리에서 정기 갱신 cron 을 바꾸면 상태 화면의 다음 실행 시각과 재시도 시작 시각이 함께 따라가야
 * 한다. 옛 설정(`update_time`)을 계속 읽으면 화면은 11:30 을 말하는데 실제로는 다른 시각에 돌고, 재시도는 정기
 * 갱신보다 먼저 돌아 빈 응답을 비영업일로 오판한다.
 */
class UpdateScheduleRowTest extends PluginTestCase
{
    /**
     * 행 상태·사이트 시간대·실행 요일 조합별 상태 응답의 다음 실행 시각·시각 안내·스케줄 요약을 확인합니다.
     *
     * @effects status_next_run_follows_schedule_row
     *
     * @return void 반환값 없음
     */
    public function test_status_follows_schedule_row_matrix(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 10:00', 'Asia/Seoul'));
        $cases = require dirname(__DIR__).'/fixtures/UpdateScheduleRowCases.php';
        foreach ($cases as [$row, $zone, $days, $expression, $active, $nextKst, $nextSite, $timeSite]) {
            Schedule::query()->where('extension_identifier', 'sirsoft-koreaexim')->delete();
            $schedule = $expression === null ? null : $this->useUpdateSchedule($expression, $active);
            config(['app.schedule_timezone' => $zone]);
            $this->configure(['auto_manage' => true, 'run_days' => $days === 'weekdays' ? [1, 2, 3, 4, 5] : []]);
            $status = app(RateRunService::class)->status();
            $context = implode('/', [$row, $zone, $days]);
            $this->assertSame($nextKst === null ? null : $nextKst.' KST', $status['next_run_at_kst'], $context);
            $this->assertSame($nextSite, $status['next_run_at'], $context);
            $this->assertSame($timeSite, $status['update_time_site'], $context);
            $this->assertSame($schedule === null ? null : [
                'id' => $schedule->id, 'name' => 'update-rates', 'expression' => $expression,
                'timezone' => 'Asia/Seoul', 'is_active' => $active,
            ], $status['schedule'], $context);
        }
    }

    /**
     * 재시도는 그날 정기 갱신 시각(스케줄 행)이 지난 뒤에만 돌고, 행이 없으면 선언 기본값(11:30)을 기다리는지 확인합니다.
     *
     * @effects retry_waits_for_today_scheduled_time
     *
     * @return void 반환값 없음
     */
    public function test_retry_waits_for_today_scheduled_time(): void
    {
        $this->configure(['auth_key' => 'test-key', 'auto_manage' => true, 'lookback_days' => 0, 'run_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $settings = app(EcommerceSettingsService::class);
        $currency = $settings->getSettings('language_currency');
        $currency['rate_provider'] = 'koreaexim';
        $settings->saveSettings(['language_currency' => $currency]);
        Http::fake(['*' => Http::response($this->quotes())]);
        $this->travelTo(Carbon::parse('2026-09-14 10:00', 'Asia/Seoul'));
        KoreaeximRateRun::create(['trigger' => RunTrigger::Scheduled, 'status' => RunStatus::NetworkError,
            'search_date' => now('Asia/Seoul')->toDateString(), 'rate_type' => 'deal_bas_r', 'base_currency' => 'KRW', 'base_unit' => 1000,
            'request_count' => 0, 'raw_response' => $this->quotes(), 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(5)]);
        $service = app(ExchangeRateUpdateService::class);

        // 행 없음 → 기본 11:30 이 아직 안 지났다
        $this->assertNull($service->execute(RunTrigger::Retry), 'missing row before 11:30');
        // 운영자가 09:00 으로 바꿨다 → 10:00 은 정기 갱신 뒤이므로 재시도한다
        $this->useUpdateSchedule('0 9 * * *');
        $this->assertNotNull($service->execute(RunTrigger::Retry), 'row 09:00 at 10:00');
        Schedule::query()->where('extension_identifier', 'sirsoft-koreaexim')->delete();
        // 운영자가 13:00 으로 바꿨다 → 10:00 은 아직 정기 갱신 전이다
        $this->useUpdateSchedule('0 13 * * *');
        $this->assertNull($service->execute(RunTrigger::Retry), 'row 13:00 at 10:00');
    }
}
