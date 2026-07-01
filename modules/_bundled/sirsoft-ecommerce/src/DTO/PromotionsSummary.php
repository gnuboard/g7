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
     * 적용된 전체 쿠폰 발급 ID 목록을 반환합니다. (취소 복원/재계산 SSoT)
     *
     * @return int[] 중복 제거된 쿠폰 발급 ID 배열
     */
    public function getCouponIssueIds(): array
    {
        $ids = [];
        foreach ($this->getAllCoupons() as $coupon) {
            if ($coupon->couponIssueId > 0) {
                $ids[] = $coupon->couponIssueId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 상품옵션별 쿠폰 매핑을 반환합니다. (취소 재계산 입력 복원용)
     *
     * 상품 쿠폰(product_promotions)의 적용 대상 옵션별로 [optionId => [issueId, ...]] 형태로 구성합니다.
     *
     * @return array<int, int[]> 상품옵션 ID => 쿠폰 발급 ID 배열
     */
    public function getItemCoupons(): array
    {
        $map = [];
        foreach ($this->productPromotions->coupons as $coupon) {
            if ($coupon->couponIssueId <= 0) {
                continue;
            }
            foreach ($coupon->appliedItems ?? [] as $item) {
                $optionId = $item['product_option_id'] ?? null;
                if ($optionId === null) {
                    continue;
                }
                $map[$optionId][] = $coupon->couponIssueId;
            }
        }

        // 옵션별 중복 제거
        foreach ($map as $optionId => $ids) {
            $map[$optionId] = array_values(array_unique($ids));
        }

        return $map;
    }

    /**
     * 적용된 할인코드 문자열을 반환합니다. (취소 재계산 입력 복원용)
     *
     * @return string|null 첫 할인코드 (없으면 null)
     */
    public function getDiscountCode(): ?string
    {
        foreach (array_merge($this->productPromotions->discountCodes, $this->orderPromotions->discountCodes) as $code) {
            if (! empty($code->code)) {
                return $code->code;
            }
        }

        return null;
    }

    /**
     * 총 할인금액을 반환합니다.
     *
     * @return int 상품/주문 프로모션 총 할인금액
     */
    public function getTotalDiscount(): int
    {
        return $this->productPromotions->getTotalDiscount() + $this->orderPromotions->getTotalDiscount();
    }

    /**
     * 배열로 변환합니다.
     *
     * @return array 평탄 키(coupon_issue_ids/item_coupons/discount_code) + 상품/주문 프로모션 구조
     */
    public function toArray(): array
    {
        return [
            // 평탄 키 (취소 복원/재계산 SSoT) — 주문 스냅샷의 표준 형식
            'coupon_issue_ids' => $this->getCouponIssueIds(),
            'item_coupons' => $this->getItemCoupons(),
            'discount_code' => $this->getDiscountCode(),
            'product_promotions' => $this->productPromotions->toArray(),
            'order_promotions' => $this->orderPromotions->toArray(),
        ];
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     * @return self
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
