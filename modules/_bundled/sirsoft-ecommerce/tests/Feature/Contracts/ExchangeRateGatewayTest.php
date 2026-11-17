<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Contracts\Currency\Data\ExchangeRateApplyResult;
use Modules\Sirsoft\Ecommerce\Contracts\Currency\ExchangeRateGateway;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 환율 반영 공개 계약 (#916)
 *
 * 환율 제공 확장(수출입은행)은 쇼핑몰 설정 서비스 대신 이 계약으로 통화 설정을 읽고 환율을 반영한다. 결과는 설정 서비스와 같아야 한다.
 */
class ExchangeRateGatewayTest extends ModuleTestCase
{
    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 통화 설정은 설정 서비스의 language_currency 와 같은 값이다(제공자 미선택은 null).
     *
     * @return void 반환값 없음
     *
     * @scenario case=exchange_rate_currency_settings
     *
     * @effects exchange_rate_gateway_currency_settings_mirror_settings_service
     */
    public function test_currency_settings_mirror_settings_service(): void
    {
        $service = app(EcommerceSettingsService::class);
        $current = $service->getSettings('language_currency');
        $current['rate_provider'] = '';
        $service->saveSettings(['language_currency' => $current]);

        $settings = app(ExchangeRateGateway::class)->getCurrencySettings();
        $expected = $service->getSettings('language_currency');

        $this->assertSame($expected['default_currency'], $settings->defaultCurrency);
        $this->assertNull($settings->rateProvider);
        $this->assertSame(array_column($expected['currencies'], 'code'), array_column($settings->currencies, 'code'));

        $current['rate_provider'] = 'fixture';
        $service->saveSettings(['language_currency' => $current]);
        $this->assertSame('fixture', app(ExchangeRateGateway::class)->getCurrencySettings()->rateProvider);
    }

    /**
     * 선택된 제공자의 환율만 반영하고 결과를 DTO 로 돌려준다. 다른 제공자는 아무것도 저장하지 않는다. 반영 뒤 액션 훅이 발화한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=exchange_rate_apply
     *
     * @effects exchange_rate_gateway_applies_only_selected_provider_and_fires_hook
     */
    public function test_apply_rates_only_for_selected_provider(): void
    {
        $service = app(EcommerceSettingsService::class);
        $current = $service->getSettings('language_currency');
        $current['rate_provider'] = 'fixture';
        $current['default_currency'] = 'KRW';
        $service->saveSettings(['language_currency' => $current]);
        $fired = [];
        HookManager::addAction('sirsoft-ecommerce.currency.after_apply_rates', function ($result, $source) use (&$fired) {
            $fired[] = $source;
        });
        $gateway = app(ExchangeRateGateway::class);

        $ignored = $gateway->applyExchangeRates(['USD' => 0.000777], 'other');
        $this->assertInstanceOf(ExchangeRateApplyResult::class, $ignored);
        $this->assertSame([[], [], []], [$ignored->applied, $ignored->unchanged, $ignored->skipped]);

        $applied = $gateway->applyExchangeRates(['USD' => 0.000777, 'KRW' => 1, 'EUR' => 0], 'fixture');
        $this->assertContains('USD', $applied->applied);
        $this->assertContains('KRW', $applied->skipped);
        $this->assertContains('EUR', $applied->skipped);
        $this->assertSame(['fixture'], $fired);

        $usd = collect($gateway->getCurrencySettings()->currencies)->firstWhere('code', 'USD');
        $this->assertEqualsWithDelta(0.000777, (float) $usd['exchange_rate'], 1e-12);
    }
}
