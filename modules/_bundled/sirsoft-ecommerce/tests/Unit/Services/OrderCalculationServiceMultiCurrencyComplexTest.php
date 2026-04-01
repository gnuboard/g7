<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ItemCalculation;
use Modules\Sirsoft\Ecommerce\DTO\MultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 계산 서비스 다통화 복합 시나리오 테스트
 *
 * 다양한 할인, 배송비, 마일리지 조합 시 다통화 변환이 올바르게 동작하는지 검증합니다.
 */
class OrderCalculationServiceMultiCurrencyComplexTest extends ModuleTestCase
{
    protected OrderCalculationService $service;

    protected CurrencyConversionService $currencyService;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 통화 설정 주입
        $this->setupTestCurrencySettings();

        // 서비스 인스턴스 생성 - 모든 의존성 주입
        $this->currencyService = new CurrencyConversionService;
        $this->service = new OrderCalculationService(
            $this->currencyService,
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
    // 1. 다통화 기본 변환 테스트 (쿠폰 없이)
    // ========================================

    public function test_multi_currency_basic_conversion(): void
    {
        // Given: 100,000원 상품 1개
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

        // Then: 기본 통화 (KRW) 값 확인
        $this->assertEquals(100000, $result->summary->subtotal);
        $this->assertEquals(100000, $result->summary->finalAmount);

        // USD 변환 확인: 100,000 / 1000 * 0.85 = 85.00
        $usdPrices = $result->summary->multiCurrency->getCurrency('USD');
        $this->assertEquals(85.00, $usdPrices['subtotal']);
        $this->assertEquals(85.00, $usdPrices['final_amount']);

        // JPY 변환 확인: 100,000 / 1000 * 115 = 11,500
        $jpyPrices = $result->summary->multiCurrency->getCurrency('JPY');
        $this->assertEquals(11500, $jpyPrices['subtotal']);
        $this->assertEquals(11500, $jpyPrices['final_amount']);
    }

    // ========================================
    // 2. 여러 상품 다통화 변환 테스트
    // ========================================

    public function test_multi_currency_with_multiple_items(): void
    {
        // Given: 50,000원 상품 2개, 30,000원 상품 1개
        [$product1, $option1] = $this->createProductWithOption(50000);
        [$product2, $option2] = $this->createProductWithOption(30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option1->id,
                    quantity: 2
                ),
                new CalculationItem(
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 기본 통화 총액 확인
        // 50,000 * 2 + 30,000 * 1 = 130,000
        $this->assertEquals(130000, $result->summary->subtotal);

        // USD 변환 확인: 130,000 / 1000 * 0.85 = 110.50
        $usdPrices = $result->summary->multiCurrency->getCurrency('USD');
        $this->assertEquals(110.50, $usdPrices['subtotal']);

        // 각 아이템의 다통화 확인
        $this->assertCount(2, $result->items);

        // 첫 번째 아이템: 50,000 * 2 = 100,000 → USD: 85.00
        $item1Usd = $result->items[0]->multiCurrency->getCurrency('USD');
        $this->assertEquals(42.50, $item1Usd['unit_price']); // 50,000 / 1000 * 0.85
        $this->assertEquals(85.00, $item1Usd['subtotal']); // 100,000 / 1000 * 0.85

        // 두 번째 아이템: 30,000 → USD: 25.50
        $item2Usd = $result->items[1]->multiCurrency->getCurrency('USD');
        $this->assertEquals(25.50, $item2Usd['unit_price']); // 30,000 / 1000 * 0.85
    }

    // ========================================
    // 3. 다통화 _meta 정보 검증 테스트
    // ========================================

    public function test_multi_currency_includes_meta_information(): void
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
        $multiCurrency = $result->summary->multiCurrency;

        // Then
        // KRW는 기본 통화
        $this->assertTrue($multiCurrency->isDefaultCurrency('KRW'));
        $this->assertNull($multiCurrency->getExchangeRate('KRW'));

        // USD는 외화
        $this->assertFalse($multiCurrency->isDefaultCurrency('USD'));
        $this->assertEquals(0.85, $multiCurrency->getExchangeRate('USD'));

        // JPY는 외화
        $this->assertFalse($multiCurrency->isDefaultCurrency('JPY'));
        $this->assertEquals(115, $multiCurrency->getExchangeRate('JPY'));
    }

    // ========================================
    // 4. MultiCurrencyPrices DTO 직접 생성 테스트
    // ========================================

    public function test_multi_currency_prices_dto_methods(): void
    {
        // Given
        $currencies = [
            'KRW' => [
                'subtotal' => 100000,
                'final_amount' => 90000,
                '_meta' => ['is_default' => true, 'exchange_rate' => null],
            ],
            'USD' => [
                'subtotal' => 85.00,
                'final_amount' => 76.50,
                '_meta' => ['is_default' => false, 'exchange_rate' => 0.85],
            ],
        ];

        $multiCurrency = new MultiCurrencyPrices($currencies);

        // Then
        // getCurrency
        $this->assertEquals(100000, $multiCurrency->getCurrency('KRW')['subtotal']);
        $this->assertEquals(85.00, $multiCurrency->getCurrency('USD')['subtotal']);
        $this->assertEquals([], $multiCurrency->getCurrency('EUR')); // 존재하지 않는 통화

        // getAmount
        $this->assertEquals(90000, $multiCurrency->getAmount('KRW', 'final_amount'));
        $this->assertEquals(76.50, $multiCurrency->getAmount('USD', 'final_amount'));
        $this->assertNull($multiCurrency->getAmount('EUR', 'final_amount')); // 존재하지 않는 통화

        // getCurrencyCodes
        $this->assertEquals(['KRW', 'USD'], $multiCurrency->getCurrencyCodes());

        // isDefaultCurrency
        $this->assertTrue($multiCurrency->isDefaultCurrency('KRW'));
        $this->assertFalse($multiCurrency->isDefaultCurrency('USD'));

        // getExchangeRate
        $this->assertNull($multiCurrency->getExchangeRate('KRW'));
        $this->assertEquals(0.85, $multiCurrency->getExchangeRate('USD'));
    }

    // ========================================
    // 5. API 응답 구조 검증 테스트
    // ========================================

    public function test_multi_currency_api_response_structure(): void
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

        // Then: 전체 응답 구조 확인
        $this->assertArrayHasKey('items', $array);
        $this->assertArrayHasKey('summary', $array);
        $this->assertArrayHasKey('promotions', $array);
        $this->assertArrayHasKey('validation_errors', $array);

        // items[0] 구조 확인
        $item = $array['items'][0];
        $this->assertArrayHasKey('product_id', $item);
        $this->assertArrayHasKey('unit_price', $item);
        $this->assertArrayHasKey('subtotal', $item);
        $this->assertArrayHasKey('multi_currency', $item);

        // items[0].multi_currency 구조 확인
        $itemMultiCurrency = $item['multi_currency'];
        $this->assertArrayHasKey('KRW', $itemMultiCurrency);
        $this->assertArrayHasKey('USD', $itemMultiCurrency);
        $this->assertArrayHasKey('_meta', $itemMultiCurrency['USD']);

        // summary 구조 확인
        $summary = $array['summary'];
        $this->assertArrayHasKey('subtotal', $summary);
        $this->assertArrayHasKey('final_amount', $summary);
        $this->assertArrayHasKey('selected_payment_currency', $summary);
        $this->assertArrayHasKey('multi_currency', $summary);

        // summary.selected_payment_currency 확인
        $this->assertEquals('USD', $summary['selected_payment_currency']);

        // summary.multi_currency 구조 확인
        $summaryMultiCurrency = $summary['multi_currency'];
        $this->assertArrayHasKey('KRW', $summaryMultiCurrency);
        $this->assertArrayHasKey('USD', $summaryMultiCurrency);

        // _meta 필드 확인
        $this->assertTrue($summaryMultiCurrency['KRW']['_meta']['is_default']);
        $this->assertFalse($summaryMultiCurrency['USD']['_meta']['is_default']);
        $this->assertEquals(0.85, $summaryMultiCurrency['USD']['_meta']['exchange_rate']);
    }

    // ========================================
    // 6. 0원 금액 다통화 변환 테스트
    // ========================================

    public function test_multi_currency_with_zero_amounts(): void
    {
        // Given: 할인이 없으므로 discount 필드들은 모두 0
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

        // Then: 0원 필드도 올바르게 변환됨 (0 → 0)
        $this->assertEquals(0, $multiCurrency['KRW']['product_coupon_discount']);
        $this->assertEquals(0, $multiCurrency['USD']['product_coupon_discount']);
        $this->assertEquals(0, $multiCurrency['JPY']['product_coupon_discount']);

        $this->assertEquals(0, $multiCurrency['KRW']['code_discount']);
        $this->assertEquals(0, $multiCurrency['USD']['code_discount']);
        $this->assertEquals(0, $multiCurrency['JPY']['code_discount']);
    }

    // ========================================
    // 추가: fromArray/toArray 일관성 테스트
    // ========================================

    public function test_item_calculation_from_array_to_array_consistency(): void
    {
        // Given
        $originalData = [
            'product_id' => 1,
            'product_option_id' => 10,
            'quantity' => 2,
            'unit_price' => 50000,
            'subtotal' => 100000,
            'product_coupon_discount_amount' => 5000,
            'code_discount_amount' => 0,
            'order_coupon_discount_share' => 2000,
            'points_used_share' => 1000,
            'points_earning' => 500,
            'taxable_amount' => 92000,
            'tax_free_amount' => 0,
            'final_amount' => 92000,
            'product_name' => '테스트 상품',
            'option_name' => '옵션 A',
            'multi_currency' => [
                'KRW' => [
                    'unit_price' => 50000,
                    'subtotal' => 100000,
                    'final_amount' => 92000,
                    '_meta' => ['is_default' => true, 'exchange_rate' => null],
                ],
                'USD' => [
                    'unit_price' => 42.50,
                    'subtotal' => 85.00,
                    'final_amount' => 78.20,
                    '_meta' => ['is_default' => false, 'exchange_rate' => 0.85],
                ],
            ],
        ];

        // When
        $item = ItemCalculation::fromArray($originalData);
        $resultData = $item->toArray();

        // Then
        $this->assertEquals($originalData['product_id'], $resultData['product_id']);
        $this->assertEquals($originalData['subtotal'], $resultData['subtotal']);
        $this->assertEquals($originalData['multi_currency'], $resultData['multi_currency']);
    }

    // ========================================
    // 추가: Summary fromArray/toArray 일관성 테스트
    // ========================================

    public function test_summary_from_array_to_array_consistency(): void
    {
        // Given
        $originalData = [
            'subtotal' => 200000,
            'product_coupon_discount' => 10000,
            'code_discount' => 0,
            'order_coupon_discount' => 5000,
            'total_discount' => 15000,
            'base_shipping_total' => 3000,
            'extra_shipping_total' => 0,
            'total_shipping' => 3000,
            'shipping_discount' => 0,
            'taxable_amount' => 188000,
            'tax_free_amount' => 0,
            'points_earning' => 1880,
            'points_used' => 5000,
            'payment_amount' => 188000,
            'final_amount' => 183000,
            'selected_payment_currency' => 'USD',
            'multi_currency' => [
                'KRW' => [
                    'subtotal' => 200000,
                    'final_amount' => 183000,
                    '_meta' => ['is_default' => true, 'exchange_rate' => null],
                ],
                'USD' => [
                    'subtotal' => 170.00,
                    'final_amount' => 155.55,
                    '_meta' => ['is_default' => false, 'exchange_rate' => 0.85],
                ],
            ],
        ];

        // When
        $summary = Summary::fromArray($originalData);
        $resultData = $summary->toArray();

        // Then
        $this->assertEquals($originalData['subtotal'], $resultData['subtotal']);
        $this->assertEquals($originalData['final_amount'], $resultData['final_amount']);
        $this->assertEquals($originalData['selected_payment_currency'], $resultData['selected_payment_currency']);
        $this->assertEquals($originalData['multi_currency'], $resultData['multi_currency']);
    }

    // ========================================
    // 배송비 헬퍼 메서드
    // ========================================

    /**
     * 테스트용 배송정책을 생성합니다.
     */
    protected function createShippingPolicy(
        ChargePolicyEnum $chargePolicy = ChargePolicyEnum::FREE,
        int $baseFee = 0,
        ?int $freeThreshold = null,
    ): ShippingPolicy {
        return ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'shipping_method' => ShippingMethodEnum::PARCEL,
            'charge_policy' => $chargePolicy,
            'base_fee' => $baseFee,
            'free_threshold' => $freeThreshold,
            'ranges' => null,
            'countries' => ['KR'],
            'currency_code' => 'KRW',
            'is_active' => true,
        ]);
    }

    // ========================================
    // 7.17.3 다통화 + 복합 시나리오 테스트 (#137, #139, #140)
    // ========================================

    /**
     * 테스트 #137: 배송비 + 다통화 변환
     *
     * 입력: 상품 50,000원 + 배송비 3,000원
     * 기대: USD: subtotal=42.5, shipping=2.55
     */
    public function test_multi_currency_with_shipping_fee(): void
    {
        // Given: 50,000원 상품 + 3,000원 배송비
        [$product, $option] = $this->createProductWithOption(50000);
        $policy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        $product->update(['shipping_policy_id' => $policy->id]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: KRW 값 확인
        $this->assertEquals(50000, $result->summary->subtotal);
        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(53000, $result->summary->finalAmount);

        // USD 변환 확인
        // subtotal: 50,000 / 1000 * 0.85 = 42.5
        // shipping: 3,000 / 1000 * 0.85 = 2.55
        // final: 53,000 / 1000 * 0.85 = 45.05
        $usdPrices = $result->summary->multiCurrency->getCurrency('USD');
        $this->assertEqualsWithDelta(42.5, $usdPrices['subtotal'], 0.01);
        $this->assertEqualsWithDelta(2.55, $usdPrices['total_shipping'], 0.01);
        $this->assertEqualsWithDelta(45.05, $usdPrices['final_amount'], 0.01);

        // JPY 변환 확인
        // subtotal: 50,000 / 1000 * 115 = 5,750
        // shipping: 3,000 / 1000 * 115 = 345
        // final: 53,000 / 1000 * 115 = 6,095
        $jpyPrices = $result->summary->multiCurrency->getCurrency('JPY');
        $this->assertEquals(5750, $jpyPrices['subtotal']);
        $this->assertEquals(345, $jpyPrices['total_shipping']);
        $this->assertEquals(6095, $jpyPrices['final_amount']);
    }

    /**
     * 테스트 #139: 마일리지 사용 + 다통화
     *
     * 입력: 상품 100,000원 + 마일리지 10,000원 사용
     * 기대: 마일리지는 기본통화 기준 유지, 다통화 변환에 반영되지 않음
     */
    public function test_multi_currency_with_mileage_usage(): void
    {
        // Given: 100,000원 상품 + 마일리지 10,000원 사용
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            usePoints: 10000
        );

        // When
        $result = $this->service->calculate($input);

        // Then: KRW 값 확인
        $this->assertEquals(100000, $result->summary->subtotal);
        $this->assertEquals(10000, $result->summary->pointsUsed);
        $this->assertEquals(90000, $result->summary->finalAmount);

        // USD 변환 확인 (마일리지는 기본통화 기준 유지)
        // final_amount: 90,000 / 1000 * 0.85 = 76.5
        $usdPrices = $result->summary->multiCurrency->getCurrency('USD');
        $this->assertEquals(85.0, $usdPrices['subtotal']);
        $this->assertEquals(76.5, $usdPrices['final_amount']);

        // 마일리지 필드는 다통화에 포함되지 않음
        $this->assertArrayNotHasKey('points_used', $usdPrices);
    }

    /**
     * 테스트 #140: 전체 복합 (쿠폰+배송+마일리지)
     *
     * 복합 시나리오: 상품 100,000원 + 배송비 5,000원 + 마일리지 10,000원 사용
     * 검증: 모든 필드가 정확히 다통화 변환됨
     */
    public function test_multi_currency_with_full_complex_scenario(): void
    {
        // Given: 100,000원 상품 + 5,000원 배송비 + 마일리지 10,000원 사용
        [$product, $option] = $this->createProductWithOption(100000);
        $policy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 5000);
        $product->update(['shipping_policy_id' => $policy->id]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            usePoints: 10000
        );

        // When
        $result = $this->service->calculate($input);

        // Then: KRW 값 확인
        $this->assertEquals(100000, $result->summary->subtotal);
        $this->assertEquals(5000, $result->summary->totalShipping);
        $this->assertEquals(10000, $result->summary->pointsUsed);
        // paymentAmount = 100,000 + 5,000 = 105,000
        // finalAmount = 105,000 - 10,000 = 95,000
        $this->assertEquals(95000, $result->summary->finalAmount);

        // USD 변환 확인
        // subtotal: 100,000 / 1000 * 0.85 = 85.0
        // shipping: 5,000 / 1000 * 0.85 = 4.25
        // final: 95,000 / 1000 * 0.85 = 80.75
        $usdPrices = $result->summary->multiCurrency->getCurrency('USD');
        $this->assertEquals(85.0, $usdPrices['subtotal']);
        $this->assertEquals(4.25, $usdPrices['total_shipping']);
        $this->assertEquals(80.75, $usdPrices['final_amount']);

        // JPY 변환 확인
        // subtotal: 100,000 / 1000 * 115 = 11,500
        // shipping: 5,000 / 1000 * 115 = 575
        // final: 95,000 / 1000 * 115 = 10,925
        $jpyPrices = $result->summary->multiCurrency->getCurrency('JPY');
        $this->assertEquals(11500, $jpyPrices['subtotal']);
        $this->assertEquals(575, $jpyPrices['total_shipping']);
        $this->assertEquals(10925, $jpyPrices['final_amount']);
    }

    // ========================================
    // 7.17.3 다통화 + 추가배송비 테스트
    // ========================================

    /**
     * 테스트 #138: 다통화 + 추가배송비 (도서산간)
     *
     * 입력: 상품 50,000원 + 기본 배송비 3,000원 + 도서산간 추가배송비 3,000원
     * 기대:
     *   - KRW: subtotal=50,000, baseShipping=3,000, extraShipping=3,000, total=6,000, final=56,000
     *   - USD: subtotal=42.5, totalShipping=5.1 (6,000 * 0.85 / 1000), final=47.6
     *   - JPY: subtotal=5,750, totalShipping=690 (6,000 * 115 / 1000), final=6,440
     */
    public function test_multi_currency_with_extra_shipping_fee(): void
    {
        // Given: 50,000원 상품 + 추가배송비 활성화된 배송정책
        [$product, $option] = $this->createProductWithOption(50000);

        $policy = ShippingPolicy::create([
            'name' => ['ko' => '추가배송비 정책', 'en' => 'Extra Shipping Policy'],
            'shipping_method' => ShippingMethodEnum::PARCEL,
            'charge_policy' => ChargePolicyEnum::FIXED,
            'base_fee' => 3000,
            'countries' => ['KR'],
            'currency_code' => 'KRW',
            'is_active' => true,
            'extra_fee_enabled' => true,
            'extra_fee_settings' => [
                ['zipcode' => '63*', 'fee' => 3000], // 제주도 추가배송비
            ],
        ]);
        $product->update(['shipping_policy_id' => $policy->id]);

        // ShippingAddress for 제주도
        $shippingAddress = new \Modules\Sirsoft\Ecommerce\DTO\ShippingAddress(zipcode: '63123');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            shippingAddress: $shippingAddress
        );

        // When
        $result = $this->service->calculate($input);

        // Then: KRW 값 확인
        $this->assertEquals(50000, $result->summary->subtotal);
        $this->assertEquals(3000, $result->summary->baseShippingTotal);  // 기본 배송비
        $this->assertEquals(3000, $result->summary->extraShippingTotal); // 추가배송비
        $this->assertEquals(6000, $result->summary->totalShipping);      // 총 배송비
        $this->assertEquals(56000, $result->summary->finalAmount);

        // Then: 아이템에 추가배송비 기록 확인
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);

        // Then: USD 변환 확인
        // subtotal: 50,000 / 1000 * 0.85 = 42.5
        // total_shipping: 6,000 / 1000 * 0.85 = 5.1
        // final: 56,000 / 1000 * 0.85 = 47.6
        $this->assertNotNull($result->summary->multiCurrency);
        $usdPrices = $result->summary->multiCurrency->getCurrency('USD');
        $this->assertEqualsWithDelta(42.5, $usdPrices['subtotal'], 0.001);
        $this->assertEqualsWithDelta(5.1, $usdPrices['total_shipping'], 0.001);
        $this->assertEqualsWithDelta(47.6, $usdPrices['final_amount'], 0.001);

        // Then: JPY 변환 확인
        // subtotal: 50,000 / 1000 * 115 = 5,750
        // total_shipping: 6,000 / 1000 * 115 = 690
        // final: 56,000 / 1000 * 115 = 6,440
        $jpyPrices = $result->summary->multiCurrency->getCurrency('JPY');
        $this->assertEqualsWithDelta(5750, $jpyPrices['subtotal'], 0.001);
        $this->assertEqualsWithDelta(690, $jpyPrices['total_shipping'], 0.001);
        $this->assertEqualsWithDelta(6440, $jpyPrices['final_amount'], 0.001);
    }
}
