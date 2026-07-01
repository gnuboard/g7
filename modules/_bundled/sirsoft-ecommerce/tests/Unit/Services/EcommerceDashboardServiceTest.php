<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Carbon\CarbonImmutable;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\EcommerceStat;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Services\EcommerceDashboardService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 대시보드 서비스 테스트
 *
 * 판매 수량/순매출은 주문상품(order_options)의 매출 반영 상태(option_status) 옵션만 합산하며,
 * 날짜는 주문(orders.ordered_at) 기준으로 귀속한다.
 */
class EcommerceDashboardServiceTest extends ModuleTestCase
{
    private EcommerceDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // ecommerce_stats 는 전역 단일 집계 테이블이며, across-products 카운트를 단언하므로
        // 같은 스위트의 잔여 행을 명시 초기화한다.
        OrderOption::query()->forceDelete();
        Order::query()->forceDelete();
        ProductReview::query()->forceDelete();
        ProductInquiry::query()->forceDelete();
        EcommerceStat::query()->delete();

        $this->service = $this->app->make(EcommerceDashboardService::class);
    }

    /**
     * 특정 주문일/상태/수량/단가의 주문상품 1건을 생성합니다.
     */
    private function makeOption(
        string $orderedAt,
        OrderStatusEnum $status,
        int $quantity,
        int $unitPrice,
        int $cancelledQuantity = 0,
    ): OrderOption {
        $order = Order::factory()->create(['ordered_at' => $orderedAt]);

        return OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => $status,
            'quantity' => $quantity,
            'cancelled_quantity' => $cancelledQuantity,
            'unit_price' => $unitPrice,
        ]);
    }

    #[Test]
    public function test_get_overview_reads_today_buckets_from_ecommerce_stats(): void
    {
        $today = CarbonImmutable::today()->toDateString();
        EcommerceStat::create([
            'date' => $today,
            'sales_quantity' => 5,
            'sales_amount' => 50000,
            'option_status_counts' => [
                'payment_complete' => 3,
                'shipping' => 2,
            ],
        ]);

        $overview = $this->service->getOverview();

        $this->assertSame(3, $overview['payment_complete']);
        $this->assertSame(2, $overview['shipping']);
        // 미존재 버킷은 0
        $this->assertSame(0, $overview['preparing']);
        $this->assertSame(0, $overview['cancellations']);
    }

    #[Test]
    public function test_get_overview_returns_zero_when_today_row_missing(): void
    {
        $overview = $this->service->getOverview();

        foreach (['pending_payment', 'payment_complete', 'preparing', 'shipping_ready', 'shipping', 'cancellations', 'returns'] as $key) {
            $this->assertSame(0, $overview[$key], "버킷 {$key} 은 0 이어야 함");
        }
    }

    #[Test]
    public function test_get_sales_graph_returns_current_7_day_bars_and_totals(): void
    {
        $today = CarbonImmutable::today();
        for ($i = 0; $i < 7; $i++) {
            EcommerceStat::create([
                'date' => $today->subDays($i)->toDateString(),
                'sales_quantity' => 2,
                'sales_amount' => 10000,
                'option_status_counts' => [],
            ]);
        }

        $graph = $this->service->getSalesGraph(7);

        $this->assertCount(7, $graph['days']);
        $this->assertSame(14, $graph['total_quantity']);
        // 매출 금액은 기본 통화(테스트 기본 KRW, 0자리) 자릿수로 정규화 → 정수
        $this->assertSame(70000, $graph['total_sales']);
        $this->assertSame($today->subDays(6)->toDateString(), $graph['days'][0]['date']);
        $this->assertSame($today->toDateString(), $graph['days'][6]['date']);
    }

    #[Test]
    public function test_sales_graph_change_rate_is_calculated_vs_previous_7_days(): void
    {
        $today = CarbonImmutable::today();
        // 이번 7일: 수량 합 14
        for ($i = 0; $i < 7; $i++) {
            EcommerceStat::create(['date' => $today->subDays($i)->toDateString(), 'sales_quantity' => 2, 'sales_amount' => 0, 'option_status_counts' => []]);
        }
        // 직전 7일(8~14일 전): 수량 합 7
        for ($i = 7; $i < 14; $i++) {
            EcommerceStat::create(['date' => $today->subDays($i)->toDateString(), 'sales_quantity' => 1, 'sales_amount' => 0, 'option_status_counts' => []]);
        }

        $graph = $this->service->getSalesGraph(7);

        // (14 - 7) / 7 * 100 = 100.0
        $this->assertSame(100.0, $graph['quantity_change']);
    }

    #[Test]
    public function test_sales_graph_change_rate_is_null_when_no_previous_data(): void
    {
        $today = CarbonImmutable::today();
        for ($i = 0; $i < 7; $i++) {
            EcommerceStat::create(['date' => $today->subDays($i)->toDateString(), 'sales_quantity' => 2, 'sales_amount' => 10000, 'option_status_counts' => []]);
        }

        $graph = $this->service->getSalesGraph(7);

        $this->assertNull($graph['quantity_change']);
        $this->assertNull($graph['sales_change']);
    }

    #[Test]
    public function test_sales_graph_updated_at_display_is_empty_when_no_rows(): void
    {
        $graph = $this->service->getSalesGraph(7);

        $this->assertNull($graph['updated_at']);
        $this->assertSame('', $graph['updated_at_display']);
    }

    #[Test]
    public function test_aggregate_only_sums_sales_eligible_statuses(): void
    {
        $today = CarbonImmutable::today()->toDateString();

        // 매출 반영: payment_complete (수량 2 × 단가 1000 = 2000)
        $this->makeOption($today, OrderStatusEnum::PAYMENT_COMPLETE, 2, 1000);
        // 매출 반영: shipping_hold 포함 검증 (수량 1 × 단가 5000 = 5000)
        $this->makeOption($today, OrderStatusEnum::SHIPPING_HOLD, 1, 5000);
        // 매출 제외: 결제전(pending_payment)
        $this->makeOption($today, OrderStatusEnum::PENDING_PAYMENT, 3, 9999);
        // 매출 제외: 취소(cancelled)
        $this->makeOption($today, OrderStatusEnum::CANCELLED, 4, 9999);

        $this->service->aggregateRecentDays(7);

        $row = EcommerceStat::where('date', $today)->first();
        // 수량: 2 + 1 = 3 (결제전/취소 제외)
        $this->assertSame(3, $row->sales_quantity);
        // 순매출: 2000 + 5000 = 7000
        $this->assertSame('7000.00', $row->sales_amount);
    }

    #[Test]
    public function test_aggregate_deducts_cancelled_quantity_from_net_sales(): void
    {
        $today = CarbonImmutable::today()->toDateString();

        // 수량 5, 그 중 2 취소, 단가 1000 → 유효수량 3, 순매출 3000
        $this->makeOption($today, OrderStatusEnum::SHIPPING, 5, 1000, cancelledQuantity: 2);

        $this->service->aggregateRecentDays(7);

        $row = EcommerceStat::where('date', $today)->first();
        $this->assertSame(3, $row->sales_quantity);
        $this->assertSame('3000.00', $row->sales_amount);
    }

    #[Test]
    public function test_aggregate_attributes_sales_by_order_ordered_at_date(): void
    {
        $today = CarbonImmutable::today();
        $todayStr = $today->toDateString();
        $threeDaysAgoStr = $today->subDays(3)->toDateString();

        // 오늘 주문
        $this->makeOption($today->setTime(10, 0)->toDateTimeString(), OrderStatusEnum::CONFIRMED, 1, 1000);
        // 3일 전 주문 (오늘 행에 잡히면 안 됨)
        $this->makeOption($today->subDays(3)->setTime(9, 0)->toDateTimeString(), OrderStatusEnum::CONFIRMED, 7, 1000);

        $this->service->aggregateRecentDays(7);

        $todayRow = EcommerceStat::where('date', $todayStr)->first();
        $threeDaysAgoRow = EcommerceStat::where('date', $threeDaysAgoStr)->first();

        $this->assertSame(1, $todayRow->sales_quantity, '오늘 행은 오늘 주문만 귀속');
        $this->assertSame(7, $threeDaysAgoRow->sales_quantity, '3일 전 행은 3일 전 주문 귀속');
    }

    #[Test]
    public function test_aggregate_builds_status_buckets_with_cancellation_merge(): void
    {
        $today = CarbonImmutable::today()->toDateString();

        $this->makeOption($today, OrderStatusEnum::PAYMENT_COMPLETE, 2, 1000);
        $this->makeOption($today, OrderStatusEnum::CANCELLED, 3, 1000);

        $this->service->aggregateRecentDays(7);

        $row = EcommerceStat::where('date', $today)->first();
        $counts = $row->option_status_counts;

        $this->assertSame(2, $counts['payment_complete']);
        // 부분취소는 별도 상태가 아니라 취소 옵션(cancelled)으로 집계 → cancellations = cancelled(3)
        $this->assertSame(3, $counts['cancellations']);
    }

    #[Test]
    public function test_aggregate_recent_days_upserts_only_recent_7_days_preserving_older(): void
    {
        $today = CarbonImmutable::today();
        $eightDaysAgo = $today->subDays(8)->toDateString();
        EcommerceStat::create(['date' => $eightDaysAgo, 'sales_quantity' => 99, 'sales_amount' => 99000, 'option_status_counts' => []]);

        $this->makeOption($today->toDateString(), OrderStatusEnum::CONFIRMED, 1, 1000);

        $this->service->aggregateRecentDays(7);

        // 8일 전 행은 변경되지 않음
        $oldRow = EcommerceStat::where('date', $eightDaysAgo)->first();
        $this->assertSame(99, $oldRow->sales_quantity);

        // 7일치 + 8일전 1행 = 8행
        $this->assertSame(8, EcommerceStat::count());
    }

    #[Test]
    public function test_aggregate_recent_days_is_idempotent_on_rerun(): void
    {
        $this->service->aggregateRecentDays(7);
        $this->service->aggregateRecentDays(7);

        $this->assertSame(7, EcommerceStat::count());
    }

    #[Test]
    public function test_get_recent_reviews_returns_visible_reviews_latest_first(): void
    {
        $old = ProductReview::factory()->create();
        $old->forceFill(['created_at' => CarbonImmutable::today()->subDays(2)->toDateTimeString()])->saveQuietly();
        $latest = ProductReview::factory()->create();

        $reviews = $this->service->getRecentReviews(5);

        $this->assertSame($latest->id, $reviews->first()->id);
    }

    #[Test]
    public function test_get_pending_inquiries_returns_unanswered_items_and_total(): void
    {
        $product = Product::factory()->create();
        ProductInquiry::factory()->create(['is_answered' => false, 'product_id' => $product->id]);
        ProductInquiry::factory()->create(['is_answered' => false, 'product_id' => $product->id]);
        ProductInquiry::factory()->create(['is_answered' => true, 'product_id' => $product->id]);

        $result = $this->service->getPendingInquiries(5);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['items']);
        // 상품 문의는 게시판 Post 로 관리되므로 대시보드 네비게이션용 board_slug 키를 포함한다.
        $this->assertArrayHasKey('board_slug', $result);
    }
}
