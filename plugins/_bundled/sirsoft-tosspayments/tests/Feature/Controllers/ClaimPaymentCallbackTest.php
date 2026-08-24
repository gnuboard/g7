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
     * @return array{order: Order, return: OrderReturn, payment: ClaimPayment}
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
        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=success");

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertSame('pk_claim_cb_1', $fresh->transaction_id);
    }

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

        $response->assertRedirect("/shop/guest/claims/{$return->return_number}?claim_payment=success");
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $payment->fresh()->payment_status);
    }

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

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=failed&error=failed");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

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
        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=cancelled");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }
}
