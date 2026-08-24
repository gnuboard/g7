<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Extension\HookListenerRegistrar;
use App\Models\User;
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
use Plugins\Sirsoft\PayNhnkcp\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 클레임 추가결제(CP 채번) 콜백·승인키 기능 테스트
 *
 * PG 콜백의 주문번호 자리에 CP 결제번호가 실려 와도 `Order` 를 전제하지 않고
 * `ClaimPayment` 로 되찾아 승인·리다이렉트하는지, 모바일 승인키 발급이 결제 건
 * 기준으로 검증되는지 확인한다.
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
        HookListenerRegistrar::register(ClaimPaymentApproveListener::class, 'sirsoft-pay_nhnkcp');
    }

    /**
     * 결제 대기 클레임 결제 건을 만듭니다. (반품 신청서 + 주문 통화 스냅샷 동반)
     *
     * @param  bool  $withUser  회원 주문 여부
     * @return array{order: Order, return: OrderReturn, payment: ClaimPayment, user: ?User}
     */
    private function makePendingClaimPayment(bool $withUser = true): array
    {
        $seq = ++self::$sequence;
        $user = $withUser ? User::factory()->create() : null;

        $order = OrderFactory::new()->create([
            'user_id' => $user?->id,
            'order_number' => 'ORD-KCPCLAIM-'.$seq,
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
            'return_number' => 'RT-KCPCLAIM-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);

        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-KCPCLAIM-'.$seq,
            'pg_provider' => 'nhnkcp',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        return ['order' => $order, 'return' => $return, 'payment' => $payment, 'user' => $user];
    }

    public function test_auth_callback_with_a_claim_payment_number_confirms_the_claim_payment_without_an_order(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('getSiteCd')->willReturn('T0000');
        $mock->method('isTestMode')->willReturn(true);
        $mock->method('approvePayment')->willReturn([
            'res_cd' => '0000',
            'res_msg' => 'OK',
            'tno' => 'KCP-CB-TNO-1',
        ]);
        $this->app->instance(NhnKcpApiService::class, $mock);

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '0000',
            'enc_data' => 'enc-data',
            'enc_info' => 'enc-info',
            'ordr_idxx' => $payment->payment_number,
            'good_mny' => 3000,
        ]);

        // 주문 완료 페이지가 아니라 신청서 상세로 돌아간다 — order_not_found 분기를 타지 않는다.
        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=success");

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertSame('KCP-CB-TNO-1', $fresh->transaction_id);
    }

    public function test_user_cancel_callback_returns_to_the_claim_detail_without_an_error(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '3001',
            'res_msg' => '사용자 취소',
            'ordr_idxx' => $payment->payment_number,
        ]);

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=cancelled");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    public function test_mobile_approval_key_is_issued_for_a_claim_payment_when_the_amount_matches(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->makePendingClaimPayment();

        $soap = $this->createMock(KcpSoapService::class);
        $soap->method('getApprovalKey')->willReturn([
            'approval_key' => 'APPROVAL-KEY-1',
            'pay_url' => 'https://smpay.kcp.co.kr/pay/mobile',
        ]);
        $soap->method('getSiteCd')->willReturn('T0000');
        $this->app->instance(KcpSoapService::class, $soap);

        $response = $this->actingAs($user)->postJson('/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key', [
            'order_number' => $payment->payment_number,
            'amount' => 3000,
            'good_name' => '반품 배송비',
            'pay_method' => 'card',
            'ret_url' => 'https://shop.example.com/plugins/sirsoft-pay_nhnkcp/payment/callback',
        ]);

        $response->assertOk()->assertJsonPath('data.fields.ordr_idxx', $payment->payment_number);
    }

    public function test_mobile_approval_key_is_rejected_for_a_claim_payment_amount_mismatch(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->makePendingClaimPayment();

        $response = $this->actingAs($user)->postJson('/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key', [
            'order_number' => $payment->payment_number,
            'amount' => 9999,
            'good_name' => '반품 배송비',
            'pay_method' => 'card',
            'ret_url' => 'https://shop.example.com/plugins/sirsoft-pay_nhnkcp/payment/callback',
        ]);

        $response->assertStatus(422);
    }

    public function test_mobile_approval_key_rejects_a_deposit_pending_method_for_a_claim_payment(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->makePendingClaimPayment();

        $response = $this->actingAs($user)->postJson('/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key', [
            'order_number' => $payment->payment_number,
            'amount' => 3000,
            'good_name' => '반품 배송비',
            'pay_method' => 'vbank',
            'ret_url' => 'https://shop.example.com/plugins/sirsoft-pay_nhnkcp/payment/callback',
        ]);

        $response->assertStatus(422);
    }
}
