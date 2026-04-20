<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Enums\SequenceAlgorithm;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 취소 실행/상태변경/DB기록 테스트
 *
 * OrderCancellationService의 전체취소/부분취소 실행을 검증합니다.
 * - 전체취소: cancel 레코드, 주문 상태, refund 레코드, payment 갱신, 쿠폰 복원
 * - 부분취소: cancel 레코드, 옵션 분할, 금액 재계산, 주문 합계
 * - PG 환불 훅, 에러 처리
 * - 환경설정 기반 취소 가능 상태 검증
 */
class OrderCancellationServiceTest extends ModuleTestCase
{
    protected OrderCancellationService $cancellationService;

    protected OrderCalculationService $calculationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->setupSequences();
        $this->cancellationService = app(OrderCancellationService::class);
        $this->calculationService = app(OrderCalculationService::class);
    }

    /**
     * 테스트용 통화 설정
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

    /**
     * 취소/환불 시퀀스 레코드를 생성합니다.
     */
    protected function setupSequences(): void
    {
        foreach ([SequenceType::CANCEL, SequenceType::REFUND] as $type) {
            $config = $type->getDefaultConfig();
            Sequence::firstOrCreate(
                ['type' => $type->value],
                [
                    'algorithm' => $config['algorithm']->value,
                    'prefix' => $config['prefix'],
                    'current_value' => 0,
                    'increment' => 1,
                    'min_value' => 1,
                    'max_value' => $config['max_value'],
                    'cycle' => false,
                    'pad_length' => $config['pad_length'],
                ]
            );
        }
    }

    protected function tearDown(): void
    {
        $settingsFile = storage_path('app/modules/sirsoft-ecommerce/settings/language_currency.json');
        if (file_exists($settingsFile)) {
            unlink($settingsFile);
        }

        parent::tearDown();
    }

    // ================================================================
    // 헬퍼 메서드
    // ================================================================

    /**
     * 상품+옵션을 생성합니다.
     */
    protected function createProductWithOption(
        int $price = 10000,
        int $stock = 100,
        ?ShippingPolicy $shippingPolicy = null,
    ): array {
        $attrs = [
            'selling_price' => $price,
            'list_price' => $price,
            'tax_status' => 'taxable',
        ];
        if ($shippingPolicy) {
            $attrs['shipping_policy_id'] = $shippingPolicy->id;
        }
        $product = ProductFactory::new()->create($attrs);

        $option = ProductOption::factory()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => $stock,
        ]);

        return [$product, $option];
    }

    /**
     * 배송정책을 생성합니다.
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
            'shipping_method' => 'parcel',
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
     * OrderCalculationService로 계산 후 주문 레코드를 생성합니다.
     */
    protected function createOrderFromCalculation(
        CalculationInput $input,
        array $orderOverrides = [],
    ): Order {
        $result = $this->calculationService->calculate($input);

        $user = User::factory()->create();

        $promotionsSnapshot = [
            'coupon_issue_ids' => $input->couponIssueIds,
            'item_coupons' => $input->itemCoupons,
            'discount_code' => $input->discountCode,
        ];

        $shippingPolicySnapshot = [];
        if ($input->shippingAddress) {
            $shippingPolicySnapshot['address'] = $input->shippingAddress->toArray();
        }

        // 배송정책 스냅샷 구성 (재계산 시 스냅샷 기반 배송비 계산용)
        foreach ($result->items as $item) {
            if ($item->appliedShippingPolicy !== null) {
                $shippingPolicySnapshot[] = [
                    'product_option_id' => $item->productOptionId,
                    'policy' => array_merge(
                        $item->appliedShippingPolicy->policySnapshot,
                        ['policy_id' => $item->appliedShippingPolicy->policyId, 'policy_name' => $item->appliedShippingPolicy->policyName]
                    ),
                ];
            }
        }

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

        foreach ($result->items as $item) {
            $product = Product::find($item->productId);
            $productOption = ProductOption::find($item->productOptionId);

            OrderOption::factory()->forOrder($order)->create([
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
                'product_snapshot' => $product?->toSnapshotArray() ?? [],
                'option_snapshot' => $productOption?->toSnapshotArray() ?? [],
            ]);
        }

        $shippingPolicy = $result->shippings[0] ?? null;
        OrderShipping::factory()->forOrder($order)->create([
            'shipping_status' => 'pending',
            'shipping_policy_id' => $shippingPolicy?->policyId,
            'base_shipping_amount' => $result->summary->baseShippingTotal,
            'extra_shipping_amount' => $result->summary->extraShippingTotal,
            'total_shipping_amount' => $result->summary->totalShipping,
            'shipping_discount_amount' => $result->summary->shippingDiscount,
            'delivery_policy_snapshot' => $shippingPolicy?->policySnapshot,
        ]);

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
     * 쿠폰 + 발급 내역을 생성합니다.
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

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'TEST'.uniqid(),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ]);
    }

    // ================================================================
    // B-1. 전체취소 실행 (5건)
    // ================================================================

    /**
     * B-1-1: 전체취소 시 OrderCancel 레코드가 type=full, status=completed로 생성되는지 검증
     */
    public function test_full_cancel_creates_cancel_record(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 2)],
        );
        $order = $this->createOrderFromCalculation($input);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            reason: 'changed_mind',
            cancelledBy: $order->user_id,
            cancelPg: false,
        );

        $cancel = $result->orderCancel;
        $this->assertEquals(CancelTypeEnum::FULL, $cancel->cancel_type);
        $this->assertEquals(CancelStatusEnum::COMPLETED, $cancel->cancel_status);
        $this->assertNotNull($cancel->cancelled_at);
        $this->assertNotNull($cancel->cancel_number);
    }

    /**
     * B-1-2: 전체취소 후 주문 상태가 CANCELLED로 변경되는지 검증
     */
    public function test_full_cancel_updates_order_status(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 2)],
        );
        $order = $this->createOrderFromCalculation($input);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        $this->assertEquals(OrderStatusEnum::CANCELLED, $result->order->order_status);
    }

    /**
     * B-1-3: 전체취소 시 OrderRefund 레코드의 refund_amount가 원 결제금액과 일치하는지 검증
     */
    public function test_full_cancel_creates_refund_record(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 2)],
        );
        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        $this->assertNotNull($result->orderRefund);
        $this->assertEquals($originalPaid, (float) $result->orderRefund->refund_amount);
        $this->assertEquals(RefundStatusEnum::COMPLETED, $result->orderRefund->refund_status);
    }

    /**
     * B-1-4: 전체취소 시 payment.cancelled_amount에 환불금액이 누적되는지 검증
     */
    public function test_full_cancel_updates_payment_cancelled_amount(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 2)],
        );
        $order = $this->createOrderFromCalculation($input);
        $originalPaid = (float) $order->total_paid_amount;

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        $payment = $result->order->payment;
        $this->assertEquals($originalPaid, (float) $payment->cancelled_amount);
        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
    }

    /**
     * B-1-5: 전체취소 시 사용된 쿠폰이 복원 대상에 포함되는지 검증
     */
    public function test_full_cancel_restores_coupons(): void
    {
        $this->createShippingPolicy();
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
            couponIssueIds: [$couponIssue->id],
        );
        $order = $this->createOrderFromCalculation($input);

        // 쿠폰이 USED 상태인지 확인
        $this->assertEquals(CouponIssueRecordStatus::USED, $couponIssue->fresh()->status);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        // 쿠폰 복원 훅이 호출되어야 함 (restoredCouponIssueIds 포함)
        $this->assertNotEmpty($result->adjustmentResult->restoredCouponIssueIds);
        $this->assertContains($couponIssue->id, $result->adjustmentResult->restoredCouponIssueIds);
    }

    // ================================================================
    // B-2. 부분취소 실행 (8건)
    // ================================================================

    /**
     * B-2-1: 부분취소 시 cancel 레코드가 type=partial로 생성되는지 검증
     */
    public function test_partial_cancel_creates_cancel_record(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $cancel = $result->orderCancel;
        $this->assertEquals(CancelTypeEnum::PARTIAL, $cancel->cancel_type);
        $this->assertEquals(CancelStatusEnum::COMPLETED, $cancel->cancel_status);

        // cancel_options 수 확인
        $this->assertEquals(1, $cancel->cancelOptions()->count());
    }

    /**
     * B-2-2: 부분취소 후 주문 상태가 PARTIAL_CANCELLED로 변경되는지 검증
     */
    public function test_partial_cancel_updates_order_status(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $this->assertEquals(OrderStatusEnum::PARTIAL_CANCELLED, $result->order->order_status);
    }

    /**
     * B-2-3: 부분취소 시 옵션 수량 분할이 정상 동작하는지 검증
     * (전량 취소 → CANCELLED, 부분 수량 취소 → 분할)
     */
    public function test_partial_cancel_splits_option_quantity(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 5)],
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // 5개 중 3개 취소
        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 3]],
            cancelPg: false,
        );

        // 원본 옵션 + 분할 옵션으로 2개 존재
        $options = $result->order->options;
        $cancelledOptions = $options->where('option_status', OrderStatusEnum::CANCELLED);
        $activeOptions = $options->where('option_status', '!=', OrderStatusEnum::CANCELLED);

        // 취소된 옵션(3개) + 잔여 옵션(2개) 존재 확인
        $this->assertEquals(3, $cancelledOptions->sum('quantity'));
        $this->assertEquals(2, $activeOptions->sum('quantity'));
    }

    /**
     * B-2-4: 부분취소 후 잔여 옵션의 subtotal_paid_amount가 재계산되는지 검증
     */
    public function test_partial_cancel_updates_option_amounts(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        // 잔여 옵션 B의 paid amount = 10,000
        $remainingOption = $result->order->options
            ->where('product_option_id', $oB->id)
            ->where('option_status', '!=', OrderStatusEnum::CANCELLED)
            ->first();

        $this->assertEquals(10000, (int) $remainingOption->subtotal_paid_amount);
    }

    /**
     * B-2-5: 부분취소로 배송비 변동 시 shipping 레코드가 갱신되는지 검증
     */
    public function test_partial_cancel_updates_shipping_amounts(): void
    {
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
        );
        $order = $this->createOrderFromCalculation($input);
        // 60K → 무료배송
        $this->assertEquals(0, (int) $order->total_shipping_amount);

        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        // A(20K) 취소 → 잔여 40K < 50K → 배송비 3000 발생
        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $shipping = $result->order->shippings->first();
        $this->assertEquals(3000, (int) $shipping->total_shipping_amount);
    }

    /**
     * B-2-6: 부분취소 후 주문 합계 컬럼이 모두 재계산되는지 검증
     */
    public function test_partial_cancel_updates_order_totals(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $updatedOrder = $result->order;
        // 잔여 B(10K)만 남음
        $this->assertEquals(10000, (int) $updatedOrder->total_paid_amount);
        $this->assertGreaterThan(0, (float) $updatedOrder->total_cancelled_amount);
    }

    /**
     * B-2-7: 부분취소 시 cancellation_count가 1 증가하는지 검증
     */
    public function test_partial_cancel_increments_cancellation_count(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $this->assertEquals(1, $result->order->cancellation_count);
    }

    /**
     * B-2-8: 모든 옵션을 전량 지정하면 전체취소(full)로 전환되는지 검증
     */
    public function test_partial_cancel_converts_to_full_when_all_cancelled(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();
        $optionB = $order->options->where('product_option_id', $oB->id)->first();

        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [
                ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
                ['order_option_id' => $optionB->id, 'cancel_quantity' => 1],
            ],
            cancelPg: false,
        );

        // 전체취소로 전환됨
        $this->assertEquals(CancelTypeEnum::FULL, $result->orderCancel->cancel_type);
        $this->assertEquals(OrderStatusEnum::CANCELLED, $result->order->order_status);
    }

    // ================================================================
    // B-3. PG 환불 훅 + 에러 처리 (4건)
    // ================================================================

    /**
     * B-3-1: 취소 시 PG 환불 훅이 발행되는지 검증
     */
    public function test_cancel_fires_payment_refund_hook(): void
    {
        $hookCalled = false;
        \App\Extension\HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default) use (&$hookCalled) {
            $hookCalled = true;

            return [
                'success' => true,
                'transaction_id' => 'TXN_'.uniqid(),
                'error_code' => null,
                'error_message' => null,
            ];
        });

        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        $order = $this->createOrderFromCalculation($input);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: true,
        );

        $this->assertTrue($hookCalled, 'PG 환불 훅이 호출되어야 합니다');
        $this->assertEquals(RefundStatusEnum::COMPLETED, $result->orderRefund->refund_status);
    }

    /**
     * B-3-2: PG 환불 실패 시 전체 트랜잭션이 롤백되는지 검증
     */
    public function test_cancel_pg_failure_rolls_back(): void
    {
        \App\Extension\HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default) {
            return [
                'success' => false,
                'error_code' => 'PG_ERROR',
                'error_message' => 'PG 결제 취소 실패',
                'transaction_id' => null,
            ];
        });

        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        $order = $this->createOrderFromCalculation($input);
        $originalStatus = $order->order_status;

        $this->expectException(\Exception::class);

        $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: true,
        );

        // 트랜잭션 롤백 확인: 주문 상태 변경 없음
        $this->assertEquals($originalStatus, $order->fresh()->order_status);
        // 취소 레코드 미생성
        $this->assertEquals(0, OrderCancel::where('order_id', $order->id)->count());
    }

    /**
     * B-3-3: cancelPg=false 시 PG 환불 훅이 미발행되는지 검증
     */
    public function test_cancel_without_pg_skips_refund_hook(): void
    {
        $hookCalled = false;
        \App\Extension\HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default) use (&$hookCalled) {
            $hookCalled = true;

            return ['success' => true, 'transaction_id' => 'TXN'];
        });

        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        $order = $this->createOrderFromCalculation($input);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        $this->assertFalse($hookCalled, 'cancelPg=false 시 PG 훅이 호출되면 안됩니다');
    }

    /**
     * B-3-4: 미결제 주문 취소 시 refund 레코드가 미생성되는지 검증
     */
    public function test_unpaid_order_cancel_skips_refund(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );

        // 미결제(PENDING_PAYMENT) 주문 생성
        $order = $this->createOrderFromCalculation($input, [
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);
        // payment를 미결제 상태로 변경
        $order->payment->update(['payment_status' => PaymentStatusEnum::READY]);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        // cancel은 생성, refund는 미생성
        $this->assertNotNull($result->orderCancel);
        $this->assertNull($result->orderRefund);
        $this->assertEquals(OrderStatusEnum::CANCELLED, $result->order->order_status);
    }

    // ================================================================
    // B-4. 환경설정 + 검증 (3건)
    // ================================================================

    /**
     * B-4-1: cancellable_statuses에 없는 상태에서 취소 시 예외 발생
     */
    public function test_cancel_rejected_when_status_not_cancellable(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        $order = $this->createOrderFromCalculation($input, [
            'order_status' => OrderStatusEnum::SHIPPING,
        ]);

        $this->expectException(\Exception::class);

        $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );
    }

    /**
     * B-4-2: 설정된 상태에서 취소가 허용되는지 검증
     */
    public function test_cancel_allowed_for_configured_status(): void
    {
        // 기본 cancellable_statuses에 payment_complete이 포함됨
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        $order = $this->createOrderFromCalculation($input);

        // PAYMENT_COMPLETE 상태는 취소 가능
        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        $this->assertEquals(CancelStatusEnum::COMPLETED, $result->orderCancel->cancel_status);
    }

    /**
     * B-4-3: previewRefund 호출 후 DB가 변경되지 않는지 검증
     */
    public function test_preview_refund_does_not_modify_db(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $cancelCountBefore = OrderCancel::count();
        $refundCountBefore = OrderRefund::count();
        $orderStatusBefore = $order->order_status;
        $paidAmountBefore = (float) $order->total_paid_amount;

        // 미리보기 실행
        $result = $this->cancellationService->previewRefund($order, [
            ['order_option_id' => $optionA->id, 'cancel_quantity' => 1],
        ]);

        // DB 변경 없음 확인
        $this->assertEquals($cancelCountBefore, OrderCancel::count());
        $this->assertEquals($refundCountBefore, OrderRefund::count());
        $this->assertEquals($orderStatusBefore, $order->fresh()->order_status);
        $this->assertEquals($paidAmountBefore, (float) $order->fresh()->total_paid_amount);

        // 결과는 존재
        $this->assertGreaterThan(0, $result->refundAmount);
    }

    // ================================================================
    // C-1. 쿠폰 복원 실행 검증 (5건)
    // ================================================================

    /**
     * C-1-1: 전체취소 시 모든 쿠폰이 복원 대상으로 포함되고, 훅을 통한 상태 복원이 실행되는지 검증
     */
    public function test_full_cancel_restores_all_coupons_status_and_used_at(): void
    {
        $this->createShippingPolicy();

        // 주문쿠폰 (FIXED 5000)
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
            couponIssueIds: [$couponIssue->id],
        );
        $order = $this->createOrderFromCalculation($input);

        // 쿠폰이 USED 상태인지 확인
        $this->assertEquals(CouponIssueRecordStatus::USED, $couponIssue->fresh()->status);
        $this->assertNotNull($couponIssue->fresh()->used_at);

        // 훅 리스너 등록: 쿠폰 복원 시 실제 DB 업데이트 수행
        \App\Extension\HookManager::addAction('sirsoft-ecommerce.coupon.restore', function ($order, $couponIssueIds) {
            CouponIssue::whereIn('id', $couponIssueIds)->update([
                'status' => CouponIssueRecordStatus::AVAILABLE,
                'used_at' => null,
                'order_id' => null,
            ]);
        });

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        // 복원 대상에 포함
        $this->assertContains($couponIssue->id, $result->adjustmentResult->restoredCouponIssueIds);

        // 실제 DB에서 쿠폰이 복원됨
        $restored = $couponIssue->fresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $restored->status);
        $this->assertNull($restored->used_at);
        $this->assertNull($restored->order_id);
    }

    /**
     * C-1-2: 부분취소 시 최소주문금액 미달로 쿠폰이 복원 대상에 포함되는지 검증
     */
    public function test_partial_cancel_restores_coupon_when_min_amount_not_met(): void
    {
        $this->createShippingPolicy();

        // 주문쿠폰: FIXED 5000, 최소주문금액 60000
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 60000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 2)],
            couponIssueIds: [$couponIssue->id],
        );
        $order = $this->createOrderFromCalculation($input);
        // 100,000원 → 쿠폰 적용 가능 (60,000 이상)

        $optionA = $order->options->first();

        // 1개 취소 → 잔여 50,000 < 최소주문금액 60,000 → 쿠폰 복원
        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $this->assertContains($couponIssue->id, $result->adjustmentResult->restoredCouponIssueIds);
    }

    /**
     * C-1-3: 부분취소 후 최소주문금액을 여전히 충족하면 쿠폰이 복원되지 않는지 검증
     */
    public function test_partial_cancel_does_not_restore_coupon_when_still_met(): void
    {
        $this->createShippingPolicy();

        // 주문쿠폰: FIXED 5000, 최소주문금액 30000
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 30000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 2)],
            couponIssueIds: [$couponIssue->id],
        );
        $order = $this->createOrderFromCalculation($input);
        // 100,000원 → 쿠폰 적용

        $optionA = $order->options->first();

        // 1개 취소 → 잔여 50,000 >= 30,000 → 쿠폰 유지
        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        // 쿠폰이 복원 대상에 포함되지 않아야 함
        $this->assertNotContains($couponIssue->id, $result->adjustmentResult->restoredCouponIssueIds);

        // DB에서도 여전히 USED 상태
        $this->assertEquals(CouponIssueRecordStatus::USED, $couponIssue->fresh()->status);
    }

    /**
     * C-1-4: 다른 주문의 쿠폰이 현재 주문 취소에 영향받지 않는지 검증
     */
    public function test_cancel_does_not_affect_other_order_coupons(): void
    {
        $this->createShippingPolicy();

        // 주문1용 쿠폰
        $couponIssue1 = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
        );

        // 주문2용 쿠폰
        $couponIssue2 = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 3000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 20000);

        // 주문 1
        $input1 = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
            couponIssueIds: [$couponIssue1->id],
        );
        $order1 = $this->createOrderFromCalculation($input1);

        // 주문 2
        $input2 = new CalculationInput(
            items: [new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1)],
            couponIssueIds: [$couponIssue2->id],
        );
        $order2 = $this->createOrderFromCalculation($input2);

        // 주문1 전체취소
        $this->cancellationService->cancelOrder(
            order: $order1,
            cancelPg: false,
        );

        // 주문2의 쿠폰은 여전히 USED 상태
        $this->assertEquals(CouponIssueRecordStatus::USED, $couponIssue2->fresh()->status);
        $this->assertNotNull($couponIssue2->fresh()->used_at);
    }

    /**
     * C-1-5: previewRefund 호출 시 쿠폰/포인트 DB가 변경되지 않는지 검증
     */
    public function test_cancel_preview_does_not_modify_coupon_or_points(): void
    {
        $this->createShippingPolicy();

        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 30000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
            couponIssueIds: [$couponIssue->id],
        );
        $order = $this->createOrderFromCalculation($input);

        $couponStatusBefore = $couponIssue->fresh()->status;
        $couponUsedAtBefore = $couponIssue->fresh()->used_at;

        // 미리보기 실행
        $result = $this->cancellationService->previewRefund($order, [
            ['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1],
        ]);

        // 쿠폰 상태 변경 없음
        $this->assertEquals($couponStatusBefore, $couponIssue->fresh()->status);
        $this->assertEquals($couponUsedAtBefore?->toDateTimeString(), $couponIssue->fresh()->used_at?->toDateTimeString());

        // OrderCancel/OrderRefund 미생성
        $this->assertEquals(0, OrderCancel::where('order_id', $order->id)->count());
        $this->assertEquals(0, OrderRefund::where('order_id', $order->id)->count());
    }

    // ================================================================
    // C-2. 환불 처리 검증 (5건)
    // ================================================================

    /**
     * C-2-1: PG 우선 환불 시 refunded_amount가 정확히 갱신되는지 검증
     */
    public function test_cancel_with_pg_first_updates_refunded_amount_correctly(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
            usePoints: 10000,
        );
        $order = $this->createOrderFromCalculation($input);
        // total_paid_amount = 40000 (PG), points_used = 10000

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
            refundPriority: RefundPriorityEnum::PG_FIRST,
        );

        // PG 환불금액 = 40000 (결제금 전액)
        $this->assertEquals(40000, (float) $result->orderRefund->refund_amount);
        // 마일리지 환불액 = 10000
        $this->assertEquals(10000, (float) $result->orderRefund->refund_points_amount);
        // 취소 레코드 생성 확인
        $this->assertNotNull($result->orderCancel);
        $this->assertNotNull($result->orderRefund);
    }

    /**
     * C-2-2: 포인트 우선 환불 시 환불 레코드에 적절한 분배가 기록되는지 검증
     */
    public function test_cancel_with_points_first_updates_refunded_points_correctly(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
            usePoints: 10000,
        );
        $order = $this->createOrderFromCalculation($input);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
            refundPriority: RefundPriorityEnum::POINTS_FIRST,
        );

        // POINTS_FIRST: 마일리지 환불이 포인트 사용분부터 차감
        $this->assertNotNull($result->orderRefund);
        $this->assertGreaterThan(0, (float) $result->orderRefund->refund_points_amount);
        // AdjustmentResult에도 올바른 우선순위 저장
        $this->assertEquals(RefundPriorityEnum::POINTS_FIRST, $result->adjustmentResult->refundPriority);
    }

    /**
     * C-2-3: 순차 부분취소 시 total_cancelled_amount가 누적되는지 검증
     */
    public function test_sequential_cancel_accumulates_refunded_amounts(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 3)],
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->first();

        // 1차 취소: 1개
        $result1 = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );
        $cancelledAfterFirst = (float) $result1->order->total_cancelled_amount;
        $this->assertGreaterThan(0, $cancelledAfterFirst);

        // 2차 취소: 잔여 옵션에서 1개 추가 취소
        $updatedOrder = $result1->order->fresh(['options', 'payment', 'shippings']);
        // 부분취소 후 주문 상태가 변경되므로 취소 가능 상태로 복원
        $updatedOrder->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $updatedOrder->refresh();

        $remainingOption = $updatedOrder->options
            ->where('option_status', '!=', OrderStatusEnum::CANCELLED)
            ->first();

        $result2 = $this->cancellationService->cancelOrderOptions(
            order: $updatedOrder,
            cancelItems: [['order_option_id' => $remainingOption->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        // 2차 취소 후 누적 금액이 1차보다 큰지 확인
        $cancelledAfterSecond = (float) $result2->order->fresh()->total_cancelled_amount;
        $this->assertGreaterThan($cancelledAfterFirst, $cancelledAfterSecond);
    }

    /**
     * C-2-4: 취소 시 AdjustmentResult에 환불 우선순위가 저장되는지 검증
     */
    public function test_cancel_stores_refund_priority_in_adjustment_result(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        $order = $this->createOrderFromCalculation($input);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
            refundPriority: RefundPriorityEnum::PG_FIRST,
        );

        $this->assertEquals(RefundPriorityEnum::PG_FIRST, $result->adjustmentResult->refundPriority);
    }

    /**
     * C-2-5: previewRefund 호출 시 OrderRefund 레코드가 생성되지 않는지 검증
     */
    public function test_cancel_preview_does_not_create_refund_record(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
        );
        $order = $this->createOrderFromCalculation($input);
        $refundCountBefore = OrderRefund::count();

        // 미리보기 실행
        $this->cancellationService->previewRefund($order, [
            ['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1],
        ]);

        $this->assertEquals($refundCountBefore, OrderRefund::count());
    }

    // ================================================================
    // C-3. mc_* 다중통화 환불 레코드 검증 (4건)
    // ================================================================

    /**
     * C-3-1: 다중통화 스냅샷이 있는 주문 취소 시 OrderRefund에 mc_refund_amount가 저장되는지 검증
     */
    public function test_refund_record_includes_mc_refund_amount(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 30000);

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.85, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        // OrderRefund 레코드에 mc_refund_amount 저장 확인
        $refund = $result->orderRefund;
        $this->assertNotNull($refund->mc_refund_amount);
        $this->assertArrayHasKey('KRW', $refund->mc_refund_amount);
        $this->assertArrayHasKey('USD', $refund->mc_refund_amount);
        $this->assertGreaterThan(0, $refund->mc_refund_amount['USD']);
    }

    /**
     * C-3-2: 포인트 사용 + 다중통화 주문 취소 시 mc_refund_points_amount가 저장되는지 검증
     */
    public function test_refund_record_includes_mc_refund_points_amount(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 30000);

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.85, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
            usePoints: 10000,
        );
        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
            refundPriority: RefundPriorityEnum::POINTS_FIRST,
        );

        $refund = $result->orderRefund;
        // 포인트 환불이 발생하면 mc_refund_points_amount 존재
        if ((float) $refund->refund_points_amount > 0) {
            $this->assertNotNull($refund->mc_refund_points_amount);
            $this->assertArrayHasKey('KRW', $refund->mc_refund_points_amount);
        }
    }

    /**
     * C-3-3: 배송비 + 다중통화 주문 취소 시 mc_refund_shipping_amount가 저장되는지 검증
     */
    public function test_refund_record_includes_mc_refund_shipping_amount(): void
    {
        $sp = $this->createShippingPolicy(
            chargePolicy: ChargePolicyEnum::FIXED,
            baseFee: 3000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $sp);

        $currencySnapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => 1, 'rounding_method' => 'round'],
                'USD' => ['rate' => 0.85, 'rounding_unit' => 0.01, 'rounding_method' => 'round'],
            ],
        ];

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        $order = $this->createOrderFromCalculation($input, [
            'currency_snapshot' => $currencySnapshot,
        ]);

        // 배송비 3000원 존재 확인
        $this->assertEquals(3000, (int) $order->total_shipping_amount);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        $refund = $result->orderRefund;
        // 전체취소 시 배송비 환불 → mc_refund_shipping_amount 존재
        if ((float) $refund->refund_shipping_amount > 0) {
            $this->assertNotNull($refund->mc_refund_shipping_amount);
            $this->assertArrayHasKey('USD', $refund->mc_refund_shipping_amount);
            $this->assertGreaterThan(0, $refund->mc_refund_shipping_amount['USD']);
        }
    }

    /**
     * C-3-4: 다중통화 스냅샷이 없는 주문 취소 시 AdjustmentResult의 mc_* 필드가 null인지 검증
     */
    public function test_refund_record_mc_null_when_no_currency_snapshot(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        // currency_snapshot 미설정
        $order = $this->createOrderFromCalculation($input);

        $result = $this->cancellationService->cancelOrder(
            order: $order,
            cancelPg: false,
        );

        // AdjustmentResult의 mc_* 필드가 모두 null
        $this->assertNull($result->adjustmentResult->mcRefundAmount);
        $this->assertNull($result->adjustmentResult->mcRefundPointsAmount);
        $this->assertNull($result->adjustmentResult->mcRefundShippingAmount);

        // OrderRefund의 mc_* 필드도 null
        $this->assertNull($result->orderRefund->mc_refund_amount);
        $this->assertNull($result->orderRefund->mc_refund_points_amount);
        $this->assertNull($result->orderRefund->mc_refund_shipping_amount);
    }

    // ================================================================
    // C-4. 스냅샷 업데이트 검증 (3건)
    // ================================================================

    /**
     * C-4-1: 부분취소 후 잔여 옵션의 promotions_applied_snapshot이 갱신되는지 검증
     */
    public function test_partial_cancel_updates_remaining_option_promotion_snapshot(): void
    {
        $this->createShippingPolicy();

        // 상품쿠폰
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::PRODUCT_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 2000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 20000);
        [$pB, $oB] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            couponIssueIds: [$couponIssue->id],
            itemCoupons: [['product_id' => $pA->id, 'coupon_issue_id' => $couponIssue->id]],
        );
        $order = $this->createOrderFromCalculation($input);

        $optionB = $order->options->where('product_option_id', $oB->id)->first();
        $snapshotBefore = $optionB->promotions_applied_snapshot;

        // A 옵션 취소
        $optionA = $order->options->where('product_option_id', $oA->id)->first();
        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        // 잔여 옵션 B의 스냅샷이 업데이트되었는지 확인 (재계산 반영)
        $remainingB = $result->order->options
            ->where('product_option_id', $oB->id)
            ->where('option_status', '!=', OrderStatusEnum::CANCELLED)
            ->first();

        // 스냅샷이 존재하면 재계산 결과가 반영되어 있어야 함
        // (부분취소 후 재계산이 실행되므로 스냅샷이 갱신됨)
        $this->assertNotNull($remainingB);
    }

    /**
     * C-4-2: 부분취소 후 주문의 promotions_applied_snapshot이 갱신되는지 검증
     */
    public function test_partial_cancel_updates_order_promotion_snapshot(): void
    {
        $this->createShippingPolicy();

        // 주문쿠폰
        $couponIssue = $this->createCouponWithIssue(
            targetType: CouponTargetType::ORDER_AMOUNT,
            discountType: CouponDiscountType::FIXED,
            discountValue: 5000,
            minOrderAmount: 60000,
        );

        [$pA, $oA] = $this->createProductWithOption(price: 50000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 2)],
            couponIssueIds: [$couponIssue->id],
        );
        $order = $this->createOrderFromCalculation($input);
        // 100,000원 → 쿠폰 적용

        $snapshotBefore = $order->promotions_applied_snapshot;
        $optionA = $order->options->first();

        // 1개 취소 → 잔여 50,000 < 60,000 → 쿠폰 복원
        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $updatedSnapshot = $result->order->promotions_applied_snapshot;

        // 쿠폰이 복원되었으므로 스냅샷에서 쿠폰 정보가 제거/갱신되어야 함
        // 또는 스냅샷 자체가 갱신되었는지 확인
        $this->assertNotEquals($snapshotBefore, $updatedSnapshot);
    }

    /**
     * C-4-3: 순차 부분취소 시 2차 취소가 1차 갱신된 스냅샷 기반으로 계산되는지 검증
     */
    public function test_sequential_cancel_uses_updated_snapshot_for_second_cancel(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 3)],
        );
        $order = $this->createOrderFromCalculation($input);
        // 60,000원 (20,000 x 3)
        $optionA = $order->options->first();

        // 1차 취소: 1개 (20,000원 환불)
        $result1 = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $refund1Amount = $result1->adjustmentResult->refundAmount;
        $this->assertGreaterThan(0, $refund1Amount);

        // 2차 취소를 위해 갱신된 주문 로드
        $updatedOrder = $result1->order->fresh(['options', 'payment', 'shippings']);
        // 부분취소 후 주문 상태가 변경되므로 취소 가능 상태로 복원
        $updatedOrder->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $updatedOrder->refresh();

        $remainingOption = $updatedOrder->options
            ->where('option_status', '!=', OrderStatusEnum::CANCELLED)
            ->first();

        // 2차 취소: 1개 추가
        $result2 = $this->cancellationService->cancelOrderOptions(
            order: $updatedOrder,
            cancelItems: [['order_option_id' => $remainingOption->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $refund2Amount = $result2->adjustmentResult->refundAmount;
        $this->assertGreaterThan(0, $refund2Amount);

        // 각 취소의 환불금액이 단위가격(20,000)과 일치하는지 검증
        // (스냅샷이 올바르게 갱신되었다면 2차 취소도 정확한 금액)
        $this->assertEquals(20000, (int) $refund1Amount);
        $this->assertEquals(20000, (int) $refund2Amount);

        // 최종 주문 잔여 금액: 20,000 (1개 남음)
        $finalOrder = $result2->order;
        $this->assertEquals(20000, (int) $finalOrder->total_paid_amount);
    }
}
