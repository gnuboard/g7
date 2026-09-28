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
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class UserReceiptControllerTest extends PluginTestCase
{
    /**
     * 영수증 쿠키를 암호화 대상에서 뺍니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 운영에서는 PayNhnkcpServiceProvider::boot 가 동일하게 등록한다.
        EncryptCookies::except(['nhnkcp_receipt_token']);
    }

    /**
     * 간편결제 주문이면 결제수단 표시 이름에 간편결제 이름을 붙여 준다(응답은 공통 형식의 data).
     *
     * @scenario viewer=member_session
     *
     * @effects easy_pay_display_label_returned, response_uses_common_envelope
     *
     * @return void 반환값 없음
     */
    public function test_receipt_response_includes_easy_pay_display_label(): void
    {
        app()->setLocale('ko');

        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-KCP-RECEIPT-'.random_int(10000, 99999),
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
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.selected_payment_method', 'nhnkcp_naverpay')
            ->assertJsonPath('data.embedded_pg_provider', 'naverpay')
            ->assertJsonMissingPath('receipt_url');

        $data = $response->json('data');
        $this->assertContains($data['embedded_pg_provider_label'] ?? null, ['네이버페이', 'NaverPay']);
        $this->assertSame(
            ($data['embedded_pg_provider_label'] ?? '').' ('.($data['payment_method_label'] ?? '').')',
            $data['payment_method_display_label'] ?? null,
        );
    }

    /**
     * 조회 토큰이 없거나 위조되면 비회원 영수증을 대상 없음(공통 형식 404)으로 처리한다.
     *
     * @scenario viewer=guest_invalid_token
     *
     * @effects lookup_denied_as_not_found
     *
     * @return void 반환값 없음
     */
    public function test_guest_receipt_requires_valid_token(): void
    {
        $order = $this->createGuestOrder();
        $this->createKcpPayment($order, 'KCP_TNO_GUEST_001');

        $this->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt")
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('error');

        $this->withHeader('X-Guest-Order-Token', (time() + 1800).'|'.str_repeat('0', 64))
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }

    /**
     * 유효한 조회 토큰 헤더면 비회원에게도 매출전표 주소를 준다.
     *
     * @scenario viewer=guest_valid_token
     *
     * @effects receipt_url_returned, response_uses_common_envelope
     *
     * @return void 반환값 없음
     */
    public function test_guest_receipt_returns_receipt_url_with_valid_header_token(): void
    {
        app()->setLocale('ko');

        $order = $this->createGuestOrder();
        $this->createKcpPayment($order, 'KCP_TNO_GUEST_002');

        $response = $this->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order))
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt_url', 'https://testadmin8.kcp.co.kr/assist/bill.BillActionNew.do?cmd=card_bill&tno=KCP_TNO_GUEST_002&order_no='.$order->order_number.'&trade_mony=1000');

        $this->assertContains($response->json('data.payment_method_label'), ['신용카드', 'Credit Card']);
    }

    /**
     * 결제 직후 발급된 영수증 쿠키로도 비회원에게 매출전표 주소를 준다.
     *
     * @scenario viewer=guest_receipt_cookie
     *
     * @effects receipt_url_returned
     *
     * @return void 반환값 없음
     */
    public function test_guest_receipt_returns_receipt_url_with_valid_cookie(): void
    {
        $order = $this->createGuestOrder();
        $this->createKcpPayment($order, 'KCP_TNO_GUEST_003');

        $this->withUnencryptedCookie('nhnkcp_receipt_token', $this->issueReceiptCookie($order->order_number))
            ->withCredentials()
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt_url', 'https://testadmin8.kcp.co.kr/assist/bill.BillActionNew.do?cmd=card_bill&tno=KCP_TNO_GUEST_003&order_no='.$order->order_number.'&trade_mony=1000');
    }

    /**
     * 다른 주문의 조회 토큰으로는 영수증을 받을 수 없다.
     *
     * @scenario viewer=guest_other_order_token
     *
     * @effects lookup_denied_as_not_found
     *
     * @return void 반환값 없음
     */
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

    /**
     * 로그인한 회원은 비회원 조회 토큰을 실어도 비회원 주문 영수증을 받을 수 없다(회원 분기가 먼저다).
     *
     * @scenario viewer=member_with_guest_token
     *
     * @effects lookup_denied_as_not_found
     *
     * @return void 반환값 없음
     */
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

    /**
     * 결제를 마친 비회원 주문을 만듭니다.
     *
     * @return Order 비회원 주문
     */
    private function createGuestOrder()
    {
        return OrderFactory::new()->create([
            'user_id' => null,
            'guest_lookup_password_hash' => Hash::make('test1234'),
            'order_number' => 'ORD-KCP-GUEST-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);
    }

    /**
     * 주문에 NHN KCP 카드 결제 행을 붙입니다.
     *
     * @param  Order  $order  주문
     * @param  string  $transactionId  거래번호
     * @return void 반환값 없음
     */
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

    /**
     * 주문의 비회원 조회 토큰을 발급합니다 — 전화번호 매칭(배송지 필요)을 우회하려고 서비스의 서명 규칙을 reflection 으로 직접 부른다.
     *
     * @param  Order  $order  비회원 주문
     * @return string 조회 토큰
     */
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

    /**
     * 결제 직후 PG 콜백이 발급하는 것과 같은 서명의 영수증 쿠키 값을 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return string 쿠키 값
     */
    private function issueReceiptCookie(string $orderNumber): string
    {
        $expiresTs = time() + 300;
        $payload = $orderNumber.'|'.$expiresTs;
        $signature = hash_hmac('sha256', $payload, (string) config('app.key', ''));

        return $payload.'|'.$signature;
    }
}
