<?php

namespace App\Repositories;

use App\Contracts\Repositories\JobQueueRepositoryInterface;
use App\Models\FailedJob;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Search\KeywordSearch;
use App\Support\Query\BoundedPage;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 큐 대기(jobs)·실패(failed_jobs) 표 조회 Repository
 *
 * 두 표 모두 운영 중에 계속 쌓일 수 있어 총 건수는 상한까지만 센다(BoundedPaginator).
 * 검색어는 payload JSON 원문에 대해 LIKE 로 찾는다 — 큐가 payload 를 `JSON_UNESCAPED_UNICODE`
 * 로 인코딩하므로 검색어도 같은 방식으로 인코딩해 `App\\Jobs\\…` 같은 이스케이프 차이를 맞춘다.
 */
class JobQueueRepository implements JobQueueRepositoryInterface
{
    use FiltersByDateRange;

    /** 목록에 싣는 실패 예외 앞부분 길이(자) — 상세는 단건 조회가 전문을 준다 */
    public const EXCEPTION_PREVIEW_LENGTH = 500;

    /**
     * {@inheritDoc}
     */
    public function paginatePending(?string $database, string $table, array $filters, int $perPage): BoundedPage
    {
        $query = DB::connection($database)->table($table);

        if (! empty($filters['queue']) && $filters['queue'] !== 'all') {
            $query->where('queue', $filters['queue']);
        }

        $this->applyPayloadSearch($query, $filters, ['all', 'job', 'uuid']);

        // payload 는 표시 이름·작업 클래스·예약 작업 ID 를 꺼내는 원천이라 목록에도 필요하다.
        // 정렬 열이 같으면 기본키로 전순서를 보장한다(페이지 경계 중복·누락 방지). 지정이 없으면 최근 등록순.
        $direction = self::sortDirection($filters);
        $sortBy = $filters['sort_by'] ?? null;
        if (is_string($sortBy) && in_array($sortBy, self::PENDING_SORT_COLUMNS, true)) {
            $query->orderBy($sortBy, $direction);
        }
        $query->orderBy('id', $direction);

        return BoundedPaginator::paginate(
            $query,
            perPage: $perPage,
            resultCap: PaginationLimits::resultCap('admin.queue.pending'),
            columns: ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function countPending(?string $database, string $table): int
    {
        try {
            if (! Schema::connection($database)->hasTable($table)) {
                return 0;
            }

            return (int) DB::connection($database)->table($table)->count();
        } catch (Throwable) {
            // 상태 요약은 큐 표를 못 읽어도 깨지지 않는다 — 이 화면을 여는 이유가 대개 「뭔가 잘못됐다」이다.
            return 0;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function paginateFailed(array $filters, int $perPage): BoundedPage
    {
        $query = FailedJob::query();

        if (! empty($filters['queue']) && $filters['queue'] !== 'all') {
            $query->where('queue', $filters['queue']);
        }

        if (! empty($filters['connection']) && $filters['connection'] !== 'all') {
            $query->where('connection', $filters['connection']);
        }

        $this->applyViewerDateRangeFilter(
            $query,
            'failed_at',
            $filters['failed_start_date'] ?? null,
            $filters['failed_end_date'] ?? null,
        );

        $keyword = trim((string) ($filters['search_keyword'] ?? ''));

        if ($keyword !== '') {
            $field = $filters['search_field'] ?? 'all';
            $payloadLike = '%'.KeywordSearch::escapeLikeWildcards(self::jsonFragment($keyword)).'%';
            $plainLike = '%'.KeywordSearch::escapeLikeWildcards($keyword).'%';

            $query->where(function ($q) use ($field, $payloadLike, $plainLike) {
                if ($field === 'all' || $field === 'job') {
                    $q->orWhere('payload', 'like', $payloadLike);
                }
                if ($field === 'all' || $field === 'error') {
                    $q->orWhere('exception', 'like', $plainLike);
                }
                if ($field === 'all' || $field === 'uuid') {
                    $q->orWhere('uuid', 'like', $plainLike);
                }
            });
        }

        // failed_at 은 동률이 가능하다 — 기본키를 덧붙여 전순서를 보장한다(페이지 경계 중복·누락 방지).
        // 정렬 지정이 없으면 최근 실패순이다. 예외 전문(스택 트레이스)은 행마다 수십 KB 라 목록에는 앞부분만 싣는다.
        $direction = self::sortDirection($filters);
        $sortBy = $filters['sort_by'] ?? 'failed_at';
        if (! is_string($sortBy) || ! in_array($sortBy, self::FAILED_SORT_COLUMNS, true)) {
            $sortBy = 'failed_at';
        }
        if ($sortBy !== 'id') {
            $query->orderBy($sortBy, $direction);
        }
        $query->orderBy('id', $direction);

        return BoundedPaginator::paginate(
            $query,
            perPage: $perPage,
            resultCap: PaginationLimits::resultCap('admin.queue.failed'),
            columns: [
                'id', 'uuid', 'connection', 'queue', 'payload', 'failed_at',
                DB::raw('SUBSTRING(exception, 1, '.self::EXCEPTION_PREVIEW_LENGTH.') as exception'),
            ],
        );
    }

    /**
     * 검증된 정렬 순서를 쿼리 방향으로 바꿉니다(지정이 없거나 모르는 값이면 desc).
     *
     * @param  array<string, mixed>  $filters  검증된 필터
     * @return string `asc` 또는 `desc`
     */
    private static function sortDirection(array $filters): string
    {
        return ($filters['sort_order'] ?? null) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * {@inheritDoc}
     */
    public function findFailedByUuid(string $uuid): ?FailedJob
    {
        return FailedJob::query()->where('uuid', $uuid)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function countFailed(): int
    {
        try {
            return (int) FailedJob::query()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 대기 작업 payload 검색 조건을 적용합니다.
     *
     * @param  BuilderContract  $query  대상 쿼리
     * @param  array<string, mixed>  $filters  필터 (search_field·search_keyword)
     * @param  list<string>  $fields  허용 검색 대상
     * @return void 반환값 없음
     */
    private function applyPayloadSearch(BuilderContract $query, array $filters, array $fields): void
    {
        $keyword = trim((string) ($filters['search_keyword'] ?? ''));
        $field = $filters['search_field'] ?? 'all';

        if ($keyword === '' || ! in_array($field, $fields, true)) {
            return;
        }

        // UUID 는 payload 의 "uuid":"…" 값으로, 작업 이름·전체는 payload 원문으로 찾는다.
        $fragment = KeywordSearch::escapeLikeWildcards(self::jsonFragment($keyword));
        $pattern = $field === 'uuid' ? '%"uuid":"'.$fragment.'%' : '%'.$fragment.'%';

        $query->where('payload', 'like', $pattern);
    }

    /**
     * 검색어를 payload JSON 안에 나타나는 모양(따옴표 제외)으로 인코딩합니다.
     *
     * @param  string  $keyword  검색어
     * @return string JSON 인코딩한 문자열의 따옴표 안쪽
     */
    private static function jsonFragment(string $keyword): string
    {
        $encoded = json_encode($keyword, JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? substr($encoded, 1, -1) : $keyword;
    }
}
