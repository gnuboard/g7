<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Listeners\VbankCloseListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 입금 전 취소된 가상계좌 — 토스 발급 취소와 취소 후 입금 기록 (P-D1 · Q11)
 *
 * 시나리오 매니페스트: `tests/scenarios/vbank-cancel-before-deposit.yaml`
 */
class VbankCancelBeforeDepositTest extends PluginTestCase
{
    private const SECRET = 'ps_test_secret_pd1';

    /**
     * 플러그인 설정을 테스트 모드로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn(['is_test_mode' => true, 'webhook_secret_verify' => true]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 취소된 가상계좌 주문을 만듭니다.
     *
     * @param  string  $provider  결제행 제공자
     * @return Order 주문
     */
    private function cancelledVbankOrder(string $provider = 'tosspayments'): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-PD1-'.random_int(10000, 99999),
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
            'transaction_id' => 'pk_pd1_'.$order->id,
            'paid_amount_base' => 30000,
            'paid_amount_local' => 30000,
            'paid_at' => null,
            'currency' => 'KRW',
            'payment_meta' => ['toss_secret' => self::SECRET, 'toss_approved_amount' => 30000],
        ]);

        return $order->fresh(['payment']);
    }

    /**
     * 자기 결제의 미입금 가상계좌는 결제 취소 API(전액 — 금액·환불계좌 없이)로 발급 취소하고 handled=true 로 답한다.
     *
     * @scenario role=close
     *
     * @effects toss_closes_unpaid_vbank_with_full_cancel
     *
     * @return void 반환값 없음
     */
    public function test_unpaid_vbank_is_closed_with_a_full_cancel(): void
    {
        Http::fake(['api.tosspayments.com/*' => Http::response(['status' => 'CANCELED', 'cancels' => []], 200)]);
        $order = $this->cancelledVbankOrder();

        $result = app(VbankCloseListener::class)->closeVbank(['handled' => false, 'success' => false], $order, $order->payment, 30000);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/payments/pk_pd1_'.$order->id.'/cancel')
            && ! isset($request->data()['cancelAmount'])
            && ! isset($request->data()['refundReceiveAccount']));

        $other = $this->cancelledVbankOrder('other_pg');
        $passed = app(VbankCloseListener::class)->closeVbank(['handled' => false, 'success' => false], $other, $other->payment, 30000);
        $this->assertFalse($passed['handled'], '다른 PG 결제를 처리했다');
    }

    /**
     * PG 가 거절하면 handled=true, success=false 로 사유를 돌려준다(주문 취소는 그대로 — 모듈이 표식을 남긴다).
     *
     * @scenario role=close
     *
     * @effects toss_closes_unpaid_vbank_with_full_cancel
     *
     * @return void 반환값 없음
     */
    public function test_close_failure_is_reported_as_handled(): void
    {
        Http::fake(['api.tosspayments.com/*' => Http::response(['code' => 'NOT_CANCELABLE', 'message' => '취소 불가'], 400)]);
        $order = $this->cancelledVbankOrder();

        $result = app(VbankCloseListener::class)->closeVbank(['handled' => false, 'success' => false], $order, $order->payment, 30000);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
    }

    /**
     * 취소된 주문의 입금 통보(DONE)는 주문을 되살리지 않고 결제행에 기록한 뒤 OK 로 답한다.
     *
     * @scenario role=deposit_webhook
     *
     * @effects toss_deposit_on_cancelled_order_is_recorded
     *
     * @return void 반환값 없음
     */
    public function test_deposit_on_a_cancelled_order_is_recorded(): void
    {
        $order = $this->cancelledVbankOrder();

        $response = $this->postJson('/plugins/sirsoft-tosspayments/webhook/deposit', [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => 'txn_pd1',
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
        $record = $order->payment->fresh()->payment_meta['deposit_after_cancel'] ?? null;
        $this->assertIsArray($record, '취소 후 입금이 기록되지 않았다');
        $this->assertEquals(30000, $record['amount']);
        $this->assertSame('tosspayments', $record['provider']);
    }
}
