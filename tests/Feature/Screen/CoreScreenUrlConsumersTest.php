<?php

namespace Tests\Feature\Screen;

use App\Extension\HookManager;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Http\Controllers\Api\Identity\IdentityVerificationController;
use App\Listeners\CoreNotificationDataListener;
use App\Listeners\CorePrivacyNotificationDataListener;
use App\Listeners\SeoNotificationDataListener;
use App\Models\User;
use App\Seo\SitemapGenerator;
use App\Services\AuthService;
use App\Services\IdentityMessageDispatcher;
use App\Support\ScreenUrl;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * 코어가 만드는 화면 주소가 화면 주소 헬퍼를 거치는지 — 알림·비밀번호 재설정·메일 본인인증·본인인증 복귀·관리자 링크
 * (#792 WP-U2 — F1-1·F1-2·F1-3·F1-19·F1-20)
 */
class CoreScreenUrlConsumersTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 사이트 주소와 사용자 화면 URL 을 다르게 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://site.example.test',
            'g7_settings.core.general.user_screen_url' => 'https://front.example.test',
        ]);
    }

    /**
     * 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(ScreenUrl::FILTER);
        HookManager::clearAction('core.auth.after_reset_password_request');

        parent::tearDown();
    }

    /**
     * 회원 대상 코어 알림 링크는 사용자 화면 URL 과 주소 필터를 따르고, 관리자 대상은 사이트 주소입니다.
     *
     * @scenario case=core_notifications
     *
     * @effects core_notification_urls_use_helper, admin_links_keep_site_url
     *
     * @return void 반환값 없음
     */
    public function test_core_notification_links(): void
    {
        $listener = app(CoreNotificationDataListener::class);
        $member = User::factory()->create();

        $this->assertSame('https://front.example.test/login', $listener->extractData([], 'welcome', [$member])['data']['action_url']);
        $this->assertSame('https://front.example.test', $listener->extractData([], 'welcome', [$member])['data']['site_url']);
        $this->assertSame('https://front.example.test/login', $listener->extractData([], 'password_changed', [$member])['data']['action_url']);
        $this->assertSame('https://front.example.test/reset-password', $listener->extractData([], 'reset_password', [$member, []])['data']['action_url']);
        $this->assertSame('https://front.example.test/mypage/change-password', $listener->extractData([], 'password_expiry_notice', [$member, []])['data']['action_url']);

        HookManager::addFilter(ScreenUrl::FILTER, fn (string $path, string $key) => $key === 'auth.login' ? '/signin' : $path);
        $this->assertSame('https://front.example.test/signin', $listener->extractData([], 'password_expired', [$member, []])['data']['action_url']);

        $privacy = app(CorePrivacyNotificationDataListener::class);
        $this->assertSame('https://front.example.test/signin', $this->firstActionUrl($privacy, $member));

        $admin = Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('isAdmin')->andReturn(true);
        $admin->uuid = 'u-792';
        $admin->name = 'admin';
        $admin->id = 1;
        $this->assertSame('https://site.example.test/admin/login', $listener->extractData([], 'password_expired', [$admin, []])['data']['action_url']);
        $this->assertSame('https://site.example.test/admin/users/u-792/edit', $listener->extractData([], 'password_expiry_notice', [$admin, []])['data']['action_url']);

        $seo = app(SeoNotificationDataListener::class);
        $seoData = $this->firstActionUrl($seo, $member);
        if ($seoData !== null) {
            $this->assertStringStartsWith('https://site.example.test/admin/settings', $seoData);
        }
    }

    /**
     * 비밀번호 재설정 메일 링크는 사용자 화면 URL 의 재설정 화면이고 토큰·이메일을 싣습니다. 관리자 재설정은 사이트 주소입니다.
     *
     * @scenario case=reset_password
     *
     * @effects reset_password_link_uses_user_screen_url
     *
     * @return void 반환값 없음
     */
    public function test_reset_password_link(): void
    {
        $user = User::factory()->create(['email' => 'reset792_'.uniqid().'@example.com']);
        $captured = [];
        HookManager::addAction('core.auth.after_reset_password_request', function ($u, array $extra) use (&$captured) {
            $captured[] = $extra['reset_url'] ?? null;
        });

        $service = app(AuthService::class);
        $service->forgotPassword($user->email);
        $service->forgotPassword($user->email, 'admin');

        $this->assertStringStartsWith('https://front.example.test/reset-password?token=', (string) $captured[0]);
        $this->assertStringContainsString('email='.rawurlencode($user->email), (string) $captured[0]);
        $this->assertStringStartsWith('https://site.example.test/admin/reset-password?token=', (string) $captured[1]);
    }

    /**
     * 메일 본인인증 링크는 화면 주소 헬퍼(identity.verify)를 거칩니다.
     *
     * @scenario case=mail_identity
     *
     * @effects mail_identity_link_uses_helper
     *
     * @return void 반환값 없음
     */
    public function test_mail_identity_link(): void
    {
        $provider = app(MailIdentityProvider::class);
        $method = new \ReflectionMethod($provider, 'buildSignedLink');

        $link = $method->invoke($provider, 'ch-1', 'tok+/', now()->addMinutes(10));

        $this->assertSame('https://front.example.test/identity/verify?challenge=ch-1&token=tok%2B%2F', $link);
    }

    /**
     * 메일 본인인증 메일의 {site_url} 은 회원이 여는 화면 주소(「사용자 화면 URL」)입니다 (#792).
     *
     * @scenario case=mail_identity
     *
     * @effects mail_identity_site_url_user_screen
     *
     * @return void 반환값 없음
     */
    public function test_mail_identity_site_url(): void
    {
        $captured = null;
        $dispatcher = Mockery::mock(IdentityMessageDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (string $providerId, string $purpose, ?string $policyKey, string $renderHint, string $channel, string $target, array $data) use (&$captured) {
            $captured = $data;

            return true;
        });
        $this->app->instance(IdentityMessageDispatcher::class, $dispatcher);

        $provider = app(MailIdentityProvider::class);
        (new \ReflectionMethod($provider, 'dispatchMessage'))->invoke($provider, 'm@example.test', 'signup', 'text_code', 'ch-1', null, '123456', null, 10, now()->addMinutes(10));

        $this->assertSame('https://front.example.test', $captured['site_url'] ?? null);
    }

    /**
     * 본인인증 복귀 주소는 사이트·사용자 화면 URL 출처를 허용하고 제3 출처는 거부합니다. 상대 경로는 사용자 화면 베이스로 절대화합니다.
     *
     * @scenario case=identity_return
     *
     * @effects identity_return_allows_user_screen_origin, third_party_return_still_rejected
     *
     * @return void 반환값 없음
     */
    public function test_identity_return_origins(): void
    {
        $controller = app(IdentityVerificationController::class);
        $safe = new \ReflectionMethod($controller, 'isSafeReturnUrl');
        $absolute = new \ReflectionMethod($controller, 'absoluteReturnUrl');

        $this->assertTrue($safe->invoke($controller, 'https://site.example.test/register'));
        $this->assertTrue($safe->invoke($controller, 'https://front.example.test/signup/verify'));
        $this->assertTrue($safe->invoke($controller, '/register'));
        $this->assertFalse($safe->invoke($controller, 'https://evil.example.test/steal'));
        $this->assertFalse($safe->invoke($controller, 'https://front.example.test\\@evil.example.test/'));
        $this->assertFalse($safe->invoke($controller, 'https://user@front.example.test/'));

        $this->assertSame('https://front.example.test/register?x=1', $absolute->invoke($controller, '/register?x=1'));
    }

    /**
     * 사이트맵의 기여자 경로는 사용자 화면 URL 로 절대화되고, 이미 절대 주소면 그대로입니다 (W17).
     *
     * @scenario case=sitemap
     *
     * @effects sitemap_loc_uses_user_screen_url
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_loc_uses_user_screen_url(): void
    {
        $generator = app(SitemapGenerator::class);
        $normalize = new \ReflectionMethod($generator, 'normalizeEntry');

        $this->assertSame('https://front.example.test/board/free', $normalize->invoke($generator, ['url' => '/board/free'])['loc']);
        $this->assertSame('https://cdn.example.test/x', $normalize->invoke($generator, ['url' => 'https://cdn.example.test/x'])['loc']);
    }

    /**
     * 알림 리스너의 첫 유형에서 action_url 을 뽑습니다(유형 이름에 의존하지 않는 확인용).
     *
     * @param  object  $listener  알림 데이터 리스너
     * @param  User  $user  대상 회원
     * @return string|null 첫 알림 링크 주소
     */
    private function firstActionUrl(object $listener, User $user): ?string
    {
        $reflection = new \ReflectionClass($listener);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PRIVATE) as $method) {
            if (! str_starts_with($method->getName(), 'extract') || $method->getNumberOfParameters() !== 1) {
                continue;
            }
            $result = $method->invoke($listener, [$user, []]);
            $url = $result['data']['action_url'] ?? null;
            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }
}
