<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 모바일 가상계좌 콜백 세션 바인딩 회귀 테스트 (KVE-2026-2019).
 *
 * 회귀 배경: KCP 모바일(SmartPhone Pay) 가상계좌 콜백은 enc_data/enc_info 없이
 * 계좌 정보를 평문 POST 로만 전달한다. 그런데 컨트롤러는 그 평문을 PG 서버 검증 없이
 * 그대로 영속시켰다. 그래서 주문번호만 아는 익명 요청 1회로 피해자 주문의 입금 계좌를
 * 공격자 계좌로 바꿀 수 있었다.
 *
 * 방어: 소유자 인증을 거친 승인키 발급 시점에 일회성 nonce 를 만들어 주문에 저장하고
 * KCP passthrough 파라미터(param_opt_2)로 실어 보낸 뒤, 콜백에서 되돌아온 값과
 * 대조한다. 불일치/부재면 상태를 전혀 바꾸지 않는다.
 *
 * @group nhnkcp
 * @group security
 */
class MobileVbankCallbackBindingTest extends PluginTestCase
{
    private const TEST_SITE_CD = 'T0000';

    private const TEST_SITE_KEY = 'TEST_SITE_KEY_0000';

    private const CALLBACK_URL = '/plugins/sirsoft-pay_nhnkcp/payment/callback';

    private const APPROVAL_KEY_ENDPOINT = '/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key';

    private const TEST_PAY_URL = 'https://testpay.kcp.co.kr/php/mobile/mc_pay_form.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockPluginSettings();
    }

    /**
     * 플러그인 설정을 테스트 모드로 고정합니다.
     */
    private function mockPluginSettings(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_site_cd' => self::TEST_SITE_CD,
            'test_site_key' => self::TEST_SITE_KEY,
            'live_site_cd' => '',
            'live_site_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * KcpSoapService 를 승인키 발급 성공으로 고정합니다.
     */
    private function mockSoapService(): void
    {
        $mock = $this->createMock(KcpSoapService::class);
        $mock->method('getApprovalKey')->willReturn([
            'approval_key' => 'TEST_APPROVAL_KEY',
            'pay_url' => self::TEST_PAY_URL,
        ]);
        $mock->method('getSiteCd')->willReturn(self::TEST_SITE_CD);
        $mock->method('getEscrowSiteCd')->willReturn(self::TEST_SITE_CD);
        $this->app->instance(KcpSoapService::class, $mock);
    }

    /**
     * KRW 통화 스냅샷을 반환합니다.
     *
     * @return array<string, mixed>
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
     * 가상계좌 결제대기 주문을 만듭니다.
     *
     * @param  array<string, mixed>  $paymentMeta  payment_meta 초기값
     */
    private function createVbankOrder(array $paymentMeta = [], int $totalAmount = 50000): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-VB-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => $totalAmount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'total_due_amount' => $totalAmount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
            'total_tax_amount' => $totalAmount,
            'total_vat_amount' => (int) round($totalAmount * 10 / 110),
            'total_tax_free_amount' => 0,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'nhnkcp',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'vbank_name' => null,
            'vbank_number' => null,
            'vbank_holder' => null,
            'payment_meta' => $paymentMeta,
        ]);

        return $order->fresh('payment');
    }

    /**
     * 저장된 세션 nonce 를 가진 주문을 만듭니다.
     *
     * @return array{0: Order, 1: string}
     */
    private function createVbankOrderWithState(): array
    {
        $nonce = bin2hex(random_bytes(16));
        $order = $this->createVbankOrder([
            'mobile_vbank_state' => [
                'nonce' => $nonce,
                'issued_at' => now()->toIso8601String(),
            ],
        ]);

        return [$order, $nonce];
    }

    /**
     * 모바일 가상계좌 콜백 페이로드를 만듭니다 (enc_data/enc_info 없음).
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed>
     */
    private function mobileVbankPayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => 'KCP_TNO_'.uniqid(),
            'ordr_idxx' => $order->order_number,
            'good_mny' => (int) $order->total_due_amount,
            'use_pay_method' => 'VCNT',
            'bankname' => '공격자은행',
            'account' => '9999999999',
            'depositor' => '공격자',
            'va_date' => now()->addDays(3)->format('YmdHis'),
        ], $overrides);
    }

    // =========================================================================
    // 차단 매트릭스
    // =========================================================================

    /**
     * 세션 nonce 가 저장되지 않은 주문에 온 모바일 콜백은 계좌를 쓰지 못한다.
     */
    public function test_mobile_vbank_callback_without_stored_state_cannot_persist_account(): void
    {
        $order = $this->createVbankOrder();

        $this->post(self::CALLBACK_URL, $this->mobileVbankPayload($order));

        $payment = $order->fresh()->payment;
        $this->assertNull($payment->vbank_number, '검증되지 않은 콜백이 계좌를 저장했습니다');
        $this->assertNull($payment->vbank_name);
        $this->assertSame(PaymentStatusEnum::READY->value, $payment->payment_status->value);
    }

    /**
     * 저장된 nonce 와 다른 param_opt_2 는 차단된다.
     */
    public function test_mobile_vbank_callback_with_mismatched_state_is_rejected(): void
    {
        [$order] = $this->createVbankOrderWithState();

        $this->post(self::CALLBACK_URL, $this->mobileVbankPayload($order, [
            'param_opt_2' => bin2hex(random_bytes(16)),
        ]));

        $payment = $order->fresh()->payment;
        $this->assertNull($payment->vbank_number, '불일치 nonce 로 계좌가 저장되었습니다');
        $this->assertNull($payment->vbank_name);
    }

    /**
     * 이미 발급된 계좌는 위조 콜백으로 덮어써지지 않는다.
     */
    public function test_forged_callback_cannot_overwrite_an_issued_account(): void
    {
        $order = $this->createVbankOrder();
        $order->payment->update([
            'vbank_name' => '정상은행',
            'vbank_number' => 'T1234567890',
            'vbank_holder' => 'NHN KCP',
            'vbank_issued_at' => now(),
        ]);

        $this->post(self::CALLBACK_URL, $this->mobileVbankPayload($order));

        $payment = $order->fresh()->payment;
        $this->assertSame('T1234567890', $payment->vbank_number, '위조 콜백이 발급된 계좌를 변조했습니다');
        $this->assertSame('정상은행', $payment->vbank_name);
    }

    /**
     * nonce 는 1회성이다 — 정상 발급 후 같은 nonce 로 다시 오면 차단된다.
     */
    public function test_state_is_single_use_and_replay_is_rejected(): void
    {
        [$order, $nonce] = $this->createVbankOrderWithState();

        $this->post(self::CALLBACK_URL, $this->mobileVbankPayload($order, [
            'param_opt_2' => $nonce,
            'bankname' => '정상은행',
            'account' => 'T1111111111',
            'depositor' => 'NHN KCP',
        ]));

        $this->assertSame('T1111111111', $order->fresh()->payment->vbank_number);

        // 같은 nonce 로 재사용 시도 (계좌 변조)
        $this->post(self::CALLBACK_URL, $this->mobileVbankPayload($order, [
            'param_opt_2' => $nonce,
            'bankname' => '공격자은행',
            'account' => 'T9999999999',
        ]));

        $this->assertSame(
            'T1111111111',
            $order->fresh()->payment->vbank_number,
            'nonce 재사용으로 계좌가 변조되었습니다'
        );
    }

    // =========================================================================
    // 발급 지점 (승인키 요청) — 정상 흐름 불변
    // =========================================================================

    /**
     * 가상계좌 모바일 승인키 요청은 nonce 를 만들어 주문에 저장하고 param_opt_2 로 실어 보낸다.
     *
     * 이 경로는 종전 테스트가 카드 결제만 다뤄 비어 있었다. 발급이 조용히 실패하면
     * 콜백은 언제나 차단되어 **가상계좌 결제 자체가 불능**이 되는데, 그 실패는 차단
     * 테스트만으로는 드러나지 않는다.
     */
    public function test_mobile_vbank_approval_key_issues_and_persists_the_session_state(): void
    {
        $order = $this->createVbankOrder();
        $this->mockSoapService();

        $response = $this->actingAs($order->user)->postJson(self::APPROVAL_KEY_ENDPOINT, [
            'order_number' => $order->order_number,
            'amount' => (int) $order->total_due_amount,
            'good_name' => '테스트 상품',
            'pay_method' => 'vbank',
            'ret_url' => 'https://example.com/callback',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $echoed = $response->json('data.fields.param_opt_2');
        $this->assertIsString($echoed);
        $this->assertNotSame('', $echoed, 'param_opt_2 가 결제창 필드에 실리지 않았다');

        $stored = $order->fresh()->payment->payment_meta['mobile_vbank_state']['nonce'] ?? null;
        $this->assertSame($echoed, $stored, '결제창에 실은 값과 저장된 값이 다르다');
    }

    /**
     * 카드 결제에는 nonce 를 만들지 않는다 (범위 최소화 — 불필요한 부작용 방지).
     */
    public function test_card_approval_key_does_not_issue_a_vbank_state(): void
    {
        $order = $this->createVbankOrder();
        $this->mockSoapService();

        $response = $this->actingAs($order->user)->postJson(self::APPROVAL_KEY_ENDPOINT, [
            'order_number' => $order->order_number,
            'amount' => (int) $order->total_due_amount,
            'good_name' => '테스트 상품',
            'pay_method' => 'card',
            'ret_url' => 'https://example.com/callback',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.fields.param_opt_2'));
        $this->assertArrayNotHasKey(
            'mobile_vbank_state',
            $order->fresh()->payment->payment_meta ?? []
        );
    }

    /**
     * 발급 → 콜백 왕복이 실제로 성립한다 (발급 지점과 검증 지점의 계약 일치).
     *
     * 두 지점이 서로 다른 키·형식을 쓰면 차단 테스트는 전부 통과하면서 정상 결제만
     * 막힌다. 과거 KVE 재수정 사례가 "검증 지점과 실행 지점의 해석 불일치" 였으므로
     * 두 절반을 한 테스트로 묶어 고정한다.
     */
    public function test_issued_state_round_trips_through_the_callback(): void
    {
        $order = $this->createVbankOrder();
        $this->mockSoapService();

        $issued = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, [
                'order_number' => $order->order_number,
                'amount' => (int) $order->total_due_amount,
                'good_name' => '테스트 상품',
                'pay_method' => 'vbank',
                'ret_url' => 'https://example.com/callback',
            ])
            ->json('data.fields.param_opt_2');

        $this->post(self::CALLBACK_URL, $this->mobileVbankPayload($order, [
            'param_opt_2' => $issued,
            'bankname' => '정상은행',
            'account' => 'T3333333333',
            'depositor' => 'NHN KCP',
        ]));

        $this->assertSame(
            'T3333333333',
            $order->fresh()->payment->vbank_number,
            '발급된 nonce 가 콜백에서 인정되지 않았다 — 정상 가상계좌 결제가 불능이다'
        );
    }

    // =========================================================================
    // 통과 매트릭스 (정상 흐름 불변)
    // =========================================================================

    /**
     * 일치하는 nonce 를 실은 콜백은 종전대로 계좌를 저장한다.
     */
    public function test_mobile_vbank_callback_with_matching_state_persists_account(): void
    {
        [$order, $nonce] = $this->createVbankOrderWithState();

        $this->post(self::CALLBACK_URL, $this->mobileVbankPayload($order, [
            'param_opt_2' => $nonce,
            'bankname' => '정상은행',
            'account' => 'T2222222222',
            'depositor' => 'NHN KCP',
        ]));

        $payment = $order->fresh()->payment;
        $this->assertSame('T2222222222', $payment->vbank_number);
        $this->assertSame('정상은행', $payment->vbank_name);
        $this->assertSame('NHN KCP', $payment->vbank_holder);
        $this->assertSame('mobile', $payment->payment_device);
    }
}
