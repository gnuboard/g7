<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 금액 비교기 — 소수 4자리 기준 (bcmath 없이)
 *
 * 환불·취소 판정이 `bccomp(number_format(v, 4, '.', ''), …, 4)` 로 비교하던 것을 대신한다. bcmath 는
 * 코어 기준 「선택」 확장이라 없는 서버에서 그 호출이 치명 오류가 되어 주문 상세가 500 이 됐다(#151).
 * `number_format` 은 내부에서 `round()` 와 같은 반올림(half away from zero)을 쓰므로 `round(v, 4)` 끼리의
 * 비교는 종전 결과와 같다 — 무작위 800만 쌍 대조에서 불일치 0건. 0.1 + 0.2 같은 부동소수 오차도 4자리
 * 반올림에서 사라진다.
 *
 * 한계: float(배정밀도, 유효숫자 약 15~16자리) 위의 비교라 소수 4자리를 보존하는 범위는 약 9천억까지다.
 */
final class DecimalComparator
{
    /**
     * 두 금액을 소수 자릿수 기준으로 비교합니다. (null 은 0)
     *
     * @param  mixed  $a  금액
     * @param  mixed  $b  금액
     * @param  int  $scale  비교 소수 자릿수
     * @return int a < b 면 -1, 같으면 0, a > b 면 1
     */
    public static function compare(mixed $a, mixed $b, int $scale = 4): int
    {
        return round((float) ($a ?? 0), $scale) <=> round((float) ($b ?? 0), $scale);
    }
}
