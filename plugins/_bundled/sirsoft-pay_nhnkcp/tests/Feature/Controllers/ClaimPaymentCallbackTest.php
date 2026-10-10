<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
        HookListenerRegistrar::register(ClaimPaymentApproveListener::class, 'sirsoft-pay_nhnkcp');
    }

    /**
     * 결제 대기 클레임 결제 건을 만듭니다. (반품 신청서 + 주문 통화 스냅샷 동반)
     *
     * @param  bool  $withUser  회원 주문 여부
     * @return array{order: Order, return: OrderReturn, payment: ClaimPayment, user: ?User} 주문·신청서·결제 건·회원
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

    /**
     * CP 결제번호가 실린 승인 콜백이 주문 분기를 타지 않고 클레임 결제 건을 확정한다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_routing
     *
     * @effects kcp_claim_callback_confirms_the_claim_payment_without_an_order
     */
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
            'amount' => '3000',
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
        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=success&claim_number={$return->return_number}");

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertSame('KCP-CB-TNO-1', $fresh->transaction_id);
    }

    /**
     * 사용자가 결제창을 닫으면 오류 안내 없이 신청서 상세로 돌아가고 결제 건은 결제 대기로 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_routing
     *
     * @effects kcp_claim_user_cancel_returns_to_the_claim_detail
     */
    public function test_user_cancel_callback_returns_to_the_claim_detail_without_an_error(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '3001',
            'res_msg' => '사용자 취소',
            'ordr_idxx' => $payment->payment_number,
        ]);

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=cancelled&claim_number={$return->return_number}");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 금액이 맞는 클레임 결제 건에는 모바일 승인키가 발급된다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approval_key
     *
     * @effects kcp_claim_mobile_approval_key_is_gated_by_the_claim_payment
     */
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

    /**
     * 청구액과 다른 금액의 승인키 요청은 422 로 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approval_key
     *
     * @effects kcp_claim_mobile_approval_key_is_gated_by_the_claim_payment
     */
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

    /**
     * 입금 대기 수단(가상계좌)의 승인키 요청은 422 로 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approval_key
     *
     * @effects kcp_claim_mobile_approval_key_is_gated_by_the_claim_payment
     */
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

    /**
     * 주문이 사라진 결제 건의 승인키 요청은 통과가 아니라 차단이다. (K9)
     *
     * 부모를 못 읽었을 때 `?->user_id ?? 0` 으로 접으면 열람자 판정이 0 == 0 이 되어
     * 통과한다 — 지금은 라우트의 `auth:sanctum` 이 오른쪽을 0 이 되지 못하게 막고 있어
     * 실질은 닫혀 있지만, 그 방어가 이 판정 밖의 조건에 얹혀 있는 형태였다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approval_key
     *
     * @effects kcp_claim_mobile_approval_key_is_gated_by_the_claim_payment
     */
    public function test_mobile_approval_key_is_rejected_when_the_parent_order_is_gone(): void
    {
        ['payment' => $payment, 'order' => $order, 'user' => $user] = $this->makePendingClaimPayment();

        $order->delete();

        $response = $this->actingAs($user)->postJson('/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key', [
            'order_number' => $payment->payment_number,
            'amount' => 3000,
            'good_name' => '반품 배송비',
            'pay_method' => 'card',
            'ret_url' => 'https://shop.example.com/plugins/sirsoft-pay_nhnkcp/payment/callback',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 다른 회원의 결제 건은 승인키를 받을 수 없다. (대조군 — 위 단언이 전부-거절로 공허해지지 않음)
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approval_key
     *
     * @effects kcp_claim_mobile_approval_key_is_gated_by_the_claim_payment
     */
    public function test_mobile_approval_key_is_rejected_for_another_members_claim_payment(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();

        $response = $this->actingAs(User::factory()->create())->postJson('/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key', [
            'order_number' => $payment->payment_number,
            'amount' => 3000,
            'good_name' => '반품 배송비',
            'pay_method' => 'card',
            'ret_url' => 'https://shop.example.com/plugins/sirsoft-pay_nhnkcp/payment/callback',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 클레임 승인도 주문 경로와 같은 직렬화 락 안에서 일어난다. (K4)
     *
     * CP 분기가 락을 걸기 **전에** return 하면 같은 콜백이 겹쳐 들어왔을 때 방어가 PG 의
     * 중복 승인 거절 하나뿐이 된다 — 주문 경로와 방어 층이 달라진다. 승인 훅이 불릴 때
     * 같은 키를 다른 소유자로 잡아 보고, 잡히면 그 자리에 락이 없다는 뜻이다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_serialization
     *
     * @effects kcp_claim_approval_runs_inside_the_callback_lock
     */
    public function test_claim_approval_runs_inside_the_same_callback_lock_as_the_order_path(): void
    {
        ['payment' => $payment] = $this->makePendingClaimPayment();

        $lockKey = 'sirsoft-pay_nhnkcp:callback:authCallback:'.sha1(trim($payment->payment_number));
        $acquiredDuringApproval = null;

        HookManager::addFilter(
            'sirsoft-ecommerce.claim_payment.approve',
            function (array $default) use ($lockKey, &$acquiredDuringApproval) {
                $probe = Cache::lock($lockKey, 5);
                $acquiredDuringApproval = $probe->get();

                if ($acquiredDuringApproval) {
                    $probe->release();
                }

                return array_merge($default, [
                    'handled' => true,
                    'success' => true,
                    'transaction_id' => 'KCP-LOCK-TNO',
                    // 모듈은 승인액이 없으면 불일치로 거절한다 — 정상 승인의 모양을 갖춘다.
                    'paid_amount' => 3000,
                ]);
            },
            1,
        );

        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('getSiteCd')->willReturn('T0000');
        $mock->method('isTestMode')->willReturn(true);
        $this->app->instance(NhnKcpApiService::class, $mock);

        $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '0000',
            'enc_data' => 'enc-data',
            'enc_info' => 'enc-info',
            'ordr_idxx' => $payment->payment_number,
            'good_mny' => 3000,
        ]);

        $this->assertNotNull($acquiredDuringApproval, '승인 훅이 불리지 않았다면 이 테스트는 아무것도 재지 못한다');
        $this->assertFalse(
            $acquiredDuringApproval,
            '승인 도중 같은 키를 잡을 수 있다면 CP 분기가 직렬화 락 밖에서 승인하고 있다'
        );
    }
}
