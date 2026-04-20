<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\BaseApiCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 위시리스트(찜) 컬렉션 리소스
 *
 * 위시리스트 목록을 페이지네이션과 함께 반환합니다.
 * Public API이므로 abilities는 포함하지 않습니다.
 */
class WishlistCollection extends BaseApiCollection
{
    /**
     * 위시리스트 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<string, mixed> 변환된 위시리스트 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $result = [
            'data' => $this->collection->map(function ($wishlist) use ($request) {
                return (new WishlistResource($wishlist))->resolve($request);
            })->toArray(),
        ];

        if ($this->resource instanceof LengthAwarePaginator) {
            $result['pagination'] = [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ];
        }

        return $result;
    }
}
