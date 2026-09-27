<?php

namespace App\Enums\Analytics;

/**
 * 방문 수집 경로 Enum
 *
 * 방문이 어느 경로로 기록됐는지 나타냅니다. 서버 폴백만으로 잡힌 방문이 많다면
 * 브라우저에서 비콘이 막히고 있다는 신호입니다.
 */
enum VisitSource: string
{
    /**
     * 브라우저 비콘
     */
    case Beacon = 'beacon';

    /**
     * 서버 폴백
     */
    case Server = 'server';

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
        return __('analytics.visit_source.'.$this->value);
    }
}
