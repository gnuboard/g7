<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\TempOrderFactory;
use Modules\Sirsoft\Ecommerce\DTO\AppliedPromotions;
use Modules\Sirsoft\Ecommerce\DTO\AppliedShippingPolicy;
use Modules\Sirsoft\Ecommerce\DTO\CouponApplication;
use Modules\Sirsoft\Ecommerce\DTO\ItemCalculation;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\PromotionsSummary;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderAmountChangedException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 생성 서비스 테스트
 */
class OrderProcessingServiceTest extends ModuleTestCase
{
    protected OrderProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderProcessingService::class);
    }

    // ===== 기존 테스트 (determineInitialStatus, isFirstOrder, generateOrderNumber) =====

    public function test_determine_initial_status_returns_pending_payment_for_vbank(): void
    {
        // Protected 메서드 테스트를 위한 리플렉션
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineInitialStatus');
        $method->setAccessible(true);

        $status = $method->invoke($this->service, 'vbank');

        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $status);
    }

    public function test_determine_initial_status_returns_pending_order_for_card(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineInitialStatus');
        $method->setAccessible(true);

        $status = $method->invoke($this->service, 'card');

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $status);
    }

    public function test_determine_initial_status_returns_pending_order_for_pg(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineInitialStatus');
        $method->setAccessible(true);

        $status = $method->invoke($this->service, 'pg');

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $status);
    }

    public function test_is_first_order_returns_true_for_new_user(): void
    {
        $user = User::factory()->create();

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isFirstOrder');
        $method->setAccessible(true);

        $isFirstOrder = $method->invoke($this->service, $user->id);

        $this->assertTrue($isFirstOrder);
    }

    public function test_is_first_order_returns_false_for_user_with_orders(): void
    {
        $user = User::factory()->create();

        // 기존 주문 생성
        Order::factory()->create(['user_id' => $user->id]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isFirstOrder');
        $method->setAccessible(true);

        $isFirstOrder = $method->invoke($this->service, $user->id);

        $this->assertFalse($isFirstOrder);
    }

    public function test_is_first_order_returns_true_for_guest(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isFirstOrder');
        $method->setAccessible(true);

        $isFirstOrder = $method->invoke($this->service, null);

        $this->assertTrue($isFirstOrder);
    }

    public function test_generate_order_number_returns_unique_string(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateOrderNumber');
        $method->setAccessible(true);

        $orderNumber1 = $method->invoke($this->service);
        $orderNumber2 = $method->invoke($this->service);

        $this->assertIsString($orderNumber1);
        $this->assertIsString($orderNumber2);
        $this->assertNotEquals($orderNumber1, $orderNumber2);
    }

    public function test_generate_order_number_has_expected_format(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateOrderNumber');
        $method->setAccessible(true);

        $orderNumber = $method->invoke($this->service);

        // SequenceService TIMESTAMP 알고리즘: Ymd-His + 밀리초3자리 + 랜덤1자리 (예: 20260208-1435226549)
        $this->assertMatchesRegularExpression('/^\d{8}-\d{10}$/', $orderNumber);
    }

    public function test_generate_order_number_is_recorded_in_sequence_codes(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateOrderNumber');
        $method->setAccessible(true);

        $orderNumber = $method->invoke($this->service);

        // 채번된 주문번호가 ecommerce_sequence_codes 테이블에 기록되는지 확인
        $this->assertDatabaseHas('ecommerce_sequence_codes', [
            'type' => 'order',
            'code' => $orderNumber,
        ]);
    }

    public function test_generate_order_number_produces_unique_values(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateOrderNumber');
        $method->setAccessible(true);

        $orderNumbers = [];
        for ($i = 0; $i < 10; $i++) {
            $orderNumbers[] = $method->invoke($this->service);
        }

        // 모든 주문번호가 유일해야 함
        $uniqueNumbers = array_unique($orderNumbers);
        $this->assertCount(10, $uniqueNumbers);

        // 모든 주문번호가 타임스탬프 형식이어야 함
        foreach ($orderNumbers as $orderNumber) {
            $this->assertMatchesRegularExpression('/^\d{8}-\d{10}$/', $orderNumber);
        }
    }

    // ===== createFromTempOrder 재계산 통합 테스트 =====

    /**
     * OrderCalculationService를 mock하여 재계산 결과를 주입하고, 서비스를 재생성합니다.
     *
     * @param  OrderCalculationResult  $result  재계산 결과
     * @return void
     */
    protected function mockCalculationService(OrderCalculationResult $result): void
    {
        $mock = $this->createMock(OrderCalculationService::class);
        $mock->method('calculate')->willReturn($result);

        $this->app->instance(OrderCalculationService::class, $mock);
        $this->service = app(OrderProcessingService::class);
    }

    /**
     * 기본 계산 결과 DTO를 생성합니다.
     *
     * @param  int  $finalAmount  최종 금액
     * @param  array  $overrides  오버라이드 옵션
     * @return OrderCalculationResult
     */
    protected function makeCalculationResult(int $finalAmount = 103000, array $overrides = []): OrderCalculationResult
    {
        $summary = new Summary(
            subtotal: $overrides['subtotal'] ?? 100000,
            totalDiscount: $overrides['total_discount'] ?? 0,
            productCouponDiscount: $overrides['product_coupon_discount'] ?? 0,
            codeDiscount: $overrides['code_discount'] ?? 0,
            totalShipping: $overrides['total_shipping'] ?? 3000,
            taxableAmount: $overrides['taxable_amount'] ?? 93636,
            taxFreeAmount: $overrides['tax_free_amount'] ?? 0,
            pointsUsed: $overrides['points_used'] ?? 0,
            pointsEarning: $overrides['points_earning'] ?? 0,
            paymentAmount: $overrides['payment_amount'] ?? $finalAmount,
            finalAmount: $finalAmount,
        );

        $items = $overrides['items'] ?? [];
        $promotions = $overrides['promotions'] ?? new PromotionsSummary();

        return new OrderCalculationResult(
            items: $items,
            summary: $summary,
            promotions: $promotions,
            validationErrors: $overrides['validation_errors'] ?? [],
        );
    }

    /**
     * 테스트용 기본 TempOrder를 생성합니다.
     *
     * @param  User  $user  사용자
     * @param  int  $finalAmount  최종 금액 (calculation_result.summary.final_amount)
     * @return \Modules\Sirsoft\Ecommerce\Models\TempOrder
     */
    protected function createTestTempOrder(User $user, int $finalAmount = 103000)
    {
        return TempOrderFactory::new()
            ->forUser($user)
            ->withCalculationResult([
                'summary' => [
                    'subtotal' => 100000,
                    'total_discount' => 0,
                    'product_coupon_discount' => 0,
                    'code_discount' => 0,
                    'total_shipping' => 3000,
                    'final_amount' => $finalAmount,
                    'taxable_amount' => 93636,
                    'tax_free_amount' => 0,
                    'points_used' => 0,
                ],
                'items' => [],
                'shippings' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();
    }

    public function test_create_from_temp_order_creates_order(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);

        // mock: 재계산 결과가 저장된 금액과 동일
        $this->mockCalculationService($this->makeCalculationResult(103000));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'test@example.com'],
            ['recipient_name' => '홍길동', 'recipient_phone' => '010-1234-5678', 'zipcode' => '12345', 'address' => '서울시 강남구 테헤란로', 'address_detail' => '123동 456호'],
            'card',
            103000,
            '문앞에 놓아주세요'
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals($user->id, $order->user_id);
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(103000, $order->total_amount);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{10}$/', $order->order_number);
    }

    public function test_create_from_temp_order_with_vbank_sets_pending_payment(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user, 50000);

        $this->mockCalculationService($this->makeCalculationResult(50000, [
            'subtotal' => 50000,
            'total_shipping' => 0,
            'taxable_amount' => 45454,
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => '김철수', 'phone' => '010-9876-5432', 'email' => 'kim@example.com'],
            ['recipient_name' => '김철수', 'recipient_phone' => '010-9876-5432', 'zipcode' => '54321', 'address' => '부산시 해운대구', 'address_detail' => '마린시티 1동'],
            'vbank',
            50000,
            null,
            '김철수'
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
    }

    public function test_create_from_temp_order_preserves_temp_order(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user, 30000);

        $this->mockCalculationService($this->makeCalculationResult(30000, [
            'subtotal' => 30000,
            'total_shipping' => 0,
            'taxable_amount' => 27273,
        ]));

        $tempOrderId = $tempOrder->id;

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test Address', 'address_detail' => 'Test Detail'],
            'card',
            30000
        );

        // PG 결제 취소 → 재결제를 위해 임시주문 유지
        $this->assertDatabaseHas('ecommerce_temp_orders', ['id' => $tempOrderId]);
    }

    // ===== 신규 테스트: 프로모션/배송비/마일리지 데이터 누락 수정 검증 =====

    public function test_create_from_temp_order_populates_promotions_applied_snapshot(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);

        // 쿠폰이 적용된 프로모션 결과 구성
        $coupon = new CouponApplication(
            couponId: 1,
            couponIssueId: 101,
            name: '10% 할인 쿠폰',
            targetType: 'product_amount',
            discountType: 'rate',
            discountValue: 10,
            totalDiscount: 10000,
        );
        $productPromotions = new AppliedPromotions(coupons: [$coupon]);
        $promotions = new PromotionsSummary(productPromotions: $productPromotions);

        $this->mockCalculationService($this->makeCalculationResult(103000, [
            'promotions' => $promotions,
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            103000
        );

        // promotions_applied_snapshot이 비어있지 않아야 함
        $snapshot = $order->promotions_applied_snapshot;
        $this->assertNotEmpty($snapshot);
        $this->assertArrayHasKey('product_promotions', $snapshot);
        $this->assertNotEmpty($snapshot['product_promotions']['coupons']);
        $this->assertEquals(101, $snapshot['product_promotions']['coupons'][0]['coupon_issue_id']);
    }

    public function test_create_from_temp_order_populates_shipping_policy_snapshot(): void
    {
        $user = User::factory()->create();

        // 실제 상품/옵션 생성 (FK 제약조건 충족)
        $product = \Modules\Sirsoft\Ecommerce\Models\Product::factory()->create();
        $productOption = \Modules\Sirsoft\Ecommerce\Models\ProductOption::factory()->create([
            'product_id' => $product->id,
        ]);

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([
                [
                    'cart_id' => 1,
                    'product_id' => $product->id,
                    'product_option_id' => $productOption->id,
                    'quantity' => 1,
                ],
            ])
            ->withCalculationResult([
                'summary' => ['final_amount' => 103000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        // 배송정책이 적용된 아이템 구성
        $shippingPolicy = new AppliedShippingPolicy(
            policyId: 1,
            policyName: '기본 배송',
            chargePolicy: 'paid',
            shippingAmount: 3000,
            extraShippingAmount: 0,
            totalShippingAmount: 3000,
            policySnapshot: ['id' => 1, 'name' => '기본 배송', 'base_fee' => 3000],
        );

        $item = new ItemCalculation(
            productId: $product->id,
            productOptionId: $productOption->id,
            quantity: 1,
            unitPrice: 100000,
            subtotal: 100000,
            finalAmount: 100000,
            appliedShippingPolicy: $shippingPolicy,
        );

        $this->mockCalculationService($this->makeCalculationResult(103000, [
            'items' => [$item],
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            103000
        );

        // shipping_policy_applied_snapshot이 비어있지 않아야 함
        $snapshot = $order->shipping_policy_applied_snapshot;
        $this->assertNotEmpty($snapshot);
        $this->assertEquals($productOption->id, $snapshot[0]['product_option_id']);
        $this->assertEquals(1, $snapshot[0]['policy']['policy_id']);
    }

    public function test_create_from_temp_order_saves_order_meta_with_calculation_input(): void
    {
        $user = User::factory()->create();

        $calculationInput = [
            'promotions' => [
                'item_coupons' => [1 => [101]],
                'order_coupon_issue_id' => null,
                'shipping_coupon_issue_id' => null,
            ],
            'use_points' => 500,
            'shipping_address' => null,
        ];

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withCalculationInput($calculationInput)
            ->withCalculationResult([
                'summary' => ['final_amount' => 103000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        $this->mockCalculationService($this->makeCalculationResult(103000));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            103000
        );

        // order_meta에 calculation_input이 포함되어야 함
        $meta = $order->order_meta;
        $this->assertArrayHasKey('temp_order_id', $meta);
        $this->assertArrayHasKey('calculation_input', $meta);
        $this->assertEquals(500, $meta['calculation_input']['use_points']);
    }

    public function test_create_order_shippings_uses_correct_dto_properties(): void
    {
        $user = User::factory()->create();

        // 실제 상품/옵션 생성
        $product = \Modules\Sirsoft\Ecommerce\Models\Product::factory()->create();
        $productOption = \Modules\Sirsoft\Ecommerce\Models\ProductOption::factory()->create([
            'product_id' => $product->id,
        ]);

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([
                [
                    'cart_id' => 1,
                    'product_id' => $product->id,
                    'product_option_id' => $productOption->id,
                    'quantity' => 2,
                ],
            ])
            ->withCalculationResult([
                'summary' => ['final_amount' => 55000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        // 배송정책이 설정된 아이템
        $shippingPolicy = new AppliedShippingPolicy(
            policyId: 5,
            policyName: '도서산간 배송',
            chargePolicy: 'paid',
            shippingAmount: 3000,
            extraShippingAmount: 2000,
            totalShippingAmount: 5000,
            shippingDiscountAmount: 1000,
            policySnapshot: ['id' => 5, 'name' => '도서산간 배송'],
        );

        $item = new ItemCalculation(
            productId: $product->id,
            productOptionId: $productOption->id,
            quantity: 2,
            unitPrice: 25000,
            subtotal: 50000,
            finalAmount: 50000,
            appliedShippingPolicy: $shippingPolicy,
        );

        $this->mockCalculationService($this->makeCalculationResult(55000, [
            'subtotal' => 50000,
            'total_shipping' => 5000,
            'items' => [$item],
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            55000
        );

        // 배송 정보가 올바른 DTO 속성값으로 저장되었는지 확인
        $shipping = $order->shippings()->first();
        $this->assertNotNull($shipping);
        $this->assertEquals(3000, $shipping->base_shipping_amount); // shippingAmount (기본 배송비)
        $this->assertEquals(2000, $shipping->extra_shipping_amount); // extraShippingAmount (추가 배송비)
        $this->assertEquals(5000, $shipping->total_shipping_amount); // totalShippingAmount (총 배송비)
        $this->assertEquals(1000, $shipping->shipping_discount_amount); // shippingDiscountAmount (배송비 할인)
        $this->assertTrue((bool) $shipping->is_remote_area); // extraShippingAmount > 0
    }

    public function test_create_order_shippings_saves_delivery_policy_snapshot(): void
    {
        $user = User::factory()->create();

        $product = \Modules\Sirsoft\Ecommerce\Models\Product::factory()->create();
        $productOption = \Modules\Sirsoft\Ecommerce\Models\ProductOption::factory()->create([
            'product_id' => $product->id,
        ]);

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([
                [
                    'cart_id' => 1,
                    'product_id' => $product->id,
                    'product_option_id' => $productOption->id,
                    'quantity' => 1,
                ],
            ])
            ->withCalculationResult([
                'summary' => ['final_amount' => 33000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        $policySnapshotData = [
            'id' => 3,
            'name' => '기본 배송정책',
            'base_fee' => 3000,
            'free_shipping_threshold' => 50000,
        ];

        $shippingPolicy = new AppliedShippingPolicy(
            policyId: 3,
            policyName: '기본 배송정책',
            chargePolicy: 'paid',
            shippingAmount: 3000,
            totalShippingAmount: 3000,
            policySnapshot: $policySnapshotData,
        );

        $item = new ItemCalculation(
            productId: $product->id,
            productOptionId: $productOption->id,
            quantity: 1,
            unitPrice: 30000,
            subtotal: 30000,
            finalAmount: 30000,
            appliedShippingPolicy: $shippingPolicy,
        );

        $this->mockCalculationService($this->makeCalculationResult(33000, [
            'subtotal' => 30000,
            'total_shipping' => 3000,
            'items' => [$item],
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            33000
        );

        // delivery_policy_snapshot이 저장되었는지 확인
        $shipping = $order->shippings()->first();
        $this->assertNotNull($shipping);
        $this->assertNotNull($shipping->delivery_policy_snapshot);
        $this->assertEquals(3, $shipping->delivery_policy_snapshot['id']);
        $this->assertEquals('기본 배송정책', $shipping->delivery_policy_snapshot['name']);
    }

    public function test_create_from_temp_order_calls_coupon_use_hook(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);

        // 쿠폰이 적용된 계산 결과
        $coupon = new CouponApplication(
            couponId: 1,
            couponIssueId: 201,
            name: '테스트 쿠폰',
            targetType: 'product_amount',
            discountType: 'fixed',
            discountValue: 5000,
            totalDiscount: 5000,
        );
        $productPromotions = new AppliedPromotions(coupons: [$coupon]);
        $promotions = new PromotionsSummary(productPromotions: $productPromotions);

        $this->mockCalculationService($this->makeCalculationResult(103000, [
            'promotions' => $promotions,
        ]));

        // 훅 호출 감지
        $hookCalled = false;
        $capturedCouponIds = [];
        HookManager::addAction('sirsoft-ecommerce.coupon.use', function ($couponIds, $order) use (&$hookCalled, &$capturedCouponIds) {
            $hookCalled = true;
            $capturedCouponIds = $couponIds;
        });

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            103000
        );

        $this->assertTrue($hookCalled, '쿠폰 사용 훅이 호출되어야 합니다');
        $this->assertContains(201, $capturedCouponIds);
    }

    public function test_create_from_temp_order_calls_mileage_use_hook(): void
    {
        $user = User::factory()->create();

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withUsePoints(1000)
            ->withCalculationResult([
                'summary' => ['final_amount' => 102000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        $this->mockCalculationService($this->makeCalculationResult(102000, [
            'points_used' => 1000,
        ]));

        // 훅 호출 감지
        $hookCalled = false;
        $capturedPoints = 0;
        HookManager::addAction('sirsoft-ecommerce.mileage.use', function ($points, $order) use (&$hookCalled, &$capturedPoints) {
            $hookCalled = true;
            $capturedPoints = $points;
        });

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            102000
        );

        $this->assertTrue($hookCalled, '마일리지 사용 훅이 호출되어야 합니다');
        $this->assertEquals(1000, $capturedPoints);
    }

    public function test_create_from_temp_order_recalculates_from_stored_input(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);

        // OrderCalculationService::calculate()가 호출되는지 확인
        $mock = $this->createMock(OrderCalculationService::class);
        $mock->expects($this->once()) // 정확히 1회 호출
            ->method('calculate')
            ->willReturn($this->makeCalculationResult(103000));

        $this->app->instance(OrderCalculationService::class, $mock);
        $this->service = app(OrderProcessingService::class);

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            103000
        );
    }

    public function test_create_from_temp_order_blocks_order_on_amount_drift(): void
    {
        $user = User::factory()->create();

        // TempOrder에는 103000으로 저장
        $tempOrder = $this->createTestTempOrder($user, 103000);

        // 재계산 결과는 105000 (금액 변동)
        $this->mockCalculationService($this->makeCalculationResult(105000));

        $this->expectException(OrderAmountChangedException::class);

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            103000
        );
    }

    // ===== validatePaymentAmount 테스트 =====

    /**
     * 테스트용 주문 + 결제 레코드를 생성합니다.
     *
     * @param int $subtotal 소계
     * @param int $shipping 배송비
     * @param int $discount 할인액
     * @return Order
     */
    protected function createOrderWithPayment(
        int $subtotal = 50000,
        int $shipping = 3000,
        int $discount = 0,
    ): Order {
        $user = User::factory()->create();
        $totalAmount = $subtotal - $discount + $shipping;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => $subtotal,
            'total_discount_amount' => $discount,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => $discount,
            'base_shipping_amount' => $shipping,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => $shipping,
            'total_amount' => $totalAmount,
            'total_due_amount' => $totalAmount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'tosspayments',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'card_approval_number' => null,
        ]);

        return $order;
    }

    public function test_validate_payment_amount_passes_when_amounts_match(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        // 리플렉션으로 protected 메서드 테스트
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePaymentAmount');
        $method->setAccessible(true);

        // 53000 = 50000 + 3000 - 0 → total_amount와 일치해야 함
        $method->invoke($this->service, $order, 53000);

        // 예외가 발생하지 않으면 성공
        $this->assertTrue(true);
    }

    public function test_validate_payment_amount_throws_on_component_mismatch(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        // DB에서 total_amount를 직접 변조 (컴포넌트 합산과 불일치)
        Order::withoutEvents(function () use ($order) {
            $order->update(['total_amount' => 99999]);
        });
        $order->refresh();

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePaymentAmount');
        $method->setAccessible(true);

        $this->expectException(PaymentAmountMismatchException::class);
        $method->invoke($this->service, $order, 99999);
    }

    public function test_validate_payment_amount_throws_on_pg_amount_mismatch(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePaymentAmount');
        $method->setAccessible(true);

        // PG에서 다른 금액이 전달된 경우
        $this->expectException(PaymentAmountMismatchException::class);
        $method->invoke($this->service, $order, 99999);
    }

    // ===== completePayment 확장 테스트 =====

    public function test_complete_payment_with_pg_amount_validates_and_updates(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        $result = $this->service->completePayment($order, [
            'transaction_id' => 'pk_test_123',
            'card_approval_number' => '87654321',
            'card_number_masked' => '5432-****-****-1234',
            'card_name' => '삼성카드',
            'card_installment_months' => 3,
            'is_interest_free' => true,
            'receipt_url' => 'https://receipt.example.com',
            'payment_device' => 'pc',
        ], 53000);

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);

        // 결제 정보 확인
        $payment = $result->payment;
        $payment->refresh();
        $this->assertEquals('pk_test_123', $payment->transaction_id);
        $this->assertEquals('87654321', $payment->card_approval_number);
        $this->assertEquals('5432-****-****-1234', $payment->card_number_masked);
        $this->assertEquals('삼성카드', $payment->card_name);
        $this->assertEquals(3, $payment->card_installment_months);
        $this->assertTrue((bool) $payment->is_interest_free);
        $this->assertEquals('https://receipt.example.com', $payment->receipt_url);
        $this->assertEquals('pc', $payment->payment_device);
    }

    public function test_complete_payment_without_pg_amount_skips_validation(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        // $pgAmount = null → 금액 검증 생략 (무통장입금 등)
        $result = $this->service->completePayment($order, [
            'transaction_id' => 'manual_confirm_123',
        ]);

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
    }

    public function test_complete_payment_calls_hooks(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        $beforeCalled = false;
        $afterCalled = false;

        HookManager::addAction('sirsoft-ecommerce.order.before_payment_complete', function () use (&$beforeCalled) {
            $beforeCalled = true;
        });
        HookManager::addAction('sirsoft-ecommerce.order.after_payment_complete', function () use (&$afterCalled) {
            $afterCalled = true;
        });

        $this->service->completePayment($order, [], 53000);

        $this->assertTrue($beforeCalled, 'before_payment_complete 훅이 호출되어야 합니다');
        $this->assertTrue($afterCalled, 'after_payment_complete 훅이 호출되어야 합니다');
    }

    // ===== failPayment 테스트 =====

    public function test_fail_payment_cancels_pending_order(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);

        $result = $this->service->failPayment($order, 'PAY_PROCESS_CANCELED', '결제가 취소되었습니다.');

        $this->assertEquals(OrderStatusEnum::CANCELLED, $result->order_status);

        $meta = $result->order_meta;
        $this->assertEquals('PAY_PROCESS_CANCELED', $meta['payment_failure_code']);
        $this->assertEquals('결제가 취소되었습니다.', $meta['payment_failure_message']);
        $this->assertArrayHasKey('payment_failed_at', $meta);
    }

    public function test_fail_payment_ignores_non_pending_order(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        // 이미 결제 완료된 상태로 변경
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $order->refresh();

        $result = $this->service->failPayment($order, 'SOME_ERROR', '에러');

        // 상태 변경 없이 원래 주문이 반환되어야 함
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
    }

    public function test_fail_payment_calls_payment_failed_hook(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        $hookCalled = false;
        $capturedCode = null;

        HookManager::addAction('sirsoft-ecommerce.order.payment_failed', function ($o, $code, $message) use (&$hookCalled, &$capturedCode) {
            $hookCalled = true;
            $capturedCode = $code;
        });

        $this->service->failPayment($order, 'USER_CANCEL', '사용자 취소');

        $this->assertTrue($hookCalled, 'payment_failed 훅이 호출되어야 합니다');
        $this->assertEquals('USER_CANCEL', $capturedCode);
    }

    // ===== findByOrderNumber 테스트 =====

    // ===== recordPaymentCancellation 테스트 =====

    public function test_record_payment_cancellation_updates_payment_status(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        $result = $this->service->recordPaymentCancellation($order);

        $payment = $result->payment;
        $payment->refresh();

        $this->assertEquals(PaymentStatusEnum::CANCELLED->value, $payment->payment_status->value);
        $this->assertNotNull($payment->cancelled_at);
    }

    public function test_record_payment_cancellation_appends_cancel_history(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        $result = $this->service->recordPaymentCancellation($order, 'USER_CANCEL', '사용자가 결제를 취소했습니다.');

        $payment = $result->payment;
        $payment->refresh();

        $cancelHistory = $payment->cancel_history;
        $this->assertIsArray($cancelHistory);
        $this->assertCount(1, $cancelHistory);
        $this->assertEquals('USER_CANCEL', $cancelHistory[0]['cancel_code']);
        $this->assertEquals('사용자가 결제를 취소했습니다.', $cancelHistory[0]['cancel_message']);
        $this->assertArrayHasKey('cancelled_at', $cancelHistory[0]);
    }

    public function test_record_payment_cancellation_appends_to_existing_history(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        // 1차 취소
        $this->service->recordPaymentCancellation($order);
        // 2차 취소
        $result = $this->service->recordPaymentCancellation($order->fresh());

        $payment = $result->payment;
        $payment->refresh();

        $cancelHistory = $payment->cancel_history;
        $this->assertCount(2, $cancelHistory);
    }

    public function test_record_payment_cancellation_returns_order_when_no_payment(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        // payment 없이 호출 → 오류 없이 반환
        $result = $this->service->recordPaymentCancellation($order);
        $this->assertEquals($order->id, $result->id);
    }

    // ===== completePayment 임시주문 정리 테스트 =====

    public function test_complete_payment_deletes_temp_order(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        // 임시주문 생성
        $user = $order->user;
        $tempOrder = \Modules\Sirsoft\Ecommerce\Models\TempOrder::create([
            'user_id' => $user->id,
            'cart_key' => 'test-cart-key',
            'items' => [],
            'calculation_result' => ['summary' => ['final_amount' => 53000]],
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->assertDatabaseHas('ecommerce_temp_orders', ['id' => $tempOrder->id]);

        $this->service->completePayment($order, [
            'transaction_id' => 'test_tx_123',
        ], 53000);

        // completePayment 후 임시주문 삭제 확인
        $this->assertDatabaseMissing('ecommerce_temp_orders', ['id' => $tempOrder->id]);
    }

    // ===== findByOrderNumber 테스트 =====

    public function test_find_by_order_number_returns_order(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        $found = $this->service->findByOrderNumber($order->order_number);

        $this->assertNotNull($found);
        $this->assertEquals($order->id, $found->id);
    }

    public function test_find_by_order_number_returns_null_for_non_existent(): void
    {
        $found = $this->service->findByOrderNumber('NON_EXISTENT_ORDER_NUMBER');

        $this->assertNull($found);
    }

    // ===== 재고 차감 타이밍 (stock_deduction_timing) 테스트 =====

    /**
     * StockService와 EcommerceSettingsService를 mock하여 재고 차감 타이밍을 제어합니다.
     *
     * @param string $timing 재고 차감 타이밍 ('order_placed', 'payment_complete', 'none')
     * @return \PHPUnit\Framework\MockObject\MockObject StockService mock
     */
    protected function mockStockAndSettingsForTiming(string $timing): \PHPUnit\Framework\MockObject\MockObject
    {
        $settingsMock = $this->createMock(EcommerceSettingsService::class);
        $settingsMock->method('getStockDeductionTiming')->willReturn($timing);
        $this->app->instance(EcommerceSettingsService::class, $settingsMock);

        $stockMock = $this->createMock(StockService::class);
        $this->app->instance(StockService::class, $stockMock);

        return $stockMock;
    }

    public function test_create_from_temp_order_deducts_stock_for_order_placed_timing(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);

        // StockService mock: deductStock이 정확히 1회 호출되어야 함
        $stockMock = $this->mockStockAndSettingsForTiming('order_placed');
        $stockMock->expects($this->once())->method('deductStock');

        $this->mockCalculationService($this->makeCalculationResult(103000));

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'dbank',
            103000
        );
    }

    public function test_create_from_temp_order_does_not_deduct_stock_for_payment_complete_timing(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);

        // StockService mock: deductStock이 호출되지 않아야 함
        $stockMock = $this->mockStockAndSettingsForTiming('payment_complete');
        $stockMock->expects($this->never())->method('deductStock');

        $this->mockCalculationService($this->makeCalculationResult(103000));

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            103000
        );
    }

    public function test_create_from_temp_order_does_not_deduct_stock_for_none_timing(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);

        // StockService mock: deductStock이 호출되지 않아야 함
        $stockMock = $this->mockStockAndSettingsForTiming('none');
        $stockMock->expects($this->never())->method('deductStock');

        $this->mockCalculationService($this->makeCalculationResult(103000));

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'dbank',
            103000
        );
    }

    public function test_complete_payment_deducts_stock_for_payment_complete_timing(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        // StockService mock: deductStock이 정확히 1회 호출되어야 함
        $stockMock = $this->mockStockAndSettingsForTiming('payment_complete');
        $stockMock->expects($this->once())->method('deductStock');
        $this->service = app(OrderProcessingService::class);

        $this->service->completePayment($order, [
            'transaction_id' => 'test_tx_stock',
        ], 53000);
    }

    public function test_complete_payment_does_not_deduct_stock_for_order_placed_timing(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        // StockService mock: deductStock이 호출되지 않아야 함 (이미 주문 시 차감됨)
        $stockMock = $this->mockStockAndSettingsForTiming('order_placed');
        $stockMock->expects($this->never())->method('deductStock');
        $this->service = app(OrderProcessingService::class);

        $this->service->completePayment($order, [
            'transaction_id' => 'test_tx_no_stock',
        ], 53000);
    }

    // ===== completePayment 배송지 자동 저장 테스트 =====

    public function test_complete_payment_saves_shipping_address_when_meta_flag_set(): void
    {
        $order = $this->createOrderWithPayment();
        $order->update([
            'order_meta' => [
                'save_shipping_address' => true,
                'shipping_info_for_save' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구 테헤란로 123',
                    'address_detail' => '101동 1001호',
                ],
            ],
        ]);

        $this->service->completePayment($order, [
            'transaction_id' => 'test_tx_addr_save',
        ], 53000);

        $this->assertDatabaseHas('ecommerce_user_addresses', [
            'user_id' => $order->user_id,
            'recipient_name' => '김철수',
            'zipcode' => '12345',
        ]);

        // order_meta에서 플래그 제거 확인
        $order->refresh();
        $this->assertFalse($order->order_meta['save_shipping_address'] ?? false);
        $this->assertArrayNotHasKey('shipping_info_for_save', $order->order_meta ?? []);
    }

    public function test_complete_payment_does_not_save_address_when_no_meta_flag(): void
    {
        $order = $this->createOrderWithPayment();

        $this->service->completePayment($order, [
            'transaction_id' => 'test_tx_no_flag',
        ], 53000);

        $this->assertDatabaseMissing('ecommerce_user_addresses', [
            'user_id' => $order->user_id,
        ]);
    }

    public function test_complete_payment_does_not_save_address_for_guest_order(): void
    {
        $order = $this->createOrderWithPayment();
        // user_id를 null로 변경 (비회원 주문 시뮬레이션)
        Order::withoutEvents(function () use ($order) {
            $order->update([
                'user_id' => null,
                'order_meta' => [
                    'save_shipping_address' => true,
                    'shipping_info_for_save' => [
                        'recipient_name' => '비회원',
                        'recipient_phone' => '010-0000-0000',
                        'zipcode' => '99999',
                        'address' => '서울시',
                    ],
                ],
            ]);
        });
        $order->refresh();

        $this->service->completePayment($order, [
            'transaction_id' => 'test_tx_guest',
        ], 53000);

        $this->assertEquals(0, UserAddress::count());
    }

    public function test_complete_payment_succeeds_even_if_address_save_fails(): void
    {
        $order = $this->createOrderWithPayment();
        $order->update([
            'order_meta' => [
                'save_shipping_address' => true,
                'shipping_info_for_save' => [], // 빈 데이터 (저장 시 예외 가능)
            ],
        ]);

        // 결제 완료는 정상 처리되어야 함 (예외 미전파)
        $result = $this->service->completePayment($order, [
            'transaction_id' => 'test_tx_addr_fail',
        ], 53000);

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
    }

    // ===== 장바구니 처리 테스트 =====

    /**
     * 테스트용 Cart 레코드를 생성합니다.
     *
     * @param int $userId 사용자 ID
     * @param int $quantity 수량
     * @param Product|null $product 상품 (null이면 새로 생성)
     * @param ProductOption|null $option 옵션 (null이면 새로 생성)
     * @return Cart
     */
    protected function createTestCart(
        int $userId,
        int $quantity = 1,
        ?Product $product = null,
        ?ProductOption $option = null
    ): Cart {
        $product = $product ?? Product::factory()->create();
        $option = $option ?? ProductOption::factory()->create(['product_id' => $product->id]);

        return Cart::create([
            'user_id' => $userId,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => $quantity,
        ]);
    }

    public function test_build_order_meta에_cart_items_포함(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $option1 = ProductOption::factory()->create(['product_id' => $product->id]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id]);

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([
                ['cart_id' => 10, 'product_id' => $product->id, 'product_option_id' => $option1->id, 'quantity' => 3],
                ['cart_id' => 20, 'product_id' => $product->id, 'product_option_id' => $option2->id, 'quantity' => 1],
            ])
            ->withCalculationResult([
                'summary' => ['final_amount' => 50000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildOrderMeta');
        $method->setAccessible(true);

        $meta = $method->invoke($this->service, $tempOrder);

        $this->assertArrayHasKey('cart_items', $meta);
        $this->assertCount(2, $meta['cart_items']);
        $this->assertEquals(10, $meta['cart_items'][0]['cart_id']);
        $this->assertEquals(3, $meta['cart_items'][0]['quantity']);
        $this->assertEquals(20, $meta['cart_items'][1]['cart_id']);
        $this->assertEquals(1, $meta['cart_items'][1]['quantity']);
    }

    public function test_build_order_meta_cart_id_없는_아이템은_제외(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([
                ['product_id' => $product->id, 'product_option_id' => $option->id, 'quantity' => 1],
            ])
            ->withCalculationResult([
                'summary' => ['final_amount' => 50000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildOrderMeta');
        $method->setAccessible(true);

        $meta = $method->invoke($this->service, $tempOrder);

        $this->assertArrayHasKey('cart_items', $meta);
        $this->assertEmpty($meta['cart_items']);
    }

    public function test_clear_ordered_cart_items_장바구니_수량과_주문_수량_같으면_삭제(): void
    {
        $user = User::factory()->create();
        $cart = $this->createTestCart($user->id, 3);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_meta' => ['cart_items' => [['cart_id' => $cart->id, 'quantity' => 3]]],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('clearOrderedCartItems');
        $method->setAccessible(true);
        $method->invoke($this->service, $order);

        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }

    public function test_clear_ordered_cart_items_장바구니_수량이_주문보다_크면_차감(): void
    {
        $user = User::factory()->create();
        $cart = $this->createTestCart($user->id, 5);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_meta' => ['cart_items' => [['cart_id' => $cart->id, 'quantity' => 3]]],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('clearOrderedCartItems');
        $method->setAccessible(true);
        $method->invoke($this->service, $order);

        $this->assertDatabaseHas('ecommerce_carts', ['id' => $cart->id, 'quantity' => 2]);
    }

    public function test_clear_ordered_cart_items_장바구니_수량이_주문보다_작으면_삭제(): void
    {
        $user = User::factory()->create();
        $cart = $this->createTestCart($user->id, 2);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_meta' => ['cart_items' => [['cart_id' => $cart->id, 'quantity' => 3]]],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('clearOrderedCartItems');
        $method->setAccessible(true);
        $method->invoke($this->service, $order);

        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }

    public function test_clear_ordered_cart_items_cart_items_비어있으면_스킵(): void
    {
        $order = Order::factory()->create([
            'order_meta' => ['cart_items' => []],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('clearOrderedCartItems');
        $method->setAccessible(true);
        $method->invoke($this->service, $order);

        // 예외 없이 정상 완료
        $this->assertTrue(true);
    }

    public function test_clear_ordered_cart_items_이미_삭제된_장바구니는_무시(): void
    {
        $order = Order::factory()->create([
            'order_meta' => ['cart_items' => [['cart_id' => 999999, 'quantity' => 1]]],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('clearOrderedCartItems');
        $method->setAccessible(true);
        $method->invoke($this->service, $order);

        // 존재하지 않는 cart_id여도 예외 없이 정상 완료 (멱등성)
        $this->assertTrue(true);
    }

    public function test_clear_ordered_cart_items_복수_아이템_혼합_처리(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $option1 = ProductOption::factory()->create(['product_id' => $product->id]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id]);
        $option3 = ProductOption::factory()->create(['product_id' => $product->id]);

        $cart1 = $this->createTestCart($user->id, 5, $product, $option1);
        $cart2 = $this->createTestCart($user->id, 3, $product, $option2);
        $cart3 = $this->createTestCart($user->id, 1, $product, $option3);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_meta' => ['cart_items' => [
                ['cart_id' => $cart1->id, 'quantity' => 2],
                ['cart_id' => $cart2->id, 'quantity' => 3],
                ['cart_id' => $cart3->id, 'quantity' => 5],
            ]],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('clearOrderedCartItems');
        $method->setAccessible(true);
        $method->invoke($this->service, $order);

        // cart1: 수량 5, 주문 2 → 잔여 3
        $this->assertDatabaseHas('ecommerce_carts', ['id' => $cart1->id, 'quantity' => 3]);
        // cart2: 수량 3, 주문 3 → 삭제
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart2->id]);
        // cart3: 수량 1, 주문 5 → 삭제
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart3->id]);
    }

    public function test_order_placed_타이밍에서_장바구니_아이템_삭제(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);

        $cart = $this->createTestCart($user->id, 2, $product, $option);

        // order_placed 타이밍 설정
        $settingsMock = $this->createMock(EcommerceSettingsService::class);
        $settingsMock->method('getStockDeductionTiming')->willReturn('order_placed');
        $this->app->instance(EcommerceSettingsService::class, $settingsMock);

        $stockMock = $this->createMock(StockService::class);
        $stockMock->expects($this->once())->method('deductStock');
        $this->app->instance(StockService::class, $stockMock);

        $this->service = app(OrderProcessingService::class);
        $this->mockCalculationService($this->makeCalculationResult(103000));

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([
                ['cart_id' => $cart->id, 'product_id' => $product->id, 'product_option_id' => $option->id, 'quantity' => 2],
            ])
            ->withCalculationResult([
                'summary' => ['final_amount' => 103000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'test@example.com'],
            ['recipient_name' => '홍길동', 'recipient_phone' => '010-1234-5678', 'zipcode' => '12345', 'address' => '서울시 강남구', 'address_detail' => '123동'],
            'dbank',
            103000,
            null,
            '홍길동'
        );

        // order_placed 타이밍 → 재고 차감 + 장바구니 삭제
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }

    public function test_payment_complete_타이밍에서_createFromTempOrder_장바구니_미삭제(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);

        $cart = $this->createTestCart($user->id, 2, $product, $option);

        // payment_complete 타이밍 설정
        $settingsMock = $this->createMock(EcommerceSettingsService::class);
        $settingsMock->method('getStockDeductionTiming')->willReturn('payment_complete');
        $this->app->instance(EcommerceSettingsService::class, $settingsMock);

        $this->service = app(OrderProcessingService::class);
        $this->mockCalculationService($this->makeCalculationResult(103000));

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([
                ['cart_id' => $cart->id, 'product_id' => $product->id, 'product_option_id' => $option->id, 'quantity' => 2],
            ])
            ->withCalculationResult([
                'summary' => ['final_amount' => 103000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'test@example.com'],
            ['recipient_name' => '홍길동', 'recipient_phone' => '010-1234-5678', 'zipcode' => '12345', 'address' => '서울시 강남구', 'address_detail' => '123동'],
            'card',
            103000
        );

        // payment_complete 타이밍 → createFromTempOrder에서는 장바구니 미삭제
        $this->assertDatabaseHas('ecommerce_carts', ['id' => $cart->id]);
        // order_meta에 cart_items 저장 확인 (나중에 completePayment에서 사용)
        $this->assertNotEmpty($order->order_meta['cart_items']);
    }

    public function test_complete_payment_시_장바구니_아이템_삭제(): void
    {
        $user = User::factory()->create();
        $cart = $this->createTestCart($user->id, 2);

        $order = $this->createOrderWithPayment();
        $order->update([
            'order_meta' => array_merge($order->order_meta ?? [], [
                'cart_items' => [['cart_id' => $cart->id, 'quantity' => 2]],
            ]),
        ]);

        $result = $this->service->completePayment($order, [
            'transaction_id' => 'test_tx_cart_clear',
        ], $order->total_amount);

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }
}
