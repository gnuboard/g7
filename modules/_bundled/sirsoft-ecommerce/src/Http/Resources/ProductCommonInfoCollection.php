<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use App\Http\Resources\BaseApiCollection;

/**
 * 공통정보 템플릿 컬렉션 리소스
 *
 * 공통정보 목록을 abilities와 함께 반환합니다.
 */
class ProductCommonInfoCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = ProductCommonInfoResource::class;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.product-common-infos.create',
            'can_update' => 'sirsoft-ecommerce.product-common-infos.update',
            'can_delete' => 'sirsoft-ecommerce.product-common-infos.delete',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ];
    }
}
