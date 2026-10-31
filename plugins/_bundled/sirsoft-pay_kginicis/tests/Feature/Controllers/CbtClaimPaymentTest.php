<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Extension\HookListenerRegistrar;
use App\Models\User;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Plugins\Sirsoft\PayKginicis\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * CBT(일본 엔화) 경로의 클레임 추가결제 테스트 (K5)
 *
 * 엔화 기준 상점에서는 프론트가 결제 통화로 CBT 경로를 고르므로 클레임 추가결제도 토큰 →
 * 해시 → 콜백 3단계를 그대로 탄다. 그 세 지점이 CP 번호를 주문번호로 조회하면
 * `Order not found` 404 로 끝나 결제창이 열리지도 않았다 — 원인이 어디에도 드러나지 않는
 * 형태의 기능 공백이었다.
 *
 * 검증 축: 토큰 발급 / 해시 발급 / 승인 콜백 / 통화 게이트 / 금액 대조 / 결제창 중단 복귀.
 */
class CbtClaimPaymentTest extends PluginTestCase
{
    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(ClaimPaymentApproveListener::class, 'sirsoft-pay_kginicis');
    }

    /**
     * 엔화 결제 대기 클레임 결제 건을 만듭니다.
     *
     * @param  string  $currency  주문 통화 (JPY / KRW)
     * @return array{order: Order, return: OrderReturn, payment: ClaimPayment, user: User}
     */
    private function makePendingClaimPayment(string $currency = 'JPY'): array
    {
        $seq = ++self::$sequence;
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-CBTCLAIM-'.$seq,
            'currency' => $currency,
            'currency_snapshot' => [
                'base_currency' => $currency,
                'order_currency' => $currency,
                'exchange_rates' => [
                    $currency => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round'],
                ],
            ],
        ]);

        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-CBTCLAIM-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 500,
        ]);

        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-CBTCLAIM-'.$seq,
            'pg_provider' => 'kginicis',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'currency_code' => $currency,
            'amount' => 500,
        ]);

        return ['order' => $order, 'return' => $return, 'payment' => $payment, 'user' => $user];
    }

    /**
     * 일본 CBT 가 켜진 API 서비스 목을 바인딩합니다.
     *
     * @return Mockery\MockInterface&KgInicisApiService API 서비스 목
     */
    private function mockJapanApi(): Mockery\MockInterface
    {
        $apiService = Mockery::mock(KgInicisApiService::class)->makePartial();
        $apiService->shouldReceive('isJapanEnabled')->andReturn(true);
        $apiService->shouldReceive('isJapanConfigured')->andReturn(true);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('generateCbtHashData')->andReturn('HASH-DATA-1');

        $this->app->instance(KgInicisApiService::class, $apiService);

        return $apiService;
    }

    /**
     * 엔화 클레임 결제는 CBT 체크아웃 토큰을 발급받는다.
     */
    public function test_checkout_token_is_issued_for_a_jpy_claim_payment(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();
        $this->mockJapanApi();

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token', [
            'oid' => $payment->payment_number,
            'price' => 500,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.checkout_token'));
    }

    /**
     * 금액이 어긋나면 토큰을 발급하지 않는다.
     */
    public function test_checkout_token_rejects_an_amount_mismatch(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();
        $this->mockJapanApi();

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token', [
            'oid' => $payment->payment_number,
            'price' => 999,
        ]);

        $response->assertStatus(422);
    }

    /**
     * 원화 클레임 결제가 CBT 경로로 오면 거절한다 — 그 건은 국내 표준결제로 가야 한다.
     */
    public function test_checkout_token_rejects_a_krw_claim_payment(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment('KRW');
        $this->mockJapanApi();

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token', [
            'oid' => $payment->payment_number,
            'price' => 500,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'CBT payment is only available for JPY claim payments.');
    }

    /**
     * 토큰을 받은 뒤 해시까지 발급된다 (결제창을 여는 마지막 재료).
     */
    public function test_hash_data_is_issued_for_a_jpy_claim_payment(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();
        $this->mockJapanApi();

        $token = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token', [
            'oid' => $payment->payment_number,
            'price' => 500,
        ])->json('data.checkout_token');

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data', [
            'oid' => $payment->payment_number,
            'price' => 500,
            'timestamp' => date('YmdHis'),
            'checkout_token' => $token,
        ]);

        $response->assertOk()->assertJsonPath('data.hash_data', 'HASH-DATA-1');
    }

    /**
     * 승인 콜백이 주문을 전제하지 않고 클레임 결제 건을 확정한다.
     */
    public function test_cbt_callback_confirms_the_claim_payment_without_an_order(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        $apiService = $this->mockJapanApi();
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID-CLAIM-1')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT-CLAIM-TID-1',
                'paymethod' => 'CARD',
            ]);
        $apiService->shouldNotReceive('refundCbtPayment');

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'.http_build_query([
            'oid' => $payment->payment_number,
            'sid' => 'SID-CLAIM-1',
            'resultCode' => 'OK',
            'mid' => KgInicisApiService::JAPAN_TEST_MID,
            'paymethod' => 'CARD',
        ]));

        $response->assertRedirect(
            "/mypage/claims/{$return->return_number}?claim_payment=success&claim_number={$return->return_number}"
        );

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertSame('CBT-CLAIM-TID-1', $fresh->transaction_id);
    }

    /**
     * 사용자가 결제창을 닫으면 체크아웃이 아니라 신청서 상세로 돌아간다.
     */
    public function test_user_cancel_returns_to_the_claim_detail(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();
        $this->mockJapanApi();

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'.http_build_query([
            'oid' => $payment->payment_number,
            'sid' => 'SID-CLAIM-2',
            'resultCode' => '2001',
            'resultMsg' => 'ユーザーキャンセル',
        ]));

        $response->assertRedirect(
            "/mypage/claims/{$return->return_number}?claim_payment=cancelled&claim_number={$return->return_number}"
        );
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 편의점(CVS)은 입금 대기 수단이라 클레임 결제로 받을 수 없다 — 발급분은 환불로 되돌린다.
     */
    public function test_cvs_is_rejected_and_the_issued_payment_is_refunded(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();

        $apiService = $this->mockJapanApi();
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID-CLAIM-3')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT-CLAIM-TID-3',
                'paymethod' => 'CVS',
            ]);
        $apiService->shouldReceive('refundCbtPayment')
            ->once()
            ->with('CBT-CLAIM-TID-3', null, Mockery::any())
            ->andReturn(['resultCode' => 'OK']);

        $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'.http_build_query([
            'oid' => $payment->payment_number,
            'sid' => 'SID-CLAIM-3',
            'resultCode' => 'OK',
            'mid' => KgInicisApiService::JAPAN_TEST_MID,
            'paymethod' => 'CVS',
        ]));

        $this->assertSame(
            ClaimPaymentStatusEnum::PENDING,
            $payment->fresh()->payment_status,
            '입금 대기 수단은 결제 완료로 넘어가서는 안 된다'
        );
    }
}
