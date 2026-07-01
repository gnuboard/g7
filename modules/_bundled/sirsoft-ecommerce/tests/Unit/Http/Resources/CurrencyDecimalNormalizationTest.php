<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 가격 raw 응답의 통화 소수 자릿수 정규화 테스트
 *
 * 가격 컬럼은 통화 무관하게 decimal(15,2) 로 cast 되어 JPY/KRW(소수 0자리) 통화에서도
 * `200.00` 처럼 소수가 붙어 응답된다. HasMultiCurrencyPrices::roundToCurrency() 계열
 * 헬퍼가 통화 설정의 decimal_places 에 따라 0자리 통화는 정수(int), 2자리 통화는
 * 소수(float)로 정규화하는지 검증한다.
 */
class CurrencyDecimalNormalizationTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
    }

    protected function tearDown(): void
    {
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
        parent::tearDown();
    }

    /**
     * 기본통화를 설정합니다 (default_currency + 각 통화의 decimal_places).
     *
     * @param  string  $code  기본통화 코드
     */
    private function setBaseCurrency(string $code): void
    {
        $languageCurrency = [
            'default_currency' => $code,
            'currencies' => [
                ['code' => 'JPY', 'name' => ['ja' => '円'], 'is_default' => $code === 'JPY', 'decimal_places' => 0, 'base_unit' => 100, 'exchange_rate' => $code === 'JPY' ? null : 100],
                ['code' => 'KRW', 'name' => ['ko' => '원'], 'is_default' => $code === 'KRW', 'decimal_places' => 0, 'base_unit' => 1000, 'exchange_rate' => $code === 'KRW' ? null : 951],
                ['code' => 'USD', 'name' => ['en' => 'Dollar'], 'is_default' => $code === 'USD', 'decimal_places' => 2, 'base_unit' => 1, 'exchange_rate' => $code === 'USD' ? null : 0.85],
            ],
        ];

        app(EcommerceSettingsService::class)->setSetting('language_currency', $languageCurrency);
        config(['g7_settings.modules.sirsoft-ecommerce.language_currency' => $languageCurrency]);
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
    }

    /**
     * trait 의 protected 헬퍼를 노출하는 테스트용 객체를 생성합니다.
     *
     * trait 의 통화 설정 캐시는 use 한 클래스(여기서는 이 익명 클래스)별로 보존되므로,
     * 인스턴스 생성 시 해당 클래스의 캐시를 명시적으로 초기화해 테스트 간 누수를 차단한다.
     */
    private function helper(): object
    {
        $instance = new class
        {
            use HasMultiCurrencyPrices;

            public function roundBase(float|int|null $price): float|int
            {
                return $this->roundToBaseCurrency($price);
            }

            public function round(float|int|null $price, string $code): float|int
            {
                return $this->roundToCurrency($price, $code);
            }

            public function roundOrder(float|int|null $price, ?string $code = null): float|int
            {
                return $this->roundToOrderCurrency($price, $code);
            }
        };

        $instance::clearCurrencySettingsCache();

        return $instance;
    }

    public function test_jpy_base_returns_integer_without_decimals(): void
    {
        $this->setBaseCurrency('JPY');
        $value = $this->helper()->roundBase(200.00);

        $this->assertSame(200, $value);
        $this->assertIsInt($value);
    }

    public function test_krw_base_returns_integer(): void
    {
        $this->setBaseCurrency('KRW');
        $value = $this->helper()->roundBase(10000.00);

        $this->assertSame(10000, $value);
        $this->assertIsInt($value);
    }

    public function test_usd_base_returns_float_with_two_decimals(): void
    {
        $this->setBaseCurrency('USD');
        $value = $this->helper()->roundBase(1.7);

        $this->assertSame(1.7, $value);
        $this->assertIsFloat($value);
    }

    public function test_usd_base_rounds_to_two_decimals(): void
    {
        $this->setBaseCurrency('USD');
        // 소수 3자리 입력은 2자리로 라운딩
        $this->assertSame(1.57, $this->helper()->roundBase(1.566));
    }

    public function test_round_to_explicit_currency_code(): void
    {
        $this->setBaseCurrency('KRW');
        $helper = $this->helper();

        // 명시 통화 코드 — 기본통화와 무관하게 그 통화 자릿수 적용
        // JPY 0자리: 소수부 반올림되어 정수(int) 반환
        $this->assertSame(11, $helper->round(11.40, 'JPY'));
        $this->assertIsInt($helper->round(11.40, 'JPY'));
        // USD 2자리: 소수 보존(float)
        $this->assertSame(11.6, $helper->round(11.60, 'USD'));
        $this->assertIsFloat($helper->round(11.60, 'USD'));
    }

    public function test_null_price_returns_zero(): void
    {
        $this->setBaseCurrency('JPY');
        $this->assertSame(0, $this->helper()->roundBase(null));
    }

    public function test_order_currency_falls_back_to_default_when_null(): void
    {
        $this->setBaseCurrency('JPY');
        // 인자·주입값 모두 없으면 기본통화(JPY) 자릿수
        $this->assertSame(200, $this->helper()->roundOrder(200.00));
    }

    public function test_order_currency_uses_explicit_snapshot_code(): void
    {
        $this->setBaseCurrency('USD');
        // 주문 시점 통화 코드 명시 — 현재 기본통화(USD)와 달라도 그 통화 자릿수
        $this->assertSame(200, $this->helper()->roundOrder(200.00, 'JPY'));
    }

    public function test_unknown_currency_defaults_to_two_decimals(): void
    {
        $this->setBaseCurrency('JPY');
        // 설정에 없는 통화는 기본값 2자리 (getDecimalPlacesForCurrency 폴백)
        $this->assertSame(3.33, $this->helper()->round(3.333, 'XXX'));
    }
}
