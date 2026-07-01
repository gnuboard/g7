<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class UserReceiptControllerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 영수증 cookie 가 EncryptCookies 미들웨어에서 폐기되지 않도록 명시 등록.
        // production 에서는 PayKginicisServiceProvider::boot 가 동일하게 등록.
        EncryptCookies::except(['kginicis_receipt_token']);
    }

    public function test_receipt_response_includes_easy_pay_display_label(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RECEIPT-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'StdpayCARDINIpayTest20260521124857685014',
            'embedded_pg_provider' => 'naverpay',
            'paid_amount_local' => 1000,
            'payment_meta' => [
                'selected_payment_method' => 'kginicis_naverpay',
                'embedded_pg_provider' => 'naverpay',
                'embedded_pg_provider_label' => '네이버페이',
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('receipt_type', 'inicis_receipt')
            ->assertJsonPath('receipt_url', 'https://iniweb.inicis.com/DefaultWebApp/mall/cr/cm/mCmReceipt_head.jsp?noTid=StdpayCARDINIpayTest20260521124857685014&noMethod=1')
            ->assertJsonPath('payment_method_label', '신용카드')
            ->assertJsonPath('payment_method_display_label', '네이버페이 (신용카드)')
            ->assertJsonPath('selected_payment_method', 'kginicis_naverpay')
            ->assertJsonPath('embedded_pg_provider', 'naverpay')
            ->assertJsonPath('embedded_pg_provider_label', '네이버페이');
    }

    public function test_receipt_response_keeps_base_payment_label_without_easy_pay_context(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RECEIPT-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'StdpayCARDINIpayTest20260521124857685014',
            'paid_amount_local' => 1000,
            'payment_meta' => ['pay_method' => 'Card'],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('payment_method_label', '신용카드')
            ->assertJsonPath('payment_method_display_label', '신용카드')
            ->assertJsonPath('embedded_pg_provider', null)
            ->assertJsonPath('embedded_pg_provider_label', null);
    }

    public function test_receipt_response_allows_sanctum_bearer_token(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-BEARER-RECEIPT-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);

        $this->createKginicisPayment($order, 'StdpayCARDINIpayTest20260605110631608277');

        $token = $user->createToken('kginicis-receipt-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('receipt_type', 'inicis_receipt')
            ->assertJsonPath('payment_method_label', '신용카드');
    }

    public function test_cbt_receipt_response_returns_internal_confirmation_data_without_inicis_receipt_url(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-CBT-RECEIPT-' . random_int(10000, 99999),
            'currency' => 'JPY',
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'INIJPGCARDCBTTEST00120260522151050916864',
            'paid_amount_local' => 1000,
            'currency' => 'JPY',
            'card_approval_number' => '0679589',
            'card_installment_months' => 0,
            'payment_meta' => [
                'result_code' => 'OK',
                'pay_method' => 'CARD',
                'cbt_type' => 'JPPG',
                'cbt_mid' => 'CBTTEST001',
                'cbt_sid' => 'CBTTEST00120260522er9rLrYt5',
                'currency' => 'JPY',
                'is_cbt' => true,
                'pg_approve_response' => [
                    'resultCode' => 'OK',
                    'tid' => 'INIJPGCARDCBTTEST00120260522151050916864',
                    'paymethod' => 'CARD',
                    'applDate' => '20260522',
                    'applTime' => '151205',
                    'approve' => '0679589',
                    'installMonth' => '00',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('receipt_type', 'cbt_confirmation')
            ->assertJsonPath('receipt_url', null)
            ->assertJsonPath('receipt_label', '결제확인')
            ->assertJsonPath('receipt_view_label', '결제확인서 보기')
            ->assertJsonPath('payment_method_label', '신용카드 (일본 CBT)')
            ->assertJsonPath('payment_method_display_label', '신용카드 (일본 CBT)')
            ->assertJsonFragment(['label' => '결제수단', 'value' => '신용카드 (일본 CBT)'])
            ->assertJsonFragment(['label' => '거래번호', 'value' => 'INIJPGCARDCBTTEST00120260522151050916864'])
            ->assertJsonFragment(['label' => '카드 승인번호', 'value' => '0679589'])
            ->assertJsonMissing(['label' => 'CBT MID', 'value' => 'CBTTEST001'])
            ->assertJsonMissing(['label' => 'SID', 'value' => 'CBTTEST00120260522er9rLrYt5']);
    }

    public function test_cbt_cvs_waiting_deposit_receipt_response_uses_cvs_label_and_due_amount(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-CBT-CVS-' . random_int(10000, 99999),
            'currency' => 'JPY',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'total_amount' => 10,
            'total_due_amount' => 10,
            'total_paid_amount' => 0,
            'paid_at' => null,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'INIJPGCVS_CBTTEST00120260522160833186429',
            'paid_amount_local' => 0,
            'currency' => 'JPY',
            'vbank_name' => 'CVS',
            'vbank_number' => '999999999999999999',
            'payment_meta' => [
                'result_code' => 'OK',
                'pay_method' => 'CVS',
                'cbt_type' => 'JPPG',
                'cbt_mid' => 'CBTTEST001',
                'cbt_sid' => 'CBTTEST00120260522M280RzeXo',
                'currency' => 'JPY',
                'is_cbt' => true,
                'cvs_amount' => 10,
                'cvs_convenience' => '00007',
                'cvs_conf_no' => '999999999999999999',
                'cvs_receipt_no' => '1779433713966',
                'cvs_payment_term' => '20260527235959',
                'pg_approve_response' => [
                    'resultCode' => 'OK',
                    'tid' => 'INIJPGCVS_CBTTEST00120260522160833186429',
                    'paymethod' => 'CVS',
                    'applDate' => '20260522',
                    'applTime' => '160834',
                    'convenience' => '00007',
                    'confNo' => '999999999999999999',
                    'receiptNo' => '1779433713966',
                    'paymentTerm' => '20260527235959',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('receipt_type', 'cbt_confirmation')
            ->assertJsonPath('receipt_url', null)
            ->assertJsonPath('receipt_label', '입금정보')
            ->assertJsonPath('receipt_view_label', '편의점 입금정보 보기')
            ->assertJsonPath('payment_method_label', '일본 편의점결제')
            ->assertJsonPath('payment_method_display_label', '일본 편의점결제')
            ->assertJsonPath('cbt_pay_method', 'CVS')
            ->assertJsonPath('payment_status', 'waiting_deposit')
            ->assertJsonFragment(['label' => '입금예정금액', 'value' => '10 JPY'])
            ->assertJsonFragment(['label' => '입금 상태', 'value' => '입금대기'])
            ->assertJsonFragment(['label' => '편의점 코드', 'value' => '00007'])
            ->assertJsonFragment(['label' => '편의점 확인번호', 'value' => '999999999999999999'])
            ->assertJsonFragment(['label' => '편의점 접수번호', 'value' => '1779433713966'])
            ->assertJsonFragment(['label' => '입금 마감일시', 'value' => '2026-05-27 23:59:59'])
            ->assertJsonMissing(['label' => 'CBT MID', 'value' => 'CBTTEST001'])
            ->assertJsonMissing(['label' => 'SID', 'value' => 'CBTTEST00120260522M280RzeXo']);
    }

    public function test_guest_receipt_requires_valid_token(): void
    {
        $this->mockPluginSettings();
        $order = $this->createGuestOrder();
        $this->createKginicisPayment($order, 'StdpayCARDINIpayTest20260605110631608277');

        // 토큰 헤더 없음 → 404
        $this->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();

        // 변조 토큰 → 404
        $this->withHeader('X-Guest-Order-Token', (time() + 1800) . '|' . str_repeat('0', 64))
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }

    public function test_guest_receipt_returns_inicis_receipt_url_with_valid_token(): void
    {
        $this->mockPluginSettings();
        $order = $this->createGuestOrder();
        $this->createKginicisPayment($order, 'StdpayCARDINIpayTest20260605110631608277');

        $token = $this->issueGuestToken($order);

        $this->withHeader('X-Guest-Order-Token', $token)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('receipt_type', 'inicis_receipt')
            ->assertJsonPath('receipt_url', 'https://iniweb.inicis.com/DefaultWebApp/mall/cr/cm/mCmReceipt_head.jsp?noTid=StdpayCARDINIpayTest20260605110631608277&noMethod=1')
            ->assertJsonPath('payment_method_label', '신용카드');
    }

    public function test_guest_token_for_other_order_cannot_access_receipt(): void
    {
        $this->mockPluginSettings();
        $order1 = $this->createGuestOrder();
        $order2 = $this->createGuestOrder();
        $this->createKginicisPayment($order1, 'StdpayCARDINIpayTest20260605110631608277');
        $this->createKginicisPayment($order2, 'StdpayCARDINIpayTest20260605110631608278');

        // order1 토큰을 들고 order2 영수증 조회 → 404 (cross-order token reuse 차단)
        $token1 = $this->issueGuestToken($order1);

        $this->withHeader('X-Guest-Order-Token', $token1)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order2->order_number}/receipt")
            ->assertNotFound();
    }

    public function test_authenticated_user_cannot_access_guest_order_receipt_via_token(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = $this->createGuestOrder();
        $this->createKginicisPayment($order, 'StdpayCARDINIpayTest20260605110631608277');

        // 회원이 로그인된 상태에서 비회원 토큰을 헤더에 들고 접근 → 회원 분기로 user_id 매칭 실패 404
        $token = $this->issueGuestToken($order);

        $this->actingAs($user)
            ->withHeader('X-Guest-Order-Token', $token)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }


    private function createGuestOrder()
    {
        return OrderFactory::new()->create([
            'user_id' => null,
            'guest_lookup_password_hash' => Hash::make('test1234'),
            'order_number' => 'ORD-GUEST-RECEIPT-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);
    }

    private function createKginicisPayment($order, string $transactionId): void
    {
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => $transactionId,
            'paid_amount_local' => 1000,
            'payment_meta' => ['pay_method' => 'Card'],
        ]);
    }

    /**
     * 전화번호 매칭(shipping_address 필요)을 우회하고 토큰을 직접 발급한다.
     * 컨트롤러의 토큰 검증 분기만 검증하기 위해 sign() private API 를 reflection 으로 호출.
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

        return $expiresTs . '|' . $sig;
    }

    private function mockPluginSettings(): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn(['is_test_mode' => true]);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }
}
