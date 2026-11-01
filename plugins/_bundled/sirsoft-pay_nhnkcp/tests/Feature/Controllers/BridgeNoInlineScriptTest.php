<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use Plugins\Sirsoft\PayNhnkcp\Http\Requests\KcpFrameRequest;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * PC 결제창 프레임 페이지에 실행형 인라인 코드가 없다 (#158 strict CSP)
 *
 * 종전에는 주문 화면이 빈 iframe 에 `document.write` 로 KCP SDK 와 인라인 `<script>` 를 써 넣었다. 사이트가
 * `script-src 'self'` 를 걸면 그 인라인 코드가 실행되지 않아 PC 결제가 15초 뒤 시간 초과로 끝났다. 이제 iframe 은
 * 같은 출처 라우트 `payment.kcp-frame` 을 열고, 페이지는 SDK `<script src>` → 브리지 `<script src>` 만 싣는다.
 * SDK 주소는 허용 두 개와 정확히 같아야 한다(다른 주소면 이 출처에서 임의 스크립트를 싣는 통로가 된다).
 */
class BridgeNoInlineScriptTest extends PluginTestCase
{
    private const FRAME = '/plugins/sirsoft-pay_nhnkcp/payment/kcp-frame';

    /**
     * 허용 SDK 주소마다 SDK → 폼 → 브리지 순서이고 인라인 실행 코드가 없다.
     *
     * @scenario page=kcp_frame
     *
     * @effects kcp_frame_loads_sdk_then_bridge_without_inline
     *
     * @return void 반환값 없음
     */
    public function test_프레임_페이지는_sdk_와_브리지만_싣는다(): void
    {
        foreach (KcpFrameRequest::ALLOWED_SDK_URLS as $sdk) {
            $response = $this->get(self::FRAME.'?sdk='.urlencode($sdk))->assertOk();
            $html = (string) $response->getContent();

            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertSame(0, preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html), 'on* 인라인 핸들러가 남아 있다');

            preg_match_all('#<script\b([^>]*)>(.*?)</script>#s', $html, $scripts, PREG_SET_ORDER);
            $this->assertCount(2, $scripts, 'SDK·브리지 두 개만 실려야 한다');
            foreach ($scripts as $script) {
                $this->assertSame('', trim($script[2]), '실행형 인라인 <script> 본문이 남아 있다');
            }

            $sdkAt = strpos($html, 'src="'.$sdk.'"');
            $formAt = strpos($html, '<form name="order_info">');
            $bridgeAt = strpos($html, 'assets/bridge/kcp-frame.js');
            $this->assertNotFalse($sdkAt);
            $this->assertNotFalse($formAt);
            $this->assertNotFalse($bridgeAt);
            $this->assertLessThan($formAt, $sdkAt, 'SDK 가 폼보다 먼저 동기 로드돼야 한다');
            $this->assertLessThan($bridgeAt, $formAt, '브리지가 폼 뒤에서 폼을 채워야 한다');
        }
    }

    /**
     * 허용 목록 밖 SDK 주소는 400 — 페이지를 그리지 않는다.
     *
     * @scenario page=kcp_frame
     *
     * @effects kcp_frame_rejects_untrusted_sdk
     *
     * @return void 반환값 없음
     */
    public function test_허용_밖_sdk_주소는_400(): void
    {
        foreach ([
            '',
            'https://evil.example.com/payplus_web.jsp',
            'https://pay.kcp.co.kr.evil.com/plugin/payplus_web.jsp',
            'http://pay.kcp.co.kr/plugin/payplus_web.jsp',
            'https://pay.kcp.co.kr/plugin/other.js',
            '"><script>alert(1)</script>',
        ] as $sdk) {
            $response = $this->get(self::FRAME.'?sdk='.urlencode($sdk));
            $response->assertStatus(400);
            $this->assertStringNotContainsString('<script', (string) $response->getContent());
        }
    }

    /**
     * SDK 가 이어서 불러오는 결제 연동 스크립트 호스트까지 신뢰 호스트로 선언한다.
     *
     * `payplus_web.jsp` 는 자기 호스트 밖 `npay`(web_cross_hub)·`spay`(kcp_spay_cross_hub) 계열에서 스크립트를
     * 더 싣는다. 코어 CSP 는 활성 확장 선언만 script-src 에 합치므로, 선언이 빠지면 강제 모드에서 그 스크립트가
     * 막혀 결제창이 열리지 않는다(2026-10-31 브라우저 실측에서 `testnpay.kcp.co.kr` 차단 확인).
     *
     * @scenario page=kcp_frame
     *
     * @effects kcp_sdk_secondary_hosts_declared_for_csp
     *
     * @return void 반환값 없음
     */
    public function test_sdk_가_싣는_연동_스크립트_호스트까지_선언한다(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/plugin.json'), true);
        $hosts = $manifest['trusted_script_hosts'] ?? [];
        $reasons = $manifest['trusted_script_hosts_reason'] ?? [];

        foreach (KcpFrameRequest::ALLOWED_SDK_URLS as $sdk) {
            $this->assertContains((string) parse_url($sdk, PHP_URL_HOST), $hosts);
        }
        foreach (['testnpay.kcp.co.kr', 'npay.kcp.co.kr', 'testspay.kcp.co.kr', 'spay.kcp.co.kr'] as $host) {
            $this->assertContains($host, $hosts, "{$host} 선언이 빠지면 CSP 강제 모드에서 결제창이 열리지 않는다");
            $this->assertNotEmpty($reasons[$host] ?? null, "{$host} 사유가 없다");
        }
    }
}
