<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\RedirectToCanonicalHost;
use App\Support\TrustedHosts;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 사이트 주소가 아닌 Host 로 들어온 화면 요청의 사이트 주소 이동을 검증합니다.
 *
 * 이동 판정은 미들웨어를 직접 통과시켜 확인하고(DB 없이), 실제 화면 셸 라우트에 붙어 있는지와
 * 셸 HTML 대신 301 이 나가는지는 HTTP 요청으로 확인한다 — 이동은 템플릿 의존성 검사 등 DB 를
 * 읽는 미들웨어보다 앞에서 끝난다.
 */
class CanonicalHostRedirectTest extends TestCase
{
    /** 테스트 사이트 주소 */
    private const SITE = 'https://shop.example.test';

    /**
     * 사이트 주소와 설치 완료 상태를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::SITE,
            'app.installer_completed' => true,
            'trustedhosts.canonical_redirect' => true,
        ]);
    }

    /**
     * 요청을 미들웨어에 통과시킵니다.
     *
     * @param  string  $url  요청 주소
     * @param  string  $method  HTTP 메서드
     * @return Response 미들웨어 응답 (통과면 200 'next')
     */
    private function through(string $url, string $method = 'GET'): Response
    {
        $request = Request::create($url, $method);

        return (new RedirectToCanonicalHost)->handle($request, fn () => response('next', 200));
    }

    /**
     * 요청을 운영 환경처럼 적용한 신뢰 Host 검사 → 사이트 주소 이동 순서로 통과시킵니다.
     *
     * 전역 TrustHosts 는 테스트 환경에서 스스로 건너뛰므로 그 판정만 뒤집은 하위 클래스를 쓴다.
     * Host 를 읽는 순간 거부되는 요청은 null 을 돌려준다(운영에서는 404).
     *
     * @param  string  $url  요청 주소
     * @param  string  $method  HTTP 메서드
     * @return Response|null 이동 미들웨어 응답, 신뢰 Host 검사에서 거부되면 null
     */
    private function throughTrustAndCanonical(string $url, string $method = 'GET'): ?Response
    {
        $trust = new class($this->app) extends TrustHosts
        {
            /**
             * 로컬·테스트 건너뜀을 끄고 운영 환경처럼 적용합니다.
             *
             * @return bool 항상 true
             */
            protected function shouldSpecifyTrustedHosts()
            {
                return true;
            }
        };
        TrustHosts::at(fn (): array => TrustedHosts::patterns(), false);

        try {
            $request = Request::create($url, $method);

            return $trust->handle($request, function (Request $request): Response {
                try {
                    $request->getHost();
                } catch (SuspiciousOperationException) {
                    return response('rejected', 404);
                }

                return (new RedirectToCanonicalHost)->handle($request, fn () => response('next', 200));
            });
        } finally {
            TrustHosts::flushState();
            Request::setTrustedHosts([]);
        }
    }

    /**
     * 사이트 주소가 `www.` 주소면 `www.` 없는 주소의 화면 요청은 거부되지 않고 사이트 주소로 301,
     * 반대 방향도 같다. 이동 제외 규칙(POST)·설정 off 는 종전대로 통과.
     *
     * @scenario host_header=forged, sink=canonical_redirect
     *
     * @effects www_pair_host_page_request_redirects_to_site
     *
     * @return void 반환값 없음
     */
    public function test_www_pair_host_is_trusted_and_redirected_to_site(): void
    {
        config(['app.url' => 'https://www.example.test']);

        $response = $this->throughTrustAndCanonical('https://example.test/shop/products?x=1');
        $this->assertSame(301, $response->getStatusCode(), 'www 없는 주소가 신뢰 Host 검사에서 막히면 안 된다');
        $this->assertSame('https://www.example.test/shop/products?x=1', $response->headers->get('Location'));

        $this->assertSame('next', $this->throughTrustAndCanonical('https://www.example.test/shop')->getContent());
        $this->assertSame('next', $this->throughTrustAndCanonical('https://example.test/api/x', 'POST')->getContent());
        $this->assertSame('rejected', $this->throughTrustAndCanonical('https://shop.example.test/shop')->getContent(), 'www 를 뗀 주소의 다른 하위 도메인은 열지 않는다');

        config(['app.url' => 'https://example.test']);
        $response = $this->throughTrustAndCanonical('https://www.example.test/shop?x=1');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://example.test/shop?x=1', $response->headers->get('Location'));
    }

    /**
     * www 쌍 처리도 비상 스위치를 따른다 — 이동 off 면 그 주소에서 화면을 그대로 내고,
     * 신뢰 Host 검사 off 면 이동만 남는다. 상위 도메인으로 번지는 www 쌍은 없다.
     *
     * @scenario host_header=forged, sink=canonical_redirect
     *
     * @effects www_pair_host_page_request_redirects_to_site
     *
     * @return void 반환값 없음
     */
    public function test_www_pair_respects_emergency_switches_and_edges(): void
    {
        config(['app.url' => 'https://www.example.test', 'trustedhosts.canonical_redirect' => false]);
        $this->assertSame('next', $this->throughTrustAndCanonical('https://example.test/shop')->getContent());

        config(['trustedhosts.canonical_redirect' => true, 'trustedhosts.enabled' => false]);
        $response = $this->throughTrustAndCanonical('https://attacker.example/shop');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://www.example.test/shop', $response->headers->get('Location'));

        config(['trustedhosts.enabled' => true, 'app.url' => 'https://www.shop.example.test']);
        $this->assertSame(301, $this->throughTrustAndCanonical('https://shop.example.test/x')->getStatusCode());
        $this->assertSame('rejected', $this->throughTrustAndCanonical('https://example.test/x')->getContent());

        config(['app.url' => 'http://203.0.113.10']);
        $this->assertSame('next', $this->throughTrustAndCanonical('http://203.0.113.10/x')->getContent());
        $this->assertSame('rejected', $this->throughTrustAndCanonical('http://203.0.113.11/x')->getContent());
    }

    /**
     * 사이트 주소가 아닌 Host 변종.
     *
     * @return array<string, array{0: string}> 변종별 요청 주소
     */
    public static function foreignHosts(): array
    {
        return [
            'external' => ['https://attacker.example/shop/products?x=1'],
            'subdomain' => ['https://attacker.shop.example.test/shop/products?x=1'],
            'port' => ['https://shop.example.test:6666/shop/products?x=1'],
            'alias' => ['https://www.shop.example.test/shop/products?x=1'],
        ];
    }

    /**
     * 사이트 주소가 아닌 Host 의 화면 요청은 같은 경로·쿼리의 사이트 주소로 301.
     *
     * @scenario host_header=forged, sink=canonical_redirect
     *
     * @effects foreign_host_page_request_redirects_to_site
     *
     * @param  string  $url  요청 주소
     * @return void 반환값 없음
     */
    #[DataProvider('foreignHosts')]
    public function test_foreign_host_redirects_to_same_path_on_site(string $url): void
    {
        $response = $this->through($url);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(self::SITE.'/shop/products?x=1', $response->headers->get('Location'));
    }

    /**
     * 사이트 주소 Host 는 통과한다(이동 루프 없음). scheme 은 비교하지 않는다.
     *
     * @scenario host_header=canonical, sink=canonical_redirect
     *
     * @effects canonical_host_passes_without_loop
     *
     * @return void 반환값 없음
     */
    public function test_canonical_host_passes(): void
    {
        $this->assertSame('next', $this->through(self::SITE.'/shop/products')->getContent());
        $this->assertSame('next', $this->through('http://shop.example.test/shop/products')->getContent());
        $this->assertSame('next', $this->through('https://SHOP.example.test:443/shop')->getContent());
    }

    /**
     * 명시 포트가 있는 사이트 주소는 그 포트 Host 만 통과한다.
     *
     * @scenario host_header=forged, sink=canonical_redirect
     *
     * @effects canonical_host_passes_without_loop
     *
     * @return void 반환값 없음
     */
    public function test_site_with_explicit_port(): void
    {
        config(['app.url' => 'https://shop.example.test:8443']);

        $this->assertSame('next', $this->through('https://shop.example.test:8443/x')->getContent());
        $response = $this->through('https://shop.example.test/x');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://shop.example.test:8443/x', $response->headers->get('Location'));
    }

    /**
     * `//evil.com` 경로도 사이트 주소 안 경로로 이동한다.
     *
     * @scenario host_header=forged, sink=canonical_redirect
     *
     * @effects foreign_host_page_request_redirects_to_site
     *
     * @return void 반환값 없음
     */
    public function test_double_slash_path_stays_inside_site(): void
    {
        $request = Request::create('https://attacker.shop.example.test/x');
        $request->server->set('REQUEST_URI', '//evil.com/x?y=1');

        $response = (new RedirectToCanonicalHost)->handle($request, fn () => response('next'));

        $this->assertSame(self::SITE.'/evil.com/x?y=1', $response->headers->get('Location'));
    }

    /**
     * GET·HEAD 가 아닌 요청·설정 off·설치 전·사이트 주소 미상은 통과.
     *
     * @scenario host_header=forged, sink=canonical_redirect
     *
     * @effects canonical_redirect_skipped_when_not_applicable
     *
     * @return void 반환값 없음
     */
    public function test_skips_when_not_applicable(): void
    {
        $foreign = 'https://attacker.shop.example.test/shop';

        $this->assertSame(301, $this->through($foreign, 'HEAD')->getStatusCode());
        $this->assertSame('next', $this->through($foreign, 'POST')->getContent());

        config(['trustedhosts.canonical_redirect' => false]);
        $this->assertSame('next', $this->through($foreign)->getContent());

        config(['trustedhosts.canonical_redirect' => true, 'app.installer_completed' => false]);
        $this->assertSame('next', $this->through($foreign)->getContent());

        config(['app.installer_completed' => true, 'app.url' => '']);
        $this->assertSame('next', $this->through($foreign)->getContent());
    }

    /**
     * 화면 셸 두 묶음에만 붙고, API·확장·사이트맵 라우트에는 붙지 않는다.
     *
     * @scenario host_header=forged, sink=canonical_redirect
     *
     * @effects canonical_redirect_only_on_page_shells
     *
     * @return void 반환값 없음
     */
    public function test_attached_only_to_page_shell_routes(): void
    {
        $withAlias = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('canonical.host', $route->gatherMiddleware(), true)) {
                $withAlias[] = $route->uri();
            }
        }

        sort($withAlias);
        $this->assertSame(['admin/{any?}', '{any?}'], $withAlias);
    }

    /**
     * 포트·하위 도메인 Host 의 셸 요청은 셸 HTML(요청 Host 로 만든 자산 주소) 대신 301 을 받는다.
     *
     * @scenario host_header=forged, sink=canonical_redirect
     *
     * @effects page_shell_not_rendered_for_foreign_host
     *
     * @return void 반환값 없음
     */
    public function test_shell_routes_redirect_instead_of_rendering(): void
    {
        $this->get('https://shop.example.test:6666/admin')
            ->assertStatus(301)
            ->assertHeader('Location', self::SITE.'/admin');

        $this->get('https://attacker.shop.example.test/shop/products?x=1')
            ->assertStatus(301)
            ->assertHeader('Location', self::SITE.'/shop/products?x=1');
    }
}
