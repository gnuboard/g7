<?php

namespace App\Http\Resources;

use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;

class RoleCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 키 => 권한 식별자 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'core.permissions.create',
            'can_update' => 'core.permissions.update',
            'can_delete' => 'core.permissions.delete',
        ];
    }

    /**
     * 역할 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 역할 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => RoleResource::collection($this->collection),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
            'pagination' => $this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ] : null,
        ];
    }
}
