<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 관리자 라우트 세부권한 회귀 테스트 (KVE-2026-1893)
 *
 * `admin` 미들웨어는 type=admin 권한 보유 여부만 판정하므로 업무 권한이 없는
 * 제한 관리자도 도달한다. 주문 조회/조작 경로는 `permission:admin,...` 로
 * 세부권한을 부착해야 하며, 형제 PG 플러그인(kginicis/nicepayments)과 동일 강도여야 한다.
 */
class AdminRoutePermissionTest extends PluginTestCase
{
    /**
     * 라우트 선언에 세부권한 미들웨어가 부착되어 있는지 확인합니다.
     *
     * @return void
     */
    public function test_admin_routes_declare_ecommerce_permission_middleware(): void
    {
        $expected = [
            'api.plugins.sirsoft-pay_nhnkcp.admin.orders.test-mode-map' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_nhnkcp.admin.orders.easy-pay-display-map' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_nhnkcp.admin.orders.transaction-status' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_nhnkcp.admin.orders.escrow-delivery.form' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_nhnkcp.admin.orders.escrow-delivery.register' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_nhnkcp.admin.vbank.notify.url' => 'permission:admin,sirsoft-ecommerce.settings.read',
            'api.plugins.sirsoft-pay_nhnkcp.admin.health' => 'permission:admin,sirsoft-ecommerce.settings.read',
        ];

        // 이름 조회표는 앱 부팅 시점에 한 번 갱신된다. 테스트는 부팅 이후(setUp)에 라우트를
        // 등록하므로 그 표에 반영되지 않는다 — 조회 전에 명시적으로 다시 만든다.
        Route::getRoutes()->refreshNameLookups();

        foreach ($expected as $routeName => $permissionMiddleware) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route [{$routeName}] should exist.");
            $this->assertContains(
                $permissionMiddleware,
                $route->gatherMiddleware(),
                "Route [{$routeName}] should require [{$permissionMiddleware}]."
            );
        }
    }

    /**
     * 업무 권한이 없는 관리자는 주문 관련 전 경로에서 403 을 받아야 합니다.
     *
     * @return void
     */
    public function test_admin_without_business_permission_is_denied_on_every_order_route(): void
    {
        $admin = $this->createAdminUser([]);
        $order = $this->createEscrowOrder();
        $base = '/api/plugins/sirsoft-pay_nhnkcp/admin';

        $this->actingAs($admin)->getJson($base.'/orders/test-mode-map')->assertForbidden();
        $this->actingAs($admin)->getJson($base.'/orders/easy-pay-display-map')->assertForbidden();
        $this->actingAs($admin)->getJson($base."/orders/{$order->order_number}/transaction-status")->assertForbidden();
        $this->actingAs($admin)->getJson($base."/orders/{$order->order_number}/escrow-delivery")->assertForbidden();
        $this->actingAs($admin)->postJson($base."/orders/{$order->order_number}/escrow-delivery", [
            'deli_numb' => '1234567890',
            'deli_corp' => '04',
        ])->assertForbidden();
        $this->actingAs($admin)->getJson($base.'/vbank-notify-url')->assertForbidden();
        $this->actingAs($admin)->getJson($base.'/health')->assertForbidden();
    }

    /**
     * 403 은 가드 선행이어야 하며, 거부된 등록 요청은 결제 메타를 변경하지 않아야 합니다.
     *
     * @return void
     */
    public function test_denied_escrow_register_leaves_payment_meta_untouched(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = $this->createEscrowOrder();
        $before = $order->payment()->first()->payment_meta;

        $this->actingAs($admin)
            ->postJson("/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{$order->order_number}/escrow-delivery", [
                'deli_numb' => '1234567890',
                'deli_corp' => '04',
            ])
            ->assertForbidden();

        $payment = $order->payment()->first();
        $this->assertSame($before, $payment->payment_meta);
        $this->assertArrayNotHasKey('escrow_delivery', $payment->payment_meta ?? []);
    }

    /**
     * 읽기 권한 보유 관리자는 조회 경로에 도달해야 합니다 (수정이 정상 관리자를 깨지 않음).
     *
     * @return void
     */
    public function test_admin_with_orders_read_can_reach_read_routes(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = $this->createEscrowOrder();
        $base = '/api/plugins/sirsoft-pay_nhnkcp/admin';

        $this->actingAs($admin)->getJson($base.'/orders/test-mode-map')->assertOk();
        $this->actingAs($admin)->getJson($base.'/orders/easy-pay-display-map')->assertOk();
        $this->actingAs($admin)->getJson($base."/orders/{$order->order_number}/escrow-delivery")->assertOk();
    }

    /**
     * 수정 권한 보유 관리자는 배송등록에 도달해야 합니다.
     *
     * @return void
     */
    public function test_admin_with_orders_update_can_register_escrow_delivery(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = $this->createEscrowOrder();

        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('registerEscrowDelivery')->willReturn([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => 'KCP_ESCROW_TNO_PERM',
            'deli_numb' => '1234567890',
            'deli_corp' => '04',
        ]);
        $this->app->instance(NhnKcpApiService::class, $mock);

        $this->actingAs($admin)
            ->postJson("/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{$order->order_number}/escrow-delivery", [
                'deli_numb' => '1234567890',
                'deli_corp' => '04',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * 설정 읽기 권한 보유 관리자는 설정성 경로에 도달해야 합니다.
     *
     * @return void
     */
    public function test_admin_with_settings_read_can_reach_settings_routes(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $this->actingAs($admin)
            ->getJson('/api/plugins/sirsoft-pay_nhnkcp/admin/vbank-notify-url')
            ->assertOk();
    }

    /**
     * 에스크로 결제가 붙은 테스트 주문을 생성합니다.
     *
     * @return Order 생성된 주문
     */
    private function createEscrowOrder(): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KCP-PERM-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 30000,
            'total_due_amount' => 0,
            'total_paid_amount' => 30000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => 'KCP_TNO_PERM_'.random_int(10000, 99999),
            'paid_amount_local' => 30000,
            'paid_at' => now(),
            'is_escrow' => true,
            'payment_meta' => [
                'site_cd' => 'T0000',
                'is_test_mode' => true,
                'escw_yn' => 'Y',
            ],
        ]);

        return $order->fresh();
    }
}
