<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송국가 Enum
 */
enum ShippingCountryEnum: string
{
    case KR = 'KR';   // 한국
    case US = 'US';   // 미국
    case CN = 'CN';   // 중국
    case JP = 'JP';   // 일본

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.shipping_country.'.$this->value);
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
     * 국기 이모지를 반환합니다.
     *
     * @return string
     */
    public function flag(): string
    {
        return match ($this) {
            self::KR => "\u{1F1F0}\u{1F1F7}", // 🇰🇷
            self::US => "\u{1F1FA}\u{1F1F8}", // 🇺🇸
            self::CN => "\u{1F1E8}\u{1F1F3}", // 🇨🇳
            self::JP => "\u{1F1EF}\u{1F1F5}", // 🇯🇵
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
            'flag' => $case->flag(),
        ], self::cases());
    }

    /**
     * 국내 배송국가인지 확인합니다.
     *
     * @return bool
     */
    public function isDomestic(): bool
    {
        return $this === self::KR;
    }

    /**
     * 해외 배송국가 목록을 반환합니다.
     *
     * @return array
     */
    public static function internationalCountries(): array
    {
        return [
            self::US,
            self::CN,
            self::JP,
        ];
    }
}
