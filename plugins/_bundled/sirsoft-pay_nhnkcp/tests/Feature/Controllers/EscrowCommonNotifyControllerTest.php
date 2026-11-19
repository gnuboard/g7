<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * NHN KCP 에스크로 공통 통보 수신의 거래번호 대조와 재전송 처리를 검증한다.
 */
class EscrowCommonNotifyControllerTest extends PluginTestCase
{
    /**
     * 테스트가 등록한 훅 리스너를 모두 걷어 다음 테스트에 남지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * 결제 완료 상태의 회원 주문과 KCP 에스크로 카드 결제 행을 만든다.
     *
     * @param  string  $tno  결제 행에 기록할 KCP 거래번호
     * @return Order 생성한 주문
     */
    private function createEscrowOrder(string $tno = 'KCP_ESCROW_TNO_REAL'): Order
    {
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_due_amount' => 0,
            'total_paid_amount' => 30000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => $tno,
            'paid_amount_local' => 30000,
            'paid_at' => now(),
            'is_escrow' => true,
            'payment_meta' => [
                'site_cd' => 'T0000',
                'is_test_mode' => true,
                'escw_yn' => 'Y',
            ],
        ]);

        return $order;
    }

    /**
     * KCP 서버 IP 로 에스크로 공통 통보를 보낸다.
     *
     * @param  array<string, string>  $payload  통보 본문
     * @return TestResponse 통보 응답
     */
    private function postEscrowNotify(array $payload): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.238.36.58'])
            ->post('/plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify', $payload);
    }

    /**
     * 응답이 KCP 에 재통보를 요구하는 결과(0000 아님)인지 단언한다.
     *
     * @param  TestResponse  $response  통보 응답
     * @return void 반환값 없음
     */
    private function assertKcpNotifyRetry(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
        $this->assertStringNotContainsString('value="0000"', $response->getContent());
    }

    /**
     * 응답이 KCP 에 수신 완료(0000)를 알리는 결과인지 단언한다.
     *
     * @param  TestResponse  $response  통보 응답
     * @return void 반환값 없음
     */
    private function assertKcpNotifyOk(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
        $this->assertStringContainsString('value="0000"', $response->getContent());
    }

    /**
     * 주문에 기록된 것과 다른 거래번호로 온 통보는 재통보를 요구하고 구매확정 훅을 발행하지 않는지 확인한다.
     *
     * @scenario caller=pg_server_verified
     *
     * @effects kcp_escrow_notify_retries_on_tno_mismatch_without_hooks
     *
     * @return void 반환값 없음
     */
    public function test_escrow_common_notify_retries_on_tno_mismatch_before_hooks(): void
    {
        $order = $this->createEscrowOrder('KCP_ESCROW_TNO_REAL');
        $hookCalled = false;

        HookManager::addAction(
            'sirsoft-pay_nhnkcp.escrow.purchase_confirmed',
            function () use (&$hookCalled): void {
                $hookCalled = true;
            }
        );

        $response = $this->postEscrowNotify([
            'site_cd' => 'T0000',
            'tno' => 'ATTACKER_ESCROW_TNO',
            'order_no' => $order->order_number,
            'tx_cd' => 'TX02',
            'cl_status' => '2',
        ]);

        $this->assertKcpNotifyRetry($response);
        $this->assertFalse($hookCalled, '검증 실패한 에스크로 통보는 hook 을 발행하지 않아야 합니다.');
    }

    /**
     * 거래번호가 일치하는 구매확정 통보는 0000 으로 응답하고 구매확정 훅을 발행하는지 확인한다.
     *
     * @scenario caller=pg_server_verified
     *
     * @effects kcp_escrow_notify_with_matching_tno_fires_purchase_confirmed_hook
     *
     * @return void 반환값 없음
     */
    public function test_escrow_common_notify_processes_matching_tno(): void
    {
        $order = $this->createEscrowOrder('KCP_ESCROW_TNO_REAL');
        $hookCalled = false;

        HookManager::addAction(
            'sirsoft-pay_nhnkcp.escrow.purchase_confirmed',
            function () use (&$hookCalled): void {
                $hookCalled = true;
            }
        );

        $response = $this->postEscrowNotify([
            'site_cd' => 'T0000',
            'tno' => 'KCP_ESCROW_TNO_REAL',
            'order_no' => $order->order_number,
            'tx_cd' => 'TX02',
            'cl_status' => '2',
        ]);

        $this->assertKcpNotifyOk($response);
        $this->assertTrue($hookCalled);
    }

    /**
     * 같은 에스크로 통보가 두 번 와도 두 번 모두 0000 으로 응답하되 구매확정 훅은 한 번만 발행하는지 확인한다.
     *
     * @scenario threat=replay, callback_state=second_arrival_same_tno
     *
     * @effects replay_no_double_completion, replay_response_is_idempotent_ok
     *
     * @return void 반환값 없음
     */
    public function test_escrow_common_notify_does_not_dispatch_duplicate_payload_twice(): void
    {
        $order = $this->createEscrowOrder('KCP_ESCROW_TNO_REAL');
        $hookCount = 0;

        HookManager::addAction(
            'sirsoft-pay_nhnkcp.escrow.purchase_confirmed',
            function () use (&$hookCount): void {
                $hookCount++;
            }
        );

        $payload = [
            'site_cd' => 'T0000',
            'tno' => 'KCP_ESCROW_TNO_REAL',
            'order_no' => $order->order_number,
            'tx_cd' => 'TX02',
            'tx_tm' => '20260624164000',
            'cl_status' => '2',
        ];

        $first = $this->postEscrowNotify($payload);
        $second = $this->postEscrowNotify($payload);

        $this->assertKcpNotifyOk($first);
        $this->assertKcpNotifyOk($second);
        $this->assertSame(1, $hookCount, '동일 에스크로 통보 replay 는 hook 을 한 번만 발행해야 합니다.');
    }
}
