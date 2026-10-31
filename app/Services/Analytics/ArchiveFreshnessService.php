<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\OnViewOutcome;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ArchiveRunType;
use App\Models\Analytics\AnalyticsArchiveRun;
use Illuminate\Support\Carbon;

/**
 * 집계 신선도
 *
 * 통계 화면이 「숫자가 언제 기준인지」와 「스케줄러가 돌고 있는지」를 알리는 데 쓰는 값을 만듭니다.
 * 스케줄러가 멈추면 숫자가 한 시각에 멈춘 채 정상처럼 보이는데, 그 사실을 알릴 곳이 진단 화면
 * 배지뿐이었습니다. 이 값이 모든 집계 응답의 `data.freshness` 로 실려 기간 줄과 상단 알림이 읽습니다.
 */
class ArchiveFreshnessService
{
    /**
     * 스케줄러가 멈췄다고 볼 경과 시간 (분)
     *
     * 매시 5분 실행 두 번을 놓친 시간이다 — 한 번은 서버 재시작 등으로 놓칠 수 있다.
     */
    public const SCHEDULER_STALE_MINUTES = 120;

    /**
     * 집계가 멈췄다고 볼 경과 시간 (분)
     */
    public const STALE_MINUTES = 120;

    /**
     * 신선도 계산기를 만듭니다.
     *
     * @param  ArchiveRunRepositoryInterface  $runs  실행 이력 저장소
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     */
    public function __construct(
        private readonly ArchiveRunRepositoryInterface $runs,
        private readonly VisitRepositoryInterface $visits,
    ) {}

    /**
     * 응답에 실을 신선도 블록을 만듭니다.
     *
     * @param  OnViewOutcome|null  $outcome  이번 요청의 화면 열 때 집계 결과 (돌리지 않았으면 null)
     * @return array{last_run_at: string|null, last_run_trigger: string|null, is_today: bool, lag_minutes: int|null, stale: bool, scheduler: array{alive: bool|null, last_run_at: string|null}, on_view: array{enabled: bool, status: string|null, today_visits: int|null, max_visits: int}}
     *                                                                                                                                                                                                                                                                                       신선도
     */
    public function describe(?OnViewOutcome $outcome = null): array
    {
        $config = AnalyticsConfig::fromSettings();
        $last = $this->lastSuccessfulRun();
        $finishedAt = $last?->finished_at;
        $now = AnalyticsClock::now();

        $lagMinutes = $finishedAt !== null ? max(0, (int) floor($finishedAt->diffInSeconds($now, true) / 60)) : null;
        $lastRunAt = AnalyticsClock::toSiteDateTimeString($finishedAt);

        return [
            'last_run_at' => $lastRunAt,
            'last_run_trigger' => $last?->trigger,
            'is_today' => $lastRunAt !== null && substr($lastRunAt, 0, 10) === AnalyticsClock::today(),
            'lag_minutes' => $lagMinutes,
            // 한 번도 집계하지 않았는데 방문이 쌓여 있으면 그것도 멈춘 것이다.
            'stale' => $lagMinutes !== null
                ? $lagMinutes > self::STALE_MINUTES
                : $this->visits->firstTrackedAt() !== null,
            'scheduler' => $this->schedulerStatus(),
            'on_view' => [
                'enabled' => $config->archiveOnViewEnabled,
                'status' => $outcome?->status,
                'today_visits' => $outcome?->todayVisits,
                'max_visits' => $config->archiveOnViewMaxVisits,
            ],
        ];
    }

    /**
     * 스케줄러가 돌고 있는지 판정합니다.
     *
     * 예약 매시 실행(`trigger=scheduled`)이 성공·실패와 무관하게 최근 120분 안에 시작했으면 살아 있다.
     * 없을 때는 판정 근거가 쌓였는지 먼저 본다 — 설치 직후 첫 :05 전에 「스케줄러가 없다」고 띄우면
     * 오경보다. 근거 시각은 원장 첫 방문과 첫 집계 실행 중 이른 쪽이고, 그 시각이 120분 넘게 지났는데도
     * 예약 실행이 없으면 멈춘 것이다. 근거가 없거나 아직 이르면 `null`(모름)이다.
     *
     * @return array{alive: bool|null, last_run_at: string|null} 판정과 마지막 예약 실행 시각 (사이트 표준시)
     */
    public function schedulerStatus(): array
    {
        $cutoff = AnalyticsClock::now()->subMinutes(self::SCHEDULER_STALE_MINUTES);
        $last = $this->runs->findLastStartedByTrigger(ArchiveRunType::Hour->value, 'scheduled');
        $lastRunAt = AnalyticsClock::toSiteDateTimeString($last?->started_at);

        if ($last?->started_at !== null && $last->started_at->greaterThanOrEqualTo($cutoff)) {
            return ['alive' => true, 'last_run_at' => $lastRunAt];
        }

        $basis = $this->earliest($this->visits->firstTrackedAt(), $this->runs->firstStartedAt());

        if ($basis === null || $basis->greaterThan($cutoff)) {
            return ['alive' => null, 'last_run_at' => $lastRunAt];
        }

        return ['alive' => false, 'last_run_at' => $lastRunAt];
    }

    /**
     * 마지막으로 성공한 매시·일 집계 중 늦게 끝난 것을 고릅니다.
     *
     * @return AnalyticsArchiveRun|null 마지막 성공 실행 (없으면 null)
     */
    private function lastSuccessfulRun(): ?AnalyticsArchiveRun
    {
        $hour = $this->runs->findLastSuccessful(ArchiveRunType::Hour->value);
        $day = $this->runs->findLastSuccessful(ArchiveRunType::Day->value);

        if ($hour?->finished_at === null) {
            return $day?->finished_at !== null ? $day : null;
        }

        if ($day?->finished_at === null) {
            return $hour;
        }

        return $day->finished_at->greaterThan($hour->finished_at) ? $day : $hour;
    }

    /**
     * 두 시각 중 이른 쪽을 고릅니다.
     *
     * @param  Carbon|null  $first  첫 시각
     * @param  Carbon|null  $second  둘째 시각
     * @return Carbon|null 이른 시각 (둘 다 없으면 null)
     */
    private function earliest(?Carbon $first, ?Carbon $second): ?Carbon
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        return $first->lessThan($second) ? $first : $second;
    }
}
