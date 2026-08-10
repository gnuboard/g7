<?php

namespace App\Enums;

/**
 * 목록 총 건수(total)와 실제 매칭 건수의 관계
 *
 * 대용량 목록에서 `COUNT(*)` 전량 집계는 비용이 커서 상한을 걸고 센다.
 * 상한 이하면 센 값이 곧 실제 건수이고, 상한을 넘으면 "그 이상"이라는 사실만 알 수 있다.
 * 화면은 이 값을 보고 "1,234건" 과 "10,000건 이상" 을 구분해 표기한다.
 */
enum TotalRelation: string
{
    /** 정확 — total 이 실제 매칭 건수와 같다 */
    case Exact = 'exact';

    /** 하한 — 실제 매칭 건수가 total 이상이다 (상한 초과로 정확히 세지 않음) */
    case AtLeast = 'at_least';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string>
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
     * 총 건수가 정확한지 여부를 반환합니다.
     *
     * @return bool 정확하면 true
     */
    public function isExact(): bool
    {
        return $this === self::Exact;
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string lang 키 해석 결과
     */
    public function label(): string
    {
        return __('pagination.total_relation.'.$this->value);
    }
}
