<?php

namespace App\Repositories;

use App\Contracts\Repositories\SeoCacheStatRepositoryInterface;
use App\Models\SeoCacheStat;
use App\Repositories\Concerns\DeletesInBatches;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SEO 캐시 통계 Repository
 *
 * SEO 캐시 히트/미스 통계의 데이터 접근 계층을 담당합니다.
 */
class SeoCacheStatRepository implements SeoCacheStatRepositoryInterface
{
    use DeletesInBatches;

    /**
     * 그룹 집계에 허용된 컬럼 목록
     *
     * @var array<int, string>
     */
    private const GROUPABLE_COLUMNS = ['layout_name', 'module_identifier'];

    /**
     * 캐시 통계 레코드를 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  기록할 속성
     */
    public function record(array $attributes): void
    {
        SeoCacheStat::create($attributes);
    }

    /**
     * 전체 캐시 통계를 집계합니다.
     *
     * @param  Carbon|null  $since  집계 시작 시점 (null 이면 전체 기간)
     * @return array{total: int, hits: int, misses: int, avg_response_time_ms: float|null} 집계 결과
     */
    public function aggregate(?Carbon $since = null): array
    {
        $row = $this->baseQuery($since)
            ->select($this->aggregateSelects())
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'hits' => (int) ($row->hits ?? 0),
            'misses' => (int) ($row->misses ?? 0),
            'avg_response_time_ms' => $row?->avg_response_time_ms !== null
                ? (float) $row->avg_response_time_ms
                : null,
        ];
    }

    /**
     * 지정한 컬럼으로 그룹화하여 캐시 통계를 집계합니다.
     *
     * @param  string  $groupBy  그룹 기준 컬럼 (layout_name | module_identifier)
     * @param  Carbon|null  $since  집계 시작 시점 (null 이면 전체 기간)
     * @return array<int, array{group: string|null, total: int, hits: int, misses: int, avg_response_time_ms: float|null}> 그룹별 집계 결과
     *
     * @throws \InvalidArgumentException 허용되지 않은 그룹 컬럼인 경우
     */
    public function aggregateGrouped(string $groupBy, ?Carbon $since = null): array
    {
        if (! in_array($groupBy, self::GROUPABLE_COLUMNS, true)) {
            throw new \InvalidArgumentException(
                __('exceptions.seo.sitemap_stat_group_column_unsupported', ['column' => $groupBy])
            );
        }

        return $this->baseQuery($since)
            ->select(array_merge([$groupBy], $this->aggregateSelects()))
            ->groupBy($groupBy)
            ->get()
            ->map(fn ($row): array => [
                'group' => $row->{$groupBy},
                'total' => (int) $row->total,
                'hits' => (int) $row->hits,
                'misses' => (int) $row->misses,
                'avg_response_time_ms' => $row->avg_response_time_ms !== null
                    ? (float) $row->avg_response_time_ms
                    : null,
            ])
            ->all();
    }

    /**
     * 기준 시점보다 오래된 통계 레코드를 삭제합니다.
     *
     * @param  Carbon  $cutoff  기준 시점
     * @return int 삭제된 레코드 수
     */
    public function deleteOlderThan(Carbon $cutoff): int
    {
        return $this->deleteInBatches(SeoCacheStat::where('created_at', '<', $cutoff));
    }

    /**
     * 기간 필터가 적용된 기본 쿼리를 반환합니다.
     *
     * @param  Carbon|null  $since  집계 시작 시점
     * @return Builder 쿼리 빌더
     */
    private function baseQuery(?Carbon $since): Builder
    {
        $query = SeoCacheStat::query();

        if ($since) {
            $query->since($since);
        }

        return $query;
    }

    /**
     * 집계 select 절을 반환합니다.
     *
     * hit/miss 는 단일 순회로 집계하기 위해 조건부 합계를 사용합니다.
     *
     * @return array<int, Expression> select 표현식 배열
     */
    private function aggregateSelects(): array
    {
        return [
            DB::raw('COUNT(*) as total'),
            DB::raw("SUM(CASE WHEN type = 'hit' THEN 1 ELSE 0 END) as hits"),
            DB::raw("SUM(CASE WHEN type = 'miss' THEN 1 ELSE 0 END) as misses"),
            DB::raw("AVG(CASE WHEN type = 'miss' AND response_time_ms IS NOT NULL THEN response_time_ms ELSE NULL END) as avg_response_time_ms"),
        ];
    }
}
