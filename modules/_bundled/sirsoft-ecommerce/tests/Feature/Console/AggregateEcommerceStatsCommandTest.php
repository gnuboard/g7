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
    protected function setUp(): void
    {
        parent::setUp();

        OrderOption::query()->forceDelete();
        Order::query()->forceDelete();
        EcommerceStat::query()->delete();
    }

    /**
     * 오늘 주문일의 매출 반영 옵션 1건을 생성합니다.
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

    #[Test]
    public function test_dry_run_does_not_persist_rows(): void
    {
        $this->makeTodayOption(2, 1000);

        $this->artisan('sirsoft-ecommerce:aggregate-stats', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, EcommerceStat::count());
    }

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
