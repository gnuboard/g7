<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ScheduleHistoryCollection extends BaseApiCollection
{
    /**
     * 스케줄 실행 이력 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 이력 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        // 실행한 사람 이름은 표시 이름으로 나간다. 추가 항목 값이 필요한 설정이면 여기서 한 번에 읽는다.
        $this->preloadMemberDisplayNames($this->collection, ['triggeredBy'], $request);

        return [
            'data' => $this->collection->map(function ($history) {
                return (new ScheduleHistoryResource($history))->toListArray();
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
}
