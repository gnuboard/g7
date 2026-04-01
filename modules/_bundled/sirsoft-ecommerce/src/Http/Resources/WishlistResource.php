<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 위시리스트(찜) 리소스
 *
 * 개별 위시리스트 항목을 ProductListResource 형식의 상품 정보와 함께 반환합니다.
 */
class WishlistResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<string, mixed> 변환된 위시리스트 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'created_at' => $this->created_at,
            'product' => $this->whenLoaded('product', fn () => $this->product
                ? (new ProductListResource($this->product))->resolve($request)
                : null
            ),
        ];
    }
}
