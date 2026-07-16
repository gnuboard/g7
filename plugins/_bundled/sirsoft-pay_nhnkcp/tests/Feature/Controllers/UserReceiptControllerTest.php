<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class UserReceiptControllerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 운영에서는 PayNhnkcpServiceProvider::boot 가 동일하게 등록한다.
        EncryptCookies::except(['nhnkcp_receipt_token']);
    }

    public function test_receipt_response_includes_easy_pay_display_label(): void
    {
        app()->setLocale('ko');

        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-KCP-RECEIPT-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 50000,
            'total_due_amount' => 0,
            'total_paid_amount' => 50000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => 'KCP_TNO_NAVERPAY',
            'embedded_pg_provider' => 'naverpay',
            'paid_amount_local' => 50000,
            'paid_at' => now(),
            'payment_meta' => [
                'nhnkcp_easy_pay_method' => 'nhnkcp_naverpay',
                'nhnkcp_easy_pay_provider' => 'naverpay',
                'nhnkcp_easy_pay_label' => [
                    'ko' => '네이버페이',
                    'en' => 'NaverPay',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('selected_payment_method', 'nhnkcp_naverpay')
            ->assertJsonPath('embedded_pg_provider', 'naverpay');

        $data = $response->json();
        $this->assertContains($data['embedded_pg_provider_label'] ?? null, ['네이버페이', 'NaverPay']);
        $this->assertSame(
            ($data['embedded_pg_provider_label'] ?? '') . ' (' . ($data['payment_method_label'] ?? '') . ')',
            $data['payment_method_display_label'] ?? null,
        );
    }

    public function test_guest_receipt_requires_valid_token(): void
    {
        $order = $this->createGuestOrder();
        $this->createKcpPayment($order, 'KCP_TNO_GUEST_001');

        $this->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();

        $this->withHeader('X-Guest-Order-Token', (time() + 1800).'|'.str_repeat('0', 64))
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }

    public function test_guest_receipt_returns_receipt_url_with_valid_header_token(): void
    {
        app()->setLocale('ko');

        $order = $this->createGuestOrder();
        $this->createKcpPayment($order, 'KCP_TNO_GUEST_002');

        $response = $this->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order))
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('receipt_url', 'https://testadmin8.kcp.co.kr/assist/bill.BillActionNew.do?cmd=card_bill&tno=KCP_TNO_GUEST_002&order_no='.$order->order_number.'&trade_mony=1000');

        $this->assertContains($response->json('payment_method_label'), ['신용카드', 'Credit Card']);
    }

    public function test_guest_receipt_returns_receipt_url_with_valid_cookie(): void
    {
        $order = $this->createGuestOrder();
        $this->createKcpPayment($order, 'KCP_TNO_GUEST_003');

        $this->withUnencryptedCookie('nhnkcp_receipt_token', $this->issueReceiptCookie($order->order_number))
            ->withCredentials()
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('receipt_url', 'https://testadmin8.kcp.co.kr/assist/bill.BillActionNew.do?cmd=card_bill&tno=KCP_TNO_GUEST_003&order_no='.$order->order_number.'&trade_mony=1000');
    }

    public function test_guest_token_for_other_order_cannot_access_receipt(): void
    {
        $order1 = $this->createGuestOrder();
        $order2 = $this->createGuestOrder();
        $this->createKcpPayment($order1, 'KCP_TNO_GUEST_004');
        $this->createKcpPayment($order2, 'KCP_TNO_GUEST_005');

        $this->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order1))
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order2->order_number}/receipt")
            ->assertNotFound();
    }

    public function test_authenticated_user_cannot_access_guest_order_receipt_via_token(): void
    {
        $user = User::factory()->create();
        $order = $this->createGuestOrder();
        $this->createKcpPayment($order, 'KCP_TNO_GUEST_006');

        $this->actingAs($user)
            ->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order))
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }

    private function createGuestOrder()
    {
        return OrderFactory::new()->create([
            'user_id' => null,
            'guest_lookup_password_hash' => Hash::make('test1234'),
            'order_number' => 'ORD-KCP-GUEST-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);
    }

    private function createKcpPayment($order, string $transactionId): void
    {
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => $transactionId,
            'paid_amount_local' => 1000,
            'paid_at' => now(),
            'payment_meta' => [],
        ]);
    }

    private function issueGuestToken($order): string
    {
        $svc = app(GuestOrderAuthService::class);
        $rc = new \ReflectionClass($svc);
        $signMethod = $rc->getMethod('sign');
        $signMethod->setAccessible(true);
        $suffixMethod = $rc->getMethod('passwordHashSuffix');
        $suffixMethod->setAccessible(true);

        $expiresTs = time() + 1800;
        $suffix = $suffixMethod->invoke($svc, $order);
        $sig = $signMethod->invoke($svc, $order->order_number, (int) $order->id, $expiresTs, $suffix);

        return $expiresTs.'|'.$sig;
    }

    private function issueReceiptCookie(string $orderNumber): string
    {
        $expiresTs = time() + 300;
        $payload = $orderNumber.'|'.$expiresTs;
        $signature = hash_hmac('sha256', $payload, (string) config('app.key', ''));

        return $payload.'|'.$signature;
    }
}
