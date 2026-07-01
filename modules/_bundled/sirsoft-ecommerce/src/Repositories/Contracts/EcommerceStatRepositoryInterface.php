<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\EcommerceStat;

/**
 * 이커머스 일별 판매 집계 Repository 계약
 *
 * ecommerce_stats 테이블의 upsert / 범위 조회를 정의합니다.
 */
interface EcommerceStatRepositoryInterface
{
    /**
     * 특정 날짜의 집계 행을 upsert 합니다 (멱등).
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @param  int  $salesQuantity  판매 수량
     * @param  float  $salesAmount  상품 순매출
     * @param  array<string, int>  $statusCounts  상태별 판매 수량 (option_status 버킷)
     * @return EcommerceStat upsert 된 집계 행
     */
    public function upsertForDate(string $date, int $salesQuantity, float $salesAmount, array $statusCounts): EcommerceStat;

    /**
     * 날짜 범위(포함)의 집계 행을 날짜 오름차순으로 조회합니다.
     *
     * @param  string  $startDate  시작 날짜 (Y-m-d, 포함)
     * @param  string  $endDate  종료 날짜 (Y-m-d, 포함)
     * @return Collection<int, EcommerceStat> 날짜 오름차순 집계 행 컬렉션
     */
    public function getByDateRange(string $startDate, string $endDate): Collection;

    /**
     * 특정 날짜의 집계 행을 조회합니다.
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return EcommerceStat|null 집계 행 또는 null
     */
    public function findByDate(string $date): ?EcommerceStat;
}
