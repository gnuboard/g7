<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Controllers;

use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 본인인증 결과 전달 브리지 페이지에 실행형 인라인 코드가 없다 (#158 strict CSP)
 *
 * 종전에는 페이지 인라인 `<script>` 가 부모창 postMessage·창 닫기·모바일 복귀를 했다. 사이트가
 * `script-src 'self'` 를 걸면 실행되지 않아 인증 결과가 전달되지 않고 팝업도 닫히지 않았다. 이제 서버 값은
 * `#bridge-data` JSON 블록으로, 동작은 `assets/bridge/kcp-bridge.js` 가 한다.
 */
class BridgeNoInlineScriptTest extends PluginTestCase
{
    private const BRIDGE_PATH = '/plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/bridge';

    /**
     * 브리지 HTML — 인라인 실행 0, 데이터 블록 파싱 가능, 브리지 파일이 데이터 블록 뒤.
     *
     * @scenario page=identity_bridge
     *
     * @effects identity_bridge_has_no_inline_executable
     *
     * @return void 반환값 없음
     */
    public function test_브리지_페이지는_데이터_블록과_브리지_파일만_싣는다(): void
    {
        $html = (string) $this->get(self::BRIDGE_PATH.'?identity_error=9999')->assertOk()->getContent();

        $this->assertSame(0, preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html), 'on* 인라인 핸들러가 남아 있다');

        preg_match_all('#<script\b([^>]*)>(.*?)</script>#s', $html, $scripts, PREG_SET_ORDER);
        $data = null;
        foreach ($scripts as $script) {
            if (str_contains($script[1], 'type="application/json"')) {
                $data = json_decode($script[2], true);

                continue;
            }
            $this->assertSame('', trim($script[2]), '실행형 인라인 <script> 본문이 남아 있다');
        }

        $this->assertIsArray($data, '#bridge-data 블록이 JSON 으로 파싱되지 않는다');
        $this->assertArrayHasKey('siteOrigin', $data);
        $this->assertSame('9999', $data['payload']['identity_error'] ?? null);
        $this->assertLessThan(strpos($html, 'assets/bridge/kcp-bridge.js'), strpos($html, 'id="bridge-data"'), '데이터 블록이 브리지보다 먼저여야 한다');
    }
}
