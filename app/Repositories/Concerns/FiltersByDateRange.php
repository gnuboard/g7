<?php

namespace App\Repositories\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * 날짜 필터를 인덱스가 살아 있는 범위 조건으로 적용하는 Trait
 *
 * `whereDate('created_at', ...)` 는 컬럼에 `DATE()` 를 씌운다. 함수가 씌워진 컬럼은
 * 인덱스를 탈 수 없으므로, 그 조건 하나 때문에 목록 전체가 풀스캔이 된다. 같은 결과를
 * 내는 `>= 하루 시작` / `<= 하루 끝` 범위 조건으로 바꾸면 인덱스가 그대로 쓰인다.
 *
 * 경계 처리를 각 저장소가 따로 적으면 한쪽이 종료일 하루를 통째로 빠뜨리기 쉽다
 * (`<= '2026-08-02'` 는 그날 00:00:00 까지만 포함한다). 경계는 이 Trait 한 곳에서만 정한다.
 */
trait FiltersByDateRange
{
    /**
     * 기간(시작일~종료일) 필터를 범위 조건으로 적용합니다.
     *
     * 종료일은 그날 23:59:59.999999 까지 포함하므로 `whereDate(..., '<=', ...)` 와
     * 같은 결과를 냅니다.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  대상 쿼리
     * @param  string  $column  날짜 컬럼 (테이블 한정자 포함 가능)
     * @param  string|null  $startDate  시작일 (빈 값이면 미적용)
     * @param  string|null  $endDate  종료일 (빈 값이면 미적용)
     */
    protected function applyDateRangeFilter($query, string $column, ?string $startDate, ?string $endDate): void
    {
        if (! empty($startDate)) {
            $query->where($column, '>=', CarbonImmutable::parse($startDate)->startOfDay());
        }

        if (! empty($endDate)) {
            $query->where($column, '<=', CarbonImmutable::parse($endDate)->endOfDay());
        }
    }

    /**
     * 특정 하루에 해당하는 행만 남기는 필터를 범위 조건으로 적용합니다.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  대상 쿼리
     * @param  string  $column  날짜 컬럼 (테이블 한정자 포함 가능)
     * @param  \DateTimeInterface|string  $day  대상 날짜
     */
    protected function applyDayFilter($query, string $column, \DateTimeInterface|string $day): void
    {
        $target = CarbonImmutable::parse($day instanceof \DateTimeInterface ? $day->format('Y-m-d H:i:s') : $day);

        $query->whereBetween($column, [$target->startOfDay(), $target->endOfDay()]);
    }

    /**
     * 특정 연·월에 해당하는 행만 남기는 필터를 범위 조건으로 적용합니다.
     *
     * `whereYear` + `whereMonth` 조합은 컬럼에 함수를 두 번 씌워 인덱스를 완전히 막습니다.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  대상 쿼리
     * @param  string  $column  날짜 컬럼 (테이블 한정자 포함 가능)
     * @param  int  $year  연도
     * @param  int  $month  월 (1~12)
     */
    protected function applyMonthFilter($query, string $column, int $year, int $month): void
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        $query->whereBetween($column, [$start, $start->endOfMonth()]);
    }
}
