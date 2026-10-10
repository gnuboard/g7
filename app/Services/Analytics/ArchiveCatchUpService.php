<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ArchiveRunType;
use App\Models\Analytics\AnalyticsArchiveRun;
use Illuminate\Support\Carbon;

/**
 * 밀린 날 따라잡기
 *
 * 「어제 확정」은 매일 00:15 에 한 번만 돈다. 스케줄러가 며칠 멈췄다 돌아오면 그 사이 날은 아무도
 * 확정하지 않아 오늘 집계가 덮어쓰지도 않는 미확정 숫자로 남는다. 방문 원장이 있는 동안(보존기간 안)
 * 그런 날을 찾아 확정한다 — 예약 일 집계와 화면 열 때 집계가 함께 쓴다.
 */
class ArchiveCatchUpService
{
    /**
     * 한 번에 확정할 최대 일수
     */
    public const MAX_DAYS = 7;

    /**
     * 따라잡기를 만듭니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ArchiveService  $archive  집계 서비스
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ArchiveService $archive,
    ) {}

    /**
     * 확정할 날을 최근 날부터 고릅니다.
     *
     * 보존기간 경계에 걸린 날은 원장이 (일부라도) 지워졌으므로 넣지 않는다 — 빈 재료로 영구 보존되는
     * 일 집계를 덮어쓰게 된다. 방문 예산을 주면 방문 수 누계가 예산을 넘는 날부터 버린다(요청 하나가
     * 과도하게 길어지지 않게).
     *
     * @param  int  $limit  최대 일수
     * @param  int|null  $visitBudget  방문 수 누계 상한 (null 이면 제한 없음)
     * @return array<string, int> 날짜(Y-m-d) => 그날 방문 수, 최근 날부터
     */
    public function candidates(int $limit = self::MAX_DAYS, ?int $visitBudget = null): array
    {
        $today = AnalyticsClock::today();
        $floor = Carbon::parse($today)
            ->subDays(AnalyticsConfig::fromSettings()->effectiveRetentionDays())
            ->toDateString();

        $dates = $this->visits->unfinalizedDatesWithVisits($floor, $today, min($limit, self::MAX_DAYS));

        if ($visitBudget === null) {
            return $dates;
        }

        $kept = [];
        $total = 0;

        foreach ($dates as $date => $visits) {
            $total += $visits;

            if ($total > $visitBudget) {
                break;
            }

            $kept[$date] = $visits;
        }

        return $kept;
    }

    /**
     * 고른 날을 하나씩 확정합니다.
     *
     * @param  array<int, string>  $dates  확정할 날짜 목록 (Y-m-d)
     * @param  string  $trigger  실행 주체 (scheduled, manual, on_view)
     * @param  bool  $recordSkipped  잠금을 못 잡은 날을 건너뜀으로 남길지 여부
     * @return array<int, AnalyticsArchiveRun> 실행 이력
     */
    public function finalize(array $dates, string $trigger, bool $recordSkipped = true): array
    {
        $runs = [];

        foreach ($dates as $date) {
            $run = $this->archive->archiveDay($date, [
                'run_type' => ArchiveRunType::Day->value,
                'trigger' => $trigger,
                'is_final' => true,
                'record_skipped' => $recordSkipped,
            ]);

            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }
}
