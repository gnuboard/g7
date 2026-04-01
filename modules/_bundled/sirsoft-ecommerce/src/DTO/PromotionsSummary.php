<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 프로모션 요약 DTO (상품별/주문별 분리)
 *
 * orders.promotions_applied_snapshot에 저장됩니다.
 */
class PromotionsSummary
{
    /**
     * @param  AppliedPromotions|null  $productPromotions  상품/카테고리 대상 프로모션
     * @param  AppliedPromotions|null  $orderPromotions  주문금액/배송비 대상 프로모션
     */
    public function __construct(
        public ?AppliedPromotions $productPromotions = null,
        public ?AppliedPromotions $orderPromotions = null,
    ) {
        $this->productPromotions = $productPromotions ?? new AppliedPromotions;
        $this->orderPromotions = $orderPromotions ?? new AppliedPromotions;
    }

    /**
     * 모든 쿠폰 적용 정보를 반환합니다.
     *
     * @return CouponApplication[]
     */
    public function getAllCoupons(): array
    {
        return array_merge(
            $this->productPromotions->coupons,
            $this->orderPromotions->coupons
        );
    }

    /**
     * 총 할인금액을 반환합니다.
     */
    public function getTotalDiscount(): int
    {
        return $this->productPromotions->getTotalDiscount() + $this->orderPromotions->getTotalDiscount();
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'product_promotions' => $this->productPromotions->toArray(),
            'order_promotions' => $this->orderPromotions->toArray(),
        ];
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productPromotions: isset($data['product_promotions'])
                ? AppliedPromotions::fromArray($data['product_promotions'])
                : null,
            orderPromotions: isset($data['order_promotions'])
                ? AppliedPromotions::fromArray($data['order_promotions'])
                : null,
        );
    }
}
