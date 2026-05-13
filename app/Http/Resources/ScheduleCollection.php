<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ScheduleCollection extends BaseApiCollection
{
    /**
     * 스케줄 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 스케줄 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($schedule) {
                return (new ScheduleResource($schedule))->toListArray(request());
            }),
            'pagination' => $this->when($this->resource instanceof LengthAwarePaginator, [
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
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param array $statistics 통계 데이터 배열
     * @return array<string, mixed> 통계 정보가 포함된 스케줄 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        return [
            'data' => $this->collection->map(function ($schedule) {
                return (new ScheduleResource($schedule))->toListArray(request());
            }),
            'statistics' => $statistics,
            'pagination' => $this->when($this->resource instanceof LengthAwarePaginator, [
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
}
