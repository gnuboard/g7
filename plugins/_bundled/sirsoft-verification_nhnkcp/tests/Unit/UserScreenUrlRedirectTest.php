<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Unit;

use App\Extension\HookManager;
use App\Support\ScreenUrl;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;
use Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers\KcpBridgeController;

/**
 * NHN KCP 본인인증 — 브리지 대체 복귀 주소 (#792 WP-U5)
 *
 * 모바일 브리지가 복귀 주소를 못 찾을 때 가는 홈이 화면 주소 헬퍼(home)를 거치는지 잠근다.
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
     * 복귀 주소가 없을 때 갈 홈은 화면 주소 헬퍼(home)를 거칩니다 — 기본 `/`, 필터를 걸면 그 주소.
     *
     * @scenario case=bridge_home
     *
     * @effects bridge_fallback_uses_home_helper
     *
     * @return void 반환값 없음
     */
    public function test_bridge_fallback_uses_home_helper(): void
    {
        $controller = app(KcpBridgeController::class);
        $render = new \ReflectionMethod($controller, 'renderBridgeHtml');

        $this->assertStringContainsString('var HOME_URL = "\/" || ', $render->invoke($controller, '{}'));

        HookManager::addFilter(ScreenUrl::FILTER, fn (string $path, string $key) => $key === 'home' ? 'https://front.example.test/' : $path);
        $html = $render->invoke($controller, '{}');
        $this->assertStringContainsString('var HOME_URL = "https:\/\/front.example.test\/" || ', $html);
        $this->assertStringContainsString('toSafeReturnPath(stash.return_url)) || HOME_URL;', $html);
    }
}
