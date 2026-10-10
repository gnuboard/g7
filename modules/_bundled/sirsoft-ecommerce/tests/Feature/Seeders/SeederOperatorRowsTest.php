<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Seeders;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 시더 3종의 stale 정리가 운영자가 만든 미사용 항목을 지우지 않는지 고정한다 (#727 W7).
 *
 * 결함(수정 전): 클레임 사유·배송 유형·택배사 시더는 "사용 중인 코드" 만 보존하고 나머지 선언 밖 코드를 지웠다 —
 * 운영자가 설정 화면에서 만든 미사용 항목이 언어팩을 켜고 끌 때(시더 재실행) 사라졌다. 정리 대상은 시더가 만든 행
 * (`created_by IS NULL`)뿐이어야 한다.
 */
class SeederOperatorRowsTest extends ModuleTestCase
{
    /**
     * 운영자가 만든 미사용 클레임 사유는 남고, 선언에서 빠진 미사용 시드 사유는 지워진다.
     *
     * @scenario seeder=claim_reason
     *
     * @effects operator_rows_survive_reseed
     *
     * @return void 반환값 없음
     */
    public function test_claim_reason_seeder_keeps_operator_rows(): void
    {
        $operator = User::factory()->create();
        ClaimReason::create(['type' => 'refund', 'code' => 'probe_operator', 'name' => ['ko' => '운영자 사유'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 99, 'created_by' => $operator->id]);
        ClaimReason::create(['type' => 'refund', 'code' => 'probe_stale_seed', 'name' => ['ko' => '빠진 시드'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 98, 'created_by' => null]);

        Artisan::call('db:seed', ['--class' => ClaimReasonSeeder::class, '--force' => true]);

        $this->assertTrue(ClaimReason::where('code', 'probe_operator')->exists(), '운영자가 만든 사유가 삭제되었다');
        $this->assertFalse(ClaimReason::where('code', 'probe_stale_seed')->exists(), '선언에서 빠진 미사용 시드 사유는 정리되어야 한다');
    }

    /**
     * 운영자가 만든 미사용 배송 유형은 남고, 선언에서 빠진 미사용 시드 유형은 지워진다.
     *
     * @scenario seeder=shipping_type
     *
     * @effects operator_rows_survive_reseed
     *
     * @return void 반환값 없음
     */
    public function test_shipping_type_seeder_keeps_operator_rows(): void
    {
        $operator = User::factory()->create();
        ShippingType::create(['code' => 'probe_operator', 'name' => ['ko' => '운영자 유형'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 99, 'created_by' => $operator->id]);
        ShippingType::create(['code' => 'probe_stale_seed', 'name' => ['ko' => '빠진 시드'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 98, 'created_by' => null]);

        Artisan::call('db:seed', ['--class' => ShippingTypeSeeder::class, '--force' => true]);

        $this->assertTrue(ShippingType::where('code', 'probe_operator')->exists(), '운영자가 만든 배송 유형이 삭제되었다');
        $this->assertFalse(ShippingType::where('code', 'probe_stale_seed')->exists());
    }

    /**
     * 운영자가 만든 미사용 택배사는 남고, 선언에서 빠진 미사용 시드 택배사는 지워진다.
     *
     * @scenario seeder=shipping_carrier
     *
     * @effects operator_rows_survive_reseed
     *
     * @return void 반환값 없음
     */
    public function test_shipping_carrier_seeder_keeps_operator_rows(): void
    {
        $operator = User::factory()->create();
        ShippingCarrier::create(['code' => 'probe_operator', 'name' => ['ko' => '운영자 택배'], 'type' => 'domestic', 'is_active' => true, 'sort_order' => 99, 'created_by' => $operator->id]);
        ShippingCarrier::create(['code' => 'probe_stale_seed', 'name' => ['ko' => '빠진 시드'], 'type' => 'domestic', 'is_active' => true, 'sort_order' => 98, 'created_by' => null]);

        Artisan::call('db:seed', ['--class' => ShippingCarrierSeeder::class, '--force' => true]);

        $this->assertTrue(ShippingCarrier::where('code', 'probe_operator')->exists(), '운영자가 만든 택배사가 삭제되었다');
        $this->assertFalse(ShippingCarrier::where('code', 'probe_stale_seed')->exists());
    }

    /**
     * 로그인한 요청 안에서 시더가 돌아도 시더가 새로 만든 행의 created_by 는 null 이다 (§9 R9).
     *
     * @scenario seeder=claim_reason
     *
     * @effects seeded_rows_have_null_created_by
     *
     * @return void 반환값 없음
     */
    public function test_seeded_rows_have_null_created_by_even_when_authenticated(): void
    {
        ShippingType::query()->delete();
        $this->actingAs(User::factory()->create());

        Artisan::call('db:seed', ['--class' => ShippingTypeSeeder::class, '--force' => true]);

        $this->assertGreaterThan(0, ShippingType::count());
        $this->assertSame(0, ShippingType::whereNotNull('created_by')->count(), '시드 행에 로그인 사용자가 created_by 로 기록되었다');
    }
}
