<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature;

use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayKginicis\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 입금 전 취소된 가상계좌 — KG이니시스 채번 취소와 취소 후 입금 기록 (P-D1 · Q11)
 *
 * 시나리오 매니페스트: `tests/scenarios/vbank-cancel-before-deposit.yaml`
 */
class VbankCancelBeforeDepositTest extends PluginTestCase
{
    private const TEST_MID = 'INIpayTest';

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
            'test_mid' => self::TEST_MID,
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
            'live_mid' => '',
            'live_sign_key' => '',
            'live_iniapi_key' => '',
            'live_iniapi_iv' => '',
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 취소된 가상계좌 주문을 만듭니다.
     *
     * @param  string  $tid  발급 거래번호
     * @param  string  $provider  결제행 제공자
     * @return Order 주문
     */
    private function cancelledVbankOrder(string $tid, string $provider = 'kginicis'): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-PD1K-'.random_int(10000, 99999),
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
            'transaction_id' => $tid,
            'paid_at' => null,
            'currency' => 'KRW',
            'vbank_number' => '1234567890',
            'payment_meta' => ['pay_method' => 'VBank', 'mid' => self::TEST_MID, 'is_test_mode' => true],
        ]);

        return $order->fresh(['payment']);
    }

    /**
     * 입금통보 본문을 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  string  $tid  통보 거래번호
     * @return array<string, string> 통보 본문
     */
    private function notifyPayload(Order $order, string $tid): array
    {
        return [
            'no_tid' => $tid,
            'no_oid' => $order->order_number,
            'id_merchant' => self::TEST_MID,
            'dt_trans' => now()->format('Ymd'),
            'tm_trans' => now()->format('His'),
            'cd_bank' => '04',
            'no_vacct' => '1234567890',
            'amt_input' => '30000',
            'nm_inputbank' => '국민은행',
            'nm_input' => '홍길동',
        ];
    }

    /**
     * 자기 결제의 미입금 가상계좌는 전액 취소(`refund`)로 채번 취소하고 handled=true 로 답한다.
     *
     * @scenario role=close
     *
     * @effects kg_closes_unpaid_vbank_with_full_cancel
     *
     * @return void 반환값 없음
     */
    public function test_unpaid_vbank_is_closed_with_a_full_cancel(): void
    {
        $api = $this->createMock(KgInicisApiService::class);
        $api->expects($this->once())->method('cancelPayment')
            ->with('KG_TID_PD1', 'VBank', null, $this->anything())
            ->willReturn(['resultCode' => '00']);
        $this->app->instance(KgInicisApiService::class, $api);
        $order = $this->cancelledVbankOrder('KG_TID_PD1');

        $result = app(PaymentRefundListener::class)->closeVbank(['handled' => false, 'success' => false], $order, $order->payment, 30000);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);

        $other = $this->cancelledVbankOrder('KG_TID_OTHER', 'other_pg');
        $this->assertFalse(app(PaymentRefundListener::class)->closeVbank(['handled' => false, 'success' => false], $other, $other->payment, 30000)['handled']);
    }

    /**
     * 취소된 주문의 입금 통보는 발급 거래번호·상점이 맞으면 기록하고 OK 로 재통보를 멈춘다.
     *
     * @scenario role=deposit_webhook
     *
     * @effects kg_deposit_on_cancelled_order_is_recorded
     *
     * @return void 반환값 없음
     */
    public function test_deposit_on_a_cancelled_order_is_recorded(): void
    {
        $order = $this->cancelledVbankOrder('KG_TID_DEP');

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $this->notifyPayload($order, 'KG_TID_DEP'));

        $this->assertSame('OK', $response->getContent());
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
        $record = $order->payment->fresh()->payment_meta['deposit_after_cancel'] ?? null;
        $this->assertIsArray($record, '취소 후 입금이 기록되지 않았다');
        $this->assertEquals(30000, $record['amount']);
    }

    /**
     * 발급 거래번호와 다른 통보는 기록하지 않고 종전대로 FAIL 이다.
     *
     * @scenario role=deposit_webhook
     *
     * @effects kg_deposit_on_cancelled_order_is_recorded
     *
     * @return void 반환값 없음
     */
    public function test_mismatched_tid_is_not_recorded(): void
    {
        $order = $this->cancelledVbankOrder('KG_TID_REAL');

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $this->notifyPayload($order, 'KG_TID_FORGED'));

        $this->assertSame('FAIL', $response->getContent());
        $this->assertArrayNotHasKey('deposit_after_cancel', $order->payment->fresh()->payment_meta ?? []);
    }
}
