<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 목록 응답의 송장 조회 주소 테스트
 *
 * 주문 상세와 같은 규칙(택배사의 조회 주소 틀에 송장 번호를 넣음)으로 목록에도 조회 주소를 싣는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/order-list-tracking-url.yaml
 */
class OrderListTrackingUrlTest extends ModuleTestCase
{
    private const URL = '/api/modules/sirsoft-ecommerce/user/orders';

    /**
     * 송장이 있는 주문은 조회 주소가 실리고, 송장·택배사가 없는 주문은 null 이다.
     *
     * @scenario shipping=with_tracking_and_without
     *
     * @effects list_carries_tracking_url_when_tracked, list_tracking_url_null_without_tracking
     *
     * @return void 반환값 없음
     */
    public function test_order_list_carries_tracking_url(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);
        $carrier = ShippingCarrier::create([
            'code' => 'test-carrier-'.uniqid(),
            'name' => ['ko' => '시험 택배', 'en' => 'Test Carrier'],
            'type' => 'domestic',
            'tracking_url' => 'https://tracking.example.com/?no={tracking_number}',
            'is_active' => true,
        ]);

        $tracked = Order::factory()->forUser($user)->pendingPayment()->create();
        OrderShipping::factory()->create([
            'order_id' => $tracked->id,
            'carrier_id' => $carrier->id,
            'tracking_number' => '1234567890',
        ]);

        $untracked = Order::factory()->forUser($user)->pendingPayment()->create();
        OrderShipping::factory()->create(['order_id' => $untracked->id]);

        $rows = collect($this->getJson(self::URL)->assertOk()->json('data.data'))->keyBy('id');

        $this->assertSame(
            'https://tracking.example.com/?no=1234567890',
            $rows[$tracked->id]['shipping']['tracking_url']
        );
        $this->assertSame('1234567890', $rows[$tracked->id]['shipping']['tracking_number']);
        $this->assertNull($rows[$untracked->id]['shipping']['tracking_url']);
    }

    /**
     * 조회 주소를 실어도 주문 수에 따라 쿼리가 늘지 않는다.
     *
     * @scenario shipping=many_orders
     *
     * @effects list_tracking_url_adds_no_per_row_query
     *
     * @return void 반환값 없음
     */
    public function test_tracking_url_does_not_add_per_row_queries(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);
        $carrier = ShippingCarrier::create([
            'code' => 'test-carrier-'.uniqid(),
            'name' => ['ko' => '시험 택배', 'en' => 'Test Carrier'],
            'type' => 'domestic',
            'tracking_url' => 'https://tracking.example.com/?no={tracking_number}',
            'is_active' => true,
        ]);

        $count = function () use ($user, $carrier): int {
            $order = Order::factory()->forUser($user)->pendingPayment()->create();
            OrderShipping::factory()->create([
                'order_id' => $order->id,
                'carrier_id' => $carrier->id,
                'tracking_number' => (string) random_int(100000, 999999),
            ]);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URL)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // 첫 요청은 권한·확장 목록 같은 1회성 조회가 섞이므로 두 번째·네 번째 요청을 비교한다
        $count();
        $withTwo = $count();
        $count();
        $withFour = $count();

        $this->assertSame($withTwo, $withFour, '주문이 늘어도 목록 쿼리 수는 같아야 한다');
    }
}
