<?php

namespace App\Seo;

use App\Contracts\Repositories\SeoCacheStatRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SEO 캐시 통계 서비스
 *
 * 캐시 히트/미스를 기록하고 통계 데이터를 제공합니다.
 * 데이터 접근은 Repository 인터페이스에 위임합니다.
 */
class SeoCacheStatsService
{
    /**
     * SeoCacheStatsService 생성자
     *
     * @param  SeoCacheStatRepositoryInterface  $statRepository  SEO 캐시 통계 Repository
     */
    public function __construct(
        private readonly SeoCacheStatRepositoryInterface $statRepository,
    ) {}

    /**
     * 캐시 히트를 기록합니다.
     *
     * @param  string  $url  요청 URL
     * @param  string  $locale  로케일
     * @param  string|null  $layoutName  레이아웃명
     * @param  string|null  $moduleIdentifier  모듈 식별자
     */
    public function recordHit(
        string $url,
        string $locale,
        ?string $layoutName = null,
        ?string $moduleIdentifier = null
    ): void {
        $this->record([
            'url' => $url,
            'locale' => $locale,
            'layout_name' => $layoutName,
            'module_identifier' => $moduleIdentifier,
            'type' => 'hit',
        ], '[SEO] 캐시 히트 기록 실패');
    }

    /**
     * 캐시 미스를 기록합니다.
     *
     * @param  string  $url  요청 URL
     * @param  string  $locale  로케일
     * @param  string|null  $layoutName  레이아웃명
     * @param  string|null  $moduleIdentifier  모듈 식별자
     * @param  int|null  $responseTimeMs  렌더링 소요 시간 (ms)
     */
    public function recordMiss(
        string $url,
        string $locale,
        ?string $layoutName = null,
        ?string $moduleIdentifier = null,
        ?int $responseTimeMs = null
    ): void {
        $this->record([
            'url' => $url,
            'locale' => $locale,
            'layout_name' => $layoutName,
            'module_identifier' => $moduleIdentifier,
            'type' => 'miss',
            'response_time_ms' => $responseTimeMs,
        ], '[SEO] 캐시 미스 기록 실패');
    }

    /**
     * 캐시 통계를 조회합니다.
     *
     * @param  Carbon|null  $since  조회 시작 시점 (null이면 전체 기간)
     * @return array{total_entries: int, hits: int, misses: int, hit_rate: float, avg_response_time_ms: float|null} 캐시 통계
     */
    public function getStats(?Carbon $since = null): array
    {
        $stats = $this->statRepository->aggregate($since);

        return [
            'total_entries' => $stats['total'],
            'hits' => $stats['hits'],
            'misses' => $stats['misses'],
            'hit_rate' => $this->hitRate($stats['total'], $stats['hits']),
            'avg_response_time_ms' => $this->roundOrNull($stats['avg_response_time_ms']),
        ];
    }

    /**
     * 레이아웃별 캐시 통계를 조회합니다.
     *
     * @param  Carbon|null  $since  조회 시작 시점 (null이면 전체 기간)
     * @return array<int, array{layout_name: string|null, total: int, hits: int, misses: int, hit_rate: float, avg_response_time_ms: float|null}> 레이아웃별 캐시 통계
     */
    public function getStatsByLayout(?Carbon $since = null): array
    {
        return array_map(
            fn (array $row): array => ['layout_name' => $row['group']] + $this->formatGroupRow($row),
            $this->statRepository->aggregateGrouped('layout_name', $since),
        );
    }

    /**
     * 모듈별 캐시 통계를 조회합니다.
     *
     * @param  Carbon|null  $since  조회 시작 시점 (null이면 전체 기간)
     * @return array<int, array{module_identifier: string|null, total: int, hits: int, misses: int, hit_rate: float, avg_response_time_ms: float|null}> 모듈별 캐시 통계
     */
    public function getStatsByModule(?Carbon $since = null): array
    {
        return array_map(
            fn (array $row): array => ['module_identifier' => $row['group']] + $this->formatGroupRow($row),
            $this->statRepository->aggregateGrouped('module_identifier', $since),
        );
    }

    /**
     * 오래된 통계 레코드를 삭제합니다.
     *
     * @param  int  $daysToKeep  보존 기간 (일, 기본값: 30)
     * @return int 삭제된 레코드 수
     */
    public function cleanup(int $daysToKeep = 30): int
    {
        // 보존 기간 하한(1일)은 이 계층이 소유한다 — 파기는 되돌릴 수 없으므로
        // 호출자마다 다시 막지 않고 실제로 지우는 경로에서 한 번 막는다.
        $daysToKeep = max(1, $daysToKeep);

        $deleted = $this->statRepository->deleteOlderThan(Carbon::now()->subDays($daysToKeep));

        Log::info('[SEO] 캐시 통계 정리 완료', [
            'deleted' => $deleted,
            'days_kept' => $daysToKeep,
        ]);

        return $deleted;
    }

    /**
     * 통계 레코드를 기록하고, 실패해도 요청 흐름을 막지 않습니다.
     *
     * @param  array<string, mixed>  $attributes  기록할 속성
     * @param  string  $failureMessage  실패 시 로그 메시지
     */
    private function record(array $attributes, string $failureMessage): void
    {
        try {
            $this->statRepository->record($attributes);
        } catch (\Exception $e) {
            Log::warning($failureMessage, [
                'url' => $attributes['url'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 그룹 집계 행을 응답 형식으로 변환합니다.
     *
     * @param  array{total: int, hits: int, misses: int, avg_response_time_ms: float|null}  $row  집계 행
     * @return array{total: int, hits: int, misses: int, hit_rate: float, avg_response_time_ms: float|null} 변환된 행
     */
    private function formatGroupRow(array $row): array
    {
        return [
            'total' => $row['total'],
            'hits' => $row['hits'],
            'misses' => $row['misses'],
            'hit_rate' => $this->hitRate($row['total'], $row['hits']),
            'avg_response_time_ms' => $this->roundOrNull($row['avg_response_time_ms']),
        ];
    }

    /**
     * 히트율(%)을 계산합니다.
     *
     * @param  int  $total  전체 건수
     * @param  int  $hits  히트 건수
     * @return float 히트율 (소수점 2자리)
     */
    private function hitRate(int $total, int $hits): float
    {
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
    }

    /**
     * 값이 있으면 소수점 2자리로 반올림합니다.
     *
     * @param  float|null  $value  원본 값
     * @return float|null 반올림된 값 (null 이면 null)
     */
    private function roundOrNull(?float $value): ?float
    {
        return $value !== null ? round($value, 2) : null;
    }
}
