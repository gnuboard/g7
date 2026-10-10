<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 관리자 설정 화면의 가상계좌 입금통보 웹훅 URL 조회 회귀
 *
 * 운영자는 이 값을 토스 개발자센터 웹훅 메뉴에 그대로 붙여 넣는다. 종전에는 레이아웃이
 * `/plugins/sirsoft-tosspayments/webhook/deposit` 경로만 보여 주고 복사해, 운영자가 사이트 주소를
 * 손으로 덧붙여야 했다. 다른 결제사 플러그인처럼 서버가 url() 로 만든 전체 주소를 내려준다.
 *
 * 시나리오 매니페스트: tests/scenarios/toss-payment-methods-vbank-escrow.yaml
 */
class AdminWebhookUrlTest extends PluginTestCase
{
    private const ENDPOINT = '/api/plugins/sirsoft-tosspayments/admin/webhook-url';

    private const ROUTE_NAME = 'api.plugins.sirsoft-tosspayments.admin.webhook-url';

    private const DEPOSIT_ROUTE_NAME = 'web.plugins.sirsoft-tosspayments.webhook.deposit';

    /**
     * 설정 조회 권한이 있는 관리자는 사이트 주소까지 붙은 입금통보 웹훅 전체 URL 을 받는다.
     *
     * @effects webhook_url_returned_as_absolute_url
     *
     * @return void 반환값 없음
     */
    public function test_admin_receives_absolute_deposit_webhook_url(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $response = $this->actingAs($admin)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.url', url('/plugins/sirsoft-tosspayments/webhook/deposit'));

        $webhookUrl = (string) $response->json('data.url');
        $this->assertMatchesRegularExpression(
            '#^https?://[^/]+/plugins/sirsoft-tosspayments/webhook/deposit$#',
            $webhookUrl,
            '웹훅 URL 은 경로만이 아니라 scheme·host 가 붙은 전체 주소여야 합니다.'
        );
    }

    /**
     * 내려준 URL 의 경로가 실제 입금통보 수신 라우트와 같다 — 라우트 경로가 바뀌면 함께 바뀌어야 한다.
     *
     * @effects webhook_url_returned_as_absolute_url
     *
     * @return void 반환값 없음
     */
    public function test_returned_url_points_to_registered_deposit_route(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $webhookUrl = (string) $this->actingAs($admin)->getJson(self::ENDPOINT)->json('data.url');

        $depositRoute = Route::getRoutes()->getByName(self::DEPOSIT_ROUTE_NAME);
        $this->assertNotNull($depositRoute, 'Route ['.self::DEPOSIT_ROUTE_NAME.'] should exist.');
        $this->assertContains('POST', $depositRoute->methods());
        $this->assertSame('/'.ltrim($depositRoute->uri(), '/'), parse_url($webhookUrl, PHP_URL_PATH));
    }

    /**
     * 조회 라우트는 다른 결제사 플러그인의 입금통보 URL 조회와 같은 권한을 요구한다.
     *
     * @effects webhook_url_lookup_requires_settings_read
     *
     * @return void 반환값 없음
     */
    public function test_route_requires_ecommerce_settings_read_permission(): void
    {
        $route = Route::getRoutes()->getByName(self::ROUTE_NAME);

        $this->assertNotNull($route, 'Route ['.self::ROUTE_NAME.'] should exist.');
        $this->assertContains('GET', $route->methods());
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:admin,sirsoft-ecommerce.settings.read', $route->gatherMiddleware());
    }

    /**
     * 설정 조회 권한이 없는 관리자는 거부된다.
     *
     * @effects webhook_url_lookup_requires_settings_read
     *
     * @return void 반환값 없음
     */
    public function test_admin_without_settings_read_permission_is_forbidden(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->getJson(self::ENDPOINT)->assertForbidden();
    }

    /**
     * 비로그인 요청은 거부된다.
     *
     * @effects webhook_url_lookup_requires_settings_read
     *
     * @return void 반환값 없음
     */
    public function test_guest_is_unauthorized(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }
}
