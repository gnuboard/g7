<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * 사용자 배송지 컬렉션 리소스
 */
class UserAddressCollection extends ResourceCollection
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
            'abilities' => [
                'can_create' => true,
            ],
        ];
    }
}
