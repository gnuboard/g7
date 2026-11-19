<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Concerns;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNhnkcp\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * Phase 3 PoC (Feature) — NHN KCP PreventsReplayCallback trait 동작 검증
 *
 * trait 의 wasAlreadyPaid() 가 다음 시나리오에서 올바르게 분기:
 *   1. transaction_id null/empty → false (early return)
 *   2. paid 상태 + 동일 tno → true (replay detected)
 *   3. ready 상태만 존재 → false
 *   4. paid 상태이지만 다른 tno → false
 *   5. failed 상태 + 동일 tno → false
 */
class PreventsReplayCallbackTest extends PluginTestCase
{
    private object $traitSubject;

    /**
     * 재전송 방어 트레이트를 담은 최소 호스트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 트레이트는 호스트가 이커머스 결제 계약을 `$bridge` 로 들고 있다고 전제한다.
        $this->traitSubject = new class(app(PaymentGatewayBridge::class))
        {
            use PreventsReplayCallback {
                wasAlreadyPaid as public;
                logReplayDetected as public;
            }

            /**
             * 트레이트가 쓰는 이커머스 결제 계약을 주입받습니다.
             *
             * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약
             */
            public function __construct(
                private readonly PaymentGatewayBridge $bridge,
            ) {}
        };
    }

    /**
     * 이 PG 의 결제 행을 주어진 상태·거래번호로 심습니다.
     *
     * @param  string  $tno  거래번호
     * @param  PaymentStatusEnum  $status  결제 상태
     * @param  int  $amount  금액
     * @return void 반환값 없음
     */
    private function seedPayment(string $tno, PaymentStatusEnum $status, int $amount = 50000): void
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RC-'.random_int(10000, 99999),
            'order_status' => $status === PaymentStatusEnum::PAID
                ? OrderStatusEnum::PAYMENT_COMPLETE
                : OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => $amount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $amount,
            'total_due_amount' => $amount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => $status === PaymentStatusEnum::PAID ? $amount : 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => $status,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'paid_amount_local' => $status === PaymentStatusEnum::PAID ? $amount : 0,
            'paid_at' => $status === PaymentStatusEnum::PAID ? now() : null,
            'transaction_id' => $tno,
        ]);
    }

    /**
     * 거래번호가 null 이면 재전송 판정 없이 거짓이다.
     *
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_null_transaction_id_returns_false(): void
    {
        $this->assertFalse($this->traitSubject->wasAlreadyPaid(null));
    }

    /**
     * 거래번호가 빈 문자열이면 재전송 판정 없이 거짓이다.
     *
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_empty_transaction_id_returns_false(): void
    {
        $this->assertFalse($this->traitSubject->wasAlreadyPaid(''));
    }

    /**
     * 같은 거래번호의 이 PG 결제 행이 결제완료면 재전송으로 본다.
     *
     * @scenario context=paymentCallback_card, threat=replay, callback_state=second_arrival_same_tno
     *
     * @effects replay_detected_on_second_arrival
     *
     * @return void 반환값 없음
     */
    public function test_paid_payment_with_same_tno_returns_true(): void
    {
        $tno = 'TNO_PAID_001';
        $this->seedPayment($tno, PaymentStatusEnum::PAID);

        $this->assertTrue($this->traitSubject->wasAlreadyPaid($tno));
    }

    /**
     * 같은 거래번호라도 결제대기 행뿐이면 재전송이 아니다.
     *
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_ready_status_payment_returns_false(): void
    {
        $tno = 'TNO_READY_001';
        $this->seedPayment($tno, PaymentStatusEnum::READY);

        $this->assertFalse($this->traitSubject->wasAlreadyPaid($tno));
    }

    /**
     * 다른 거래번호의 결제완료 행은 재전송 판정에 쓰지 않는다.
     *
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_paid_with_different_tno_returns_false(): void
    {
        $this->seedPayment('TNO_OTHER_001', PaymentStatusEnum::PAID);

        $this->assertFalse($this->traitSubject->wasAlreadyPaid('TNO_QUERY_001'));
    }

    /**
     * 같은 거래번호라도 결제 실패 행이면 재전송이 아니다.
     *
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_failed_payment_with_same_tno_returns_false(): void
    {
        $tno = 'TNO_FAILED_001';
        $this->seedPayment($tno, PaymentStatusEnum::FAILED);

        $this->assertFalse($this->traitSubject->wasAlreadyPaid($tno));
    }

    /**
     * 재전송을 감지하면 거래번호·주문번호·콜백 종류를 한 줄 로그에 남긴다(운영 모니터링 형식 고정).
     *
     * @scenario context=paymentCallback_card, threat=replay, callback_state=second_arrival_same_tno
     *
     * @effects replay_logged_with_context
     *
     * @return void 반환값 없음
     */
    public function test_replay_detection_is_logged_with_its_context(): void
    {
        Log::spy();

        $this->traitSubject->logReplayDetected('TNO_REPLAY_LOG', 'ORD-RC-LOG', 'authCallback (card)');

        Log::shouldHaveReceived('info')
            ->once()
            ->with('NHN KCP: replay detected — already paid, returning idempotent response', [
                'tid' => 'TNO_REPLAY_LOG',
                'moid' => 'ORD-RC-LOG',
                'context' => 'authCallback (card)',
            ]);
    }

    /**
     * 동시에 도착한 콜백이 둘 다 재전송 검사를 통과해도 같은 거래번호로 두 번째 결제 행은 저장되지 않는다.
     *
     * 재전송 검사(조회)와 저장 사이의 경쟁은 이커머스 결제 행의 거래번호 유일 인덱스가 막는다
     * (`ecommerce_order_payments_transaction_id_unique`). 같은 거래번호 두 번째 저장은 DB 가 거부한다.
     *
     * @scenario context=paymentCallback_card, threat=replay, callback_state=second_arrival_same_tno
     *
     * @effects replay_db_unique_blocks_concurrent_insert
     *
     * @return void 반환값 없음
     */
    public function test_second_payment_row_with_same_tno_is_rejected_by_unique_index(): void
    {
        $tno = 'TNO_UNIQUE_001';
        $this->seedPayment($tno, PaymentStatusEnum::PAID);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->seedPayment($tno, PaymentStatusEnum::PAID);
    }
}
