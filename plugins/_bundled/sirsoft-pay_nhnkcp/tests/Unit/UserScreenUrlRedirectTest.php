<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit;

use App\Extension\HookManager;
use App\Support\ScreenUrl;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;
use Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCallbackController;

/**
 * NHN KCP — 결제 뒤 복귀 주소
 *
 * 결제 성공·실패 뒤 복귀가 「사용자 화면 URL」을 베이스로 쓰는지 잠근다.
 */
class UserScreenUrlRedirectTest extends PluginTestCase
{
    /**
     * 사이트 주소와 「사용자 화면 URL」을 다르게 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://site.example.test', 'g7_settings.core.general.user_screen_url' => 'https://front.example.test']);
    }

    /**
     * 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(ScreenUrl::FILTER);

        parent::tearDown();
    }

    /**
     * 결제 성공·실패 뒤 돌아가는 주소는 사용자 화면 URL 이 베이스입니다 (콜백·웹훅 주소는 그대로 사이트 주소).
     *
     * @scenario case=pg_redirect
     *
     * @effects pg_redirect_uses_user_screen_url
     *
     * @return void 반환값 없음
     */
    public function test_success_and_fail_redirects_use_user_screen_url(): void
    {
        $controller = app(PaymentCallbackController::class);
        $success = (new \ReflectionMethod($controller, 'resolveSuccessUrl'))->invoke($controller, 'O-792');
        $fail = (new \ReflectionMethod($controller, 'resolveFailUrl'))->invoke($controller, ['code' => 'X']);

        $this->assertStringStartsWith('https://front.example.test/', $success);
        $this->assertStringContainsString('O-792', $success);
        $this->assertStringStartsWith('https://front.example.test/', $fail);
    }
}
