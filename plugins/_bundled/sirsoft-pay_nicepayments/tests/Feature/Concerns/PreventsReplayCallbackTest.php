<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Concerns;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNicepayments\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * Phase 3 PoC (Feature) — 나이스페이먼츠 PreventsReplayCallback trait 동작 검증
 *
 * 본 테스트는 trait 의 wasAlreadyPaid() 가 다음 시나리오에서 올바르게
 * 분기하는지 DB 통합 환경에서 검증한다:
 *
 *   1. transaction_id null/empty → false (early return, query 미실행)
 *   2. paid 상태 + 동일 tid 존재 → true (replay detected)
 *   3. ready 상태만 존재 → false (still in progress)
 *   4. paid 상태이지만 다른 tid → false (no match)
 *
 * 본 trait 의 정확성은 사용 측 컨트롤러 (PaymentCallbackController) 의
 * 멱등성 분기 정확도에 직결되므로 PoC 의 핵심 검증 대상.
 */
class PreventsReplayCallbackTest extends PluginTestCase
{
    private object $traitSubject;

    /**
     * 결제 계약을 주입한 trait 호스트(익명 클래스)를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 익명 클래스에 trait 적용 + protected 메서드를 public 으로 노출 (호스트 요건: 결제 계약 주입)
        $this->traitSubject = new class(app(PaymentGatewayBridge::class))
        {
            use PreventsReplayCallback {
                wasAlreadyPaid as public;
            }

            /**
             * 결제 계약을 받습니다(trait 호스트 요건).
             *
             * @param  PaymentGatewayBridge  $paymentGateway  이커머스 공개 결제 계약
             */
            public function __construct(private readonly PaymentGatewayBridge $paymentGateway) {}
        };
    }

    /**
     * 지정 상태·거래번호의 나이스페이먼츠 결제 행을 주문과 함께 심습니다.
     *
     * @param  string  $tid  거래번호
     * @param  PaymentStatusEnum  $status  결제 상태
     * @param  int  $amount  금액
     * @return void 반환값 없음
     */
    private function seedPayment(string $tid, PaymentStatusEnum $status, int $amount = 50000): void
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
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => $status === PaymentStatusEnum::PAID ? $amount : 0,
            'paid_at' => $status === PaymentStatusEnum::PAID ? now() : null,
            'transaction_id' => $tid,
        ]);
    }

    /**
     * 거래번호가 null 이면 조회 없이 재전송이 아니라고 본다.
     *
     * @scenario context=authorize_card, threat=replay_db_long, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_null_transaction_id_returns_false_without_query(): void
    {
        $this->assertFalse($this->traitSubject->wasAlreadyPaid(null));
    }

    /**
     * 거래번호가 빈 문자열이면 조회 없이 재전송이 아니라고 본다.
     *
     * @scenario context=authorize_card, threat=replay_db_long, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_empty_transaction_id_returns_false_without_query(): void
    {
        $this->assertFalse($this->traitSubject->wasAlreadyPaid(''));
    }

    /**
     * 같은 거래번호의 결제 완료 행이 있으면 재전송으로 본다.
     *
     * @scenario context=authorize_card, threat=replay_db_long, callback_state=second_arrival_outside_60s
     *
     * @effects replay_detected_on_second_arrival_db
     *
     * @return void 반환값 없음
     */
    public function test_paid_payment_with_same_tid_returns_true(): void
    {
        $tid = 'TID_PAID_001';
        $this->seedPayment($tid, PaymentStatusEnum::PAID);

        $this->assertTrue($this->traitSubject->wasAlreadyPaid($tid));
    }

    /**
     * 같은 거래번호로 두 번째 결제 행을 쓰려 하면 DB 유일 제약이 막는다 — 트레이트 확인과 기록 사이에 끼어든
     * 동시 콜백도 같은 거래를 두 번 결제 행으로 남기지 못한다(이커머스 결제 행 transaction_id 유일 인덱스).
     *
     * @scenario context=authorize_card, threat=replay_db_long, callback_state=second_arrival_outside_60s
     *
     * @effects replay_db_unique_blocks_concurrent_insert
     *
     * @return void 반환값 없음
     */
    public function test_db_unique_index_blocks_second_payment_row_with_same_tid(): void
    {
        $tid = 'TID_UNIQUE_001';
        $this->seedPayment($tid, PaymentStatusEnum::PAID);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->seedPayment($tid, PaymentStatusEnum::PAID);
    }

    /**
     * Ready 상태만 존재 — 아직 결제 미완료이므로 replay 미감지.
     *
     * @scenario context=authorize_card, threat=replay_db_long, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_ready_status_payment_returns_false(): void
    {
        $tid = 'TID_READY_001';
        $this->seedPayment($tid, PaymentStatusEnum::READY);

        $this->assertFalse($this->traitSubject->wasAlreadyPaid($tid));
    }

    /**
     * 다른 tid 로 paid 상태 — 동일 tid 가 아니므로 match 없음.
     *
     * @scenario context=authorize_card, threat=replay_db_long, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_paid_with_different_tid_returns_false(): void
    {
        $this->seedPayment('TID_OTHER_PAYMENT_001', PaymentStatusEnum::PAID);

        $this->assertFalse($this->traitSubject->wasAlreadyPaid('TID_QUERY_001'));
    }

    /**
     * Failed 상태 + 동일 tid — paid 가 아니므로 replay 감지 안 함.
     *
     * @scenario context=authorize_card, threat=replay_db_long, callback_state=first_arrival
     *
     * @effects replay_first_arrival_proceeds_normally
     *
     * @return void 반환값 없음
     */
    public function test_failed_payment_with_same_tid_returns_false(): void
    {
        $tid = 'TID_FAILED_001';
        $this->seedPayment($tid, PaymentStatusEnum::FAILED);

        $this->assertFalse($this->traitSubject->wasAlreadyPaid($tid));
    }
}
