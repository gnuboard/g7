<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * KG이니시스 관리자 연동 경로의 이커머스 세부 권한 선언 테스트
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/admin-route-permission.yaml 참조.
 */
class AdminRoutePermissionTest extends PluginTestCase
{
    /**
     * 관리자 금융 경로마다 업무에 맞는 이커머스 세부 권한 미들웨어가 붙어 있는지 확인합니다.
     *
     * @scenario route_group=admin_financial_routes
     *
     * @effects admin_financial_routes_declare_ecommerce_permission_middleware
     *
     * @return void 반환값 없음
     */
    public function test_admin_financial_routes_require_ecommerce_permissions(): void
    {
        $expected = [
            'api.plugins.sirsoft-pay_kginicis.admin.orders.test-mode-map' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.transaction.query' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.transaction-status' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-reconciliation.show' => 'permission:admin,sirsoft-ecommerce.orders.read',
            // 환불 재시도는 PG 환불을 다시 보내므로 「환불 실행」 권한(제품 결정 R1, 7.1.0)
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-reconciliation.refund-retry' => 'permission:admin,sirsoft-ecommerce.claims.execute-refund',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.show' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.simulate-notify' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.expire' => 'permission:admin,sirsoft-ecommerce.orders.update',
            // 재확인은 결제 기록에 재확인 시각·결과를 쓰므로 주문 수정 권한(7.1.0 abilities 게이트 N1)
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.recheck' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-delivery.form' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-delivery.register' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-deny-confirm' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_kginicis.admin.cbt.test-product.create' => 'permission:admin,sirsoft-ecommerce.products.create',
            'api.plugins.sirsoft-pay_kginicis.admin.vbank.notify.url' => 'permission:admin,sirsoft-ecommerce.settings.read',
            // 연결 진단은 플러그인 설정 화면의 버튼이라 화면 권한(core.plugins.update)으로도 쓴다(OR, 7.1.0 abilities 게이트 N5)
            'api.plugins.sirsoft-pay_kginicis.admin.cbt.connectivity.check' => 'permission:admin,sirsoft-ecommerce.settings.read|core.plugins.update,false',
        ];

        foreach ($expected as $routeName => $permissionMiddleware) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route [{$routeName}] should exist.");
            $this->assertContains($permissionMiddleware, $route->gatherMiddleware(), "Route [{$routeName}] should require [{$permissionMiddleware}].");
        }
    }

    /**
     * 현금영수증 발행 라우트가 제거되었는지 확인합니다.
     *
     * 발행은 이커머스 모듈의 공용 현금영수증 API 로 이관되었다 (프로바이더 훅 축).
     * 라우트가 되살아나면 KG 전용 경로가 다시 생겨 이중 발행 경로가 된다.
     *
     * @effects legacy_kg_cash_receipt_route_removed
     *
     * @return void 반환값 없음
     */
    public function test_legacy_cash_receipt_route_is_removed(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('api.plugins.sirsoft-pay_kginicis.admin.orders.cash-receipt.issue'),
            'KG 전용 현금영수증 발행 라우트는 공용 프로바이더 축으로 이관되어 제거되어야 합니다.'
        );
    }
}
