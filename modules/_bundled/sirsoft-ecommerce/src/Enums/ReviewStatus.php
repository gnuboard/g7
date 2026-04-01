<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 리뷰 상태 Enum
 */
enum ReviewStatus: string
{
    case VISIBLE = 'visible';   // 전시중
    case HIDDEN = 'hidden';     // 숨김

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.review_status.'.$this->value);
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string
     */
    public function variant(): string
    {
        return match ($this) {
            self::VISIBLE => 'success',
            self::HIDDEN => 'secondary',
        };
    }

    /**
     * 뱃지 색상을 반환합니다.
     *
     * @return string
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::VISIBLE => 'blue',
            self::HIDDEN => 'gray',
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
