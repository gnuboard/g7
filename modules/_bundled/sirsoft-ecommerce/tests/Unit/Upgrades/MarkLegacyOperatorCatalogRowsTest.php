<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 1.2.1 운영자 카탈로그 행 생성자 표식(DataMigration) 회귀 테스트 (7.1.0 출시 점검 EU-2)
 *
 * 1.2.1 관리자 화면은 created_by 를 채우지 않아, 1.3.0 시더의 정리 범위(`created_by IS NULL`)가 그 시절 운영자가 만든
 * 미사용 택배사·배송유형·클레임 사유까지 시드 잔재로 보고 지웠다. 스텝은 역대 시더 코드 밖의 NULL 행에 최고관리자를 채운다.
 *
 * 검증 축: ① 시더 코드 밖 NULL 행만 표식 ② 시더 코드 행·이미 생성자 있는 행 불변 ③ 최고관리자 없으면 무변경 ④ 멱등
 *
 * 시나리오 매니페스트: `tests/scenarios/legacy-operator-catalog-rows.yaml`
 */
class MarkLegacyOperatorCatalogRowsTest extends ModuleTestCase
{
    private object $migration;

    private UpgradeContext $context;

    /**
     * 세 카탈로그 표를 비우고 업그레이드 데이터 마이그레이션을 불러옵니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ecommerce_shipping_carriers', 'ecommerce_shipping_types', 'ecommerce_claim_reasons'] as $table) {
            DB::table($table)->delete();
        }

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.0/migrations/14_MarkLegacyOperatorCatalogRows.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\MarkLegacyOperatorCatalogRows';

        $this->migration = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '1.2.1',
            toVersion: '1.3.0',
            currentStep: '1.3.0',
        );
    }

    /**
     * 택배사 행을 넣습니다.
     *
     * @param  string  $code  코드
     * @param  int|null  $createdBy  생성자
     * @return int 행 ID
     */
    private function carrier(string $code, ?int $createdBy = null): int
    {
        return DB::table('ecommerce_shipping_carriers')->insertGetId([
            'code' => $code, 'name' => json_encode(['ko' => $code]), 'type' => 'domestic',
            'is_active' => true, 'sort_order' => 0, 'created_by' => $createdBy,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * 배송유형 행을 넣습니다.
     *
     * @param  string  $code  코드
     * @return int 행 ID
     */
    private function shippingType(string $code): int
    {
        return DB::table('ecommerce_shipping_types')->insertGetId([
            'code' => $code, 'name' => json_encode(['ko' => $code]), 'category' => 'domestic',
            'is_active' => true, 'sort_order' => 0, 'created_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * 클레임 사유 행을 넣습니다.
     *
     * @param  string  $code  코드
     * @return int 행 ID
     */
    private function reason(string $code): int
    {
        return DB::table('ecommerce_claim_reasons')->insertGetId([
            'type' => 'refund', 'code' => $code, 'name' => json_encode(['ko' => $code]), 'fault_type' => 'customer',
            'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 0, 'created_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * 행의 생성자를 읽습니다.
     *
     * @param  string  $table  테이블
     * @param  int  $id  행 ID
     * @return int|null 생성자
     */
    private function createdBy(string $table, int $id): ?int
    {
        $value = DB::table($table)->where('id', $id)->value('created_by');

        return $value === null ? null : (int) $value;
    }

    /**
     * 시더 코드 밖의 생성자 없는 행만 최고관리자로 표식하고, 다시 돌려도 같다.
     *
     * @effects legacy_operator_catalog_rows_marked
     *
     * @return void 반환값 없음
     */
    public function test_marks_only_non_seed_rows_without_creator_and_is_idempotent(): void
    {
        DB::table('users')->update(['is_super' => false]);
        $other = User::factory()->create(['is_super' => false]);
        $super = User::factory()->create(['is_super' => true]);

        $seedCarrier = $this->carrier('cj');
        $operatorCarrier = $this->carrier('my_local_courier');
        $ownedCarrier = $this->carrier('another_courier', $other->id);
        $operatorType = $this->shippingType('same_day_bike');
        $seedType = $this->shippingType('parcel');
        $operatorReason = $this->reason('vip_request');
        $seedReason = $this->reason('changed_mind');

        $this->migration->run($this->context);

        $this->assertNull($this->createdBy('ecommerce_shipping_carriers', $seedCarrier));
        $this->assertSame($super->id, $this->createdBy('ecommerce_shipping_carriers', $operatorCarrier));
        $this->assertSame($other->id, $this->createdBy('ecommerce_shipping_carriers', $ownedCarrier));
        $this->assertSame($super->id, $this->createdBy('ecommerce_shipping_types', $operatorType));
        $this->assertNull($this->createdBy('ecommerce_shipping_types', $seedType));
        $this->assertSame($super->id, $this->createdBy('ecommerce_claim_reasons', $operatorReason));
        $this->assertNull($this->createdBy('ecommerce_claim_reasons', $seedReason));

        $this->migration->run($this->context);

        $this->assertNull($this->createdBy('ecommerce_shipping_carriers', $seedCarrier));
        $this->assertSame($super->id, $this->createdBy('ecommerce_shipping_carriers', $operatorCarrier));
    }

    /**
     * 표식한 1.2.1 운영자 배송유형은 업데이트 뒤 재시드의 미사용 정리에서 살아남는다.
     *
     * 7.1.0 출시 리허설 실측: 수정 전에는 생성자 없는 운영자 배송유형이 업데이트 뒤 지워졌다(배송유형 시더는 사용 중이 아닌
     * 생성자 없는 행을 시드 잔재로 정리한다). 대조군으로 스텝 뒤에 넣은 생성자 없는 행은 같은 정리에서 지워진다.
     *
     * @effects legacy_operator_catalog_rows_marked
     *
     * @return void 반환값 없음
     */
    public function test_marked_operator_shipping_type_survives_reseed_cleanup(): void
    {
        DB::table('users')->update(['is_super' => false]);
        User::factory()->create(['is_super' => true]);

        $this->shippingType('same_day_bike');

        $this->migration->run($this->context);

        $this->shippingType('unmarked_after_upgrade');

        $this->seed(ShippingTypeSeeder::class);

        $this->assertTrue(DB::table('ecommerce_shipping_types')->where('code', 'same_day_bike')->exists(), '표식한 운영자 배송유형이 재시드 정리에서 지워졌습니다');
        $this->assertFalse(DB::table('ecommerce_shipping_types')->where('code', 'unmarked_after_upgrade')->exists(), '대조군: 생성자 없는 미사용 행은 정리되어야 합니다');
    }

    /**
     * 최고관리자가 없으면 아무 행도 바꾸지 않는다.
     *
     * @effects legacy_operator_catalog_rows_marked
     *
     * @return void 반환값 없음
     */
    public function test_leaves_rows_untouched_without_super_admin(): void
    {
        DB::table('users')->update(['is_super' => false]);
        $operatorCarrier = $this->carrier('my_local_courier');

        $this->migration->run($this->context);

        $this->assertNull($this->createdBy('ecommerce_shipping_carriers', $operatorCarrier));
    }
}
