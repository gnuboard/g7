<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 소수 표기 다듬기.
 *
 * 소수 형변환(`decimal:2`) 값을 `%` 같은 표시 문구에 넣으면 「10.00」처럼 끝 0 이 붙는다.
 * 금액이 아닌 비율·수량 표시에서 그 끝 0 과 점을 떼어 낸다(금액은 통화 서식 함수를 쓴다).
 */
final class DecimalTrim
{
    /**
     * 소수 끝의 0 과 남는 점을 떼어 냅니다.
     *
     * @param  float|int|string|null  $value  숫자 값 (예: `10.00`, `12.50`)
     * @return string 다듬은 문자열 (예: `10`, `12.5`). 빈 값은 `0`
     */
    public static function trim(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $text = is_string($value) ? trim($value) : (string) $value;

        if (! is_numeric($text)) {
            return $text;
        }

        if (! str_contains($text, '.')) {
            return $text;
        }

        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }
}
