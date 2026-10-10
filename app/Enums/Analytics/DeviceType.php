<?php

namespace App\Enums\Analytics;

/**
 * 기기 종류 Enum
 */
enum DeviceType: string
{
    /**
     * 데스크톱
     */
    case Desktop = 'desktop';

    /**
     * 휴대전화
     */
    case Mobile = 'mobile';

    /**
     * 태블릿
     */
    case Tablet = 'tablet';

    /**
     * 그 밖의 기기
     */
    case Other = 'other';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인합니다.
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 라벨
     */
    public function label(): string
    {
        return __('analytics.device_type.'.$this->value);
    }
}
