<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderPayment 모델 테스트
 *
 * 축 요약(마커 아님 — 평문): method_kind=extension, capability_declared=declared,
 * capability=refund_method. 요약을 시나리오 축 마커로 적으면 파서가 쉼표로만 축을 분리하므로 `×`
 * 표기는 실재하지 않는 조합 1건으로 집계된다(커버리지를 부풀리는 방향).
 *
 * 효과 요약(마커 아님 — 평문): extension_id_persisted_as_is, builtin_capability_unchanged, refund_method_is_pg.
 */
class OrderPaymentTest extends ModuleTestCase
{
    /**
     * 결제 행이 주문에 묶여 저장된다.
     *
     * @scenario subject=model
     * @effects order_payment_row_is_persisted
     * @return void 반환값 없음
     */
    public function test_order_payment_can_be_created(): void
    {
        // paid_amount 컬럼은 paid_amount_local / paid_amount_base 로 분리됨 (다중 통화 지원)
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'paid_amount_local' => 50000,
            'paid_amount_base' => 50000,
        ]);

        $this->assertDatabaseHas('ecommerce_order_payments', [
            'id' => $payment->id,
            'order_id' => $order->id,
        ]);
    }

    /**
     * 결제 행에서 주문 관계로 원 주문을 읽는다.
     *
     * @scenario subject=model
     * @effects order_payment_belongs_to_order
     * @return void 반환값 없음
     */
    public function test_order_payment_belongs_to_order(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create();

        $this->assertInstanceOf(Order::class, $payment->order);
        $this->assertEquals($order->id, $payment->order->id);
    }

    /**
     * 결제수단은 enum 캐스트 없이 문자열로 저장되고, 카드 판정은 능력 메서드가 답한다.
     *
     * @effects builtin_capability_unchanged
     * @return void 반환값 없음
     */
    public function test_order_payment_method_is_stored_as_plain_string(): void
    {
        // payment_method 는 enum 캐스트를 두지 않는다 — PG 플러그인이 등록하는
        // 확장 결제수단(예: nhnkcp_naverpay)은 enum case 가 없어 캐스트가 ValueError 를 던지기 때문.
        // 능력 질의는 모델의 능력 메서드가 PaymentMethodResolver 에 위임해 답한다.
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->card()->create();

        $this->assertSame(PaymentMethodEnum::CARD->value, $payment->fresh()->payment_method);
        $this->assertTrue($payment->isCardPayment());
    }

    /**
     * PG 플러그인이 등록한 확장 결제수단 ID 도 그대로 저장된다.
     *
     * @effects extension_id_persisted_as_is
     * @return void 반환값 없음
     */
    public function test_order_payment_accepts_extension_payment_method_id(): void
    {
        // 확장 결제수단 ID 를 1급 시민으로 저장할 수 있어야 한다 (캐스트가 있으면 ValueError).
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_method' => 'nhnkcp_naverpay',
            'pg_provider' => 'nhnkcp',
        ]);

        $this->assertSame('nhnkcp_naverpay', $payment->fresh()->payment_method);
    }

    /**
     * 결제 상태는 PaymentStatusEnum 으로 캐스팅된다.
     *
     * @scenario subject=model
     * @effects order_payment_status_is_cast_to_enum
     * @return void 반환값 없음
     */
    public function test_order_payment_casts_status_to_enum(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->completed()->create();

        $this->assertInstanceOf(PaymentStatusEnum::class, $payment->payment_status);
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
    }

    /**
     * 카드 결제는 카드 결제로 판정되고 카드사명을 담는다.
     *
     * @scenario subject=model
     * @effects card_payment_keeps_card_info
     * @return void 반환값 없음
     */
    public function test_card_payment_has_card_info(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->card()->create([
            'card_name' => '신한카드',
        ]);

        $this->assertTrue($payment->isCardPayment());
        $this->assertEquals('신한카드', $payment->card_name);
    }

    /**
     * 가상계좌 결제는 가상계좌로 판정되고 은행명·계좌번호를 담는다.
     *
     * @scenario subject=model
     * @effects virtual_account_payment_keeps_vbank_info
     * @return void 반환값 없음
     */
    public function test_virtual_account_payment_has_vbank_info(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->virtualAccount()->create();

        $this->assertTrue($payment->isVirtualAccount());
        $this->assertNotNull($payment->vbank_name);
        $this->assertNotNull($payment->vbank_number);
    }

    /**
     * 결제 대기 상태에는 결제 시각이 없다.
     *
     * @scenario subject=model
     * @effects payment_paid_at_follows_status
     * @return void 반환값 없음
     */
    public function test_pending_payment_has_no_paid_at(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->pending()->create();

        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->paid_at);
    }

    /**
     * 결제 완료 상태에는 결제 시각이 있다.
     *
     * @scenario subject=model
     * @effects payment_paid_at_follows_status
     * @return void 반환값 없음
     */
    public function test_completed_payment_has_paid_at(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->completed()->create();

        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertNotNull($payment->paid_at);
    }

    /**
     * 취소된 결제는 취소 시각과 취소 사유를 담는다.
     *
     * @scenario subject=model
     * @effects cancelled_payment_keeps_cancel_info
     * @return void 반환값 없음
     */
    public function test_cancelled_payment_has_cancel_info(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->cancelled()->create();

        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertNotNull($payment->cancel_reason);
    }

    /**
     * 실패한 결제는 실패 상태이고 결제 시각이 없다.
     *
     * @scenario subject=model
     * @effects payment_paid_at_follows_status
     * @return void 반환값 없음
     */
    public function test_failed_payment_has_correct_status(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->failed()->create();

        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertNull($payment->paid_at);
    }

    /**
     * 결제 통화가 기준 통화와 다르면 취소 가능 금액을 결제 통화의 누적 취소로 계산한다.
     *
     * @scenario subject=model
     * @effects cancellable_amount_uses_payment_currency_cumulative
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_uses_payment_currency_cumulative_when_cross_currency(): void
    {
        // base JPY 주문을 KRW 로 결제: paid_amount_local 은 결제 통화(KRW 4750), 누적 취소도
        // 결제 통화로 비교해야 한다. mc_cancelled_amount[KRW]=1000 이면 잔여 = 4750-1000 = 3750.
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'currency' => 'KRW',
            'paid_amount_local' => 4750,
            'paid_amount_base' => 500,
            // base 누적(cancelled_amount)은 JPY 100 이지만 결제 통화 비교에는 쓰지 않는다.
            'cancelled_amount' => 100,
            'mc_cancelled_amount' => ['JPY' => 100, 'KRW' => 1000],
        ]);

        $this->assertSame(1000.0, $payment->cancelledLocalAmount());
        // 3750 = 4750(KRW) - 1000(KRW). base cancelled(100)으로 빼면 4650 이 되는 회귀를 차단.
        $this->assertSame(3750.0, $payment->getCancellableAmount());
    }

    /**
     * 통화별 누적 취소가 없는 옛 결제는 기준 통화 누적 취소로 대신 계산한다.
     *
     * @scenario subject=model
     * @effects cancellable_amount_falls_back_to_base_for_legacy_payment
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_falls_back_to_base_cancelled_for_legacy_payment(): void
    {
        // mc_cancelled_amount 가 없는(레거시) 결제는 base 누적 cancelled_amount 로 폴백한다.
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'currency' => 'KRW',
            'paid_amount_local' => 50000,
            'paid_amount_base' => 50000,
            'cancelled_amount' => 20000,
            'mc_cancelled_amount' => null,
        ]);

        $this->assertSame(20000.0, $payment->cancelledLocalAmount());
        $this->assertSame(30000.0, $payment->getCancellableAmount());
    }
}
