<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 클레임 추가결제 상태 Enum
 *
 * 클레임 배송비·차액의 별도 결제 건(`ecommerce_claim_payments.payment_status`)이
 * 갖는 상태다. 철회·반려 시 기결제 건은 자동 환급(PG 취소/마일리지 복원)되어
 * REFUNDED 로 종결된다.
 */
enum ClaimPaymentStatusEnum: string
{
    case PENDING = 'pending';        // 결제대기
    case PAID = 'paid';              // 결제완료
    case FAILED = 'failed';          // 결제실패
    case CANCELLED = 'cancelled';    // 결제취소
    case REFUNDED = 'refunded';      // 환급완료

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_payment_status.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.claim_payment_status.'.$this->value;
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string 뱃지 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::PAID => 'success',
            self::FAILED => 'danger',
            self::CANCELLED => 'secondary',
            self::REFUNDED => 'info',
        };
    }

    /**
     * 최종(터미널) 상태 여부를 반환합니다.
     *
     * @return bool 최종 상태이면 true
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::CANCELLED, self::REFUNDED], true);
    }

    /**
     * 결제 금액이 실제로 수납된 상태인지 반환합니다.
     *
     * @return bool 수납 완료 여부
     */
    public function isSettled(): bool
    {
        return $this === self::PAID;
    }

    /**
     * 환급 대상 상태인지 반환합니다.
     *
     * 철회·반려 시 이미 수납된 건만 환급 대상이 된다.
     *
     * @return bool 환급 대상 여부
     */
    public function isRefundable(): bool
    {
        return $this === self::PAID;
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<string> 상태 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<array{value: string, label: string}> 셀렉트 옵션 배열
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
