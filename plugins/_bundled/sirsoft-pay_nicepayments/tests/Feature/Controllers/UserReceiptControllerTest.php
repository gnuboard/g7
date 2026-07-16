<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class UserReceiptControllerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 운영에서는 PayNicepaymentsServiceProvider::boot 가 동일하게 등록한다.
        EncryptCookies::except(['nicepayments_receipt_token']);
    }

    public function test_guest_receipt_requires_valid_token(): void
    {
        $order = $this->createGuestOrder();
        $this->createNicepaymentsPayment($order, 'NICE_TID_GUEST_001');

        $this->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();

        $this->withHeader('X-Guest-Order-Token', (time() + 1800).'|'.str_repeat('0', 64))
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }

    public function test_guest_receipt_returns_receipt_url_with_valid_header_token(): void
    {
        app()->setLocale('ko');

        $order = $this->createGuestOrder();
        $this->createNicepaymentsPayment($order, 'NICE_TID_GUEST_002');

        $response = $this->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order))
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('receipt_url', 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID=NICE_TID_GUEST_002');

        $this->assertContains($response->json('payment_method_label'), ['신용카드', 'Credit Card']);
    }

    public function test_guest_receipt_returns_receipt_url_with_valid_cookie(): void
    {
        $order = $this->createGuestOrder();
        $this->createNicepaymentsPayment($order, 'NICE_TID_GUEST_003');

        $this->withUnencryptedCookie('nicepayments_receipt_token', $this->issueReceiptCookie($order->order_number))
            ->withCredentials()
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('receipt_url', 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID=NICE_TID_GUEST_003');
    }

    public function test_guest_token_for_other_order_cannot_access_receipt(): void
    {
        $order1 = $this->createGuestOrder();
        $order2 = $this->createGuestOrder();
        $this->createNicepaymentsPayment($order1, 'NICE_TID_GUEST_004');
        $this->createNicepaymentsPayment($order2, 'NICE_TID_GUEST_005');

        $this->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order1))
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order2->order_number}/receipt")
            ->assertNotFound();
    }

    public function test_authenticated_user_cannot_access_guest_order_receipt_via_token(): void
    {
        $user = User::factory()->create();
        $order = $this->createGuestOrder();
        $this->createNicepaymentsPayment($order, 'NICE_TID_GUEST_006');

        $this->actingAs($user)
            ->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order))
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }

    private function createGuestOrder()
    {
        return OrderFactory::new()->create([
            'user_id' => null,
            'guest_lookup_password_hash' => Hash::make('test1234'),
            'order_number' => 'ORD-NICE-GUEST-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);
    }

    private function createNicepaymentsPayment($order, string $transactionId): void
    {
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'transaction_id' => $transactionId,
            'receipt_url' => null,
            'paid_amount_local' => 1000,
            'paid_at' => now(),
            'payment_meta' => ['is_test_mode' => true],
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
