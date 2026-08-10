<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_1_0_5;

/**
 * 1.0.5 주문 부가세 백필 업그레이드 스텝 테스트 (#493 E5)
 *
 * 부가세 쓰기 지점이 부분취소 재계산 1곳뿐이라, 부분취소를 겪은 주문만 값이 정상화되고
 * 나머지 주문은 영구 0 이었습니다. 백필은 그 0 을 메우되, 이미 값이 있는 주문(부분취소로
 * 정상화된 주문)은 건드리지 않아야 합니다 — 정상 값을 재계산으로 덮어쓰면 회계 수치가
 * 조용히 바뀝니다.
 *
 * @scenario actor=member, change_mode=api
 *
 * @effects order_vat_backfilled_for_zero_only, mixed_tax_rate_summed_per_option
 */
class OrderVatBackfillTest extends ModuleTestCase
{
    /**
     * 부가세/과세표준을 지정한 주문을 만듭니다.
     *
     * @param  int  $totalTaxAmount  주문 과세표준
     * @param  int  $totalVatAmount  주문 부가세 (0 = 미기록)
     * @param  array<int, array{taxable: int, rate: float}>  $options  옵션별 과세표준·세율
     * @return int 생성된 주문 ID
     */
    private function makeOrder(int $totalTaxAmount, int $totalVatAmount, array $options): int
    {
        $order = OrderFactory::new()->create();

        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'total_tax_amount' => $totalTaxAmount,
            'total_vat_amount' => $totalVatAmount,
        ]);

        foreach ($options as $spec) {
            $orderOption = OrderOptionFactory::new()->forOrder($order)->create([
                'parent_option_id' => null,
            ]);

            $snapshot = $orderOption->product_snapshot;
            $snapshot['tax_rate'] = $spec['rate'];

            DB::table('ecommerce_order_options')->where('id', $orderOption->id)->update([
                'subtotal_tax_amount' => $spec['taxable'],
                'product_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $order->id;
    }

    /**
     * 1.0.5 업그레이드 스텝을 실행합니다.
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_0_5)->run(new UpgradeContext('1.0.4', '1.0.5', '1.0.5', 'extension-upgrade'));
    }

    /**
     * 부가세를 반환합니다.
     *
     * @param  int  $orderId  주문 ID
     * @return int 부가세
     */
    private function vatOf(int $orderId): int
    {
        return (int) Order::find($orderId)->total_vat_amount;
    }

    /**
     * 부가세가 0 인 주문만 스냅샷 세율로 백필되는지 테스트합니다.
     */
    public function test_zero_vat_order_is_backfilled_with_snapshot_rate(): void
    {
        $orderId = $this->makeOrder(11000, 0, [['taxable' => 11000, 'rate' => 10.0]]);

        $this->runUpgrade();

        $this->assertSame(1000, $this->vatOf($orderId));
    }

    /**
     * 이미 부가세가 기록된 주문은 백필이 건드리지 않는지 테스트합니다.
     *
     * 부분취소로 정상화된 주문을 재계산으로 덮어쓰면 회계 수치가 조용히 바뀐다.
     */
    public function test_order_with_existing_vat_is_left_untouched(): void
    {
        $orderId = $this->makeOrder(11000, 777, [['taxable' => 11000, 'rate' => 10.0]]);

        $this->runUpgrade();

        $this->assertSame(777, $this->vatOf($orderId));
    }

    /**
     * 과세표준이 없는 주문(전액 면세)은 0 으로 남는지 테스트합니다.
     */
    public function test_tax_free_order_stays_zero(): void
    {
        $orderId = $this->makeOrder(0, 0, [['taxable' => 0, 'rate' => 10.0]]);

        $this->runUpgrade();

        $this->assertSame(0, $this->vatOf($orderId));
    }

    /**
     * 세율이 섞인 주문은 옵션별 세율로 합산되는지 테스트합니다.
     *
     * 주문 전체 과세표준에 단일 세율을 적용하면 값이 어긋난다.
     */
    public function test_mixed_rate_order_is_summed_per_option(): void
    {
        $orderId = $this->makeOrder(21500, 0, [
            ['taxable' => 11000, 'rate' => 10.0],
            ['taxable' => 10500, 'rate' => 5.0],
        ]);

        $this->runUpgrade();

        // 1000(10%) + 500(5%) = 1500. 단일 세율이었다면 1955.
        $this->assertSame(1500, $this->vatOf($orderId));
    }

    /**
     * 재실행해도 결과가 같은지 테스트합니다 (멱등).
     */
    public function test_backfill_is_idempotent(): void
    {
        $orderId = $this->makeOrder(11000, 0, [['taxable' => 11000, 'rate' => 10.0]]);

        $this->runUpgrade();
        $first = $this->vatOf($orderId);

        $this->runUpgrade();

        $this->assertSame($first, $this->vatOf($orderId));
    }
}
