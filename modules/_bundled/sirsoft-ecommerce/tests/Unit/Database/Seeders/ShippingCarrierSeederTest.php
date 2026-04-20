<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders;

use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ShippingCarrierSeeder 테스트
 *
 * GenericEntitySyncHelper 기반 upsert + stale cleanup 패턴 검증.
 * 핵심 불변: 사용자가 수정한 필드는 user_overrides 에 기록되어 재시드 시 보존.
 */
class ShippingCarrierSeederTest extends ModuleTestCase
{
    public function test_seeder_creates_carriers_on_fresh_install(): void
    {
        $this->seed(ShippingCarrierSeeder::class);

        $this->assertGreaterThan(0, ShippingCarrier::count());
        $this->assertTrue(ShippingCarrier::where('code', 'cj')->exists(), 'CJ대한통운이 생성되어야 함');
        $this->assertTrue(ShippingCarrier::where('code', 'dhl')->exists(), 'DHL이 생성되어야 함');
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ShippingCarrierSeeder::class);
        $firstCount = ShippingCarrier::count();

        $this->seed(ShippingCarrierSeeder::class);
        $secondCount = ShippingCarrier::count();

        $this->assertEquals($firstCount, $secondCount, '재실행해도 중복 생성되지 않아야 함');
    }

    /**
     * 핵심 회귀 테스트: 사용자가 UI에서 수정한 필드는 재시드 시 보존되어야 함
     * (이전 delete+insert 패턴으로 수정 사항이 손실되던 버그 회귀 방지)
     */
    public function test_seeder_preserves_user_modified_fields(): void
    {
        $this->seed(ShippingCarrierSeeder::class);

        // 사용자가 관리자 UI에서 CJ대한통운의 이름과 정렬 순서 수정 시뮬레이션
        $cj = ShippingCarrier::where('code', 'cj')->first();
        $cj->update([
            'name' => ['ko' => 'CJ대한통운 (직접 수정)', 'en' => 'CJ Custom'],
            'sort_order' => 99,
        ]);

        // 재시드 (install --force 시나리오)
        $this->seed(ShippingCarrierSeeder::class);

        $cj->refresh();
        $this->assertEquals(
            ['ko' => 'CJ대한통운 (직접 수정)', 'en' => 'CJ Custom'],
            $cj->name,
            '사용자가 수정한 name 이 재시드 후에도 보존되어야 함'
        );
        $this->assertEquals(99, $cj->sort_order, '사용자가 수정한 sort_order 가 보존되어야 함');
    }

    /**
     * user_overrides 에 없는 필드는 시더 값으로 업데이트되어야 함
     * (기본 정의 변경이 정상 전파되는지 확인)
     *
     * 주의: Eloquent update() 는 HasUserOverrides 의 auto-tracking 으로 user_overrides 가
     * 자동 기록되므로, 이 테스트는 user_overrides 가 비어있는 상태를 재현하기 위해
     * 원시 DB 쿼리로 `tracking_url` 만 변경한다.
     */
    public function test_seeder_updates_non_overridden_fields(): void
    {
        $this->seed(ShippingCarrierSeeder::class);

        // user_overrides 기록 없이 tracking_url 만 변경 (raw DB 쿼리)
        \Illuminate\Support\Facades\DB::table('ecommerce_shipping_carriers')
            ->where('code', 'dhl')
            ->update(['tracking_url' => 'http://old-url.example.com/{tracking_number}']);

        $this->seed(ShippingCarrierSeeder::class);

        $dhl = ShippingCarrier::where('code', 'dhl')->first();
        $this->assertStringContainsString(
            'dhl.com',
            $dhl->tracking_url,
            'user_overrides 에 없는 필드는 시더 값으로 복원되어야 함'
        );
    }

    public function test_seeder_deletes_stale_carriers(): void
    {
        $this->seed(ShippingCarrierSeeder::class);

        // 시더에 없는 code 를 수동 삽입 (예: 이전 버전의 잔해)
        ShippingCarrier::create([
            'code' => 'legacy-carrier',
            'name' => ['ko' => '구버전 배송사', 'en' => 'Legacy'],
            'type' => 'domestic',
            'tracking_url' => null,
            'is_active' => true,
            'sort_order' => 999,
        ]);
        $this->assertTrue(ShippingCarrier::where('code', 'legacy-carrier')->exists());

        $this->seed(ShippingCarrierSeeder::class);

        $this->assertFalse(
            ShippingCarrier::where('code', 'legacy-carrier')->exists(),
            '시더 정의에 없는 stale 배송사는 삭제되어야 함'
        );
    }
}
