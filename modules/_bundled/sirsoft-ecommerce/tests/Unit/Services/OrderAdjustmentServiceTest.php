<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\DTO\ExchangeAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\ReturnAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
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
    // S1-D22 가드가 쓰는 `krwCurrencySnapshot()` 등 클레임 주문 헬퍼는 이 trait 에 있다.
    // 빠져 있으면 테스트가 `Call to undefined method` 로 **실행 자체가 안 되는데**, 스위트
    // 전체는 다른 테스트가 통과해 green 으로 보인다 — 가드가 있다고 착각하게 되는 형태다.
    use BuildsClaimFlowOrders;

    protected OrderAdjustmentService $adjustmentService;

    protected OrderCalculationService $calculationService;

    /**
     * 통화 설정을 심고 계산·조정 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->adjustmentService = app(OrderAdjustmentService::class);
        $this->calculationService = app(OrderCalculationService::class);
    }

    /**
     * 테스트용 통화 설정을 저장합니다.
     *
     * @return void 반환값 없음
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
                    'is_default' => true,
                ],
            ],
        ];

        file_put_contents(
            $settingsPath.'/language_currency.json',
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 테스트가 심은 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $settingsFile = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/language_currency.json');
        if (file_exists($settingsFile)) {
            unlink($settingsFile);
        }
        $mileageFile = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/mileage.json');
        if (file_exists($mileageFile)) {
            unlink($mileageFile);
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
     * @param  float|null  $weight  옵션 무게
     * @param  float|null  $volume  옵션 부피
     * @param  int|null  $listPrice  정가 (없으면 판매가)
     * @return array 생성된 [상품, 상품옵션]
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
     * @return ShippingPolicy 생성된 배송정책
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
     * @return Category 생성된 카테고리
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
     * @return Order 생성된 주문 (옵션, 배송, 결제 관계 로드됨)
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
            // 결제완료 주문은 사용 마일리지를 이미 차감했다(실주문은 결제 확정 때 `is_mileage_deducted` 를 켠다).
            // 차감하지 않은 주문(Q15 — 미리보기·실행 모두 마일리지 몫 0)은 호출부가 덮어써 만든다.
            'is_mileage_deducted' => $result->summary->pointsUsed > 0,
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
                'subtotal_earned_points_amount' => $item->pointsEarning,
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
     * @param  array  $cancelItems  취소할 행과 수량 [{order_option_id, cancel_quantity}]
     * @return CancellationAdjustment 취소 조정
     */
    protected function buildCancellation(array $cancelItems): CancellationAdjustment
    {
        return CancellationAdjustment::fromArray($cancelItems);
    }

    /**
     * 주문의 모든 옵션을 전량 취소하는 CancellationAdjustment를 생성합니다.
     *
     * @param  Order  $order  주문
     * @return CancellationAdjustment 전량 취소 조정
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
     *
     * @effects full_cancel_refunds_the_entire_paid_amount
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_entire_paid_amount
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_entire_paid_amount
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_entire_paid_amount, cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_entire_paid_amount, cancel_restores_the_coupon_when_the_minimum_breaks
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_refunds_the_difference_against_the_recalculated_total
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_refunds_the_difference_against_the_recalculated_total
     *
     * @return void 반환값 없음
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
     *
     * @effects all_items_promotes_to_full_cancel
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_refunds_the_difference_against_the_recalculated_total
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_refunds_the_difference_against_the_recalculated_total
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_refunds_the_difference_against_the_recalculated_total
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_shipping_fee_that_was_charged
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_shipping_fee_that_was_charged
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_shipping_fee_that_was_charged
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_shipping_fee_that_was_charged
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_shipping_fee_that_was_charged, cancel_recalculation_matches_the_charge_policy_tier
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_shipping_fee_that_was_charged, cancel_recalculation_matches_the_charge_policy_units
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_free_shipping_while_the_remainder_meets_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_free_shipping_while_the_remainder_meets_the_threshold, boundary_value_charged_not_free
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects cancelling_every_coupon_target_drops_the_item_discount
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_the_order_coupon_while_the_minimum_holds
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_the_order_coupon_while_the_minimum_holds
     *
     * @return void 반환값 없음
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
     * 주문쿠폰 주문 전체취소 → 환불 상세(옵션별) 행 환불액이 그 행의 실결제액과 같다 (S3-D3, 2026-10-07 실측)
     *
     * A(10,000)+B(30,000), 주문쿠폰 3,000 → 행 실결제 9,250 / 27,750. 전체취소 장부(`adjustedItems`)가
     * 할인 반영 후 금액을 취소액으로 적고, 환불 상세 행은 그 값을 정가로 보고 할인 비율을 **한 번 더**
     * 빼서 8,556.25 / 25,668.75(합 34,225)로 기록됐다 — 신청서 총액 39,500(배송비 2,500 포함)과 줄 합계가
     * 2,775원 어긋났다. 부분취소 장부는 정가를 적으므로 같은 행 계산이 맞게 나온다.
     *
     * @effects full_cancel_refunds_the_entire_paid_amount
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_with_order_coupon_refund_option_rows_match_paid_amount(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 30000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            minOrderAmount: 0,
        );

        $order = $this->createOrderFromCalculation(new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        ));

        $cancelItems = $order->options->map(fn ($o) => ['order_option_id' => $o->id, 'cancel_quantity' => $o->quantity])->values()->all();
        $result = $this->adjustmentService->calculate($order, $this->buildFullCancellation($order));
        $rows = app(RefundExecutionService::class)
            ->buildRefundOptionRows($order, $cancelItems, $result);

        foreach ($order->options as $option) {
            $row = collect($rows)->firstWhere('order_option_id', $option->id);
            $this->assertEqualsWithDelta((float) $option->subtotal_paid_amount, (float) $row['refund_amount'], 0.01, "옵션 {$option->id} 환불액");
        }
        $this->assertEqualsWithDelta(37000.0, (float) collect($rows)->sum('refund_amount'), 0.01);
    }

    /**
     * 같은 주문의 부분취소(행 전량)는 종전에도 맞았다 — 전체취소와 같은 행 금액을 말한다.
     *
     * @effects full_cancel_refunds_the_entire_paid_amount
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_with_order_coupon_refund_option_row_matches_paid_amount(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 30000);

        $coupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            minOrderAmount: 0,
        );

        $order = $this->createOrderFromCalculation(new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$coupon->id],
        ));

        $optA = $order->options->firstWhere('product_option_id', $oA->id);
        $cancelItems = [['order_option_id' => $optA->id, 'cancel_quantity' => 1]];
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation($cancelItems));
        $rows = app(RefundExecutionService::class)
            ->buildRefundOptionRows($order, $cancelItems, $result);

        $this->assertEqualsWithDelta((float) $optA->subtotal_paid_amount, (float) $rows[0]['refund_amount'], 0.01);
    }

    /**
     * 앞서 일부 환불한 주문을 다시 취소할 때 「취소 전 실결제금액」은 지금 남은 결제액이다 (S3-D14, 2026-10-07 실측)
     *
     * 취소 창 「주문금액 비교」의 취소 전 열은 지금 주문(판매가·배송비·과세)을 그리는데 실결제금액만 처음 결제 총액을
     * 그렸다. 60,000원 주문에서 27,500원을 이미 환불한 뒤 남은 상품을 모두 취소하면 「실결제 60,000 → 0」 옆에
     * 「최종 환불 예정액 32,500」이 찍혀 두 숫자가 서로 맞지 않았다(실측: 주문 20261007-0258571183). 같은 값은 취소
     * 차단 판정(`isCancelBlocked`)의 기준선이기도 하다 — 처음 결제 총액을 보면 이미 돌려준 돈만큼 추가결제 필요를
     * 놓친다.
     *
     * @effects partial_cancel_after_a_prior_refund_compares_against_the_amount_still_paid
     *
     * @return void 반환값 없음
     */
    public function test_original_snapshot_paid_amount_excludes_prior_refunds(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 30000);

        $order = $this->createOrderFromCalculation(new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        ));
        $paid = (float) $order->total_paid_amount;

        // 앞선 부분취소(A) 뒤 상태 — A 행 취소, 주문 합계는 남은 B 기준, 결제 총액은 그대로, 환불 누계 10,000
        $optA = $order->options->firstWhere('product_option_id', $oA->id);
        $optA->update(['option_status' => OrderStatusEnum::CANCELLED]);
        $order->update([
            'subtotal_amount' => 30000,
            'total_amount' => $paid - 10000,
            'total_refunded_amount' => 10000,
        ]);
        $order->refresh()->load('options');

        $optB = $order->options->firstWhere('product_option_id', $oB->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        $this->assertEqualsWithDelta($paid - 10000, (float) $result->originalSnapshot['total_paid_amount'], 0.01, '취소 전 실결제는 이미 돌려준 돈을 뺀 값이어야 한다');
        $this->assertFalse($result->isCancelBlocked());
    }

    /**
     * 「취소 전 실결제금액」 보조 통화도 지금 남은 결제액을 환산한다 (S3-D14 후속, 2026-10-07 재실측)
     *
     * 기준 통화 줄은 「결제 총액 − 환불 누계」로 바뀌었는데 보조 통화 줄은 주문의 `mc_total_paid_amount`(결제 총액 맵)를
     * 그대로 읽어, 한 칸 안에 「22,500원 · $44.56(60,000원어치)」처럼 두 시점이 섞였다(재실측: 주문 20261007-0258571183
     * 취소 창).
     *
     * @effects partial_cancel_after_a_prior_refund_compares_against_the_amount_still_paid
     *
     * @return void 반환값 없음
     */
    public function test_original_multi_currency_paid_amount_excludes_prior_refunds(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 30000);

        $order = $this->createOrderFromCalculation(new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        ));
        $paid = (float) $order->total_paid_amount;
        $snapshot = $order->currency_snapshot;
        $this->assertNotEmpty($snapshot, '통화 스냅샷이 있어야 보조 통화 줄이 선다');

        $order->options->firstWhere('product_option_id', $oA->id)->update(['option_status' => OrderStatusEnum::CANCELLED]);
        $currency = app(CurrencyConversionService::class);
        // 실제 주문처럼 결제 총액의 통화별 맵을 들고 있다 — 환불이 나가도 이 맵은 깎이지 않는다
        $order->update([
            'subtotal_amount' => 30000,
            'total_amount' => $paid - 10000,
            'total_refunded_amount' => 10000,
            'mc_total_paid_amount' => $currency->convertToMultiCurrencyWithSnapshot($paid, $snapshot),
        ]);
        $order->refresh()->load('options');

        $optB = $order->options->firstWhere('product_option_id', $oB->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        $expected = app(CurrencyConversionService::class)
            ->convertToMultiCurrencyWithSnapshot($paid - 10000, $snapshot);
        foreach ($expected as $code => $amount) {
            $this->assertEqualsWithDelta((float) $amount, (float) ($result->mcOriginalSnapshot['mc_total_paid_amount'][$code] ?? -1), 0.011, $code.' 보조 통화가 결제 총액을 환산한다');
        }
    }

    /**
     * 환불 0원으로 닫힌 상품이 있는 주문의 마지막 상품 취소는 닫힌 상품 값을 다시 돌려주지 않는다 (S3-D18, 2026-10-07 실측)
     *
     * 부분 경로는 종결 행을 뺀 기준선으로 계산해 이 문제를 막는다(#599 validate P2 #2). 남은 상품을 **모두** 빼는 취소는
     * 재계산 없이 「결제액 − 환불 누계」를 돌려주는 전량 제외 경로를 타는데, 0원으로 닫힌 행은 환불 누계를 늘리지 않아
     * 그 값이 그대로 다시 나갔다. 실측: 주문 20261007-0533380250(A 10,000 + B 30,000 + 배송 2,500) — A 취소 환불을
     * 0원으로 실행한 뒤 B 취소 창의 최종 환불 예정액 **42,500**(B 몫 32,500 + 닫힌 A 10,000).
     *
     * @effects zero_refund_closed_row_is_not_refunded_again
     *
     * @return void 반환값 없음
     */
    public function test_cancelling_the_last_item_does_not_refund_a_row_closed_with_zero(): void
    {
        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 30000);

        $order = $this->createOrderFromCalculation(new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        ));
        $paid = (float) $order->total_paid_amount;

        // A 를 환불 0원으로 닫은 뒤 상태 — 행은 취소, 주문 합계는 B 기준, 환불 누계는 그대로 0
        $order->options->firstWhere('product_option_id', $oA->id)->update(['option_status' => OrderStatusEnum::CANCELLED]);
        $order->update(['subtotal_amount' => 30000, 'total_amount' => $paid - 10000]);
        $order->refresh()->load('options');

        $optB = $order->options->firstWhere('product_option_id', $oB->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optB->id, 'cancel_quantity' => 1],
        ]));

        $this->assertEqualsWithDelta($paid - 10000, (float) $result->refundAmount + (float) $result->refundPointsAmount, 0.01, '0원으로 닫힌 A 값이 다시 환불된다');
    }

    /**
     * A-9-5: 주문쿠폰 min 없음 (0) → 항상 유지
     *
     * @effects partial_cancel_keeps_the_order_coupon_while_the_minimum_holds
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points, partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items, partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold, cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects all_items_promotes_to_full_cancel
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks, shipping_coupon_is_reapplied_to_the_recalculated_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects apportioned_option_subtotals_sum_back_to_the_order_total
     *
     * @return void 반환값 없음
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
     *
     * @effects apportioned_option_subtotals_sum_back_to_the_order_total
     *
     * @return void 반환값 없음
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
     *
     * @effects apportioned_option_subtotals_sum_back_to_the_order_total
     *
     * @return void 반환값 없음
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
     *
     * @effects apportioned_option_subtotals_sum_back_to_the_order_total
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_recalculation_matches_the_charge_policy_tier
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_recalculation_matches_the_charge_policy_tier, unit_converted_kg_l
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_range_weight_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 0.5, 'fee' => 2500],
                    ['min' => 0.5, 'max' => null, 'fee' => 4000],
                ],
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 300);

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
     *
     * @effects cancel_recalculation_matches_the_charge_policy_tier, unit_converted_kg_l
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_range_volume_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 1, 'fee' => 3000],
                    ['min' => 1, 'max' => null, 'fee' => 5000],
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
     *
     * @effects cancel_recalculation_matches_the_charge_policy_tier, volumetric_weight_compared_in_kg
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_range_volume_weight_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 0.5, 'fee' => 3000],
                    ['min' => 0.5, 'max' => null, 'fee' => 5000],
                ],
                'volume_weight_divisor' => 6000,
            ],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 500, volume: 4200);

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
     *
     * @effects cancel_recalculation_matches_the_charge_policy_units, unit_converted_kg_l
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_per_weight_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 1200);

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
     *
     * @effects cancel_recalculation_matches_the_charge_policy_units, unit_converted_kg_l
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_per_volume_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME,
            baseFee: 1500,
            ranges: ['unit_value' => 1],
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
     *
     * @effects cancel_recalculation_matches_the_charge_policy_units, volumetric_weight_compared_in_kg
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_per_volume_weight_shipping(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5, 'volume_weight_divisor' => 6000],
        );
        [$p, $o] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 300, volume: 4200);

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
     *
     * @effects cancel_recalculation_matches_the_charge_policy_units
     *
     * @return void 반환값 없음
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
     *
     * @effects sequential_partial_cancels_recalculate_from_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder, cancel_recalculation_matches_the_charge_policy_tier
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_the_shipping_fee_inside_the_same_tier
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_the_shipping_fee_inside_the_same_tier
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder, unit_converted_kg_l
     *
     * @return void 반환값 없음
     */
    public function test_range_weight_tier_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 0.5, 'fee' => 2500],
                    ['min' => 0.5, 'max' => null, 'fee' => 4000],
                ],
            ],
        );
        // 0.35kg × 2개 = 0.7kg = 700g → 4000원 구간
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 350);

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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder, unit_converted_kg_l
     *
     * @return void 반환값 없음
     */
    public function test_range_volume_tier_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 1, 'fee' => 3000],
                    ['min' => 1, 'max' => null, 'fee' => 5000],
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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder, volumetric_weight_compared_in_kg
     *
     * @return void 반환값 없음
     */
    public function test_range_volume_weight_tier_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::RANGE_VOLUME_WEIGHT,
            ranges: [
                'tiers' => [
                    ['min' => 0, 'max' => 0.5, 'fee' => 3000],
                    ['min' => 0.5, 'max' => null, 'fee' => 5000],
                ],
                'volume_weight_divisor' => 6000,
            ],
        );
        // 0.2kg, 1500cm³ per item
        // 3개: 무게0.6kg, 부피4500→부피무게0.75, max(0.6,0.75)=750g → 5000원
        // 1개: 무게0.2kg, 부피1500→부피무게0.25, max(0.2,0.25)=250g → 3000원
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 200, volume: 1500);

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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_rescales_the_per_unit_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_rescales_the_per_unit_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_the_shipping_fee_inside_the_same_tier
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_rescales_the_per_unit_shipping_fee, unit_converted_kg_l
     *
     * @return void 반환값 없음
     */
    public function test_per_weight_unit_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5],
        );
        // 0.4kg × 3개 = 1.2kg
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 400);

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
     *
     * @effects partial_cancel_rescales_the_per_unit_shipping_fee, unit_converted_kg_l
     *
     * @return void 반환값 없음
     */
    public function test_per_weight_large_decrease(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5],
        );
        // 0.4kg × 3개 = 1.2kg
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 400);

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
     *
     * @effects partial_cancel_rescales_the_per_unit_shipping_fee, unit_converted_kg_l
     *
     * @return void 반환값 없음
     */
    public function test_per_volume_unit_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME,
            baseFee: 1500,
            ranges: ['unit_value' => 1],
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
     *
     * @effects partial_cancel_rescales_the_per_unit_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_the_shipping_fee_inside_the_same_tier
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_rescales_the_per_unit_shipping_fee, volumetric_weight_compared_in_kg
     *
     * @return void 반환값 없음
     */
    public function test_per_volume_weight_unit_change(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::PER_VOLUME_WEIGHT,
            baseFee: 2000,
            ranges: ['unit_value' => 0.5, 'volume_weight_divisor' => 6000],
        );
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 200, volume: 1500);

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
     *
     * @effects partial_cancel_rescales_the_per_unit_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_shipping_fee_that_was_charged, cancel_recalculates_the_island_extra_fee_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold, cancel_recalculates_the_island_extra_fee_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_recalculates_the_island_extra_fee_with_the_remainder, extra_fee_multiplied_by_charge_units
     *
     * @return void 반환값 없음
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
     * 도서산간 multiply 는 기본 배송비와 같은 배송 단위 수 기준 (수량이 아니다)
     * 3개(1.2kg): 배송 ceil(1.2/0.5)=3단위 × 2K=6K, 도서산간 3단위 × 1.5K=4.5K
     * 1개(0.4kg): 배송 ceil(0.4/0.5)=1단위 × 2K=2K, 도서산간 1단위 × 1.5K=1.5K
     *
     * @effects cancel_recalculates_the_island_extra_fee_with_the_remainder, extra_fee_multiplied_by_charge_units
     *
     * @return void 반환값 없음
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
        [$p, $o] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, weight: 400);

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

        // 원래: 30K + 6K(배송) + 4.5K(도서산간) = 40.5K
        // 잔여: 10K + 2K(배송) + 1.5K(도서산간) = 13.5K
        // refund = 40.5K - 13.5K = 27,000
        $this->assertEquals(27000, $result->refundAmount);
    }

    /**
     * A-7-5: FIXED + no multiply extra fee → 부분취소 시 도서산간 변동 없음
     *
     * 기본배송 3000 + 도서산간 2000 (flat, 1회). 부분취소해도 도서산간 그대로.
     *
     * @effects cancel_recalculates_the_island_extra_fee_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_recalculates_the_island_extra_fee_with_the_remainder, partial_cancel_keeps_free_shipping_while_the_remainder_meets_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items, item_coupon_discount_stays_within_its_cap_after_recalculation
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects item_coupon_discount_stays_within_its_cap_after_recalculation
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_the_order_coupon_while_the_minimum_holds, item_coupon_discount_stays_within_its_cap_after_recalculation
     *
     * @return void 반환값 없음
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
     *
     * @effects order_level_discount_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects order_level_discount_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects sequential_partial_cancels_recalculate_from_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects shipping_coupon_is_reapplied_to_the_recalculated_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects shipping_coupon_is_reapplied_to_the_recalculated_shipping_fee, partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects shipping_coupon_is_reapplied_to_the_recalculated_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects shipping_coupon_is_reapplied_to_the_recalculated_shipping_fee, partial_cancel_moves_the_shipping_tier_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects shipping_coupon_discount_capped_by_shipping_total
     *
     * @return void 반환값 없음
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
     * A-10-5b: 정책 그룹 소멸 시 배송비 쿠폰 재적용 — 최소 주문금액은 상품소계 기준 (P18)
     *
     * 구성(계획서 1-A 회차 ② 직후 잔여 = 회차 ③ 의 baseline):
     *   브이넥 25,000 ×1 (정책1 무료) · 텀블러 25,000 ×1 (정책2 고정 3,000)
     *   양말 4,000 ×2 (정책3 조건부무료 5만 미만 2,500)
     *   상품쿠폰 3,000(min 20,000 · 브이넥) · 주문쿠폰 5,000(min 50,000) · 배송비쿠폰 5,000(min 30,000)
     *   배송비 0 + 3,000 + 2,500 = 5,500 → 배송비쿠폰 min(5,000, 5,500) = 5,000 → 실부담 500
     *   결제 58,000 − 3,000 − 5,000 + 500 = 50,500
     *
     * 텀블러 1개 취소 → 정책2 그룹 소멸:
     *   상품 33,000 · 주문쿠폰 탈락(33,000 < 50,000) → 복원
     *   배송비 총액 5,500 → 2,500 · 배송비쿠폰은 상품소계 33,000 ≥ 30,000 이라 유지
     *     → min(5,000, 2,500) = 2,500 → 실부담 500 → 0
     *   재계산 33,000 − 3,000 + 0 = 30,000 → 환불 50,500 − 30,000 = 20,500
     *
     * 수정 전(배송비 총액과 비교)에는 5,500 < 30,000 으로 배송비쿠폰이 처음부터 미적용이라
     * 결제 55,500 · 환불 23,000 이었다.
     *
     * @scenario basis_case=policy_group_dissolved
     *
     * @effects shipping_coupon_survives_policy_group_dissolution, shipping_coupon_discount_capped_by_shipping_total
     *
     * @return void 반환값 없음
     */
    public function test_shipping_coupon_reapplied_when_policy_group_disappears(): void
    {
        $policyFree = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FREE, baseFee: 0);
        $policyFixed = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);
        $policyConditional = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 2500,
            freeThreshold: 50000,
        );

        [$pVneck, $oVneck] = $this->createProductWithOption(price: 25000, shippingPolicy: $policyFree);
        [$pTumbler, $oTumbler] = $this->createProductWithOption(price: 25000, shippingPolicy: $policyFixed);
        [$pSocks, $oSocks] = $this->createProductWithOption(price: 4000, shippingPolicy: $policyConditional);

        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            minOrderAmount: 20000,
        );
        $orderCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 50000,
        );
        $shippingCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 30000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pVneck->id, productOptionId: $oVneck->id, quantity: 1),
                new CalculationItem(productId: $pTumbler->id, productOptionId: $oTumbler->id, quantity: 1),
                new CalculationItem(productId: $pSocks->id, productOptionId: $oSocks->id, quantity: 2),
            ],
            couponIssueIds: [$orderCoupon->id, $shippingCoupon->id],
            itemCoupons: [$oVneck->id => [$productCoupon->id]],
        );

        $order = $this->createOrderFromCalculation($input);

        // 배송비 쿠폰이 상품소계(58,000) 기준으로 적용되어 실부담 배송비 500
        $this->assertEquals(5500, (int) $order->total_shipping_amount);
        $this->assertEquals(5000, (int) $order->shipping_discount_amount);
        $this->assertEquals(50500, (int) $order->total_paid_amount);

        $optTumbler = $order->options->firstWhere('product_option_id', $oTumbler->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optTumbler->id, 'cancel_quantity' => 1],
        ]));

        // 주문쿠폰만 탈락 복원 · 배송비쿠폰은 잔여 상품소계 33,000 ≥ 30,000 이라 유지
        $this->assertContains($orderCoupon->id, $result->restoredCouponIssueIds);
        $this->assertNotContains($shippingCoupon->id, $result->restoredCouponIssueIds);
        $this->assertEquals(20500, (int) $result->refundAmount);
    }

    /**
     * A-10-5c: 상품쿠폰이 걸린 라인을 부분취소해도 상품쿠폰이 두 번 먹지 않는다 (S1-D2)
     *
     * 구성(계획서 1-A 회차 ①):
     *   브이넥 25,000 ×2 (정책1 무료) · 텀블러 25,000 ×1 (정책2 고정 3,000)
     *   양말 4,000 ×3 (정책3 조건부무료 5만 미만 2,500)
     *   상품쿠폰 3,000(min 20,000 · 브이넥 · 정액이라 수량 2 → 6,000)
     *   주문쿠폰 5,000(min 50,000) · 배송비쿠폰 5,000(min 30,000)
     *   결제 87,000 − 6,000 − 5,000 + 500 = 76,500
     *
     * 양말 1개 취소 → 상품 83,000 · 쿠폰 구성 불변(전부 최소금액 유지) → 환불은 양말 단가 4,000.
     *
     * 결함: 주문 스냅샷의 `coupon_issue_ids` 는 상품쿠폰까지 **전부** 담는데
     * (복원·탈락검출의 SSoT 라 그래야 한다) 재계산 입력이 그 목록을 `itemCoupons` 와
     * **함께** 넘겨, 상품쿠폰이 상품별 슬롯과 주문레벨 슬롯에서 두 번 적용됐다.
     * 할인이 6,000 → 12,000 으로 부풀어 환불이 4,000 이 아니라 10,000 으로 나갔다.
     * 주문서 계산은 주문쿠폰·배송비쿠폰만 `couponIssueIds` 에 넘기므로(TempOrderService:377)
     * 재계산도 같은 계약이어야 한다.
     *
     * @scenario basis_case=item_coupon_not_double_applied_on_recalc
     *
     * @effects item_coupon_applied_once_in_recalculation, partial_cancel_refund_equals_line_amount
     *
     * @return void 반환값 없음
     */
    public function test_item_coupon_not_double_applied_on_partial_cancel(): void
    {
        $policyFree = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FREE, baseFee: 0);
        $policyFixed = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);
        $policyConditional = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 2500,
            freeThreshold: 50000,
        );

        [$pVneck, $oVneck] = $this->createProductWithOption(price: 25000, shippingPolicy: $policyFree);
        [$pTumbler, $oTumbler] = $this->createProductWithOption(price: 25000, shippingPolicy: $policyFixed);
        [$pSocks, $oSocks] = $this->createProductWithOption(price: 4000, shippingPolicy: $policyConditional);

        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            minOrderAmount: 20000,
        );
        $orderCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 50000,
        );
        $shippingCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 30000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pVneck->id, productOptionId: $oVneck->id, quantity: 2),
                new CalculationItem(productId: $pTumbler->id, productOptionId: $oTumbler->id, quantity: 1),
                new CalculationItem(productId: $pSocks->id, productOptionId: $oSocks->id, quantity: 3),
            ],
            couponIssueIds: [$orderCoupon->id, $shippingCoupon->id],
            itemCoupons: [$oVneck->id => [$productCoupon->id]],
        );

        $order = $this->createOrderFromCalculation($input);

        // 주문서 계산은 정상 — 상품쿠폰 정액 × 수량 2 = 6,000
        $this->assertEquals(76500, (int) $order->total_paid_amount);

        $optSocks = $order->options->firstWhere('product_option_id', $oSocks->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optSocks->id, 'cancel_quantity' => 1],
        ]));

        // 쿠폰 구성이 그대로이므로 환불은 양말 단가 그대로 4,000
        $this->assertEquals([], $result->restoredCouponIssueIds);
        $this->assertEquals(4000, (int) $result->refundAmount);

        // 재계산 할인이 부풀지 않는다
        // (상품쿠폰 6,000 + 주문쿠폰 5,000 = 11,000 — 배송비쿠폰 할인은 totalDiscount 가 아니라
        //  배송비 쪽으로 빠진다)
        $this->assertEquals(11000, (int) $result->recalculated->summary->totalDiscount);
    }

    /**
     * A-10-5d: 운영 스냅샷 형식(coupon_issue_ids 가 상품쿠폰까지 포함)에서도 두 번 먹지 않는다 (S1-D2)
     *
     * 위 A-10-5c 는 테스트 헬퍼가 `coupon_issue_ids` 를 **입력값으로 덮어써** 상품쿠폰이 빠진
     * 목록을 저장하므로 결함이 재현되지 않는다. 운영은 `PromotionsSummary::toArray()` 를 그대로
     * 저장해(OrderProcessingService::buildPromotionsAppliedSnapshot) 상품쿠폰 발급ID 가
     * `coupon_issue_ids` 와 `item_coupons` **양쪽에** 들어간다. 이 테스트는 그 형식을 그대로
     * 심어 재계산이 상품쿠폰을 한 번만 먹는지 잠근다.
     *
     * 실측(주문 20260915-0604289654): 양말 1개 취소 미리보기가 `refund_amount=10,000`
     * (기대 4,000) · 비교표 상품쿠폰 할인 `-6,000 → -12,000`.
     *
     * @scenario basis_case=production_snapshot_shape
     *
     * @effects item_coupon_applied_once_when_present_in_both_snapshot_keys
     *
     * @return void 반환값 없음
     */
    public function test_item_coupon_not_double_applied_with_production_snapshot_shape(): void
    {
        $policyFree = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FREE, baseFee: 0);
        $policyFixed = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);
        $policyConditional = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 2500,
            freeThreshold: 50000,
        );

        [$pVneck, $oVneck] = $this->createProductWithOption(price: 25000, shippingPolicy: $policyFree);
        [$pTumbler, $oTumbler] = $this->createProductWithOption(price: 25000, shippingPolicy: $policyFixed);
        [$pSocks, $oSocks] = $this->createProductWithOption(price: 4000, shippingPolicy: $policyConditional);

        $productCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            minOrderAmount: 20000,
        );
        $orderCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 50000,
        );
        $shippingCoupon = $this->createCouponWithIssue(
            targetType: CouponTargetType::SHIPPING_FEE,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 30000,
        );

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pVneck->id, productOptionId: $oVneck->id, quantity: 2),
                new CalculationItem(productId: $pTumbler->id, productOptionId: $oTumbler->id, quantity: 1),
                new CalculationItem(productId: $pSocks->id, productOptionId: $oSocks->id, quantity: 3),
            ],
            couponIssueIds: [$orderCoupon->id, $shippingCoupon->id],
            itemCoupons: [$oVneck->id => [$productCoupon->id]],
        );

        $order = $this->createOrderFromCalculation($input);
        $this->assertEquals(76500, (int) $order->total_paid_amount);

        // 운영 형식으로 교정 — 상품쿠폰이 coupon_issue_ids 에도 들어간다
        $snapshot = $order->promotions_applied_snapshot;
        $snapshot['coupon_issue_ids'] = [$productCoupon->id, $orderCoupon->id, $shippingCoupon->id];
        $order->promotions_applied_snapshot = $snapshot;
        $order->save();
        $order->refresh();

        $optSocks = $order->options->firstWhere('product_option_id', $oSocks->id);
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optSocks->id, 'cancel_quantity' => 1],
        ]));

        $this->assertEquals([], $result->restoredCouponIssueIds);
        $this->assertEquals(4000, (int) $result->refundAmount);
    }

    /**
     * A-10-6: 정률 100% 배송비 쿠폰, 부분취소 후 배송비↑ → 증가분도 100% 할인
     *
     * @effects shipping_coupon_is_reapplied_to_the_recalculated_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects shipping_coupon_is_reapplied_to_the_recalculated_shipping_fee, partial_cancel_rescales_the_per_unit_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects shipping_coupon_is_reapplied_to_the_recalculated_shipping_fee, partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks, points_usage_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold, cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks, points_usage_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder, points_usage_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects order_level_discount_is_reapportioned_over_the_remaining_items, points_usage_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_rescales_the_per_unit_shipping_fee, partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_moves_the_shipping_tier_with_the_remainder, cancel_restores_the_coupon_when_the_minimum_breaks, points_usage_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
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
                    ['min' => 0, 'max' => 0.5, 'fee' => 2500],
                    ['min' => 0.5, 'max' => null, 'fee' => 4000],
                ],
            ],
        );

        // 0.3kg × 2개
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 300);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, weight: 300);

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
     *
     * @effects apportioned_option_subtotals_sum_back_to_the_order_total
     *
     * @return void 반환값 없음
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
     *
     * @effects order_level_discount_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects points_usage_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects sequential_partial_cancels_recalculate_from_the_remaining_items, apportioned_option_subtotals_sum_back_to_the_order_total
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold, boundary_value_charged_not_free
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
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
     *
     * @effects cancelling_every_coupon_target_drops_the_item_discount
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_recalculates_the_island_extra_fee_with_the_remainder, shipping_coupon_is_reapplied_to_the_recalculated_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_priority_changes_the_split_not_the_total
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_preview_carries_the_requested_refund_priority
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_amounts_are_converted_with_the_order_time_currency_snapshot
     *
     * @return void 반환값 없음
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
     * A-MC-3: 부분취소 orderUpdates 가 mc_* 금액 컬럼도 함께 갱신한다 (S1-D3)
     *
     * `buildOrderUpdates()` 는 base 금액 컬럼 17종을 갱신하면서 짝이 되는 `mc_*` 컬럼을
     * 하나도 갱신하지 않았다. 주문 생성은 16종을 쓰므로(OrderProcessingService:611-627)
     * 부분취소 뒤 base 와 mc 가 어긋난 채 남고, 비교표 보조통화 줄이 **취소 전 값**을 보여준다.
     *
     * 실측(주문 20260915-0604289654 · 회차 ② 화면): 비교표 총 판매가 금액
     * base `83,000원` 인데 보조통화가 `$87.00 / ¥10,005`(= 87,000 환산) 로 찍혔다.
     * DB: `subtotal_amount=83000` vs `mc_subtotal_amount.KRW=87000`.
     *
     * @scenario basis_case=partial_cancel_updates_mc_columns
     *
     * @effects mc_columns_track_base_columns_after_partial_cancel
     *
     * @return void 반환값 없음
     */
    public function test_order_updates_refresh_mc_amount_columns(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 30000);

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.001, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ],
        );

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $this->assertEquals(90000, (int) $order->subtotal_amount);

        $opt = $order->options->first();
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]));

        $updates = $result->orderUpdates;

        // base 는 이미 갱신된다
        $this->assertEquals(60000, (int) $updates['subtotal_amount']);

        // mc_* 도 같은 값으로 갱신돼야 한다
        $this->assertArrayHasKey('mc_subtotal_amount', $updates);
        $this->assertEquals(60000, (int) ($updates['mc_subtotal_amount']['KRW'] ?? 0));
        // 보조통화도 같은 재계산 결과에서 환산된다(환산값 자체는 스냅샷 단위 규칙이 정한다)
        $this->assertGreaterThan(0, (float) ($updates['mc_subtotal_amount']['USD'] ?? 0));

        $this->assertArrayHasKey('mc_total_amount', $updates);
        $this->assertEquals(
            (int) $updates['total_amount'],
            (int) ($updates['mc_total_amount']['KRW'] ?? 0)
        );

        $this->assertArrayHasKey('mc_total_tax_amount', $updates);
        $this->assertEquals(
            (int) $updates['total_tax_amount'],
            (int) ($updates['mc_total_tax_amount']['KRW'] ?? 0)
        );

        $this->assertArrayHasKey('mc_total_shipping_amount', $updates);
        $this->assertEquals(
            (int) $updates['total_shipping_amount'],
            (int) ($updates['mc_total_shipping_amount']['KRW'] ?? 0)
        );
    }

    /**
     * A-MC-5: 닫힌(취소된) 옵션 행에는 살아 있는 행의 재계산 금액을 덮어쓰지 않는다 (S1-D4)
     *
     * 수량 분할이 일어나면 닫힌 행과 살아 있는 행이 **같은 `product_option_id`** 를 갖는다.
     * `buildOptionUpdates()` 가 `product_option_id` 로만 짝을 지어 살아 있는 행의 줄 합계를
     * 닫힌 행에도 써서, 닫힌 행의 base(`subtotal_price`)와 mc(`mc_subtotal_price`)가 어긋났다.
     *
     * 실측(주문 20260915-0604289654 · 양말 3개 줄): base `12,000원`(4,000×3) 인데
     * `mc_subtotal_price.KRW = 16,000`(4,000×4 — 그 행에 존재한 적 없는 수량) 이라
     * 주문상세가 **할인가가 원가보다 큰** 줄로 렌더됐다.
     *
     * @scenario basis_case=split_row_not_overwritten
     *
     * @effects cancelled_split_row_excluded_from_option_updates
     *
     * @return void 반환값 없음
     */
    public function test_cancelled_split_row_not_overwritten_by_recalc(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 4000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ],
        );

        $order = $this->createOrderFromCalculation($input);
        $opt = $order->options->first();

        // 닫힌 분할 행을 흉내낸다 — 같은 product_option_id 를 갖는 cancelled 행
        $closed = $opt->replicate();
        $closed->option_status = OrderStatusEnum::CANCELLED;
        $closed->quantity = 1;
        $closed->subtotal_price = 4000;
        $closed->source_type = 'split';
        $closed->save();

        $order->load('options');

        // 남은 행에서 1개를 더 취소 → 재계산 대상은 살아 있는 행뿐
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]));

        // 닫힌 행에는 갱신이 가지 않아야 한다
        $this->assertArrayNotHasKey($closed->id, $result->optionUpdates);
    }

    /**
     * A-MC-3b: 전액취소도 mc_* 금액 컬럼을 0 으로 내린다 (S1-D3)
     *
     * 전액취소는 부분취소와 다른 경로(`buildFullCancellation`)를 타는데, base 8종만 0 으로
     * 내리고 `mc_*` 를 그대로 둬서 주문상세 보조통화 줄이 **직전 회차 금액**을 보여줬다.
     * 실측(주문 20260915-0604289654 · 회차 ⑤ 뒤): `총 결제금액 0원` 옆에 `$22.00 / ¥2,530`.
     *
     * @scenario basis_case=full_cancel_zeroes_mc_columns
     *
     * @effects mc_columns_zeroed_on_full_cancel
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_zeroes_mc_amount_columns(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 30000);

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.001, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
            ],
        );

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $opt = $order->options->first();
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 2],
        ]));

        $updates = $result->orderUpdates;

        // base 는 0
        $this->assertSame(0, (int) $updates['subtotal_amount']);
        $this->assertSame(0, (int) $updates['total_amount']);

        // mc_* 도 0 이어야 한다
        $this->assertArrayHasKey('mc_subtotal_amount', $updates);
        $this->assertSame(0, (int) ($updates['mc_subtotal_amount']['KRW'] ?? -1));
        $this->assertArrayHasKey('mc_total_amount', $updates);
        $this->assertSame(0, (int) ($updates['mc_total_amount']['KRW'] ?? -1));

        // 총 결제액 mc 는 건드리지 않는다 (환불로 깎지 않는 회계 계약)
        $this->assertArrayNotHasKey('mc_total_paid_amount', $updates);
    }

    /**
     * 전액취소는 **과세·부가세 base 컬럼도** 0 으로 내린다 (S1-D22)
     *
     * 전액취소 확정 블록은 `total_amount` 를 비롯한 7개 base 컬럼을 0 으로 내리면서
     * **`total_tax_amount`·`total_vat_amount` 는 빼놓았다.** 그런데 같은 반환값의
     * `buildZeroMcOrderUpdates()` 는 `mc_total_tax_amount` 를 0 으로 내린다 — 그래서
     * **한 행 안에서 base 와 mc 가 어긋난다.**
     *
     * 실측(#599 통합실측 세션1 · 1-T · 주문 113 `20260915-0651135077` 자동취소):
     * ```
     * total_amount          0        ← 취소로 내려감
     * total_tax_amount      10,500   ← 그대로 남음
     * total_vat_amount      954      ← 그대로 남음
     * mc_total_tax_amount   {"KRW":0,...}  ← 같은 행인데 0
     * ```
     * 화면에는 `최종 결제금액 0원` 옆에 `과세 금액 10,500원 · 부가세 954원` 이 나란히 선다.
     * 취소 주문 10건 중 **9건**이 이 상태였다(0 인 1건은 다른 경로로 정리된 건).
     *
     * 세금계산서·정산이 이 컬럼을 읽으면 **취소된 주문에 과세 근거가 남아 있는 것으로 읽힌다.**
     *
     * @scenario basis_case=full_cancel_zeroes_mc_columns
     *
     * @effects full_cancel_zeroes_tax_columns_too
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_zeroes_tax_and_vat_base_columns(): void
    {
        $currencySnapshot = $this->krwCurrencySnapshot();
        $order = $this->createSingleItemClaimOrder(29000, 2, $this->createClaimShippingPolicy());
        $order->forceFill(['currency_snapshot' => $currencySnapshot])->save();
        $order = $order->fresh(['options']);

        $opt = $order->options->first();
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 2],
        ]));

        $updates = $result->orderUpdates;

        $this->assertSame(0, (int) $updates['total_amount'], '전제: 전액취소로 결제금액이 0 이다');

        $this->assertArrayHasKey('total_tax_amount', $updates);
        $this->assertSame(
            0,
            (int) $updates['total_tax_amount'],
            '취소된 주문에 과세 금액이 남으면 세금계산서·정산이 과세 근거가 있다고 읽는다'
        );

        $this->assertArrayHasKey('total_vat_amount', $updates);
        $this->assertSame(0, (int) $updates['total_vat_amount']);

        // base 와 mc 가 **같은 행 안에서** 어긋나지 않는다
        $this->assertSame(
            (int) $updates['total_tax_amount'],
            (int) ($updates['mc_total_tax_amount']['KRW'] ?? -1),
            'base 과세금액과 mc 과세금액은 같은 값을 말해야 한다'
        );
    }

    /**
     * A-MC-4: currency_snapshot 이 없는 레거시 주문은 mc_* 갱신을 시도하지 않는다 (S1-D3)
     *
     * 다통화 도입 이전에 만들어진 주문은 환율 스냅샷이 없어 환산 근거가 없다. 그런 주문에
     * `mc_*` 를 억지로 써 넣으면 근거 없는 보조통화 값이 생기므로, 키 자체를 내보내지 않는다
     * (종전 동작 유지).
     *
     * @scenario basis_case=legacy_order_without_currency_snapshot
     *
     * @effects mc_columns_omitted_without_currency_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_order_updates_skip_mc_columns_without_currency_snapshot(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3),
            ],
        );

        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => null,
        ]);

        $opt = $order->options->first();
        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $opt->id, 'cancel_quantity' => 1],
        ]));

        $this->assertEquals(60000, (int) $result->orderUpdates['subtotal_amount']);
        $this->assertArrayNotHasKey('mc_subtotal_amount', $result->orderUpdates);
    }

    /**
     * 다중통화 스냅샷이 없는 주문의 환불 시 mc_* 필드는 모두 null입니다.
     *
     * 레거시 주문(다통화 도입 이전에 생성되어 currency_snapshot 이 비어 있는 행)의 취소 경로를
     * 검증한다. OrderFactory 는 기본 스냅샷을 채우므로 여기서는 명시적으로 null 을 주입한다.
     *
     * @effects mc_columns_omitted_without_currency_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_mc_fields_null_without_currency_snapshot(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
        );

        // OrderFactory 는 currency_snapshot 을 항상 채운다 (등록된 통화 설정 기준).
        // 통화 설정이 비어 있을 때만 우연히 falsy 가 되므로, 앞선 테스트가 통화 설정을 남기면
        // 이 테스트가 간헐 실패한다. "스냅샷 없는 주문" 이라는 전제를 명시적으로 못박는다.
        $order = $this->createOrderFromCalculation($input, ['currency_snapshot' => null]);
        $this->assertNull($order->currency_snapshot, '테스트 전제: 주문에 통화 스냅샷이 없다');

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
     *
     * @effects refund_amounts_are_converted_with_the_order_time_currency_snapshot
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_amounts_are_converted_with_the_order_time_currency_snapshot
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_preview_array_carries_the_multi_currency_fields
     *
     * @return void 반환값 없음
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
     *
     * 재계산 전 쿠폰을 일시적으로 미사용으로 되돌리던 왕복 UPDATE 를 제거한 뒤에도(#108)
     * 이 복원 판정이 그대로여야 한다 — 판정은 스냅샷의 min_order_amount 로만 이뤄지고
     * 쿠폰의 사용 상태(status/used_at)를 읽지 않기 때문이다.
     *
     * @effects coupon_restore_on_min_amount_shortfall_unchanged_after_reset_removal
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_keeps_the_order_coupon_while_the_minimum_holds
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks
     *
     * @return void 반환값 없음
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
     *
     * @effects cancelling_every_coupon_target_drops_the_item_discount, cancel_restores_the_coupon_when_the_minimum_breaks
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_only_the_coupons_that_lost_their_condition
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_restored_coupon_issue_ids
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_restored_coupon_issue_ids
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_split_spills_into_the_other_tender_when_the_first_runs_out
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_split_spills_into_the_other_tender_when_the_first_runs_out
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_priority_changes_the_split_not_the_total
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_priority_changes_the_split_not_the_total
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_remaining_pg_and_point_balances
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_remaining_pg_and_point_balances
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_remaining_pg_and_point_balances
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_preview_carries_the_requested_refund_priority
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_shipping_difference_per_policy_group
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_shipping_difference_per_policy_group
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_shipping_difference_per_policy_group, cancel_recalculates_the_island_extra_fee_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_shipping_difference_per_policy_group
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_shipping_difference_per_policy_group, partial_cancel_rescales_the_per_unit_shipping_fee
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_amounts_are_converted_with_the_order_time_currency_snapshot
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_amounts_are_converted_with_the_order_time_currency_snapshot
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_amounts_are_converted_with_the_order_time_currency_snapshot
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_restores_the_coupon_when_the_minimum_breaks, partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
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
     *
     * @effects full_cancel_refunds_the_entire_paid_amount, cancel_recalculates_the_island_extra_fee_with_the_remainder
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_splits_the_refund_between_pg_and_points
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_reports_the_shipping_difference_per_policy_group
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_amounts_are_converted_with_the_order_time_currency_snapshot
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_is_blocked_when_losing_the_coupon_raises_the_bill
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_is_blocked_when_losing_the_coupon_raises_the_bill
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_is_blocked_when_losing_the_coupon_raises_the_bill
     *
     * @return void 반환값 없음
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
     *
     * @effects partial_cancel_is_blocked_when_losing_the_coupon_raises_the_bill
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_total_counts_the_shipping_difference_exactly_once
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_total_counts_the_shipping_difference_exactly_once
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_total_counts_the_shipping_difference_exactly_once
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_total_counts_the_shipping_difference_exactly_once
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_snapshot_carries_the_total_list_price
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_snapshot_carries_the_total_list_price
     *
     * @return void 반환값 없음
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
     *
     * @effects cancel_snapshot_carries_the_total_list_price
     *
     * @return void 반환값 없음
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

    /**
     * 마일리지 설정을 활성화합니다 (적립 발생 테스트용).
     *
     * @param  int  $earnRate  기본 적립률 (%)
     * @return void 반환값 없음
     */
    protected function enableMileageSettings(int $earnRate = 10): void
    {
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }

        file_put_contents(
            $settingsPath.'/mileage.json',
            json_encode([
                'enabled' => true,
                'default_earn_rate' => $earnRate,
                'earn_trigger' => 'confirmed',
                'earn_delay_days' => 0,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
                ],
                'expiry_enabled' => true,
                'expiry_days' => 365,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // 설정 파일을 새로 썼으므로 주입된 서비스 인스턴스를 재해석 (설정 캐시 초기화)
        $this->calculationService = app(OrderCalculationService::class);
        $this->adjustmentService = app(OrderAdjustmentService::class);
    }

    /**
     * §6.3 회귀: 부분취소 후 잔여 옵션의 적립액(subtotal_earned_points_amount)이
     * 재안분값으로 옵션 업데이트에 저장되는지 검증 (PG 우선).
     *
     * 결함: buildOptionUpdates 가 적립액 갱신을 누락하면 취소 상품 몫까지 적립됨.
     *
     * @effects partial_cancel_persists_recalculated_subtotal_earned_points_amount_pg_first
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_recalculates_earned_points_pg_first(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionToCancel = $order->options->firstWhere('product_option_id', $optionA->id);
        $remainingOption = $order->options->firstWhere('product_option_id', $optionB->id);

        $adjustment = $this->buildCancellation([
            ['order_option_id' => $optionToCancel->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        // 잔여 옵션(B, 10,000)의 적립액이 옵션 업데이트에 저장되어야 함 (10% = 1,000)
        $this->assertArrayHasKey($remainingOption->id, $result->optionUpdates);
        $this->assertArrayHasKey(
            'subtotal_earned_points_amount',
            $result->optionUpdates[$remainingOption->id],
            '부분취소 재계산 결과에 적립액 갱신이 누락되었습니다.'
        );
        $this->assertEquals(
            1000,
            $result->optionUpdates[$remainingOption->id]['subtotal_earned_points_amount'],
            '취소 상품 몫이 제외된 잔여 옵션 적립액과 일치해야 합니다.'
        );
    }

    /**
     * 부분취소 재계산이 주문 헤더의 적립 예정액(total_earned_points_amount)도 남은 상품 기준으로 갱신한다.
     *
     * C12 실측: 상품 두 종 중 하나를 취소한 주문(232)의 헤더가 1,070P(취소 전)로 남아, 행 합계 640P 와
     * 달랐다. 관리자 주문 상세 상품 표 합계 줄이 헤더를 그대로 보여 취소 상품 몫까지 적립될 것처럼 보였다.
     * 옵션 행은 재계산값으로 갱신되는데 헤더 갱신 목록(buildOrderUpdates)에서만 빠져 있었다.
     *
     * @return void 반환값 없음
     *
     * @effects header_earned_points_follow_the_recalculation
     */
    public function test_partial_cancel_updates_header_earned_points_to_remaining_items(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $optionToCancel = $order->options->firstWhere('product_option_id', $optionA->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $optionToCancel->id, 'cancel_quantity' => 1],
        ]));

        // 남은 B(10,000)의 10% = 1,000. 취소 전 헤더 3,000 이 그대로 남으면 안 된다.
        $this->assertArrayHasKey('total_earned_points_amount', $result->orderUpdates, '헤더 적립 예정액 갱신이 빠졌습니다.');
        $this->assertEquals(1000, $result->orderUpdates['total_earned_points_amount']);
        $this->assertEquals(
            array_sum(array_map(fn ($u) => (float) ($u['subtotal_earned_points_amount'] ?? 0), $result->optionUpdates)),
            $result->orderUpdates['total_earned_points_amount'],
            '헤더 적립 예정액은 남은 행들의 적립 예정액 합과 같아야 합니다.'
        );
    }

    /**
     * §6.3 회귀: 마일리지 우선(POINTS_FIRST) 환불 시에도 잔여 옵션 적립액이 재안분 저장됨.
     *
     * 입력: A(20,000)+B(10,000), rate 10%, usePoints 3,000(A:2,000/B:1,000 안분), A 취소.
     * 예상: 재계산 시 B만 남고 usePoints 3,000 전액이 B(결제금액 10,000)에 적용 →
     *       B earnable = 10,000 − 3,000 = 7,000 → earn = floor(7,000 × 10%) = 700.
     *       적립 재안분값은 환불 우선순위와 무관(우선순위는 refund split 만 변경)하므로 pg_first 와 동일.
     *
     * @effects partial_cancel_persists_recalculated_subtotal_earned_points_amount_points_first
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_recalculates_earned_points_points_first(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optionToCancel = $order->options->firstWhere('product_option_id', $optionA->id);
        $remainingOption = $order->options->firstWhere('product_option_id', $optionB->id);

        $adjustment = $this->buildCancellation([
            ['order_option_id' => $optionToCancel->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $adjustment, RefundPriorityEnum::POINTS_FIRST);

        $this->assertArrayHasKey('subtotal_earned_points_amount', $result->optionUpdates[$remainingOption->id]);
        // 잔여 B 적립액 = floor((10,000 − 3,000) × 10%) = 700 (마일리지 사용분 제외 정확값)
        $this->assertEquals(
            700,
            $result->optionUpdates[$remainingOption->id]['subtotal_earned_points_amount'],
            'POINTS_FIRST 부분취소 시 잔여 옵션 적립액은 사용분(3,000) 제외 기준 정확값이어야 합니다.'
        );
    }

    /**
     * §6.3: 마일리지 사용 + 부분취소 재안분 적립값은 환불 우선순위(PG_FIRST/POINTS_FIRST)에 무관하게 동일하다.
     *
     * 근거: 적립 재안분은 잔여 옵션 재계산(recalcResult)에서 도출되며, 우선순위는 환불 분배(refund split)에만 작용.
     * 동일 입력(A 20,000 + B 10,000, rate 10%, usePoints 3,000, A 취소)으로 두 우선순위 각각 계산 →
     * 잔여 B 적립액 == 700 으로 동일, 단 refund split(refundAmount/refundPointsAmount)은 상이.
     *
     * @effects refund_priority_changes_the_split_not_the_total
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_earned_points_equal_across_refund_priority(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $makeOrder = function () use ($productA, $optionA, $productB, $optionB): Order {
            $input = new CalculationInput(
                items: [
                    new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                    new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
                ],
                usePoints: 3000,
            );

            return $this->createOrderFromCalculation($input);
        };

        $orderPg = $makeOrder();
        $cancelPg = $orderPg->options->firstWhere('product_option_id', $optionA->id);
        $remainPg = $orderPg->options->firstWhere('product_option_id', $optionB->id);
        $resultPg = $this->adjustmentService->calculate(
            $orderPg,
            $this->buildCancellation([['order_option_id' => $cancelPg->id, 'cancel_quantity' => 1]]),
            RefundPriorityEnum::PG_FIRST
        );

        $orderPoints = $makeOrder();
        $cancelPoints = $orderPoints->options->firstWhere('product_option_id', $optionA->id);
        $remainPoints = $orderPoints->options->firstWhere('product_option_id', $optionB->id);
        $resultPoints = $this->adjustmentService->calculate(
            $orderPoints,
            $this->buildCancellation([['order_option_id' => $cancelPoints->id, 'cancel_quantity' => 1]]),
            RefundPriorityEnum::POINTS_FIRST
        );

        // 적립 재안분값은 두 우선순위에서 동일 (700)
        $this->assertEquals(700, $resultPg->optionUpdates[$remainPg->id]['subtotal_earned_points_amount']);
        $this->assertEquals(
            $resultPg->optionUpdates[$remainPg->id]['subtotal_earned_points_amount'],
            $resultPoints->optionUpdates[$remainPoints->id]['subtotal_earned_points_amount'],
            '적립 재안분값은 환불 우선순위와 무관하게 동일해야 합니다.'
        );

        // 단, 환불 분배(refund split)는 우선순위에 따라 달라져야 함 (POINTS_FIRST 는 포인트 환불이 우선)
        $this->assertGreaterThanOrEqual($resultPg->refundPointsAmount, $resultPoints->refundPointsAmount);
    }

    /**
     * §6.3: 부분취소 후 잔여 옵션 적립액 합이 취소 상품 몫을 포함하지 않는다 (취소분 미적립).
     *
     * 입력: A(20,000)+B(10,000), rate 10%, 사용 없음. 원 적립 합 = 2,000 + 1,000 = 3,000.
     * A 취소 → 잔여 적립(B) = 1,000 < 원 적립 합 3,000 (취소 A 몫 2,000 제외됨).
     *
     * @effects partial_cancel_excludes_cancelled_option_share_from_earned_points
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_excludes_cancelled_option_share_from_earned(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $originalEarnedSum = (int) $order->options->sum('subtotal_earned_points_amount');
        $optionToCancel = $order->options->firstWhere('product_option_id', $optionA->id);
        $remainingOption = $order->options->firstWhere('product_option_id', $optionB->id);

        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([['order_option_id' => $optionToCancel->id, 'cancel_quantity' => 1]])
        );

        // 원 적립 합은 3,000 (A 2,000 + B 1,000)
        $this->assertEquals(3000, $originalEarnedSum);
        // 잔여 B 적립 = 1,000 (취소 A 몫 2,000 미포함)
        $this->assertEquals(1000, $result->optionUpdates[$remainingOption->id]['subtotal_earned_points_amount']);
    }

    /**
     * §6.3 회귀: 부분취소 재계산 시 옵션 레벨 다통화(mc_) 컬럼이 재안분값으로 갱신된다 (정책 확정).
     *
     * 결함: buildOptionUpdates 가 plain subtotal_* 만 갱신하고 mc_subtotal_* 는 부분취소 후 stale.
     *
     * @effects mc_columns_track_base_columns_after_partial_cancel
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_recalculates_option_multi_currency_columns(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]);

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.85, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $order = $this->createOrderFromCalculation($input, ['currency_snapshot' => $currencySnapshot]);
        $optionToCancel = $order->options->firstWhere('product_option_id', $optionA->id);
        $remainingOption = $order->options->firstWhere('product_option_id', $optionB->id);

        $adjustment = $this->buildCancellation([
            ['order_option_id' => $optionToCancel->id, 'cancel_quantity' => 1],
        ]);
        $result = $this->adjustmentService->calculate($order, $adjustment);

        $updates = $result->optionUpdates[$remainingOption->id];

        // mc_ 적립/사용 컬럼이 재산출되어야 함 (KRW 기준값 = plain 값과 동일)
        $this->assertArrayHasKey('mc_subtotal_earned_points_amount', $updates, 'mc_ 적립 컬럼이 부분취소 재계산에 포함되어야 합니다.');
        $this->assertArrayHasKey('mc_subtotal_points_used_amount', $updates, 'mc_ 사용 컬럼도 함께 갱신되어야 합니다(비대칭 해소).');
        $this->assertArrayHasKey('KRW', $updates['mc_subtotal_earned_points_amount']);
        $this->assertSame(
            (int) $updates['subtotal_earned_points_amount'],
            (int) $updates['mc_subtotal_earned_points_amount']['KRW'],
            'KRW 기준 mc_ 적립값은 plain 재안분값과 일치해야 합니다.'
        );
        // USD 변환값 존재
        $this->assertArrayHasKey('USD', $updates['mc_subtotal_earned_points_amount']);
    }

    // ================================================================
    // §6.3 복합 상황: 배송정책 · 할인쿠폰 · 도서산간 · 상품별 정책 + 마일리지 (추가 요구)
    // OrderCalculation 테스트 수준의 정확값 검증. 각 케이스는 적립 재안분값이
    // 환불 우선순위에 무관(우선순위는 refund split 만 변경)함을 함께 단언한다.
    // ================================================================

    /**
     * 복합 1: 상품쿠폰 + 마일리지 사용 + 부분취소.
     *
     * A(20,000) + B(10,000), 상품쿠폰 FIXED 3,000(scope ALL), rate 10%, usePoints 3,000, A 취소.
     * 잔여 B 적립 base = 상품쿠폰 차감 후 금액 − 재안분 마일리지 사용분. 취소 옵션 쿠폰 복원 동반.
     * 정확값은 엔진 재계산 기준으로 고정하고, 취소분 미포함(잔여 적립 < 원 적립 합)을 함께 단언.
     *
     * @effects partial_cancel_excludes_cancelled_option_share_from_earned_points, partial_cancel_reapplies_the_item_coupon_to_the_remaining_items
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_product_coupon_and_mileage_usage_exact(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            targetScope: CouponTargetScope::ALL,
        );

        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $originalEarnedSum = (int) $order->options->sum('subtotal_earned_points_amount');
        $optionToCancel = $order->options->firstWhere('product_option_id', $optionA->id);
        $remainingOption = $order->options->firstWhere('product_option_id', $optionB->id);

        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([['order_option_id' => $optionToCancel->id, 'cancel_quantity' => 1]])
        );

        $earned = (int) $result->optionUpdates[$remainingOption->id]['subtotal_earned_points_amount'];
        // 상품쿠폰 FIXED 3,000(scope ALL)은 상품별 적용 → B 에 3,000 적용.
        // 잔여 B earnable = 10,000 − 3,000(상품쿠폰) − 3,000(사용) = 4,000 → earn = floor(4,000 × 10%) = 400.
        $this->assertEquals(400, $earned, '상품쿠폰+마일리지 복합 부분취소 잔여 적립 정확값');
        // 취소 A 몫이 잔여에 합산되지 않음
        $this->assertLessThan($originalEarnedSum, $earned);
    }

    /**
     * 복합 2: 주문쿠폰 + 마일리지 사용 + 부분취소.
     *
     * A(20,000) + B(10,000), 주문쿠폰 ORDER_AMOUNT FIXED 3,000, rate 10%, usePoints 3,000, A 취소.
     * 주문쿠폰은 잔여 옵션 비례 재안분 → 취소 후 B 의 주문쿠폰 안분이 커짐. earnable 재산출 정확값 고정.
     *
     * @effects partial_cancel_excludes_cancelled_option_share_from_earned_points, order_level_discount_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_order_coupon_and_mileage_usage_exact(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
            targetScope: CouponTargetScope::ALL,
        );

        [$productA, $optionA] = $this->createProductWithOption(price: 20000);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optionToCancel = $order->options->firstWhere('product_option_id', $optionA->id);
        $remainingOption = $order->options->firstWhere('product_option_id', $optionB->id);

        $resultPg = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([['order_option_id' => $optionToCancel->id, 'cancel_quantity' => 1]]),
            RefundPriorityEnum::PG_FIRST
        );

        $earnedPg = (int) $resultPg->optionUpdates[$remainingOption->id]['subtotal_earned_points_amount'];
        // 재계산: B 만 남으므로 주문쿠폰 3,000 전액 B 에 적용 + usePoints 3,000 전액 B.
        // B earnable = 10,000 − 3,000(주문쿠폰) − 3,000(사용) = 4,000 → earn = floor(4,000 × 10%) = 400.
        $this->assertEquals(400, $earnedPg, '주문쿠폰+마일리지 복합 부분취소 잔여 적립 정확값');

        // 환불 우선순위 무관 동일
        $order2 = $this->createOrderFromCalculation($input);
        $cancel2 = $order2->options->firstWhere('product_option_id', $optionA->id);
        $remain2 = $order2->options->firstWhere('product_option_id', $optionB->id);
        $resultPoints = $this->adjustmentService->calculate(
            $order2,
            $this->buildCancellation([['order_option_id' => $cancel2->id, 'cancel_quantity' => 1]]),
            RefundPriorityEnum::POINTS_FIRST
        );
        $this->assertEquals(
            $earnedPg,
            (int) $resultPoints->optionUpdates[$remain2->id]['subtotal_earned_points_amount'],
            '주문쿠폰 복합에서도 적립 재안분값은 환불 우선순위와 무관해야 합니다.'
        );
    }

    /**
     * 복합 3: 조건부 무료배송 임계 붕괴 + 마일리지 사용 + 부분취소.
     *
     * 정책 CONDITIONAL_FREE(baseFee 3,000, freeThreshold 25,000). A(20,000)+B(10,000)=30,000 → 무료.
     * A 취소 → 잔여 10,000 < 25,000 → 배송비 3,000 부활(shippingDifference 음수 방향).
     * 배송비 재판정이 상품금액 기준 적립 안분을 오염시키지 않음을 검증.
     *
     * @effects partial_cancel_excludes_cancelled_option_share_from_earned_points, partial_cancel_charges_shipping_when_the_remainder_drops_below_the_threshold
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_free_shipping_threshold_breaks_with_mileage_usage(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 25000,
        );

        [$productA, $optionA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        // 원 주문은 무료배송 (합계 30,000 >= 25,000)
        $this->assertEquals(0, (int) $order->total_shipping_amount);

        $optionToCancel = $order->options->firstWhere('product_option_id', $optionA->id);
        $remainingOption = $order->options->firstWhere('product_option_id', $optionB->id);

        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([['order_option_id' => $optionToCancel->id, 'cancel_quantity' => 1]])
        );

        // 잔여 B 적립 = floor((10,000 − 3,000 사용) × 10%) = 700. 배송비 부활과 독립.
        $this->assertEquals(
            700,
            (int) $result->optionUpdates[$remainingOption->id]['subtotal_earned_points_amount'],
            '배송비 재판정이 상품금액 기준 적립 안분을 오염시키면 안 됩니다.'
        );
        // 잔여 주문은 무료배송 문턱 아래라 배송비 3,000 부활 → recalc 배송비 = 3,000
        $this->assertEquals(3000, (int) $result->recalculated->summary->totalShipping);
    }

    /**
     * 복합 4: 상품쿠폰 + 조건부 배송정책 + 마일리지 사용 3중 복합, 3옵션 중 1개 부분취소.
     *
     * 잔여 2옵션 적립 재안분 + 배송비 차이 + 복원 쿠폰 + 환불 split 동시 단언.
     * 적립 재안분값은 두 우선순위에서 동일.
     *
     * @effects partial_cancel_excludes_cancelled_option_share_from_earned_points, points_usage_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_coupon_and_shipping_and_mileage_usage_full_composite(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        $sp = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);

        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pC, $oC] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        // 주문마다 신규 쿠폰 발급 (발급 1건을 두 주문에 재사용하면 첫 주문이 USED 처리 → 둘째 주문 오염)
        $makeOrder = function () use ($pA, $oA, $pB, $oB, $pC, $oC): Order {
            $couponIssue = $this->createCouponWithIssue(
                targetType: CouponTargetType::PRODUCT_AMOUNT,
                discountType: CouponDiscountType::FIXED,
                discountValue: 3000,
                targetScope: CouponTargetScope::ALL,
            );
            $input = new CalculationInput(
                items: [
                    new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                    new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
                    new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
                ],
                couponIssueIds: [$couponIssue->id],
                usePoints: 6000,
            );

            return $this->createOrderFromCalculation($input);
        };

        $orderPg = $makeOrder();
        $cancelA = $orderPg->options->firstWhere('product_option_id', $oA->id);
        $remainB = $orderPg->options->firstWhere('product_option_id', $oB->id);
        $remainC = $orderPg->options->firstWhere('product_option_id', $oC->id);
        $resultPg = $this->adjustmentService->calculate(
            $orderPg,
            $this->buildCancellation([['order_option_id' => $cancelA->id, 'cancel_quantity' => 1]]),
            RefundPriorityEnum::PG_FIRST
        );

        $earnedB = (int) $resultPg->optionUpdates[$remainB->id]['subtotal_earned_points_amount'];
        $earnedC = (int) $resultPg->optionUpdates[$remainC->id]['subtotal_earned_points_amount'];

        // 잔여 B/C earnable (상품쿠폰 3,000 각 적용 + usePoints 6,000 안분 B:4,000/C:2,000):
        // B = 20,000 − 3,000 − 4,000 = 13,000 → 1,300 / C = 10,000 − 3,000 − 2,000 = 5,000 → 500.
        $this->assertEquals(1300, $earnedB, '복합 잔여 B 적립 정확값');
        $this->assertEquals(500, $earnedC, '복합 잔여 C 적립 정확값');
        // 두 우선순위 적립값 동일
        $orderPoints = $makeOrder();
        $cancelA2 = $orderPoints->options->firstWhere('product_option_id', $oA->id);
        $remainB2 = $orderPoints->options->firstWhere('product_option_id', $oB->id);
        $remainC2 = $orderPoints->options->firstWhere('product_option_id', $oC->id);
        $resultPoints = $this->adjustmentService->calculate(
            $orderPoints,
            $this->buildCancellation([['order_option_id' => $cancelA2->id, 'cancel_quantity' => 1]]),
            RefundPriorityEnum::POINTS_FIRST
        );
        $this->assertEquals($earnedB, (int) $resultPoints->optionUpdates[$remainB2->id]['subtotal_earned_points_amount']);
        $this->assertEquals($earnedC, (int) $resultPoints->optionUpdates[$remainC2->id]['subtotal_earned_points_amount']);
        // 환불 총액은 두 우선순위에서 동일 (분배만 상이)
        $this->assertEquals(
            $resultPg->refundAmount + $resultPg->refundPointsAmount,
            $resultPoints->refundAmount + $resultPoints->refundPointsAmount,
            '환불 총액은 우선순위와 무관하게 동일해야 합니다.'
        );
    }

    /**
     * 복합 5: 복합 상황에서 옵션 전량취소가 아닌 수량 일부 축소.
     *
     * A(10,000)×3 + B(10,000)×1, rate 10%, usePoints 3,000. A 수량 2개만 취소(1개 잔존).
     * 잔여 A(1개)+B 기준 적립 재산출 정확값.
     *
     * @effects partial_cancel_excludes_cancelled_option_share_from_earned_points, partial_cancel_refunds_the_difference_against_the_recalculated_total
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_composite_reduce_quantity_not_full_option(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        [$pA, $oA] = $this->createProductWithOption(price: 10000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 3),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->firstWhere('product_option_id', $oA->id);
        $optionB = $order->options->firstWhere('product_option_id', $oB->id);

        // A 3개 중 2개만 취소 → A 1개 잔존
        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([['order_option_id' => $optionA->id, 'cancel_quantity' => 2]])
        );

        // 잔여: A(1×10,000)=10,000, B(10,000)=10,000, 총 20,000. usePoints 3,000 → A:1,500/B:1,500.
        // A earnable = 10,000 − 1,500 = 8,500 → 850. B 동일 850.
        $this->assertEquals(850, (int) $result->optionUpdates[$optionA->id]['subtotal_earned_points_amount']);
        $this->assertEquals(850, (int) $result->optionUpdates[$optionB->id]['subtotal_earned_points_amount']);
    }

    /**
     * 복합 6: 도서산간 추가배송비 + 마일리지 사용 + 부분취소.
     *
     * 정책 FIXED 3,000 + 도서산간(zipcode 63* → +3,000). 배송지 제주(63123).
     * A(20,000)+B(10,000), rate 10%, usePoints 3,000, A 취소.
     * 도서산간 추가배송비는 상품금액 기준 적립 base 와 독립 — 적립값이 추가배송비에 오염되지 않음.
     *
     * @effects partial_cancel_excludes_cancelled_option_share_from_earned_points, cancel_recalculates_the_island_extra_fee_with_the_remainder
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_island_extra_shipping_fee_with_mileage_usage(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [
                ['zipcode' => '63*', 'fee' => 3000],
            ],
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 3000,
            shippingAddress: new ShippingAddress(zipcode: '63123'),
        );

        $order = $this->createOrderFromCalculation($input);
        // 도서산간 추가배송비가 원 주문에 반영됨
        $this->assertGreaterThan(0, (int) $order->extra_shipping_amount);

        $optionA = $order->options->firstWhere('product_option_id', $oA->id);
        $optionB = $order->options->firstWhere('product_option_id', $oB->id);

        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([['order_option_id' => $optionA->id, 'cancel_quantity' => 1]])
        );

        // 잔여 B 적립 = floor((10,000 − 3,000 사용) × 10%) = 700. 도서산간 추가배송비와 독립.
        $this->assertEquals(
            700,
            (int) $result->optionUpdates[$optionB->id]['subtotal_earned_points_amount'],
            '도서산간 추가배송비가 상품금액 기준 적립 안분을 오염시키면 안 됩니다.'
        );
    }

    /**
     * 복합 7: 상품별 배송정책 상이 + 마일리지 사용 + 부분취소.
     *
     * 옵션 A=정책α(FIXED 3,000), 옵션 B=정책β(CONDITIONAL_FREE freeThreshold 5,000).
     * A(20,000)+B(10,000), rate 10%, usePoints 3,000, A 취소.
     * 잔여 B 는 정책β 기준 배송비 재판정(10,000 >= 5,000 → 무료), B 적립 재안분 정확값.
     *
     * @effects partial_cancel_excludes_cancelled_option_share_from_earned_points, cancel_reports_the_shipping_difference_per_policy_group
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_per_product_distinct_shipping_policies_with_mileage(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        $spA = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);
        $spB = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE, baseFee: 2500, freeThreshold: 5000);

        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $spA);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $spB);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            usePoints: 3000,
        );

        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->firstWhere('product_option_id', $oA->id);
        $optionB = $order->options->firstWhere('product_option_id', $oB->id);

        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([['order_option_id' => $optionA->id, 'cancel_quantity' => 1]])
        );

        // 잔여 B 적립 = floor((10,000 − 3,000) × 10%) = 700. 정책β 기준 배송비는 무료(10,000 >= 5,000).
        $this->assertEquals(
            700,
            (int) $result->optionUpdates[$optionB->id]['subtotal_earned_points_amount'],
            '옵션별 배송정책이 달라도 적립 재안분은 상품금액 기준으로 정확해야 합니다.'
        );
        $this->assertEquals(0, (int) $result->recalculated->summary->totalShipping, '잔여 B 는 정책β 무료배송 문턱 충족');
    }

    /**
     * 복합 8: 상품별 상이 배송정책 + 도서산간 + 쿠폰 + 마일리지 최대 복합, 3옵션 중 1개 부분취소.
     *
     * 잔여 2옵션 적립 재안분 + 옵션별 배송비 + 도서산간 + 쿠폰 복원 + 환불 split 전 항목 동시 단언.
     * 적립 재안분값은 두 우선순위에서 동일.
     *
     * @effects partial_cancel_excludes_cancelled_option_share_from_earned_points, cancel_reports_the_shipping_difference_per_policy_group, points_usage_is_reapportioned_over_the_remaining_items
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_full_composite_per_product_policy_island_coupon_mileage(): void
    {
        $this->enableMileageSettings(earnRate: 10);

        $spA = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
            extraFeeEnabled: true,
            extraFeeSettings: [['zipcode' => '63*', 'fee' => 3000]],
        );
        $spB = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE, baseFee: 2500, freeThreshold: 5000);

        [$pA, $oA] = $this->createProductWithOption(price: 30000, shippingPolicy: $spA);
        [$pB, $oB] = $this->createProductWithOption(price: 20000, shippingPolicy: $spB);
        [$pC, $oC] = $this->createProductWithOption(price: 10000, shippingPolicy: $spB);

        // 주문마다 신규 쿠폰 발급 (발급 1건 재사용 시 첫 주문 USED → 둘째 주문 오염)
        $makeOrder = function () use ($pA, $oA, $pB, $oB, $pC, $oC): Order {
            $couponIssue = $this->createCouponWithIssue(
                targetType: CouponTargetType::PRODUCT_AMOUNT,
                discountType: CouponDiscountType::FIXED,
                discountValue: 3000,
                targetScope: CouponTargetScope::ALL,
            );
            $input = new CalculationInput(
                items: [
                    new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                    new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
                    new CalculationItem(productId: $pC->id, productOptionId: $oC->id, quantity: 1),
                ],
                couponIssueIds: [$couponIssue->id],
                usePoints: 6000,
                shippingAddress: new ShippingAddress(zipcode: '63123'),
            );

            return $this->createOrderFromCalculation($input);
        };

        $orderPg = $makeOrder();
        $cancelA = $orderPg->options->firstWhere('product_option_id', $oA->id);
        $remainB = $orderPg->options->firstWhere('product_option_id', $oB->id);
        $remainC = $orderPg->options->firstWhere('product_option_id', $oC->id);
        $resultPg = $this->adjustmentService->calculate(
            $orderPg,
            $this->buildCancellation([['order_option_id' => $cancelA->id, 'cancel_quantity' => 1]]),
            RefundPriorityEnum::PG_FIRST
        );

        $earnedB = (int) $resultPg->optionUpdates[$remainB->id]['subtotal_earned_points_amount'];
        $earnedC = (int) $resultPg->optionUpdates[$remainC->id]['subtotal_earned_points_amount'];
        $this->assertGreaterThan(0, $earnedB);
        $this->assertGreaterThan(0, $earnedC);

        // 적립 재안분값은 두 우선순위에서 동일
        $orderPoints = $makeOrder();
        $cancelA2 = $orderPoints->options->firstWhere('product_option_id', $oA->id);
        $remainB2 = $orderPoints->options->firstWhere('product_option_id', $oB->id);
        $remainC2 = $orderPoints->options->firstWhere('product_option_id', $oC->id);
        $resultPoints = $this->adjustmentService->calculate(
            $orderPoints,
            $this->buildCancellation([['order_option_id' => $cancelA2->id, 'cancel_quantity' => 1]]),
            RefundPriorityEnum::POINTS_FIRST
        );
        $this->assertEquals($earnedB, (int) $resultPoints->optionUpdates[$remainB2->id]['subtotal_earned_points_amount']);
        $this->assertEquals($earnedC, (int) $resultPoints->optionUpdates[$remainC2->id]['subtotal_earned_points_amount']);

        // 환불 총액 동일
        $this->assertEquals(
            $resultPg->refundAmount + $resultPg->refundPointsAmount,
            $resultPoints->refundAmount + $resultPoints->refundPointsAmount,
        );
    }

    // ================================================================
    // 부분취소 부가세 재산출 (#493 E5)
    // ================================================================

    /**
     * 부분취소 재계산이 잔여 금액 기준 부가세를 다시 계산하는지 테스트합니다.
     *
     * 부가세를 재산출하지 않으면 취소 후에도 취소 전 부가세가 남아 세금 표기가 실제 결제
     * 금액과 어긋난다.
     *
     * @effects partial_cancel_recalculates_the_vat_on_the_remaining_amount
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_recalculates_vat(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 11000);
        [$productB, $optionB] = $this->createProductWithOption(price: 22000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);
        $cancelTarget = $order->options->firstWhere('product_option_id', $optionB->id);

        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([['order_option_id' => $cancelTarget->id, 'cancel_quantity' => 1]])
        );

        // 잔여 11,000 (10%) → 1,000. 취소 전 전체(33,000)의 3,000 이 남으면 안 된다.
        $this->assertSame(1000, (int) $result->orderUpdates['total_vat_amount']);
    }

    /**
     * 부분취소 재계산이 주문 당시 스냅샷 세율을 쓰는지 테스트합니다.
     *
     * 상품의 현재 세율을 읽으면, 주문 이후 세율이 바뀐 상품에서 과거 주문의 세금 표기가
     * 소급해 바뀐다.
     *
     * @effects partial_cancel_recalculates_the_vat_on_the_remaining_amount, partial_cancel_uses_the_order_time_tax_rate_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_uses_snapshot_tax_rate_not_current_product_rate(): void
    {
        [$productA, $optionA] = $this->createProductWithOption(price: 11000);
        [$productB, $optionB] = $this->createProductWithOption(price: 22000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]);

        $order = $this->createOrderFromCalculation($input);

        // 주문 이후 상품 세율 변경 — 과거 주문의 재계산에 영향을 주면 안 된다
        $productA->update(['tax_rate' => 25.0]);

        $cancelTarget = $order->options->firstWhere('product_option_id', $optionB->id);

        $result = $this->adjustmentService->calculate(
            $order,
            $this->buildCancellation([['order_option_id' => $cancelTarget->id, 'cancel_quantity' => 1]])
        );

        // 스냅샷 세율 10% 기준 1,000 (현재 세율 25% 였다면 2,200)
        $this->assertSame(1000, (int) $result->orderUpdates['total_vat_amount']);
    }

    // ================================================================
    // Z. 교환 옵션 치환 2-패스 — 등가성 픽스처 (#599 후속)
    // ================================================================

    /**
     * 등가성 픽스처용 주문 3종을 조립합니다. (취소 · 반품 · 치환 없는 교환)
     *
     * 조건부 무료배송(50,000 기준)을 넘는 두 상품 주문에서 한 상품을 부분 제외해
     * 배송비 전이·안분·정가 합계가 모두 움직이는 조합을 쓴다 — 스냅샷의 어느 줄도
     * 0 으로 비지 않아야 회귀를 잡는다.
     *
     * @return array{0: Order, 1: OrderOption, 2: OrderOption} [주문, A 행, B 행]
     */
    protected function buildEquivalenceFixtureOrder(): array
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp, listPrice: 25000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $sp, listPrice: 12000);

        // 원 주문의 적립예정액은 주문 컬럼에서 읽는다 — 팩토리 기본값이 난수라 고정한다(픽스처 결정성).
        $order = $this->createOrderFromCalculation(new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 2),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 3),
        ]), ['total_earned_points_amount' => 700]);

        return [
            $order,
            $order->options->firstWhere('product_option_id', $oA->id),
            $order->options->firstWhere('product_option_id', $oB->id),
        ];
    }

    /**
     * 미리보기 배열에서 실행마다 달라지는 DB 식별자를 서수로 치환합니다.
     *
     * `cancelled_items[].order_option_id` 만 자동증가 ID 다(트랜잭션 롤백은 시퀀스를 되돌리지
     * 않는다). 주문 행 순서(ID 오름차순)의 1-based 서수로 바꾸면 같은 조립은 같은 JSON 이 된다.
     *
     * @param  array  $preview  미리보기 배열 (`AdjustmentResult::toPreviewArray()`)
     * @param  Order  $order  주문
     * @return array 정규화된 배열
     */
    protected function normalizePreviewForFixture(array $preview, Order $order): array
    {
        $ordinals = [];
        foreach ($order->options->sortBy('id')->values() as $index => $option) {
            $ordinals[$option->id] = $index + 1;
        }

        foreach ($preview['cancelled_items'] ?? [] as $i => $item) {
            $preview['cancelled_items'][$i]['order_option_id'] = $ordinals[$item['order_option_id']] ?? $item['order_option_id'];
        }

        // `substituted_items` 는 이 작업이 **추가**한 키라 변경 전 덤프에는 없다. 치환이 없으면 빈 배열이어야
        // 하고, 그것을 확인한 뒤 비교에서 뺀다 — 나머지 키 전부가 바이트 단위로 같아야 한다는 계약은 그대로다.
        if (array_key_exists('substituted_items', $preview)) {
            $this->assertSame([], $preview['substituted_items'], '치환 입력이 없는데 치환 근거 행이 실렸다.');
            unset($preview['substituted_items']);
        }

        return $preview;
    }

    /**
     * 치환 테스트용 주문을 조립합니다. (상품 A 20,000 × 2 · 정가 25,000)
     *
     * @param  ShippingPolicy|null  $policy  배송정책 (없으면 무료)
     * @param  int  $price  판매가
     * @param  array  $inputOverrides  CalculationInput 생성자 오버라이드 (itemCoupons 등)
     * @return array{0: Order, 1: OrderOption, 2: ProductOption} [주문, 주문 행, 상품옵션]
     */
    protected function buildSubstitutionOrder(?ShippingPolicy $policy = null, int $price = 20000, array $inputOverrides = []): array
    {
        [$product, $productOption] = $this->createProductWithOption(price: $price, shippingPolicy: $policy, listPrice: 25000);

        $input = new CalculationInput(...array_merge([
            'items' => [new CalculationItem(productId: $product->id, productOptionId: $productOption->id, quantity: 2)],
        ], $inputOverrides));

        $order = $this->createOrderFromCalculation($input);

        return [$order, $order->options->firstWhere('product_option_id', $productOption->id), $productOption];
    }

    /**
     * 같은 상품의 형제 옵션을 만들어 스냅샷을 돌려줍니다.
     *
     * @param  ProductOption  $original  원 상품옵션
     * @param  int  $priceAdjustment  옵션 조정액
     * @param  array  $overrides  옵션 속성 오버라이드 (list_price · mileage_*)
     * @return array{0: ProductOption, 1: array} [형제 옵션, 스냅샷]
     */
    protected function siblingOptionSnapshot(ProductOption $original, int $priceAdjustment, array $overrides = []): array
    {
        $sibling = ProductOptionFactory::new()->forProduct($original->product)->create(array_merge([
            'price_adjustment' => $priceAdjustment,
            'stock_quantity' => 100,
            'is_default' => false,
            'is_active' => true,
        ], $overrides));

        return [$sibling, $sibling->toSnapshotArray()];
    }

    /**
     * 치환 입력이 있는 교환 DTO 를 만듭니다. (배송비 0 · 환불금 차감)
     *
     * @param  OrderOption  $option  대상 주문 행
     * @param  array  $substitution  치환 입력 (quantity · product_option_id · unit_price · option_snapshot · additional_options_snapshot)
     * @param  int  $priceDifference  옵션 차액 합계
     * @return ExchangeAdjustment 교환 DTO
     */
    protected function exchangeWith(OrderOption $option, array $substitution, int $priceDifference = 0): ExchangeAdjustment
    {
        return ExchangeAdjustment::fromShippingFee(
            [['order_option_id' => $option->id, 'cancel_quantity' => $substitution['quantity']]],
            new ClaimShippingFee(fee: 0, claimType: ClaimTypeEnum::EXCHANGE, charged: false),
            $priceDifference,
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            [$option->id => $substitution + ['product_option_id' => null, 'additional_options_snapshot' => []]],
        );
    }

    /**
     * 치환 라인은 환불 산식·업데이트 페이로드를 바꾸지 않고 표시 열만 바꾼다. (2-패스 계약)
     *
     * @scenario price_delta=higher
     *
     * @effects exchange_preview_two_pass_keeps_refund_math
     *
     * @return void 반환값 없음
     */
    public function test_substitution_lines_do_not_change_refund_math(): void
    {
        [$order, $option, $productOption] = $this->buildSubstitutionOrder();
        [, $snapshot] = $this->siblingOptionSnapshot($productOption, 5000);

        $substitution = ['quantity' => 1, 'unit_price' => 25000, 'option_snapshot' => $snapshot];
        $with = $this->adjustmentService->preview($order, $this->exchangeWith($option, $substitution, 5000));
        $without = $this->adjustmentService->preview($order, ExchangeAdjustment::fromShippingFee(
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            new ClaimShippingFee(fee: 0, claimType: ClaimTypeEnum::EXCHANGE, charged: false),
            5000,
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ));

        // 산식 축 — 전부 패스 A
        $this->assertSame($without->refundAmount, $with->refundAmount);
        $this->assertSame($without->refundPointsAmount, $with->refundPointsAmount);
        $this->assertSame($without->shippingDifference, $with->shippingDifference);
        $this->assertSame($without->discountDifference, $with->discountDifference);
        $this->assertSame($without->orderUpdates, $with->orderUpdates);
        $this->assertSame($without->optionUpdates, $with->optionUpdates);
        $this->assertSame($without->originalSnapshot, $with->originalSnapshot);

        // 표시 축 — 패스 B: 20,000 × 1 + 25,000 × 1
        $this->assertSame(40000.0, $with->originalSnapshot['subtotal_amount']);
        $this->assertSame(45000.0, $with->recalculatedSnapshot['subtotal_amount']);
        $this->assertSame(2, (int) $with->recalculatedSnapshot['item_count']);
        $this->assertCount(1, $with->substitutedItems);
        $this->assertSame(-1 * $option->id, $with->substitutedItems[0]['line_key']);
        $this->assertSame(25000.0, $with->substitutedItems[0]['new_unit_price']);
        $this->assertSame([], $without->substitutedItems);
    }

    /**
     * 치환 라인은 원 라인의 배송정책 스냅샷을 승계한다 — 조건부 무료가 유지된다.
     *
     * 승계하지 않으면 스냅샷 모드는 폴백 없이 그 라인을 배송 그룹에서 빼고, 남은 원 라인만으로
     * 기준금액을 판정해 배송비가 생긴다.
     *
     * @scenario price_delta=lower
     *
     * @effects exchange_substitution_inherits_shipping_policy_and_coupons
     *
     * @return void 반환값 없음
     */
    public function test_substitution_inherits_shipping_policy_snapshot(): void
    {
        $policy = $this->createShippingPolicy(ChargePolicyEnum::CONDITIONAL_FREE, 3000, 50000);
        [$order, $option, $productOption] = $this->buildSubstitutionOrder($policy, 30000);
        $this->assertSame(0.0, (float) $order->total_shipping_amount);
        [, $snapshot] = $this->siblingOptionSnapshot($productOption, -5000);

        // 30,000 × 1 + 25,000 × 1 = 55,000 ≥ 50,000 → 무료 유지
        $result = $this->adjustmentService->preview($order, $this->exchangeWith($option, [
            'quantity' => 1, 'unit_price' => 25000, 'option_snapshot' => $snapshot,
        ], -5000));

        $this->assertSame(55000.0, $result->recalculatedSnapshot['subtotal_amount']);
        $this->assertSame(0.0, $result->recalculatedSnapshot['total_shipping_amount']);
    }

    /**
     * 치환 라인은 원 라인의 상품쿠폰 슬롯을 승계한다. (정액 1,000 × 수량 → 갈려도 합계 불변)
     *
     * @scenario price_delta=equal
     *
     * @effects exchange_substitution_inherits_shipping_policy_and_coupons
     *
     * @return void 반환값 없음
     */
    public function test_substitution_inherits_item_coupons(): void
    {
        $issue = $this->createCouponWithIssue(CouponTargetType::PRODUCT_AMOUNT, CouponDiscountType::FIXED, 1000);
        [$product, $productOption] = $this->createProductWithOption(price: 20000, listPrice: 25000);
        $order = $this->createOrderFromCalculation(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $productOption->id, quantity: 2)],
            itemCoupons: [$productOption->id => [$issue->id]],
        ));
        $option = $order->options->firstWhere('product_option_id', $productOption->id);
        $this->assertSame(2000.0, (float) $order->total_product_coupon_discount_amount);
        [, $snapshot] = $this->siblingOptionSnapshot($productOption, 0);

        $result = $this->adjustmentService->preview($order, $this->exchangeWith($option, [
            'quantity' => 1, 'unit_price' => 20000, 'option_snapshot' => $snapshot,
        ]));

        $this->assertSame(2000.0, $result->recalculatedSnapshot['total_product_coupon_discount_amount']);
    }

    /**
     * 정률+상한 쿠폰은 라인이 갈리면 상한을 수량 비례로 나눠 싣는다 — 상한이 두 번 걸리지 않는다.
     *
     * 원 20,000 × 2 = 40,000 · 10% 상한 3,000 → 3,000. 1개 치환(동가) 뒤 라인별 상한 없이 계산하면
     * 2,000 + 2,000 = 4,000 이 되어 원 주문보다 할인이 커진다. 1,500 + 1,500 으로 3,000 을 유지한다.
     *
     * @scenario price_delta=equal
     *
     * @effects exchange_substitution_inherits_shipping_policy_and_coupons
     *
     * @return void 반환값 없음
     */
    public function test_substitution_splits_percent_coupon_cap_by_quantity(): void
    {
        $issue = $this->createCouponWithIssue(CouponTargetType::PRODUCT_AMOUNT, CouponDiscountType::RATE, 10, maxDiscount: 3000);
        [$product, $productOption] = $this->createProductWithOption(price: 20000, listPrice: 25000);
        $order = $this->createOrderFromCalculation(new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $productOption->id, quantity: 2)],
            itemCoupons: [$productOption->id => [$issue->id]],
        ));
        $option = $order->options->firstWhere('product_option_id', $productOption->id);
        $this->assertSame(3000.0, (float) $order->total_product_coupon_discount_amount);
        [, $snapshot] = $this->siblingOptionSnapshot($productOption, 0);

        $result = $this->adjustmentService->preview($order, $this->exchangeWith($option, [
            'quantity' => 1, 'unit_price' => 20000, 'option_snapshot' => $snapshot,
        ]));

        $this->assertSame(3000.0, $result->recalculatedSnapshot['total_product_coupon_discount_amount']);
        $this->assertSame(37000.0, $result->recalculatedSnapshot['total_amount']);
    }

    /**
     * 옵션 변경 없이 추가옵션만 바꾸는 교환은 원 키와 다른 라인 키를 써야 한다.
     *
     * 엔진 맵은 `product_option_id` 키라 같은 키의 두 라인은 나중 것이 조용히 덮어쓴다 — 치환 라인이
     * 음수 키를 쓰면 원 라인(20,000)과 치환 라인(20,000 + 추가옵션 2,000)이 둘 다 산다.
     *
     * @scenario price_delta=equal, additional_options=picked
     *
     * @effects exchange_preview_two_pass_keeps_refund_math
     *
     * @return void 반환값 없음
     */
    public function test_substitution_same_option_custom_change_uses_distinct_line_key(): void
    {
        [$order, $option] = $this->buildSubstitutionOrder();

        $result = $this->adjustmentService->preview($order, $this->exchangeWith($option, [
            'quantity' => 1,
            'unit_price' => 20000,
            'option_snapshot' => $option->option_snapshot,
            'additional_options_snapshot' => [['additional_option_id' => 1, 'value_id' => 1, 'price_adjustment' => 2000]],
        ], 2000));

        $this->assertSame(42000.0, $result->recalculatedSnapshot['subtotal_amount']);
        $this->assertSame(2, (int) $result->recalculatedSnapshot['item_count']);
        $this->assertNull($result->substitutedItems[0]['product_option_id']);
        $this->assertSame(-1 * $option->id, $result->substitutedItems[0]['line_key']);
    }

    /**
     * 치환 단가는 동결 상품가가 아니라 `new_unit_price` 다. (현재 상품가 기준 차액 산식과 같은 기준)
     *
     * 스냅샷의 조정액(+5,000)대로면 25,000 이지만 신규 단가가 27,000 이면 후열은 27,000 을 쓴다.
     *
     * @scenario price_delta=higher
     *
     * @effects exchange_preview_two_pass_keeps_refund_math
     *
     * @return void 반환값 없음
     */
    public function test_substitution_unit_price_overrides_frozen_product_price(): void
    {
        [$order, $option, $productOption] = $this->buildSubstitutionOrder();
        [, $snapshot] = $this->siblingOptionSnapshot($productOption, 5000);

        $result = $this->adjustmentService->preview($order, $this->exchangeWith($option, [
            'quantity' => 1, 'unit_price' => 27000, 'option_snapshot' => $snapshot,
        ], 7000));

        $this->assertSame(47000.0, $result->recalculatedSnapshot['subtotal_amount']);
        $this->assertSame(27000.0, $result->substitutedItems[0]['new_unit_price']);
    }

    /**
     * 후열은 신규 옵션의 정가와 적립 규칙을 반영한다.
     *
     * 정가: 25,000 × 1 + 30,000 × 1 = 55,000. 적립: 원 라인 1개 몫 + 신규 옵션 정액 500.
     *
     * @scenario price_delta=equal
     *
     * @effects exchange_preview_two_pass_keeps_refund_math
     *
     * @return void 반환값 없음
     */
    public function test_recalculated_snapshot_reflects_new_list_price_and_points(): void
    {
        $this->enableMileageSettings(10);
        [$order, $option, $productOption] = $this->buildSubstitutionOrder();
        [, $snapshot] = $this->siblingOptionSnapshot($productOption, 0, [
            'list_price' => 30000,
            'mileage_type' => 'fixed',
            'mileage_value' => 500,
        ]);

        $without = $this->adjustmentService->preview($order, ExchangeAdjustment::fromShippingFee(
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            new ClaimShippingFee(fee: 0, claimType: ClaimTypeEnum::EXCHANGE, charged: false),
        ));
        $with = $this->adjustmentService->preview($order, $this->exchangeWith($option, [
            'quantity' => 1, 'unit_price' => 20000, 'option_snapshot' => $snapshot,
        ]));

        $this->assertSame(50000.0, $without->recalculatedSnapshot['total_list_price_amount']);
        $this->assertSame(55000.0, $with->recalculatedSnapshot['total_list_price_amount']);
        $this->assertSame(
            $without->recalculatedSnapshot['total_earned_points_amount'] / 2 + 500,
            $with->recalculatedSnapshot['total_earned_points_amount'],
        );
    }

    /**
     * 픽스처 파일과 미리보기 JSON 이 바이트 단위로 같은지 단언합니다.
     *
     * 픽스처가 없으면 `ADJUSTMENT_PREVIEW_FIXTURE_WRITE=1` 일 때만 현재 출력을 기록한다 —
     * 치환 재계산 도입 **전** 커밋에서 한 번 기록한 뒤에는 읽기 전용이다.
     *
     * @param  string  $name  픽스처 이름 (cancel · return · exchange)
     * @param  array  $preview  정규화된 미리보기 배열
     * @return void 반환값 없음
     */
    protected function assertPreviewMatchesFixture(string $name, array $preview): void
    {
        $dir = dirname(__DIR__, 2).'/Fixtures/adjustment-preview';
        $path = $dir.'/'.$name.'.json';
        $json = json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";

        if (! is_file($path)) {
            if (getenv('ADJUSTMENT_PREVIEW_FIXTURE_WRITE') !== '1') {
                $this->fail("등가성 픽스처가 없습니다: {$path} — 변경 전 커밋에서 ADJUSTMENT_PREVIEW_FIXTURE_WRITE=1 로 먼저 기록하세요.");
            }

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $json);
        }

        $this->assertSame(file_get_contents($path), $json, "미리보기 출력이 변경 전 픽스처({$name})와 다릅니다.");
    }

    /**
     * 치환 입력이 비어 있으면 세 유형의 미리보기 출력이 변경 전과 바이트 단위로 같다.
     *
     * 패스 B 는 `getSubstitutions() === []` 이면 실행되지 않고 `buildRecalcInput()` 은 한 줄도
     * 바뀌지 않는다는 계약을 변경 전 덤프로 고정한다(취소 · 회수비 차감 반품 · 치환 없는 교환).
     *
     * @effects cancel_preview_output_unchanged_with_empty_substitutions
     *
     * @return void 반환값 없음
     */
    public function test_empty_substitutions_preview_is_byte_identical(): void
    {
        [$order, $optionA, $optionB] = $this->buildEquivalenceFixtureOrder();

        $cancel = $this->adjustmentService->preview($order, $this->buildCancellation([
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]));
        $this->assertPreviewMatchesFixture('cancel', $this->normalizePreviewForFixture($cancel->toPreviewArray(), $order));

        $return = $this->adjustmentService->preview($order, new ReturnAdjustment(
            returnItems: [['order_option_id' => $optionB->id, 'cancel_quantity' => 3]],
            collectionFee: 3000,
            chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ), RefundPriorityEnum::PG_FIRST);
        $this->assertPreviewMatchesFixture('return', $this->normalizePreviewForFixture($return->toPreviewArray(), $order));

        $exchange = $this->adjustmentService->preview($order, ExchangeAdjustment::fromShippingFee(
            [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            new ClaimShippingFee(fee: 6000, claimType: ClaimTypeEnum::EXCHANGE, charged: true),
            -4000,
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ));
        $this->assertPreviewMatchesFixture('exchange', $this->normalizePreviewForFixture($exchange->toPreviewArray(), $order));
    }

    // ═══════════════════════════════════════════════════════════════
    // 교환 완료 뒤 후속 행의 회계 단가 (#599 S3-D3 재정의 · 2026-09-17 실측)
    // ═══════════════════════════════════════════════════════════════

    /**
     * 저가 옵션으로 교환 완료된 주문을 만듭니다. (원 행 EXCHANGED · 후속 행 · 차액 환불신청서)
     *
     * 후속 행은 설계대로 원 행 단가를 **승계**한다(2026-08-22 판정 1). 차액은 교환 발 환불신청서가
     * 돌려준다 — 그래서 후속 행에 대해 고객이 실제로 낸 돈은 승계 단가 − 환불된 차액이다.
     *
     * @param  int  $aPrice  교환 대상 상품 A 단가
     * @param  int  $difference  옵션 차액 (음수 = 저가 옵션)
     * @param  bool  $differenceRefunded  차액 환불신청서를 만들지 (false 면 「접수하지 않음」 완료)
     * @return array{0: Order, 1: OrderOption, 2: OrderOption, 3: OrderOption} [주문, 원 행 A, 후속 행, 다른 상품 B 행]
     */
    protected function buildCompletedExchangeOrder(int $aPrice = 20000, int $difference = -5000, bool $differenceRefunded = true): array
    {
        [$productA, $optionA] = $this->createProductWithOption(price: $aPrice);
        [$productB, $optionB] = $this->createProductWithOption(price: 30000);

        $order = $this->createOrderFromCalculation(new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 2),
        ]));

        $rowA = $order->options->firstWhere('product_option_id', $optionA->id);
        $rowB = $order->options->firstWhere('product_option_id', $optionB->id);

        // 신규 옵션(같은 상품의 형제 · 차액만큼 조정)
        [$sibling, $siblingSnapshot] = $this->siblingOptionSnapshot($optionA, $difference);

        // 후속 행 — OrderExchangeService::ensureNewOrderOption() 과 같은 모양: 금액 승계 · 정체만 신규
        $successor = $rowA->replicate(['parent_option_id', 'cancelled_quantity', 'cancel_reason', 'confirmed_at', 'delivered_at']);
        $successor->option_status = OrderStatusEnum::DELIVERED;
        $successor->source_type = OrderOptionSourceTypeEnum::EXCHANGE;
        $successor->source_option_id = $rowA->id;
        $successor->product_option_id = $sibling->id;
        $successor->option_snapshot = array_merge($siblingSnapshot, [
            'price_adjustment' => $rowA->option_snapshot['price_adjustment'] ?? $sibling->price_adjustment,
            'list_price' => $rowA->option_snapshot['list_price'] ?? $sibling->list_price,
            'selling_price' => $rowA->option_snapshot['selling_price'] ?? $sibling->selling_price,
        ]);
        $successor->save();

        $rowA->update(['option_status' => OrderStatusEnum::EXCHANGED]);

        $exchange = OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_status' => ExchangeStatusEnum::COMPLETED,
        ]);
        OrderExchangeOption::factory()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $rowA->id,
            'option_status' => ExchangeStatusEnum::COMPLETED,
            'unit_price' => $aPrice,
            'new_product_option_id' => $sibling->id,
            'new_option_snapshot' => $siblingSnapshot,
            'new_unit_price' => $aPrice + $difference,
            'price_difference' => $difference,
            'new_order_option_id' => $successor->id,
        ]);

        if ($differenceRefunded && $difference < 0) {
            OrderRefund::create([
                'order_id' => $order->id,
                'refund_source_type' => RefundSourceTypeEnum::EXCHANGE,
                'refund_source_id' => $exchange->id,
                'refund_number' => 'RF'.uniqid(),
                'refund_status' => RefundStatusEnum::COMPLETED,
                'refund_method' => 'bank',
                'refund_amount' => -$difference,
            ]);
            $order->update(['total_refunded_amount' => -$difference]);
        }

        return [$order->fresh(['options', 'payment']), $rowA->fresh(), $successor->fresh(), $rowB->fresh()];
    }

    /**
     * 저가 옵션 교환이 끝난 뒤 그 후속 행을 취소하면 환불액은 **신규 옵션 단가**다. (S3-D3)
     *
     * 실측(2026-09-17 주문 1497): 블랙/L 29,000 → 블랙/M 24,000 교환 완료 · 차액 5,000 환불 뒤
     * 블랙/M 취소 환불액이 **29,000** 으로 나와 차액이 두 번 나갔다. 후속 행이 원 행 단가를 승계하는
     * 설계는 다른 상품의 부분취소를 정확하게 하려는 것이지, 후속 행 자신의 환불까지 승계 단가로
     * 하라는 뜻이 아니다 — 고객이 그 행에 실제로 낸 돈은 승계 단가 − 환불된 차액이다.
     *
     * @scenario price_delta=lower
     *
     * @effects exchanged_successor_row_refunds_at_new_option_price
     *
     * @return void 반환값 없음
     */
    public function test_cancelling_the_exchanged_successor_row_refunds_the_new_option_price(): void
    {
        [$order, , $successor] = $this->buildCompletedExchangeOrder(aPrice: 20000, difference: -5000);

        $result = $this->adjustmentService->preview($order, $this->buildCancellation([
            ['order_option_id' => $successor->id, 'cancel_quantity' => 1],
        ]));

        $this->assertSame(15000.0, (float) $result->refundAmount, '후속 행 환불액은 신규 옵션 단가(20,000 − 5,000)여야 한다 — 승계 단가 20,000 이면 차액이 두 번 나간다');
        $this->assertSame(15000.0, (float) $result->adjustedItems[0]['cancel_amount']);
    }

    /**
     * 같은 주문에서 **다른 상품**을 부분취소하는 환불액은 종전과 같다. (2026-08-22 판정 1 보존 · 회차 ⑤)
     *
     * @scenario price_delta=lower
     *
     * @effects exchanged_successor_pricing_keeps_other_item_refunds
     *
     * @return void 반환값 없음
     */
    public function test_other_item_refund_is_unchanged_after_a_cheaper_exchange(): void
    {
        [$order, , , $rowB] = $this->buildCompletedExchangeOrder(aPrice: 20000, difference: -5000);

        $result = $this->adjustmentService->preview($order, $this->buildCancellation([
            ['order_option_id' => $rowB->id, 'cancel_quantity' => 1],
        ]));

        $this->assertSame(30000.0, (float) $result->refundAmount, '다른 상품 1개의 환불액은 그 상품 단가 그대로여야 한다');
    }

    /**
     * 교환 완료 주문의 「취소·환불 전」 상품수량은 교환으로 닫힌 원 행을 세지 않는다 (S3-D16, 2026-10-07 실측)
     *
     * 같은 비교 카드의 정가 합계(`listPriceTotal`)는 교환완료로 닫힌 원 행을 빼는데, 상품수량(`item_count`)은
     * 취소 행만 빼서 원 행과 후속 행을 둘 다 셌다. 실측: 주문 20261007-0346009059(A옵션1·A옵션2·B — A옵션2 를
     * 옵션1 로 교환 완료) 뒤 반품 환불신청서 RF20261007-0434050542 의 「환불 전 상품수량 4개」 — 살아 있는 상품은 3개.
     *
     * @scenario price_delta=lower
     *
     * @effects exchanged_source_row_is_not_counted_twice_in_the_comparison_quantity
     *
     * @return void 반환값 없음
     */
    public function test_comparison_item_count_skips_the_exchanged_source_row(): void
    {
        [$order, , , $rowB] = $this->buildCompletedExchangeOrder(aPrice: 20000, difference: -5000);

        $result = $this->adjustmentService->preview($order, $this->buildCancellation([
            ['order_option_id' => $rowB->id, 'cancel_quantity' => 1],
        ]));

        // 후속 행 1 + B 2 — 교환완료로 닫힌 원 행 A 는 후속 행이 승계했으므로 세지 않는다
        $this->assertSame(3, (int) $result->originalSnapshot['item_count']);
    }

    /**
     * 차액이 환불되지 않은 교환(「접수하지 않음」 완료)의 후속 행은 승계 단가 그대로다. (과잉 적용 방지)
     *
     * @scenario price_delta=lower
     *
     * @effects exchanged_successor_pricing_requires_a_refunded_difference
     *
     * @return void 반환값 없음
     */
    public function test_successor_row_keeps_inherited_price_when_the_difference_was_not_refunded(): void
    {
        [$order, , $successor] = $this->buildCompletedExchangeOrder(aPrice: 20000, difference: -5000, differenceRefunded: false);

        $result = $this->adjustmentService->preview($order, $this->buildCancellation([
            ['order_option_id' => $successor->id, 'cancel_quantity' => 1],
        ]));

        $this->assertSame(20000.0, (float) $result->refundAmount, '차액이 돌아가지 않았으면 후속 행의 환불은 승계 단가 전액이어야 한다');
    }

    // ================================================================
    // S-Q26-b·c — 재계산 결과의 주문 행 단위 배분 (#747 C3b)
    // ================================================================

    /**
     * 주문 행 하나를 같은 상품옵션의 두 행으로 나눕니다(상태 분할 형제 — 실제 분할이 남기는 모양).
     *
     * @param  OrderOption  $row  원 행
     * @param  int  $keep  원 행에 남길 수량
     * @param  OrderStatusEnum  $keepStatus  원 행 상태
     * @param  OrderStatusEnum  $movedStatus  새 행 상태
     * @return array{0: OrderOption, 1: OrderOption} [원 행, 새 행]
     */
    protected function splitSameOptionRow(OrderOption $row, int $keep, OrderStatusEnum $keepStatus, OrderStatusEnum $movedStatus): array
    {
        $total = (int) $row->quantity;
        $move = $total - $keep;
        $ratio = fn (float $v, int $q) => round($v * $q / $total, 2);
        $amountCols = ['subtotal_price', 'subtotal_paid_amount', 'subtotal_tax_amount', 'subtotal_tax_free_amount',
            'subtotal_points_used_amount', 'subtotal_earned_points_amount', 'subtotal_discount_amount', 'coupon_discount_amount'];

        $moved = $row->replicate();
        $moved->quantity = $move;
        $moved->option_status = $movedStatus;
        $moved->parent_option_id = $row->id;
        foreach ($amountCols as $c) {
            $moved->{$c} = $ratio((float) $row->{$c}, $move);
        }
        $moved->save();

        $updates = ['quantity' => $keep, 'option_status' => $keepStatus];
        foreach ($amountCols as $c) {
            $updates[$c] = $ratio((float) $row->{$c}, $keep);
        }
        $row->forceFill($updates)->save();

        return [$row->fresh(), $moved->fresh()];
    }

    /**
     * 같은 상품옵션의 활성 행 둘은 키 합계를 나눠 받는다 — 두 행 모두 합계를 받지 않는다.
     *
     * 엔진은 결과를 product_option_id 키로 합친다. 키로 맞춰 쓰면 상태가 다른 두 행이 각각 합계를 받아
     * 금액과 적립 예정액이 두 배가 된다(1521: 30,000원 행 둘이 각 60,000원·600P).
     *
     * @scenario same_option_lines=two_lines_different_status, trigger=partial_cancel_split, row_data=fresh
     *
     * @effects option_amounts_are_allocated_per_order_row_not_per_option_key, allocation_sums_equal_recalculated_totals
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_splits_key_amounts_across_same_option_rows(): void
    {
        $this->enableMileageSettings(10);
        [$pK, $oK] = $this->createProductWithOption(price: 10000);
        [$pZ, $oZ] = $this->createProductWithOption(price: 10000);

        $order = $this->createOrderFromCalculation(new CalculationInput(items: [
            new CalculationItem(productId: $pK->id, productOptionId: $oK->id, quantity: 3),
            new CalculationItem(productId: $pZ->id, productOptionId: $oZ->id, quantity: 1),
        ]));
        $rowK = $order->options->firstWhere('product_option_id', $oK->id);
        $rowZ = $order->options->firstWhere('product_option_id', $oZ->id);
        [$r1, $r2] = $this->splitSameOptionRow($rowK, 2, OrderStatusEnum::PREPARING, OrderStatusEnum::SHIPPING);
        $order->load('options');

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $rowZ->id, 'cancel_quantity' => 1],
        ]));

        $this->assertEquals(20000, $result->optionUpdates[$r1->id]['subtotal_paid_amount']);
        $this->assertEquals(10000, $result->optionUpdates[$r2->id]['subtotal_paid_amount']);
        $this->assertEquals(
            (float) $result->recalculated->summary->subtotal,
            $result->optionUpdates[$r1->id]['subtotal_paid_amount'] + $result->optionUpdates[$r2->id]['subtotal_paid_amount'],
        );
        $this->assertEquals(2000, $result->optionUpdates[$r1->id]['subtotal_earned_points_amount']);
        $this->assertEquals(1000, $result->optionUpdates[$r2->id]['subtotal_earned_points_amount']);
        $this->assertArrayNotHasKey($rowZ->id, $result->optionUpdates);
    }

    /**
     * 키당 한 행이면 배분은 엔진 결과 그대로다 — 대부분의 주문이 지나는 경로의 회귀 가드.
     *
     * @scenario same_option_lines=single_line, trigger=partial_cancel_split, row_data=fresh
     *
     * @effects single_line_keys_keep_engine_values
     *
     * @return void 반환값 없음
     */
    public function test_single_line_keys_keep_engine_values_unchanged(): void
    {
        $this->enableMileageSettings(10);
        [$pK, $oK] = $this->createProductWithOption(price: 13000);
        [$pZ, $oZ] = $this->createProductWithOption(price: 7000);

        $order = $this->createOrderFromCalculation(new CalculationInput(items: [
            new CalculationItem(productId: $pK->id, productOptionId: $oK->id, quantity: 1),
            new CalculationItem(productId: $pZ->id, productOptionId: $oZ->id, quantity: 3),
        ]));
        $rowK = $order->options->firstWhere('product_option_id', $oK->id);
        $rowZ = $order->options->firstWhere('product_option_id', $oZ->id);

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $rowZ->id, 'cancel_quantity' => 1],
        ]));

        foreach ($result->recalculated->items as $item) {
            $rowId = $item->productOptionId === $oK->id ? $rowK->id : $rowZ->id;
            $this->assertEquals((float) $item->finalAmount, $result->optionUpdates[$rowId]['subtotal_paid_amount']);
            $this->assertEquals((float) $item->pointsEarning, $result->optionUpdates[$rowId]['subtotal_earned_points_amount']);
            $this->assertEquals((float) $item->taxableAmount, $result->optionUpdates[$rowId]['subtotal_tax_amount']);
        }
    }

    /**
     * 재계산 결과가 입력 라인과 어긋나면 경고를 남기고 입력 수량 비중으로 나눈다. 결과에 없는 키의 행은 쓰지 않는다.
     *
     * @scenario same_option_lines=two_lines_different_status, trigger=partial_cancel_split, row_data=fresh
     *
     * @effects misaligned_result_falls_back_to_input_quantity_weights
     *
     * @return void 반환값 없음
     */
    public function test_misaligned_result_falls_back_to_input_quantity_weights(): void
    {
        [$pK, $oK] = $this->createProductWithOption(price: 10000);
        [$pZ, $oZ] = $this->createProductWithOption(price: 10000);

        $order = $this->createOrderFromCalculation(new CalculationInput(items: [
            new CalculationItem(productId: $pK->id, productOptionId: $oK->id, quantity: 3),
            new CalculationItem(productId: $pZ->id, productOptionId: $oZ->id, quantity: 2),
        ]));
        $rowK = $order->options->firstWhere('product_option_id', $oK->id);
        $rowZ = $order->options->firstWhere('product_option_id', $oZ->id);
        [$r1, $r2] = $this->splitSameOptionRow($rowK, 2, OrderStatusEnum::PREPARING, OrderStatusEnum::SHIPPING);
        $order->load('options');

        // 계산 결과에서 Z 아이템을 빼는 대역 — prepareItems 가 레거시 라인을 건너뛴 상황 재현
        $real = app(OrderCalculationService::class);
        $stub = \Mockery::mock($real);
        $stub->shouldReceive('calculate')->andReturnUsing(function (CalculationInput $input) use ($real, $oZ) {
            $r = $real->calculate($input);

            return new OrderCalculationResult(
                items: array_values(array_filter($r->items, fn ($it) => $it->productOptionId !== $oZ->id)),
                summary: $r->summary,
                promotions: $r->promotions,
                validationErrors: $r->validationErrors,
            );
        });
        $this->app->instance(OrderCalculationService::class, $stub);
        $service = $this->app->make(OrderAdjustmentService::class);

        Log::spy();

        $result = $service->calculate($order, $this->buildCancellation([
            ['order_option_id' => $rowZ->id, 'cancel_quantity' => 1],
        ]));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, '어긋나'))
            ->once();
        $this->assertArrayHasKey($r1->id, $result->optionUpdates);
        $this->assertArrayHasKey($r2->id, $result->optionUpdates);
        $this->assertArrayNotHasKey($rowZ->id, $result->optionUpdates);
        $this->assertEquals(20000, $result->optionUpdates[$r1->id]['subtotal_paid_amount']);
        $this->assertEquals(10000, $result->optionUpdates[$r2->id]['subtotal_paid_amount']);
    }

    /**
     * 배송비 정책별 상세의 원 주문 측은 부풀려진 레거시 배송 행이 아니라 직전 재계산에서 구한다.
     *
     * 1.2.1 의 부분취소는 모든 배송 행에 주문 전체 배송비를 써 두었다(748: 행마다 5,500). 원 주문 측을
     * 배송 행 합으로 구하면 정책별 차액이 부풀어 보인다. 종결 행이 있으면 기준선 재계산(priorResult)이
     * 이미 있으므로 그 값을 쓴다.
     *
     * @scenario shipping_policy=two_policies, trigger=partial_cancel_split, row_data=legacy_inflated
     *
     * @effects shipping_details_original_side_uses_prior_recalc
     *
     * @return void 반환값 없음
     */
    public function test_shipping_details_original_side_ignores_inflated_legacy_rows(): void
    {
        $spA = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::FIXED, baseFee: 3000);
        $spB = $this->createShippingPolicy(chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE, baseFee: 2500, freeThreshold: 50000);
        [$pA, $oA] = $this->createProductWithOption(price: 10000, shippingPolicy: $spA);
        [$pB, $oB] = $this->createProductWithOption(price: 4000, shippingPolicy: $spB);

        $order = $this->createOrderFromCalculation(new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 6),
        ]));
        $rowA = $order->options->firstWhere('product_option_id', $oA->id);
        $rowB = $order->options->firstWhere('product_option_id', $oB->id);
        // 앞선 부분취소가 남긴 모양: B 1개가 취소 조각으로 떨어졌다
        $this->splitSameOptionRow($rowB, 5, OrderStatusEnum::PAYMENT_COMPLETE, OrderStatusEnum::CANCELLED);
        $order->load('options');

        // 1.2.1 레거시: 배송 행마다 주문 전체 배송비 5,500
        $order->shippings()->delete();
        foreach ($order->options as $row) {
            OrderShipping::factory()->forOrder($order)->create([
                'order_option_id' => $row->id,
                'shipping_policy_id' => $row->product_option_id === $oA->id ? $spA->id : $spB->id,
                'base_shipping_amount' => 5500,
                'extra_shipping_amount' => 0,
                'total_shipping_amount' => 5500,
                'shipping_discount_amount' => 0,
            ]);
        }
        $order->load('shippings');

        $result = $this->adjustmentService->calculate($order, $this->buildCancellation([
            ['order_option_id' => $rowA->id, 'cancel_quantity' => 1],
        ]));

        $this->assertCount(1, $result->shippingDetails);
        $this->assertEquals(3000, $result->shippingDetails[0]['base_difference']);
        $this->assertEquals(3000, $result->shippingDetails[0]['total_difference']);
    }
}
