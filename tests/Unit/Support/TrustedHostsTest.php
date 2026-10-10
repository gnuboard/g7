<?php

namespace Tests\Unit\Support;

use App\Support\TrustedHosts;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * 신뢰 Host 허용 목록을 검증합니다 (KVE-2026-2333/2334 심층방어).
 *
 * Laravel TrustHosts 는 로컬·단위 테스트에서 스스로 적용을 건너뛰므로, 거부 동작은 그 판정만
 * 뒤집은 하위 클래스로 확인한다. `Request::setTrustedHosts()` 는 전역 정적 상태라 매 테스트 뒤 비운다.
 */
class TrustedHostsTest extends TestCase
{
    /**
     * 테스트 초기화 - 설치 완료·사이트 주소 상태를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.installer_completed' => true,
            'app.url' => 'https://site.example',
            'trustedhosts.enabled' => true,
            'trustedhosts.hosts' => null,
        ]);
    }

    /**
     * 테스트 종료 - 전역 신뢰 호스트 설정을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /**
     * 사이트 도메인·하위 도메인과 TRUSTED_HOSTS(정확 일치)만 허용한다.
     *
     * @effects trusted_hosts_allow_site_domain_and_listed_hosts
     *
     * @return void 반환값 없음
     */
    public function test_patterns_allow_site_domain_subdomains_and_listed_hosts(): void
    {
        config(['trustedhosts.hosts' => ' Alias.Example , ,203.0.113.7']);

        $patterns = TrustedHosts::patterns();
        $matches = fn (string $host): bool => collect($patterns)->contains(fn ($p) => preg_match('{'.$p.'}i', $host) === 1);

        $this->assertTrue($matches('site.example'));
        $this->assertTrue($matches('www.site.example'));
        $this->assertTrue($matches('alias.example'));
        $this->assertTrue($matches('203.0.113.7'));
        $this->assertFalse($matches('attacker.example'));
        $this->assertFalse($matches('site.example.attacker.example'));
        $this->assertFalse($matches('www.alias.example'), '추가 도메인은 하위 도메인까지 열지 않는다');
    }

    /**
     * 주어진 host 가 현재 패턴 중 하나에 맞는지 판정합니다 (Symfony 와 같은 `{패턴}i` 대조).
     *
     * @param  string  $host  요청 host
     * @return bool 맞는 패턴이 있으면 true
     */
    private function trusted(string $host): bool
    {
        return collect(TrustedHosts::patterns())->contains(fn ($p) => preg_match('{'.$p.'}i', $host) === 1);
    }

    /**
     * 사이트 주소가 `www.` 로 시작하면 `www.` 를 뗀 주소도 허용한다 — 그 주소만 정확히.
     *
     * 사이트 주소를 `www.` 주소로 둔 사이트에서 `www.` 없이 들어온 방문자가 404 를 받던 결함의 회귀
     * 테스트다. 뗀 주소의 다른 하위 도메인은 여전히 막는다.
     *
     * @effects trusted_hosts_allow_www_pair_of_site_host
     *
     * @return void 반환값 없음
     */
    public function test_www_site_host_also_trusts_bare_host(): void
    {
        config(['app.url' => 'https://www.site.example']);

        $this->assertTrue($this->trusted('www.site.example'));
        $this->assertTrue($this->trusted('site.example'), 'www 없는 주소가 막히면 안 된다');
        $this->assertTrue($this->trusted('SITE.example'));
        $this->assertTrue($this->trusted('cdn.www.site.example'), '사이트 주소의 하위 도메인은 종전대로 허용');
        $this->assertFalse($this->trusted('shop.site.example'), 'www 를 뗀 주소의 다른 하위 도메인은 열지 않는다');
        $this->assertFalse($this->trusted('attacker.example'));
        $this->assertFalse($this->trusted('notsite.example'));
        $this->assertFalse($this->trusted('site.example.attacker.example'));

        // 포트가 있는 사이트 주소도 host 만 본다.
        config(['app.url' => 'https://www.site.example:8443']);
        $this->assertTrue($this->trusted('site.example'));
    }

    /**
     * 사이트 주소가 `www.` 없는 주소면 `www.` 주소도 허용한다(하위 도메인 허용에 포함되어 종전과 같다).
     *
     * @effects trusted_hosts_allow_www_pair_of_site_host
     *
     * @return void 반환값 없음
     */
    public function test_bare_site_host_also_trusts_www_host(): void
    {
        config(['app.url' => 'https://site.example']);

        $this->assertTrue($this->trusted('site.example'));
        $this->assertTrue($this->trusted('www.site.example'));
    }

    /**
     * www 쌍은 맨 앞 `www.` 한 단계를 붙이고 떼는 것만이다 — 상위 도메인으로 번지지 않는다.
     *
     * @effects trusted_hosts_allow_www_pair_of_site_host
     *
     * @return void 반환값 없음
     */
    public function test_www_pair_is_only_the_leading_www_label(): void
    {
        config(['app.url' => 'https://www.shop.example.com']);

        $this->assertTrue($this->trusted('shop.example.com'));
        $this->assertFalse($this->trusted('example.com'));
        $this->assertFalse($this->trusted('www.example.com'));
        $this->assertFalse($this->trusted('blog.shop.example.com'));

        $this->assertSame('shop.example.com', TrustedHosts::wwwCounterpart('WWW.Shop.Example.com'));
        $this->assertSame('www.shop.example.com', TrustedHosts::wwwCounterpart('shop.example.com'));
        $this->assertSame('www.example.com', TrustedHosts::wwwCounterpart('example.com'));

        // 뗀 결과가 다시 `www.` 로 시작해도 한 단계 짝은 허용한다 — 짝이 `www.` 로 시작한다는 이유로 빠지면 안 된다
        config(['app.url' => 'https://www.www.example.com']);
        $this->assertTrue($this->trusted('www.example.com'), 'www.www.X 사이트의 한 단계 짝 www.X 가 막히면 안 된다');
        $this->assertFalse($this->trusted('example.com'), '두 단계를 뗀 주소는 짝이 아니다');
        $this->assertSame('www.example.com', TrustedHosts::wwwCounterpart('www.www.example.com'));
    }

    /**
     * IP 주소·점 없는 이름(localhost 등)·`www.` 를 떼면 점이 없어지는 이름은 www 쌍을 만들지 않는다.
     *
     * @effects trusted_hosts_allow_www_pair_of_site_host
     *
     * @return void 반환값 없음
     */
    public function test_www_pair_is_not_made_for_ip_or_single_label_hosts(): void
    {
        foreach (['203.0.113.10', '[2001:db8::1]', '2001:db8::1', 'localhost', 'www.localhost', 'www.example', 'www.203.0.113.10', ''] as $host) {
            $this->assertNull(TrustedHosts::wwwCounterpart($host), $host.' 는 www 쌍이 없어야 한다');
        }

        config(['app.url' => 'http://www.localhost:8000']);
        $this->assertTrue($this->trusted('www.localhost'));
        $this->assertFalse($this->trusted('localhost'));

        config(['app.url' => 'http://203.0.113.10']);
        $this->assertCount(1, TrustedHosts::patterns());
        $this->assertTrue($this->trusted('203.0.113.10'));
        $this->assertFalse($this->trusted('203.0.113.11'));
    }

    /**
     * 비상 스위치(TRUSTED_HOSTS_ENABLED=false)는 www 사이트 주소에서도 제한을 모두 푼다.
     *
     * @effects trusted_hosts_unrestricted_before_install_or_disabled
     *
     * @return void 반환값 없음
     */
    public function test_disabled_switch_still_returns_empty_for_www_site(): void
    {
        config(['app.url' => 'https://www.site.example', 'trustedhosts.enabled' => false]);

        $this->assertSame([], TrustedHosts::patterns());
    }

    /**
     * 설치 전·비활성·사이트 주소 미상이면 빈 목록(제한 없음)을 돌려준다.
     *
     * @effects trusted_hosts_unrestricted_before_install_or_disabled
     *
     * @return void 반환값 없음
     */
    public function test_patterns_are_empty_before_install_or_when_disabled(): void
    {
        config(['app.installer_completed' => false]);
        $this->assertSame([], TrustedHosts::patterns());

        config(['app.installer_completed' => true, 'trustedhosts.enabled' => false]);
        $this->assertSame([], TrustedHosts::patterns());

        config(['trustedhosts.enabled' => true, 'app.url' => '']);
        $this->assertSame([], TrustedHosts::patterns());
    }

    /**
     * TrustHosts 가 전역 미들웨어 스택에 등록되어 있고 허용 목록으로 TrustedHosts 패턴을 쓴다.
     *
     * @effects trust_hosts_registered_in_global_stack
     *
     * @return void 반환값 없음
     */
    public function test_trust_hosts_is_registered_in_global_stack(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $this->assertTrue($kernel->hasMiddleware(TrustHosts::class), 'bootstrap/app.php 에 trustHosts() 등록이 없습니다');

        $hosts = (new TrustHosts($this->app))->hosts();
        $this->assertSame(TrustedHosts::patterns(), $hosts);
    }

    /**
     * 운영 환경에서 신뢰하지 않는 Host 로 온 요청은 Host 를 읽는 순간 거부된다.
     *
     * @effects untrusted_host_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_untrusted_host_is_rejected_by_middleware(): void
    {
        $middleware = new class($this->app) extends TrustHosts
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
            $trusted = Request::create('https://www.site.example/');
            $middleware->handle($trusted, fn () => null);
            $this->assertSame('www.site.example', $trusted->getHost());

            config(['app.url' => 'https://www.site.example']);
            $bare = Request::create('https://site.example/');
            $middleware->handle($bare, fn () => null);
            $this->assertSame('site.example', $bare->getHost(), 'www 사이트 주소에서 www 없는 Host 가 거부되면 안 된다');

            $forged = Request::create('https://attacker.example/');
            $middleware->handle($forged, fn () => null);
            $this->expectException(SuspiciousOperationException::class);
            $forged->getHost();
        } finally {
            TrustHosts::flushState();
        }
    }
}
