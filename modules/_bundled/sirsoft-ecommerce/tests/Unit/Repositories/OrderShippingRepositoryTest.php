<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Repositories\OrderShippingRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 배송 Repository 테스트 (송장 input 버그 — 백엔드)
 *
 * updateTrackingByOrderOptionId 는 옵션 단위 배송 레코드에 택배사/송장번호를 기록한다.
 * 배송 레코드는 주문 생성 시점에 만들어지므로, 없으면 정책 파생 컬럼(shipping_status/
 * shipping_type NOT NULL)을 채울 수 없어 생성하지 않고 갱신을 건너뛴다(상태 전이는 막지 않음).
 */
class OrderShippingRepositoryTest extends ModuleTestCase
{
    protected OrderShippingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OrderShippingRepository(new OrderShipping);
    }

    /**
     * 기존 배송 레코드가 있으면 carrier_id/tracking_number 를 갱신한다.
     */
    public function test_update_tracking_updates_existing_shipping_row(): void
    {
        $option = OrderOptionFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrderOption($option)->create([
            'carrier_id' => null,
            'tracking_number' => null,
        ]);

        $result = $this->repository->updateTrackingByOrderOptionId($option->id, [
            'carrier_id' => 1,
            'tracking_number' => 'SAVED-TRACK-108',
        ]);

        $this->assertNotNull($result);
        $this->assertEquals($shipping->id, $result->id);

        $fresh = $shipping->fresh();
        $this->assertEquals(1, $fresh->carrier_id);
        $this->assertEquals('SAVED-TRACK-108', $fresh->tracking_number);
    }

    /**
     * 배송 레코드가 없으면 생성하지 않고 null 을 반환한다 (NOT NULL 위반 회피).
     */
    public function test_update_tracking_returns_null_when_no_shipping_row(): void
    {
        // shipping 레코드 없는 옵션
        $option = OrderOptionFactory::new()->create();

        $result = $this->repository->updateTrackingByOrderOptionId($option->id, [
            'carrier_id' => 1,
            'tracking_number' => 'NO-ROW-TRACK',
        ]);

        $this->assertNull($result);
        // 새 레코드를 만들지 않았는지 확인
        $this->assertSame(0, OrderShipping::where('order_option_id', $option->id)->count());
    }

    /**
     * carrier_id/tracking_number 가 모두 비면 갱신하지 않고 null 을 반환한다.
     */
    public function test_update_tracking_returns_null_when_payload_empty(): void
    {
        $option = OrderOptionFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrderOption($option)->create([
            'carrier_id' => 7,
            'tracking_number' => 'ORIGINAL-TRACK',
        ]);

        $result = $this->repository->updateTrackingByOrderOptionId($option->id, [
            'carrier_id' => null,
            'tracking_number' => '',
        ]);

        $this->assertNull($result);

        // 기존 값이 보존되어야 한다 (빈 payload 가 덮어쓰지 않음)
        $fresh = $shipping->fresh();
        $this->assertEquals(7, $fresh->carrier_id);
        $this->assertEquals('ORIGINAL-TRACK', $fresh->tracking_number);
    }

    /**
     * 빈 값 키는 무시하고 채워진 키만 부분 갱신한다.
     */
    public function test_update_tracking_partial_only_filled_keys(): void
    {
        $option = OrderOptionFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrderOption($option)->create([
            'carrier_id' => 7,
            'tracking_number' => 'ORIGINAL-TRACK',
        ]);

        // tracking_number 만 전달 (carrier_id 는 빈 값 → 보존)
        $result = $this->repository->updateTrackingByOrderOptionId($option->id, [
            'carrier_id' => '',
            'tracking_number' => 'NEW-TRACK-ONLY',
        ]);

        $this->assertNotNull($result);
        $fresh = $shipping->fresh();
        $this->assertEquals(7, $fresh->carrier_id, '빈 carrier_id 는 기존 값 보존');
        $this->assertEquals('NEW-TRACK-ONLY', $fresh->tracking_number);
    }
}
