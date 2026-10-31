<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNicepayments\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 나이스페이 부분취소 제약을 접수 단계에서 먼저 알린다 (P-D7) · 부분취소 회차 번호를 거래 ID 에 남긴다 (P-D11)
 *
 * 나이스페이 결과코드표(구 WebAPI 보관본 manual-code.php)는 에스크로 거래의 부분취소를 2052 「에스크로 거래는 부분취소 불가」 로
 * 거절한다. 휴대폰 결제는 통신사 월 마감 때문에 결제한 달에만 취소된다(토스 플러그인과 같은 KST 당월 판정).
 * 취소 응답(manual-cancel.php)의 `TID` 는 원거래 ID 이고 회차를 가리키는 값은 `CancelNum`(취소번호)이다 — 부분취소를
 * 여러 번 하면 환불 기록의 거래 ID 가 모두 같아져 회차를 구분할 수 없었다.
 *
 * 시나리오 매니페스트: `tests/scenarios/partial-cancel-constraints.yaml`
 */
class PartialCancelConstraintsTest extends PluginTestCase
{
    /**
     * 나이스페이 결제완료 주문을 만듭니다.
     *
     * @param  array<string, mixed>  $paymentOverrides  결제행 덮어쓰기
     * @return Order 주문
     */
    private function paidOrder(array $paymentOverrides = []): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-PD7-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'currency' => 'KRW',
            'currency_snapshot' => ['base_currency' => 'KRW', 'order_currency' => 'KRW'],
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
        ]);

        OrderPaymentFactory::new()->create(array_merge([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => 'card',
            'pg_provider' => 'nicepayments',
            'transaction_id' => 'nicepay00m0101'.$order->id,
            'paid_amount_local' => 30000,
            'paid_amount_base' => 30000,
            'cancelled_amount' => 0,
            'currency' => 'KRW',
            'is_escrow' => false,
            'vbank_number' => null,
            'paid_at' => now(),
            'payment_meta' => ['mid' => 'nicepay00m', 'is_test_mode' => true],
        ], $paymentOverrides));

        return $order->fresh(['payment']);
    }

    /**
     * 에스크로 결제는 부분취소를 지원하지 않는다고 미리 답한다(결과코드 2052).
     *
     * @scenario method=escrow
     *
     * @effects escrow_payment_declares_no_partial_cancel
     *
     * @return void 반환값 없음
     */
    public function test_escrow_payment_does_not_support_partial_cancel(): void
    {
        $listener = app(PaymentRefundListener::class);

        $this->assertTrue($listener->supportsPartialCancel(true, $this->paidOrder()->payment), '일반 카드 결제는 부분취소할 수 있다');
        $this->assertFalse($listener->supportsPartialCancel(true, $this->paidOrder(['is_escrow' => true])->payment), '에스크로는 2052 로 거절된다');
    }

    /**
     * 지난달(한국 시간) 휴대폰 결제는 부분취소를 지원하지 않는다 — 이번 달 결제는 지원한다.
     *
     * @scenario method=phone
     *
     * @effects phone_payment_from_earlier_month_declares_no_partial_cancel
     *
     * @return void 반환값 없음
     */
    public function test_phone_payment_from_earlier_month_does_not_support_partial_cancel(): void
    {
        $listener = app(PaymentRefundListener::class);

        $lastMonth = $this->paidOrder(['payment_method' => 'phone', 'paid_at' => now('Asia/Seoul')->subMonthNoOverflow()->startOfMonth()->addDays(2)]);
        $thisMonth = $this->paidOrder(['payment_method' => 'phone', 'paid_at' => now()]);

        $this->assertFalse($listener->supportsPartialCancel(true, $lastMonth->payment), '달이 바뀐 휴대폰 결제는 취소되지 않는다');
        $this->assertTrue($listener->supportsPartialCancel(true, $thisMonth->payment));
    }

    /**
     * 다른 PG 결제는 판정하지 않고, 앞선 판정이 false 면 그대로 둔다.
     *
     * @scenario method=escrow
     *
     * @effects other_provider_partial_cancel_is_untouched
     *
     * @return void 반환값 없음
     */
    public function test_other_provider_and_prior_false_are_left_as_is(): void
    {
        $listener = app(PaymentRefundListener::class);

        $this->assertTrue($listener->supportsPartialCancel(true, $this->paidOrder(['pg_provider' => 'other_pg', 'is_escrow' => true])->payment));
        $this->assertFalse($listener->supportsPartialCancel(false, $this->paidOrder()->payment));
    }

    /**
     * 부분취소 응답의 취소번호(`CancelNum`)를 거래 ID 에 붙여 회차를 구분한다. 전액취소는 응답 TID 그대로다.
     *
     * @scenario method=card
     *
     * @effects partial_cancel_transaction_id_carries_cancel_number
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_transaction_id_carries_cancel_number(): void
    {
        $order = $this->paidOrder();
        $tid = $order->payment->transaction_id;
        $api = $this->createMock(NicePaymentsApiService::class);
        $api->method('cancelPayment')->willReturn(['ResultCode' => '2001', 'TID' => $tid, 'CancelNum' => '00000002']);
        $this->app->instance(NicePaymentsApiService::class, $api);

        $partial = app(PaymentRefundListener::class)->processRefund(['success' => false], $order, $order->payment, 10000.0, '부분');
        $this->assertTrue($partial['success']);
        $this->assertSame($tid.'-00000002', $partial['transaction_id'], '부분취소 회차가 거래 ID 에 남아야 한다');

        $full = $this->paidOrder();
        $fullTid = $full->payment->transaction_id;
        $api2 = $this->createMock(NicePaymentsApiService::class);
        $api2->method('cancelPayment')->willReturn(['ResultCode' => '2001', 'TID' => $fullTid, 'CancelNum' => '00000001']);
        $this->app->instance(NicePaymentsApiService::class, $api2);
        $full->payment->update(['cancelled_amount' => 30000]);

        $whole = app(PaymentRefundListener::class)->processRefund(['success' => false], $full, $full->payment->fresh(), 30000.0, '전액');
        $this->assertSame($fullTid, $whole['transaction_id'], '전액취소는 원거래 ID 그대로');
    }
}
