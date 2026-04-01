<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 통화 변환 서비스 Unit 테스트
 */
class CurrencyConversionServiceTest extends ModuleTestCase
{
    protected CurrencyConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CurrencyConversionService;

        // 테스트용 통화 설정 주입
        $this->setupTestCurrencySettings();
    }

    /**
     * 테스트용 통화 설정을 저장합니다.
     */
    protected function setupTestCurrencySettings(): void
    {
        $settingsPath = storage_path('app/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }

        $settings = [
            'default_language' => 'ko',
            'default_currency' => 'KRW',
            'currencies' => [
                [
                    'code' => 'KRW',
                    'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                    'exchange_rate' => null,
                    'rounding_unit' => '1',
                    'rounding_method' => 'floor',
                    'decimal_places' => 0,
                    'is_default' => true,
                ],
                [
                    'code' => 'USD',
                    'name' => ['ko' => 'USD (달러)', 'en' => 'USD (Dollar)'],
                    'exchange_rate' => 0.85,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
                    'decimal_places' => 2,
                    'is_default' => false,
                ],
                [
                    'code' => 'JPY',
                    'name' => ['ko' => 'JPY (엔)', 'en' => 'JPY (Yen)'],
                    'exchange_rate' => 115,
                    'rounding_unit' => '1',
                    'rounding_method' => 'floor',
                    'decimal_places' => 0,
                    'is_default' => false,
                ],
                [
                    'code' => 'EUR',
                    'name' => ['ko' => 'EUR (유로)', 'en' => 'EUR (Euro)'],
                    'exchange_rate' => 0.78,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'ceil',
                    'decimal_places' => 2,
                    'is_default' => false,
                ],
            ],
        ];

        file_put_contents(
            $settingsPath.'/language_currency.json',
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // 캐시 초기화
        $this->service->clearCache();
    }

    protected function tearDown(): void
    {
        // 테스트 설정 파일 정리
        $settingsFile = storage_path('app/modules/sirsoft-ecommerce/settings/language_currency.json');
        if (file_exists($settingsFile)) {
            unlink($settingsFile);
        }

        parent::tearDown();
    }

    // ========================================
    // 1. 기본 통화 조회 테스트
    // ========================================

    public function test_it_returns_default_currency(): void
    {
        // When
        $defaultCurrency = $this->service->getDefaultCurrency();

        // Then
        $this->assertEquals('KRW', $defaultCurrency);
    }

    // ========================================
    // 2. 지원 통화 목록 조회 테스트
    // ========================================

    public function test_it_returns_supported_currencies(): void
    {
        // When
        $currencies = $this->service->getSupportedCurrencies();

        // Then
        $this->assertContains('KRW', $currencies);
        $this->assertContains('USD', $currencies);
        $this->assertContains('JPY', $currencies);
        $this->assertContains('EUR', $currencies);
    }

    // ========================================
    // 3. KRW → USD 기본 변환 테스트
    // ========================================

    public function test_it_converts_krw_to_usd(): void
    {
        // Given
        $basePrice = 100000; // 100,000 KRW

        // When
        $result = $this->service->convertToCurrency($basePrice, 'USD');

        // Then
        // 100,000 / 1000 * 0.85 = 85.00 (round)
        $this->assertEquals(85.00, $result['price']);
        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals(0.85, $result['exchange_rate']);
    }

    // ========================================
    // 4. KRW → JPY 변환 테스트
    // ========================================

    public function test_it_converts_krw_to_jpy(): void
    {
        // Given
        $basePrice = 100000; // 100,000 KRW

        // When
        $result = $this->service->convertToCurrency($basePrice, 'JPY');

        // Then
        // 100,000 / 1000 * 115 = 11,500 (floor)
        $this->assertEquals(11500, $result['price']);
        $this->assertEquals('JPY', $result['currency']);
        $this->assertEquals(115, $result['exchange_rate']);
    }

    // ========================================
    // 5. round 반올림 적용 테스트
    // ========================================

    public function test_it_applies_round_rounding(): void
    {
        // Given
        $basePrice = 33333; // 33,333 KRW

        // When
        $result = $this->service->convertToCurrency($basePrice, 'USD');

        // Then
        // 33,333 / 1000 * 0.85 = 28.33305
        // round to 0.01 → 28.33
        $this->assertEqualsWithDelta(28.33, $result['price'], 0.001);
    }

    // ========================================
    // 6. floor 버림 적용 테스트
    // ========================================

    public function test_it_applies_floor_rounding(): void
    {
        // Given
        $basePrice = 33333; // 33,333 KRW

        // When
        $result = $this->service->convertToCurrency($basePrice, 'JPY');

        // Then
        // 33,333 / 1000 * 115 = 3833.295
        // floor to 1 → 3833
        $this->assertEquals(3833, $result['price']);
    }

    // ========================================
    // 7. ceil 올림 적용 테스트
    // ========================================

    public function test_it_applies_ceil_rounding(): void
    {
        // Given
        $basePrice = 33333; // 33,333 KRW

        // When
        $result = $this->service->convertToCurrency($basePrice, 'EUR');

        // Then
        // 33,333 / 1000 * 0.78 = 25.99974
        // ceil to 0.01 → 26.00
        $this->assertEquals(26.00, $result['price']);
    }

    // ========================================
    // 8. 기본통화는 변환 없이 반환 테스트
    // ========================================

    public function test_it_returns_base_price_for_default_currency(): void
    {
        // Given
        $basePrice = 100000; // 100,000 KRW

        // When
        $result = $this->service->convertToCurrency($basePrice, 'KRW');

        // Then
        $this->assertEquals(100000, $result['price']);
        $this->assertEquals('KRW', $result['currency']);
        $this->assertArrayNotHasKey('exchange_rate', $result);
    }

    // ========================================
    // 9. 여러 필드 그룹 변환 테스트
    // ========================================

    public function test_it_converts_multiple_amounts_grouped_by_currency(): void
    {
        // Given
        $amounts = [
            'subtotal' => 100000,
            'discount' => 10000,
            'final_amount' => 90000,
        ];

        // When
        $result = $this->service->convertMultipleAmounts($amounts);

        // Then
        // KRW
        $this->assertArrayHasKey('KRW', $result);
        $this->assertEquals(100000, $result['KRW']['subtotal']);
        $this->assertEquals(10000, $result['KRW']['discount']);
        $this->assertEquals(90000, $result['KRW']['final_amount']);
        $this->assertTrue($result['KRW']['_meta']['is_default']);

        // USD
        $this->assertArrayHasKey('USD', $result);
        $this->assertEquals(85.00, $result['USD']['subtotal']);
        $this->assertEquals(8.50, $result['USD']['discount']);
        $this->assertEquals(76.50, $result['USD']['final_amount']);
        $this->assertFalse($result['USD']['_meta']['is_default']);
        $this->assertEquals(0.85, $result['USD']['_meta']['exchange_rate']);

        // JPY
        $this->assertArrayHasKey('JPY', $result);
        $this->assertEquals(11500, $result['JPY']['subtotal']);
        $this->assertEquals(1150, $result['JPY']['discount']);
        $this->assertEquals(10350, $result['JPY']['final_amount']);

        // EUR
        $this->assertArrayHasKey('EUR', $result);
        $this->assertEquals(78.00, $result['EUR']['subtotal']);
        $this->assertEquals(7.80, $result['EUR']['discount']);
        $this->assertEquals(70.20, $result['EUR']['final_amount']);
    }

    // ========================================
    // 10. 미지원 통화 예외 테스트
    // ========================================

    public function test_it_throws_exception_for_unknown_currency(): void
    {
        // Given
        $basePrice = 100000;

        // Then
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown currency: GBP');

        // When
        $this->service->convertToCurrency($basePrice, 'GBP');
    }

    // ========================================
    // 추가 테스트: 가격 포맷팅
    // ========================================

    public function test_it_formats_price_for_each_currency(): void
    {
        // KRW
        $this->assertEquals('100,000원', $this->service->formatPrice(100000, 'KRW'));

        // USD
        $this->assertEquals('$85.00', $this->service->formatPrice(85.00, 'USD'));

        // JPY
        $this->assertEquals('¥11,500', $this->service->formatPrice(11500, 'JPY'));

        // EUR
        $this->assertEquals('€78.00', $this->service->formatPrice(78.00, 'EUR'));
    }

    // ========================================
    // 추가 테스트: 통화 지원 여부 확인
    // ========================================

    public function test_it_checks_if_currency_is_supported(): void
    {
        $this->assertTrue($this->service->isSupportedCurrency('KRW'));
        $this->assertTrue($this->service->isSupportedCurrency('USD'));
        $this->assertTrue($this->service->isSupportedCurrency('JPY'));
        $this->assertTrue($this->service->isSupportedCurrency('EUR'));
        // GBP is not in our test settings
        $this->assertFalse($this->service->isSupportedCurrency('GBP'));
        $this->assertFalse($this->service->isSupportedCurrency('XYZ'));
    }

    // ========================================
    // 추가 테스트: 다통화 변환 (convertToMultiCurrency)
    // ========================================

    public function test_it_converts_single_price_to_all_currencies(): void
    {
        // Given
        $basePrice = 50000;

        // When
        $result = $this->service->convertToMultiCurrency($basePrice);

        // Then
        // KRW
        $this->assertArrayHasKey('KRW', $result);
        $this->assertEquals(50000, $result['KRW']['price']);
        $this->assertTrue($result['KRW']['is_default']);

        // USD: 50000 / 1000 * 0.85 = 42.50
        $this->assertArrayHasKey('USD', $result);
        $this->assertEquals(42.50, $result['USD']['price']);

        // JPY: 50000 / 1000 * 115 = 5750
        $this->assertArrayHasKey('JPY', $result);
        $this->assertEquals(5750, $result['JPY']['price']);

        // EUR: 50000 / 1000 * 0.78 = 39.00
        $this->assertArrayHasKey('EUR', $result);
        $this->assertEquals(39.00, $result['EUR']['price']);
    }

    // ========================================
    // 추가 테스트: 소수 자릿수 조회 (getDecimalPlaces)
    // ========================================

    public function test_it_returns_decimal_places_for_each_currency(): void
    {
        // KRW: 소수 자릿수 0
        $this->assertEquals(0, $this->service->getDecimalPlaces('KRW'));

        // USD: 소수 자릿수 2
        $this->assertEquals(2, $this->service->getDecimalPlaces('USD'));

        // JPY: 소수 자릿수 0
        $this->assertEquals(0, $this->service->getDecimalPlaces('JPY'));

        // EUR: 소수 자릿수 2
        $this->assertEquals(2, $this->service->getDecimalPlaces('EUR'));
    }

    public function test_it_returns_default_decimal_places_for_unknown_currency(): void
    {
        // 설정에 없는 통화는 기본값 2 반환
        $this->assertEquals(2, $this->service->getDecimalPlaces('GBP'));
        $this->assertEquals(2, $this->service->getDecimalPlaces('XYZ'));
    }

    public function test_format_price_uses_decimal_places_from_settings(): void
    {
        // KRW: 소수 자릿수 0이므로 정수만 표시
        $formatted = $this->service->formatPrice(10000.50, 'KRW');
        $this->assertStringNotContainsString('.', $formatted);

        // USD: 소수 자릿수 2이므로 소수점 표시
        $formatted = $this->service->formatPrice(100.5, 'USD');
        $this->assertStringContainsString('.50', $formatted);
    }
}
