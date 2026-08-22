<?php

namespace Plugins\Sirsoft\Marketing\Tests\Feature\Http\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

/**
 * 마케팅 관리자 라우트 세부권한 회귀 테스트
 *
 * `admin` 미들웨어는 type=admin 보유 여부만 판정하므로, 업무 권한이 없는 제한 관리자도
 * 도달한다. 채널 저장은 코어 `PUT plugins/{id}/settings`(core.plugins.update)와 같은
 * `plugin_settings` 를 덮어쓰는 우회 경로이므로 동일 권한으로 게이트되어야 한다.
 */
class MarketingAdminRoutePermissionTest extends PluginTestCase
{
    /**
     * 기본 채널 목록
     */
    private const EXISTING_CHANNELS = [
        [
            'key' => 'email_subscription',
            'label' => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
            'page_slug' => '',
            'enabled' => true,
            'is_system' => true,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, string $key, mixed $default = null) => match ($key) {
                'channels' => json_encode(self::EXISTING_CHANNELS),
                default => $default,
            }
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 채널 저장 라우트에 플러그인 설정 권한이 선언되어 있는지 확인합니다.
     *
     * @return void
     */
    public function test_channels_update_route_declares_plugin_settings_permission(): void
    {
        // 이름 조회표는 앱 부팅 시점에 한 번 갱신된다. 테스트는 부팅 이후(setUp)에 라우트를
        // 등록하므로 그 표에 반영되지 않는다 — 조회 전에 명시적으로 다시 만든다.
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('api.plugins.sirsoft-marketing.admin.channels.update');

        $this->assertNotNull($route, '채널 저장 라우트가 존재해야 합니다.');
        $this->assertContains(
            'permission:admin,core.plugins.update',
            $route->gatherMiddleware(),
            '채널 저장 라우트는 core.plugins.update 권한을 요구해야 합니다.'
        );
    }

    /**
     * 업무 권한이 없는 관리자는 채널 저장에서 403 을 받아야 합니다.
     *
     * @return void
     */
    public function test_admin_without_plugin_settings_permission_cannot_update_channels(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->putJson('/api/plugins/sirsoft-marketing/admin/channels', ['channels' => self::EXISTING_CHANNELS])
            ->assertForbidden();
    }

    /**
     * 플러그인 설정 권한 보유 관리자는 정상 저장할 수 있어야 합니다.
     *
     * @return void
     */
    public function test_admin_with_plugin_settings_permission_can_update_channels(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);

        $this->actingAs($admin)
            ->putJson('/api/plugins/sirsoft-marketing/admin/channels', ['channels' => self::EXISTING_CHANNELS])
            ->assertOk();
    }
}
