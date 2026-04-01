<?php

use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

if (! function_exists('ecommerce_format_price')) {
    /**
     * 이커머스 가격을 포맷팅합니다.
     *
     * @param  int|float  $price  가격
     * @param  string|null  $currencyCode  통화 코드 (null이면 기본 통화)
     * @return string 포맷팅된 가격
     */
    function ecommerce_format_price(int|float $price, ?string $currencyCode = null): string
    {
        $service = app(CurrencyConversionService::class);
        $code = $currencyCode ?? $service->getDefaultCurrency();

        return $service->formatPrice($price, $code);
    }
}
