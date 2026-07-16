<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Extension\HookManager;
use App\Models\User;
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
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotHookManager();
        $this->registerMileageTransactionListener();
    }

    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

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
     * @return array{
     *     user: User,
     *     order: Order,
     *     product: \Modules\Sirsoft\Ecommerce\Models\Product,
     *     productOption: \Modules\Sirsoft\Ecommerce\Models\ProductOption,
     *     cart: \Modules\Sirsoft\Ecommerce\Models\Cart,
     *     couponIssue: CouponIssue
     * }
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
            'order_number' => 'ORD-KCP-SIDE-' . random_int(10000, 99999),
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
            'merchant_order_id' => 'MO-' . $order->order_number,
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
            'coupon_code' => 'KCP-SIDE-' . strtoupper(uniqid()),
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now()->subHour(),
            'expired_at' => now()->addMonth(),
            'used_at' => now(),
            'discount_amount' => 3000,
        ]);
    }

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

        $mock = $this->createMock(\App\Services\PluginSettingsService::class);
        $mock->method('get')->willReturn(array_merge($defaults, $overrides));
        $this->app->instance(\App\Services\PluginSettingsService::class, $mock);
    }

    private function mockApiService(array $cliResponse): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('approvePayment')->willReturn($cliResponse);
        $this->app->instance(NhnKcpApiService::class, $mock);
    }

    private function makeCliResponse(string $tno, string $ordrIdxx, int $amount): array
    {
        return [
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => $tno,
            'ordr_idxx' => $ordrIdxx,
            'good_mny' => $amount,
            'app_no' => 'APP12345',
            'card_no' => '4330****1234',
            'card_name' => '신한카드',
            'quota' => '00',
            'use_pay_method' => 'CARD',
            'app_time' => now()->format('YmdHis'),
        ];
    }

    private function makeCallbackParams(string $ordrIdxx, int $goodMny, array $overrides = []): array
    {
        return array_merge([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => 'KCP_TNO_' . uniqid(),
            'ordr_idxx' => $ordrIdxx,
            'good_mny' => $goodMny,
            'enc_data' => base64_encode('encrypted_payment_data'),
            'enc_info' => base64_encode('encrypted_info'),
            'use_pay_method' => 'CARD',
        ], $overrides);
    }

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

    private function registerMileageTransactionListener(): void
    {
        $listener = app(MileageTransactionListener::class);
        HookManager::addAction('sirsoft-ecommerce.mileage.use', [$listener, 'handleUse'], 10);
    }

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
