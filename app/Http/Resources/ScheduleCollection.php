<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ScheduleCollection extends BaseApiCollection
{
    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * 「예약 작업 등록」·일괄 상태 변경·행 선택 같은 페이지 수준 버튼이 읽는다.
     * 식별자는 스케줄 라우트의 permission 미들웨어와 같다.
     *
     * @return array<string, string> 능력 키 => 권한 식별자 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'core.schedules.create',
            'can_update' => 'core.schedules.update',
            'can_delete' => 'core.schedules.delete',
            'can_run' => 'core.schedules.run',
        ];
    }

    /**
     * 스케줄 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 스케줄 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($schedule) {
                return (new ScheduleResource($schedule))->toListArray(request());
            }),
            'abilities' => $this->resolveCollectionAbilities($request),
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
     * @param  array  $statistics  통계 데이터 배열
     * @return array<string, mixed> 통계 정보가 포함된 스케줄 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        return [
            'data' => $this->collection->map(function ($schedule) {
                return (new ScheduleResource($schedule))->toListArray(request());
            }),
            'statistics' => $statistics,
            'abilities' => $this->resolveCollectionAbilities(request()),
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
