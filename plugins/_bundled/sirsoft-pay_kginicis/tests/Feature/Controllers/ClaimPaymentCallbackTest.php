<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

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
use Plugins\Sirsoft\PayKginicis\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 클레임 추가결제(CP 채번) 콜백·서명 기능 테스트
 *
 * PG 콜백의 주문번호 자리에 CP 결제번호가 실려 와도 `Order` 를 전제하지 않고
 * `ClaimPayment` 로 되찾아 승인·리다이렉트하는지, 서명 발급이 결제 건 기준으로
 * 검증되는지 확인한다 — 주문 분기를 그대로 태우면 order_not_found 로 끝난다.
 */
class ClaimPaymentCallbackTest extends PluginTestCase
{
    private const PC_AUTH_URL = 'https://fcstdpay.inicis.com/api/payAuth';

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // PluginTestCase 는 플러그인 훅 리스너를 자동 등록하지 않고, tearDown 의 HookManager
        // 복원이 앞 테스트의 등록을 지우므로 매 테스트마다 레지스트라를 초기화하고 다시 건다.
        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(ClaimPaymentApproveListener::class, 'sirsoft-pay_kginicis');

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => '2IgsAQSbMqHkAkj3',
        ]);
        $this->app->instance(PluginSettingsService::class, $settings);
    }

    /**
     * 결제 대기 클레임 결제 건을 만듭니다. (반품 신청서 + 주문 통화 스냅샷 동반)
     *
     * @return array{order: Order, return: OrderReturn, payment: ClaimPayment}
     */
    private function makePendingClaimPayment(): array
    {
        $seq = ++self::$sequence;

        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-INICLAIM-'.$seq,
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
            'return_number' => 'RT-INICLAIM-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);

        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-INICLAIM-'.$seq,
            'pg_provider' => 'kginicis',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        return ['order' => $order, 'return' => $return, 'payment' => $payment];
    }

    public function test_auth_callback_with_a_claim_payment_number_confirms_the_claim_payment_without_an_order(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        Http::fake([
            self::PC_AUTH_URL => Http::response([
                'resultCode' => '0000',
                'tid' => 'INI-CLAIM-TID-1',
                'payMethod' => 'Card',
                'TotPrice' => 3000,
                'mid' => 'INIpayTest',
            ], 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', [
            'resultCode' => '0000',
            'authToken' => 'auth-token-claim',
            'authUrl' => self::PC_AUTH_URL,
            'idc_name' => 'fc',
            'MOID' => $payment->payment_number,
        ]);

        // 주문 완료 페이지가 아니라 신청서 상세로 돌아간다 — order_not_found 분기를 타지 않는다.
        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=success&claim_number={$return->return_number}");

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertSame('INI-CLAIM-TID-1', $fresh->transaction_id);
    }

    public function test_user_cancel_callback_returns_to_the_claim_detail_without_an_error(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', [
            'resultCode' => '0021',
            'resultMsg' => '사용자가 결제를 취소하였습니다',
            'MOID' => $payment->payment_number,
        ]);

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=cancelled&claim_number={$return->return_number}");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    public function test_signature_is_issued_for_a_claim_payment_number_when_the_amount_matches(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => $payment->payment_number,
            'price' => 3000,
            'timestamp' => (string) (int) (microtime(true) * 1000),
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['signature', 'verification', 'mKey']]);
    }

    public function test_signature_is_rejected_for_a_claim_payment_amount_mismatch(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => $payment->payment_number,
            'price' => 9999,
            'timestamp' => (string) (int) (microtime(true) * 1000),
        ]);

        $response->assertStatus(422);
    }

    public function test_signature_is_rejected_when_the_order_of_the_claim_payment_cannot_be_read(): void
    {
        // 주문이 soft-delete 되면 `$payment->order` 가 null 이 된다. 이 플러그인은 청구액 가드가
        // 구매자 가드보다 앞서 있어 어느 쪽이든 거절되지만(그래서 구매자 가드의 fail-closed 정렬은
        // 관측 가능한 동작 변화가 없다), "부모를 읽을 수 없는 결제 건에는 서명을 발급하지 않는다"
        // 는 계약 자체를 여기서 고정한다 — 가드 순서가 바뀌어도 이 단언은 유지되어야 한다.
        ['payment' => $payment] = $this->makePendingClaimPayment();
        $payment->order->delete();

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => $payment->payment_number,
            'price' => 3000,
            'timestamp' => (string) (int) (microtime(true) * 1000),
        ]);

        $response->assertStatus(422);
    }
}
