<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 환불 옵션 상태 Enum
 */
enum RefundOptionStatusEnum: string
{
    case REQUESTED = 'requested';      // 환불 신청
    case APPROVED = 'approved';        // 환불 승인
    case PROCESSING = 'processing';    // 환불 처리 중
    case ON_HOLD = 'on_hold';          // 환불 보류
    case COMPLETED = 'completed';      // 처리 완료
    case REJECTED = 'rejected';        // 환불 반려

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.refund_option_status.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string
     */
    public function variant(): string
    {
        return match ($this) {
            self::REQUESTED => 'secondary',
            self::APPROVED => 'info',
            self::PROCESSING => 'warning',
            self::ON_HOLD => 'dark',
            self::COMPLETED => 'success',
            self::REJECTED => 'danger',
        };
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
