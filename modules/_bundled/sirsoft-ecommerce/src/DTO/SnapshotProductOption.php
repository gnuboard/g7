<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 스냅샷 기반 가상 상품옵션 객체
 *
 * 환불 재계산 시 DB 조회 대신 주문 시점의 스냅샷 데이터로 구성합니다.
 * ProductOption 모델과 동일한 속성/메서드 인터페이스를 제공하여
 * OrderCalculationService의 계산 로직에서 투명하게 사용됩니다.
 */
class SnapshotProductOption
{
    public int $id;

    public int $selling_price;

    public int $price_adjustment;

    public ?float $weight;

    public ?float $volume;

    public ?float $mileage_value;

    public ?string $mileage_type;

    public string|array|null $option_name;

    /** @var int 상품 판매가 (ProductOption::getFinalPrice() = product.selling_price + price_adjustment) */
    private int $productSellingPrice;

    /**
     * @param  array  $snapshot  ProductOption::toSnapshotArray() 형식의 배열
     * @param  int  $productSellingPrice  상품의 판매가 (스냅샷 상품의 selling_price)
     */
    public function __construct(array $snapshot, int $productSellingPrice = 0)
    {
        $this->id = $snapshot['id'] ?? 0;
        $this->selling_price = $snapshot['selling_price'] ?? 0;
        $this->price_adjustment = $snapshot['price_adjustment'] ?? 0;
        $this->weight = $snapshot['weight'] ?? null;
        $this->volume = $snapshot['volume'] ?? null;
        $this->mileage_value = $snapshot['mileage_value'] ?? null;
        $this->mileage_type = $snapshot['mileage_type'] ?? null;
        $this->option_name = $snapshot['option_name'] ?? '';
        $this->productSellingPrice = $productSellingPrice;
    }

    /**
     * 최종 판매가를 반환합니다.
     *
     * ProductOption::getFinalPrice()와 동일하게 상품 판매가 + 옵션 조정액으로 계산합니다.
     *
     * @return int 스냅샷 기반 판매가
     */
    public function getSellingPrice(): int
    {
        return $this->productSellingPrice + $this->price_adjustment;
    }

    /**
     * 최종 판매가를 반환합니다 (getSellingPrice 별칭).
     *
     * @return int
     */
    public function getFinalPrice(): int
    {
        return $this->getSellingPrice();
    }

    /**
     * 다국어 옵션명을 반환합니다.
     *
     * @return string 스냅샷에 저장된 옵션명
     */
    public function getLocalizedOptionName(): string
    {
        if (is_array($this->option_name)) {
            return $this->option_name[app()->getLocale()] ?? $this->option_name['ko'] ?? reset($this->option_name) ?: '';
        }

        return $this->option_name ?? '';
    }
}
