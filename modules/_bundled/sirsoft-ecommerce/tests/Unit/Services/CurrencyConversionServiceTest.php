<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Models\Order;
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
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
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

        // g7_module_settings() 는 Config::get('g7_settings.modules.{id}') 를 조회함.
        // CoreServiceProvider::loadModuleSettingsToConfig 는 활성 모듈에만 적용되므로
        // (테스트 환경에서는 모듈이 활성화 상태로 시드되지 않음) Config 를 수동으로 주입한다.
        Config::set(
            'g7_settings.modules.sirsoft-ecommerce.language_currency',
            $settings
        );

        // 캐시 초기화
        $this->service->clearCache();
    }

    protected function tearDown(): void
    {
        // 테스트 설정 파일 정리
        $settingsFile = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/language_currency.json');
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

        // Then — 번역된 예외 메시지 (sirsoft-ecommerce::exceptions.unknown_currency)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('지원하지 않는 통화입니다: GBP');

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

    // ──────────────────────────────────────────────
    // resolveSnapshotPaymentCharge (PG/결제 청구 SSoT)
    // ──────────────────────────────────────────────

    /**
     * base=USD 주문 스냅샷 (실제 버그 재현 형태) — KRW 결제통화는 환산 + minor-unit 정수.
     */
    private function usdBaseSnapshot(string $orderCurrency): array
    {
        return [
            'base_currency' => 'USD',
            'order_currency' => $orderCurrency,
            'exchange_rates' => [
                // KRW: USD base 기준 환율(공식: base/1000 × rate). $6 → 7058원
                'KRW' => ['rate' => 1176470, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                // USD: base 자기자신
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
                // JPY: 현실적 환율 (≈157). $6 → (6/1000)×157000 = 942
                'JPY' => ['rate' => 157000, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                // CNY: 환율 0 → 미지원(차단 대상)
                'CNY' => ['rate' => 0, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ],
        ];
    }

    public function test_snapshot_charge_converts_base_usd_to_krw_minor_unit(): void
    {
        $charge = $this->service->resolveSnapshotPaymentCharge(6.0, $this->usdBaseSnapshot('KRW'));

        $this->assertSame('KRW', $charge['currency']);
        $this->assertEqualsWithDelta(7058, $charge['amount'], 0.001);
        // KRW decimal_places=0 → minor unit = 환산금액 그대로
        $this->assertSame(7058, $charge['minor_unit_amount']);
        $this->assertSame(0, $charge['decimal_places']);
    }

    public function test_snapshot_charge_uses_base_amount_when_order_currency_is_base(): void
    {
        // 결제통화 = base(USD) → 환산 없이 $6, minor unit = $6 × 10^2 = 600 (KG "1달러=100" 규칙)
        $charge = $this->service->resolveSnapshotPaymentCharge(6.0, $this->usdBaseSnapshot('USD'));

        $this->assertSame('USD', $charge['currency']);
        $this->assertEqualsWithDelta(6, $charge['amount'], 0.001);
        $this->assertSame(600, $charge['minor_unit_amount']);
        $this->assertSame(2, $charge['decimal_places']);
    }

    public function test_snapshot_charge_converts_base_usd_to_jpy_integer(): void
    {
        $charge = $this->service->resolveSnapshotPaymentCharge(6.0, $this->usdBaseSnapshot('JPY'));

        $this->assertSame('JPY', $charge['currency']);
        // (6/1000)×157000 = 942, floor, dp 0
        $this->assertSame(942, $charge['minor_unit_amount']);
        $this->assertSame(0, $charge['decimal_places']);
    }

    public function test_snapshot_charge_throws_for_zero_rate_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->resolveSnapshotPaymentCharge(6.0, $this->usdBaseSnapshot('CNY'));
    }

    public function test_snapshot_charge_throws_for_currency_absent_from_snapshot(): void
    {
        // 스냅샷 exchange_rates 에 없는 통화 → 환율 0 폴백 → 차단
        $this->expectException(InvalidArgumentException::class);
        $this->service->resolveSnapshotPaymentCharge(6.0, $this->usdBaseSnapshot('XXX'));
    }

    public function test_order_payment_charge_amount_converts_base_to_order_currency(): void
    {
        // 주문 래퍼: total_due_amount(base USD 6) + 스냅샷(order KRW) → 7058원(KRW minor unit)
        $order = new Order;
        $order->total_due_amount = 6.0;
        $order->currency_snapshot = $this->usdBaseSnapshot('KRW');

        $this->assertSame(7058, $this->service->resolveOrderPaymentChargeAmount($order));
    }

    public function test_order_payment_charge_amount_uses_base_when_order_is_base(): void
    {
        // base==order(USD) → 환산 없이 base 금액 그대로 minor unit (6 → 600)
        $order = new Order;
        $order->total_due_amount = 6.0;
        $order->currency_snapshot = $this->usdBaseSnapshot('USD');

        $this->assertSame(600, $this->service->resolveOrderPaymentChargeAmount($order));
    }

    // ──────────────────────────────────────────────
    // base_unit (통화별 기준 단위 — MP08-3 방향 B)
    // ──────────────────────────────────────────────

    /**
     * USD base + base_unit 명시 설정 (¥0 버그 재현/정상화 검증용).
     */
    private function usdBaseUnitSettings(): array
    {
        return [
            ['code' => 'USD', 'is_default' => true, 'base_unit' => 1, 'exchange_rate' => null, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ['code' => 'JPY', 'is_default' => false, 'base_unit' => 100, 'exchange_rate' => 157, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
            ['code' => 'KRW', 'is_default' => false, 'base_unit' => 1000, 'exchange_rate' => 1300, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
            ['code' => 'EUR', 'is_default' => false, 'base_unit' => 1, 'exchange_rate' => 0.92, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
        ];
    }

    private function injectSettings(array $currencies, string $default): void
    {
        $payload = ['default_currency' => $default, 'currencies' => $currencies];
        Config::set('g7_settings.modules.sirsoft-ecommerce.language_currency', $payload);
        $this->service->clearCache();
    }

    public function test_get_base_unit_reads_setting_then_falls_back(): void
    {
        // 기본 픽스처(KRW base, base_unit 미설정) → 폴백: KRW=1000, JPY=100, USD/EUR=1
        $this->assertSame(1000, $this->service->getBaseUnit('KRW'));
        $this->assertSame(100, $this->service->getBaseUnit('JPY'));
        $this->assertSame(1, $this->service->getBaseUnit('USD'));
        $this->assertSame(1, $this->service->getBaseUnit('EUR'));
        // 설정에 없는 통화 → 폴백 테이블 없으면 1
        $this->assertSame(1, $this->service->getBaseUnit('GBP'));
    }

    public function test_get_base_unit_prefers_explicit_setting_over_fallback(): void
    {
        $this->injectSettings($this->usdBaseUnitSettings(), 'USD');

        $this->assertSame(1, $this->service->getBaseUnit('USD'));
        $this->assertSame(100, $this->service->getBaseUnit('JPY'));
        $this->assertSame(1000, $this->service->getBaseUnit('KRW'));
        $this->assertSame(1, $this->service->getDefaultBaseUnit()); // 기본=USD
    }

    public function test_usd_base_converts_without_thousand_divisor_regression_jpy_zero(): void
    {
        // 회귀: base=USD, base_unit=1 일 때 $3.20 → JPY 가 0 이 아니어야 함
        $this->injectSettings($this->usdBaseUnitSettings(), 'USD');

        // $3 → JPY: (3 / 1) × 157 = 471 (floor)
        $jpy = $this->service->convertToCurrency(3, 'JPY');
        $this->assertSame(471.0, (float) $jpy['price']);
        $this->assertNotSame(0.0, (float) $jpy['price'], 'USD base 환산이 ÷1000 잔재로 0 이 되면 안 됨');

        // $3 → KRW: (3 / 1) × 1300 = 3900
        $this->assertSame(3900.0, (float) $this->service->convertToCurrency(3, 'KRW')['price']);
        // $3 → EUR: (3 / 1) × 0.92 = 2.76
        $this->assertEqualsWithDelta(2.76, (float) $this->service->convertToCurrency(3, 'EUR')['price'], 0.001);
    }

    public function test_krw_base_unit_1000_is_equivalent_to_legacy_formula(): void
    {
        // 등가성: KRW base + base_unit=1000 → 옛 ÷1000 공식과 동일 결과
        $currencies = [
            ['code' => 'KRW', 'is_default' => true, 'base_unit' => 1000, 'exchange_rate' => null, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
            ['code' => 'USD', 'is_default' => false, 'base_unit' => 1, 'exchange_rate' => 0.85, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ['code' => 'JPY', 'is_default' => false, 'base_unit' => 100, 'exchange_rate' => 115, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
        ];
        $this->injectSettings($currencies, 'KRW');

        // 53000 / 1000 × 0.85 = 45.05
        $this->assertEqualsWithDelta(45.05, (float) $this->service->convertToCurrency(53000, 'USD')['price'], 0.001);
        // 53000 / 1000 × 115 = 6095
        $this->assertSame(6095.0, (float) $this->service->convertToCurrency(53000, 'JPY')['price']);
    }

    public function test_snapshot_base_unit_falls_back_to_1000_for_legacy_snapshot(): void
    {
        // base_unit 미박제 옛 스냅샷 → 폴백 1000 (÷1000 공식 유지, 환차손 0)
        $this->assertSame(1000, $this->service->getSnapshotBaseUnit($this->usdBaseSnapshot('JPY')));
    }

    public function test_snapshot_base_unit_reads_embedded_value(): void
    {
        $snap = $this->usdBaseSnapshot('JPY');
        $snap['base_unit'] = 1; // 새 주문: base=USD, base_unit=1 박제
        $this->assertSame(1, $this->service->getSnapshotBaseUnit($snap));
    }

    public function test_snapshot_charge_uses_embedded_base_unit_new_formula(): void
    {
        // 새 스냅샷: base=USD, base_unit=1, JPY rate=157 (1달러=157엔) → $6 → ¥942
        $snap = [
            'base_currency' => 'USD',
            'order_currency' => 'JPY',
            'base_unit' => 1,
            'exchange_rates' => [
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1],
                'JPY' => ['rate' => 157, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
            ],
        ];

        $charge = $this->service->resolveSnapshotPaymentCharge(6.0, $snap);
        // (6 / 1) × 157 = 942
        $this->assertSame(942, $charge['minor_unit_amount']);
        $this->assertSame('JPY', $charge['currency']);
    }
}
