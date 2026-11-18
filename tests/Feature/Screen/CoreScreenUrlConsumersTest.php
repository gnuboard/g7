<?php

namespace Tests\Feature\Screen;

use App\Extension\HookManager;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Http\Controllers\Api\Identity\IdentityVerificationController;
use App\Listeners\CoreNotificationDataListener;
use App\Listeners\CorePrivacyNotificationDataListener;
use App\Listeners\SeoNotificationDataListener;
use App\Models\IdentityPolicy;
use App\Models\Role;
use App\Models\User;
use App\Seo\SitemapGenerator;
use App\Services\AuthService;
use App\Services\IdentityMessageDispatcher;
use App\Support\ScreenUrl;
use Database\Seeders\RolePermissionSeeder;
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

        // 관리자 대상 메일의 사이트 링크({site_url})는 사이트 주소다 — 비밀번호 변경 완료 메일도 만료 메일과 같은 판정을 따른다
        $this->assertSame('https://front.example.test', $listener->extractData([], 'password_changed', [$member])['data']['site_url']);
        $adminChanged = $listener->extractData([], 'password_changed', [$admin])['data'];
        $this->assertSame('https://site.example.test/admin/login', $adminChanged['action_url']);
        $this->assertSame('https://site.example.test', $adminChanged['site_url']);
        $this->assertSame('https://site.example.test', $listener->extractData([], 'password_expired', [$admin, []])['data']['site_url']);
        $this->assertSame('https://site.example.test', $listener->extractData([], 'password_expiry_notice', [$admin, []])['data']['site_url']);

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
        // 7.1.6 의 재설정 요청 규칙: 관리자 화면 주소는 관리자 계정에만 보내고, 같은 주소의 재요청은 대기 시간 안이면 조용히 끝나며,
        // 훅은 응답 뒤로 미뤄 발화한다. 한 회원으로 두 갈래를 잇달아 재므로 관리자 계정·대기 0·즉시 발화로 세운다.
        $this->seed(RolePermissionSeeder::class);
        config(['auth.passwords.users.throttle' => 0]);
        $this->withoutDefer();

        $user = User::factory()->create(['email' => 'reset792_'.uniqid().'@example.com']);
        $user->roles()->attach(Role::where('identifier', 'admin')->value('id'), ['assigned_at' => now(), 'assigned_by' => null]);
        $user = $user->fresh();
        $captured = [];
        $extras = [];
        HookManager::addAction('core.auth.after_reset_password_request', function ($u, array $extra) use (&$captured, &$extras) {
            $captured[] = $extra['reset_url'] ?? null;
            $extras[] = $extra;
        });

        $service = app(AuthService::class);
        $service->forgotPassword($user->email);
        $service->forgotPassword($user->email, 'admin');

        $this->assertStringStartsWith('https://front.example.test/reset-password?token=', (string) $captured[0]);
        $this->assertStringContainsString('email='.rawurlencode($user->email), (string) $captured[0]);
        $this->assertStringStartsWith('https://site.example.test/admin/reset-password?token=', (string) $captured[1]);

        // 메일 아래쪽 사이트 링크({site_url})도 재설정 화면과 같은 쪽 — 회원 재설정은 사용자 화면 URL, 관리자 재설정은 사이트 주소
        $listener = app(CoreNotificationDataListener::class);
        $memberMail = $listener->extractData([], 'reset_password', [$user, $extras[0]])['data'];
        $adminMail = $listener->extractData([], 'reset_password', [$user, $extras[1]])['data'];
        $this->assertSame($captured[0], $memberMail['action_url']);
        $this->assertSame('https://front.example.test', $memberMail['site_url']);
        $this->assertSame($captured[1], $adminMail['action_url']);
        $this->assertSame('https://site.example.test', $adminMail['site_url']);
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
     * 메일 본인인증 메일의 {site_url} 은 회원이 여는 화면 주소(「사용자 화면 URL」)입니다 (#792). 관리자에게만 적용되는 정책의 메일은 사이트 주소입니다.
     *
     * @scenario case=mail_identity
     *
     * @effects mail_identity_site_url_user_screen
     *
     * @return void 반환값 없음
     */
    public function test_mail_identity_site_url(): void
    {
        $captured = [];
        $dispatcher = Mockery::mock(IdentityMessageDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->times(4)->andReturnUsing(function (string $providerId, string $purpose, ?string $policyKey, string $renderHint, string $channel, string $target, array $data) use (&$captured) {
            $captured[$policyKey ?? 'none'] = $data;

            return true;
        });
        $this->app->instance(IdentityMessageDispatcher::class, $dispatcher);

        // 관리자에게만 적용되는 정책(관리자 화면의 민감 작업) · 회원·관리자 공통 정책 · 회원 전용 정책
        $suffix = uniqid();
        foreach (['admin', 'both', 'self'] as $appliesTo) {
            IdentityPolicy::create([
                'key' => "test.mail_site_url.{$appliesTo}.{$suffix}",
                'scope' => 'route',
                'target' => "api.test.mail_site_url.{$appliesTo}",
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => true,
                'priority' => 100,
                'source_type' => 'admin',
                'source_identifier' => 'admin',
                'applies_to' => $appliesTo,
                'fail_mode' => 'block',
            ]);
        }

        $provider = app(MailIdentityProvider::class);
        $dispatch = new \ReflectionMethod($provider, 'dispatchMessage');
        $dispatch->invoke($provider, 'm@example.test', 'signup', 'text_code', 'ch-1', null, '123456', null, 10, now()->addMinutes(10));
        foreach (['admin', 'both', 'self'] as $appliesTo) {
            $dispatch->invoke($provider, 'm@example.test', 'sensitive_action', 'text_code', 'ch-'.$appliesTo, "test.mail_site_url.{$appliesTo}.{$suffix}", '123456', null, 10, now()->addMinutes(10));
        }

        $this->assertSame('https://front.example.test', $captured['none']['site_url'] ?? null, '정책 없는 메일은 사용자 화면 URL');
        $this->assertSame('https://site.example.test', $captured["test.mail_site_url.admin.{$suffix}"]['site_url'] ?? null, '관리자 전용 정책의 메일은 사이트 주소');
        $this->assertSame('https://front.example.test', $captured["test.mail_site_url.both.{$suffix}"]['site_url'] ?? null, '회원·관리자 공통 정책은 종전대로 사용자 화면 URL');
        $this->assertSame('https://front.example.test', $captured["test.mail_site_url.self.{$suffix}"]['site_url'] ?? null, '회원 전용 정책은 사용자 화면 URL');
    }

    /**
     * 본인인증 복귀 주소는 사이트·사용자 화면 URL 출처를 허용하고 제3 출처는 거부합니다. 상대 경로는 사용자 화면 베이스로 절대화합니다.
     *
     * @scenario case=identity_return
     *
     * @effects identity_return_allows_user_screen_origin, third_party_return_still_rejected, identity_return_admin_path_uses_site_url
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

        // 관리자 화면 경로는 「사용자 화면 URL」 쪽에 없다 — 사이트 주소로 절대화한다(`/administrator` 같은 다른 경로는 해당 없음)
        $this->assertSame('https://site.example.test/admin/users?x=1', $absolute->invoke($controller, '/admin/users?x=1'));
        $this->assertSame('https://site.example.test/admin', $absolute->invoke($controller, '/admin'));
        $this->assertSame('https://front.example.test/administrator', $absolute->invoke($controller, '/administrator'));
    }

    /**
     * 관리자 화면에서 시작한 메일 본인인증(`scope=admin`)은 확인 링크와 사이트 링크가 사이트 주소 기준이고,
     * 회원 화면에서 시작했으면(범위 없음) 「사용자 화면 URL」 기준입니다. 정책 키 없이 관리자 화면에서 시작하는 인증번호 확인
     * (앱 키 재생성 창, 관리자 본인 정보 수정)도 같은 기준이고, 회원 마이페이지 정보 수정은 「사용자 화면 URL」 그대로입니다.
     * 범위 값은 `admin`·`user` 만 받습니다.
     *
     * @scenario case=mail_identity_scope
     *
     * @effects mail_identity_admin_scope_uses_site_url
     *
     * @return void 반환값 없음
     */
    public function test_mail_identity_screen_scope(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $captured = [];
        $dispatcher = Mockery::mock(IdentityMessageDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->times(5)->andReturnUsing(function (string $providerId, string $purpose, ?string $policyKey, string $renderHint, string $channel, string $target, array $data) use (&$captured) {
            $captured[] = $data;

            return true;
        });
        $this->app->instance(IdentityMessageDispatcher::class, $dispatcher);

        // 같은 주소·같은 목적의 재요청은 대기 시간 안이면 429 다(메일 확인 재발송 대기) — 요청마다 다른 주소를 쓴다
        $fresh = static fn (): string => 'scope792_'.str_replace('.', '', uniqid('', true)).'@example.test';
        $email = $fresh();
        $this->postJson('/api/identity/challenges', ['purpose' => 'password_reset', 'target' => ['email' => $email], 'scope' => 'admin'])->assertCreated();
        $this->postJson('/api/identity/challenges', ['purpose' => 'password_reset', 'target' => ['email' => $fresh()]])->assertCreated();

        $this->assertStringStartsWith('https://site.example.test/identity/verify?challenge=', (string) ($captured[0]['action_url'] ?? ''), '관리자 화면에서 시작한 확인 링크는 사이트 자체의 확인 화면');
        $this->assertSame('https://site.example.test', $captured[0]['site_url'] ?? null);
        $this->assertStringStartsWith('https://front.example.test/identity/verify?challenge=', (string) ($captured[1]['action_url'] ?? ''), '회원 화면에서 시작했으면 사용자 화면 URL');
        $this->assertSame('https://front.example.test', $captured[1]['site_url'] ?? null);

        // 정책 키 없이 관리자 화면에서 시작하는 인증번호 확인(앱 키 재생성 창, 관리자 본인 정보 수정)이 보내는 본문 —
        // 화면이 싣는 범위로 가른다. 서버는 `return_path` 로 관리자 여부를 짐작하지 않는다(범위 없는 `/admin` 경로는 회원 기준).
        $this->postJson('/api/identity/challenges', ['purpose' => 'sensitive_action', 'target' => ['email' => $email], 'return_path' => '/admin/settings?tab=security&reauth=app_key', 'scope' => 'admin'])->assertCreated();
        $this->postJson('/api/identity/challenges', ['purpose' => 'sensitive_action', 'target' => ['email' => $fresh()], 'return_path' => '/admin/users/1/edit', 'scope' => 'admin'])->assertCreated();
        // 대조: 회원 마이페이지 정보 수정(비밀번호 없는 회원)의 본문은 범위가 없어 「사용자 화면 URL」 그대로
        $this->postJson('/api/identity/challenges', ['purpose' => 'sensitive_action', 'target' => ['email' => $fresh()], 'return_path' => '/mypage/profile/edit'])->assertCreated();

        $this->assertSame('https://site.example.test', $captured[2]['site_url'] ?? null, '앱 키 재생성 창의 인증번호 메일은 사이트 주소');
        $this->assertSame('https://site.example.test', $captured[3]['site_url'] ?? null, '관리자 본인 정보 수정의 인증번호 메일은 사이트 주소');
        $this->assertSame('https://front.example.test', $captured[4]['site_url'] ?? null, '회원 마이페이지 정보 수정의 인증번호 메일은 사용자 화면 URL');

        // 정해진 값 밖의 범위는 받지 않는다(메일을 보내지 않음)
        $this->postJson('/api/identity/challenges', ['purpose' => 'password_reset', 'target' => ['email' => $email], 'scope' => 'other'])->assertStatus(422);
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
