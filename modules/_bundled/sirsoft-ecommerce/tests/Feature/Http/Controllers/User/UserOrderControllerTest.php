<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * UserOrderController Feature 테스트
 *
 * 사용자 주문 생성, 목록 조회, 상세 조회 API를 테스트합니다.
 */
class UserOrderControllerTest extends ModuleTestCase
{
    protected string $cartKey;
    protected Product $product;
    protected ProductOption $productOption;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cartKey = Str::uuid()->toString();

        // 테스트 상품 생성
        $this->product = Product::create([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
            'product_code' => 'TEST-' . Str::random(8),
            'sku' => 'SKU-' . Str::random(8),
            'list_price' => 20000,
            'selling_price' => 15000,
            'currency_code' => 'KRW',
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);

        // 테스트 상품 옵션 생성
        $this->productOption = ProductOption::create([
            'product_id' => $this->product->id,
            'option_code' => 'OPT-' . Str::random(8),
            'option_values' => ['색상' => '검정', '사이즈' => 'M'],
            'option_name' => null,
            'sku' => 'SKU-' . Str::random(8),
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /**
     * 임시 주문 생성 헬퍼
     */
    protected function createTempOrder(int $userId): TempOrder
    {
        return TempOrder::create([
            'user_id' => $userId,
            'cart_key' => $this->cartKey,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                ],
            ],
            'calculation_result' => [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'product_option_id' => $this->productOption->id,
                        'quantity' => 2,
                        'unit_price' => 15000,
                        'subtotal' => 30000,
                        'final_amount' => 30000,
                    ],
                ],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'final_amount' => 30000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * 주문 생성 기본 테스트 (무통장입금)
     */
    public function test_로그인_사용자_주문_생성_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer' => [
                    'name' => '홍길동',
                    'phone' => '010-1234-5678',
                    'email' => 'hong@test.com',
                ],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구 테헤란로 123',
                    'address_detail' => '101동 1001호',
                ],
                'shipping_memo' => '문 앞에 놓아주세요',
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 30000,
                'depositor_name' => '홍길동',
                'dbank' => [
                    'bank_code' => 'KB',
                    'bank_name' => '국민은행',
                    'account_number' => '123-456-789012',
                    'account_holder' => '주식회사 테스트',
                ],
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'order' => [
                        'id',
                        'order_number',
                        'order_status',
                        'total_amount',
                    ],
                ],
            ]);
    }

    /**
     * 임시 주문 없이 주문 생성 실패 테스트
     */
    public function test_임시주문_없으면_주문_생성_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer' => [
                    'name' => '홍길동',
                    'phone' => '010-1234-5678',
                    'email' => 'hong@test.com',
                ],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구',
                    'address_detail' => '101동',
                ],
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 30000,
                'depositor_name' => '홍길동',
                'dbank' => [
                    'bank_code' => 'KB',
                    'bank_name' => '국민은행',
                    'account_number' => '123-456-789012',
                    'account_holder' => '주식회사 테스트',
                ],
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(404);
    }

    /**
     * 만료된 임시 주문으로 생성 실패 테스트
     */
    public function test_만료된_임시주문으로_주문_생성_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $tempOrder = $this->createTempOrder($user->id);
        $tempOrder->update(['expires_at' => now()->subMinute()]);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer' => [
                    'name' => '홍길동',
                    'phone' => '010-1234-5678',
                    'email' => 'hong@test.com',
                ],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구',
                    'address_detail' => '101동',
                ],
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 30000,
                'depositor_name' => '홍길동',
                'dbank' => [
                    'bank_code' => 'KB',
                    'bank_name' => '국민은행',
                    'account_number' => '123-456-789012',
                    'account_holder' => '주식회사 테스트',
                ],
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        // 만료된 임시주문은 findValidByUserOrCartKey에서 null 반환하므로 404
        $response->assertStatus(404);
    }

    /**
     * 결제 금액 불일치 시 실패 테스트
     */
    public function test_결제금액_불일치_시_주문_생성_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer_name' => '홍길동',
                'orderer_phone' => '010-1234-5678',
                'orderer_email' => 'hong@test.com',
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'zipcode' => '12345',
                'address1' => '서울시 강남구',
                'address2' => '101동',
                'country_code' => 'KR',
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 99999, // 잘못된 금액
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(422);
    }

    /**
     * 비로그인 사용자 주문 생성 실패 테스트
     */
    public function test_비로그인_사용자_주문_생성_실패(): void
    {
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer_name' => '홍길동',
                'orderer_phone' => '010-1234-5678',
                'orderer_email' => 'hong@test.com',
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'zipcode' => '12345',
                'address1' => '서울시 강남구',
                'address2' => '101동',
                'country_code' => 'KR',
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 30000,
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(401);
    }

    // ========================================================================
    // 주문 목록 조회 (index) 테스트
    // ========================================================================

    /**
     * 인증된 사용자의 본인 주문 목록 조회 성공
     */
    public function test_인증된_사용자_주문_목록_조회_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 본인 주문 3건 생성
        Order::factory()->count(3)->forUser($user)->pendingPayment()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'statistics' => [
                        'pending_payment',
                        'payment_complete',
                        'preparing',
                        'shipping',
                        'delivered',
                        'confirmed',
                    ],
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                    ],
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 3);
    }

    /**
     * 주문 목록에 타인의 주문이 포함되지 않음
     */
    public function test_주문_목록에_타인_주문_미포함(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->actingAs($user);

        // 본인 주문 2건
        Order::factory()->count(2)->forUser($user)->pendingPayment()->create();
        // 타인 주문 3건
        Order::factory()->count(3)->forUser($otherUser)->paid()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 2);
    }

    /**
     * 상태 필터로 주문 목록 조회
     */
    public function test_상태_필터로_주문_목록_조회(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 다양한 상태의 주문 생성
        Order::factory()->count(2)->forUser($user)->pendingPayment()->create();
        Order::factory()->count(3)->forUser($user)->paid()->create();
        Order::factory()->forUser($user)->shipping()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders?status=payment_complete');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 3);
    }

    /**
     * 주문 목록 페이지네이션 동작
     */
    public function test_주문_목록_페이지네이션(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        Order::factory()->count(15)->forUser($user)->pendingPayment()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders?per_page=5&page=2');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 5)
            ->assertJsonPath('data.pagination.total', 15);
    }

    /**
     * 주문 목록에 상태별 통계가 정확하게 포함됨
     */
    public function test_주문_목록_통계_정확성(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        Order::factory()->count(2)->forUser($user)->pendingPayment()->create();
        Order::factory()->count(1)->forUser($user)->paid()->create();
        Order::factory()->count(3)->forUser($user)->shipping()->create();
        Order::factory()->count(1)->forUser($user)->delivered()->create();
        Order::factory()->count(1)->forUser($user)->confirmed()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200)
            ->assertJsonPath('data.statistics.pending_payment', 2)
            ->assertJsonPath('data.statistics.payment_complete', 1)
            ->assertJsonPath('data.statistics.shipping', 3)
            ->assertJsonPath('data.statistics.delivered', 1)
            ->assertJsonPath('data.statistics.confirmed', 1);
    }

    /**
     * 비로그인 사용자 주문 목록 조회 시 401
     */
    public function test_비로그인_사용자_주문_목록_조회_실패(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(401);
    }

    /**
     * 주문 목록 각 항목의 리소스 구조 확인
     */
    public function test_주문_목록_리소스_구조(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->pendingPayment()->create();
        OrderOption::factory()->forOrder($order)->create([
            'product_id' => $this->product->id,
            'product_option_id' => $this->productOption->id,
        ]);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'order_number',
                            'status',
                            'status_label',
                            'status_variant',
                            'ordered_at',
                            'ordered_at_formatted',
                            'total_amount',
                            'total_amount_formatted',
                            'items',
                            'item_count',
                        ],
                    ],
                ],
            ]);
    }

    // ========================================================================
    // 주문 상세 조회 (show by ID) 테스트
    // ========================================================================

    /**
     * 인증된 사용자의 본인 주문 상세 조회 성공 (ID 기반)
     */
    public function test_본인_주문_상세_조회_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->pendingPayment()->create();

        $response = $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'order_number',
                    'order_status',
                    'total_amount',
                ],
            ])
            ->assertJsonPath('data.id', $order->id);
    }

    /**
     * 타인의 주문 상세 조회 시 404
     */
    public function test_타인_주문_상세_조회_실패(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($otherUser)->pendingPayment()->create();

        $response = $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 주문 ID로 상세 조회 시 404
     */
    public function test_존재하지_않는_주문_상세_조회_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders/99999');

        $response->assertStatus(404);
    }

    /**
     * 비로그인 사용자 주문 상세 조회 시 401
     */
    public function test_비로그인_사용자_주문_상세_조회_실패(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $response = $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(401);
    }

    // ========================================================================
    // 결제 취소 기록 (cancelPayment) 테스트
    // ========================================================================

    /**
     * pending_order 상태 주문에 결제 취소 기록 성공
     */
    public function test_결제_취소_기록_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment",
            [
                'cancel_code' => 'USER_CANCEL',
                'cancel_message' => '사용자가 결제를 취소했습니다.',
            ]
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // cancel_history 기록 확인
        $payment = $order->fresh()->payment;
        $this->assertEquals(PaymentStatusEnum::CANCELLED->value, $payment->payment_status->value);
        $this->assertNotEmpty($payment->cancel_history);
        $this->assertEquals('USER_CANCEL', $payment->cancel_history[0]['cancel_code']);
        $this->assertEquals('사용자가 결제를 취소했습니다.', $payment->cancel_history[0]['cancel_message']);
    }

    /**
     * 타인의 주문에 결제 취소 기록 시 404
     */
    public function test_타인_주문_결제_취소_기록_실패(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($otherUser)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment"
        );

        $response->assertStatus(404);
    }

    /**
     * pending_order 외 상태에서 결제 취소 기록 시 422
     */
    public function test_결제완료_상태에서_결제_취소_기록_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->paid()->create();

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment"
        );

        $response->assertStatus(422);
    }

    /**
     * 비로그인 사용자가 비회원 주문 결제 취소 기록 성공
     */
    public function test_비로그인_사용자_비회원_주문_결제_취소_기록_성공(): void
    {
        // 비회원 주문 (user_id = null)
        $order = Order::factory()->create([
            'user_id' => null,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment"
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $payment = $order->fresh()->payment;
        $this->assertEquals(PaymentStatusEnum::CANCELLED->value, $payment->payment_status->value);
    }

    /**
     * 비로그인 사용자가 회원 주문에 접근 시 404
     */
    public function test_비로그인_사용자_회원_주문_결제_취소_기록_실패(): void
    {
        $user = $this->createUser();

        // 회원 주문 (user_id 존재)
        $order = Order::factory()->forUser($user)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        // 비로그인 상태에서 회원 주문 접근
        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment"
        );

        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 주문번호로 결제 취소 기록 시 404
     */
    public function test_존재하지_않는_주문_결제_취소_기록_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/orders/NON_EXISTENT/cancel-payment'
        );

        $response->assertStatus(404);
    }

    // ========================================================================
    // 배송지 자동 저장 테스트
    // ========================================================================

    /**
     * 주문 생성 요청 데이터 헬퍼 (배송지 저장 테스트용)
     */
    protected function buildOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'orderer' => [
                'name' => '홍길동',
                'phone' => '010-1234-5678',
                'email' => 'hong@test.com',
            ],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'shipping_memo' => '문 앞에 놓아주세요',
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => 30000,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
        ], $overrides);
    }

    public function test_비PG_체크ON_배송지_생성(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['save_shipping_address' => true]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('ecommerce_user_addresses', [
            'user_id' => $user->id,
            'recipient_name' => '김철수',
            'zipcode' => '12345',
            'address' => '서울시 강남구 테헤란로 123',
        ]);
    }

    public function test_비PG_체크OFF_배송지_미생성(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['save_shipping_address' => false]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $this->assertDatabaseMissing('ecommerce_user_addresses', [
            'user_id' => $user->id,
        ]);
    }

    public function test_비PG_체크_미전달_배송지_미생성(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $this->assertDatabaseMissing('ecommerce_user_addresses', [
            'user_id' => $user->id,
        ]);
    }

    public function test_PG_체크ON_order_meta에_플래그_저장(): void
    {
        // PG 결제가 실제로 동작하도록 기본 PG 제공자 설정
        app(EcommerceSettingsService::class)->setSetting('order_settings.default_pg_provider', 'tosspayments');

        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload([
                'payment_method' => PaymentMethodEnum::CARD->value,
                'save_shipping_address' => true,
                'depositor_name' => null,
                'dbank' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertTrue($order->order_meta['save_shipping_address'] ?? false);
        $this->assertArrayHasKey('shipping_info_for_save', $order->order_meta);
    }

    public function test_PG_체크ON_UserAddress_미생성(): void
    {
        app(EcommerceSettingsService::class)->setSetting('order_settings.default_pg_provider', 'tosspayments');

        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload([
                'payment_method' => PaymentMethodEnum::CARD->value,
                'save_shipping_address' => true,
                'depositor_name' => null,
                'dbank' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $this->assertDatabaseMissing('ecommerce_user_addresses', [
            'user_id' => $user->id,
        ]);
    }

    public function test_PG_체크OFF_order_meta_미저장(): void
    {
        app(EcommerceSettingsService::class)->setSetting('order_settings.default_pg_provider', 'tosspayments');

        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload([
                'payment_method' => PaymentMethodEnum::CARD->value,
                'save_shipping_address' => false,
                'depositor_name' => null,
                'dbank' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertFalse($order->order_meta['save_shipping_address'] ?? false);
    }

    public function test_첫_배송지_기본_배송지_자동_설정(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['save_shipping_address' => true]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $address = UserAddress::where('user_id', $user->id)->first();
        $this->assertNotNull($address);
        $this->assertTrue($address->is_default);
    }

    public function test_배송지명_자동_순번_부여(): void
    {
        // auto_saved_label 은 locale 의존 (__() 호출 시점마다 다를 수 있음)이므로
        // 테스트 전체에서 locale 을 명시적으로 'ko' 로 고정
        app()->setLocale('ko');

        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        // 기존 '새 배송지' 생성 — 컨트롤러가 동일 locale 로 호출하도록 보장
        $autoSavedLabel = __('sirsoft-ecommerce::messages.address.auto_saved_label');
        UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => $autoSavedLabel,
        ]);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['save_shipping_address' => true]),
            ['X-Cart-Key' => $this->cartKey, 'Accept-Language' => 'ko']
        );

        $response->assertStatus(201);

        $expectedName = "{$autoSavedLabel} (2)";
        $newAddress = UserAddress::where('user_id', $user->id)
            ->where('name', $expectedName)
            ->first();
        $this->assertNotNull($newAddress, "배송지명 '{$expectedName}'이 생성되어야 합니다");
    }

    // ===== 장바구니 처리 통합 테스트 =====

    /**
     * cart_id를 포함한 임시 주문 생성 헬퍼
     *
     * @param int $userId 사용자 ID
     * @param array $cartItems [['cart_id' => int, 'quantity' => int, 'product_id' => int, 'product_option_id' => int], ...]
     * @return TempOrder
     */
    protected function createTempOrderWithCartIds(int $userId, array $cartItems): TempOrder
    {
        $items = [];
        $calcItems = [];
        $subtotal = 0;

        foreach ($cartItems as $item) {
            $unitPrice = 15000;
            $itemSubtotal = $unitPrice * $item['quantity'];
            $subtotal += $itemSubtotal;

            $items[] = [
                'cart_id' => $item['cart_id'],
                'product_id' => $item['product_id'],
                'product_option_id' => $item['product_option_id'],
                'quantity' => $item['quantity'],
            ];

            $calcItems[] = [
                'product_id' => $item['product_id'],
                'product_option_id' => $item['product_option_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $unitPrice,
                'subtotal' => $itemSubtotal,
                'final_amount' => $itemSubtotal,
            ];
        }

        return TempOrder::create([
            'user_id' => $userId,
            'cart_key' => $this->cartKey,
            'items' => $items,
            'calculation_result' => [
                'items' => $calcItems,
                'summary' => [
                    'subtotal' => $subtotal,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => $subtotal,
                    'final_amount' => $subtotal,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function test_주문_생성_후_장바구니_아이템_삭제_order_placed(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 장바구니에 상품 추가
        $cart = Cart::create([
            'user_id' => $user->id,
            'product_id' => $this->product->id,
            'product_option_id' => $this->productOption->id,
            'quantity' => 2,
        ]);

        // cart_id 포함 TempOrder 생성
        $this->createTempOrderWithCartIds($user->id, [
            ['cart_id' => $cart->id, 'product_id' => $this->product->id, 'product_option_id' => $this->productOption->id, 'quantity' => 2],
        ]);

        // dbank = order_placed 타이밍 (기본 설정)
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }

    public function test_주문_생성_후_장바구니_수량_차감(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 장바구니에 수량 5로 저장
        $cart = Cart::create([
            'user_id' => $user->id,
            'product_id' => $this->product->id,
            'product_option_id' => $this->productOption->id,
            'quantity' => 5,
        ]);

        // 주문 수량 2 → 잔여 3
        $this->createTempOrderWithCartIds($user->id, [
            ['cart_id' => $cart->id, 'product_id' => $this->product->id, 'product_option_id' => $this->productOption->id, 'quantity' => 2],
        ]);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_carts', ['id' => $cart->id, 'quantity' => 3]);
    }

    public function test_주문_생성_후_복수_장바구니_혼합_처리(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 두 번째 상품/옵션 생성
        $product2 = Product::create([
            'name' => ['ko' => '테스트 상품2', 'en' => 'Test Product2'],
            'product_code' => 'TEST-' . Str::random(8),
            'sku' => 'SKU-' . Str::random(8),
            'list_price' => 20000,
            'selling_price' => 15000,
            'currency_code' => 'KRW',
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);
        $option2 = ProductOption::create([
            'product_id' => $product2->id,
            'option_code' => 'OPT-' . Str::random(8),
            'option_values' => ['색상' => '흰색'],
            'sku' => 'SKU-' . Str::random(8),
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // cart1: 수량 10, 주문 3 → 잔여 7
        $cart1 = Cart::create([
            'user_id' => $user->id,
            'product_id' => $this->product->id,
            'product_option_id' => $this->productOption->id,
            'quantity' => 10,
        ]);

        // cart2: 수량 2, 주문 2 → 삭제
        $cart2 = Cart::create([
            'user_id' => $user->id,
            'product_id' => $product2->id,
            'product_option_id' => $option2->id,
            'quantity' => 2,
        ]);

        $this->createTempOrderWithCartIds($user->id, [
            ['cart_id' => $cart1->id, 'product_id' => $this->product->id, 'product_option_id' => $this->productOption->id, 'quantity' => 3],
            ['cart_id' => $cart2->id, 'product_id' => $product2->id, 'product_option_id' => $option2->id, 'quantity' => 2],
        ]);

        $totalAmount = 15000 * 3 + 15000 * 2; // 75000
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['expected_total_amount' => $totalAmount]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_carts', ['id' => $cart1->id, 'quantity' => 7]);
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart2->id]);
    }
}
