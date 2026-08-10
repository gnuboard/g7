<?php

namespace App\Http\Resources\Admin\Identity;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;

/**
 * IDV 메시지 정의 컬렉션.
 */
class IdentityMessageDefinitionCollection extends BaseApiCollection
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'core.admin.identity.messages.update',
            'can_update' => 'core.admin.identity.messages.update',
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
                return (new IdentityMessageDefinitionResource($definition))->toArray($request);
            }, $sortOrder),
            // 표준 메타를 쓴다 — 페이지 결과의 형태를 스스로 판정해 그 형태가 아는 값만 낸다.
            ...$this->paginationMeta(),
            ...($abilities ? ['abilities' => $abilities] : []),
        ];
    }
}
