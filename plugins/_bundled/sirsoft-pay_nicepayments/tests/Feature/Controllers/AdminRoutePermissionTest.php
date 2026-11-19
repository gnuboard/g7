<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 나이스페이먼츠 설정 화면 보조 경로(가상계좌 입금통보 주소 조회)의 권한 테스트
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/admin-route-permission.yaml 참조.
 */
class AdminRoutePermissionTest extends PluginTestCase
{
    private const ENDPOINT = '/api/plugins/sirsoft-pay_nicepayments/admin/vbank-notify-url';

    /**
     * 입금통보 주소 조회 경로가 쇼핑몰 설정 읽기 또는 플러그인 보기 권한(OR)을 선언하는지 확인합니다.
     *
     * @scenario permission=settings_read
     *
     * @effects vbank_notify_url_declares_settings_or_plugin_view_permission
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_url_route_declares_settings_or_plugin_view_permission(): void
    {
        $route = Route::getRoutes()->getByName('api.plugins.sirsoft-pay_nicepayments.admin.vbank.notify.url');

        $this->assertNotNull($route);
        $this->assertContains(
            'permission:admin,sirsoft-ecommerce.settings.read|core.plugins.read,false',
            $route->gatherMiddleware(),
        );
    }

    /**
     * 「플러그인 보기」 권한만 있는 관리자도 읽기 전용 설정 화면이 보여 주는 입금통보 주소를 받는지 확인합니다.
     *
     * 화면은 `core.plugins.read` 로 열리는데 주소 조회가 쇼핑몰 설정 읽기 권한만 받아, 들어갈 때마다
     * 「해당 권한이 없습니다」 알림이 뜨고 주소 칸이 비었다.
     *
     * @scenario permission=plugins_read
     *
     * @effects plugin_view_admin_reaches_vbank_notify_url
     *
     * @return void 반환값 없음
     */
    public function test_plugin_view_admin_reaches_vbank_notify_url(): void
    {
        $this->actingAs($this->createAdminUser(['core.plugins.read']))->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.url', url('/plugins/sirsoft-pay_nicepayments/payment/vbank-notify'));
    }

    /**
     * 쇼핑몰 설정 읽기 권한도 플러그인 보기 권한도 없는 관리자는 거부되는지 확인합니다.
     *
     * @scenario permission=none
     *
     * @effects admin_without_settings_or_plugin_view_is_denied
     *
     * @return void 반환값 없음
     */
    public function test_admin_without_settings_or_plugin_view_is_denied(): void
    {
        $this->actingAs($this->createAdminUser())->getJson(self::ENDPOINT)->assertForbidden();
    }
}
