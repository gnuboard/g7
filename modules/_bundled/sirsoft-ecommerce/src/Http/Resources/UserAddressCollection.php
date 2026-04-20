<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;

/**
 * 사용자 배송지 컬렉션 리소스
 */
class UserAddressCollection extends BaseApiCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = UserAddressResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'data' => $this->collection->toArray(),
            // user-addresses 권한 식별자가 모듈 매니페스트에 없으므로
            // abilityMap() 대신 인증 기반 하드코딩 (인증된 사용자는 항상 생성 가능)
            'abilities' => [
                'can_create' => true,
            ],
        ];
    }
}
