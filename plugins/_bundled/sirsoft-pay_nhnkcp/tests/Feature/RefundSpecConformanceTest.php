<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * NHN KCP 취소 요청을 공개 사양에 맞춘다 — #747 C8
 *
 * - P-D7: 부분취소를 거절당할 결제(에스크로, 달이 바뀐 휴대폰, 입금된 가상계좌)는 접수 단계에서 먼저 알린다.
 * - P-D8: 복합과세(TG03)로 승인된 결제의 부분취소는 과세·부가세·비과세 취소 금액을 함께 보낸다(합 = 취소 금액).
 * - P-D9: 입금된 가상계좌 환불은 플러그인이 보내던 계좌 필드가 공개 사양 어디와도 맞지 않아, 결제사로 보내지 않고 계좌 환불로 안내한다.
 * - P-D11: 부분취소 응답의 `tno` 는 원거래 번호다 — 회차는 `mod_pcan_seq_no`(부분취소 일련번호)로 구분한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/refund-spec-conformance.yaml`
 */
class RefundSpecConformanceTest extends PluginTestCase
{
    /**
     * KCP 결제완료 주문을 만듭니다.
     *
     * @param  array<string, mixed>  $paymentOverrides  결제행 덮어쓰기
     * @param  array<string, mixed>  $orderOverrides  주문 덮어쓰기
     * @return Order 주문
     */
    private function paidOrder(array $paymentOverrides = [], array $orderOverrides = []): Order
    {
        $order = OrderFactory::new()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KCPC8-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'currency' => 'KRW',
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_tax_amount' => 30000,
            'total_vat_amount' => 2727,
            'total_tax_free_amount' => 0,
        ], $orderOverrides));

        OrderPaymentFactory::new()->create(array_merge([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => 'card',
            'pg_provider' => 'nhnkcp',
            'transaction_id' => '2099123'.str_pad((string) $order->id, 7, '0', STR_PAD_LEFT),
            'paid_amount_local' => 30000,
            'paid_amount_base' => 30000,
            'cancelled_amount' => 0,
            'currency' => 'KRW',
            'is_escrow' => false,
            'vbank_number' => null,
            'paid_at' => now(),
            'payment_meta' => ['site_cd' => 'T0000', 'is_test_mode' => true],
        ], $paymentOverrides));

        return $order->fresh(['payment']);
    }

    /**
     * 에스크로·지난달 휴대폰·입금된 가상계좌는 부분취소를 지원하지 않고, 카드·이번 달 휴대폰은 지원한다.
     *
     * @scenario method=escrow
     *
     * @effects partial_cancel_support_follows_kcp_constraints
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_support_follows_kcp_constraints(): void
    {
        $listener = app(PaymentRefundListener::class);

        $this->assertTrue($listener->supportsPartialCancel(true, $this->paidOrder()->payment));
        $this->assertFalse($listener->supportsPartialCancel(true, $this->paidOrder(['is_escrow' => true])->payment), '에스크로 부분취소는 막는다');
        $this->assertFalse($listener->supportsPartialCancel(true, $this->paidOrder(['payment_method' => 'phone', 'paid_at' => now('Asia/Seoul')->subMonthNoOverflow()->startOfMonth()->addDays(2)])->payment), '당월 거래만 취소된다');
        $this->assertTrue($listener->supportsPartialCancel(true, $this->paidOrder(['payment_method' => 'phone', 'paid_at' => now()])->payment));
        $this->assertFalse($listener->supportsPartialCancel(true, $this->paidOrder(['payment_method' => 'vbank', 'vbank_number' => '1234567890'])->payment), '입금된 가상계좌는 자동 환불하지 않는다');
        $this->assertTrue($listener->supportsPartialCancel(true, $this->paidOrder(['pg_provider' => 'other_pg', 'is_escrow' => true])->payment), '다른 PG 결제는 판정하지 않는다');
        $this->assertFalse($listener->supportsPartialCancel(false, $this->paidOrder()->payment));
    }

    /**
     * 복합과세 주문(비과세 10,000 · 과세 20,000 중 부가세 1,818)을 15,000 부분취소하면
     * 비과세 5,000 · 과세 공급가 9,091 · 부가세 909 를 함께 보낸다(합 15,000).
     *
     * @scenario method=card
     *
     * @effects tg03_partial_cancel_sends_tax_split
     *
     * @return void 반환값 없음
     */
    public function test_tg03_partial_cancel_sends_tax_split(): void
    {
        $order = $this->paidOrder([], ['total_tax_amount' => 20000, 'total_vat_amount' => 1818, 'total_tax_free_amount' => 10000]);
        $api = $this->createMock(NhnKcpApiService::class);
        $api->expects($this->once())->method('cancelPayment')
            ->with($this->anything(), $this->anything(), 15000, $this->anything(), true, 30000, null, [
                'tax_flag' => 'TG03',
                'mod_tax_mny' => '9091',
                'mod_vat_mny' => '909',
                'mod_free_mny' => '5000',
            ])
            ->willReturn(['res_cd' => '0000', 'tno' => $order->payment->transaction_id]);
        $this->app->instance(NhnKcpApiService::class, $api);

        $result = app(PaymentRefundListener::class)->processRefund(['success' => false], $order, $order->payment, 15000.0, '부분');

        $this->assertTrue($result['success']);
    }

    /**
     * 과세만 있는 주문의 부분취소, 복합과세 주문의 전액취소에는 과세 분리를 싣지 않는다.
     *
     * @scenario method=card
     *
     * @effects non_tg03_or_full_cancel_omits_tax_split
     *
     * @return void 반환값 없음
     */
    public function test_tax_split_is_omitted_for_taxable_only_or_full_cancel(): void
    {
        $api = $this->createMock(NhnKcpApiService::class);
        $api->expects($this->exactly(2))->method('cancelPayment')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), null, [])
            ->willReturn(['res_cd' => '0000', 'tno' => 'TNO']);
        $this->app->instance(NhnKcpApiService::class, $api);
        $listener = app(PaymentRefundListener::class);

        $taxable = $this->paidOrder();
        $listener->processRefund(['success' => false], $taxable, $taxable->payment, 10000.0, '부분');

        $mixed = $this->paidOrder([], ['total_tax_amount' => 20000, 'total_vat_amount' => 1818, 'total_tax_free_amount' => 10000]);
        $listener->processRefund(['success' => false], $mixed, $mixed->payment, 30000.0, '전액');
    }

    /**
     * 입금된 가상계좌 환불은 결제사를 부르지 않고 계좌 환불로 처리하라고 돌려준다.
     *
     * @scenario method=vbank
     *
     * @effects settled_vbank_refund_is_declined_without_pg_call
     *
     * @return void 반환값 없음
     */
    public function test_settled_vbank_refund_is_declined_without_pg_call(): void
    {
        $api = $this->createMock(NhnKcpApiService::class);
        $api->expects($this->never())->method('cancelPayment');
        $this->app->instance(NhnKcpApiService::class, $api);
        $order = $this->paidOrder(['payment_method' => 'vbank', 'vbank_number' => '1234567890', 'refund_bank_code' => '04', 'refund_bank_account' => '1234567890123', 'refund_bank_holder' => '홍길동']);

        $result = app(PaymentRefundListener::class)->processRefund(['success' => false], $order, $order->payment, 30000.0, '환불');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['handled']);
        $this->assertSame('VBANK_REQUIRES_BANK_TRANSFER', $result['error_code']);
        $this->assertSame(__('sirsoft-pay_nhnkcp::messages.refund.vbank_requires_bank_transfer'), $result['error_message']);
    }

    /**
     * 부분취소 결과의 거래 ID 에 부분취소 일련번호를 붙인다. 전액취소는 응답 `tno` 그대로다.
     *
     * @scenario method=card
     *
     * @effects partial_cancel_transaction_id_carries_sequence_number
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_transaction_id_carries_sequence_number(): void
    {
        $order = $this->paidOrder();
        $tno = $order->payment->transaction_id;
        $api = $this->createMock(NhnKcpApiService::class);
        $api->method('cancelPayment')->willReturnOnConsecutiveCalls(
            ['res_cd' => '0000', 'tno' => $tno, 'mod_pcan_seq_no' => '2'],
            ['res_cd' => '0000', 'tno' => $tno],
        );
        $this->app->instance(NhnKcpApiService::class, $api);
        $listener = app(PaymentRefundListener::class);

        $partial = $listener->processRefund(['success' => false], $order, $order->payment, 10000.0, '부분');
        $this->assertSame($tno.'-2', $partial['transaction_id']);

        $full = $this->paidOrder();
        $whole = $listener->processRefund(['success' => false], $full, $full->payment, 30000.0, '전액');
        $this->assertSame($tno, $whole['transaction_id']);
    }
}
