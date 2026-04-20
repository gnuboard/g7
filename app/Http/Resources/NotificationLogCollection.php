<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class NotificationLogCollection extends BaseApiCollection
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_delete' => 'core.notification-logs.delete',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        $sortOrder = $request->input('sort_order', 'desc');
        $abilities = $this->resolveCollectionAbilities($request);

        return [
            'data' => $this->mapWithRowNumber(function ($log) use ($request) {
                return (new NotificationLogResource($log))->toArray($request);
            }, $sortOrder),
            'pagination' => [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ],
            ...($abilities ? ['abilities' => $abilities] : []),
        ];
    }
}
