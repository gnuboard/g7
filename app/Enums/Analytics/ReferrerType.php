<?php

namespace App\Enums\Analytics;

/**
 * 유입 종류 Enum
 *
 * 방문자가 어디서 왔는지 분류합니다.
 */
enum ReferrerType: string
{
    /**
     * 직접 유입 — 주소 입력·즐겨찾기이거나 유입 정보가 없는 경우
     */
    case Direct = 'direct';

    /**
     * 검색엔진
     */
    case Search = 'search';

    /**
     * 다른 사이트
     */
    case Website = 'website';

    /**
     * 캠페인 링크
     */
    case Campaign = 'campaign';

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
        return __('analytics.referrer_type.'.$this->value);
    }
}
