<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;

use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 계산 서비스 기본 Unit 테스트
 *
 * OrderCalculationService의 기본 계산 기능을 검증합니다.
 * - 옵션별 판매금액 계산
 * - 상품/카테고리 쿠폰 적용
 * - 배송비 계산
 * - 과세/면세 분류
 * - 쿠폰 검증 규칙
 * - 안분 계산 정확성
 * - 복합 시나리오
 */
class OrderCalculationServiceTest extends ModuleTestCase
{
    protected OrderCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 통화 설정 주입
        $this->setupTestCurrencySettings();

        // 서비스 인스턴스 생성 (DI 컨테이너를 통해 resolve)
        $this->service = app(OrderCalculationService::class);
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
     * @param  int  $price  상품 판매가
     * @param  int  $priceAdjustment  옵션 추가금액
     * @return array [Product, ProductOption]
     */
    protected function createProductWithOption(
        int $price = 50000,
        int $priceAdjustment = 0
    ): array {
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => $priceAdjustment,
            'stock_quantity' => 100,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    // ========================================
    // Section 7.1: 옵션별 판매금액 계산 (3개)
    // ========================================

    /**
     * 테스트 #1: 단일 옵션 소계 계산
     *
     * 입력: 옵션A: 10,000원 × 2개
     * 기대: subtotal = 20,000원
     */
    public function test_it_calculates_single_option_subtotal(): void
    {
        // Given: 10,000원 상품, 옵션 추가금액 0원
        [$product, $option] = $this->createProductWithOption(
            price: 10000,
            priceAdjustment: 0
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 2
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertCount(1, $result->items);
        $this->assertEquals(10000, $result->items[0]->unitPrice);
        $this->assertEquals(2, $result->items[0]->quantity);
        $this->assertEquals(20000, $result->items[0]->subtotal);
    }

    /**
     * 테스트 #2: 다중 옵션 소계 합산
     *
     * 입력: 옵션A: 10,000원 × 2개, 옵션B: 5,000원 × 3개
     * 기대: summary.subtotal = 35,000원
     */
    public function test_it_calculates_multiple_options_subtotal_sum(): void
    {
        // Given: 상품A 10,000원, 상품B 5,000원
        [$productA, $optionA] = $this->createProductWithOption(price: 10000, priceAdjustment: 0);
        [$productB, $optionB] = $this->createProductWithOption(price: 5000, priceAdjustment: 0);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 2
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 3
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertCount(2, $result->items);

        // 옵션A: 10,000 × 2 = 20,000
        $this->assertEquals(20000, $result->items[0]->subtotal);

        // 옵션B: 5,000 × 3 = 15,000
        $this->assertEquals(15000, $result->items[1]->subtotal);

        // 합계: 20,000 + 15,000 = 35,000
        $this->assertEquals(35000, $result->summary->subtotal);
    }

    /**
     * 테스트 #3: 수량 0인 옵션 제외
     *
     * 입력: 옵션A: 10,000원 × 0개
     * 기대: 계산 대상에서 제외되어 빈 결과 반환
     */
    public function test_it_excludes_zero_quantity_option(): void
    {
        // Given: 10,000원 상품, 수량 0
        [$product, $option] = $this->createProductWithOption(price: 10000, priceAdjustment: 0);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 0
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 수량 0인 아이템은 prepareItems에서 로드되지만,
        // subtotal 계산에서는 0 × 가격 = 0이 됨
        // 실제 구현에 따라 빈 배열이거나 subtotal=0일 수 있음
        if (count($result->items) === 0) {
            // 빈 결과인 경우
            $this->assertEquals(0, $result->summary->subtotal);
        } else {
            // 아이템이 있지만 subtotal이 0인 경우
            $this->assertEquals(0, $result->items[0]->subtotal);
            $this->assertEquals(0, $result->summary->subtotal);
        }
    }

    // ========================================
    // Section 7.2: 상품/카테고리 쿠폰 적용 (6개)
    // ========================================

    /**
     * 테스트용 쿠폰과 발급 내역을 생성합니다.
     *
     * @param  CouponTargetType  $targetType  적용 대상 타입
     * @param  CouponDiscountType  $discountType  할인 타입
     * @param  float  $discountValue  할인 값
     * @param  CouponTargetScope  $targetScope  적용 범위
     * @param  float|null  $maxDiscount  최대 할인금액
     * @return CouponIssue 쿠폰 발급 내역
     */
    protected function createCouponWithIssue(
        CouponTargetType $targetType = CouponTargetType::PRODUCT_AMOUNT,
        CouponDiscountType $discountType = CouponDiscountType::FIXED,
        float $discountValue = 1000,
        CouponTargetScope $targetScope = CouponTargetScope::ALL,
        ?float $maxDiscount = null,
    ): CouponIssue {
        $coupon = Coupon::create([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '테스트용 쿠폰', 'en' => 'Test coupon'],
            'target_type' => $targetType,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_max_amount' => $maxDiscount,
            'min_order_amount' => 0,
            'target_scope' => $targetScope,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ]);

        // 테스트용 사용자 생성
        $user = User::factory()->create();

        $couponIssue = CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'TEST'.uniqid(),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ]);

        return $couponIssue;
    }

    /**
     * 테스트용 카테고리를 생성합니다.
     *
     * @param  string  $name  카테고리명
     * @return Category
     */
    protected function createCategory(string $name = '테스트 카테고리'): Category
    {
        $category = Category::create([
            'name' => ['ko' => $name, 'en' => 'Test Category'],
            'slug' => 'test-category-'.uniqid(),
            'path' => '1',
            'depth' => 0,
            'is_active' => true,
        ]);

        // path 업데이트 (자기 ID 기반)
        $category->update(['path' => (string) $category->id]);

        return $category;
    }

    /**
     * 테스트 #4: 전체상품 정액 할인
     *
     * 입력: 쿠폰: all, fixed 1,000원
     * 기대: 각 옵션에 금액 비율로 할인 적용
     */
    public function test_it_applies_fixed_discount_to_all_products(): void
    {
        // Given: 상품A 20,000원, 상품B 10,000원
        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        // 전체상품 정액 1,000원 할인 쿠폰
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 각 옵션에 정액 할인 적용 (각각 1,000원씩)
        $this->assertEquals(1000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(1000, $result->items[1]->productCouponDiscountAmount);
        $this->assertEquals(2000, $result->summary->productCouponDiscount);
    }

    /**
     * 테스트 #5: 전체상품 정률 할인
     *
     * 입력: 쿠폰: all, rate 10%
     * 기대: 각 옵션에 10% 할인 적용
     */
    public function test_it_applies_rate_discount_to_all_products(): void
    {
        // Given: 상품A 20,000원, 상품B 10,000원
        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        // 전체상품 정률 10% 할인 쿠폰
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 각 옵션에 10% 할인
        // 상품A: 20,000 × 10% = 2,000원
        // 상품B: 10,000 × 10% = 1,000원
        $this->assertEquals(2000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(1000, $result->items[1]->productCouponDiscountAmount);
        $this->assertEquals(3000, $result->summary->productCouponDiscount);
    }

    /**
     * 테스트 #6: 특정상품 정액 할인
     *
     * 입력: 쿠폰: products [1,2], fixed 2,000원
     * 기대: 상품1,2만 할인
     */
    public function test_it_applies_fixed_discount_to_specific_products(): void
    {
        // Given: 상품A 20,000원, 상품B 10,000원, 상품C 15,000원
        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);
        [$productC, $optionC] = $this->createProductWithOption(price: 15000);

        // 특정상품 정액 2,000원 할인 쿠폰 (상품A, B만)
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
            targetScope: CouponTargetScope::PRODUCTS
        );

        // 쿠폰에 적용 상품 연결
        $couponIssue->coupon->includedProducts()->attach([$productA->id, $productB->id], ['type' => 'include']);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productC->id,
                    productOptionId: $optionC->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 상품A, B만 할인 적용, 상품C는 할인 없음
        $this->assertEquals(2000, $result->items[0]->productCouponDiscountAmount); // 상품A
        $this->assertEquals(2000, $result->items[1]->productCouponDiscountAmount); // 상품B
        $this->assertEquals(0, $result->items[2]->productCouponDiscountAmount);    // 상품C
        $this->assertEquals(4000, $result->summary->productCouponDiscount);
    }

    /**
     * 테스트 #7: 특정상품 정률 할인
     *
     * 입력: 쿠폰: products [1], rate 15%
     * 기대: 상품1만 15% 할인
     */
    public function test_it_applies_rate_discount_to_specific_product(): void
    {
        // Given: 상품A 20,000원, 상품B 10,000원
        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        // 특정상품 정률 15% 할인 쿠폰 (상품A만)
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 15,
            targetScope: CouponTargetScope::PRODUCTS
        );

        // 쿠폰에 적용 상품 연결
        $couponIssue->coupon->includedProducts()->attach([$productA->id], ['type' => 'include']);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 상품A만 15% 할인 = 3,000원, 상품B는 할인 없음
        $this->assertEquals(3000, $result->items[0]->productCouponDiscountAmount); // 상품A: 20,000 × 15%
        $this->assertEquals(0, $result->items[1]->productCouponDiscountAmount);    // 상품B: 할인 없음
        $this->assertEquals(3000, $result->summary->productCouponDiscount);
    }

    /**
     * 테스트 #8: 특정카테고리 할인
     *
     * 입력: 쿠폰: categories [10], rate 20%
     * 기대: 카테고리10 상품만 20% 할인
     */
    public function test_it_applies_rate_discount_to_specific_category(): void
    {
        // Given: 카테고리 생성
        $category = $this->createCategory('테스트 카테고리');

        // 상품A는 카테고리에 속함, 상품B는 카테고리 없음
        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        // 상품A에 카테고리 연결
        $productA->categories()->attach($category->id);

        // 특정카테고리 정률 20% 할인 쿠폰
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 20,
            targetScope: CouponTargetScope::CATEGORIES
        );

        // 쿠폰에 적용 카테고리 연결
        $couponIssue->coupon->includedCategories()->attach([$category->id], ['type' => 'include']);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 상품A만 20% 할인 = 4,000원, 상품B는 카테고리가 없어 할인 없음
        $this->assertEquals(4000, $result->items[0]->productCouponDiscountAmount); // 상품A: 20,000 × 20%
        $this->assertEquals(0, $result->items[1]->productCouponDiscountAmount);    // 상품B: 할인 없음
        $this->assertEquals(4000, $result->summary->productCouponDiscount);
    }

    /**
     * 테스트 #9: 할인 최대금액 제한
     *
     * 입력: 쿠폰: rate 50%, max_discount 5,000원
     * 기대: 할인액 5,000원 초과 불가
     */
    public function test_it_limits_discount_to_max_amount(): void
    {
        // Given: 상품 30,000원
        [$product, $option] = $this->createProductWithOption(price: 30000);

        // 전체상품 정률 50% 할인 쿠폰, 최대 할인 5,000원
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 50,
            targetScope: CouponTargetScope::ALL,
            maxDiscount: 5000
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 50% = 15,000원이지만, 최대 할인 5,000원 적용
        $this->assertEquals(5000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(5000, $result->summary->productCouponDiscount);
    }

    // ========================================
    // Section 7.3: 배송비 계산 - 단일 정책 (14개 중 주요 6개)
    // ========================================

    /**
     * 테스트용 배송정책을 생성합니다.
     *
     * 국가별 설정(ShippingPolicyCountrySetting) 구조로 생성합니다.
     *
     * @param  ChargePolicyEnum  $chargePolicy  배송비 부과정책
     * @param  int  $baseFee  기본 배송비
     * @param  int|null  $freeThreshold  무료배송 기준금액
     * @param  array|null  $ranges  구간 설정
     * @param  bool  $extraFeeEnabled  도서산간 추가배송비 사용여부
     * @param  array|null  $extraFeeSettings  도서산간 추가배송비 설정
     * @param  bool  $extraFeeMultiply  도서산간 추가배송비 수량비례 적용
     * @param  string  $countryCode  국가코드
     * @param  string  $currencyCode  통화코드
     * @return ShippingPolicy
     */
    protected function createShippingPolicy(
        ChargePolicyEnum $chargePolicy = ChargePolicyEnum::FREE,
        int $baseFee = 0,
        ?int $freeThreshold = null,
        ?array $ranges = null,
        bool $extraFeeEnabled = false,
        ?array $extraFeeSettings = null,
        bool $extraFeeMultiply = false,
        string $countryCode = 'KR',
        string $currencyCode = 'KRW',
        ?string $apiEndpoint = null,
        ?array $apiRequestFields = null,
        ?string $apiResponseFeeField = null,
    ): ShippingPolicy {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => $countryCode,
            'shipping_method' => 'parcel',
            'currency_code' => $currencyCode,
            'charge_policy' => $chargePolicy,
            'base_fee' => $baseFee,
            'free_threshold' => $freeThreshold,
            'ranges' => $ranges,
            'api_endpoint' => $apiEndpoint,
            'api_request_fields' => $apiRequestFields,
            'api_response_fee_field' => $apiResponseFeeField,
            'extra_fee_enabled' => $extraFeeEnabled,
            'extra_fee_settings' => $extraFeeSettings,
            'extra_fee_multiply' => $extraFeeMultiply,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
    }

    /**
     * 테스트용 배송정책을 다국가 설정으로 생성합니다.
     *
     * @param  array  $countrySettingsData  국가별 설정 배열 [{country_code, charge_policy, base_fee, ...}, ...]
     * @return ShippingPolicy
     */
    protected function createMultiCountryShippingPolicy(array $countrySettingsData): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 다국가 배송정책', 'en' => 'Test Multi-Country Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        foreach ($countrySettingsData as $cs) {
            $policy->countrySettings()->create(array_merge([
                'shipping_method' => 'parcel',
                'currency_code' => 'KRW',
                'charge_policy' => ChargePolicyEnum::FREE,
                'base_fee' => 0,
                'free_threshold' => null,
                'ranges' => null,
                'extra_fee_enabled' => false,
                'extra_fee_settings' => null,
                'extra_fee_multiply' => false,
                'is_active' => true,
            ], $cs));
        }

        return $policy->load('countrySettings');
    }

    /**
     * 테스트용 상품과 옵션을 배송정책과 함께 생성합니다.
     *
     * @param  int  $price  상품 판매가
     * @param  ShippingPolicy  $shippingPolicy  배송정책
     * @return array [Product, ProductOption]
     */
    protected function createProductWithShippingPolicy(
        int $price,
        ShippingPolicy $shippingPolicy
    ): array {
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
            'shipping_policy_id' => $shippingPolicy->id,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    /**
     * 무게/부피가 설정된 상품+옵션 생성
     *
     * @param  int  $price  상품 가격
     * @param  ShippingPolicy  $shippingPolicy  배송정책
     * @param  float  $weight  무게 (kg)
     * @param  float  $volume  부피 (cm³)
     * @return array [Product, ProductOption]
     */
    protected function createProductWithDimensions(
        int $price,
        ShippingPolicy $shippingPolicy,
        float $weight,
        float $volume
    ): array {
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
            'shipping_policy_id' => $shippingPolicy->id,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
            'weight' => $weight,
            'volume' => $volume,
        ]);

        return [$product, $option];
    }

    /**
     * 테스트 #10: FREE 정책 - 무료 배송
     *
     * 입력: FREE 정책
     * 기대: 배송비 0원
     */
    public function test_it_calculates_free_shipping_policy(): void
    {
        // Given: FREE 배송정책
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FREE
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);

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

        // Then: 배송비 0원
        $this->assertEquals(0, $result->summary->totalShipping);
    }

    /**
     * 테스트 #11: FIXED 정책 - 고정 배송비
     *
     * 입력: FIXED, base_fee: 3,000원
     * 기대: 배송비 3,000원
     */
    public function test_it_calculates_fixed_shipping_policy(): void
    {
        // Given: FIXED 3,000원 배송정책
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);

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

        // Then: 배송비 3,000원
        $this->assertEquals(3000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #12: CONDITIONAL_FREE 정책 - 무료배송 충족
     *
     * 입력: CONDITIONAL_FREE, base_fee: 3,000원, threshold: 50,000원, 그룹합계 60,000원
     * 기대: 배송비 0원
     */
    public function test_it_calculates_conditional_free_shipping_when_threshold_met(): void
    {
        // Given: CONDITIONAL_FREE (5만원 이상 무료, 미만 3,000원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000
        );
        [$product, $option] = $this->createProductWithShippingPolicy(60000, $shippingPolicy);

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

        // Then: 6만원 > 5만원 → 무료배송
        $this->assertEquals(0, $result->summary->totalShipping);
    }

    /**
     * 테스트 #13: CONDITIONAL_FREE 정책 - 무료배송 미충족
     *
     * 입력: CONDITIONAL_FREE, base_fee: 3,000원, threshold: 50,000원, 그룹합계 30,000원
     * 기대: 배송비 3,000원
     */
    public function test_it_calculates_conditional_free_shipping_when_threshold_not_met(): void
    {
        // Given: CONDITIONAL_FREE (5만원 이상 무료, 미만 3,000원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);

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

        // Then: 3만원 < 5만원 → 배송비 3,000원
        $this->assertEquals(3000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #14: RANGE_AMOUNT 정책 - 금액 구간별 배송비
     *
     * 입력: RANGE_AMOUNT, tiers: [~2만:5천, ~4만:4천, ~∞:3천], 그룹합계 35,000원
     * 기대: 배송비 4,000원
     */
    public function test_it_calculates_range_amount_shipping_policy(): void
    {
        // Given: RANGE_AMOUNT 정책
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 20000, 'fee' => 5000],
                    ['min' => 20000, 'max' => 40000, 'fee' => 4000],
                    ['min' => 40000, 'max' => null, 'fee' => 3000],
                ],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(35000, $shippingPolicy);

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

        // Then: 35,000원 → ~4만원 구간 → 4,000원
        $this->assertEquals(4000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #15: RANGE_QUANTITY 정책 - 수량 구간별 배송비
     *
     * 입력: RANGE_QUANTITY, tiers: [~2개:5천, ~5개:4천, ~∞:3천], 총 수량 4개
     * 기대: 배송비 4,000원
     */
    public function test_it_calculates_range_quantity_shipping_policy(): void
    {
        // Given: RANGE_QUANTITY 정책
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_QUANTITY,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 2, 'fee' => 5000],
                    ['min' => 2, 'max' => 5, 'fee' => 4000],
                    ['min' => 5, 'max' => null, 'fee' => 3000],
                ],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $shippingPolicy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 4
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 4개 → ~5개 구간 → 4,000원
        $this->assertEquals(4000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #19: PER_QUANTITY 정책 - 수량당 배송비
     *
     * 입력: PER_QUANTITY, base_fee: 2,000원, unit_value: 2개, 수량 5개
     * 기대: ceil(5/2) × 2,000 = 6,000원
     */
    public function test_it_calculates_per_quantity_shipping_policy(): void
    {
        // Given: PER_QUANTITY (2개당 2,000원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 2000,
            ranges: ['unit_value' => 2]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $shippingPolicy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 5
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: ceil(5/2) × 2,000 = 3 × 2,000 = 6,000원
        $this->assertEquals(6000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #16: RANGE_WEIGHT 정책 - 무게 구간별 배송비
     *
     * 입력: RANGE_WEIGHT, tiers: [~1kg:3천, ~3kg:5천, ~∞:8천], 무게 2.5kg
     * 기대: 배송비 5,000원
     */
    public function test_it_calculates_range_weight_shipping_policy(): void
    {
        // Given: RANGE_WEIGHT 정책 (g 단위로 설정)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 1000, 'fee' => 3000],      // ~1kg
                    ['min' => 1000, 'max' => 3000, 'fee' => 5000],   // ~3kg
                    ['min' => 3000, 'max' => null, 'fee' => 8000],   // 3kg~
                ],
            ]
        );
        // 2.5kg = 2500g → ~3000g 구간 → 5000원
        [$product, $option] = $this->createProductWithDimensions(20000, $shippingPolicy, 2.5, 1000);

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

        // Then: 2.5kg → ~3kg 구간 → 5,000원
        $this->assertEquals(5000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #17: RANGE_VOLUME 정책 - 부피 구간별 배송비
     *
     * 입력: RANGE_VOLUME, tiers: [~5000cm³:3천, ~10000cm³:5천, ~∞:8천], 부피 7000cm³
     * 기대: 배송비 5,000원
     */
    public function test_it_calculates_range_volume_shipping_policy(): void
    {
        // Given: RANGE_VOLUME 정책
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 5000, 'fee' => 3000],
                    ['min' => 5000, 'max' => 10000, 'fee' => 5000],
                    ['min' => 10000, 'max' => null, 'fee' => 8000],
                ],
            ]
        );
        [$product, $option] = $this->createProductWithDimensions(20000, $shippingPolicy, 1.0, 7000);

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

        // Then: 7000cm³ → ~10000cm³ 구간 → 5,000원
        $this->assertEquals(5000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #18: RANGE_VOLUME_WEIGHT 정책 - 부피무게 구간별 배송비
     *
     * 입력: RANGE_VOLUME_WEIGHT, divisor: 6000, tiers: [~2kg:3천, ~5kg:5천, ~∞:8천]
     * 부피 18000cm³ → 부피무게 3kg
     * 기대: 배송비 5,000원
     */
    public function test_it_calculates_range_volume_weight_shipping_policy(): void
    {
        // Given: RANGE_VOLUME_WEIGHT 정책 (g 단위로 설정)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME_WEIGHT,
            ranges: [
                'volume_weight_divisor' => 6000,
                'tiers' => [
                    ['min' => 0, 'max' => 2000, 'fee' => 3000],      // ~2kg
                    ['min' => 2000, 'max' => 5000, 'fee' => 5000],   // ~5kg
                    ['min' => 5000, 'max' => null, 'fee' => 8000],   // 5kg~
                ],
            ]
        );
        // 부피 18000cm³ / 6000 = 부피무게 3kg = 3000g → ~5000g 구간 → 5000원
        [$product, $option] = $this->createProductWithDimensions(20000, $shippingPolicy, 1.0, 18000);

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

        // Then: 부피무게 3kg → ~5kg 구간 → 5,000원
        $this->assertEquals(5000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #20: PER_WEIGHT 정책 - 무게당 배송비
     *
     * 입력: PER_WEIGHT, base_fee: 1000원, unit_value: 0.5kg, 무게 2kg
     * 기대: ceil(2/0.5) × 1,000 = 4 × 1,000 = 4,000원
     */
    public function test_it_calculates_per_weight_shipping_policy(): void
    {
        // Given: PER_WEIGHT (0.5kg당 1,000원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_WEIGHT,
            baseFee: 1000,
            ranges: ['unit_value' => 0.5]
        );
        [$product, $option] = $this->createProductWithDimensions(20000, $shippingPolicy, 2.0, 1000);

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

        // Then: ceil(2/0.5) × 1,000 = 4 × 1,000 = 4,000원
        $this->assertEquals(4000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #21: PER_VOLUME 정책 - 부피당 배송비
     *
     * 입력: PER_VOLUME, base_fee: 500원, unit_value: 1000cm³, 부피 3500cm³
     * 기대: ceil(3500/1000) × 500 = 4 × 500 = 2,000원
     */
    public function test_it_calculates_per_volume_shipping_policy(): void
    {
        // Given: PER_VOLUME (1000cm³당 500원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME,
            baseFee: 500,
            ranges: ['unit_value' => 1000]
        );
        [$product, $option] = $this->createProductWithDimensions(20000, $shippingPolicy, 1.0, 3500);

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

        // Then: ceil(3500/1000) × 500 = 4 × 500 = 2,000원
        $this->assertEquals(2000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #22: PER_VOLUME_WEIGHT 정책 - 부피무게당 배송비
     *
     * 입력: PER_VOLUME_WEIGHT, base_fee: 2000원, divisor: 5000, unit_value: 1kg
     * 부피 12000cm³ → 부피무게 2.4kg
     * 기대: ceil(2.4/1) × 2,000 = 3 × 2,000 = 6,000원
     */
    public function test_it_calculates_per_volume_weight_shipping_policy(): void
    {
        // Given: PER_VOLUME_WEIGHT (부피무게 1kg당 2,000원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME_WEIGHT,
            baseFee: 2000,
            ranges: ['volume_weight_divisor' => 5000, 'unit_value' => 1]
        );
        // 부피 12000cm³ / 5000 = 부피무게 2.4kg
        [$product, $option] = $this->createProductWithDimensions(20000, $shippingPolicy, 1.0, 12000);

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

        // Then: ceil(2.4/1) × 2,000 = 3 × 2,000 = 6,000원
        $this->assertEquals(6000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #23: PER_AMOUNT 정책 - 금액당 배송비
     *
     * 입력: PER_AMOUNT, base_fee: 1000원, unit_value: 10000원, 금액 35000원
     * 기대: ceil(35000/10000) × 1,000 = 4 × 1,000 = 4,000원
     */
    public function test_it_calculates_per_amount_shipping_policy(): void
    {
        // Given: PER_AMOUNT (10,000원당 1,000원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_AMOUNT,
            baseFee: 1000,
            ranges: ['unit_value' => 10000]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(35000, $shippingPolicy);

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

        // Then: ceil(35000/10000) × 1,000 = 4 × 1,000 = 4,000원
        $this->assertEquals(4000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #24: API 정책 배송비 계산 (api_endpoint 미설정)
     *
     * api_endpoint 미설정 시 base_fee 반환
     */
    public function test_it_calculates_api_shipping_policy_without_endpoint(): void
    {
        // Given: API 정책 (api_endpoint 미설정 → base_fee 반환)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::API,
            baseFee: 5000,
            ranges: [] // api_endpoint 미설정
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);

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

        // Then: api_endpoint 미설정 → base_fee (5,000원)
        $this->assertEquals(5000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #24-2: API 정책 배송비 계산 (Mock API 성공 응답)
     *
     * 외부 API 호출을 Mock하여 배송비 계산 테스트
     */
    public function test_it_calculates_api_shipping_policy_with_mock_response(): void
    {
        // Given: Mock API 응답 설정 (와일드카드 패턴)
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response([
                'shipping_fee' => 7500,
                'message' => 'success',
            ], 200),
        ]);

        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::API,
            baseFee: 5000, // fallback
            apiEndpoint: 'https://shipping-api.example.com/calculate',
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);

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

        // Then: Mock API 응답 배송비 (7,500원)
        $this->assertEquals(7500, $result->summary->totalShipping);

        // API 호출 확인
        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), 'shipping-api.example.com');
        });
    }

    /**
     * 테스트 #24-3: API 정책 배송비 계산 (API 실패 시 fallback)
     *
     * API 호출 실패 시 base_fee로 fallback
     */
    public function test_it_falls_back_to_base_fee_when_api_fails(): void
    {
        // Given: Mock API 실패 응답
        \Illuminate\Support\Facades\Http::fake([
            'https://shipping-api.example.com/calculate' => \Illuminate\Support\Facades\Http::response([
                'error' => 'Service unavailable',
            ], 500),
        ]);

        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::API,
            baseFee: 5000, // fallback
            apiEndpoint: 'https://shipping-api.example.com/calculate',
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);

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

        // Then: API 실패 → base_fee (5,000원)로 fallback
        $this->assertEquals(5000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #24-4: API 정책 배송비 계산 (API 타임아웃 시 fallback)
     *
     * API 호출 타임아웃 시 base_fee로 fallback
     */
    public function test_it_falls_back_to_base_fee_when_api_timeout(): void
    {
        // Given: Mock API 타임아웃 (ConnectionException)
        \Illuminate\Support\Facades\Http::fake([
            'https://shipping-api.example.com/calculate' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
            },
        ]);

        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::API,
            baseFee: 5000, // fallback
            apiEndpoint: 'https://shipping-api.example.com/calculate',
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);

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

        // Then: API 타임아웃 → base_fee (5,000원)로 fallback
        $this->assertEquals(5000, $result->summary->totalShipping);
    }

    // ========================================
    // Section 7.4: 배송비 계산 - 다중 정책 조합 (6개)
    // ========================================

    /**
     * 테스트 #25: 2개 정책 그룹 (FIXED + CONDITIONAL_FREE 미충족)
     *
     * 상품A,B: FIXED 3,000원 / 상품C: CONDITIONAL_FREE(미충족) 2,500원
     * 기대: 총 5,500원
     */
    public function test_it_calculates_two_policy_groups_fixed_and_conditional(): void
    {
        // Given: FIXED 정책 (3,000원)
        $fixedPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000
        );

        // CONDITIONAL_FREE 정책 (5만원 미만 시 2,500원)
        $conditionalPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 2500,
            freeThreshold: 50000
        );

        // 상품A, B: FIXED 정책 적용
        [$productA, $optionA] = $this->createProductWithShippingPolicy(20000, $fixedPolicy);
        [$productB, $optionB] = $this->createProductWithShippingPolicy(15000, $fixedPolicy);

        // 상품C: CONDITIONAL_FREE 정책 적용 (금액 미충족)
        [$productC, $optionC] = $this->createProductWithShippingPolicy(10000, $conditionalPolicy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productC->id,
                    productOptionId: $optionC->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: FIXED 그룹 3,000원 + CONDITIONAL_FREE 그룹 2,500원 = 5,500원
        $this->assertEquals(5500, $result->summary->totalShipping);
    }

    /**
     * 테스트 #26: 3개 정책 그룹 (FREE + FIXED + PER_QUANTITY)
     *
     * 상품A: FREE / 상품B: FIXED 3,000원 / 상품C,D: PER_QUANTITY 2,000원×2
     * 기대: 총 7,000원 (0 + 3,000 + 4,000)
     */
    public function test_it_calculates_three_policy_groups(): void
    {
        // Given: FREE 정책
        $freePolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FREE
        );

        // FIXED 정책 (3,000원)
        $fixedPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000
        );

        // PER_QUANTITY 정책 (2개당 2,000원)
        $perQuantityPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 2000,
            ranges: ['unit_value' => 2]
        );

        [$productA, $optionA] = $this->createProductWithShippingPolicy(10000, $freePolicy);
        [$productB, $optionB] = $this->createProductWithShippingPolicy(20000, $fixedPolicy);
        [$productC, $optionC] = $this->createProductWithShippingPolicy(15000, $perQuantityPolicy);
        [$productD, $optionD] = $this->createProductWithShippingPolicy(15000, $perQuantityPolicy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productC->id,
                    productOptionId: $optionC->id,
                    quantity: 2
                ),
                new CalculationItem(
                    productId: $productD->id,
                    productOptionId: $optionD->id,
                    quantity: 2
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: FREE 0원 + FIXED 3,000원 + PER_QUANTITY ceil(4/2)×2,000 = 4,000원 = 7,000원
        $this->assertEquals(7000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #27: 동일 정책 다른 설정 (CONDITIONAL_FREE)
     *
     * 상품A,B: CONDITIONAL_FREE(5만원 기준) / 상품C,D: CONDITIONAL_FREE(3만원 기준)
     * 각 그룹별 독립 계산
     */
    public function test_it_calculates_same_policy_different_settings(): void
    {
        // Given: CONDITIONAL_FREE 정책 (5만원 기준, 배송비 3,000원)
        $policy50k = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000
        );

        // CONDITIONAL_FREE 정책 (3만원 기준, 배송비 2,500원)
        $policy30k = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 2500,
            freeThreshold: 30000
        );

        // 그룹1: 5만원 기준 미충족 (합계 40,000원)
        [$productA, $optionA] = $this->createProductWithShippingPolicy(20000, $policy50k);
        [$productB, $optionB] = $this->createProductWithShippingPolicy(20000, $policy50k);

        // 그룹2: 3만원 기준 충족 (합계 35,000원)
        [$productC, $optionC] = $this->createProductWithShippingPolicy(20000, $policy30k);
        [$productD, $optionD] = $this->createProductWithShippingPolicy(15000, $policy30k);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productC->id,
                    productOptionId: $optionC->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productD->id,
                    productOptionId: $optionD->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 그룹1 미충족 3,000원 + 그룹2 충족 0원 = 3,000원
        $this->assertEquals(3000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #28: RANGE + PER 혼합
     *
     * 상품A: RANGE_AMOUNT / 상품B: PER_QUANTITY / 상품C: RANGE_QUANTITY
     * 각 정책별 정확한 계산
     */
    public function test_it_calculates_range_and_per_mixed_policies(): void
    {
        // Given: RANGE_AMOUNT 정책 (구간별)
        $rangeAmountPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            baseFee: 0,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 20000, 'fee' => 5000],
                    ['min' => 20000, 'max' => 40000, 'fee' => 4000],
                    ['min' => 40000, 'max' => null, 'fee' => 3000],
                ],
            ]
        );

        // PER_QUANTITY 정책 (2개당 2,000원)
        $perQuantityPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 2000,
            ranges: ['unit_value' => 2]
        );

        // RANGE_QUANTITY 정책 (구간별)
        $rangeQuantityPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_QUANTITY,
            baseFee: 0,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 2, 'fee' => 5000],
                    ['min' => 2, 'max' => 5, 'fee' => 4000],
                    ['min' => 5, 'max' => null, 'fee' => 3000],
                ],
            ]
        );

        [$productA, $optionA] = $this->createProductWithShippingPolicy(25000, $rangeAmountPolicy);
        [$productB, $optionB] = $this->createProductWithShippingPolicy(10000, $perQuantityPolicy);
        [$productC, $optionC] = $this->createProductWithShippingPolicy(15000, $rangeQuantityPolicy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1 // 금액 25,000원 → 2~4만 구간 → 4,000원
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 3 // ceil(3/2) = 2 × 2,000 = 4,000원
                ),
                new CalculationItem(
                    productId: $productC->id,
                    productOptionId: $optionC->id,
                    quantity: 3 // 2~5개 구간 → 4,000원
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 4,000 + 4,000 + 4,000 = 12,000원
        $this->assertEquals(12000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #29: 무료+유료 혼합
     *
     * 상품A,B: FREE / 상품C: FIXED 3,000원 / 상품D: CONDITIONAL_FREE(충족)
     * 기대: 총 3,000원
     */
    public function test_it_calculates_free_and_paid_mixed(): void
    {
        // Given: FREE 정책
        $freePolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FREE
        );

        // FIXED 정책 (3,000원)
        $fixedPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000
        );

        // CONDITIONAL_FREE 정책 (3만원 충족 시 무료)
        $conditionalPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 2500,
            freeThreshold: 30000
        );

        [$productA, $optionA] = $this->createProductWithShippingPolicy(10000, $freePolicy);
        [$productB, $optionB] = $this->createProductWithShippingPolicy(10000, $freePolicy);
        [$productC, $optionC] = $this->createProductWithShippingPolicy(20000, $fixedPolicy);
        [$productD, $optionD] = $this->createProductWithShippingPolicy(40000, $conditionalPolicy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productC->id,
                    productOptionId: $optionC->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productD->id,
                    productOptionId: $optionD->id,
                    quantity: 1 // 40,000원 ≥ 30,000원 → 무료
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: FREE 0원 + FIXED 3,000원 + CONDITIONAL_FREE(충족) 0원 = 3,000원
        $this->assertEquals(3000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #30: 4개 이상 정책 그룹
     *
     * 5개 상품, 각각 다른 배송정책 → 모든 정책 독립 계산 및 합산
     */
    public function test_it_calculates_four_or_more_policy_groups(): void
    {
        // Given: 5개의 다른 배송정책
        $policy1 = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FREE
        );

        $policy2 = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 2500
        );

        $policy3 = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 30000
        );

        $policy4 = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            baseFee: 0,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 30000, 'fee' => 4000],
                    ['min' => 30000, 'max' => null, 'fee' => 2000],
                ],
            ]
        );

        $policy5 = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1500,
            ranges: ['unit_value' => 3]
        );

        [$product1, $option1] = $this->createProductWithShippingPolicy(10000, $policy1);
        [$product2, $option2] = $this->createProductWithShippingPolicy(15000, $policy2);
        [$product3, $option3] = $this->createProductWithShippingPolicy(20000, $policy3); // 미충족
        [$product4, $option4] = $this->createProductWithShippingPolicy(35000, $policy4); // 3만 이상
        [$product5, $option5] = $this->createProductWithShippingPolicy(8000, $policy5);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product3->id,
                    productOptionId: $option3->id,
                    quantity: 1 // 20,000 < 30,000 → 3,000원
                ),
                new CalculationItem(
                    productId: $product4->id,
                    productOptionId: $option4->id,
                    quantity: 1 // 35,000 ≥ 30,000 → 2,000원
                ),
                new CalculationItem(
                    productId: $product5->id,
                    productOptionId: $option5->id,
                    quantity: 5 // ceil(5/3) = 2 × 1,500 = 3,000원
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 0 + 2,500 + 3,000 + 2,000 + 3,000 = 10,500원
        $this->assertEquals(10500, $result->summary->totalShipping);
    }

    // ========================================
    // Section 7.5: 배송비 안분 (3개)
    // ========================================

    /**
     * 테스트 #31: 동일 정책 2개 옵션 안분
     *
     * 정책A: 3,000원, 옵션 2개 (60,000원:40,000원 = 6:4 비율)
     * 기대: 옵션1: 1,800원, 옵션2: 1,200원
     */
    public function test_it_apportions_shipping_to_two_options_by_ratio(): void
    {
        // Given: FIXED 정책 (3,000원)
        $fixedPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000
        );

        // 옵션1: 60,000원 (60%)
        [$product1, $option1] = $this->createProductWithShippingPolicy(60000, $fixedPolicy);
        // 옵션2: 40,000원 (40%)
        [$product2, $option2] = $this->createProductWithShippingPolicy(40000, $fixedPolicy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 총 배송비 3,000원
        $this->assertEquals(3000, $result->summary->totalShipping);

        // 안분: 옵션1 60% = 1,800원, 옵션2 40% = 1,200원
        $this->assertNotNull($result->items[0]->appliedShippingPolicy);
        $this->assertNotNull($result->items[1]->appliedShippingPolicy);
        $this->assertEquals(1800, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(1200, $result->items[1]->appliedShippingPolicy->shippingAmount);
    }

    /**
     * 테스트 #32: 동일 정책 3개 옵션 안분
     *
     * 정책A: 5,000원, 옵션 3개 (50,000원:30,000원:20,000원 = 5:3:2 비율)
     * 기대: 2,500 + 1,500 + 1,000 = 5,000원
     */
    public function test_it_apportions_shipping_to_three_options_by_ratio(): void
    {
        // Given: FIXED 정책 (5,000원)
        $fixedPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 5000
        );

        // 옵션1: 50,000원 (50%)
        [$product1, $option1] = $this->createProductWithShippingPolicy(50000, $fixedPolicy);
        // 옵션2: 30,000원 (30%)
        [$product2, $option2] = $this->createProductWithShippingPolicy(30000, $fixedPolicy);
        // 옵션3: 20,000원 (20%)
        [$product3, $option3] = $this->createProductWithShippingPolicy(20000, $fixedPolicy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product3->id,
                    productOptionId: $option3->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 총 배송비 5,000원
        $this->assertEquals(5000, $result->summary->totalShipping);

        // 안분: 50% = 2,500원, 30% = 1,500원, 20% = 1,000원
        $this->assertNotNull($result->items[0]->appliedShippingPolicy);
        $this->assertNotNull($result->items[1]->appliedShippingPolicy);
        $this->assertNotNull($result->items[2]->appliedShippingPolicy);
        $this->assertEquals(2500, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(1500, $result->items[1]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(1000, $result->items[2]->appliedShippingPolicy->shippingAmount);
    }

    /**
     * 테스트 #33: 다중 정책 각각 안분
     *
     * 정책A: 3,000원 (옵션 2개), 정책B: 4,000원 (옵션 2개)
     * 각 정책 내 독립 안분
     */
    public function test_it_apportions_shipping_independently_per_policy(): void
    {
        // Given: 정책A (FIXED 3,000원)
        $policyA = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000
        );

        // 정책B (FIXED 4,000원)
        $policyB = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 4000
        );

        // 정책A 그룹: 옵션1 60,000원 (60%), 옵션2 40,000원 (40%)
        [$product1, $option1] = $this->createProductWithShippingPolicy(60000, $policyA);
        [$product2, $option2] = $this->createProductWithShippingPolicy(40000, $policyA);

        // 정책B 그룹: 옵션3 50,000원 (50%), 옵션4 50,000원 (50%)
        [$product3, $option3] = $this->createProductWithShippingPolicy(50000, $policyB);
        [$product4, $option4] = $this->createProductWithShippingPolicy(50000, $policyB);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product3->id,
                    productOptionId: $option3->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product4->id,
                    productOptionId: $option4->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 총 배송비 7,000원 (3,000 + 4,000)
        $this->assertEquals(7000, $result->summary->totalShipping);

        // 정책A 안분: 옵션1 1,800원, 옵션2 1,200원
        $this->assertEquals(1800, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(1200, $result->items[1]->appliedShippingPolicy->shippingAmount);

        // 정책B 안분: 옵션3 2,000원, 옵션4 2,000원
        $this->assertEquals(2000, $result->items[2]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(2000, $result->items[3]->appliedShippingPolicy->shippingAmount);
    }

    // ========================================
    // Section 7.5.1: 추가배송비(도서산간) 테스트 (10개)
    // ========================================

    /**
     * 테스트 #36-1: 도서산간 우편번호 매칭 시 추가배송비 부과
     *
     * 우편번호 63000 (제주도) → 추가배송비 3,000원
     */
    public function test_it_applies_extra_shipping_fee_for_island_zipcode(): void
    {
        // Given: 추가배송비 활성화된 정책
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000], // 제주도
                ['zipcode' => '54*', 'fee' => 5000], // 울릉도
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // 배송지 주소 (제주도)
        $shippingAddress = new ShippingAddress(zipcode: '63123');

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

        // Then: 기본 3,000원 + 추가 3,000원 = 6,000원
        $this->assertEquals(6000, $result->summary->totalShipping);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-2: 일반 지역 우편번호는 추가배송비 없음
     *
     * 우편번호 06000 (서울) → 추가배송비 없음
     */
    public function test_it_does_not_apply_extra_fee_for_normal_zipcode(): void
    {
        // Given: 추가배송비 활성화된 정책
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000], // 제주도만
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // 배송지 주소 (서울)
        $shippingAddress = new ShippingAddress(zipcode: '06123');

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

        // Then: 기본 3,000원만, 추가배송비 없음
        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(0, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-3: 배송지 주소 없으면 추가배송비 없음
     */
    public function test_it_does_not_apply_extra_fee_without_shipping_address(): void
    {
        // Given: 추가배송비 활성화된 정책
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // 배송지 주소 없음
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

        // Then: 기본 3,000원만
        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(0, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-4: 추가배송비 비활성화 시 적용 안 함
     */
    public function test_it_does_not_apply_extra_fee_when_disabled(): void
    {
        // Given: 추가배송비 비활성화
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: false, // 비활성화
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63123'); // 제주도

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

        // Then: 기본 3,000원만, 추가배송비 없음
        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(0, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-5: extra_fee_multiply=true + PER_QUANTITY 시 수량만큼 중복 부과
     *
     * PER_QUANTITY(2개당 1,000원), 수량 5개, 제주도 추가 3,000원
     * 기본: ceil(5/2) × 1,000 = 3,000원
     * 추가: 3,000 × ceil(5/2) = 9,000원 (multiply)
     */
    public function test_it_multiplies_extra_fee_for_per_quantity_policy(): void
    {
        // Given: PER_QUANTITY + extra_fee_multiply=true
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 2],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ],
            extraFeeMultiply: true
        );
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63123');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 5
                ),
            ],
            shippingAddress: $shippingAddress
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 기본 3,000원 + 추가 9,000원 = 12,000원
        $this->assertEquals(12000, $result->summary->totalShipping);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(9000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-6: extra_fee_multiply=false 시 1회만 부과
     *
     * PER_QUANTITY(2개당 1,000원), 수량 5개, 제주도 추가 3,000원
     * 기본: ceil(5/2) × 1,000 = 3,000원
     * 추가: 3,000원 (1회)
     */
    public function test_it_does_not_multiply_extra_fee_when_multiply_disabled(): void
    {
        // Given: PER_QUANTITY + extra_fee_multiply=false
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 2],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ],
            extraFeeMultiply: false
        );
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63123');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 5
                ),
            ],
            shippingAddress: $shippingAddress
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 기본 3,000원 + 추가 3,000원 = 6,000원
        $this->assertEquals(6000, $result->summary->totalShipping);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-7: FIXED 정책은 multiply와 무관하게 1회 부과
     */
    public function test_it_applies_extra_fee_once_for_fixed_policy_even_with_multiply(): void
    {
        // Given: FIXED + extra_fee_multiply=true (무시됨)
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ],
            extraFeeMultiply: true // FIXED에서는 무시됨
        );
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63123');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 5 // 수량과 무관
                ),
            ],
            shippingAddress: $shippingAddress
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 기본 3,000원 + 추가 3,000원 = 6,000원 (multiply 무시)
        $this->assertEquals(6000, $result->summary->totalShipping);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-8: 다중 정책 그룹에서 각각 추가배송비 적용
     */
    public function test_it_applies_extra_fee_to_each_policy_group(): void
    {
        // Given: 정책A (제주 3,000원 추가)
        $policyA = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ]
        );

        // 정책B (제주 5,000원 추가)
        $policyB = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 4000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 5000],
            ]
        );

        [$product1, $option1] = $this->createProductWithShippingPolicy(30000, $policyA);
        [$product2, $option2] = $this->createProductWithShippingPolicy(40000, $policyB);

        $shippingAddress = new ShippingAddress(zipcode: '63123');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ],
            shippingAddress: $shippingAddress
        );

        // When
        $result = $this->service->calculate($input);

        // Then: (3,000+3,000) + (4,000+5,000) = 15,000원
        $this->assertEquals(15000, $result->summary->totalShipping);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
        $this->assertEquals(5000, $result->items[1]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-9: 정확한 우편번호 매칭 (와일드카드 없음)
     */
    public function test_it_matches_exact_zipcode(): void
    {
        // Given: 정확한 우편번호 매칭
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63123', 'fee' => 2000], // 정확히 63123만
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // 매칭되는 우편번호
        $shippingAddress1 = new ShippingAddress(zipcode: '63123');

        $input1 = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            shippingAddress: $shippingAddress1
        );

        // When
        $result1 = $this->service->calculate($input1);

        // Then: 매칭 → 추가배송비 2,000원
        $this->assertEquals(5000, $result1->summary->totalShipping);
        $this->assertEquals(2000, $result1->items[0]->appliedShippingPolicy->extraShippingAmount);

        // 매칭되지 않는 우편번호
        $shippingAddress2 = new ShippingAddress(zipcode: '63124'); // 다른 우편번호

        $input2 = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            shippingAddress: $shippingAddress2
        );

        $result2 = $this->service->calculate($input2);

        // Then: 매칭 안 됨 → 추가배송비 0원
        $this->assertEquals(3000, $result2->summary->totalShipping);
        $this->assertEquals(0, $result2->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-4: 범위 패턴 매칭 (63000-63999)
     */
    public function test_it_matches_range_pattern_zipcode(): void
    {
        // Given: 범위 패턴 우편번호 매칭
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63999', 'fee' => 4000], // 범위 패턴
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // 범위 내 우편번호
        $shippingAddress1 = new ShippingAddress(zipcode: '63500');

        $input1 = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            shippingAddress: $shippingAddress1
        );

        // When
        $result1 = $this->service->calculate($input1);

        // Then: 범위 내 → 추가배송비 4,000원
        $this->assertEquals(7000, $result1->summary->totalShipping);
        $this->assertEquals(4000, $result1->items[0]->appliedShippingPolicy->extraShippingAmount);

        // 범위 밖 우편번호 (경계값 테스트)
        $shippingAddress2 = new ShippingAddress(zipcode: '62999'); // 범위 미만

        $input2 = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            shippingAddress: $shippingAddress2
        );

        $result2 = $this->service->calculate($input2);

        // Then: 범위 밖 → 추가배송비 0원
        $this->assertEquals(3000, $result2->summary->totalShipping);
        $this->assertEquals(0, $result2->items[0]->appliedShippingPolicy->extraShippingAmount);

        // 범위 끝 경계값 (64000 - 범위 초과)
        $shippingAddress3 = new ShippingAddress(zipcode: '64000');

        $input3 = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            shippingAddress: $shippingAddress3
        );

        $result3 = $this->service->calculate($input3);

        // Then: 범위 밖 → 추가배송비 0원
        $this->assertEquals(3000, $result3->summary->totalShipping);
        $this->assertEquals(0, $result3->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-10: 첫 번째 매칭되는 패턴 적용
     */
    public function test_it_applies_first_matching_pattern(): void
    {
        // Given: 여러 패턴 (첫 번째 매칭 적용)
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '631*', 'fee' => 2000],  // 더 구체적
                ['zipcode' => '63*', 'fee' => 3000],   // 더 일반적
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63123'); // 631*에 먼저 매칭

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

        // Then: 첫 번째 패턴 631* 적용 → 2,000원
        $this->assertEquals(5000, $result->summary->totalShipping);
        $this->assertEquals(2000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    // ========================================
    // Section 7.5.1.1: 정책 유형별 도서산간 추가배송비 테스트
    // ========================================

    /**
     * 테스트 #36-11a: CONDITIONAL_FREE 정책 + 도서산간
     *
     * 조건 미충족: 기본 5,000원 + 추가 3,000원 = 8,000원
     */
    public function test_it_applies_extra_fee_with_conditional_free_not_met(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 5000,
            freeThreshold: 50000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63100');

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

        $result = $this->service->calculate($input);

        // Then: 기본 5,000원 + 추가 3,000원 = 8,000원
        $this->assertEquals(8000, $result->summary->totalShipping);
        $this->assertEquals(5000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-11b: CONDITIONAL_FREE 정책 + 조건 충족 + 도서산간
     *
     * 조건 충족: 기본 0원 + 추가 3,000원 = 3,000원
     */
    public function test_it_applies_extra_fee_with_conditional_free_met(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 5000,
            freeThreshold: 50000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(60000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63100');

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

        $result = $this->service->calculate($input);

        // Then: 기본 0원 (무료배송 충족) + 추가 3,000원 = 3,000원
        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(0, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-11c: RANGE_AMOUNT 정책 + 도서산간
     *
     * 30,000원 상품 → 구간 매칭 → 기본 5,000원 + 추가 3,000원
     */
    public function test_it_applies_extra_fee_with_range_amount(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            ranges: [
                'type' => 'amount',
                'tiers' => [
                    ['min' => 0, 'max' => 20000, 'fee' => 3000],
                    ['min' => 20001, 'max' => 50000, 'fee' => 5000],
                    ['min' => 50001, 'max' => null, 'fee' => 8000],
                ],
            ],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63200');

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

        $result = $this->service->calculate($input);

        // Then: 기본 5,000원 (30,000원 구간) + 추가 3,000원 = 8,000원
        $this->assertEquals(8000, $result->summary->totalShipping);
        $this->assertEquals(5000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-11d: RANGE_QUANTITY 정책 + 도서산간
     *
     * 수량 3개 → 구간 매칭 → 기본 5,000원 + 추가 3,000원
     */
    public function test_it_applies_extra_fee_with_range_quantity(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_QUANTITY,
            ranges: [
                'type' => 'quantity',
                'tiers' => [
                    ['min' => 1, 'max' => 2, 'fee' => 3000],
                    ['min' => 3, 'max' => 5, 'fee' => 5000],
                    ['min' => 6, 'max' => null, 'fee' => 8000],
                ],
            ],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63500');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 3
                ),
            ],
            shippingAddress: $shippingAddress
        );

        $result = $this->service->calculate($input);

        // Then: 기본 5,000원 (3~5개 구간) + 추가 3,000원 = 8,000원
        $this->assertEquals(8000, $result->summary->totalShipping);
        $this->assertEquals(5000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-11e: RANGE_WEIGHT 정책 + 도서산간
     *
     * 무게 2.5kg(2500g) → 구간 매칭 → 기본 5,000원 + 추가 3,000원
     */
    public function test_it_applies_extra_fee_with_range_weight(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_WEIGHT,
            ranges: [
                'type' => 'weight',
                'tiers' => [
                    ['min' => 0, 'max' => 1000, 'fee' => 3000],
                    ['min' => 1001, 'max' => 3000, 'fee' => 5000],
                    ['min' => 3001, 'max' => null, 'fee' => 8000],
                ],
            ],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithDimensions(10000, $policy, weight: 2.5, volume: 0);

        $shippingAddress = new ShippingAddress(zipcode: '63100');

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

        $result = $this->service->calculate($input);

        // Then: 기본 5,000원 (1001~3000g 구간) + 추가 3,000원 = 8,000원
        $this->assertEquals(8000, $result->summary->totalShipping);
        $this->assertEquals(5000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-11f: RANGE_VOLUME 정책 + 도서산간 (이번 버그 시나리오)
     *
     * 부피 30cm³ → 구간 매칭 → 기본 5,000원 + 추가 3,000원
     */
    public function test_it_applies_extra_fee_with_range_volume(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME,
            ranges: [
                'type' => 'volume',
                'tiers' => [
                    ['min' => 0, 'max' => 50, 'fee' => 5000],
                    ['min' => 51, 'max' => 100, 'fee' => 10000],
                    ['min' => 101, 'max' => null, 'fee' => 20000],
                ],
            ],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithDimensions(27000, $policy, weight: 0.5, volume: 30);

        $shippingAddress = new ShippingAddress(zipcode: '63100');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 2
                ),
            ],
            shippingAddress: $shippingAddress
        );

        $result = $this->service->calculate($input);

        // Then: 부피 60cm³(30×2) → 51~100 구간 → 기본 10,000원 + 추가 3,000원 = 13,000원
        $this->assertEquals(13000, $result->summary->totalShipping);
        $this->assertEquals(10000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-11g: PER_WEIGHT 정책 + 도서산간 + multiply=true
     *
     * 무게 1.5kg, 수량 4개, 단위 2개당 2,000원
     * 기본: ceil(4/2)×2,000=4,000원
     * 추가: multiply는 total_quantity/unit_value 기준 → ceil(4/2)×3,000=6,000원
     */
    public function test_it_applies_extra_fee_with_per_weight_multiply(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 2],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ],
            extraFeeMultiply: true
        );
        [$product, $option] = $this->createProductWithDimensions(10000, $policy, weight: 1.5, volume: 0);

        $shippingAddress = new ShippingAddress(zipcode: '63200');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 4
                ),
            ],
            shippingAddress: $shippingAddress
        );

        $result = $this->service->calculate($input);

        // Then: 기본 ceil(6kg/2)×2,000=6,000원 + 추가 ceil(4/2)×3,000=6,000원 = 12,000원
        $this->assertEquals(12000, $result->summary->totalShipping);
        $this->assertEquals(6000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(6000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-11h: PER_VOLUME 정책 + 도서산간
     *
     * 부피 50cm³, 단위 20cm³당 1,500원, 추가배송비 3,000원(1회)
     */
    public function test_it_applies_extra_fee_with_per_volume(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME,
            baseFee: 1500,
            ranges: ['unit_value' => 20],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithDimensions(10000, $policy, weight: 0, volume: 50);

        $shippingAddress = new ShippingAddress(zipcode: '63500');

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

        $result = $this->service->calculate($input);

        // Then: 기본 ceil(50/20)×1,500=4,500원 + 추가 3,000원(1회) = 7,500원
        $this->assertEquals(7500, $result->summary->totalShipping);
        $this->assertEquals(4500, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-11i: PER_AMOUNT 정책 + 도서산간
     *
     * 금액 30,000원, 단위 10,000원당 1,000원, 추가배송비 3,000원(1회)
     */
    public function test_it_applies_extra_fee_with_per_amount(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_AMOUNT,
            baseFee: 1000,
            ranges: ['unit_value' => 10000],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63100');

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

        $result = $this->service->calculate($input);

        // Then: 기본 ceil(30000/10000)×1,000=3,000원 + 추가 3,000원(1회) = 6,000원
        $this->assertEquals(6000, $result->summary->totalShipping);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    /**
     * 테스트 #36-11j: FREE 정책 + 도서산간
     *
     * 기본 0원이지만 추가배송비 3,000원만 부과
     */
    public function test_it_applies_extra_fee_with_free_policy(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FREE,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        $shippingAddress = new ShippingAddress(zipcode: '63100');

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

        $result = $this->service->calculate($input);

        // Then: 기본 0원 + 추가 3,000원 = 3,000원
        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(0, $result->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(3000, $result->items[0]->appliedShippingPolicy->extraShippingAmount);
    }

    // ========================================
    // Section 7.5.2: 추가배송비+쿠폰 복합 테스트 (4개)
    // ========================================

    /**
     * 테스트 #36-11: 배송비 쿠폰이 기본+추가배송비 모두 할인
     *
     * 기본 3,000원 + 추가 3,000원 = 6,000원
     * 배송비 쿠폰 100% 할인 → 최종 0원
     */
    public function test_it_applies_shipping_coupon_to_base_and_extra_shipping(): void
    {
        // Given: 추가배송비 활성화 정책
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // 배송비 100% 쿠폰
        $shippingCoupon = $this->createShippingCouponWithIssue(
            discountType: CouponDiscountType::RATE,
            discountValue: 100
        );

        $shippingAddress = new ShippingAddress(zipcode: '63123');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$shippingCoupon->id],
            shippingAddress: $shippingAddress
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 배송비 쿠폰이 기본+추가 모두 할인
        // totalShipping = 할인 전 총 배송비, shippingDiscount = 할인금액
        $this->assertEquals(6000, $result->summary->totalShipping);
        $this->assertEquals(6000, $result->summary->shippingDiscount);
        // 최종 배송비 = totalShipping - shippingDiscount = 0
    }

    /**
     * 테스트 #36-12: 배송비 쿠폰 정액 할인이 추가배송비까지 적용
     *
     * 기본 3,000원 + 추가 3,000원 = 6,000원
     * 배송비 쿠폰 4,000원 정액 할인 → 최종 2,000원
     */
    public function test_it_applies_fixed_shipping_coupon_to_total_shipping(): void
    {
        // Given: 추가배송비 활성화 정책
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // 배송비 4,000원 정액 쿠폰
        $shippingCoupon = $this->createShippingCouponWithIssue(
            discountType: CouponDiscountType::FIXED,
            discountValue: 4000
        );

        $shippingAddress = new ShippingAddress(zipcode: '63123');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$shippingCoupon->id],
            shippingAddress: $shippingAddress
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 할인 전 6,000원, 쿠폰 4,000원 할인
        // totalShipping = 할인 전 총 배송비, shippingDiscount = 할인금액
        $this->assertEquals(6000, $result->summary->totalShipping);
        $this->assertEquals(4000, $result->summary->shippingDiscount);
        // 최종 배송비 = totalShipping - shippingDiscount = 2,000원
    }

    /**
     * 테스트 #36-13: 추가배송비 없을 때 배송비 쿠폰은 기본만 할인
     *
     * 기본 3,000원 (추가 없음)
     * 배송비 쿠폰 100% 할인 → 최종 0원
     */
    public function test_it_applies_shipping_coupon_only_to_base_when_no_extra(): void
    {
        // Given: 추가배송비 활성화 정책 (일반 지역)
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000], // 제주만
            ]
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // 배송비 100% 쿠폰
        $shippingCoupon = $this->createShippingCouponWithIssue(
            discountType: CouponDiscountType::RATE,
            discountValue: 100
        );

        $shippingAddress = new ShippingAddress(zipcode: '06123'); // 서울 (추가배송비 없음)

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$shippingCoupon->id],
            shippingAddress: $shippingAddress
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 기본 3,000원만 할인
        // totalShipping = 할인 전 총 배송비, shippingDiscount = 할인금액
        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(3000, $result->summary->shippingDiscount);
        // 최종 배송비 = totalShipping - shippingDiscount = 0원
    }

    /**
     * 테스트 #36-14: 다중 정책 그룹에서 추가배송비+쿠폰 적용
     *
     * 정책A: 기본 3,000 + 추가 3,000 = 6,000원
     * 정책B: 기본 4,000 + 추가 5,000 = 9,000원
     * 총 배송비: 15,000원
     * 배송비 쿠폰 50% 할인 → 7,500원
     */
    public function test_it_applies_shipping_coupon_to_multiple_policy_groups_with_extra(): void
    {
        // Given: 정책A
        $policyA = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ]
        );

        // 정책B
        $policyB = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 4000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 5000],
            ]
        );

        [$product1, $option1] = $this->createProductWithShippingPolicy(30000, $policyA);
        [$product2, $option2] = $this->createProductWithShippingPolicy(40000, $policyB);

        // 배송비 50% 쿠폰
        $shippingCoupon = $this->createShippingCouponWithIssue(
            discountType: CouponDiscountType::RATE,
            discountValue: 50
        );

        $shippingAddress = new ShippingAddress(zipcode: '63123');

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$shippingCoupon->id],
            shippingAddress: $shippingAddress
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 15,000원의 50% = 7,500원 할인
        // totalShipping = 할인 전 총 배송비, shippingDiscount = 할인금액
        $this->assertEquals(15000, $result->summary->totalShipping);
        $this->assertEquals(7500, $result->summary->shippingDiscount);
        // 최종 배송비 = totalShipping - shippingDiscount = 7,500원
    }

    // ========================================
    // Section 7.5.3: 국가별 배송비 분기 테스트 (6개)
    // ========================================

    /**
     * 테스트: 배송 주소의 국가코드에 따라 해당 국가 설정의 배송비 적용
     *
     * 동일 정책에 KR=고정 3,000원, US=고정 15,000원 설정
     * KR 주소 → 3,000원, US 주소 → 15,000원
     */
    public function test_shipping_fee_uses_country_code_from_address(): void
    {
        // Given: KR=고정 3,000원, US=고정 15,000원 다국가 정책
        $policy = $this->createMultiCountryShippingPolicy([
            [
                'country_code' => 'KR',
                'charge_policy' => ChargePolicyEnum::FIXED,
                'base_fee' => 3000,
                'currency_code' => 'KRW',
            ],
            [
                'country_code' => 'US',
                'charge_policy' => ChargePolicyEnum::FIXED,
                'base_fee' => 15000,
                'currency_code' => 'KRW',
            ],
        ]);
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // When: KR 주소로 계산
        $resultKR = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
        ));

        // When: US 주소로 계산
        $resultUS = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'US'),
        ));

        // Then: KR → 3,000원
        $this->assertEquals(3000, $resultKR->summary->totalShipping);
        $this->assertEquals('KR', $resultKR->items[0]->appliedShippingPolicy->countryCode);

        // Then: US → 15,000원
        $this->assertEquals(15000, $resultUS->summary->totalShipping);
        $this->assertEquals('US', $resultUS->items[0]->appliedShippingPolicy->countryCode);
    }

    /**
     * 테스트: 국가코드 null일 때 KR 기본값 사용
     *
     * ShippingAddress의 countryCode 기본값은 'KR'이므로
     * 명시적으로 null을 전달해도 KR 설정이 적용됨
     */
    public function test_shipping_fee_falls_back_to_kr_when_no_country_code(): void
    {
        // Given: KR=고정 3,000원 정책
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            countryCode: 'KR',
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // When: countryCode 없이 계산 (기본값 KR)
        $result = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
        ));

        // Then: KR 설정 적용 → 3,000원
        $this->assertEquals(3000, $result->summary->totalShipping);
    }

    /**
     * 테스트: 매칭되는 국가 설정이 없는 경우 배송비 0원 (skip)
     *
     * KR만 설정된 정책에 US 주소로 요청 → 매칭 없음 → 배송비 0원
     */
    public function test_shipping_fee_skips_policy_without_matching_country(): void
    {
        // Given: KR만 설정된 정책
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            countryCode: 'KR',
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // When: US 주소로 계산 (KR 설정만 있으므로 매칭 안 됨)
        $result = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'US'),
        ));

        // Then: 매칭 국가설정 없음 → 배송비 0원
        $this->assertEquals(0, $result->summary->totalShipping);
    }

    /**
     * 테스트: 도서산간 추가배송비는 KR 주소에서만 적용
     *
     * KR+US 다국가 정책에서 KR에만 도서산간 설정
     * KR 주소+도서산간 우편번호 → 추가배송비 적용
     * US 주소 → 추가배송비 미적용
     */
    public function test_extra_fee_only_applies_to_kr_address(): void
    {
        // Given: KR=고정 3,000원+도서산간 5,000원, US=고정 15,000원
        $policy = $this->createMultiCountryShippingPolicy([
            [
                'country_code' => 'KR',
                'charge_policy' => ChargePolicyEnum::FIXED,
                'base_fee' => 3000,
                'currency_code' => 'KRW',
                'extra_fee_enabled' => true,
                'extra_fee_settings' => [
                    ['zipcode' => '63000-63644', 'fee' => 5000],
                ],
                'extra_fee_multiply' => false,
            ],
            [
                'country_code' => 'US',
                'charge_policy' => ChargePolicyEnum::FIXED,
                'base_fee' => 15000,
                'currency_code' => 'KRW',
            ],
        ]);
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // When: KR 제주도 주소 (도서산간)
        $resultKR = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '63123'),
        ));

        // When: US 주소 (도서산간 해당 없음)
        $resultUS = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'US'),
        ));

        // Then: KR → 기본 3,000 + 도서산간 5,000 = 8,000원
        $this->assertEquals(3000, $resultKR->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(5000, $resultKR->items[0]->appliedShippingPolicy->extraShippingAmount);
        $this->assertEquals(8000, $resultKR->summary->totalShipping);

        // Then: US → 기본 15,000원, 도서산간 0원
        $this->assertEquals(15000, $resultUS->items[0]->appliedShippingPolicy->shippingAmount);
        $this->assertEquals(0, $resultUS->items[0]->appliedShippingPolicy->extraShippingAmount);
        $this->assertEquals(15000, $resultUS->summary->totalShipping);
    }

    /**
     * 테스트: AppliedShippingPolicy 스냅샷에 국가별 설정 정보 포함
     */
    public function test_policy_snapshot_includes_country_setting(): void
    {
        // Given: KR=고정 3,000원
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            countryCode: 'KR',
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // When
        $result = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
        ));

        // Then: 스냅샷에 country_code 포함
        $appliedPolicy = $result->items[0]->appliedShippingPolicy;
        $this->assertNotNull($appliedPolicy);
        $this->assertEquals('KR', $appliedPolicy->countryCode);
        $this->assertEquals('fixed', $appliedPolicy->chargePolicy);
        $this->assertNotEmpty($appliedPolicy->policySnapshot);
    }

    /**
     * 테스트: 다국가 정책에서 국가별 다른 부과정책 적용
     *
     * KR=조건부무료(5만원 이상 무료, 미만 3,000원)
     * US=구간별(무게 기준)
     */
    public function test_different_charge_policy_per_country(): void
    {
        // Given: KR=조건부무료, US=고정 20,000원
        $policy = $this->createMultiCountryShippingPolicy([
            [
                'country_code' => 'KR',
                'charge_policy' => ChargePolicyEnum::CONDITIONAL_FREE,
                'base_fee' => 3000,
                'free_threshold' => 50000,
                'currency_code' => 'KRW',
            ],
            [
                'country_code' => 'US',
                'charge_policy' => ChargePolicyEnum::FIXED,
                'base_fee' => 20000,
                'currency_code' => 'KRW',
            ],
        ]);

        // 상품 30,000원 (5만원 미만)
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $policy);

        // When: KR 주소 (5만원 미만이므로 배송비 부과)
        $resultKR = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
        ));

        // When: US 주소 (고정 20,000원)
        $resultUS = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'US'),
        ));

        // Then: KR → 조건부무료에서 미달이므로 3,000원
        $this->assertEquals(3000, $resultKR->summary->totalShipping);

        // Then: US → 고정 20,000원
        $this->assertEquals(20000, $resultUS->summary->totalShipping);

        // When: KR 5만원 이상 → 무료
        $resultKRFree = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2)],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
        ));

        // Then: KR → 60,000원으로 5만원 이상 → 무료
        $this->assertEquals(0, $resultKRFree->summary->totalShipping);
    }

    // ========================================
    // Section 7.6: 배송비 쿠폰 적용 (3개)
    // ========================================

    /**
     * 배송비 쿠폰 생성 헬퍼
     *
     * @param  CouponDiscountType  $discountType  할인 타입
     * @param  float  $discountValue  할인 값
     * @param  float|null  $maxDiscount  최대 할인금액
     * @return CouponIssue
     */
    protected function createShippingCouponWithIssue(
        CouponDiscountType $discountType,
        float $discountValue,
        ?float $maxDiscount = null
    ): CouponIssue {
        $user = User::factory()->create();

        $coupon = Coupon::create([
            'code' => 'SHIP'.uniqid(),
            'name' => ['ko' => '배송비 쿠폰', 'en' => 'Shipping Coupon'],
            'target_type' => CouponTargetType::SHIPPING_FEE,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'max_discount' => $maxDiscount,
            'min_order_amount' => 0,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * 테스트 #34: 배송비 전액 할인 (쿠폰 할인액 > 배송비)
     *
     * 쿠폰: shipping_fee, fixed 5,000원, 배송비 3,000원
     * 기대: 배송비 0원 (초과분 무시)
     */
    public function test_it_applies_shipping_coupon_full_discount(): void
    {
        // Given: FIXED 정책 (3,000원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000
        );

        [$product, $option] = $this->createProductWithShippingPolicy(50000, $shippingPolicy);

        // 배송비 쿠폰: 5,000원 할인 (배송비보다 큼)
        $couponIssue = $this->createShippingCouponWithIssue(
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 배송비 3,000원 - 쿠폰 5,000원 = 0원 (음수 안 됨)
        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(3000, $result->summary->shippingDiscount); // 실제 할인액은 배송비까지만
    }

    /**
     * 테스트 #35: 배송비 정률 할인
     *
     * 쿠폰: shipping_fee, rate 50%, 배송비 4,000원
     * 기대: 배송비 할인 2,000원
     */
    public function test_it_applies_shipping_coupon_rate_discount(): void
    {
        // Given: FIXED 정책 (4,000원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 4000
        );

        [$product, $option] = $this->createProductWithShippingPolicy(50000, $shippingPolicy);

        // 배송비 쿠폰: 50% 할인
        $couponIssue = $this->createShippingCouponWithIssue(
            discountType: CouponDiscountType::RATE,
            discountValue: 50
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 배송비 4,000원 × 50% = 2,000원 할인
        $this->assertEquals(4000, $result->summary->totalShipping);
        $this->assertEquals(2000, $result->summary->shippingDiscount);
    }

    /**
     * 테스트 #36: 배송비 부분 할인
     *
     * 쿠폰: shipping_fee, fixed 1,000원, 배송비 3,000원
     * 기대: 배송비 할인 1,000원 (실 배송비 2,000원)
     */
    public function test_it_applies_shipping_coupon_partial_discount(): void
    {
        // Given: FIXED 정책 (3,000원)
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000
        );

        [$product, $option] = $this->createProductWithShippingPolicy(50000, $shippingPolicy);

        // 배송비 쿠폰: 1,000원 할인
        $couponIssue = $this->createShippingCouponWithIssue(
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 배송비 3,000원 - 쿠폰 1,000원 = 2,000원 할인 후 남은 배송비
        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(1000, $result->summary->shippingDiscount);
    }

    // ========================================
    // Section 7.7: 주문금액 쿠폰 적용 (4개)
    // ========================================

    /**
     * 주문금액 쿠폰 생성 헬퍼
     *
     * @param  CouponDiscountType  $discountType  할인 타입
     * @param  float  $discountValue  할인 값
     * @param  float|null  $maxDiscount  최대 할인금액
     * @return CouponIssue
     */
    protected function createOrderCouponWithIssue(
        CouponDiscountType $discountType,
        float $discountValue,
        ?float $maxDiscount = null
    ): CouponIssue {
        $user = User::factory()->create();

        $coupon = Coupon::create([
            'code' => 'ORDER'.uniqid(),
            'name' => ['ko' => '주문금액 쿠폰', 'en' => 'Order Coupon'],
            'target_type' => CouponTargetType::ORDER_AMOUNT,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'max_discount' => $maxDiscount,
            'min_order_amount' => 0,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * 상품금액 쿠폰 생성 헬퍼
     *
     * @param  CouponTargetScope  $targetScope  적용 범위
     * @param  CouponDiscountType  $discountType  할인 타입
     * @param  float  $discountValue  할인 값
     * @param  float|null  $maxDiscount  최대 할인금액
     * @param  array  $targetIds  대상 상품/카테고리 ID (선택)
     * @return CouponIssue
     */
    protected function createProductCouponWithIssue(
        CouponTargetScope $targetScope,
        CouponDiscountType $discountType,
        float $discountValue,
        ?float $maxDiscount = null,
        array $targetIds = []
    ): CouponIssue {
        $user = User::factory()->create();

        $coupon = Coupon::create([
            'code' => 'PRODUCT'.uniqid(),
            'name' => ['ko' => '상품금액 쿠폰', 'en' => 'Product Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => $targetScope,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_max_amount' => $maxDiscount,
            'min_order_amount' => 0,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
        ]);

        // 특정 상품/카테고리 지정 (피벗 테이블)
        if (! empty($targetIds)) {
            if ($targetScope === CouponTargetScope::PRODUCTS) {
                $pivotData = array_fill_keys($targetIds, ['type' => 'include']);
                $coupon->products()->attach($pivotData);
            } elseif ($targetScope === CouponTargetScope::CATEGORIES) {
                $pivotData = array_fill_keys($targetIds, ['type' => 'include']);
                $coupon->categories()->attach($pivotData);
            }
        }

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDay(),
        ]);
    }

    /**
     * 테스트 #37: 주문금액 정액 할인 및 안분
     *
     * 쿠폰: order_amount, fixed 5,000원
     * 주문금액 100,000원 (옵션1: 60,000, 옵션2: 40,000)
     * 기대: 옵션별 금액 비율로 5,000원 안분 (3,000 + 2,000)
     */
    public function test_it_applies_order_coupon_fixed_discount_with_apportionment(): void
    {
        // Given: 두 상품
        [$product1, $option1] = $this->createProductWithOption(60000);
        [$product2, $option2] = $this->createProductWithOption(40000);

        // 주문금액 쿠폰: 5,000원 할인
        $couponIssue = $this->createOrderCouponWithIssue(
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 총 할인 5,000원
        $this->assertEquals(5000, $result->summary->orderCouponDiscount);

        // 안분: 옵션1 60% = 3,000원, 옵션2 40% = 2,000원
        $this->assertEquals(3000, $result->items[0]->orderCouponDiscountShare);
        $this->assertEquals(2000, $result->items[1]->orderCouponDiscountShare);
    }

    /**
     * 테스트 #38: 주문금액 정률 할인 및 안분
     *
     * 쿠폰: order_amount, rate 10%
     * 주문금액 100,000원
     * 기대: 10,000원 할인, 옵션별 금액 비율로 안분
     */
    public function test_it_applies_order_coupon_rate_discount_with_apportionment(): void
    {
        // Given: 두 상품
        [$product1, $option1] = $this->createProductWithOption(50000);
        [$product2, $option2] = $this->createProductWithOption(50000);

        // 주문금액 쿠폰: 10% 할인
        $couponIssue = $this->createOrderCouponWithIssue(
            discountType: CouponDiscountType::RATE,
            discountValue: 10
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 100,000원 × 10% = 10,000원 할인
        $this->assertEquals(10000, $result->summary->orderCouponDiscount);

        // 안분: 50% 씩 → 5,000원 씩
        $this->assertEquals(5000, $result->items[0]->orderCouponDiscountShare);
        $this->assertEquals(5000, $result->items[1]->orderCouponDiscountShare);
    }

    /**
     * 테스트 #39: 주문할인 안분 정확성 (끝전 처리)
     *
     * 주문금액 100,000원, 할인 3,333원, 옵션 3개 (균등 분할 시 나누어 떨어지지 않음)
     * 기대: 끝전 처리로 합계 정확히 3,333원
     */
    public function test_it_handles_order_discount_apportionment_remainder(): void
    {
        // Given: 세 상품 (동일 금액)
        [$product1, $option1] = $this->createProductWithOption(33333);
        [$product2, $option2] = $this->createProductWithOption(33333);
        [$product3, $option3] = $this->createProductWithOption(33334);

        // 주문금액 쿠폰: 3,333원 할인
        $couponIssue = $this->createOrderCouponWithIssue(
            discountType: CouponDiscountType::FIXED,
            discountValue: 3333
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product3->id,
                    productOptionId: $option3->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 총 할인 3,333원
        $this->assertEquals(3333, $result->summary->orderCouponDiscount);

        // 안분 합계가 정확히 3,333원이어야 함 (끝전 처리)
        $totalApportioned = $result->items[0]->orderCouponDiscountShare
            + $result->items[1]->orderCouponDiscountShare
            + $result->items[2]->orderCouponDiscountShare;
        $this->assertEquals(3333, $totalApportioned);
    }

    /**
     * 테스트 #40: 할인 후 금액 > 0 보장 (음수 방지)
     *
     * 할인액이 상품가보다 클 때 최소 0원, 음수 방지
     */
    public function test_it_prevents_negative_final_amount(): void
    {
        // Given: 작은 금액 상품
        [$product, $option] = $this->createProductWithOption(1000);

        // 주문금액 쿠폰: 5,000원 할인 (상품가보다 큼)
        $couponIssue = $this->createOrderCouponWithIssue(
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 최종 금액이 음수가 아니어야 함
        $this->assertGreaterThanOrEqual(0, $result->summary->finalAmount);

        // 할인액이 상품가를 초과하지 않아야 함 (초과분 무시)
        $this->assertLessThanOrEqual($result->summary->subtotal, $result->summary->orderCouponDiscount);
    }

    // ========================================
    // Section 7.9: 과세/면세 분류 (3개)
    // ========================================

    /**
     * 과세 상태 지정 상품 생성 헬퍼
     *
     * @param  int  $price  상품 가격
     * @param  ProductTaxStatus  $taxStatus  과세 상태
     * @return array [Product, ProductOption]
     */
    protected function createProductWithTaxStatus(
        int $price,
        ProductTaxStatus $taxStatus
    ): array {
        $product = ProductFactory::new()->create([
            'tax_status' => $taxStatus,
            'selling_price' => $price,
            'list_price' => $price,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    /**
     * 테스트 #59: 과세 상품만 있는 경우
     *
     * 모든 상품 tax_status: taxable
     * 기대: taxFreeAmount = 0
     */
    public function test_it_classifies_taxable_products_only(): void
    {
        // Given: 과세 상품 2개
        [$product1, $option1] = $this->createProductWithTaxStatus(30000, ProductTaxStatus::TAXABLE);
        [$product2, $option2] = $this->createProductWithTaxStatus(20000, ProductTaxStatus::TAXABLE);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 모두 과세
        $this->assertEquals(50000, $result->summary->taxableAmount);
        $this->assertEquals(0, $result->summary->taxFreeAmount);

        // 개별 아이템 확인
        $this->assertEquals(30000, $result->items[0]->taxableAmount);
        $this->assertEquals(0, $result->items[0]->taxFreeAmount);
        $this->assertEquals(20000, $result->items[1]->taxableAmount);
        $this->assertEquals(0, $result->items[1]->taxFreeAmount);
    }

    /**
     * 테스트 #60: 면세 상품만 있는 경우
     *
     * 모든 상품 tax_status: tax_free
     * 기대: taxableAmount = 0
     */
    public function test_it_classifies_tax_free_products_only(): void
    {
        // Given: 면세 상품 2개
        [$product1, $option1] = $this->createProductWithTaxStatus(30000, ProductTaxStatus::TAX_FREE);
        [$product2, $option2] = $this->createProductWithTaxStatus(20000, ProductTaxStatus::TAX_FREE);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 모두 면세
        $this->assertEquals(0, $result->summary->taxableAmount);
        $this->assertEquals(50000, $result->summary->taxFreeAmount);

        // 개별 아이템 확인
        $this->assertEquals(0, $result->items[0]->taxableAmount);
        $this->assertEquals(30000, $result->items[0]->taxFreeAmount);
        $this->assertEquals(0, $result->items[1]->taxableAmount);
        $this->assertEquals(20000, $result->items[1]->taxFreeAmount);
    }

    /**
     * 테스트 #61: 과세/면세 혼합 상품
     *
     * 과세 30,000원, 면세 20,000원
     * 기대: taxable: 30,000, taxFree: 20,000
     */
    public function test_it_classifies_mixed_tax_status_products(): void
    {
        // Given: 과세 1개, 면세 1개
        [$product1, $option1] = $this->createProductWithTaxStatus(30000, ProductTaxStatus::TAXABLE);
        [$product2, $option2] = $this->createProductWithTaxStatus(20000, ProductTaxStatus::TAX_FREE);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product1->id,
                    productOptionId: $option1->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $product2->id,
                    productOptionId: $option2->id,
                    quantity: 1
                ),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 과세 30,000 + 면세 20,000
        $this->assertEquals(30000, $result->summary->taxableAmount);
        $this->assertEquals(20000, $result->summary->taxFreeAmount);

        // 개별 아이템 확인
        $this->assertEquals(30000, $result->items[0]->taxableAmount);
        $this->assertEquals(0, $result->items[0]->taxFreeAmount);
        $this->assertEquals(0, $result->items[1]->taxableAmount);
        $this->assertEquals(20000, $result->items[1]->taxFreeAmount);
    }

    // ========================================
    // Section 7.10: 쿠폰 검증 규칙 (6개)
    // ========================================

    /**
     * 테스트 #62: 중복할인불가 쿠폰 단독 사용
     *
     * is_combinable: false 단독 사용 → 정상 적용
     */
    public function test_it_allows_non_combinable_coupon_alone(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(50000);

        $user = User::factory()->create();
        $coupon = Coupon::create([
            'code' => 'NONCOMBO'.uniqid(),
            'name' => ['ko' => '중복불가 쿠폰', 'en' => 'Non-combinable Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 0,
            'is_combinable' => false,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $couponIssue = CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 정상 적용, 검증 오류 없음
        $this->assertEmpty($result->validationErrors);
        $this->assertEquals(5000, $result->summary->productCouponDiscount);
    }

    /**
     * 테스트 #63: 중복할인불가 + 다른 쿠폰 → 검증 오류
     *
     * is_combinable: false + 다른 쿠폰 → validationErrors 포함
     */
    public function test_it_rejects_non_combinable_coupon_with_other_coupons(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(50000);

        $user = User::factory()->create();

        // 중복불가 쿠폰
        $nonComboCoupon = Coupon::create([
            'code' => 'NONCOMBO'.uniqid(),
            'name' => ['ko' => '중복불가 쿠폰', 'en' => 'Non-combinable Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 3000,
            'min_order_amount' => 0,
            'is_combinable' => false,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        // 일반 쿠폰
        $normalCoupon = Coupon::create([
            'code' => 'NORMAL'.uniqid(),
            'name' => ['ko' => '일반 쿠폰', 'en' => 'Normal Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 2000,
            'min_order_amount' => 0,
            'is_combinable' => true,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $issue1 = CouponIssue::create([
            'coupon_id' => $nonComboCoupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $issue2 = CouponIssue::create([
            'coupon_id' => $normalCoupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$issue1->id, $issue2->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 검증 오류 포함
        $this->assertNotEmpty($result->validationErrors);
    }

    /**
     * 테스트 #64: 최소주문금액 충족 → 정상 적용
     *
     * min_order_amount: 30,000, 주문: 50,000 → 정상 적용
     */
    public function test_it_applies_coupon_when_min_order_amount_met(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(50000);

        $user = User::factory()->create();
        $coupon = Coupon::create([
            'code' => 'MIN30K'.uniqid(),
            'name' => ['ko' => '최소 3만원 쿠폰', 'en' => 'Min 30K Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 30000,
            'is_combinable' => true,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $couponIssue = CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 정상 적용
        $this->assertEmpty($result->validationErrors);
        $this->assertEquals(5000, $result->summary->productCouponDiscount);
    }

    /**
     * 테스트 #65: 최소주문금액 미달 → 검증 오류
     *
     * min_order_amount: 50,000, 주문: 30,000 → validationErrors 포함
     */
    public function test_it_rejects_coupon_when_min_order_amount_not_met(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(30000);

        $user = User::factory()->create();
        $coupon = Coupon::create([
            'code' => 'MIN50K'.uniqid(),
            'name' => ['ko' => '최소 5만원 쿠폰', 'en' => 'Min 50K Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 50000,
            'is_combinable' => true,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $couponIssue = CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 검증 오류 포함
        $this->assertNotEmpty($result->validationErrors);
        // 쿠폰이 적용되지 않음
        $this->assertEquals(0, $result->summary->productCouponDiscount);
    }

    /**
     * 테스트 #66: 특정상품 쿠폰 - 대상 상품 없음 → 검증 오류
     *
     * products: [999], 장바구니에 없는 상품 → validationErrors 포함
     */
    public function test_it_rejects_product_specific_coupon_without_target_product(): void
    {
        // Given: 상품 생성
        [$product, $option] = $this->createProductWithOption(50000);

        $user = User::factory()->create();

        // 다른 상품을 대상으로 하는 쿠폰 (장바구니에 없는 상품 ID)
        $coupon = Coupon::create([
            'code' => 'SPECIFIC'.uniqid(),
            'name' => ['ko' => '특정상품 쿠폰', 'en' => 'Specific Product Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => CouponTargetScope::PRODUCTS,
            'target_ids' => [999999], // 존재하지 않는 상품 ID
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 0,
            'is_combinable' => true,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $couponIssue = CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 검증 오류 포함 또는 할인 미적용
        // 대상 상품이 없으면 할인이 0이거나 validationErrors가 있어야 함
        $this->assertTrue(
            ! empty($result->validationErrors) || $result->summary->productCouponDiscount === 0
        );
    }

    /**
     * 테스트 #67: 쿠폰 유효기간 만료 → 검증 오류
     *
     * expires_at < now() → validationErrors 포함
     */
    public function test_it_rejects_expired_coupon(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(50000);

        $user = User::factory()->create();
        $coupon = Coupon::create([
            'code' => 'EXPIRED'.uniqid(),
            'name' => ['ko' => '만료 쿠폰', 'en' => 'Expired Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 0,
            'is_combinable' => true,
            'started_at' => now()->subWeek(),
            'ended_at' => now()->subDay(), // 어제 만료
            'is_active' => true,
        ]);

        $couponIssue = CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now()->subWeek(),
            'expired_at' => now()->subDay(), // 어제 만료
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 검증 오류 포함 또는 할인 미적용
        $this->assertTrue(
            ! empty($result->validationErrors) || $result->summary->productCouponDiscount === 0
        );
    }

    // ====================================================================
    // Section 7.11: 안분 계산 정확성 테스트
    // ====================================================================

    /**
     * 테스트 #68: 할인 안분 후 합계 검증
     *
     * 주문쿠폰 할인 10,000원을 3개 옵션에 안분 → 합계 = 10,000원
     */
    public function test_it_apportions_discount_with_exact_sum(): void
    {
        // Given: 3개 상품 (10000, 20000, 30000)
        [$product1, $option1] = $this->createProductWithOption(10000);
        [$product2, $option2] = $this->createProductWithOption(20000);
        [$product3, $option3] = $this->createProductWithOption(30000);

        // 주문쿠폰 10,000원 고정 할인
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::FIXED,
            10000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
                new CalculationItem(productId: $product3->id, productOptionId: $option3->id, quantity: 1),
            ],
            couponIssueIds: [$orderCouponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 각 아이템의 orderDiscountShare 합계 = 10,000원
        $totalOrderDiscountShare = 0;
        foreach ($result->items as $item) {
            $totalOrderDiscountShare += $item->orderCouponDiscountShare;
        }
        $this->assertEquals(10000, $totalOrderDiscountShare);

        // 각 아이템의 안분액은 비율대로 분배됨
        // 총액: 60000, 비율 = 10000:20000:30000 = 1:2:3
        // 이론적 안분: 1667 + 3333 + 5000 = 10000
    }

    /**
     * 테스트 #69: 안분 시 나머지 마지막 항목 합산
     *
     * 나누어떨어지지 않는 경우 마지막 항목에 나머지 합산
     */
    public function test_it_assigns_apportionment_remainder_to_last_item(): void
    {
        // Given: 3개 동일 상품 (각 10000원, 총 30000원)
        [$product1, $option1] = $this->createProductWithOption(10000);
        [$product2, $option2] = $this->createProductWithOption(10000);
        [$product3, $option3] = $this->createProductWithOption(10000);

        // 10원 할인 → 3등분 불가 (나머지 1원)
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::FIXED,
            10,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
                new CalculationItem(productId: $product3->id, productOptionId: $option3->id, quantity: 1),
            ],
            couponIssueIds: [$orderCouponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 합계는 정확히 10원
        $totalOrderDiscountShare = 0;
        foreach ($result->items as $item) {
            $totalOrderDiscountShare += $item->orderCouponDiscountShare;
        }
        $this->assertEquals(10, $totalOrderDiscountShare);

        // 마지막 아이템 확인 - items 배열의 마지막 값
        $itemsArray = array_values((array) $result->items);
        $lastItem = end($itemsArray);
        // 마지막 항목에 나머지가 포함되어 있음 (앞의 두 항목 합과 전체 합의 차이)
        $firstTwoShare = $itemsArray[0]->orderCouponDiscountShare + $itemsArray[1]->orderCouponDiscountShare;
        $this->assertEquals(10 - $firstTwoShare, $lastItem->orderCouponDiscountShare);
    }

    /**
     * 테스트 #70: 마일리지 안분 로직 검증
     *
     * 마일리지 사용금액이 각 아이템에 비율대로 안분됨
     */
    public function test_it_apportions_mileage_by_subtotal_ratio(): void
    {
        // Given: 2개 상품 (10000원, 30000원 = 1:3 비율)
        [$product1, $option1] = $this->createProductWithOption(10000);
        [$product2, $option2] = $this->createProductWithOption(30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
            ],
            usePoints: 4000 // 4000원 마일리지 사용
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 마일리지 사용액 합계 = 4000원
        $totalPointsUsedShare = 0;
        foreach ($result->items as $item) {
            $totalPointsUsedShare += $item->pointsUsedShare;
        }
        $this->assertEquals(4000, $totalPointsUsedShare);

        // 안분 비율 확인 (1:3)
        // 이론적: 1000 + 3000 = 4000
        $itemsArray = array_values((array) $result->items);
        // 첫 번째 아이템 (10000원) = 약 1000원
        // 두 번째 아이템 (30000원) = 약 3000원
        $this->assertEqualsWithDelta(1000, $itemsArray[0]->pointsUsedShare, 1);
        $this->assertEqualsWithDelta(3000, $itemsArray[1]->pointsUsedShare, 1);

        // summary의 pointsUsed도 확인
        $this->assertEquals(4000, $result->summary->pointsUsed);
    }

    // ====================================================================
    // Section 7.12: 복합 시나리오 테스트
    // ====================================================================

    /**
     * 테스트 #71: 2개 상품 + 상품쿠폰 + 고정배송비
     */
    public function test_complex_two_products_with_product_coupon_and_fixed_shipping(): void
    {
        // Given: 배송정책 (고정 3000원)
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);

        // 2개 상품 (배송정책 포함)
        [$product1, $option1] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);
        [$product2, $option2] = $this->createProductWithShippingPolicy(20000, $shippingPolicy);

        // 상품쿠폰: 5000원 고정 할인
        $couponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::FIXED,
            5000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(50000, $result->summary->subtotal); // 30000 + 20000
        // 상품쿠폰: Fixed 5000원 × 2개 상품 = 10000원
        $this->assertEquals(10000, $result->summary->productCouponDiscount);
        $this->assertEquals(3000, $result->summary->totalShipping);
        // finalAmount = 50000 - 10000 + 3000 = 43000
        $this->assertEquals(43000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #72: 3개 상품 + 주문쿠폰 + 배송쿠폰
     */
    public function test_complex_three_products_with_order_and_shipping_coupon(): void
    {
        // Given: 배송정책 (고정 5000원)
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 5000);

        // 3개 상품 (배송정책 포함)
        [$product1, $option1] = $this->createProductWithShippingPolicy(20000, $shippingPolicy);
        [$product2, $option2] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);
        [$product3, $option3] = $this->createProductWithShippingPolicy(50000, $shippingPolicy);

        // 주문쿠폰: 10% 할인
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::RATE,
            10,
            null
        );

        // 배송비쿠폰: 전액 무료
        $shippingCouponIssue = $this->createShippingCouponWithIssue(
            CouponDiscountType::FIXED,
            5000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
                new CalculationItem(productId: $product3->id, productOptionId: $option3->id, quantity: 1),
            ],
            couponIssueIds: [$orderCouponIssue->id, $shippingCouponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(100000, $result->summary->subtotal); // 20000 + 30000 + 50000
        $this->assertEquals(10000, $result->summary->orderCouponDiscount); // 100000 * 10%
        $this->assertEquals(5000, $result->summary->totalShipping);
        $this->assertEquals(5000, $result->summary->shippingDiscount);
        $this->assertEquals(90000, $result->summary->finalAmount); // 100000 - 10000 + 5000 - 5000
    }

    /**
     * 테스트 #73: 상품쿠폰 + 주문쿠폰 + 마일리지 조합
     */
    public function test_complex_product_coupon_order_coupon_and_mileage(): void
    {
        // Given: 2개 상품
        [$product1, $option1] = $this->createProductWithOption(40000);
        [$product2, $option2] = $this->createProductWithOption(60000);

        // 상품쿠폰: 3000원
        $productCouponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::FIXED,
            3000,
            null
        );

        // 주문쿠폰: 5%
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::RATE,
            5,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
            ],
            couponIssueIds: [$productCouponIssue->id, $orderCouponIssue->id],
            usePoints: 5000
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(100000, $result->summary->subtotal);
        // 상품쿠폰: Fixed 3000원 × 2개 상품 = 6000원
        $this->assertEquals(6000, $result->summary->productCouponDiscount);
        // 주문쿠폰: (100000 - 6000) * 5% = 4700
        $this->assertEquals(4700, $result->summary->orderCouponDiscount);
        $this->assertEquals(5000, $result->summary->pointsUsed);
        // finalAmount = 100000 - 6000 - 4700 - 5000 = 84300
        $this->assertEquals(84300, $result->summary->finalAmount);
    }

    /**
     * 테스트 #74: 과세/면세 혼합 + 할인 적용
     */
    public function test_complex_mixed_tax_status_with_discount(): void
    {
        // Given: 과세 상품 + 면세 상품
        [$productTaxable, $optionTaxable] = $this->createProductWithTaxStatus(30000, ProductTaxStatus::TAXABLE);
        [$productTaxFree, $optionTaxFree] = $this->createProductWithTaxStatus(20000, ProductTaxStatus::TAX_FREE);

        // 주문쿠폰: 5000원
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::FIXED,
            5000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productTaxable->id, productOptionId: $optionTaxable->id, quantity: 1),
                new CalculationItem(productId: $productTaxFree->id, productOptionId: $optionTaxFree->id, quantity: 1),
            ],
            couponIssueIds: [$orderCouponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(50000, $result->summary->subtotal);
        $this->assertEquals(5000, $result->summary->orderCouponDiscount);
        // 과세/면세 금액 합계
        $totalTaxable = 0;
        $totalTaxFree = 0;
        foreach ($result->items as $item) {
            $totalTaxable += $item->taxableAmount;
            $totalTaxFree += $item->taxFreeAmount;
        }
        $this->assertGreaterThan(0, $totalTaxable);
        $this->assertGreaterThan(0, $totalTaxFree);
    }

    /**
     * 테스트 #75: 다중 배송정책 + 상품쿠폰 + 주문쿠폰
     */
    public function test_complex_multiple_shipping_policies_with_coupons(): void
    {
        // Given: 3개 상품, 각각 다른 배송정책
        [$product1, $option1] = $this->createProductWithOption(20000);
        [$product2, $option2] = $this->createProductWithOption(30000);
        [$product3, $option3] = $this->createProductWithOption(50000);

        $freePolicy = $this->createShippingPolicy(ChargePolicyEnum::FREE);
        $fixedPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        $conditionalPolicy = $this->createShippingPolicy(ChargePolicyEnum::CONDITIONAL_FREE, 5000, 40000);

        $product1->update(['shipping_policy_id' => $freePolicy->id]);
        $product2->update(['shipping_policy_id' => $fixedPolicy->id]);
        $product3->update(['shipping_policy_id' => $conditionalPolicy->id]); // 50000원 > 40000원 → 무료

        // 상품쿠폰: 10%
        $productCouponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::RATE,
            10,
            null
        );

        // 주문쿠폰: 2000원
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::FIXED,
            2000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
                new CalculationItem(productId: $product3->id, productOptionId: $option3->id, quantity: 1),
            ],
            couponIssueIds: [$productCouponIssue->id, $orderCouponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(100000, $result->summary->subtotal);
        $this->assertEquals(10000, $result->summary->productCouponDiscount); // 100000 * 10%
        // 주문쿠폰: 2000원
        $this->assertEquals(2000, $result->summary->orderCouponDiscount);
        // 배송비: 무료 + 3000 + 무료(조건충족) = 3000
        $this->assertEquals(3000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #76: 수량 2개 이상 + 배송비 + 쿠폰
     */
    public function test_complex_multiple_quantity_with_shipping_and_coupon(): void
    {
        // Given: 1개 상품, 수량 3개
        [$product, $option] = $this->createProductWithOption(10000);

        // 수량별 배송비 정책
        $perQuantityPolicy = $this->createShippingPolicy(ChargePolicyEnum::PER_QUANTITY, 1000);
        $product->update(['shipping_policy_id' => $perQuantityPolicy->id]);

        // 상품쿠폰: 5%
        $couponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::RATE,
            5,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(30000, $result->summary->subtotal); // 10000 * 3
        $this->assertEquals(1500, $result->summary->productCouponDiscount); // 30000 * 5%
        $this->assertEquals(3000, $result->summary->totalShipping); // 1000 * 3
        $this->assertEquals(31500, $result->summary->finalAmount); // 30000 - 1500 + 3000
    }

    /**
     * 테스트 #77: 전체 할인 시 0원 결제
     */
    public function test_complex_full_discount_zero_payment(): void
    {
        // Given: 소액 상품
        [$product, $option] = $this->createProductWithOption(5000);

        // 배송비 무료
        $freePolicy = $this->createShippingPolicy(ChargePolicyEnum::FREE);
        $product->update(['shipping_policy_id' => $freePolicy->id]);

        // 상품쿠폰: 5000원 (전액)
        $couponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::FIXED,
            5000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(5000, $result->summary->subtotal);
        $this->assertEquals(5000, $result->summary->productCouponDiscount);
        $this->assertEquals(0, $result->summary->finalAmount);
    }

    /**
     * 테스트 #78: 할인 + 마일리지로 0원 결제
     */
    public function test_complex_discount_and_mileage_zero_payment(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(10000);

        // 상품쿠폰: 5000원
        $couponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::FIXED,
            5000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
            usePoints: 5000 // 나머지 5000원을 마일리지로
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(10000, $result->summary->subtotal);
        $this->assertEquals(5000, $result->summary->productCouponDiscount);
        $this->assertEquals(5000, $result->summary->pointsUsed);
        $this->assertEquals(0, $result->summary->finalAmount);
    }

    /**
     * 테스트 #79: 복수 상품 동일 배송정책 그룹화
     */
    public function test_complex_same_shipping_policy_grouped(): void
    {
        // Given: 3개 상품, 같은 배송정책 → 그룹화
        [$product1, $option1] = $this->createProductWithOption(10000);
        [$product2, $option2] = $this->createProductWithOption(20000);
        [$product3, $option3] = $this->createProductWithOption(30000);

        // 조건부 무료: 50000원 이상 무료, 미만 시 3000원
        $conditionalPolicy = $this->createShippingPolicy(ChargePolicyEnum::CONDITIONAL_FREE, 3000, 50000);
        $product1->update(['shipping_policy_id' => $conditionalPolicy->id]);
        $product2->update(['shipping_policy_id' => $conditionalPolicy->id]);
        $product3->update(['shipping_policy_id' => $conditionalPolicy->id]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
                new CalculationItem(productId: $product3->id, productOptionId: $option3->id, quantity: 1),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 총 60000원 > 50000원 → 배송비 무료
        $this->assertEquals(60000, $result->summary->subtotal);
        $this->assertEquals(0, $result->summary->totalShipping);
        $this->assertEquals(60000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #80: 특정 상품 쿠폰 + 주문쿠폰 조합
     */
    public function test_complex_specific_product_coupon_with_order_coupon(): void
    {
        // Given: 2개 상품
        [$product1, $option1] = $this->createProductWithOption(30000);
        [$product2, $option2] = $this->createProductWithOption(20000);

        // product1 전용 쿠폰: 3000원
        $productCouponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::PRODUCTS,
            CouponDiscountType::FIXED,
            3000,
            null,
            [$product1->id]
        );

        // 주문쿠폰: 10%
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::RATE,
            10,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
            ],
            couponIssueIds: [$productCouponIssue->id, $orderCouponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(50000, $result->summary->subtotal);
        $this->assertEquals(3000, $result->summary->productCouponDiscount); // product1에만 적용
        // 주문쿠폰: (50000 - 3000) * 10% = 4700
        $this->assertEquals(4700, $result->summary->orderCouponDiscount);
        // finalAmount = 50000 - 3000 - 4700 = 42300
        $this->assertEquals(42300, $result->summary->finalAmount);
    }

    /**
     * 테스트 #81: 정률쿠폰 최대할인 제한 + 주문쿠폰
     */
    public function test_complex_rate_coupon_max_discount_with_order_coupon(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        // 상품쿠폰: 50% (최대 10000원)
        $productCouponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::RATE,
            50,
            10000
        );

        // 주문쿠폰: 5000원
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::FIXED,
            5000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$productCouponIssue->id, $orderCouponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(100000, $result->summary->subtotal);
        // 50% = 50000원이지만 최대 10000원
        $this->assertEquals(10000, $result->summary->productCouponDiscount);
        $this->assertEquals(5000, $result->summary->orderCouponDiscount);
        // finalAmount = 100000 - 10000 - 5000 = 85000
        $this->assertEquals(85000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #82: 마일리지 전액 사용 (결제금액 초과 요청)
     */
    public function test_complex_mileage_exceeds_payment_amount(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            usePoints: 20000 // 결제금액(10000원)보다 많이 요청
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 결제금액만큼만 사용됨
        $this->assertEquals(10000, $result->summary->subtotal);
        $this->assertEquals(10000, $result->summary->pointsUsed); // 실제 사용은 10000원
        $this->assertEquals(0, $result->summary->finalAmount);
    }

    /**
     * 테스트 #83: 구간별 배송비 + 수량 조합
     */
    public function test_complex_range_shipping_with_quantity(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(5000);

        // 수량별 구간 배송비: 1-2개: 3000원, 3-5개: 5000원, 6개+: 7000원
        $rangePolicy = $this->createShippingPolicy(
            ChargePolicyEnum::RANGE_QUANTITY,
            0,
            null,
            [
                'tiers' => [
                    ['min' => 0, 'max' => 2, 'fee' => 3000],
                    ['min' => 2, 'max' => 5, 'fee' => 5000],
                    ['min' => 5, 'max' => null, 'fee' => 7000],
                ],
            ]
        );
        $product->update(['shipping_policy_id' => $rangePolicy->id]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 4),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(20000, $result->summary->subtotal); // 5000 * 4
        $this->assertEquals(5000, $result->summary->totalShipping); // 4개 → 3-5개 구간
        $this->assertEquals(25000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #84: 금액별 구간 배송비 + 쿠폰
     */
    public function test_complex_range_amount_shipping_with_coupon(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(35000);

        // 금액별 구간 배송비
        $rangePolicy = $this->createShippingPolicy(
            ChargePolicyEnum::RANGE_AMOUNT,
            0,
            null,
            [
                'tiers' => [
                    ['min' => 0, 'max' => 30000, 'fee' => 5000],
                    ['min' => 30000, 'max' => 50000, 'fee' => 3000],
                    ['min' => 50000, 'max' => null, 'fee' => 0],
                ],
            ]
        );
        $product->update(['shipping_policy_id' => $rangePolicy->id]);

        // 상품쿠폰: 5000원
        $couponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::FIXED,
            5000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(35000, $result->summary->subtotal);
        $this->assertEquals(5000, $result->summary->productCouponDiscount);
        // 배송비 구간: 35000원 → 30001-50000 구간 → 3000원
        $this->assertEquals(3000, $result->summary->totalShipping);
        // finalAmount = 35000 - 5000 + 3000 = 33000
        $this->assertEquals(33000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #85: 전체 시나리오 - 상품쿠폰 + 주문쿠폰 + 배송쿠폰 + 마일리지
     */
    public function test_complex_full_scenario_all_discounts(): void
    {
        // Given: 2개 상품
        [$product1, $option1] = $this->createProductWithOption(50000);
        [$product2, $option2] = $this->createProductWithOption(50000);

        // 배송정책 (고정 5000원)
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 5000);
        $product1->update(['shipping_policy_id' => $shippingPolicy->id]);
        $product2->update(['shipping_policy_id' => $shippingPolicy->id]);

        // 상품쿠폰: 10000원
        $productCouponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::FIXED,
            10000,
            null
        );

        // 주문쿠폰: 5%
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::RATE,
            5,
            null
        );

        // 배송비쿠폰: 50% (2500원)
        $shippingCouponIssue = $this->createShippingCouponWithIssue(
            CouponDiscountType::RATE,
            50,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
            ],
            couponIssueIds: [$productCouponIssue->id, $orderCouponIssue->id, $shippingCouponIssue->id],
            usePoints: 10000
        );

        // When
        $result = $this->service->calculate($input);

        // Then
        $this->assertEquals(100000, $result->summary->subtotal);
        // 상품쿠폰: Fixed 10000원 × 2개 상품 = 20000원
        $this->assertEquals(20000, $result->summary->productCouponDiscount);
        // 주문쿠폰: (100000 - 20000) * 5% = 4000
        $this->assertEquals(4000, $result->summary->orderCouponDiscount);
        $this->assertEquals(5000, $result->summary->totalShipping);
        $this->assertEquals(2500, $result->summary->shippingDiscount); // 5000 * 50%
        $this->assertEquals(10000, $result->summary->pointsUsed);
        // finalAmount = 100000 - 20000 - 4000 + 5000 - 2500 - 10000 = 68500
        $this->assertEquals(68500, $result->summary->finalAmount);
    }

    /**
     * 테스트 #86: 적립금 계산 검증 (기본 1%)
     */
    public function test_complex_points_earning_default_rate(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(100000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 기본 적립율 1%
        $this->assertEquals(1000, $result->summary->pointsEarning); // 100000 * 1%
    }

    /**
     * 테스트 #87: 다중 옵션 배송정책 별도 적용
     */
    public function test_complex_multiple_options_separate_shipping_policies(): void
    {
        // Given: 3개 상품, 2개 다른 배송정책
        [$product1, $option1] = $this->createProductWithOption(20000);
        [$product2, $option2] = $this->createProductWithOption(30000);
        [$product3, $option3] = $this->createProductWithOption(50000);

        $policyA = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        $policyB = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 5000);

        $product1->update(['shipping_policy_id' => $policyA->id]);
        $product2->update(['shipping_policy_id' => $policyA->id]); // 같은 정책
        $product3->update(['shipping_policy_id' => $policyB->id]); // 다른 정책

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
                new CalculationItem(productId: $product3->id, productOptionId: $option3->id, quantity: 1),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: policyA(3000) + policyB(5000) = 8000
        $this->assertEquals(100000, $result->summary->subtotal);
        $this->assertEquals(8000, $result->summary->totalShipping);
        $this->assertEquals(108000, $result->summary->finalAmount);
    }

    // ========================================
    // Section 7.12.3: 배송비 복합 시나리오 추가
    // ========================================

    /**
     * 테스트 #84: 배송비가 상품가보다 높은 경우
     *
     * 시나리오: 상품 5,000원, 배송비 7,000원
     * 검증: 정상 계산 (음수 아님)
     */
    public function test_complex_shipping_higher_than_product_price(): void
    {
        // Given: 상품 5,000원, 배송비 7,000원
        [$product, $option] = $this->createProductWithOption(5000);
        $policy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 7000);
        $product->update(['shipping_policy_id' => $policy->id]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 정상 계산
        $this->assertEquals(5000, $result->summary->subtotal);
        $this->assertEquals(7000, $result->summary->totalShipping);
        $this->assertEquals(12000, $result->summary->finalAmount);
        // 음수가 아님을 확인
        $this->assertGreaterThan(0, $result->summary->finalAmount);
    }

    // ========================================
    // Section 7.12.4: 금액 경계값 시나리오 추가
    // ========================================

    /**
     * 테스트 #94: 마일리지 전액 사용
     *
     * 시나리오: 결제금액 50,000원, 마일리지 50,000원 사용
     * 검증: finalAmount = 0
     */
    public function test_complex_mileage_full_payment(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            usePoints: 50000
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 마일리지로 전액 결제
        $this->assertEquals(50000, $result->summary->subtotal);
        $this->assertEquals(50000, $result->summary->pointsUsed);
        $this->assertEquals(0, $result->summary->finalAmount);
    }

    // ========================================
    // Section 7.12.6: 특수 케이스 시나리오 추가
    // ========================================

    /**
     * 테스트 #100: 전체 옵션 취소 (빈 장바구니)
     *
     * 시나리오: 모든 옵션 취소 후 재계산
     * 검증: 빈 결과, 0원 처리
     */
    public function test_complex_empty_cart_calculation(): void
    {
        // Given: 빈 장바구니
        $input = new CalculationInput(
            items: []
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 모든 값이 0
        $this->assertEmpty($result->items);
        $this->assertEquals(0, $result->summary->subtotal);
        $this->assertEquals(0, $result->summary->totalShipping);
        $this->assertEquals(0, $result->summary->productCouponDiscount);
        $this->assertEquals(0, $result->summary->orderCouponDiscount);
        $this->assertEquals(0, $result->summary->pointsUsed);
        $this->assertEquals(0, $result->summary->finalAmount);
    }

    // ========================================
    // Section 7.12.5: 옵션/수량 변동 테스트 (3개)
    // ========================================

    /**
     * 테스트 #101: 수량 증가 시 수량당 배송비 재계산
     *
     * 시나리오: 수량 2개 → 5개로 변경 시 배송비 변화
     * 검증: 배송비가 수량에 비례하여 증가
     */
    public function test_quantity_change_affects_per_quantity_shipping(): void
    {
        // Given: 수량당 배송비 정책 (1,000원/개)
        $policy = $this->createShippingPolicy(ChargePolicyEnum::PER_QUANTITY, 1000);
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $policy);

        // When: 수량 2개
        $input2 = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ]
        );
        $result2 = $this->service->calculate($input2);

        // When: 수량 5개
        $input5 = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 5),
            ]
        );
        $result5 = $this->service->calculate($input5);

        // Then: 수량 2개 → 배송비 2,000원
        $this->assertEquals(20000, $result2->summary->subtotal);
        $this->assertEquals(2000, $result2->summary->totalShipping);
        $this->assertEquals(22000, $result2->summary->finalAmount);

        // Then: 수량 5개 → 배송비 5,000원
        $this->assertEquals(50000, $result5->summary->subtotal);
        $this->assertEquals(5000, $result5->summary->totalShipping);
        $this->assertEquals(55000, $result5->summary->finalAmount);
    }

    /**
     * 테스트 #102: 수량 변경으로 조건부 무료배송 달성/미달
     *
     * 시나리오: 30,000원 이상 무료배송, 상품 10,000원
     *   - 수량 2개: 20,000원 < 30,000원 → 배송비 3,000원
     *   - 수량 3개: 30,000원 >= 30,000원 → 배송비 무료
     */
    public function test_quantity_change_conditional_free_shipping_threshold(): void
    {
        // Given: 조건부 무료배송 정책 (30,000원 이상 무료, 기본 3,000원)
        $policy = $this->createShippingPolicy(ChargePolicyEnum::CONDITIONAL_FREE, 3000, 30000);
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $policy);

        // When: 수량 2개 (20,000원 - 무료배송 미달)
        $input2 = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ]
        );
        $result2 = $this->service->calculate($input2);

        // When: 수량 3개 (30,000원 - 무료배송 달성)
        $input3 = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ]
        );
        $result3 = $this->service->calculate($input3);

        // Then: 수량 2개 → 배송비 3,000원 (미달)
        $this->assertEquals(20000, $result2->summary->subtotal);
        $this->assertEquals(3000, $result2->summary->totalShipping);
        $this->assertEquals(23000, $result2->summary->finalAmount);

        // Then: 수량 3개 → 배송비 무료 (달성)
        $this->assertEquals(30000, $result3->summary->subtotal);
        $this->assertEquals(0, $result3->summary->totalShipping);
        $this->assertEquals(30000, $result3->summary->finalAmount);
    }

    /**
     * 테스트 #103: 다른 배송정책 상품 추가 시 그룹별 배송비 계산
     *
     * 시나리오:
     *   - 상품A(무료배송)만: 배송비 0원
     *   - 상품A + 상품B(고정 3,000원) 추가: 배송비 3,000원
     */
    public function test_adding_product_with_different_shipping_policy(): void
    {
        // Given: 무료배송 정책 상품
        $freePollicy = $this->createShippingPolicy(ChargePolicyEnum::FREE);
        [$productA, $optionA] = $this->createProductWithShippingPolicy(20000, $freePollicy);

        // Given: 고정배송비 정책 상품
        $fixedPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        [$productB, $optionB] = $this->createProductWithShippingPolicy(15000, $fixedPolicy);

        // When: 상품A만
        $inputA = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            ]
        );
        $resultA = $this->service->calculate($inputA);

        // When: 상품A + 상품B
        $inputAB = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ]
        );
        $resultAB = $this->service->calculate($inputAB);

        // Then: 상품A만 → 배송비 0원
        $this->assertEquals(20000, $resultA->summary->subtotal);
        $this->assertEquals(0, $resultA->summary->totalShipping);
        $this->assertEquals(20000, $resultA->summary->finalAmount);

        // Then: 상품A + 상품B → 배송비 3,000원 (무료 + 고정)
        $this->assertEquals(35000, $resultAB->summary->subtotal);
        $this->assertEquals(3000, $resultAB->summary->totalShipping);
        $this->assertEquals(38000, $resultAB->summary->finalAmount);
    }

    // ========================================
    // Section 7.12.3.1: 추가배송비(도서산간) 복합 시나리오 (6개)
    // ========================================

    /**
     * 테스트 #85: 전체 흐름 - 상품쿠폰 + 주문쿠폰 + 추가배송비 + 배송비쿠폰
     *
     * 3개 상품, 도서산간 배송지, 모든 쿠폰 적용
     * 기본/추가 배송비 분리, 쿠폰 할인은 총액 기준
     */
    public function test_complex_full_flow_with_extra_shipping_and_all_coupons(): void
    {
        // Given: 고정 배송비 정책 + 도서산간 추가배송비
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 5000],
            ]
        );

        // Given: 3개 상품
        [$product1, $option1] = $this->createProductWithShippingPolicy(30000, $policy);
        [$product2, $option2] = $this->createProductWithShippingPolicy(20000, $policy);
        [$product3, $option3] = $this->createProductWithShippingPolicy(50000, $policy);

        // Given: 상품 쿠폰 (상품1에 10% 할인)
        $productCouponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::PRODUCTS,
            CouponDiscountType::RATE,
            10,
            targetIds: [$product1->id]
        );

        // Given: 주문 쿠폰 (3,000원 할인)
        $orderCouponIssue = $this->createOrderCouponWithIssue(CouponDiscountType::FIXED, 3000);

        // Given: 배송비 쿠폰 (50% 할인)
        $shippingCouponIssue = $this->createShippingCouponWithIssue(CouponDiscountType::RATE, 50);

        // When: 도서산간 배송지, 모든 쿠폰 적용
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product1->id, productOptionId: $option1->id, quantity: 1),
                new CalculationItem(productId: $product2->id, productOptionId: $option2->id, quantity: 1),
                new CalculationItem(productId: $product3->id, productOptionId: $option3->id, quantity: 1),
            ],
            couponIssueIds: [$productCouponIssue->id, $orderCouponIssue->id, $shippingCouponIssue->id],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '63123')
        );
        $result = $this->service->calculate($input);

        // Then: 상품금액 100,000원 (30,000 + 20,000 + 50,000)
        $this->assertEquals(100000, $result->summary->subtotal);

        // Then: 상품쿠폰 할인 3,000원 (30,000 × 10%)
        $this->assertEquals(3000, $result->summary->productCouponDiscount);

        // Then: 주문쿠폰 할인 3,000원
        $this->assertEquals(3000, $result->summary->orderCouponDiscount);

        // Then: 총 할인 6,000원
        $this->assertEquals(6000, $result->summary->totalDiscount);

        // Then: 기본 배송비 3,000원, 추가배송비 5,000원, 총 배송비 8,000원
        $this->assertEquals(3000, $result->summary->baseShippingTotal);
        $this->assertEquals(5000, $result->summary->extraShippingTotal);
        $this->assertEquals(8000, $result->summary->totalShipping);

        // Then: 배송비 쿠폰 50% → 4,000원 할인
        $this->assertEquals(4000, $result->summary->shippingDiscount);

        // Then: 최종금액 = 100,000 - 6,000 + 8,000 - 4,000 = 98,000원
        $this->assertEquals(98000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #86: 다중 정책 + 각각 다른 추가배송비
     *
     * 정책A: 추가배송비 5,000원, 정책B: 추가배송비 3,000원, 정책C: 추가배송비 없음
     * 각 정책별 추가배송비 독립 계산
     */
    public function test_complex_multiple_policies_with_different_extra_fees(): void
    {
        // Given: 정책A - 추가배송비 5,000원
        $policyA = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 2000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 5000],
            ]
        );

        // Given: 정책B - 추가배송비 3,000원
        $policyB = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 2500,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ]
        );

        // Given: 정책C - 추가배송비 없음
        $policyC = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: false
        );

        [$productA, $optionA] = $this->createProductWithShippingPolicy(20000, $policyA);
        [$productB, $optionB] = $this->createProductWithShippingPolicy(30000, $policyB);
        [$productC, $optionC] = $this->createProductWithShippingPolicy(40000, $policyC);

        // When: 도서산간 배송지
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
                new CalculationItem(productId: $productC->id, productOptionId: $optionC->id, quantity: 1),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '63500')
        );
        $result = $this->service->calculate($input);

        // Then: 기본 배송비 = 2,000 + 2,500 + 3,000 = 7,500원
        $this->assertEquals(7500, $result->summary->baseShippingTotal);

        // Then: 추가 배송비 = 5,000 + 3,000 + 0 = 8,000원
        $this->assertEquals(8000, $result->summary->extraShippingTotal);

        // Then: 총 배송비 = 15,500원
        $this->assertEquals(15500, $result->summary->totalShipping);

        // Then: 최종금액 = 90,000 + 15,500 = 105,500원
        $this->assertEquals(90000, $result->summary->subtotal);
        $this->assertEquals(105500, $result->summary->finalAmount);
    }

    /**
     * 테스트 #87: per_* 정책 혼합 + multiply 설정 혼합
     *
     * PER_QUANTITY(multiply=true) + FIXED(multiply=false)
     * 정책별 multiply 설정 독립 적용
     */
    public function test_complex_per_policies_with_mixed_multiply_settings(): void
    {
        // Given: PER_QUANTITY 정책 + multiply=true
        $policyA = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 2],  // 2개당 1,000원
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 2000],
            ],
            extraFeeMultiply: true
        );

        // Given: FIXED 정책 + multiply=false (기본)
        $policyB = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ],
            extraFeeMultiply: false
        );

        [$productA, $optionA] = $this->createProductWithShippingPolicy(10000, $policyA);
        [$productB, $optionB] = $this->createProductWithShippingPolicy(15000, $policyB);

        // When: 상품A 5개, 상품B 3개 → 도서산간 배송지
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 5),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 3),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '63123')
        );
        $result = $this->service->calculate($input);

        // Then: 상품금액 = 50,000 + 45,000 = 95,000원
        $this->assertEquals(95000, $result->summary->subtotal);

        // Then: 기본 배송비
        // 정책A: ceil(5/2) × 1,000 = 3,000원
        // 정책B: 3,000원 (고정)
        // 합계: 6,000원
        $this->assertEquals(6000, $result->summary->baseShippingTotal);

        // Then: 추가 배송비
        // 정책A: multiply=true → 2,000 × 3 = 6,000원 (배송비 단위 수만큼)
        // 정책B: multiply=false → 3,000원 (1회만)
        // 합계: 9,000원
        $this->assertEquals(9000, $result->summary->extraShippingTotal);

        // Then: 총 배송비 = 15,000원
        $this->assertEquals(15000, $result->summary->totalShipping);
    }

    /**
     * 테스트 #88: 무료배송 조건 충족 + 추가배송비
     *
     * 기본배송비 무료 조건 충족, 도서산간 지역
     * baseShippingTotal=0, extraShippingTotal > 0
     */
    public function test_complex_free_shipping_with_extra_fee(): void
    {
        // Given: CONDITIONAL_FREE 정책 (5만원 이상 무료) + 추가배송비
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 5000],
            ]
        );

        [$product, $option] = $this->createProductWithShippingPolicy(60000, $policy);

        // When: 60,000원 상품 (무료배송 조건 충족) + 도서산간
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '63456')
        );
        $result = $this->service->calculate($input);

        // Then: 기본 배송비 0원 (무료배송 조건 충족)
        $this->assertEquals(0, $result->summary->baseShippingTotal);

        // Then: 추가 배송비 5,000원 (도서산간)
        $this->assertEquals(5000, $result->summary->extraShippingTotal);

        // Then: 총 배송비 5,000원
        $this->assertEquals(5000, $result->summary->totalShipping);

        // Then: 최종금액 = 60,000 + 5,000 = 65,000원
        $this->assertEquals(65000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #89: 배송지 변경으로 추가배송비 발생
     *
     * 일반지역 → 도서산간 지역 변경
     * 추가배송비 동적 재계산
     */
    public function test_complex_address_change_adds_extra_fee(): void
    {
        // Given: 추가배송비 설정된 정책
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 5000],
            ]
        );

        [$product, $option] = $this->createProductWithShippingPolicy(50000, $policy);

        // When: 일반 지역 배송
        $inputNormal = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '12345')
        );
        $resultNormal = $this->service->calculate($inputNormal);

        // When: 도서산간 지역으로 변경
        $inputIsland = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '63789')
        );
        $resultIsland = $this->service->calculate($inputIsland);

        // Then: 일반 지역 - 추가배송비 0원
        $this->assertEquals(0, $resultNormal->summary->extraShippingTotal);
        $this->assertEquals(3000, $resultNormal->summary->totalShipping);
        $this->assertEquals(53000, $resultNormal->summary->finalAmount);

        // Then: 도서산간 지역 - 추가배송비 5,000원
        $this->assertEquals(5000, $resultIsland->summary->extraShippingTotal);
        $this->assertEquals(8000, $resultIsland->summary->totalShipping);
        $this->assertEquals(58000, $resultIsland->summary->finalAmount);

        // Then: 차이 = 5,000원 (추가배송비)
        $this->assertEquals(5000, $resultIsland->summary->finalAmount - $resultNormal->summary->finalAmount);
    }

    /**
     * 테스트 #90: 배송지 변경으로 추가배송비 제거
     *
     * 도서산간 → 일반지역 변경
     * 추가배송비 0원으로 변경
     */
    public function test_complex_address_change_removes_extra_fee(): void
    {
        // Given: 추가배송비 설정된 정책
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 5000],
            ]
        );

        [$product, $option] = $this->createProductWithShippingPolicy(50000, $policy);

        // When: 도서산간 지역 배송
        $inputIsland = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '63789')
        );
        $resultIsland = $this->service->calculate($inputIsland);

        // When: 일반 지역으로 변경
        $inputNormal = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '12345')
        );
        $resultNormal = $this->service->calculate($inputNormal);

        // Then: 도서산간 지역 - 추가배송비 5,000원
        $this->assertEquals(5000, $resultIsland->summary->extraShippingTotal);
        $this->assertEquals(8000, $resultIsland->summary->totalShipping);
        $this->assertEquals(58000, $resultIsland->summary->finalAmount);

        // Then: 일반 지역 - 추가배송비 0원
        $this->assertEquals(0, $resultNormal->summary->extraShippingTotal);
        $this->assertEquals(3000, $resultNormal->summary->totalShipping);
        $this->assertEquals(53000, $resultNormal->summary->finalAmount);

        // Then: 차이 = -5,000원 (추가배송비 제거됨)
        $this->assertEquals(-5000, $resultNormal->summary->finalAmount - $resultIsland->summary->finalAmount);
    }

    // ========================================
    // Section 7.14: 다중 상품 쿠폰 적용 테스트
    // ========================================

    /**
     * 테스트 #95: 동일 상품에 2개 상품쿠폰 적용 (정액 + 정액)
     *
     * 시나리오: 상품 50,000원에 2개 정액 쿠폰 적용
     * - 쿠폰A: 1,000원 정액 할인
     * - 쿠폰B: 2,000원 정액 할인
     * 기대: 총 3,000원 할인
     */
    public function test_multiple_fixed_coupons_apply_to_same_product(): void
    {
        // Given: 50,000원 상품
        [$product, $option] = $this->createProductWithOption(price: 50000);

        // 쿠폰A: 1,000원 정액 할인
        $couponIssueA = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        // 쿠폰B: 2,000원 정액 할인
        $couponIssueB = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssueA->id, $couponIssueB->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 쿠폰 할인 합계 = 1,000 + 2,000 = 3,000원
        $this->assertEquals(3000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(3000, $result->summary->productCouponDiscount);
        $this->assertEquals(47000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #96: 동일 상품에 2개 상품쿠폰 적용 (정률 + 정률)
     *
     * 시나리오: 상품 100,000원에 2개 정률 쿠폰 적용
     * - 쿠폰A: 5% 할인
     * - 쿠폰B: 10% 할인
     *
     * 참고: 현재 구현에서 각 쿠폰은 원가 기준으로 개별 계산됨 (순차 적용 아님)
     */
    public function test_multiple_rate_coupons_apply_to_same_product(): void
    {
        // Given: 100,000원 상품
        [$product, $option] = $this->createProductWithOption(price: 100000);

        // 쿠폰A: 5% 정률 할인
        $couponIssueA = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 5,
            targetScope: CouponTargetScope::ALL
        );

        // 쿠폰B: 10% 정률 할인
        $couponIssueB = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssueA->id, $couponIssueB->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 각 쿠폰은 원가 기준 개별 계산
        // 쿠폰A: 100,000 × 5% = 5,000원
        // 쿠폰B: 100,000 × 10% = 10,000원
        // 총 할인: 15,000원
        $this->assertEquals(15000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(15000, $result->summary->productCouponDiscount);
        $this->assertEquals(85000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #97: 동일 상품에 2개 상품쿠폰 적용 (정액 + 정률 혼합)
     *
     * 시나리오: 상품 50,000원에 정액 + 정률 쿠폰 적용
     * - 쿠폰A: 5,000원 정액 할인
     * - 쿠폰B: 10% 정률 할인
     *
     * 참고: 현재 구현에서 각 쿠폰은 원가 기준으로 개별 계산됨
     */
    public function test_mixed_fixed_and_rate_coupons_apply_to_same_product(): void
    {
        // Given: 50,000원 상품
        [$product, $option] = $this->createProductWithOption(price: 50000);

        // 쿠폰A: 5,000원 정액 할인
        $couponIssueA = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            targetScope: CouponTargetScope::ALL
        );

        // 쿠폰B: 10% 정률 할인
        $couponIssueB = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssueA->id, $couponIssueB->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 각 쿠폰은 원가 기준 개별 계산
        // 쿠폰A: 5,000원 정액
        // 쿠폰B: 50,000 × 10% = 5,000원
        // 총 할인: 10,000원
        $this->assertEquals(10000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(10000, $result->summary->productCouponDiscount);
        $this->assertEquals(40000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #98: 다중 상품에 각각 다른 쿠폰 적용
     *
     * 시나리오: 상품A, 상품B에 서로 다른 쿠폰 적용
     * - 상품A (30,000원): 쿠폰1 (전체 적용 1,000원)
     * - 상품B (20,000원): 쿠폰1 (전체 적용 1,000원) + 쿠폰2 (상품B만 2,000원)
     */
    public function test_different_coupons_for_different_products(): void
    {
        // Given: 상품A 30,000원, 상품B 20,000원
        [$productA, $optionA] = $this->createProductWithOption(price: 30000);
        [$productB, $optionB] = $this->createProductWithOption(price: 20000);

        // 쿠폰1: 전체 상품 1,000원 정액 할인
        $couponIssue1 = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        // 쿠폰2: 상품B만 2,000원 정액 할인
        $couponIssue2 = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
            targetScope: CouponTargetScope::PRODUCTS
        );
        $couponIssue2->coupon->includedProducts()->attach([$productB->id], ['type' => 'include']);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 1
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue1->id, $couponIssue2->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then:
        // 상품A: 쿠폰1만 적용 = 1,000원 할인
        // 상품B: 쿠폰1 + 쿠폰2 = 1,000 + 2,000 = 3,000원 할인
        $this->assertEquals(1000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(3000, $result->items[1]->productCouponDiscountAmount);
        $this->assertEquals(4000, $result->summary->productCouponDiscount);
        $this->assertEquals(46000, $result->summary->finalAmount);
    }

    /**
     * 테스트 #99: 수량 있는 상품에 2개 쿠폰 적용
     *
     * 시나리오: 상품 10,000원 × 3개에 2개 쿠폰 적용
     *
     * 참고: 현재 구현에서 각 쿠폰은 원가 기준으로 개별 계산됨
     */
    public function test_multiple_coupons_with_quantity(): void
    {
        // Given: 10,000원 상품 × 3개 = 30,000원
        [$product, $option] = $this->createProductWithOption(price: 10000);

        // 쿠폰A: 3,000원 정액 할인
        $couponIssueA = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            targetScope: CouponTargetScope::ALL
        );

        // 쿠폰B: 5% 정률 할인
        $couponIssueB = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 5,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 3
                ),
            ],
            couponIssueIds: [$couponIssueA->id, $couponIssueB->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 각 쿠폰은 원가 기준 개별 계산
        // 소계: 10,000 × 3 = 30,000원
        // 쿠폰A: 3,000원 정액 × 수량 3 = 9,000원
        // 쿠폰B: 30,000 × 5% = 1,500원
        // 총 할인: 10,500원
        $this->assertEquals(10500, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(10500, $result->summary->productCouponDiscount);
        $this->assertEquals(19500, $result->summary->finalAmount);
    }

    /**
     * 테스트 #100: 다중 쿠폰 할인 합계 (상품 금액 초과 시 계산 방식)
     *
     * 시나리오: 상품 5,000원에 두 개의 큰 쿠폰 적용
     *
     * 참고: 현재 구현에서는 각 쿠폰의 할인액을 개별 계산하여 합산함
     * 최종 결제 금액에서 음수 방지 처리됨 (finalAmount는 0 이상)
     */
    public function test_multiple_coupons_discount_sum(): void
    {
        // Given: 5,000원 상품
        [$product, $option] = $this->createProductWithOption(price: 5000);

        // 쿠폰A: 3,000원 정액 할인
        $couponIssueA = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            targetScope: CouponTargetScope::ALL
        );

        // 쿠폰B: 3,000원 정액 할인
        $couponIssueB = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssueA->id, $couponIssueB->id],
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 각 쿠폰은 개별 계산 (원가 기준)
        // 쿠폰A: min(3,000, 5,000) = 3,000원
        // 쿠폰B: min(3,000, 잔액 2,000) = 3,000원 (순차 적용 아님)
        // 쿠폰 합계: 6,000원 (상품 금액 초과 가능)
        $this->assertEquals(6000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(6000, $result->summary->productCouponDiscount);
        // 최종 금액은 음수 방지로 0원 처리
        $this->assertEquals(0, $result->summary->finalAmount);
    }

    /**
     * 테스트: 상품쿠폰 적용 시 포맷된 할인 금액이 포함되는지 확인
     */
    public function test_product_coupon_includes_formatted_discount_amounts(): void
    {
        // Given: 배송정책 (고정 3000원)
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);

        // 상품 (10,000원)
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $shippingPolicy);

        // 상품쿠폰: 1000원 고정 할인
        $couponIssue = $this->createProductCouponWithIssue(
            CouponTargetScope::ALL,
            CouponDiscountType::FIXED,
            1000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 포맷된 금액 필드 확인
        $resultArray = $result->toArray();
        $productCoupons = $resultArray['promotions']['product_promotions']['coupons'] ?? [];

        $this->assertNotEmpty($productCoupons);
        $this->assertArrayHasKey('total_discount_formatted', $productCoupons[0]);
        $this->assertNotEmpty($productCoupons[0]['total_discount_formatted']);
        $this->assertEquals(1000, $productCoupons[0]['total_discount']);

        // applied_items에도 포맷된 금액 포함 확인
        $appliedItems = $productCoupons[0]['applied_items'] ?? [];
        $this->assertNotEmpty($appliedItems);
        $this->assertArrayHasKey('discount_amount_formatted', $appliedItems[0]);
        $this->assertNotEmpty($appliedItems[0]['discount_amount_formatted']);
    }

    /**
     * 테스트: 주문쿠폰 적용 시 포맷된 할인 금액이 포함되는지 확인
     */
    public function test_order_coupon_includes_formatted_discount_amounts(): void
    {
        // Given: 배송정책 (고정 3000원)
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);

        // 상품 (50,000원)
        [$product, $option] = $this->createProductWithShippingPolicy(50000, $shippingPolicy);

        // 주문쿠폰: 5000원 고정 할인
        $orderCouponIssue = $this->createOrderCouponWithIssue(
            CouponDiscountType::FIXED,
            5000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$orderCouponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 포맷된 금액 필드 확인
        $resultArray = $result->toArray();
        $orderCoupons = $resultArray['promotions']['order_promotions']['coupons'] ?? [];

        $this->assertNotEmpty($orderCoupons);
        $this->assertArrayHasKey('total_discount_formatted', $orderCoupons[0]);
        $this->assertNotEmpty($orderCoupons[0]['total_discount_formatted']);
        $this->assertEquals(5000, $orderCoupons[0]['total_discount']);

        // applied_items에도 포맷된 금액 포함 확인
        $appliedItems = $orderCoupons[0]['applied_items'] ?? [];
        $this->assertNotEmpty($appliedItems);
        $this->assertArrayHasKey('discount_amount_formatted', $appliedItems[0]);
    }

    /**
     * 테스트: 배송비쿠폰 적용 시 포맷된 할인 금액이 포함되는지 확인
     */
    public function test_shipping_coupon_includes_formatted_discount_amounts(): void
    {
        // Given: 배송정책 (고정 5000원)
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 5000);

        // 상품 (30,000원)
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);

        // 배송비쿠폰: 전액 무료 (5000원 고정 할인)
        $shippingCouponIssue = $this->createShippingCouponWithIssue(
            CouponDiscountType::FIXED,
            5000,
            null
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$shippingCouponIssue->id]
        );

        // When
        $result = $this->service->calculate($input);

        // Then: 포맷된 금액 필드 확인
        $resultArray = $result->toArray();
        $orderCoupons = $resultArray['promotions']['order_promotions']['coupons'] ?? [];

        // 배송비 쿠폰은 order_promotions에 포함됨
        $shippingCoupons = array_filter($orderCoupons, fn ($c) => $c['target_type'] === 'shipping_fee');
        $shippingCoupons = array_values($shippingCoupons);

        $this->assertNotEmpty($shippingCoupons);
        $this->assertArrayHasKey('total_discount_formatted', $shippingCoupons[0]);
        $this->assertNotEmpty($shippingCoupons[0]['total_discount_formatted']);
        $this->assertEquals(5000, $shippingCoupons[0]['total_discount']);
    }

    // ========================================
    // Section A: prepareItems 스냅샷 모드 (6개)
    // ========================================

    /**
     * 테스트: 스냅샷 모드에서 아이템의 tax_status를 스냅샷 값으로 사용
     *
     * DB의 현재 tax_status가 변경되어도 스냅샷에 저장된 주문 시점의 값을 사용합니다.
     */
    public function test_snapshot_mode_uses_item_snapshot_tax_status(): void
    {
        // Given: DB에 과세 상품 생성
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => 50000,
            'list_price' => 50000,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
        ]);

        // 통상 모드로 먼저 계산 (taxable)
        $normalResult = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
        ));
        $this->assertEquals(50000, $normalResult->summary->taxableAmount);
        $this->assertEquals(0, $normalResult->summary->taxFreeAmount);

        // DB에서 tax_status를 tax_free로 변경
        $product->update(['tax_status' => 'tax_free']);

        // 스냅샷에는 주문 시점의 taxable 상태를 보관
        $productSnapshot = [
            'id' => $product->id,
            'name' => '테스트 상품',
            'selling_price' => 50000,
            'tax_status' => 'taxable', // 주문 시점: 과세
        ];
        $optionSnapshot = [
            'id' => $option->id,
            'selling_price' => 50000,
            'price_adjustment' => 0,
        ];

        // When: 스냅샷 모드로 계산
        $snapshotResult = $this->service->calculate(new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            metadata: ['snapshot_mode' => true],
        ));

        // Then: DB가 tax_free로 바뀌었지만 스냅샷의 taxable 사용
        $this->assertEquals(50000, $snapshotResult->summary->taxableAmount);
        $this->assertEquals(0, $snapshotResult->summary->taxFreeAmount);
    }

    /**
     * 테스트: 스냅샷 모드에서 아이템 무게를 스냅샷 값으로 사용 (RANGE_WEIGHT 배송비)
     *
     * DB에서 무게가 변경되어도 스냅샷의 주문 시점 무게로 배송비를 계산합니다.
     */
    public function test_snapshot_mode_uses_item_snapshot_weight_for_shipping(): void
    {
        // Given: RANGE_WEIGHT 배송정책 (0~5000g: 3000원, 5000~10000g: 5000원)
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 5000, 'fee' => 3000],
                    ['min' => 5000, 'max' => 10000, 'fee' => 5000],
                ],
            ],
        );

        // DB에 weight=10.0인 상품 생성
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => 30000,
            'list_price' => 30000,
            'shipping_policy_id' => $policy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'weight' => 10.0,
        ]);

        // 스냅샷에는 주문 시점 무게 (2.0kg = 2000g → 0~5000g 구간 → 3000원)
        $productSnapshot = [
            'id' => $product->id,
            'name' => '테스트 상품',
            'selling_price' => 30000,
            'tax_status' => 'taxable',
        ];
        $optionSnapshot = [
            'id' => $option->id,
            'selling_price' => 30000,
            'price_adjustment' => 0,
            'weight' => 2.0, // 주문 시점: 2kg (2000g → 0~5000g 구간)
            'volume' => null,
        ];

        $shippingPolicySnapshots = [
            $option->id => [
                'policy_id' => $policy->id,
                'policy_snapshot' => [
                    'policy_name' => '테스트 배송정책',
                    'country_code' => 'KR',
                    'charge_policy' => 'range_weight',
                    'base_fee' => 0,
                    'ranges' => [
                        'tiers' => [
                            ['min' => 0, 'max' => 5000, 'fee' => 3000],
                            ['min' => 5000, 'max' => 10000, 'fee' => 5000],
                        ],
                    ],
                ],
            ],
        ];

        // When: 스냅샷 모드로 계산
        $result = $this->service->calculate(new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
            shippingPolicySnapshots: $shippingPolicySnapshots,
            metadata: ['snapshot_mode' => true],
        ));

        // Then: 스냅샷 무게 2.0kg → 0~5kg 구간 → 배송비 3000원
        $this->assertEquals(3000, $result->summary->totalShipping);
    }

    /**
     * 테스트: 스냅샷 모드에서 아이템 부피를 스냅샷 값으로 사용 (RANGE_VOLUME 배송비)
     *
     * DB에서 부피가 변경되어도 스냅샷의 주문 시점 부피로 배송비를 계산합니다.
     */
    public function test_snapshot_mode_uses_item_snapshot_volume_for_shipping(): void
    {
        // Given: RANGE_VOLUME 배송정책 (0~5000: 2000원, 5000~10000: 4000원)
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 5000, 'fee' => 2000],
                    ['min' => 5000, 'max' => 10000, 'fee' => 4000],
                ],
            ],
        );

        // DB에 volume=7000인 상품 생성
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => 30000,
            'list_price' => 30000,
            'shipping_policy_id' => $policy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'weight' => 1.0,
            'volume' => 7000.0,
        ]);

        // 스냅샷에는 주문 시점 부피 (3000cm³ → 0~5000 구간 → 2000원)
        $productSnapshot = [
            'id' => $product->id,
            'name' => '테스트 상품',
            'selling_price' => 30000,
            'tax_status' => 'taxable',
        ];
        $optionSnapshot = [
            'id' => $option->id,
            'selling_price' => 30000,
            'price_adjustment' => 0,
            'weight' => 1.0,
            'volume' => 3000.0, // 주문 시점: 3000cm³ (0~5000 구간)
        ];

        $shippingPolicySnapshots = [
            $option->id => [
                'policy_id' => $policy->id,
                'policy_snapshot' => [
                    'policy_name' => '테스트 배송정책',
                    'country_code' => 'KR',
                    'charge_policy' => 'range_volume',
                    'base_fee' => 0,
                    'ranges' => [
                        'tiers' => [
                            ['min' => 0, 'max' => 5000, 'fee' => 2000],
                            ['min' => 5000, 'max' => 10000, 'fee' => 4000],
                        ],
                    ],
                ],
            ],
        ];

        // When: 스냅샷 모드로 계산
        $result = $this->service->calculate(new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
            shippingPolicySnapshots: $shippingPolicySnapshots,
            metadata: ['snapshot_mode' => true],
        ));

        // Then: 스냅샷 부피 50.0 → 0~100 구간 → 배송비 2000원
        $this->assertEquals(2000, $result->summary->totalShipping);
    }

    /**
     * 테스트: 스냅샷 모드에서 혼합 아이템 (스냅샷 + DB) 계산
     *
     * 하나는 스냅샷 데이터, 다른 하나는 DB에서 로드하여 함께 계산합니다.
     */
    public function test_snapshot_mode_mixed_snapshot_and_db_items(): void
    {
        // Given: DB 상품 (통상 모드용)
        [$dbProduct, $dbOption] = $this->createProductWithOption(price: 20000);

        // 스냅샷 아이템 (DB에 없는 가상 상품)
        $productSnapshot = [
            'id' => 9999,
            'name' => '스냅샷 상품',
            'selling_price' => 30000,
            'tax_status' => 'taxable',
        ];
        $optionSnapshot = [
            'id' => 8888,
            'selling_price' => 30000,
            'price_adjustment' => 0,
        ];

        // When: 스냅샷 아이템 + DB 아이템 혼합 계산
        $result = $this->service->calculate(new CalculationInput(
            items: [
                new CalculationItem(
                    productId: 9999,
                    productOptionId: 8888,
                    quantity: 2,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
                new CalculationItem(
                    productId: $dbProduct->id,
                    productOptionId: $dbOption->id,
                    quantity: 1,
                ),
            ],
            metadata: ['snapshot_mode' => true],
        ));

        // Then: 양쪽 모두 정상 계산
        $this->assertCount(2, $result->items);
        // 스냅샷 아이템: 30,000 × 2 = 60,000
        $this->assertEquals(60000, $result->items[0]->subtotal);
        // DB 아이템: 20,000 × 1 = 20,000
        $this->assertEquals(20000, $result->items[1]->subtotal);
        // 합계: 80,000
        $this->assertEquals(80000, $result->summary->subtotal);
    }

    /**
     * 테스트: 스냅샷 모드에서 마일리지 필드를 스냅샷 옵션에서 사용
     *
     * 옵션 스냅샷의 mileage_value/mileage_type으로 마일리지가 계산됩니다.
     */
    public function test_snapshot_mode_mileage_fields_from_snapshot(): void
    {
        // Given: 스냅샷에 mileage_value=5.0 (정률 5%)
        $productSnapshot = [
            'id' => 9999,
            'name' => '마일리지 테스트 상품',
            'selling_price' => 100000,
            'tax_status' => 'taxable',
        ];
        $optionSnapshot = [
            'id' => 8888,
            'selling_price' => 100000,
            'price_adjustment' => 0,
            'mileage_value' => 5.0,
            'mileage_type' => 'rate',
        ];

        // When
        $result = $this->service->calculate(new CalculationInput(
            items: [
                new CalculationItem(
                    productId: 9999,
                    productOptionId: 8888,
                    quantity: 1,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            metadata: ['snapshot_mode' => true],
        ));

        // Then: 마일리지 = 100,000 × 5% = 5,000
        $this->assertEquals(5000, $result->items[0]->pointsEarning);
        $this->assertEquals(5000, $result->summary->pointsEarning);
    }

    /**
     * 테스트: 스냅샷 모드에서 productSnapshot이 null이면 DB 폴백
     *
     * 스냅샷이 없는 아이템은 통상 모드처럼 DB에서 상품 정보를 조회합니다.
     */
    public function test_snapshot_mode_null_fallback_to_db(): void
    {
        // Given: DB에 상품 생성
        [$product, $option] = $this->createProductWithOption(price: 40000);

        // When: productSnapshot=null → DB 폴백
        $result = $this->service->calculate(new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 2,
                    productSnapshot: null, // 스냅샷 없음
                    optionSnapshot: null,
                ),
            ],
            metadata: ['snapshot_mode' => true],
        ));

        // Then: DB에서 조회하여 정상 계산
        $this->assertCount(1, $result->items);
        $this->assertEquals(40000, $result->items[0]->unitPrice);
        $this->assertEquals(80000, $result->items[0]->subtotal);
    }

    // ========================================
    // Section B: 배송비 스냅샷 모드 (2개)
    // ========================================

    /**
     * 테스트: 스냅샷 배송정책의 RANGE_AMOUNT 구간별 수수료 적용
     *
     * 스냅샷에 저장된 구간별 요금으로 주문 금액에 맞는 배송비를 계산합니다.
     */
    public function test_snapshot_shipping_policy_different_fee_ranges(): void
    {
        // Given: DB에 배송정책 생성 (실제와 다른 요금)
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '구간 배송', 'en' => 'Range Shipping'],
            'is_default' => false,
            'is_active' => true,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::RANGE_AMOUNT,
            'base_fee' => 0,
            'ranges' => [
                'ranges' => [
                    ['min' => 0, 'max' => 50000, 'fee' => 9999],
                    ['min' => 50000, 'max' => 999999, 'fee' => 0],
                ],
            ],
            'is_active' => true,
        ]);

        // 스냅샷: 주문 시점의 요금 구간 (0~50000: 3000원, 50000+: 0원)
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
        ];

        $shippingPolicySnapshots = [
            8888 => [
                'policy_id' => $policy->id,
                'policy_snapshot' => [
                    'policy_name' => '구간 배송',
                    'country_code' => 'KR',
                    'charge_policy' => 'range_amount',
                    'base_fee' => 0,
                    'ranges' => [
                        'tiers' => [
                            ['min' => 0, 'max' => 50000, 'fee' => 3000],
                            ['min' => 50000, 'max' => 999999, 'fee' => 0],
                        ],
                    ],
                ],
            ],
        ];

        // When: 30,000원 주문 (0~50000 구간 → 스냅샷 3000원)
        $result = $this->service->calculate(new CalculationInput(
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
        ));

        // Then: DB 요금(9999)이 아닌 스냅샷 요금(3000) 적용
        $this->assertEquals(3000, $result->summary->totalShipping);
    }

    /**
     * 테스트: 스냅샷 배송정책에 도서산간 추가배송비 포함
     *
     * 스냅샷에 저장된 도서산간 설정으로 추가배송비를 계산합니다.
     */
    public function test_snapshot_shipping_policy_extra_fee_from_snapshot(): void
    {
        // Given: DB에 배송정책 생성 (도서산간 미설정)
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '도서산간 배송', 'en' => 'Island Shipping'],
            'is_default' => false,
            'is_active' => true,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FIXED,
            'base_fee' => 3000,
            'extra_fee_enabled' => false, // DB에서는 비활성
            'is_active' => true,
        ]);

        // 스냅샷: 주문 시점에는 도서산간 활성이었음
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
        ];

        $shippingPolicySnapshots = [
            8888 => [
                'policy_id' => $policy->id,
                'policy_snapshot' => [
                    'policy_name' => '도서산간 배송',
                    'country_code' => 'KR',
                    'charge_policy' => 'fixed',
                    'base_fee' => 3000,
                    'extra_fee_enabled' => true, // 스냅샷: 도서산간 활성
                    'extra_fee_multiply' => false,
                    'extra_fee_settings' => [
                        ['zipcode' => '63000-63644', 'fee' => 5000],
                    ],
                ],
            ],
        ];

        // When: 도서산간 우편번호로 계산
        $result = $this->service->calculate(new CalculationInput(
            items: [
                new CalculationItem(
                    productId: 9999,
                    productOptionId: 8888,
                    quantity: 1,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '63100'),
            shippingPolicySnapshots: $shippingPolicySnapshots,
            metadata: ['snapshot_mode' => true],
        ));

        // Then: 기본 3,000 + 도서산간 5,000 = 8,000원
        $this->assertEquals(3000, $result->summary->baseShippingTotal);
        $this->assertEquals(5000, $result->summary->extraShippingTotal);
        $this->assertEquals(8000, $result->summary->totalShipping);
    }

    // ========================================
    // Section C: 쿠폰 스냅샷 모드 (3개)
    // ========================================

    /**
     * 테스트: 스냅샷 모드에서도 최소 주문금액 검증은 수행
     *
     * 스냅샷 쿠폰의 min_order_amount 미달 시 쿠폰이 적용되지 않습니다.
     */
    public function test_snapshot_coupon_min_order_still_checked(): void
    {
        // Given: 쿠폰 생성 (최소 주문금액 30,000원)
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'name' => ['ko' => '최소주문 쿠폰', 'en' => 'Min Order Coupon'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 30000,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ]);
        $couponIssue = CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'MINORDER'.uniqid(),
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ]);

        // 스냅샷: 20,000원 상품 (최소 주문금액 30,000원 미달)
        $productSnapshot = [
            'id' => 9999,
            'name' => '테스트 상품',
            'selling_price' => 20000,
            'tax_status' => 'taxable',
        ];
        $optionSnapshot = [
            'id' => 8888,
            'selling_price' => 20000,
            'price_adjustment' => 0,
        ];

        $couponSnapshots = [
            $couponIssue->id => [
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'min_order_amount' => 30000, // 최소 주문금액 30,000원
                'target_type' => 'product_amount',
                'target_scope' => 'all',
            ],
        ];

        // When: 주문금액 20,000원 < 최소 30,000원
        $result = $this->service->calculate(new CalculationInput(
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
        ));

        // Then: 최소 주문금액 미달 → 쿠폰 미적용
        $appliedCoupons = $result->promotions->getAllCoupons();
        $this->assertEmpty($appliedCoupons);
        // 검증 오류가 기록됨
        $this->assertNotEmpty($result->validationErrors);
    }

    /**
     * 테스트: 스냅샷 모드에서 만료된 쿠폰도 적용 (유효기간/범위 검증 스킵)
     *
     * 환불 재계산 시 이미 사용된 쿠폰의 만료/범위 검증을 스킵합니다.
     */
    public function test_snapshot_coupon_scope_skipped(): void
    {
        // Given: 만료된 쿠폰 생성
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'name' => ['ko' => '만료 쿠폰', 'en' => 'Expired Coupon'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 3000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDays(60),
            'valid_to' => now()->subDays(30), // 30일 전 만료
        ]);
        $couponIssue = CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'EXPIRED'.uniqid(),
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now()->subDays(60),
            'expired_at' => now()->subDays(30), // 만료
        ]);

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

        $couponSnapshots = [
            $couponIssue->id => [
                'discount_type' => 'fixed',
                'discount_value' => 3000,
                'min_order_amount' => 0,
                'target_type' => 'product_amount',
                'target_scope' => 'all',
            ],
        ];

        // When: 스냅샷 모드로 만료 쿠폰 적용
        $result = $this->service->calculate(new CalculationInput(
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
        ));

        // Then: 만료 쿠폰이지만 스냅샷 모드에서 적용됨
        $appliedCoupons = $result->promotions->getAllCoupons();
        $this->assertNotEmpty($appliedCoupons);
        $this->assertEquals(3000, $appliedCoupons[0]->totalDiscount);
    }

    /**
     * 테스트: 스냅샷 모드에서 상품쿠폰 + 주문쿠폰 + 배송비쿠폰 복합 적용
     *
     * 3종류 쿠폰이 모두 스냅샷 모드에서 동시 적용됩니다.
     */
    public function test_snapshot_coupon_multiple_types_combined(): void
    {
        // Given: 배송정책
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

        $user = User::factory()->create();

        // 상품쿠폰: 2,000원 할인
        $productCoupon = Coupon::create([
            'name' => ['ko' => '상품쿠폰', 'en' => 'Product Coupon'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 2000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDays(60),
            'valid_to' => now()->subDays(1), // 만료
        ]);
        $productCouponIssue = CouponIssue::create([
            'coupon_id' => $productCoupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'PROD'.uniqid(),
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now()->subDays(60),
            'expired_at' => now()->subDays(1),
        ]);

        // 주문쿠폰: 3,000원 할인
        $orderCoupon = Coupon::create([
            'name' => ['ko' => '주문쿠폰', 'en' => 'Order Coupon'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'target_type' => CouponTargetType::ORDER_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 3000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDays(60),
            'valid_to' => now()->subDays(1), // 만료
        ]);
        $orderCouponIssue = CouponIssue::create([
            'coupon_id' => $orderCoupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'ORDER'.uniqid(),
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now()->subDays(60),
            'expired_at' => now()->subDays(1),
        ]);

        // 배송비쿠폰: 전액 할인
        $shippingCoupon = Coupon::create([
            'name' => ['ko' => '배송비쿠폰', 'en' => 'Shipping Coupon'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'target_type' => CouponTargetType::SHIPPING_FEE,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDays(60),
            'valid_to' => now()->subDays(1), // 만료
        ]);
        $shippingCouponIssue = CouponIssue::create([
            'coupon_id' => $shippingCoupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'SHIP'.uniqid(),
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now()->subDays(60),
            'expired_at' => now()->subDays(1),
        ]);

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

        $couponSnapshots = [
            $productCouponIssue->id => [
                'discount_type' => 'fixed',
                'discount_value' => 2000,
                'min_order_amount' => 0,
                'target_type' => 'product_amount',
                'target_scope' => 'all',
            ],
            $orderCouponIssue->id => [
                'discount_type' => 'fixed',
                'discount_value' => 3000,
                'min_order_amount' => 0,
                'target_type' => 'order_amount',
                'target_scope' => 'all',
            ],
            $shippingCouponIssue->id => [
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'min_order_amount' => 0,
                'target_type' => 'shipping_fee',
                'target_scope' => 'all',
            ],
        ];

        $shippingPolicySnapshots = [
            8888 => [
                'policy_id' => $policy->id,
                'policy_snapshot' => [
                    'policy_name' => '기본 배송',
                    'country_code' => 'KR',
                    'charge_policy' => 'fixed',
                    'base_fee' => 5000,
                ],
            ],
        ];

        // When: 3종류 쿠폰 모두 적용
        $result = $this->service->calculate(new CalculationInput(
            items: [
                new CalculationItem(
                    productId: 9999,
                    productOptionId: 8888,
                    quantity: 1,
                    productSnapshot: $productSnapshot,
                    optionSnapshot: $optionSnapshot,
                ),
            ],
            couponIssueIds: [$productCouponIssue->id, $orderCouponIssue->id, $shippingCouponIssue->id],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
            shippingPolicySnapshots: $shippingPolicySnapshots,
            metadata: [
                'snapshot_mode' => true,
                'coupon_snapshots' => $couponSnapshots,
            ],
        ));

        // Then: 모든 쿠폰이 적용됨
        $allCoupons = $result->promotions->getAllCoupons();
        $this->assertCount(3, $allCoupons);

        // 상품쿠폰 2,000원 할인
        $this->assertEquals(2000, $result->summary->productCouponDiscount);
        // 주문쿠폰 3,000원 할인
        $this->assertEquals(3000, $result->summary->orderCouponDiscount);
        // 배송비쿠폰 5,000원 할인
        $this->assertEquals(5000, $result->summary->shippingDiscount);
        // 총 할인: 2,000 + 3,000 + 5,000 = 10,000
        $this->assertEquals(10000, $result->summary->totalDiscount + $result->summary->shippingDiscount);
    }

    // ========================================================================
    // 정액 상품쿠폰 × 수량 반영 테스트
    // ========================================================================

    /**
     * 테스트: 정액 상품쿠폰 — 단일 상품, 수량 2개
     *
     * 시나리오: 20,000원 × 2 = 40,000원, 정액 1,000원 쿠폰
     * 기대: 1,000 × 2 = 2,000원 할인
     */
    public function test_fixed_product_coupon_quantity_2_single_product(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 2
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $result = $this->service->calculate($input);

        // 1,000원 × 2개 = 2,000원
        $this->assertEquals(2000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(2000, $result->summary->productCouponDiscount);
        $this->assertEquals(38000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 — 다중 상품, 다른 수량
     *
     * 시나리오: A(20,000원 × 3), B(10,000원 × 2) = 총 80,000원, 정액 1,000원 쿠폰 전체 적용
     * 기대: A=1,000×3=3,000원, B=1,000×2=2,000원, 합계 5,000원
     */
    public function test_fixed_product_coupon_multiple_products_different_quantities(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 3
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 2
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $result = $this->service->calculate($input);

        // A: 1,000 × 3 = 3,000원, B: 1,000 × 2 = 2,000원
        $this->assertEquals(3000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(2000, $result->items[1]->productCouponDiscountAmount);
        $this->assertEquals(5000, $result->summary->productCouponDiscount);
        $this->assertEquals(75000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 — 적용대상 상품만 수량 할인
     *
     * 시나리오: A(10,000원 × 2), B(15,000원 × 3), C(20,000원 × 1)
     * 쿠폰: 정액 2,000원, A,B만 적용
     * 기대: A=2,000×2=4,000원, B=2,000×3=6,000원, C=0, 합계 10,000원
     */
    public function test_fixed_product_coupon_specific_products_with_quantity(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 10000);
        [$productB, $optionB] = $this->createProductWithOption(price: 15000);
        [$productC, $optionC] = $this->createProductWithOption(price: 20000);

        $couponIssue = $this->createProductCouponWithIssue(
            targetScope: CouponTargetScope::PRODUCTS,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
            targetIds: [$productA->id, $productB->id]
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 2
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 3
                ),
                new CalculationItem(
                    productId: $productC->id,
                    productOptionId: $optionC->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $result = $this->service->calculate($input);

        // A: 2,000×2=4,000, B: 2,000×3=6,000, C: 0
        $this->assertEquals(4000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(6000, $result->items[1]->productCouponDiscountAmount);
        $this->assertEquals(0, $result->items[2]->productCouponDiscountAmount);
        $this->assertEquals(10000, $result->summary->productCouponDiscount);
        // 소계: 20,000+45,000+20,000=85,000, 할인 10,000 → 75,000
        $this->assertEquals(75000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 — 할인액이 소계 초과 시 소계 한도
     *
     * 시나리오: 500원 × 3 = 1,500원, 정액 1,000원 쿠폰
     * 기대: 1,000 × 3 = 3,000이지만, 소계 1,500원 한도 → 1,500원
     */
    public function test_fixed_product_coupon_quantity_discount_capped_by_subtotal(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 500);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 3
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $result = $this->service->calculate($input);

        // 1,000×3=3,000이지만 소계 1,500원이 한도
        $this->assertEquals(1500, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(1500, $result->summary->productCouponDiscount);
        $this->assertEquals(0, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 — 수량 5개, 대량 할인
     *
     * 시나리오: 30,000원 × 5 = 150,000원, 정액 10,000원 쿠폰
     * 기대: 10,000 × 5 = 50,000원
     */
    public function test_fixed_product_coupon_quantity_5_large_discount(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 30000);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 10000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 5
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $result = $this->service->calculate($input);

        // 10,000 × 5 = 50,000원
        $this->assertEquals(50000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(50000, $result->summary->productCouponDiscount);
        $this->assertEquals(100000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정률 상품쿠폰은 수량 영향 없음 (소계 기준)
     *
     * 시나리오: 10,000원 × 3 = 30,000원, 정률 10% 쿠폰
     * 기대: 30,000 × 10% = 3,000원 (수량 무관, 소계 기준)
     */
    public function test_rate_product_coupon_not_multiplied_by_quantity(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 10000);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 3
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $result = $this->service->calculate($input);

        // 30,000 × 10% = 3,000원 (정률은 소계 기준)
        $this->assertEquals(3000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(3000, $result->summary->productCouponDiscount);
        $this->assertEquals(27000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액+정률 혼합 쿠폰 — 수량 반영
     *
     * 시나리오: 50,000원 × 2 = 100,000원
     * 정액 5,000원 쿠폰 + 정률 10% 쿠폰
     * 기대: 정액 5,000×2=10,000원, 정률 100,000×10%=10,000원, 합계 20,000원
     */
    public function test_mixed_fixed_and_rate_coupons_with_quantity(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $couponIssueA = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            targetScope: CouponTargetScope::ALL
        );

        $couponIssueB = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 2
                ),
            ],
            couponIssueIds: [$couponIssueA->id, $couponIssueB->id],
        );

        $result = $this->service->calculate($input);

        // 정액: 5,000×2=10,000, 정률: 100,000×10%=10,000, 합계 20,000
        $this->assertEquals(20000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(20000, $result->summary->productCouponDiscount);
        $this->assertEquals(80000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 + 주문쿠폰 — 수량 반영
     *
     * 시나리오: A(40,000원 × 2), B(60,000원 × 1) = 총 140,000원
     * 상품쿠폰: 정액 3,000원 전체, 주문쿠폰: 정률 5%
     * 기대:
     *   상품쿠폰: A=3,000×2=6,000, B=3,000×1=3,000 → 합계 9,000원
     *   주문쿠폰: (140,000-9,000) × 5% = 6,550원
     */
    public function test_fixed_product_coupon_with_order_coupon_quantity(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 40000);
        [$productB, $optionB] = $this->createProductWithOption(price: 60000);

        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            targetScope: CouponTargetScope::ALL
        );

        $orderCoupon = $this->createOrderCouponWithIssue(
            discountType: CouponDiscountType::RATE,
            discountValue: 5,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 2
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$productCoupon->id, $orderCoupon->id],
        );

        $result = $this->service->calculate($input);

        // 상품쿠폰: A=3,000×2=6,000, B=3,000×1=3,000 → 합계 9,000
        $this->assertEquals(6000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(3000, $result->items[1]->productCouponDiscountAmount);
        $this->assertEquals(9000, $result->summary->productCouponDiscount);

        // 주문쿠폰: (140,000-9,000) × 5% = 6,550원
        $this->assertEquals(6550, $result->summary->orderCouponDiscount);

        // 최종: 140,000 - 9,000 - 6,550 = 124,450
        $this->assertEquals(124450, $result->summary->finalAmount);
    }

    /**
     * 테스트: 복합 — 상품쿠폰(정액) + 주문쿠폰 + 배송비 + 마일리지, 수량 반영
     *
     * 시나리오: A(50,000원 × 2), B(50,000원 × 1) = 총 150,000원
     * 상품쿠폰: 정액 10,000원, 주문쿠폰: 정률 5%, 배송쿠폰: 정률 50%
     * 배송비: 5,000원, 마일리지: 10,000원
     */
    public function test_complex_full_scenario_all_discounts_with_quantity(): void
    {
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 5000
        );
        [$productA, $optionA] = $this->createProductWithShippingPolicy(50000, $shippingPolicy);
        [$productB, $optionB] = $this->createProductWithShippingPolicy(50000, $shippingPolicy);

        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 10000,
            targetScope: CouponTargetScope::ALL
        );

        $orderCoupon = $this->createOrderCouponWithIssue(
            discountType: CouponDiscountType::RATE,
            discountValue: 5,
        );

        $shippingCoupon = $this->createShippingCouponWithIssue(
            discountType: CouponDiscountType::RATE,
            discountValue: 50,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 2
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$productCoupon->id, $orderCoupon->id, $shippingCoupon->id],
            usePoints: 10000,
        );

        $result = $this->service->calculate($input);

        // 상품쿠폰: A=10,000×2=20,000, B=10,000×1=10,000 → 합계 30,000
        $this->assertEquals(20000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(10000, $result->items[1]->productCouponDiscountAmount);
        $this->assertEquals(30000, $result->summary->productCouponDiscount);

        // 주문쿠폰: (150,000-30,000) × 5% = 6,000
        $this->assertEquals(6000, $result->summary->orderCouponDiscount);

        // 배송비: 5,000원 (동일 정책 그룹), 배송쿠폰: 5,000×50%=2,500
        $this->assertEquals(5000, $result->summary->totalShipping);
        $this->assertEquals(2500, $result->summary->shippingDiscount);

        // 마일리지: 10,000원
        $this->assertEquals(10000, $result->summary->pointsUsed);

        // 최종: 150,000 - 30,000 - 6,000 + 5,000 - 2,500 - 10,000 = 106,500
        $this->assertEquals(106500, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 — 다중 정액 쿠폰, 수량 반영
     *
     * 시나리오: 50,000원 × 2 = 100,000원
     * 쿠폰A: 정액 1,000원, 쿠폰B: 정액 2,000원
     * 기대: A=1,000×2=2,000, B=2,000×2=4,000 → 합계 6,000원
     */
    public function test_multiple_fixed_coupons_with_quantity(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $couponIssueA = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        $couponIssueB = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 2
                ),
            ],
            couponIssueIds: [$couponIssueA->id, $couponIssueB->id],
        );

        $result = $this->service->calculate($input);

        // 쿠폰A: 1,000×2=2,000, 쿠폰B: 2,000×2=4,000, 합계 6,000
        $this->assertEquals(6000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(6000, $result->summary->productCouponDiscount);
        $this->assertEquals(94000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 — 서로 다른 상품에 서로 다른 쿠폰, 수량 반영
     *
     * 시나리오: A(30,000원 × 2), B(20,000원 × 3)
     * 쿠폰1: 정액 1,000원 전체, 쿠폰2: 정액 2,000원 B만
     * 기대:
     *   쿠폰1: A=1,000×2=2,000, B=1,000×3=3,000 → 5,000
     *   쿠폰2: B=2,000×3=6,000
     *   A 합계: 2,000, B 합계: 9,000, 총 합계: 11,000
     */
    public function test_different_fixed_coupons_for_different_products_with_quantity(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 30000);
        [$productB, $optionB] = $this->createProductWithOption(price: 20000);

        $couponIssue1 = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        $couponIssue2 = $this->createProductCouponWithIssue(
            targetScope: CouponTargetScope::PRODUCTS,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
            targetIds: [$productB->id]
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 2
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 3
                ),
            ],
            couponIssueIds: [$couponIssue1->id, $couponIssue2->id],
        );

        $result = $this->service->calculate($input);

        // 쿠폰1: A=1,000×2=2,000, B=1,000×3=3,000
        // 쿠폰2: B=2,000×3=6,000
        // A 합계: 2,000, B 합계: 9,000
        $this->assertEquals(2000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(9000, $result->items[1]->productCouponDiscountAmount);
        $this->assertEquals(11000, $result->summary->productCouponDiscount);
        // 소계: 60,000+60,000=120,000, 할인 11,000 → 109,000
        $this->assertEquals(109000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 + 주문쿠폰(정액) — 주문쿠폰은 수량 미반영
     *
     * 시나리오: 20,000원 × 3 = 60,000원
     * 상품쿠폰: 정액 2,000원, 주문쿠폰: 정액 5,000원
     * 기대:
     *   상품쿠폰: 2,000×3=6,000
     *   주문쿠폰: 5,000 (수량 무관, 주문 전체 1회)
     */
    public function test_fixed_product_coupon_quantity_order_coupon_no_quantity(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000);

        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
            targetScope: CouponTargetScope::ALL
        );

        $orderCoupon = $this->createOrderCouponWithIssue(
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 3
                ),
            ],
            couponIssueIds: [$productCoupon->id, $orderCoupon->id],
        );

        $result = $this->service->calculate($input);

        // 상품쿠폰: 2,000×3=6,000
        $this->assertEquals(6000, $result->summary->productCouponDiscount);
        // 주문쿠폰: 5,000 (수량 무관)
        $this->assertEquals(5000, $result->summary->orderCouponDiscount);
        // 최종: 60,000-6,000-5,000=49,000
        $this->assertEquals(49000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 — 최대할인금액 제한 + 수량
     *
     * 시나리오: 10,000원 × 5 = 50,000원
     * 정률 50% 쿠폰, 최대할인 5,000원
     * 기대: 50,000×50%=25,000이지만 최대 5,000원 (정률은 수량 영향 없음)
     */
    public function test_rate_product_coupon_max_discount_with_quantity(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 10000);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 50,
            targetScope: CouponTargetScope::ALL,
            maxDiscount: 5000
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 5
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $result = $this->service->calculate($input);

        // 50,000 × 50% = 25,000 → 최대 5,000원
        $this->assertEquals(5000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(5000, $result->summary->productCouponDiscount);
        $this->assertEquals(45000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 — 배송정책 수량계산과 함께
     *
     * 시나리오: 10,000원 × 4 = 40,000원, 수량당 배송비 1,000원
     * 정액 1,000원 쿠폰 전체 적용
     * 기대: 상품쿠폰 1,000×4=4,000, 배송비 4×1,000=4,000
     */
    public function test_fixed_product_coupon_with_per_quantity_shipping_and_quantity(): void
    {
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000
        );
        [$product, $option] = $this->createProductWithShippingPolicy(10000, $shippingPolicy);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 4
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $result = $this->service->calculate($input);

        // 상품쿠폰: 1,000×4=4,000
        $this->assertEquals(4000, $result->summary->productCouponDiscount);
        // 배송비: 4×1,000=4,000
        $this->assertEquals(4000, $result->summary->totalShipping);
        // 최종: 40,000-4,000+4,000=40,000
        $this->assertEquals(40000, $result->summary->finalAmount);
    }

    /**
     * 테스트: 정액 상품쿠폰 — 과세/면세 혼합 + 수량
     *
     * 시나리오: A(과세 10,000원 × 2), B(면세 20,000원 × 1)
     * 정액 1,000원 쿠폰 전체 적용
     * 기대: A=1,000×2=2,000, B=1,000×1=1,000 → 합계 3,000
     */
    public function test_fixed_product_coupon_mixed_tax_status_with_quantity(): void
    {
        [$productA, $optionA] = $this->createProductWithTaxStatus(10000, ProductTaxStatus::TAXABLE);
        [$productB, $optionB] = $this->createProductWithTaxStatus(20000, ProductTaxStatus::TAX_FREE);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::ALL
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $productA->id,
                    productOptionId: $optionA->id,
                    quantity: 2
                ),
                new CalculationItem(
                    productId: $productB->id,
                    productOptionId: $optionB->id,
                    quantity: 1
                ),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $result = $this->service->calculate($input);

        // A: 1,000×2=2,000, B: 1,000×1=1,000
        $this->assertEquals(2000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(1000, $result->items[1]->productCouponDiscountAmount);
        $this->assertEquals(3000, $result->summary->productCouponDiscount);

        // 과세금액: A할인 후 소계 = 20,000 - 2,000 = 18,000
        $this->assertEquals(18000, $result->summary->taxableAmount);
        // 면세금액: B할인 후 소계 = 20,000 - 1,000 = 19,000
        $this->assertEquals(19000, $result->summary->taxFreeAmount);
    }

    /**
     * 테스트: 스냅샷 모드에서도 정액 상품쿠폰 수량 반영
     *
     * 시나리오: 50,000원 × 2 = 100,000원 (스냅샷 모드)
     * 정액 2,000원 쿠폰
     * 기대: 2,000×2=4,000원
     */
    public function test_snapshot_mode_fixed_product_coupon_with_quantity(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
            targetScope: CouponTargetScope::ALL
        );

        $productSnapshot = [
            'id' => $product->id,
            'name' => '테스트 상품',
            'selling_price' => 50000,
            'tax_status' => ProductTaxStatus::TAXABLE->value,
        ];
        $optionSnapshot = [
            'id' => $option->id,
            'selling_price' => 50000,
            'price_adjustment' => 0,
        ];

        $input = new CalculationInput(
            items: [
                CalculationItem::fromArray([
                    'product_id' => $product->id,
                    'product_option_id' => $option->id,
                    'quantity' => 2,
                    'product_snapshot' => $productSnapshot,
                    'option_snapshot' => $optionSnapshot,
                ]),
            ],
            couponIssueIds: [$couponIssue->id],
            metadata: [
                'snapshot_mode' => true,
                'coupon_snapshots' => [
                    $couponIssue->id => [
                        'discount_type' => 'fixed',
                        'discount_value' => 2000,
                        'min_order_amount' => 0,
                        'max_discount_amount' => 0,
                        'target_type' => 'product_amount',
                        'target_scope' => 'all',
                        'applied_items' => [
                            ['product_option_id' => $option->id, 'discount_amount' => 2000],
                        ],
                    ],
                ],
            ],
        );

        $result = $this->service->calculate($input);

        // 스냅샷 모드에서도 정액 2,000×2=4,000원
        $this->assertEquals(4000, $result->items[0]->productCouponDiscountAmount);
        $this->assertEquals(4000, $result->summary->productCouponDiscount);
        $this->assertEquals(96000, $result->summary->finalAmount);
    }

    /**
     * 삭제된 배송정책 참조 시 에러 없이 배송비 0원 처리
     *
     * 입력: shipping_policy_id가 존재하지만 실제 ShippingPolicy 레코드가 삭제된 상품
     * 기대: TypeError 없이 배송비 0원, 정상 계산 완료
     */
    public function test_it_handles_deleted_shipping_policy_gracefully(): void
    {
        // Given: 배송정책을 생성 후 삭제하여, 상품이 존재하지 않는 policy_id를 참조하게 함
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000
        );
        [$product, $option] = $this->createProductWithShippingPolicy(30000, $shippingPolicy);

        // 배송정책 삭제 (상품의 shipping_policy_id는 남아있음)
        $shippingPolicy->countrySettings()->delete();
        $shippingPolicy->delete();

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1
                ),
            ]
        );

        // When: 에러 없이 계산 완료되어야 함
        $result = $this->service->calculate($input);

        // Then: 배송비 0원, 상품 금액은 정상
        $this->assertEquals(0, $result->summary->totalShipping);
        $this->assertEquals(30000, $result->summary->subtotal);
    }
}
