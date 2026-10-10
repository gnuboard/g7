<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RunTrigger;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Services\ExchangeRateUpdateService;
use Plugins\Sirsoft\Koreaexim\Services\RateRunService;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

class ReviewRegressionTest extends PluginTestCase
{
    /**
     * 매시간 재시도가 통신 오류와 고시 전 빈 응답만 허용하는지 검증합니다.
     *
     * @scenario review=regression
     *
     * @effects retry_status_allowlist
     *
     * @return void 반환값 없음
     */
    public function test_retry_status_allowlist(): void
    {
        $this->configure(['auth_key' => 'test-key', 'auto_manage' => true, 'lookback_days' => 0, 'run_days' => [1, 2, 3, 4, 5, 6, 7], 'update_time' => '10:00']);
        $settings = app(EcommerceSettingsService::class);
        $currency = $settings->getSettings('language_currency');
        $currency['rate_provider'] = 'koreaexim';
        $settings->saveSettings(['language_currency' => $currency]);
        Http::fake(['*' => Http::response($this->quotes())]);
        foreach (RunStatus::cases() as $index => $status) {
            $this->travelTo(now('Asia/Seoul')->setDate(2026, 9, 1)->addDays($index)->setTime(12, 0));
            KoreaeximRateRun::create(['trigger' => RunTrigger::Scheduled, 'status' => $status,
                'search_date' => now()->toDateString(), 'rate_type' => 'deal_bas_r', 'base_currency' => 'KRW', 'base_unit' => 1000,
                'request_count' => 0, 'raw_response' => $this->quotes(), 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
            $run = app(ExchangeRateUpdateService::class)->execute(RunTrigger::Retry);
            $this->assertSame(in_array($status, [RunStatus::NetworkError, RunStatus::BadResponse, RunStatus::NoData], true), $run !== null, $status->value);
        }
    }

    /**
     * 주말 빈 응답과 서버 실패에서도 연결 테스트는 한 번만 전송하는지 검증합니다.
     *
     * @effects connection_test_one_request
     *
     * @return void 반환값 없음
     */
    public function test_connection_test_is_one_request_even_on_weekend_or_failure(): void
    {
        $this->travelTo(now('Asia/Seoul')->setDate(2026, 9, 13)->setTime(12, 0));
        $this->configure(['auth_key' => 'test-key', 'lookback_days' => 14]);
        $status = 200;
        Http::fake(function () use (&$status) {
            return Http::response([], $status);
        });
        $service = app(ExchangeRateUpdateService::class);
        $this->assertSame(1, $service->execute(RunTrigger::Manual, dryRun: true)->request_count);
        Http::assertSentCount(1);
        $status = 503;
        $run = $service->execute(RunTrigger::Manual, dryRun: true);
        $this->assertSame(RunStatus::NetworkError, $run->status);
        $this->assertSame(1, $run->request_count);
        Http::assertSentCount(2);
    }

    /**
     * 실행 락 경합은 수동 쿨다운과 일일 실행 예산을 소비하지 않는지 검증합니다.
     *
     * @effects lock_contention_preserves_manual_budget
     *
     * @return void 반환값 없음
     */
    public function test_lock_contention_does_not_consume_manual_budget(): void
    {
        RateLimiter::clear('koreaexim:manual:cooldown');
        $daily = 'koreaexim:manual:'.now('Asia/Seoul')->format('Ymd');
        RateLimiter::clear($daily);
        $lock = Cache::lock('sirsoft-koreaexim:update', 900);
        $this->assertTrue($lock->get());
        try {
            $this->assertNull(app(RateRunService::class)->manual(true, null));
            $this->assertSame(0, RateLimiter::attempts('koreaexim:manual:cooldown'));
            $this->assertSame(0, RateLimiter::attempts($daily));
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }
}
