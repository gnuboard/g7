<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Extension\HookListenerRegistrar;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
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
use Plugins\Sirsoft\PayNicepayments\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 클레임 추가결제(CP 채번) 콜백·SignData 기능 테스트
 *
 * PG 콜백의 주문번호 자리에 CP 결제번호가 실려 와도 `Order` 를 전제하지 않고
 * `ClaimPayment` 로 되찾아 승인·리다이렉트하는지, SignData 발급이 결제 건 기준으로
 * 검증되는지 확인한다.
 */
class ClaimPaymentCallbackTest extends PluginTestCase
{
    private const TEST_MID = 'nicepay00m';

    private const TEST_MERCHANT_KEY = 'test-merchant-key-for-signature';

    private const NEXT_APP_URL = 'https://webapi.nicepay.co.kr/webapi/pay_process.jsp';

    private const NET_CANCEL_URL = 'https://webapi.nicepay.co.kr/webapi/cancel_process.jsp';

    private static int $sequence = 0;

    /**
     * 승인 리스너를 다시 등록하고 테스트 MID·MerchantKey 를 담은 플러그인 설정을 주입한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // PluginTestCase 는 플러그인 훅 리스너를 자동 등록하지 않고, tearDown 의 HookManager
        // 복원이 앞 테스트의 등록을 지우므로 매 테스트마다 레지스트라를 초기화하고 다시 건다.
        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(ClaimPaymentApproveListener::class, 'sirsoft-pay_nicepayments');

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_merchant_key' => self::TEST_MERCHANT_KEY,
        ]);
        $this->app->instance(PluginSettingsService::class, $settings);
    }

    /**
     * 결제 대기 클레임 결제 건을 만듭니다. (반품 신청서 + 주문 통화 스냅샷 동반)
     *
     * @return array{order: Order, return: OrderReturn, payment: ClaimPayment} 주문·반품 신청서·클레임 결제 건
     */
    private function makePendingClaimPayment(): array
    {
        $seq = ++self::$sequence;

        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-NICECLAIM-'.$seq,
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'KRW',
                'exchange_rates' => [
                    'KRW' => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round'],
                ],
            ],
        ]);

        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-NICECLAIM-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);

        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-NICECLAIM-'.$seq,
            'pg_provider' => 'nicepayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        return ['order' => $order, 'return' => $return, 'payment' => $payment];
    }

    /**
     * 결제 건 결제번호와 금액으로 서명한 나이스페이먼츠 인증 콜백 값을 만든다.
     *
     * @param  ClaimPayment  $payment  대상 클레임 결제 건
     * @param  int  $amt  콜백 금액
     * @return array<string, mixed> 서명된 콜백 값
     */
    private function signedCallback(ClaimPayment $payment, int $amt = 3000): array
    {
        $authToken = 'nice-auth-token-cb';
        $signature = bin2hex(hash('sha256', $authToken.self::TEST_MID.(string) $amt.self::TEST_MERCHANT_KEY, true));

        return [
            'AuthResultCode' => '0000',
            'NextAppURL' => self::NEXT_APP_URL,
            'TxTid' => 'nicepay00m01012608240000000009',
            'AuthToken' => $authToken,
            'PayMethod' => 'CARD',
            'MID' => self::TEST_MID,
            'Moid' => $payment->payment_number,
            'Amt' => $amt,
            'NetCancelURL' => self::NET_CANCEL_URL,
            'Signature' => $signature,
        ];
    }

    /**
     * CP 결제번호가 실린 인증 콜백이 주문 분기를 타지 않고 클레임 결제 건을 확정한 뒤 신청서 상세로 돌려보낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_routing
     *
     * @effects nice_claim_callback_confirms_the_claim_payment_without_an_order
     */
    public function test_auth_callback_with_a_claim_payment_number_confirms_the_claim_payment_without_an_order(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        Http::fake([
            self::NEXT_APP_URL => Http::response(json_encode([
                'ResultCode' => '3001',
                'ResultMsg' => 'OK',
                'TID' => 'NICE-CB-TID-1',
                'PayMethod' => 'CARD',
                'Amt' => '3000',
            ]), 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $this->signedCallback($payment));

        // 주문 완료 페이지가 아니라 신청서 상세로 돌아간다 — order_not_found 분기를 타지 않는다.
        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=success&claim_number={$return->return_number}");

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertSame('NICE-CB-TID-1', $fresh->transaction_id);
    }

    /**
     * 승인 응답에 승인 금액이 없으면 클레임 결제 건을 결제완료로 만들지 않고 승인을 망취소한다.
     *
     * 수정 전: 응답 금액이 비면 대조를 건너뛰고 브라우저가 보낸 콜백 금액을 결제 금액으로 넘겼다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approved_amount
     *
     * @effects nice_claim_callback_without_an_approved_amount_is_net_cancelled
     */
    public function test_auth_callback_without_an_approved_amount_leaves_the_claim_payment_pending(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();

        Http::fake([
            self::NEXT_APP_URL => Http::response(json_encode([
                'ResultCode' => '3001',
                'ResultMsg' => 'OK',
                'TID' => 'NICE-CB-TID-NO-AMT',
                'PayMethod' => 'CARD',
                'Moid' => $payment->payment_number,
            ]), 200),
            self::NET_CANCEL_URL => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $this->signedCallback($payment));

        $response->assertRedirect();
        $this->assertStringNotContainsString('claim_payment=success', (string) $response->headers->get('Location'));

        $this->assertNotSame(ClaimPaymentStatusEnum::PAID, $payment->fresh()->payment_status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'cancel_process')
            && ($request->data()['NetCancel'] ?? null) == 1);
    }

    /**
     * 인증 단계 실패(결제창 종료)는 오류 안내 없이 신청서 상세로 돌아가고 결제 건은 결제 대기로 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_routing
     *
     * @effects nice_claim_auth_failure_returns_to_the_claim_detail
     */
    public function test_auth_phase_failure_returns_to_the_claim_detail_silently(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', [
            'AuthResultCode' => '0021',
            'AuthResultMsg' => '결제창을 종료하셨습니다',
            'Moid' => $payment->payment_number,
        ]);

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=cancelled&claim_number={$return->return_number}");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 청구액과 같은 금액이면 클레임 결제 건에 SignData 를 발급한다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=sign_data
     *
     * @effects nice_claim_sign_data_is_gated_by_the_claim_payment
     */
    public function test_sign_data_is_issued_for_a_claim_payment_when_the_amount_matches(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'moid' => $payment->payment_number,
            'amt' => 3000,
        ]);

        $response->assertOk()->assertJsonStructure(['ediDate', 'signData', 'mid']);
    }

    /**
     * 청구액과 다른 금액의 SignData 요청은 422 로 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=sign_data
     *
     * @effects nice_claim_sign_data_is_gated_by_the_claim_payment
     */
    public function test_sign_data_is_rejected_for_a_claim_payment_amount_mismatch(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'moid' => $payment->payment_number,
            'amt' => 9999,
        ]);

        $response->assertStatus(422);
    }

    /**
     * 부모 주문을 읽을 수 없는 클레임 결제 건의 SignData 요청은 구매자 확인 단계에서 403 으로 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=sign_data
     *
     * @effects nice_claim_sign_data_rejects_a_payment_whose_order_cannot_be_read
     */
    public function test_sign_data_buyer_verification_rejects_a_claim_payment_whose_order_cannot_be_read(): void
    {
        // 주문이 soft-delete 되면 `$payment->order` 는 null 이다. 구매자 가드가 그 상태를 통과로
        // 읽으면 대조가 통째로 건너뛰어진다 — 이 플러그인은 구매자 가드가 청구액 가드보다 앞서
        // 있어, 통과로 읽던 시절에는 뒤쪽 가드가 낸 422 가 나왔다. 부모를 못 읽으면 403 이다.
        ['payment' => $payment] = $this->makePendingClaimPayment();
        $payment->order->delete();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'moid' => $payment->payment_number,
            'amt' => 3000,
        ]);

        $response->assertStatus(403);
    }
}
