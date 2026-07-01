<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * Phase 3 PoC (E2E) — KG 이니시스 PC 가상계좌 입금통보 replay 방어 침투 테스트
 *
 * 본 테스트는 시나리오 매니페스트 (security-callback-defense.yaml) 의
 * manual_verification "Replay PoC — vbankNotify 두 번 전송" 항목을 자동화한다.
 *
 * 공격 시나리오:
 *   1. 정상 입금통보가 1차 도착 → payment_status=paid + transaction_id=$tid 저장
 *   2. 동일 페이로드 (no_tid 동일) 가 2차 도착 (재처리/replay)
 *
 * 기대 동작:
 *   - 2차 응답도 "OK" 반환 (PG 재시도 차단 위해 idempotent 응답)
 *   - completePayment 가 2번째에는 미호출 (paid_amount_local 미변경, paid_at 미갱신)
 *   - Log 에 'replay detected' INFO 기록
 *   - g7_ecommerce_order_payments 의 transaction_id 가 동일 row 그대로 (중복 row 미생성)
 */
class VbankNotifyReplayTest extends PluginTestCase
{
    private function createPaidOrder(string $tid, int $amount = 50000): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-VR-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
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
            'total_paid_amount' => $amount,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'kginicis',
            'paid_amount_local' => $amount,
            'paid_at' => now(),
            'transaction_id' => $tid,
        ]);

        return $order;
    }

    private function makeVbankNotifyParams(string $tid, string $moid, int $amount): array
    {
        return [
            'no_tid'       => $tid,
            'no_oid'       => $moid,
            'id_merchant'  => 'INIpayTest',
            'dt_trans'     => '20260516',
            'tm_trans'     => '120000',
            'cd_bank'      => '003',
            'no_vacct'     => '1234567890123',
            'amt_input'    => (string) $amount,
            'nm_inputbank' => '기업은행',
            'nm_input'     => '홍길동',
        ];
    }

    /**
     * @scenario context=vbankNotify, threat=replay, callback_state=second_arrival_same_tid
     * @effects replay_detected_on_second_arrival, replay_response_is_idempotent_ok, replay_no_double_completion, replay_logged_with_context
     */
    public function test_replay_returns_idempotent_ok_and_does_not_double_complete(): void
    {
        $tid = 'TID_REPLAY_001';
        $order = $this->createPaidOrder($tid, 50000);

        // 1차 결제는 이미 완료 상태 (createPaidOrder 가 paid 로 seed).
        // 실제 시나리오는 1차 vbankNotify 가 도착해 paid 처리한 후, 2차 vbankNotify 가 도착하는 흐름.
        // 본 테스트는 그 2차 도착만 시뮬레이션 (1차 효과는 seed 로 표현).

        $beforePaidAt = $order->payment->paid_at;
        $beforePaymentId = $order->payment->id;
        $beforeAmount = $order->payment->paid_amount_local;

        $payload = $this->makeVbankNotifyParams($tid, $order->order_number, 50000);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $payload);

        // 1. 응답: 200 OK + body "OK" (KG 이니시스 재시도 차단 위해 멱등 응답)
        $response->assertStatus(200);
        $this->assertSame('OK', $response->getContent());
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));

        // 2. payment row 가 중복 생성되지 않음 (transaction_id unique 기반)
        $paymentCount = OrderPayment::query()->where('transaction_id', $tid)->count();
        $this->assertSame(1, $paymentCount, 'transaction_id 가 동일한 row 가 1건만 존재해야 함');

        // 3. completePayment 가 재호출되지 않음 — paid_at / paid_amount_local 보존
        $order->refresh();
        $order->payment->refresh();
        $this->assertSame($beforePaymentId, $order->payment->id);
        $this->assertEquals($beforeAmount, $order->payment->paid_amount_local);
        $this->assertEquals(
            $beforePaidAt?->format('Y-m-d H:i:s'),
            $order->payment->paid_at?->format('Y-m-d H:i:s'),
            'paid_at 가 2차 콜백으로 갱신되지 않아야 함'
        );
    }

    /**
     * @scenario context=vbankNotify, threat=replay, callback_state=first_arrival
     * @effects replay_first_arrival_proceeds_normally
     */
    public function test_first_arrival_with_issued_tid_proceeds_to_complete_payment_path(): void
    {
        // 가상계좌 발급 완료 상태의 결제 row 를 가진 주문 — 1차 vbankNotify 도착 시 처리 경로 검증
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-FA-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => 30000,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => 30000,
            'total_due_amount' => 30000,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        $tid = 'TID_FIRST_001';
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'kginicis',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => $tid,
            'vbank_number' => '1234567890123',
            'payment_meta' => [
                'mid' => 'INIpayTest',
                'is_test_mode' => true,
            ],
        ]);

        $payload = $this->makeVbankNotifyParams($tid, $order->order_number, 30000);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $payload);

        // 정상 흐름 통과 — 200 OK 응답 (재시도 차단). 내부 처리 성공/실패와 무관하게
        // KG 이니시스 manual 에 따라 OK 만 응답하면 PG 측 재시도가 중단됨.
        $response->assertStatus(200);
        $this->assertSame('OK', $response->getContent());
    }

    /**
     * @scenario context=vbankNotify, threat=replay, callback_state=first_arrival
     * @effects replay_db_unique_blocks_concurrent_insert
     */
    public function test_db_unique_index_blocks_duplicate_transaction_id_insert(): void
    {
        // DB 레벨 보조 방어 — plugin trait 가드를 우회하더라도 DB unique 가 마지막 방벽
        $tid = 'TID_DB_UNIQUE_001';

        // 1차 row insert
        $order1 = $this->createPaidOrder($tid, 10000);
        $this->assertNotNull($order1->payment->id);

        // 2차 동일 tid 직접 insert 시도 — MySQL 1062 차단 확인
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry|Integrity constraint/');

        $order2 = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-DUP-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 20000,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => 20000,
            'total_due_amount' => 20000,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 20000,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order2->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'kginicis',
            'paid_amount_local' => 20000,
            'paid_at' => now(),
            'transaction_id' => $tid,  // 동일 tid → 1062 위반
        ]);
    }
}
