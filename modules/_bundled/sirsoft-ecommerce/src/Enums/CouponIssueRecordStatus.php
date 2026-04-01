<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 쿠폰 발급 내역 상태 Enum
 */
enum CouponIssueRecordStatus: string
{
    case AVAILABLE = 'available';  // 사용가능
    case USED = 'used';            // 사용완료
    case EXPIRED = 'expired';      // 만료
    case CANCELLED = 'cancelled';  // 취소

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.coupon_issue_record_status.'.$this->value);
    }

    /**
     * 배지 색상을 반환합니다.
     *
     * @return string
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::AVAILABLE => 'blue',
            self::USED => 'green',
            self::EXPIRED => 'gray',
            self::CANCELLED => 'red',
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
