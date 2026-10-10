<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Feature\Console;

use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RunTrigger;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRunItem;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;
use Plugins\Sirsoft\Koreaexim\Services\ExchangeRateUpdateService;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

class UpdateExchangeRatesCommandTest extends PluginTestCase
{
    /**
     * 요일과 인증키 및 기본통화 조합별 실행 자격을 검증합니다.
     *
     * @effects update_eligibility, http_call_budget
     *
     * @return void 반환값 없음
     */
    public function test_update_eligibility_matrix(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response($this->quotes());
        });
        $cases = require dirname(__DIR__, 2).'/fixtures/UpdateCases.php';
        $settings = app(EcommerceSettingsService::class);
        $original = $settings->getSettings('language_currency');
        $repo = app(RateRunRepositoryInterface::class);
        $originalMaxId = KoreaeximRateRun::max('id') ?? 0;
        foreach ($cases as $index => [$trigger, $auto, $key, $base, $day, $time, $success, $attempts]) {
            KoreaeximRateRunItem::where('run_id', '>', $originalMaxId)->delete();
            KoreaeximRateRun::where('id', '>', $originalMaxId)->delete();
            $this->travelTo(Carbon::create(2027, 1, 1, $time === 'before_11' ? 10 : 13, 0, 0, 'Asia/Seoul')->addDays($index));
            $this->configure(['auto_manage' => $auto === 'on', 'auth_key' => $key === 'set' ? 'fixture-key' : '', 'run_days' => $day === 'run_day' ? [1, 2, 3, 4, 5, 6, 7] : [], 'retry_max' => 3, 'update_time' => '11:30', 'lookback_days' => 0]);
            $currency = $original;
            if ($base === 'unsupported') {
                $currency['currencies'][] = ['code' => 'VND', 'name' => ['ko' => '동'], 'symbol' => 'VND', 'base_unit' => 1, 'exchange_rate' => 20, 'decimal_places' => 0, 'rounding_unit' => 1, 'rounding_method' => 'round'];
            }
            $currency['default_currency'] = $base === 'unsupported' ? 'VND' : $base;
            $settings->saveSettings(['language_currency' => $currency]);
            $seed = ['search_date' => now('Asia/Seoul')->toDateString(), 'rate_type' => 'deal_bas_r', 'base_currency' => 'KRW', 'base_unit' => 1000, 'request_count' => 1, 'started_at' => now(), 'finished_at' => now()];
            for ($n = 0; $n < ['zero' => 0, 'lt_max' => 1, 'ge_max' => 4][$attempts]; $n++) {
                $repo->create($seed + ['trigger' => RunTrigger::Scheduled, 'status' => RunStatus::NetworkError]);
            }
            if ($success === 'yes') {
                $repo->create($seed + ['trigger' => RunTrigger::Manual, 'status' => RunStatus::Success, 'raw_response' => $this->quotes()]);
            }
            $before = $calls;
            $run = app(ExchangeRateUpdateService::class)->execute(RunTrigger::from($trigger));
            $context = implode('/', $cases[$index]);
            $scheduled = $trigger !== 'manual';
            $empty = ($scheduled && $auto === 'off') || ($trigger === 'retry' && ($time === 'before_11' || $day === 'off_day' || $success === 'yes' || in_array($attempts, ['zero', 'ge_max'], true)));
            if ($empty) {
                $this->assertNull($run, $context);
                $this->assertSame(0, $calls - $before, $context);

                continue;
            }
            $expected = $scheduled && $day === 'off_day' ? RunStatus::Skipped : ($key === 'unset' ? RunStatus::AuthMissing : ($auto === 'off' ? RunStatus::Skipped : ($base === 'unsupported' ? RunStatus::UnsupportedBase : RunStatus::Success)));
            $this->assertSame($expected, $run->status, $context);
            $expectedRequests = $expected === RunStatus::Success || ($expected === RunStatus::UnsupportedBase && $success === 'no') ? 1 : 0;
            $this->assertSame($expectedRequests, $calls - $before, $context);
        }
    }

    /**
     * 연결 테스트의 조회일과 인증 실패 종료 코드를 검증합니다.
     *
     * @effects console_dry_run_date_and_auth_error_exit_codes
     *
     * @return void 반환값 없음
     */
    public function test_console_dry_run_date_and_auth_error_exit_codes(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->quotes())->push([['result' => 3]])]);
        $this->artisan('sirsoft-koreaexim:update', ['--dry-run' => true, '--date' => '2026-09-11'])->assertExitCode(0);
        $run = app(RateRunRepositoryInterface::class)->latest();
        $this->assertSame('2026-09-11', $run->search_date->toDateString());
        $this->assertSame(RunStatus::DryRun, $run->status);
        $this->artisan('sirsoft-koreaexim:update', ['--date' => '2026-99-99'])->assertExitCode(1);
        $this->artisan('sirsoft-koreaexim:update', ['--dry-run' => true])->assertExitCode(1);
    }

    /**
     * 이력 정리 명령이 보존 기간을 저장소에 전달하는지 검증합니다.
     *
     * @effects prune_command_calls_retention_repository
     *
     * @return void 반환값 없음
     */
    public function test_prune_command_calls_retention_repository(): void
    {
        $repository = \Mockery::mock(RateRunRepositoryInterface::class);
        $repository->shouldReceive('prune')->once()->with(365)->andReturn(3);
        $this->app->instance(RateRunRepositoryInterface::class, $repository);
        $this->artisan('sirsoft-koreaexim:prune-history')->assertExitCode(0);
    }

    /**
     * 각 테스트의 격리된 설정과 의존 객체를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Seoul')->setDate(2026, 9, 14)->setTime(11, 30));
        $settings = app(EcommerceSettingsService::class);
        $currency = $settings->getSettings('language_currency');
        $currency['rate_provider'] = 'koreaexim';
        $settings->saveSettings(['language_currency' => $currency]);
    }

    /**
     * 성공 시 환율과 실행 이력 및 통화 스냅샷이 저장되는지 검증합니다.
     *
     * @effects success_saves_rates_run_and_currency_snapshots
     *
     * @return void 반환값 없음
     */
    public function test_success_saves_rates_run_and_currency_snapshots(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $run = $this->runUpdate();
        $this->assertContains($run->status, [RunStatus::Success, RunStatus::Partial]);
        $this->assertGreaterThan(0, $run->applied_count);
        $this->assertGreaterThan(0, $run->items()->count());
        $this->assertSame(1, $run->request_count);
        $this->assertStringNotContainsString('fixture-key', json_encode($run->raw_response));
        $second = $this->runUpdate();
        $this->assertSame(0, $second->applied_count);
        $this->assertGreaterThan(0, $second->unchanged_count);
    }

    /**
     * 연결 테스트가 환율을 적용하거나 재적용 원본이 되지 않는지 검증합니다.
     *
     * @effects dry_run_does_not_apply_or_become_reapplication_source
     *
     * @return void 반환값 없음
     */
    public function test_dry_run_does_not_apply_or_become_reapplication_source(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $settings = app(EcommerceSettingsService::class);
        $before = $settings->getSettings('language_currency');
        $run = app(ExchangeRateUpdateService::class)->execute(RunTrigger::Manual, dryRun: true);
        $this->assertSame(RunStatus::DryRun, $run->status);
        $this->assertSame($before, $settings->getSettings('language_currency'));
        $this->assertNull(app(RateRunRepositoryInterface::class)->latestSuccessful());
    }

    /**
     * 열한 시 이후 빈 응답을 재시도하거나 알리지 않는지 검증합니다.
     *
     * @effects after_eleven_no_data_has_no_retry_and_no_notification
     *
     * @return void 반환값 없음
     */
    public function test_after_eleven_no_data_has_no_retry_and_no_notification(): void
    {
        $this->configure(['lookback_days' => 0, 'notify_on_failure' => true]);
        $notifications = 0;
        HookManager::addAction('sirsoft-koreaexim.update.failed', function () use (&$notifications) {
            $notifications++;
        });
        Http::fake(['*' => Http::response([])]);
        $run = $this->runUpdate();
        $this->assertSame(RunStatus::NoData, $run->status);
        $this->assertStringContainsString('직전 영업일', $run->message);
        $this->travel(1)->hours();
        $this->assertNull($this->runUpdate(RunTrigger::Retry));
        $this->assertSame(0, $notifications);
        Http::assertSentCount(1);
    }

    /**
     * 열한 시 이전 빈 응답이 재시도 대상인지 검증합니다.
     *
     * @effects before_eleven_no_data_is_retried
     *
     * @return void 반환값 없음
     */
    public function test_before_eleven_no_data_is_retried(): void
    {
        $this->travelTo(now('Asia/Seoul')->setTime(9, 30));
        $this->configure(['lookback_days' => 0, 'update_time' => '09:30']);
        Http::fake(['*' => Http::response([])]);
        $run = $this->runUpdate();
        $this->assertStringContainsString('11시 이전', $run->message);
        $this->travel(1)->hours();
        $this->assertSame(RunStatus::NoData, $this->runUpdate(RunTrigger::Retry)->status);
        Http::assertSentCount(2);
    }

    /**
     * 비활성 또는 실행 요일 밖에서는 외부 요청하지 않는지 검증합니다.
     *
     * @effects disabled_and_out_of_weekday_paths_do_not_call_http
     *
     * @return void 반환값 없음
     */
    public function test_disabled_and_out_of_weekday_paths_do_not_call_http(): void
    {
        $this->configure(['auto_manage' => false]);
        $this->assertNull($this->runUpdate());
        $this->configure(['auto_manage' => true, 'run_days' => []]);
        $this->assertSame(RunStatus::Skipped, $this->runUpdate()->status);
        Http::assertNothingSent();
    }

    /**
     * 수동 영업일 역탐색의 모든 요청을 호출 예산에 합산하는지 검증합니다.
     *
     * @effects manual_lookback_counts_every_request
     *
     * @return void 반환값 없음
     */
    public function test_manual_lookback_counts_every_request(): void
    {
        Http::fakeSequence()->push([])->push([])->push($this->quotes());
        $run = $this->runUpdate(RunTrigger::Manual);
        $this->assertSame(3, $run->request_count);
        $this->assertSame('2026-09-12', $run->search_date->toDateString());
    }

    /**
     * 인증 실패와 호출 한도가 자동 재시도를 중단시키는지 검증합니다.
     *
     * @effects auth_error_and_quota_stop_retries_and_quota_blocks_manual
     *
     * @return void 반환값 없음
     */
    public function test_auth_error_and_quota_stop_retries_and_quota_blocks_manual(): void
    {
        Http::fakeSequence()->push([['result' => 3]])->push([['result' => 4]]);
        $settings = app(EcommerceSettingsService::class);
        $before = collect($settings->getSettings('language_currency')['currencies'])->pluck('exchange_rate', 'code')->all();
        $this->assertSame(RunStatus::AuthError, $this->runUpdate()->status);
        // 인증 실패는 환율을 건드리지 않아야 합니다 — 0 이나 빈 값이 들어가면 그 통화가 사용자 화면에서 사라집니다.
        $this->assertNotEmpty($before);
        $this->assertSame($before, collect($settings->getSettings('language_currency')['currencies'])->pluck('exchange_rate', 'code')->all());
        $this->travel(1)->hours();
        $this->assertNull($this->runUpdate(RunTrigger::Retry));
        $this->assertSame(RunStatus::QuotaExceeded, $this->runUpdate(RunTrigger::Manual)->status);
        $this->assertSame(0, $this->runUpdate(RunTrigger::Manual)->request_count);
        Http::assertSentCount(2);
    }

    /**
     * 락 경합과 인증키 누락 시 외부 요청하지 않는지 검증합니다.
     *
     * @effects lock_contention_and_missing_key_do_not_call_http
     *
     * @return void 반환값 없음
     */
    public function test_lock_contention_and_missing_key_do_not_call_http(): void
    {
        $lock = Cache::lock('sirsoft-koreaexim:update', 900);
        $lock->get();
        try {
            $this->assertNull($this->runUpdate());
        } finally {
            $lock->release();
        }
        $this->configure(['auth_key' => '']);
        $this->assertSame(RunStatus::AuthMissing, $this->runUpdate()->status);
        Http::assertNothingSent();
    }

    /**
     * 성공하거나 재시도 예산을 소진하면 추가 실행하지 않는지 검증합니다.
     *
     * @effects success_and_retry_budget_suppress_more_attempts
     *
     * @return void 반환값 없음
     */
    public function test_success_and_retry_budget_suppress_more_attempts(): void
    {
        $this->configure(['retry_max' => 0, 'lookback_days' => 0]);
        Http::fake(['*' => Http::response('not json')]);
        $this->assertSame(RunStatus::BadResponse, $this->runUpdate()->status);
        $this->travel(1)->hours();
        $this->assertNull($this->runUpdate(RunTrigger::Retry));
        Http::assertSentCount(1);
    }

    /**
     * 환율 갱신 명령을 실행하고 종료 코드를 검증합니다.
     *
     * @param  RunTrigger  $trigger  trigger 입력값
     * @return ?KoreaeximRateRun 처리 결과
     */
    private function runUpdate(RunTrigger $trigger = RunTrigger::Scheduled): ?KoreaeximRateRun
    {
        return app(ExchangeRateUpdateService::class)->execute($trigger);
    }
}
