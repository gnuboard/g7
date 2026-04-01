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
 * 주문 계산 서비스 마일리지 계산 Unit 테스트
 *
 * OrderCalculationService의 옵션별 마일리지 계산 기능을 검증합니다.
 * - 정액 적립 (fixed): mileage_value × quantity
 * - 정률 적립 (percent): baseAmount × mileage_value / 100
 * - 기본 적립 (fallback): baseAmount × 0.01 (1%)
 */
class OrderCalculationServiceMileageTest extends ModuleTestCase
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
     *
     * @param  int  $price  상품 가격
     * @param  float|null  $mileageValue  마일리지 값
     * @param  string|null  $mileageType  마일리지 타입 (fixed|percent)
     * @return array [Product, ProductOption]
     */
    protected function createProductWithOption(
        int $price = 50000,
        ?float $mileageValue = null,
        ?string $mileageType = null
    ): array {
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
        ]);

        $optionData = [
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
        ];

        if ($mileageValue !== null) {
            $optionData['mileage_value'] = $mileageValue;
        }
        if ($mileageType !== null) {
            $optionData['mileage_type'] = $mileageType;
        }

        $option = ProductOptionFactory::new()->forProduct($product)->create($optionData);

        return [$product, $option];
    }

    // ========================================
    // 1. 정액 적립 (fixed) 테스트
    // ========================================

    public function test_it_calculates_fixed_mileage_for_single_item(): void
    {
        // Given: 정액 500원 적립 설정
        [$product, $option] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 500,
            mileageType: 'fixed'
        );

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

        // Then: 정액 500원 × 1개 = 500원
        $this->assertEquals(500, $result->items[0]->pointsEarning);
        $this->assertEquals(500, $result->summary->pointsEarning);
    }

    public function test_it_calculates_fixed_mileage_multiplied_by_quantity(): void
    {
        // Given: 정액 500원 적립, 수량 3개
        [$product, $option] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 500,
            mileageType: 'fixed'
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 3
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 정액 500원 × 3개 = 1500원
        $this->assertEquals(1500, $result->items[0]->pointsEarning);
        $this->assertEquals(1500, $result->summary->pointsEarning);
    }

    public function test_it_floors_fixed_mileage_with_decimal_value(): void
    {
        // Given: 정액 333.33원 적립, 수량 2개
        [$product, $option] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 333.33,
            mileageType: 'fixed'
        );

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

        // Then: floor(333.33 × 2) = floor(666.66) = 666
        $this->assertEquals(666, $result->items[0]->pointsEarning);
    }

    // ========================================
    // 2. 정률 적립 (percent) 테스트
    // ========================================

    public function test_it_calculates_percent_mileage_for_single_item(): void
    {
        // Given: 5% 적립 설정, 상품 가격 100,000원
        [$product, $option] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 5,
            mileageType: 'percent'
        );

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

        // Then: 100,000원 × 5% = 5,000원
        $this->assertEquals(5000, $result->items[0]->pointsEarning);
        $this->assertEquals(5000, $result->summary->pointsEarning);
    }

    public function test_it_calculates_percent_mileage_based_on_subtotal(): void
    {
        // Given: 5% 적립 설정, 상품 가격 50,000원, 수량 2개
        [$product, $option] = $this->createProductWithOption(
            price: 50000,
            mileageValue: 5,
            mileageType: 'percent'
        );

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

        // Then: (50,000원 × 2개) × 5% = 100,000원 × 5% = 5,000원
        $this->assertEquals(5000, $result->items[0]->pointsEarning);
    }

    public function test_it_floors_percent_mileage_with_decimal_result(): void
    {
        // Given: 3% 적립 설정, 상품 가격 33,333원
        [$product, $option] = $this->createProductWithOption(
            price: 33333,
            mileageValue: 3,
            mileageType: 'percent'
        );

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

        // Then: floor(33,333 × 3%) = floor(999.99) = 999
        $this->assertEquals(999, $result->items[0]->pointsEarning);
    }

    public function test_it_calculates_high_percent_mileage(): void
    {
        // Given: 10% 적립 설정
        [$product, $option] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 10,
            mileageType: 'percent'
        );

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

        // Then: 100,000원 × 10% = 10,000원
        $this->assertEquals(10000, $result->items[0]->pointsEarning);
    }

    // ========================================
    // 3. 기본 1% 폴백 테스트
    // ========================================

    public function test_it_uses_default_1_percent_when_mileage_not_set(): void
    {
        // Given: 마일리지 설정 없음 (기본 1%)
        [$product, $option] = $this->createProductWithOption(
            price: 100000,
            mileageValue: null,
            mileageType: null
        );

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

        // Then: 100,000원 × 1% = 1,000원
        $this->assertEquals(1000, $result->items[0]->pointsEarning);
        $this->assertEquals(1000, $result->summary->pointsEarning);
    }

    public function test_it_uses_default_1_percent_for_multiple_quantity(): void
    {
        // Given: 마일리지 설정 없음, 수량 5개
        [$product, $option] = $this->createProductWithOption(
            price: 20000,
            mileageValue: null,
            mileageType: null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 5
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: (20,000원 × 5개) × 1% = 100,000원 × 1% = 1,000원
        $this->assertEquals(1000, $result->items[0]->pointsEarning);
    }

    // ========================================
    // 4. 여러 아이템 혼합 테스트
    // ========================================

    public function test_it_calculates_mixed_mileage_types_for_multiple_items(): void
    {
        // Given: 아이템1 - 정액 500원, 아이템2 - 정률 5%
        [$product1, $option1] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 500,
            mileageType: 'fixed'
        );

        [$product2, $option2] = $this->createProductWithOption(
            price: 50000,
            mileageValue: 5,
            mileageType: 'percent'
        );

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

        // Then:
        // 아이템1: 정액 500원 × 2개 = 1,000원
        // 아이템2: 50,000원 × 5% = 2,500원
        // 합계: 3,500원
        $this->assertEquals(1000, $result->items[0]->pointsEarning);
        $this->assertEquals(2500, $result->items[1]->pointsEarning);
        $this->assertEquals(3500, $result->summary->pointsEarning);
    }

    public function test_it_calculates_mixed_mileage_with_default_fallback(): void
    {
        // Given: 아이템1 - 정률 3%, 아이템2 - 기본 1%
        [$product1, $option1] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 3,
            mileageType: 'percent'
        );

        [$product2, $option2] = $this->createProductWithOption(
            price: 50000,
            mileageValue: null,
            mileageType: null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productOptionId: $option2->id,
                    quantity: 2
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then:
        // 아이템1: 100,000원 × 3% = 3,000원
        // 아이템2: (50,000원 × 2개) × 1% = 100,000원 × 1% = 1,000원
        // 합계: 4,000원
        $this->assertEquals(3000, $result->items[0]->pointsEarning);
        $this->assertEquals(1000, $result->items[1]->pointsEarning);
        $this->assertEquals(4000, $result->summary->pointsEarning);
    }

    // ========================================
    // 5. 엣지 케이스 테스트
    // ========================================

    public function test_it_handles_zero_mileage_value(): void
    {
        // Given: 마일리지 0원 설정 (적립 안 함)
        [$product, $option] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 0,
            mileageType: 'fixed'
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 5
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 0원 × 5개 = 0원
        $this->assertEquals(0, $result->items[0]->pointsEarning);
    }

    public function test_it_handles_zero_percent_mileage(): void
    {
        // Given: 0% 적립 설정
        [$product, $option] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 0,
            mileageType: 'percent'
        );

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

        // Then: 100,000원 × 0% = 0원
        $this->assertEquals(0, $result->items[0]->pointsEarning);
    }

    public function test_it_handles_very_small_percent_mileage(): void
    {
        // Given: 0.1% 적립 설정
        [$product, $option] = $this->createProductWithOption(
            price: 100000,
            mileageValue: 0.1,
            mileageType: 'percent'
        );

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

        // Then: floor(100,000 × 0.1%) = floor(100) = 100
        $this->assertEquals(100, $result->items[0]->pointsEarning);
    }

    // ========================================
    // 6. 마일리지 사용 테스트 (Section 7.8.6)
    // ========================================

    /**
     * 테스트 #55: 마일리지 사용 안분
     *
     * 입력: 사용 마일리지 1,000원, 옵션2개 (7:3 비율)
     * 기대: 옵션1: 700원, 옵션2: 300원
     */
    public function test_it_apportions_mileage_usage_by_subtotal_ratio(): void
    {
        // Given: 옵션1 70,000원, 옵션2 30,000원 (7:3 비율)
        [$product1, $option1] = $this->createProductWithOption(price: 70000);
        [$product2, $option2] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ],
            usePoints: 1000
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 1,000원을 7:3 비율로 안분 → 700원, 300원
        $this->assertEquals(1000, $result->summary->pointsUsed);
        $this->assertEquals(700, $result->items[0]->pointsUsedShare);
        $this->assertEquals(300, $result->items[1]->pointsUsedShare);
        // finalAmount = 100,000 - 1,000 = 99,000
        $this->assertEquals(99000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #56: 마일리지 사용 한도 초과
     *
     * 입력: 사용 마일리지 > 결제금액
     * 기대: 결제금액까지만 사용
     */
    public function test_it_limits_mileage_usage_to_payment_amount(): void
    {
        // Given: 상품 50,000원, 마일리지 100,000원 사용 시도
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            usePoints: 100000 // 결제금액보다 많음
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 실제 사용 마일리지는 결제금액(50,000원)으로 제한
        $this->assertEquals(50000, $result->summary->pointsUsed);
        $this->assertEquals(0, $result->summary->finalAmount);
    }

    /**
     * 테스트 #57: 마일리지 전액 사용
     *
     * 입력: 결제금액 50,000원, 마일리지 50,000원 사용
     * 기대: finalAmount = 0
     */
    public function test_it_allows_full_mileage_payment(): void
    {
        // Given: 상품 50,000원, 마일리지 50,000원 사용
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            usePoints: 50000
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 마일리지 전액 사용으로 결제금액 0원
        $this->assertEquals(50000, $result->summary->pointsUsed);
        $this->assertEquals(0, $result->summary->finalAmount);
    }

    /**
     * 테스트 #58: 마일리지 균등 안분
     *
     * 입력: 옵션3개 각 10,000원, 마일리지 3,000원
     * 기대: 각 1,000원 안분
     */
    public function test_it_apportions_mileage_evenly_for_equal_subtotals(): void
    {
        // Given: 3개 상품 각 10,000원
        [$product1, $option1] = $this->createProductWithOption(price: 10000);
        [$product2, $option2] = $this->createProductWithOption(price: 10000);
        [$product3, $option3] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productOptionId: $option2->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productOptionId: $option3->id,
                    quantity: 1
                ),
            ],
            usePoints: 3000
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 3,000원을 균등하게 안분 → 각 1,000원
        $this->assertEquals(3000, $result->summary->pointsUsed);
        $this->assertEquals(1000, $result->items[0]->pointsUsedShare);
        $this->assertEquals(1000, $result->items[1]->pointsUsedShare);
        $this->assertEquals(1000, $result->items[2]->pointsUsedShare);
        // finalAmount = 30,000 - 3,000 = 27,000
        $this->assertEquals(27000, $result->summary->finalAmount);
    }
}
