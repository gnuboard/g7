<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\Traits\HasAbilityCheck;
use App\Http\Resources\Traits\HasRowNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * 브랜드 컬렉션 리소스
 *
 * 브랜드 목록을 페이지네이션 및 통계와 함께 반환합니다.
 */
class BrandCollection extends ResourceCollection
{
    use HasAbilityCheck;
    use HasRowNumber;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.brands.create',
            'can_update' => 'sirsoft-ecommerce.brands.update',
            'can_delete' => 'sirsoft-ecommerce.brands.delete',
        ];
    }

    /**
     * 브랜드 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 브랜드 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->mapWithRowNumber(function ($brand) {
                return (new BrandResource($brand))->toArray(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ];
    }

    /**
     * 간단한 목록 형태의 배열을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 간략한 브랜드 정보 배열
     */
    public function toSimpleArray(): array
    {
        return $this->collection->map(function ($brand) {
            return [
                'id' => $brand->id,
                'name' => $brand->getLocalizedName(),
                'slug' => $brand->slug,
            ];
        })->toArray();
    }
}
