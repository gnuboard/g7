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
     * @param  Request  $request
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
            // 표준 메타를 쓴다 — 페이지 결과의 형태를 스스로 판정해 그 형태가 아는 값만 낸다.
            ...$this->paginationMeta(),
            ...($abilities ? ['abilities' => $abilities] : []),
        ];
    }
}
