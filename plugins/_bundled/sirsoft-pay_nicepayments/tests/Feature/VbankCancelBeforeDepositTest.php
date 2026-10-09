<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNicepayments\Listeners\VbankCloseListener;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 입금 전 취소된 가상계좌 — 나이스페이 발급 취소와 취소 후 입금 기록 (P-D1 · Q11)
 *
 * 시나리오 매니페스트: `tests/scenarios/vbank-cancel-before-deposit.yaml`
 */
class VbankCancelBeforeDepositTest extends PluginTestCase
{
    private const TEST_MID = 'nicepay00m';

    private const TEST_MERCHANT_KEY = 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==';

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
            'test_merchant_key' => self::TEST_MERCHANT_KEY,
            'live_mid' => '',
            'live_merchant_key' => '',
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 취소된 가상계좌 주문을 만듭니다. (입금 전 거래번호는 payment_meta.vbank_tid)
     *
     * @param  string  $tid  발급 거래번호
     * @param  string  $provider  결제행 제공자
     * @return Order 주문
     */
    private function cancelledVbankOrder(string $tid, string $provider = 'nicepayments'): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-PD1N-'.random_int(10000, 99999),
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
            'transaction_id' => null,
            'paid_at' => null,
            'currency' => 'KRW',
            'vbank_number' => '1234567890',
            'payment_meta' => ['vbank_tid' => $tid, 'mid' => self::TEST_MID, 'is_test_mode' => true],
        ]);

        return $order->fresh(['payment']);
    }

    /**
     * 자기 결제의 미입금 가상계좌는 발급 거래번호로 전액 취소(부분취소 코드 0)하고 handled=true 로 답한다.
     *
     * @scenario role=close
     *
     * @effects nice_closes_unpaid_vbank_with_full_cancel
     *
     * @return void 반환값 없음
     */
    public function test_unpaid_vbank_is_closed_with_a_full_cancel(): void
    {
        $api = $this->createMock(NicePaymentsApiService::class);
        $api->expects($this->once())->method('cancelPayment')
            ->with('VBANK_TID_PD1', $this->anything(), 30000, $this->anything(), 0)
            ->willReturn(['ResultCode' => '2001']);
        $this->app->instance(NicePaymentsApiService::class, $api);
        $order = $this->cancelledVbankOrder('VBANK_TID_PD1');

        $result = app(VbankCloseListener::class)->closeVbank(['handled' => false, 'success' => false], $order, $order->payment, 30000);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);

        $other = $this->cancelledVbankOrder('VBANK_TID_OTHER', 'other_pg');
        $this->assertFalse(app(VbankCloseListener::class)->closeVbank(['handled' => false, 'success' => false], $other, $other->payment, 30000)['handled']);
    }

    /**
     * 취소된 주문의 입금 통보는 PG 거래 조회로 입금이 확인되면 기록하고 OK 로 재통보를 멈춘다.
     *
     * @scenario role=deposit_webhook
     *
     * @effects nice_deposit_on_cancelled_order_is_recorded
     *
     * @return void 반환값 없음
     */
    public function test_confirmed_deposit_on_a_cancelled_order_is_recorded(): void
    {
        $order = $this->cancelledVbankOrder('VBANK_TID_DEP');
        Http::fake(['webapi.nicepay.co.kr/webapi/inquery/trans_status.jsp' => Http::response([
            'ResultCode' => '0000', 'TID' => 'VBANK_TID_DEP', 'Status' => '0', 'Amt' => '30000',
        ], 200)]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/vbank-notify', [
            'PG' => 'nicepay', 'PayMethod' => 'VBANK', 'MID' => self::TEST_MID, 'MOID' => $order->order_number,
            'TID' => 'VBANK_TID_DEP', 'Amt' => 30000, 'ResultCode' => '4110', 'ResultMsg' => '입금완료',
            'AuthDate' => now()->format('YmdHis'), 'AuthCode' => 'AUTH1', 'VbankNum' => '1234567890',
            'VbankName' => '국민은행', 'VbankInputName' => '홍길동', 'FnCd' => '004', 'FnName' => '국민은행',
        ]);

        $this->assertSame('OK', $response->getContent());
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
        $record = $order->payment->fresh()->payment_meta['deposit_after_cancel'] ?? null;
        $this->assertIsArray($record, '취소 후 입금이 기록되지 않았다');
        $this->assertEquals(30000, $record['amount']);
    }

    /**
     * 발급 거래번호와 다른 통보는 기록하지 않고 종전대로 FAIL 이다(통보에는 서명이 없다).
     *
     * @scenario role=deposit_webhook
     *
     * @effects nice_deposit_on_cancelled_order_is_recorded
     *
     * @return void 반환값 없음
     */
    public function test_mismatched_tid_is_not_recorded(): void
    {
        $order = $this->cancelledVbankOrder('VBANK_TID_REAL');
        Http::fake(['webapi.nicepay.co.kr/*' => Http::response(['ResultCode' => '0000', 'Status' => '0'], 200)]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/vbank-notify', [
            'PG' => 'nicepay', 'PayMethod' => 'VBANK', 'MID' => self::TEST_MID, 'MOID' => $order->order_number,
            'TID' => 'VBANK_TID_FORGED', 'Amt' => 30000, 'ResultCode' => '4110', 'ResultMsg' => '입금완료',
            'AuthDate' => now()->format('YmdHis'), 'AuthCode' => 'AUTH1', 'VbankNum' => '1234567890',
            'VbankName' => '국민은행', 'VbankInputName' => '홍길동', 'FnCd' => '004', 'FnName' => '국민은행',
        ]);

        $this->assertSame('FAIL', $response->getContent());
        $this->assertArrayNotHasKey('deposit_after_cancel', $order->payment->fresh()->payment_meta ?? []);
    }
}
