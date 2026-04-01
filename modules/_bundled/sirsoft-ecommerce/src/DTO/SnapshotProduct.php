<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;

/**
 * 스냅샷 기반 가상 상품 객체
 *
 * 환불 재계산 시 DB 조회 대신 주문 시점의 스냅샷 데이터로 구성합니다.
 * Product 모델과 동일한 속성/메서드 인터페이스를 제공하여
 * OrderCalculationService의 계산 로직에서 투명하게 사용됩니다.
 */
class SnapshotProduct
{
    public int $id;

    public string|array $name;

    public int $selling_price;

    public ?ProductTaxStatus $tax_status;

    public ?float $tax_rate;

    public ?int $shipping_policy_id;

    /** @var object|null 배송정책 (스냅샷 모드에서는 null — calculateShippingFee에서 별도 처리) */
    public $shippingPolicy = null;

    /** @var \Illuminate\Support\Collection 카테고리 (스냅샷 모드에서는 빈 컬렉션 — 쿠폰 스냅샷 모드에서 검증 우회) */
    public $categories;

    /**
     * @param  array  $snapshot  Product::toSnapshotArray() 형식의 배열
     * @param  int|null  $shippingPolicyId  배송정책 ID (shippingPolicySnapshots에서 추출)
     */
    public function __construct(array $snapshot, ?int $shippingPolicyId = null)
    {
        $this->id = $snapshot['id'] ?? 0;
        $this->name = $snapshot['name'] ?? '';
        $this->selling_price = $snapshot['selling_price'] ?? 0;
        $this->tax_status = isset($snapshot['tax_status'])
            ? ProductTaxStatus::tryFrom($snapshot['tax_status'])
            : ProductTaxStatus::TAXABLE;
        $this->tax_rate = $snapshot['tax_rate'] ?? null;
        $this->shipping_policy_id = $shippingPolicyId;
        $this->categories = collect([]);
    }

    /**
     * 다국어 상품명을 반환합니다.
     *
     * @return string 스냅샷에 저장된 상품명
     */
    public function getLocalizedName(): string
    {
        if (is_array($this->name)) {
            return $this->name[app()->getLocale()] ?? $this->name['ko'] ?? reset($this->name) ?: '';
        }

        return $this->name;
    }

    /**
     * relation 로드 여부 확인 (스냅샷에서는 항상 false)
     *
     * @param  string  $relation  관계명
     * @return bool
     */
    public function relationLoaded(string $relation): bool
    {
        return false;
    }
}
