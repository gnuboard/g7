<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsManualPaidOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 수동 결제 처리 항목 미리보기 API (Q13·Q14·Q24·Q26·Q28)
 *
 * 확인 창이 무엇을 보일지는 서버 미리보기가 정한다. 실행과 같은 판정 메서드를 쓰므로 미리보기 수치가
 * 실행 결과와 같아야 하고, 미리보기는 아무것도 쓰지 않아야 한다.
 */
class ManualPaidTransitionPreviewTest extends ModuleTestCase
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
     * 목록 미리보기 URL 입니다.
     *
     * @return string 목록 미리보기 주소
     */
    protected function bulkPreviewUrl(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin/orders/bulk/manual-paid-preview';
    }

    /**
     * 목록 미리보기는 주문별 세 항목과 집계를 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @effects not_applicable_items_are_hidden_in_preview
     */
    public function test_bulk_preview_shape_and_summary(): void
    {
        $pending = $this->pendingOrderUsingMileage(points: 5000, balance: 10000);
        $live = $this->paidOrderUsingMileage(points: 0);

        $response = $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), [
            'ids' => [$pending->id, $live->id], 'order_status' => 'preparing',
        ])->assertOk();

        $response->assertJsonStructure(['data' => [
            'target_status',
            'orders' => [['order_id', 'order_number', 'transition' => ['enters_paid_course', 'revives_whole_order', 'revived_item_count'],
                'stock_deduction' => ['applicable', 'option_count', 'reason'],
                'mileage_deduction' => ['applicable', 'amount', 'amount_formatted', 'currency', 'balance', 'balance_sufficient', 'reason'],
                'paid_amount_record' => ['applicable', 'amount', 'amount_formatted', 'auto_sync', 'auto_sync_amount', 'auto_sync_amount_formatted', 'reason'],
                'restoration', 'warnings']],
            'summary' => ['order_count', 'affected_order_count', 'any_applicable', 'stock_deduction', 'mileage_deduction', 'paid_amount_record', 'never_paid_count', 'restoration', 'warnings'],
        ]]);
        $this->assertSame(2, $response->json('data.summary.order_count'));
        $this->assertSame(1, $response->json('data.summary.affected_order_count'));
        $this->assertSame(1, $response->json('data.summary.mileage_deduction.applicable_count'));
        $this->assertSame(ecommerce_format_price(5000, 'KRW'), $response->json('data.summary.mileage_deduction.total_amount_formatted'));
    }

    /**
     * 수정 권한이 없으면 403 이다.
     *
     * @return void 반환값 없음
     *
     * @effects preview_matches_execution
     */
    public function test_bulk_preview_requires_update_permission_and_scope(): void
    {
        $order = $this->pendingOrderUsingMileage();
        $reader = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $this->actingAs($reader)->postJson($this->bulkPreviewUrl(), ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertStatus(403);
    }

    /**
     * 실행이 거절할 전이(전이 규칙 위반)는 미리보기도 422 로 거절한다.
     *
     * @return void 반환값 없음
     *
     * @effects preview_matches_execution
     */
    public function test_bulk_preview_invalid_transition_is_422(): void
    {
        $order = $this->paidOrderUsingMileage(points: 0);
        $order->forceFill(['order_status' => OrderStatusEnum::DELIVERED])->save();

        $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertStatus(422);
    }

    /**
     * 미리보기는 주문·원장·재고를 바꾸지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects preview_matches_execution
     */
    public function test_preview_does_not_write(): void
    {
        $order = $this->cancelledPaidOrderWithMileageRefund(points: 5000);
        $before = [
            'order' => DB::table('ecommerce_orders')->where('id', $order->id)->first(),
            'options' => DB::table('ecommerce_order_options')->where('order_id', $order->id)->get()->toArray(),
            'ledger' => MileageTransaction::query()->count(),
        ];

        $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), ['ids' => [$order->id], 'order_status' => 'payment_complete'])->assertOk();

        $this->assertEquals($before['order'], DB::table('ecommerce_orders')->where('id', $order->id)->first());
        $this->assertEquals($before['options'], DB::table('ecommerce_order_options')->where('order_id', $order->id)->get()->toArray());
        $this->assertSame($before['ledger'], MileageTransaction::query()->count());
    }

    /**
     * 상품 일괄 미리보기는 수량에 따라 부모 파생 상태를 시뮬레이션한다.
     *
     * @return void 반환값 없음
     *
     * @effects option_bulk_parent_sync_is_atomic_with_effects
     */
    public function test_option_preview_simulates_parent_status_by_quantity(): void
    {
        $order = $this->pendingOrderUsingMileage(points: 5000, balance: 10000, quantity: 2);
        $option = $order->options->first();
        $url = "/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/options/bulk-status/manual-paid-preview";

        $full = $this->actingAs($this->admin)->postJson($url, ['items' => [['option_id' => $option->id, 'quantity' => 2]], 'status' => 'payment_complete'])
            ->assertOk()->json('data.orders.0.transition');
        $part = $this->actingAs($this->admin)->postJson($url, ['items' => [['option_id' => $option->id, 'quantity' => 1]], 'status' => 'payment_complete'])
            ->assertOk()->json('data.orders.0.transition');

        $this->assertTrue($full['enters_paid_course']);
        $this->assertFalse($part['enters_paid_course']);
    }

    /**
     * 같은 회원의 두 주문은 잔액을 누적으로 판정한다.
     *
     * @return void 반환값 없음
     *
     * @effects bulk_mileage_short_is_all_or_nothing_and_names_every_order
     */
    public function test_preview_reports_short_balance_cumulatively(): void
    {
        $member = User::factory()->create();
        $first = $this->pendingOrderUsingMileage(points: 5000, balance: 7000, member: $member);
        $second = $this->pendingOrderUsingMileage(points: 5000, balance: 0, member: $member);

        $data = $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), [
            'ids' => [$first->id, $second->id], 'order_status' => 'payment_complete',
        ])->assertOk()->json('data');

        $rows = collect($data['orders'])->keyBy('order_id');
        $this->assertTrue($rows[$first->id]['mileage_deduction']['balance_sufficient']);
        $this->assertFalse($rows[$second->id]['mileage_deduction']['balance_sufficient']);
        $this->assertSame([$second->order_number], $data['summary']['mileage_deduction']['short_order_numbers']);
    }

    /**
     * 미리보기 금액은 실행 뒤 원장·헤더 변화량과 같다.
     *
     * @return void 반환값 없음
     *
     * @effects preview_matches_execution
     */
    public function test_preview_and_execution_agree(): void
    {
        $order = $this->cancelledPaidOrderWithMileageRefund(points: 5000, price: 35000);
        $preview = $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk()->json('data.orders.0');
        $refundedBefore = (float) $order->total_refunded_amount;

        $this->actingAs($this->admin)->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk();

        $order->refresh();
        $reclaimed = abs((float) MileageTransaction::query()->where('order_id', $order->id)->where('metadata->reason', 'reactivation')->sum('amount'));
        $this->assertEquals($preview['mileage_deduction']['amount'], $reclaimed);
        $this->assertEquals($preview['paid_amount_record']['amount'], $refundedBefore - (float) $order->total_refunded_amount);
        $this->assertEquals($preview['restoration']['totals_after']['total_amount'], (float) $order->total_amount);
    }

    /**
     * 재활성 미리보기는 되살릴 상품 수와 복원 합계를 싣는다.
     *
     * @return void 반환값 없음
     *
     * @effects preview_reports_restoration_and_matches_execution
     */
    public function test_preview_reports_restoration_totals_and_counts(): void
    {
        $paid = $this->paidOrderUsingMileage(points: 0, price: 20000, quantity: 2);
        $before = (float) $paid->total_amount;
        $order = $this->cancelThroughAdmin($paid, 'full');
        $this->assertEquals(0, (float) $order->total_amount);

        $data = $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk()->json('data');

        $this->assertEquals($before, $data['orders'][0]['restoration']['totals_after']['total_amount']);
        $this->assertSame(1, $data['summary']['restoration']['applicable_count']);
        $this->assertSame(2, $data['summary']['restoration']['revived_quantity']);
        $this->assertTrue($data['summary']['any_applicable']);
    }

    /**
     * 다른 주문이 이미 쓴 쿠폰은 충돌로 보고하되 아무것도 쓰지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_reclaims_coupons_or_blocks_on_conflict
     */
    public function test_preview_reports_coupon_conflicts_without_writing(): void
    {
        $coupon = $this->createManualPaidCoupon(2000);
        $order = $this->cancelThroughAdmin($this->paidOrderUsingMileage(points: 0, price: 20000, couponIssueIds: [$coupon->id]), 'full');
        $other = $this->paidOrderUsingMileage(points: 0);
        CouponIssue::whereKey($coupon->id)->update(['status' => CouponIssueRecordStatus::USED, 'order_id' => $other->id]);
        $snapshot = DB::table('ecommerce_orders')->where('id', $order->id)->first();

        $restoration = $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk()->json('data.orders.0.restoration');

        $this->assertSame('used_by_other_order', $restoration['coupon_conflicts'][0]['reason']);
        $this->assertSame($other->order_number, $restoration['coupon_conflicts'][0]['other_order_number']);
        $this->assertEquals($snapshot, DB::table('ecommerce_orders')->where('id', $order->id)->first());
    }

    /**
     * 미리보기 복원 합계는 실행 뒤 헤더와 같다.
     *
     * @return void 반환값 없음
     *
     * @effects preview_reports_restoration_and_matches_execution
     */
    public function test_preview_restoration_matches_execution(): void
    {
        $order = $this->cancelThroughAdmin($this->paidOrderUsingMileage(points: 3000, price: 20000, quantity: 2, shippingFee: 3000), 'full');

        $after = $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk()->json('data.orders.0.restoration.totals_after');
        $this->actingAs($this->admin)->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk();

        $order->refresh();
        foreach (['total_amount', 'subtotal_amount', 'total_discount_amount', 'total_shipping_amount', 'total_points_used_amount'] as $key) {
            $this->assertEquals($after[$key], (float) $order->{$key}, $key);
        }
    }

    /**
     * 카드 결제가 이미 취소된 주문을 되살릴 때 PG 재취소 불가 경고를 싣는다. (Q24)
     *
     * @return void 반환값 없음
     *
     * @effects reactivation_preview_warns_pg_already_cancelled
     */
    public function test_preview_warns_when_pg_payment_already_cancelled(): void
    {
        $order = $this->cancelledPaidOrderWithMileageRefund(points: 0, price: 20000);
        $this->assertSame('cancelled', $order->payment->payment_status->value);
        $unpaid = $this->cancelledUnpaidDbankOrderWithRestoredMileage(points: 0);

        $data = $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), [
            'ids' => [$order->id, $unpaid->id], 'order_status' => 'payment_complete',
        ])->assertOk()->json('data');

        $rows = collect($data['orders'])->keyBy('order_id');
        $this->assertSame(['pg_already_cancelled'], $rows[$order->id]['warnings']);
        $this->assertSame([], $rows[$unpaid->id]['warnings']);
        $this->assertSame([$order->order_number], $data['summary']['warnings']['pg_already_cancelled']);
    }

    /**
     * 입금 전에 취소된 주문을 되살릴 때 「결제금액 기록」 항목을 보이고(기본 켬) 복원 합계를 금액으로 쓴다. (Q28)
     *
     * @return void 반환값 없음
     *
     * @effects never_paid_reactivation_offers_paid_amount_record
     */
    public function test_never_paid_reactivation_offers_paid_amount_record(): void
    {
        $order = $this->cancelledUnpaidDbankOrderWithRestoredMileage(points: 0, price: 20000);

        $data = $this->actingAs($this->admin)->postJson($this->bulkPreviewUrl(), ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk()->json('data');

        $record = $data['orders'][0]['paid_amount_record'];
        $this->assertTrue($record['applicable']);
        $this->assertSame('never_paid', $record['reason']);
        $this->assertEquals($data['orders'][0]['restoration']['totals_after']['total_paid_amount'], $record['amount']);
        $this->assertSame(1, $data['summary']['never_paid_count']);
    }
}
