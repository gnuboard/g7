<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class AdminRoutePermissionTest extends PluginTestCase
{
    public function test_admin_financial_routes_require_ecommerce_permissions(): void
    {
        $expected = [
            'api.plugins.sirsoft-pay_kginicis.admin.orders.test-mode-map' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.transaction.query' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.transaction-status' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-reconciliation.show' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-reconciliation.refund-retry' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.show' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.simulate-notify' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.expire' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.recheck' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-delivery.form' => 'permission:admin,sirsoft-ecommerce.orders.read',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-delivery.register' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-deny-confirm' => 'permission:admin,sirsoft-ecommerce.orders.update',
            'api.plugins.sirsoft-pay_kginicis.admin.cbt.test-product.create' => 'permission:admin,sirsoft-ecommerce.products.create',
            'api.plugins.sirsoft-pay_kginicis.admin.vbank.notify.url' => 'permission:admin,sirsoft-ecommerce.settings.read',
            'api.plugins.sirsoft-pay_kginicis.admin.cbt.connectivity.check' => 'permission:admin,sirsoft-ecommerce.settings.read',
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
     */
    public function test_legacy_cash_receipt_route_is_removed(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('api.plugins.sirsoft-pay_kginicis.admin.orders.cash-receipt.issue'),
            'KG 전용 현금영수증 발행 라우트는 공용 프로바이더 축으로 이관되어 제거되어야 합니다.'
        );
    }
}
