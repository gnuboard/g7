<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 입금 전 취소된 가상계좌 — KCP 발급 취소와 취소 후 입금 기록 (P-D1 · Q11)
 *
 * 시나리오 매니페스트: `tests/scenarios/vbank-cancel-before-deposit.yaml`
 */
class VbankCancelBeforeDepositTest extends PluginTestCase
{
    private const TEST_SITE_CD = 'T0000';

    /**
     * 플러그인 설정을 테스트 상점으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_site_cd' => self::TEST_SITE_CD,
            'test_site_key' => 'TEST_SITE_KEY_0000',
            'live_site_cd' => '',
            'live_site_key' => '',
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 취소된 가상계좌 주문을 만듭니다.
     *
     * @param  string  $tno  발급 거래번호
     * @param  string  $provider  결제행 제공자
     * @return Order 주문
     */
    private function cancelledVbankOrder(string $tno, string $provider = 'nhnkcp'): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-PD1C-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::CANCELLED,
            'currency' => 'KRW',
            'currency_snapshot' => ['base_currency' => 'KRW', 'order_currency' => 'KRW'],
            'total_amount' => 30000,
            'total_due_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::CANCELLED,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => $provider,
            'transaction_id' => $tno,
            'paid_at' => null,
            'currency' => 'KRW',
            'vbank_number' => 'T1234567890',
            'payment_meta' => ['tno' => $tno, 'site_cd' => self::TEST_SITE_CD, 'is_test_mode' => true],
        ]);

        return $order->fresh(['payment']);
    }

    /**
     * KCP 발신 IP 로 입금통보를 보냅니다.
     *
     * @param  Order  $order  주문
     * @param  string  $tno  통보 거래번호
     * @return TestResponse 응답
     */
    private function postNotify(Order $order, string $tno): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.238.36.58'])
            ->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', [
                'site_cd' => self::TEST_SITE_CD,
                'tno' => $tno,
                'order_no' => $order->order_number,
                'tx_cd' => 'TX00',
                'tx_tm' => now()->format('YmdHis'),
                'op_cd' => '50',
                'ipgm_mnyx' => 30000,
                'ipgm_name' => '홍길동',
                'remitter' => '홍길동',
                'bank_code' => 'BK04',
                'account' => 'T1234567890',
                'noti_id' => uniqid('NOTI_'),
            ]);
    }

    /**
     * 자기 결제의 미입금 가상계좌는 전체 취소로 발급 취소하고 handled=true 로 답한다.
     *
     * @scenario role=close
     *
     * @effects kcp_closes_unpaid_vbank_with_full_cancel
     *
     * @return void 반환값 없음
     */
    public function test_unpaid_vbank_is_closed_with_a_full_cancel(): void
    {
        $api = $this->createMock(NhnKcpApiService::class);
        $api->expects($this->once())->method('cancelPayment')
            ->with('KCP_TNO_PD1', $this->anything(), 30000, $this->anything(), false, 30000, null)
            ->willReturn(['res_cd' => '0000']);
        $this->app->instance(NhnKcpApiService::class, $api);
        $order = $this->cancelledVbankOrder('KCP_TNO_PD1');

        $result = app(PaymentRefundListener::class)->closeVbank(['handled' => false, 'success' => false], $order, $order->payment, 30000);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);

        $other = $this->cancelledVbankOrder('KCP_TNO_OTHER', 'other_pg');
        $this->assertFalse(app(PaymentRefundListener::class)->closeVbank(['handled' => false, 'success' => false], $other, $other->payment, 30000)['handled']);
    }

    /**
     * 취소된 주문의 입금 통보는 기록하고 0000 으로 답한다 — 종전 9999(재통보 10회)가 아니다.
     *
     * @scenario role=deposit_webhook
     *
     * @effects kcp_deposit_on_cancelled_order_is_recorded
     *
     * @return void 반환값 없음
     */
    public function test_deposit_on_a_cancelled_order_is_recorded(): void
    {
        $order = $this->cancelledVbankOrder('KCP_TNO_DEP');

        $response = $this->postNotify($order, 'KCP_TNO_DEP');

        $this->assertStringContainsString('value="0000"', $response->getContent());
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
        $record = $order->payment->fresh()->payment_meta['deposit_after_cancel'] ?? null;
        $this->assertIsArray($record, '취소 후 입금이 기록되지 않았다');
        $this->assertEquals(30000, $record['amount']);
    }

    /**
     * 발급 거래번호와 다른 통보는 기록하지 않고 종전대로 재통보를 요청한다.
     *
     * @scenario role=deposit_webhook
     *
     * @effects kcp_deposit_on_cancelled_order_is_recorded
     *
     * @return void 반환값 없음
     */
    public function test_mismatched_tno_is_not_recorded(): void
    {
        $order = $this->cancelledVbankOrder('KCP_TNO_REAL');

        $response = $this->postNotify($order, 'KCP_TNO_FORGED');

        $this->assertStringNotContainsString('value="0000"', $response->getContent());
        $this->assertArrayNotHasKey('deposit_after_cancel', $order->payment->fresh()->payment_meta ?? []);
    }
}
