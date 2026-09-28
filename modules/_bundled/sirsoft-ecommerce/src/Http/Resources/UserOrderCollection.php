<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;

/**
 * 사용자 주문 컬렉션 리소스
 *
 * 마이페이지 주문내역 목록을 페이지네이션 및 통계와 함께 반환합니다.
 *
 * pagination 블록은 `paginationMeta()` 표준을 쓴다 — 손조립하면 `from`/`to`/`has_more_pages`
 * 가 빠지고, 저장소가 `BoundedPaginator` 로 바뀔 때 `total_relation`/`total_is_exact` 가 통째로
 * 빠져 잘린 총 건수가 정확한 값처럼 나간다 (`ClaimCollection` 과 동형 — pagination.md).
 */
class UserOrderCollection extends BaseApiCollection
{
    /**
     * 주문 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 주문 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return array_merge([
            'data' => $this->collection->map(function ($order) {
                return (new UserOrderListResource($order))->toArray(request());
            })->toArray(),
            'abilities' => [
                'can_create' => PermissionHelper::check('sirsoft-ecommerce.user-orders.create'),
            ],
        ], $this->paginationMeta());
    }

    /**
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param  array  $statistics  주문상태별 통계 데이터
     * @param  array  $statusSummary  목록 상단 건수 칸 배열 (키·라벨·아이콘 토큰·색·건수)
     * @return array<string, mixed> 통계 정보가 포함된 주문 컬렉션
     */
    public function withStatistics(array $statistics = [], array $statusSummary = []): array
    {
        return array_merge([
            'data' => $this->collection->map(function ($order) {
                return (new UserOrderListResource($order))->toArray(request());
            })->toArray(),
            'statistics' => $statistics,
            'status_summary' => $statusSummary,
            'abilities' => [
                'can_create' => PermissionHelper::check('sirsoft-ecommerce.user-orders.create'),
            ],
        ], $this->paginationMeta());
    }
}
