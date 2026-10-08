<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\EcommerceStat;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\EcommerceDashboardService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;

/**
 * 대시보드 「오늘」 상태 배지 = 배지로 연 주문 목록의 건수 (7.1.0 전수 실측 · 결정 2026-10-08 4차)
 *
 * 배지는 일 집계 표(ecommerce_stats)의 옵션 상태별 **수량**을 읽고, 배지를 눌러 여는 주문 목록은 주문 상태별
 * **주문 수**를 셌다 — 한 주문에 수량이 2개면 배지 「결제완료 2건」 → 목록 「총 1개」. 결정: 배지가 주문 수를 센다.
 * 배지 숫자는 배지 링크가 여는 목록과 **같은 조건**(주문 상태·사이트 하루·삭제 제외·주문 조회 권한 범위)으로 주문 표에서 바로 센다.
 *
 * 공통: 사이트 표준시 Asia/Seoul, 관리자 계정 시간대 UTC. 기준 순간 UTC 2026-10-07 16:30 = 한국 10-08 01:30.
 * - 사이트 오늘(10-08) 안쪽 시각: UTC 10-07 15:30~16:00 (한국 10-08 00:30~01:00)
 * - 바깥 시각: UTC 10-07 14:30 (한국 10-07 23:30)
 *
 * 시나리오 SSoT: tests/scenarios/admin-dashboard-badge-order-count.yaml
 */
class DashboardBadgeOrderCountTest extends ModuleTestCase
{
    private const OVERVIEW = '/api/modules/sirsoft-ecommerce/admin/dashboard/overview';

    private const ORDERS = '/api/modules/sirsoft-ecommerce/admin/orders';

    private const SITE_TODAY = '2026-10-08';

    /**
     * 배지 링크가 들어 있는 대시보드 확장 레이아웃(`.g7.html` 의 굽기 산출물).
     */
    private const LAYOUT = __DIR__.'/../../../resources/extensions/admin_dashboard_commerce.json';

    /**
     * 배지 링크 질의 인자 중 상태 조건이 아닌 것(날짜·기준).
     *
     * @var array<int, string>
     */
    private const NON_STATUS_KEYS = ['date_type', 'date_basis', 'start_date', 'end_date'];

    private User $admin;

    /**
     * 시각·시간대를 고정하고 주문·집계 표를 비운 뒤 대시보드·주문 조회 권한을 가진 UTC 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 16:30:00', 'UTC'));
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        OrderReturn::query()->forceDelete();
        OrderOption::query()->forceDelete();
        Order::query()->forceDelete();
        EcommerceStat::query()->delete();

        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.dashboard.view', 'sirsoft-ecommerce.orders.read']);
        $this->admin->forceFill(['timezone' => 'UTC'])->save();
    }

    /**
     * 결제완료 배지는 옵션 수량이 아니라 주문 수를 센다 — 수량 2 주문 1건 + 수량 1 주문 1건 + 옵션 2개 주문 1건 = 3건,
     * 그 배지 링크로 연 목록도 3건이다. 사이트 하루 밖(어제 밤) 주문은 둘 다 세지 않는다.
     *
     * 배지는 일 집계(매시)를 기다리지 않는다 — 집계 표에 묵은 수량(99)이 있어도 주문 표에서 바로 센 값이다.
     *
     * @scenario metric=order_count, badge=payment_complete
     *
     * @effects dashboard_badge_counts_orders_not_option_quantities, dashboard_badge_equals_linked_order_list_total, dashboard_badge_reads_orders_without_waiting_for_hourly_aggregate
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function payment_complete_badge_counts_orders_not_quantities(): void
    {
        $this->makeOrder(OrderStatusEnum::PAYMENT_COMPLETE, '2026-10-07 15:30:00', [2]);
        $this->makeOrder(OrderStatusEnum::PAYMENT_COMPLETE, '2026-10-07 15:40:00', [1]);
        $this->makeOrder(OrderStatusEnum::PAYMENT_COMPLETE, '2026-10-07 15:50:00', [1, 1]);
        // 사이트 하루 밖 (한국 10-07 23:30)
        $this->makeOrder(OrderStatusEnum::PAYMENT_COMPLETE, '2026-10-07 14:30:00', [5]);

        EcommerceStat::create([
            'date' => self::SITE_TODAY,
            'sales_quantity' => 99,
            'sales_amount' => 0,
            'option_status_counts' => ['payment_complete' => 99],
        ]);

        $overview = $this->overview();

        $this->assertSame(3, $overview['payment_complete'], '배지는 주문 3건이다(옵션 수량 합 5 가 아니다)');
        $this->assertSame(3, $this->listTotal($this->badgeLinkFilters()['payment_complete']), '배지 링크로 연 목록도 3건');
    }

    /**
     * 배지 7개 전부 — 배지 숫자가 그 배지 링크로 연 목록의 총 건수와 같다.
     *
     * 목록 조건을 그대로 따른다: 「준비중」은 목록 상태 필터처럼 상품준비중+배송준비중을 함께 세고, 「취소」는 주문 전체가
     * 취소된 주문만(일부 취소 주문은 진행 단계 배지에 남는다), 「반품」은 반품 완료 신청서가 있는 주문을 센다
     * (반품 진행 중은 세지 않는다).
     *
     * @scenario metric=order_count, badge=all
     *
     * @effects dashboard_badge_equals_linked_order_list_total, dashboard_badges_follow_list_status_semantics
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_badge_equals_the_total_of_the_list_it_links_to(): void
    {
        $this->makeOrder(OrderStatusEnum::PENDING_PAYMENT, '2026-10-07 15:31:00', [3]);
        // 일부 취소 주문 — 주문은 결제완료, 옵션 하나만 취소
        $this->makeOrder(OrderStatusEnum::PAYMENT_COMPLETE, '2026-10-07 15:32:00', [1, 2], [null, OrderStatusEnum::CANCELLED]);
        $this->makeOrder(OrderStatusEnum::PREPARING, '2026-10-07 15:33:00', [2]);
        $this->makeOrder(OrderStatusEnum::SHIPPING_READY, '2026-10-07 15:34:00', [4]);
        $this->makeOrder(OrderStatusEnum::SHIPPING, '2026-10-07 15:35:00', [1, 1]);
        $this->makeOrder(OrderStatusEnum::CANCELLED, '2026-10-07 15:36:00', [3]);
        // 반품 완료 주문 (수량 2 옵션 반품완료)
        $returned = $this->makeOrder(OrderStatusEnum::CANCELLED, '2026-10-07 15:37:00', [2], [OrderStatusEnum::RETURNED]);
        OrderReturnFactory::new()->status(ReturnStatusEnum::COMPLETED)->create(['order_id' => $returned->id]);
        // 반품 진행 중 주문 — 반품 배지에 넣지 않는다
        $returning = $this->makeOrder(OrderStatusEnum::DELIVERED, '2026-10-07 15:38:00', [1], [OrderStatusEnum::RETURN_IN_PROGRESS]);
        OrderReturnFactory::new()->status(ReturnStatusEnum::COLLECTING)->create(['order_id' => $returning->id]);
        // 사이트 하루 밖 — 어느 배지에도 들어가지 않는다
        $this->makeOrder(OrderStatusEnum::PAYMENT_COMPLETE, '2026-10-07 14:30:00', [1]);
        $outsideReturn = $this->makeOrder(OrderStatusEnum::CANCELLED, '2026-10-07 14:31:00', [1], [OrderStatusEnum::RETURNED]);
        OrderReturnFactory::new()->status(ReturnStatusEnum::COMPLETED)->create(['order_id' => $outsideReturn->id]);

        $overview = $this->overview();

        $expected = [
            'pending_payment' => 1,
            'payment_complete' => 1,
            'preparing' => 2,
            'shipping_ready' => 1,
            'shipping' => 1,
            'cancellations' => 2,
            'returns' => 1,
        ];

        foreach ($this->badgeLinkFilters() as $key => $filters) {
            $this->assertSame($expected[$key], $overview[$key], "배지 {$key}");
            $this->assertSame($overview[$key], $this->listTotal($filters), "배지 {$key} = 그 링크로 연 목록 건수");
        }
    }

    /**
     * 배지 조건 표는 서버(`EcommerceDashboardService::BADGE_LIST_FILTERS`)와 레이아웃 링크 7개 두 곳에 적혀 있다 —
     * 두 곳이 같은지 여기서 잠근다. 한쪽만 바꾸면(예: 반품 링크 조건만 바꿈) 배지 「N건」 → 목록 「총 M개」로 다시 갈린다.
     * 다른 시험들도 목록 조건을 이 레이아웃에서 읽으므로 「배지 = 실제 링크로 연 목록」을 함께 단언한다.
     *
     * @scenario metric=order_count, badge=all
     *
     * @effects dashboard_badge_server_conditions_equal_layout_links
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function server_badge_conditions_equal_the_layout_badge_links(): void
    {
        $server = (new ReflectionClassConstant(EcommerceDashboardService::class, 'BADGE_LIST_FILTERS'))->getValue();

        $this->assertEquals($server, $this->badgeLinkFilters());
    }

    /**
     * 주문 조회 권한이 본인 범위(`orders.read` scope=self)로 좁혀진 관리자 — 배지도 그 관리자의 목록과 같은 범위로 센다.
     * 수정 전에는 배지가 범위를 걸지 않아 배지 「결제완료 3건」 → 목록 「총 2개」였다. 전체 권한 관리자는 그대로 3건이다.
     *
     * @scenario metric=order_count, badge=all
     *
     * @effects dashboard_badge_applies_order_list_permission_scope, dashboard_badge_equals_linked_order_list_total
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function scoped_admin_badge_equals_their_scoped_list_total(): void
    {
        $scoped = $this->createScopedOrderReader(ScopeType::Self);
        $other = User::factory()->create();

        $this->makeOrder(OrderStatusEnum::PAYMENT_COMPLETE, '2026-10-07 15:30:00', [1], [], $scoped->id);
        $this->makeOrder(OrderStatusEnum::PAYMENT_COMPLETE, '2026-10-07 15:40:00', [2], [], $scoped->id);
        $this->makeOrder(OrderStatusEnum::PAYMENT_COMPLETE, '2026-10-07 15:50:00', [1], [], $other->id);
        $ownReturn = $this->makeOrder(OrderStatusEnum::CANCELLED, '2026-10-07 15:51:00', [1], [OrderStatusEnum::RETURNED], $scoped->id);
        OrderReturnFactory::new()->status(ReturnStatusEnum::COMPLETED)->create(['order_id' => $ownReturn->id]);
        $otherReturn = $this->makeOrder(OrderStatusEnum::CANCELLED, '2026-10-07 15:52:00', [1], [OrderStatusEnum::RETURNED], $other->id);
        OrderReturnFactory::new()->status(ReturnStatusEnum::COMPLETED)->create(['order_id' => $otherReturn->id]);

        $scopedOverview = $this->overview($scoped);
        $this->assertSame(2, $scopedOverview['payment_complete'], '본인 범위 관리자의 결제완료 배지는 본인 주문 2건');
        $this->assertSame(1, $scopedOverview['returns'], '본인 범위 관리자의 반품 배지는 본인 주문 1건');
        foreach ($this->badgeLinkFilters() as $key => $filters) {
            $this->assertSame($scopedOverview[$key], $this->listTotal($filters, $scoped), "범위 관리자 배지 {$key} = 그 관리자의 목록 건수");
        }

        // 전체 권한 관리자(범위 없음)는 불변
        $fullOverview = $this->overview();
        $this->assertSame(3, $fullOverview['payment_complete']);
        $this->assertSame(2, $fullOverview['returns']);
        $this->assertSame(3, $this->listTotal($this->badgeLinkFilters()['payment_complete']));
    }

    /**
     * 대시보드 개요 API 를 불러 배지 값을 돌려줍니다.
     *
     * @param  User|null  $as  호출할 관리자 (null 이면 전체 권한 관리자)
     * @return array<string, int> 배지 키 => 값
     */
    private function overview(?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin)->getJson(self::OVERVIEW);
        $response->assertOk();

        return $response->json('data');
    }

    /**
     * 배지 링크와 같은 조건(사이트 오늘·주문일·상태 조건)으로 주문 목록 API 를 불러 총 건수를 돌려줍니다.
     *
     * @param  array<string, array<int, string>>  $statusFilters  배지 링크의 상태 조건
     * @param  User|null  $as  호출할 관리자 (null 이면 전체 권한 관리자)
     * @return int 목록 총 건수
     */
    private function listTotal(array $statusFilters, ?User $as = null): int
    {
        $response = $this->actingAs($as ?? $this->admin)->getJson(self::ORDERS.'?'.http_build_query(array_merge($statusFilters, [
            'date_type' => 'ordered_at',
            'date_basis' => 'site',
            'start_date' => self::SITE_TODAY,
            'end_date' => self::SITE_TODAY,
        ])));
        $response->assertOk();

        return (int) $response->json('data.pagination.total');
    }

    /**
     * 주문 1건과 옵션들을 만듭니다.
     *
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  string  $orderedAtUtc  주문 시각(UTC)
     * @param  array<int, int>  $quantities  옵션별 수량
     * @param  array<int, OrderStatusEnum|null>  $optionStatuses  옵션별 상태 (null·생략이면 주문 상태)
     * @param  int|null  $userId  주문 회원 ID (null 이면 팩토리가 새 회원을 만든다)
     * @return Order 만든 주문
     */
    private function makeOrder(OrderStatusEnum $status, string $orderedAtUtc, array $quantities, array $optionStatuses = [], ?int $userId = null): Order
    {
        $attributes = ['order_status' => $status, 'ordered_at' => $orderedAtUtc];
        if ($userId !== null) {
            $attributes['user_id'] = $userId;
        }
        $order = OrderFactory::new()->create($attributes);

        foreach ($quantities as $index => $quantity) {
            OrderOptionFactory::new()->create([
                'order_id' => $order->id,
                'option_status' => $optionStatuses[$index] ?? $status,
                'quantity' => $quantity,
                'cancelled_quantity' => 0,
                'unit_price' => 1000,
            ]);
        }

        return $order;
    }

    /**
     * 배지 7개의 링크(`admin_dashboard_commerce.json` 의 navigate 경로)에서 상태 조건을 읽어 배지 키별로 돌려줍니다.
     *
     * 날짜·기준 인자(`NON_STATUS_KEYS`)는 빼고, 배열 인자 이름의 `[]` 를 떼어 목록 API 필터 키로 맞춘다.
     *
     * @return array<string, array<string, array<int, string>>> 배지 키 => 목록 상태 조건
     */
    private function badgeLinkFilters(): array
    {
        $layout = json_decode((string) file_get_contents(self::LAYOUT), true, 512, JSON_THROW_ON_ERROR);
        $filters = [];

        $walk = function (mixed $node) use (&$walk, &$filters): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['id']) && is_string($node['id']) && preg_match('/^today_(.+)_badge$/', $node['id'], $m)) {
                foreach ($node['actions'] ?? [] as $action) {
                    if (($action['handler'] ?? null) !== 'navigate') {
                        continue;
                    }
                    $query = explode('?', (string) $action['params']['path'], 2)[1] ?? '';
                    $conditions = [];
                    foreach (explode('&', $query) as $pair) {
                        [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                        $name = str_replace('[]', '', $name);
                        if ($name === '' || in_array($name, self::NON_STATUS_KEYS, true)) {
                            continue;
                        }
                        $conditions[$name][] = $value;
                    }
                    $filters[$m[1]] = $conditions;
                }
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($layout);

        $this->assertCount(7, $filters, '대시보드 레이아웃에서 배지 링크 7개를 찾아야 한다');

        return $filters;
    }

    /**
     * 주문 조회 권한(`sirsoft-ecommerce.orders.read`)을 주어진 범위로만 가진 대시보드 관리자를 만듭니다.
     *
     * 권한 행의 소유자 열(`owner_key = user_id`)은 모듈 정의(`module.php` orders 묶음)와 같게 맞춘다.
     *
     * @param  ScopeType  $scope  조회 범위 (본인·역할)
     * @return User 만든 관리자
     */
    private function createScopedOrderReader(ScopeType $scope): User
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.dashboard.view']);
        $user->forceFill(['timezone' => 'UTC'])->save();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.orders.read'],
            ['name' => ['ko' => '주문 조회', 'en' => 'Read Orders'], 'type' => PermissionType::Admin]
        );
        $permission->update(['resource_route_key' => 'order', 'owner_key' => 'user_id']);

        $user->roles()->first()->permissions()->syncWithoutDetaching([
            $permission->id => ['scope_type' => $scope->value],
        ]);

        PermissionHelper::clearPermissionScopeCache();

        return $user->fresh();
    }
}
