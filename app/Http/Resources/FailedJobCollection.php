<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 큐 실패 작업 목록 컬렉션
 */
class FailedJobCollection extends BaseApiCollection
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_retry' => 'core.queue_jobs.retry',
            'can_delete' => 'core.queue_jobs.delete',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $abilities = $this->resolveCollectionAbilities($request);

        return [
            'data' => $this->collection->map(fn ($row) => (new FailedJobResource($row))->toArray($request))->values()->all(),
            ...$this->paginationMeta(),
            ...($abilities ? ['abilities' => $abilities] : []),
        ];
    }
}
