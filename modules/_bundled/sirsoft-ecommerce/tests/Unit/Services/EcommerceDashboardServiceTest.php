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

    /**
     * 집계·주문·리뷰·문의 잔여 행을 비우고 기준 시각을 고정한 뒤 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
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

        // 집계의 「하루」는 사이트 표준시(한국)의 하루다. UTC 날짜와 사이트 날짜가 같은 시각으로 고정한다 —
        // 한국 0~9시(UTC 전날)에 돌리면 UTC 기준 「오늘」 데이터가 사이트 어제로 잡혀 시각 따라 깨진다.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));

        $this->service = $this->app->make(EcommerceDashboardService::class);
    }

    /**
     * 주문 상태가 정해진 주문 1건을 옵션 1줄과 함께 생성합니다.
     *
     * @param  string  $orderedAt  주문 일시 (orders.ordered_at)
     * @param  OrderStatusEnum  $status  주문 상태 (옵션 상태도 같게 둔다)
     * @param  int  $quantity  옵션 수량
     * @return Order 생성된 주문
     */
    private function makeOrderWithOption(string $orderedAt, OrderStatusEnum $status, int $quantity): Order
    {
        $order = Order::factory()->create(['ordered_at' => $orderedAt, 'order_status' => $status]);

        OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => $status,
            'quantity' => $quantity,
            'cancelled_quantity' => 0,
            'unit_price' => 1000,
        ]);

        return $order;
    }

    /**
     * 특정 주문일/상태/수량/단가의 주문상품 1건을 생성합니다.
     *
     * @param  string  $orderedAt  주문 일시 (orders.ordered_at)
     * @param  OrderStatusEnum  $status  주문상품 상태 (option_status)
     * @param  int  $quantity  수량 (부분취소 뒤에는 잔여 수량)
     * @param  int  $unitPrice  단가
     * @param  int  $cancelledQuantity  취소 이력 카운터
     * @return OrderOption 생성된 주문상품
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

    /**
     * 오늘 배지는 오늘 주문을 상태별 **주문 수**로 센다 — 옵션 수량이 아니고, 일 집계 행을 기다리지 않는다
     * (7.1.0 결정 2026-10-08 4차: 배지 = 배지 링크로 연 주문 목록 건수).
     *
     * @effects today_order_status_badges_reflect_overview_api
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_get_overview_counts_today_orders_by_status(): void
    {
        $today = CarbonImmutable::today()->toDateString();
        // 결제완료 주문 3건(수량 2·1·3), 배송중 주문 2건 — 수량 합(6·2)이 아니라 주문 수(3·2)
        foreach ([2, 1, 3] as $quantity) {
            $this->makeOrderWithOption($today, OrderStatusEnum::PAYMENT_COMPLETE, $quantity);
        }
        $this->makeOrderWithOption($today, OrderStatusEnum::SHIPPING, 1);
        $this->makeOrderWithOption($today, OrderStatusEnum::SHIPPING, 1);
        // 묵은 집계 행은 배지가 읽지 않는다
        EcommerceStat::create([
            'date' => $today,
            'sales_quantity' => 99,
            'sales_amount' => 0,
            'option_status_counts' => ['payment_complete' => 99, 'shipping' => 99],
        ]);

        $overview = $this->service->getOverview();

        $this->assertSame(3, $overview['payment_complete']);
        $this->assertSame(2, $overview['shipping']);
        // 그 상태의 주문이 없는 배지는 0
        $this->assertSame(0, $overview['preparing']);
        $this->assertSame(0, $overview['cancellations']);
    }

    /**
     * 오늘 주문이 없으면 모든 배지가 0 이다 — null 이 새어 나가지 않는다.
     *
     * @effects overview_buckets_are_zero_when_no_orders_today
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_get_overview_returns_zero_when_no_orders_today(): void
    {
        $overview = $this->service->getOverview();

        foreach (['pending_payment', 'payment_complete', 'preparing', 'shipping_ready', 'shipping', 'cancellations', 'returns'] as $key) {
            $this->assertSame(0, $overview[$key], "버킷 {$key} 은 0 이어야 함");
        }
    }

    /**
     * 7일 막대와 합계 — 날짜는 오래된 쪽부터, 합계는 기본 통화 자릿수로 정규화된다.
     *
     * @effects sales_graph_chart_renders_7_days_from_sales_graph_api, total_quantity_displays_sum_of_7_days, total_sales_displays_sum_of_7_days
     *
     * @return void 반환값 없음
     */
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

    /**
     * 증감률은 직전 같은 길이 구간(8~14일 전)과 비교해 산출한다.
     *
     * @effects sales_change_rate_compares_against_the_previous_window_of_the_same_length
     *
     * @return void 반환값 없음
     */
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

    /**
     * 비교할 직전 구간이 비면 0% 가 아니라 null 이다 — 화면의 대시(–) 가 이 값에 선다.
     *
     * @effects sales_change_rate_is_null_when_the_previous_window_has_no_rows
     *
     * @return void 반환값 없음
     */
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

    /**
     * 집계 행이 하나도 없으면 갱신 시각 캡션은 서지 않는다.
     *
     * @effects sales_graph_updated_at_caption_hidden_when_no_aggregated_rows
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_sales_graph_updated_at_display_is_empty_when_no_rows(): void
    {
        $graph = $this->service->getSalesGraph(7);

        $this->assertNull($graph['updated_at']);
        $this->assertSame('', $graph['updated_at_display']);
    }

    /**
     * 순매출은 매출 반영 상태의 주문상품만 더한다 — 결제전·취소는 제외된다.
     *
     * @effects net_sales_sums_only_sales_eligible_statuses
     *
     * @return void 반환값 없음
     */
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

    /**
     * 부분취소 뒤 남은 수량은 한 번만 센다 — 취소 수량을 두 번 빼지 않는다.
     *
     * 부분취소는 행을 나눠 살아 있는 행의 quantity 를 잔여 수량으로 줄이고, 같은 행의 cancelled_quantity
     * 에도 취소 수량을 더한다(이력 카운터). 예전 집계식 unit_price × (quantity − cancelled_quantity) 는
     * 취소 수량을 한 번 더 빼서 748 주문의 6개가 5개로 집계됐다. 픽스처는 실제 부분취소가 남기는 모양
     * (살아 있는 행 + 부모를 가리키는 취소 조각)이다. 예전 테스트의 「qty 5, cq 2 단일 행」 픽스처는 코드
     * 경로가 만들 수 없는 모양이라 폐기했다.
     *
     * @scenario module_state=active, data_state=has_orders, user_role=admin_with_dashboard_permission
     *
     * @effects net_sales_counts_remaining_quantity_once
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_aggregate_counts_remaining_quantity_once_after_partial_cancel(): void
    {
        $today = CarbonImmutable::today()->toDateString();

        // 살아 있는 행: 원래 5개 중 2개를 취소해 잔여 3개, 이력 카운터 2
        $alive = $this->makeOption($today, OrderStatusEnum::SHIPPING, 3, 1000, cancelledQuantity: 2);
        // 분할된 취소 조각: 2개, 상태 cancelled (매출 반영 상태가 아니라 집계에서 빠진다)
        OrderOption::factory()->create([
            'order_id' => $alive->order_id,
            'parent_option_id' => $alive->id,
            'option_status' => OrderStatusEnum::CANCELLED,
            'quantity' => 2,
            'cancelled_quantity' => 0,
            'unit_price' => 1000,
        ]);

        $this->service->aggregateRecentDays(7);

        $row = EcommerceStat::where('date', $today)->first();
        $this->assertSame(3, $row->sales_quantity);
        $this->assertSame('3000.00', $row->sales_amount);
    }

    /**
     * 이력 카운터가 수량보다 큰 행이 있어도 집계가 실패하지 않는다.
     *
     * cancelled_quantity 는 unsigned 라 quantity − cancelled_quantity 가 음수가 되면 MySQL 이
     * SQLSTATE 22003(1690 BIGINT UNSIGNED out of range)으로 쿼리 전체를 실패시켰다. 개발 DB 옵션
     * 199(qty 1, cq 2) 같은 행이 판매 상태가 되면 그날 집계 커맨드가 멈췄다.
     *
     * @scenario module_state=active, data_state=has_orders, user_role=admin_with_dashboard_permission
     *
     * @effects net_sales_aggregate_survives_counter_above_quantity
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_aggregate_survives_counter_above_quantity(): void
    {
        $today = CarbonImmutable::today()->toDateString();

        $this->makeOption($today, OrderStatusEnum::PAYMENT_COMPLETE, 1, 1000, cancelledQuantity: 2);

        $this->service->aggregateRecentDays(7);

        $row = EcommerceStat::where('date', $today)->first();
        $this->assertSame(1, $row->sales_quantity);
        $this->assertSame('1000.00', $row->sales_amount);
    }

    /**
     * 매출 귀속일은 주문의 `ordered_at` 이다 — 집계일이 아니다.
     *
     * @effects net_sales_attributed_by_order_ordered_at
     *
     * @return void 반환값 없음
     */
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

    /**
     * 상태 버킷은 취소 옵션을 cancellations 한 칸으로 모은다.
     *
     * @effects status_buckets_merge_cancelled_options_into_one_cancellations_count
     *
     * @return void 반환값 없음
     */
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

    /**
     * #599 통합 실측 P15 ① · S0-D1 — 반품 칸이 0 에 고정돼 있었다.
     *
     * (7.1.0 결정 2026-10-08 4차 뒤로 「오늘」 배지는 이 수량 기록이 아니라 주문 수를 바로 센다 — 이 테스트는 일 집계의
     * 상태별 수량 기록을 지킨다.) 취소 배지가 취소 옵션(cancelled)을 세는 것과 같은 규칙으로, 반품 배지는 반품완료 옵션(returned)을
     * 센다. 반품 진행 중(return_in_progress)은 아직 돌려받지 않은 상품이라 완료 배지에 넣지 않는다 —
     * 취소 배지도 취소 신청 중(환불신청)은 세지 않는다. 실측 DB 에서 2026-09-15 반품완료 수량 16 ·
     * 09-16 22 인데 집계 행의 returns 가 전부 0 이었다.
     *
     * @scenario module_state=active, data_state=has_orders, user_role=admin_with_dashboard_permission
     *
     * @effects aggregate_status_record_counts_returned_option_quantity
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_aggregate_builds_returns_bucket_from_returned_options(): void
    {
        $today = CarbonImmutable::today()->toDateString();

        $this->makeOption($today, OrderStatusEnum::RETURNED, 2, 1000);
        $this->makeOption($today, OrderStatusEnum::RETURNED, 3, 1000);
        $this->makeOption($today, OrderStatusEnum::RETURN_IN_PROGRESS, 4, 1000);
        $this->makeOption($today, OrderStatusEnum::CANCELLED, 1, 1000);

        $this->service->aggregateRecentDays(7);

        $counts = EcommerceStat::where('date', $today)->first()->option_status_counts;

        // 방향까지 고정한다 — returned 만 세고 return_in_progress(4) 는 섞이지 않는다.
        $this->assertSame(5, $counts['returns']);
        $this->assertSame(1, $counts['cancellations']);
    }

    /**
     * 최근 N일만 upsert 한다 — 그 밖의 과거 행은 그대로 남는다.
     *
     * @effects aggregate_recent_days_upserts_only_the_window_and_preserves_older_rows
     *
     * @return void 반환값 없음
     */
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

    /**
     * 같은 구간을 다시 돌려도 행이 늘지 않는다 (스케줄러 재실행 안전).
     *
     * @effects aggregate_recent_days_is_idempotent_on_rerun
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_aggregate_recent_days_is_idempotent_on_rerun(): void
    {
        $this->service->aggregateRecentDays(7);
        $this->service->aggregateRecentDays(7);

        $this->assertSame(7, EcommerceStat::count());
    }

    /**
     * 최근 리뷰 카드 재료는 최신순이다.
     *
     * @effects latest_reviews_card_renders_recent_reviews_api_items
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_get_recent_reviews_returns_visible_reviews_latest_first(): void
    {
        $old = ProductReview::factory()->create();
        $old->forceFill(['created_at' => CarbonImmutable::today()->subDays(2)->toDateTimeString()])->saveQuietly();
        $latest = ProductReview::factory()->create();

        $reviews = $this->service->getRecentReviews(5);

        $this->assertSame($latest->id, $reviews->first()->id);
    }

    /**
     * 미답변 문의만 세고, 게시판 이동용 slug 를 함께 낸다.
     *
     * @effects pending_inquiries_card_renders_pending_inquiries_api_items, pending_inquiries_count_badge_shows_total, pending_inquiries_payload_carries_the_board_slug_for_navigation
     *
     * @return void 반환값 없음
     */
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
