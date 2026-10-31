<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 큐 작업 처리 기록 목록 컬렉션 (#17)
 */
class QueueJobLogCollection extends BaseApiCollection
{
    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(fn ($row) => (new QueueJobLogResource($row))->toArray($request))->values()->all(),
            ...$this->paginationMeta(),
        ];
    }
}
