<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Request;

/**
 * 전체 후기 공개 목록(리뷰 모아보기) 리소스
 *
 * 공개 후기 응답에 그 후기가 달린 상품의 요약(상품코드·이름·대표 이미지)을 더한다.
 */
class PublicReviewFeedResource extends PublicProductReviewResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 상품 요약이 붙은 공개 후기 응답
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $product = $this->resource->relationLoaded('product') ? $this->resource->product : null;

        $data['product'] = $product === null ? null : [
            'product_code' => $product->product_code,
            'name' => $product->getLocalizedName(),
            'thumbnail_url' => $product->getThumbnailUrl(),
        ];

        return $data;
    }
}
