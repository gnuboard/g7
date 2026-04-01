<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 주문 계산 결과 DTO
 *
 * 9단계 주문 계산 로직의 최종 결과를 담는 메인 DTO입니다.
 * 장바구니 금액 표시, 주문서 작성, 부분취소, 할인쿠폰적용, 취소 등에서 사용됩니다.
 */
class OrderCalculationResult
{
    /**
     * @param  ItemCalculation[]  $items  아이템별 계산 결과
     * @param  Summary  $summary  합계 정보
     * @param  PromotionsSummary  $promotions  적용된 프로모션 (상품별/주문별 분리)
     * @param  ValidationError[]  $validationErrors  쿠폰 검증 오류
     * @param  array<string, mixed>  $metadata  플러그인 확장용 메타데이터
     *         - 플러그인별 추가 계산 결과 저장
     *         - 예: deposit_calculation, grade_discount_details 등
     */
    public function __construct(
        public array $items = [],
        public ?Summary $summary = null,
        public ?PromotionsSummary $promotions = null,
        public array $validationErrors = [],
        public array $metadata = [],
    ) {
        $this->summary = $summary ?? new Summary;
        $this->promotions = $promotions ?? new PromotionsSummary;
    }

    /**
     * 검증 오류가 있는지 확인합니다.
     */
    public function hasValidationErrors(): bool
    {
        return count($this->validationErrors) > 0;
    }

    /**
     * 특정 쿠폰에 대한 검증 오류를 반환합니다.
     *
     * @param  int  $couponId  쿠폰 ID
     */
    public function getValidationError(int $couponId): ?ValidationError
    {
        foreach ($this->validationErrors as $error) {
            if ($error->couponId === $couponId) {
                return $error;
            }
        }

        return null;
    }

    /**
     * 중복 불가 쿠폰이 적용되었는지 확인합니다.
     */
    public function hasExclusiveCouponApplied(): bool
    {
        if ($this->promotions === null) {
            return false;
        }

        foreach ($this->promotions->getAllCoupons() as $coupon) {
            if ($coupon->isExclusiveCoupon()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 적용된 모든 쿠폰 발급 ID 목록을 반환합니다.
     *
     * @return int[]
     */
    public function getAppliedCouponIds(): array
    {
        if ($this->promotions === null) {
            return [];
        }

        $ids = [];
        foreach ($this->promotions->getAllCoupons() as $coupon) {
            $ids[] = $coupon->couponIssueId;
        }

        return array_unique($ids);
    }

    /**
     * 배송비를 정책별로 집계하여 반환합니다.
     *
     * @return array<int, array{base: float, extra: float}> [policyId => ['base' => 금액, 'extra' => 금액]]
     */
    public function getShippingByPolicy(): array
    {
        $byPolicy = [];
        foreach ($this->items as $item) {
            if ($item->appliedShippingPolicy === null) {
                continue;
            }
            $policyId = $item->appliedShippingPolicy->policyId;
            if (! isset($byPolicy[$policyId])) {
                $byPolicy[$policyId] = ['base' => 0, 'extra' => 0];
            }
            $byPolicy[$policyId]['base'] = (float) $item->appliedShippingPolicy->shippingAmount;
            $byPolicy[$policyId]['extra'] = (float) $item->appliedShippingPolicy->extraShippingAmount;
        }

        return $byPolicy;
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        $result = [
            'items' => array_map(fn (ItemCalculation $item) => $item->toArray(), $this->items),
            'summary' => $this->summary->toArray(),
            'promotions' => $this->promotions->toArray(),
            'validation_errors' => array_map(fn (ValidationError $error) => $error->toArray(), $this->validationErrors),
        ];

        if (! empty($this->metadata)) {
            $result['metadata'] = $this->metadata;
        }

        return $result;
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        $items = array_map(
            fn (array $item) => ItemCalculation::fromArray($item),
            $data['items'] ?? []
        );

        $validationErrors = array_map(
            fn (array $error) => ValidationError::fromArray($error),
            $data['validation_errors'] ?? []
        );

        return new self(
            items: $items,
            summary: isset($data['summary']) ? Summary::fromArray($data['summary']) : null,
            promotions: isset($data['promotions']) ? PromotionsSummary::fromArray($data['promotions']) : null,
            validationErrors: $validationErrors,
            metadata: $data['metadata'] ?? [],
        );
    }
}
