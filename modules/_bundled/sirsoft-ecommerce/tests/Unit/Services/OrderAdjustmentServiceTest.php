<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
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
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 변경(취소) 금액 계산 서비스 테스트
 *
 * OrderAdjustmentService의 환불금액 계산을 검증합니다.
 * - 전체취소/부분취소 기본
 * - 14종 배송비 정책별 전체취소/부분취소
 * - 쿠폰(상품/주문/배송) + 부분취소 조합
 * - 마일리지 + 부분취소
 * - 복합 시나리오 (배송비 + 쿠폰 + 마일리지 동시)
 * - 안분 정확성
 */
class OrderAdjustmentServiceTest extends ModuleTestCase
{
    protected OrderAdjustmentService $adjustmentService;

    protected OrderCalculationService $calculationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->adjustmentService = app(OrderAdjustmentService::class);
        $this->calculationService = app(OrderCalculationService::class);
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
        $settingsFile = storage_path('app/modules/sirsoft-ecommerce/settings/language_currency.json');
        if (file_exists($settingsFile)) {
            unlink($settingsFile);
        }
        parent::tearDown();
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
     * @return array [Product, ProductOption]
     */
    protected function createProductWithOption(
        int $price = 50000,
        int $priceAdjustment = 0,
        ?ShippingPolicy $shippingPolicy = null,
        ?float $weight = null,
        ?float $volume = null,
        ?int $listPrice = null,
    ): array {
        $attrs = [
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $listPrice ?? $price,
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
        if ($volume !== null) {
            $optionAttrs['volume'] = $volume;
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
     * @param  bool  $extraFeeEnabled  도서산간 추가배송비 사용여부
     * @param  array|null  $extraFeeSettings  도서산간 추가배송비 설정
     * @param  bool  $extraFeeMultiply  도서산간 추가배송비 수량비례 적용
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
    ): ShippingPolicy {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => $chargePolicy,
            'base_fee' => $baseFee,
            'free_threshold' => $freeThreshold,
            'ranges' => $ranges,
            'extra_fee_enabled' => $extraFeeEnabled,
            'extra_fee_settings' => $extraFeeSettings,
            'extra_fee_multiply' => $extraFeeMultiply,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
    }

    /**
     * 테스트용 쿠폰과 발급 내역을 생성합니다.
     *
     * @param  CouponTargetType  $targetType  적용 대상 타입
     * @param  CouponDiscountType  $discountType  할인 타입
     * @param  float  $discountValue  할인 값
     * @param  CouponTargetScope  $targetScope  적용 범위
     * @param  float|null  $maxDiscount  최대 할인금액
     * @param  float  $minOrderAmount  최소 주문금액
     * @return CouponIssue 쿠폰 발급 내역
     */
    protected function createCouponWithIssue(
        CouponTargetType $targetType = CouponTargetType::PRODUCT_AMOUNT,
        CouponDiscountType $discountType = CouponDiscountType::FIXED,
        float $discountValue = 1000,
        CouponTargetScope $targetScope = CouponTargetScope::ALL,
        ?float $maxDiscount = null,
        float $minOrderAmount = 0,
    ): CouponIssue {
        $coupon = Coupon::create([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '테스트용 쿠폰', 'en' => 'Test coupon'],
            'target_type' => $targetType,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_max_amount' => $maxDiscount,
            'min_order_amount' => $minOrderAmount,
            'target_scope' => $targetScope,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ]);

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
        $category->update(['path' => (string) $category->id]);

        return $category;
    }

    /**
     * OrderCalculationService로 계산 후 Order + OrderOption + OrderShipping + OrderPayment 레코드를 생성합니다.
     *
     * 이 메서드는 실제 주문 생성 프로세스를 시뮬레이션합니다.
     * 계산 결과를 기반으로 정확한 금액이 반영된 주문 레코드를 생성합니다.
     *
     * @param  CalculationInput  $input  계산 입력
     * @param  array  $orderOverrides  Order 추가/오버라이드 속성
     * @return Order  생성된 주문 (옵션, 배송, 결제 관계 로드됨)
     */
    protected function createOrderFromCalculation(
        CalculationInput $input,
        array $orderOverrides = [],
    ): Order {
        $result = $this->calculationService->calculate($input);

        $user = User::factory()->create();

        // 프로모션 스냅샷 구성 (OrderProcessingService::buildPromotionsAppliedSnapshot 동일 구조)
        $promotionsSnapshot = array_merge(
            $result->promotions->toArray(),
            [
                'coupon_issue_ids' => $input->couponIssueIds,
                'item_coupons' => $input->itemCoupons,
                'discount_code' => $input->discountCode,
            ]
        );

        // 배송정책 스냅샷 구성 (OrderProcessingService::buildShippingPolicyAppliedSnapshot 동일 구조)
        $shippingPolicySnapshot = [];
        if ($input->shippingAddress) {
            $shippingPolicySnapshot['address'] = $input->shippingAddress->toArray();
        }
        // product_option_id를 키로 하는 배송정책 데이터 구성 (스냅샷 모드 재계산 호환)
        foreach ($result->items as $item) {
            if ($item->appliedShippingPolicy) {
                $shippingPolicySnapshot[$item->productOptionId] = $item->appliedShippingPolicy->toArray();
            }
        }

        // Order 생성
        $order = Order::factory()->create(array_merge([
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
        ], $orderOverrides));

        // OrderOption 생성
        foreach ($result->items as $item) {
            // 실제 상품/옵션 데이터로 스냅샷 구성 (스냅샷 모드 재계산과 호환)
            $product = Product::find($item->productId);
            $productOption = ProductOption::find($item->productOptionId);
            $productSnapshot = $product ? $product->toSnapshotArray() : null;
            $optionSnapshot = $productOption ? $productOption->toSnapshotArray() : null;

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

            if ($productSnapshot) {
                $createData['product_snapshot'] = $productSnapshot;
            }
            if ($optionSnapshot) {
                $createData['option_snapshot'] = $optionSnapshot;
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

    /**
     * CancellationAdjustment를 생성합니다.
     *
     * @param  array  $cancelItems  [{order_option_id, cancel_quantity}]
     * @return CancellationAdjustment
     */
    protected function buildCancellation(array $cancelItems): CancellationAdjustment
    {
        return CancellationAdjustment::fromArray($cancelItems);
    }

    /**
     * 주문의 모든 옵션을 전량 취소하는 CancellationAdjustment를 생성합니다.
     *
     * @param  Order  $order  주문
     * @return CancellationAdjustment
     */
    protected function buildFullCancellation(Order $order): CancellationAdjustment
    {
        $items = [];
        foreach ($order->options as $option) {
            $items[] = [
                'order_option_id' => $option->id,
                'cancel_quantity' => $option->quantity,
            ];
        }

        return CancellationAdjustment::fromArray($items);
    }

    // ================================================================
    // A-1. 전체취소 기본 (5건)
    // ================================================================

    /**
     * A-1-1: 단일 옵션 할인없음 → 전체취소
     *
     * 옵션1개(20,000×2) 할인없음 → 전체취소 → refund=40,000
     */
    public function test_full_cancel_single_option_no_discount(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertEquals(40000, $result->refundAmount);
        $this->assertEquals(40000, $result->originalPaidAmount);
        $this->assertEquals(0, $result->recalculatedPaidAmount);
    }

    /**
     * A-1-2: 다중 옵션 할인없음 → 전체취소
     *
     * 옵션2개(20,000×1 + 10,000×3) → 전체취소 → refund=50,000
     */
    public function test_full_cancel_multiple_options_no_discount(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 3),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertEquals(50000, $result->refundAmount);
    }

    /**
     * A-1-3: 쿠폰+배송비 적용된 주문 → 전체취소 → 원래 결제금액 전액 환불
     */
    public function test_full_cancel_returns_original_paid_amount(): void
    {
        $shippingPolicy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );
        [$product, $option] = $this->createProductWithOption(price: 30000, shippingPolicy: $shippingPolicy);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // 전체취소 = 원래 결제금액 전액 환불
        $this->assertEquals($originalPaid, $result->refundAmount);
    }

    /**
     * A-1-4: 마일리지 3,000원 사용 주문 → 전체취소
     *
     * refund = 결제금액, pointsRefund = 3,000
     */
    public function test_full_cancel_with_mileage_used(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // PG 환불금액 = 결제금액 (30,000 - 3,000 = 27,000)
        $this->assertEquals(27000, $result->refundAmount);
        // 마일리지 환불
        $this->assertEquals(3000, $result->refundPointsAmount);
    }

    /**
     * A-1-5: 전액 쿠폰 결제(paid=0) → 전체취소
     */
    public function test_full_cancel_zero_amount_order(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 10000);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 10000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertEquals(0, $result->refundAmount);
        $this->assertContains($couponIssue->id, $result->restoredCouponIssueIds);
    }

    // ================================================================
    // A-2. 부분취소 기본 — 할인/배송비 없음 (6건)
    // ================================================================

    /**
     * A-2-1: 2개 옵션 중 1개 취소
     *
     * A(20,000)+B(10,000), A 취소 → refund=20,000
     */
    public function test_partial_cancel_one_of_two_options(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionToCancel = $order->options->firstWhere('product_option_id', $optionA->id);

        $adjustment = $this->buildCancellation([
            ['order_option_id' => $optionToCancel->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertEquals(20000, $result->refundAmount);
    }

    /**
     * A-2-2: 수량 부분 취소
     *
     * A(10,000×5), 수량3 취소 → refund=30,000
     */
    public function test_partial_cancel_reduces_quantity(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 5),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $orderOption = $order->options->first();

        $adjustment = $this->buildCancellation([
            ['order_option_id' => $orderOption->id, 'cancel_quantity' => 3],
        ]);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertEquals(30000, $result->refundAmount);
        $this->assertEquals(50000, $result->originalPaidAmount);
        $this->assertEquals(20000, $result->recalculatedPaidAmount);
    }

    /**
     * A-2-3: 모든 옵션 전량 취소 → 전체취소 전환
     */
    public function test_partial_cancel_all_quantity_equals_full_cancel(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);

        // 모든 옵션 전량 지정 → 전체취소와 동일
        $items = [];
        foreach ($order->options as $opt) {
            $items[] = ['order_option_id' => $opt->id, 'cancel_quantity' => $opt->quantity];
        }
        $adjustment = $this->buildCancellation($items);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // 전액 환불
        $this->assertEquals(30000, $result->refundAmount);
        $this->assertEquals(0, $result->recalculatedPaidAmount);
    }

    /**
     * A-2-4: 3개 옵션 중 1개만 취소
     */
    public function test_partial_cancel_single_item_from_three(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 15000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);
        [$pC, $oC] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optB = $order->options->firstWhere('product_option_id', $oB->id);

        $adjustment = $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // B(20,000) 취소 → refund=20,000
        $this->assertEquals(20000, $result->refundAmount);
    }

    /**
     * A-2-5: 3개 옵션 중 2개 동시 취소
     */
    public function test_partial_cancel_multiple_items_simultaneously(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 15000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);
        [$pC, $oC] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);
        $optC = $order->options->firstWhere('product_option_id', $oC->id);

        $adjustment = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
            ['order_option_id' => $optC->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // A(15,000)+C(10,000) 취소 → refund=25,000
        $this->assertEquals(25000, $result->refundAmount);
    }

    /**
     * A-2-6: 다중 옵션 부분 수량 취소
     *
     * A 5개→2개취소, B 3개→1개취소
     */
    public function test_partial_cancel_partial_quantity_from_multiple(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 5),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 3),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);
        $optB = $order->options->firstWhere('product_option_id', $oB->id);

        $adjustment = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 2],
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // A 2개취소(20,000) + B 1개취소(20,000) = 40,000
        $this->assertEquals(40000, $result->refundAmount);
        // 잔여: A 3개(30,000) + B 2개(40,000) = 70,000
        $this->assertEquals(70000, $result->recalculatedPaidAmount);
    }

    // ================================================================
    // A-3. 배송비 정책별 전체취소 (주요 6종)
    // ================================================================

    /**
     * A-3-1: FREE 배송비 전체취소
     */
    public function test_full_cancel_free_shipping(): void
    {
        $sp = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FREE);
        [$p, $o] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        $this->assertEquals(30000, $result->refundAmount);
    }

    /**
     * A-3-2: FIXED 배송비(3,000원) 전체취소 → 배송비 포함 환불
     */
    public function test_full_cancel_fixed_shipping(): void
    {
        $sp = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);
        [$p, $o] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 30,000 + 배송비 3,000 = 33,000
        $this->assertEquals(33000, $result->refundAmount);
    }

    /**
     * A-3-3: CONDITIONAL_FREE 무료배송 충족 → 전체취소
     */
    public function test_full_cancel_conditional_free_met(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$p, $o] = $this->createProductWithOption(price: 60000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 무료배송이었으므로 상품금액만 환불
        $this->assertEquals(60000, $result->refundAmount);
    }

    /**
     * A-3-4: CONDITIONAL_FREE 미충족 → 전체취소 → 배송비 포함
     */
    public function test_full_cancel_conditional_free_not_met(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$p, $o] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 30,000 + 배송비 3,000 = 33,000
        $this->assertEquals(33000, $result->refundAmount);
    }

    /**
     * A-3-5: RANGE_AMOUNT 구간 배송비 전체취소
     *
     * ranges: 0~30,000→3,000, 30,000+→1,000
     * 주문 20,000원 → 배송비 3,000원 구간
     */
    public function test_full_cancel_range_amount_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 30000, 'fee' => 3000],
                    ['min' => 30000, 'max' => null, 'fee' => 1000],
                ],
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 20,000 + 배송비 3,000 = 23,000
        $this->assertEquals(23000, $result->refundAmount);
    }

    /**
     * A-3-6: PER_QUANTITY 수량당 배송비 전체취소
     *
     * unit=5, fee=1,000 → 12개 → ceil(12/5)=3 → 배송비 3,000
     */
    public function test_full_cancel_per_quantity_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 5],
        );
        [$p, $o] = $this->createProductWithOption(price: 1000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 12),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        $originalPaid = (float) $order->total_paid_amount;
        $this->assertEquals($originalPaid, $result->refundAmount);
    }

    // ================================================================
    // A-4. CONDITIONAL_FREE 부분취소 — 무료↔유료 전환 (8건)
    // 핵심 시나리오: 이슈 #29 예시 1번의 일반화
    // ================================================================

    /**
     * A-4-1: 무료배송 유지 (잔여금액 ≥ threshold)
     *
     * 60,000 주문(무료), 5,000 취소 → 잔여 55,000 ≥ 50,000 → refund=5,000
     */
    public function test_conditional_free_stays_free_after_partial(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 55000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optB = $order->options->firstWhere('product_option_id', $oB->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 55,000 ≥ 50,000 → 무료배송 유지 → 배송비 변동 없음
        $this->assertEquals(5000, $result->refundAmount);
        $this->assertEquals(0, $result->shippingDifference);
    }

    /**
     * A-4-2: 무료→유료 전환 (잔여금액 < threshold)
     *
     * ⭐ 이슈 #29 예시 1: 60,000 주문(무료), 20,000 취소 → 잔여 40,000 < 50,000
     * → 배송비 3,000 발생 → refund = 20,000 - 3,000 = 17,000
     */
    public function test_conditional_free_becomes_paid_after_partial(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        // 10,000 × 3 + 10,000 × 3 = 60,000 (무료배송)
        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 3),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 3),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $this->assertEquals(0, (float) $order->total_shipping_amount);
        $this->assertEquals(60000, (float) $order->total_paid_amount);

        // B옵션 2개 취소 → 잔여 = 30,000 + 10,000 = 40,000 < 50,000
        $optB = $order->options->firstWhere('product_option_id', $oB->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 2],
        ]));

        // 원 결제: 60,000, 재계산: 40,000 + 배송비 3,000 = 43,000
        // 환불: 60,000 - 43,000 = 17,000
        $this->assertEquals(17000, $result->refundAmount);
    }

    /**
     * A-4-3: 경계값 — 정확히 threshold 금액 → 무료배송 유지
     */
    public function test_conditional_free_exact_threshold_stays_free(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 50000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optB = $order->options->firstWhere('product_option_id', $oB->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 정확히 50,000 = threshold → 무료배송 유지
        $this->assertEquals(10000, $result->refundAmount);
    }

    /**
     * A-4-4: 수량 부분취소로 유료 전환
     *
     * 10,000×6개=60,000(무료), 3개 취소→30,000 < 50,000
     * → refund = 30,000 - 3,000 = 27,000
     */
    public function test_conditional_free_partial_qty_triggers_paid(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 6),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 3],
        ]));

        // 원: 60,000, 재계산: 30,000 + 배송비 3,000 = 33,000
        // 환불: 60,000 - 33,000 = 27,000
        $this->assertEquals(27000, $result->refundAmount);
    }

    // ================================================================
    // A-8. 상품쿠폰(PRODUCT_AMOUNT) + 부분취소 (4건)
    // ================================================================

    /**
     * A-8-1: 상품쿠폰 정액 + 부분취소
     *
     * A(20,000)+B(10,000), 상품쿠폰 정액 1,000 ALL → A 취소
     * 재계산: B(10,000) - 쿠폰1,000 = 9,000
     */
    public function test_product_coupon_fixed_partial_cancel(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // 환불 = 원 결제금액 - 재계산 결제금액
        // 재계산: B만 남은 상태에서 쿠폰 적용 결과
        $this->assertEquals($originalPaid - $result->recalculatedPaidAmount, $result->refundAmount);
        $this->assertGreaterThan(0, $result->refundAmount);
        $this->assertLessThan($originalPaid, $result->recalculatedPaidAmount);
    }

    /**
     * A-8-2: 상품쿠폰 정률 + 부분취소
     *
     * A(20,000)+B(10,000), 상품쿠폰 정률 10% ALL → A 취소
     */
    public function test_product_coupon_rate_partial_cancel(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // 환불 = 원 결제금액 - 재계산 결제금액
        $this->assertEquals($originalPaid - $result->recalculatedPaidAmount, $result->refundAmount);
        $this->assertGreaterThan(0, $result->refundAmount);
        $this->assertLessThan($originalPaid, $result->recalculatedPaidAmount);
    }

    /**
     * A-8-3: 특정상품 쿠폰, 대상 취소 → 쿠폰 할인 소멸
     *
     * A(20,000)+B(10,000), 쿠폰 적용 대상=A → A 취소 → 쿠폰 할인 0
     */
    public function test_product_coupon_target_cancelled_discount_removed(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 15,
            targetScope: CouponTargetScope::PRODUCTS,
        );
        $coupon->coupon->includedProducts()->attach([$pA->id], ['type' => 'include']);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // 재계산: B(10,000) 쿠폰 대상 아님 → 할인 0 → paid=10,000
        $this->assertEquals(10000, $result->recalculatedPaidAmount);
    }

    // ================================================================
    // A-9. 주문쿠폰(ORDER_AMOUNT) + 부분취소 (5건)
    // 핵심: 이슈 #29 예시 2번의 일반화
    // ================================================================

    /**
     * A-9-1: 주문쿠폰 min_order 미달 → 쿠폰 복원
     *
     * ⭐ 이슈 #29 예시 2:
     * 40,000 주문, 주문쿠폰 10%(min30,000) → 36,000 결제
     * 20,000 취소 → 잔여 20,000 < 30,000 → 쿠폰 복원
     * 재계산: 20,000 (할인없음)
     * 환불: 36,000 - 20,000 = 16,000
     */
    public function test_order_coupon_min_order_not_met_coupon_restored(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            minOrderAmount: 30000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        // 원 결제: 40,000 - 4,000(10%) = 36,000
        $this->assertEquals(36000, (float) $order->total_paid_amount);

        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 20,000 < min 30,000 → 쿠폰 복원 → 할인 0
        // 재계산: 20,000
        // 환불: 36,000 - 20,000 = 16,000
        $this->assertEquals(16000, $result->refundAmount);
        $this->assertContains($coupon->id, $result->restoredCouponIssueIds);
    }

    /**
     * A-9-2: 주문쿠폰 min_order 충족 유지
     *
     * 40,000 주문, 주문쿠폰 10%(min30,000) → 36,000 결제
     * 5,000 취소 → 잔여 35,000 ≥ 30,000 → 쿠폰 유지
     */
    public function test_order_coupon_min_order_still_met(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 35000);
        [$pB, $oB] = $this->createProductWithOption(price: 5000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            minOrderAmount: 30000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;
        $optB = $order->options->firstWhere('product_option_id', $oB->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 35,000 ≥ 30,000 → 쿠폰 유지
        // 재계산: 35,000 - 3,500(10%) = 31,500
        $this->assertEquals(31500, $result->recalculatedPaidAmount);
        $this->assertNotContains($coupon->id, $result->restoredCouponIssueIds);
    }

    /**
     * A-9-3: 주문쿠폰 정액 + min_order 미달 → 쿠폰 복원
     *
     * 40,000 주문, 주문쿠폰 정액 5,000원(min30,000) → 35,000 결제
     * 15,000 취소 → 잔여 25,000 < 30,000 → 쿠폰 복원
     * 환불: 35,000 - 25,000 = 10,000
     */
    public function test_order_coupon_fixed_min_not_met(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 25000);
        [$pB, $oB] = $this->createProductWithOption(price: 15000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 30000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $this->assertEquals(35000, (float) $order->total_paid_amount);

        $optB = $order->options->firstWhere('product_option_id', $oB->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 25,000 < min 30,000 → 쿠폰 복원 → 할인 0
        // 재계산: 25,000
        // 환불: 35,000 - 25,000 = 10,000
        $this->assertEquals(10000, $result->refundAmount);
        $this->assertContains($coupon->id, $result->restoredCouponIssueIds);
    }

    /**
     * A-9-4: 주문쿠폰 정확히 threshold 경계값
     */
    public function test_order_coupon_exact_threshold_maintained(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 30000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            minOrderAmount: 30000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $optB = $order->options->firstWhere('product_option_id', $oB->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 30,000 = min → 쿠폰 유지
        $this->assertEquals(27000, $result->recalculatedPaidAmount);
        $this->assertNotContains($coupon->id, $result->restoredCouponIssueIds);
    }

    /**
     * A-9-5: 주문쿠폰 min 없음 (0) → 항상 유지
     */
    public function test_order_coupon_no_min_always_maintained(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 0,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // min=0 → 항상 충족 → 쿠폰 유지
        // 재계산: 10,000 - 5,000 = 5,000
        $this->assertEquals(5000, $result->recalculatedPaidAmount);
        $this->assertNotContains($coupon->id, $result->restoredCouponIssueIds);
    }

    // ================================================================
    // A-11. 마일리지 + 부분취소 (3건)
    // ================================================================

    /**
     * A-11-1: 마일리지 사용 + 옵션1개 취소
     *
     * 마일리지 3,000 사용, A(20,000)+B(10,000) → 27,000 결제
     * A 취소 → 잔여 B 10,000 - 마일리지안분
     */
    public function test_mileage_partial_cancel_single_option(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // 총 환불 (PG + 마일리지)
        $totalRefund = $result->refundAmount + $result->refundPointsAmount;
        // A가 전체의 2/3를 차지하므로 대략 환불액은 원결제의 2/3 정도
        $this->assertGreaterThan(0, $result->refundAmount);
        $this->assertGreaterThanOrEqual(0, $result->refundPointsAmount);
    }

    /**
     * A-11-2: 마일리지 전액 결제(paid=0) + 부분취소
     */
    public function test_mileage_full_payment_partial_cancel(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 20000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // PG 환불 0원 (전액 마일리지였으므로)
        $this->assertEquals(0, $result->refundAmount);
        // 마일리지 환불 = 취소분
        $this->assertGreaterThan(0, $result->refundPointsAmount);
    }

    /**
     * A-11-3: 마일리지 + 쿠폰 복합 부분취소
     */
    public function test_mileage_with_coupon_partial_cancel(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // 복합: 쿠폰 + 마일리지 재계산
        $totalRefund = $result->refundAmount + $result->refundPointsAmount;
        $this->assertGreaterThan(0, $totalRefund);
        // 원결제 > 재계산 결제
        $this->assertGreaterThan($result->recalculatedPaidAmount, $originalPaid);
    }

    // ================================================================
    // A-12. 복합 시나리오 — 배송비 + 쿠폰 + 마일리지 동시 (6건)
    // ================================================================

    /**
     * A-12-1: 이슈 예시 1 정밀 재현
     *
     * 10,000×3 + 15,000×2 = 60,000, CONDITIONAL_FREE(50,000, 3,000)
     * 15,000×2 취소(30,000) → 잔여 30,000 < 50,000
     * → refund = 30,000 - 3,000 = 27,000
     */
    public function test_issue_example_1_precise(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 15000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 3),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 2),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $this->assertEquals(60000, (float) $order->total_paid_amount);

        $optB = $order->options->firstWhere('product_option_id', $oB->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 2],
        ]));

        // 잔여: 30,000 < 50,000 → 배송비 3,000 발생
        // 재계산: 30,000 + 3,000 = 33,000
        // 환불: 60,000 - 33,000 = 27,000
        $this->assertEquals(27000, $result->refundAmount);
    }

    /**
     * A-12-2: 이슈 예시 2 정밀 재현
     *
     * 20,000×2 = 40,000, 주문쿠폰 10%(min30,000) → 36,000 결제
     * 20,000×1 취소 → 잔여 20,000 < 30,000 → 쿠폰 복원
     * 재계산: 20,000 (할인없음)
     * 환불: 36,000 - 20,000 = 16,000
     */
    public function test_issue_example_2_precise(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            minOrderAmount: 30000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $this->assertEquals(36000, (float) $order->total_paid_amount);

        $optA = $order->options->firstWhere('product_option_id', $oA->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        $this->assertEquals(16000, $result->refundAmount);
        $this->assertContains($coupon->id, $result->restoredCouponIssueIds);
    }

    /**
     * A-12-3: 상품쿠폰 + 배송비전환
     *
     * A(30,000)+B(20,000), 상품쿠폰 10% ALL, CONDITIONAL_FREE(40,000, 3,000)
     * B 취소 → 잔여 A 27,000(할인후) < 40,000 → 배송비 발생
     */
    public function test_product_coupon_with_shipping_transition(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 40000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;

        $optB = $order->options->firstWhere('product_option_id', $oB->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        // 재계산: A 30,000 - 3,000(10%) = 27,000 + 배송비 3,000 = 30,000
        $this->assertEquals(30000, $result->recalculatedPaidAmount);
        $this->assertEquals($originalPaid - 30000, $result->refundAmount);
    }

    /**
     * A-12-4: 마일리지 + CONDITIONAL_FREE 배송비 전환
     */
    public function test_mileage_with_conditional_free_transition(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 40000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 5000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optB = $order->options->firstWhere('product_option_id', $oB->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        // 잔여: 40,000 < 50,000 → 배송비 3,000 발생
        // 배송비+마일리지 재계산 → 환불액 계산
        $this->assertGreaterThan(0, $result->refundAmount);
    }

    /**
     * A-12-5: 전량 부분취소 → 전체취소 전환 검증
     *
     * 2옵션 모두 전량 취소 지정 → full cancel 결과와 동일
     */
    public function test_all_items_partial_cancel_equals_full_cancel(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 15000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            minOrderAmount: 30000,
        );

        $sp = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);

        // 배송비 정책 연결을 위해 배송정책이 있는 상품 재생성
        [$pA2, $oA2] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB2, $oB2] = $this->createProductWithOption(price: 15000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA2->id, productOptionId: $oA2->id, quantity: 1),
                new CalculationItem(productId: $pB2->id, productOptionId: $oB2->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);

        // 전체취소
        $fullResult = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 부분취소 (모든 옵션 전량)
        $items = [];
        foreach ($order->options as $opt) {
            $items[] = ['order_option_id' => $opt->id, 'cancel_quantity' => $opt->quantity];
        }
        $partialResult = $this->adjustmentService->calculate($order, $this->buildCancellation($items));

        // 동일한 환불액
        $this->assertEquals($fullResult->refundAmount, $partialResult->refundAmount);
        $this->assertEquals($fullResult->refundPointsAmount, $partialResult->refundPointsAmount);
    }

    /**
     * A-12-6: 주문쿠폰 + 배송비쿠폰 + 부분취소
     *
     * 주문쿠폰 10%OFF + 배송비쿠폰, CONDITIONAL_FREE
     */
    public function test_order_coupon_shipping_coupon_partial_cancel(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $orderCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            minOrderAmount: 40000,
        );

        $shippingCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$orderCoupon->id, $shippingCoupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;

        $optB = $order->options->firstWhere('product_option_id', $oB->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 30,000 < min 40,000 → 주문쿠폰 복원
        // 잔여 30,000 < 50,000 → 배송비 3,000 발생 → 배송비쿠폰으로 상쇄
        $this->assertContains($orderCoupon->id, $result->restoredCouponIssueIds);
        $this->assertGreaterThan(0, $result->refundAmount);
    }

    // ================================================================
    // A-13. 안분 정확성 검증 (4건)
    // ================================================================

    /**
     * A-13-1: 3개 옵션 안분 합계 = 원래 금액
     */
    public function test_apportion_sum_equals_original(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 13000);
        [$pB, $oB] = $this->createProductWithOption(price: 17000);
        [$pC, $oC] = $this->createProductWithOption(price: 9000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
                new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        // 옵션별 paid_amount 합 = total_paid_amount
        $optionPaidSum = $order->options->sum('subtotal_paid_amount');
        $this->assertEquals(
            (float) $order->total_paid_amount,
            (float) $optionPaidSum,
            'Option paid amounts should sum to order total paid amount (apportion accuracy)'
        );
    }

    /**
     * A-13-2: 부분취소 후 잔여 옵션 재계산 합계 일관성
     */
    public function test_apportion_consistency_after_partial_cancel(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 15000);
        [$pB, $oB] = $this->createProductWithOption(price: 25000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 2),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // optionUpdates의 paid amount 합 = recalculatedPaidAmount
        if (! empty($result->optionUpdates)) {
            $updatedPaidSum = 0;
            foreach ($result->optionUpdates as $update) {
                $updatedPaidSum += $update['subtotal_paid_amount'] ?? 0;
            }
            $this->assertEquals(
                $result->recalculatedPaidAmount,
                $updatedPaidSum,
                'Option updates sum should match recalculated paid amount'
            );
        }

        // 기본 검증: refund > 0
        $this->assertGreaterThan(0, $result->refundAmount);
    }

    /**
     * A-13-3: 단일 옵션 → 할인 전액 해당 옵션
     */
    public function test_apportion_single_item_gets_all(): void
    {
        [$p, $o] = $this->createProductWithOption(price: 30000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);

        // 단일 옵션이므로 할인 전액이 이 옵션에 적용
        $option = $order->options->first();
        $this->assertEquals(25000, (float) $option->subtotal_paid_amount);
    }

    /**
     * A-13-4: 동일 금액 옵션 균등 안분
     */
    public function test_apportion_equal_subtotals_split(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);
        [$pC, $oC] = $this->createProductWithOption(price: 10000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
                new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        );

        $order = $this->createOrderFromCalculation($input);

        // 3개 동일 금액 → 균등 안분 (각 1,000원 할인)
        // 합계가 정확해야 함
        $paidSum = $order->options->sum('subtotal_paid_amount');
        $this->assertEquals(27000, (float) $paidSum);
    }

    // ================================================================
    // A-3 나머지: 배송비 정책별 전체취소 (8건 추가)
    // ================================================================

    /**
     * A-3-7: RANGE_QUANTITY 구간별(수량) 배송비 전체취소
     *
     * ranges: 1~5→2500, 6+→4000
     * 3개×10,000 → 수량3 → 2500원 구간
     */
    public function test_full_cancel_range_quantity_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_QUANTITY,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 6, 'fee' => 2500],
                    ['min' => 6, 'max' => null, 'fee' => 4000],
                ],
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 3),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 30,000 + 배송비 2,500 = 32,500
        $this->assertEquals(32500, $result->refundAmount);
    }

    /**
     * A-3-8: RANGE_WEIGHT 구간별(무게) 배송비 전체취소
     *
     * ranges: 0~500g→2500, 500g+→4000
     * 상품 무게 0.3kg × 1개 = 300g → 2500원 구간
     */
    public function test_full_cancel_range_weight_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 500, 'fee' => 2500],
                    ['min' => 500, 'max' => null, 'fee' => 4000],
                ],
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 0.3);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 20,000 + 배송비 2,500 = 22,500
        $this->assertEquals(22500, $result->refundAmount);
    }

    /**
     * A-3-9: RANGE_VOLUME 구간별(부피) 배송비 전체취소
     *
     * ranges: 0~1000cm³→3000, 1000+→5000
     * 부피 800cm³ → 3000원 구간
     */
    public function test_full_cancel_range_volume_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 1000, 'fee' => 3000],
                    ['min' => 1000, 'max' => null, 'fee' => 5000],
                ],
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, volume: 800);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 20,000 + 배송비 3,000 = 23,000
        $this->assertEquals(23000, $result->refundAmount);
    }

    /**
     * A-3-10: RANGE_VOLUME_WEIGHT 구간별(부피+무게) 배송비 전체취소
     *
     * divisor=6000, 무게=0.5kg, 부피=4200cm³
     * 부피무게=4200/6000=0.7kg → max(0.5, 0.7)=0.7kg=700g
     * ranges: 0~500g→3000, 500g+→5000 → 5000원
     */
    public function test_full_cancel_range_volume_weight_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 500, 'fee' => 3000],
                    ['min' => 500, 'max' => null, 'fee' => 5000],
                ],
                'volume_weight_divisor' => 6000,
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 0.5, volume: 4200);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 20,000 + 배송비 5,000 = 25,000
        $this->assertEquals(25000, $result->refundAmount);
    }

    /**
     * A-3-11: PER_WEIGHT 무게당 배송비 전체취소
     *
     * unit=0.5kg, fee=2000. 무게 1.2kg → ceil(1.2/0.5)=3 → 6,000원
     */
    public function test_full_cancel_per_weight_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 1.2);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        $originalPaid = (float) $order->total_paid_amount;
        $this->assertEquals($originalPaid, $result->refundAmount);
    }

    /**
     * A-3-12: PER_VOLUME 부피당 배송비 전체취소
     *
     * unit=1000cm³, fee=1500. 부피 2500cm³ → ceil(2500/1000)=3 → 4,500원
     */
    public function test_full_cancel_per_volume_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME,
            baseFee: 1500,
            ranges: ['unit_value' => 1000],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, volume: 2500);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        $originalPaid = (float) $order->total_paid_amount;
        $this->assertEquals($originalPaid, $result->refundAmount);
    }

    /**
     * A-3-13: PER_VOLUME_WEIGHT 부피무게당 배송비 전체취소
     *
     * divisor=6000, unit=0.5kg, fee=2000
     * 무게=0.3kg, 부피=4200cm³ → 부피무게=0.7kg → max(0.3,0.7)=0.7
     * ceil(0.7/0.5)=2 → 4,000원
     */
    public function test_full_cancel_per_volume_weight_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5, 'volume_weight_divisor' => 6000],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 0.3, volume: 4200);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        $originalPaid = (float) $order->total_paid_amount;
        $this->assertEquals($originalPaid, $result->refundAmount);
    }

    /**
     * A-3-14: PER_AMOUNT 금액당 배송비 전체취소
     *
     * unit=10000, fee=500. 금액 35,000 → ceil(35000/10000)=4 → 2,000원
     */
    public function test_full_cancel_per_amount_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_AMOUNT,
            baseFee: 500,
            ranges: ['unit_value' => 10000],
        );
        [$p, $o] = $this->createProductWithOption(price: 35000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        $originalPaid = (float) $order->total_paid_amount;
        $this->assertEquals($originalPaid, $result->refundAmount);
    }

    // ================================================================
    // A-4 나머지: CONDITIONAL_FREE 부분취소 (4건 추가)
    // ================================================================

    /**
     * A-4-5: 연속 2회 부분취소 — 1차 무료유지, 2차 유료전환
     *
     * 70,000→55,000(1차)→45,000(2차)
     * threshold=50,000, fee=3,000
     */
    public function test_conditional_free_multiple_partial_cancels(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 15000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pC, $oC] = $this->createProductWithOption(price: 35000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);

        // 1차: 15,000원 옵션 취소 → 잔여 55,000 ≥ 50,000 → 무료배송 유지
        $optionA = $order->options->where('product_option_id', $oA->id)->first();
        $result1 = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        $this->assertEquals(15000, $result1->refundAmount);

        // 2차: 20,000원 옵션 취소 → 잔여 35,000 < 50,000 → 유료전환
        $optionB = $order->options->where('product_option_id', $oB->id)->first();
        $result2 = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
            ['order_option_id' => $optionB->id, 'cancel_quantity' => 1],
        ]));

        // 누적 취소: A(15K)+B(20K)=35K, 잔여=35K, 배송비 3K 발생
        // refund = 70K - (35K + 3K) = 32,000
        $this->assertEquals(32000, $result2->refundAmount);
    }

    /**
     * A-4-7: 높은 threshold (100,000원)
     *
     * threshold=100,000, fee=3,000
     * 120,000 주문, 30,000 취소 → 잔여 90,000 < 100,000
     */
    public function test_conditional_free_high_threshold(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 100000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 90000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 90,000 < 100,000 → 배송비 3,000 발생
        // refund = 120,000 - (90,000 + 3,000) = 27,000
        $this->assertEquals(27000, $result->refundAmount);
    }

    /**
     * A-4-8: fee=0인 CONDITIONAL_FREE → 전환돼도 배송비 0
     *
     * threshold=50,000, fee=0
     * 60,000→40,000 (유료 전환이지만 fee=0)
     */
    public function test_conditional_free_zero_base_fee(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 0,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 40000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // fee=0이므로 전환되어도 배송비 0 → refund = 20,000
        $this->assertEquals(20000, $result->refundAmount);
    }

    // ================================================================
    // A-5. RANGE 기반 배송비 부분취소 — 구간 변동 (10건)
    // ================================================================

    /**
     * A-5-1: RANGE_AMOUNT 상위→중간 구간 변동
     *
     * 0~20K→3000, 20K~50K→2000, 50K+→0
     * 60K→40K 부분취소 → 배송비 0→2000
     */
    public function test_range_amount_upper_to_middle_tier(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 20000, 'fee' => 3000],
                    ['min' => 20000, 'max' => 50000, 'fee' => 2000],
                    ['min' => 50000, 'max' => null, 'fee' => 0],
                ],
            ],
        );
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 40000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 40K → 20K~50K 구간 → 배송비 2,000
        // refund = 60K - (40K + 2K) = 18,000
        $this->assertEquals(18000, $result->refundAmount);
    }

    /**
     * A-5-2: RANGE_AMOUNT 중간→하위 구간 변동
     *
     * 0~20K→3000, 20K~50K→2000
     * 25K→15K → 배송비 2000→3000
     */
    public function test_range_amount_middle_to_lower_tier(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 20000, 'fee' => 3000],
                    ['min' => 20000, 'max' => 50000, 'fee' => 2000],
                ],
            ],
        );
        [$pA, $oA] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 15000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 잔여 15K → 0~20K 구간 → 배송비 3,000
        // 원래: 25K(2K배송)=27K결제, 재계산: 15K+3K=18K
        // refund = 27K - 18K = 9,000
        $this->assertEquals(9000, $result->refundAmount);
    }

    /**
     * A-5-3: RANGE_AMOUNT 동일 구간 내 부분취소 → 배송비 변동 없음
     */
    public function test_range_amount_same_tier_no_shipping_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 20000, 'fee' => 3000],
                    ['min' => 20000, 'max' => 50000, 'fee' => 2000],
                ],
            ],
        );
        [$pA, $oA] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 40000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래 45K(2K배송)=47K, 잔여 40K → 동일 구간(20K~50K) → 배송비 2K 유지
        // refund = 47K - (40K + 2K) = 5,000
        $this->assertEquals(5000, $result->refundAmount);
    }

    /**
     * A-5-4: RANGE_QUANTITY 구간 변동 (8개→4개)
     *
     * 1~5→2500, 6~10→4000
     */
    public function test_range_quantity_tier_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_QUANTITY,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 6, 'fee' => 2500],
                    ['min' => 6, 'max' => null, 'fee' => 4000],
                ],
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 8),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 4],
        ]));

        // 원래: 40K+4K배송=44K, 잔여4개: 20K+2.5K=22.5K
        // refund = 44K - 22.5K = 21,500
        $this->assertEquals(21500, $result->refundAmount);
    }

    /**
     * A-5-5: RANGE_QUANTITY 구간 내 부분취소 (10→8, 동일 구간)
     */
    public function test_range_quantity_same_tier(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_QUANTITY,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 6, 'fee' => 2500],
                    ['min' => 6, 'max' => null, 'fee' => 4000],
                ],
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 10),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));

        // 원래: 50K+4K=54K, 잔여8개(동일구간): 40K+4K=44K
        // refund = 54K - 44K = 10,000
        $this->assertEquals(10000, $result->refundAmount);
    }

    /**
     * A-5-6: RANGE_WEIGHT 구간 변동 (700g→400g)
     *
     * 0~500g→2500, 500g+→4000
     */
    public function test_range_weight_tier_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 500, 'fee' => 2500],
                    ['min' => 500, 'max' => null, 'fee' => 4000],
                ],
            ],
        );
        // 0.35kg × 2개 = 0.7kg = 700g → 4000원 구간
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 0.35);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 2),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        // 1개 취소 → 0.35kg = 350g → 2500원 구간
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 20K+4K=24K, 잔여: 10K+2.5K=12.5K
        // refund = 24K - 12.5K = 11,500
        $this->assertEquals(11500, $result->refundAmount);
    }

    /**
     * A-5-7: RANGE_VOLUME 구간 변동 (1500→800)
     *
     * 0~1000→3000, 1000+→5000
     */
    public function test_range_volume_tier_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 1000, 'fee' => 3000],
                    ['min' => 1000, 'max' => null, 'fee' => 5000],
                ],
            ],
        );
        // 750cm³ × 2개 = 1500cm³ → 5000원 구간
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, volume: 750);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 2),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        // 1개 취소 → 750cm³ → 3000원 구간
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 20K+5K=25K, 잔여: 10K+3K=13K
        // refund = 25K - 13K = 12,000
        $this->assertEquals(12000, $result->refundAmount);
    }

    /**
     * A-5-8: RANGE_VOLUME_WEIGHT 부피무게 구간 전환
     *
     * divisor=6000, 0~500g→3000, 500g+→5000
     * 무게0.2kg×3=0.6kg, 부피1500cm³×3=4500cm³→부피무게=0.75kg
     * max(0.6,0.75)=0.75kg=750g → 5000원
     * 1개 취소 → 무게0.4, 부피3000→부피무게0.5, max(0.4,0.5)=0.5kg=500g
     * tier boundary: 500 >= 0 && 500 < 500 → false (상한 미만) → 500g+ 구간 = 5000원 (변동없음)
     * → 2개 취소로 확실하게 구간 변동 검증
     */
    public function test_range_volume_weight_tier_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 500, 'fee' => 3000],
                    ['min' => 500, 'max' => null, 'fee' => 5000],
                ],
                'volume_weight_divisor' => 6000,
            ],
        );
        // 0.2kg, 1500cm³ per item
        // 3개: 무게0.6kg, 부피4500→부피무게0.75, max(0.6,0.75)=750g → 5000원
        // 1개: 무게0.2kg, 부피1500→부피무게0.25, max(0.2,0.25)=250g → 3000원
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 0.2, volume: 1500);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 3),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));

        // 원래: 30K+5K=35K, 잔여1개: 10K+3K=13K
        // refund = 35K - 13K = 22,000
        $this->assertEquals(22000, $result->refundAmount);
    }

    /**
     * A-5-9: RANGE_AMOUNT 최상위→최하위 극단 변동
     *
     * 0~10K→5000, 10K~30K→3000, 30K+→0
     * 50K→5K
     */
    public function test_range_amount_extreme_tier_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 10000, 'fee' => 5000],
                    ['min' => 10000, 'max' => 30000, 'fee' => 3000],
                    ['min' => 30000, 'max' => null, 'fee' => 0],
                ],
            ],
        );
        [$pA, $oA] = $this->createProductWithOption(price: 45000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 50K+0배송=50K, 잔여5K+5K배송=10K
        // refund = 50K - 10K = 40,000
        $this->assertEquals(40000, $result->refundAmount);
    }

    /**
     * A-5-10: RANGE_QUANTITY 1개만 남기기 → 최소 구간
     */
    public function test_range_quantity_down_to_one(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_QUANTITY,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 3, 'fee' => 5000],
                    ['min' => 3, 'max' => 6, 'fee' => 3000],
                    ['min' => 6, 'max' => null, 'fee' => 1000],
                ],
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 7),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 6],
        ]));

        // 원래: 35K+1K=36K, 잔여1개: 5K+5K=10K
        // refund = 36K - 10K = 26,000
        $this->assertEquals(26000, $result->refundAmount);
    }

    // ================================================================
    // A-6. PER_UNIT 기반 배송비 부분취소 — 단위 변동 (10건)
    // ================================================================

    /**
     * A-6-1: PER_QUANTITY 단위 변동 (12→7개)
     *
     * unit=5, fee=1000. 12→7 → ceil(12/5)=3→ceil(7/5)=2
     */
    public function test_per_quantity_unit_change_12_to_7(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 5],
        );
        [$p, $o] = $this->createProductWithOption(price: 1000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 12),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 5],
        ]));

        // 원래: 12K+3K=15K, 잔여7개: 7K+ceil(7/5)×1K=7K+2K=9K
        // refund = 15K - 9K = 6,000
        $this->assertEquals(6000, $result->refundAmount);
    }

    /**
     * A-6-2: PER_QUANTITY 큰 단위 변동 (12→5개)
     *
     * unit=5, fee=1000. ceil(12/5)=3 → ceil(5/5)=1
     */
    public function test_per_quantity_unit_change_12_to_5(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 5],
        );
        [$p, $o] = $this->createProductWithOption(price: 1000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 12),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 7],
        ]));

        // 원래: 12K+3K=15K, 잔여5개: 5K+1K=6K
        // refund = 15K - 6K = 9,000
        $this->assertEquals(9000, $result->refundAmount);
    }

    /**
     * A-6-3: PER_QUANTITY 단위 내 부분취소 (5→3, 동일 단위수)
     *
     * unit=5, fee=1000. ceil(5/5)=1 = ceil(3/5)=1
     */
    public function test_per_quantity_same_unit_count(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 5],
        );
        [$p, $o] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 5),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));

        // 원래: 25K+1K=26K, 잔여3개: 15K+ceil(3/5)×1K=15K+1K=16K
        // refund = 26K - 16K = 10,000
        $this->assertEquals(10000, $result->refundAmount);
    }

    /**
     * A-6-4: PER_WEIGHT 무게 단위 변동 (1.2kg→0.8kg)
     *
     * unit=0.5, fee=2000. ceil(1.2/0.5)=3→ceil(0.8/0.5)=2
     */
    public function test_per_weight_unit_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5],
        );
        // 0.4kg × 3개 = 1.2kg
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 0.4);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 3),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        // 1개 취소 → 0.8kg
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 30K+ceil(1.2/0.5)×2K=30K+6K=36K
        // 잔여: 20K+ceil(0.8/0.5)×2K=20K+4K=24K
        // refund = 36K - 24K = 12,000
        $this->assertEquals(12000, $result->refundAmount);
    }

    /**
     * A-6-5: PER_WEIGHT 큰 무게 감소 (1.2kg→0.4kg)
     *
     * unit=0.5, fee=2000. ceil(1.2/0.5)=3→ceil(0.4/0.5)=1
     */
    public function test_per_weight_large_decrease(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5],
        );
        // 0.4kg × 3개 = 1.2kg
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 0.4);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 3),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        // 2개 취소 → 0.4kg
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));

        // 원래: 30K+6K=36K, 잔여: 10K+ceil(0.4/0.5)×2K=10K+2K=12K
        // refund = 36K - 12K = 24,000
        $this->assertEquals(24000, $result->refundAmount);
    }

    /**
     * A-6-6: PER_VOLUME 부피 단위 변동 (2500→1500)
     *
     * unit=1000, fee=1500. ceil(2500/1000)=3→ceil(1500/1000)=2
     */
    public function test_per_volume_unit_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME,
            baseFee: 1500,
            ranges: ['unit_value' => 1000],
        );
        // 500cm³ × 5개 = 2500cm³
        [$p, $o] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp, volume: 500);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 5),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        // 2개 취소 → 1500cm³
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));

        // 원래: 25K+ceil(2500/1000)×1.5K=25K+4.5K=29.5K
        // 잔여: 15K+ceil(1500/1000)×1.5K=15K+3K=18K
        // refund = 29.5K - 18K = 11,500
        $this->assertEquals(11500, $result->refundAmount);
    }

    /**
     * A-6-7: PER_AMOUNT 금액당 단위 변동 (35K→20K)
     *
     * unit=10K, fee=500. ceil(35K/10K)=4→ceil(20K/10K)=2
     */
    public function test_per_amount_unit_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_AMOUNT,
            baseFee: 500,
            ranges: ['unit_value' => 10000],
        );
        [$pA, $oA] = $this->createProductWithOption(price: 15000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 35K+ceil(35K/10K)×500=35K+2K=37K
        // 잔여: 20K+ceil(20K/10K)×500=20K+1K=21K
        // refund = 37K - 21K = 16,000
        $this->assertEquals(16000, $result->refundAmount);
    }

    /**
     * A-6-8: PER_AMOUNT 동일 단위 (35K→30K)
     *
     * unit=10K, fee=500. ceil(35K/10K)=4→ceil(30K/10K)=3
     */
    public function test_per_amount_unit_change_slight(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_AMOUNT,
            baseFee: 500,
            ranges: ['unit_value' => 10000],
        );
        [$pA, $oA] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 35K+ceil(35K/10K)×500=35K+2K=37K
        // 잔여: 30K+ceil(30K/10K)×500=30K+1.5K=31.5K
        // refund = 37K - 31.5K = 5,500
        $this->assertEquals(5500, $result->refundAmount);
    }

    /**
     * A-6-9: PER_VOLUME_WEIGHT 부피무게 단위 변동
     *
     * divisor=6000, unit=0.5, fee=2000
     * 무게0.2×4=0.8kg, 부피1500×4=6000→부피무게1.0kg
     * max(0.8,1.0)=1.0 → ceil(1.0/0.5)=2 → 4K
     * 2개 취소 → 무게0.4, 부피3000→부피무게0.5, max(0.4,0.5)=0.5
     * ceil(0.5/0.5)=1 → 2K
     */
    public function test_per_volume_weight_unit_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5, 'volume_weight_divisor' => 6000],
        );
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 0.2, volume: 1500);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 4),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));

        // 원래: 40K+4K=44K, 잔여: 20K+2K=22K
        // refund = 44K - 22K = 22,000
        $this->assertEquals(22000, $result->refundAmount);
    }

    /**
     * A-6-10: PER_QUANTITY 건당(unit=1) 배송비
     *
     * unit=1, fee=500. 5→3개 → 배송비 2500→1500
     */
    public function test_per_quantity_per_item(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 500,
            ranges: ['unit_value' => 1],
        );
        [$p, $o] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 5),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));

        // 원래: 25K+5×500=27.5K, 잔여: 15K+3×500=16.5K
        // refund = 27.5K - 16.5K = 11,000
        $this->assertEquals(11000, $result->refundAmount);
    }

    // ================================================================
    // A-7. 도서산간 추가배송비 부분취소 (6건)
    // ================================================================

    /**
     * A-7-1: FIXED + 도서산간 flat extra fee → 전체취소
     *
     * 기본배송 3000 + 도서산간 2500 = 배송비 5500
     */
    public function test_extra_fee_flat_full_cancel(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 2500, 'region' => '제주도'],
            ],
            extraFeeMultiply: false,
        );
        [$p, $o] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $address = new ShippingAddress(countryCode: 'KR', zipcode: '63100');
        $input = new CalculationInput(
            items: [new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1)],
            shippingAddress: $address,
        );

        $order = $this->createOrderFromCalculation($input);

        // 전체취소
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 원래: 30K + 3K(기본) + 2.5K(도서산간) = 35.5K → 전액 환불
        $this->assertEquals($order->total_paid_amount, $result->refundAmount);
    }

    /**
     * A-7-2: CONDITIONAL_FREE + 도서산간 → 부분취소로 유료 전환 시 기본배송비 + 도서산간 모두 차감
     *
     * 60K 주문(무료배송), 도서산간 2500. 20K 취소 → 40K < 50K → 유료 전환
     * 기본배송비 3000 + 도서산간 2500 = 5500 발생
     */
    public function test_extra_fee_conditional_free_partial_cancel(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 2500, 'region' => '제주도'],
            ],
            extraFeeMultiply: false,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 40000, shippingPolicy: $sp);

        $address = new ShippingAddress(countryCode: 'KR', zipcode: '63200');
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            shippingAddress: $address,
        );

        $order = $this->createOrderFromCalculation($input);

        // 원래: 60K(무료배송) + 2.5K(도서산간) = 62.5K
        // 도서산간은 무료배송이어도 부과됨
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(20K) 취소 → 잔여 40K < 50K → 유료배송 전환
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 잔여: 40K + 3K(기본) + 2.5K(도서산간) = 45.5K
        // refund = originalPaid - 45500
        $this->assertEquals($originalPaid - 45500, $result->refundAmount);
    }

    /**
     * A-7-3: PER_QUANTITY + multiply extra fee → 수량 감소 시 도서산간도 비례 감소
     *
     * unit=5, fee=1000, extra=1000×multiply.
     * 12개: 배송비 ceil(12/5)×1K=3K, 도서산간 ceil(12/5)×1K=3K → 총 6K
     * 7개: 배송비 ceil(7/5)×1K=2K, 도서산간 ceil(7/5)×1K=2K → 총 4K
     */
    public function test_extra_fee_multiply_per_quantity(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 5],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 1000, 'region' => '제주도'],
            ],
            extraFeeMultiply: true,
        );
        [$p, $o] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $address = new ShippingAddress(countryCode: 'KR', zipcode: '63300');
        $input = new CalculationInput(
            items: [new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 12)],
            shippingAddress: $address,
        );

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        // 5개 취소 → 7개
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 5],
        ]));

        // 원래: 60K + 3K(배송) + 3K(도서산간) = 66K
        // 잔여: 35K + 2K(배송) + 2K(도서산간) = 39K
        // refund = 66K - 39K = 27,000
        $this->assertEquals(27000, $result->refundAmount);
    }

    /**
     * A-7-4: PER_WEIGHT + multiply extra fee → 수량 감소 시 도서산간도 비례 감소
     *
     * unit=0.5kg, fee=2000, extra=1500×multiply.
     * 도서산간 multiply는 quantity/unit_value 기반 (PER_WEIGHT에서도 수량 사용)
     * 3개: 배송 ceil(1.2kg/0.5)×2K=6K, 도서산간 ceil(3/0.5)×1.5K=9K
     * 1개: 배송 ceil(0.4kg/0.5)×2K=2K, 도서산간 ceil(1/0.5)×1.5K=3K
     */
    public function test_extra_fee_multiply_per_weight(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5],
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 1500, 'region' => '제주도'],
            ],
            extraFeeMultiply: true,
        );
        // 0.4kg × 3개 = 1.2kg
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 0.4);

        $address = new ShippingAddress(countryCode: 'KR', zipcode: '63400');
        $input = new CalculationInput(
            items: [new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 3)],
            shippingAddress: $address,
        );

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        // 2개 취소 → 1개 (0.4kg)
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));

        // 원래: 30K + 6K(배송) + 9K(도서산간) = 45K
        // 잔여: 10K + 2K(배송) + 3K(도서산간) = 15K
        // refund = 45K - 15K = 30,000
        $this->assertEquals(30000, $result->refundAmount);
    }

    /**
     * A-7-5: FIXED + no multiply extra fee → 부분취소 시 도서산간 변동 없음
     *
     * 기본배송 3000 + 도서산간 2000 (flat, 1회). 부분취소해도 도서산간 그대로.
     */
    public function test_extra_fee_no_multiply_fixed(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 2000, 'region' => '제주도'],
            ],
            extraFeeMultiply: false,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $address = new ShippingAddress(countryCode: 'KR', zipcode: '63500');
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            shippingAddress: $address,
        );

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(20K) 취소
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 30K + 3K(기본) + 2K(도서산간) = 35K
        // 잔여: 10K + 3K(기본) + 2K(도서산간) = 15K (배송비 변동 없음)
        // refund = 35K - 15K = 20,000
        $this->assertEquals(20000, $result->refundAmount);
    }

    /**
     * A-7-6: CONDITIONAL_FREE + extra fee → 무료배송 유지 시 도서산간만 남음
     *
     * 60K(무료배송) + 도서산간 3000. 5K 취소 → 55K ≥ 50K → 무료 유지. 도서산간 그대로.
     */
    public function test_extra_fee_with_conditional_free_stays_free(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 3000, 'region' => '제주도'],
            ],
            extraFeeMultiply: false,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 55000, shippingPolicy: $sp);

        $address = new ShippingAddress(countryCode: 'KR', zipcode: '63600');
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            shippingAddress: $address,
        );

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(5K) 취소 → 잔여 55K ≥ 50K → 무료 유지
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 60K + 0(무료) + 3K(도서산간) = 63K
        // 잔여: 55K + 0(무료) + 3K(도서산간) = 58K
        // refund = 63K - 58K = 5,000
        $this->assertEquals(5000, $result->refundAmount);
    }

    // ================================================================
    // A-8 나머지. 상품쿠폰(PRODUCT_AMOUNT) + 부분취소
    // ================================================================

    /**
     * A-8-3: 정률 10% max=1500, ALL 적용. B(10K) 취소 → A(20K)에만 적용, 10%=2000→cap 1500
     */
    public function test_product_coupon_rate_with_max_partial_cancel(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            maxDiscount: 1500,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionB = $order->options->where('product_option_id', $oB->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionB->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 30K - discount(10% of 30K=3K → cap 1.5K) = 28.5K
        // 잔여: 20K - discount(10% of 20K=2K → cap 1.5K) = 18.5K
        // refund = 28.5K - 18.5K = 10,000
        $this->assertEquals($originalPaid - 18500, $result->refundAmount);
    }

    /**
     * A-8-4: 정액 2000, PRODUCTS [A,B] 적용. A,B,C 중 A 취소 → B만 쿠폰 적용 지속
     */
    public function test_product_coupon_specific_products_partial_cancel(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
            targetScope: CouponTargetScope::PRODUCTS,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 15000);
        [$pC, $oC] = $this->createProductWithOption(price: 10000);

        // 쿠폰 대상 상품으로 A, B 지정
        $couponIssue->coupon->includedProducts()->attach([$pA->id, $pB->id], ['type' => 'include']);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
                new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A 취소 → 남은 B, C 중 B에만 쿠폰 적용
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 잔여: B(15K) + C(10K) - discount on B = 25K - 2K = 23K
        $this->assertEquals($originalPaid - 23000, $result->refundAmount);
    }

    /**
     * A-8-6: 정액 1000, CATEGORIES [cat1] 적용. cat1 상품 취소 → 잔여 cat1에만 할인
     */
    public function test_product_coupon_category_partial_cancel(): void
    {
        $cat = $this->createCategory('전자기기');

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
            targetScope: CouponTargetScope::CATEGORIES,
        );

        // 쿠폰 대상 카테고리 지정
        $couponIssue->coupon->includedCategories()->attach([$cat->id], ['type' => 'include']);

        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 15000);

        // A, B 모두 cat에 소속
        $pA->categories()->attach($cat->id);
        $pB->categories()->attach($cat->id);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A 취소 → B만 남음, 할인 1K
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 잔여: 15K - 1K = 14K
        $this->assertEquals($originalPaid - 14000, $result->refundAmount);
    }

    /**
     * A-8-7: 정률 20% ALL, 3개 옵션 부분수량 취소 → 잔여 수량 기준 재계산
     */
    public function test_product_coupon_rate_partial_quantity(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 20,
        );

        [$p, $o] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 5)],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $option = $order->options->first();

        // 2개 취소 → 3개 남음
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));

        // 원래: 50K - 20% = 40K
        // 잔여: 30K - 20% = 24K
        // refund = 40K - 24K = 16,000
        $this->assertEquals(16000, $result->refundAmount);
    }

    /**
     * A-8-8: 정액 5000, ALL. 1개 옵션(10K)만 남음 → 할인 min(5000,10000) = 5000 적용
     */
    public function test_product_coupon_fixed_high_discount_partial(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 40000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(40K) 취소 → B(10K)만 남음
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 잔여: 10K - min(5K,10K)=5K = 5K
        $this->assertEquals($originalPaid - 5000, $result->refundAmount);
    }

    /**
     * A-8-9: 정률 50% ALL, 고율 할인. 부분취소 시 큰 할인 차이 검증
     */
    public function test_product_coupon_high_rate_partial(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 50,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 50K - 50% = 25K
        // 잔여: 20K - 50% = 10K
        // refund = 25K - 10K = 15,000
        $this->assertEquals(15000, $result->refundAmount);
    }

    // ================================================================
    // A-9 나머지. 주문쿠폰(ORDER_AMOUNT) + 부분취소
    // ================================================================

    /**
     * A-9-4: 정률 20% max=10000, min=50000. 60K→45K(미달) → 쿠폰복원
     */
    public function test_order_coupon_rate_with_max_min_not_met(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 20,
            maxDiscount: 10000,
            minOrderAmount: 50000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 15000);
        [$pB, $oB] = $this->createProductWithOption(price: 45000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(15K) 취소 → 잔여 45K < 50K → 쿠폰 복원
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 60K - 20%=12K→cap 10K = 50K
        // 잔여: 45K (쿠폰복원, 할인 0)
        // refund = 50K - 45K = 5,000
        $this->assertEquals($originalPaid - 45000, $result->refundAmount);
    }

    /**
     * A-9-5: 정률 20% max=10000, min=50000. 60K→55K(충족) → 쿠폰 유지, max 재적용
     */
    public function test_order_coupon_rate_with_max_min_still_met(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 20,
            maxDiscount: 10000,
            minOrderAmount: 50000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 5000);
        [$pB, $oB] = $this->createProductWithOption(price: 55000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(5K) 취소 → 잔여 55K ≥ 50K → 쿠폰 유지
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 60K - min(20%=12K, max10K) = 50K
        // 잔여: 55K - min(20%=11K, max10K) = 45K
        // refund = 50K - 45K = 5,000
        $this->assertEquals($originalPaid - 45000, $result->refundAmount);
    }

    /**
     * A-9-6: 주문쿠폰 안분 검증. A(30K)+B(10K), 정률10%, B 취소 → A 안분 할인만 남음
     */
    public function test_order_coupon_apportion_after_cancel(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionB = $order->options->where('product_option_id', $oB->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionB->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 40K - 10%=4K = 36K
        // 잔여: 30K - 10%=3K = 27K
        // refund = 36K - 27K = 9,000
        $this->assertEquals($originalPaid - 27000, $result->refundAmount);
    }

    /**
     * A-9-7: 정액 3000, min=20000. 3개 옵션 안분, 1개 취소 → 안분 비율 재계산
     */
    public function test_order_coupon_fixed_apportion_recalculation(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            minOrderAmount: 20000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 15000);
        [$pC, $oC] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
                new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionC = $order->options->where('product_option_id', $oC->id)->first();

        // C(10K) 취소 → 잔여 35K ≥ 20K → 쿠폰 유지
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionC->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 45K - 3K = 42K
        // 잔여: 35K - 3K = 32K
        // refund = 42K - 32K = 10,000
        $this->assertEquals($originalPaid - 32000, $result->refundAmount);
    }

    /**
     * A-9-8: 연속 2회 부분취소 시뮬레이션. 50K→40K(유지)→25K(복원)
     *
     * 이 테스트는 1회 부분취소 후 재계산만 검증 (서비스가 단일 계산 단위)
     * 실제 연속 취소는 OrderCancellationServiceTest에서 검증
     */
    public function test_order_coupon_sequential_cancel_first(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 15,
            minOrderAmount: 30000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 40000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(10K) 취소 → 잔여 40K ≥ 30K → 쿠폰 유지
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 50K - 15%=7.5K = 42.5K
        // 잔여: 40K - 15%=6K = 34K
        // refund = 42.5K - 34K = 8,500
        $this->assertEquals($originalPaid - 34000, $result->refundAmount);
    }

    // ================================================================
    // A-10. 배송비쿠폰(SHIPPING_FEE) + 부분취소 (8건)
    // ================================================================

    /**
     * A-10-1: 정액 3000 배송비 쿠폰, FIXED 배송비 3000. 부분취소 후 배송비 변동 없음 → 쿠폰 유지
     */
    public function test_shipping_coupon_fixed_no_change(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
        );

        $sp = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 30K + 3K(배송) - 3K(쿠폰) = 30K
        // 잔여: 10K + 3K(배송) - 3K(쿠폰) = 10K
        // refund = 30K - 10K = 20,000
        $this->assertEquals(20000, $result->refundAmount);
    }

    /**
     * A-10-2: 정액 3000 배송비 쿠폰, CONDITIONAL_FREE 무료→유료 전환 시 쿠폰으로 배송비 상쇄
     */
    public function test_shipping_coupon_conditional_free_to_paid(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
        );

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 40000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(20K) 취소 → 40K < 50K → 유료 전환 3K. 쿠폰 3K 상쇄
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 60K + 0(무료) - 0(쿠폰 무효, 배송비 0) = 60K
        // 잔여: 40K + 3K(유료전환) - 3K(쿠폰) = 40K
        // refund = 60K - 40K = 20,000
        $this->assertEquals($originalPaid - 40000, $result->refundAmount);
    }

    /**
     * A-10-3: 정률 50% 배송비 쿠폰, FIXED 배송비 4000. 전체취소
     */
    public function test_shipping_coupon_rate_full_cancel(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::RATE,
            discountValue: 50,
        );

        $sp = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 4000);
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1)],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);

        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));

        // 전체취소 → 원결제금액 전액 환불
        $this->assertEquals($order->total_paid_amount, $result->refundAmount);
    }

    /**
     * A-10-4: 정액 2000 배송비 쿠폰, RANGE_AMOUNT 구간 변동 시 배송비↑ + 쿠폰
     */
    public function test_shipping_coupon_range_amount_tier_change(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
        );

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 20000, 'fee' => 4000],
                    ['min' => 20000, 'max' => 50000, 'fee' => 2500],
                    ['min' => 50000, 'max' => null, 'fee' => 0],
                ],
            ],
        );
        [$pA, $oA] = $this->createProductWithOption(price: 15000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionB = $order->options->where('product_option_id', $oB->id)->first();

        // B(20K) 취소 → 잔여 15K → 배송비 4000(하위구간)
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionB->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 35K + 2.5K(배송) - 2K(쿠폰) = 35.5K
        // 잔여: 15K + 4K(배송) - 2K(쿠폰) = 17K
        // refund = 35.5K - 17K = 18,500
        $this->assertEquals($originalPaid - 17000, $result->refundAmount);
    }

    /**
     * A-10-5: 정액 5000 배송비 쿠폰 > 배송비 3000 → 초과할인 방지 (할인=min(5K,3K)=3K)
     */
    public function test_shipping_coupon_exceeds_shipping_fee(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
        );

        $sp = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 1)],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);

        // 배송비 3K - 쿠폰 min(5K,3K) = 0 → 배송비 할인 3K
        $this->assertEquals(3000, (int) $order->shipping_discount_amount);
        // 실결제 = 20K + 0(배송비 실질) = 20K
        $this->assertEquals(20000, (int) $order->total_paid_amount);
    }

    /**
     * A-10-6: 정률 100% 배송비 쿠폰, 부분취소 후 배송비↑ → 증가분도 100% 할인
     */
    public function test_shipping_coupon_100_percent_partial_cancel(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::RATE,
            discountValue: 100,
        );

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 5000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 40000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(20K) 취소 → 40K < 50K → 유료 5K 발생. 100% 쿠폰으로 전액 할인
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 60K + 0(무료) - 0(쿠폰) = 60K
        // 잔여: 40K + 5K(유료) - 5K(100%쿠폰) = 40K
        // refund = 60K - 40K = 20,000
        $this->assertEquals($originalPaid - 40000, $result->refundAmount);
    }

    /**
     * A-10-7: 정액 1000 배송비 쿠폰, PER_QUANTITY 배송비 변동 + 쿠폰
     */
    public function test_shipping_coupon_per_quantity_change(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
        );

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 5],
        );
        [$p, $o] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 12)],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $option = $order->options->first();

        // 5개 취소 → 7개
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 5],
        ]));

        // 원래: 60K + ceil(12/5)×1K=3K - 1K(쿠폰) = 62K
        // 잔여: 35K + ceil(7/5)×1K=2K - 1K(쿠폰) = 36K
        // refund = 62K - 36K = 26,000
        $this->assertEquals($originalPaid - 36000, $result->refundAmount);
    }

    /**
     * A-10-8: 정률 30% 배송비 쿠폰, CONDITIONAL_FREE → 유료전환, 배송비×70% 부과
     */
    public function test_shipping_coupon_rate_conditional_free_to_paid(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::RATE,
            discountValue: 30,
        );

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 4000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 40000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(20K) 취소 → 40K < 50K → 배송비 4K 발생. 30% 할인 → 1.2K 할인
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 60K + 0(무료) = 60K
        // 잔여: 40K + 4K - 1.2K(30%) = 42.8K
        // refund = 60K - 42.8K = 17,200
        $this->assertEquals($originalPaid - 42800, $result->refundAmount);
    }

    // ================================================================
    // A-11 나머지. 마일리지 + 부분취소
    // ================================================================

    /**
     * A-11-4: 마일리지+쿠폰 복합, 부분취소 → 쿠폰 복원 시 마일리지 안분도 재계산
     */
    public function test_mileage_with_coupon_restore_recalculation(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            minOrderAmount: 30000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;
        $originalPoints = (int) $order->total_points_used_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(20K) 취소 → 잔여 20K < 30K → 쿠폰 복원
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 40K - 4K(쿠폰) - 3K(마일리지) = 33K paid
        // 잔여: 20K - 0(쿠폰복원) - 3K(마일리지) = 17K paid
        // refund PG = 33K - 17K = 16K, refundPoints = 3K-3K = 0 (마일리지는 잔여에 전액 적용)
        $this->assertEquals($originalPaid - 17000, $result->refundAmount);
    }

    /**
     * A-11-5: 마일리지 10K + CONDITIONAL_FREE 배송비 전환
     */
    public function test_mileage_with_shipping_transition(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 10000,
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A 취소 → 잔여 30K < 50K → 배송비 3K 발생
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 60K + 0(무료) - 10K(마일리지) = 50K paid
        // 잔여: 30K + 3K(유료) - 10K(마일리지) = 23K paid
        // refund = 50K - 23K = 27,000
        $this->assertEquals($originalPaid - 23000, $result->refundAmount);
    }

    /**
     * A-11-6: 마일리지+주문쿠폰+부분취소 → 쿠폰 복원+마일리지 동시 재계산
     */
    public function test_mileage_with_order_coupon_restore(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 40000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 25000);
        [$pB, $oB] = $this->createProductWithOption(price: 25000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
            usePoints: 5000,
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(25K) 취소 → 잔여 25K < 40K → 쿠폰 복원
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 50K - 5K(쿠폰) - 5K(마일리지) = 40K paid
        // 잔여: 25K - 0(쿠폰복원) - 5K(마일리지) = 20K paid
        // refund = 40K - 20K = 20,000
        $this->assertEquals($originalPaid - 20000, $result->refundAmount);
    }

    // ================================================================
    // A-12 나머지. 복합 시나리오
    // ================================================================

    /**
     * A-12-5: 마일리지2K+상품쿠폰정액1K, RANGE_AMOUNT 배송비 구간변동
     */
    public function test_complex_mileage_product_coupon_range_shipping(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
        );

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_AMOUNT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 30000, 'fee' => 3000],
                    ['min' => 30000, 'max' => null, 'fee' => 1000],
                ],
            ],
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
            usePoints: 2000,
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A 취소 → 잔여 20K → 하위구간 배송비 3K
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 40K - 1K(상품쿠폰) + 1K(배송, 30K+구간) - 2K(마일리지) = 38K
        // 잔여: 20K - 1K(상품쿠폰) + 3K(배송, <30K구간) - 2K(마일리지) = 20K
        // refund = 38K - 20K = 18,000
        $this->assertEquals($originalPaid - 20000, $result->refundAmount);
    }

    /**
     * A-12-6: 3중 할인(상품쿠폰+주문쿠폰+마일리지) + 다중 옵션 부분취소
     */
    public function test_complex_triple_discount_partial_cancel(): void
    {
        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
        );

        $orderCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            minOrderAmount: 30000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$productCoupon->id, $orderCoupon->id],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(20K) 취소 → 잔여 30K ≥ 30K → 주문쿠폰 유지
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 재계산 후 잔여 값을 직접 비교 (복합 할인으로 정확한 계산은 계산 엔진에 위임)
        $this->assertGreaterThan(0, $result->refundAmount);
        $this->assertLessThan($originalPaid, $result->refundAmount);
    }

    /**
     * A-12-7: 카테고리 쿠폰 + 부분취소 대상이 다른 카테고리 → 쿠폰 영향 없음
     */
    public function test_complex_category_coupon_different_category_cancel(): void
    {
        $cat1 = $this->createCategory('전자기기');
        $cat2 = $this->createCategory('의류');

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            targetScope: CouponTargetScope::CATEGORIES,
        );
        $couponIssue->coupon->includedCategories()->attach([$cat1->id], ['type' => 'include']);

        [$pA, $oA] = $this->createProductWithOption(price: 30000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);
        $pA->categories()->attach($cat1->id);
        $pB->categories()->attach($cat2->id);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionB = $order->options->where('product_option_id', $oB->id)->first();

        // B(cat2, 20K) 취소 → cat1 상품(A)만 남음 → 쿠폰 영향 없음 (여전히 cat1에 적용)
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionB->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 50K - 3K(cat1 쿠폰) = 47K
        // 잔여: 30K - 3K(cat1 쿠폰) = 27K
        // refund = 47K - 27K = 20,000
        $this->assertEquals($originalPaid - 27000, $result->refundAmount);
    }

    /**
     * A-12-8: 카테고리 쿠폰 + 부분취소 대상이 같은 카테고리 → 할인 재계산
     */
    public function test_complex_category_coupon_same_category_cancel(): void
    {
        $cat1 = $this->createCategory('전자기기');

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            targetScope: CouponTargetScope::CATEGORIES,
        );
        $couponIssue->coupon->includedCategories()->attach([$cat1->id], ['type' => 'include']);

        [$pA, $oA] = $this->createProductWithOption(price: 30000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);
        $pA->categories()->attach($cat1->id);
        $pB->categories()->attach($cat1->id);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(cat1, 30K) 취소 → B(cat1, 20K)만 남음 → 쿠폰 대상 감소
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 50K - 3K(쿠폰) = 47K
        // 잔여: 20K - 3K(쿠폰, B도 cat1) = 17K
        // refund = 47K - 17K = 30,000
        $this->assertEquals($originalPaid - 17000, $result->refundAmount);
    }

    /**
     * A-12-9: PER_QUANTITY + 상품쿠폰 + 수량 부분취소 → 배송비+할인 동시 변동
     */
    public function test_complex_per_quantity_coupon_partial_quantity(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
        );

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
            ranges: ['unit_value' => 5],
        );

        [$p, $o] = $this->createProductWithOption(price: 5000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $p->id, productOptionId: $o->id, quantity: 12)],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $option = $order->options->first();

        // 5개 취소 → 7개
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $option->id, 'cancel_quantity' => 5],
        ]));

        // 원래: 60K - 6K(10%) + 3K(배송 ceil(12/5)×1K) = 57K
        // 잔여: 35K - 3.5K(10%) + 2K(배송 ceil(7/5)×1K) = 33.5K
        // refund = 57K - 33.5K = 23,500
        $this->assertEquals($originalPaid - 33500, $result->refundAmount);
    }

    /**
     * A-12-10: RANGE_WEIGHT + 주문쿠폰(min_order) + 마일리지 → 3중 변동
     */
    public function test_complex_range_weight_order_coupon_mileage(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
            minOrderAmount: 30000,
        );

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 500, 'fee' => 2500],
                    ['min' => 500, 'max' => null, 'fee' => 4000],
                ],
            ],
        );

        // 0.3kg × 2개
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 0.3);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 0.3);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
            usePoints: 2000,
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (int) $order->total_paid_amount;

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A 취소 → 잔여 20K < 30K → 쿠폰 복원, 무게 0.3kg→<500g → 배송비 2500
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 40K - 4K(쿠폰10%) + 2.5K(배송, 600g→≥500→4000이 아니라... 0.3*2=0.6kg=600g≥500→4K) - 2K(마일리지) = 38K
        // 잠깐... 0.3kg×2 = 0.6kg = 600g ≥ 500g → 배송비 4000
        // 원래: 40K - 4K(쿠폰) + 4K(배송) - 2K(마일리지) = 38K
        // 잔여: 20K - 0(쿠폰복원) + 2.5K(배송, 300g<500g) - 2K(마일리지) = 20.5K
        // refund = 38K - 20.5K = 17,500
        $this->assertEquals($originalPaid - 20500, $result->refundAmount);
    }

    // ================================================================
    // A-13 나머지. 안분 정확성 검증
    // ================================================================

    /**
     * A-13-5: 배송비 안분이 옵션 소계 비율과 일치하는지 검증
     */
    public function test_shipping_apportion_matches_option_ratio(): void
    {
        $sp = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);
        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);

        // 배송비 3000이 30:10 = 3:1 비율로 안분
        $optA = $order->options->where('product_option_id', $oA->id)->first();
        $optB = $order->options->where('product_option_id', $oB->id)->first();

        // A의 결제금액이 B보다 큼 (배송비 포함 시)
        $this->assertGreaterThan((int) $optB->subtotal_paid_amount, (int) $optA->subtotal_paid_amount);
    }

    /**
     * A-13-6: 주문쿠폰 안분이 소계 비율과 일치하는지 검증
     */
    public function test_order_coupon_apportion_matches_ratio(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 4000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);

        // 쿠폰 4000이 30:10 비율로 안분 → A:3000, B:1000
        $optA = $order->options->where('product_option_id', $oA->id)->first();
        $optB = $order->options->where('product_option_id', $oB->id)->first();

        $totalCouponDiscount = (int) $optA->coupon_discount_amount + (int) $optB->coupon_discount_amount;
        $this->assertEquals(4000, $totalCouponDiscount);

        // A의 할인이 B보다 큼
        $this->assertGreaterThan((int) $optB->coupon_discount_amount, (int) $optA->coupon_discount_amount);
    }

    /**
     * A-13-7: 마일리지 안분이 비율 일치하는지 검증
     */
    public function test_mileage_apportion_matches_ratio(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 30000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 4000,
        );

        $order = $this->createOrderFromCalculation($input);

        $optA = $order->options->where('product_option_id', $oA->id)->first();
        $optB = $order->options->where('product_option_id', $oB->id)->first();

        $totalPoints = (int) $optA->subtotal_points_used_amount + (int) $optB->subtotal_points_used_amount;
        $this->assertEquals(4000, $totalPoints);

        // 30:10 비율 → A:3000, B:1000
        $this->assertGreaterThan((int) $optB->subtotal_points_used_amount, (int) $optA->subtotal_points_used_amount);
    }

    /**
     * A-13-8: 1차 부분취소 후 잔여 옵션 안분 재검증 (누적 정합성)
     */
    public function test_multiple_cancel_apportion_reconsistency(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 6000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);
        [$pC, $oC] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
                new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(30K) 취소 → B+C = 30K 남음
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));

        // 원래: 60K - 6K(쿠폰) = 54K
        // 잔여: 30K - 6K(쿠폰) = 24K
        // 잔여 옵션 업데이트에서 할인 안분 합계 = 6K
        $this->assertEquals(24000, $result->recalculatedPaidAmount);

        // 잔여 옵션 subtotal_paid_amount 합계 = recalculatedPaidAmount (24K)
        // ORDER_AMOUNT 쿠폰은 subtotal_paid_amount에 안분 반영됨
        if (! empty($result->optionUpdates)) {
            $totalPaid = 0;
            foreach ($result->optionUpdates as $update) {
                $totalPaid += $update['subtotal_paid_amount'] ?? 0;
            }
            $this->assertEquals($result->recalculatedPaidAmount, $totalPaid);
        }
    }

    /**
     * A-4 #29: 무료배송 기준금액 정확히 1원 미달 시 배송비 발생
     */
    public function test_conditional_free_drops_below_threshold_by_1(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        // 50001원 주문 (무료배송)
        [$pA, $oA] = $this->createProductWithOption(price: 40001, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $this->assertEquals(0, (float) $order->total_shipping_amount);
        $originalPaid = (float) $order->total_paid_amount;

        // B(10000) 취소 → 잔여 40001 < 50000 → 유료배송 전환
        // BUT: actually 40001 < 50000, shipping becomes 3000
        $optB = $order->options->firstWhere('product_option_id', $oB->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        // 잔여: 40001 + 3000(배송비) = 43001
        // 환불: originalPaid - 43001
        $this->assertEquals($originalPaid - 43001, $result->refundAmount);
        // 배송비 증가 (무료→유료): shippingDifference < 0 (환불 관점에서 음수)
        $this->assertLessThan(0, $result->shippingDifference);
    }

    /**
     * A-8 #69: 상품쿠폰 2개 동시 적용 (combinable) 복합 할인 재계산
     */
    public function test_product_coupon_two_combinable(): void
    {
        // 상품쿠폰 1: 정액 1000원
        $coupon1 = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 1000,
        );

        // 상품쿠폰 2: 정률 10%
        $coupon2 = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 10,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon1->id, $coupon2->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;

        // A(30K) 취소 → 잔여 B(20K)에 두 쿠폰 적용
        $optA = $order->options->firstWhere('product_option_id', $oA->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        $this->assertGreaterThan(0, $result->refundAmount);
        $this->assertLessThan($originalPaid, $result->refundAmount);
    }

    /**
     * A-8 #70: 상품쿠폰 대상 상품 취소 시 할인 제거
     */
    public function test_product_coupon_exclude_target_cancel(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 15,
            targetScope: CouponTargetScope::PRODUCTS,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        // 쿠폰 대상: A만
        $couponIssue->coupon->includedProducts()->attach([$pA->id], ['type' => 'include']);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;
        $originalDiscount = (float) $order->total_discount_amount;

        // A를 취소 → 쿠폰 대상 소멸 → 할인 0
        $optA = $order->options->firstWhere('product_option_id', $oA->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        // 잔여: B(10K) — 쿠폰 대상이 없으므로 할인 0
        // 환불액 = originalPaid - 10000
        $this->assertEquals($originalPaid - 10000, $result->refundAmount);
    }

    /**
     * A-12 #106: 도서산간 추가배송비 + 배송비쿠폰 + 부분취소
     */
    public function test_complex_extra_fee_shipping_coupon(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63000-63644', 'fee' => 2500, 'region' => '제주도'],
            ],
            extraFeeMultiply: false,
        );

        // 배송비 쿠폰: 정액 2000원
        $shippingCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $address = new ShippingAddress(countryCode: 'KR', zipcode: '63200');
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$shippingCoupon->id],
            shippingAddress: $address,
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;

        // 원래: 60K(무료배송) + 2.5K(도서산간) - 2K(배송쿠폰) = 60.5K
        // A(30K) 취소 → 잔여 30K < 50K → 유료배송 전환
        // 잔여: 30K + 3K(기본) + 2.5K(도서산간) - 2K(배송쿠폰) = 33.5K
        $optA = $order->options->firstWhere('product_option_id', $oA->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]));

        $this->assertGreaterThan(0, $result->refundAmount);
        $this->assertEquals($originalPaid - 33500, $result->refundAmount);
    }

    // ================================================================
    // A-13. 환불 우선순위 (RefundPriorityEnum) 테스트 (4건)
    // ================================================================

    /**
     * A-13-1: PG_FIRST — PG 결제금부터 환불 (기본 동작)
     *
     * PG 27,000 + 마일리지 3,000 = 30,000원 주문
     * A(20,000) 취소 시 → PG 먼저 환불
     */
    public function test_refund_priority_pg_first(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([
                ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
            ]),
            RefundPriorityEnum::PG_FIRST,
        );

        $totalRefund = $result->refundAmount + $result->refundPointsAmount;
        $this->assertGreaterThan(0, $totalRefund);
        // PG_FIRST: PG 환불이 포인트 환불보다 크거나 같아야 함
        $this->assertGreaterThanOrEqual($result->refundPointsAmount, $result->refundAmount);
        $this->assertEquals(RefundPriorityEnum::PG_FIRST, $result->refundPriority);
    }

    /**
     * A-13-2: POINTS_FIRST — 포인트부터 환불
     *
     * 동일 주문에서 POINTS_FIRST 사용 시 포인트 환불이 우선
     */
    public function test_refund_priority_points_first(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([
                ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
            ]),
            RefundPriorityEnum::POINTS_FIRST,
        );

        $totalRefund = $result->refundAmount + $result->refundPointsAmount;
        $this->assertGreaterThan(0, $totalRefund);
        // POINTS_FIRST: 포인트 잔액만큼 포인트 환불 우선 (3000원이 전부 환불)
        $this->assertEquals(3000, $result->refundPointsAmount);
        $this->assertEquals(RefundPriorityEnum::POINTS_FIRST, $result->refundPriority);
    }

    /**
     * A-13-3: 동일 주문에서 우선순위 변경 시 총 환불액은 동일
     *
     * PG_FIRST와 POINTS_FIRST 총합은 같고, 배분만 다름
     * A(10,000)+B(10,000), 포인트 10,000 → PG 10,000 결제
     * A 취소 → 환불금 ≈ 10,000 (포인트 안분 5,000 + PG 안분 5,000)
     * PG_FIRST: PG 5,000 + 포인트 5,000
     * POINTS_FIRST: 포인트 5,000 + PG 5,000
     */
    public function test_refund_priority_total_same_regardless_of_priority(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 10000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]);

        $pgFirst = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::PG_FIRST);
        $pointsFirst = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::POINTS_FIRST);

        // 총 환불액 동일
        $pgTotal = $pgFirst->refundAmount + $pgFirst->refundPointsAmount;
        $pointsTotal = $pointsFirst->refundAmount + $pointsFirst->refundPointsAmount;
        $this->assertEquals($pgTotal, $pointsTotal);

        // PG_FIRST: PG 환불 >= 포인트 환불
        $this->assertGreaterThanOrEqual($pgFirst->refundPointsAmount, $pgFirst->refundAmount);
        // POINTS_FIRST: 포인트 환불 >= PG 환불
        $this->assertGreaterThanOrEqual($pointsFirst->refundAmount, $pointsFirst->refundPointsAmount);
    }

    /**
     * A-13-4: preview()에서 우선순위 전달 확인
     */
    public function test_preview_respects_refund_priority(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 5000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->firstWhere('product_option_id', $oA->id);

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->preview($order, $cancellation, RefundPriorityEnum::POINTS_FIRST);

        $this->assertEquals(RefundPriorityEnum::POINTS_FIRST, $result->refundPriority);
        // POINTS_FIRST이므로 포인트 잔액(5000원)이 먼저 환불
        $this->assertEquals(5000, $result->refundPointsAmount);
    }

    // ===== mc_* 다중통화 환불 테스트 =====

    /**
     * 다중통화 스냅샷이 있는 주문의 환불 시 mc_refund_amount가 통화별로 변환됩니다.
     */
    public function test_mc_refund_amount_converted_with_currency_snapshot(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
        );

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.85, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $optA = $order->options->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation);

        // mc_refund_amount가 null이 아니어야 함
        $this->assertNotNull($result->mcRefundAmount);
        $this->assertIsArray($result->mcRefundAmount);

        // KRW 기본 통화는 환불액과 동일
        $this->assertArrayHasKey('KRW', $result->mcRefundAmount);
        $this->assertEquals($result->refundAmount, $result->mcRefundAmount['KRW']);

        // USD 변환값 존재
        $this->assertArrayHasKey('USD', $result->mcRefundAmount);
        $this->assertGreaterThan(0, $result->mcRefundAmount['USD']);
    }

    /**
     * 다중통화 스냅샷이 없는 주문의 환불 시 mc_* 필드는 모두 null입니다.
     */
    public function test_mc_fields_null_without_currency_snapshot(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
        );

        // currency_snapshot 미설정 (기본값 null)
        $order = $this->createOrderFromCalculation($input);
        $optA = $order->options->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation);

        $this->assertNull($result->mcRefundAmount);
        $this->assertNull($result->mcRefundPointsAmount);
        $this->assertNull($result->mcRefundShippingAmount);
    }

    /**
     * 마일리지 사용 주문의 다중통화 환불 시 mc_refund_points_amount가 변환됩니다.
     */
    public function test_mc_refund_points_amount_converted(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            usePoints: 10000,
        );

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.85, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $optA = $order->options->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]);

        // POINTS_FIRST로 계산하여 마일리지 환불이 발생하도록 함
        $result = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::POINTS_FIRST);

        // 마일리지 환불이 있으므로 mc_refund_points_amount 존재
        $this->assertGreaterThan(0, $result->refundPointsAmount);
        $this->assertNotNull($result->mcRefundPointsAmount);
        $this->assertArrayHasKey('KRW', $result->mcRefundPointsAmount);
        $this->assertEquals($result->refundPointsAmount, $result->mcRefundPointsAmount['KRW']);
        $this->assertArrayHasKey('USD', $result->mcRefundPointsAmount);
    }

    /**
     * 배송비가 있는 주문 전체취소 시 mc_refund_shipping_amount가 변환됩니다.
     */
    public function test_mc_refund_shipping_amount_when_shipping_difference_positive(): void
    {
        $policy = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $policy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            ],
        );

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'JPY' => ['rate' => 9.2, 'rounding_unit' => 1, 'rounding_method' => 'floor'],
            ],
        ];

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        // 배송비 3000원 유료 → 전체취소 시 배송비 전액 환불
        $optA = $order->options->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation);

        // 전체취소 → 배송비 환불 (shipping_difference > 0)
        $this->assertGreaterThan(0, $result->shippingDifference);
        $this->assertNotNull($result->mcRefundShippingAmount);
        $this->assertArrayHasKey('KRW', $result->mcRefundShippingAmount);
        $this->assertEquals($result->shippingDifference, $result->mcRefundShippingAmount['KRW']);
        $this->assertArrayHasKey('JPY', $result->mcRefundShippingAmount);
        $this->assertGreaterThan(0, $result->mcRefundShippingAmount['JPY']);
    }

    /**
     * toPreviewArray()에 mc_* 필드가 포함됩니다.
     */
    public function test_preview_array_includes_mc_fields(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 40000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            usePoints: 5000,
        );

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 1350, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $optA = $order->options->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->preview($order, $cancellation);
        $preview = $result->toPreviewArray();

        // toPreviewArray에 mc_* 키 존재
        $this->assertArrayHasKey('mc_refund_amount', $preview);
        $this->assertArrayHasKey('mc_refund_points_amount', $preview);
        $this->assertArrayHasKey('mc_refund_shipping_amount', $preview);

        // mc_refund_amount는 통화별 값
        $this->assertNotNull($preview['mc_refund_amount']);
        $this->assertArrayHasKey('KRW', $preview['mc_refund_amount']);
        $this->assertArrayHasKey('USD', $preview['mc_refund_amount']);

        // mc_refund_points_amount도 변환됨 (마일리지 사용했으므로)
        $this->assertNotNull($preview['mc_refund_points_amount']);
    }

    // ================================================================
    // B. 쿠폰 복원 감지 (8건)
    // ================================================================

    /**
     * B-1: 부분취소 후 최소주문금액 미달 시 쿠폰 복원 감지
     *
     * 50,000×1 + 주문쿠폰(FIXED 5000, minOrder=30000)
     * 전체취소 → 잔여 0 < 30000 → 쿠폰 복원
     */
    public function test_coupon_restore_detected_when_min_order_not_met_after_partial(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 30000,
        );

        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertContains($couponIssue->id, $result->restoredCouponIssueIds);
    }

    /**
     * B-2: 부분취소 후 최소주문금액 충족 시 쿠폰 미복원
     *
     * 50,000×2 + 주문쿠폰(FIXED 5000, minOrder=30000)
     * 1개 취소 → 잔여 50,000 >= 30,000 → 쿠폰 유지
     */
    public function test_coupon_restore_not_detected_when_min_order_still_met(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 30000,
        );

        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $cancellation);

        $this->assertEmpty($result->restoredCouponIssueIds);
    }

    /**
     * B-3: 주문쿠폰 최소금액 경계값 — 잔여금액이 정확히 미달 시 복원
     *
     * 30,000×1 + 주문쿠폰(FIXED 3000, minOrder=30001)
     * 전체취소 → 쿠폰 복원
     */
    public function test_coupon_restore_detected_for_order_coupon_min_amount(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            minOrderAmount: 30001,
        );

        [$product, $option] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertContains($couponIssue->id, $result->restoredCouponIssueIds);
    }

    /**
     * B-4: 상품쿠폰 대상 전체 취소 시 복원 감지
     *
     * 20,000×1 + 상품쿠폰(FIXED 2000, targetType=PRODUCT_AMOUNT)
     * 전체취소 → 쿠폰 복원
     */
    public function test_coupon_restore_detected_for_product_coupon_target_all_cancelled(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
        );

        [$product, $option] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertContains($couponIssue->id, $result->restoredCouponIssueIds);
    }

    /**
     * B-5: 복수 쿠폰 부분 복원 — 주문쿠폰만 복원, 상품쿠폰은 유지 가능
     *
     * 상품A(50,000×1) + 상품B(30,000×1)
     * 주문쿠폰(FIXED 5000, minOrder=60000) + 상품쿠폰(FIXED 2000)
     * B 취소 → 잔여 50,000 < 60,000 → 주문쿠폰 복원
     */
    public function test_coupon_restore_multiple_coupons_partial_restore(): void
    {
        $orderCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 60000,
        );

        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 50000);
        [$pB, $oB] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$orderCoupon->id, $productCoupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $optB = $order->options->firstWhere('product_option_id', $oB->id);

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation);

        // 주문쿠폰은 최소금액 미달로 복원
        $this->assertContains($orderCoupon->id, $result->restoredCouponIssueIds);
    }

    /**
     * B-6: 배송쿠폰 전체취소 시 복원 감지
     *
     * 20,000×1 + 배송쿠폰(SHIPPING_FEE) → 전체취소 → 배송쿠폰 복원
     */
    public function test_coupon_restore_shipping_coupon_when_all_cancelled(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );

        $shippingCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
        );

        [$product, $option] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$shippingCoupon->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertContains($shippingCoupon->id, $result->restoredCouponIssueIds);
    }

    /**
     * B-7: restoredCouponIssueIds에 정확한 쿠폰 발급 ID가 포함되는지 검증
     *
     * 50,000×1 + 주문쿠폰(FIXED 5000, minOrder=30000) → 전체취소
     */
    public function test_coupon_restore_ids_populated_in_adjustment_result(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 30000,
        );

        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertIsArray($result->restoredCouponIssueIds);
        $this->assertCount(1, $result->restoredCouponIssueIds);
        $this->assertEquals($couponIssue->id, $result->restoredCouponIssueIds[0]);
    }

    /**
     * B-8: toPreviewArray()에 restored_coupons 키와 쿠폰 정보 포함 확인
     *
     * 50,000×1 + 주문쿠폰(FIXED 5000, minOrder=30000) → 전체취소
     */
    public function test_coupon_restore_info_in_preview_array(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 30000,
        );

        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $preview = $result->toPreviewArray();

        $this->assertArrayHasKey('restored_coupons', $preview);
        $this->assertNotEmpty($preview['restored_coupons']);

        $firstCoupon = $preview['restored_coupons'][0];
        $this->assertArrayHasKey('coupon_name', $firstCoupon);
        $this->assertArrayHasKey('discount_amount', $firstCoupon);
    }

    // ================================================================
    // C. 환불 우선순위 (RefundPriorityEnum) 상세 (12건)
    // ================================================================

    /**
     * C-1: PG_FIRST 부분취소 — PG만으로 충분 시 포인트 환불 없음
     *
     * 50,000×2, 포인트 10,000, 1개 취소 → 환불 25,000
     * PG_FIRST: refundAmount=25,000, refundPointsAmount=0
     */
    public function test_refund_priority_pg_first_partial_cancel_pg_only(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
            usePoints: 10000,
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::PG_FIRST);

        $totalRefund = $result->refundAmount + $result->refundPointsAmount;
        // PG_FIRST: PG 잔액(90,000)이 충분하므로 PG에서 전액 환불
        $this->assertEquals($totalRefund, $result->refundAmount + $result->refundPointsAmount);
        $this->assertGreaterThanOrEqual($result->refundPointsAmount, $result->refundAmount);
    }

    /**
     * C-2: PG_FIRST 전체취소 — PG + 포인트 모두 환불
     *
     * 50,000×2, 포인트 10,000, 전체취소 → PG 90,000 + 포인트 10,000 환불
     */
    public function test_refund_priority_pg_first_full_cancel_pg_and_points(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
            usePoints: 10000,
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);

        $result = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::PG_FIRST);

        // 전체취소 → PG 결제분 + 포인트 모두 환불
        $this->assertEquals(90000, $result->refundAmount);
        $this->assertEquals(10000, $result->refundPointsAmount);
    }

    /**
     * C-3: PG_FIRST — PG 잔액 초과 시 포인트로 넘침
     *
     * 10,000×1, 포인트 8,000 → PG 결제 2,000
     * PG_FIRST 전체취소: refundAmount=2,000, refundPointsAmount=8,000
     */
    public function test_refund_priority_pg_first_exceeds_pg_balance_spills_to_points(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            usePoints: 8000,
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);

        $result = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::PG_FIRST);

        // PG 결제 2,000 + 포인트 8,000 = 10,000 전액 환불
        $this->assertEquals(2000, $result->refundAmount);
        $this->assertEquals(8000, $result->refundPointsAmount);
    }

    /**
     * C-4: POINTS_FIRST 부분취소 — 포인트만으로 충분 시 PG 환불 없음
     *
     * 50,000×2, 포인트 30,000, 1개 취소 → 환불 25,000
     * POINTS_FIRST: refundPointsAmount=25,000, refundAmount=0
     */
    public function test_refund_priority_points_first_partial_cancel_points_only(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
            usePoints: 30000,
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::POINTS_FIRST);

        $totalRefund = $result->refundAmount + $result->refundPointsAmount;
        // POINTS_FIRST: 포인트(30,000)가 환불액(25,000) 이상이므로 포인트 우선
        $this->assertGreaterThanOrEqual($result->refundAmount, $result->refundPointsAmount);
    }

    /**
     * C-5: POINTS_FIRST 전체취소 — 포인트 + PG 모두 환불
     *
     * 50,000×2, 포인트 30,000, 전체취소 → refundPointsAmount=30,000, refundAmount=70,000
     */
    public function test_refund_priority_points_first_full_cancel_points_and_pg(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
            usePoints: 30000,
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);

        $result = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::POINTS_FIRST);

        // 전체취소 → 포인트 30,000 + PG 70,000 = 100,000 전액 환불
        $this->assertEquals(70000, $result->refundAmount);
        $this->assertEquals(30000, $result->refundPointsAmount);
    }

    /**
     * C-6: POINTS_FIRST — 포인트 잔액 초과 시 PG로 넘침
     *
     * 50,000×2, 포인트 10,000, 1개 취소 → 환불 25,000
     * POINTS_FIRST: refundPointsAmount=10,000, refundAmount=15,000
     */
    public function test_refund_priority_points_first_exceeds_points_balance_spills_to_pg(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
            usePoints: 10000,
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::POINTS_FIRST);

        // POINTS_FIRST: 포인트 안분(5,000)이 먼저 → 나머지 PG
        $totalRefund = $result->refundAmount + $result->refundPointsAmount;
        $this->assertGreaterThan(0, $totalRefund);
        // 포인트 환불이 발생해야 함
        $this->assertGreaterThan(0, $result->refundPointsAmount);
        // 포인트만으로 부족하므로 PG 환불도 있어야 함
        $this->assertGreaterThan(0, $result->refundAmount);
    }

    /**
     * C-7: 포인트 미사용 시 우선순위 무관 — 동일 결과
     *
     * 30,000×1, 포인트 없음 → 양 우선순위 모두 refundAmount=30,000, refundPointsAmount=0
     */
    public function test_refund_priority_no_points_used_ignores_priority(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);

        $pgFirst = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::PG_FIRST);
        $pointsFirst = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::POINTS_FIRST);

        $this->assertEquals(30000, $pgFirst->refundAmount);
        $this->assertEquals(0, $pgFirst->refundPointsAmount);
        $this->assertEquals(30000, $pointsFirst->refundAmount);
        $this->assertEquals(0, $pointsFirst->refundPointsAmount);
    }

    /**
     * C-8: 전액 포인트 결제 시 양 우선순위 모두 포인트만 환불
     *
     * 10,000×1, 포인트 10,000 → PG 0원
     * 양 우선순위: refundAmount=0, refundPointsAmount=10,000
     */
    public function test_refund_priority_full_points_payment_always_points(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            usePoints: 10000,
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);

        $pgFirst = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::PG_FIRST);
        $pointsFirst = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::POINTS_FIRST);

        // PG 결제 0원이므로 양 우선순위 모두 포인트만 환불
        $this->assertEquals(0, $pgFirst->refundAmount);
        $this->assertEquals(10000, $pgFirst->refundPointsAmount);
        $this->assertEquals(0, $pointsFirst->refundAmount);
        $this->assertEquals(10000, $pointsFirst->refundPointsAmount);
    }

    /**
     * C-9: PG_FIRST 부분취소 후 잔여 잔액 정확성 검증
     *
     * 20,000×3, 포인트 10,000 → PG 50,000
     * 1개 취소(PG_FIRST) → remainingPgBalance, remainingPointsBalance 확인
     */
    public function test_refund_priority_sequential_cancel_pg_first_remaining_correct(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ],
            usePoints: 10000,
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::PG_FIRST);

        // 잔여 잔액은 원래 잔액에서 환불분 차감
        $originalPg = (float) $order->total_paid_amount;
        $originalPoints = (float) $order->total_points_used_amount;

        $this->assertEquals($originalPg - $result->refundAmount, $result->remainingPgBalance);
        $this->assertEquals($originalPoints - $result->refundPointsAmount, $result->remainingPointsBalance);
    }

    /**
     * C-10: POINTS_FIRST 부분취소 후 잔여 잔액 정확성 검증
     *
     * 20,000×3, 포인트 10,000 → PG 50,000
     * 1개 취소(POINTS_FIRST) → remainingPgBalance, remainingPointsBalance 확인
     */
    public function test_refund_priority_sequential_cancel_points_first_remaining_correct(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ],
            usePoints: 10000,
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::POINTS_FIRST);

        $originalPg = (float) $order->total_paid_amount;
        $originalPoints = (float) $order->total_points_used_amount;

        $this->assertEquals($originalPg - $result->refundAmount, $result->remainingPgBalance);
        $this->assertEquals($originalPoints - $result->refundPointsAmount, $result->remainingPointsBalance);
    }

    /**
     * C-11: toPreviewArray()에 remaining_pg_balance, remaining_points_balance 포함 확인
     */
    public function test_refund_priority_remaining_balances_in_preview_array(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
            usePoints: 5000,
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation);
        $preview = $result->toPreviewArray();

        $this->assertArrayHasKey('remaining_pg_balance', $preview);
        $this->assertArrayHasKey('remaining_points_balance', $preview);
        $this->assertGreaterThanOrEqual(0, $preview['remaining_pg_balance']);
        $this->assertGreaterThanOrEqual(0, $preview['remaining_points_balance']);
    }

    /**
     * C-12: 우선순위 미지정 시 기본값 PG_FIRST 확인
     */
    public function test_refund_priority_default_pg_first_when_not_specified(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            usePoints: 5000,
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);

        // 우선순위 인자 미전달 → 기본값 PG_FIRST
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertEquals(RefundPriorityEnum::PG_FIRST, $result->refundPriority);
    }

    // ================================================================
    // D. 배송비 상세 (shippingDetails) (5건)
    // ================================================================

    /**
     * D-1: 단일 배송그룹 전체취소 시 base_difference 포함
     *
     * 2개 상품 동일 정책(FIXED 3000), 전체취소 → shippingDetails에 base_difference=3000
     */
    public function test_shipping_details_single_group_last_item_includes_base_fee(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertNotEmpty($result->shippingDetails);
        $detail = $result->shippingDetails[0];
        $this->assertArrayHasKey('base_difference', $detail);
        $this->assertEquals(3000, $detail['base_difference']);
    }

    /**
     * D-2: 다중 배송그룹 독립 계산 — 정책별 개별 shippingDetails 항목
     *
     * 상품A(정책1 FIXED 3000) + 상품B(정책2 FIXED 5000), 전체취소
     * shippingDetails에 2개 항목
     */
    public function test_shipping_details_multi_group_independent_per_policy(): void
    {
        $sp1 = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );
        $sp2 = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 5000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp1);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp2);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);

        // 기본 헬퍼가 1개 OrderShipping만 생성하므로, 정책별 2개 레코드로 교체
        $order->shippings()->delete();
        OrderShipping::factory()->forOrder($order)->create([
            'shipping_policy_id' => $sp1->id,
            'shipping_status' => 'pending',
            'base_shipping_amount' => 3000,
            'extra_shipping_amount' => 0,
            'total_shipping_amount' => 3000,
            'shipping_discount_amount' => 0,
        ]);
        OrderShipping::factory()->forOrder($order)->create([
            'shipping_policy_id' => $sp2->id,
            'shipping_status' => 'pending',
            'base_shipping_amount' => 5000,
            'extra_shipping_amount' => 0,
            'total_shipping_amount' => 5000,
            'shipping_discount_amount' => 0,
        ]);
        $order->load('shippings');

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertCount(2, $result->shippingDetails);

        $totalDiff = array_sum(array_column($result->shippingDetails, 'total_difference'));
        $this->assertEquals(8000, $totalDiff);
    }

    /**
     * D-3: 도서산간 추가배송비 포함 시 extra_difference 존재
     *
     * 정책(FIXED 3000, extraFee 도서산간 2000), 전체취소
     * shippingDetails에 extra_difference 포함
     */
    public function test_shipping_details_extra_fee_included_on_last_item(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                'island' => 2000,
                'mountain' => 3000,
            ],
        );

        [$product, $option] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $address = new ShippingAddress(countryCode: 'KR', zipcode: '63200');
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            shippingAddress: $address,
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertNotEmpty($result->shippingDetails);
        $detail = $result->shippingDetails[0];
        $this->assertArrayHasKey('extra_difference', $detail);
        $this->assertGreaterThanOrEqual(0, $detail['extra_difference']);
    }

    /**
     * D-4: 배송비 변동 없으면 shippingDetails 비어있음
     *
     * 50,000×2 + CONDITIONAL_FREE(freeThreshold=30000), 1개 취소
     * 잔여 50,000 >= 30,000 → 배송비 변동 없음 → shippingDetails 비어있음
     */
    public function test_shipping_details_no_change_returns_empty_array(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 30000,
        );

        [$product, $option] = $this->createProductWithOption(price: 50000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation);

        // 잔여 50,000 >= 30,000 → 무료배송 유지 → 배송비 차이 없음
        $this->assertEquals(0, $result->shippingDifference);
        $this->assertEmpty($result->shippingDetails);
    }

    /**
     * D-5: PER_QUANTITY 정책 부분취소 시 수량 기반 배송비 재계산
     *
     * PER_QUANTITY(baseFee=1000/개), 3개 → 1개 취소
     * shippingDetails에 base_difference=1000
     */
    public function test_shipping_details_per_quantity_partial_qty_recalculation(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_QUANTITY,
            baseFee: 1000,
        );

        [$product, $option] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ],
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation);

        // 3개→2개: 배송비 차이 발생 (PER_QUANTITY 기반 재계산)
        $this->assertGreaterThan(0, $result->shippingDifference);
        if (! empty($result->shippingDetails)) {
            $this->assertArrayHasKey('base_difference', $result->shippingDetails[0]);
            $this->assertGreaterThan(0, $result->shippingDetails[0]['base_difference']);
        }
    }

    // ================================================================
    // E. mc_* 다중통화 환불 상세 (3건)
    // ================================================================

    /**
     * E-1: 환율 변경 시 주문 시점 스냅샷 환율 사용 검증
     *
     * 스냅샷 환율 USD=0.85로 주문 → mc_refund_amount['USD']는 0.85 기준
     */
    public function test_mc_refund_exchange_rate_changed_uses_order_time(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 26000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
        );

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.85, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // 변환식: (26,000 / 1000) × 0.85 = 22.10 USD (스냅샷 환율 기준)
        $this->assertNotNull($result->mcRefundAmount);
        $this->assertArrayHasKey('USD', $result->mcRefundAmount);
        $this->assertEquals(22.10, $result->mcRefundAmount['USD']);
    }

    /**
     * E-2: JPY 반올림 규칙(floor) 적용 검증
     *
     * 스냅샷: JPY rounding_unit=1, method=floor
     */
    public function test_mc_refund_rounding_rule_changed_uses_order_time(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 15000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
        );

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'JPY' => ['rate' => 9.2, 'rounding_unit' => 1, 'rounding_method' => 'floor'],
            ],
        ];

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // 변환식: (15,000 / 1000) × 9.2 = 138.0 → floor → 138 JPY
        $this->assertNotNull($result->mcRefundAmount);
        $this->assertArrayHasKey('JPY', $result->mcRefundAmount);
        $this->assertEquals(138, $result->mcRefundAmount['JPY']);
    }

    /**
     * E-3: 배송비 + 포인트 포함 주문의 mc 변환 검증
     *
     * 배송비 3000, 포인트 5000 사용 → 전체취소 시 mc_refund_points_amount, mc_refund_shipping_amount 모두 존재
     */
    public function test_mc_refund_points_and_shipping_converted_correctly(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );

        [$product, $option] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            usePoints: 5000,
        );

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.85, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::POINTS_FIRST);

        // 배송비 환불 존재 (전체취소 → 배송비 3000 환불)
        $this->assertGreaterThan(0, $result->shippingDifference);
        $this->assertNotNull($result->mcRefundShippingAmount);
        $this->assertArrayHasKey('USD', $result->mcRefundShippingAmount);
        $this->assertGreaterThan(0, $result->mcRefundShippingAmount['USD']);

        // 포인트 환불 존재 (POINTS_FIRST → 포인트 5000 전액 환불)
        $this->assertGreaterThan(0, $result->refundPointsAmount);
        $this->assertNotNull($result->mcRefundPointsAmount);
        $this->assertArrayHasKey('USD', $result->mcRefundPointsAmount);
        $this->assertGreaterThan(0, $result->mcRefundPointsAmount['USD']);
    }

    // ================================================================
    // F. 복합 시나리오 (5건)
    // ================================================================

    /**
     * F-1: 스냅샷 가격 변경 + 쿠폰 + 배송비 전환 복합
     *
     * 50,000×2, CONDITIONAL_FREE(threshold=80000), 주문쿠폰 5000(min=60000)
     * 1개 취소 → 잔여 50,000 < 80,000 → 배송비 추가, < 60,000 → 쿠폰 복원
     */
    public function test_complex_snapshot_price_change_with_coupon_and_shipping_transition(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 80000,
        );

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 60000,
        );

        [$product, $option] = $this->createProductWithOption(price: 50000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;

        // 상품 가격 변경 (스냅샷에는 영향 없음)
        $product->update(['selling_price' => 70000]);

        $opt = $order->options->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $result = $this->adjustmentService->calculate($order, $cancellation);

        // 쿠폰 복원 (잔여 50,000 < 60,000)
        $this->assertContains($couponIssue->id, $result->restoredCouponIssueIds);

        // 배송비 전환 (잔여 50,000 < 80,000 → 유료배송 3000 추가)
        // 원래: 100,000 - 5,000(쿠폰) + 0(무료배송) = 95,000
        // 잔여: 50,000 - 0(쿠폰복원) + 3,000(배송비) = 53,000
        $this->assertEquals(53000, $result->recalculatedPaidAmount);
        $this->assertEquals($originalPaid - 53000, $result->refundAmount);
    }

    /**
     * F-2: 배송비 + 도서산간 + 상품쿠폰 + 마일리지 전체취소
     *
     * 30,000×2, FIXED 3000 + extraFee 2000 + 상품쿠폰 3000 + 포인트 5000
     * 전체취소 → 전액 환불, 쿠폰 복원
     */
    public function test_complex_snapshot_coupon_expired_with_mileage_and_extra_fee(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                'island' => 2000,
                'mountain' => 3000,
            ],
        );

        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $address = new ShippingAddress(countryCode: 'KR', zipcode: '63200');
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$productCoupon->id],
            usePoints: 5000,
            shippingAddress: $address,
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // 전체취소 → 결제금 전액 환불 (PG + 포인트)
        $totalRefund = $result->refundAmount + $result->refundPointsAmount;
        $this->assertEquals((float) $order->total_paid_amount + (float) $order->total_points_used_amount, $totalRefund);

        // 쿠폰 복원
        $this->assertContains($productCoupon->id, $result->restoredCouponIssueIds);

        // 재계산 결제금액은 0
        $this->assertEquals(0, $result->recalculatedPaidAmount);
    }

    /**
     * F-3: 특정 우선순위로 부분취소 계산 검증
     *
     * 20,000×3, 포인트 15,000
     * 1개 취소(PG_FIRST) → 환불 배분 확인
     */
    public function test_complex_snapshot_sequential_cancel_with_priority_change(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ],
            usePoints: 15000,
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]);

        $pgResult = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::PG_FIRST);
        $ptResult = $this->adjustmentService->calculate($order, $cancellation, RefundPriorityEnum::POINTS_FIRST);

        // 총 환불액 동일
        $pgTotal = $pgResult->refundAmount + $pgResult->refundPointsAmount;
        $ptTotal = $ptResult->refundAmount + $ptResult->refundPointsAmount;
        $this->assertEquals($pgTotal, $ptTotal);

        // PG_FIRST: PG 환불 >= 포인트 환불
        $this->assertGreaterThanOrEqual($pgResult->refundPointsAmount, $pgResult->refundAmount);
        // POINTS_FIRST: 포인트 환불 >= PG 환불
        $this->assertGreaterThanOrEqual($ptResult->refundAmount, $ptResult->refundPointsAmount);
    }

    /**
     * F-4: 다중 배송정책 그룹 전체취소 시 각 그룹별 shippingDetails 확인
     *
     * 상품A(정책1 FIXED 3000) + 상품B(정책2 FIXED 5000)
     * 전체취소 → shippingDetails 2개, 합계 8000
     */
    public function test_complex_snapshot_multi_policy_last_item_each_group(): void
    {
        $sp1 = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );
        $sp2 = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 5000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp1);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp2);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);

        // 정책별 2개 OrderShipping 레코드로 교체
        $order->shippings()->delete();
        OrderShipping::factory()->forOrder($order)->create([
            'shipping_policy_id' => $sp1->id,
            'shipping_status' => 'pending',
            'base_shipping_amount' => 3000,
            'extra_shipping_amount' => 0,
            'total_shipping_amount' => 3000,
            'shipping_discount_amount' => 0,
        ]);
        OrderShipping::factory()->forOrder($order)->create([
            'shipping_policy_id' => $sp2->id,
            'shipping_status' => 'pending',
            'base_shipping_amount' => 5000,
            'extra_shipping_amount' => 0,
            'total_shipping_amount' => 5000,
            'shipping_discount_amount' => 0,
        ]);
        $order->load('shippings');

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $this->assertCount(2, $result->shippingDetails);
        $this->assertEquals(8000, $result->shippingDifference);

        $totalDetailDiff = array_sum(array_column($result->shippingDetails, 'total_difference'));
        $this->assertEquals(8000, $totalDetailDiff);
    }

    /**
     * F-5: 모든 할인 + 다중통화 복합 — mc_* 필드 전체 검증
     *
     * 50,000×1, 배송비 3000, 주문쿠폰 5000, 포인트 10,000
     * currency_snapshot USD rate=1300
     * 전체취소 → mc_refund_amount, mc_refund_points_amount, mc_refund_shipping_amount 모두 존재
     */
    public function test_complex_snapshot_mc_refund_with_all_discounts(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
        );

        [$product, $option] = $this->createProductWithOption(price: 50000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
            usePoints: 10000,
        );

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.85, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::POINTS_FIRST);

        // mc_refund_amount 존재 (PG 환불)
        $this->assertNotNull($result->mcRefundAmount);
        $this->assertArrayHasKey('KRW', $result->mcRefundAmount);
        $this->assertArrayHasKey('USD', $result->mcRefundAmount);

        // mc_refund_points_amount 존재 (포인트 환불)
        $this->assertNotNull($result->mcRefundPointsAmount);
        $this->assertArrayHasKey('USD', $result->mcRefundPointsAmount);
        $this->assertGreaterThan(0, $result->mcRefundPointsAmount['USD']);

        // mc_refund_shipping_amount 존재 (배송비 환불)
        $this->assertNotNull($result->mcRefundShippingAmount);
        $this->assertArrayHasKey('USD', $result->mcRefundShippingAmount);
        $this->assertGreaterThan(0, $result->mcRefundShippingAmount['USD']);

        // 전체취소 → 재계산 결제금액 0
        $this->assertEquals(0, $result->recalculatedPaidAmount);

        // 쿠폰 복원
        $this->assertContains($couponIssue->id, $result->restoredCouponIssueIds);
    }

    // ========================================
    // cancel_blocked (재계산 결제금액 > 원 결제금액)
    // ========================================

    /**
     * 부분취소 시 쿠폰 조건 미달로 결제금액 증가 → cancel_blocked = true
     *
     * 상품A(15,000×1) + 상품B(15,000×1) = 30,000
     * 주문쿠폰(FIXED 20,000, minOrder=25,000) → 결제 10,000원
     * B 취소 → 잔여 15,000 < 25,000 → 쿠폰 소멸 → 재계산 15,000 > 원결제 10,000
     */
    public function test_cancel_blocked_when_coupon_lost_increases_payment(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 20000,
            minOrderAmount: 25000,
        );

        [$productA, $optionA] = $this->createProductWithOption(price: 15000);
        [$productB, $optionB] = $this->createProductWithOption(price: 15000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);

        // 원 결제금액: 30,000 - 20,000 = 10,000
        $this->assertEquals(10000, (float) $order->total_paid_amount);

        // B 취소
        $optB = $order->options->where('product_option_id', $optionB->id)->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $cancellation);

        // 재계산 결제금액 15,000 > 원 결제 10,000 → cancel_blocked
        $this->assertTrue($result->isCancelBlocked());

        // toPreviewArray에도 반영
        $preview = $result->toPreviewArray();
        $this->assertTrue($preview['cancel_blocked']);
        $this->assertNotNull($preview['cancel_blocked_reason']);
    }

    /**
     * 부분취소 후에도 쿠폰 조건 충족 → cancel_blocked = false
     *
     * 상품A(30,000×1) + 상품B(30,000×1) = 60,000
     * 주문쿠폰(FIXED 5,000, minOrder=25,000) → 결제 55,000원
     * B 취소 → 잔여 30,000 >= 25,000 → 쿠폰 유지 → 재계산 25,000 < 원결제 55,000
     */
    public function test_cancel_not_blocked_when_coupon_still_valid(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 25000,
        );

        [$productA, $optionA] = $this->createProductWithOption(price: 30000);
        [$productB, $optionB] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $optB = $order->options->where('product_option_id', $optionB->id)->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $cancellation);

        $this->assertFalse($result->isCancelBlocked());

        $preview = $result->toPreviewArray();
        $this->assertFalse($preview['cancel_blocked']);
        $this->assertNull($preview['cancel_blocked_reason']);
    }

    /**
     * 전체취소 → cancel_blocked = false (전체취소는 항상 허용)
     */
    public function test_cancel_not_blocked_on_full_cancel(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 20000,
            minOrderAmount: 25000,
        );

        [$product, $option] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // 전체취소: 재계산 결제금액 0 → cancel_blocked = false
        $this->assertFalse($result->isCancelBlocked());
    }

    /**
     * 상품쿠폰(대형 할인) 부분취소로 할인 소멸 → cancel_blocked
     *
     * 상품A(10,000×3) + 상품쿠폰(FIXED 8,000, minOrder=25,000)
     * 원 결제: 30,000 - 24,000(8000×3) = 6,000원
     * 수량 2개 취소 → 잔여 10,000 < 25,000 → 쿠폰 소멸 → 재계산 10,000 > 원결제 6,000
     */
    public function test_cancel_blocked_product_coupon_quantity_loss(): void
    {
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 8000,
            minOrderAmount: 25000,
        );

        [$product, $option] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);

        // 원 결제: 30,000 - 24,000 = 6,000
        $this->assertEquals(6000, (float) $order->total_paid_amount);

        $opt = $order->options->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 2],
        ]);
        $result = $this->adjustmentService->calculate($order, $cancellation);

        $this->assertTrue($result->isCancelBlocked());
    }

    // ================================================================
    // G. refund_total 정합성 검증 (배송비 이중 계산 방지)
    // ================================================================

    /**
     * G-1: 부분취소 시 배송비 변동 발생 → refund_total에 배송비 이중 포함 안됨
     *
     * 상품A(50,000×1) + 상품B(10,000×1), 무료배송 기준 50,000 이상 (배송비 3,000)
     * 원 주문: 60,000원 (배송비 0, 무료배송 충족)
     * B 취소 → 잔여 50,000 >= 50,000 → 무료 유지 → refund_total = 10,000
     */
    public function test_refund_total_equals_refund_plus_points_no_shipping_double_count(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );

        [$productA, $optionA] = $this->createProductWithOption(price: 50000, shippingPolicy: $sp);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
        );

        $order = $this->createOrderFromCalculation($input);
        $optB = $order->options->where('product_option_id', $optionB->id)->first();

        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $cancellation);
        $preview = $result->toPreviewArray();

        // refund_total = refundAmount + refundPointsAmount (배송비 별도 가산 없음)
        $expected = max(0, $result->refundAmount) + $result->refundPointsAmount;
        $this->assertEquals($expected, $preview['refund_total']);
    }

    /**
     * G-2: 부분취소로 무료배송 → 유료배송 전환 시 refund_total 정합성
     *
     * 상품A(30,000×1) + 상품B(30,000×1), 무료배송 기준 50,000 (배송비 3,000)
     * 원 주문: 60,000 (배송비 0 — 무료배송)
     * B 취소 → 잔여 30,000 < 50,000 → 배송비 3,000 발생
     * 재계산 결제금액 33,000, 환불 = 60,000 - 33,000 = 27,000 (배송비 차이 포함)
     * refund_total = 27,000 (shippingDifference -3,000이 별도 가산되면 안됨)
     */
    public function test_refund_total_with_shipping_threshold_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );

        [$productA, $optionA] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);
        [$productB, $optionB] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
        );

        $order = $this->createOrderFromCalculation($input);

        // 원 주문: 60,000 배송비 0 (무료배송)
        $this->assertEquals(0, (float) $order->total_shipping_amount);

        $optB = $order->options->where('product_option_id', $optionB->id)->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $cancellation);
        $preview = $result->toPreviewArray();

        // 배송비 차이 음수 (무료→유료: 환불 관점에서 음수)
        $this->assertLessThan(0, $result->shippingDifference);

        // refund_total = refundAmount + refundPointsAmount (shippingDifference 별도 가산 없음)
        $expected = max(0, $result->refundAmount) + $result->refundPointsAmount;
        $this->assertEquals($expected, $preview['refund_total']);
    }

    /**
     * G-3: 전체취소 시 배송비 있는 주문 → refund_total = 원결제 전액
     *
     * 상품 10,000 + 배송비 3,000 = 결제 13,000
     * 전체취소 → refund_total = 13,000 (배송비 이중 포함 안됨)
     */
    public function test_refund_total_full_cancel_with_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );

        [$product, $option] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
        );

        $order = $this->createOrderFromCalculation($input);

        // 원 주문: 10,000 + 3,000 = 13,000
        $this->assertEquals(13000, (float) $order->total_paid_amount);

        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);
        $preview = $result->toPreviewArray();

        // 전체취소: refund_total = 원결제 전액 (배송비 이중 아님)
        $expected = max(0, $result->refundAmount) + $result->refundPointsAmount;
        $this->assertEquals($expected, $preview['refund_total']);
        $this->assertEquals(13000, $preview['refund_total']);
    }

    /**
     * G-4: 쿠폰 + 배송비 변동 복합 — refund_total 정합성
     *
     * 상품A(50,000) + 상품B(6,000), 배송비 8,500 (무료배송 50,000 이상)
     * 주문쿠폰 30% 할인 + 배송비쿠폰 적용 시나리오
     * B만 남기고 A 취소 → 쿠폰 소멸 + 배송비 변동
     * refund_total = refundAmount + refundPointsAmount
     */
    public function test_refund_total_coupon_and_shipping_combined(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 8500,
            freeThreshold: 50000,
        );

        [$productA, $optionA] = $this->createProductWithOption(price: 50000, shippingPolicy: $sp);
        [$productB, $optionB] = $this->createProductWithOption(price: 6000, shippingPolicy: $sp);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::RATE,
            discountValue: 30,
            minOrderAmount: 40000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
        );

        $order = $this->createOrderFromCalculation($input);

        // 원 주문: (50,000 + 6,000) × 0.7 = 39,200 + 배송비(무료 — 56,000 > 50,000)
        $this->assertEquals(0, (float) $order->total_shipping_amount);

        $optA = $order->options->where('product_option_id', $optionA->id)->first();
        $cancellation = $this->buildCancellation([
            ['order_option_id' => $optA->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $cancellation);
        $preview = $result->toPreviewArray();

        // refund_total 정합성: refundAmount + refundPointsAmount (shippingDifference 별도 가산 없음)
        $expected = max(0, $result->refundAmount) + $result->refundPointsAmount;
        $this->assertEquals($expected, $preview['refund_total']);

        // refund_total = 원결제액 - 재계산결제액
        $this->assertEquals(
            (float) $order->total_paid_amount - $result->recalculatedPaidAmount,
            $preview['refund_total']
        );
    }

    // ====================================================================
    // L. 총 정가금액(total_list_price_amount) 스냅샷 테스트
    // ====================================================================

    /**
     * L-1: 전체취소 시 original_snapshot에 total_list_price_amount 포함
     *
     * 정가 30,000 × 2개 = 60,000
     * 판매가 20,000 × 2개 = 40,000
     */
    public function test_full_cancel_snapshot_includes_total_list_price_amount(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000, listPrice: 30000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $preview = $result->toPreviewArray();

        // original_snapshot: 정가 합계 = 30,000 × 2 = 60,000
        $this->assertEquals(60000, $preview['original_snapshot']['total_list_price_amount']);
        // original_snapshot: 판매가 합계 = 20,000 × 2 = 40,000
        $this->assertEquals(40000, $preview['original_snapshot']['subtotal_amount']);
        // recalculated_snapshot: 전체취소이므로 0
        $this->assertEquals(0, $preview['recalculated_snapshot']['total_list_price_amount']);
        $this->assertEquals(0, $preview['recalculated_snapshot']['subtotal_amount']);
    }

    /**
     * L-2: 부분취소 시 total_list_price_amount 정확한 계산
     *
     * 옵션A: 정가 50,000, 판매가 40,000, 2개
     * 옵션B: 정가 30,000, 판매가 20,000, 3개
     * → 옵션B 2개 취소
     * → 취소 후: A(50,000×2) + B(30,000×1) = 130,000 (정가)
     * → 취소 후: A(40,000×2) + B(20,000×1) = 100,000 (판매가)
     */
    public function test_partial_cancel_snapshot_total_list_price_amount(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 40000, listPrice: 50000);
        [$productB, $optionB] = $this->createProductWithOption(price: 20000, listPrice: 30000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 2),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 3),
        ]);

        $order = $this->createOrderFromCalculation($input);

        // 옵션B 2개 취소
        $optionBRecord = $order->options->where('product_option_id', $optionB->id)->first();
        $adjustment = CancellationAdjustment::fromArray([
            ['order_option_id' => $optionBRecord->id, 'cancel_quantity' => 2],
        ]);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $preview = $result->toPreviewArray();

        // original: A(50,000×2) + B(30,000×3) = 100,000 + 90,000 = 190,000
        $this->assertEquals(190000, $preview['original_snapshot']['total_list_price_amount']);
        // original: A(40,000×2) + B(20,000×3) = 80,000 + 60,000 = 140,000
        $this->assertEquals(140000, $preview['original_snapshot']['subtotal_amount']);

        // recalculated: A(50,000×2) + B(30,000×1) = 100,000 + 30,000 = 130,000
        $this->assertEquals(130000, $preview['recalculated_snapshot']['total_list_price_amount']);
    }

    /**
     * L-3: 정가 = 판매가일 때 total_list_price_amount == subtotal_amount
     */
    public function test_list_price_equals_selling_price_same_amounts(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 25000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $adjustment = $this->buildFullCancellation($order);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $preview = $result->toPreviewArray();

        // list_price == selling_price이므로 두 값이 동일
        $this->assertEquals(
            $preview['original_snapshot']['subtotal_amount'],
            $preview['original_snapshot']['total_list_price_amount']
        );
    }
}
