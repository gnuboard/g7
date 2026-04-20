<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\DTO\SnapshotProduct;
use Modules\Sirsoft\Ecommerce\DTO\SnapshotProductOption;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 스냅샷 기반 재계산 테스트
 *
 * 환불 시 주문 시점의 스냅샷 데이터를 사용한 재계산 로직을 검증합니다.
 * - SnapshotProduct/SnapshotProductOption DTO 동작
 * - 스냅샷 모드에서 DB 조회 없는 계산
 * - 쿠폰 스냅샷 모드 (검증 우회 + 스냅샷 규칙 사용)
 * - 배송비 스냅샷 정책 사용
 * - 다통화 스냅샷 환율 변환
 */
class SnapshotRecalculationTest extends ModuleTestCase
{
    protected OrderCalculationService $service;

    protected CurrencyConversionService $currencyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->service = app(OrderCalculationService::class);
        $this->currencyService = app(CurrencyConversionService::class);
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
                    'exchange_rate' => 1.35,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
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
        $settingsFile = storage_path('app/modules/sirsoft-ecommerce/settings/language_currency.json');
        if (file_exists($settingsFile)) {
            unlink($settingsFile);
        }

        parent::tearDown();
    }

    // ========================================
    // Section 1: SnapshotProduct/SnapshotProductOption DTO 테스트
    // ========================================

    /**
     * SnapshotProduct DTO가 스냅샷 배열에서 올바르게 생성됩니다.
     */
    public function test_snapshot_product_creates_from_array(): void
    {
        $snapshot = [
            'id' => 10,
            'name' => '테스트 상품',
            'selling_price' => 50000,
            'tax_status' => 'taxable',
            'tax_rate' => 10.0,
        ];

        $product = new SnapshotProduct($snapshot, 5);

        $this->assertEquals(10, $product->id);
        $this->assertEquals('테스트 상품', $product->name);
        $this->assertEquals(50000, $product->selling_price);
        $this->assertEquals(ProductTaxStatus::TAXABLE, $product->tax_status);
        $this->assertEquals(10.0, $product->tax_rate);
        $this->assertEquals(5, $product->shipping_policy_id);
        $this->assertEquals('테스트 상품', $product->getLocalizedName());
        $this->assertFalse($product->relationLoaded('categories'));
    }

    /**
     * SnapshotProductOption DTO가 스냅샷 배열에서 올바르게 생성됩니다.
     */
    public function test_snapshot_product_option_creates_from_array(): void
    {
        $snapshot = [
            'id' => 20,
            'selling_price' => 50000,
            'price_adjustment' => 5000,
            'weight' => 1.5,
            'volume' => 2.0,
            'mileage_value' => 3.0,
            'mileage_type' => 'rate',
            'option_name' => '빨강/XL',
        ];

        $option = new SnapshotProductOption($snapshot, 50000);

        $this->assertEquals(20, $option->id);
        $this->assertEquals(50000, $option->selling_price);
        $this->assertEquals(5000, $option->price_adjustment);
        $this->assertEquals(55000, $option->getSellingPrice());
        $this->assertEquals(55000, $option->getFinalPrice());
        $this->assertEquals(1.5, $option->weight);
        $this->assertEquals(2.0, $option->volume);
        $this->assertEquals(3.0, $option->mileage_value);
        $this->assertEquals('rate', $option->mileage_type);
        $this->assertEquals('빨강/XL', $option->getLocalizedOptionName());
    }

    /**
     * SnapshotProduct는 누락된 필드에 대해 기본값을 사용합니다.
     */
    public function test_snapshot_product_uses_defaults_for_missing_fields(): void
    {
        $product = new SnapshotProduct([]);

        $this->assertEquals(0, $product->id);
        $this->assertEquals('', $product->name);
        $this->assertEquals(0, $product->selling_price);
        $this->assertEquals(ProductTaxStatus::TAXABLE, $product->tax_status);
        $this->assertNull($product->shipping_policy_id);
    }

    // ========================================
    // Section 2: 스냅샷 모드 계산 (DB 조회 없이)
    // ========================================

    /**
     * 스냅샷 모드에서 DB 조회 없이 옵션별 소계를 계산합니다.
     */
    public function test_snapshot_mode_calculates_subtotal_without_db(): void
    {
        // Given: 스냅샷 데이터만으로 CalculationItem 구성 (DB에 해당 상품 없음)
        $productSnapshot = [
            'id' => 9999,
            'name' => '스냅샷 상품',
            'selling_price' => 30000,
            'tax_status' => 'taxable',
            'tax_rate' => 10.0,
        ];
        $optionSnapshot = [
            'id' => 8888,
            'selling_price' => 30000,
            'price_adjustment' => 0,
            'weight' => null,
            'volume' => null,
            'mileage_value' => null,
            'mileage_type' => null,
            'option_name' => '기본',
        ];

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: 9999,
                    productOptionId: 8888,
                    quantity: 3,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            metadata: ['snapshot_mode' => true],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: DB 조회 없이 스냅샷 기반 계산 성공
        $this->assertCount(1, $result->items);
        $this->assertEquals(30000, $result->items[0]->unitPrice);
        $this->assertEquals(3, $result->items[0]->quantity);
        $this->assertEquals(90000, $result->items[0]->subtotal);
    }

    /**
     * 스냅샷 모드에서 가격 변경이 반영되지 않습니다 (주문 시점 가격 유지).
     */
    public function test_snapshot_mode_uses_order_time_price_not_current(): void
    {
        // Given: DB에 상품 생성 (현재가 50,000원)
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => 50000,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
        ]);

        // 스냅샷에는 주문 시점 가격 (30,000원)
        $productSnapshot = [
            'id' => $product->id,
            'name' => '주문 시 이름',
            'selling_price' => 30000,
            'tax_status' => 'taxable',
        ];
        $optionSnapshot = [
            'id' => $option->id,
            'selling_price' => 30000,
            'price_adjustment' => 0,
            'weight' => null,
            'volume' => null,
        ];

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 2,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            metadata: ['snapshot_mode' => true],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 현재가(50,000)가 아닌 스냅샷 가격(30,000)으로 계산
        $this->assertEquals(30000, $result->items[0]->unitPrice);
        $this->assertEquals(60000, $result->items[0]->subtotal);
    }

    // ========================================
    // Section 3: 쿠폰 스냅샷 모드
    // ========================================

    /**
     * 테스트용 쿠폰과 발급 레코드를 생성합니다.
     *
     * @param  array  $couponOverrides  쿠폰 데이터 오버라이드
     * @param  array  $issueOverrides  발급 레코드 오버라이드
     * @return CouponIssue
     */
    protected function createCouponIssue(array $couponOverrides = [], array $issueOverrides = []): CouponIssue
    {
        $coupon = Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '테스트용', 'en' => 'Test'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ], $couponOverrides));

        $user = User::factory()->create();

        return CouponIssue::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'TEST'.uniqid(),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ], $issueOverrides));
    }

    /**
     * 스냅샷 모드에서 만료된 쿠폰도 재계산에 적용됩니다.
     */
    public function test_snapshot_mode_applies_expired_coupon(): void
    {
        // Given: 만료된 쿠폰 생성
        $couponIssue = $this->createCouponIssue(
            couponOverrides: [
                'discount_type' => CouponDiscountType::FIXED,
                'discount_value' => 5000,
                'valid_from' => now()->subDays(30),
                'valid_to' => now()->subDays(1), // 어제 만료
            ],
            issueOverrides: [
                'status' => CouponIssueRecordStatus::USED,
                'expired_at' => now()->subDays(1),
            ],
        );

        $productSnapshot = [
            'id' => 9999,
            'name' => '테스트 상품',
            'selling_price' => 50000,
            'tax_status' => 'taxable',
        ];
        $optionSnapshot = [
            'id' => 8888,
            'selling_price' => 50000,
            'price_adjustment' => 0,
        ];

        // 쿠폰 스냅샷: 주문 시점의 규칙
        $couponSnapshots = [
            $couponIssue->id => [
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'min_order_amount' => 0,
                'target_type' => 'product_amount',
                'target_scope' => 'all',
            ],
        ];

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: 9999,
                    productOptionId: 8888,
                    quantity: 1,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            couponIssueIds: [$couponIssue->id],
            metadata: [
                'snapshot_mode' => true,
                'coupon_snapshots' => $couponSnapshots,
            ],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 만료 쿠폰이지만 스냅샷 모드에서 적용됨
        $this->assertEquals(50000, $result->items[0]->subtotal);
        // 쿠폰 할인이 적용되었는지 확인
        $this->assertNotEmpty($result->promotions->getAllCoupons());
        $this->assertEquals(5000, $result->promotions->getAllCoupons()[0]->totalDiscount);
    }

    /**
     * 스냅샷 모드에서 쿠폰 스냅샷 규칙이 현재 쿠폰 규칙보다 우선합니다.
     */
    public function test_snapshot_mode_uses_snapshot_coupon_rules_over_current(): void
    {
        // Given: 현재 쿠폰은 10% 할인으로 변경됨
        $couponIssue = $this->createCouponIssue(
            couponOverrides: [
                'discount_type' => CouponDiscountType::RATE,
                'discount_value' => 10, // 현재: 10%
            ],
            issueOverrides: [
                'status' => CouponIssueRecordStatus::USED,
            ],
        );

        $productSnapshot = [
            'id' => 9999,
            'name' => '테스트 상품',
            'selling_price' => 100000,
            'tax_status' => 'taxable',
        ];
        $optionSnapshot = [
            'id' => 8888,
            'selling_price' => 100000,
            'price_adjustment' => 0,
        ];

        // 주문 시점에는 3,000원 정액 할인이었음
        $couponSnapshots = [
            $couponIssue->id => [
                'discount_type' => 'fixed',
                'discount_value' => 3000,
                'min_order_amount' => 0,
                'target_type' => 'product_amount',
                'target_scope' => 'all',
            ],
        ];

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: 9999,
                    productOptionId: 8888,
                    quantity: 1,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            couponIssueIds: [$couponIssue->id],
            metadata: [
                'snapshot_mode' => true,
                'coupon_snapshots' => $couponSnapshots,
            ],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 현재 규칙(10%=10,000원)이 아닌 스냅샷 규칙(정액 3,000원)으로 계산
        $appliedCoupons = $result->promotions->getAllCoupons();
        $this->assertNotEmpty($appliedCoupons);
        $this->assertEquals(3000, $appliedCoupons[0]->totalDiscount);
    }

    // ========================================
    // Section 4: 배송비 스냅샷 정책
    // ========================================

    /**
     * 스냅샷 모드에서 배송비가 스냅샷 정책으로 계산됩니다.
     */
    public function test_snapshot_mode_calculates_shipping_with_snapshot_policy(): void
    {
        // Given: DB에 배송정책 생성 (현재: 5,000원)
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '기본 배송', 'en' => 'Standard'],
            'is_default' => false,
            'is_active' => true,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FIXED,
            'base_fee' => 5000,
            'is_active' => true,
        ]);

        $productSnapshot = [
            'id' => 9999,
            'name' => '테스트 상품',
            'selling_price' => 30000,
            'tax_status' => 'taxable',
        ];
        $optionSnapshot = [
            'id' => 8888,
            'selling_price' => 30000,
            'price_adjustment' => 0,
            'weight' => 1.0,
            'volume' => null,
        ];

        // 스냅샷에는 주문 시점 배송비 (3,000원)
        $shippingPolicySnapshots = [
            8888 => [
                'policy_id' => $policy->id,
                'policy_snapshot' => [
                    'policy_name' => '기본 배송',
                    'country_code' => 'KR',
                    'charge_policy' => 'fixed',
                    'base_fee' => 3000, // 주문 시점: 3,000원
                    'conditional_free_threshold' => null,
                    'ranges' => null,
                    'extra_fee_template' => null,
                ],
            ],
        ];

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: 9999,
                    productOptionId: 8888,
                    quantity: 1,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
            shippingPolicySnapshots: $shippingPolicySnapshots,
            metadata: ['snapshot_mode' => true],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 현재 배송비(5,000)가 아닌 스냅샷 배송비(3,000)로 계산
        $this->assertEquals(3000, $result->summary->totalShipping);
    }

    // ========================================
    // Section 5: 다통화 스냅샷 환율 변환
    // ========================================

    /**
     * 스냅샷 환율로 다통화 변환이 수행됩니다.
     */
    public function test_convert_multiple_amounts_with_snapshot(): void
    {
        $amounts = ['refund_amount' => 50000]; // 50,000 KRW

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'exchange_rate' => 1.20,
            'exchange_rates' => [
                'KRW' => 1.0,
                'USD' => 1.20, // 주문 시점: 1.20 (현재: 1.35)
            ],
            'snapshot_at' => '2026-03-20T10:00:00+09:00',
        ];

        // When
        $result = $this->currencyService->convertMultipleAmountsWithSnapshot($amounts, $currencySnapshot);

        // Then: 스냅샷 환율(1.20) 사용
        $this->assertArrayHasKey('KRW', $result);
        $this->assertArrayHasKey('USD', $result);

        // KRW는 기본통화이므로 원본 그대로
        $this->assertEquals(50000, $result['KRW']['refund_amount']);

        // USD: (50000 / 1000) × 1.20 = 60.00
        $this->assertEquals(60.0, $result['USD']['refund_amount']);

        // 메타데이터에 snapshot_based 플래그 확인
        $this->assertTrue($result['USD']['_meta']['snapshot_based']);
        $this->assertEquals(1.20, $result['USD']['_meta']['exchange_rate']);
    }

    /**
     * 스냅샷 환율이 현재 설정 환율과 다르게 변환됩니다.
     */
    public function test_snapshot_exchange_rate_differs_from_current(): void
    {
        // 캐시 초기화 (이전 테스트 영향 방지)
        $this->currencyService->clearCache();

        $amounts = ['amount' => 100000]; // 100,000 KRW

        // 스냅샷 환율: USD 1.00
        $snapshotResult = $this->currencyService->convertMultipleAmountsWithSnapshot($amounts, [
            'base_currency' => 'KRW',
            'exchange_rates' => ['KRW' => 1.0, 'USD' => 1.00],
        ]);

        // 현재 환율: USD 1.35 (설정에서)
        $currentResult = $this->currencyService->convertMultipleAmounts($amounts);

        // Then: 스냅샷 환율(1.00)과 현재 환율(1.35)이 다름
        $snapshotUsd = $snapshotResult['USD']['amount']; // (100000/1000) × 1.00 = 100.00
        $currentUsd = $currentResult['USD']['amount'];   // (100000/1000) × 1.35 = 135.00

        $this->assertEquals(100.0, $snapshotUsd);
        // 현재 환율과 스냅샷 환율이 다른 것만 검증
        $this->assertNotEquals($snapshotUsd, $currentUsd);
    }

    // ========================================
    // Section 6: ProductOption::toSnapshotArray 마일리지 필드
    // ========================================

    /**
     * ProductOption::toSnapshotArray()에 마일리지 필드가 포함됩니다.
     */
    public function test_product_option_snapshot_includes_mileage_fields(): void
    {
        $product = ProductFactory::new()->create([
            'selling_price' => 30000,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'mileage_value' => 5.0,
            'mileage_type' => 'rate',
        ]);

        // When
        $snapshot = $option->toSnapshotArray();

        // Then
        $this->assertArrayHasKey('mileage_value', $snapshot);
        $this->assertArrayHasKey('mileage_type', $snapshot);
        $this->assertEquals(5.0, $snapshot['mileage_value']);
        $this->assertEquals('rate', $snapshot['mileage_type']);
    }
}
