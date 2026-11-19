<?php

namespace Modules\Sirsoft\Ecommerce\Services\Bridge;

use Modules\Sirsoft\Ecommerce\Contracts\Currency\PriceFormatter;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

/**
 * 금액 표시 형식 공개 계약 구현 — 통화 환산 서비스의 표시 형식에 위임만 한다(전역 헬퍼 ecommerce_format_price 와 같은 결과).
 *
 * @internal 다른 확장은 {@see PriceFormatter} 로 주입받는다.
 */
class EcommercePriceFormatter implements PriceFormatter
{
    /**
     * {@inheritDoc}
     *
     * @param  int|float  $amount  금액
     * @param  string|null  $currency  통화 코드
     * @return string 표시 문자열
     */
    public function format(int|float $amount, ?string $currency = null): string
    {
        $service = app(CurrencyConversionService::class);

        return $service->formatPrice($amount, $currency ?? $service->getDefaultCurrency());
    }
}
