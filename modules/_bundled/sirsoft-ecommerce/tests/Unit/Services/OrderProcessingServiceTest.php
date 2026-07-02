<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\App;
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
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderAmountChangedException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Exceptions\UnsupportedPaymentCurrencyException;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * 주문 생성 서비스 테스트
 */
class OrderProcessingServiceTest extends ModuleTestCase
{
    protected OrderProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupMileageSettingsFile();
        $this->service = app(OrderProcessingService::class);
    }

    protected function tearDown(): void
    {
        $this->cleanupMileageSettingsFile();
        parent::tearDown();
    }

    /**
     * 테스트 간 격리를 위해 testing 격리 경로의 마일리지 설정 파일을 제거합니다.
     * (setSetting 으로 deduction_timing 등을 변경한 테스트가 다음 테스트에 누설되지 않도록)
     */
    private function cleanupMileageSettingsFile(): void
    {
        foreach (['mileage.json', 'order_settings.json'] as $name) {
            $file = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/'.$name);
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * 카드(card) 결제수단의 마일리지 차감 시점을 설정합니다. (마일리지/MP06)
     *
     * 차감 시점은 결제수단별(order_settings.payment_methods.*.mileage_deduction_timing)로 관리되므로,
     * 단일 키 대신 카드 결제수단 설정을 저장한다.
     *
     * @param  string  $timing  order_placed | payment_complete
     */
    private function setCardMileageTiming(string $timing): void
    {
        app(EcommerceSettingsService::class)->saveSettings([
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'card', 'sort_order' => 1, 'is_active' => true, 'min_order_amount' => 0, 'mileage_deduction_timing' => $timing],
                ],
            ],
        ]);
        app(EcommerceSettingsService::class)->clearCache();
    }

    // ===== 기존 테스트 (determineInitialStatus, isFirstOrder, generateOrderNumber) =====

    public function test_determine_initial_status_returns_pending_payment_for_vbank(): void
    {
        // Protected 메서드 테스트를 위한 리플렉션
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineInitialStatus');
        $method->setAccessible(true);

        // 결제할 금액이 있는 일반 주문(finalAmount > 0)
        $status = $method->invoke($this->service, 'vbank', 50000);

        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $status);
    }

    public function test_determine_initial_status_returns_pending_order_for_card(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineInitialStatus');
        $method->setAccessible(true);

        $status = $method->invoke($this->service, 'card', 50000);

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $status);
    }

    public function test_determine_initial_status_returns_pending_order_for_pg(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineInitialStatus');
        $method->setAccessible(true);

        $status = $method->invoke($this->service, 'pg', 50000);

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

    public function test_is_first_order_returns_false_for_guest(): void
    {
        // 비회원 주문(user_id = null)은 회원 식별이 불가능해 첫 주문 혜택 대상이 아니다.
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isFirstOrder');
        $method->setAccessible(true);

        $isFirstOrder = $method->invoke($this->service, null);

        $this->assertFalse($isFirstOrder);
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
        $promotions = $overrides['promotions'] ?? new PromotionsSummary;

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
     * @return TempOrder
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

    /**
     * 주문 생성 시 현재 화면 언어가 배송지 orderer_locale 에 저장된다.
     *
     * 비회원 알림(이메일)이 주문 화면 언어로 발송되도록 하는 근거 데이터.
     */
    public function test_create_from_temp_order_stores_current_locale_on_address(): void
    {
        App::setLocale('en');

        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);
        $this->mockCalculationService($this->makeCalculationResult(103000));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'test@example.com'],
            ['recipient_name' => '홍길동', 'recipient_phone' => '010-1234-5678', 'zipcode' => '12345', 'address' => '서울시 강남구 테헤란로', 'address_detail' => '123동 456호'],
            'card',
            103000,
            null
        );

        $this->assertSame('en', $order->fresh()->shippingAddress?->orderer_locale);
    }

    /**
     * 주문 생성 시 결제 레코드에 결제요청일시·구매자 정보·부가세·결제명·디바이스가
     * 모두 채워지는지 검증합니다 (PG 콜백을 거치지 않는 수단에서도 NULL 로 남지 않아야 함).
     *
     * 회귀 배경: createOrderPayment 가 결제요청일시·구매자정보·결제명·부가세·디바이스 컬럼을
     * 설정하지 않아, 무통장 등 PG 콜백 없는 주문에서 영구 NULL 로 남던 결함.
     */
    public function test_create_from_temp_order_populates_payment_record_fields(): void
    {
        $user = User::factory()->create();

        // 실제 상품/옵션 생성 (FK 제약조건 충족 + payment_name 산출용 상품명)
        $product = Product::factory()->create();
        $productOption = ProductOption::factory()->create(['product_id' => $product->id]);

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([
                ['cart_id' => 1, 'product_id' => $product->id, 'product_option_id' => $productOption->id, 'quantity' => 1],
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

        $item = new ItemCalculation(
            productId: $product->id,
            productOptionId: $productOption->id,
            quantity: 1,
            unitPrice: 100000,
            subtotal: 100000,
            finalAmount: 100000,
            productName: '테스트 상품 A',
        );

        $this->mockCalculationService($this->makeCalculationResult(103000, [
            'taxable_amount' => 93636,
            'items' => [$item],
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'buyer@example.com'],
            ['recipient_name' => '홍길동', 'recipient_phone' => '010-1234-5678', 'zipcode' => '12345', 'address' => '서울시 강남구 테헤란로', 'address_detail' => '123동 456호'],
            'dbank',
            103000,
            null,
            '홍길동',
            ['bank_code' => '004', 'bank_name' => '국민은행', 'account_number' => '123-456', 'account_holder' => '판매자']
        );

        $payment = $order->payment;
        $this->assertNotNull($payment, '결제 레코드가 생성되어야 함');

        // 결제요청일시 — 전 결제수단 공통, 생성 시점에 기록
        $this->assertNotNull($payment->payment_started_at, 'payment_started_at 은 생성 시점에 기록되어야 함');

        // 구매자 정보 — 주문자 정보가 결제 레코드에 기록
        $this->assertEquals('홍길동', $payment->buyer_name);
        $this->assertEquals('buyer@example.com', $payment->buyer_email);
        $this->assertEquals('010-1234-5678', $payment->buyer_phone);

        // 부가세 — 과세표준(93636)의 1/11
        $this->assertEquals((int) round(93636 / 11), (int) $payment->vat_amount);

        // 결제명 — 상품명 (단일 항목이므로 상품명 그대로)
        $this->assertEquals('테스트 상품 A', $payment->payment_name);

        // 결제 디바이스 — User-Agent 기반 (테스트 환경은 비어 'pc')
        $this->assertContains($payment->payment_device, ['pc', 'mobile']);
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
        $product = Product::factory()->create();
        $productOption = ProductOption::factory()->create([
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

        // 마일리지 사용 훅이 실제 FIFO 차감을 수행하므로 사용액(500) 이상 잔액 시드
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => 'purchase_earn',
            'amount' => 2000,
            'remaining_amount' => 2000,
            'balance_after' => 2000,
        ]);

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
        $product = Product::factory()->create();
        $productOption = ProductOption::factory()->create([
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

        $product = Product::factory()->create();
        $productOption = ProductOption::factory()->create([
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

        // 카드 결제수단의 마일리지 차감 시점을 order_placed 로 설정 — 주문 생성 시 mileage.use 훅 발화
        // (기본 payment_complete 타이밍은 결제완료 시점에 발화하며, 별도 테스트에서 검증)
        $this->setCardMileageTiming('order_placed');

        // 마일리지 사용 훅이 실제 FIFO 차감을 수행하므로 사용액(1000) 이상 잔액 시드
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => 'purchase_earn',
            'amount' => 2000,
            'remaining_amount' => 2000,
            'balance_after' => 2000,
        ]);

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

    /**
     * 마일리지 사용 시 결제예정금액(total_due_amount)은 마일리지 차감 후 금액(finalAmount)이어야 한다.
     *
     * 회귀: 주문 생성 시 total_due_amount 에 차감 전 paymentAmount 가 저장되어
     * PG(KG 이니시스 등)·무통장 입금액이 마일리지 차감 전 금액으로 잡히던 결함.
     * 결제예정금액(total_due_amount)·주문 결제금액(total_amount)은 모두 차감 후 실결제액(finalAmount)이며,
     * 차감 전 paymentAmount 는 어느 컬럼에도 PG 결제 기준값으로 저장되지 않는다.
     */
    public function test_create_from_temp_order_sets_total_due_amount_to_post_mileage_final_amount(): void
    {
        $user = User::factory()->create();

        // 마일리지 사용 훅의 FIFO 차감을 위한 잔액 시드 (2,000 사용)
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => 'purchase_earn',
            'amount' => 2000,
            'remaining_amount' => 2000,
            'balance_after' => 2000,
        ]);

        // 차감 전 결제금액 55,000 / 마일리지 2,000 사용 → 차감 후 53,000
        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withUsePoints(2000)
            ->withCalculationResult([
                'summary' => ['final_amount' => 53000],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        $this->mockCalculationService($this->makeCalculationResult(53000, [
            'subtotal' => 55000,
            'total_shipping' => 0,
            'taxable_amount' => 50000,
            'points_used' => 2000,
            'payment_amount' => 55000,
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            53000
        );

        $this->assertEquals(53000, (int) $order->total_amount, '주문 결제금액(total_amount)은 마일리지 차감 후 실결제액이어야 합니다.');
        $this->assertEquals(2000, (int) $order->total_points_used_amount, '사용 마일리지가 기록되어야 합니다.');
        $this->assertEquals(
            53000,
            (int) $order->total_due_amount,
            '결제예정금액(total_due_amount)은 마일리지 차감 후 실결제액이어야 합니다 — PG/무통장 입금액의 SSoT.'
        );
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
     * @param  int  $subtotal  소계
     * @param  int  $shipping  배송비
     * @param  int  $discount  할인액
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

        // DB에서 결제예정금액(total_due_amount)을 직접 변조 (컴포넌트 재합산과 불일치)
        // 검증 1단계 기준 컬럼 = total_due_amount.
        Order::withoutEvents(function () use ($order) {
            $order->update(['total_due_amount' => 99999]);
        });
        $order->refresh();

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePaymentAmount');
        $method->setAccessible(true);

        $this->expectException(PaymentAmountMismatchException::class);
        $method->invoke($this->service, $order, 99999);
    }

    /**
     * 마일리지 사용 주문: 검증 기준 = total_due_amount(차감 후). 차감 후 금액이면 통과, 차감 전 금액이면 실패.
     *
     * 회귀: PG 검증 기준 컬럼을 total_due_amount 로 통일. total_points_used_amount 가 0 이 아닐 때
     * 컴포넌트 재합산(마일리지 차감 포함) = total_due_amount 와 일치하고, 차감 전 금액(pgAmount)은 거부되어야 한다.
     */
    public function test_validate_payment_amount_uses_post_mileage_due_amount(): void
    {
        // 상품 55,000 + 배송 0 / 마일리지 2,000 사용 → 차감 후 53,000
        $order = $this->createOrderWithPayment(55000, 0, 0);
        Order::withoutEvents(function () use ($order) {
            $order->update([
                'total_code_discount_amount' => 0,
                'total_points_used_amount' => 2000,
                'total_amount' => 53000,
                'total_due_amount' => 53000,
            ]);
        });
        $order->refresh();

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePaymentAmount');
        $method->setAccessible(true);

        // 차감 후 53,000 으로 PG 콜백 → 통과
        $method->invoke($this->service, $order, 53000);
        $this->assertTrue(true);

        // 차감 전 55,000 으로 PG 콜백 → 2단계에서 거부
        $this->expectException(PaymentAmountMismatchException::class);
        $method->invoke($this->service, $order, 55000);
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

    /**
     * base 통화 ≠ 결제 통화 조합에서 PG 청구액(결제 통화 환산) 검증이 정합해야 한다.
     *
     * 회귀 배경: buildPgPaymentData 가 PG 청구액을 결제 통화로 환산(resolveOrderPaymentChargeAmount)
     * 하도록 바뀌었으나, validatePaymentAmount 2단계가 base(total_due_amount)를 직접 비교해
     * base≠order_currency(예: base JPY 500 → 결제 KRW 4,750) 에서 "결제금액 불일치"로 오차단됐다.
     * 검증 기준을 결제 통화 환산액으로 통일했으므로, 환산 청구액은 통과하고 base 금액은 거부되어야 한다.
     *
     * @dataProvider providePaymentCurrencyCombinations
     */
    public function test_validate_payment_amount_uses_payment_currency_charge(
        string $baseCurrency,
        string $orderCurrency,
        float $baseDueAmount,
        array $exchangeRates,
        int $expectedPgAmount,
    ): void {
        $order = $this->createOrderWithPayment(50000, 3000, 0);

        // base≠order 스냅샷 + base 통화 total_due_amount 강제 주입
        // (createOrderWithPayment 의 컴포넌트 검증 1단계를 통과시키려 컴포넌트도 base 기준으로 맞춘다)
        Order::withoutEvents(function () use ($order, $baseCurrency, $orderCurrency, $baseDueAmount, $exchangeRates) {
            $order->update([
                'currency' => $orderCurrency,
                'subtotal_amount' => $baseDueAmount,
                'total_product_coupon_discount_amount' => 0,
                'total_order_coupon_discount_amount' => 0,
                'total_code_discount_amount' => 0,
                'base_shipping_amount' => 0,
                'extra_shipping_amount' => 0,
                'shipping_discount_amount' => 0,
                'total_points_used_amount' => 0,
                'total_amount' => $baseDueAmount,
                'total_due_amount' => $baseDueAmount,
                'currency_snapshot' => [
                    'base_currency' => $baseCurrency,
                    'order_currency' => $orderCurrency,
                    'exchange_rates' => $exchangeRates,
                ],
            ]);
        });
        $order->refresh();

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePaymentAmount');
        $method->setAccessible(true);

        // 결제 통화 환산 청구액(PG 가 실제 청구하는 값)은 통과해야 한다.
        $method->invoke($this->service, $order, $expectedPgAmount);
        $this->assertTrue(true);

        // base 금액(환산 전)이 환산 청구액과 다르면, base 금액을 PG 금액으로 주면 거부되어야 한다.
        // (JPY→USD 처럼 base_unit/소수자리 보정으로 정수값이 우연히 같아지는 조합은 거부 단정 제외.)
        $baseInt = (int) round($baseDueAmount);
        if ($baseInt !== $expectedPgAmount) {
            $this->expectException(PaymentAmountMismatchException::class);
            $method->invoke($this->service, $order, $baseInt);
        }
    }

    /**
     * 검증 4조합: base/order 통화 전 경우의 수.
     *
     * @return array<string, array{0:string,1:string,2:float,3:array,4:int}>
     */
    public static function providePaymentCurrencyCombinations(): array
    {
        // 환율/단위 규칙(스냅샷): KRW(decimals 0, base_unit 1000), USD(2, 1), JPY(0, base_unit 100)
        $krw = ['rate' => '950', 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000];
        $usd = ['rate' => '1', 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1];
        $jpy = ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'round', 'decimal_places' => 0, 'base_unit' => 100];

        return [
            // 기본 KRW + 유저 KRW 결제 (base==order): 53,000원 그대로
            'base KRW / pay KRW' => [
                'KRW', 'KRW', 53000.0,
                ['KRW' => $krw, 'USD' => $usd, 'JPY' => $jpy],
                53000,
            ],
            // 기본 USD + 유저 KRW 결제: base $6 → (6/1)*950 = 5,700 → floor 단위1 → 5,700원
            'base USD / pay KRW' => [
                'USD', 'KRW', 6.0,
                ['USD' => ['rate' => '1', 'decimal_places' => 2, 'base_unit' => 1], 'KRW' => $krw],
                5700,
            ],
            // 기본 JPY + 유저 USD 결제: base ¥500 → (500/100)*1 = $5.00 → minor 500
            'base JPY / pay USD' => [
                'JPY', 'USD', 500.0,
                ['JPY' => $jpy, 'USD' => $usd],
                500,
            ],
            // 기본 JPY + 유저 JPY 결제 (base==order): ¥500 그대로
            'base JPY / pay JPY' => [
                'JPY', 'JPY', 500.0,
                ['JPY' => $jpy],
                500,
            ],
        ];
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

    // ===== completePayment 옵션 상태 동기화 회귀 테스트 (PG 결제완료 후 옵션이 주문대기에 갇히는 버그) =====

    public function test_complete_payment_syncs_option_status_to_payment_complete(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);
        $optionA = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        $optionB = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $this->service->completePayment($order, [], 53000);

        // 주문상품옵션도 결제완료로 동기화되어야 화면에 "주문대기"로 갇히지 않는다.
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $optionA->fresh()->option_status);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $optionB->fresh()->option_status);
    }

    public function test_complete_payment_does_not_resurrect_cancelled_option(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);
        $activeOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        $cancelledOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::CANCELLED,
        ]);

        $this->service->completePayment($order, [], 53000);

        // 활성 옵션만 결제완료로 전이, 취소 옵션은 보존되어야 한다.
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $activeOption->fresh()->option_status);
        $this->assertEquals(OrderStatusEnum::CANCELLED, $cancelledOption->fresh()->option_status);
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

    /**
     * 결제 실패 시 옵션 상태도 CANCELLED 로 동기화 (B1 회귀)
     *
     * 기존: order_status 만 CANCELLED 로 바꾸고 option_status 는 PENDING_ORDER 에 잔존 →
     * order_status=CANCELLED ↔ option_status=PENDING_ORDER 불일치.
     *
     * @scenario terminal_path=fail_payment, payment_method=dbank, option_mix=all_active, actor=member
     *
     * @effects fail_payment_syncs_option_status_to_cancelled
     */
    public function test_fail_payment_syncs_option_status_to_cancelled(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);
        $optionA = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        $optionB = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $this->service->failPayment($order, 'USER_CANCEL', '사용자 취소');

        // 결제 실패로 주문이 취소되면 옵션도 취소 상태로 동기화되어야 한다.
        $this->assertEquals(OrderStatusEnum::CANCELLED, $optionA->fresh()->option_status);
        $this->assertEquals(OrderStatusEnum::CANCELLED, $optionB->fresh()->option_status);
    }

    /**
     * 결제 실패 동기화가 이미 부분취소된 옵션을 되살리지 않음 (B1 syncExcludedValues)
     *
     * @scenario terminal_path=fail_payment, payment_method=dbank, option_mix=has_cancelled, actor=member
     *
     * @effects fail_payment_preserves_already_cancelled_options
     */
    public function test_fail_payment_preserves_already_cancelled_options(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);
        $activeOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        // 이미 취소된 옵션(부분취소로 일부 옵션이 cancelled 된 주문) — failPayment 가 보존해야 한다.
        $cancelledOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::CANCELLED,
        ]);

        $this->service->failPayment($order, 'USER_CANCEL', '사용자 취소');

        // 활성 옵션만 CANCELLED 로 전이, 이미 취소된 옵션은 보존(syncExcludedValues).
        $this->assertEquals(OrderStatusEnum::CANCELLED, $activeOption->fresh()->option_status);
        $this->assertEquals(OrderStatusEnum::CANCELLED, $cancelledOption->fresh()->option_status);
    }

    /**
     * 이미 결제 완료(payment_status=PAID)된 주문은 failPayment 가 무시한다 (race 회귀).
     *
     * 배경: 카드(PG) 주문은 승인 직전까지 order_status=PENDING_ORDER 이다. PG 승인 콜백
     * (completePayment)과 결제창 닫힘 보고(failPayment)가 거의 동시에 도착하면, failPayment 가
     * order_status 만 보는 기존 가드(isBeforePayment)를 통과해 옵션을 CANCELLED 로 덮을 수 있다.
     * 이후 order_status 는 completePayment 가 PAYMENT_COMPLETE 로 덮지만, 옵션은 syncExcludedValues
     * 보호로 되살아나지 못해 "주문=결제완료 / 옵션=취소" 모순이 고착된다.
     * → payment_status=PAID 면 결제는 이미 성공한 것이므로 failPayment 를 무시해야 한다.
     *
     * @scenario terminal_path=fail_payment, payment_method=card, payment_status=paid, option_mix=all_active, actor=member
     *
     * @effects fail_payment_ignored_when_payment_already_paid
     */
    public function test_fail_payment_ignores_order_when_payment_already_paid(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);
        // 승인 콜백이 payment 를 먼저 PAID 로 갱신한 race 상태 재현 (order_status 는 아직 PENDING_ORDER).
        $order->payment->update(['payment_status' => PaymentStatusEnum::PAID]);
        $order->refresh();

        $optionA = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        $optionB = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $result = $this->service->failPayment($order, 'USER_CANCEL', '결제창 닫힘');

        // 결제가 이미 성공했으므로 주문/옵션 모두 취소되지 않아야 한다.
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $result->order_status);
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $optionA->fresh()->option_status);
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $optionB->fresh()->option_status);
    }

    /**
     * payment_status=PAID 가드는 결제실패 메타도 기록하지 않는다 (race 회귀).
     *
     * @scenario terminal_path=fail_payment, payment_method=card, payment_status=paid, option_mix=all_active, actor=member
     *
     * @effects fail_payment_ignored_when_payment_already_paid
     */
    public function test_fail_payment_does_not_record_failure_meta_when_paid(): void
    {
        $order = $this->createOrderWithPayment(50000, 3000, 0);
        $order->payment->update(['payment_status' => PaymentStatusEnum::PAID]);
        $order->refresh();

        $result = $this->service->failPayment($order, 'USER_CANCEL', '결제창 닫힘');

        $meta = $result->order_meta ?? [];
        $this->assertArrayNotHasKey('payment_failure_code', $meta);
        $this->assertArrayNotHasKey('payment_failed_at', $meta);
    }

    // ===== confirmManualDeposit (무통장 입금확인) 테스트 (B2) =====

    /**
     * 무통장 미결제 주문 생성 (입금확인 테스트용)
     */
    private function createDbankOrder(int $subtotal = 50000, int $shipping = 3000): Order
    {
        $order = $this->createOrderWithPayment($subtotal, $shipping, 0);
        $order->update(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $order->payment->update(['payment_method' => PaymentMethodEnum::DBANK]);

        return $order->fresh();
    }

    /**
     * 무통장 입금확인 → completePayment 위임으로 옵션상태 결제완료 동기화 (B2)
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_syncs_option_status_via_complete_payment, manual_deposit_confirm_records_depositor_name
     */
    public function test_confirm_manual_deposit_syncs_option_status_and_records_depositor(): void
    {
        $order = $this->createDbankOrder(50000, 3000);
        $option = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        // markOrderComplete=true: 입금 기록 + 결제완료 전이
        $result = $this->service->confirmManualDeposit($order, 53000, '홍길동', true);

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $option->fresh()->option_status);
        $this->assertEquals('홍길동', $order->fresh()->payment->depositor_name);
        $this->assertEquals(PaymentStatusEnum::PAID, $order->fresh()->payment->payment_status);
    }

    /**
     * 무통장 입금확인 — markOrderComplete=false: 입금(payment)만 기록, 주문 상태는 전이 안 함
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_records_payment_only_without_order_transition
     */
    public function test_confirm_manual_deposit_records_payment_only_when_not_marking_complete(): void
    {
        $order = $this->createDbankOrder(50000, 3000);
        $option = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $result = $this->service->confirmManualDeposit($order, 53000, '홍길동', false);

        // 결제 레코드는 입금완료로 기록
        $this->assertEquals(PaymentStatusEnum::PAID, $result->payment->payment_status);
        $this->assertNotNull($result->payment->paid_at);
        $this->assertEquals('홍길동', $result->payment->depositor_name);

        // 주문/옵션 상태는 전이되지 않음 (결제완료 부수효과 미발생)
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $option->fresh()->option_status);
    }

    /**
     * 무통장 입금확인 — 주문은 이미 결제완료(order_status)인데 payment 가 미입금(ready)인
     * 불일치 주문: payment 만 PAID 로 정합화하고 결제완료 부수효과는 재실행하지 않는다.
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_reconciles_payment_only_for_already_completed_order
     */
    public function test_confirm_manual_deposit_reconciles_payment_for_already_completed_order(): void
    {
        $order = $this->createDbankOrder(50000, 3000);
        // 관리자가 주문 상태만 결제완료로 바꿔 둔 불일치 상황 (payment 는 여전히 ready)
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $this->assertEquals(PaymentStatusEnum::READY, $order->fresh()->payment->payment_status);

        // markOrderComplete=true 여도 이미 결제완료이므로 completePayment 전이는 건너뛰고 payment 만 정합
        $result = $this->service->confirmManualDeposit($order, 53000, '홍길동', true);

        $this->assertEquals(PaymentStatusEnum::PAID, $result->payment->payment_status);
        $this->assertNotNull($result->payment->paid_at);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
    }

    /**
     * 무통장 입금확인: 입금액이 결제예정금액과 불일치하면 422(PaymentAmountMismatchException) (B2)
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_rejects_amount_mismatch_422
     */
    public function test_confirm_manual_deposit_rejects_amount_mismatch(): void
    {
        $order = $this->createDbankOrder(50000, 3000);
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $this->expectException(PaymentAmountMismatchException::class);
        $this->service->confirmManualDeposit($order, 10000, '홍길동');

        // 결제완료로 전이되지 않아야 한다 (예외 전 검증 단계에서 차단)
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    /**
     * 무통장 입금확인 진입부에서 IDV 가드 훅(payment.before_confirm_deposit)이 발화 (A8/B2)
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_syncs_option_status_via_complete_payment
     */
    public function test_confirm_manual_deposit_fires_before_confirm_deposit_hook(): void
    {
        $order = $this->createDbankOrder(50000, 3000);
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $hookFired = false;
        HookManager::addAction('sirsoft-ecommerce.payment.before_confirm_deposit', function () use (&$hookFired) {
            $hookFired = true;
        });

        $this->service->confirmManualDeposit($order, 53000, '홍길동');

        $this->assertTrue($hookFired, 'payment.before_confirm_deposit 훅이 발화되어야 합니다');
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
        $tempOrder = TempOrder::create([
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
     * @param  string  $timing  재고 차감 타이밍 ('order_placed', 'payment_complete', 'none')
     * @return MockObject StockService mock
     */
    protected function mockStockAndSettingsForTiming(string $timing): MockObject
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

    // ──────────────────────────────────────────────
    // 관리자 신규주문 알림 발송 시점 (order.after_admin_notify)
    // 카드(PG): 결제완료 시점 / 무통장(비-PG): 주문 생성 시점
    // ──────────────────────────────────────────────

    /**
     * order.after_admin_notify 발화 횟수를 카운트하는 리스너를 등록합니다.
     *
     * @return \Closure 발화 횟수를 반환하는 getter
     */
    private function countAdminNotify(): \Closure
    {
        $count = 0;
        HookManager::addAction('sirsoft-ecommerce.order.after_admin_notify', function () use (&$count) {
            $count++;
        }, 99);

        return function () use (&$count) {
            return $count;
        };
    }

    /**
     * 카드 결제수단에 PG 공급자가 설정된 환경을 시뮬레이션합니다.
     *
     * orderRequiresPgPayment 는 determinePgProvider → SettingsService 조회 결과로 PG 필요 여부를
     * 판정한다. 테스트 환경엔 활성 PG 플러그인이 없어 'none' 이 되므로, 설정을 목킹해 'kginicis' 를
     * 반환하게 한다. mockCalculationService 호출 전에 설정 인스턴스를 바인딩해야 한다.
     */
    private function mockCardPgProvider(): void
    {
        $settingsMock = $this->createMock(EcommerceSettingsService::class);
        $settingsMock->method('getPaymentMethodConfig')->willReturn(['pg_provider' => 'kginicis']);
        $settingsMock->method('getSetting')->willReturnCallback(
            fn ($key, $default = null) => $key === 'order_settings.default_pg_provider' ? 'kginicis' : $default
        );
        $settingsMock->method('getStockDeductionTiming')->willReturn('payment_complete');
        $settingsMock->method('getMileageDeductionTiming')->willReturn('payment_complete');
        $this->app->instance(EcommerceSettingsService::class, $settingsMock);
    }

    public function test_card_order_does_not_fire_admin_notify_at_creation(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);
        $this->mockCardPgProvider();
        $this->mockCalculationService($this->makeCalculationResult(103000));

        $getCount = $this->countAdminNotify();

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            103000
        );

        // 카드(PG) 주문은 생성 시점(pending_order)에 관리자 알림을 발화하지 않는다.
        $this->assertSame(0, $getCount());
    }

    public function test_dbank_order_fires_admin_notify_at_creation(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->createTestTempOrder($user);
        $this->mockCalculationService($this->makeCalculationResult(103000));

        $getCount = $this->countAdminNotify();

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Test', 'phone' => '010-0000-0000', 'email' => 'test@test.com'],
            ['recipient_name' => 'Test', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'dbank',
            103000
        );

        // 무통장(비-PG) 주문은 생성 시점(입금 전이라도)에 관리자 알림을 발화한다.
        $this->assertSame(1, $getCount());
    }

    public function test_card_order_fires_admin_notify_on_payment_complete(): void
    {
        // 일관된 카드 주문 fixture(total_due=53000) — completePayment 금액 재검증 통과
        $order = $this->createOrderWithPayment();

        $getCount = $this->countAdminNotify();

        // 결제완료 시점에 1회 발화 (PG 콜백 실청구액 = 주문 결제예정액)
        $this->service->completePayment($order, ['transaction_id' => 'TX-TEST'], (int) $order->total_due_amount);

        $this->assertSame(1, $getCount());
    }

    // ──────────────────────────────────────────────
    // 미지원 결제 통화 차단 (assertPaymentCurrencyChargeable)
    // ──────────────────────────────────────────────

    public function test_assert_payment_currency_throws_for_zero_rate_currency(): void
    {
        $method = new \ReflectionMethod($this->service, 'assertPaymentCurrencyChargeable');
        $method->setAccessible(true);

        $snapshot = [
            'base_currency' => 'USD',
            'order_currency' => 'CNY',
            'exchange_rates' => [
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
                'CNY' => ['rate' => 0, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ],
        ];

        $this->expectException(UnsupportedPaymentCurrencyException::class);
        $method->invoke($this->service, $snapshot, 6.0);
    }

    public function test_assert_payment_currency_throws_when_converted_amount_is_zero(): void
    {
        $method = new \ReflectionMethod($this->service, 'assertPaymentCurrencyChargeable');
        $method->setAccessible(true);

        // base $0.0001 × JPY 환율 → 환산 후 0 (PG 최소금액 미만)
        $snapshot = [
            'base_currency' => 'USD',
            'order_currency' => 'JPY',
            'exchange_rates' => [
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
                'JPY' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
            ],
        ];

        $this->expectException(UnsupportedPaymentCurrencyException::class);
        $method->invoke($this->service, $snapshot, 0.0001);
    }

    public function test_assert_payment_currency_passes_for_valid_currency(): void
    {
        $method = new \ReflectionMethod($this->service, 'assertPaymentCurrencyChargeable');
        $method->setAccessible(true);

        $snapshot = [
            'base_currency' => 'USD',
            'order_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1176470, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ],
        ];

        // 예외 없이 통과
        $method->invoke($this->service, $snapshot, 6.0);
        $this->assertTrue(true);
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
     * @param  int  $userId  사용자 ID
     * @param  int  $quantity  수량
     * @param  Product|null  $product  상품 (null이면 새로 생성)
     * @param  ProductOption|null  $option  옵션 (null이면 새로 생성)
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

    public function test_payment_complete_타이밍에서_create_from_temp_order_장바구니_미삭제(): void
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

    public function test_complete_payment_stores_paid_amount_local_in_order_currency_when_cross_currency(): void
    {
        // base JPY 주문을 KRW 로 결제: paid_amount_local 은 결제 통화(KRW) 환산액이어야 하고,
        // paid_amount_base 는 base(JPY) 진실값이어야 한다. base 값을 그대로 local 에 넣던 회귀를 차단.
        $order = $this->createOrderWithPayment(200, 0, 0); // base JPY 200
        $order->update([
            'currency' => 'KRW',
            'total_due_amount' => 200,
            'currency_snapshot' => [
                'base_currency' => 'JPY',
                'order_currency' => 'KRW',
                'base_unit' => 100,
                'exchange_rates' => [
                    'JPY' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'round', 'decimal_places' => 0, 'base_unit' => 100],
                    'KRW' => ['rate' => 950, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                ],
            ],
        ]);

        // 결제 통화(KRW) 청구액 = (200/100)×950 = 1900
        $result = $this->service->completePayment($order->fresh(), [], 1900);

        $payment = $result->payment()->first();
        $this->assertSame('KRW', $payment->currency);
        // paid_amount_local = 결제 통화 환산액 1900 (base 200 이 아님)
        $this->assertEqualsWithDelta(1900, (float) $payment->paid_amount_local, 0.001, 'paid_amount_local 은 결제 통화(KRW) 환산액이어야 한다.');
        // paid_amount_base = base 진실값 200
        $this->assertEqualsWithDelta(200, (float) $payment->paid_amount_base, 0.001, 'paid_amount_base 는 base(JPY) 진실값이어야 한다.');
        // mc_total_paid_amount[order_currency] 와 일치
        $this->assertEqualsWithDelta(
            (float) ($result->fresh()->mc_total_paid_amount['KRW'] ?? 0),
            (float) $payment->paid_amount_local,
            0.001,
            'paid_amount_local 은 mc_total_paid_amount[order_currency] 와 정합해야 한다.'
        );
    }

    // ===== 구매 대상 제한 최종 차단 통합 테스트 =====

    /**
     * 구매 대상 제한이 걸린 상품을 담은 TempOrder를 생성합니다.
     *
     * @param  User  $user  사용자
     * @param  int  $productId  주문 항목의 상품 ID
     * @return TempOrder
     */
    protected function createRestrictedTempOrder(User $user, int $productId)
    {
        return TempOrderFactory::new()
            ->forUser($user)
            ->withItems([
                ['cart_id' => 1, 'product_id' => $productId, 'product_option_id' => 1, 'quantity' => 1],
            ])
            ->withCalculationResult([
                'summary' => [
                    'subtotal' => 100000, 'total_discount' => 0, 'product_coupon_discount' => 0,
                    'code_discount' => 0, 'total_shipping' => 3000, 'final_amount' => 103000,
                    'taxable_amount' => 93636, 'tax_free_amount' => 0, 'points_used' => 0,
                ],
                'items' => [], 'shippings' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();
    }

    /**
     * @return array{0: array, 1: array} 주문자 정보, 배송지 정보
     */
    protected function ordererAndShipping(): array
    {
        return [
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'test@example.com'],
            ['recipient_name' => '홍길동', 'recipient_phone' => '010-1234-5678', 'zipcode' => '12345', 'address' => '서울시 강남구 테헤란로', 'address_detail' => '123동 456호'],
        ];
    }

    public function test_허용_역할_없는_회원은_제한_상품_주문_차단(): void
    {
        $allowedRole = Role::create(['identifier' => 'vip-test', 'name' => ['ko' => 'VIP', 'en' => 'VIP']]);

        // 사용자는 allowedRole 미보유
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'purchase_restriction' => 'restricted',
            'allowed_roles' => [$allowedRole->id],
        ]);

        $tempOrder = $this->createRestrictedTempOrder($user, $product->id);
        [$orderer, $shipping] = $this->ordererAndShipping();

        $this->expectException(CartUnavailableException::class);

        $this->service->createFromTempOrder($tempOrder, $orderer, $shipping, 'card', 103000);
    }

    public function test_허용_역할_보유_회원은_제한_상품_주문_가능(): void
    {
        $allowedRole = Role::create(['identifier' => 'vip-test', 'name' => ['ko' => 'VIP', 'en' => 'VIP']]);

        $user = User::factory()->create();
        $user->roles()->attach($allowedRole->id);
        $this->actingAs($user->fresh());

        $product = Product::factory()->create([
            'purchase_restriction' => 'restricted',
            'allowed_roles' => [$allowedRole->id],
        ]);

        $tempOrder = $this->createRestrictedTempOrder($user, $product->id);
        [$orderer, $shipping] = $this->ordererAndShipping();

        $this->mockCalculationService($this->makeCalculationResult(103000));

        $order = $this->service->createFromTempOrder($tempOrder, $orderer, $shipping, 'card', 103000);

        $this->assertInstanceOf(Order::class, $order);
    }

    public function test_제한_없음_상품은_회원_주문_정상_생성(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'purchase_restriction' => 'none',
            'allowed_roles' => [],
        ]);

        $tempOrder = $this->createRestrictedTempOrder($user, $product->id);
        [$orderer, $shipping] = $this->ordererAndShipping();

        $this->mockCalculationService($this->makeCalculationResult(103000));

        $order = $this->service->createFromTempOrder($tempOrder, $orderer, $shipping, 'card', 103000);

        $this->assertInstanceOf(Order::class, $order);
    }

    /**
     * §3.4 회귀: 옵션 다통화 빌더가 mc_subtotal_earned_points_amount 를 포함해야 한다.
     *
     * 결함: buildOptionMultiCurrency 에 적립값 변환이 빠져 mc_ 적립 컬럼이 항상 NULL.
     */
    public function test_build_option_multi_currency_includes_earned_points(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildOptionMultiCurrency');
        $method->setAccessible(true);

        $item = (object) [
            'unitPrice' => 10000,
            'subtotal' => 10000,
            'productCouponDiscountAmount' => 0,
            'orderCouponDiscountShare' => 0,
            'codeDiscountAmount' => 0,
            'pointsUsedShare' => 0,
            'pointsEarning' => 500,
            'taxableAmount' => 10000,
            'taxFreeAmount' => 0,
            'finalAmount' => 10000,
        ];

        $snapshot = [
            'base_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => 1,
                'USD' => ['rate' => 0.75, 'rounding_unit' => '0.01', 'rounding_method' => 'round'],
            ],
        ];

        $mc = $method->invoke($this->service, $item, $snapshot);

        // 적립 다통화 컬럼이 존재하고 적립값(500)이 기준통화에 반영되어야 함
        $this->assertArrayHasKey('mc_subtotal_earned_points_amount', $mc, '적립 다통화 컬럼이 빌더에 포함되어야 합니다.');
        $this->assertSame(500, $mc['mc_subtotal_earned_points_amount']['KRW'] ?? null);
    }

    // ===== 전액 마일리지 결제 (결제액 0원) — determineInitialStatus / 자동 완료 =====

    public function test_determine_initial_status_returns_payment_complete_for_zero_amount(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineInitialStatus');
        $method->setAccessible(true);

        // 결제수단 무관(card/vbank/dbank) — finalAmount 0 이면 결제완료
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $method->invoke($this->service, 'card', 0));
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $method->invoke($this->service, 'vbank', 0));
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $method->invoke($this->service, 'dbank', 0));

        // finalAmount > 0 이면 기존 분기 유지
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $method->invoke($this->service, 'card', 50000));
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $method->invoke($this->service, 'vbank', 50000));
    }

    public function test_create_from_temp_order_full_mileage_payment_marks_payment_complete(): void
    {
        $user = User::factory()->create();

        // 결제액 전액(100,000)을 마일리지로 충당 — FIFO 차감용 잔액 시드
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => 'purchase_earn',
            'amount' => 100000,
            'remaining_amount' => 100000,
            'balance_after' => 100000,
        ]);

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withUsePoints(100000)
            ->withCalculationResult([
                'summary' => ['final_amount' => 0],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        // 재계산: payment_amount 100,000 전액 마일리지 사용 → final_amount 0
        $this->mockCalculationService($this->makeCalculationResult(0, [
            'subtotal' => 100000,
            'total_shipping' => 0,
            'taxable_amount' => 100000,
            'payment_amount' => 100000,
            'points_used' => 100000,
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => '전액마일', 'phone' => '010-0000-0000', 'email' => 'allmileage@test.com'],
            ['recipient_name' => '전액마일', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Test', 'address_detail' => 'Test'],
            'card',
            0
        );

        $order->refresh()->load('payment');

        // 결제액 0 → 즉시 결제완료
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status, '전액 마일리지 결제는 결제완료 상태여야 합니다.');
        $this->assertNotNull($order->paid_at, '결제완료 시각이 기록되어야 합니다.');
        $this->assertEquals(0, (int) $order->total_due_amount, '미결제 잔액(입금 필요액)은 0이어야 합니다.');
        $this->assertEquals(0, (int) $order->total_paid_amount, 'PG/현금 결제액은 0이어야 합니다(전액 마일리지).');
        $this->assertEquals(100000, (int) $order->total_points_used_amount, '마일리지 사용액이 기록되어야 합니다.');

        // 결제 레코드도 PAID
        $this->assertNotNull($order->payment);
        $this->assertEquals(PaymentStatusEnum::PAID, $order->payment->payment_status, '전액 마일리지 결제의 결제 레코드는 PAID 여야 합니다.');
    }

    public function test_create_from_temp_order_full_mileage_payment_fires_payment_complete_hooks(): void
    {
        $user = User::factory()->create();
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => 'purchase_earn',
            'amount' => 50000,
            'remaining_amount' => 50000,
            'balance_after' => 50000,
        ]);

        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withUsePoints(50000)
            ->withCalculationResult([
                'summary' => ['final_amount' => 0],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();

        $this->mockCalculationService($this->makeCalculationResult(0, [
            'subtotal' => 50000,
            'total_shipping' => 0,
            'taxable_amount' => 50000,
            'payment_amount' => 50000,
            'points_used' => 50000,
        ]));

        $afterConfirmFired = false;
        $paymentCompleteFired = false;
        HookManager::addAction('sirsoft-ecommerce.order.after_confirm', function ($order) use (&$afterConfirmFired) {
            $afterConfirmFired = true;
        });
        HookManager::addAction('sirsoft-ecommerce.order.after_payment_complete', function ($order) use (&$paymentCompleteFired) {
            $paymentCompleteFired = true;
        });

        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'Hook', 'phone' => '010-0000-0000', 'email' => 'hook@test.com'],
            ['recipient_name' => 'Hook', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'T', 'address_detail' => 'T'],
            'card',
            0
        );

        $this->assertTrue($paymentCompleteFired, '전액 마일리지 결제 시 결제완료 훅이 발화해야 합니다.');
        $this->assertTrue($afterConfirmFired, '전액 마일리지 결제 시 주문확인 훅이 발화해야 합니다.');
    }

    // ===== 마일리지 차감 시점 (mileage.deduction_timing) — 후속 결함 =====

    /**
     * 부분 마일리지 사용 PG 주문 TempOrder + 잔액 시드를 구성합니다.
     *
     * @param  User  $user  사용자
     * @param  int  $usePoints  사용 마일리지
     * @param  int  $finalAmount  차감 후 결제액
     */
    protected function makePartialMileageTempOrder(User $user, int $usePoints, int $finalAmount): TempOrder
    {
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => 'purchase_earn',
            'amount' => $usePoints,
            'remaining_amount' => $usePoints,
            'balance_after' => $usePoints,
        ]);

        return TempOrderFactory::new()
            ->forUser($user)
            ->withUsePoints($usePoints)
            ->withCalculationResult([
                'summary' => ['final_amount' => $finalAmount],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();
    }

    public function test_payment_complete_timing_does_not_deduct_mileage_at_order_creation(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->makePartialMileageTempOrder($user, 2000, 53000);

        // 기본(payment_complete) 타이밍 — 실제 설정값(defaults.json) 사용
        $this->mockCalculationService($this->makeCalculationResult(53000, [
            'subtotal' => 55000, 'total_shipping' => 0, 'taxable_amount' => 53000,
            'payment_amount' => 55000, 'points_used' => 2000,
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'PG', 'phone' => '010-0000-0000', 'email' => 'pg@test.com'],
            ['recipient_name' => 'PG', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'T', 'address_detail' => 'T'],
            'card',
            53000
        );

        $order->refresh();

        // 결제완료 전이므로 마일리지가 차감되지 않아야 한다 (선차감 결함 방지)
        $this->assertFalse((bool) $order->is_mileage_deducted, '결제완료 전(payment_complete 타이밍)에는 마일리지가 차감되지 않아야 합니다.');
        $this->assertEquals(0, MileageTransaction::where('user_id', $user->id)->where('type', 'order_use')->count(), '주문 사용 거래가 생성되지 않아야 합니다.');
        // 잔액 lot 은 그대로 남아있어야 함
        $this->assertEquals(2000, (int) MileageTransaction::where('user_id', $user->id)->where('type', 'purchase_earn')->sum('remaining_amount'), '결제 전이므로 적립 lot 잔여가 보존되어야 합니다.');
    }

    public function test_complete_payment_deducts_mileage_for_payment_complete_timing(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->makePartialMileageTempOrder($user, 2000, 53000);

        $this->mockCalculationService($this->makeCalculationResult(53000, [
            'subtotal' => 55000, 'total_shipping' => 0, 'taxable_amount' => 53000,
            'payment_amount' => 55000, 'points_used' => 2000,
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'PG', 'phone' => '010-0000-0000', 'email' => 'pg@test.com'],
            ['recipient_name' => 'PG', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'T', 'address_detail' => 'T'],
            'card',
            53000
        );

        // 결제완료 처리 → 이 시점에 마일리지 차감
        $this->service->completePayment($order->fresh(), [], 53000);

        $order->refresh();
        $this->assertTrue((bool) $order->is_mileage_deducted, '결제완료 시 마일리지 차감 플래그가 기록되어야 합니다.');
        $this->assertEquals(1, MileageTransaction::where('user_id', $user->id)->where('type', 'order_use')->count(), '결제완료 시점에 주문 사용 거래가 1건 생성되어야 합니다.');
        $this->assertEquals(0, (int) MileageTransaction::where('user_id', $user->id)->where('type', 'purchase_earn')->sum('remaining_amount'), '사용분만큼 적립 lot 잔여가 소진되어야 합니다.');
    }

    public function test_order_placed_timing_deducts_mileage_at_order_creation(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->makePartialMileageTempOrder($user, 2000, 53000);

        // 카드 결제수단의 마일리지 차감 시점을 order_placed 로 설정 (testing 격리 경로에 저장됨)
        $this->setCardMileageTiming('order_placed');

        $this->mockCalculationService($this->makeCalculationResult(53000, [
            'subtotal' => 55000, 'total_shipping' => 0, 'taxable_amount' => 53000,
            'payment_amount' => 55000, 'points_used' => 2000,
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'PG', 'phone' => '010-0000-0000', 'email' => 'pg@test.com'],
            ['recipient_name' => 'PG', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'T', 'address_detail' => 'T'],
            'card',
            53000
        );

        $order->refresh();
        $this->assertTrue((bool) $order->is_mileage_deducted, 'order_placed 타이밍은 주문 생성 시 차감되어야 합니다.');
        $this->assertEquals(1, MileageTransaction::where('user_id', $user->id)->where('type', 'order_use')->count(), '주문 생성 시점에 사용 거래가 생성되어야 합니다.');
    }

    public function test_fail_payment_restores_deducted_mileage(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->makePartialMileageTempOrder($user, 2000, 53000);

        $this->setCardMileageTiming('order_placed');

        $this->mockCalculationService($this->makeCalculationResult(53000, [
            'subtotal' => 55000, 'total_shipping' => 0, 'taxable_amount' => 53000,
            'payment_amount' => 55000, 'points_used' => 2000,
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'PG', 'phone' => '010-0000-0000', 'email' => 'pg@test.com'],
            ['recipient_name' => 'PG', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'T', 'address_detail' => 'T'],
            'card',
            53000
        );

        $order->refresh();
        $this->assertTrue((bool) $order->is_mileage_deducted, '사전 조건: order_placed 차감이 일어나야 합니다.');

        // 결제 실패 → 차감된 마일리지가 복원되어야 한다
        $result = $this->service->failPayment($order, 'USER_CANCEL', '결제창 닫음');

        $this->assertEquals(OrderStatusEnum::CANCELLED, $result->order_status);
        $this->assertFalse((bool) $result->is_mileage_deducted, '복원 후 차감 플래그가 해제되어야 합니다.');
        $this->assertEquals(1, MileageTransaction::where('user_id', $user->id)->where('type', 'order_cancel_restore')->count(), '결제 실패 취소 시 복원 거래가 1건 생성되어야 합니다.');
        $this->assertEquals(2000, (int) MileageTransaction::where('user_id', $user->id)->where('type', 'order_cancel_restore')->sum('amount'), '복원액은 사용 마일리지와 같아야 합니다.');
    }

    public function test_fail_payment_does_not_create_phantom_mileage_when_not_deducted(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->makePartialMileageTempOrder($user, 2000, 53000);

        // 기본(payment_complete) — 생성 시 차감 안 됨
        $this->mockCalculationService($this->makeCalculationResult(53000, [
            'subtotal' => 55000, 'total_shipping' => 0, 'taxable_amount' => 53000,
            'payment_amount' => 55000, 'points_used' => 2000,
        ]));

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'PG', 'phone' => '010-0000-0000', 'email' => 'pg@test.com'],
            ['recipient_name' => 'PG', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'T', 'address_detail' => 'T'],
            'card',
            53000
        );

        $order->refresh();
        $this->assertFalse((bool) $order->is_mileage_deducted, '사전 조건: 결제 전이라 차감되지 않아야 합니다.');

        // 결제 실패 → 차감된 적 없으므로 복원(유령 적립)이 발생하면 안 된다
        $this->service->failPayment($order, 'USER_CANCEL', '결제창 닫음');

        $this->assertEquals(0, MileageTransaction::where('user_id', $user->id)->where('type', 'order_cancel_restore')->count(), '차감되지 않은 주문은 복원 거래를 생성하지 않아야 합니다 (유령 적립 방지).');
        // 잔액 lot 은 차감/복원 모두 없이 그대로
        $this->assertEquals(2000, (int) MileageTransaction::where('user_id', $user->id)->where('type', 'purchase_earn')->sum('remaining_amount'), '잔액이 변동 없이 보존되어야 합니다.');
    }

    public function test_order_status_enum_list_hidden_values_contains_only_pending_order(): void
    {
        $this->assertEquals(['pending_order'], OrderStatusEnum::listHiddenValues());
    }
}
