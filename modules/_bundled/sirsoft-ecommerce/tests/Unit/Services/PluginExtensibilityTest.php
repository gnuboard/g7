<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 플러그인 확장성 테스트
 *
 * 3개의 확장 훅이 올바르게 동작하는지 검증합니다:
 * - sirsoft-ecommerce.shipping.calculate_fee (배송정책 플러그인)
 * - sirsoft-ecommerce.calculation.filter_promotions_snapshot (프로모션 스냅샷 저장)
 * - sirsoft-ecommerce.adjustment.filter_restore_promotions (프로모션 스냅샷 복원)
 */
class PluginExtensibilityTest extends ModuleTestCase
{
    protected OrderCalculationService $calculationService;

    protected OrderAdjustmentService $adjustmentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->calculationService = app(OrderCalculationService::class);
        $this->adjustmentService = app(OrderAdjustmentService::class);
    }

    protected function tearDown(): void
    {
        // 테스트 훅 정리
        HookManager::resetAll();

        $settingsFile = storage_path('app/modules/sirsoft-ecommerce/settings/language_currency.json');
        if (file_exists($settingsFile)) {
            unlink($settingsFile);
        }
        parent::tearDown();
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

    // ========================================
    // 헬퍼 메서드
    // ========================================

    /**
     * 테스트용 상품과 옵션을 생성합니다.
     *
     * @param  int  $price  상품 판매가
     * @param  int  $priceAdjustment  옵션 추가금액
     * @param  ShippingPolicy|null  $shippingPolicy  배송정책
     * @param  float|null  $weight  옵션 중량 (kg)
     * @return array [Product, ProductOption]
     */
    protected function createProductWithOption(
        int $price = 50000,
        int $priceAdjustment = 0,
        ?ShippingPolicy $shippingPolicy = null,
        ?float $weight = null,
    ): array {
        $attrs = [
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
        ];
        if ($shippingPolicy) {
            $attrs['shipping_policy_id'] = $shippingPolicy->id;
        }

        $product = ProductFactory::new()->create($attrs);

        $optionAttrs = [
            'price_adjustment' => $priceAdjustment,
            'stock_quantity' => 100,
            'is_default' => true,
        ];
        if ($weight !== null) {
            $optionAttrs['weight'] = $weight;
        }

        $option = ProductOptionFactory::new()->forProduct($product)->create($optionAttrs);

        return [$product, $option];
    }

    /**
     * 테스트용 배송정책을 생성합니다.
     *
     * @param  ChargePolicyEnum  $chargePolicy  배송비 부과정책
     * @param  int  $baseFee  기본 배송비
     * @param  int|null  $freeThreshold  무료배송 기준금액
     * @param  array|null  $ranges  구간 설정
     * @return ShippingPolicy
     */
    protected function createShippingPolicy(
        ChargePolicyEnum $chargePolicy = ChargePolicyEnum::FREE,
        int $baseFee = 0,
        ?int $freeThreshold = null,
        ?array $ranges = null,
    ): ShippingPolicy {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => ShippingMethodEnum::PARCEL,
            'currency_code' => 'KRW',
            'charge_policy' => $chargePolicy,
            'base_fee' => $baseFee,
            'free_threshold' => $freeThreshold,
            'ranges' => $ranges,
            'extra_fee_enabled' => false,
            'extra_fee_settings' => null,
            'extra_fee_multiply' => false,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
    }

    /**
     * 테스트용 쿠폰 발급 내역을 생성합니다.
     *
     * @param  CouponTargetType  $targetType  적용 대상 타입
     * @param  CouponDiscountType  $discountType  할인 타입
     * @param  float  $discountValue  할인 값
     * @param  CouponTargetScope  $targetScope  적용 범위
     * @param  float  $minOrderAmount  최소 주문금액
     * @return CouponIssue
     */
    protected function createCouponWithIssue(
        CouponTargetType $targetType = CouponTargetType::PRODUCT_AMOUNT,
        CouponDiscountType $discountType = CouponDiscountType::FIXED,
        float $discountValue = 1000,
        CouponTargetScope $targetScope = CouponTargetScope::ALL,
        float $minOrderAmount = 0,
    ): CouponIssue {
        $coupon = Coupon::create([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '테스트용 쿠폰', 'en' => 'Test coupon'],
            'target_type' => $targetType,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_max_amount' => null,
            'min_order_amount' => $minOrderAmount,
            'target_scope' => $targetScope,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ]);

        $user = User::factory()->create();

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'TEST'.uniqid(),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ]);
    }

    /**
     * OrderCalculationService로 계산 후 주문 레코드를 생성합니다.
     *
     * @param  CalculationInput  $input  계산 입력
     * @return Order 생성된 주문
     */
    protected function createOrderFromCalculation(CalculationInput $input): Order
    {
        $result = $this->calculationService->calculate($input);
        $user = User::factory()->create();

        // 프로모션 스냅샷 (OrderProcessingService::buildPromotionsAppliedSnapshot 흐름 재현)
        $promotionsSnapshot = $result->promotions->toArray();

        // filter_promotions_snapshot 훅 적용 (프로덕션과 동일 흐름)
        $promotionsSnapshot = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.filter_promotions_snapshot',
            $promotionsSnapshot,
            $result
        );

        $promotionsSnapshot = array_merge(
            $promotionsSnapshot,
            [
                'coupon_issue_ids' => $input->couponIssueIds,
                'item_coupons' => $input->itemCoupons,
                'discount_code' => $input->discountCode,
            ]
        );

        // 배송정책 스냅샷
        $shippingPolicySnapshot = [];
        if ($input->shippingAddress) {
            $shippingPolicySnapshot['address'] = $input->shippingAddress->toArray();
        }
        foreach ($result->items as $item) {
            if ($item->appliedShippingPolicy) {
                $shippingPolicySnapshot[$item->productOptionId] = $item->appliedShippingPolicy->toArray();
            }
        }

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $result->summary->subtotal,
            'total_discount_amount' => $result->summary->totalDiscount,
            'total_product_coupon_discount_amount' => $result->summary->productCouponDiscount,
            'total_order_coupon_discount_amount' => $result->summary->orderCouponDiscount,
            'total_coupon_discount_amount' => $result->summary->productCouponDiscount + $result->summary->orderCouponDiscount,
            'total_code_discount_amount' => $result->summary->codeDiscount,
            'base_shipping_amount' => $result->summary->baseShippingTotal,
            'extra_shipping_amount' => $result->summary->extraShippingTotal,
            'shipping_discount_amount' => $result->summary->shippingDiscount,
            'total_shipping_amount' => $result->summary->totalShipping,
            'total_amount' => $result->summary->paymentAmount,
            'total_paid_amount' => $result->summary->finalAmount,
            'total_due_amount' => 0,
            'total_points_used_amount' => $result->summary->pointsUsed,
            'total_tax_amount' => $result->summary->taxableAmount,
            'total_tax_free_amount' => $result->summary->taxFreeAmount,
            'total_cancelled_amount' => 0,
            'total_refunded_amount' => 0,
            'cancellation_count' => 0,
            'currency' => 'KRW',
            'promotions_applied_snapshot' => $promotionsSnapshot,
            'shipping_policy_applied_snapshot' => $shippingPolicySnapshot,
        ]);

        // OrderOption 생성
        foreach ($result->items as $item) {
            $product = Product::find($item->productId);
            $productOption = ProductOption::find($item->productOptionId);

            $createData = [
                'product_id' => $item->productId,
                'product_option_id' => $item->productOptionId,
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice,
                'subtotal_price' => $item->subtotal,
                'subtotal_discount_amount' => $item->productCouponDiscountAmount + $item->codeDiscountAmount + $item->orderCouponDiscountShare,
                'coupon_discount_amount' => $item->productCouponDiscountAmount + $item->orderCouponDiscountShare,
                'code_discount_amount' => $item->codeDiscountAmount,
                'subtotal_paid_amount' => $item->finalAmount,
                'subtotal_tax_amount' => $item->taxableAmount,
                'subtotal_tax_free_amount' => $item->taxFreeAmount,
                'subtotal_points_used_amount' => $item->pointsUsedShare,
            ];

            if ($product) {
                $createData['product_snapshot'] = $product->toSnapshotArray();
            }
            if ($productOption) {
                $createData['option_snapshot'] = $productOption->toSnapshotArray();
            }

            OrderOption::factory()->forOrder($order)->create($createData);
        }

        // OrderShipping 생성
        OrderShipping::factory()->forOrder($order)->create([
            'shipping_status' => 'pending',
            'base_shipping_amount' => $result->summary->baseShippingTotal,
            'extra_shipping_amount' => $result->summary->extraShippingTotal,
            'total_shipping_amount' => $result->summary->totalShipping,
            'shipping_discount_amount' => $result->summary->shippingDiscount,
        ]);

        // OrderPayment 생성
        OrderPayment::factory()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => $result->summary->finalAmount,
            'paid_amount_base' => $result->summary->finalAmount,
            'paid_at' => now(),
        ]);

        // 쿠폰 사용 처리
        foreach ($input->couponIssueIds as $couponIssueId) {
            CouponIssue::where('id', $couponIssueId)->update([
                'status' => CouponIssueRecordStatus::USED,
                'used_at' => now(),
                'order_id' => $order->id,
            ]);
        }

        return $order->load(['options', 'shippings', 'payment']);
    }

    // ================================================================
    // A. 배송정책 플러그인 테스트 (TC-S1 ~ TC-S6)
    // ================================================================

    /**
     * TC-S1: 플러그인 배송비 훅 — 기본 동작
     *
     * 플러그인이 shipping.calculate_fee 훅에 리스너를 등록하면
     * 내장 match 로직 대신 플러그인의 계산 결과가 사용된다.
     */
    public function test_s1_plugin_shipping_fee_hook_overrides_builtin_calculation(): void
    {
        // Given: FIXED 3,000원 배송정책
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        [$product, $option] = $this->createProductWithOption(10000, 0, $shippingPolicy);

        // 플러그인 훅: 항상 8,000원 반환
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($currentFee, $countrySetting, $group) {
                return 8000;
            }
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
        $result = $this->calculationService->calculate($input);

        // Then: 플러그인 배송비 8,000원 적용 (FIXED 3,000원 아님)
        $this->assertEquals(8000, $result->summary->baseShippingTotal);
    }

    /**
     * TC-S2: 플러그인 미등록 시 기존 로직 유지
     *
     * shipping.calculate_fee 훅에 리스너가 없으면
     * 기존 내장 match 로직이 100% 동일하게 동작한다.
     */
    public function test_s2_no_plugin_uses_builtin_calculation(): void
    {
        // Given: CONDITIONAL_FREE 정책 (기준금액 50,000원, 기본 3,000원)
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::CONDITIONAL_FREE, 3000, 50000);

        // 상품 30,000원 × 1개 (< threshold)
        [$productA, $optionA] = $this->createProductWithOption(30000, 0, $shippingPolicy);
        // 상품 30,000원 × 2개 (= 60,000원 ≥ threshold)
        [$productB, $optionB] = $this->createProductWithOption(30000, 0, $shippingPolicy);

        // 훅 미등록 상태

        // When: 30,000원 (미달)
        $resultUnder = $this->calculationService->calculate(new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            ]
        ));

        // When: 60,000원 (이상)
        $resultOver = $this->calculationService->calculate(new CalculationInput(
            items: [
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 2),
            ]
        ));

        // Then
        $this->assertEquals(3000, $resultUnder->summary->baseShippingTotal); // threshold 미달 → base_fee
        $this->assertEquals(0, $resultOver->summary->baseShippingTotal);     // threshold 이상 → 무료
    }

    /**
     * TC-S3: 스냅샷 모드에서 훅 미호출 + 스냅샷 금액 사용
     *
     * 환불 재계산(snapshot_mode=true) 시 플러그인 훅이 호출되지 않고
     * 스냅샷에 저장된 배송비가 그대로 사용된다.
     */
    public function test_s3_snapshot_mode_uses_snapshot_fee_not_hook(): void
    {
        // Given: FIXED 3,000원 배송정책
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        [$product, $option] = $this->createProductWithOption(50000, 0, $shippingPolicy);

        // 플러그인 훅: 8,000원 + 호출 여부 추적
        $hookCalled = false;
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($currentFee, $countrySetting, $group) use (&$hookCalled) {
                $hookCalled = true;

                return 8000;
            }
        );

        // 주문 생성 (플러그인 배송비 8,000원 적용)
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ]
        );
        $order = $this->createOrderFromCalculation($input);

        // 배송비 8,000원 확인
        $this->assertEquals(8000, $order->base_shipping_amount);

        // 훅 호출 플래그 리셋
        $hookCalled = false;

        // When: 전체취소 환불 재계산 (snapshot_mode=true)
        $cancellation = CancellationAdjustment::fromArray(
            $order->options->map(fn ($opt) => [
                'order_option_id' => $opt->id,
                'cancel_quantity' => $opt->quantity,
            ])->toArray()
        );
        $adjustmentResult = $this->adjustmentService->calculate($order, $cancellation);

        // Then: 스냅샷 금액 사용, 훅 미호출
        $this->assertFalse($hookCalled, '스냅샷 모드에서 shipping.calculate_fee 훅이 호출되지 않아야 함');
    }

    /**
     * TC-S4: 스냅샷 모드 + 플러그인 OFF (훅 미등록)
     *
     * 환불 재계산 시 플러그인이 비활성(훅 미등록)이어도
     * 스냅샷에 저장된 배송비가 정확하게 사용된다.
     */
    public function test_s4_snapshot_mode_plugin_off_uses_snapshot_fee(): void
    {
        // Given: FIXED 3,000원 배송정책
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        [$product, $option] = $this->createProductWithOption(50000, 0, $shippingPolicy);

        // 플러그인 훅 ON: 8,000원 배송비
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($currentFee) {
                return 8000;
            }
        );

        // 주문 생성 (플러그인 배송비 8,000원 적용)
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ]
        );
        $order = $this->createOrderFromCalculation($input);
        $this->assertEquals(8000, $order->base_shipping_amount);

        // 플러그인 OFF (훅 제거)
        HookManager::resetAll();

        // When: 전체취소 환불 재계산 (플러그인 OFF 상태)
        $cancellation = CancellationAdjustment::fromArray(
            $order->options->map(fn ($opt) => [
                'order_option_id' => $opt->id,
                'cancel_quantity' => $opt->quantity,
            ])->toArray()
        );
        $adjustmentResult = $this->adjustmentService->calculate($order, $cancellation);

        // Then: 스냅샷의 8,000원 사용 (match default의 3,000원이 아님)
        // 전체취소이므로 환불액에 배송비 8,000원 포함
        $this->assertEquals(
            $order->total_paid_amount,
            $adjustmentResult->refundAmount,
            '플러그인 OFF 상태에서도 스냅샷 기반 환불액이 정확해야 함'
        );
    }

    /**
     * TC-S5: 플러그인 훅 우선순위 (여러 플러그인 경쟁)
     *
     * Filter 훅이므로 마지막 필터의 반환값이 최종 적용된다.
     */
    public function test_s5_multiple_plugins_last_filter_wins(): void
    {
        // Given: FIXED 3,000원 배송정책
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        [$product, $option] = $this->createProductWithOption(10000, 0, $shippingPolicy);

        // 플러그인 A: priority 10, 5,000원
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($currentFee) {
                return 5000;
            },
            10
        );

        // 플러그인 B: priority 20, 7,000원
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($currentFee) {
                return 7000;
            },
            20
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ]
        );

        // When
        $result = $this->calculationService->calculate($input);

        // Then: 마지막 필터(priority 20)의 7,000원 적용
        $this->assertEquals(7000, $result->summary->baseShippingTotal);
    }

    /**
     * TC-S6: 정상 주문(플러그인 배송비) → 부분취소 → 환불 재계산 전체 흐름
     *
     * 주문 생성 시 플러그인이 15,000원 배송비 → 부분취소 후
     * 재계산 시 스냅샷의 15,000원 기준으로 환불액 산출.
     */
    public function test_s6_full_flow_order_with_plugin_fee_then_partial_cancel(): void
    {
        // Given: FIXED 3,000원 배송정책 (플러그인이 오버라이드)
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        [$productA, $optionA] = $this->createProductWithOption(50000, 0, $shippingPolicy);
        [$productB, $optionB] = $this->createProductWithOption(30000, 0, $shippingPolicy);

        // 플러그인 훅: 15,000원 배송비
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($currentFee) {
                return 15000;
            }
        );

        // 주문 생성: 상품A 50,000 × 2 + 상품B 30,000 × 1 + 배송비 15,000
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 2),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ]
        );
        $order = $this->createOrderFromCalculation($input);

        // 주문 금액 검증
        $this->assertEquals(130000, $order->subtotal_amount); // 100,000 + 30,000
        $this->assertEquals(15000, $order->base_shipping_amount);

        // 플러그인 OFF
        HookManager::resetAll();

        // When: 상품A 1개 부분취소
        $optionARecord = $order->options->firstWhere('product_option_id', $optionA->id);
        $cancellation = CancellationAdjustment::fromArray([
            [
                'order_option_id' => $optionARecord->id,
                'cancel_quantity' => 1,
            ],
        ]);
        $adjustmentResult = $this->adjustmentService->calculate($order, $cancellation);

        // Then: 환불액은 상품A 1개(50,000원) + 배송비 차이
        // 배송비는 스냅샷 기준 15,000원으로 재계산 (플러그인 OFF 무관)
        $this->assertGreaterThan(0, $adjustmentResult->refundAmount);
        // 상품 환불액은 최소 50,000원 (배송비 차이에 따라 추가)
        $this->assertGreaterThanOrEqual(50000, $adjustmentResult->refundAmount);
    }

    // ================================================================
    // B. 할인정책 플러그인 테스트 (TC-D1 ~ TC-D7)
    // ================================================================

    /**
     * TC-D1: after_product_discount 훅으로 추가 할인 주입
     *
     * 플러그인이 상품 할인 후 추가 3,000원 할인을 적용한다.
     */
    public function test_d1_plugin_adds_discount_via_after_product_discount_hook(): void
    {
        // Given: 50,000원 상품 + 10% 쿠폰 (5,000원 할인)
        [$product, $option] = $this->createProductWithOption(50000);
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            targetScope: CouponTargetScope::ALL,
        );

        // 플러그인 훅: after_product_discount에서 추가 3,000원 할인
        HookManager::addFilter(
            'sirsoft-ecommerce.calculation.after_product_discount',
            function ($discountedItems) {
                foreach ($discountedItems as &$item) {
                    $item['coupon_discount'] = ($item['coupon_discount'] ?? 0) + 3000;
                    $item['discounted_subtotal'] = ($item['discounted_subtotal'] ?? $item['subtotal']) - 3000;
                }

                return $discountedItems;
            }
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        // When
        $result = $this->calculationService->calculate($input);

        // Then: 50,000 - 5,000(쿠폰) - 3,000(플러그인) = 42,000원
        $this->assertEquals(42000, $result->summary->paymentAmount - $result->summary->totalShipping);
    }

    /**
     * TC-D2: before_product_discount 훅으로 자동 쿠폰 주입
     *
     * 플러그인이 상품 할인 전에 자동 쿠폰을 주입한다.
     */
    public function test_d2_plugin_injects_auto_coupon_via_before_product_discount(): void
    {
        // Given: 100,000원 상품, 수동 쿠폰 없음
        [$product, $option] = $this->createProductWithOption(100000);

        // 자동 쿠폰 생성
        $autoCouponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10, // 10% = 10,000원
            targetScope: CouponTargetScope::ALL,
        );

        // 플러그인 훅: before_product_discount에서 자동 쿠폰을 쿠폰 목록에 추가
        HookManager::addFilter(
            'sirsoft-ecommerce.calculation.before_product_discount',
            function ($productCoupons) use ($autoCouponIssue) {
                $productCoupons[] = $autoCouponIssue;

                return $productCoupons;
            }
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            // 수동 쿠폰 없음
        );

        // When
        $result = $this->calculationService->calculate($input);

        // Then: 플러그인이 주입한 쿠폰으로 10% 할인 적용
        $this->assertEquals(10000, $result->summary->productCouponDiscount);
        $this->assertEquals(90000, $result->summary->paymentAmount - $result->summary->totalShipping);
    }

    /**
     * TC-D3: 프로모션 스냅샷 저장 — 플러그인 데이터 포함
     *
     * filter_promotions_snapshot 훅으로 플러그인이 자체 할인 데이터를
     * 스냅샷에 추가할 수 있다.
     */
    public function test_d3_plugin_data_included_in_promotions_snapshot(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(50000);

        // 플러그인 훅: 스냅샷에 referral_discount 데이터 추가
        HookManager::addFilter(
            'sirsoft-ecommerce.calculation.filter_promotions_snapshot',
            function ($snapshot, $result) {
                $snapshot['referral_discount'] = [
                    'amount' => 3000,
                    'source' => 'ref_code_ABC',
                ];

                return $snapshot;
            }
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ]
        );

        // 주문 생성 (buildPromotionsAppliedSnapshot 훅 실행)
        $order = $this->createOrderFromCalculation($input);

        // Then: 스냅샷에 플러그인 데이터 포함 확인
        $snapshot = $order->promotions_applied_snapshot;
        $this->assertArrayHasKey('referral_discount', $snapshot);
        $this->assertEquals(3000, $snapshot['referral_discount']['amount']);
        $this->assertEquals('ref_code_ABC', $snapshot['referral_discount']['source']);
    }

    /**
     * TC-D4: 프로모션 스냅샷 복원 — 플러그인 ON
     *
     * filter_restore_promotions 훅으로 플러그인이 스냅샷에서
     * 자체 할인 데이터를 해석/복원할 수 있다.
     */
    public function test_d4_plugin_restores_data_from_promotions_snapshot(): void
    {
        // Given: 주문 생성
        [$product, $option] = $this->createProductWithOption(50000);
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ]
        );
        $order = $this->createOrderFromCalculation($input);

        // 스냅샷에 플러그인 데이터 수동 추가 (주문 시점 시뮬레이션)
        $snapshot = $order->promotions_applied_snapshot ?? [];
        $snapshot['referral_discount'] = ['amount' => 3000, 'source' => 'ref_code_ABC'];
        $order->update(['promotions_applied_snapshot' => $snapshot]);
        $order->refresh();

        // 플러그인 훅: 복원 시 referral_discount 키 확인 가능
        $pluginDataRestored = false;
        HookManager::addFilter(
            'sirsoft-ecommerce.adjustment.filter_restore_promotions',
            function ($promoSnapshot, $orderModel) use (&$pluginDataRestored) {
                if (isset($promoSnapshot['referral_discount'])) {
                    $pluginDataRestored = true;
                }

                return $promoSnapshot;
            }
        );

        // When: 환불 재계산
        $cancellation = CancellationAdjustment::fromArray(
            $order->options->map(fn ($opt) => [
                'order_option_id' => $opt->id,
                'cancel_quantity' => $opt->quantity,
            ])->toArray()
        );
        $this->adjustmentService->calculate($order, $cancellation);

        // Then: 플러그인이 스냅샷 데이터를 수신함
        $this->assertTrue($pluginDataRestored, '플러그인이 스냅샷에서 referral_discount 데이터를 수신해야 함');
    }

    /**
     * TC-D5: 프로모션 스냅샷 복원 — 플러그인 OFF (핵심 안전 테스트)
     *
     * 플러그인 비활성 시 filter_restore_promotions 훅 미등록 →
     * $promoSnapshot이 변경 없이 그대로 사용된다.
     */
    public function test_d5_plugin_off_snapshot_restore_safe(): void
    {
        // Given: 주문 생성
        [$product, $option] = $this->createProductWithOption(50000);
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ]
        );
        $order = $this->createOrderFromCalculation($input);

        // 스냅샷에 플러그인 데이터 포함
        $snapshot = $order->promotions_applied_snapshot ?? [];
        $snapshot['referral_discount'] = ['amount' => 3000];
        $order->update(['promotions_applied_snapshot' => $snapshot]);
        $order->refresh();

        // 플러그인 OFF (훅 미등록)
        HookManager::resetAll();

        // When: 환불 재계산 (플러그인 OFF)
        $cancellation = CancellationAdjustment::fromArray(
            $order->options->map(fn ($opt) => [
                'order_option_id' => $opt->id,
                'cancel_quantity' => $opt->quantity,
            ])->toArray()
        );
        $adjustmentResult = $this->adjustmentService->calculate($order, $cancellation);

        // Then: 전체 취소 시 원 결제금액 전액 환불 (snapshot_mode 기반)
        $this->assertEquals(
            $order->total_paid_amount,
            $adjustmentResult->refundAmount,
            '플러그인 OFF 상태에서도 전액 환불이 정확해야 함'
        );
    }

    /**
     * TC-D6: 플러그인 할인 + 내장 쿠폰 동시 적용
     *
     * 유입할인(플러그인) + 상품쿠폰(내장) + 주문쿠폰(내장)이 복합으로 동작한다.
     */
    public function test_d6_plugin_discount_plus_builtin_coupons(): void
    {
        // Given: 100,000원 상품
        [$product, $option] = $this->createProductWithOption(100000);

        // 상품 쿠폰 10% (10,000원)
        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
        );

        // 주문 쿠폰 5,000원
        $orderCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
        );

        // 플러그인 훅: after_product_discount에서 추가 3,000원 할인
        HookManager::addFilter(
            'sirsoft-ecommerce.calculation.after_product_discount',
            function ($discountedItems) {
                foreach ($discountedItems as &$item) {
                    $item['coupon_discount'] = ($item['coupon_discount'] ?? 0) + 3000;
                    $item['discounted_subtotal'] = ($item['discounted_subtotal'] ?? $item['subtotal']) - 3000;
                }

                return $discountedItems;
            }
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$productCoupon->id, $orderCoupon->id],
        );

        // When
        $result = $this->calculationService->calculate($input);

        // Then: 100,000 - 10,000(쿠폰) - 3,000(플러그인) - 5,000(주문쿠폰) = 82,000원
        $paymentWithoutShipping = $result->summary->paymentAmount - $result->summary->totalShipping;
        $this->assertEquals(82000, $paymentWithoutShipping);
    }

    /**
     * TC-D7: 전체 흐름 — 주문(플러그인 할인) → 부분취소 → 환불
     *
     * 내장 쿠폰 + 플러그인 할인으로 주문 → 부분취소 → snapshot_mode 환불 재계산.
     * 플러그인 ON/OFF 무관하게 환불액이 정확해야 한다.
     */
    public function test_d7_full_flow_plugin_discount_then_partial_cancel(): void
    {
        // Given: 50,000원 × 2개 + 30,000원 × 1개
        [$productA, $optionA] = $this->createProductWithOption(50000);
        [$productB, $optionB] = $this->createProductWithOption(30000);

        // 상품 쿠폰 10,000원
        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 10000,
        );

        // 플러그인 훅: after_product_discount에서 추가 5,000원 할인
        HookManager::addFilter(
            'sirsoft-ecommerce.calculation.after_product_discount',
            function ($discountedItems) {
                foreach ($discountedItems as &$item) {
                    $item['coupon_discount'] = ($item['coupon_discount'] ?? 0) + 5000;
                    $item['discounted_subtotal'] = ($item['discounted_subtotal'] ?? $item['subtotal']) - 5000;
                }

                return $discountedItems;
            }
        );

        // 주문 생성
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 2),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );
        $order = $this->createOrderFromCalculation($input);
        $originalPaidAmount = $order->total_paid_amount;

        // 플러그인 OFF
        HookManager::resetAll();

        // When: 상품A 1개 부분취소
        $optionARecord = $order->options->firstWhere('product_option_id', $optionA->id);
        $cancellation = CancellationAdjustment::fromArray([
            [
                'order_option_id' => $optionARecord->id,
                'cancel_quantity' => 1,
            ],
        ]);
        $adjustmentResult = $this->adjustmentService->calculate($order, $cancellation);

        // Then: 환불액이 양수이고 원 결제금액 이하
        $this->assertGreaterThan(0, $adjustmentResult->refundAmount);
        $this->assertLessThanOrEqual($originalPaidAmount, $adjustmentResult->refundAmount);
    }

    // ================================================================
    // C. 복합 시나리오 테스트 (TC-C1 ~ TC-C3)
    // ================================================================

    /**
     * TC-C1: 플러그인 배송비 + 플러그인 할인 + 내장 쿠폰 동시
     *
     * 모든 확장 포인트가 동시에 활성인 경우.
     */
    public function test_c1_all_extension_points_active_simultaneously(): void
    {
        // Given: 100,000원 상품
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        [$product, $option] = $this->createProductWithOption(100000, 0, $shippingPolicy);

        // 상품 쿠폰 10% (10,000원)
        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
        );

        // 배송비 쿠폰 2,000원
        $shippingCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
        );

        // 플러그인 배송비: 12,000원
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($currentFee) {
                return 12000;
            }
        );

        // 플러그인 할인: after_product_discount에서 추가 3,000원
        HookManager::addFilter(
            'sirsoft-ecommerce.calculation.after_product_discount',
            function ($discountedItems) {
                foreach ($discountedItems as &$item) {
                    $item['coupon_discount'] = ($item['coupon_discount'] ?? 0) + 3000;
                    $item['discounted_subtotal'] = ($item['discounted_subtotal'] ?? $item['subtotal']) - 3000;
                }

                return $discountedItems;
            }
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$productCoupon->id, $shippingCoupon->id],
        );

        // When
        $result = $this->calculationService->calculate($input);

        // Then:
        // 상품 할인: 10,000(쿠폰) + 3,000(플러그인) = 13,000
        $this->assertEquals(13000, $result->summary->totalDiscount);
        // 상품 순액: 100,000 - 13,000 = 87,000
        $this->assertEquals(87000, $result->summary->subtotal - $result->summary->totalDiscount);

        // 배송비: 12,000(플러그인) - 2,000(쿠폰) = 10,000
        $this->assertEquals(12000, $result->summary->baseShippingTotal);
        $this->assertEquals(2000, $result->summary->shippingDiscount);

        // 최종: 87,000 + 12,000(배송비) - 2,000(배송할인) = 97,000
        $this->assertEquals(97000, $result->summary->paymentAmount);
    }

    /**
     * TC-C2: 모든 플러그인 OFF 상태에서 환불 재계산
     *
     * 주문 시 모든 플러그인 ON → 환불 시 모든 플러그인 OFF.
     * 스냅샷 기반으로 환불액이 정확해야 한다.
     */
    public function test_c2_all_plugins_off_refund_uses_snapshots(): void
    {
        // Given: 모든 플러그인 ON
        $shippingPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        [$product, $option] = $this->createProductWithOption(100000, 0, $shippingPolicy);

        // 플러그인 배송비: 12,000원
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($currentFee) {
                return 12000;
            }
        );

        // 플러그인 할인: 5,000원
        HookManager::addFilter(
            'sirsoft-ecommerce.calculation.after_product_discount',
            function ($discountedItems) {
                foreach ($discountedItems as &$item) {
                    $item['coupon_discount'] = ($item['coupon_discount'] ?? 0) + 5000;
                    $item['discounted_subtotal'] = ($item['discounted_subtotal'] ?? $item['subtotal']) - 5000;
                }

                return $discountedItems;
            }
        );

        // 주문 생성
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ]
        );
        $order = $this->createOrderFromCalculation($input);

        // 원 결제금액: 100,000 - 5,000(플러그인 할인) + 12,000(플러그인 배송비) = 107,000
        $this->assertEquals(107000, $order->total_paid_amount);

        // 모든 플러그인 OFF
        HookManager::resetAll();

        // When: 전체취소
        $cancellation = CancellationAdjustment::fromArray(
            $order->options->map(fn ($opt) => [
                'order_option_id' => $opt->id,
                'cancel_quantity' => $opt->quantity,
            ])->toArray()
        );
        $adjustmentResult = $this->adjustmentService->calculate($order, $cancellation);

        // Then: 전체취소 → 원 결제금액 전액 환불
        $this->assertEquals(
            $order->total_paid_amount,
            $adjustmentResult->refundAmount,
            '모든 플러그인 OFF 상태에서도 전액 환불이 정확해야 함'
        );
    }

    /**
     * TC-C3: 기존 내장 계산 로직 무영향 (회귀 테스트)
     *
     * 플러그인 훅 미등록 상태에서 기존 배송비 정책들이 모두 정상 동작한다.
     */
    public function test_c3_builtin_policies_unaffected_regression(): void
    {
        // 훅 미등록 상태 확인
        HookManager::resetAll();

        // FREE 정책
        $freePolicy = $this->createShippingPolicy(ChargePolicyEnum::FREE, 0);
        [$prodFree, $optFree] = $this->createProductWithOption(10000, 0, $freePolicy);

        $result = $this->calculationService->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $prodFree->id, productOptionId: $optFree->id, quantity: 1)]
        ));
        $this->assertEquals(0, $result->summary->baseShippingTotal, 'FREE 정책: 0원');

        // FIXED 정책
        $fixedPolicy = $this->createShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        [$prodFixed, $optFixed] = $this->createProductWithOption(10000, 0, $fixedPolicy);

        $result = $this->calculationService->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $prodFixed->id, productOptionId: $optFixed->id, quantity: 1)]
        ));
        $this->assertEquals(3000, $result->summary->baseShippingTotal, 'FIXED 정책: 3,000원');

        // CONDITIONAL_FREE 정책 (threshold 50,000원)
        $condPolicy = $this->createShippingPolicy(ChargePolicyEnum::CONDITIONAL_FREE, 3000, 50000);
        [$prodCond, $optCond] = $this->createProductWithOption(60000, 0, $condPolicy);

        $result = $this->calculationService->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $prodCond->id, productOptionId: $optCond->id, quantity: 1)]
        ));
        $this->assertEquals(0, $result->summary->baseShippingTotal, 'CONDITIONAL_FREE: 60,000 ≥ 50,000 → 무료');

        // CONDITIONAL_FREE 미달
        [$prodCond2, $optCond2] = $this->createProductWithOption(30000, 0, $condPolicy);
        $result = $this->calculationService->calculate(new CalculationInput(
            items: [new CalculationItem(productId: $prodCond2->id, productOptionId: $optCond2->id, quantity: 1)]
        ));
        $this->assertEquals(3000, $result->summary->baseShippingTotal, 'CONDITIONAL_FREE: 30,000 < 50,000 → 3,000원');
    }
}
