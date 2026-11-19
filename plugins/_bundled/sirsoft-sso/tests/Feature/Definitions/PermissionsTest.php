<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Definitions;

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Plugin;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 권한 선언(W42, D26)과 관리자 라우트의 권한 미들웨어 — 6권한 모두 admin 형식이고, 관리자 API 마다 그 화면의 권한이 붙는다.
 *
 * @since 1.0.0
 */
class PermissionsTest extends PluginTestCase
{
    /**
     * 권한 6개가 선언돼 있는지 확인한다.
     *
     * @scenario declarations=permissions
     *
     * @effects six_admin_permissions
     *
     * @return void 반환값 없음
     */
    public function test_six_permissions_declared(): void
    {
        $declared = [];
        foreach ((new Plugin)->getPermissions()['categories'] as $category) {
            foreach ($category['permissions'] as $permission) {
                $declared[] = 'sirsoft-sso.'.$category['identifier'].'.'.$permission['action'];
                $this->assertSame('admin', $permission['type']);
            }
        }

        sort($declared);
        $this->assertSame([
            'sirsoft-sso.accounts.provision', 'sirsoft-sso.accounts.unlink', 'sirsoft-sso.accounts.view',
            'sirsoft-sso.logs.view', 'sirsoft-sso.settings.update', 'sirsoft-sso.settings.view',
        ], $declared);
    }

    /**
     * 관리자 라우트마다 기대한 권한 미들웨어가 붙어 있는지 확인한다.
     *
     * @scenario routes=admin.*|me.*
     *
     * @effects route_permission_map
     *
     * @return void 반환값 없음
     */
    public function test_admin_routes_carry_permissions(): void
    {
        $expected = [
            'admin.idps.index' => 'settings.view', 'admin.idps.show' => 'settings.view', 'admin.idps.store' => 'settings.update',
            'admin.idps.update' => 'settings.update', 'admin.idps.destroy' => 'settings.update', 'admin.idps.test' => 'settings.update',
            'admin.idps.sp-keys' => 'settings.update', 'admin.idps.scim-token' => 'settings.update',
            'admin.policy.show' => 'settings.view', 'admin.policy.update' => 'settings.update', 'admin.policy.impact' => 'settings.view',
            'admin.accounts.index' => 'accounts.view', 'admin.accounts.store' => 'accounts.provision', 'admin.accounts.import' => 'accounts.provision',
            'admin.accounts.destroy' => 'accounts.unlink', 'admin.logs.attempts' => 'logs.view', 'admin.logs.scim' => 'logs.view',
        ];

        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName('api.plugins.sirsoft-sso.'.$name);
            $this->assertNotNull($route, $name);
            $this->assertContains('permission:admin,sirsoft-sso.'.$permission, $route->gatherMiddleware(), $name);
        }

        foreach (['me.accounts.index', 'me.links.start', 'me.accounts.destroy'] as $name) {
            $this->assertContains('auth:sanctum', Route::getRoutes()->getByName('api.plugins.sirsoft-sso.'.$name)->gatherMiddleware(), $name);
        }
    }
}
