<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data;

/**
 * 단순 상품 생성 명세 (공개 계약 DTO)
 *
 * 다국어 값은 `['ko' => …, 'en' => …]` 처럼 로캘별 배열로 넘기고 그대로 저장된다.
 *
 * 쇼핑몰 상품 가격은 언제나 기본 통화로 저장되고 표시와 결제 때 환율로 환산된다(상품별 통화 가격 없음).
 * `currency` 를 기본 통화가 아닌 통화로 주면 그 통화 금액을 지금 환율로 기본 통화 가격으로 바꿔 저장한다 —
 * 그 통화로 환산했을 때 정확히 같은 금액이 되는 기본 통화 가격을 고르며, 환율이 바뀌면 환산 금액도 따라 바뀐다.
 *
 * @since 1.4.0
 */
final readonly class SimpleProductSpec
{
    /**
     * SimpleProductSpec 생성자
     *
     * @param  array<string, string>  $name  로캘별 상품명
     * @param  string  $productCode  상품 코드(필수, 중복 불가 — 자동 채번 없음)
     * @param  int|float  $sellingPrice  판매가(`currency` 통화 금액)
     * @param  int  $stockQuantity  재고 수량(기본 옵션에도 같은 값)
     * @param  string|null  $sku  SKU
     * @param  int|float|null  $listPrice  정가(`currency` 통화 금액, null 이면 판매가와 같음)
     * @param  array<string, string>|null  $description  로캘별 설명
     * @param  string  $salesStatus  판매 상태
     * @param  string  $displayStatus  전시 상태
     * @param  array<string, string>|null  $defaultOptionName  로캘별 기본 옵션명
     * @param  string  $defaultOptionCode  기본 옵션 코드
     * @param  string|null  $currency  가격 통화 코드(null 이면 쇼핑몰 기본 통화)
     */
    public function __construct(
        public array $name,
        public string $productCode,
        public int|float $sellingPrice,
        public int $stockQuantity,
        public ?string $sku = null,
        public int|float|null $listPrice = null,
        public ?array $description = null,
        public string $salesStatus = 'on_sale',
        public string $displayStatus = 'visible',
        public ?array $defaultOptionName = null,
        public string $defaultOptionCode = 'default',
        public ?string $currency = null,
    ) {}
}
