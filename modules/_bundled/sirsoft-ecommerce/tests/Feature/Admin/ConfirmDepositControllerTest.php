<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 무통장 입금확인 컨트롤러 Feature 테스트 (B2 / D7)
 *
 * PATCH /api/modules/sirsoft-ecommerce/admin/orders/{order}/confirm-deposit
 */
class ConfirmDepositControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * 주문 수정 권한을 가진 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
    }

    /**
     * 무통장·미결제 주문 + 입금자명/입금액 동봉 헬퍼
     *
     * @param  int  $totalDue  결제예정금액
     * @return Order 입금대기 상태의 무통장 주문
     */
    private function makeDbankOrder(int $totalDue = 30000): Order
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'subtotal_amount' => $totalDue,
            'total_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_points_used_amount' => 0,
            'total_amount' => $totalDue,
            'total_due_amount' => $totalDue,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            // 무통장 미결제 = 결제 레코드도 입금대기(ready). Factory 기본값 PAID 를 명시 오버라이드.
            'payment_status' => PaymentStatusEnum::READY,
            'paid_at' => null,
        ]);
        OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        return $order->fresh();
    }

    /**
     * 주문번호로 입금확인 엔드포인트 경로를 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @return string 입금확인 API 경로
     */
    private function url(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/confirm-deposit";
    }

    /**
     * 성공: 입금액 일치 → 결제완료 전이 + 옵션 동기화 + 입금자명 기록
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_syncs_option_status_via_complete_payment, manual_deposit_confirm_records_depositor_name
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_succeeds_and_marks_paid(): void
    {
        $order = $this->makeDbankOrder(30000);

        // mark_order_complete=true: 입금 기록 + 결제완료 전이
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->url($order), [
                'amount' => 30000,
                'depositor_name' => '홍길동',
                'mark_order_complete' => true,
            ]);

        $response->assertOk();
        $fresh = $order->fresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $fresh->order_status);
        $this->assertEquals('홍길동', $fresh->payment->depositor_name);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $fresh->options->first()->option_status);

        // 결제완료까지 처리했을 때만 「결제완료」를 말한다 (S2-D3).
        $this->assertSame(
            __('sirsoft-ecommerce::messages.orders.deposit_confirmed'),
            $response->json('message'),
            'mark_order_complete=true 인데 결제완료 메시지가 아닙니다.'
        );
    }

    /**
     * mark_order_complete 미전달(기본 false): 입금(payment)만 기록, 주문 상태는 전이 안 함
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_records_payment_only_without_order_transition
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_records_payment_only_when_not_marking_complete(): void
    {
        $order = $this->makeDbankOrder(30000);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->url($order), [
                'amount' => 30000,
                'depositor_name' => '홍길동',
                // mark_order_complete 미전달 → 입금만 기록
            ]);

        $response->assertOk();
        $fresh = $order->fresh();
        // 결제 레코드는 입금완료
        $this->assertEquals(PaymentStatusEnum::PAID, $fresh->payment->payment_status);
        $this->assertEquals('홍길동', $fresh->payment->depositor_name);
        // 주문/옵션 상태는 전이되지 않음
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $fresh->order_status);

        // S2-D3 회귀: 주문이 결제대기로 남았는데 응답이 「결제완료 처리되었습니다」 라고
        // 말하면, 응답만 읽는 소비자는 결제되지 않은 주문을 결제완료로 읽는다.
        // 모달이 안내하는 대로 "입금 내역만 기록했다" 고 말해야 한다.
        $this->assertSame(
            __('sirsoft-ecommerce::messages.orders.deposit_recorded'),
            $response->json('message'),
            '입금만 기록했는데 응답이 결제완료를 단언합니다. 주문 상태: '
                .($fresh->order_status->value ?? (string) $fresh->order_status)
        );

        $this->assertNotSame(
            __('sirsoft-ecommerce::messages.orders.deposit_confirmed'),
            $response->json('message'),
            '입금만 기록한 응답이 결제완료 메시지를 그대로 씁니다.'
        );
    }

    /**
     * order_status 는 이미 결제완료인데 payment 가 미입금(ready)인 불일치 주문:
     * 입금확인으로 payment 만 PAID 로 정합화 (200)
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_reconciles_payment_only_for_already_completed_order
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_reconciles_payment_for_status_completed_but_unpaid_order(): void
    {
        $order = $this->makeDbankOrder(30000);
        // 관리자가 주문 상태만 결제완료로 바꿔 둔 불일치 (payment 는 여전히 ready)
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->url($order->fresh()), [
                'amount' => 30000,
            ]);

        $response->assertOk();
        $this->assertEquals(PaymentStatusEnum::PAID, $order->fresh()->payment->payment_status);
    }

    /**
     * 상태를 상품준비중까지 먼저 올려 둔 주문에 입금을 기록하면, 체크 여부와 무관하게
     * 상태는 그대로이고 주문 결제금액이 채워지며 응답도 그 결과를 말한다 (실측 2026-10-03).
     *
     * 종전에는 미체크면 「주문 상태는 결제대기로 유지됩니다」, 체크면 「결제완료 처리되었습니다」 로
     * 응답해 실제(상품준비중 유지)와 어긋났고, 주문 결제금액이 0 으로 남아 환불 예정액이 0원이었다.
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_fills_order_paid_amount_when_status_already_past_payment, manual_deposit_does_not_regress_order_already_past_payment
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_on_order_already_past_payment_keeps_status_and_says_so(): void
    {
        foreach ([false, true] as $markComplete) {
            $order = $this->makeDbankOrder(30000);
            $order->update(['order_status' => OrderStatusEnum::PREPARING]);

            $response = $this->actingAs($this->adminUser)
                ->patchJson($this->url($order->fresh()), [
                    'amount' => 30000,
                    'mark_order_complete' => $markComplete,
                ]);

            $response->assertOk();
            $fresh = $order->fresh();
            $this->assertEquals(OrderStatusEnum::PREPARING, $fresh->order_status, '결제 이후 주문이 결제완료로 되돌아갔습니다.');
            $this->assertEquals(30000.0, (float) $fresh->total_paid_amount, '입금을 기록했는데 주문 결제금액이 0 입니다.');
            $this->assertEquals(0.0, (float) $fresh->total_due_amount);
            $this->assertSame(
                __('sirsoft-ecommerce::messages.orders.deposit_recorded_status_kept'),
                $response->json('message'),
                'mark_order_complete='.var_export($markComplete, true).' 응답이 실제 결과(상태 유지)와 다릅니다.'
            );
        }
    }

    /**
     * 반대 방향 불일치: payment 는 PAID 인데 주문 금액이 미결제로 남은 주문 (S2-D4)
     *
     * 「입금 내역만 기록」(mark_order_complete 미체크)은 payment 만 PAID 로 만들고
     * 주문은 결제대기로 남긴다 — 설계대로다. 그러나 운영자가 그 뒤 **일괄 상태바**로
     * 상품 상태를 결제완료~배송완료까지 올리면, 그 경로는 `order_status` 만 쓰고
     * `completePayment()` 를 타지 않아 `total_paid_amount` 가 0 인 채로 굳는다.
     *
     * 그 상태에서 [입금확인] 은 이미 사라져 있다(payment 가 PAID 라 멱등 반환).
     * **화면에서 되돌릴 방법이 없다.** 장부상 미결제이므로 이후 모든 환불 계산이
     * `refund_amount=0` 으로 떨어진다 — 고객이 돈을 돌려받지 못한다.
     *
     * 실측(주문 115): 결제행 `paid`/174,500 · 주문헤더 `total_paid_amount=0` ·
     * `total_due_amount=174,500` · 반품 미리보기 `refund_amount=0`(기대 46,000).
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_reconciles_order_totals_when_payment_already_paid
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_reconciles_order_totals_when_payment_already_paid(): void
    {
        $order = $this->makeDbankOrder(30000);

        // 1) 입금 내역만 기록 (mark_order_complete 미전달)
        $this->actingAs($this->adminUser)
            ->patchJson($this->url($order), ['amount' => 30000])
            ->assertOk();

        $stuck = $order->fresh();
        $this->assertEquals(PaymentStatusEnum::PAID, $stuck->payment->payment_status);
        $this->assertEquals(
            0,
            (int) $stuck->total_paid_amount,
            '전제 확인: 이 시점에는 주문 금액이 아직 미정합 상태여야 한다.'
        );

        // 2) 운영자가 [입금확인] 대신 **일괄 상태바**로 상품을 결제완료로 올린다.
        //    이 시점에 [입금확인] 은 이미 쓸 수 없다 — payment 가 PAID 라 422 로 막힌다.
        //    따라서 장부를 맞출 기회는 이 전이 경로밖에 없다.
        $this->actingAs($this->adminUser)
            ->patchJson(
                "/api/modules/sirsoft-ecommerce/admin/orders/{$stuck->order_number}/options/bulk-status",
                [
                    'items' => $stuck->options->map(fn ($o) => [
                        'option_id' => $o->id,
                        'quantity' => $o->quantity,
                    ])->all(),
                    'status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
                ]
            )
            ->assertOk();

        $fixed = $order->fresh();

        $this->assertEquals(
            OrderStatusEnum::PAYMENT_COMPLETE,
            $fixed->order_status,
            '전제 확인: 일괄 전이로 주문이 결제완료가 되어야 한다.'
        );

        $this->assertEquals(
            30000,
            (int) $fixed->total_paid_amount,
            'payment 가 PAID 인데 주문 total_paid_amount 가 0 으로 남았습니다. '
                .'장부상 미결제라 이후 환불이 전부 0 원으로 계산됩니다.'
        );
        $this->assertEquals(
            0,
            (int) $fixed->total_due_amount,
            'total_due_amount 가 남아 있습니다 — 결제된 주문은 미수금이 0 이어야 합니다.'
        );
    }

    /**
     * 결제완료를 **건너뛰는** 일괄 전이(결제대기 → 상품준비중)도 수납액을 동기화한다.
     *
     * S2-D4 수정은 「결제완료로 올리는 전이」에서만 맞췄다. 그런데 일괄 상태바는 결제대기 상품을
     * 곧장 상품준비중으로 올릴 수 있고(전이 규칙 허용), 그 경로는 결제완료를 지나지 않아 같은 구멍이
     * 그대로 남았다 — 결제행은 PAID 인데 주문 `total_paid_amount=0` · 이후 [입금확인] 422 · 환불 전부 0원
     * (2026-09-17 실측 주문 1496).
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_reconciles_order_totals_when_bulk_status_skips_payment_complete
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_that_skips_payment_complete_still_reconciles_order_totals(): void
    {
        $order = $this->makeDbankOrder(30000);

        $this->actingAs($this->adminUser)
            ->patchJson($this->url($order), ['amount' => 30000])
            ->assertOk();

        $stuck = $order->fresh();
        $this->assertEquals(PaymentStatusEnum::PAID, $stuck->payment->payment_status);
        $this->assertEquals(0, (int) $stuck->total_paid_amount);

        $this->actingAs($this->adminUser)
            ->patchJson(
                "/api/modules/sirsoft-ecommerce/admin/orders/{$stuck->order_number}/options/bulk-status",
                [
                    'items' => $stuck->options->map(fn ($o) => [
                        'option_id' => $o->id,
                        'quantity' => $o->quantity,
                    ])->all(),
                    'status' => OrderStatusEnum::PREPARING->value,
                ]
            )
            ->assertOk();

        $fixed = $order->fresh();

        $this->assertEquals(OrderStatusEnum::PREPARING, $fixed->order_status);
        $this->assertEquals(30000, (int) $fixed->total_paid_amount, '결제완료를 건너뛴 일괄 전이 뒤에도 수납액이 주문에 기록되어야 한다.');
        $this->assertEquals(0, (int) $fixed->total_due_amount);
    }

    /**
     * 금액 불일치 → 422
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_rejects_amount_mismatch_422
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_rejects_amount_mismatch(): void
    {
        $order = $this->makeDbankOrder(30000);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->url($order), [
                'amount' => 10000,
                'depositor_name' => '홍길동',
            ]);

        $response->assertStatus(422);
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    /**
     * 결제 통화가 USD 인 무통장 주문을 만듭니다 — 23,000원 × 0.000742 = $17.066 → 0.01 절사 $17.06.
     *
     * 결제 통화가 소수 통화여야 입금액(주 단위)과 PG 비교 기준(최소 화폐단위)의 단위 차이가 드러난다.
     * KRW 주문은 두 단위가 같아 어느 쪽으로 비교해도 통과한다.
     *
     * @return Order 입금대기 상태의 USD 결제 무통장 주문
     */
    private function makeUsdDbankOrder(): Order
    {
        $order = $this->makeDbankOrder(23000);
        $order->update(['currency_snapshot' => [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor'],
                'USD' => ['rate' => 0.000742, 'decimal_places' => 2, 'rounding_unit' => '0.01', 'rounding_method' => 'floor'],
            ],
        ]]);
        $order->payment->update(['currency' => 'USD']);

        return $order->fresh();
    }

    /**
     * USD 결제 주문: 입금확인 창이 안내하는 결제 통화 금액($17.06)을 넣으면 결제완료된다
     *
     * 입금액은 결제 통화의 주 단위로 들어온다. 이를 정수로 자른 뒤(17) 최소 화폐단위 청구액(1706)과
     * 비교하면, 안내대로 넣은 금액이 늘 불일치로 거절된다.
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_accepts_payment_currency_amount
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_accepts_payment_currency_amount_for_usd_order(): void
    {
        $order = $this->makeUsdDbankOrder();

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->url($order), [
                'amount' => 17.06,
                'depositor_name' => '홍길동',
                'mark_order_complete' => true,
            ]);

        $response->assertOk();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
        $this->assertEqualsWithDelta(17.06, (float) $order->fresh()->payment->paid_amount_local, 0.001);
    }

    /**
     * USD 결제 주문: 입금을 「기록만」 해도 결제행의 결제 통화 입금액이 센트까지 남는다
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_record_keeps_payment_currency_amount
     *
     * @return void 반환값 없음
     */
    public function test_record_deposit_keeps_payment_currency_amount_for_usd_order(): void
    {
        $order = $this->makeUsdDbankOrder();

        $this->actingAs($this->adminUser)
            ->patchJson($this->url($order), [
                'amount' => 17.06,
                'mark_order_complete' => false,
            ])
            ->assertOk();

        $payment = $order->fresh()->payment;
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertEqualsWithDelta(17.06, (float) $payment->paid_amount_local, 0.001, '입금액이 정수로 잘려 기록되면 안 된다.');
    }

    /**
     * USD 결제 주문: 금액이 다르면 422 이고, 진단 문구는 결제 통화로 「예상 = 결제예정금액, 실제 = 입금액」 이다
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_mismatch_reports_due_and_received_in_payment_currency
     *
     * @return void 반환값 없음
     */
    public function test_usd_deposit_mismatch_reports_amounts_in_payment_currency(): void
    {
        $order = $this->makeUsdDbankOrder();

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->url($order), [
                'amount' => 17.00,
                'mark_order_complete' => true,
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.payment_amount_mismatch', [
                'expected' => ecommerce_format_price(17.06, 'USD'),
                'actual' => ecommerce_format_price(17.00, 'USD'),
            ]),
            $response->json('errors.detail'),
        );
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    /**
     * 무통장이 아닌 주문 → 422 (가드)
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=card_pg, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_rejects_non_dbank_order_422
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_rejects_non_dbank_order(): void
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);
        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->url($order->fresh()), [
                'amount' => (int) $order->total_due_amount,
            ]);

        $response->assertStatus(422);
    }

    /**
     * 결제 레코드(payment)가 이미 입금완료(PAID)면 HTTP 입금확인은 거부(422) —
     * FormRequest 가드(payment 미입금 상태만 허용)가 차단한다. 입금완료 결제의 재처리 방지.
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_rejects_when_payment_already_paid
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_rejects_when_payment_already_paid(): void
    {
        $order = OrderFactory::new()->paid()->create();
        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->url($order->fresh()), [
                'amount' => (int) $order->total_amount,
            ]);

        // payment 가 이미 PAID → FormRequest 가드가 422 로 거부
        $response->assertStatus(422);
    }

    /**
     * 권한 없는 사용자 → 403
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_requires_orders_update_permission
     *
     * @return void 반환값 없음
     */
    public function test_confirm_deposit_requires_orders_update_permission(): void
    {
        $order = $this->makeDbankOrder(30000);
        $noPermUser = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $response = $this->actingAs($noPermUser)
            ->patchJson($this->url($order), [
                'amount' => 30000,
            ]);

        $response->assertStatus(403);
    }
}
