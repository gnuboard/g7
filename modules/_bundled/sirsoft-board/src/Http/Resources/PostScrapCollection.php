<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 게시글 스크랩 컬렉션 (마이페이지 목록)
 *
 * 스크랩 목록을 페이지네이션과 함께 반환한다.
 * 개인 보관함이므로 게시판 권한(abilities)은 포함하지 않는다.
 */
class PostScrapCollection extends BaseApiCollection
{
    /**
     * 스크랩 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 스크랩 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $result = [
            'data' => $this->collection
                ->map(fn ($scrap) => (new PostScrapResource($scrap))->resolve($request))
                ->toArray(),
        ];

        if ($this->resource instanceof LengthAwarePaginator) {
            $result['pagination'] = [
                'total' => $this->resource->total(),
                'count' => $this->resource->count(),
                'per_page' => $this->resource->perPage(),
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ];
        }

        return $result;
    }
}
