<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Enums\PermissionType;
use App\Extension\HookListenerRegistrar;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ScopedPermissionEnforcement;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDeliveryController;
use Plugins\Sirsoft\PayKginicis\Listeners\OrderScopeLedgerListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * KG이니시스 관리자 경로의 주문 권한 범위(「본인만」) 재적용
 *
 * 관리자 경로는 이커머스 주문 권한을 걸지만 주문을 `{orderNumber}`·거래번호로 받아 권한 미들웨어의
 * 범위 검사가 돌지 않았다. 범위 밖 주문은 403(500 아님)으로 거부하고 PG 를 부르지 않아야 한다.
 */
class AdminOrderScopeGuardTest extends PluginTestCase
{
    private const BASE = '/api/plugins/sirsoft-pay_kginicis/admin';

    /**
     * 주문번호 경로 사례 (메서드, 경로 꼬리, 권한, 본문 종류)
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}> 사례
     */
    public static function orderNumberRoutes(): array
    {
        return [
            '거래 상태' => ['GET', 'transaction-status', 'sirsoft-ecommerce.orders.read', 'none'],
            'CBT 정산 조회' => ['GET', 'cbt-reconciliation', 'sirsoft-ecommerce.orders.read', 'none'],
            'CBT 환불 재시도' => ['POST', 'cbt-reconciliation/refund-retry', 'sirsoft-ecommerce.claims.execute-refund', 'none'],
            'CBT 편의점 조회' => ['GET', 'cbt-cvs', 'sirsoft-ecommerce.orders.read', 'none'],
            'CBT 편의점 입금 모의' => ['POST', 'cbt-cvs/simulate-notify', 'sirsoft-ecommerce.orders.update', 'none'],
            'CBT 편의점 만료' => ['POST', 'cbt-cvs/expire', 'sirsoft-ecommerce.orders.update', 'none'],
            'CBT 편의점 재확인' => ['POST', 'cbt-cvs/recheck', 'sirsoft-ecommerce.orders.update', 'none'],
            '에스크로 배송 폼' => ['GET', 'escrow-delivery', 'sirsoft-ecommerce.orders.read', 'none'],
            '에스크로 배송 등록' => ['POST', 'escrow-delivery', 'sirsoft-ecommerce.orders.update', 'escrow_delivery'],
            '에스크로 구매거절 확인' => ['POST', 'escrow-deny-confirm', 'sirsoft-ecommerce.orders.update', 'none'],
        ];
    }

    /**
     * 「본인만」 범위 관리자는 남의 주문을 주문번호 경로로 다룰 수 없다 — 403.
     *
     * @scenario target=others, route_shape=order_number
     *
     * @effects out_of_scope_order_is_denied_with_403
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $tail  주문번호 뒤 경로
     * @param  string  $permission  라우트 권한
     * @param  string  $body  본문 종류
     * @return void 반환값 없음
     */
    #[DataProvider('orderNumberRoutes')]
    public function test_self_scoped_admin_is_denied_on_others_order(string $method, string $tail, string $permission, string $body): void
    {
        $order = $this->orderOf(User::factory()->create());
        $admin = $this->scopedAdmin($permission, 'self');

        $response = $this->actingAs($admin)->json($method, self::BASE."/orders/{$order->order_number}/{$tail}", $this->body($body));

        $response->assertStatus(403);
    }

    /**
     * (과차단 회귀) 자기 주문 조회 경로는 범위 판정에 막히지 않는다.
     *
     * @scenario target=own, route_shape=order_number
     *
     * @effects in_scope_order_is_not_denied
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_admin_reads_own_order_routes(): void
    {
        $admin = $this->scopedAdmin('sirsoft-ecommerce.orders.read', 'self');
        $order = $this->orderOf($admin);

        foreach (['transaction-status', 'cbt-reconciliation', 'cbt-cvs', 'escrow-delivery'] as $tail) {
            $status = $this->actingAs($admin)->getJson(self::BASE."/orders/{$order->order_number}/{$tail}")->status();
            $this->assertNotSame(403, $status, "{$tail} 는 자기 주문에서 막히면 안 됩니다");
        }
    }

    /**
     * 거래번호 직접 조회 — 범위 밖 주문의 거래는 403, PG 를 부르지 않는다.
     *
     * @scenario target=others, route_shape=transaction_id
     *
     * @effects out_of_scope_order_is_denied_with_403
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_admin_cannot_query_others_transaction(): void
    {
        $order = $this->orderOf(User::factory()->create());
        $this->paymentOf($order, 'StdpayCARDINIpayTest_scope_'.random_int(1000, 9999));
        $tid = (string) $order->payment->transaction_id;
        $admin = $this->scopedAdmin('sirsoft-ecommerce.orders.read', 'self');

        $this->actingAs($admin)->postJson(self::BASE.'/transaction/query', ['tid' => $tid])->assertStatus(403);
    }

    /**
     * 테스트 결제 배지 맵 — 범위 밖 주문번호는 맵에 실리지 않는다.
     *
     * @scenario target=others, route_shape=list
     *
     * @effects out_of_scope_orders_are_filtered_from_list
     *
     * @return void 반환값 없음
     */
    public function test_test_mode_map_hides_others_orders(): void
    {
        $admin = $this->scopedAdmin('sirsoft-ecommerce.orders.read', 'self');
        $mine = $this->orderOf($admin);
        $theirs = $this->orderOf(User::factory()->create());
        $this->paymentOf($mine, 'StdpayCARDINIpayTest_mine_'.random_int(1000, 9999));
        $this->paymentOf($theirs, 'StdpayCARDINIpayTest_theirs_'.random_int(1000, 9999));

        $map = $this->actingAs($admin)->getJson(self::BASE.'/orders/test-mode-map')->assertOk()->json('data');

        $this->assertArrayHasKey($mine->order_number, $map);
        $this->assertArrayNotHasKey($theirs->order_number, $map);
    }

    /**
     * 원장 감사 — 이 플러그인의 관리자 라우트는 모두 원장에 있고, 적힌 강제 지점은 실제로 범위를 판정한다.
     *
     * @scenario target=others, route_shape=ledger_audit
     *
     * @effects plugin_routes_are_ledgered_with_scope_enforcers
     *
     * @return void 반환값 없음
     */
    public function test_plugin_admin_routes_are_ledgered(): void
    {
        HookListenerRegistrar::register(OrderScopeLedgerListener::class);

        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route) => str_starts_with((string) $route->getName(), 'api.plugins.sirsoft-pay_kginicis.')
        ));
        $this->assertNotEmpty($routes);

        $routeViolations = ScopedPermissionEnforcement::auditRoutes($routes, 'sirsoft-ecommerce.');
        $this->assertSame([], $routeViolations, json_encode($routeViolations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $pluginPaths = array_merge(...array_column(OrderScopeLedgerListener::entries(), 'paths'));
        $enforcerViolations = array_values(array_filter(
            ScopedPermissionEnforcement::auditEnforcers('sirsoft-ecommerce.'),
            fn (array $violation) => in_array($violation['path'], $pluginPaths, true)
        ));
        $this->assertSame([], $enforcerViolations, json_encode($enforcerViolations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 사례별 요청 본문 (FormRequest 를 통과해야 컨트롤러 판정까지 간다)
     *
     * @param  string  $kind  본문 종류
     * @return array<string, mixed> 본문
     */
    private function body(string $kind): array
    {
        return match ($kind) {
            'escrow_delivery' => ['invoice' => 'INV-SCOPE-1', 'ex_code' => array_key_first(AdminEscrowDeliveryController::COURIER_CODES)],
            default => [],
        };
    }

    /**
     * 회원의 결제 완료 주문을 만듭니다.
     *
     * @param  User  $owner  주문 회원
     * @return Order 주문
     */
    private function orderOf(User $owner): Order
    {
        return OrderFactory::new()->create([
            'user_id' => $owner->id,
            'order_number' => 'ORD-KG-SCOPE-'.random_int(100000, 999999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 10000,
            'total_due_amount' => 0,
            'total_paid_amount' => 10000,
            'paid_at' => now(),
        ]);
    }

    /**
     * 주문에 KG이니시스 테스트 결제를 붙입니다.
     *
     * @param  Order  $order  주문
     * @param  string  $tid  거래번호
     * @return void 반환값 없음
     */
    private function paymentOf(Order $order, string $tid): void
    {
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => $tid,
            'paid_amount_local' => 10000,
            'paid_at' => now(),
            'payment_meta' => ['is_test_mode' => true],
        ]);
        $order->unsetRelation('payment');
    }

    /**
     * 주문 권한 하나를 지정한 범위로 가진 관리자를 만듭니다 (권한 행에 module.php 의 스코프 메타를 심는다).
     *
     * @param  string  $permission  권한 식별자
     * @param  string|null  $scope  self / role / null(전체)
     * @return User 관리자
     */
    private function scopedAdmin(string $permission, ?string $scope): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'kg-scope-'.$user->id.'-'.uniqid(),
            'name' => ['ko' => '범위 관리자', 'en' => 'Scoped Admin'],
        ]);
        $user->roles()->attach($role->id);

        $access = Permission::firstOrCreate(['identifier' => 'admin.access'], ['name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'], 'type' => PermissionType::Admin]);
        $target = Permission::updateOrCreate(
            ['identifier' => $permission],
            ['name' => ['ko' => $permission, 'en' => $permission], 'type' => PermissionType::Admin, 'resource_route_key' => 'order', 'owner_key' => 'user_id']
        );
        $role->permissions()->syncWithoutDetaching([$access->id => ['scope_type' => null], $target->id => ['scope_type' => $scope]]);

        PermissionHelper::clearPermissionScopeCache();

        return $user->fresh();
    }
}
