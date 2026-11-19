<?php

namespace Tests\Unit\Support;

use App\Seo\SeoUrl;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 사이트 주소 기준 절대 URL 헬퍼가 요청 Host·전달 헤더를 따르지 않는지 검증합니다.
 *
 * 위조 Host 는 URL 생성기에 위조 요청을 심어 만든다. 그 상태에서 Laravel `url()` 이 위조값을
 * 따른다는 것을 각 케이스가 전제로 먼저 확인하므로, `SiteUrl` 이 그것을 따르지 않는다는 것이 곧
 * 조치의 증거다.
 *
 * DB 를 쓰지 않는다.
 */
class SiteUrlTest extends TestCase
{
    /** 테스트 사이트 주소 */
    private const SITE = 'https://shop.example.test';

    /**
     * 사이트 주소를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::SITE]);
    }

    /**
     * 신뢰 프록시 정적 상태와 서버 변수를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        unset($_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_HOST']);

        parent::tearDown();
    }

    /**
     * URL 생성기에 위조 요청을 심습니다.
     *
     * @param  string  $url  위조 요청 주소
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    private function forgeRequest(string $url, array $server = []): void
    {
        $request = Request::create($url, 'GET', [], [], [], $server);
        app()->instance('request', $request);
        app('url')->setRequest($request);
    }

    /**
     * 위조 Host 변종 4종 — 외부 도메인·하위 도메인·포트·신뢰 프록시 전달 헤더.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: bool}> 변종별 요청 주소·서버 변수·프록시 신뢰 여부
     */
    public static function forgedHosts(): array
    {
        return [
            'external' => ['https://attacker.example/', [], false],
            'subdomain' => ['https://attacker.shop.example.test/', [], false],
            'port' => ['https://shop.example.test:6666/', [], false],
            'forwarded' => ['http://10.0.0.5/', [
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_X_FORWARDED_HOST' => 'attacker.example',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ], true],
        ];
    }

    /**
     * 위조 Host 요청 안에서도 절대 URL 은 사이트 주소를 쓴다.
     *
     * @scenario host_header=forged, sink=site_url_helper
     *
     * @effects site_url_ignores_request_host
     *
     * @param  string  $url  위조 요청 주소
     * @param  array<string, string>  $server  추가 서버 변수
     * @param  bool  $trustProxy  요청 직전 호출자를 신뢰 프록시로 둘지
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_absolute_ignores_forged_host(string $url, array $server, bool $trustProxy): void
    {
        if ($trustProxy) {
            Request::setTrustedProxies(['10.0.0.5'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
        }
        $this->forgeRequest($url, $server);

        $this->assertStringStartsNotWith(self::SITE.'/', url('/x'), '전제: Laravel url() 은 위조값을 따른다');
        $this->assertSame(self::SITE.'/shop/checkout?error=1', SiteUrl::absolute('/shop/checkout?error=1'));
        $this->assertSame(self::SITE.'/plugins/a/b', SiteUrl::forPath('/plugins/a/b'));
        $this->assertSame('https://shop.example.test', SiteUrl::origin());
    }

    /**
     * `$_SERVER` 의 Host·전달 헤더를 바꿔도 따르지 않는다 (확장이 쓰던 종전 헬퍼의 회귀 케이스 이관).
     *
     * @scenario host_header=forged, sink=site_url_helper
     *
     * @effects site_url_ignores_request_host
     *
     * @return void 반환값 없음
     */
    public function test_absolute_ignores_server_superglobal_host(): void
    {
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.example.test';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_HOST'] = 'attacker.example.test';

        $this->assertSame(self::SITE.'/shop/checkout', SiteUrl::absolute('/shop/checkout'));
    }

    /**
     * 완전한 URL 은 그대로, 상대 경로는 끝 슬래시를 떼고 쿼리를 보존한다.
     *
     * @scenario host_header=canonical, sink=site_url_helper
     *
     * @effects site_url_absolute_shape
     *
     * @return void 반환값 없음
     */
    public function test_absolute_shape(): void
    {
        $this->assertSame('https://payments.example.test/return', SiteUrl::absolute('https://payments.example.test/return'));
        $this->assertSame(self::SITE.'/a/b', SiteUrl::absolute('a/b/'));
        $this->assertSame(self::SITE.'/a?x=1&y=2', SiteUrl::absolute('/a?x=1&y=2'));
        $this->assertSame(self::SITE, SiteUrl::absolute(''));
        $this->assertSame(self::SITE, SiteUrl::absolute('/'));
    }

    /**
     * 사이트 주소가 비면 요청 Host 로 되돌아가지 않고 상대 경로를 돌려준다.
     *
     * @scenario host_header=forged, sink=site_url_helper
     *
     * @effects site_url_empty_base_stays_relative
     *
     * @return void 반환값 없음
     */
    public function test_empty_site_url_stays_relative(): void
    {
        config(['app.url' => '']);
        $this->forgeRequest('https://attacker.example/');

        $this->assertSame('', SiteUrl::base());
        $this->assertSame('/shop/checkout', SiteUrl::absolute('/shop/checkout'));
        $this->assertSame('/x', SiteUrl::forPath('//x'));
        $this->assertSame('', SiteUrl::origin());
        $this->assertSame('', SiteUrl::hostWithPort());
        $this->assertFalse(SiteUrl::isSameOrigin('https://attacker.example/'));
    }

    /**
     * 요청 경로는 언제나 사이트 주소 안 경로로 붙는다.
     *
     * @scenario host_header=canonical, sink=site_url_helper
     *
     * @effects site_url_for_path_stays_inside_site
     *
     * @return void 반환값 없음
     */
    public function test_for_path_keeps_request_path_inside_site(): void
    {
        $this->assertSame(self::SITE.'/evil.com/x', SiteUrl::forPath('//evil.com/x'));
        $this->assertSame(self::SITE.'/evil.com', SiteUrl::forPath('/\\evil.com'));
        $this->assertSame(self::SITE.'/evil', SiteUrl::forPath("/\t/evil"));
        $this->assertSame(self::SITE.'/https://evil.com', SiteUrl::forPath('https://evil.com'));
        $this->assertSame(self::SITE.'/shop?x=1', SiteUrl::forPath('/shop?x=1'));
        $this->assertSame(self::SITE.'/', SiteUrl::forPath(''));
    }

    /**
     * 하위 폴더 사이트 주소에서 경로가 마운트 접두사를 이미 가지면 다시 붙이지 않는다 (absolute).
     *
     * 서버가 만드는 사이트 경로에는 접두사를 이미 실은 값(`AssetUrl::sitePath()` 결과)과 싣지 않은 값이
     * 섞여 도착한다. 앞의 것에 한 번 더 붙이면 `/g7/g7/…` 가 되어 사이트 밖(404)을 가리킨다. 접두사가
     * 경계 없이 이어진 다른 경로(`/g7x`)는 보유가 아니다.
     *
     * @scenario host_header=canonical, sink=site_url_helper
     *
     * @effects site_url_absolute_shape
     *
     * @return void 반환값 없음
     */
    public function test_absolute_does_not_repeat_mount_prefix(): void
    {
        config(['app.url' => self::SITE.'/g7/']);

        // 접두사 없는 경로 — 사이트 주소(오리진 + 접두사) 뒤에 붙는다
        $this->assertSame(self::SITE.'/g7/login', SiteUrl::absolute('/login'));
        $this->assertSame(self::SITE.'/g7/a/b', SiteUrl::absolute('a/b/'));
        $this->assertSame(self::SITE.'/g7', SiteUrl::absolute('/'));
        $this->assertSame(self::SITE.'/g7?x=1', SiteUrl::absolute('?x=1'));

        // 접두사를 이미 가진 경로 — 오리진 + 입력 경로
        $this->assertSame(self::SITE.'/g7/api/x', SiteUrl::absolute('/g7/api/x'));
        $this->assertSame(self::SITE.'/g7', SiteUrl::absolute('/g7'));
        $this->assertSame(self::SITE.'/g7', SiteUrl::absolute('/g7/'));
        $this->assertSame(self::SITE.'/g7?tab=1', SiteUrl::absolute('/g7?tab=1'));
        $this->assertSame(self::SITE.'/g7/shop?x=1&y=2', SiteUrl::absolute('/g7/shop?x=1&y=2'));
        $this->assertSame(self::SITE.'/g7#top', SiteUrl::absolute('/g7#top'));
        $this->assertSame(self::SITE.'/g7/a', SiteUrl::absolute('g7/a'));

        // 경계 없이 이어진 다른 경로 — 접두사 보유가 아니다
        $this->assertSame(self::SITE.'/g7/g7x/foo', SiteUrl::absolute('/g7x/foo'));

        // 완전한 URL 은 그대로
        $this->assertSame('https://cdn.example.test/g7/x.png', SiteUrl::absolute('https://cdn.example.test/g7/x.png'));

        // 다단 접두사
        config(['app.url' => self::SITE.'/sites/g7']);
        $this->assertSame(self::SITE.'/sites/g7/x', SiteUrl::absolute('/sites/g7/x'));
        $this->assertSame(self::SITE.'/sites/g7/sites/x', SiteUrl::absolute('/sites/x'));
        $this->assertSame('/sites/g7', SiteUrl::basePath());
    }

    /**
     * 루트 설치(사이트 주소에 경로 없음)에서는 접두사 판정이 개입하지 않는다.
     *
     * @scenario host_header=canonical, sink=site_url_helper
     *
     * @effects site_url_absolute_shape
     *
     * @return void 반환값 없음
     */
    public function test_root_install_has_no_mount_prefix(): void
    {
        $this->assertSame('', SiteUrl::basePath());
        $this->assertSame(self::SITE.'/g7/api/x', SiteUrl::absolute('/g7/api/x'));
        $this->assertSame(self::SITE.'/g7/api/x', SiteUrl::forPath('/g7/api/x'));
        $this->assertSame(self::SITE.'/', SiteUrl::forPath('/'));

        config(['app.url' => self::SITE.'/']);
        $this->assertSame('', SiteUrl::basePath());
        $this->assertSame(self::SITE.'/login', SiteUrl::absolute('/login'));
    }

    /**
     * 요청 경로(하위 폴더 설치에서는 접두사를 싣고 온다)도 접두사를 다시 붙이지 않고, 사이트 밖으로 나가지 않는다.
     *
     * @scenario host_header=canonical, sink=site_url_helper
     *
     * @effects site_url_for_path_stays_inside_site
     *
     * @return void 반환값 없음
     */
    public function test_for_path_does_not_repeat_mount_prefix(): void
    {
        config(['app.url' => self::SITE.'/g7']);

        $this->assertSame(self::SITE.'/g7/products/1?page=2', SiteUrl::forPath('/g7/products/1?page=2'));
        $this->assertSame(self::SITE.'/g7', SiteUrl::forPath('/g7'));
        $this->assertSame(self::SITE.'/g7?ref=x', SiteUrl::forPath('/g7?ref=x'));
        $this->assertSame(self::SITE.'/g7/products/1', SiteUrl::forPath('/products/1'));
        $this->assertSame(self::SITE.'/g7/g7x', SiteUrl::forPath('/g7x'));
        $this->assertSame(self::SITE.'/g7/', SiteUrl::forPath(''));

        // `//evil` 방어는 그대로 — 앞 슬래시를 모두 떼고 사이트 주소 안 경로로 붙는다
        $this->assertSame(self::SITE.'/g7/evil.com/x', SiteUrl::forPath('//evil.com/x'));
        $this->assertSame(self::SITE.'/g7/evil.com', SiteUrl::forPath('/\\evil.com'));
        $this->assertSame(self::SITE.'/g7//evil.com', SiteUrl::forPath('/g7//evil.com'));
        $this->assertSame(self::SITE.'/g7/x', SiteUrl::forPath('//g7/x'));
    }

    /**
     * 사이트 주소가 비면 접두사 판정과 무관하게 상대 경로다 (요청 Host 폴백 없음).
     *
     * @scenario host_header=forged, sink=site_url_helper
     *
     * @effects site_url_empty_base_stays_relative
     *
     * @return void 반환값 없음
     */
    public function test_empty_site_url_mount_prefix_stays_relative(): void
    {
        config(['app.url' => '']);
        $this->forgeRequest('https://attacker.example/g7/');

        $this->assertSame('', SiteUrl::basePath());
        $this->assertSame('/g7/api/x', SiteUrl::absolute('/g7/api/x'));
        $this->assertSame('/g7/api/x', SiteUrl::forPath('/g7/api/x'));
    }

    /**
     * 접두사 보유 판정 — 끝·`/`·`?`·`#` 경계만 보유로 본다.
     *
     * @scenario host_header=canonical, sink=site_url_helper
     *
     * @effects site_url_absolute_shape
     *
     * @return void 반환값 없음
     */
    public function test_has_path_prefix_boundaries(): void
    {
        $this->assertTrue(SiteUrl::hasPathPrefix('/g7', '/g7'));
        $this->assertTrue(SiteUrl::hasPathPrefix('/g7/x', '/g7'));
        $this->assertTrue(SiteUrl::hasPathPrefix('/g7?x=1', '/g7'));
        $this->assertTrue(SiteUrl::hasPathPrefix('/g7#top', '/g7'));
        $this->assertFalse(SiteUrl::hasPathPrefix('/g7x/foo', '/g7'));
        $this->assertFalse(SiteUrl::hasPathPrefix('/x/g7', '/g7'));
    }

    /**
     * 같은 출처 판정 — 브라우저와 PHP 해석이 갈리는 입력은 모두 거부.
     *
     * @return array<string, array{0: string, 1: bool}> 입력 주소와 기대 판정
     */
    public static function originCases(): array
    {
        return [
            'backslash path' => ['/\\evil.com', false],
            'decoded tab' => ["/\t/evil.com", false],
            'encoded tab' => ['/%09/evil.com', false],
            'protocol relative' => ['//evil.com', false],
            'backslash userinfo' => ['https://evil.com\\@shop.example.test/', false],
            'userinfo' => ['https://user@shop.example.test/', false],
            'scheme differs' => ['http://shop.example.test/', false],
            'port differs' => ['https://shop.example.test:8443/', false],
            'subdomain' => ['https://attacker.shop.example.test/', false],
            'javascript' => ['javascript:alert(1)', false],
            'empty' => ['', false],
            'same origin absolute' => ['https://shop.example.test/ok', true],
            'explicit default port' => ['https://shop.example.test:443/ok', true],
            'upper-case host' => ['https://SHOP.example.test/ok', true],
            'relative path' => ['/ok?x=1', true],
        ];
    }

    /**
     * 같은 출처 판정 결과가 기대와 같다.
     *
     * @scenario host_header=canonical, sink=return_url_check
     *
     * @effects return_url_must_be_same_origin
     *
     * @param  string  $url  검사할 주소
     * @param  bool  $expected  기대 판정
     * @return void 반환값 없음
     */
    #[DataProvider('originCases')]
    public function test_is_same_origin(string $url, bool $expected): void
    {
        $this->assertSame($expected, SiteUrl::isSameOrigin($url), $url);
    }

    /**
     * 비교용 host — 표준 포트는 생략, 명시 포트만 붙인다.
     *
     * @scenario host_header=forged, sink=canonical_redirect
     *
     * @effects site_url_host_with_port
     *
     * @return void 반환값 없음
     */
    public function test_host_with_port(): void
    {
        $this->assertSame('shop.example.test', SiteUrl::hostWithPort());

        config(['app.url' => 'https://Shop.Example.test:8443/']);
        $this->assertSame('shop.example.test:8443', SiteUrl::hostWithPort());

        config(['app.url' => 'https://shop.example.test:443']);
        $this->assertSame('shop.example.test', SiteUrl::hostWithPort());
    }

    /**
     * SEO 헬퍼는 같은 값을 낸다(위임).
     *
     * @scenario host_header=forged, sink=site_url_helper
     *
     * @effects seo_url_delegates_to_site_url
     *
     * @return void 반환값 없음
     */
    public function test_seo_url_delegates(): void
    {
        $this->forgeRequest('https://shop.example.test:6666/');

        $this->assertSame(SiteUrl::base(), SeoUrl::base());
        $this->assertSame(SiteUrl::absolute('/p?x=1'), SeoUrl::absolute('/p?x=1'));
        $this->assertSame(SiteUrl::origin(), SeoUrl::origin());
    }

    /**
     * 정본 화면 주소는 하위 폴더 마운트 루트만 끝 슬래시를 갖고, 루트 설치의 홈은 종전과 같다.
     *
     * 하위 폴더의 마운트 루트(`…/g7`)는 웹서버에게 폴더라 끝 슬래시 형태(`…/g7/`)로 301 을 돌려보낸다.
     * 검색엔진에 알리는 정본이 접힌 형태면 그 주소마다 이동을 한 번 거친다.
     *
     * @scenario host_header=canonical, sink=site_url_helper
     *
     * @effects canonical_screen_url_keeps_only_the_mount_root_slash
     *
     * @return void 반환값 없음
     */
    public function test_canonical_screen_form_keeps_only_the_mount_root_slash(): void
    {
        config(['app.url' => 'https://shop.example.test/g7']);

        $this->assertSame('https://shop.example.test/g7/', SiteUrl::canonicalScreenForm('https://shop.example.test/g7'));
        $this->assertSame('https://shop.example.test/g7/', SiteUrl::canonicalScreenForm('https://shop.example.test/g7/'));
        $this->assertSame('https://shop.example.test/g7/?locale=en', SiteUrl::canonicalScreenForm('https://shop.example.test/g7?locale=en'));
        $this->assertSame('https://shop.example.test/g7/board/free', SiteUrl::canonicalScreenForm('https://shop.example.test/g7/board/free/'));
        $this->assertSame('https://shop.example.test/g7x', SiteUrl::canonicalScreenForm('https://shop.example.test/g7x'), '접두사 경계 밖 경로는 마운트 루트가 아니다');
        $this->assertSame('https://shop.example.test/g7/', SeoUrl::canonical('/'));
        $this->assertSame('https://shop.example.test/g7/', SeoUrl::canonical('/g7'));
        $this->assertSame('https://shop.example.test/g7/products/1', SeoUrl::canonical('/products/1'));

        // 사이트 주소와 다른 베이스(사용자 화면 URL)로 만든 주소는 그 베이스의 마운트로 판정한다
        $this->assertSame('https://user.example.test/app/', SiteUrl::canonicalScreenForm('https://user.example.test/app', 'https://user.example.test/app/'));

        // 루트 설치 — 홈 정본은 종전과 같이 끝 슬래시 없는 사이트 주소다
        config(['app.url' => 'https://shop.example.test']);

        $this->assertSame('https://shop.example.test', SiteUrl::canonicalScreenForm('https://shop.example.test/'));
        $this->assertSame('https://shop.example.test', SeoUrl::canonical('/'));
        $this->assertSame('https://shop.example.test/products/1', SeoUrl::canonical('/products/1/'));
    }
}
