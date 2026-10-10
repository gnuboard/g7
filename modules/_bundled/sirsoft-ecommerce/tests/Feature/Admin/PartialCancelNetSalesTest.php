<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use Carbon\CarbonImmutable;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\EcommerceStat;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\EcommerceDashboardService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsManualPaidOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 부분취소 뒤 대시보드 순매출 집계 (S-Q26-a)
 *
 * 부분취소는 실제 관리자 취소 API 로 한다. 취소 엔진은 살아 있는 행의 quantity 를 잔여로 줄이고
 * 같은 행 cancelled_quantity 에 취소 수량을 더한다. 집계가 이 카운터를 또 빼면 748 주문처럼 6개 중
 * 1개 취소가 4개로 집계된다.
 *
 * 시나리오 매니페스트: `tests/scenarios/admin-dashboard-commerce.yaml`
 */
class PartialCancelNetSalesTest extends ModuleTestCase
{
    use BuildsManualPaidOrders;

    /**
     * 통화·사유를 심고 집계 테이블을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();

        // ecommerce_stats 는 전역 단일 집계 테이블 — 오늘 행을 이 주문만으로 단언하려고 비운다.
        OrderOption::query()->forceDelete();
        Order::query()->forceDelete();
        EcommerceStat::query()->delete();
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
     * 관리자 부분취소 뒤 대시보드 집계는 남은 수량을 한 번만 세고, 살아 있는 행의 취소 카운터는 이력으로 남는다.
     *
     * @effects net_sales_counts_remaining_quantity_once, partial_cancel_keeps_cancelled_quantity_as_history_counter
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_through_admin_endpoint_counts_remaining_quantity(): void
    {
        $order = $this->paidOrderUsingMileage(points: 0, price: 4000, quantity: 6);
        $order->forceFill(['ordered_at' => CarbonImmutable::now()])->save();
        $row = $order->options->first();

        $this->cancelThroughAdmin($order, 'partial', [
            ['order_option_id' => $row->id, 'cancel_quantity' => 1],
        ]);

        app(EcommerceDashboardService::class)->aggregateRecentDays(7);

        $alive = $row->fresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $alive->option_status);
        $this->assertSame(5, (int) $alive->quantity);
        // 카운터는 이력으로 남는다(의미 고정)
        $this->assertSame(1, (int) $alive->cancelled_quantity);

        $stat = EcommerceStat::where('date', CarbonImmutable::today()->toDateString())->first();
        $this->assertNotNull($stat);
        $this->assertSame(5, $stat->sales_quantity);
        $this->assertSame('20000.00', $stat->sales_amount);
    }
}
