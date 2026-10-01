<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\MileageTransactionListener;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class PaymentCallbackOrderSideEffectsTest extends PluginTestCase
{
    private const TEST_SITE_CD = 'T0000';

    private const TEST_SITE_KEY = 'TEST_SITE_KEY_0000';

    /**
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * 훅 매니저 상태를 저장해 두고 마일리지 사용 리스너를 등록한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotHookManager();
        $this->registerMileageTransactionListener();
    }

    /**
     * 저장해 둔 훅 매니저 상태로 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * 카드 승인 콜백이 주문을 결제완료로 바꾸면서 재고 차감, 쿠폰 사용 확정, 장바구니 삭제, 마일리지 차감까지 반영한다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=card_auth_callback
     *
     * @effects kcp_card_approval_completes_the_order_with_the_paid_amount,
     *   kcp_card_approval_deducts_option_and_product_stock,
     *   kcp_card_approval_confirms_the_used_coupon_on_the_order,
     *   kcp_card_approval_removes_the_ordered_cart_items,
     *   kcp_card_approval_deducts_the_used_mileage
     */
    public function test_auth_callback_completes_order_and_applies_ecommerce_side_effects(): void
    {
        $fixture = $this->createPendingCardOrderFixture();
        $order = $fixture['order'];
        $product = $fixture['product'];
        $productOption = $fixture['productOption'];
        $cart = $fixture['cart'];
        $couponIssue = $fixture['couponIssue'];
        $user = $fixture['user'];

        $this->mockPluginSettings();

        $tno = 'KCP_TNO_SIDE_EFFECTS';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 55000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 55000, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh()->load('payment', 'options');
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(55000, (int) $order->total_paid_amount);
        $this->assertEquals(0, (int) $order->total_due_amount);
        $this->assertTrue((bool) $order->is_mileage_deducted);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertEquals($tno, $payment->transaction_id);
        $this->assertEquals('APP12345', $payment->card_approval_number);
        $this->assertEquals(55000, (int) $payment->paid_amount_local);
        $this->assertEquals(55000, (int) $payment->paid_amount_base);

        $productOption->refresh();
        $product->refresh();
        $this->assertEquals(8, (int) $productOption->stock_quantity);
        $this->assertEquals(8, (int) $product->stock_quantity);
        $this->assertTrue((bool) $order->options->first()->is_stock_deducted);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->options->first()->option_status);

        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::USED, $couponIssue->status);
        $this->assertEquals($order->id, $couponIssue->order_id);
        $this->assertNotNull($couponIssue->used_at);

        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);

        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => MileageTransactionTypeEnum::ORDER_USE->value,
            'amount' => -2000,
            'remaining_amount' => 0,
        ]);
        $this->assertEquals(0, (int) MileageTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->sum('remaining_amount'));
    }

    /**
     * 쿠폰·마일리지·장바구니가 걸린 결제 대기 카드 주문 픽스처를 만든다.
     *
     * @return array{user: User, order: Order, product: Product, productOption: ProductOption, cart: Cart, couponIssue: CouponIssue} 픽스처 모델 묶음
     */
    private function createPendingCardOrderFixture(): array
    {
        $user = User::factory()->create();
        $product = ProductFactory::new()->create([
            'name' => ['ko' => 'KCP 통합 테스트 상품', 'en' => 'KCP Integration Product'],
            'list_price' => 30000,
            'selling_price' => 30000,
            'currency_code' => 'KRW',
            'stock_quantity' => 10,
            'has_options' => true,
        ]);
        $productOption = ProductOptionFactory::new()->forProduct($product)->create([
            'option_code' => 'KCP-SIDE-EFFECTS-OPTION',
            'option_name' => ['ko' => '기본', 'en' => 'Default'],
            'price_adjustment' => 0,
            'list_price' => 30000,
            'selling_price' => 30000,
            'currency_code' => 'KRW',
            'stock_quantity' => 10,
        ]);
        $cart = CartFactory::new()->forUser($user)->forOption($productOption)->withQuantity(2)->create();
        $couponIssue = $this->createUsedCouponIssue($user);

        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 2000,
            'remaining_amount' => 2000,
            'balance_after' => 2000,
            'description' => 'NHN KCP callback side effect fixture',
        ]);

        $order = OrderFactory::new()->forUser($user)->create([
            'order_number' => 'ORD-KCP-SIDE-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => 60000,
            'total_discount_amount' => 3000,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 3000,
            'total_coupon_discount_amount' => 3000,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => 57000,
            'total_tax_amount' => 55000,
            'total_vat_amount' => 5000,
            'total_tax_free_amount' => 0,
            'total_points_used_amount' => 2000,
            'is_mileage_deducted' => false,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
            'total_due_amount' => 55000,
            'total_earned_points_amount' => 0,
            'item_count' => 1,
            'order_meta' => [
                'cart_items' => [
                    [
                        'cart_id' => $cart->id,
                        'product_id' => $product->id,
                        'product_option_id' => $productOption->id,
                        'quantity' => 2,
                    ],
                ],
                'promotions' => [
                    'coupon_issue_ids' => [$couponIssue->id],
                    'order_promotions' => [
                        'coupons' => [[
                            'coupon_issue_id' => $couponIssue->id,
                            'applied_amount' => 3000,
                        ]],
                    ],
                ],
            ],
        ]);

        OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $product->id,
            'product_option_id' => $productOption->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
            'quantity' => 2,
            'unit_price' => 30000,
            'subtotal_price' => 60000,
            'subtotal_discount_amount' => 3000,
            'coupon_discount_amount' => 3000,
            'product_coupon_discount_amount' => 0,
            'order_coupon_discount_amount' => 3000,
            'code_discount_amount' => 0,
            'subtotal_points_used_amount' => 2000,
            'subtotal_paid_amount' => 55000,
            'subtotal_tax_amount' => 55000,
            'subtotal_tax_free_amount' => 0,
            'subtotal_earned_points_amount' => 0,
            'is_stock_deducted' => false,
        ]);

        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::READY,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => null,
            'merchant_order_id' => 'MO-'.$order->order_number,
            'payment_method' => PaymentMethodEnum::CARD,
            'paid_amount_local' => 0,
            'paid_amount_base' => 0,
            'vat_amount' => 5000,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'card_name' => null,
            'card_number_masked' => null,
            'card_approval_number' => null,
            'receipt_url' => null,
            'payment_meta' => null,
            'paid_at' => null,
        ]);

        $couponIssue->update(['order_id' => $order->id]);

        return compact('user', 'order', 'product', 'productOption', 'cart', 'couponIssue');
    }

    /**
     * 주문 금액 정액 할인 쿠폰과 그 쿠폰의 사용 처리된 발급 건을 만든다.
     *
     * @param  User  $user  쿠폰을 받은 회원
     * @return CouponIssue 사용 처리된 쿠폰 발급 건
     */
    private function createUsedCouponIssue(User $user): CouponIssue
    {
        $coupon = Coupon::create([
            'name' => ['ko' => 'KCP 통합 테스트 쿠폰', 'en' => 'KCP Integration Coupon'],
            'description' => ['ko' => 'KCP 통합 테스트용', 'en' => 'For KCP integration test'],
            'target_type' => CouponTargetType::ORDER_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 3000,
            'discount_max_amount' => 3000,
            'min_order_amount' => 0,
            'issue_method' => CouponIssueMethod::DIRECT,
            'issue_condition' => CouponIssueCondition::MANUAL,
            'issue_status' => CouponIssueStatus::ISSUING,
            'total_quantity' => 100,
            'issued_count' => 1,
            'per_user_limit' => 1,
            'valid_type' => 'period',
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addMonth(),
            'issue_from' => now()->subDay(),
            'issue_to' => now()->addMonth(),
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL,
        ]);

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'KCP-SIDE-'.strtoupper(uniqid()),
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now()->subHour(),
            'expired_at' => now()->addMonth(),
            'used_at' => now(),
            'discount_amount' => 3000,
        ]);
    }

    /**
     * 테스트 모드 site_cd 와 리다이렉트 경로를 담은 플러그인 설정을 mock 으로 주입한다.
     *
     * @param  array<string, mixed>  $overrides  기본값을 덮어쓸 설정
     * @return void 반환값 없음
     */
    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode' => true,
            'test_site_cd' => self::TEST_SITE_CD,
            'test_site_key' => self::TEST_SITE_KEY,
            'live_site_cd' => '',
            'live_site_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];

        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn(array_merge($defaults, $overrides));
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * NhnKcpApiService::approvePayment() 가 CLI 실행 없이 주어진 응답을 돌려주도록 mock 을 주입한다.
     *
     * @param  array<string, mixed>  $cliResponse  approvePayment 가 돌려줄 응답
     * @return void 반환값 없음
     */
    private function mockApiService(array $cliResponse): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('approvePayment')->willReturn($cliResponse);
        $this->app->instance(NhnKcpApiService::class, $mock);
    }

    /**
     * KCP CLI 정상 승인 응답을 흉내 낸 배열을 만든다.
     *
     * @param  string  $tno  거래번호
     * @param  string  $ordrIdxx  주문번호
     * @param  int  $amount  승인 금액
     * @return array<string, mixed> CLI 승인 응답
     */
    private function makeCliResponse(string $tno, string $ordrIdxx, int $amount): array
    {
        return [
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => $tno,
            'ordr_idxx' => $ordrIdxx,
            'good_mny' => $amount,
            // 서버-서버 승인 응답의 승인 금액 — 결제완료 확정의 유일한 금액 근거
            'amount' => (string) $amount,
            'app_no' => 'APP12345',
            'card_no' => '4330****1234',
            'card_name' => '신한카드',
            'quota' => '00',
            'use_pay_method' => 'CARD',
            'app_time' => now()->format('YmdHis'),
        ];
    }

    /**
     * 결제창이 인증 콜백으로 보내는 기본 파라미터를 만든다.
     *
     * @param  string  $ordrIdxx  주문번호
     * @param  int  $goodMny  결제창 금액
     * @param  array<string, mixed>  $overrides  덮어쓸 파라미터
     * @return array<string, mixed> 콜백 파라미터
     */
    private function makeCallbackParams(string $ordrIdxx, int $goodMny, array $overrides = []): array
    {
        return array_merge([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => 'KCP_TNO_'.uniqid(),
            'ordr_idxx' => $ordrIdxx,
            'good_mny' => $goodMny,
            'enc_data' => base64_encode('encrypted_payment_data'),
            'enc_info' => base64_encode('encrypted_info'),
            'use_pay_method' => 'CARD',
        ], $overrides);
    }

    /**
     * KRW 단일 통화 주문의 통화 스냅샷을 돌려준다.
     *
     * @return array<string, mixed> 통화 스냅샷
     */
    private static function krwCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    /**
     * 마일리지 사용 훅에 이커머스 마일리지 거래 리스너를 건다.
     *
     * @return void 반환값 없음
     */
    private function registerMileageTransactionListener(): void
    {
        $listener = app(MileageTransactionListener::class);
        HookManager::addAction('sirsoft-ecommerce.mileage.use', [$listener, 'handleUse'], 10);
    }

    /**
     * 테스트가 건 훅이 다음 테스트로 새지 않도록 훅 매니저의 등록 상태를 저장한다.
     *
     * @return void 반환값 없음
     */
    private function snapshotHookManager(): void
    {
        $ref = new \ReflectionClass(HookManager::class);

        $hooks = $ref->getProperty('hooks');
        $hooks->setAccessible(true);
        $filters = $ref->getProperty('filters');
        $filters->setAccessible(true);
        $dispatching = $ref->getProperty('dispatching');
        $dispatching->setAccessible(true);

        $this->hookSnapshot = [
            'hooks' => $hooks->getValue(),
            'filters' => $filters->getValue(),
            'dispatching' => $dispatching->getValue(),
        ];
    }

    /**
     * 저장해 둔 훅 매니저 등록 상태를 되돌리고 스냅샷을 비운다.
     *
     * @return void 반환값 없음
     */
    private function restoreHookManager(): void
    {
        if ($this->hookSnapshot === null) {
            return;
        }

        $ref = new \ReflectionClass(HookManager::class);

        $hooks = $ref->getProperty('hooks');
        $hooks->setAccessible(true);
        $hooks->setValue(null, $this->hookSnapshot['hooks']);

        $filters = $ref->getProperty('filters');
        $filters->setAccessible(true);
        $filters->setValue(null, $this->hookSnapshot['filters']);

        $dispatching = $ref->getProperty('dispatching');
        $dispatching->setAccessible(true);
        $dispatching->setValue(null, $this->hookSnapshot['dispatching']);

        $this->hookSnapshot = null;
    }
}
