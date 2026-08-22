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

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
    }

    /**
     * 무통장·미결제 주문 + 입금자명/입금액 동봉 헬퍼
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
    }

    /**
     * mark_order_complete 미전달(기본 false): 입금(payment)만 기록, 주문 상태는 전이 안 함
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_records_payment_only_without_order_transition
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
    }

    /**
     * order_status 는 이미 결제완료인데 payment 가 미입금(ready)인 불일치 주문:
     * 입금확인으로 payment 만 PAID 로 정합화 (200)
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
     *
     * @effects manual_deposit_reconciles_payment_only_for_already_completed_order
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
     * 금액 불일치 → 422
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_rejects_amount_mismatch_422
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
     * 무통장이 아닌 주문 → 422 (가드)
     *
     * @scenario terminal_path=manual_deposit_confirm, payment_method=card_pg, option_mix=all_active, actor=admin
     *
     * @effects manual_deposit_confirm_rejects_amount_mismatch_422
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
     * @effects manual_deposit_confirm_syncs_option_status_via_complete_payment
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
