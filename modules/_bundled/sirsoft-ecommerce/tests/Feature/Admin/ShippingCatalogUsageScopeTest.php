<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingCarrierOperationException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
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
 *     교환 상품 행은 `collection_carrier_id`·`redelivery_carrier_id` 로, 반품 상품 행은
 *     `collection_carrier_id` 로 각자 자기 표에 배송사를 붙잡는다(송장은 상품 행마다 — #599). 그래서 회수 전용으로만
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
     * 배송사를 붙잡는 컬럼 전수를 **실제 스키마**에서 파생합니다. (이커머스 테이블의 `*carrier_id`)
     *
     * 마이그레이션 원문을 읽으면 `Schema::create` 만 보이고 나중에 `Schema::table` 로 붙은 컬럼(상품 행
     * 송장 — #599)은 빠진다. 적용된 스키마를 읽어야 「새 컬럼이 생겼는데 사용량 계수 밖」 을 잡는다.
     *
     * @return array<int, string> `테이블.컬럼` 목록 (접두어 제외, 정렬)
     */
    private function carrierReferencingColumns(): array
    {
        $prefix = DB::getTablePrefix();
        $found = [];

        foreach (Schema::getTables() as $table) {
            $name = (string) ($table['name'] ?? '');

            if ($prefix !== '' && str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
            }

            if (! str_starts_with($name, 'ecommerce_') || $name === 'ecommerce_shipping_carriers') {
                continue;
            }

            foreach (Schema::getColumnListing($name) as $column) {
                if (str_ends_with($column, 'carrier_id')) {
                    $found[] = $name.'.'.$column;
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * 스키마에서 파생한 배송사 참조 컬럼이 모두 사용량 계수 또는 휴면 선언에 들어 있는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario catalog=shipping_carrier, delete_path=settings_service
     *
     * @effects every_carrier_referencing_column_is_counted_or_declared_dormant
     */
    #[Test]
    public function every_column_that_holds_a_carrier_is_either_counted_or_declared_dormant(): void
    {
        // 이 검사가 손열거였다면 **네 번째 컬럼이 생겨도 케이스 없이 조용히 통과**한다 —
        // 19차가 고친 결함이 정확히 그 형태였다(가드는 있는데 세는 컬럼 집합이 참조 컬럼
        // 전수와 달랐다). 그래서 모집단을 마이그레이션에서 파생하고, 각 컬럼이 ⓐ 사용량에
        // 계수되거나 ⓑ 휴면(쓰기 0)으로 **명시 선언**되었는지만 본다.
        //
        // `ShippingCarrierService::countUsage()` 가 세는 컬럼
        // 송장은 신청서 헤더가 아니라 **상품 행**에 있다(#599 — 다중 송장).
        $counted = [
            'ecommerce_order_shippings.carrier_id',
            'ecommerce_order_exchange_options.collection_carrier_id',
            'ecommerce_order_exchange_options.redelivery_carrier_id',
            'ecommerce_order_return_options.collection_carrier_id',
        ];

        // 휴면 컬럼 — 쓰기가 0이라 계수 대상이 아닌 컬럼. 배송 행의 반품·교환 송장 칸(S12)은 삭제했다.
        $dormant = [];

        $derived = $this->carrierReferencingColumns();

        $this->assertGreaterThanOrEqual(
            4,
            count($derived),
            '스키마에서 배송사 참조 컬럼을 파생하지 못했다 — 이 검사가 아무것도 재지 않고 있다'
        );

        $this->assertSame(
            [],
            array_values(array_diff($derived, $counted, $dormant)),
            '배송사를 붙잡는 컬럼이 새로 생겼는데 사용량 계수에도, 휴면 선언에도 없다 — '
            .'그 컬럼만 참조하는 배송사는 삭제가 200 으로 통과하고 참조가 조용히 끊긴다'
        );
    }

    // ===== ① 판정 모집단: 회수 택배사도 "쓰이는 중" 이다 =====

    /**
     * 교환 회수 택배사로만 쓰인 배송사도 사용 중으로 집계되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario catalog=shipping_carrier, delete_path=settings_service
     *
     * @effects exchange_collection_carrier_counts_as_in_use
     */
    #[Test]
    public function a_carrier_used_only_as_an_exchange_collection_carrier_counts_as_in_use(): void
    {
        $order = $this->deliveredClaimOrder();
        $carrier = $this->operatorCarrier('s19_ex_collect');

        $this->exchangeRowWith($order, collection: $carrier->id);

        $this->assertGreaterThan(
            0,
            app(ShippingCarrierService::class)->countUsage($carrier->id),
            '교환 회수 택배사로만 쓰인 배송사가 "안 쓰임" 으로 판정되면, 삭제가 200 으로 통과하고 '
            .'nullOnDelete 가 그 신청서의 회수 택배사를 조용히 비운다'
        );
    }

    /**
     * 교환 재발송 택배사로만 쓰인 배송사도 사용 중으로 집계되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario catalog=shipping_carrier, delete_path=settings_service
     *
     * @effects exchange_redelivery_carrier_counts_as_in_use
     */
    #[Test]
    public function a_carrier_used_only_as_an_exchange_redelivery_carrier_counts_as_in_use(): void
    {
        $order = $this->deliveredClaimOrder();
        $carrier = $this->operatorCarrier('s19_ex_redeliver');

        $this->exchangeRowWith($order, redelivery: $carrier->id);

        $this->assertGreaterThan(
            0,
            app(ShippingCarrierService::class)->countUsage($carrier->id),
            '재발송 택배사는 배송행으로 미러링되는 경로가 있어 우연히 세어질 뿐이다 — 그 미러링이 '
            .'없는 상태(승인 전 지정 등)에서도 판정이 서야 한다'
        );
    }

    /**
     * 반품 회수 택배사로만 쓰인 배송사도 사용 중으로 집계되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario catalog=shipping_carrier, delete_path=settings_service
     *
     * @effects return_collection_carrier_counts_as_in_use
     */
    #[Test]
    public function a_carrier_used_only_as_a_return_collection_carrier_counts_as_in_use(): void
    {
        $order = $this->deliveredClaimOrder();
        $carrier = $this->operatorCarrier('s19_rt_collect');

        $this->returnRowWith($order, $carrier->id);

        $this->assertGreaterThan(
            0,
            app(ShippingCarrierService::class)->countUsage($carrier->id),
            '반품 회수 택배사로만 쓰인 배송사도 삭제되면 그 신청서가 택배사를 잃는다'
        );
    }

    /**
     * 어디에도 쓰이지 않는 배송사는 사용량 0 으로 판정되어 여전히 지울 수 있는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario catalog=shipping_carrier, delete_path=settings_service
     *
     * @effects unused_carrier_counts_as_not_in_use
     */
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

    /**
     * 클레임 상품 행만 참조하는 배송사를 지우려 하면 도메인 예외로 거절되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario catalog=shipping_carrier, delete_path=settings_service
     *
     * @effects claim_only_carrier_deletion_is_rejected
     */
    #[Test]
    public function deleting_a_carrier_that_only_a_claim_references_is_rejected(): void
    {
        $order = $this->deliveredClaimOrder();
        $carrier = $this->operatorCarrier('s19_delete_guard');

        $this->returnRowWith($order, $carrier->id);

        $this->expectException(ShippingCarrierOperationException::class);

        app(ShippingCarrierService::class)->deleteCarrier($carrier->id);
    }

    // ===== ② 시더의 stale 정리가 설정 경로와 같은 강도인가 =====

    /**
     * 배송사 시더의 stale 정리가 클레임이 참조하는 배송사는 남기고 미사용 잔여만 지우는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario catalog=shipping_carrier, delete_path=seeder_stale_cleanup
     *
     * @effects carrier_seeder_cleanup_keeps_claim_referenced_carriers
     */
    #[Test]
    public function the_carrier_seeder_stale_cleanup_keeps_carriers_that_claims_still_reference(): void
    {
        $order = $this->deliveredClaimOrder();

        $inUse = $this->operatorCarrier('s19_seed_in_use');
        $unused = $this->operatorCarrier('s19_seed_unused');

        $this->returnRowWith($order, $inUse->id);

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

    /**
     * 배송유형 시더의 stale 정리가 주문 배송행이 참조하는 유형은 남기고 미사용 잔여만 지우는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario catalog=shipping_type, delete_path=seeder_stale_cleanup
     *
     * @effects shipping_type_seeder_cleanup_keeps_order_referenced_types
     */
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

    /**
     * 회수·재발송 송장이 붙은 교환 상품 행을 만듭니다. (송장은 상품 행에 있다 — #599)
     *
     * @param  Order  $order  주문
     * @param  int|null  $collection  회수 택배사 ID
     * @param  int|null  $redelivery  재발송 택배사 ID
     * @return void 반환값 없음
     */
    private function exchangeRowWith(Order $order, ?int $collection = null, ?int $redelivery = null): void
    {
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id]);

        OrderExchangeOption::factory()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $order->options->first()->id,
            'collection_carrier_id' => $collection,
            'collection_tracking_number' => $collection !== null ? 'C-'.$collection : null,
            'redelivery_carrier_id' => $redelivery,
            'redelivery_tracking_number' => $redelivery !== null ? 'R-'.$redelivery : null,
        ]);
    }

    /**
     * 회수 송장이 붙은 반품 상품 행을 만듭니다. (송장은 상품 행에 있다 — #599)
     *
     * @param  Order  $order  주문
     * @param  int  $carrierId  회수 택배사 ID
     * @return void 반환값 없음
     */
    private function returnRowWith(Order $order, int $carrierId): void
    {
        $return = OrderReturn::factory()->create(['order_id' => $order->id]);

        OrderReturnOption::factory()->create([
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $order->options->first()->id,
            'collection_carrier_id' => $carrierId,
            'collection_tracking_number' => 'C-'.$carrierId,
        ]);
    }
}
