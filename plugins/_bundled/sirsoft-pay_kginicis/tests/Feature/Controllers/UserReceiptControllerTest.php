<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class UserReceiptControllerTest extends PluginTestCase
{
    /**
     * 영수증 쿠키를 암호화 대상에서 빼고 요청 언어를 한국어로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 영수증 cookie 가 EncryptCookies 미들웨어에서 폐기되지 않도록 명시 등록.
        // production 에서는 PayKginicisServiceProvider::boot 가 동일하게 등록.
        EncryptCookies::except(['kginicis_receipt_token']);

        // 응답의 표시 이름은 요청 언어를 따른다 — 이 클래스는 한국어 이름을 검증한다(다른 언어는 UserReceiptLabelLocaleTest).
        $this->withHeaders(['Accept-Language' => 'ko']);
    }

    /**
     * 간편결제 주문이면 결제수단 표시 이름에 간편결제 이름을 붙여 준다.
     *
     * @scenario viewer=member_session, payment=easy_pay
     *
     * @effects easy_pay_display_label_returned, inicis_receipt_url_returned, response_uses_common_envelope
     *
     * @return void 반환값 없음
     */
    public function test_receipt_response_includes_easy_pay_display_label(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RECEIPT-'.random_int(10000, 99999),
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
            ->assertJsonPath('data.receipt_type', 'inicis_receipt')
            ->assertJsonPath('data.receipt_url', 'https://iniweb.inicis.com/DefaultWebApp/mall/cr/cm/mCmReceipt_head.jsp?noTid=StdpayCARDINIpayTest20260521124857685014&noMethod=1')
            ->assertJsonPath('data.payment_method_label', '신용카드')
            ->assertJsonPath('data.payment_method_display_label', '네이버페이 (신용카드)')
            ->assertJsonPath('data.selected_payment_method', 'kginicis_naverpay')
            ->assertJsonPath('data.embedded_pg_provider', 'naverpay')
            ->assertJsonPath('data.embedded_pg_provider_label', '네이버페이');
    }

    /**
     * 간편결제 정보가 없으면 기본 결제수단 이름을 그대로 쓴다.
     *
     * @scenario viewer=member_session, payment=domestic_card
     *
     * @effects base_payment_label_kept
     *
     * @return void 반환값 없음
     */
    public function test_receipt_response_keeps_base_payment_label_without_easy_pay_context(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RECEIPT-'.random_int(10000, 99999),
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
            ->assertJsonPath('data.payment_method_label', '신용카드')
            ->assertJsonPath('data.payment_method_display_label', '신용카드')
            ->assertJsonPath('data.embedded_pg_provider', null)
            ->assertJsonPath('data.embedded_pg_provider_label', null);
    }

    /**
     * 입금 대기 가상계좌 주문은 은행코드를 함께 준다 — 저장된 코드가 없으면 은행 이름으로 찾는다.
     *
     * 주문 상세 입금 안내 조각(user_order_show)이 관리자에게만 보여 주는 값이다. 예전에는 화면 스크립트가
     * 은행 이름표를 들고 있었으나 조각으로 옮기며 서버가 계산한다. 화면만 is_admin 으로 숨기고 응답에는 누구에게나
     * 실렸으므로(7.1.0 abilities 게이트 basic-shop-mypage #7) 주문 조회 권한이 있는 관리자에게만 싣는다.
     *
     * @scenario viewer=member_session, payment=vbank_waiting
     *
     * @effects vbank_bank_code_returned
     *
     * @return void 반환값 없음
     */
    public function test_receipt_response_includes_vbank_bank_code_for_waiting_vbank(): void
    {
        $this->mockPluginSettings();
        $user = $this->userWithOrderReadPermission();

        foreach ([['88', '신한은행', '88'], [null, '국민은행', '04'], [null, '알 수 없는 은행', null]] as [$storedCode, $bankName, $expected]) {
            $order = OrderFactory::new()->create([
                'user_id' => $user->id,
                'order_number' => 'ORD-VBANK-'.random_int(10000, 99999),
                'order_status' => OrderStatusEnum::PENDING_PAYMENT,
                'total_amount' => 1000,
                'total_due_amount' => 1000,
                'total_paid_amount' => 0,
            ]);

            OrderPaymentFactory::new()->create([
                'order_id' => $order->id,
                'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
                'payment_method' => PaymentMethodEnum::VBANK,
                'pg_provider' => 'kginicis',
                'transaction_id' => 'StdpayVBNKINIpayTest'.random_int(100000, 999999),
                'vbank_code' => $storedCode,
                'vbank_name' => $bankName,
                'vbank_number' => '56211234567890',
                'paid_amount_local' => 0,
                'payment_meta' => [],
            ]);

            $this->actingAs($user)
                ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
                ->assertOk()
                ->assertJsonPath('data.receipt_type', 'inicis_receipt')
                ->assertJsonPath('data.vbank_bank_code', $expected);
        }
    }

    /**
     * 주문 조회 권한이 없는 일반 회원에게는 은행코드를 싣지 않는다(화면 숨김이 아니라 응답에서 뺀다).
     *
     * @scenario viewer=member_session, payment=vbank_waiting
     *
     * @effects vbank_bank_code_withheld_from_member
     *
     * @return void 반환값 없음
     */
    public function test_receipt_response_withholds_vbank_bank_code_from_member(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-VBANK-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'total_amount' => 1000,
            'total_due_amount' => 1000,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'StdpayVBNKINIpayTest'.random_int(100000, 999999),
            'vbank_code' => '88',
            'vbank_name' => '신한은행',
            'vbank_number' => '56211234567890',
            'paid_amount_local' => 0,
            'payment_meta' => [],
        ]);

        $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('data.vbank_bank_code', null);
    }

    /**
     * 주문 조회 권한(sirsoft-ecommerce.orders.read)을 가진 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function userWithOrderReadPermission(): User
    {
        $user = User::factory()->create();
        $role = Role::create(['identifier' => 'kg_order_reader_'.uniqid(), 'name' => ['ko' => '주문 조회', 'en' => 'Order Reader'], 'is_active' => true]);
        $role->permissions()->attach(Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.orders.read'],
            ['name' => ['ko' => '주문 조회', 'en' => 'Orders Read'], 'type' => 'admin']
        )->id);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * Bearer 토큰으로 로그인한 회원도 본인 주문 영수증을 받는다.
     *
     * @scenario viewer=member_bearer, payment=domestic_card
     *
     * @effects inicis_receipt_url_returned
     *
     * @return void 반환값 없음
     */
    public function test_receipt_response_allows_sanctum_bearer_token(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-BEARER-RECEIPT-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);

        $this->createKginicisPayment($order, 'StdpayCARDINIpayTest20260605110631608277');

        $token = $user->createToken('kginicis-receipt-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt_type', 'inicis_receipt')
            ->assertJsonPath('data.payment_method_label', '신용카드');
    }

    /**
     * 일본(CBT) 카드 결제는 매출전표 주소 없이 결제확인서 항목을 준다(내부 식별값은 싣지 않는다).
     *
     * @scenario viewer=member_session, payment=cbt_card
     *
     * @effects cbt_confirmation_returned_without_inicis_url
     *
     * @return void 반환값 없음
     */
    public function test_cbt_receipt_response_returns_internal_confirmation_data_without_inicis_receipt_url(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-CBT-RECEIPT-'.random_int(10000, 99999),
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
            ->assertJsonPath('data.receipt_type', 'cbt_confirmation')
            ->assertJsonPath('data.receipt_url', null)
            ->assertJsonPath('data.receipt_label', '결제확인')
            ->assertJsonPath('data.receipt_view_label', '결제확인서 보기')
            ->assertJsonPath('data.payment_method_label', '신용카드 (일본 CBT)')
            ->assertJsonPath('data.payment_method_display_label', '신용카드 (일본 CBT)')
            ->assertJsonFragment(['label' => '결제수단', 'value' => '신용카드 (일본 CBT)'])
            ->assertJsonFragment(['label' => '거래번호', 'value' => 'INIJPGCARDCBTTEST00120260522151050916864'])
            ->assertJsonFragment(['label' => '카드 승인번호', 'value' => '0679589'])
            ->assertJsonMissing(['label' => 'CBT MID', 'value' => 'CBTTEST001'])
            ->assertJsonMissing(['label' => 'SID', 'value' => 'CBTTEST00120260522er9rLrYt5']);
    }

    /**
     * 일본 편의점 결제가 입금 대기면 입금정보 라벨과 입금예정금액을 준다.
     *
     * @scenario viewer=member_session, payment=cbt_cvs_waiting
     *
     * @effects cbt_cvs_waiting_uses_cvs_labels
     *
     * @return void 반환값 없음
     */
    public function test_cbt_cvs_waiting_deposit_receipt_response_uses_cvs_label_and_due_amount(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-CBT-CVS-'.random_int(10000, 99999),
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
            ->assertJsonPath('data.receipt_type', 'cbt_confirmation')
            ->assertJsonPath('data.receipt_url', null)
            ->assertJsonPath('data.receipt_label', '입금정보')
            ->assertJsonPath('data.receipt_view_label', '편의점 입금정보 보기')
            ->assertJsonPath('data.payment_method_label', '일본 편의점결제')
            ->assertJsonPath('data.payment_method_display_label', '일본 편의점결제')
            ->assertJsonPath('data.cbt_pay_method', 'CVS')
            ->assertJsonPath('data.payment_status', 'waiting_deposit')
            ->assertJsonFragment(['label' => '입금예정금액', 'value' => '10 JPY'])
            ->assertJsonFragment(['label' => '입금 상태', 'value' => '입금대기'])
            ->assertJsonFragment(['label' => '편의점 코드', 'value' => '00007'])
            ->assertJsonFragment(['label' => '편의점 확인번호', 'value' => '999999999999999999'])
            ->assertJsonFragment(['label' => '편의점 접수번호', 'value' => '1779433713966'])
            ->assertJsonFragment(['label' => '입금 마감일시', 'value' => '2026-05-27 23:59:59'])
            ->assertJsonMissing(['label' => 'CBT MID', 'value' => 'CBTTEST001'])
            ->assertJsonMissing(['label' => 'SID', 'value' => 'CBTTEST00120260522M280RzeXo']);
    }

    /**
     * 조회 토큰이 없거나 위조되면 비회원 영수증을 대상 없음으로 처리한다.
     *
     * @scenario viewer=guest_invalid_token, payment=domestic_card
     *
     * @effects lookup_denied_as_not_found
     *
     * @return void 반환값 없음
     */
    public function test_guest_receipt_requires_valid_token(): void
    {
        $this->mockPluginSettings();
        $order = $this->createGuestOrder();
        $this->createKginicisPayment($order, 'StdpayCARDINIpayTest20260605110631608277');

        // 토큰 헤더 없음 → 404
        $this->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();

        // 변조 토큰 → 404
        $this->withHeader('X-Guest-Order-Token', (time() + 1800).'|'.str_repeat('0', 64))
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertNotFound();
    }

    /**
     * 유효한 조회 토큰이면 비회원에게도 매출전표 주소를 준다.
     *
     * @scenario viewer=guest_valid_token, payment=domestic_card
     *
     * @effects inicis_receipt_url_returned
     *
     * @return void 반환값 없음
     */
    public function test_guest_receipt_returns_inicis_receipt_url_with_valid_token(): void
    {
        $this->mockPluginSettings();
        $order = $this->createGuestOrder();
        $this->createKginicisPayment($order, 'StdpayCARDINIpayTest20260605110631608277');

        $token = $this->issueGuestToken($order);

        $this->withHeader('X-Guest-Order-Token', $token)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt_type', 'inicis_receipt')
            ->assertJsonPath('data.receipt_url', 'https://iniweb.inicis.com/DefaultWebApp/mall/cr/cm/mCmReceipt_head.jsp?noTid=StdpayCARDINIpayTest20260605110631608277&noMethod=1')
            ->assertJsonPath('data.payment_method_label', '신용카드');
    }

    /**
     * 다른 주문의 조회 토큰으로는 영수증을 받을 수 없다.
     *
     * @scenario viewer=guest_other_order_token, payment=domestic_card
     *
     * @effects lookup_denied_as_not_found
     *
     * @return void 반환값 없음
     */
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

    /**
     * 로그인한 회원은 비회원 조회 토큰을 실어도 비회원 주문 영수증을 받을 수 없다(회원 분기가 먼저다).
     *
     * @scenario viewer=member_with_guest_token, payment=domestic_card
     *
     * @effects lookup_denied_as_not_found
     *
     * @return void 반환값 없음
     */
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
            'order_number' => 'ORD-GUEST-RECEIPT-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);
    }

    /**
     * 주문에 KG이니시스 카드 결제 행을 붙입니다.
     *
     * @param  Order  $order  주문
     * @param  string  $transactionId  거래번호
     * @return void 반환값 없음
     */
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
     * 플러그인 설정을 테스트 모드로 고정합니다.
     *
     * @return void 반환값 없음
     */
    private function mockPluginSettings(): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn(['is_test_mode' => true]);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }
}
