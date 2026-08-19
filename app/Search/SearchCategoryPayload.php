<?php

namespace App\Search;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use App\Support\Query\KeysetPaginator;
use Illuminate\Pagination\CursorPaginator;

/**
 * 검색 카테고리 응답 페이로드를 만드는 단일 지점
 *
 * 검색 결과는 컬렉션 리소스를 거치지 않고 리스너가 배열을 만들어 넘긴다. 그 조립을
 * 도메인마다 손으로 하면 응답 형태가 갈라지고, 나중에 필드가 하나 늘 때 어떤 화면은
 * 받고 어떤 화면은 못 받는다. 그 형태 확장이 조용히 깨지는 것을 막기 위해 키 구성을
 * 여기 한 곳에 둔다.
 *
 * offset 응답과 커서 응답은 채워지는 값만 다르고 **키 집합은 같다**. 화면이 두 형태를
 * 분기 없이 그릴 수 있어야 하기 때문이다.
 */
final class SearchCategoryPayload
{
    /**
     * offset(페이지 번호) 방식 결과로 페이로드를 만듭니다.
     *
     * @param  BoundedPage  $page  상한 총 건수를 가진 페이지 결과
     * @param  array<int, mixed>  $items  화면에 실을 항목 (가공 완료 상태)
     * @param  array<string, mixed>  $extra  도메인 고유 필드 (available_boards 등)
     * @return array<string, mixed> 카테고리 페이로드
     */
    public static function fromBounded(BoundedPage $page, array $items, array $extra = []): array
    {
        return array_merge([
            'total' => $page->total(),
            'total_relation' => $page->totalRelation()->value,
            'total_is_exact' => $page->totalRelation()->isExact(),
            'result_cap' => $page->resultCap(),
            'last_page' => $page->lastPage(),
            'has_more_pages' => $page->hasMorePages(),
            // offset 응답에는 커서가 없다. 키 자체는 남겨 화면이 분기 없이 읽게 한다.
            'next_cursor' => null,
            'prev_cursor' => null,
            'items' => $items,
        ], $extra);
    }

    /**
     * 커서(키셋) 방식 결과로 페이로드를 만듭니다.
     *
     * 커서 응답에는 총 건수가 없으므로 건수는 별도 집계로 받습니다. 마지막 페이지 번호는
     * 커서 방식에 존재하지 않는 개념이라 언제나 null 이며, 화면은 그때 마지막 페이지
     * 점프만 감추고 "다음" 이동은 그대로 유지합니다.
     *
     * @param  CursorPaginator  $page  커서 페이지 결과
     * @param  BoundedCount|null  $count  총 건수 집계 (배지를 그리지 않으면 null)
     * @param  array<int, mixed>  $items  화면에 실을 항목 (가공 완료 상태)
     * @param  array<string, mixed>  $extra  도메인 고유 필드
     * @return array<string, mixed> 카테고리 페이로드
     */
    public static function fromCursor(
        CursorPaginator $page,
        ?BoundedCount $count,
        array $items,
        array $extra = []
    ): array {
        $relation = $count?->totalRelation() ?? TotalRelation::AtLeast;

        return array_merge([
            'total' => $count?->total() ?? count($items),
            'total_relation' => $relation->value,
            'total_is_exact' => $count !== null && $relation->isExact(),
            'result_cap' => $count?->resultCap(),
            // 커서 방식에는 마지막 페이지 번호가 없다 (총 건수를 알아도 계산하지 않는다).
            'last_page' => null,
            'has_more_pages' => $page->hasMorePages(),
            'next_cursor' => KeysetPaginator::nextCursor($page),
            'prev_cursor' => KeysetPaginator::previousCursor($page),
            'items' => $items,
        ], $extra);
    }

    /**
     * 카테고리 검색이 예외로 실패했을 때의 페이로드를 만듭니다.
     *
     * 실패한 0건을 "정확한 0건" 으로 말하지 않는다 — `total_is_exact=false` 로 내보내
     * 배지가 정확한 값처럼 그려지는 것을 막고, `failed` 플래그로 화면이 "결과 없음" 과
     * 구분되는 오류 안내를 그릴 수 있게 한다. 키 집합은 다른 팩토리와 동일하게 유지해
     * 화면이 분기 없이 읽게 한다.
     *
     * @param  array<string, mixed>  $extra  도메인 고유 필드 (available_boards 등)
     * @return array<string, mixed> 카테고리 페이로드
     */
    public static function failed(array $extra = []): array
    {
        return array_merge([
            'failed' => true,
            'total' => 0,
            'total_relation' => TotalRelation::AtLeast->value,
            'total_is_exact' => false,
            'result_cap' => null,
            'last_page' => null,
            'has_more_pages' => false,
            'next_cursor' => null,
            'prev_cursor' => null,
            'items' => [],
        ], $extra);
    }

    /**
     * 목록 없이 건수만 필요한 자리(비활성 탭 배지)의 페이로드를 만듭니다.
     *
     * @param  BoundedCount  $count  총 건수 집계
     * @param  array<string, mixed>  $extra  도메인 고유 필드
     * @return array<string, mixed> 카테고리 페이로드
     */
    public static function fromCountOnly(BoundedCount $count, array $extra = []): array
    {
        return array_merge($count->toArray(), [
            'last_page' => null,
            'has_more_pages' => false,
            'next_cursor' => null,
            'prev_cursor' => null,
            'items' => [],
        ], $extra);
    }
}
