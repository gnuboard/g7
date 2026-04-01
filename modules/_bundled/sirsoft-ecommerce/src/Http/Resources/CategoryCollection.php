<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * 카테고리 컬렉션 리소스
 *
 * 카테고리 목록을 계층 구조 및 권한 정보와 함께 반환합니다.
 */
class CategoryCollection extends ResourceCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.categories.create',
            'can_update' => 'sirsoft-ecommerce.categories.update',
            'can_delete' => 'sirsoft-ecommerce.categories.delete',
        ];
    }

    /**
     * 카테고리 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 카테고리 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => CategoryResource::collection($this->collection),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ];
    }
}
