<?php

namespace App\Seo;

use App\Models\SeoCacheStat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEO 캐시 통계 서비스
 *
 * 캐시 히트/미스를 기록하고 통계 데이터를 제공합니다.
 */
class SeoCacheStatsService
{
    /**
     * 캐시 히트를 기록합니다.
     *
     * @param string $url 요청 URL
     * @param string $locale 로케일
     * @param string|null $layoutName 레이아웃명
     * @param string|null $moduleIdentifier 모듈 식별자
     * @return void
     */
    public function recordHit(
        string $url,
        string $locale,
        ?string $layoutName = null,
        ?string $moduleIdentifier = null
    ): void {
        try {
            SeoCacheStat::create([
                'url' => $url,
                'locale' => $locale,
                'layout_name' => $layoutName,
                'module_identifier' => $moduleIdentifier,
                'type' => 'hit',
            ]);
        } catch (\Exception $e) {
            Log::warning('[SEO] 캐시 히트 기록 실패', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 캐시 미스를 기록합니다.
     *
     * @param string $url 요청 URL
     * @param string $locale 로케일
     * @param string|null $layoutName 레이아웃명
     * @param string|null $moduleIdentifier 모듈 식별자
     * @param int|null $responseTimeMs 렌더링 소요 시간 (ms)
     * @return void
     */
    public function recordMiss(
        string $url,
        string $locale,
        ?string $layoutName = null,
        ?string $moduleIdentifier = null,
        ?int $responseTimeMs = null
    ): void {
        try {
            SeoCacheStat::create([
                'url' => $url,
                'locale' => $locale,
                'layout_name' => $layoutName,
                'module_identifier' => $moduleIdentifier,
                'type' => 'miss',
                'response_time_ms' => $responseTimeMs,
            ]);
        } catch (\Exception $e) {
            Log::warning('[SEO] 캐시 미스 기록 실패', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 캐시 통계를 조회합니다.
     *
     * @param Carbon|null $since 조회 시작 시점 (null이면 전체 기간)
     * @return array{total_entries: int, hits: int, misses: int, hit_rate: float, avg_response_time_ms: float|null}
     */
    public function getStats(?Carbon $since = null): array
    {
        $query = SeoCacheStat::query();

        if ($since) {
            $query->since($since);
        }

        $total = $query->count();
        $hits = (clone $query)->hits()->count();
        $misses = (clone $query)->misses()->count();
        $avgResponseTime = (clone $query)->misses()
            ->whereNotNull('response_time_ms')
            ->avg('response_time_ms');

        return [
            'total_entries' => $total,
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => $total > 0 ? round(($hits / $total) * 100, 2) : 0.0,
            'avg_response_time_ms' => $avgResponseTime !== null ? round((float) $avgResponseTime, 2) : null,
        ];
    }

    /**
     * 레이아웃별 캐시 통계를 조회합니다.
     *
     * @param Carbon|null $since 조회 시작 시점 (null이면 전체 기간)
     * @return array<int, array{layout_name: string|null, total: int, hits: int, misses: int, hit_rate: float, avg_response_time_ms: float|null}>
     */
    public function getStatsByLayout(?Carbon $since = null): array
    {
        $query = SeoCacheStat::query()
            ->select(
                'layout_name',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN type = 'hit' THEN 1 ELSE 0 END) as hits"),
                DB::raw("SUM(CASE WHEN type = 'miss' THEN 1 ELSE 0 END) as misses"),
                DB::raw("AVG(CASE WHEN type = 'miss' AND response_time_ms IS NOT NULL THEN response_time_ms ELSE NULL END) as avg_response_time_ms")
            )
            ->groupBy('layout_name');

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->get()->map(function ($row) {
            $total = (int) $row->total;
            $hits = (int) $row->hits;

            return [
                'layout_name' => $row->layout_name,
                'total' => $total,
                'hits' => $hits,
                'misses' => (int) $row->misses,
                'hit_rate' => $total > 0 ? round(($hits / $total) * 100, 2) : 0.0,
                'avg_response_time_ms' => $row->avg_response_time_ms !== null
                    ? round((float) $row->avg_response_time_ms, 2)
                    : null,
            ];
        })->toArray();
    }

    /**
     * 모듈별 캐시 통계를 조회합니다.
     *
     * @param Carbon|null $since 조회 시작 시점 (null이면 전체 기간)
     * @return array<int, array{module_identifier: string|null, total: int, hits: int, misses: int, hit_rate: float, avg_response_time_ms: float|null}>
     */
    public function getStatsByModule(?Carbon $since = null): array
    {
        $query = SeoCacheStat::query()
            ->select(
                'module_identifier',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN type = 'hit' THEN 1 ELSE 0 END) as hits"),
                DB::raw("SUM(CASE WHEN type = 'miss' THEN 1 ELSE 0 END) as misses"),
                DB::raw("AVG(CASE WHEN type = 'miss' AND response_time_ms IS NOT NULL THEN response_time_ms ELSE NULL END) as avg_response_time_ms")
            )
            ->groupBy('module_identifier');

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->get()->map(function ($row) {
            $total = (int) $row->total;
            $hits = (int) $row->hits;

            return [
                'module_identifier' => $row->module_identifier,
                'total' => $total,
                'hits' => $hits,
                'misses' => (int) $row->misses,
                'hit_rate' => $total > 0 ? round(($hits / $total) * 100, 2) : 0.0,
                'avg_response_time_ms' => $row->avg_response_time_ms !== null
                    ? round((float) $row->avg_response_time_ms, 2)
                    : null,
            ];
        })->toArray();
    }

    /**
     * 오래된 통계 레코드를 삭제합니다.
     *
     * @param int $daysToKeep 보존 기간 (일, 기본값: 30)
     * @return int 삭제된 레코드 수
     */
    public function cleanup(int $daysToKeep = 30): int
    {
        $cutoff = Carbon::now()->subDays($daysToKeep);

        $deleted = SeoCacheStat::where('created_at', '<', $cutoff)->delete();

        Log::info('[SEO] 캐시 통계 정리 완료', [
            'deleted' => $deleted,
            'days_kept' => $daysToKeep,
        ]);

        return $deleted;
    }
}
