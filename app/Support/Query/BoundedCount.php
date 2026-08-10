<?php

namespace App\Support\Query;

use App\Contracts\Pagination\BoundedTotalAware;
use App\Enums\TotalRelation;

/**
 * 상한을 건 총 건수 집계 결과
 *
 * 목록을 조회하지 않고 건수만 필요한 자리(탭 배지, 요약 수치)를 위한 값 객체다.
 *
 * `int` 하나만 돌려주면 상한에 걸려 잘린 값과 정확히 센 값이 구분되지 않는다.
 * 잘린 10,000 이 "정확히 10,000 건" 으로 화면에 나가는 것은 오류로 드러나지 않고
 * 그냥 틀린 숫자로만 보이므로, 건수와 정확도를 한 덩어리로 옮긴다.
 *
 * @see BoundedPaginator::count() 이 값을 만드는 유일한 자리
 */
final class BoundedCount implements BoundedTotalAware
{
    /**
     * @param  int  $total  총 건수 (정확도가 AtLeast 면 "이 값 이상")
     * @param  TotalRelation  $relation  총 건수 정확도
     * @param  int|null  $resultCap  집계에 적용된 상한 (null 이면 무제한)
     */
    public function __construct(
        public readonly int $total,
        private readonly TotalRelation $relation,
        private readonly ?int $resultCap,
    ) {}

    /**
     * 총 건수 정확도를 반환합니다.
     *
     * @return TotalRelation 정확도
     */
    public function totalRelation(): TotalRelation
    {
        return $this->relation;
    }

    /**
     * 집계에 적용된 상한을 반환합니다.
     *
     * @return int|null 상한 (무제한이면 null)
     */
    public function resultCap(): ?int
    {
        return $this->resultCap;
    }

    /**
     * 상한에 걸려 잘렸는지 여부를 반환합니다.
     *
     * @return bool 잘렸으면 true
     */
    public function isTruncated(): bool
    {
        return ! $this->relation->isExact();
    }

    /**
     * 총 건수를 반환합니다.
     *
     * @return int 총 건수
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * 응답에 실을 정확도 필드 묶음을 반환합니다.
     *
     * 배지·요약 자리마다 같은 키를 손으로 조립하면 한 군데만 빠져도
     * 그 화면에서만 잘린 값이 정확한 것처럼 나간다.
     *
     * @return array{total: int, total_relation: string, total_is_exact: bool, result_cap: int|null}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'total_relation' => $this->relation->value,
            'total_is_exact' => $this->relation->isExact(),
            'result_cap' => $this->resultCap,
        ];
    }
}
