<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;
use Tests\Concerns\ForgesRequestHost;

/**
 * 이니시스 본인확인 결과가 요청 Host 가 아니라 사이트 주소로만 전달되는지 검증합니다.
 *
 * 콜백 뒤 브리지 이동·브리지의 부모창 전달 대상·프런트가 이니시스로 보내는 결과 수신 주소가
 * 요청 Host 나 브라우저의 현재 주소를 따르면, 위조 Host 로 시작한 인증의 검증 토큰이 다른 도메인으로
 * 간다.
 */
class InicisHostTrustTest extends PluginTestCase
{
    use ForgesRequestHost;

    private const CALLBACK_PATH = '/plugins/sirsoft-verification_kginicis/plugin/inicis/callback';

    private const BRIDGE_PATH = '/plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge';

    /**
     * 사이트 주소를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pinForgedHostSite();
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->resetForgedHostProxies();
        parent::tearDown();
    }

    /**
     * 콜백(실패 결과) 뒤 브리지 이동은 사이트 주소다 — 사이트 주소 Host 에서도 같은 값이다.
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
        $this->trustProxyForForgedHost($server);

        $response = $this->withServerVariables($server)->post($root.self::CALLBACK_PATH, []);

        $response->assertRedirect();
        $this->assertOnForgedHostSite($response->headers->get('Location'), self::BRIDGE_PATH.'?');
    }

    /**
     * 사이트 주소 Host 의 콜백도 사이트 주소 브리지로 간다.
     *
     * @scenario host_header=canonical, sink=bridge_redirect
     *
     * @effects callback_redirects_to_site_bridge
     *
     * @return void 반환값 없음
     */
    public function test_canonical_callback_redirects_to_site_bridge(): void
    {
        $response = $this->post(self::forgedHostSite().self::CALLBACK_PATH, []);

        $this->assertOnForgedHostSite($response->headers->get('Location'), self::BRIDGE_PATH.'?identity_error=');
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
        $this->trustProxyForForgedHost($server);

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
        $html = (string) $this->get(self::forgedHostSite().self::BRIDGE_PATH.'?identity_error=X')->getContent();

        $this->assertStringContainsString('function toSafeReturnPath(', $html);
        $this->assertStringContainsString("var dest = (stash && toSafeReturnPath(stash.return_url)) || '/';", $html);
    }

    /**
     * 프런트에 내려가는 결과 수신 주소와 출처 검증 기준은 사이트 주소다(브라우저 현재 주소가 아니다).
     *
     * @scenario host_header=forged, sink=provider_callback_url
     *
     * @effects challenge_payload_carries_site_callback_url
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_challenge_payload_carries_site_callback_url(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/api/identity/challenges');

        $challenge = app(InicisIdentityProvider::class)
            ->requestChallenge(['email' => 'guest@example.test'], ['purpose' => 'signup']);

        $this->assertSame(self::forgedHostSite().self::CALLBACK_PATH, $challenge->publicPayload['callbackUrl'] ?? null);
        $this->assertSame(self::forgedHostSite(), $challenge->publicPayload['siteOrigin'] ?? null);
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
        $this->trustProxyForForgedHost($server);

        $response = $this->withServerVariables($server + ['HTTP_REFERER' => 'https://evil.example/landing'])
            ->get($root.self::BRIDGE_PATH.'?verification_token[]=x');

        $this->assertSame(self::forgedHostSite().self::BRIDGE_PATH.'?identity_error=UNKNOWN', $response->headers->get('Location'));
    }
}
