<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Models\Analytics\AnalyticsArchiveRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 배치 실행 이력 Repository
 */
class ArchiveRunRepository implements ArchiveRunRepositoryInterface
{
    /**
     * 「실행 중」 행을 한 번에 찾는 최대 수
     */
    private const RUNNING_LOOKUP_LIMIT = 100;

    /**
     * 실행 이력을 시작 상태로 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  실행 속성
     * @return AnalyticsArchiveRun 생성된 실행 이력
     */
    public function create(array $attributes): AnalyticsArchiveRun
    {
        return AnalyticsArchiveRun::query()->create($attributes);
    }

    /**
     * 실행 이력을 갱신합니다.
     *
     * @param  AnalyticsArchiveRun  $run  대상 실행 이력
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return bool 갱신 성공 여부
     */
    public function update(AnalyticsArchiveRun $run, array $attributes): bool
    {
        return $run->fill($attributes)->save();
    }

    /**
     * ID 로 실행 이력을 조회합니다.
     *
     * @param  int  $id  실행 이력 ID
     * @return AnalyticsArchiveRun|null 실행 이력 (없으면 null)
     */
    public function findById(int $id): ?AnalyticsArchiveRun
    {
        return AnalyticsArchiveRun::query()->find($id);
    }

    /**
     * 종류별 마지막 성공 실행을 조회합니다.
     *
     * @param  string  $runType  실행 종류
     * @return AnalyticsArchiveRun|null 마지막 성공 실행 (없으면 null)
     */
    public function findLastSuccessful(string $runType): ?AnalyticsArchiveRun
    {
        return AnalyticsArchiveRun::query()
            ->where('run_type', $runType)
            ->where('status', ArchiveRunStatus::Success->value)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 실행 이력을 페이지네이션하여 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 조건 (run_type, status)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션된 실행 이력
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // error 는 text 라 목록에서 통째로 읽으면 오버플로 페이지 비용이 붙는다.
        // 목록은 실패 사실만 보여 주고 전문은 상세 조회가 읽는다.
        return AnalyticsArchiveRun::query()
            ->when(! empty($filters['run_type']), fn (Builder $q) => $q->where('run_type', $filters['run_type']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage, [
                'id', 'run_type', 'period_start', 'period_end', 'trigger', 'status',
                'processed_visits', 'processed_actions', 'chunks', 'progress_percent',
                'started_at', 'finished_at', 'triggered_by',
            ]);
    }

    /**
     * 보존기간이 지난 실행 이력을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 이력이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsArchiveRun::query()
                ->where('started_at', '<', $before)
                ->limit($chunkSize)
                ->delete();

            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 기준 시각보다 오래된 집계 실행 이력 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int
    {
        return AnalyticsArchiveRun::query()->where('started_at', '<', $before)->count();
    }

    /**
     * 지금 돌고 있는 집계 실행을 찾습니다.
     *
     * @param  Carbon|null  $startedSince  이 시각 이후에 시작한 행만 본다(그 전의 「실행 중」은 멈춘 행으로 친다). null 이면 제한 없음
     * @return AnalyticsArchiveRun|null 실행 중인 이력 (없으면 null)
     */
    public function findRunning(?Carbon $startedSince = null): ?AnalyticsArchiveRun
    {
        return AnalyticsArchiveRun::query()
            ->where('status', ArchiveRunStatus::Running->value)
            ->when($startedSince !== null, fn (Builder $query) => $query->where('started_at', '>=', $startedSince))
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * 주어진 시각 이전에 시작해 아직 「실행 중」으로 남은 행을 찾습니다.
     *
     * @param  Carbon  $before  이 시각보다 앞서 시작한 행
     * @return Collection<int, AnalyticsArchiveRun> 멈춘 것으로 볼 실행 이력
     */
    public function getRunningStartedBefore(Carbon $before): Collection
    {
        return AnalyticsArchiveRun::query()
            ->where('status', ArchiveRunStatus::Running->value)
            ->where('started_at', '<', $before)
            ->orderBy('id')
            // 멈춘 행은 워커가 죽은 횟수만큼만 생긴다 — 한 번에 다 못 닫아도 매시 정리가 이어 닫는다.
            ->limit(self::RUNNING_LOOKUP_LIMIT)
            ->get();
    }

    /**
     * 한 잡이 맡은 「실행 중」 행을 찾습니다 — 같은 유형·같은 요청자로 그 시각 이후에 시작한 행.
     *
     * 기간을 주면 그 기간 안의 날짜 행만 고릅니다. 같은 관리자가 다른 기간을 따로 재집계하는
     * 중이면(워커 둘) 그 잡의 행까지 닫지 않도록 합니다.
     *
     * @param  string  $runType  실행 유형
     * @param  Carbon  $since  잡을 큐에 올린 시각
     * @param  int|null  $triggeredBy  요청한 관리자 ID (예약 실행이면 null)
     * @param  array{0: Carbon, 1: Carbon}|null  $period  잡이 맡은 기간 [시작(포함), 끝(미포함)] (UTC)
     * @return Collection<int, AnalyticsArchiveRun> 실행 중인 이력
     */
    public function getRunningOfTypeSince(string $runType, Carbon $since, ?int $triggeredBy, ?array $period = null): Collection
    {
        return AnalyticsArchiveRun::query()
            ->where('status', ArchiveRunStatus::Running->value)
            ->where('run_type', $runType)
            ->where('started_at', '>=', $since)
            ->when($period !== null, fn (Builder $query) => $query
                ->where('period_start', '>=', $period[0])
                ->where('period_start', '<', $period[1]))
            ->when(
                $triggeredBy === null,
                fn (Builder $query) => $query->whereNull('triggered_by'),
                fn (Builder $query) => $query->where('triggered_by', $triggeredBy),
            )
            ->orderBy('id')
            // 잡 하나가 여는 실행 중 행은 많아야 하루치 하나다.
            ->limit(self::RUNNING_LOOKUP_LIMIT)
            ->get();
    }
}
