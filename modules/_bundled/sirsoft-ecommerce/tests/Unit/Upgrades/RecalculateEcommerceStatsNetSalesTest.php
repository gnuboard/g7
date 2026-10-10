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
 * 일별 판매 집계를 사이트 하루로 다시 묶는 재집계(DataMigration) 회귀 테스트 (S-Q26-a · 7.1.0 출시 점검 EU-1/U2-1)
 *
 * 1.3.0 이전 집계식 unit_price × (quantity − cancelled_quantity) 는 부분취소 수량을 두 번 뺐고, 날짜 축은 UTC 하루였다.
 * 1.3.0 런타임은 사이트 표준시 하루로 묶으므로(QE-O2), 이 스텝은 기존 집계 행 전부를 사이트 하루·새 식으로 다시 쓴다
 * (결정 Q-4: 과거 행 전체 재집계). 상태 배지(option_status_counts)도 같은 하루로 다시 센다.
 *
 * 검증 축: ① 한국 시간 새벽(UTC 전날) 주문이 사이트 날짜 행으로 간다 ② 판매수량·순매출·상태 배지 모두 새 축·새 식
 * ③ 그 날 주문이 없게 된 행은 0 으로 ④ 집계 행이 없는 날짜는 만들지 않음 ⑤ 멱등 ⑥ 시간대 미설정이면 UTC 하루
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

        $class = 'App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations\RecalculateEcommerceStatsNetSales';

        $this->migration = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '1.2.1',
            toVersion: '1.3.0',
            currentStep: '1.3.0',
        );
    }

    /**
     * 주문 하나와 옵션 행들을 만듭니다.
     *
     * @param  string  $orderedAtUtc  주문 시각 (UTC, Y-m-d H:i:s)
     * @param  array<int, array{0: OrderStatusEnum, 1: int, 2: int, 3?: int}>  $rows  [상태, 수량, 단가, 취소 카운터]
     * @return void 반환값 없음
     */
    private function makeOrder(string $orderedAtUtc, array $rows): void
    {
        $order = Order::factory()->create(['ordered_at' => $orderedAtUtc]);

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
     * 날짜의 집계 행을 직접 씁니다(예전 UTC 하루·옛 식으로 저장된 값 재현).
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
            'option_status_counts' => ['payment_complete' => 99],
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
     * 한국 시간 새벽 주문을 사이트 날짜 행으로 옮기고, 기존 행 전부를 새 식·새 축으로 다시 쓰며, 다시 돌려도 같다.
     *
     * @effects upgrade_recalculates_past_net_sales_stats
     *
     * @return void 반환값 없음
     */
    public function test_rebuckets_every_existing_row_to_site_day_and_is_idempotent(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        // 한국 시간 2025-03-10 01:00 = UTC 2025-03-09 16:00 — 예전 축이면 03-09 행에 들어갔다. 부분취소 형태
        $this->makeOrder('2025-03-09 16:00:00', [
            [OrderStatusEnum::SHIPPING, 3, 1000, 2],
            [OrderStatusEnum::CANCELLED, 2, 1000, 0],
        ]);
        // 한국 시간 2025-03-10 19:00 = UTC 2025-03-10 10:00 — 카운터 없는 정상 주문
        $this->makeOrder('2025-03-10 10:00:00', [[OrderStatusEnum::DELIVERED, 2, 500]]);
        // 한국 시간 2025-03-12 08:59 = UTC 2025-03-11 23:59 — 집계 행 없는 날짜, 행을 만들지 않는다
        $this->makeOrder('2025-03-11 23:59:00', [[OrderStatusEnum::PAYMENT_COMPLETE, 1, 1000]]);

        $this->seedStat('2025-03-09', 1, '1000.00');
        $this->seedStat('2025-03-10', 2, '1000.00');

        $this->migration->run($this->context);

        // 03-10(한국 하루) = 새벽 부분취소 주문 3×1000 + 저녁 주문 2×500
        $d = $this->stat('2025-03-10');
        $this->assertSame(5, (int) $d->sales_quantity);
        $this->assertSame('4000.00', (string) $d->sales_amount);
        // JSON 컬럼은 키 순서를 바꿔 저장할 수 있어 순서 무관 비교
        $this->assertEquals(
            ['pending_payment' => 0, 'payment_complete' => 0, 'preparing' => 0, 'shipping_ready' => 0,
                'shipping' => 3, 'cancellations' => 2, 'returns' => 0],
            json_decode((string) $d->option_status_counts, true),
        );

        // 03-09(한국 하루) 에는 주문이 없다 — 0 으로
        $c = $this->stat('2025-03-09');
        $this->assertSame(0, (int) $c->sales_quantity);
        $this->assertSame('0.00', (string) $c->sales_amount);
        $this->assertSame(0, array_sum(json_decode((string) $c->option_status_counts, true)));

        $this->assertNull($this->stat('2025-03-11'));
        $this->assertNull($this->stat('2025-03-12'));

        // 두 번째 실행 — 같은 값(업그레이드 뒤 --force 재실행이 런타임 집계를 덮어 틀리게 만들지 않는다)
        $this->migration->run($this->context);
        $d2 = $this->stat('2025-03-10');
        $this->assertSame(5, (int) $d2->sales_quantity);
        $this->assertSame('4000.00', (string) $d2->sales_amount);
        $this->assertNull($this->stat('2025-03-12'));
    }

    /**
     * 사이트 시간대가 비어 있으면 UTC 하루로 묶는다.
     *
     * @effects upgrade_recalculates_past_net_sales_stats
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_utc_day_when_site_timezone_is_empty(): void
    {
        config(['app.default_user_timezone' => null]);

        $this->makeOrder('2025-03-09 16:00:00', [[OrderStatusEnum::DELIVERED, 1, 700]]);
        $this->seedStat('2025-03-09', 0, '0.00');
        $this->seedStat('2025-03-10', 9, '9.00');

        $this->migration->run($this->context);

        $this->assertSame('700.00', (string) $this->stat('2025-03-09')->sales_amount);
        $this->assertSame('0.00', (string) $this->stat('2025-03-10')->sales_amount);
    }
}
