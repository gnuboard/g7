<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\BaseApiCollection;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 신고 목록 리소스 컬렉션
 *
 * 신고 목록을 페이지네이션과 함께 반환합니다.
 */
class ReportCollection extends BaseApiCollection
{
    use ChecksBoardPermission;

    /**
     * 리소스 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($report) {
                return new ReportResource($report);
            }),
            'pagination' => $this->when($this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator, [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ]),
        ];
    }

    /**
     * 통계 및 권한 정보가 포함된 형태의 배열을 반환합니다.
     *
     * @param  array  $statistics  통계 데이터 배열
     * @return array<string, mixed> 통계 및 권한 정보가 포함된 신고 컬렉션
     */
    public function withStatisticsAndPermissions(array $statistics = []): array
    {
        return [
            'data' => $this->collection->map(function ($report) {
                return new ReportResource($report);
            }),
            'statistics' => $statistics,
            'pagination' => $this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ] : null,
            'abilities' => [
                'can_view' => $this->checkModulePermission('reports', 'view'),
                'can_manage' => $this->checkModulePermission('reports', 'manage'),
            ],
        ];
    }
}
