<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\EcommerceStat;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 순매출 집계식 수정 이전에 쌓인 일별 판매 집계 재계산(DataMigration) 회귀 테스트 (S-Q26-a)
 *
 * 1.3.0 이전 집계식 unit_price × (quantity − cancelled_quantity) 는 부분취소 수량을 두 번 뺐다.
 * 스케줄러는 최근 7일만 다시 쓰므로 그 이전 날짜의 집계 행은 이 스텝이 고친다.
 *
 * 검증 축: ① 영향받은 날짜(카운터 있는 판매 행)만 새 식으로 갱신 ② 카운터 없는 날짜 불변
 * ③ 집계 행이 없는 날짜는 만들지 않음 ④ 멱등
 *
 * 시나리오 매니페스트: `tests/scenarios/admin-dashboard-commerce.yaml`
 *
 * @effects upgrade_recalculates_past_net_sales_stats
 */
class RecalculateEcommerceStatsNetSalesTest extends ModuleTestCase
{
    private object $migration;

    private UpgradeContext $context;

    /**
     * 집계·주문을 비우고 업그레이드 데이터 마이그레이션을 불러옵니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        OrderOption::query()->forceDelete();
        Order::query()->forceDelete();
        EcommerceStat::query()->delete();

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.0/migrations/08_RecalculateEcommerceStatsNetSales.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\RecalculateEcommerceStatsNetSales';

        $this->migration = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '1.2.1',
            toVersion: '1.3.0',
            currentStep: '1.3.0',
        );
    }

    /**
     * 주문일에 주문 하나와 옵션 행들을 만듭니다.
     *
     * @param  string  $date  주문일 (Y-m-d)
     * @param  array<int, array{0: OrderStatusEnum, 1: int, 2: int, 3?: int}>  $rows  [상태, 수량, 단가, 취소 카운터]
     * @return void 반환값 없음
     */
    private function makeOrder(string $date, array $rows): void
    {
        $order = Order::factory()->create(['ordered_at' => $date.' 10:00:00']);

        foreach ($rows as $row) {
            OrderOption::factory()->create([
                'order_id' => $order->id,
                'option_status' => $row[0],
                'quantity' => $row[1],
                'unit_price' => $row[2],
                'cancelled_quantity' => $row[3] ?? 0,
            ]);
        }
    }

    /**
     * 날짜의 집계 행을 직접 씁니다(예전 식으로 저장된 값 재현).
     *
     * @param  string  $date  집계일
     * @param  int  $quantity  판매 수량
     * @param  string  $amount  순매출
     * @return void 반환값 없음
     */
    private function seedStat(string $date, int $quantity, string $amount): void
    {
        EcommerceStat::query()->create([
            'date' => $date,
            'sales_quantity' => $quantity,
            'sales_amount' => $amount,
            'option_status_counts' => ['payment_complete' => 1],
        ]);
    }

    /**
     * 날짜의 집계 행을 읽습니다.
     *
     * @param  string  $date  집계일
     * @return object|null 집계 행
     */
    private function stat(string $date): ?object
    {
        return DB::table('ecommerce_stats')->whereDate('date', $date)->first();
    }

    /**
     * 취소 카운터가 있는 날짜의 집계 행만 새 식으로 다시 쓰고, 집계 행이 없는 날짜는 만들지 않으며, 다시 돌려도 같다.
     *
     * @effects upgrade_recalculates_past_net_sales_stats
     *
     * @return void 반환값 없음
     */
    public function test_recalculates_only_dates_with_counter_rows_and_is_idempotent(): void
    {
        // D: 부분취소 형태 — 살아 있는 행 qty 3(카운터 2) + 취소 조각 qty 2. 예전 식으로 1 / 1000 저장
        $this->makeOrder('2025-03-10', [
            [OrderStatusEnum::SHIPPING, 3, 1000, 2],
            [OrderStatusEnum::CANCELLED, 2, 1000, 0],
        ]);
        $this->seedStat('2025-03-10', 1, '1000.00');

        // E: 카운터 없는 정상 날짜 — 저장값이 일부러 다른 값이어도 건드리지 않는다
        $this->makeOrder('2025-03-11', [[OrderStatusEnum::DELIVERED, 2, 500]]);
        $this->seedStat('2025-03-11', 7, '7777.00');

        // F: 카운터 행은 있지만 집계 행이 없는 날짜 — 행을 만들지 않는다
        $this->makeOrder('2025-03-12', [[OrderStatusEnum::PAYMENT_COMPLETE, 1, 1000, 2]]);

        $this->migration->run($this->context);

        $d = $this->stat('2025-03-10');
        $this->assertSame(3, (int) $d->sales_quantity);
        $this->assertSame('3000.00', (string) $d->sales_amount);
        // 상태 배지는 건드리지 않는다
        $this->assertSame(['payment_complete' => 1], json_decode((string) $d->option_status_counts, true));

        $e = $this->stat('2025-03-11');
        $this->assertSame(7, (int) $e->sales_quantity);
        $this->assertSame('7777.00', (string) $e->sales_amount);

        $this->assertNull($this->stat('2025-03-12'));

        // 두 번째 실행 — 같은 값
        $this->migration->run($this->context);
        $d2 = $this->stat('2025-03-10');
        $this->assertSame(3, (int) $d2->sales_quantity);
        $this->assertSame('3000.00', (string) $d2->sales_amount);
        $this->assertNull($this->stat('2025-03-12'));
    }
}
