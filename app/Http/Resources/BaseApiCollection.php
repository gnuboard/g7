<?php

namespace App\Http\Resources;

use App\Contracts\Pagination\BoundedTotalAware;
use App\Helpers\PermissionHelper;
use App\Http\Resources\Traits\HasRowNumber;
use App\Support\Query\BoundedPage;
use App\Support\Query\KeysetPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\CursorPaginator;

/**
 * API 컬렉션 리소스 기본 클래스
 *
 * 모든 API Collection은 이 클래스를 상속해야 합니다.
 * - HasRowNumber: 순번 부여
 * - abilityMap(): 컬렉션 레벨 abilities (페이지 버튼 제어)
 */
abstract class BaseApiCollection extends ResourceCollection
{
    use HasRowNumber;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * 페이지 레벨 버튼 제어용 (생성, 일괄삭제 등).
     * abilities가 불필요한 컬렉션은 오버라이드하지 않습니다.
     *
     * @return array<string, string> ['can_delete' => 'permission.identifier', ...]
     */
    protected function abilityMap(): array
    {
        return [];
    }

    /**
     * 컬렉션 레벨 abilities를 해석합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, bool>
     */
    public function resolveCollectionAbilities(Request $request): array
    {
        $map = $this->abilityMap();
        if (empty($map)) {
            return [];
        }

        $user = $request->user();
        $abilities = [];

        foreach ($map as $key => $permission) {
            $abilities[$key] = PermissionHelper::check($permission, $user);
        }

        return $abilities;
    }

    /**
     * paginator 인 경우 표준 pagination 메타를 반환합니다.
     *
     * 무한스크롤 화면이 전체 개수(total)와 다음 페이지 존재 여부(has_more_pages)를
     * 정확히 판정할 수 있도록 노출합니다. 전체 조회(get) 등 paginator 가 아닌
     * 경우에는 빈 배열을 반환하므로 toArray 에서 array_merge 로 안전하게 합칠 수 있습니다.
     *
     * 페이지 결과는 세 형태 중 하나이며, 형태를 스스로 판정해 그 형태가 실제로 아는
     * 값만 내보냅니다. 모르는 값을 0 이나 1 로 채우면 화면이 그것을 사실로 읽습니다.
     *
     * | 형태 | 예 | total | last_page | 추가 |
     * |---|---|---|---|---|
     * | 전체건수형 | `paginate()` | 정확 | 정확 | — |
     * | 상한형 | {@see BoundedPage} | 정확 또는 하한 | 하한이면 null | `total_relation` `total_is_exact` `result_cap` |
     * | 단순형 | `simplePaginate()` / `cursorPaginate()` | 없음 | 없음 | 커서면 `next_cursor` `prev_cursor` |
     *
     * 기존 필드는 하나도 제거하지 않으므로, 표준 `paginate()` 를 쓰는 기존 컬렉션의
     * 응답은 이전과 완전히 동일합니다.
     *
     * @return array<string, mixed> ['pagination' => [...]] 또는 빈 배열
     */
    protected function paginationMeta(): array
    {
        $resource = $this->resource;

        if ($resource instanceof CursorPaginator) {
            return ['pagination' => $this->cursorPaginationMeta($resource)];
        }

        if (! method_exists($resource, 'currentPage')) {
            return [];
        }

        $meta = [
            'current_page' => $resource->currentPage(),
            'per_page' => $resource->perPage(),
            'from' => $resource->firstItem(),
            'to' => $resource->lastItem(),
            'has_more_pages' => $resource->hasMorePages(),
        ];

        // 단순형(simplePaginate)은 총 건수를 아예 세지 않으므로 total/last_page 를 내보내지 않는다.
        if ($resource instanceof LengthAwarePaginatorContract) {
            $meta['last_page'] = $resource->lastPage();
            $meta['total'] = $resource->total();
        }

        if ($resource instanceof BoundedTotalAware) {
            $meta['total_relation'] = $resource->totalRelation()->value;
            $meta['total_is_exact'] = $resource->totalRelation()->isExact();
            $meta['result_cap'] = $resource->resultCap();
        }

        return ['pagination' => $meta];
    }

    /**
     * 커서 페이지의 pagination 메타를 만듭니다.
     *
     * 커서 방식은 총 건수와 페이지 번호를 계산하지 않습니다. 대신 앞뒤 이동 커서를
     * 그대로 실어 보내며, 화면은 이 값으로 이전/다음 버튼을 만듭니다.
     *
     * 인코딩은 {@see KeysetPaginator} 를 거칩니다. 커서 문자열의 형식은 디코딩과 짝을
     * 이뤄야 하므로, 한쪽만 여기서 직접 만들면 표준이 두 벌이 됩니다.
     *
     * @param  CursorPaginator  $resource  커서 페이지 결과
     * @return array<string, mixed> pagination 메타
     */
    private function cursorPaginationMeta(CursorPaginator $resource): array
    {
        return [
            'per_page' => $resource->perPage(),
            'has_more_pages' => $resource->hasMorePages(),
            'next_cursor' => KeysetPaginator::nextCursor($resource),
            'prev_cursor' => KeysetPaginator::previousCursor($resource),
        ];
    }
}
