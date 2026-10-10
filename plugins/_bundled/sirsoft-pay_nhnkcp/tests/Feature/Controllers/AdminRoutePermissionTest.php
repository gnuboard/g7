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
     * 1.1.0 에서 제거한 간편결제 표시 맵 경로가 다시 등록되지 않았는지도 함께 확인합니다.
     *
     * @effects admin_routes_declare_ecommerce_permission_middleware, removed_easy_pay_display_map_route_is_not_registered
     *
     * @return void 반환값 없음
     */
    public function test_admin_routes_declare_ecommerce_permission_middleware(): void
    {
        $expected = [
            'api.plugins.sirsoft-pay_nhnkcp.admin.orders.test-mode-map' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_nhnkcp.admin.orders.transaction-status' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_nhnkcp.admin.orders.escrow-delivery.form' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_nhnkcp.admin.orders.escrow-delivery.register' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_nhnkcp.admin.vbank.notify.url' => 'permission:admin,sirsoft-ecommerce.settings.read',
            // 시스템 점검은 플러그인 설정 화면 카드라 화면 권한(core.plugins.update)으로도 읽는다(OR, 7.1.0 abilities 게이트 N4)
            'api.plugins.sirsoft-pay_nhnkcp.admin.health' => 'permission:admin,sirsoft-ecommerce.settings.read|core.plugins.update,false',
        ];

        foreach ($expected as $routeName => $permissionMiddleware) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route [{$routeName}] should exist.");
            $this->assertContains(
                $permissionMiddleware,
                $route->gatherMiddleware(),
                "Route [{$routeName}] should require [{$permissionMiddleware}]."
            );
        }

        // 주문 목록 결제수단 이름은 이커머스 응답이 싣는다 — 별도 표시 맵 경로는 제거됐다.
        $this->assertNull(
            Route::getRoutes()->getByName('api.plugins.sirsoft-pay_nhnkcp.admin.orders.easy-pay-display-map'),
            '제거한 간편결제 표시 맵 경로가 다시 등록되면 안 된다.'
        );
    }

    /**
     * 업무 권한이 없는 관리자는 주문 관련 전 경로에서 403 을 받아야 합니다.
     *
     * @scenario permission=none
     *
     * @effects admin_without_business_permission_is_denied_on_order_routes
     *
     * @return void 반환값 없음
     */
    public function test_admin_without_business_permission_is_denied_on_every_order_route(): void
    {
        $admin = $this->createAdminUser([]);
        $order = $this->createEscrowOrder();
        $base = '/api/plugins/sirsoft-pay_nhnkcp/admin';

        $this->actingAs($admin)->getJson($base.'/orders/test-mode-map')->assertForbidden();
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
     * @scenario permission=orders_read
     *
     * @effects denied_escrow_register_leaves_payment_meta_untouched
     *
     * @return void 반환값 없음
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
     * @scenario permission=orders_read
     *
     * @effects orders_read_admin_reaches_read_routes
     *
     * @return void 반환값 없음
     */
    public function test_admin_with_orders_read_can_reach_read_routes(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = $this->createEscrowOrder();
        $base = '/api/plugins/sirsoft-pay_nhnkcp/admin';

        $this->actingAs($admin)->getJson($base.'/orders/test-mode-map')->assertOk();
        $this->actingAs($admin)->getJson($base."/orders/{$order->order_number}/escrow-delivery")->assertOk();
    }

    /**
     * 수정 권한 보유 관리자는 배송등록에 도달해야 합니다.
     *
     * @scenario permission=orders_update
     *
     * @effects orders_update_admin_reaches_escrow_register
     *
     * @return void 반환값 없음
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
     * @scenario permission=settings_read
     *
     * @effects settings_read_admin_reaches_settings_routes
     *
     * @return void 반환값 없음
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
