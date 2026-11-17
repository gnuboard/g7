<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * 다른 확장에 건네는 상품 요약 (공개 계약 DTO)
 *
 * 상품 Model 대신 이 값만 넘긴다 — 상품 테이블·관계 구조가 바뀌어도 다른 확장은 깨지지 않는다.
 *
 * @since 1.4.0
 */
final readonly class ProductSummary
{
    /**
     * ProductSummary 생성자
     *
     * @param  int  $id  상품 id
     * @param  string  $code  상품 코드(사이트 주소 조각)
     * @param  string  $label  현재 언어 상품명(요청 언어 → 기본 언어 → 첫 값)
     * @param  string  $url  사이트 상품 상세 경로(쇼핑몰 경로 접두 반영)
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $label,
        public string $url,
    ) {}

    /**
     * 상품 Model 에서 요약을 만듭니다.
     *
     * @internal 이커머스 구현 전용 — 다른 확장은 계약 메서드의 반환값만 받는다.
     *
     * @param  Product  $product  상품
     * @return self 요약
     */
    public static function fromModel(Product $product): self
    {
        return new self(
            id: (int) $product->id,
            code: (string) $product->product_code,
            label: $product->getLocalizedName(),
            url: ShopPathResolver::path('products/'.$product->product_code),
        );
    }
}
