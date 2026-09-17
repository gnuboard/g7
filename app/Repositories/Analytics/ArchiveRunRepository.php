<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Models\Analytics\AnalyticsArchiveRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * 배치 실행 이력 Repository
 */
class ArchiveRunRepository implements ArchiveRunRepositoryInterface
{
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
}
