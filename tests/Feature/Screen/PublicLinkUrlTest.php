<?php

namespace Tests\Feature\Screen;

use App\Extension\HookManager;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Http\Controllers\Api\Identity\IdentityVerificationController;
use App\Listeners\CoreNotificationDataListener;
use App\Listeners\SeoNotificationDataListener;
use App\Models\User;
use App\Support\ScreenUrl;
use App\Support\SiteUrl;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * 밖으로 나가는 링크의 주소(`G7_PUBLIC_LINK_URL` → `core.public_link_url`) 테스트 (#888)
 *
 * 같은 DB 를 쓰는 서버를 공개 주소와 다른 주소로 띄울 때, 그 서버가 보내는 메일·알림의 회원용·관리자용 링크는
 * 공개 주소를 가리키고, 이 서버가 받아야 하는 주소(결제·본인인증 콜백, 정식 주소 이동)와 브라우저가 돌아가는 주소
 * (본인인증 복귀 주소·복귀 출처 검사)는 사이트 주소에 남는지 잠근다.
 *
 * @scenario-manifest tests/scenarios/public-link-url.yaml
 */
class PublicLinkUrlTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 사이트 주소를 정하고 사용자 화면 URL·공개 링크 주소를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://site.example.test',
            'g7_settings.core.general.user_screen_url' => '',
            'core.public_link_url' => null,
        ]);
    }

    /**
     * 주소 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(ScreenUrl::FILTER);

        parent::tearDown();
    }

    /**
     * 공개 링크 주소를 정하지 않으면 모든 링크가 지금처럼 사이트 주소입니다.
     *
     * @scenario case=unset
     *
     * @effects unset_keeps_site_url
     *
     * @return void 반환값 없음
     */
    public function test_unset_keeps_site_url(): void
    {
        $this->assertSame('https://site.example.test', SiteUrl::forOutboundLinks());
        $this->assertSame('https://site.example.test', ScreenUrl::userBase());
        $this->assertSame('https://site.example.test', ScreenUrl::userLinkBase());
        $this->assertSame('https://site.example.test/login', ScreenUrl::absolute('auth.login', '/login'));
        $this->assertSame('https://site.example.test/login', ScreenUrl::absoluteForLinks('auth.login', '/login'));
        $this->assertSame('https://site.example.test/admin/login', ScreenUrl::absoluteForLinks('admin.login', '/admin/login', [], ScreenUrl::SCOPE_ADMIN));

        $listener = app(CoreNotificationDataListener::class);
        $member = User::factory()->create();
        $welcome = $listener->extractData([], 'welcome', [$member])['data'];
        $this->assertSame('https://site.example.test/login', $welcome['action_url']);
        $this->assertSame('https://site.example.test', $welcome['site_url']);

        $adminReset = $listener->extractData([], 'reset_password', [$member, ['redirect_prefix' => 'admin']])['data'];
        $this->assertSame('https://site.example.test/admin/reset-password', $adminReset['action_url']);
        $this->assertSame('https://site.example.test', $adminReset['site_url']);
    }

    /**
     * 공개 링크 주소를 정하면 회원용·관리자용 알림 링크와 사이트 링크가 그 주소입니다(끝 슬래시는 뗍니다).
     *
     * @scenario case=configured
     *
     * @effects member_links_use_public_link_url, admin_links_use_public_link_url
     *
     * @return void 반환값 없음
     */
    public function test_configured_links_use_public_link_url(): void
    {
        config(['core.public_link_url' => 'https://public.example.test/']);

        $this->assertSame('https://public.example.test', SiteUrl::forOutboundLinks());
        $this->assertSame('https://public.example.test', ScreenUrl::userLinkBase());
        $this->assertSame('https://public.example.test/admin/settings', ScreenUrl::toAbsoluteForLinks('/admin/settings', ScreenUrl::SCOPE_ADMIN));
        $this->assertSame('https://public.example.test/mypage', ScreenUrl::toAbsoluteForLinks('/mypage'));
        $this->assertSame('https://public.example.test/login', ScreenUrl::absoluteForLinks('auth.login', '/login'));

        $listener = app(CoreNotificationDataListener::class);
        $member = User::factory()->create();
        $welcome = $listener->extractData([], 'welcome', [$member])['data'];
        $this->assertSame('https://public.example.test/login', $welcome['action_url']);
        $this->assertSame('https://public.example.test', $welcome['site_url']);

        $adminReset = $listener->extractData([], 'reset_password', [$member, ['redirect_prefix' => 'admin']])['data'];
        $this->assertSame('https://public.example.test/admin/reset-password', $adminReset['action_url']);
        $this->assertSame('https://public.example.test', $adminReset['site_url']);

        $admin = Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('isAdmin')->andReturn(true);
        $admin->uuid = 'u-888';
        $admin->name = 'admin';
        $admin->id = 1;
        $expiry = $listener->extractData([], 'password_expiry_notice', [$admin, []])['data'];
        $this->assertSame('https://public.example.test/admin/users/u-888/edit', $expiry['action_url']);
        $this->assertSame('https://public.example.test', $expiry['site_url']);

        $seo = app(SeoNotificationDataListener::class)->extractData([], 'sitemap_regenerated', [['data' => ['url_count' => 3]], 1])['data'];
        $this->assertSame('https://public.example.test/admin/settings?tab=seo', $seo['action_url']);
        $this->assertSame('https://public.example.test', $seo['site_url']);

        $provider = app(MailIdentityProvider::class);
        $resolveSiteUrl = new \ReflectionMethod($provider, 'resolveSiteUrl');
        $this->assertSame('https://public.example.test', $resolveSiteUrl->invoke($provider, null, ScreenUrl::SCOPE_ADMIN));
        $this->assertSame('https://public.example.test', $resolveSiteUrl->invoke($provider, null, ScreenUrl::SCOPE_USER));
    }

    /**
     * 사용자 화면 URL 을 정했으면 회원용 링크는 그 값이 우선하고, 관리자용 링크만 공개 링크 주소를 씁니다.
     *
     * @scenario case=user_screen_url
     *
     * @effects user_screen_url_wins_for_member_links
     *
     * @return void 반환값 없음
     */
    public function test_user_screen_url_wins_for_member_links(): void
    {
        config([
            'core.public_link_url' => 'https://public.example.test',
            'g7_settings.core.general.user_screen_url' => 'https://front.example.test',
        ]);

        $this->assertSame('https://front.example.test', ScreenUrl::userBase());
        $this->assertSame('https://front.example.test', ScreenUrl::userLinkBase());
        $this->assertSame('https://front.example.test/login', ScreenUrl::absoluteForLinks('auth.login', '/login'));
        $this->assertSame('https://public.example.test/admin/login', ScreenUrl::absoluteForLinks('admin.login', '/admin/login', [], ScreenUrl::SCOPE_ADMIN));

        $listener = app(CoreNotificationDataListener::class);
        $member = User::factory()->create();
        $this->assertSame('https://front.example.test', $listener->extractData([], 'welcome', [$member])['data']['site_url']);
        $this->assertSame('https://public.example.test', $listener->extractData([], 'reset_password', [$member, ['redirect_prefix' => 'admin']])['data']['site_url']);
    }

    /**
     * 공개 링크 주소를 정해도 이 서버가 받아야 하는 주소(결제·본인인증 콜백, 정식 주소 이동)와 화면 주소 헬퍼의
     * 기본 베이스(결제 뒤 브라우저 복귀·사이트맵)는 사이트 주소입니다.
     *
     * @scenario case=server_received
     *
     * @effects callbacks_keep_site_url
     *
     * @return void 반환값 없음
     */
    public function test_server_received_addresses_keep_site_url(): void
    {
        config(['core.public_link_url' => 'https://public.example.test']);

        $this->assertSame('https://site.example.test', SiteUrl::base());
        $this->assertSame('https://site.example.test', SiteUrl::origin());
        $this->assertSame('https://site.example.test/plugins/sirsoft-tosspayments/payment/success', SiteUrl::absolute('/plugins/sirsoft-tosspayments/payment/success'));
        $this->assertSame('https://site.example.test/plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/callback', SiteUrl::absolute('/plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/callback'));
        $this->assertSame('https://site.example.test/shop/products?page=2', SiteUrl::forPath('/shop/products?page=2'));
        $this->assertTrue(SiteUrl::isSameOrigin('https://site.example.test/mypage'));
        $this->assertFalse(SiteUrl::isSameOrigin('https://public.example.test/mypage'));
        $this->assertSame('https://site.example.test', ScreenUrl::userBase());
        $this->assertSame('https://site.example.test/shop/orders/O-1/complete', ScreenUrl::toAbsolute('/shop/orders/O-1/complete'));
        $this->assertSame('https://site.example.test/admin/settings', ScreenUrl::toAbsolute('/admin/settings', ScreenUrl::SCOPE_ADMIN));
        $this->assertSame('https://site.example.test/login', ScreenUrl::absolute('auth.login', '/login'));
    }

    /**
     * 공개 링크 주소를 정해도 본인인증 뒤 브라우저가 돌아가는 주소와 복귀 출처 검사는 사이트(사용자 화면) 주소 기준입니다.
     *
     * 복귀 주소는 인증을 시작한 이 서버의 화면으로 돌아가야 한다 — 공개 주소로 보내면 인증 토큰이 다른 서버 화면에
     * 실리고, 공개 주소 출처를 허용하면 허용 출처가 의도 없이 넓어진다.
     *
     * @scenario case=identity_return
     *
     * @effects identity_return_keeps_site_url
     *
     * @return void 반환값 없음
     */
    public function test_identity_return_url_keeps_site_url(): void
    {
        config(['core.public_link_url' => 'https://public.example.test']);

        $controller = app(IdentityVerificationController::class);
        $isSafe = new \ReflectionMethod($controller, 'isSafeReturnUrl');
        $absolute = new \ReflectionMethod($controller, 'absoluteReturnUrl');

        $this->assertSame('https://site.example.test/mypage', $absolute->invoke($controller, '/mypage'));
        $this->assertSame('https://site.example.test/admin/users', $absolute->invoke($controller, '/admin/users'));

        $this->assertTrue(ScreenUrl::isUserScreenOrigin('https://site.example.test/mypage'));
        $this->assertFalse(ScreenUrl::isUserScreenOrigin('https://public.example.test/mypage'));
        $this->assertTrue($isSafe->invoke($controller, 'https://site.example.test/mypage'));
        $this->assertFalse($isSafe->invoke($controller, 'https://public.example.test/mypage'));

        // 「사용자 화면 URL」을 정하면 복귀 출처는 그 주소다(외부 화면으로 돌아가는 복귀) — 공개 링크 주소는 여전히 아니다.
        config(['g7_settings.core.general.user_screen_url' => 'https://front.example.test']);
        $this->assertSame('https://front.example.test/mypage', $absolute->invoke($controller, '/mypage'));
        $this->assertSame('https://site.example.test/admin/users', $absolute->invoke($controller, '/admin/users'));
        $this->assertTrue($isSafe->invoke($controller, 'https://front.example.test/mypage'));
        $this->assertFalse($isSafe->invoke($controller, 'https://public.example.test/mypage'));
    }
}
