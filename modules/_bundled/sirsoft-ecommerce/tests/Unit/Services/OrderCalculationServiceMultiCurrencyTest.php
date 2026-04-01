<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 계산 서비스 다통화 기능 Unit 테스트
 *
 * OrderCalculationService의 다통화(Multi-Currency) 지원 기능을 검증합니다.
 */
class OrderCalculationServiceMultiCurrencyTest extends ModuleTestCase
{
    protected OrderCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 통화 설정 주입
        $this->setupTestCurrencySettings();

        // 서비스 인스턴스 생성 - 모든 의존성 주입
        $currencyService = new CurrencyConversionService;
        $this->service = new OrderCalculationService(
            $currencyService,
            app(ProductOptionRepositoryInterface::class),
            app(CouponIssueRepositoryInterface::class),
            app(ShippingPolicyRepositoryInterface::class)
        );
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
                    'is_default' => true,
                ],
                [
                    'code' => 'USD',
                    'name' => ['ko' => 'USD (달러)', 'en' => 'USD (Dollar)'],
                    'exchange_rate' => 0.85,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
                    'is_default' => false,
                ],
                [
                    'code' => 'JPY',
                    'name' => ['ko' => 'JPY (엔)', 'en' => 'JPY (Yen)'],
                    'exchange_rate' => 115,
                    'rounding_unit' => '1',
                    'rounding_method' => 'floor',
                    'is_default' => false,
                ],
            ],
        ];

        file_put_contents(
            $settingsPath.'/language_currency.json',
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
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

    /**
     * 테스트용 상품과 옵션을 생성합니다.
     */
    protected function createProductWithOption(int $price = 50000): array
    {
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => $price, // 상품 판매 가격
            'list_price' => $price, // 상품 정가
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0, // 옵션 추가 금액 없음
            'stock_quantity' => 100,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    // ========================================
    // 1. items[]에 multiCurrency 포함 테스트
    // ========================================

    public function test_it_includes_multi_currency_in_items(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertNotEmpty($result->items);
        $this->assertNotNull($result->items[0]->multiCurrency);
        $this->assertArrayHasKey('KRW', $result->items[0]->multiCurrency->toArray());
        $this->assertArrayHasKey('USD', $result->items[0]->multiCurrency->toArray());
        $this->assertArrayHasKey('JPY', $result->items[0]->multiCurrency->toArray());
    }

    // ========================================
    // 2. summary에 multiCurrency 포함 테스트
    // ========================================

    public function test_it_includes_multi_currency_in_summary(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertNotNull($result->summary->multiCurrency);
        $this->assertArrayHasKey('KRW', $result->summary->multiCurrency->toArray());
        $this->assertArrayHasKey('USD', $result->summary->multiCurrency->toArray());
        $this->assertArrayHasKey('JPY', $result->summary->multiCurrency->toArray());
    }

    // ========================================
    // 3. 아이템 9개 필드 변환 테스트
    // ========================================

    public function test_it_converts_all_item_amount_fields(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 2
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);
        $multiCurrency = $result->items[0]->multiCurrency->toArray();

        // Then
        $expectedFields = [
            'unit_price',
            'subtotal',
            'product_coupon_discount_amount',
            'code_discount_amount',
            'order_coupon_discount_share',
            'points_used_share',
            'taxable_amount',
            'tax_free_amount',
            'final_amount',
        ];

        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $multiCurrency['KRW']);
            $this->assertArrayHasKey($field, $multiCurrency['USD']);
            $this->assertArrayHasKey($field, $multiCurrency['JPY']);
        }

        // USD 변환 검증: 100,000 / 1000 * 0.85 = 85.00
        $this->assertEquals(85.00, $multiCurrency['USD']['unit_price']);
        // 200,000 / 1000 * 0.85 = 170.00
        $this->assertEquals(170.00, $multiCurrency['USD']['subtotal']);
    }

    // ========================================
    // 4. summary 13개 필드 변환 테스트
    // ========================================

    public function test_it_converts_all_summary_amount_fields(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);
        $multiCurrency = $result->summary->multiCurrency->toArray();

        // Then
        $expectedFields = [
            'subtotal',
            'product_coupon_discount',
            'code_discount',
            'order_coupon_discount',
            'total_discount',
            'base_shipping_total',
            'extra_shipping_total',
            'total_shipping',
            'shipping_discount',
            'taxable_amount',
            'tax_free_amount',
            'payment_amount',
            'final_amount',
        ];

        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $multiCurrency['KRW'], "KRW missing field: {$field}");
            $this->assertArrayHasKey($field, $multiCurrency['USD'], "USD missing field: {$field}");
            $this->assertArrayHasKey($field, $multiCurrency['JPY'], "JPY missing field: {$field}");
        }
    }

    // ========================================
    // 5. applied_shipping_policy 제외 확인 테스트
    // ========================================

    public function test_it_excludes_applied_shipping_policy_from_conversion(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);
        $itemArray = $result->items[0]->toArray();

        // Then
        // applied_shipping_policy가 존재할 경우 multi_currency 필드가 없어야 함
        if (isset($itemArray['applied_shipping_policy']) && $itemArray['applied_shipping_policy'] !== null) {
            $this->assertArrayNotHasKey('multi_currency', $itemArray['applied_shipping_policy']);
        }
        // 아이템의 multi_currency는 존재해야 함
        $this->assertArrayHasKey('multi_currency', $itemArray);
    }

    // ========================================
    // 6. promotions 제외 확인 테스트
    // ========================================

    public function test_it_excludes_promotions_from_conversion(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);
        $resultArray = $result->toArray();

        // Then
        // promotions 섹션에는 multi_currency가 없어야 함
        $this->assertArrayNotHasKey('multi_currency', $resultArray['promotions']);
    }

    // ========================================
    // 7. 마일리지 필드 제외 확인 테스트
    // ========================================

    public function test_it_excludes_points_from_summary_conversion(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);
        $multiCurrency = $result->summary->multiCurrency->toArray();

        // Then
        // points_earning, points_used는 multi_currency에 포함되지 않아야 함
        foreach ($multiCurrency as $code => $amounts) {
            if ($code === '_meta') {
                continue;
            }
            $this->assertArrayNotHasKey('points_earning', $amounts);
            $this->assertArrayNotHasKey('points_used', $amounts);
        }
    }

    // ========================================
    // 8. 결제 통화 선택 저장 테스트
    // ========================================

    public function test_it_stores_selected_payment_currency(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            paymentCurrency: 'USD'
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals('USD', $result->summary->selectedPaymentCurrency);
    }

    // ========================================
    // 추가: 결제 통화 미선택 시 null 유지
    // ========================================

    public function test_it_keeps_null_when_payment_currency_not_selected(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertNull($result->summary->selectedPaymentCurrency);
    }

    // ========================================
    // 추가: toArray() 출력 구조 검증
    // ========================================

    public function test_to_array_includes_multi_currency_structure(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            paymentCurrency: 'USD'
        );

        // When
        $result = $this->service->calculate($input);
        $array = $result->toArray();

        // Then
        // items[0].multi_currency 존재
        $this->assertArrayHasKey('multi_currency', $array['items'][0]);

        // summary.multi_currency 존재
        $this->assertArrayHasKey('multi_currency', $array['summary']);

        // summary.selected_payment_currency 존재
        $this->assertArrayHasKey('selected_payment_currency', $array['summary']);
        $this->assertEquals('USD', $array['summary']['selected_payment_currency']);
    }

    // ========================================
    // 스냅샷 기반 다통화 변환 테스트
    // ========================================

    /**
     * 테스트: 스냅샷 환율로 다통화 변환 수행
     *
     * 주문 시점의 환율 스냅샷(USD=1300)으로 mc 금액을 변환합니다.
     */
    public function test_snapshot_currency_conversion_uses_snapshot_exchange_rate(): void
    {
        // Given: 스냅샷 환율 USD=1300 (현재 설정: 0.85)
        $currencyService = new CurrencyConversionService;
        $amounts = ['subtotal' => 100000]; // 100,000 KRW

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => 1.0,
                'USD' => ['rate' => 1300, 'rounding_unit' => '0.01', 'rounding_method' => 'round'],
            ],
        ];

        // When
        $result = $currencyService->convertMultipleAmountsWithSnapshot($amounts, $currencySnapshot);

        // Then: 스냅샷 환율(1300) 사용 → (100000 / 1000) × 1300 = 130,000
        $this->assertArrayHasKey('USD', $result);
        $this->assertEquals(130000.0, $result['USD']['subtotal']);
        // KRW는 기본통화이므로 원본 그대로
        $this->assertEquals(100000, $result['KRW']['subtotal']);
        // 메타데이터 확인
        $this->assertTrue($result['USD']['_meta']['snapshot_based']);
        $this->assertEquals(1300, $result['USD']['_meta']['exchange_rate']);
    }

    /**
     * 테스트: 스냅샷 환율의 반올림 규칙 적용
     *
     * JPY의 rounding_unit=1, method=floor로 소수점 없이 내림 처리합니다.
     */
    public function test_snapshot_currency_conversion_uses_snapshot_rounding_rule(): void
    {
        // Given: JPY 스냅샷 환율 (rounding_unit=1, floor)
        $currencyService = new CurrencyConversionService;
        $amounts = ['subtotal' => 33333]; // 33,333 KRW

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => 1.0,
                'JPY' => ['rate' => 115, 'rounding_unit' => '1', 'rounding_method' => 'floor'],
            ],
        ];

        // When
        $result = $currencyService->convertMultipleAmountsWithSnapshot($amounts, $currencySnapshot);

        // Then: (33333 / 1000) × 115 = 3833.295 → floor to 1 → 3833
        $this->assertArrayHasKey('JPY', $result);
        $this->assertEquals(3833, $result['JPY']['subtotal']);
    }

    /**
     * 테스트: 레거시 스냅샷 형식 (단순 float) 하위 호환
     *
     * exchange_rates에 단순 float 값만 있는 이전 형식도 정상 동작합니다.
     */
    public function test_snapshot_currency_legacy_format_fallback(): void
    {
        // Given: 레거시 형식 (단순 float 환율, 반올림 설정 없음)
        $currencyService = new CurrencyConversionService;
        $amounts = ['subtotal' => 50000]; // 50,000 KRW

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => 1.0,
                'USD' => 0.85, // 레거시: 단순 float (rounding 없음)
            ],
        ];

        // When
        $result = $currencyService->convertMultipleAmountsWithSnapshot($amounts, $currencySnapshot);

        // Then: (50000 / 1000) × 0.85 = 42.5 → 기본 반올림(round, 0.01) → 42.5
        $this->assertArrayHasKey('USD', $result);
        $this->assertEquals(42.5, $result['USD']['subtotal']);
        $this->assertTrue($result['USD']['_meta']['snapshot_based']);
    }

    /**
     * 테스트: 스냅샷 환율로 여러 금액 필드 동시 변환
     *
     * subtotal, shipping, discount 등 복수 필드를 한 번에 변환합니다.
     */
    public function test_snapshot_currency_multiple_amounts_converted(): void
    {
        // Given: 여러 금액 필드
        $currencyService = new CurrencyConversionService;
        $amounts = [
            'subtotal' => 100000,
            'shipping' => 5000,
            'discount' => 10000,
        ];

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => 1.0,
                'USD' => ['rate' => 0.85, 'rounding_unit' => '0.01', 'rounding_method' => 'round'],
            ],
        ];

        // When
        $result = $currencyService->convertMultipleAmountsWithSnapshot($amounts, $currencySnapshot);

        // Then: 각 필드별 변환 확인
        // subtotal: (100000/1000) × 0.85 = 85.00
        $this->assertEquals(85.00, $result['USD']['subtotal']);
        // shipping: (5000/1000) × 0.85 = 4.25
        $this->assertEquals(4.25, $result['USD']['shipping']);
        // discount: (10000/1000) × 0.85 = 8.50
        $this->assertEquals(8.50, $result['USD']['discount']);

        // KRW 원본 금액 유지
        $this->assertEquals(100000, $result['KRW']['subtotal']);
        $this->assertEquals(5000, $result['KRW']['shipping']);
        $this->assertEquals(10000, $result['KRW']['discount']);

        // 포맷 필드도 존재
        $this->assertArrayHasKey('subtotal_formatted', $result['USD']);
        $this->assertArrayHasKey('shipping_formatted', $result['USD']);
        $this->assertArrayHasKey('discount_formatted', $result['USD']);
    }

    /**
     * 테스트: 통화 스냅샷이 없으면 단일통화 주문 (mc 필드 없음)
     *
     * currency_snapshot 없이 calculate()를 호출하면 기본 통화만 사용합니다.
     */
    public function test_snapshot_currency_null_snapshot_uses_current_settings(): void
    {
        // Given: 다통화 설정이 있는 상태 (setUp에서 KRW, USD, JPY 설정됨)
        [$product, $option] = $this->createProductWithOption(50000);

        // When: 통화 스냅샷 없이 계산 (paymentCurrency 미지정)
        $result = $this->service->calculate(new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1,
                ),
            ],
        ));

        // Then: 현재 설정에 따라 모든 통화로 변환됨 (스냅샷이 아닌 현재 환율 사용)
        $multiCurrency = $result->summary->multiCurrency->toArray();
        $this->assertArrayHasKey('KRW', $multiCurrency);
        $this->assertArrayHasKey('USD', $multiCurrency);
        $this->assertArrayHasKey('JPY', $multiCurrency);
        // KRW 원본 금액 유지
        $this->assertEquals(50000, $multiCurrency['KRW']['subtotal']);
        // selectedPaymentCurrency는 null (미지정)
        $this->assertNull($result->summary->selectedPaymentCurrency);
        // 메타데이터에 snapshot_based 플래그가 없음 (현재 설정 기반)
        $this->assertFalse($multiCurrency['USD']['_meta']['snapshot_based'] ?? false);
    }
}
