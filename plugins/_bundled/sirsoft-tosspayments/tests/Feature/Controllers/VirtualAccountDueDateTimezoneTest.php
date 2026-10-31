<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Plugins\Sirsoft\Tosspayments\Support\ShopRedirectUrl;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 토스 가상계좌 입금 기한이 앱 시간대로 저장되는지 검증한다 (이커머스 전수 실측 S4 마감 검증 — S4-D24)
 *
 * 결함: 결제 승인 응답의 `virtualAccount.dueDate` 는 오프셋이 붙은 한국 시각(`2026-05-21T23:59:59+09:00`)인데,
 *   `Carbon::parse()` 결과를 그대로 저장하면 datetime 캐스트가 시간대를 바꾸지 않고 한국 시각 숫자를 UTC 칸에 넣어
 *   기한이 9시간 늦게 저장됐다(화면 표시·만료 판정 모두 어긋남). KCP·KG·나이스의 같은 결함(S4-D5)과 같은 계열이다.
 *
 * 같은 발급 경로의 후속 처리(장바구니 정리)도 여기서 고정한다 — 다른 결제사 3종은 발급 저장 직후 이커머스 발급
 * 후속 진입점을 부르는데 토스만 빠져 있었다.
 */
class VirtualAccountDueDateTimezoneTest extends PluginTestCase
{
    /**
     * 결제 대기 원화 주문 하나를 만듭니다.
     *
     * @return Order 주문
     */
    private function createOrder(): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-TOSS-DUE-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'KRW',
                'exchange_rate' => 1.0,
                'exchange_rates' => ['KRW' => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round']],
                'snapshot_at' => now()->toIso8601String(),
            ],
            'subtotal_amount' => 50000,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => 50000,
            'total_due_amount' => 50000,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'tosspayments',
            'currency' => 'KRW',
            'currency_snapshot' => ['KRW' => 1.0],
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
        ]);

        return $order;
    }

    /**
     * 플러그인 설정을 시험값으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    private function settings(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_mock_key',
            'test_client_key' => 'test_ck_mock_key',
            'redirect_success_url' => ShopRedirectUrl::DEFAULT_SUCCESS_URL,
            'redirect_fail_url' => ShopRedirectUrl::DEFAULT_FAIL_URL,
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 가상계좌 입금 기한(한국 시각 +09:00)을 앱 시간대(UTC)로 바꿔 저장한다.
     *
     * @scenario payment_method=toss_virtual_account, use_escrow=off
     *
     * @effects vbank_due_at_stored_in_app_timezone
     *
     * @return void 반환값 없음
     */
    public function test_virtual_account_due_date_is_stored_in_app_timezone(): void
    {
        $order = $this->createOrder();
        $this->settings();
        $paymentKey = 'pk_test_vbank_due_tz';

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => $paymentKey,
                'orderId' => $order->order_number,
                'status' => 'WAITING_FOR_DEPOSIT',
                'method' => '가상계좌',
                'totalAmount' => 50000,
                'secret' => 'wsec_due_tz',
                'virtualAccount' => [
                    'accountNumber' => '12345678901299',
                    'bankCode' => '20',
                    'bank' => '우리은행',
                    'customerName' => '홍길동',
                    'dueDate' => '2026-05-21T23:59:59+09:00',
                    'useEscrow' => false,
                ],
            ], 200),
        ]);

        $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $order->order_number,
            'amount' => 50000,
        ]))->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $raw = DB::table('ecommerce_order_payments')->where('order_id', $order->id)->value('vbank_due_at');
        $this->assertSame('2026-05-21 14:59:59', (string) $raw);
    }

    /**
     * 가상계좌 계좌가 발급되면 입금 전이라도 주문한 장바구니 항목을 정리한다 — KG·KCP·나이스와 같은 처리.
     * 종전에는 입금이 확인될 때까지 주문을 마친 고객의 장바구니에 같은 상품이 그대로 보였다.
     *
     * @scenario payment_method=toss_virtual_account, use_escrow=off
     *
     * @effects vbank_issue_clears_ordered_cart_items
     *
     * @return void 반환값 없음
     */
    public function test_virtual_account_issue_clears_ordered_cart_items(): void
    {
        $order = $this->createOrder();
        $this->settings();
        $paymentKey = 'pk_test_vbank_cart';

        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);
        $cart = Cart::create([
            'user_id' => $order->user_id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
        ]);
        $order->update([
            'order_meta' => array_merge($order->order_meta ?? [], ['cart_items' => [['cart_id' => $cart->id, 'quantity' => 1]]]),
        ]);

        // 하한 — 정리할 장바구니 행이 실제로 심어졌는지 먼저 확인한다.
        $this->assertDatabaseHas('ecommerce_carts', ['id' => $cart->id]);

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => $paymentKey,
                'orderId' => $order->order_number,
                'status' => 'WAITING_FOR_DEPOSIT',
                'method' => '가상계좌',
                'totalAmount' => 50000,
                'secret' => 'wsec_cart',
                'virtualAccount' => [
                    'accountNumber' => '12345678901300',
                    'bankCode' => '20',
                    'bank' => '우리은행',
                    'customerName' => '홍길동',
                    'dueDate' => '2026-05-21T23:59:59+09:00',
                    'useEscrow' => false,
                ],
            ], 200),
        ]);

        $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $order->order_number,
            'amount' => 50000,
        ]))->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertSame(
            PaymentStatusEnum::WAITING_DEPOSIT->value,
            DB::table('ecommerce_order_payments')->where('order_id', $order->id)->value('payment_status'),
        );
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }
}
