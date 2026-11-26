<?php

namespace Tests\Unit\Support;

use App\Extension\HookManager;
use App\Support\ScreenUrl;
use Tests\TestCase;

/**
 * 화면 주소 헬퍼(ScreenUrl) 단위 테스트
 *
 * 자리 치환·인코딩, 필터 훅(경로·키·파라미터·범위 인자), 범위별 절대화 베이스(사용자 = 사용자 화면 URL, 관리자 = 사이트
 * URL), 필터가 돌려준 절대 URL 보존, 같은 출처 판정을 잠근다.
 */
class ScreenUrlTest extends TestCase
{
    /**
     * 사이트·사용자 화면 URL 을 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://site.example.test', 'g7_settings.core.general.user_screen_url' => '']);
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
     * 자리를 URL 인코딩해 채우고, 값이 없으면 예외입니다.
     *
     * @scenario case=fill
     *
     * @effects placeholders_filled_encoded, missing_placeholder_throws
     *
     * @return void 반환값 없음
     */
    public function test_fills_placeholders_and_rejects_missing(): void
    {
        $this->assertSame('/board/%ED%95%9C%EA%B8%80/12', ScreenUrl::path('board.post', '/board/{slug}/{id}', ['slug' => '한글', 'id' => 12]));
        $this->assertSame('/reset-password?token=a%2Bb&email=x%40y.z', ScreenUrl::path('auth.reset_password', '/reset-password?token={token}&email={email}', ['token' => 'a+b', 'email' => 'x@y.z']));

        $this->expectException(\InvalidArgumentException::class);
        ScreenUrl::path('board.post', '/board/{slug}/{id}', ['slug' => 'free']);
    }

    /**
     * 필터는 (경로, 키, 파라미터, 범위)를 받고 그 결과가 쓰입니다. 절대 URL 결과는 그대로 둡니다.
     *
     * @scenario case=filter
     *
     * @effects filter_receives_args, filter_absolute_kept
     *
     * @return void 반환값 없음
     */
    public function test_filter_receives_arguments_and_absolute_is_kept(): void
    {
        $seen = null;
        HookManager::addFilter(ScreenUrl::FILTER, function (string $path, string $key, array $params, string $scope) use (&$seen) {
            $seen = [$path, $key, $params, $scope];

            return $key === 'board.post' ? 'https://front.example.test/community/'.$params['slug'].'/'.$params['id'] : $path;
        });

        $this->assertSame('https://front.example.test/community/free/7', ScreenUrl::absolute('board.post', '/board/{slug}/{id}', ['slug' => 'free', 'id' => 7]));
        $this->assertSame(['/board/free/7', 'board.post', ['slug' => 'free', 'id' => 7], 'user'], $seen);
    }

    /**
     * 사용자 범위는 사용자 화면 URL(비면 사이트 URL), 관리자 범위는 늘 사이트 URL 을 베이스로 씁니다.
     *
     * @scenario case=absolute
     *
     * @effects user_scope_uses_user_screen_url, admin_scope_uses_site_url
     *
     * @return void 반환값 없음
     */
    public function test_absolute_base_by_scope(): void
    {
        $this->assertSame('https://site.example.test/login', ScreenUrl::absolute('auth.login', '/login'));

        config(['g7_settings.core.general.user_screen_url' => 'https://front.example.test/']);
        $this->assertSame('https://front.example.test/login', ScreenUrl::absolute('auth.login', '/login'));
        $this->assertSame('https://front.example.test/', ScreenUrl::absolute('home', '/'));
        $this->assertSame('https://site.example.test/admin/login', ScreenUrl::absolute('admin.login', '/admin/login', [], ScreenUrl::SCOPE_ADMIN));
        $this->assertSame('https://front.example.test/mypage/orders?x=1', ScreenUrl::toAbsolute('/mypage/orders?x=1'));
    }

    /**
     * 베이스가 하위 폴더이고 경로가 그 접두사를 이미 실었으면 다시 붙이지 않습니다 (SiteUrl 과 같은 멱등 규칙).
     *
     * @scenario case=absolute
     *
     * @effects user_scope_uses_user_screen_url, admin_scope_uses_site_url
     *
     * @return void 반환값 없음
     */
    public function test_absolute_does_not_repeat_mount_prefix(): void
    {
        config(['app.url' => 'https://site.example.test/g7']);

        $this->assertSame('https://site.example.test/g7/login', ScreenUrl::absolute('auth.login', '/login'));
        $this->assertSame('https://site.example.test/g7/login', ScreenUrl::toAbsolute('/g7/login'));
        $this->assertSame('https://site.example.test/g7?ref=x', ScreenUrl::toAbsolute('/g7?ref=x'));
        // 운영자가 접두사를 이미 넣은 결제 복귀 경로 — `/g7/g7/shop/…` 가 되지 않는다
        $this->assertSame('https://site.example.test/g7/shop/orders/1?result=ok', ScreenUrl::toAbsolute('/g7/shop/orders/1?result=ok'));
        $this->assertSame('https://site.example.test/g7/shop/orders/1', ScreenUrl::absolute('shop.order', '/g7/shop/orders/{id}', ['id' => 1]));
        $this->assertSame('https://site.example.test/g7/g7x', ScreenUrl::toAbsolute('/g7x'));
        $this->assertSame('https://site.example.test/g7/', ScreenUrl::toAbsolute('/'));
        $this->assertSame('https://site.example.test/g7/admin/login', ScreenUrl::toAbsolute('/g7/admin/login', ScreenUrl::SCOPE_ADMIN));

        config(['g7_settings.core.general.user_screen_url' => 'https://front.example.test/app/']);
        $this->assertSame('https://front.example.test/app/mypage', ScreenUrl::toAbsolute('/app/mypage'));
        $this->assertSame('https://front.example.test/app/mypage', ScreenUrl::toAbsolute('/mypage'));
    }

    /**
     * 같은 출처 판정 — 기본 포트는 같게 보고 다른 호스트·포트·스킴은 다르게 봅니다.
     *
     * @scenario case=origin
     *
     * @effects user_screen_origin_matched
     *
     * @return void 반환값 없음
     */
    public function test_is_user_screen_origin(): void
    {
        config(['g7_settings.core.general.user_screen_url' => 'https://front.example.test']);

        $this->assertTrue(ScreenUrl::isUserScreenOrigin('https://front.example.test:443/after-verify?x=1'));
        $this->assertFalse(ScreenUrl::isUserScreenOrigin('http://front.example.test/after'));
        $this->assertFalse(ScreenUrl::isUserScreenOrigin('https://evil.example.test/after'));
        $this->assertFalse(ScreenUrl::isUserScreenOrigin('/relative'));
    }
}
