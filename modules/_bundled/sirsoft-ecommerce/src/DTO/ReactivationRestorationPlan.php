<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 재활성 복원 계획 — 되살릴 상품 행과 다시 계산한 주문 합계 (Q26·Q27)
 *
 * 미리보기와 실행이 같은 값을 쓰도록 DB 변경 없이 만든다. 쿠폰 충돌(다른 주문이 이미 쓴 쿠폰·회수된
 * 쿠폰)이 있으면 실행이 막힌다(Q27). `legacySnapshot` 은 최초 취소 직전 프로모션 스냅샷이 없는
 * 레거시 주문이라 현재 스냅샷으로 다시 계산했다는 표시다.
 */
final class ReactivationRestorationPlan
{
    /**
     * @param  bool  $applicable  복원 대상인지
     * @param  string|null  $reason  해당 없음 사유 (no_cancelled_rows | no_active_items | null)
     * @param  array<int, array{order_option_id: int, quantity: int, row_quantity: int}>  $revivals  되살릴 행
     * @param  array<string, float>  $totalsBefore  현재 합계
     * @param  array<string, float>  $totalsAfter  복원 뒤 합계
     * @param  array<int, int>  $couponsToReclaim  다시 「사용됨」으로 돌릴 쿠폰 발급 ID
     * @param  array<int, int>  $couponsToRelease  재계산에서 빠져 되돌릴 쿠폰 발급 ID
     * @param  array<int, array{coupon_issue_id: int, coupon_name: ?string, reason: string, other_order_number: ?string}>  $couponConflicts  쿠폰 충돌
     * @param  bool  $legacySnapshot  최초 취소 직전 스냅샷이 없어 현재 스냅샷을 썼는지
     * @param  array|null  $promotionsSnapshot  재계산에 쓴 프로모션 스냅샷 (null 이면 현재 값)
     * @param  float  $usePoints  재계산 사용 포인트 입력
     */
    public function __construct(
        public readonly bool $applicable,
        public readonly ?string $reason = null,
        public readonly array $revivals = [],
        public readonly array $totalsBefore = [],
        public readonly array $totalsAfter = [],
        public readonly array $couponsToReclaim = [],
        public readonly array $couponsToRelease = [],
        public readonly array $couponConflicts = [],
        public readonly bool $legacySnapshot = false,
        public readonly ?array $promotionsSnapshot = null,
        public readonly float $usePoints = 0.0,
    ) {}

    /**
     * 재활성 전이가 아닌 경우의 빈 계획
     *
     * @return self 빈 계획
     */
    public static function none(): self
    {
        return new self(false);
    }

    /**
     * 재활성이지만 복원할 것이 없는 계획
     *
     * @param  string  $reason  사유
     * @return self 해당 없음 계획
     */
    public static function notApplicable(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * 되살리는 수량 합계
     *
     * @return int 수량
     */
    public function revivedQuantity(): int
    {
        return (int) array_sum(array_column($this->revivals, 'quantity'));
    }

    /**
     * 미리보기·활동 로그용 배열
     *
     * @return array<string, mixed> 계획
     */
    public function toArray(): array
    {
        return [
            'applicable' => $this->applicable,
            'reason' => $this->reason,
            'revived_option_count' => count($this->revivals),
            'revived_quantity' => $this->revivedQuantity(),
            'totals_before' => $this->totalsBefore,
            'totals_after' => $this->totalsAfter,
            'coupon_conflicts' => $this->couponConflicts,
            'legacy_snapshot' => $this->legacySnapshot,
        ];
    }
}
