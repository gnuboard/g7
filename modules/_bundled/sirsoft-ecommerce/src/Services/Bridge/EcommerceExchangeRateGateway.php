<?php

namespace Modules\Sirsoft\Ecommerce\Services\Bridge;

use Modules\Sirsoft\Ecommerce\Contracts\Currency\Data\CurrencySettings;
use Modules\Sirsoft\Ecommerce\Contracts\Currency\Data\ExchangeRateApplyResult;
use Modules\Sirsoft\Ecommerce\Contracts\Currency\ExchangeRateGateway;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 환율 반영 공개 계약 구현 — 쇼핑몰 설정 서비스에 위임만 한다.
 *
 * @internal 다른 확장은 {@see ExchangeRateGateway} 로 주입받는다.
 */
class EcommerceExchangeRateGateway implements ExchangeRateGateway
{
    /**
     * EcommerceExchangeRateGateway 생성자
     *
     * @param  EcommerceSettingsService  $settings  쇼핑몰 설정 서비스
     */
    public function __construct(private EcommerceSettingsService $settings) {}

    /**
     * {@inheritDoc}
     *
     * @return CurrencySettings 통화 설정
     */
    public function getCurrencySettings(): CurrencySettings
    {
        $languageCurrency = $this->settings->getSettings('language_currency');
        $provider = $languageCurrency['rate_provider'] ?? null;

        return new CurrencySettings(
            defaultCurrency: (string) ($languageCurrency['default_currency'] ?? 'KRW'),
            rateProvider: is_string($provider) && $provider !== '' ? $provider : null,
            currencies: array_values(array_filter($languageCurrency['currencies'] ?? [], 'is_array')),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, float|int|string>  $rates  통화 코드 => 환율
     * @param  string  $source  제공자 id
     * @return ExchangeRateApplyResult 반영 결과
     */
    public function applyExchangeRates(array $rates, string $source): ExchangeRateApplyResult
    {
        $result = $this->settings->applyExchangeRates($rates, $source);

        return new ExchangeRateApplyResult(
            applied: array_values($result['applied'] ?? []),
            unchanged: array_values($result['unchanged'] ?? []),
            skipped: array_values($result['skipped'] ?? []),
        );
    }
}
