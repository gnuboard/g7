<?php

namespace App\Enums\Analytics;

/**
 * 방문자 유형 Enum
 *
 * 집계 행을 나누는 기준입니다. 「전체」 행을 따로 저장하지 않고 화면에서 고른
 * 유형을 더해 씁니다.
 */
enum VisitorType: string
{
    /**
     * 비회원
     */
    case Guest = 'guest';

    /**
     * 회원
     */
    case Member = 'member';

    /**
     * 관리자
     */
    case Admin = 'admin';

    /**
     * 봇·크롤러
     */
    case Bot = 'bot';

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
        return __('analytics.visitor_type.'.$this->value);
    }
}
