<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_1_3_3;

/**
 * 1.3.3 주문상품 과세 종류 백필 업그레이드 스텝 테스트 (#612)
 *
 * 1.3.3 이 주문 시점 과세 종류를 주문상품 행에 기록하기 시작했다. 그 전 행은 비어 있으므로 상품 스냅샷 값으로
 * 채우고, 값이 없거나 읽을 수 없으면 과세로 채운다(1.3.2 까지 계산기가 과세로 분류했다). 이미 값이 있는 행은
 * 건드리지 않는다 — 두 번 돌려도 결과가 같아야 한다.
 */
class OrderOptionTaxStatusBackfillTest extends ModuleTestCase
{
    /**
     * 과세 종류가 빈 주문상품 행을 만들고 상품 스냅샷을 원문 그대로 덮어씁니다.
     *
     * @param  string|null  $snapshotJson  product_snapshot 원문 (null = 컬럼 null)
     * @param  string|null  $taxStatus  행의 tax_status (null = 비어 있음)
     * @return int 주문상품 행 ID
     */
    private function makeRow(?string $snapshotJson, ?string $taxStatus = null): int
    {
        $order = OrderFactory::new()->create();
        $row = OrderOptionFactory::new()->forOrder($order)->create(['parent_option_id' => null]);

        DB::table('ecommerce_order_options')->where('id', $row->id)->update([
            'product_snapshot' => $snapshotJson,
            'tax_status' => $taxStatus,
        ]);

        return $row->id;
    }

    /**
     * 1.3.3 업그레이드 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_3_3)->run(new UpgradeContext('1.3.2', '1.3.3', '1.3.3', 'extension-upgrade'));
    }

    /**
     * 행의 tax_status 원값을 읽습니다.
     *
     * @param  int  $id  주문상품 행 ID
     * @return string|null 저장값
     */
    private function statusOf(int $id): ?string
    {
        return DB::table('ecommerce_order_options')->where('id', $id)->value('tax_status');
    }

    /**
     * 스냅샷 과세·면세는 그 값으로, 누락·손상·알 수 없는 값은 과세로 채우는지 확인합니다.
     *
     * @effects order_option_tax_status_backfilled_from_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_backfills_from_snapshot_with_taxable_fallback(): void
    {
        $taxable = $this->makeRow(json_encode(['id' => 1, 'tax_status' => 'taxable']));
        $taxFree = $this->makeRow(json_encode(['id' => 2, 'tax_status' => 'tax_free']));
        $missing = $this->makeRow(json_encode(['id' => 3]));
        $unknown = $this->makeRow(json_encode(['id' => 4, 'tax_status' => 'exempt']));
        $broken = $this->makeRow('{not json');

        $this->runUpgrade();

        $this->assertSame('taxable', $this->statusOf($taxable));
        $this->assertSame('tax_free', $this->statusOf($taxFree));
        $this->assertSame('taxable', $this->statusOf($missing));
        $this->assertSame('taxable', $this->statusOf($unknown));
        $this->assertSame('taxable', $this->statusOf($broken));
    }

    /**
     * 이미 값이 있는 행은 건드리지 않고, 두 번 실행해도 결과가 같은지 확인합니다.
     *
     * @effects order_option_tax_status_backfill_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_keeps_existing_values_and_is_idempotent(): void
    {
        $recorded = $this->makeRow(json_encode(['id' => 1, 'tax_status' => 'taxable']), 'zero_rated');
        $empty = $this->makeRow(json_encode(['id' => 2, 'tax_status' => 'tax_free']));

        $this->runUpgrade();
        $this->runUpgrade();

        $this->assertSame('zero_rated', $this->statusOf($recorded));
        $this->assertSame('tax_free', $this->statusOf($empty));
        $this->assertSame(0, DB::table('ecommerce_order_options')->whereNull('tax_status')->count());
    }
}
