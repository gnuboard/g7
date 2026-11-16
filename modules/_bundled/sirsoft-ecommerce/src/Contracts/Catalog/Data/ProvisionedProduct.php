<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data;

/**
 * 만든 상품 식별 정보 (공개 계약 DTO)
 *
 * @since 1.4.0
 */
final readonly class ProvisionedProduct
{
    /**
     * ProvisionedProduct 생성자
     *
     * @param  int  $id  상품 id
     * @param  string  $productCode  상품 코드
     * @param  float|int  $storedSellingPrice  저장된 판매가(쇼핑몰 기본 통화)
     * @param  string  $storedCurrency  저장 통화(쇼핑몰 기본 통화)
     */
    public function __construct(
        public int $id,
        public string $productCode,
        public float|int $storedSellingPrice = 0,
        public string $storedCurrency = '',
    ) {}
}
