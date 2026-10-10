<?php

namespace Plugins\Sirsoft\Koreaexim\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Plugins\Sirsoft\Koreaexim\Enums\RunTrigger;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** 관리자 조회와 수동 실행의 호출 제한. */
class RateRunService
{
    /**
     * 처리에 필요한 의존 객체를 주입합니다.
     *
     * @param  RateRunRepositoryInterface  $runs  runs 입력값
     * @param  ManagedCurrencyResolver  $resolver  resolver 입력값
     * @param  EcommerceSettingsService  $settings  settings 입력값
     * @param  ExchangeRateUpdateService  $updates  updates 입력값
     */
    public function __construct(private RateRunRepositoryInterface $runs, private ManagedCurrencyResolver $resolver,
        private EcommerceSettingsService $settings, private ExchangeRateUpdateService $updates) {}

    /**
     * 관리 상태와 최근 이력·다음 실행 예정 시각을 반환합니다.
     *
     * @return array 처리 결과
     */
    public function status(): array
    {
        $next = null;
        $auto = (bool) plugin_setting('sirsoft-koreaexim', 'auto_manage', false);
        $days = (array) plugin_setting('sirsoft-koreaexim', 'run_days', [1, 2, 3, 4, 5]);
        $time = plugin_setting('sirsoft-koreaexim', 'update_time', '11:30');
        if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            $time = '11:30';
        }
        if ($auto && $days !== []) {
            $candidate = Carbon::today('Asia/Seoul')->setTimeFromTimeString($time);
            for ($i = 0; $i < 8; $i++, $candidate->addDay()) {
                if ($candidate->isFuture() && in_array($candidate->dayOfWeekIso, array_map('intval', $days), true)) {
                    $next = $candidate;
                    break;
                }
            }
        }

        return array_merge($this->resolver->resolve(), [
            'auto_manage' => $auto, 'auth_key_set' => (bool) plugin_setting('sirsoft-koreaexim', 'auth_key', ''),
            'base_currency' => $this->settings->getSetting('language_currency.default_currency'),
            'last_success_run' => $this->runs->latestSuccessful(), 'last_run' => $this->runs->latest(),
            'today_request_count' => $this->runs->sumTodayRequestCount(), 'quota_limit' => 1000,
            'next_run_at_kst' => $next ? $next->format('Y-m-d H:i').' KST' : null,
            'update_time_site' => Carbon::today('Asia/Seoul')->setTimeFromTimeString($time)->setTimezone(config('app.schedule_timezone', config('app.timezone')))->format('H:i'),
            'next_run_at' => $next?->copy()->setTimezone(config('app.schedule_timezone', config('app.timezone')))->format('Y-m-d H:i'),
            'scheduler_hint' => __('sirsoft-koreaexim::messages.scheduler_hint'),
        ]);
    }

    /**
     * 조건에 맞는 실행 요약을 페이지 단위로 반환합니다.
     *
     * @param  array  $filters  filters 입력값
     * @return LengthAwarePaginator 처리 결과
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->runs->paginate($filters);
    }

    /**
     * 실행 한 건과 통화별 스냅샷을 조회합니다.
     *
     * @param  int  $id  id 입력값
     * @return KoreaeximRateRun 처리 결과
     */
    public function find(int $id): KoreaeximRateRun
    {
        return $this->runs->find($id);
    }

    /**
     * 실행 간격과 당일 상한을 확인하고 수동 실행합니다.
     *
     * @param  bool  $dryRun  dryRun 입력값
     * @param  ?int  $userId  userId 입력값
     * @return ?KoreaeximRateRun 처리 결과
     */
    public function manual(bool $dryRun, ?int $userId): ?KoreaeximRateRun
    {
        $lock = Cache::lock('sirsoft-koreaexim:manual-budget', 900);
        if (! $lock->get()) {
            throw new TooManyRequestsHttpException(60);
        }
        try {
            // 연결 테스트(dry-run)와 실제 갱신은 예산을 나눈다 — 같은 카운터를 쓰면 키를 확인하려고
            // 연결 테스트를 누른 직후 「지금 갱신」이 60초 동안 429 로 막힌다(PO 지적 2026-09-14).
            $scope = $dryRun ? 'test' : 'manual';
            $limit = $dryRun ? 5 : 20;
            $cooldown = 'koreaexim:'.$scope.':cooldown';
            // audit:allow datetime-display-user-timezone reason: 한국시간 고시일·재시도·일일 호출 및 알림 예산 정책이며 화면 표시 시각이 아닙니다.
            $daily = 'koreaexim:'.$scope.':'.now('Asia/Seoul')->format('Ymd');
            if (RateLimiter::tooManyAttempts($cooldown, 1) || RateLimiter::tooManyAttempts($daily, $limit)) {
                throw new TooManyRequestsHttpException(60, __('sirsoft-koreaexim::messages.'.($dryRun ? 'cooldown_test' : 'cooldown')));
            }
            $run = $this->updates->execute(RunTrigger::Manual, dryRun: $dryRun, userId: $userId);
            if ($run !== null) {
                RateLimiter::hit($cooldown, 60);
                RateLimiter::hit($daily, 86400);
            }

            return $run;
        } finally {
            $lock->release();
        }
    }
}
