<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_1_1_1;

/**
 * 1.1.1 업그레이드 스텝 테스트 (공개 #94)
 *
 * - 연속형 구간 정책의 시작값을 직전 종료값으로 정규화 (표시 정합)
 * - 총 무게/부피가 0 으로 고정 기록된 주문을 옵션 소계 합으로 백필
 *
 * 두 스텝 모두 멱등이어야 합니다 — 업그레이드는 재실행될 수 있고, 두 번째 실행이
 * 값을 다시 움직이면 운영자가 보정한 값이 조용히 덮어써집니다.
 *
 * @scenario charge_policy=range_weight, boundary=at_max, unit_source=g
 *
 * @effects continuous_tier_min_normalized, order_totals_backfilled, upgrade_is_idempotent
 */
class ShippingTierAndOrderTotalsBackfillTest extends ModuleTestCase
{
    /**
     * 1.1.1 업그레이드 스텝을 실행합니다.
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_1_1)->run(new UpgradeContext('1.1.0', '1.1.1', '1.1.1', 'extension-upgrade'));
    }

    /**
     * 구간 설정을 가진 배송정책 국가별 설정을 생성합니다.
     *
     * @param  ChargePolicyEnum  $chargePolicy  부과정책
     * @param  array  $tiers  구간 배열
     * @return int 생성된 국가별 설정 ID
     */
    private function makeCountrySetting(ChargePolicyEnum $chargePolicy, array $tiers): int
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '업그레이드 테스트 정책', 'en' => 'Upgrade Test Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $setting = $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => $chargePolicy,
            'base_fee' => 0,
            'ranges' => ['tiers' => $tiers],
            'extra_fee_enabled' => false,
            'is_active' => true,
        ]);

        return $setting->id;
    }

    /**
     * 국가별 설정의 구간 배열을 반환합니다.
     *
     * @param  int  $settingId  국가별 설정 ID
     * @return array 구간 배열
     */
    private function tiersOf(int $settingId): array
    {
        $row = DB::table('ecommerce_shipping_policy_country_settings')->where('id', $settingId)->first();

        return json_decode((string) $row->ranges, true)['tiers'] ?? [];
    }

    public function test_it_normalizes_continuous_tier_min_to_previous_max(): void
    {
        // 구 규칙(max + 1)으로 저장된 무게 구간
        $settingId = $this->makeCountrySetting(ChargePolicyEnum::RANGE_WEIGHT, [
            ['min' => 0, 'max' => 2, 'fee' => 3000],
            ['min' => 3, 'max' => 5, 'fee' => 5000],
            ['min' => 6, 'max' => null, 'fee' => 8000],
        ]);

        $this->runUpgrade();

        $tiers = $this->tiersOf($settingId);
        $this->assertEquals(0, $tiers[0]['min']);
        $this->assertEquals(2, $tiers[1]['min']);
        $this->assertEquals(5, $tiers[2]['min']);
        // 종료값과 배송비는 손대지 않는다
        $this->assertEquals(2, $tiers[0]['max']);
        $this->assertEquals(5, $tiers[1]['max']);
        $this->assertNull($tiers[2]['max']);
        $this->assertEquals(8000, $tiers[2]['fee']);
    }

    public function test_it_keeps_max_plus_one_for_quantity_tiers(): void
    {
        // 수량은 이산형이라 max + 1 이 정상 — 그 형태는 그대로 유지된다
        $settingId = $this->makeCountrySetting(ChargePolicyEnum::RANGE_QUANTITY, [
            ['min' => 0, 'max' => 5, 'fee' => 3000],
            ['min' => 6, 'max' => null, 'fee' => 5000],
        ]);

        $this->runUpgrade();

        $tiers = $this->tiersOf($settingId);
        $this->assertEquals(0, $tiers[0]['min']);
        $this->assertEquals(6, $tiers[1]['min']);
    }

    public function test_it_normalizes_first_quantity_tier_min_to_zero(): void
    {
        // 샘플 데이터는 "1~5개 / 6개~" 로 저장돼 있다. 시작값이 읽기전용이 된 이상
        // 이 형태가 남아 있으면 운영자가 그 정책을 다시 저장할 수 없다.
        $settingId = $this->makeCountrySetting(ChargePolicyEnum::RANGE_QUANTITY, [
            ['min' => 1, 'max' => 5, 'fee' => 3000],
            ['min' => 6, 'max' => null, 'fee' => 5000],
        ]);

        $this->runUpgrade();

        $tiers = $this->tiersOf($settingId);
        $this->assertEquals(0, $tiers[0]['min']);
        $this->assertEquals(6, $tiers[1]['min']);
        // 종료값·배송비는 불변 (계산 결과가 바뀌면 안 된다)
        $this->assertEquals(5, $tiers[0]['max']);
        $this->assertEquals(3000, $tiers[0]['fee']);
    }

    public function test_it_backfills_order_totals_from_option_subtotals(): void
    {
        $order = OrderFactory::new()->create();
        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'total_weight' => 0,
            'total_volume' => 0,
        ]);

        foreach ([[500.0, 1000.0], [250.0, 400.0]] as [$weight, $volume]) {
            $orderOption = OrderOptionFactory::new()->forOrder($order)->create(['parent_option_id' => null]);
            DB::table('ecommerce_order_options')->where('id', $orderOption->id)->update([
                'subtotal_weight' => $weight,
                'subtotal_volume' => $volume,
            ]);
        }

        $this->runUpgrade();

        $row = DB::table('ecommerce_orders')->where('id', $order->id)->first();
        $this->assertEquals(750.0, (float) $row->total_weight);
        $this->assertEquals(1400.0, (float) $row->total_volume);
    }

    public function test_it_preserves_orders_that_already_have_totals(): void
    {
        $order = OrderFactory::new()->create();
        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'total_weight' => 999.0,
            'total_volume' => 888.0,
        ]);

        $orderOption = OrderOptionFactory::new()->forOrder($order)->create(['parent_option_id' => null]);
        DB::table('ecommerce_order_options')->where('id', $orderOption->id)->update([
            'subtotal_weight' => 1.0,
            'subtotal_volume' => 2.0,
        ]);

        $this->runUpgrade();

        $row = DB::table('ecommerce_orders')->where('id', $order->id)->first();
        $this->assertEquals(999.0, (float) $row->total_weight);
        $this->assertEquals(888.0, (float) $row->total_volume);
    }

    public function test_it_is_idempotent(): void
    {
        $settingId = $this->makeCountrySetting(ChargePolicyEnum::RANGE_VOLUME, [
            ['min' => 0, 'max' => 50, 'fee' => 5000],
            ['min' => 51, 'max' => null, 'fee' => 9000],
        ]);

        $order = OrderFactory::new()->create();
        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'total_weight' => 0,
            'total_volume' => 0,
        ]);
        $orderOption = OrderOptionFactory::new()->forOrder($order)->create(['parent_option_id' => null]);
        DB::table('ecommerce_order_options')->where('id', $orderOption->id)->update([
            'subtotal_weight' => 500.0,
            'subtotal_volume' => 1000.0,
        ]);

        $this->runUpgrade();
        $firstTiers = $this->tiersOf($settingId);
        $firstOrder = DB::table('ecommerce_orders')->where('id', $order->id)->first();

        $this->runUpgrade();
        $secondTiers = $this->tiersOf($settingId);
        $secondOrder = DB::table('ecommerce_orders')->where('id', $order->id)->first();

        $this->assertSame($firstTiers, $secondTiers);
        $this->assertEquals((float) $firstOrder->total_weight, (float) $secondOrder->total_weight);
        $this->assertEquals((float) $firstOrder->total_volume, (float) $secondOrder->total_volume);
        $this->assertEquals(50, $secondTiers[1]['min']);
    }
}
