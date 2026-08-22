<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;

/**
 * 주문 변경 계산 결과 DTO
 *
 * 재계산 전/후 금액 차이와 각 테이블 업데이트 정보를 담습니다.
 */
class AdjustmentResult
{
    /**
     * @param  float  $refundAmount  PG 환불금액 (음수면 추가결제 필요)
     * @param  float  $refundPointsAmount  마일리지 환불액
     * @param  float  $originalPaidAmount  원 결제금액
     * @param  float  $recalculatedPaidAmount  재계산 결제금액
     * @param  float  $shippingDifference  배송비 차이 (양수: 환불 / 음수: 추가결제)
     * @param  float  $discountDifference  할인 차이 (양수: 할인 감소분)
     * @param  OrderCalculationResult|null  $recalculated  재계산 결과 DTO
     * @param  array  $adjustedItems  변경된 아이템 정보 [{order_option_id, cancel_quantity, cancel_amount}]
     * @param  array  $orderUpdates  주문 테이블 업데이트 데이터
     * @param  array  $optionUpdates  옵션 테이블 업데이트 데이터 [{order_option_id => [...]}]
     * @param  array  $shippingUpdates  배송 테이블 업데이트 데이터 [{order_shipping_id => [...]}]
     * @param  array  $originalSnapshot  재계산 전 주문 금액 스냅샷
     * @param  array  $recalculatedSnapshot  재계산 후 주문 금액 스냅샷
     * @param  array  $restoredCouponIssueIds  복원 대상 쿠폰 발급 ID 배열
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  float  $remainingPgBalance  환불 후 잔여 PG 잔액
     * @param  float  $remainingPointsBalance  환불 후 잔여 포인트 잔액
     * @param  array  $restoredCoupons  복원 쿠폰 정보 [{coupon_name, discount_amount}]
     * @param  array  $shippingDetails  배송비 정책별 상세 [{policy_name, base_difference, extra_difference, total_difference}]
     * @param  array|null  $mcRefundAmount  다통화 PG 환불금액 {통화코드 => 금액}
     * @param  array|null  $mcRefundPointsAmount  다통화 마일리지 환불금액 {통화코드 => 금액}
     * @param  array|null  $mcRefundShippingAmount  다통화 배송비 환불금액 {통화코드 => 금액}
     * @param  array|null  $mcOriginalSnapshot  원 주문 다통화 스냅샷 {mc_subtotal_amount, mc_total_paid_amount}
     * @param  array|null  $mcRecalculatedSnapshot  재계산 다통화 스냅샷 {mc_subtotal_amount, mc_total_paid_amount}
     * @param  array  $originalCoupons  원 주문 쿠폰 상세 [{name, target_type, discount_amount}]
     * @param  array  $recalculatedCoupons  재계산 쿠폰 상세 [{name, target_type, discount_amount}]
     */
    public function __construct(
        public float $refundAmount = 0,
        public float $refundPointsAmount = 0,
        public float $originalPaidAmount = 0,
        public float $recalculatedPaidAmount = 0,
        public float $shippingDifference = 0,
        public float $discountDifference = 0,
        public ?OrderCalculationResult $recalculated = null,
        public array $adjustedItems = [],
        public array $orderUpdates = [],
        public array $optionUpdates = [],
        public array $shippingUpdates = [],
        public array $originalSnapshot = [],
        public array $recalculatedSnapshot = [],
        public array $restoredCouponIssueIds = [],
        public RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
        public float $remainingPgBalance = 0,
        public float $remainingPointsBalance = 0,
        public array $restoredCoupons = [],
        public array $shippingDetails = [],
        public ?array $mcRefundAmount = null,
        public ?array $mcRefundPointsAmount = null,
        public ?array $mcRefundShippingAmount = null,
        public ?array $mcOriginalSnapshot = null,
        public ?array $mcRecalculatedSnapshot = null,
        public array $originalCoupons = [],
        public array $recalculatedCoupons = [],
        // 환불 총액·잔액의 base 통화 포맷 문자열 + 결제 통화 포함 다통화 포맷(취소 모달 표기용).
        public array $refundFormatted = [],
    ) {}

    /**
     * 추가결제가 필요한지 여부를 반환합니다.
     *
     * @return bool 추가결제 필요 여부 (환불액이 음수면 true)
     */
    public function requiresAdditionalPayment(): bool
    {
        return $this->refundAmount < 0;
    }

    /**
     * 부분취소로 인해 고객이 추가 결제해야 하는(=환불액 음수) 상황인지 반환합니다.
     *
     * 취소 계산기의 환불액(refundAmount/refundPointsAmount)은 그대로 두고,
     * "실제 결제가 발생한 주문에서만" 추가결제 필요 여부로 차단을 판정합니다.
     * 실결제 0원(미입금·운영자 0원 결제완료)은 환불/추가청구 개념이 없어 차단하지 않습니다.
     *
     * 부분취소 시 쿠폰 조건 미달(최소 주문금액 등)로 할인이 소멸하면
     * 재계산 결제금액이 원 결제금액보다 높아질 수 있으며, 이때 실결제 주문이라면
     * 고객에게 추가 결제를 요구해야 하므로 취소를 차단합니다.
     *
     * @return bool 취소 차단 여부
     */
    public function isCancelBlocked(): bool
    {
        // 실결제 0원(미입금·운영자 0원 결제완료) → 환불/추가청구 개념 없음 → 무조건 허용
        if (! $this->hasActualPayment()) {
            return false;
        }

        // 실결제 주문: (원결제 + 원포인트) 대비 (재계산결제 + 재계산포인트) 가 커지면 추가결제 필요 → 차단.
        $originalTotal = ((float) ($this->originalSnapshot['total_paid_amount'] ?? 0))
            + ((float) ($this->originalSnapshot['total_points_used_amount'] ?? 0));
        $recalculatedTotal = ((float) ($this->recalculatedSnapshot['total_paid_amount'] ?? 0))
            + ((float) ($this->recalculatedSnapshot['total_points_used_amount'] ?? 0));

        // 반올림 오차(decimal·소수통화) 허용 — 정수 KRW 에는 영향 없음.
        return ($recalculatedTotal - $originalTotal) > 0.01;
    }

    /**
     * 실제 결제가 발생한 주문인지 반환합니다.
     *
     * 스냅샷에 실려온 실결제 신호(payment_status === PAID 또는 실사용 금액 합 > 0)를 읽습니다.
     * 운영자가 order_status 만 강제로 결제완료로 바꾼 0원 주문과,
     * 실제 입금/결제가 완료된 주문을 구분하기 위한 게이트입니다.
     *
     * @return bool 실결제 발생 여부
     */
    public function hasActualPayment(): bool
    {
        return (bool) ($this->originalSnapshot['has_actual_payment'] ?? false);
    }

    /**
     * 추가결제 필요 금액을 반환합니다.
     *
     * @return float 추가결제 필요 금액 (없으면 0)
     */
    public function getAdditionalPaymentAmount(): float
    {
        return $this->requiresAdditionalPayment() ? abs($this->refundAmount) : 0;
    }

    /**
     * 총 환불금액(PG + 마일리지)을 반환합니다.
     *
     * @return float 총 환불금액 (PG 환불액 + 마일리지 환불액)
     */
    public function getTotalRefundAmount(): float
    {
        return max(0, $this->refundAmount) + $this->refundPointsAmount;
    }

    /**
     * 미리보기 응답용 배열을 반환합니다.
     *
     * @return array 미리보기 응답 배열
     */
    public function toPreviewArray(): array
    {
        return [
            'refund_amount' => $this->refundAmount,
            'refund_points_amount' => $this->refundPointsAmount,
            'original_paid_amount' => $this->originalPaidAmount,
            'recalculated_paid_amount' => $this->recalculatedPaidAmount,
            'shipping_difference' => $this->shippingDifference,
            'discount_difference' => $this->discountDifference,
            'additional_payment_amount' => $this->getAdditionalPaymentAmount(),
            'cancelled_items' => $this->adjustedItems,
            'refund_priority' => $this->refundPriority->value,
            'remaining_pg_balance' => $this->remainingPgBalance,
            'remaining_points_balance' => $this->remainingPointsBalance,
            'refund_total' => max(0, $this->refundAmount) + $this->refundPointsAmount,
            // base 통화 포맷(primary) + 결제 통화 포함 다통화 포맷(secondary 병기). 취소 모달 환불 표기 SSoT.
            'refund_formatted' => $this->refundFormatted,
            'restored_coupons' => $this->restoredCoupons,
            'shipping_details' => $this->shippingDetails,
            'mc_refund_amount' => $this->mcRefundAmount,
            'mc_refund_points_amount' => $this->mcRefundPointsAmount,
            'mc_refund_shipping_amount' => $this->mcRefundShippingAmount,
            'original_snapshot' => $this->originalSnapshot,
            'recalculated_snapshot' => $this->recalculatedSnapshot,
            'mc_original_snapshot' => $this->mcOriginalSnapshot,
            'mc_recalculated_snapshot' => $this->mcRecalculatedSnapshot,
            'original_coupons' => $this->originalCoupons,
            'recalculated_coupons' => $this->recalculatedCoupons,
            'cancel_blocked' => $this->isCancelBlocked(),
            'cancel_blocked_reason' => $this->isCancelBlocked()
                ? __('sirsoft-ecommerce::exceptions.cancel_refund_negative')
                : null,
        ];
    }
}
