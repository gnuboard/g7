<?php

namespace Tests\Feature\Http;

use App\Support\TrustedProxyDiagnostic;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 신뢰 프록시 설정 계약 (#124).
 *
 * `config/trustedproxy.php` 는 코어 PHP 코드 수정 없이 Laravel 내장 TrustProxies 미들웨어에
 * 값을 전달하는 **유일한 배선점**이다(`$this->proxies() ?: config('trustedproxy.proxies')`).
 * 그 배선이 끊기면 프록시 뒤 설치처의 화면이 통째로 뜨지 않는데, 예외도 로그도 남지 않는다.
 *
 * `bootstrap/app.php` 에서 `trustProxies(at: env(...))` 로 설정하는 것은 함정이다 —
 * `withMiddleware` 클로저는 `.env` 로드 전에 평가되어 `env()` 가 항상 `null` 을 돌려주고,
 * 설정한 것처럼 보이는데 실제로는 아무 프록시도 신뢰하지 않는 상태가 된다. 관련 케이스가
 * 그 형태의 재유입을 소스 스캔으로 차단한다.
 *
 * DB 를 쓰지 않으므로 `RefreshDatabase` 를 붙이지 않는다. 신뢰 상태는 `Request` 의 **정적**
 * 상태라 테스트마다 되돌리지 않으면 같은 프로세스의 후속 테스트로 샌다.
 */
class TrustedProxyContractTest extends TestCase
{
    /** 프록시로 위장할 직전 호출 IP */
    private const REMOTE_ADDR = '10.0.0.5';

    /** 프록시가 전달한 실제 방문자 IP */
    private const CLIENT_IP = '203.0.113.77';

    /**
     * 각 테스트 후 정적 신뢰 상태를 원복합니다.
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * 프록시 뒤 요청을 만들고 TrustProxies 미들웨어를 통과시킵니다.
     *
     * @param  string|null  $proxies  신뢰 프록시 설정값
     * @param  string  $forwardedFor  X-Forwarded-For 헤더 값
     * @return Request 미들웨어를 통과한 요청
     */
    private function proxiedRequest(?string $proxies, string $forwardedFor = self::CLIENT_IP): Request
    {
        config(['trustedproxy.proxies' => $proxies]);

        $request = Request::create('http://g7_2.dev/', 'GET', [], [], [], [
            'REMOTE_ADDR' => self::REMOTE_ADDR,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
            'HTTP_X_FORWARDED_HOST' => 'g7_2.dev',
            'HTTP_X_FORWARDED_PORT' => '8443',
        ]);

        (new TrustProxies)->handle($request, fn () => null);

        return $request;
    }

    /**
     * 설정이 없으면 X-Forwarded-* 를 전부 무시합니다 (기본값 — 기존 설치처 동작 불변).
     *
     * @scenario proxies_setting=unset, forwarded_proto=https, forwarded_for=single
     *
     * @effects unset_ignores_forwarded_headers
     */
    public function test_미설정이면_전달_헤더를_신뢰하지_않는다(): void
    {
        $request = $this->proxiedRequest(null);

        $this->assertFalse($request->isSecure(), '미설정 상태에서는 X-Forwarded-Proto 를 신뢰하지 않아야 합니다.');
        $this->assertSame(self::REMOTE_ADDR, $request->ip(), '미설정 상태에서는 REMOTE_ADDR 이 방문자 IP 여야 합니다.');
        $this->assertStringStartsWith('http://', $request->root(), '미설정 상태에서는 평문 HTTP 루트가 만들어져야 합니다.');
    }

    /**
     * `*` 은 직전 호출 IP 를 신뢰해 스킴·IP·호스트·포트를 모두 복원합니다.
     *
     * @scenario proxies_setting=star, forwarded_proto=https, forwarded_for=single, forwarded_host=same, forwarded_port=nonstandard
     *
     * @effects star_restores_scheme_ip_and_root
     */
    public function test_별표는_직전_호출_i_p_를_신뢰한다(): void
    {
        $request = $this->proxiedRequest('*');

        $this->assertTrue($request->isSecure());
        $this->assertSame(self::CLIENT_IP, $request->ip());
        $this->assertSame('https://g7_2.dev:8443', $request->root());
    }

    /**
     * `**` 은 Laravel 내장 미들웨어에서 `*` 과 동일하게 동작합니다.
     *
     * 별도 패키지 시절 `**` 는 "모든 프록시 신뢰" 였으나, 내장 구현은 `*` 과 같은
     * `setTrustedProxyIpAddressesToTheCallingIp()` 로 분기한다. 그 사실을 문서·설정 주석이
     * 그대로 서술하고 있으므로, 프레임워크 업그레이드로 의미가 갈라지면 여기서 드러나야 한다.
     *
     * @scenario proxies_setting=double_star, forwarded_proto=https, forwarded_for=single
     *
     * @effects double_star_behaves_like_star
     */
    public function test_별표두개는_별표와_동일하게_동작한다(): void
    {
        $starIp = $this->proxiedRequest('*')->ip();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        $double = $this->proxiedRequest('**');

        $this->assertTrue($double->isSecure());
        $this->assertSame($starIp, $double->ip(), '** 와 * 의 방문자 IP 해석이 갈라졌습니다 — 문서·설정 주석을 함께 갱신해야 합니다.');
    }

    /**
     * 다단계 체인에서 `*` 은 마지막 프록시를 방문자로 본다 — 모든 단을 나열해야 복원됩니다.
     *
     * @scenario proxies_setting=star, forwarded_for=chain
     *
     * @effects chain_requires_listing_every_hop
     */
    public function test_다단계_체인은_모든_프록시를_나열해야_클라이언트_i_p_가_해석된다(): void
    {
        $chain = self::CLIENT_IP.', 10.1.1.1, 10.2.2.2';

        $star = $this->proxiedRequest('*', $chain);
        $this->assertSame('10.2.2.2', $star->ip(), '* 은 직전 호출 IP 하나만 신뢰하므로 마지막 프록시가 방문자로 보여야 합니다.');
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        $listed = $this->proxiedRequest(self::REMOTE_ADDR.',10.1.1.1,10.2.2.2', $chain);
        $this->assertSame(self::CLIENT_IP, $listed->ip(), '체인의 모든 단을 나열하면 최초 클라이언트 IP 가 해석되어야 합니다.');
    }

    /**
     * 목록에 직전 호출 IP 가 있으면 신뢰하고, 없으면 신뢰하지 않습니다.
     *
     * @scenario proxies_setting=single_ip_match, forwarded_proto=https
     *
     * @effects ip_list_match_trusts_and_mismatch_rejects
     */
    public function test_i_p_목록은_일치할_때만_신뢰한다(): void
    {
        $match = $this->proxiedRequest(self::REMOTE_ADDR);
        $this->assertTrue($match->isSecure());
        $this->assertSame(self::CLIENT_IP, $match->ip());
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        $mismatch = $this->proxiedRequest('10.0.0.9');
        $this->assertFalse($mismatch->isSecure(), '목록에 없는 프록시의 헤더는 신뢰하지 않아야 합니다.');
        $this->assertSame(self::REMOTE_ADDR, $mismatch->ip());
    }

    /**
     * CIDR 표기도 신뢰 판정에 사용됩니다.
     *
     * @scenario proxies_setting=cidr, forwarded_proto=https
     *
     * @effects cidr_notation_is_honored
     */
    public function test_cid_r_표기가_신뢰_판정에_사용된다(): void
    {
        $request = $this->proxiedRequest('10.0.0.0/8');

        $this->assertTrue($request->isSecure());
        $this->assertSame(self::CLIENT_IP, $request->ip());
    }

    /**
     * `config/trustedproxy.php` 는 `proxies` 키를 노출하고 TRUSTED_PROXIES 를 그대로 반영합니다.
     *
     * @effects config_file_exposes_proxies_key_from_env
     */
    public function test_설정_파일이_truste_d_proxie_s_를_그대로_반영한다(): void
    {
        $path = base_path('config/trustedproxy.php');
        $this->assertFileExists($path, 'config/trustedproxy.php 는 신뢰 프록시 설정의 유일한 배선점입니다.');

        $previous = $_ENV['TRUSTED_PROXIES'] ?? null;
        $_ENV['TRUSTED_PROXIES'] = '198.51.100.7';

        try {
            $config = require $path;
        } finally {
            if ($previous === null) {
                unset($_ENV['TRUSTED_PROXIES']);
            } else {
                $_ENV['TRUSTED_PROXIES'] = $previous;
            }
        }

        $this->assertArrayHasKey('proxies', $config);
        $this->assertSame('198.51.100.7', $config['proxies']);
    }

    /**
     * `bootstrap/app.php` 에 env() 기반 trustProxies / trustHosts 가 재유입되지 않습니다.
     *
     * `withMiddleware` 클로저는 `.env` 로드 전에 평가되므로 그 자리의 `env()` 는 항상 null 이다
     * — 오류 없이 no-op 이 되어, 설정한 것처럼 보이는데 아무 프록시도 신뢰하지 않는다.
     * `trustHosts()` 는 호출 자체가 모든 설치처에서 Host 검증을 켠다(opt-in 원칙 위반).
     *
     * @effects bootstrap_does_not_reintroduce_env_trust_proxies
     */
    public function test_bootstrap_에_env_기반_trust_proxies_가_없다(): void
    {
        $source = (string) file_get_contents(base_path('bootstrap/app.php'));

        // 주석을 제거해 "하면 안 되는 것" 설명문이 오탐되지 않게 한다.
        $stripped = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

        $this->assertStringNotContainsString(
            'trustHosts(',
            $stripped,
            'trustHosts() 호출은 모든 설치처에서 Host 검증을 켭니다 — opt-in 원칙 위반입니다.'
        );

        if (preg_match('/trustProxies\s*\(([^;]*)\)/s', $stripped, $m) === 1) {
            $this->assertStringNotContainsString(
                'env(',
                $m[1],
                'bootstrap/app.php 의 trustProxies() 안에서 env() 를 읽으면 항상 null 입니다 — config/trustedproxy.php 를 쓰세요.'
            );
        } else {
            $this->assertTrue(true, 'trustProxies() 호출이 없으므로 config 폴백이 유일한 배선점입니다.');
        }
    }

    /**
     * `.env.example` 과 `.env.testing.example` 이 TRUSTED_PROXIES 를 안내합니다.
     *
     * @effects env_examples_document_trusted_proxies
     */
    public function test_env_예시_파일이_truste_d_proxie_s_를_안내한다(): void
    {
        foreach (['.env.example', '.env.testing.example'] as $file) {
            $this->assertStringContainsString(
                'TRUSTED_PROXIES',
                (string) file_get_contents(base_path($file)),
                $file.' 에 TRUSTED_PROXIES 안내가 있어야 합니다.'
            );
        }
    }

    /**
     * 설치 마법사의 전달 헤더 목록이 코어 진단과 같은 집합입니다.
     *
     * 설치 마법사는 순수 PHP 영역이라 Laravel 헬퍼를 쓸 수 없어 목록을 복제한다. 복제본이
     * 갈라지면 "설치 때는 안내가 떴는데 운영 화면에는 경고가 없다"(또는 그 반대)가 된다.
     *
     * @effects installer_header_list_matches_core_diagnostic
     */
    public function test_설치_마법사의_전달_헤더_목록이_코어와_같다(): void
    {
        $source = (string) file_get_contents(base_path('public/install/api/check-configuration.php'));

        foreach (TrustedProxyDiagnostic::FORWARDED_HEADERS as $header) {
            $this->assertStringContainsString(
                "'".$header."' => 'HTTP_",
                $source,
                $header.' 가 설치 마법사의 전달 헤더 목록에 없습니다.'
            );
        }
    }

    /**
     * 신뢰 프록시가 있으면 쿠키의 Secure 가 자동 판정으로 붙고, 없으면 붙지 않습니다.
     *
     * `SESSION_SECURE_COOKIE` 미설정 시 Symfony `Response::prepare()` 가 `isSecure()` 로
     * 판정하므로, 신뢰 설정이 없으면 HTTPS 사이트인데 쿠키가 Secure 없이 발급된다.
     *
     * @scenario proxies_setting=star, session_secure_cookie=unset
     *
     * @effects cookie_secure_follows_trusted_scheme
     */
    public function test_쿠키_secure_는_신뢰된_스킴을_따른다(): void
    {
        $this->assertTrue($this->cookieIsSecureAfterPrepare('*'), '신뢰 프록시가 있으면 쿠키에 Secure 가 붙어야 합니다.');
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        $this->assertFalse($this->cookieIsSecureAfterPrepare(null), '신뢰 프록시가 없으면 평문으로 판정되어 Secure 가 붙지 않습니다.');
    }

    /**
     * 주어진 신뢰 설정으로 응답을 준비했을 때 쿠키에 Secure 가 붙는지 판정합니다.
     *
     * @param  string|null  $proxies  신뢰 프록시 설정값
     * @return bool Secure 부착 여부
     */
    private function cookieIsSecureAfterPrepare(?string $proxies): bool
    {
        $request = $this->proxiedRequest($proxies);

        $response = new Response('ok');
        // secure = null → Response::prepare() 가 요청 스킴으로 자동 판정한다.
        $response->headers->setCookie(Cookie::create('g7-session', 'value', 0, '/', null, null));
        $response->prepare($request);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'g7-session') {
                return $cookie->isSecure();
            }
        }

        $this->fail('g7-session 쿠키를 응답에서 찾지 못했습니다.');
    }
}
