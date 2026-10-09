<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class MobileApprovalControllerTest extends PluginTestCase
{
    private const APPROVAL_KEY_ENDPOINT = '/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key';

    private const TEST_PAY_URL = 'https://testpay.kcp.co.kr/php/mobile/mc_pay_form.php';

    // ===== 헬퍼 =====

    /**
     * 결제 전 회원 주문과 카드 결제 행을 만듭니다. 과세·부가세·면세 금액을 지정할 수 있습니다.
     *
     * @param  int  $totalAmount  주문 총액(원)
     * @param  array{taxable?: int, vat?: int, taxFree?: int}  $tax  과세 합계·부가세·면세 금액
     * @return Order 만든 주문
     */
    private function createOrder(int $totalAmount = 10000, array $tax = []): Order
    {
        $taxable = $tax['taxable'] ?? $totalAmount;
        $vat = $tax['vat'] ?? (int) round($taxable * 10 / 110);
        $taxFree = $tax['taxFree'] ?? 0;

        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-MOB-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => $totalAmount,
            'total_amount' => $totalAmount,
            'total_due_amount' => $totalAmount,
            'total_paid_amount' => 0,
            'total_tax_amount' => $taxable,
            'total_vat_amount' => $vat,
            'total_tax_free_amount' => $taxFree,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
        ]);

        return $order;
    }

    /**
     * 원화 단일 통화 주문 스냅샷을 돌려줍니다.
     *
     * @return array<string, mixed> 주문 통화 스냅샷
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
     * 환율이 0 인(청구 불가) USD 결제 통화 스냅샷을 돌려줍니다.
     *
     * @return array<string, mixed> 주문 통화 스냅샷
     */
    private static function invalidUsdCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1000,
                ],
                'USD' => [
                    'rate' => 0,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
                    'decimal_places' => 2,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    /**
     * KCP SOAP 서비스를 승인키·결제 주소를 돌려주는 대역으로 바꿉니다.
     *
     * @param  string  $approvalKey  돌려줄 승인키
     * @return void 반환값 없음
     */
    private function mockSoapService(string $approvalKey = 'TEST_APPROVAL_KEY'): void
    {
        $mock = $this->createMock(KcpSoapService::class);
        $mock->method('getApprovalKey')->willReturn([
            'approval_key' => $approvalKey,
            'pay_url' => self::TEST_PAY_URL,
        ]);
        $mock->method('getSiteCd')->willReturn('T0000');
        $mock->method('getEscrowSiteCd')->willReturn('T0000');
        $this->app->instance(KcpSoapService::class, $mock);
    }

    /**
     * KCP SOAP 승인키 요청이 한 번도 일어나지 않아야 함을 걸어 둡니다.
     *
     * @return void 반환값 없음
     */
    private function expectSoapServiceNotCalled(): void
    {
        $mock = $this->createMock(KcpSoapService::class);
        $mock->expects($this->never())->method('getApprovalKey');
        $this->app->instance(KcpSoapService::class, $mock);
    }

    /**
     * 플러그인 설정을 테스트 모드 사이트 코드·키로 고정합니다.
     *
     * @return void 반환값 없음
     */
    private function mockPluginSettings(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_site_cd' => 'T0000',
            'test_site_key' => 'TEST_KEY',
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 모바일 승인키 요청 본문을 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> 요청 본문
     */
    private function approvalKeyPayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'order_number' => $order->order_number,
            'amount' => (int) $order->total_due_amount,
            'good_name' => '테스트 상품',
            'pay_method' => 'card',
            'buyr_name' => '홍길동',
            'buyr_mail' => 'test@test.com',
            'buyr_tel1' => '01012345678',
            'ret_url' => 'https://example.com/callback',
        ], $overrides);
    }

    // ===== 기본 승인키 획득 =====

    /**
     * 승인키 요청이 KCP 결제 주소와 결제창 필드(승인키·주문번호·금액)를 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=valid
     *
     * @effects approval_key_returns_pay_url_and_fields
     */
    public function test_get_approval_key_returns_pay_url_and_fields(): void
    {
        $order = $this->createOrder(10000);
        $this->mockSoapService('APPROVAL_001');
        $this->mockPluginSettings();
        $user = $order->user;

        $response = $this->actingAs($user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pay_url', self::TEST_PAY_URL)
            ->assertJsonPath('data.fields.approval_key', 'APPROVAL_001')
            ->assertJsonPath('data.fields.ordr_idxx', $order->order_number)
            ->assertJsonPath('data.fields.good_mny', (string) (int) $order->total_due_amount);
    }

    /**
     * 결제창을 닫아 취소된 결제 전 주문은 재결제 승인키 요청 때 결제 전 상태로 되살아난다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=valid
     *
     * @effects retryable_cancelled_order_is_restored_for_payment
     */
    public function test_get_approval_key_restores_retryable_cancelled_order(): void
    {
        $order = $this->createOrder(10000);
        $order->update([
            'order_status' => OrderStatusEnum::CANCELLED,
            'order_meta' => [
                'payment_failure_code' => 'USER_CANCEL',
                'payment_failure_message' => '사용자 취소',
                'payment_failed_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);
        $order->payment->update([
            'payment_status' => PaymentStatusEnum::CANCELLED,
            'cancelled_at' => now()->subMinute(),
            'paid_at' => null,
            'transaction_id' => null,
            'card_approval_number' => null,
            'payment_meta' => [
                'failure_source' => 'nhnkcp',
                'failure_code' => 'USER_CANCEL',
                'failure_message' => '사용자 취소',
                'failure_stage' => 'window_closed',
                'failed_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $this->mockSoapService('APPROVAL_RETRY');
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk()
            ->assertJsonPath('data.fields.approval_key', 'APPROVAL_RETRY');

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->cancelled_at);
    }

    /**
     * KCP SOAP 승인키 요청이 실패하면 422 로 실패를 알린다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=soap_failure
     *
     * @effects soap_failure_returns_422
     */
    public function test_get_approval_key_returns_422_on_soap_failure(): void
    {
        $order = $this->createOrder(10000);
        $this->mockPluginSettings();

        $mock = $this->createMock(KcpSoapService::class);
        $mock->method('getApprovalKey')
            ->willThrowException(new NhnKcpApiException('KCP SOAP 오류'));
        $mock->method('getSiteCd')->willReturn('T0000');
        $this->app->instance(KcpSoapService::class, $mock);

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * 로그인하지 않은 요청은 401 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=unauthenticated
     *
     * @effects approval_requires_the_order_owner
     */
    public function test_get_approval_key_requires_authentication(): void
    {
        $order = $this->createOrder(10000);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertUnauthorized();
    }

    /**
     * 다른 회원의 주문으로는 승인키를 받을 수 없다(403).
     *
     * @return void 반환값 없음
     *
     * @scenario request=other_owner
     *
     * @effects approval_requires_the_order_owner
     */
    public function test_get_approval_key_rejects_other_users_order(): void
    {
        $order = $this->createOrder(10000);
        $otherUser = User::factory()->create();
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $response = $this->actingAs($otherUser)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertForbidden()
            ->assertJsonPath('success', false);
    }

    /**
     * 요청 금액이 주문 결제예정액과 다르면 422 로 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=amount_mismatch
     *
     * @effects amount_and_currency_are_verified_before_approval
     */
    public function test_get_approval_key_rejects_amount_mismatch(): void
    {
        $order = $this->createOrder(10000);
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['amount' => 9900]));

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    /**
     * 청구할 수 없는 결제 통화(환율 0)는 500 이 아니라 422 로 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=bad_currency
     *
     * @effects amount_and_currency_are_verified_before_approval
     */
    public function test_get_approval_key_rejects_invalid_payment_currency_without_server_error(): void
    {
        $order = $this->createOrder(10000);
        $order->update([
            'currency' => 'USD',
            'currency_snapshot' => self::invalidUsdCurrencySnapshot(),
        ]);
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['currency' => 'USD']));

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Payment currency is not chargeable.');
    }

    /**
     * 원화가 아닌 주문은 KCP 모바일 결제로 받지 않는다(422).
     *
     * @return void 반환값 없음
     *
     * @scenario request=non_krw
     *
     * @effects amount_and_currency_are_verified_before_approval
     */
    public function test_get_approval_key_rejects_non_krw_order(): void
    {
        $order = $this->createOrder(10000);
        $order->currency = 'USD';
        $order->save();
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['currency' => 'USD']));

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    /**
     * 필수 필드가 빠진 요청은 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=missing_fields
     *
     * @effects required_fields_are_validated
     */
    public function test_get_approval_key_validates_required_fields(): void
    {
        $user = User::factory()->create();
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, []);

        $response->assertUnprocessable();
    }

    // ===== 간편결제 direct 파라미터 =====

    /**
     * 네이버페이 간편결제는 direct 필드와 원 결제수단 반환 필드를 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=valid
     *
     * @effects easy_pay_direct_fields_are_attached
     */
    public function test_naverpay_easy_pay_includes_direct_field(): void
    {
        $order = $this->createOrder(10000);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['pay_method' => 'nhnkcp_naverpay']));

        $response->assertOk()
            ->assertJsonPath('data.fields.naverpay_direct', 'Y')
            ->assertJsonPath('data.fields.param_opt_1', 'nhnkcp_naverpay')
            ->assertJsonPath('data.fields.nhnkcp_easy_pay_method', 'nhnkcp_naverpay');
    }

    /**
     * 카카오페이 간편결제는 kakaopay_direct 필드를 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=valid
     *
     * @effects easy_pay_direct_fields_are_attached
     */
    public function test_kakaopay_easy_pay_includes_direct_field(): void
    {
        $order = $this->createOrder(10000);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['pay_method' => 'nhnkcp_kakaopay']));

        $response->assertOk()
            ->assertJsonPath('data.fields.kakaopay_direct', 'Y');
    }

    // ===== 과세/비과세 분할 필드 =====

    /**
     * 전액 과세 주문은 복합과세 필드를 싣지 않는다(KCP 가 good_mny 로 계산).
     *
     * @return void 반환값 없음
     *
     * @scenario request=valid
     *
     * @effects tax_split_fields_follow_the_order_tax_mix
     */
    public function test_fully_taxable_order_does_not_include_tax_flag(): void
    {
        // 전액 과세 → 복합과세 필드 불필요. KCP가 내부 계산
        $amount = 11000;
        $vat = (int) round($amount * 10 / 110);
        $order = $this->createOrder($amount, ['taxable' => $amount, 'vat' => $vat, 'taxFree' => 0]);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk();
        $fields = $response->json('data.fields');

        $this->assertArrayNotHasKey('tax_flag', $fields, '전액 과세 시 tax_flag 포함 불필요');
        $this->assertArrayNotHasKey('comm_tax_mny', $fields);
        $this->assertArrayNotHasKey('comm_free_mny', $fields);
    }

    /**
     * 전액 면세 주문은 TG03 과 면세 금액 전액을 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=valid
     *
     * @effects tax_split_fields_follow_the_order_tax_mix
     */
    public function test_fully_tax_free_order_includes_comm_free_mny(): void
    {
        // 10,000원 전액 비과세 (면세 상품)
        $amount = 10000;
        $order = $this->createOrder($amount, ['taxable' => 0, 'vat' => 0, 'taxFree' => $amount]);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk();
        $fields = $response->json('data.fields');

        $this->assertArrayHasKey('tax_flag', $fields);
        $this->assertEquals('TG03', $fields['tax_flag']);
        $this->assertEquals('0', $fields['comm_tax_mny'], '공급가액은 0');
        $this->assertEquals('0', $fields['comm_vat_mny'], '부가세는 0');
        $this->assertEquals((string) $amount, $fields['comm_free_mny'], '비과세 금액 일치');
    }

    /**
     * 복합과세 주문은 공급가·부가세·면세 금액을 나눠 싣고 합이 결제 금액과 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=valid
     *
     * @effects tax_split_fields_follow_the_order_tax_mix
     */
    public function test_mixed_tax_order_includes_all_tax_fields(): void
    {
        // 21,000원 = 과세 11,000(공급가 10,000 + 부가세 1,000) + 비과세 10,000
        $taxable = 11000;
        $taxFree = 10000;
        $total = $taxable + $taxFree;
        $vat = (int) round($taxable * 10 / 110); // 1,000
        $supplyAmt = $taxable - $vat;               // 10,000

        $order = $this->createOrder($total, ['taxable' => $taxable, 'vat' => $vat, 'taxFree' => $taxFree]);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk();
        $fields = $response->json('data.fields');

        $this->assertEquals('TG03', $fields['tax_flag']);
        $this->assertEquals((string) $supplyAmt, $fields['comm_tax_mny'], '공급가액 일치');
        $this->assertEquals((string) $vat, $fields['comm_vat_mny'], '부가세 일치');
        $this->assertEquals((string) $taxFree, $fields['comm_free_mny'], '비과세 금액 일치');

        // 금액 검증: good_mny = comm_tax_mny + comm_vat_mny + comm_free_mny
        $this->assertEquals(
            (string) $total,
            $fields['good_mny'],
            'good_mny = 공급가액 + 부가세 + 비과세'
        );
    }

    /**
     * 할인으로 실결제액이 줄면 과세·면세 분할을 실결제액에 맞춰 다시 나눈다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=valid
     *
     * @effects tax_split_fields_follow_the_order_tax_mix
     */
    public function test_mixed_tax_fields_are_rebalanced_to_payment_amount(): void
    {
        // 원 상품 과세/비과세 합계 21,000원에서 쿠폰/마일리지 등으로 실결제액이 19,000원이 된 상황.
        $taxable = 11000;
        $taxFree = 10000;
        $paymentAmount = 19000;
        $vat = 1000;

        $order = $this->createOrder($paymentAmount, ['taxable' => $taxable, 'vat' => $vat, 'taxFree' => $taxFree]);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk();
        $fields = $response->json('data.fields');

        $this->assertEquals('TG03', $fields['tax_flag']);
        $this->assertEquals('8182', $fields['comm_tax_mny']);
        $this->assertEquals('818', $fields['comm_vat_mny']);
        $this->assertEquals('10000', $fields['comm_free_mny']);
        $this->assertSame(
            (int) $fields['good_mny'],
            (int) $fields['comm_tax_mny'] + (int) $fields['comm_vat_mny'] + (int) $fields['comm_free_mny'],
            'KCP 복합과세 분할합은 good_mny와 반드시 일치해야 함'
        );
    }

    /**
     * 없는 주문번호는 404 이고 SOAP 요청을 보내지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=missing_order
     *
     * @effects approval_requires_the_order_owner
     */
    public function test_get_approval_key_rejects_missing_order(): void
    {
        $order = $this->createOrder(10000);
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $payload = $this->approvalKeyPayload($order, ['order_number' => 'NON-EXISTENT-ORDER']);

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $payload);

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    /**
     * 모바일 가상계좌의 계좌 만료는 주문 입금 기한(쇼핑몰 자동취소 기한)이다 — 플러그인 일수 설정이 아니다.
     *
     * 2026-10-07 13:39 KST 에 5일 뒤 13:39:23 까지인 주문을 열면 「발급일로부터 5일 · 133923」 이어야 한다.
     * 설정 일수(기본 3)로 열면 주문서 안내·자동취소 기한과 실제 계좌 기한이 어긋난다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=mobile, due=present
     *
     * @effects mobile_vcnt_expire_term_follows_the_order_vbank_due
     */
    public function test_mobile_vbank_expire_term_follows_the_order_vbank_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 04:39:23', 'UTC'));

        try {
            $order = $this->createOrder(10000);
            $order->payment->update([
                'payment_method' => PaymentMethodEnum::VBANK,
                'vbank_due_at' => Carbon::parse('2026-10-12 04:39:23', 'UTC'),
            ]);
            $this->mockSoapService();
            $this->mockPluginSettings();

            $response = $this->actingAs($order->user)
                ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['pay_method' => 'vbank']));

            $response->assertOk()
                ->assertJsonPath('data.fields.pay_method', 'VCNT')
                ->assertJsonPath('data.fields.vcnt_expire_term', '5')
                ->assertJsonPath('data.fields.vcnt_expire_term_time', '133923');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 주문에 입금 기한이 없으면 종전 예비값(플러그인 일수, 없으면 3일)으로 열고 시각은 보내지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=mobile, due=absent
     *
     * @effects missing_due_falls_back_to_the_plugin_days
     */
    public function test_mobile_vbank_without_order_due_falls_back_to_the_plugin_days(): void
    {
        $order = $this->createOrder(10000);
        $order->payment->update(['payment_method' => PaymentMethodEnum::VBANK, 'vbank_due_at' => null]);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['pay_method' => 'vbank']));

        $response->assertOk()
            ->assertJsonPath('data.fields.vcnt_expire_term', '3')
            ->assertJsonMissingPath('data.fields.vcnt_expire_term_time');
    }
}
