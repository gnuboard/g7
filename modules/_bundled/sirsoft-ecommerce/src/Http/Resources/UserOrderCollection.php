<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 사용자 주문 컬렉션 리소스
 *
 * 마이페이지 주문내역 목록을 페이지네이션 및 통계와 함께 반환합니다.
 */
class UserOrderCollection extends ResourceCollection
{
    /**
     * 주문 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 주문 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($order) {
                return (new UserOrderListResource($order))->toArray(request());
            })->toArray(),
            'abilities' => [
                'can_create' => PermissionHelper::check('sirsoft-ecommerce.user-orders.create'),
            ],
            'pagination' => $this->resource instanceof LengthAwarePaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
            ] : null,
        ];
    }

    /**
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param array $statistics 주문상태별 통계 데이터
     * @return array<string, mixed> 통계 정보가 포함된 주문 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        return [
            'data' => $this->collection->map(function ($order) {
                return (new UserOrderListResource($order))->toArray(request());
            })->toArray(),
            'statistics' => $statistics,
            'abilities' => [
                'can_create' => PermissionHelper::check('sirsoft-ecommerce.user-orders.create'),
            ],
            'pagination' => $this->resource instanceof LengthAwarePaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
            ] : null,
        ];
    }
}
