<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\EcommerceStat;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductLabelAssignment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceDashboardService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 「하루」 경계 테스트 (7.1.0 전수 실측 QE-O1·O2·O3 · 원칙 2026-10-07)
 *
 * 원칙: 사람이 보는 화면의 하루는 그 사람의 시간대, 서버에 적는 시각은 UTC. 다만 날짜당 1행 집계·공용 캐시처럼
 * 보는 사람마다 경계를 달리할 수 없는 값은 사이트 표준시(방문자 통계·게시판 대시보드와 같은 규정).
 *
 * - 대시보드 일별 집계(ecommerce_stats)·「오늘」 배지·추이 그래프·집계 명령 미리보기: 사이트 표준시의 하루.
 * - 주문 통계의 오늘 주문 수·오늘/이번 달 매출: 전역 캐시 하나 — 사이트 표준시의 하루·달.
 * - 상품 라벨 기간(날짜만 저장): 모든 방문자에게 같은 라벨이 보여야 하므로 사이트 표준시의 오늘로 판정.
 *
 * 기준 시각: UTC 2026-10-07 16:30 = 한국 2026-10-08 01:30. 보는 사람 시간대는 일부러 사이트와 다르게(뉴욕) 둔다 —
 * 사이트 표준시를 쓰는지, 보는 사람 시간대를 쓰는지 갈라 보기 위해서다.
 */
class EcommerceDayBoundaryTest extends ModuleTestCase
{
    /**
     * 시각과 시간대를 고정하고 집계 대상 표를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 16:30:00', 'UTC'));
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        App::instance('user_timezone', 'America/New_York');

        OrderOption::query()->forceDelete();
        Order::query()->forceDelete();
        EcommerceStat::query()->delete();
    }

    /**
     * 일별 집계 행·「오늘」 배지·추이 그래프는 사이트 표준시의 하루를 쓴다.
     *
     * @scenario boundary=ecommerce_stats_daily, timezone=site
     *
     * @effects ecommerce_stats_rows_follow_site_day
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function daily_stats_aggregate_by_site_day(): void
    {
        // 한국 2026-10-07 23:30 → 10-07 행, 한국 2026-10-08 00:30 → 10-08 행
        $this->makeOption('2026-10-07 14:30:00', 1);
        $this->makeOption('2026-10-07 15:30:00', 2);

        $service = $this->app->make(EcommerceDashboardService::class);
        $service->aggregateRecentDays(2);

        $today = EcommerceStat::where('date', '2026-10-08')->first();
        $yesterday = EcommerceStat::where('date', '2026-10-07')->first();

        $this->assertNotNull($today, '사이트 하루(2026-10-08) 행이 있어야 합니다');
        $this->assertSame(2, $today->sales_quantity);
        $this->assertSame(1, $yesterday->sales_quantity);

        // 「오늘」 배지와 그래프의 마지막 날도 같은 사이트 하루다
        // 배지는 그 하루의 주문 수를 센다 — 사이트 10-08 의 주문 1건(옵션 수량 2)만 들어가야 한다(어제 밤 주문은 빠짐)
        $this->assertSame(1, $service->getOverview()['payment_complete']);
        $graph = $service->getSalesGraph(7);
        $this->assertSame('2026-10-08', last($graph['days'])['date']);
        $this->assertSame(2, last($graph['days'])['sales_quantity']);
    }

    /**
     * 집계 명령의 미리보기(--dry-run)도 사이트 표준시의 오늘부터 보인다.
     *
     * @scenario boundary=aggregate_command_preview, timezone=site
     *
     * @effects ecommerce_aggregate_preview_starts_at_site_today
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function aggregate_dry_run_starts_at_site_today(): void
    {
        $this->artisan('sirsoft-ecommerce:aggregate-stats', ['--dry-run' => true])
            ->expectsOutputToContain('2026-10-08')
            ->assertExitCode(0);

        $this->assertSame(0, EcommerceStat::count(), 'dry-run 은 저장하지 않는다');
    }

    /**
     * 주문 통계의 오늘 주문 수·오늘 매출·이번 달 매출은 사이트 표준시의 하루·달로 센다.
     *
     * @scenario boundary=order_statistics, timezone=site
     *
     * @effects ecommerce_order_statistics_follow_site_day_and_month
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function order_statistics_use_site_day_and_month(): void
    {
        // UTC 2026-09-30 16:30 = 한국 2026-10-01 01:30 — 하루와 달이 함께 바뀌는 경계
        $this->travelTo(CarbonImmutable::parse('2026-09-30 16:30:00', 'UTC'));

        // 한국 2026-09-30 23:30 — 어제·지난달
        Order::factory()->create([
            'ordered_at' => '2026-09-30 14:30:00',
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
            'total_paid_amount' => 5000,
        ]);
        // 한국 2026-10-01 00:30 — 오늘·이번 달
        Order::factory()->create([
            'ordered_at' => '2026-09-30 15:30:00',
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
            'total_paid_amount' => 1000,
        ]);

        $repository = $this->app->make(OrderRepositoryInterface::class);
        $repository->forgetStatisticsCache();
        $stats = $repository->getStatistics();

        $this->assertSame(1, (int) $stats['today_count']);
        $this->assertEquals(1000, (float) $stats['today_revenue']);
        $this->assertEquals(1000, (float) $stats['monthly_revenue']);
    }

    /**
     * 상품 라벨 기간은 사이트 표준시의 오늘로 판정한다 — 한국 새벽에 시작일이 오늘인 라벨이 켜지고, 어제 끝난 라벨은 꺼진다.
     *
     * @scenario boundary=product_label_period, timezone=site
     *
     * @effects ecommerce_label_period_follows_site_today
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function label_period_uses_site_today(): void
    {
        $product = Product::factory()->create();
        $startsToday = $this->makeLabelAssignment($product, '2026-10-08', null);
        $endedYesterday = $this->makeLabelAssignment($product, null, '2026-10-07');

        $this->assertTrue($startsToday->isActive(), '사이트 오늘(10-08) 시작 라벨은 켜져야 합니다');
        $this->assertFalse($endedYesterday->isActive(), '사이트 어제(10-07) 끝난 라벨은 꺼져야 합니다');

        $activeIds = $product->activeLabelAssignments()->pluck('id')->all();
        $this->assertContains($startsToday->id, $activeIds);
        $this->assertNotContains($endedYesterday->id, $activeIds);
    }

    /**
     * 일광절약시간이 바뀌는 날(23시간·25시간)도 사이트 하루 전체를 센다 — 하루 끝은 「사이트 시간대에서 하루를 더한 직전」이다.
     *
     * 뉴욕 2026-03-08 은 23시간(UTC 05:00 ~ 다음 날 04:00), 2026-11-01 은 25시간(UTC 04:00 ~ 다음 날 05:00)이다.
     * 고정 24시간 범위로 자르면 앞날은 다음 날 0시대 주문이, 뒷날은 그날 23시대 주문이 잘못 들고 난다.
     *
     * @scenario boundary=ecommerce_stats_daily, timezone=site
     *
     * @effects ecommerce_stats_day_spans_dst_transition_day
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function site_day_spans_daylight_saving_transition_days(): void
    {
        config(['app.default_user_timezone' => 'America/New_York']);

        // 2026-03-08(23시간): 03-08 23:30 EDT = UTC 03-09 03:30 → 03-08, 03-09 00:30 EDT = UTC 03-09 04:30 → 03-09
        $this->makeOption('2026-03-09 03:30:00', 1);
        $this->makeOption('2026-03-09 04:30:00', 2);
        // 2026-11-01(25시간): 10-31 23:30 EDT = UTC 11-01 03:30 → 10-31, 11-01 23:30 EST = UTC 11-02 04:30 → 11-01,
        // 11-02 00:30 EST = UTC 11-02 05:30 → 11-02
        $this->makeOption('2026-11-01 03:30:00', 4);
        $this->makeOption('2026-11-02 04:30:00', 8);
        $this->makeOption('2026-11-02 05:30:00', 16);

        $repository = $this->app->make(OrderOptionRepositoryInterface::class);
        $status = OrderStatusEnum::PAYMENT_COMPLETE->value;

        $this->assertSame([$status => 1], $repository->countByOptionStatusOnDate('2026-03-08'));
        $this->assertSame([$status => 2], $repository->countByOptionStatusOnDate('2026-03-09'));
        $this->assertSame([$status => 4], $repository->countByOptionStatusOnDate('2026-10-31'));
        $this->assertSame([$status => 8], $repository->countByOptionStatusOnDate('2026-11-01'));
        $this->assertSame([$status => 16], $repository->countByOptionStatusOnDate('2026-11-02'));
        $this->assertSame(8, $repository->sumNetQuantityOnDate('2026-11-01'));
    }

    /**
     * 매출 반영 상태의 주문상품 1건을 지정한 UTC 주문 시각으로 만듭니다.
     *
     * @param  string  $orderedAtUtc  주문 시각(UTC)
     * @param  int  $quantity  수량
     * @return void 반환값 없음
     */
    private function makeOption(string $orderedAtUtc, int $quantity): void
    {
        // 주문 상태도 옵션과 같게 둔다 — 「오늘」 배지는 주문 상태별 주문 수를 센다
        $order = Order::factory()->create(['ordered_at' => $orderedAtUtc, 'order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => $quantity,
            'cancelled_quantity' => 0,
            'unit_price' => 1000,
        ]);
    }

    /**
     * 상품에 라벨 1개를 기간과 함께 붙입니다.
     *
     * @param  Product  $product  상품
     * @param  string|null  $startDate  시작일(Y-m-d)
     * @param  string|null  $endDate  종료일(Y-m-d)
     * @return ProductLabelAssignment 라벨 연결
     */
    private function makeLabelAssignment(Product $product, ?string $startDate, ?string $endDate): ProductLabelAssignment
    {
        $label = ProductLabel::create([
            'name' => ['ko' => '경계 라벨', 'en' => 'Boundary label'],
            'color' => '#000000',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return ProductLabelAssignment::create([
            'product_id' => $product->id,
            'label_id' => $label->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }
}
