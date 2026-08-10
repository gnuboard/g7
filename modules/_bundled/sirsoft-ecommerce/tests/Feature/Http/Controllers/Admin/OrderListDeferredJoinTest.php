<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 목록 지연 조인 회귀 테스트
 *
 * 주문 목록은 이제 "이번 페이지의 ID 를 먼저 구하고, 그 ID 에 대해서만 목록 컬럼과 관계를
 * 읽는" 2단계 조회다. 성능 수치는 머신에 좌우되므로 단언하지 않고, 그 성질이 유지되는지를
 * 쿼리 구조로 고정한다 — 누군가 목록 컬럼을 되돌리거나 관계를 inner 에 다시 붙이면 실패한다.
 *
 * 결과 정합성(무엇이 몇 건 어떤 순서로 나오는가)도 함께 고정한다. 조회 방식을 바꾸는 변경에서
 * 가장 조용히 깨지는 것이 정렬과 페이지 경계다.
 */
class OrderListDeferredJoinTest extends ModuleTestCase
{
    protected User $adminUser;

    /** 수집된 실행 쿼리 SQL 목록 */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
    }

    /** 쿼리 로그 수집을 시작합니다. */
    private function startCapture(): void
    {
        $this->queries = [];

        DB::listen(function ($query) {
            $this->queries[] = $query->sql;
        });
    }

    /**
     * 수집된 쿼리 중 조건에 맞는 것만 돌려줍니다.
     *
     * @param  string  $needle  포함 문자열
     * @return array<int, string> 일치 쿼리 목록
     */
    private function queriesContaining(string $needle): array
    {
        return array_values(array_filter($this->queries, fn (string $sql) => str_contains($sql, $needle)));
    }

    /**
     * 주문 목록을 조회합니다.
     *
     * 페이지 번호는 페이지네이터가 요청에서 해석하므로(HTTP 경로와 동일), 저장소를 직접
     * 호출하는 테스트에서는 resolver 로 지정한다.
     *
     * @param  array  $filters  조회 필터
     * @param  int  $perPage  페이지당 건수
     * @param  int  $page  조회할 페이지
     * @return LengthAwarePaginator 조회 결과
     */
    private function listOrders(array $filters = [], int $perPage = 10, int $page = 1)
    {
        Paginator::currentPageResolver(fn () => $page);

        try {
            return app(OrderRepositoryInterface::class)->getListWithFilters($filters, $perPage);
        } finally {
            Paginator::currentPageResolver(fn () => 1);
        }
    }

    /**
     * 옵션 테이블을 독립적으로 읽은 쿼리만 돌려줍니다.
     *
     * 주문 목록 SELECT 안에는 옵션 수 집계 서브쿼리가 들어 있어 단순 문자열 매칭으로는
     * 관계 로딩 쿼리와 구분되지 않는다.
     *
     * @return array<int, string> 옵션 관계 로딩 쿼리 목록
     */
    private function optionRelationQueries(): array
    {
        return array_values(array_filter(
            $this->queriesContaining('from `g7_ecommerce_order_options`'),
            fn (string $sql) => ! str_contains($sql, 'from `g7_ecommerce_orders`'),
        ));
    }

    public function test_offset_scan_reads_only_id_column(): void
    {
        OrderFactory::new()->count(25)->create();

        $this->startCapture();
        $this->listOrders(page: 2);

        $offsetQueries = $this->queriesContaining('offset');

        $this->assertNotEmpty($offsetQueries, '페이지 조회에는 OFFSET 쿼리가 있어야 한다');

        foreach ($offsetQueries as $sql) {
            // 넓은 컬럼이 OFFSET 스캔에 실리면 뒤쪽 페이지 비용이 선형으로 커진다
            $this->assertStringNotContainsString('admin_memo', $sql);
            $this->assertStringNotContainsString('order_meta', $sql);
            $this->assertStringNotContainsString('promotions_applied_snapshot', $sql);
            $this->assertStringNotContainsString('mc_subtotal_amount', $sql);
        }
    }

    public function test_list_query_does_not_select_unused_wide_columns(): void
    {
        OrderFactory::new()->count(3)->create();

        $this->startCapture();
        $this->listOrders();

        $selectQueries = $this->queriesContaining('from `g7_ecommerce_orders`');

        $this->assertNotEmpty($selectQueries);

        foreach ($selectQueries as $sql) {
            $this->assertStringNotContainsString('admin_memo', $sql, '목록은 관리자 메모를 읽지 않는다');
            $this->assertStringNotContainsString('mileage_policy_snapshot', $sql);
        }
    }

    public function test_relation_queries_run_once_for_the_page(): void
    {
        OrderFactory::new()->count(5)->create();

        $this->startCapture();
        $result = $this->listOrders();

        // 관계는 outer 에서만 로드된다 — inner 에도 붙으면 같은 관계 쿼리가 2회 실행된다
        $this->assertCount(1, $this->optionRelationQueries());
        $this->assertTrue($result->getCollection()->first()->relationLoaded('firstOption'));
        $this->assertTrue($result->getCollection()->first()->relationLoaded('shippingAddress'));
    }

    public function test_pages_do_not_overlap_and_follow_sort_order(): void
    {
        // ordered_at 이 같은 주문이 섞여 있어도 페이지 경계가 흔들리면 안 된다
        $sharedTime = now()->subDay();
        OrderFactory::new()->count(12)->create(['ordered_at' => $sharedTime]);
        OrderFactory::new()->count(8)->create();

        $page1 = $this->listOrders([], 10, 1);
        $page2 = $this->listOrders([], 10, 2);

        $firstIds = $page1->getCollection()->pluck('id')->all();
        $secondIds = $page2->getCollection()->pluck('id')->all();

        $this->assertCount(10, $firstIds);
        $this->assertEmpty(array_intersect($firstIds, $secondIds), '페이지 간 중복 주문이 없어야 한다');
        $this->assertSame(20, $page1->total());
    }

    public function test_sort_by_falls_back_when_column_is_not_allowed(): void
    {
        OrderFactory::new()->count(3)->create();

        // 예전에는 요청 값이 그대로 orderBy 로 흘러 SQL 오류가 났다
        $result = $this->listOrders(['sort_by' => 'admin_memo; drop table users', 'sort_order' => 'sideways']);

        $this->assertSame(3, $result->total());
    }

    public function test_filters_still_narrow_the_result(): void
    {
        OrderFactory::new()->count(4)->create(['order_device' => 'pc']);
        OrderFactory::new()->count(3)->create(['order_device' => 'mobile']);

        $result = $this->listOrders(['order_device' => 'mobile']);

        $this->assertSame(3, $result->total());
        $this->assertCount(3, $result->getCollection());
    }

    public function test_soft_deleted_orders_stay_excluded(): void
    {
        $orders = OrderFactory::new()->count(5)->create();
        $orders->first()->delete();

        $result = $this->listOrders();

        $this->assertSame(4, $result->total());
        $this->assertNotContains($orders->first()->id, $result->getCollection()->pluck('id')->all());
    }

    public function test_admin_list_endpoint_still_returns_expected_payload(): void
    {
        OrderFactory::new()->count(3)->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => ['id', 'order_number', 'order_status', 'total_amount', 'ordered_at'],
                    ],
                    'pagination',
                ],
            ]);

        $this->assertCount(3, $response->json('data.data'));
    }

    /**
     * @scenario surface=admin_list,option_profile=multiple
     *
     * @effects admin_list_loads_only_representative_option
     */
    public function test_admin_list_loads_only_the_representative_option(): void
    {
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->count(20)->create(['order_id' => $order->id]);

        $result = $this->listOrders();
        $row = $result->getCollection()->first();

        // 화면은 대표 1건만 그린다 — 관계 자체가 1건으로 좁혀져야 한다.
        // (`options` 를 로드한 뒤 first() 로 1건만 쓰면 20건이 모두 메모리에 올라온다)
        $this->assertFalse($row->relationLoaded('options'));
        $this->assertTrue($row->relationLoaded('firstOption'));
        $this->assertNotNull($row->firstOption);
    }

    /**
     * 배송도 대표 1건만 로드되어야 합니다.
     *
     * 목록 Resource 는 배송 정보 중 대표 1건(배송유형·배송방법·택배사·송장번호)만 그리는데
     * 관계는 `shippings` 전체를 로드하고 있었다. 분할 배송이 많은 주문일수록 쓰지 않는 배송 행이
     * 페이지 전체에 실린다 — 옵션과 같은 결함이 배송에 그대로 남아 있던 것을 고정한다.
     *
     * @scenario surface=admin_list,option_profile=multiple
     *
     * @effects admin_list_loads_only_representative_shipping
     */
    public function test_admin_list_loads_only_the_representative_shipping(): void
    {
        $order = OrderFactory::new()->create();
        OrderShippingFactory::new()->count(5)->create(['order_id' => $order->id]);

        // 배송 팩토리는 order_option 을 통해 다른 주문도 만든다 — 대상 주문 행을 명시적으로 찾는다.
        $row = $this->listOrders([], 50)->getCollection()->firstWhere('id', $order->id);

        $this->assertNotNull($row, '대상 주문이 목록에 있어야 한다');
        $this->assertFalse($row->relationLoaded('shippings'), '목록은 배송 전체를 로드하면 안 된다');
        $this->assertTrue($row->relationLoaded('firstShipping'));
        $this->assertNotNull($row->firstShipping);

        // 택배사도 함께 로드되어야 한다 — 아니면 Resource 가 행마다 carrier 를 다시 조회한다.
        $this->assertTrue($row->firstShipping->relationLoaded('carrier'));
    }

    /**
     * 배송 관계 쿼리도 페이지당 1회로 고정되어야 합니다.
     *
     * @effects admin_list_shipping_query_count_is_constant
     */
    public function test_shipping_query_count_stays_constant_as_rows_and_shipments_grow(): void
    {
        $targetIds = [];
        foreach ([1, 4, 9] as $shipmentCount) {
            $order = OrderFactory::new()->create();
            OrderShippingFactory::new()->count($shipmentCount)->create(['order_id' => $order->id]);
            $targetIds[] = $order->id;
        }

        $this->startCapture();
        $result = $this->listOrders([], 100);

        $shippingQueries = array_values(array_filter(
            $this->queries,
            fn ($sql) => str_contains($sql, (new OrderShipping)->getTable())
                && ! str_contains($sql, (new Order)->getTable()),
        ));

        $this->assertCount(1, $shippingQueries, '배송 관계 쿼리는 페이지당 1회여야 한다');
        $this->assertSame(
            3,
            $result->getCollection()
                ->whereIn('id', $targetIds)
                ->filter(fn ($order) => $order->firstShipping !== null)
                ->count(),
        );
    }

    /**
     * @effects admin_list_option_query_count_is_constant
     */
    public function test_option_query_count_stays_constant_as_rows_and_options_grow(): void
    {
        foreach ([2, 15, 30] as $optionCount) {
            $order = OrderFactory::new()->create();
            OrderOptionFactory::new()->count($optionCount)->create(['order_id' => $order->id]);
        }

        $this->startCapture();
        $result = $this->listOrders();

        // 옵션 관계 쿼리는 페이지당 1회다 — 행 수·주문당 옵션 수가 늘어도 늘지 않는다
        $this->assertCount(1, $this->optionRelationQueries());

        // 그리고 그 1회가 읽어 오는 것은 주문당 대표 1건뿐이다
        $this->assertSame(3, $result->getCollection()->count());
        $this->assertSame(
            3,
            $result->getCollection()->filter(fn ($order) => $order->firstOption !== null)->count(),
        );
    }

    /**
     * @scenario surface=admin_list,option_profile=partially_cancelled
     *
     * @effects options_count_includes_cancelled, partially_cancelled_true_only_when_some_remain, first_option_display_fields_unchanged
     */
    public function test_options_count_and_partial_cancel_come_from_aggregates(): void
    {
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->count(2)->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::CANCELLED,
        ]);
        OrderOptionFactory::new()->count(3)->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        $response->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertSame(5, $row['options_count'], '전체 옵션 수는 취소분을 포함한다');
        $this->assertTrue($row['is_partially_cancelled'], '일부만 취소된 주문은 부분취소다');
        $this->assertNotNull($row['first_option']['product_name'] ?? null);
    }

    /**
     * @scenario surface=admin_list,option_profile=fully_cancelled
     *
     * @effects fully_cancelled_is_not_partially_cancelled
     */
    public function test_fully_cancelled_order_is_not_flagged_as_partially_cancelled(): void
    {
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->count(3)->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::CANCELLED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertFalse($row['is_partially_cancelled']);
    }

    /**
     * @scenario surface=admin_list,option_profile=none
     *
     * @effects options_count_is_zero_not_missing_when_empty
     */
    public function test_order_without_options_reports_zero_not_missing(): void
    {
        $order = OrderFactory::new()->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        // COUNT 는 0건에서 0 을 돌려준다 — 필드가 사라지거나 null 이 되면 화면의 "외 N건"
        // 판정이 깨진다. 집계 유무를 값으로 판정하면 이 케이스가 조용히 틀린다.
        $this->assertArrayHasKey('options_count', $row);
        $this->assertSame(0, $row['options_count']);
        $this->assertFalse($row['is_partially_cancelled']);
    }

    /**
     * @effects list_payload_has_no_empty_object_fields
     */
    public function test_list_payload_has_no_unfulfilled_conditional_fields(): void
    {
        OrderFactory::new()->count(2)->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        foreach ($response->json('data.data') as $row) {
            foreach ($row as $key => $value) {
                // 컬렉션이 toArray() 결과를 그대로 싣는 경로는 Laravel 의 MissingValue 제거를
                // 거치지 않는다 — 미충족 필드가 `{}` 로 응답에 남는다
                $this->assertFalse(
                    is_array($value) && $value === [],
                    "미충족 조건부 필드가 빈 객체로 남았다: {$key}",
                );
            }
        }
    }

    /**
     * 주문상품이 1건뿐이어도 대표 상품과 개수가 정확히 나온다.
     *
     * "외 N건" 은 개수가 1일 때 표시되지 않아야 한다 — 집계를 잘못 세면 1건짜리 주문에
     * "외 0건" 이 붙는다.
     *
     * @scenario surface=admin_list,option_profile=single
     *
     * @effects first_option_display_fields_unchanged, options_count_includes_cancelled
     */
    public function test_single_option_order_reports_count_one(): void
    {
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->create(['order_id' => $order->id]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertSame(1, $row['options_count']);
        $this->assertNotNull($row['first_option']['product_name'] ?? null);
        $this->assertFalse($row['is_partially_cancelled']);
    }

    /**
     * 마이페이지도 주문상품이 1건인 주문을 그대로 나열한다.
     *
     * @scenario surface=my_page_list,option_profile=single
     *
     * @effects my_page_list_still_enumerates_every_item
     */
    public function test_user_order_list_returns_single_item_order(): void
    {
        $customer = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $customer->id]);
        OrderOptionFactory::new()->create(['order_id' => $order->id]);

        $response = $this->actingAs($customer)
            ->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertCount(1, $row['items']);
        $this->assertSame(1, $row['item_count']);
    }

    /**
     * @scenario surface=my_page_list,option_profile=multiple
     *
     * @effects my_page_list_enumerates_every_item_when_requested
     */
    public function test_user_order_list_enumerates_every_item_when_requested(): void
    {
        $customer = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $customer->id]);
        OrderOptionFactory::new()->count(4)->create(['order_id' => $order->id]);

        // 마이페이지는 주문별 아이템을 전부 나열하므로 전량을 명시적으로 요청한다.
        // 이 경로가 깨지면 화면에 주문마다 상품 한 줄만 남는다.
        $response = $this->actingAs($customer)
            ->getJson('/api/modules/sirsoft-ecommerce/user/orders?with_items=1');

        $response->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertCount(4, $row['items']);
        $this->assertSame(4, $row['item_count']);
    }

    /**
     * @scenario surface=my_page_list,option_profile=multiple
     *
     * @effects my_page_list_default_is_representative_only
     */
    public function test_user_order_list_defaults_to_representative_item(): void
    {
        $customer = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $customer->id]);
        OrderOptionFactory::new()->count(4)->create(['order_id' => $order->id]);

        // 요청하지 않은 호출자에게 전량을 안기지 않는다 — 개수는 집계로 그대로 제공된다.
        $response = $this->actingAs($customer)
            ->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertCount(1, $row['items']);
        $this->assertSame(4, $row['item_count']);
    }
}
