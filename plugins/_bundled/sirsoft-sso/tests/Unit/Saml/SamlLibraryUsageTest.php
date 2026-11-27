<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Saml;

use App\Support\SiteUrl;
use OneLogin\Saml2\Utils;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlEnvironment;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Symfony\Component\Finder\Finder;

/**
 * SAML 라이브러리 사용 규율 — 자체 XML 서명 처리 0, `exit` 하는 호출 0(계획서 부록 A-7), 현재 주소는 사이트 주소 기준.
 *
 * @since 1.0.0
 */
class SamlLibraryUsageTest extends PluginTestCase
{
    /**
     * 플러그인 src(모의 IdP 제외)에 XML 서명을 직접 다루는 코드와 `processResponse()`, `stay` 없는 `login()`/`logout()` 이 없다.
     *
     * @scenario scan=src_without_mock
     *
     * @effects forbidden_pattern_count_zero
     *
     * @return void 반환값 없음
     */
    public function test_no_self_signature_handling_or_exiting_calls(): void
    {
        $pattern = '/DOMXPath|XMLSecurityDSig|C14N|getElementsByTagNameNS\(\'.*Signature|processResponse\(|->login\([^)]*\)\s*;|->logout\([^)]*\)\s*;/';
        $hits = [];

        foreach ((new Finder)->files()->in(dirname(__DIR__, 3).'/src')->name('*.php')->exclude('Mock') as $file) {
            foreach (preg_split('/\r\n|\r|\n/', $file->getContents()) ?: [] as $no => $line) {
                if (preg_match($pattern, $line) === 1 && ! str_contains($line, 'stay')) {
                    $hits[] = $file->getRelativePathname().':'.($no + 1);
                }
            }
        }

        $this->assertSame([], $hits);
    }

    /**
     * 라이브러리가 쓰는 현재 주소는 요청 Host 가 아니라 사이트 주소이고, 실행 뒤 전역 값은 원래대로 돌아온다.
     *
     * @scenario host_header=spoofed
     *
     * @effects base_url_from_site_url, superglobals_restored
     *
     * @return void 반환값 없음
     */
    public function test_environment_uses_site_url_and_restores_globals(): void
    {
        $server = $_SERVER;
        $get = $_GET;
        try {
            $_SERVER['HTTP_HOST'] = 'evil.example';
            $_SERVER['REQUEST_URI'] = '/elsewhere?x=1';
            $_GET = ['keep' => '1'];

            $seen = SamlEnvironment::run('/plugins/sirsoft-sso/saml/corp/acs', null, fn () => Utils::getSelfRoutedURLNoQuery());

            $this->assertSame(SiteUrl::absolute('/plugins/sirsoft-sso/saml/corp/acs'), $seen);
            $this->assertSame('/elsewhere?x=1', $_SERVER['REQUEST_URI']);
            $this->assertSame(['keep' => '1'], $_GET);
        } finally {
            $_SERVER = $server;
            $_GET = $get;
        }
    }

    /**
     * POST 바인딩 자동 제출 페이지는 인라인 실행 코드 없이 정적 파일로 폼을 제출한다 — strict CSP(script-src 'self')에서도
     * IdP·SP 로 넘어간다(공개 #158). 종전에는 `<body onload>` 라 CSP 를 건 사이트에서 이 페이지에 멈췄다.
     *
     * @scenario binding=post
     *
     * @effects post_binding_autosubmit_without_inline_handler
     *
     * @return void 반환값 없음
     */
    public function test_post_binding_autosubmit_has_no_inline_handler(): void
    {
        $html = SamlDriver::autoSubmitHtml('https://idp.example.test/sso', ['SAMLRequest' => 'abc', 'RelayState' => 'r1']);

        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)[^>]*>#i', $html);
        $this->assertStringContainsString('assets/bridge/saml-autosubmit.js', $html);
        $this->assertStringContainsString('<input type="hidden" name="SAMLRequest" value="abc">', $html);
        $this->assertStringContainsString('<noscript><button type="submit">', $html);

        $bridge = (string) file_get_contents(dirname(__DIR__, 3).'/assets/bridge/saml-autosubmit.js');
        $this->assertStringContainsString('document.forms[0]', $bridge);
        $this->assertStringContainsString('form.submit()', $bridge);
    }
}
