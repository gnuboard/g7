<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsManualPaidOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 취소 주문·상품 재활성 시 상품 행·주문 합계 복원 (Q26·Q27·Q28)
 *
 * 취소는 실제 관리자 취소 API 로 만들고, 재활성은 목록 일괄·단건·상품 일괄 세 경로로 한다. 복원 합계는
 * 취소 엔진과 같은 입력(최초 취소 직전 프로모션 스냅샷)으로 다시 계산한 값이어야 원 주문과 같다.
 */
class ManualPaidReactivationRestorationTest extends ModuleTestCase
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
        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.orders.update']);
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
     * 합계 비교에 쓰는 헤더 값을 고릅니다.
     *
     * @param  Order  $order  주문
     * @return array<string, float> 합계
     */
    protected function totalsOf(Order $order): array
    {
        return collect([
            'total_amount', 'subtotal_amount', 'total_shipping_amount', 'base_shipping_amount',
            'total_discount_amount', 'total_tax_amount', 'total_vat_amount',
        ])->mapWithKeys(fn ($key) => [$key => (float) $order->{$key}])->all();
    }

    /**
     * 목록 일괄로 결제완료로 되살립니다.
     *
     * @param  array<int>  $ids  주문 ID
     * @param  array<string, mixed>  $flags  세 항목 값
     * @return TestResponse 응답
     */
    protected function reviveBulk(array $ids, array $flags = [])
    {
        return $this->actingAs($this->admin)->patchJson($this->ordersUrl().'/bulk', array_merge(['ids' => $ids, 'order_status' => 'payment_complete'], $flags));
    }

    /**
     * 전체취소된 주문을 목록 일괄로 되살리면 상품 행과 합계가 원래대로 돌아오고 취소 문서는 남는다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_revives_cancelled_rows_with_order, reactivation_restores_order_totals_with_cancel_engine_inputs, reactivation_reduces_cancelled_amount_by_reclaimed_only
     */
    public function test_bulk_reactivation_of_fully_cancelled_order_restores_rows_and_totals(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, quantity: 1, extraLines: [['price' => 15000, 'quantity' => 2]], shippingFee: 3000);
        $before = $this->totalsOf($paid);
        $paidAmount = (float) $paid->total_paid_amount;
        $order = $this->cancelThroughAdmin($paid, 'full');
        $this->assertEquals(0, (float) $order->total_amount);

        $this->reviveBulk([$order->id])->assertOk();

        $order->refresh();
        foreach ($order->options as $row) {
            $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $row->option_status);
            $this->assertSame(0, (int) $row->cancelled_quantity);
            $this->assertNull($row->cancel_reason);
        }
        $this->assertEquals($before, $this->totalsOf($order));
        $this->assertEquals((float) $order->total_amount, (float) ($order->mc_total_amount['KRW'] ?? 0), 'mc_total_amount 도 복원 합계로 다시 환산돼야 한다');
        $this->assertEquals($paidAmount, (float) $order->total_paid_amount);
        $this->assertEquals(0, (float) $order->total_refunded_amount);
        $this->assertEquals(0, (float) $order->total_cancelled_amount);
        $this->assertNull($order->cancelled_at);
        $this->assertEquals(0, (float) $order->total_due_amount);
        $this->assertSame(1, OrderCancel::query()->where('order_id', $order->id)->count());
    }

    /**
     * 마일리지를 쓴 결제완료 주문을 전체취소했다 되살려도 합계가 취소 전과 같다 — `total_amount` 는 사용 마일리지를 뺀 값이다.
     *
     * 실측(C12 M5): 24,000원 상품을 마일리지 5,000 + 무통장 19,000 으로 산 주문을 되살리자 `total_amount` 가 24,000
     * (마일리지 차감 전 「결제 예정금액」)으로 써져 주문 목록에 미결제 5,000원이 떴다. 주문 생성은 차감 후 값(19,000)을 쓴다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_restores_order_totals_with_cancel_engine_inputs
     */
    public function test_reactivation_of_mileage_paid_order_restores_total_amount_net_of_points(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 5000, price: 24000);
        $before = $this->totalsOf($paid);
        $this->assertEquals(19000, $before['total_amount'], '생성 시 total_amount 는 마일리지를 뺀 값이다');
        $order = $this->cancelThroughAdmin($paid, 'full');

        $this->reviveBulk([$order->id])->assertOk();

        $order->refresh();
        $this->assertEquals($before, $this->totalsOf($order));
        $this->assertEquals(19000, (float) ($order->mc_total_amount['KRW'] ?? 0));
        $this->assertEquals(0.0, (float) $order->total_amount - (float) $order->total_paid_amount, '목록 미결제액(total_amount - total_paid_amount)이 0 이어야 한다');
    }

    /**
     * 단건 수정으로 되살려도 같다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_revives_cancelled_rows_with_order
     */
    public function test_single_update_reactivation_restores_rows_and_totals(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, quantity: 2);
        $before = $this->totalsOf($paid);
        $order = $this->cancelThroughAdmin($paid, 'full');
        $this->attachManualPaidAddress($order);

        $this->actingAs($this->admin)
            ->patchJson($this->ordersUrl()."/{$order->order_number}", array_merge(['order_status' => 'payment_complete'], $this->recipientBody()))
            ->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->options->first()->option_status);
        $this->assertEquals($before, $this->totalsOf($order));
    }

    /**
     * 서비스 bulkUpdateStatus 경로도 상품 행을 되살린다(종전에는 옵션 동기화 자체가 없었다).
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_revives_cancelled_rows_with_order
     */
    public function test_bulk_update_status_service_path_restores_rows(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, quantity: 2);
        $before = $this->totalsOf($paid);
        $order = $this->cancelThroughAdmin($paid, 'full');

        $this->actingAs($this->admin);
        app(OrderService::class)->bulkUpdateStatus([$order->id], 'payment_complete');

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->options->first()->option_status);
        $this->assertEquals($before, $this->totalsOf($order));
    }

    /**
     * 되살릴 때 취소로 풀린 주문 쿠폰을 이 주문 몫으로 다시 「사용됨」으로 돌리고 할인을 복원한다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_reclaims_coupons_or_blocks_on_conflict
     */
    public function test_reactivation_reclaims_order_coupons(): void
    {
        $coupon = $this->createManualPaidCoupon(2000, 15000);
        $paid = $this->paidOrderUsingMileage(points: 0, price: 20000, couponIssueIds: [$coupon->id]);
        $this->assertEquals(2000, (float) $paid->total_order_coupon_discount_amount);
        $before = $this->totalsOf($paid);
        $order = $this->cancelThroughAdmin($paid, 'full');
        $this->assertSame(CouponIssueRecordStatus::AVAILABLE, $coupon->fresh()->status);

        $this->reviveBulk([$order->id])->assertOk();

        $coupon->refresh();
        $this->assertSame(CouponIssueRecordStatus::USED, $coupon->status);
        $this->assertSame($order->id, (int) $coupon->order_id);
        $order->refresh();
        $this->assertEquals(2000, (float) $order->total_order_coupon_discount_amount);
        $this->assertEquals($before, $this->totalsOf($order));
        $this->assertContains($coupon->id, $order->promotions_applied_snapshot['coupon_issue_ids'] ?? []);
    }

    /**
     * 취소 뒤 만료된 쿠폰도 주문 당시 정당하게 쓴 것이므로 다시 「사용됨」이 된다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_reclaims_coupons_or_blocks_on_conflict
     */
    public function test_reactivation_reclaims_expired_coupon_as_used(): void
    {
        $coupon = $this->createManualPaidCoupon(2000);
        $order = $this->cancelThroughAdmin($this->paidOrderUsingMileage(points: 0, price: 20000, couponIssueIds: [$coupon->id]), 'full');
        $coupon->update(['status' => CouponIssueRecordStatus::EXPIRED, 'expired_at' => now()->subDay()]);

        $this->reviveBulk([$order->id])->assertOk();

        $this->assertSame(CouponIssueRecordStatus::USED, $coupon->fresh()->status);
    }

    /**
     * 쿠폰을 다른 주문이 이미 썼으면 422 로 막고 아무것도 바꾸지 않는다. (Q27)
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_reclaims_coupons_or_blocks_on_conflict
     */
    public function test_reactivation_blocked_when_coupon_used_by_another_order(): void
    {
        $coupon = $this->createManualPaidCoupon(2000);
        $order = $this->cancelThroughAdmin($this->paidOrderUsingMileage(points: 3000, price: 20000, couponIssueIds: [$coupon->id]), 'full');
        $other = $this->paidOrderUsingMileage(points: 0);
        CouponIssue::whereKey($coupon->id)->update(['status' => CouponIssueRecordStatus::USED, 'order_id' => $other->id]);
        $ledgerBefore = MileageTransaction::query()->count();
        $refundedBefore = (float) $order->total_refunded_amount;

        $this->attachManualPaidAddress($order);
        $response = $this->actingAs($this->admin)
            ->patchJson($this->ordersUrl()."/{$order->order_number}", array_merge(['order_status' => 'payment_complete'], $this->recipientBody()))
            ->assertStatus(422);

        $this->assertSame([$order->order_number], $response->json('errors.order_numbers'));
        $this->assertSame($other->order_number, $response->json('errors.coupons.0.other_order_number'));
        $order->refresh();
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->options->first()->option_status);
        $this->assertSame($ledgerBefore, MileageTransaction::query()->count());
        $this->assertEquals($refundedBefore, (float) $order->total_refunded_amount);
    }

    /**
     * 일괄은 쿠폰 충돌 주문이 하나라도 있으면 전체를 바꾸지 않고 해당 주문번호를 모두 알린다.
     *
     * @return void 반환값 없음
     *
     * @effects bulk_reactivation_coupon_conflict_is_all_or_nothing
     */
    public function test_bulk_reactivation_coupon_conflict_is_all_or_nothing(): void
    {
        $ok = $this->cancelledPaidOrderWithMileageRefund(points: 5000);
        $other = $this->paidOrderUsingMileage(points: 0);
        $blocked = [];
        foreach ([1, 2] as $_) {
            $coupon = $this->createManualPaidCoupon(2000);
            $order = $this->cancelThroughAdmin($this->paidOrderUsingMileage(points: 0, price: 20000, couponIssueIds: [$coupon->id]), 'full');
            CouponIssue::whereKey($coupon->id)->update(['status' => CouponIssueRecordStatus::USED, 'order_id' => $other->id]);
            $blocked[] = $order;
        }

        $response = $this->reviveBulk([$ok->id, $blocked[0]->id, $blocked[1]->id])->assertStatus(422);

        $this->assertEqualsCanonicalizing([$blocked[0]->order_number, $blocked[1]->order_number], $response->json('errors.order_numbers'));
        $this->assertSame(OrderStatusEnum::CANCELLED, $ok->fresh()->order_status);
        $this->assertSame(0, MileageTransaction::query()->where('order_id', $ok->id)->where('metadata->reason', 'reactivation')->count());
    }

    /**
     * 부분취소 뒤 나머지를 취소한 주문을 되살리면 분할된 두 행이 한 행으로 합쳐지고 취소 카운터가 0 이 된다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_releases_cancelled_quantity_counter, reactivation_merges_split_rows_back
     */
    public function test_reactivation_after_partial_then_full_cancel_merges_rows_back(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, quantity: 3);
        $row = $paid->options->first();
        $this->cancelThroughAdmin($paid, 'partial', [['order_option_id' => $row->id, 'cancel_quantity' => 1]]);
        $order = $this->cancelThroughAdmin($paid->fresh(), 'full');

        $this->reviveBulk([$order->id])->assertOk();

        $rows = $order->fresh('options')->options;
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows->first()->quantity);
        $this->assertSame(0, (int) $rows->first()->cancelled_quantity);
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $rows->first()->option_status);
    }

    /**
     * 판매 중인 주문의 취소 상품을 상품 일괄로 되살리면 합계가 원래대로 돌아온다(부모 전이 없음).
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_restores_order_totals_with_cancel_engine_inputs
     */
    public function test_partially_cancelled_live_order_revive_row_restores_totals(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, extraLines: [['price' => 20000, 'quantity' => 1]]);
        $before = $this->totalsOf($paid);
        $a = $paid->options->first();
        $order = $this->cancelThroughAdmin($paid, 'partial', [['order_option_id' => $a->id, 'cancel_quantity' => 1]]);
        $this->assertNotEquals($before['total_amount'], (float) $order->total_amount);

        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $a->id, 'quantity' => 1]], 'status' => 'payment_complete',
        ])->assertOk();

        $order->refresh();
        $this->assertEquals($before['total_amount'], (float) $order->total_amount);
        $this->assertSame(0, $order->options->where('option_status', OrderStatusEnum::CANCELLED)->count());
        $this->assertEquals(0, (float) $order->total_refunded_amount);
    }

    /**
     * 되살린 주문을 다시 부분취소하면 그 상품 몫만큼 정확히 환불된다(행이 살아 있어 접수 가능).
     *
     * @return void 반환값 없음
     *
     * @effects reactivated_order_refunds_exact_share_on_next_cancel
     */
    public function test_reactivated_order_partial_cancel_refunds_exact_line_share(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, extraLines: [['price' => 20000, 'quantity' => 1]]);
        $order = $this->cancelThroughAdmin($paid, 'full');
        $this->reviveBulk([$order->id])->assertOk();

        $a = $order->fresh('options')->options->sortBy('id')->first();
        $this->cancelThroughAdmin($order->fresh(), 'partial', [['order_option_id' => $a->id, 'cancel_quantity' => 1]]);

        $refund = OrderRefund::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertEquals((float) $a->subtotal_paid_amount, (float) $refund->refund_amount);
        $this->assertGreaterThan(0, (float) $refund->refund_amount);
    }

    /**
     * 되살린 주문을 다시 전체취소하면 회수한 현금·마일리지를 다시 돌려주고 쿠폰도 다시 풀린다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivated_order_refunds_exact_share_on_next_cancel
     */
    public function test_reactivated_order_full_cancel_again_refunds_reclaimed_amounts(): void
    {
        $coupon = $this->createManualPaidCoupon(2000);
        $paid = $this->paidOrderUsingMileage(points: 5000, price: 37000, couponIssueIds: [$coupon->id]);
        $cash = (float) $paid->total_paid_amount;
        $order = $this->cancelThroughAdmin($paid, 'full');
        $this->reviveBulk([$order->id])->assertOk();

        $this->cancelThroughAdmin($order->fresh(), 'full');

        $second = OrderRefund::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertEquals($cash, (float) $second->refund_amount);
        $this->assertEquals(5000, (float) $second->refund_points_amount);
        $this->assertEquals(0, (float) $order->fresh()->total_amount);
        $this->assertSame(CouponIssueRecordStatus::AVAILABLE, $coupon->fresh()->status);
    }

    /**
     * 입금 전에 취소된 주문을 되살리면 사용 마일리지·합계가 돌아오고, 결제금액 기록(기본 켬)으로 결제행이 결제완료가 된다. (Q28)
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_reconciles_points_ledger_after_restoration, never_paid_reactivation_offers_paid_amount_record
     */
    public function test_unpaid_cancelled_order_reactivation_restores_points_and_totals(): void
    {
        $order = $this->cancelledUnpaidDbankOrderWithRestoredMileage(points: 5000, price: 35000);
        $this->assertEquals(0, (float) $order->total_amount);

        $this->reviveBulk([$order->id])->assertOk();

        $order->refresh();
        $this->assertEquals(5000, (float) $order->total_points_used_amount);
        $this->assertEquals(0, (float) $order->total_refunded_points_amount);
        $this->assertEquals(30000, (float) $order->total_amount, '합계는 사용 마일리지 5,000 을 뺀 값이다(주문 생성과 같은 의미)');
        $this->assertEquals(30000, (float) $order->total_paid_amount);
        $this->assertEquals(0, (float) $order->total_due_amount);
        $this->assertSame(PaymentStatusEnum::PAID, $order->payment->payment_status);
    }

    /**
     * 입금 전 취소 주문을 결제금액 기록을 끄고 되살리면 결제금액 0·미수금이 남는다. (Q28)
     *
     * @return void 반환값 없음
     *
     * @effects never_paid_reactivation_offers_paid_amount_record
     */
    public function test_unpaid_reactivation_without_record_keeps_amount_due(): void
    {
        $order = $this->cancelledUnpaidDbankOrderWithRestoredMileage(points: 0, price: 20000);

        $this->reviveBulk([$order->id], ['record_paid_amount' => false])->assertOk();

        $order->refresh();
        $this->assertEquals(0, (float) $order->total_paid_amount);
        $this->assertEquals(20000, (float) $order->total_due_amount);
        $this->assertNotSame(PaymentStatusEnum::PAID, $order->payment->payment_status);
    }

    /**
     * 입금 전 취소 주문을 마일리지 차감을 끄고 되살리면 나중에 돌려줄 마일리지가 생기지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_reconciles_points_ledger_after_restoration
     */
    public function test_unpaid_reactivation_with_mileage_unchecked_leaves_nothing_refundable(): void
    {
        $order = $this->cancelledUnpaidDbankOrderWithRestoredMileage(points: 5000);

        $this->reviveBulk([$order->id], ['apply_mileage_deduction' => false, 'record_paid_amount' => false])->assertOk();

        $order->refresh();
        $this->assertEquals(5000, (float) $order->total_points_used_amount);
        $this->assertEquals(5000, (float) $order->total_refunded_points_amount);
        $restored = fn (): float => (float) MileageTransaction::query()->where('order_id', $order->id)
            ->whereIn('type', [MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value, MileageTransactionTypeEnum::REFUND_RESTORE->value])
            ->sum('amount');
        $before = $restored();

        $this->cancelThroughAdmin($order->fresh(), 'full');

        $this->assertEquals($before, $restored(), '차감하지 않은 마일리지를 다음 취소에서 돌려주면 안 된다');
    }

    /**
     * 환불 경로로 취소된 주문을 마일리지 차감을 끄고 되살리면 사용·반환 장부가 같게 남아 다시 돌려줄 수 없다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_reconciles_points_ledger_after_restoration
     */
    public function test_refund_path_reactivation_with_mileage_unchecked_keeps_points_unreturnable(): void
    {
        $order = $this->cancelledPaidOrderWithMileageRefund(points: 10000, price: 40000);

        $this->reviveBulk([$order->id], ['apply_mileage_deduction' => false])->assertOk();

        $order->refresh();
        $this->assertEquals(10000, (float) $order->total_points_used_amount);
        $this->assertEquals(10000, (float) $order->total_refunded_points_amount);
    }

    /**
     * 취소 누적액은 실제로 회수한 금액만큼만 줄어든다(마일리지 회수를 끄면 그 몫은 남는다).
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_reduces_cancelled_amount_by_reclaimed_only
     */
    public function test_total_cancelled_amount_drops_only_by_reclaimed_amounts(): void
    {
        $order = $this->cancelledPaidOrderWithMileageRefund(points: 5000, price: 35000);
        $cancelled = (float) $order->total_cancelled_amount;

        $this->reviveBulk([$order->id], ['apply_mileage_deduction' => false])->assertOk();

        $this->assertEquals($cancelled - 30000, (float) $order->fresh()->total_cancelled_amount);
    }

    /**
     * 최초 취소 직전 스냅샷이 없는 레거시 주문은 현재 스냅샷으로 다시 계산하고 미리보기에 표시한다.
     *
     * @return void 반환값 없음
     *
     * @effects first_cancellation_keeps_pre_cancel_snapshot
     */
    public function test_legacy_order_without_pre_cancel_snapshot_uses_current_snapshot(): void
    {
        $order = $this->cancelThroughAdmin($this->paidOrderUsingMileage(points: 0, price: 20000), 'full');
        $meta = $order->order_meta;
        unset($meta['pre_cancel_snapshot']);
        $order->forceFill(['order_meta' => $meta])->save();

        $preview = $this->actingAs($this->admin)->postJson($this->ordersUrl().'/bulk/manual-paid-preview', ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk()->json('data.orders.0.restoration');
        $this->assertTrue($preview['legacy_snapshot']);

        $this->reviveBulk([$order->id])->assertOk();
        $this->assertEquals(20000, (float) $order->fresh()->total_amount);
    }

    /**
     * 최초 취소 직전 스냅샷은 처음 한 번만 기록되고 이후 취소에서 바뀌지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects first_cancellation_keeps_pre_cancel_snapshot
     */
    public function test_first_cancellation_stores_pre_cancel_snapshot_once(): void
    {
        $coupon = $this->createManualPaidCoupon(1000);
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, quantity: 3, couponIssueIds: [$coupon->id]);
        $row = $paid->options->first();

        $this->cancelThroughAdmin($paid, 'partial', [['order_option_id' => $row->id, 'cancel_quantity' => 1]]);
        $first = $paid->fresh()->order_meta['pre_cancel_snapshot'] ?? null;
        $this->assertNotNull($first);
        $this->assertSame([$coupon->id], $first['promotions_applied_snapshot']['coupon_issue_ids']);

        $this->cancelThroughAdmin($paid->fresh(), 'partial', [['order_option_id' => $row->id, 'cancel_quantity' => 1]]);
        $this->assertEquals($first, $paid->fresh()->order_meta['pre_cancel_snapshot']);
    }

    /**
     * 부분취소로 최소주문금액을 잃어 풀린 쿠폰은 그 상품을 되살리면 다시 적용·회수된다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_reclaims_coupons_or_blocks_on_conflict
     */
    public function test_dropped_coupon_is_reapplied_when_condition_restored(): void
    {
        $coupon = $this->createManualPaidCoupon(2000, 25000);
        $paid = $this->paidOrderUsingMileage(points: 0, price: 15000, extraLines: [['price' => 15000, 'quantity' => 1]], couponIssueIds: [$coupon->id]);
        $a = $paid->options->first();
        $order = $this->cancelThroughAdmin($paid, 'partial', [['order_option_id' => $a->id, 'cancel_quantity' => 1]]);
        $this->assertSame(CouponIssueRecordStatus::AVAILABLE, $coupon->fresh()->status);

        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $a->id, 'quantity' => 1]], 'status' => 'payment_complete',
        ])->assertOk();

        $this->assertSame(CouponIssueRecordStatus::USED, $coupon->fresh()->status);
        $this->assertEquals(2000, (float) $order->fresh()->total_order_coupon_discount_amount);
    }

    /**
     * 재활성 활동 로그에 복원 항목과 합계 전후가 남는다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_revives_cancelled_rows_with_order
     */
    public function test_reactivation_activity_log_records_restoration(): void
    {
        $order = $this->cancelThroughAdmin($this->paidOrderUsingMileage(points: 0, price: 20000), 'full');
        ActivityLog::query()->delete();

        $this->reviveBulk([$order->id])->assertOk();

        $log = ActivityLog::query()->where('action', 'order.manual_paid_effects')->where('loggable_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->properties['items']['restoration']['applied']);
        $changed = collect($log->changes)->keyBy('field');
        $this->assertEquals(0, $changed['total_amount']['old']);
        $this->assertEquals(20000, $changed['total_amount']['new']);
        $this->assertStringContainsString(__('sirsoft-ecommerce::activity_log.manual_paid.items.restoration'), (string) $log->localized_description);
    }

    /**
     * 클레임 조회 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    protected function claimsAdmin(): User
    {
        return $this->createAdminUser(['sirsoft-ecommerce.claims.read', 'sirsoft-ecommerce.orders.read']);
    }

    /**
     * 주문 전체를 되살리면 그 사이의 취소신청서 둘이 모두 전부 재활성으로 기록되고 목록 행에 배지 값이 실린다 (Q29).
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_marks_reverted_cancel_documents, claim_list_cancel_row_shows_reactivated_badge_with_time
     */
    public function test_whole_order_reactivation_marks_all_reverted_cancels(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, quantity: 3);
        $row = $paid->options->first();
        $this->cancelThroughAdmin($paid, 'partial', [['order_option_id' => $row->id, 'cancel_quantity' => 1]]);
        $order = $this->cancelThroughAdmin($paid->fresh(), 'full');

        $this->reviveBulk([$order->id])->assertOk();

        [$cn1, $cn2] = OrderCancel::query()->where('order_id', $order->id)->orderBy('id')->get()->all();
        $this->assertSame(1, $cn1->reactivated_quantity);
        $this->assertSame(2, $cn2->reactivated_quantity);
        $this->assertNotNull($cn1->reactivated_at);
        $this->assertNotNull($cn2->reactivated_at);

        $rows = collect($this->actingAs($this->claimsAdmin())
            ->getJson('/api/modules/sirsoft-ecommerce/admin/claims?type=cancel')
            ->assertOk()
            ->json('data.data'))->keyBy('claim_number');
        foreach ([$cn1, $cn2] as $cn) {
            $this->assertNotNull($rows[$cn->cancel_number]['reactivated_at_formatted'] ?? null);
            $this->assertFalse($rows[$cn->cancel_number]['is_partially_reactivated']);
        }
    }

    /**
     * 취소 상품 2개 중 1개만 되살리면 그 취소는 「일부 재활성」이고 상세가 수량을 함께 싣는다 (Q29).
     *
     * @return void 반환값 없음
     *
     * @effects claim_list_cancel_row_shows_partial_reactivated_badge, claim_detail_cancel_card_shows_reactivated_time_and_quantity
     */
    public function test_partial_row_revival_marks_cancel_partially(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, quantity: 2, extraLines: [['price' => 20000, 'quantity' => 1]]);
        $a = $paid->options->firstWhere('quantity', 2);
        $order = $this->cancelThroughAdmin($paid, 'partial', [['order_option_id' => $a->id, 'cancel_quantity' => 2]]);

        $this->actingAs($this->admin)->patchJson($this->ordersUrl()."/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $a->id, 'quantity' => 1]], 'status' => 'payment_complete',
        ])->assertOk();

        $cn = OrderCancel::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(1, $cn->reactivated_quantity);

        $claim = $this->actingAs($this->claimsAdmin())
            ->getJson('/api/modules/sirsoft-ecommerce/admin/claims/'.$cn->cancel_number)
            ->assertOk()
            ->json('data.claim');
        $this->assertTrue($claim['is_partially_reactivated']);
        $this->assertSame(1, $claim['reactivated_quantity']);
        $this->assertSame(2, $claim['total_cancel_quantity']);
        $this->assertNotNull($claim['reactivated_at_formatted']);
    }

    /**
     * 되살린 뒤 다시 취소하면 새 취소신청서에는 배지가 없고, 구매자 상세 응답에는 재활성 값이 실리지 않는다 (Q29).
     *
     * @return void 반환값 없음
     *
     * @effects cancel_created_after_reactivation_has_no_badge, buyer_claim_detail_hides_reactivation_fields
     */
    public function test_cancel_after_reactivation_has_no_badge(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 10000, quantity: 2);
        $order = $this->cancelThroughAdmin($paid, 'full');
        $this->reviveBulk([$order->id])->assertOk();

        $row = $order->fresh('options')->options->first();
        $this->cancelThroughAdmin($order->fresh(), 'partial', [['order_option_id' => $row->id, 'cancel_quantity' => 1]]);

        [$revived, $later] = OrderCancel::query()->where('order_id', $order->id)->orderBy('id')->get()->all();
        $this->assertNotNull($revived->reactivated_at);
        $this->assertNull($later->reactivated_at);
        $this->assertSame(0, $later->reactivated_quantity);

        // 구매자는 회원 역할로 자기 신청서를 조회한다(user-claims.read)
        $buyer = User::query()->findOrFail($order->user_id);
        $buyer->roles()->attach(Role::where('identifier', 'user')->value('id'));
        $claim = $this->actingAs($buyer)
            ->getJson('/api/modules/sirsoft-ecommerce/user/claims/'.$revived->cancel_number)
            ->assertOk()
            ->json('data.claim');
        $this->assertIsArray($claim);
        $this->assertArrayNotHasKey('reactivated_at_formatted', $claim);
        $this->assertArrayNotHasKey('reactivated_quantity', $claim);
    }
}
