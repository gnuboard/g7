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
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 나이스페이먼츠 영수증 조회 API(UserReceiptController) 테스트
 *
 * 회원·비회원이 같은 주소로 영수증 주소를 받는다. 비회원은 조회 토큰 헤더 또는 결제 직후 쿠키로만
 * 통과하고, 그 밖에는 모두 404 로 대상 없음 처리한다. 마이페이지 「결제 영수증」 링크는 레이아웃에
 * 결제사 주소를 적지 않고 이 응답의 `payment_receipt_url` 을 받아 쓴다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/user-receipt-lookup.yaml
 */
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

        // 운영에서는 PayNicepaymentsServiceProvider::boot 가 동일하게 등록한다.
        EncryptCookies::except(['nicepayments_receipt_token']);
    }

    /**
     * 조회 토큰이 없거나 위조되면 비회원 영수증을 대상 없음으로 처리한다.
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
        $this->createNicepaymentsPayment($order, 'NICE_TID_GUEST_001');

        $this->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();

        $this->withHeader('X-Guest-Order-Token', (time() + 1800).'|'.str_repeat('0', 64))
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }

    /**
     * 유효한 조회 토큰 헤더면 매출전표 주소와 마이페이지 결제 영수증 주소를 함께 준다.
     *
     * @scenario viewer=guest_header_token
     *
     * @effects receipt_urls_returned, payment_receipt_link_url_returned, response_uses_common_envelope
     *
     * @return void 반환값 없음
     */
    public function test_guest_receipt_returns_receipt_url_with_valid_header_token(): void
    {
        app()->setLocale('ko');

        $order = $this->createGuestOrder();
        $this->createNicepaymentsPayment($order, 'NICE_TID_GUEST_002');

        $response = $this->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order))
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt_url', 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID=NICE_TID_GUEST_002')
            // 마이페이지 「결제 영수증」 링크 주소 — 레이아웃이 외부 주소를 적지 않고 이 값을 받아 쓴다(첫 출시부터의 type=2 유지)
            ->assertJsonPath('data.payment_receipt_url', 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=2&TID=NICE_TID_GUEST_002');

        $this->assertContains($response->json('data.payment_method_label'), ['신용카드', 'Credit Card']);
    }

    /**
     * 결제 직후 발급한 영수증 쿠키로도 영수증 주소를 받는다.
     *
     * @scenario viewer=guest_cookie
     *
     * @effects receipt_urls_returned
     *
     * @return void 반환값 없음
     */
    public function test_guest_receipt_returns_receipt_url_with_valid_cookie(): void
    {
        $order = $this->createGuestOrder();
        $this->createNicepaymentsPayment($order, 'NICE_TID_GUEST_003');

        $this->withUnencryptedCookie('nicepayments_receipt_token', $this->issueReceiptCookie($order->order_number))
            ->withCredentials()
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt_url', 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID=NICE_TID_GUEST_003');
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
        $this->createNicepaymentsPayment($order1, 'NICE_TID_GUEST_004');
        $this->createNicepaymentsPayment($order2, 'NICE_TID_GUEST_005');

        $this->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order1))
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order2->order_number}/receipt")
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
        $this->createNicepaymentsPayment($order, 'NICE_TID_GUEST_006');

        $this->actingAs($user)
            ->withHeader('X-Guest-Order-Token', $this->issueGuestToken($order))
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }

    /**
     * 결제를 마친 비회원 주문을 만듭니다.
     *
     * @return Order 비회원 주문
     */
    private function createGuestOrder(): Order
    {
        return OrderFactory::new()->create([
            'user_id' => null,
            'guest_lookup_password_hash' => Hash::make('test1234'),
            'order_number' => 'ORD-NICE-GUEST-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);
    }

    /**
     * 주문에 나이스페이먼츠 카드 결제 행을 붙입니다(저장된 영수증 주소 없음).
     *
     * @param  Order  $order  주문
     * @param  string  $transactionId  거래번호
     * @return void 반환값 없음
     */
    private function createNicepaymentsPayment(Order $order, string $transactionId): void
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

    /**
     * 주문의 비회원 조회 토큰을 발급합니다(서비스의 서명 규칙을 그대로 쓴다).
     *
     * @param  Order  $order  비회원 주문
     * @return string 조회 토큰
     */
    private function issueGuestToken(Order $order): string
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
     * 결제 직후 발급되는 영수증 쿠키 값을 만듭니다.
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
