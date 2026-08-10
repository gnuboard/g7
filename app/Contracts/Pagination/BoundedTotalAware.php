<?php

namespace App\Contracts\Pagination;

use App\Enums\TotalRelation;

/**
 * 총 건수 정확도를 스스로 밝히는 페이지 결과 계약
 *
 * 표준 `LengthAwarePaginator` 는 `total()` 이 항상 정확하다고 가정한다. 대용량 목록에서
 * 상한을 걸고 센 결과는 그 가정을 만족하지 못하므로, 정확도를 함께 실어 나른다.
 *
 * 이 계약을 구현한 페이지는 `BaseApiCollection::paginationMeta()` 가 자동으로 알아보고
 * `total_relation` / `total_is_exact` / `result_cap` 메타를 응답에 덧붙인다. 컬렉션 쪽
 * 코드 변경은 필요 없다.
 */
interface BoundedTotalAware
{
    /**
     * 총 건수와 실제 매칭 건수의 관계를 반환합니다.
     *
     * @return TotalRelation 정확(Exact) 또는 하한(AtLeast)
     */
    public function totalRelation(): TotalRelation;

    /**
     * 총 건수 집계에 적용된 상한을 반환합니다.
     *
     * @return int|null 상한 (무제한이면 null)
     */
    public function resultCap(): ?int;

    /**
     * 총 건수가 상한에 걸려 잘렸는지 여부를 반환합니다.
     *
     * @return bool 잘렸으면 true (= totalRelation 이 AtLeast)
     */
    public function isTruncated(): bool;
}
