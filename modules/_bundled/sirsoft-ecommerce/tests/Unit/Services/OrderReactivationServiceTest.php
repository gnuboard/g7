<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\OrderReactivationService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 재활성 복원 서비스 — 장부 정합 진리표·회수 가능 마일리지 원장 순계산 (Q14·Q26)
 */
class OrderReactivationServiceTest extends ModuleTestCase
{
    /**
     * 사용·반환 헤더를 가진 주문을 만듭니다.
     *
     * @param  float  $used  사용 마일리지 헤더 (total_points_used_amount)
     * @param  float  $returned  반환 마일리지 누적 헤더 (total_refunded_points_amount)
     * @param  OrderStatusEnum  $rowStatus  상품 행 상태
     * @return Order 만든 주문
     */
    protected function orderWithLedger(float $used, float $returned, OrderStatusEnum $rowStatus = OrderStatusEnum::PAYMENT_COMPLETE): Order
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_points_used_amount' => $used,
            'total_refunded_points_amount' => $returned,
            'total_paid_amount' => 30000,
            'total_refunded_amount' => 0,
            'cancelled_at' => now(),
            'currency_snapshot' => null,
        ]);
        OrderOption::factory()->forOrder($order)->create(['option_status' => $rowStatus, 'quantity' => 1]);

        return $order->fresh();
    }

    /**
     * 02c 2-5 표 — 02b 마일리지 단계 뒤 (U, R) 와 재계산 사용 포인트로 정합 결과가 정해진다.
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: float, 4: float}> [U, R, 재계산, U_new, R_new]
     */
    public static function reconcileTable(): array
    {
        return [
            '환불 경로 · 차감 켬' => [10000, 0, 10000, 10000, 0],
            '환불 경로 · 차감 끔' => [10000, 10000, 10000, 10000, 10000],
            '미결제 취소 · 차감 켬' => [5000, 0, 5000, 5000, 0],
            '미결제 취소 · 차감 끔' => [0, 0, 5000, 5000, 5000],
            '부분취소 뒤 되살림 · 켬' => [10000, 0, 10000, 10000, 0],
            '부분취소 뒤 되살림 · 끔' => [6000, 0, 10000, 10000, 4000],
        ];
    }

    /**
     * 장부 정합 단계는 「실제로 쥔 마일리지」를 넘어 돌려줄 수 없게 반환 누적을 맞춘다.
     *
     * @effects reactivation_reconciles_points_ledger_after_restoration
     *
     * @param  float  $used  02b 마일리지 단계 뒤 사용 마일리지
     * @param  float  $returned  02b 마일리지 단계 뒤 반환 누적
     * @param  float  $after  재계산 사용 마일리지
     * @param  float  $usedNew  기대 사용 마일리지
     * @param  float  $returnedNew  기대 반환 누적
     * @return void 반환값 없음
     */
    #[DataProvider('reconcileTable')]
    public function test_reconcile_ledger_truth_table(float $used, float $returned, float $after, float $usedNew, float $returnedNew): void
    {
        $order = $this->orderWithLedger($used, $returned);

        app(OrderReactivationService::class)->reconcileLedgerAfterRestoration($order, $after, null);

        $order->refresh();
        $this->assertEquals($usedNew, (float) $order->total_points_used_amount);
        $this->assertEquals($returnedNew, (float) $order->total_refunded_points_amount);
    }

    /**
     * 두 번 불러도 같은 값이다 (일괄 2단계에서 재호출 안전).
     *
     * @effects reactivation_reconciles_points_ledger_after_restoration
     *
     * @return void 반환값 없음
     */
    public function test_reconcile_is_idempotent(): void
    {
        $order = $this->orderWithLedger(6000, 0);
        $service = app(OrderReactivationService::class);

        $service->reconcileLedgerAfterRestoration($order, 10000, null);
        $service->reconcileLedgerAfterRestoration($order, 10000, null);

        $order->refresh();
        $this->assertEquals(10000, (float) $order->total_points_used_amount);
        $this->assertEquals(4000, (float) $order->total_refunded_points_amount);
    }

    /**
     * 취소 행이 남지 않으면 취소일을 지우고, 남으면 둔다. 현금성 금액은 「결제 − 환불 누적」으로 자른다.
     *
     * @effects reactivation_reduces_cancelled_amount_by_reclaimed_only
     *
     * @return void 반환값 없음
     */
    public function test_reconcile_clears_cancelled_at_only_without_cancelled_rows(): void
    {
        $live = $this->orderWithLedger(0, 0);
        $partly = $this->orderWithLedger(0, 0, OrderStatusEnum::CANCELLED);
        $service = app(OrderReactivationService::class);

        $service->reconcileLedgerAfterRestoration($live, null, 50000);
        $service->reconcileLedgerAfterRestoration($partly, null, null);

        $this->assertNull($live->fresh()->cancelled_at);
        $this->assertEquals(30000, (float) $live->fresh()->total_cash_equivalent_amount);
        $this->assertNotNull($partly->fresh()->cancelled_at);
    }

    /**
     * 회수 가능 마일리지는 취소 출처 복원만 센다 — 반품 발 환급·클레임 추가결제 축·이미 다시 뺀 몫은 뺀다.
     *
     * @effects reactivation_excludes_return_and_claim_payment_restores, reactivation_never_double_deducts
     *
     * @return void 반환값 없음
     */
    public function test_reclaimable_points_counts_only_cancellation_restores(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $refund = fn (RefundSourceTypeEnum $source) => OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => $source,
            'refund_number' => 'RF-'.uniqid(),
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 0,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);
        $row = fn (array $extra) => MileageTransaction::create(array_merge([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'order_id' => $order->id,
            'remaining_amount' => 0,
            'balance_after' => 0,
        ], $extra));

        $row(['type' => MileageTransactionTypeEnum::REFUND_RESTORE->value, 'amount' => 3000, 'order_refund_id' => $refund(RefundSourceTypeEnum::ADMIN)->id]);
        $row(['type' => MileageTransactionTypeEnum::REFUND_RESTORE->value, 'amount' => 1000, 'order_refund_id' => $refund(RefundSourceTypeEnum::CANCEL)->id]);
        $row(['type' => MileageTransactionTypeEnum::REFUND_RESTORE->value, 'amount' => 7000, 'order_refund_id' => $refund(RefundSourceTypeEnum::RETURN)->id]);
        $row(['type' => MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value, 'amount' => 2000, 'order_cancel_id' => null]);
        $row(['type' => MileageTransactionTypeEnum::ORDER_USE->value, 'amount' => -1500, 'metadata' => ['reason' => 'reactivation']]);
        $row(['type' => MileageTransactionTypeEnum::ORDER_USE->value, 'amount' => -9000]);

        $this->assertEquals(2500.0, app(UserMileageService::class)->reclaimablePointsForReactivation($order));
    }
}
