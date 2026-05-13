<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class NotificationDefinitionCollection extends BaseApiCollection
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'core.settings.update',
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
        $sortOrder = $request->input('sort_order', 'asc');
        $abilities = $this->resolveCollectionAbilities($request);

        return [
            'data' => $this->mapWithRowNumber(function ($definition) use ($request) {
                return (new NotificationDefinitionResource($definition))->toArray($request);
            }, $sortOrder),
            'pagination' => [
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
                'per_page' => $this->perPage(),
                'total' => $this->total(),
                'from' => $this->firstItem(),
                'to' => $this->lastItem(),
                'has_more_pages' => $this->hasMorePages(),
            ],
            ...($abilities ? ['abilities' => $abilities] : []),
        ];
    }
}
