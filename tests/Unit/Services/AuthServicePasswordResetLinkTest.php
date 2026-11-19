<?php

namespace Tests\Unit\Services;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserConsentRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\AuthService;
use App\Services\IdentityPolicyService;
use App\Services\PasswordChangeChallengeService;
use App\Services\PasswordChangeService;
use App\Services\PasswordExpiryService;
use App\Services\PasswordResetService;
use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * 비밀번호 재설정 링크는 사이트 주소 헬퍼가 만든다 — 7.2.0 부터 화면 주소 헬퍼(`ScreenUrl::absolute()`)가
 * 그 자리를 맡고, 절대 주소의 본체는 `SiteUrl`(`AssetUrl::siteAbsolute()` 의 위임 대상)이다.
 *
 * `config('app.url').$path` 는 `APP_URL` 의 후행 슬래시를 그대로 실어 `…/g7//reset-password` 를
 * 만든다 — 메일의 링크가 조용히 깨지고, 사용자는 재설정을 마칠 수 없다.
 */
class AuthServicePasswordResetLinkTest extends TestCase
{
    private const HOOK = 'core.auth.after_reset_password_request';

    /**
     * 재설정 훅을 비우고 app.url 을 후행 슬래시가 붙은 하위 폴더 주소로, 요청은 다른 호스트로 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::clearAction(self::HOOK);
        Config::set('app.url', 'https://example.com/g7/');
        app()->instance('request', Request::create('http://localhost/api/x'));
    }

    /**
     * 재설정 훅과 강제 접두사를 걷어 다른 테스트에 남지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearAction(self::HOOK);
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * 사용자와 관리자 비밀번호 재설정 링크가 요청 호스트가 아니라 설정 주소를 따르고 이중 슬래시 없이 각 재설정 경로로 이어지는지 확인한다.
     *
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects notification_links_are_built_by_the_site_absolute_helper
     *
     * @return void 반환값 없음
     */
    public function test_reset_url_follows_the_configured_site_url_without_double_slash(): void
    {
        // 관리자 재설정 화면 주소는 관리자 계정에만 보낸다(7.1.6) — 두 경로를 다 재려면 관리자 계정이어야 한다
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['name' => '홍길동', 'email' => 'hong@example.com']);
        $user->shouldReceive('isAdmin')->andReturn(true);

        $users = Mockery::mock(UserRepositoryInterface::class);
        $users->shouldReceive('findByEmail')->with('hong@example.com')->andReturn($user);

        $tokens = Mockery::mock(PasswordResetTokenRepositoryInterface::class);
        // 재요청 대기(7.1.6) 판정용 직전 발송 기록 — 없으면 바로 보낸다
        $tokens->shouldReceive('findByEmail')->with('hong@example.com')->andReturn(null);
        $tokens->shouldReceive('updateOrCreateByEmail')->twice();

        // 재설정 훅은 응답 뒤로 미뤄 발화한다(7.1.6) — 시험에서는 바로 돌린다
        $this->withoutDefer();

        $service = new AuthService(
            $users,
            Mockery::mock(RoleRepositoryInterface::class),
            Mockery::mock(UserConsentRepositoryInterface::class),
            $tokens,
            Mockery::mock(IdentityPolicyService::class),
            Mockery::mock(IdentityVerificationLogRepositoryInterface::class),
            Mockery::mock(PasswordChangeService::class),
            Mockery::mock(PasswordExpiryService::class),
            Mockery::mock(PasswordChangeChallengeService::class),
            Mockery::mock(PasswordResetService::class),
        );

        $captured = [];
        HookManager::addAction(self::HOOK, function ($u, array $extra) use (&$captured): void {
            $captured[] = $extra['reset_url'] ?? null;
        });

        $service->forgotPassword('hong@example.com');
        $service->forgotPassword('hong@example.com', 'admin');

        $this->assertCount(2, $captured);
        $this->assertStringStartsWith('https://example.com/g7/reset-password?token=', $captured[0]);
        $this->assertStringStartsWith('https://example.com/g7/admin/reset-password?token=', $captured[1]);
        foreach ($captured as $url) {
            $this->assertStringNotContainsString('g7//', $url);
        }
    }
}
