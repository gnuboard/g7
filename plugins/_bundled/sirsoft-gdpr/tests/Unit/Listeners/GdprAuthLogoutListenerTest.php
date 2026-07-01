<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Gdpr\Listeners\GdprAuthLogoutListener;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 로그아웃 리스너 단위 테스트
 *
 * 코어 `core.auth.logout` 훅 구독 동작 검증:
 * - 게스트 세션 쿠키(gdpr_session) 폐기 큐잉
 * - v1.5.0 회귀 가드: 클라이언트 사이드 동의 캐시(gdpr_consent) 폐기 로직이 제거되었는지 확인
 */
class GdprAuthLogoutListenerTest extends PluginTestCase
{
    private GdprAuthLogoutListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = new GdprAuthLogoutListener;
    }

    #[Test]
    public function it_subscribes_to_core_auth_logout_hook(): void
    {
        $hooks = GdprAuthLogoutListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.auth.logout', $hooks);
        $this->assertSame('forgetGdprCookies', $hooks['core.auth.logout']['method']);
        $this->assertTrue($hooks['core.auth.logout']['sync']);
    }

    #[Test]
    public function it_queues_gdpr_session_cookie_forget_on_logout(): void
    {
        $user = User::factory()->create();

        $this->listener->forgetGdprCookies($user);

        $queued = Cookie::getQueuedCookies();
        $names = array_map(fn ($cookie) => $cookie->getName(), $queued);

        $this->assertContains('gdpr_session', $names);
    }

    /**
     * v1.5.0 회귀 가드: gdpr_consent 클라이언트 쿠키는 SSoT 가 아니므로 제거되었다.
     * 로그아웃 리스너가 gdpr_consent 를 폐기 시도하지 않아야 한다.
     */
    #[Test]
    public function it_does_not_forget_gdpr_consent_cookie_anymore(): void
    {
        $user = User::factory()->create();

        $this->listener->forgetGdprCookies($user);

        $queued = Cookie::getQueuedCookies();
        $names = array_map(fn ($cookie) => $cookie->getName(), $queued);

        $this->assertNotContains('gdpr_consent', $names);
    }
}
