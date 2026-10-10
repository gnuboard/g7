<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 마이페이지 「내 신고」 컬렉션
 *
 * 본인 신고 이력 목록. 개인 화면이므로 게시판 권한(abilities)은 포함하지 않는다.
 */
class UserReportCollection extends BaseApiCollection
{
    /**
     * 적용된 필터 에코 (화면이 현재 상태를 복원할 때 쓴다).
     *
     * @var array<string, mixed>
     */
    private array $query = [];

    /**
     * 적용된 필터를 응답에 함께 싣습니다.
     *
     * @param  array<string, mixed>  $query  적용된 필터
     * @return $this
     */
    public function withQuery(array $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * 신고 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 신고 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $result = [
            'data' => $this->collection
                ->map(fn ($log) => (new UserReportResource($log))->resolve($request))
                ->toArray(),
            'query' => $this->query,
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
