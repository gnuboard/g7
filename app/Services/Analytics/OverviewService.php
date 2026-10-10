<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\VisitorType;
use Illuminate\Support\Carbon;

/**
 * 개요 지표
 *
 * 관리자가 통계 화면을 열었을 때 가장 먼저 보는 네 숫자 — 오늘·어제·최대·전체 —
 * 를 만듭니다. 그누보드5 의 접속자집계가 보여 주던 것과 같은 정의입니다.
 *
 * 오늘 숫자만 집계 표가 아니라 원장에서 직접 셉니다. 집계는 한 시간에 한 번 도는데,
 * 「오늘 몇 명이 왔나」가 한 시간 늦으면 운영자는 그 화면을 믿지 않습니다.
 */
class OverviewService
{
    /**
     * 오늘 숫자를 다시 세기까지 기다리는 시간 (초)
     *
     * 원장을 매번 세면 방문이 많은 사이트에서 화면을 열 때마다 큰 질의가 돈다.
     */
    private const TODAY_CACHE_SECONDS = 60;

    /**
     * 개요 조회기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  StatsRepositoryInterface  $stats  집계 저장소
     * @param  CacheInterface  $cache  오늘 숫자 캐시
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly StatsRepositoryInterface $stats,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 오늘·어제·최대·전체 방문자 수를 구합니다.
     *
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return array{today: int, yesterday: int, max: array{value: int, date: string|null}, total: int, change_pct: float|null}
     *                                                                                                                          개요 지표
     */
    public function kpi(array $visitorTypes = []): array
    {
        $types = $visitorTypes !== [] ? $visitorTypes : $this->humanTypes();
        $today = AnalyticsClock::today();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        $todayCount = $this->todayVisitors($today, $types);
        $yesterdayCount = (int) $this->stats->daily($yesterday, $yesterday, $types)->sum('visitors');
        $max = $this->stats->maxDailyVisitors($types);

        // 전체는 집계 합 + 오늘이다. 오늘치 집계 행은 매시 다시 쓰이므로 그 값을 함께
        // 더하면 오늘이 두 번 세어진다.
        $total = $this->stats->sumDailyVisitors($types, $today) + $todayCount;

        return [
            'today' => $todayCount,
            'yesterday' => $yesterdayCount,
            'max' => [
                'value' => $max !== null ? (int) $max->visitors : 0,
                // 날짜 컬럼이 캐스트되면 문자열화에 시각이 붙는다 — 화면에는 날짜만 보여야 한다.
                'date' => $max === null ? null : ($max->date instanceof \DateTimeInterface
                    ? $max->date->format('Y-m-d')
                    : substr((string) $max->date, 0, 10)),
            ],
            'total' => $total,
            'change_pct' => $this->changePercent($yesterdayCount, $todayCount),
        ];
    }

    /**
     * 오늘 순방문자 수를 구합니다 (짧게 캐시).
     *
     * @param  string  $date  오늘 일자 (Y-m-d)
     * @param  array<int, string>  $types  포함할 방문자 유형
     * @return int 순방문자 수
     */
    private function todayVisitors(string $date, array $types): int
    {
        $key = 'analytics:kpi:today:'.$date.':'.implode(',', $types);

        return (int) $this->cache->remember(
            $key,
            fn (): int => $this->visits->uniqueVisitorsOnDate($date, $types),
            self::TODAY_CACHE_SECONDS,
        );
    }

    /**
     * 증감률을 구합니다.
     *
     * 직전 값이 0 이면 비율이 성립하지 않으므로 빈 값을 돌려줍니다.
     *
     * @param  int  $previous  직전 값
     * @param  int  $current  이번 값
     * @return float|null 증감률 (%)
     */
    private function changePercent(int $previous, int $current): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * 사람 방문자 유형 목록을 돌려줍니다.
     *
     * @return array<int, string> 유형 목록 (봇 제외)
     */
    private function humanTypes(): array
    {
        return [VisitorType::Guest->value, VisitorType::Member->value, VisitorType::Admin->value];
    }

    /**
     * 공개 요약을 만듭니다 (사용자 화면 위젯용).
     *
     * @param  AnalyticsConfig  $config  설정
     * @return array{today: int, yesterday: int, max: int, total: int} 요약 지표
     */
    public function publicSummary(AnalyticsConfig $config): array
    {
        $kpi = $this->kpi($this->humanTypes());

        return [
            'today' => $kpi['today'],
            'yesterday' => $kpi['yesterday'],
            'max' => $kpi['max']['value'],
            'total' => $kpi['total'],
        ];
    }
}
