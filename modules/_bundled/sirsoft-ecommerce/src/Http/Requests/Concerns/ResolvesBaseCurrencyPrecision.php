<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

/**
 * 기본통화 소수 자릿수 해석 Trait
 *
 * 가격 검증 규칙은 통화의 소수 자릿수에 따라 달라진다 (KRW=0 → `integer`,
 * USD=2 → `decimal:0,2`). 단건 등록/수정에만 있던 이 규칙을 일괄 수정 경로에서도
 * 동일하게 쓰기 위해 추출했다.
 */
trait ResolvesBaseCurrencyPrecision
{
    /**
     * 기본통화의 소수 자릿수를 반환합니다.
     *
     * 환경설정 language_currency 의 default_currency 에 매칭되는 통화의
     * decimal_places 값(예: KRW=0, USD=2)을 반환합니다.
     *
     * @return int 소수 자릿수 (미설정 시 0)
     */
    protected function baseCurrencyDecimalPlaces(): int
    {
        $settings = g7_module_settings('sirsoft-ecommerce', 'language_currency');
        $default = $settings['default_currency'] ?? 'KRW';

        foreach ($settings['currencies'] ?? [] as $currency) {
            if (($currency['code'] ?? null) === $default) {
                return (int) ($currency['decimal_places'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * 기본통화 정밀도에 맞는 가격 검증 규칙을 반환합니다.
     *
     * @return string 검증 규칙 (`integer` 또는 `decimal:0,N`)
     */
    protected function basePriceRule(): string
    {
        $decimalPlaces = $this->baseCurrencyDecimalPlaces();

        return $decimalPlaces > 0 ? 'decimal:0,'.$decimalPlaces : 'integer';
    }
}
