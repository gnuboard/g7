<?php

namespace App\Support\Query;

use App\Contracts\Pagination\BoundedTotalAware;
use App\Enums\TotalRelation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * 상한 집계 기반 페이지 결과
 *
 * 한 페이지의 조회 결과와 그 총 건수의 정확도를 함께 담는 불변 값이다. 생성 후 상태를
 * 바꾸는 메서드를 두지 않으며, 값은 전적으로 {@see BoundedPaginator} 가 채운다.
 *
 * 표준 `LengthAwarePaginator` 를 상속하는 이유는 재사용 때문이다. 기존 컬렉션·리소스·
 * 컨트롤러는 전부 페이지네이터 인터페이스에 맞춰져 있으므로, 별도 타입을 새로 정의하면
 * 소비 지점마다 언랩 코드가 생긴다. 상속하면 기존 컬렉션이 코드 변경 없이 그대로 받고,
 * {@see BoundedTotalAware} 구현 덕에 정확도 메타까지 자동으로 얻는다.
 *
 * 표준 페이지네이터와 다른 점은 셋뿐이다:
 * - `total()` 은 상한 이하일 때만 실제 건수다 (초과 시 상한값 = 하한 보증)
 * - `lastPage()` 는 총 건수가 부정확하면 null 이다 (마지막 페이지 점프 계산 불가)
 * - `hasMorePages()` 는 총 건수가 아니라 `per_page + 1` 실측으로 판정한다
 */
class BoundedPage extends LengthAwarePaginator implements BoundedTotalAware
{
    /**
     * 총 건수와 실제 매칭 건수의 관계
     */
    private TotalRelation $totalRelation;

    /**
     * 총 건수 집계에 적용된 상한 (무제한이면 null)
     */
    private ?int $resultCap;

    /**
     * 다음 페이지 존재 여부 (per_page + 1 실측 결과)
     */
    private bool $probedHasMorePages;

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $items  현재 페이지 항목
     * @param  int  $total  총 건수 (상한 초과 시 상한값)
     * @param  int  $perPage  페이지당 건수
     * @param  int  $currentPage  현재 페이지 번호
     * @param  TotalRelation  $totalRelation  총 건수 정확도
     * @param  int|null  $resultCap  적용된 상한 (무제한이면 null)
     * @param  bool  $hasMorePages  다음 페이지 존재 여부 (per_page + 1 실측)
     * @param  array<string, mixed>  $options  페이지네이터 옵션 (path, pageName 등)
     */
    public function __construct(
        $items,
        int $total,
        int $perPage,
        int $currentPage,
        TotalRelation $totalRelation,
        ?int $resultCap,
        bool $hasMorePages,
        array $options = []
    ) {
        parent::__construct($items, $total, $perPage, $currentPage, $options);

        $this->totalRelation = $totalRelation;
        $this->resultCap = $resultCap;
        $this->probedHasMorePages = $hasMorePages;
    }

    /**
     * 총 건수와 실제 매칭 건수의 관계를 반환합니다.
     *
     * @return TotalRelation 정확(Exact) 또는 하한(AtLeast)
     */
    public function totalRelation(): TotalRelation
    {
        return $this->totalRelation;
    }

    /**
     * 총 건수 집계에 적용된 상한을 반환합니다.
     *
     * @return int|null 상한 (무제한이면 null)
     */
    public function resultCap(): ?int
    {
        return $this->resultCap;
    }

    /**
     * 총 건수가 상한에 걸려 잘렸는지 여부를 반환합니다.
     *
     * @return bool 잘렸으면 true
     */
    public function isTruncated(): bool
    {
        return ! $this->totalRelation->isExact();
    }

    /**
     * 마지막 페이지 번호를 반환합니다.
     *
     * 총 건수가 부정확하면 마지막 페이지를 계산할 수 없으므로 null 을 반환합니다.
     * 화면은 이 값이 null 이면 마지막 페이지 점프 버튼을 감춥니다.
     *
     * @return int|null 마지막 페이지 번호 (총 건수 부정확 시 null)
     */
    public function lastPage(): ?int
    {
        return $this->isTruncated() ? null : $this->lastPage;
    }

    /**
     * 다음 페이지 존재 여부를 반환합니다.
     *
     * 총 건수를 몰라도 `per_page + 1` 조회로 정확히 판정되므로, 상한에 걸린 상태에서도
     * "다음" 이동은 끝까지 열려 있습니다.
     *
     * @return bool 다음 페이지가 있으면 true
     */
    public function hasMorePages(): bool
    {
        return $this->probedHasMorePages;
    }
}
