<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 주문 컬렉션 리소스
 *
 * 주문 목록을 페이지네이션 및 통계와 함께 반환합니다.
 */
class OrderCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'sirsoft-ecommerce.orders.update',
        ];
    }

    /**
     * 주문 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 주문 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $result = [
            'data' => $this->mapWithRowNumber(function ($order) {
                return (new OrderListResource($order))->toArray(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
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

    /**
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param array $statistics 통계 데이터 배열
     * @return array<string, mixed> 통계 정보가 포함된 주문 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        $result = [
            'data' => $this->mapWithRowNumber(function ($order) {
                return (new OrderListResource($order))->toArray(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), request()->user()),
            'statistics' => $statistics,
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
