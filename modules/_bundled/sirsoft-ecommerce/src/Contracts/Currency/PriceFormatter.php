<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Currency;

/**
 * 금액 표시 형식 공개 계약
 *
 * 쇼핑몰 통화 설정(기호, 소수 자릿수)대로 금액 문자열을 만든다. 서비스 컨테이너로 주입받는다.
 *
 * @since 1.4.0
 */
interface PriceFormatter
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * 금액을 표시 문자열로 바꿉니다.
     *
     * @param  int|float  $amount  금액
     * @param  string|null  $currency  통화 코드(null 이면 쇼핑몰 기본 통화)
     * @return string 표시 문자열
     */
    public function format(int|float $amount, ?string $currency = null): string;
}
