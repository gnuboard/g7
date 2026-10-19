<?php

namespace Modules\Sirsoft\Ecommerce\Exports;

/**
 * 금액·수량 합계용 10진 계산
 *
 * float 로 더하면 0.1 + 0.2 같은 오차가 합계 행에 남는다. bcmath 는 선택 확장이라 기대할 수 없으므로
 * 값을 소수 4자리 정수(× 10⁴)로 바꿔 더한다. 금액 컬럼은 소수 2자리라 4자리면 충분하고, 64비트 정수로
 * 9천억 단위까지 정확하다.
 */
final class ExportDecimal
{
    private const SCALE = 4;

    /**
     * 숫자 원값을 정수 눈금(× 10⁴)으로 바꿉니다.
     *
     * @param  mixed  $value  숫자 원값 (문자열·정수·실수)
     * @return int|null 눈금 값 (숫자가 아니면 null)
     */
    public static function toUnits(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value * 10 ** self::SCALE;
        }

        $string = is_float($value) ? sprintf('%.'.self::SCALE.'F', $value) : trim((string) $value);

        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $string, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }

        $fraction = substr(str_pad($m[3] ?? '', self::SCALE, '0'), 0, self::SCALE);
        $units = (int) ($m[2] === '' ? '0' : $m[2]) * 10 ** self::SCALE + (int) $fraction;

        return $m[1] === '-' ? -$units : $units;
    }

    /**
     * 정수 눈금을 소수 문자열로 되돌립니다(끝자리 0 은 지운다).
     *
     * @param  int  $units  눈금 값
     * @return string 소수 문자열 (예: 15000, 12.5, -300.25)
     */
    public static function fromUnits(int $units): string
    {
        $negative = $units < 0;
        $abs = abs($units);
        $base = 10 ** self::SCALE;
        $integer = intdiv($abs, $base);
        $fraction = rtrim(str_pad((string) ($abs % $base), self::SCALE, '0', STR_PAD_LEFT), '0');

        return ($negative ? '-' : '').$integer.($fraction !== '' ? '.'.$fraction : '');
    }

    /**
     * 숫자 원값을 표기용 소수 문자열로 정규화합니다(천 단위 구분·통화 기호 없음).
     *
     * @param  mixed  $value  숫자 원값
     * @return string|null 정규화한 문자열 (숫자가 아니면 null)
     */
    public static function normalize(mixed $value): ?string
    {
        $units = self::toUnits($value);

        return $units === null ? null : self::fromUnits($units);
    }
}
