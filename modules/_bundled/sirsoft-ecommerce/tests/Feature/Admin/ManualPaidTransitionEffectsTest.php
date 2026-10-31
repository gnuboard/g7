<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsManualPaidOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 수동 결제 처리·재활성 확인 창의 세 항목 — 실행 경로 (Q13·Q14·Q4·Q15)
 *
 * 관리자가 결제 전 주문을 결제완료 이후로 올리거나 취소 주문·상품을 되살릴 때, 재고 차감·사용 마일리지
 * 차감·결제금액 기록을 운영자 선택(기본 모두 켬)대로 처리하는지 본다. 값을 보내지 않는 기존 클라이언트는
 * 세 항목 모두 켠 것으로 처리된다.
 */
class ManualPaidTransitionEffectsTest extends ModuleTestCase
{
    use BuildsManualPaidOrders;

    protected User $admin;

    /**
     * 통화·사유를 심고 주문 수정 권한을 가진 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.orders.update',
            // 취소 창 예상금액 조회는 접수(claims.create) 축이다 (7.1.0).
            'sirsoft-ecommerce.claims.create',
        ]);
    }

    /**
     * 테스트가 심은 통화 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 관리자 주문 API 기본 경로입니다.
     *
     * @return string 기본 경로
     */
    protected function ordersUrl(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin/orders';
    }

    /**
     * 주문의 사용(order_use) 원장 행을 돌려줍니다.
     *
     * @param  Order  $order  주문
     * @return Collection<int, MileageTransaction> 사용 행
     */
    protected function useRows(Order $order)
    {
        return MileageTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', MileageTransactionTypeEnum::ORDER_USE->value)
            ->get();
    }

    /**
     * 주문 첫 상품 옵션의 현재 재고를 돌려줍니다.
     *
     * @param  Order  $order  주문
     * @return int 재고
     */
    protected function stockOf(Order $order): int
    {
        return (int) ProductOption::query()->whereKey($order->options->first()->product_option_id)->value('stock_quantity');
    }

    /**
     * 목록 일괄변경으로 상태를 바꿉니다.
     *
     * @param  array<int>  $ids  주문 ID
     * @param  string  $status  목표 상태
     * @param  array<string, mixed>  $flags  세 항목 값
     * @return TestResponse 응답
     */
    protected function bulk(array $ids, string $status = 'payment_complete', array $flags = [])
    {
        return $this->actingAs($this->admin)->patchJson($this->ordersUrl().'/bulk', array_merge(['ids' => $ids, 'order_status' => $status], $flags));
    }

    /**
     * 단건 수정으로 결제완료로 올리면 값을 보내지 않아도 세 항목이 모두 적용된다.
     *
     * @return void 반환값 없음
     *
     * @effects manual_paid_transition_applies_three_items_by_default, omitted_flags_default_to_true
     */
    public function test_single_update_applies_all_three_by_default(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 10000);
        $due = (float) $order->total_due_amount;
        $stockBefore = $this->stockOf($order);

        $this->actingAs($this->admin)
            ->patchJson($this->ordersUrl()."/{$order->order_number}", array_merge(['order_status' => 'payment_complete'], $this->recipientBody()))
            ->assertOk();

        $order->refresh();
        $uses = $this->useRows($order);
        $this->assertCount(1, $uses);
        $this->assertEquals(-5000, (float) $uses->first()->amount);
        $this->assertTrue((bool) $order->is_mileage_deducted);
        $this->assertTrue((bool) $order->options()->first()->is_stock_deducted);
        $this->assertSame($stockBefore - 1, $this->stockOf($order));
        $this->assertEquals($due, (float) $order->total_paid_amount);
        $this->assertEquals(0, (float) $order->total_due_amount);
        $this->assertSame(PaymentStatusEnum::PAID, $order->payment()->first()->payment_status);
        $this->assertEquals($due, (float) $order->payment()->first()->paid_amount_base);
    }

    /**
     * 목록 일괄변경도 세 값을 보내지 않으면 모두 켠 것으로 처리한다(기존 API 호환).
     *
     * @return void 반환값 없음
     *
     * @effects omitted_flags_default_to_true
     */
    public function test_bulk_update_without_flags_keeps_default_true(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 10000);
        $due = (float) $order->total_due_amount;

        $this->bulk([$order->id])->assertOk();

        $order->refresh();
        $this->assertCount(1, $this->useRows($order));
        $this->assertTrue((bool) $order->options()->first()->is_stock_deducted);
        $this->assertEquals($due, (float) $order->total_paid_amount);
        $this->assertSame(PaymentStatusEnum::PAID, $order->payment()->first()->payment_status);
    }

    /**
     * 세 체크의 8조합.
     *
     * @return array<string, array{0: bool, 1: bool, 2: bool}> 조합
     */
    public static function flagCombinations(): array
    {
        $rows = [];
        foreach ([true, false] as $stock) {
            foreach ([true, false] as $mileage) {
                foreach ([true, false] as $record) {
                    $rows[sprintf('stock=%d mileage=%d record=%d', $stock, $mileage, $record)] = [$stock, $mileage, $record];
                }
            }
        }

        return $rows;
    }

    /**
     * 끈 항목만 건너뛰고 켠 항목은 적용한다.
     *
     * @param  bool  $stock  재고 차감
     * @param  bool  $mileage  사용 마일리지 차감
     * @param  bool  $record  결제금액 기록
     * @return void 반환값 없음
     *
     * @effects unchecked_item_is_skipped_only
     */
    #[DataProvider('flagCombinations')]
    public function test_unchecking_each_item_skips_only_that_item(bool $stock, bool $mileage, bool $record): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 10000);
        $due = (float) $order->total_due_amount;
        $stockBefore = $this->stockOf($order);

        $this->bulk([$order->id], 'payment_complete', [
            'apply_stock_deduction' => $stock,
            'apply_mileage_deduction' => $mileage,
            'record_paid_amount' => $record,
        ])->assertOk();

        $order->refresh();
        $this->assertSame($stock ? $stockBefore - 1 : $stockBefore, $this->stockOf($order));
        $this->assertSame($stock, (bool) $order->options()->first()->is_stock_deducted);
        $this->assertCount($mileage ? 1 : 0, $this->useRows($order));
        $this->assertSame($mileage, (bool) $order->is_mileage_deducted);
        $this->assertEquals($record ? $due : 0.0, (float) $order->total_paid_amount);
        $this->assertSame($record ? PaymentStatusEnum::PAID : PaymentStatusEnum::READY, $order->payment()->first()->payment_status);
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 결제금액 기록을 끄면 이후 환불 예상액의 현금 몫이 0 이다(현금 한도 0).
     *
     * @return void 반환값 없음
     *
     * @effects unchecked_record_leaves_cash_refund_limit_zero
     */
    public function test_unchecked_record_keeps_cash_limit_zero(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 0, balance: 0);
        $this->bulk([$order->id], 'payment_complete', ['record_paid_amount' => false])->assertOk();

        $response = $this->actingAs($this->admin)->postJson($this->ordersUrl()."/{$order->order_number}/estimate-refund", [
            'items' => [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
        ])->assertOk();

        $this->assertEquals(0, (float) $response->json('data.refund_amount'));
        $this->assertSame(PaymentStatusEnum::READY, $order->payment()->first()->payment_status);
    }

    /**
     * 재고 차감을 끈 주문은 나중에 취소해도 재고를 되돌리지 않는다(차감도 복원도 없음).
     *
     * @return void 반환값 없음
     *
     * @effects unchecked_stock_is_not_restored_on_cancel
     */
    public function test_unchecked_stock_is_not_restored_on_later_cancel(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 0, balance: 0);
        $stockBefore = $this->stockOf($order);

        $this->bulk([$order->id], 'payment_complete', ['apply_stock_deduction' => false])->assertOk();
        $this->cancelThroughAdmin($order->fresh(), 'full');

        $this->assertSame($stockBefore, $this->stockOf($order));
    }

    /**
     * 이미 처리된 항목(주문 시 차감된 재고·마일리지)은 미리보기에서 「해당 없음」으로 숨는다.
     *
     * @return void 반환값 없음
     *
     * @effects not_applicable_items_are_hidden_in_preview
     */
    public function test_not_applicable_items_are_reported_hidden(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 0, method: PaymentMethodEnum::DBANK->value);
        $order->forceFill(['order_status' => OrderStatusEnum::PENDING_PAYMENT, 'is_mileage_deducted' => true])->save();
        foreach ($order->options as $row) {
            $row->forceFill(['option_status' => OrderStatusEnum::PENDING_PAYMENT, 'is_stock_deducted' => true])->save();
        }

        $data = $this->actingAs($this->admin)->postJson($this->ordersUrl().'/bulk/manual-paid-preview', [
            'ids' => [$order->id], 'order_status' => 'payment_complete',
        ])->assertOk()->json('data');

        $row = $data['orders'][0];
        $this->assertFalse($row['stock_deduction']['applicable']);
        $this->assertSame('no_pending_stock', $row['stock_deduction']['reason']);
        $this->assertFalse($row['mileage_deduction']['applicable']);
        $this->assertSame('already_deducted', $row['mileage_deduction']['reason']);
        $this->assertTrue($row['paid_amount_record']['applicable']);
    }

    /**
     * 비회원 주문은 마일리지 항목이 「해당 없음(guest)」이다.
     *
     * @return void 반환값 없음
     *
     * @effects not_applicable_items_are_hidden_in_preview
     */
    public function test_guest_order_mileage_not_applicable(): void
    {
        $order = $this->pendingOrderUsingMileage(guest: true);

        $data = $this->actingAs($this->admin)->postJson($this->ordersUrl().'/bulk/manual-paid-preview', [
            'ids' => [$order->id], 'order_status' => 'payment_complete',
        ])->assertOk()->json('data');

        $this->assertSame('guest', $data['orders'][0]['mileage_deduction']['reason']);
    }

    /**
     * 입금만 기록된 주문(결제행 PAID·헤더 0)은 결제금액 기록을 꺼도 헤더를 결제행 수납액으로 맞춘다.
     *
     * @return void 반환값 없음
     *
     * @effects settled_payment_row_auto_syncs_header
     */
    public function test_settled_payment_row_auto_syncs_header_without_checkbox(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 0, balance: 0, method: PaymentMethodEnum::DBANK->value);
        $due = (float) $order->total_due_amount;
        $order->payment->forceFill(['payment_status' => PaymentStatusEnum::PAID, 'paid_at' => now()])->save();

        $preview = $this->actingAs($this->admin)->postJson($this->ordersUrl().'/bulk/manual-paid-preview', [
            'ids' => [$order->id], 'order_status' => 'preparing',
        ])->assertOk()->json('data.orders.0.paid_amount_record');
        $this->assertTrue($preview['auto_sync']);
        $this->assertFalse($preview['applicable']);

        $this->actingAs($this->admin)
            ->patchJson($this->ordersUrl()."/{$order->order_number}", array_merge(['order_status' => 'preparing', 'record_paid_amount' => false], $this->recipientBody()))
            ->assertOk();

        $this->assertEquals($due, (float) $order->fresh()->total_paid_amount);
    }

    /**
     * 단건: 잔액이 모자라면 422 와 주문번호를 돌려주고 아무것도 바꾸지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects mileage_short_blocks_change_with_422
     */
    public function test_mileage_short_single_is_422_and_unchanged(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 1000);
        $stockBefore = $this->stockOf($order);

        $response = $this->actingAs($this->admin)
            ->patchJson($this->ordersUrl()."/{$order->order_number}", array_merge(['order_status' => 'payment_complete'], $this->recipientBody()))
            ->assertStatus(422);

        $this->assertSame([$order->order_number], $response->json('errors.order_numbers'));
        $order->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->options()->first()->option_status);
        $this->assertSame($stockBefore, $this->stockOf($order));
        $this->assertEquals(0, (float) $order->total_paid_amount);
        $this->assertCount(0, $this->useRows($order));
    }

    /**
     * 잔액이 모자라도 「사용 마일리지 차감」을 끄면 차감 없이 바꿀 수 있다.
     *
     * @return void 반환값 없음
     *
     * @effects unchecked_item_is_skipped_only
     */
    public function test_mileage_short_can_be_bypassed_by_unchecking(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 1000);

        $this->actingAs($this->admin)
            ->patchJson($this->ordersUrl()."/{$order->order_number}", array_merge(['order_status' => 'payment_complete', 'apply_mileage_deduction' => false], $this->recipientBody()))
            ->assertOk();

        $order->refresh();
        $this->assertCount(0, $this->useRows($order));
        $this->assertFalse((bool) $order->is_mileage_deducted);
    }

    /**
     * 일괄: 한 건이라도 모자라면 전체를 되돌리고 모자란 주문번호를 모두 알린다.
     *
     * @return void 반환값 없음
     *
     * @effects bulk_mileage_short_is_all_or_nothing_and_names_every_order
     */
    public function test_bulk_all_or_nothing_names_every_short_order(): void
    {
        $a = $this->pendingOrderUsingMileage(points: 5000, balance: 10000);
        $b = $this->pendingOrderUsingMileage(points: 5000, balance: 1000);
        $c = $this->pendingOrderUsingMileage(points: 5000, balance: 0);

        $response = $this->bulk([$a->id, $b->id, $c->id])->assertStatus(422);

        $this->assertEqualsCanonicalizing([$b->order_number, $c->order_number], $response->json('errors.order_numbers'));
        $this->assertCount(0, $this->useRows($a));
        foreach ([$a, $b, $c] as $order) {
            $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->fresh()->order_status);
        }
    }

    /**
     * 같은 회원의 두 주문은 누적으로 판정한다 — 두 번째 주문만 모자라다.
     *
     * @return void 반환값 없음
     *
     * @effects bulk_mileage_short_is_all_or_nothing_and_names_every_order
     */
    public function test_same_member_orders_judged_cumulatively(): void
    {
        $member = User::factory()->create();
        $first = $this->pendingOrderUsingMileage(points: 5000, balance: 7000, member: $member);
        $second = $this->pendingOrderUsingMileage(points: 5000, balance: 0, member: $member);

        $response = $this->bulk([$first->id, $second->id])->assertStatus(422);

        $this->assertSame([$second->order_number], $response->json('errors.order_numbers'));
    }

    /**
     * 재고가 모자라면 422 와 주문번호를 안내하고 마일리지까지 모두 되돌린다(종전 500 「수정 실패」).
     *
     * @return void 반환값 없음
     *
     * @effects stock_short_blocks_change_with_422_and_order_number
     */
    public function test_stock_short_is_422_with_order_number_and_rolls_back(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 10000, quantity: 2, stock: 1);

        $response = $this->bulk([$order->id])->assertStatus(422);

        $this->assertStringContainsString($order->order_number, (string) $response->json('message'));
        $order->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertCount(0, $this->useRows($order));
        $this->assertSame(1, $this->stockOf($order));
    }

    /**
     * 재고가 모자라도 「재고 차감」을 끄면 바꿀 수 있고 재고는 그대로다.
     *
     * @return void 반환값 없음
     *
     * @effects unchecked_item_is_skipped_only
     */
    public function test_stock_short_can_be_bypassed_by_unchecking(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 0, balance: 0, quantity: 2, stock: 1);

        $this->bulk([$order->id], 'payment_complete', ['apply_stock_deduction' => false])->assertOk();

        $this->assertSame(1, $this->stockOf($order));
    }

    /**
     * 상세 상품 일괄로 전량을 결제완료로 올리면 부모가 결제 코스로 들어가며 세 항목이 적용된다.
     *
     * @return void 반환값 없음
     *
     * @effects option_bulk_parent_sync_is_atomic_with_effects
     */
    public function test_option_bulk_moves_parent_into_paid_course_applies_effects(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 10000);
        $due = (float) $order->total_due_amount;
        $option = $order->options->first();

        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $option->id, 'quantity' => $option->quantity]],
            'status' => 'payment_complete',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertCount(1, $this->useRows($order));
        $this->assertTrue((bool) $order->options()->first()->is_stock_deducted);
        $this->assertEquals($due, (float) $order->total_paid_amount);
    }

    /**
     * 일부 수량만 올려 부모가 결제 전으로 남으면 아무 효과도 없다.
     *
     * @return void 반환값 없음
     *
     * @effects option_bulk_parent_sync_is_atomic_with_effects
     */
    public function test_option_bulk_partial_quantity_keeps_parent_pending_and_applies_nothing(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 10000, quantity: 2);
        $option = $order->options->first();

        $preview = $this->actingAs($this->admin)->postJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status/manual-paid-preview", [
            'items' => [['option_id' => $option->id, 'quantity' => 1]],
            'status' => 'payment_complete',
        ])->assertOk()->json('data.summary');
        $this->assertFalse($preview['any_applicable']);

        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $option->id, 'quantity' => 1]],
            'status' => 'payment_complete',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertCount(0, $this->useRows($order));
        $this->assertEquals(0, (float) $order->total_paid_amount);
    }

    /**
     * 상품 일괄에서 잔액이 모자라면 상품 상태 변경까지 함께 되돌린다(부모 동기화가 같은 트랜잭션).
     *
     * @return void 반환값 없음
     *
     * @effects option_bulk_parent_sync_is_atomic_with_effects
     */
    public function test_option_bulk_rollback_keeps_options_pending_when_mileage_short(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 1000);
        $option = $order->options->first();

        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $option->id, 'quantity' => $option->quantity]],
            'status' => 'payment_complete',
        ])->assertStatus(422);

        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $option->fresh()->option_status);
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->fresh()->order_status);
    }

    /**
     * 환불로 전체취소된 주문을 되살리면 취소로 돌려준 마일리지를 다시 빼고 돌려준 현금을 장부에서 되돌린다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_rededucts_mileage_restored_by_cancellation, reactivation_reclaims_refunded_cash_into_ledger
     */
    public function test_reactivate_whole_order_rededucts_restored_mileage_and_reclaims_cash(): void
    {
        $order = $this->cancelledPaidOrderWithMileageRefund(points: 5000, price: 35000);
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(5000, (float) $order->total_refunded_points_amount);
        $this->assertEquals(30000, (float) $order->total_refunded_amount);
        $stockBefore = $this->stockOf($order);

        $this->bulk([$order->id])->assertOk();

        $order->refresh();
        $reclaim = $this->useRows($order)->filter(fn ($tx) => ($tx->metadata['reason'] ?? null) === 'reactivation');
        $this->assertCount(1, $reclaim);
        $this->assertEquals(-5000, (float) $reclaim->first()->amount);
        $this->assertEquals(0, (float) $order->total_refunded_points_amount);
        $this->assertEquals(0, (float) $order->total_refunded_amount);
        $this->assertEquals(30000, (float) ($order->order_meta['reactivation_reclaim']['cash'] ?? 0));
        $this->assertSame($stockBefore - 1, $this->stockOf($order));
    }

    /**
     * 마일리지 차감을 끄고 되살린 주문을 다시 취소하면 마일리지를 더 돌려주지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects unchecked_mileage_returns_nothing_on_refund
     */
    public function test_reactivate_with_unchecked_mileage_returns_nothing_on_next_refund(): void
    {
        $order = $this->cancelledPaidOrderWithMileageRefund(points: 5000, price: 35000);
        $restoresBefore = MileageTransaction::query()->where('order_id', $order->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->count();

        $this->bulk([$order->id], 'payment_complete', ['apply_mileage_deduction' => false])->assertOk();
        $this->cancelThroughAdmin($order->fresh(), 'full');

        $second = OrderRefund::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertEquals(0, (float) $second->refund_points_amount);
        $this->assertSame($restoresBefore, MileageTransaction::query()->where('order_id', $order->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->count());
    }

    /**
     * 입금 전에 취소된 주문(취소 복원 order_cancel_restore)을 되살리면 그 마일리지를 다시 뺀다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_rededucts_mileage_restored_by_cancellation
     */
    public function test_reactivate_unpaid_cancelled_order_reclaims_cancel_restore(): void
    {
        $order = $this->cancelledUnpaidDbankOrderWithRestoredMileage(points: 5000);
        $this->assertSame(1, MileageTransaction::query()->where('order_id', $order->id)
            ->where('type', MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value)->count());

        $this->bulk([$order->id])->assertOk();

        $order->refresh();
        $reclaim = $this->useRows($order)->filter(fn ($tx) => ($tx->metadata['reason'] ?? null) === 'reactivation');
        $this->assertCount(1, $reclaim);
        $this->assertEquals(5000, (float) $order->total_points_used_amount);
        $this->assertEquals(0, (float) $order->total_refunded_points_amount);
    }

    /**
     * 되살린 뒤 다시 되살릴 것이 없으면 두 번 빼지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_never_double_deducts
     */
    public function test_reactivate_twice_does_not_double_deduct(): void
    {
        $member = User::factory()->create();
        $order = $this->cancelledPaidOrderWithMileageRefund(points: 5000, member: $member);
        $this->bulk([$order->id])->assertOk();

        $plan = app(UserMileageService::class)->reclaimablePointsForReactivation($order->fresh());

        $this->assertEquals(0.0, $plan);
        $this->assertCount(1, $this->useRows($order)->filter(fn ($tx) => ($tx->metadata['reason'] ?? null) === 'reactivation'));
    }

    /**
     * 부분취소 뒤 나머지 취소로 전체취소된 주문에서 상품을 하나씩 되살리면 처음엔 행 몫, 마지막엔 남은 전액을 뺀다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_partial_revival_reclaims_row_share_then_sweeps
     */
    public function test_partial_cancel_then_reactivate_option_reclaims_row_share_then_sweeps_rest(): void
    {
        $order = $this->paidOrderUsingMileage(points: 10000, price: 20000, extraLines: [['price' => 20000, 'quantity' => 1]]);
        [$a, $b] = [$order->options[0], $order->options[1]];
        $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $a->id, 'cancel_quantity' => 1]]);
        $order = $this->cancelThroughAdmin($order->fresh(), 'full');
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
        $restored = (float) MileageTransaction::query()->where('order_id', $order->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->sum('amount');
        $aShare = (float) $a->fresh()->subtotal_points_used_amount;

        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $a->id, 'quantity' => 1]], 'status' => 'payment_complete',
        ])->assertOk();
        $first = abs((float) $this->useRows($order)->filter(fn ($tx) => ($tx->metadata['reason'] ?? null) === 'reactivation')->sum('amount'));
        $this->assertEquals(min($restored, floor($aShare)), $first);

        $bRow = $order->fresh('options')->options->firstWhere('option_status', OrderStatusEnum::CANCELLED);
        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $bRow->id, 'quantity' => $bRow->quantity]], 'status' => 'payment_complete',
        ])->assertOk();
        $total = abs((float) $this->useRows($order)->filter(fn ($tx) => ($tx->metadata['reason'] ?? null) === 'reactivation')->sum('amount'));
        $this->assertEquals($restored, $total);
    }

    /**
     * 결제 전 주문에서 취소 행의 일부만 되살리며 결제 코스로 올리면, 계속 취소로 남는 수량의 재고는 빼지 않는다.
     *
     * 결제 전 진입(P)과 상품 되살리기(R-상품)가 한 요청에 겹치면 재고 차감 대상에 되살린 행의 원래 id 가
     * 들어간다. 일부 수량만 되살리면 분할 조각은 상태 변경이 이미 차감했는데, 취소로 남은 원래 행이
     * 결제 코스 차감에서 한 번 더 빠졌다(계획서 검증에서 발견).
     *
     * @return void 반환값 없음
     *
     * @effects partial_revival_with_paid_course_entry_deducts_only_revived_stock
     */
    public function test_partial_revival_entering_paid_course_keeps_cancelled_remainder_stock(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 0, balance: 0, quantity: 3, stock: 100);
        $row = $order->options->first();
        $productOption = ProductOption::findOrFail($row->product_option_id);
        $order = $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $row->id, 'cancel_quantity' => 2]]);

        $live = $order->options->firstWhere('option_status', OrderStatusEnum::PENDING_ORDER);
        $cancelled = $order->options->firstWhere('option_status', OrderStatusEnum::CANCELLED);
        $this->assertSame(1, (int) $live->quantity);
        $this->assertSame(2, (int) $cancelled->quantity);
        $this->assertSame(100, (int) $productOption->fresh()->stock_quantity);

        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [
                ['option_id' => $live->id, 'quantity' => 1],
                ['option_id' => $cancelled->id, 'quantity' => 1],
            ],
            'status' => 'payment_complete',
        ])->assertOk();

        // 결제 코스로 올린 2개(남아 있던 1 + 되살린 1)만 빠지고, 계속 취소인 1개는 그대로다.
        $this->assertSame(98, (int) $productOption->fresh()->stock_quantity);
        $remainder = $order->fresh('options')->options->firstWhere('option_status', OrderStatusEnum::CANCELLED);
        $this->assertNotNull($remainder);
        $this->assertSame(1, (int) $remainder->quantity);
        $this->assertFalse((bool) $remainder->is_stock_deducted);
    }

    /**
     * 판매 중인 주문의 취소 상품 하나만 되살려도(부모 전이 없음) 그 행 몫을 회수한다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_partial_revival_reclaims_row_share_then_sweeps
     */
    public function test_reactivate_option_in_live_order_without_parent_transition(): void
    {
        $order = $this->paidOrderUsingMileage(points: 10000, price: 20000, extraLines: [['price' => 20000, 'quantity' => 1]]);
        $a = $order->options[0];
        $order = $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $a->id, 'cancel_quantity' => 1]]);
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $refundedCash = (float) $order->total_refunded_amount;
        $this->assertGreaterThan(0, $refundedCash);

        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $a->id, 'quantity' => 1]], 'status' => 'payment_complete',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(0, (float) $order->total_refunded_amount);
        $this->assertEquals($refundedCash, (float) ($order->order_meta['reactivation_reclaim']['cash'] ?? 0));
    }

    /**
     * 끈 항목은 활동 로그에 「적용 안 함」으로 남는다.
     *
     * @return void 반환값 없음
     *
     * @effects manual_paid_effects_activity_log_lists_skipped_items
     */
    public function test_activity_log_records_skipped_items(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 10000);
        ActivityLog::query()->delete();

        $this->bulk([$order->id], 'payment_complete', ['apply_mileage_deduction' => false])->assertOk();

        $log = ActivityLog::query()->where('action', 'order.manual_paid_effects')->where('loggable_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(['apply_mileage_deduction'], $log->properties['skipped']);
        $this->assertSame('bulk_update', $log->properties['trigger']);
        $this->assertStringContainsString(__('sirsoft-ecommerce::activity_log.manual_paid.items.apply_mileage_deduction'), (string) $log->localized_description);
    }

    /**
     * 세 값은 boolean 이어야 한다.
     *
     * @return void 반환값 없음
     *
     * @effects omitted_flags_default_to_true
     */
    public function test_flags_must_be_boolean(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 0, balance: 0);

        $this->bulk([$order->id], 'payment_complete', ['apply_stock_deduction' => 'abc'])->assertStatus(422);
    }

    /**
     * 결제금액 기록·재고 차감을 끄고 올린 무통장 주문에 [입금확인](결제완료 처리)을 해도 상태를 끌어내리지 않고 재고를 빼지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects confirm_deposit_after_manual_paid_does_not_demote_status
     */
    public function test_confirm_deposit_after_unrecorded_manual_paid_does_not_demote_status(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 0, balance: 0, method: PaymentMethodEnum::DBANK->value);
        $due = (float) $order->total_due_amount;
        $stockBefore = $this->stockOf($order);

        $this->bulk([$order->id], 'preparing', ['record_paid_amount' => false, 'apply_stock_deduction' => false])->assertOk();

        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.orders.update', 'sirsoft-ecommerce.orders.read']))
            ->patchJson($this->ordersUrl()."/{$order->order_number}/confirm-deposit", [
                'amount' => $due, 'mark_order_complete' => true,
            ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PREPARING, $order->order_status);
        $this->assertSame($stockBefore, $this->stockOf($order));
        $this->assertEquals($due, (float) $order->total_paid_amount);
    }
}
