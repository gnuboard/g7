<?php

namespace App\Contracts\Repositories;

use Carbon\Carbon;

/**
 * SEO 캐시 통계 Repository 인터페이스
 */
interface SeoCacheStatRepositoryInterface
{
    /**
     * 캐시 통계 레코드를 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  기록할 속성 (url, locale, layout_name, module_identifier, type, response_time_ms)
     */
    public function record(array $attributes): void;

    /**
     * 전체 캐시 통계를 집계합니다.
     *
     * @param  Carbon|null  $since  집계 시작 시점 (null 이면 전체 기간)
     * @return array{total: int, hits: int, misses: int, avg_response_time_ms: float|null} 집계 결과
     */
    public function aggregate(?Carbon $since = null): array;

    /**
     * 지정한 컬럼으로 그룹화하여 캐시 통계를 집계합니다.
     *
     * @param  string  $groupBy  그룹 기준 컬럼 (layout_name | module_identifier)
     * @param  Carbon|null  $since  집계 시작 시점 (null 이면 전체 기간)
     * @return array<int, array{group: string|null, total: int, hits: int, misses: int, avg_response_time_ms: float|null}> 그룹별 집계 결과
     */
    public function aggregateGrouped(string $groupBy, ?Carbon $since = null): array;

    /**
     * 기준 시점보다 오래된 통계 레코드를 삭제합니다.
     *
     * @param  Carbon  $cutoff  기준 시점
     * @return int 삭제된 레코드 수
     */
    public function deleteOlderThan(Carbon $cutoff): int;
}
