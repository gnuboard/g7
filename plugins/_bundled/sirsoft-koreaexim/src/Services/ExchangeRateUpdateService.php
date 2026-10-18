<?php

namespace Plugins\Sirsoft\Koreaexim\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\ExchangeRateSaveException;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Plugins\Sirsoft\Koreaexim\Enums\ItemStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RateType;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RunTrigger;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;

/** 실행 잠금·호출 예산·재시도·저장·알림을 한 경로로 처리합니다. */
class ExchangeRateUpdateService
{
    /**
     * 처리에 필요한 의존 객체를 주입합니다.
     *
     * @param  RateRunRepositoryInterface  $runs  runs 입력값
     * @param  EcommerceSettingsService  $settings  settings 입력값
     * @param  KoreaeximApiClient  $client  client 입력값
     * @param  ExchangeRateCalculator  $calculator  calculator 입력값
     * @param  CacheInterface  $cache  cache 입력값
     */
    public function __construct(
        private RateRunRepositoryInterface $runs,
        private EcommerceSettingsService $settings,
        private KoreaeximApiClient $client,
        private ExchangeRateCalculator $calculator,
        private CacheInterface $cache,
    ) {}

    /**
     * 공통 잠금 안에서 실행하고 종료 시 잠금을 해제합니다.
     *
     * @param  RunTrigger  $trigger  trigger 입력값
     * @param  ?string  $date  date 입력값
     * @param  bool  $dryRun  dryRun 입력값
     * @param  ?int  $userId  userId 입력값
     * @return ?KoreaeximRateRun 처리 결과
     */
    public function execute(RunTrigger $trigger, ?string $date = null, bool $dryRun = false, ?int $userId = null): ?KoreaeximRateRun
    {
        // 최대 역탐색 15일 × 전송 재시도 2회 × 20초를 덮는 임대 시간.
        $lock = Cache::lock('sirsoft-koreaexim:update', 900);
        if (! $lock->get()) {
            return null;
        }
        try {
            return $this->executeLocked($trigger, $date, $dryRun, $userId);
        } finally {
            $lock->release();
        }
    }

    /**
     * 요일·호출 예산·재시도를 판정하여 환율을 적용하고 기록합니다.
     *
     * @param  RunTrigger  $trigger  trigger 입력값
     * @param  ?string  $date  date 입력값
     * @param  bool  $dryRun  dryRun 입력값
     * @param  ?int  $userId  userId 입력값
     * @return ?KoreaeximRateRun 처리 결과
     */
    private function executeLocked(RunTrigger $trigger, ?string $date, bool $dryRun, ?int $userId): ?KoreaeximRateRun
    {
        // audit:allow datetime-display-user-timezone reason: 한국시간 고시일·재시도·일일 호출 및 알림 예산 정책이며 화면 표시 시각이 아닙니다.
        $now = now('Asia/Seoul');
        $scheduled = in_array($trigger, [RunTrigger::Scheduled, RunTrigger::Retry], true);
        if ($scheduled && ! $this->setting('auto_manage', false)) {
            return null;
        }
        $today = $this->runs->todayRuns();
        $days = array_map('intval', (array) $this->setting('run_days', [1, 2, 3, 4, 5]));
        $retryMax = (int) $this->setting('retry_max', 3);
        if ($trigger === RunTrigger::Retry) {
            $attempts = $today->whereIn('trigger', [RunTrigger::Scheduled, RunTrigger::Retry]);
            // 통신 실패 또는 11시 이전의 빈 응답만 자동 재시도합니다.
            $blocked = $today->contains(fn ($run) => $run->trigger !== RunTrigger::DryRun && ! (
                in_array($run->status, [RunStatus::NetworkError, RunStatus::BadResponse], true)
                || ($run->status === RunStatus::NoData && $run->finished_at !== null
                    && $run->finished_at->copy()->setTimezone('Asia/Seoul')->format('H:i') < '11:00')
            ));
            if ($now->format('H:i') <= $this->setting('update_time', '11:30') || ! in_array($now->dayOfWeekIso, $days, true)
                || $blocked || $attempts->isEmpty() || $attempts->count() >= 1 + $retryMax) {
                return null;
            }
        }
        $currency = $this->settings->getSettings('language_currency');
        $base = $currency['default_currency'];
        $baseRow = collect($currency['currencies'])->firstWhere('code', $base);
        $data = ['trigger' => $dryRun ? RunTrigger::DryRun : $trigger, 'status' => RunStatus::Skipped,
            'search_date' => $date ?? $now->toDateString(), 'rate_type' => RateType::tryFrom($this->setting('rate_type', 'deal_bas_r')) ?? RateType::DealBaseRate,
            'base_currency' => $base, 'base_unit' => $baseRow['base_unit'] ?? 1, 'request_count' => 0,
            'started_at' => now(), 'triggered_by' => $userId];
        if ($scheduled && ! in_array($now->dayOfWeekIso, $days, true)) {
            return $this->finish($data, RunStatus::Skipped);
        }
        if ($today->contains('status', RunStatus::QuotaExceeded)) {
            return $this->finish($data, RunStatus::QuotaExceeded);
        }
        $key = (string) $this->setting('auth_key', '');
        if ($key === '') {
            return $this->finish($data, RunStatus::AuthMissing);
        }
        if (! $dryRun && (! $this->setting('auto_manage', false) || ($currency['rate_provider'] ?? null) !== 'koreaexim')) {
            return $this->finish($data, RunStatus::Skipped, [], __('sirsoft-koreaexim::messages.provider_not_selected'));
        }
        $latest = $this->runs->latestSuccessful();
        if (! $dryRun && $latest !== null) {
            $previous = $this->calculator->calculate($latest->raw_response, $currency['currencies'], $base, $data['rate_type']);
            if ($previous['status'] === RunStatus::UnsupportedBase) {
                return $this->finish($data, RunStatus::UnsupportedBase, $previous['items']);
            }
        }
        $lookback = ! $dryRun && (! $scheduled || $latest === null) ? (int) $this->setting('lookback_days', 7) : 0;
        $search = Carbon::parse($data['search_date'], 'Asia/Seoul');
        for ($offset = 0; $offset <= $lookback; $offset++) {
            if ($today->sum('request_count') + $data['request_count'] + 2 > 1000) {
                return $this->finish($data, RunStatus::QuotaExceeded);
            }
            $data['search_date'] = $search->toDateString();
            $response = $this->client->fetch($key, $search->format('Ymd'), $dryRun ? 1 : 2);
            $data['request_count'] += $response['request_count'];
            if ($response['status'] !== RunStatus::NoData || $offset === $lookback) {
                break;
            }
            $search->subDay();
        }
        if ($response['status'] !== RunStatus::Success) {
            $message = $response['status'] === RunStatus::NoData
                // audit:allow datetime-display-user-timezone reason: 한국시간 고시일·재시도·일일 호출 및 알림 예산 정책이며 화면 표시 시각이 아닙니다.
                ? __('sirsoft-koreaexim::messages.'.(now('Asia/Seoul')->format('H:i') < '11:00' ? 'no_data_early' : 'no_data_holiday'))
                : $response['message'];

            return $this->finish($data, $response['status'], [], $message);
        }
        $calculation = $this->calculator->calculate($response['rows'], $currency['currencies'], $base, $data['rate_type'], (int) $this->setting('rate_decimal_places', 6));
        if ($calculation['status'] === RunStatus::UnsupportedBase) {
            return $this->finish($data, RunStatus::UnsupportedBase, $calculation['items']);
        }
        if (! $dryRun) {
            $this->settings->applyExchangeRates($calculation['rates'], 'koreaexim');
            $data['raw_response'] = $response['rows'];
        }

        return $this->finish($data, $dryRun ? RunStatus::DryRun : $calculation['status'], $calculation['items']);
    }

    /**
     * 최신 성공 응답으로 HTTP 없이 통화 환율을 재적용합니다.
     *
     * @return void 반환값 없음
     */
    public function reapply(): void
    {
        if (! $this->setting('auto_manage', false) || ! $this->setting('auth_key', '') || $this->settings->getSetting('language_currency.rate_provider') !== 'koreaexim') {
            return;
        }
        $lock = Cache::lock('sirsoft-koreaexim:update', 900);
        if (! $lock->get()) {
            return;
        }
        try {
            $source = $this->runs->latestSuccessful();
            if ($source === null) {
                return;
            }
            $currency = $this->settings->getSettings('language_currency');
            $type = RateType::tryFrom($this->setting('rate_type', 'deal_bas_r')) ?? RateType::DealBaseRate;
            $calculation = $this->calculator->calculate($source->raw_response, $currency['currencies'], $currency['default_currency'], $type, (int) $this->setting('rate_decimal_places', 6));
            $result = $this->settings->applyExchangeRates($calculation['rates'], 'koreaexim');
            if ($result['applied'] === []) {
                return;
            }
            $this->finish(['trigger' => RunTrigger::CurrencyChanged, 'search_date' => $source->search_date, 'rate_type' => $type,
                'base_currency' => $currency['default_currency'], 'base_unit' => $calculation['base_unit'], 'request_count' => 0,
                'source_run_id' => $source->id, 'raw_response' => $source->raw_response, 'started_at' => now()], $calculation['status'], $calculation['items']);
        } catch (ExchangeRateSaveException $e) {
            // 관리자의 통화 설정 저장 요청 안에서 동기로 도는 리스너입니다. 재적용 저장 실패를
            // 그대로 흘리면 관리자 저장이 성공했는데도 응답이 500 으로 끝나므로, 번역된 요약만
            // 남기고 삼킵니다. 실행 이력은 finish() 이전에 끊기므로 성공 이력도 남지 않습니다.
            Log::error('[sirsoft-koreaexim] '.$e->getMessage(), ['trigger' => RunTrigger::CurrencyChanged->value]);
        } finally {
            $lock->release();
        }
    }

    /**
     * 실행·항목을 저장하고 필요한 실패 알림을 발행합니다.
     *
     * @param  array  $data  data 입력값
     * @param  RunStatus  $status  status 입력값
     * @param  array  $items  items 입력값
     * @param  ?string  $message  message 입력값
     * @return KoreaeximRateRun 처리 결과
     */
    private function finish(array $data, RunStatus $status, array $items = [], ?string $message = null): KoreaeximRateRun
    {
        $counts = array_count_values(array_column($items, 'status'));
        $run = $this->runs->create(array_merge($data, ['status' => $status, 'message' => $message ?? $status->label(), 'finished_at' => now(),
            'applied_count' => $status === RunStatus::DryRun ? 0 : ($counts[ItemStatus::Applied->value] ?? 0),
            'unchanged_count' => $counts[ItemStatus::Unchanged->value] ?? 0,
            'skipped_count' => ($counts[ItemStatus::Unsupported->value] ?? 0) + ($counts[ItemStatus::InvalidRate->value] ?? 0),
        ]), $items);
        $notify = in_array($status, [RunStatus::AuthMissing, RunStatus::AuthError, RunStatus::QuotaExceeded, RunStatus::BadRequest, RunStatus::BadResponse, RunStatus::UnsupportedBase], true);
        if ($status === RunStatus::NetworkError) {
            $attempts = $this->runs->todayRuns()->whereIn('trigger', [RunTrigger::Scheduled, RunTrigger::Retry])->count();
            $notify = $attempts >= 1 + (int) $this->setting('retry_max', 3);
        }
        // audit:allow datetime-display-user-timezone reason: 한국시간 고시일·재시도·일일 호출 및 알림 예산 정책이며 화면 표시 시각이 아닙니다.
        $notificationKey = 'koreaexim:notified:'.$status->value.':'.now('Asia/Seoul')->format('Ymd');
        // execute/reapply 의 공통 실행 잠금 안에서 검사·기록하므로 중복 알림이 경합하지 않습니다.
        if ($notify && $run->trigger !== RunTrigger::DryRun && $this->setting('notify_on_failure', true)
            && ! $this->cache->has($notificationKey)) {
            $this->cache->put($notificationKey, true, 86400);
            HookManager::doAction('sirsoft-koreaexim.update.failed', $run);
        }

        return $run;
    }

    /**
     * 플러그인 설정을 기본값과 함께 조회합니다.
     *
     * @param  string  $key  key 입력값
     * @param  mixed  $default  default 입력값
     * @return mixed 처리 결과
     */
    private function setting(string $key, mixed $default): mixed
    {
        return plugin_setting('sirsoft-koreaexim', $key, $default);
    }
}
