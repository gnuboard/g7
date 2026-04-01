<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 쿠폰 발급 조건 Enum
 */
enum CouponIssueCondition: string
{
    case MANUAL = 'manual';                // 수동발급
    case SIGNUP = 'signup';                // 회원가입
    case FIRST_PURCHASE = 'first_purchase'; // 첫구매
    case BIRTHDAY = 'birthday';            // 생일

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 번역 키를 반환합니다.
     *
     * @return string
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.coupon_issue_condition.'.$this->value;
    }

    /**
     * 배지 색상을 반환합니다.
     *
     * @return string
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::MANUAL => 'orange',
            self::SIGNUP => 'blue',
            self::FIRST_PURCHASE => 'teal',
            self::BIRTHDAY => 'pink',
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
