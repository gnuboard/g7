<?php

namespace Modules\Sirsoft\Ecommerce\Exports;

/**
 * 내보내기 필드의 값 종류
 *
 * 선언에 종류만 적으면 값 변환·합계·수식 주입 방어가 따라온다(D19). 문자열 계열(string·enum·translatable·
 * list·boolean)은 셀이 수식 문자로 시작할 수 있으므로 방어 대상이다. 숫자 계열(integer·decimal·money)은
 * 실제 숫자 값만 음수 금액을 훼손하지 않도록 그대로 두고, 숫자로 해석되지 않는 값은 방어한다.
 */
enum ExportFieldType: string
{
    case STRING = 'string';             // 문자열
    case INTEGER = 'integer';           // 정수
    case DECIMAL = 'decimal';           // 소수(무게·부피·비율)
    case MONEY = 'money';               // 금액(통화 필드와 함께, 통화별 합계 대상)
    case DATETIME = 'datetime';         // 일시(보는 사람 시간대)
    case DATE = 'date';                 // 날짜
    case BOOLEAN = 'boolean';           // 예/아니오
    case ENUM = 'enum';                 // 상태값 → 라벨
    case TRANSLATABLE = 'translatable'; // 언어별 값 ({"ko": …, "en": …}) → 다국어 형식 3종
    case LIST = 'list';                 // 여러 값 → "; " 로 잇기

    /**
     * 숫자 계열 필드인지 반환합니다. CSV 작성기는 셀 값도 숫자인 경우에만 그대로 씁니다.
     *
     * @return bool 숫자 계열이면 true
     */
    public function isNumeric(): bool
    {
        return in_array($this, [self::INTEGER, self::DECIMAL, self::MONEY], true);
    }
}
