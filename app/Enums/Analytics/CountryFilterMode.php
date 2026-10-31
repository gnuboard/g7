<?php

namespace App\Enums\Analytics;

/**
 * 국가 필터 방식 Enum
 *
 * GeoIP 가 꺼져 있으면 국가를 알 수 없어 이 필터는 동작하지 않습니다.
 */
enum CountryFilterMode: string
{
    /**
     * 사용 안 함
     */
    case None = 'none';

    /**
     * 지정한 국가만 수집
     */
    case Include = 'include';

    /**
     * 지정한 국가를 제외
     */
    case Exclude = 'exclude';

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
        return __('analytics.country_filter_mode.'.$this->value);
    }
}
