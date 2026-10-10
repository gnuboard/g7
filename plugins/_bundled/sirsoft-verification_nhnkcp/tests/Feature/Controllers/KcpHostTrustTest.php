<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpCertCrypto;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 본인인증 결과가 요청 Host 가 아니라 사이트 주소로만 전달되는지 검증합니다.
 *
 * 거래등록 복귀 주소(Ret_URL)·콜백 뒤 브리지 이동·브리지의 부모창 전달 대상이 요청 Host 를 따르면,
 * 공격자가 위조 Host 로 시작·중계한 인증의 검증 토큰이 공격자 도메인으로 간다.
 */
class KcpHostTrustTest extends PluginTestCase
{
    /** 테스트 사이트 주소 */
    private const SITE = 'https://shop.example.test';

    private const CALLBACK_PATH = '/plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/callback';

    private const BRIDGE_PATH = '/plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/bridge';

    private const TEST_CONFIG = [
        'is_test_mode' => true,
        'test_site_cd' => 'AO7F3',
        'test_enc_key' => 'c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e',
    ];

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
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * 위조 Host 변종.
     *
     * 사이트 주소 Host 행이 함께 들어 있다 — 정상 Host 에서도 같은 값을 내는지 본다.
     *
     * @scenario host_header=canonical, sink=bridge_redirect
     *
     * @effects callback_redirects_to_site_bridge
     *
     * @return array<string, array{0: string, 1: array<string, string>}> 요청 루트와 추가 서버 변수
     */
    public static function forgedHosts(): array
    {
        return [
            'canonical' => [self::SITE, []],
            'external' => ['https://attacker.example', []],
            'subdomain' => ['https://attacker.shop.example.test', []],
            'port' => ['https://shop.example.test:6666', []],
            'forwarded' => ['http://10.0.0.5', ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'attacker.example', 'HTTP_X_FORWARDED_PROTO' => 'https']],
        ];
    }

    /**
     * 위조 변종의 서버 변수에 맞춰 신뢰 프록시를 둡니다.
     *
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    private function trustProxyFor(array $server): void
    {
        if (isset($server['HTTP_X_FORWARDED_HOST'])) {
            Request::setTrustedProxies(['10.0.0.5'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
            config(['trustedproxy.proxies' => '10.0.0.5']);
        }
    }

    /**
     * 콜백 뒤 브리지 이동은 사이트 주소다.
     *
     * @scenario host_header=forged, sink=bridge_redirect
     *
     * @effects callback_redirects_to_site_bridge
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_callback_redirects_to_site_bridge(string $root, array $server): void
    {
        $this->trustProxyFor($server);

        $response = $this->withServerVariables($server)->post($root.self::CALLBACK_PATH, [
            'res_cd' => '0000',
            'reg_cert_key' => 'UNKNOWN',
        ]);

        $response->assertRedirect();
        $this->assertStringStartsWith(
            self::SITE.self::BRIDGE_PATH.'?',
            (string) $response->headers->get('Location'),
        );
    }

    /**
     * 거래등록 복귀 주소(Ret_URL)와 프런트에 내려가는 사이트 origin 은 사이트 주소다.
     *
     * @scenario host_header=forged, sink=provider_ret_url
     *
     * @effects ret_url_and_site_origin_use_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_registration_ret_url_uses_site_address(string $root, array $server): void
    {
        $this->trustProxyFor($server);
        $request = Request::create($root.'/api/identity/challenges', 'POST', [], [], [], $server);
        app()->instance('request', $request);
        app('url')->setRequest($request);

        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'call_url' => 'https://testcert.kcp.co.kr/certGateway.do',
                'reg_cert_key' => 'CERTKEY000000001',
            ]),
        ]);

        $challenge = app()->makeWith(KcpIdentityProvider::class, ['config' => self::TEST_CONFIG])
            ->requestChallenge(['email' => 'guest@example.test'], ['purpose' => 'signup']);

        $this->assertSame(self::SITE, $challenge->publicPayload['siteOrigin'] ?? null);

        // 거래등록 본문은 암호문이다 — 같은 키로 풀어 Ret_URL 을 읽는다.
        Http::assertSent(function ($sent): bool {
            $plain = KcpCertCrypto::decrypt(
                $sent->body(),
                (string) ($sent->header('rv')[0] ?? ''),
                self::TEST_CONFIG['test_enc_key'],
                self::TEST_CONFIG['test_site_cd'],
            );
            $this->assertSame(self::SITE.self::CALLBACK_PATH, json_decode($plain, true)['Ret_URL'] ?? null);

            return true;
        });
    }

    /**
     * 브리지는 사이트 origin 리터럴로만 부모창에 결과를 넘기고, 주소·캐시를 남기지 않는다.
     *
     * @scenario host_header=forged, sink=bridge_page
     *
     * @effects bridge_posts_to_site_origin_without_referrer_or_cache
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_bridge_targets_site_origin(string $root, array $server): void
    {
        $this->trustProxyFor($server);

        $response = $this->withServerVariables($server)
            ->get($root.self::BRIDGE_PATH.'?verification_token=tok-1&challenge_id=chal-1');

        $response->assertStatus(200);
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $html = (string) $response->getContent();
        $this->assertStringContainsString('var SITE_ORIGIN = "https:\/\/shop.example.test" || \'/\';', $html);
        $this->assertStringContainsString('}, SITE_ORIGIN);', $html);
        $this->assertStringNotContainsString('window.location.origin', $html);
    }

    /**
     * 브리지의 모바일 복귀는 같은 출처 경로만 받는다.
     *
     * @scenario host_header=canonical, sink=bridge_page
     *
     * @effects bridge_mobile_return_accepts_same_origin_path_only
     *
     * @return void 반환값 없음
     */
    public function test_bridge_mobile_return_guard_is_present(): void
    {
        $html = (string) $this->get(self::SITE.self::BRIDGE_PATH.'?identity_error=9999')->getContent();

        $this->assertStringContainsString('function toSafeReturnPath(', $html);
        $this->assertStringContainsString("var dest = (stash && toSafeReturnPath(stash.return_url)) || '/';", $html);
    }

    /**
     * 잘못된 브리지 요청은 직전 페이지(Referer)·요청 Host 로 돌려보내지 않고 사이트 주소의 브리지에서 실패로 끝난다.
     *
     * @scenario host_header=forged, sink=bridge_page
     *
     * @effects invalid_bridge_request_ends_as_failure_on_site_bridge
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_invalid_bridge_request_ends_as_failure_on_site_bridge(string $root, array $server): void
    {
        $this->trustProxyFor($server);

        $response = $this->withServerVariables($server + ['HTTP_REFERER' => 'https://evil.example/landing'])
            ->get($root.self::BRIDGE_PATH.'?identity_error[]=x');

        $this->assertSame(self::SITE.self::BRIDGE_PATH.'?identity_error=UNKNOWN', $response->headers->get('Location'));
    }
}
