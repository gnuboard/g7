<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 재활성 복원용 재계산 결과 묶음 (Q26)
 *
 * 취소 엔진과 같은 입력으로 활성 행 전체를 다시 계산한 값이다. 결제금액(`total_paid_amount`)·사용
 * 포인트·현금성 금액은 헤더 갱신값에서 빠져 있다 — 앞의 것은 결제 기록 단계가, 뒤의 둘은 장부 정합
 * 단계가 따로 쓴다.
 */
final class ReactivationRecalcResult
{
    /**
     * @param  array<string, mixed>  $orderUpdates  헤더 갱신값 (결제금액·사용 포인트·현금성 금액 제외)
     * @param  array<int, array<string, mixed>>  $optionUpdates  주문 행 id => 금액 갱신값
     * @param  array<int, array{base: int, extra: int}>  $shippingShares  주문 행 id => 배송비 몫 (OrderShippingAmountWriter 가 쓴다)
     * @param  array<int, int>  $appliedCouponIssueIds  재계산에 적용된 쿠폰 발급 ID
     * @param  float  $pointsUsedAfter  재계산 사용 포인트
     * @param  float  $cashEquivalentAfter  재계산 현금성 금액
     * @param  array<string, float|int>  $totalsAfter  재계산 합계 스냅샷
     */
    public function __construct(
        public readonly array $orderUpdates,
        public readonly array $optionUpdates,
        public readonly array $shippingShares,
        public readonly array $appliedCouponIssueIds,
        public readonly float $pointsUsedAfter,
        public readonly float $cashEquivalentAfter,
        public readonly array $totalsAfter,
    ) {}

    /**
     * 배열 표현
     *
     * @return array<string, mixed> 재계산 결과
     */
    public function toArray(): array
    {
        return [
            'order_updates' => $this->orderUpdates,
            'option_updates' => $this->optionUpdates,
            'shipping_shares' => $this->shippingShares,
            'applied_coupon_issue_ids' => $this->appliedCouponIssueIds,
            'points_used_after' => $this->pointsUsedAfter,
            'cash_equivalent_after' => $this->cashEquivalentAfter,
            'totals_after' => $this->totalsAfter,
        ];
    }
}
