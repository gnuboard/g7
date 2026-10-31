<?php

namespace Plugins\Sirsoft\Koreaexim\Services;

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
     * @param  UpdateScheduleResolver  $schedule  정기 갱신 시각 해석기 (코어 스케줄 행 기준)
     */
    public function __construct(private RateRunRepositoryInterface $runs, private ManagedCurrencyResolver $resolver,
        private EcommerceSettingsService $settings, private ExchangeRateUpdateService $updates,
        private UpdateScheduleResolver $schedule) {}

    /**
     * 관리 상태와 최근 이력·다음 실행 예정 시각을 반환합니다.
     *
     * 갱신 시각은 관리자 > 스케줄 관리의 정기 갱신 행(cron·한국시간)이 정한다. 다음 실행 시각은 그 행의 일정 중
     * 실행 요일에 드는 첫 시각이며, 자동 관리가 꺼졌거나 실행 요일이 없거나 행이 비활성이면 null 이다.
     * `schedule` 은 설정 화면의 「스케줄 관리에서 변경」 링크·표시용이다(행이 없으면 null).
     *
     * @return array 처리 결과
     */
    public function status(): array
    {
        $siteZone = config('app.schedule_timezone', config('app.timezone'));
        $auto = (bool) plugin_setting('sirsoft-koreaexim', 'auto_manage', false);
        $days = (array) plugin_setting('sirsoft-koreaexim', 'run_days', [1, 2, 3, 4, 5]);
        $row = $this->schedule->schedule();
        $active = $row === null || (bool) $row->is_active;
        $next = $auto && $days !== [] && $active ? $this->schedule->nextRunAt(now(), $days, $row) : null;
        // 요일·자동 관리와 무관한 「일정상 다음 갱신」의 시각(사이트 시간대 HH:mm) — 설정 화면의 시각 안내용
        $upcoming = $this->schedule->nextRunAt(now(), [1, 2, 3, 4, 5, 6, 7], $row);

        return array_merge($this->resolver->resolve(), [
            'auto_manage' => $auto, 'auth_key_set' => (bool) plugin_setting('sirsoft-koreaexim', 'auth_key', ''),
            'base_currency' => $this->settings->getSetting('language_currency.default_currency'),
            'last_success_run' => $this->runs->latestSuccessful(), 'last_run' => $this->runs->latest(),
            'today_request_count' => $this->runs->sumTodayRequestCount(), 'quota_limit' => 1000,
            'next_run_at_kst' => $next ? $next->format('Y-m-d H:i').' KST' : null,
            'update_time_site' => $upcoming?->setTimezone($siteZone)->format('H:i'),
            'next_run_at' => $next?->setTimezone($siteZone)->format('Y-m-d H:i'),
            'schedule' => $row === null ? null : [
                'id' => $row->id, 'name' => $row->name, 'expression' => $row->expression,
                'timezone' => $row->timezone, 'is_active' => (bool) $row->is_active,
            ],
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
            // 연결 테스트를 누른 직후 「지금 갱신」이 60초 동안 429 로 막힌다(2026-09-14 지적).
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
