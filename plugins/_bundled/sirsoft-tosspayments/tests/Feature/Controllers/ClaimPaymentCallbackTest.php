<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

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
use Plugins\Sirsoft\Tosspayments\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\Tosspayments\Support\ShopRedirectUrl;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 클레임 추가결제(CP 채번) 콜백 기능 테스트
 *
 * PG 콜백의 주문번호 자리에 CP 결제번호가 실려 와도 `Order` 를 전제하지 않고
 * `ClaimPayment` 로 되찾아 승인·리다이렉트하는지 검증한다 — 주문 분기를 그대로
 * 태우면 order_not_found 로 끝난다.
 */
class ClaimPaymentCallbackTest extends PluginTestCase
{
    private static int $sequence = 0;

    /**
     * 테스트마다 이 플러그인의 승인 리스너를 훅에 다시 겁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // PluginTestCase 는 플러그인 훅 리스너를 자동 등록하지 않고, tearDown 의 HookManager
        // 복원이 앞 테스트의 등록을 지우므로 매 테스트마다 레지스트라를 초기화하고 다시 건다.
        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(
            ClaimPaymentApproveListener::class,
            'sirsoft-tosspayments',
        );
    }

    /**
     * 플러그인 설정을 테스트 모드 고정값으로 대체합니다.
     *
     * @return void 반환값 없음
     */
    private function mockPluginSettings(): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_mock_key',
            'redirect_success_url' => ShopRedirectUrl::DEFAULT_SUCCESS_URL,
            'redirect_fail_url' => ShopRedirectUrl::DEFAULT_FAIL_URL,
        ]);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }

    /**
     * 결제 대기 클레임 결제 건을 만듭니다. (반품 신청서 + 주문 통화 스냅샷 동반)
     *
     * @param  bool  $guest  비회원 주문 여부
     * @return array{order: Order, return: OrderReturn, payment: ClaimPayment} 주문·신청서·결제 대기 건
     */
    private function makePendingClaimPayment(bool $guest = false): array
    {
        $seq = ++self::$sequence;

        $order = OrderFactory::new()->create([
            'user_id' => $guest ? null : User::factory()->create()->id,
            'order_number' => 'ORD-CLAIM-'.$seq,
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
            'return_number' => 'RT-CLAIM-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);

        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-CLAIM-'.$seq,
            'pg_provider' => 'tosspayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        return ['order' => $order, 'return' => $return, 'payment' => $payment];
    }

    /**
     * 토스 Confirm API 응답을 가짜로 세웁니다.
     *
     * @param  string  $paymentKey  승인 응답에 실을 결제 키
     * @param  string  $orderId  승인 응답에 실을 주문 식별자 (CP 채번)
     * @param  int  $amount  승인 금액
     * @return void 반환값 없음
     */
    private function fakeConfirmApi(string $paymentKey, string $orderId, int $amount): void
    {
        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => $paymentKey,
                'orderId' => $orderId,
                'status' => 'DONE',
                'totalAmount' => $amount,
            ], 200),
        ]);
    }

    /**
     * CP 채번 성공 콜백이 주문 분기를 타지 않고 클레임 결제를 확정하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_callback_resolves_cp_number_without_order
     */
    public function test_success_callback_with_a_claim_payment_number_confirms_the_claim_payment_without_an_order(): void
    {
        $this->mockPluginSettings();
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();
        $this->fakeConfirmApi('pk_claim_cb_1', $payment->payment_number, 3000);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => 'pk_claim_cb_1',
            'orderId' => $payment->payment_number,
            'amount' => 3000,
        ]));

        // 주문 완료 페이지가 아니라 회원 신청서 상세로 돌아간다 — order_not_found 분기를 타지 않는다.
        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=success&claim_number={$return->return_number}");

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertSame('pk_claim_cb_1', $fresh->transaction_id);
    }

    /**
     * 비회원 주문의 클레임 결제는 주문 조회 화면으로 돌아가는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_callback_returns_guest_to_order_lookup
     */
    public function test_success_callback_for_a_guest_order_redirects_to_the_guest_claim_detail(): void
    {
        $this->mockPluginSettings();
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment(guest: true);
        $this->fakeConfirmApi('pk_claim_cb_2', $payment->payment_number, 3000);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => 'pk_claim_cb_2',
            'orderId' => $payment->payment_number,
            'amount' => 3000,
        ]));

        $response->assertRedirect("/shop/guest/orders?claim_payment=success&claim_number={$return->return_number}");
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $payment->fresh()->payment_status);
    }

    /**
     * 승인 거절 시 토스가 준 코드를 복귀 주소와 결제행에 남기는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_callback_carries_pg_code_to_claim_detail
     */
    public function test_confirm_failure_redirects_to_the_claim_detail_with_an_error_code(): void
    {
        $this->mockPluginSettings();
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'code' => 'REJECT_CARD_COMPANY', 'message' => 'declined',
            ], 400),
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => 'pk_claim_cb_3',
            'orderId' => $payment->payment_number,
            'amount' => 3000,
        ]));

        // 테스트 이름이 약속한 「with an error code」 는 **토스가 준 그 코드**다. 여기가 일반
        // 토큰이면 모든 승인 거절이 같은 주소로 수렴해, 구매자 배너는 사유를 말하지 못하고
        // 고객센터도 건을 특정할 값을 받지 못한다 (#599 3차 실측 F-5 로 실거래에서 확인).
        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=failed&error=REJECT_CARD_COMPANY&claim_number={$return->return_number}");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);

        // 사유는 결제행에도 남아야 한다 — 화면에는 코드만 싣고 원문은 여기에만 남기기 때문이다.
        $fresh = $payment->fresh();
        $this->assertSame('REJECT_CARD_COMPANY', $fresh->pg_error_code);
        $this->assertSame('declined', $fresh->pg_error_message);
        $this->assertNotNull($fresh->raw_response, '실패 건에 PG 원본 응답이 남지 않아 대사할 근거가 없다');
    }

    /**
     * 사용자가 결제창을 닫은 중단은 오류 안내 없이 복귀하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_user_cancel_returns_without_error
     */
    public function test_fail_callback_with_a_user_cancel_code_returns_to_the_claim_detail_without_an_error(): void
    {
        $this->mockPluginSettings();
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/fail?'.http_build_query([
            'code' => 'PAY_PROCESS_CANCELED',
            'message' => 'cancelled by user',
            'orderId' => $payment->payment_number,
        ]));

        // 사용자 취소는 실패가 아니다 — 오류 파라미터 없이 신청서 상세로 복귀한다.
        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=cancelled&claim_number={$return->return_number}");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }
}
