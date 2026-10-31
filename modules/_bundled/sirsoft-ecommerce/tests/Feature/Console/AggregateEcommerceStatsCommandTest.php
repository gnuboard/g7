<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Carbon\CarbonImmutable;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\EcommerceStat;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 판매 현황 집계 커맨드 테스트
 *
 * sirsoft-ecommerce:aggregate-stats {--dry-run}
 */
class AggregateEcommerceStatsCommandTest extends ModuleTestCase
{
    /**
     * 기준 시각을 고정하고 주문·주문상품·집계 잔여 행을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 집계의 「하루」는 사이트 표준시(한국)의 하루다. UTC 날짜와 사이트 날짜가 같은 시각으로 고정한다 —
        // 한국 0~9시(UTC 전날)에 돌리면 UTC 기준 「오늘」 데이터가 사이트 어제로 잡혀 시각 따라 깨진다.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));

        OrderOption::query()->forceDelete();
        Order::query()->forceDelete();
        EcommerceStat::query()->delete();
    }

    /**
     * 오늘 주문일의 매출 반영 옵션 1건을 생성합니다.
     *
     * @param  int  $quantity  주문 수량
     * @param  int  $unitPrice  단가
     * @return void 반환값 없음
     */
    private function makeTodayOption(int $quantity, int $unitPrice): void
    {
        $order = Order::factory()->create(['ordered_at' => CarbonImmutable::today()->setTime(12, 0)->toDateTimeString()]);
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => $quantity,
            'cancelled_quantity' => 0,
            'unit_price' => $unitPrice,
        ]);
    }

    /**
     * --dry-run 으로 돌리면 집계만 하고 ecommerce_stats 에 행을 저장하지 않는다.
     *
     * @scenario module_state=active, data_state=has_orders
     *
     * @effects aggregate_command_dry_run_persists_no_rows
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_dry_run_does_not_persist_rows(): void
    {
        $this->makeTodayOption(2, 1000);

        $this->artisan('sirsoft-ecommerce:aggregate-stats', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, EcommerceStat::count());
    }

    /**
     * 커맨드는 최근 7일 행만 다시 쓰고 그보다 오래된 행은 보존한다.
     *
     * @scenario module_state=active, data_state=has_orders
     *
     * @effects aggregate_recent_days_upserts_only_the_window_and_preserves_older_rows
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_aggregates_recent_7_days_and_preserves_older(): void
    {
        $today = CarbonImmutable::today();
        $eightDaysAgo = $today->subDays(8)->toDateString();
        EcommerceStat::create(['date' => $eightDaysAgo, 'sales_quantity' => 99, 'sales_amount' => 99000, 'option_status_counts' => []]);

        $this->makeTodayOption(2, 1000);

        $this->artisan('sirsoft-ecommerce:aggregate-stats')->assertExitCode(0);

        // 오늘 행: 수량 2, 순매출 2000
        $todayRow = EcommerceStat::where('date', $today->toDateString())->first();
        $this->assertSame(2, $todayRow->sales_quantity);
        $this->assertSame('2000.00', $todayRow->sales_amount);

        // 8일 전 행 보존
        $oldRow = EcommerceStat::where('date', $eightDaysAgo)->first();
        $this->assertSame(99, $oldRow->sales_quantity);

        // 7일치 + 8일전 1행 = 8행
        $this->assertSame(8, EcommerceStat::count());
    }

    /**
     * 커맨드를 두 번 돌려도 행 수와 집계값이 늘지 않는다.
     *
     * @scenario module_state=active, data_state=has_orders
     *
     * @effects aggregate_recent_days_is_idempotent_on_rerun
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_is_idempotent_on_rerun(): void
    {
        $this->makeTodayOption(2, 1000);

        $this->artisan('sirsoft-ecommerce:aggregate-stats')->assertExitCode(0);
        $this->artisan('sirsoft-ecommerce:aggregate-stats')->assertExitCode(0);

        $this->assertSame(7, EcommerceStat::count());

        $todayRow = EcommerceStat::where('date', CarbonImmutable::today()->toDateString())->first();
        $this->assertSame(2, $todayRow->sales_quantity);
    }

    /**
     * 대시보드 집계 스케줄러 설정이 꺼져 있으면 커맨드는 아무 행도 저장하지 않는다.
     *
     * @scenario module_state=active, data_state=has_orders
     *
     * @effects aggregate_command_skips_when_scheduler_disabled
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_skips_when_scheduler_disabled(): void
    {
        // g7_module_settings 는 g7_settings.modules.{id} 에서 읽으므로 해당 경로를 설정한다.
        config()->set('g7_settings.modules.sirsoft-ecommerce.dashboard.scheduler_enabled', false);

        $this->makeTodayOption(2, 1000);

        $this->artisan('sirsoft-ecommerce:aggregate-stats')->assertExitCode(0);

        // 스케줄러 비활성화 시 저장하지 않음
        $this->assertSame(0, EcommerceStat::count());
    }
}
