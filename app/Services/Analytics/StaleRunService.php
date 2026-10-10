<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Services\Analytics\Concerns\BroadcastsRunProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 끝을 알리지 못하고 멈춘 실행 이력을 정리합니다.
 *
 * 실행 이력 한 행은 그 실행이 스스로 성공·실패·건너뜀으로 닫는다. 그런데 워커가 도중에 죽으면
 * (서버 재시작·메모리 초과·잡 제한시간) 닫을 코드가 돌지 못해 행이 「실행 중」으로 남는다. 진단 화면은
 * 실행 중 행이 있으면 「지금 집계」·「어제 확정」을 잠그고 진행률을 따라가므로, 그 행 하나가 정리
 * 배치가 지울 때까지(실패 로그 보존일) 버튼을 잠그고 소켓 없는 사이트에서는 2초 폴링을 멈추지 않는다
 * (#682 S3 라운드 13 — 설계 결정: 오래된 실행 중 행을 실패로 + 잡 실패 훅).
 */
class StaleRunService
{
    use BroadcastsRunProgress;

    /**
     * 「실행 중」을 멈춘 것으로 볼 경과 시간(초).
     *
     * 큐 잡의 제한시간(1시간)의 두 배다 — 잡은 그 전에 워커가 끊으므로 넘기면 멈춘 것이 확실하고,
     * 예약 커맨드처럼 제한시간 없이 도는 실행이 드물게 1시간을 넘겨도 오판하지 않는다.
     */
    public const STALE_AFTER_SECONDS = 7200;

    /**
     * 실행 이력 저장소를 받습니다.
     *
     * @param  ArchiveRunRepositoryInterface  $runs  집계 실행 이력 저장소
     */
    public function __construct(
        private readonly ArchiveRunRepositoryInterface $runs,
    ) {}

    /**
     * 이 시각보다 앞서 시작한 「실행 중」은 멈춘 것으로 본다.
     *
     * @return Carbon 기준 시각(UTC)
     */
    public function cutoff(): Carbon
    {
        return AnalyticsClock::now()->subSeconds(self::STALE_AFTER_SECONDS);
    }

    /**
     * 기준 시간을 넘긴 「실행 중」 행을 전부 실패로 닫습니다.
     *
     * @return int 닫은 행 수
     */
    public function failStale(): int
    {
        return $this->close(
            $this->runs->getRunningStartedBefore($this->cutoff()),
            __('analytics.archive.stale_run'),
        );
    }

    /**
     * 실패한 큐 잡이 맡았던 「실행 중」 행을 실패로 닫습니다.
     *
     * @param  string  $runType  잡이 만드는 실행 유형
     * @param  Carbon  $queuedAt  잡을 큐에 올린 시각
     * @param  int|null  $triggeredBy  요청한 관리자 ID
     * @param  string  $reason  잡이 실패한 사유
     * @param  array{0: Carbon, 1: Carbon}|null  $period  잡이 맡은 기간 (UTC, 주면 그 안의 날짜 행만 닫는다)
     * @return int 닫은 행 수
     */
    public function failOrphansOf(string $runType, Carbon $queuedAt, ?int $triggeredBy, string $reason, ?array $period = null): int
    {
        return $this->close(
            $this->runs->getRunningOfTypeSince($runType, $queuedAt, $triggeredBy, $period),
            __('analytics.archive.job_failed', ['reason' => Str::limit($reason, 300)]),
        );
    }

    /**
     * 행들을 실패로 닫고 각각 방송합니다.
     *
     * @param  Collection<int, AnalyticsArchiveRun>  $runs  닫을 실행 이력
     * @param  string  $error  이력에 남길 사유
     * @return int 닫은 행 수
     */
    private function close(Collection $runs, string $error): int
    {
        foreach ($runs as $run) {
            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Failed->value,
                'finished_at' => AnalyticsClock::now(),
                'error' => $error,
            ]);

            $this->broadcastRunProgress($run);
        }

        return $runs->count();
    }
}
