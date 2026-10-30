<?php

namespace App\Repositories;

use App\Contracts\Repositories\QueueJobLogRepositoryInterface;
use App\Models\QueueJobLog;
use App\Repositories\Concerns\DeletesInBatches;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Search\KeywordSearch;
use App\Support\Query\BoundedPage;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\PaginationLimits;
use Carbon\CarbonInterface;

/**
 * 큐 작업 처리 기록(queue_job_logs) Repository (#17)
 *
 * 처리 기록은 작업마다 1행씩 계속 쌓이는 원장이라 총 건수는 상한까지만 세고, 정리는 기본키 배치로 끊는다.
 */
class QueueJobLogRepository implements QueueJobLogRepositoryInterface
{
    use DeletesInBatches;
    use FiltersByDateRange;

    /** 목록 조회 컬럼 */
    private const LIST_COLUMNS = [
        'id', 'uuid', 'connection', 'queue', 'job_class', 'display_name', 'status', 'attempts',
        'started_at', 'finished_at', 'duration_ms', 'exception_class', 'exception_message', 'schedule_id', 'created_at',
    ];

    /**
     * {@inheritDoc}
     */
    public function paginate(array $filters, int $perPage): BoundedPage
    {
        $query = QueueJobLog::query();

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['queue']) && $filters['queue'] !== 'all') {
            $query->where('queue', $filters['queue']);
        }

        if (! empty($filters['connection']) && $filters['connection'] !== 'all') {
            $query->where('connection', $filters['connection']);
        }

        $this->applyViewerDateRangeFilter(
            $query,
            'created_at',
            $filters['created_start_date'] ?? null,
            $filters['created_end_date'] ?? null,
        );

        $keyword = trim((string) ($filters['search_keyword'] ?? ''));

        if ($keyword !== '') {
            $field = $filters['search_field'] ?? 'all';
            $like = '%'.KeywordSearch::escapeLikeWildcards($keyword).'%';

            $query->where(function ($q) use ($field, $like) {
                if ($field === 'all' || $field === 'job') {
                    $q->orWhere('display_name', 'like', $like)->orWhere('job_class', 'like', $like);
                }
                if ($field === 'all' || $field === 'error') {
                    $q->orWhere('exception_message', 'like', $like)->orWhere('exception_class', 'like', $like);
                }
                if ($field === 'all' || $field === 'uuid') {
                    $q->orWhere('uuid', 'like', $like);
                }
            });
        }

        // 정렬 지정이 없으면 최근 기록순(created_at)이다. 같은 값은 기본키로 순서를 정한다(페이지 경계 중복·누락 방지).
        $direction = ($filters['sort_order'] ?? null) === 'asc' ? 'asc' : 'desc';
        $sortBy = $filters['sort_by'] ?? null;
        $query->orderBy(is_string($sortBy) && in_array($sortBy, self::SORT_COLUMNS, true) ? $sortBy : 'created_at', $direction)
            ->orderBy('id', $direction);

        return BoundedPaginator::paginate(
            $query,
            perPage: $perPage,
            resultCap: PaginationLimits::resultCap('admin.queue.logs'),
            columns: self::LIST_COLUMNS,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?QueueJobLog
    {
        return QueueJobLog::query()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function latestAttemptsByUuid(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids, fn ($uuid) => is_string($uuid) && $uuid !== '')));
        if ($uuids === []) {
            return [];
        }

        // 재시도로 같은 UUID 의 기록이 여러 줄이면 마지막 줄(가장 큰 id)이 지금 실패 기록의 회차다
        $latestIds = QueueJobLog::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('uuid', $uuids)
            ->groupBy('uuid');

        return QueueJobLog::query()
            ->whereIn('id', $latestIds)
            ->pluck('attempts', 'uuid')
            ->map(fn ($attempts) => (int) $attempts)
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        QueueJobLog::query()->insert($rows);

        return count($rows);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteOlderThan(CarbonInterface $before): int
    {
        return $this->deleteInBatches(QueueJobLog::query()->where('created_at', '<', $before));
    }
}
