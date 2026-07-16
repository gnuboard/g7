<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Middleware;

use App\Extension\HookManager;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Sirsoft\Ecommerce\Http\Middleware\DetectDevice;
use Modules\Sirsoft\Ecommerce\Listeners\InjectAppConfigDeviceListener;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

class DetectDeviceTest extends ModuleTestCase
{
    /**
     * @scenario mark_form=badge, requires_ios=true, device=ios
     *
     * @effects server_ua_sets_is_ios, applepay_shown_on_ios
     */
    public function test_ios_user_agent_sets_is_ios_true_in_container(): void
    {
        $request = Request::create('/', 'GET', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        ]);

        (new DetectDevice)->handle($request, fn () => new Response);

        $this->assertTrue(DetectDevice::isIos());
    }

    /**
     * @scenario mark_form=badge, requires_ios=true, device=non_ios
     *
     * @effects server_ua_sets_is_ios, applepay_hidden_on_non_ios
     */
    public function test_desktop_user_agent_sets_is_ios_false_in_container(): void
    {
        $request = Request::create('/', 'GET', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120',
        ]);

        (new DetectDevice)->handle($request, fn () => new Response);

        $this->assertFalse(DetectDevice::isIos());
    }

    public function test_is_ios_defaults_false_when_middleware_not_run(): void
    {
        // 컨테이너 미바인딩(미들웨어 미실행) 시 fail-safe(애플페이 기본 숨김).
        $this->assertFalse(DetectDevice::isIos());
    }

    /**
     * @scenario mark_form=badge, requires_ios=true, device=ipados_desktop_ua
     *
     * @effects is_ios_flows_to_global_appconfig
     */
    public function test_listener_injects_is_ios_into_app_config_via_hook(): void
    {
        // iOS 미들웨어 통과 상태 재현.
        $request = Request::create('/', 'GET', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        ]);
        (new DetectDevice)->handle($request, fn () => new Response);

        // 리스너를 훅에 등록(모듈 부팅이 하는 것과 동일).
        HookManager::addFilter(
            'core.frontend.filter_app_config',
            [new InjectAppConfigDeviceListener, 'injectDeviceFlags'],
            20
        );

        $appConfig = app(SettingsService::class)->getAppConfigForFrontend();

        $this->assertArrayHasKey('isIos', $appConfig);
        $this->assertTrue($appConfig['isIos']);
    }
}
