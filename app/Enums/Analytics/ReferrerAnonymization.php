<?php

namespace App\Enums\Analytics;

/**
 * 유입 URL 익명화 방식 Enum
 */
enum ReferrerAnonymization: string
{
    /**
     * 원본 URL 그대로 저장
     */
    case None = 'none';

    /**
     * 도메인까지만 저장하고 경로는 버린다
     */
    case DomainOnly = 'domain_only';

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
        return __('analytics.referrer_anonymization.'.$this->value);
    }
}
