<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Payment;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Listeners\CloseUnpaidVbankOnCancelListener;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\DepositAfterCancelRecorder;
use Modules\Sirsoft\Ecommerce\Services\UnpaidVbankCloser;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 입금 전에 취소된 가상계좌 주문 — PG 계좌 폐쇄와 취소 후 입금 기록 (P-D1 · Q11)
 *
 * 입금 전 취소는 돌려줄 돈이 없어 PG 를 부르지 않았고, 발급된 가상계좌가 PG 에 남아 고객이 뒤늦게 입금할 수 있었다.
 * 취소된 주문에 들어온 입금은 로그 한 줄로만 남았다.
 *
 * 시나리오 매니페스트: `tests/scenarios/unpaid-vbank-cancel.yaml`
 */
class UnpaidVbankCancelTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화 설정과 클레임 사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 입금 대기 가상계좌 주문을 만듭니다.
     *
     * @return Order 주문
     */
    private function waitingVbankOrder(): Order
    {
        $order = $this->paidClaimOrder(29000, 1);
        $order->payment->forceFill([
            'payment_method' => PaymentMethodEnum::VBANK->value,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'pg_provider' => 'testpg',
            'transaction_id' => 'VBANK-TID-1',
            'paid_at' => null,
            'vbank_number' => '1234567890',
        ])->save();

        return $order->fresh(['payment']);
    }

    /**
     * 가상계좌 폐쇄 필터에 자기 제공자만 받는 가짜 플러그인을 등록합니다.
     *
     * @param  bool  $success  PG 폐쇄 성공 여부
     * @param  array<int, int>  $amounts  받은 발급 금액 기록
     * @return void 반환값 없음
     */
    private function fakeClosePlugin(bool $success, array &$amounts): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.close_vbank', function (array $result, $order, $payment, int $issuedAmount = 0) use ($success, &$amounts) {
            if ($payment->pg_provider !== 'testpg') {
                return $result;
            }
            $amounts[] = $issuedAmount;

            return ['handled' => true, 'success' => $success, 'error_message' => $success ? null : 'PG 거절'];
        });
    }

    /**
     * 입금 전 취소가 커밋되면 이 주문의 결제 플러그인에게 계좌를 닫게 하고, 닫힌 시각을 남긴다.
     *
     * @scenario close_result=success
     *
     * @effects unpaid_vbank_is_closed_after_cancel
     *
     * @return void 반환값 없음
     */
    public function test_closing_succeeds_and_is_recorded(): void
    {
        $amounts = [];
        $this->fakeClosePlugin(true, $amounts);
        $order = $this->waitingVbankOrder();

        app(CloseUnpaidVbankOnCancelListener::class)->closeVbank($order, [], ['total_due_amount' => 29000]);

        $this->assertSame([29000], $amounts, '취소 전 입금 예정액이 발급 금액으로 넘어가야 한다');
        $meta = $order->payment->fresh()->payment_meta;
        $this->assertNotEmpty($meta['vbank_closed_at'] ?? null);
        $this->assertArrayNotHasKey('vbank_close_failed', $meta);

        // 이미 닫은 계좌는 다시 부르지 않는다
        app(UnpaidVbankCloser::class)->close($order->fresh());
        $this->assertCount(1, $amounts);
    }

    /**
     * PG 가 거절하거나 응답한 플러그인이 없으면 `vbank_close_failed` 를 남기고, 관리자 결제 정보가 그 사실을 싣는다.
     *
     * @scenario close_result=failure
     *
     * @effects vbank_close_failure_is_flagged_for_the_operator
     *
     * @return void 반환값 없음
     */
    public function test_closing_failure_is_flagged(): void
    {
        $order = $this->waitingVbankOrder();

        // 응답한 플러그인이 없음(비활성)
        app(UnpaidVbankCloser::class)->close($order, ['total_due_amount' => 29000]);
        $meta = $order->payment->fresh()->payment_meta;
        $this->assertTrue($meta['vbank_close_failed'] ?? false);
        $this->assertSame('plugin_inactive', $meta['vbank_close_error'] ?? null);

        $resource = (new OrderPaymentResource($order->payment->fresh()))->toArray(request());
        $this->assertTrue($resource['vbank_close_failed']);
    }

    /**
     * 입금된 가상계좌·PG 를 거치지 않는 결제는 닫지 않는다.
     *
     * @scenario close_result=not_applicable
     *
     * @effects unpaid_vbank_is_closed_after_cancel
     *
     * @return void 반환값 없음
     */
    public function test_paid_or_non_vbank_payments_are_left_alone(): void
    {
        $amounts = [];
        $this->fakeClosePlugin(true, $amounts);
        $order = $this->waitingVbankOrder();
        $order->payment->forceFill(['paid_at' => now()])->save();

        $this->assertNull(app(UnpaidVbankCloser::class)->close($order->fresh()));
        $this->assertSame([], $amounts);
    }

    /**
     * 취소된 주문의 입금은 결제행에 기록되고 운영자 알림 훅이 한 번만 나간다 — 같은 거래의 재통보는 건너뛴다.
     *
     * @scenario close_result=deposit_after_cancel
     *
     * @effects deposit_after_cancel_is_recorded_and_notified_once
     *
     * @return void 반환값 없음
     */
    public function test_deposit_after_cancel_is_recorded_once(): void
    {
        $fired = [];
        HookManager::addAction('sirsoft-ecommerce.order.deposit_after_cancel', function ($order, $context) use (&$fired) {
            $fired[] = $context['amount'];
        });
        $order = $this->waitingVbankOrder();

        $recorder = app(DepositAfterCancelRecorder::class);
        $this->assertTrue($recorder->record($order, 29000.0, 'testpg', 'DEP-1'));
        $this->assertFalse($recorder->record($order, 29000.0, 'testpg', 'DEP-1'), '재통보가 다시 기록됐다');

        $this->assertSame([29000.0], $fired);
        $record = $order->payment->fresh()->payment_meta['deposit_after_cancel'];
        $this->assertSame('testpg', $record['provider']);

        $resource = (new OrderPaymentResource($order->payment->fresh()))->toArray(request());
        $this->assertStringContainsString('29,000', $resource['deposit_after_cancel']['amount_formatted']);
    }

    /**
     * 운영자 알림 데이터는 입금액을 싣는다 — 주문 총액이 아니다.
     *
     * @scenario close_result=deposit_after_cancel
     *
     * @effects deposit_after_cancel_is_recorded_and_notified_once
     *
     * @return void 반환값 없음
     */
    public function test_staff_notification_data_carries_the_deposit_amount(): void
    {
        $order = $this->waitingVbankOrder();

        $result = app(EcommerceNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'deposit_after_cancel_admin',
            [$order, ['amount' => 15000.0, 'provider' => 'testpg']],
        );

        $this->assertSame($order->order_number, $result['data']['order_number']);
        $this->assertStringContainsString('15,000', $result['data']['deposit_amount']);
    }
}
