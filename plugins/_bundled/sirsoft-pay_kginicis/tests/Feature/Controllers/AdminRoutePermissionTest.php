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
            'api.plugins.sirsoft-pay_kginicis.admin.orders.cash-receipt.issue' => 'permission:admin,sirsoft-ecommerce.orders.update',
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

    public function test_order_update_permission_is_required_for_cash_receipt_issue(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $response = $this->actingAs($admin)
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-PERM-001/cash-receipt', [
                'issue_type' => '0',
                'issue_number' => '01012345678',
            ]);

        $response->assertForbidden();
    }
}
