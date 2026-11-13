<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 쿠폰 발급 내역 컬렉션 리소스
 */
class CouponIssueCollection extends BaseApiCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = CouponIssueResource::class;

    /**
     * 쿠폰 발급 내역 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 발급 내역 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        // 행마다 회원의 표시 이름을 계산하기 전에 필요한 값을 한 번에 읽어 둔다.
        MemberDisplayName::preloadStaffRelations($this->collection, ['user'], $request);

        $result = [
            'data' => $this->collection,
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
