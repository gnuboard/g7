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

            $forged = Request::create('https://attacker.example/');
            $middleware->handle($forged, fn () => null);
            $this->expectException(SuspiciousOperationException::class);
            $forged->getHost();
        } finally {
            TrustHosts::flushState();
        }
    }
}
