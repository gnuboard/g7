<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingCarrierOperationException;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Services\ShippingCarrierService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 배송사·배송유형이 "쓰이고 있는가" 를 판정하는 모집단 (S10 19차)
 *
 * 이 판정에는 구멍이 두 종류 있었다.
 *
 *  1. **모집단이 한 컬럼**이었다. 배송사 사용량을 `order_shippings.carrier_id` 로만 셌는데,
 *     교환 신청서는 `collection_carrier_id`·`redelivery_carrier_id` 로, 반품 신청서는
 *     `collection_carrier_id` 로 각자 자기 표에 배송사를 붙잡는다. 그래서 회수 전용으로만
 *     쓰인 배송사가 "안 쓰임" 으로 판정돼 삭제가 **200 으로 통과**했고, 그 세 외래키는 전부
 *     `nullOnDelete` 라 접수된 신청서의 택배사가 오류도 로그도 없이 비워졌다.
 *  2. **같은 표에 강도가 다른 삭제 경로가 둘**이었다. 설정 화면은 사용 중이면 400 으로 막는데,
 *     모듈 재설치 시 도는 시더의 stale 정리는 그 판정을 아예 거치지 않았다.
 *
 * 둘 다 예외도 경고도 로그도 남기지 않는다 — 지워진 뒤의 상태가 유일한 증상이다.
 *
 * 배송유형은 사용처 표가 `order_shippings` 하나뿐이라 ①은 해당하지 않고 ②만 본다.
 */
class ShippingCatalogUsageScopeTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 운영자가 추가한 배송사를 만듭니다 (시드 정의 밖의 코드).
     *
     * @param  string  $code  배송사 코드
     * @return ShippingCarrier 생성된 배송사
     */
    private function operatorCarrier(string $code): ShippingCarrier
    {
        return ShippingCarrier::create([
            'code' => $code,
            'name' => ['ko' => '운영자 등록 택배사', 'en' => 'Operator carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * 마이그레이션 전수에서 배송사를 붙잡는 컬럼을 파생합니다.
     *
     * @return array<int, string> `테이블.컬럼` 형태
     */
    private function carrierReferencingColumns(): array
    {
        $found = [];
        $dir = dirname(__DIR__, 3).'/database/migrations';

        foreach ((array) glob($dir.'/*.php') as $path) {
            $source = (string) file_get_contents($path);

            if (! preg_match("/Schema::create\('([a-z_]+)'/", $source, $table)) {
                continue;
            }

            preg_match_all(
                "/->unsignedBigInteger\('([a-z_]*carrier_id)'\)/",
                $source,
                $columns
            );

            foreach ($columns[1] as $column) {
                $found[] = $table[1].'.'.$column;
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    #[Test]
    public function every_column_that_holds_a_carrier_is_either_counted_or_declared_dormant(): void
    {
        // 이 검사가 손열거였다면 **네 번째 컬럼이 생겨도 케이스 없이 조용히 통과**한다 —
        // 19차가 고친 결함이 정확히 그 형태였다(가드는 있는데 세는 컬럼 집합이 참조 컬럼
        // 전수와 달랐다). 그래서 모집단을 마이그레이션에서 파생하고, 각 컬럼이 ⓐ 사용량에
        // 계수되거나 ⓑ 휴면(쓰기 0)으로 **명시 선언**되었는지만 본다.
        //
        // `ShippingCarrierService::countUsage()` 가 세는 컬럼
        $counted = [
            'ecommerce_order_shippings.carrier_id',
            'ecommerce_order_exchanges.collection_carrier_id',
            'ecommerce_order_exchanges.redelivery_carrier_id',
            'ecommerce_order_returns.collection_carrier_id',
        ];

        // 휴면 컬럼 — `$fillable` 선언만 있고 쓰기가 0이라 계수 대상이 아니다. 여기에 쓰기가
        // 생기면 `countUsage()` 에 함께 더하고 이 목록에서 뺀다.
        $dormant = [
            'ecommerce_order_shippings.return_carrier_id',
            'ecommerce_order_shippings.exchange_carrier_id',
        ];

        $derived = $this->carrierReferencingColumns();

        $this->assertGreaterThanOrEqual(
            6,
            count($derived),
            '마이그레이션에서 배송사 참조 컬럼을 파생하지 못했다 — 이 검사가 아무것도 재지 않고 있다'
        );

        $this->assertSame(
            [],
            array_values(array_diff($derived, $counted, $dormant)),
            '배송사를 붙잡는 컬럼이 새로 생겼는데 사용량 계수에도, 휴면 선언에도 없다 — '
            .'그 컬럼만 참조하는 배송사는 삭제가 200 으로 통과하고 참조가 조용히 끊긴다'
        );
    }

    // ===== ① 판정 모집단: 회수 택배사도 "쓰이는 중" 이다 =====

    #[Test]
    public function a_carrier_used_only_as_an_exchange_collection_carrier_counts_as_in_use(): void
    {
        $order = $this->deliveredClaimOrder();
        $carrier = $this->operatorCarrier('s19_ex_collect');

        OrderExchange::factory()->create([
            'order_id' => $order->id,
            'collection_carrier_id' => $carrier->id,
        ]);

        $this->assertGreaterThan(
            0,
            app(ShippingCarrierService::class)->countUsage($carrier->id),
            '교환 회수 택배사로만 쓰인 배송사가 "안 쓰임" 으로 판정되면, 삭제가 200 으로 통과하고 '
            .'nullOnDelete 가 그 신청서의 회수 택배사를 조용히 비운다'
        );
    }

    #[Test]
    public function a_carrier_used_only_as_an_exchange_redelivery_carrier_counts_as_in_use(): void
    {
        $order = $this->deliveredClaimOrder();
        $carrier = $this->operatorCarrier('s19_ex_redeliver');

        OrderExchange::factory()->create([
            'order_id' => $order->id,
            'redelivery_carrier_id' => $carrier->id,
        ]);

        $this->assertGreaterThan(
            0,
            app(ShippingCarrierService::class)->countUsage($carrier->id),
            '재발송 택배사는 배송행으로 미러링되는 경로가 있어 우연히 세어질 뿐이다 — 그 미러링이 '
            .'없는 상태(승인 전 지정 등)에서도 판정이 서야 한다'
        );
    }

    #[Test]
    public function a_carrier_used_only_as_a_return_collection_carrier_counts_as_in_use(): void
    {
        $order = $this->deliveredClaimOrder();
        $carrier = $this->operatorCarrier('s19_rt_collect');

        OrderReturn::factory()->create([
            'order_id' => $order->id,
            'collection_carrier_id' => $carrier->id,
        ]);

        $this->assertGreaterThan(
            0,
            app(ShippingCarrierService::class)->countUsage($carrier->id),
            '반품 회수 택배사로만 쓰인 배송사도 삭제되면 그 신청서가 택배사를 잃는다'
        );
    }

    #[Test]
    public function an_unused_carrier_is_still_deletable(): void
    {
        $carrier = $this->operatorCarrier('s19_unused');

        $this->assertSame(
            0,
            app(ShippingCarrierService::class)->countUsage($carrier->id),
            '판정을 넓히다 아무 배송사도 못 지우게 만들면 그건 기능 축소다'
        );
    }

    #[Test]
    public function deleting_a_carrier_that_only_a_claim_references_is_rejected(): void
    {
        $order = $this->deliveredClaimOrder();
        $carrier = $this->operatorCarrier('s19_delete_guard');

        OrderReturn::factory()->create([
            'order_id' => $order->id,
            'collection_carrier_id' => $carrier->id,
        ]);

        $this->expectException(ShippingCarrierOperationException::class);

        app(ShippingCarrierService::class)->deleteCarrier($carrier->id);
    }

    // ===== ② 시더의 stale 정리가 설정 경로와 같은 강도인가 =====

    #[Test]
    public function the_carrier_seeder_stale_cleanup_keeps_carriers_that_claims_still_reference(): void
    {
        $order = $this->deliveredClaimOrder();

        $inUse = $this->operatorCarrier('s19_seed_in_use');
        $unused = $this->operatorCarrier('s19_seed_unused');

        OrderReturn::factory()->create([
            'order_id' => $order->id,
            'collection_carrier_id' => $inUse->id,
        ]);

        $this->seed(ShippingCarrierSeeder::class);

        $this->assertNotNull(
            ShippingCarrier::where('code', $inUse->code)->first(),
            '접수된 신청서가 참조하는 배송사를 시더가 지웠다 — 그 신청서는 택배사를 잃는다'
        );

        $this->assertNull(
            ShippingCarrier::where('code', $unused->code)->first(),
            '쓰이지 않는 잔여까지 보존하면 stale 정리가 무의미해진다'
        );
    }

    #[Test]
    public function the_shipping_type_seeder_stale_cleanup_keeps_types_that_orders_still_reference(): void
    {
        $order = $this->deliveredClaimOrder();

        $inUse = ShippingType::create([
            'code' => 's19_type_in_use',
            'name' => ['ko' => '운영자 등록 배송유형', 'en' => 'Operator type'],
            'category' => 'domestic',
            'is_active' => true,
            'sort_order' => 50,
        ]);

        $unused = ShippingType::create([
            'code' => 's19_type_unused',
            'name' => ['ko' => '운영자 등록 미사용 유형', 'en' => 'Operator type unused'],
            'category' => 'domestic',
            'is_active' => true,
            'sort_order' => 51,
        ]);

        // 주문 배송행은 배송유형을 코드 문자열로만 기억한다 (FK 없음)
        $order->shippings()->first()->update(['shipping_type' => $inUse->code]);

        $this->seed(ShippingTypeSeeder::class);

        $this->assertNotNull(
            ShippingType::where('code', $inUse->code)->first(),
            '주문 배송행이 참조하는 배송유형을 시더가 지웠다 — 목록의 배송방법 라벨이 빈칸이 된다'
        );

        $this->assertNull(
            ShippingType::where('code', $unused->code)->first(),
            '쓰이지 않는 잔여까지 보존하면 stale 정리가 무의미해진다'
        );
    }
}
