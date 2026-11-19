<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Mock;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\Http\Controllers\LoginController;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 이 플러그인이 직접 내는 독립 HTML 은 인라인 스타일을 쓰지 않는다 — 스타일 보안 정책 호환
 *
 * 사이트가 `style-src 'self'; style-src-attr 'none'` 을 걸면 `<style>` 요소와 `style="…"` 속성이 막힌다(코어 #163, 7.1.6).
 * SAML 자동 제출 화면·연결 테스트 결과 화면·모의 IdP 승인 화면(OIDC·SAML)은 모양을 `<style>` 블록으로 실어 그 정책에서
 * 무스타일로 떴다. 규칙은 같은 출처 정적 파일(`assets/bridge/*.css`)로 옮기고 `<head>` 에는 `<link>` 하나만 둔다.
 *
 * @since 1.0.0
 */
class StandalonePagesNoInlineStyleTest extends PluginTestCase
{
    /**
     * 응답 HTML 에 `<style>`·`style` 속성이 없고 `<head>` 에서 지정한 CSS 파일 하나만 싣는지 검사합니다.
     *
     * @param  string  $html  응답 HTML
     * @param  string  $stylesheet  기대하는 `assets/bridge/` 아래 파일명
     * @return void 반환값 없음
     */
    private function assertStylesheetOnly(string $html, string $stylesheet): void
    {
        $this->assertSame(0, preg_match('/<style\b/i', $html), '<style> 요소가 남아 있다');
        $this->assertSame(0, preg_match('/<[a-z][^>]*\sstyle\s*=/i', $html), 'style 속성이 남아 있다');

        $head = substr($html, 0, (int) strpos($html, '</head>'));
        $this->assertSame(1, substr_count($head, '<link rel="stylesheet"'), '<head> 에 스타일시트 링크가 정확히 1개여야 한다');
        $this->assertMatchesRegularExpression(
            '#<link rel="stylesheet" href="[^"]*sirsoft-sso[^"]*assets/bridge/'.preg_quote($stylesheet, '#').'[^"]*">#',
            $head,
        );
    }

    /**
     * 비공개 화면 조립 메서드를 부릅니다(모의 승인 화면·결과 화면은 라우트 앞단 검증을 거쳐야 닿는다 — 조립만 본다).
     *
     * @param  object  $controller  컨트롤러
     * @param  string  $method  메서드 이름
     * @param  array<int, mixed>  $args  인자
     * @return Response 화면
     */
    private function invokePage(object $controller, string $method, array $args): Response
    {
        $ref = new \ReflectionMethod($controller, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($controller, $args);
    }

    /**
     * SAML POST 바인딩 자동 제출 화면.
     *
     * @scenario page=saml_autosubmit
     *
     * @effects standalone_page_has_no_inline_style, standalone_page_links_stylesheet_in_head
     *
     * @return void 반환값 없음
     */
    public function test_saml_autosubmit_page_links_stylesheet_only(): void
    {
        $html = SamlDriver::autoSubmitHtml('https://idp.example.test/sso', ['SAMLRequest' => 'abc']);

        $this->assertStylesheetOnly($html, 'saml-autosubmit.css');
        // 자동 제출 동작(정적 스크립트)과 폼은 종전 그대로
        $this->assertStringContainsString('assets/bridge/saml-autosubmit.js', $html);
        $this->assertStringContainsString('<input type="hidden" name="SAMLRequest" value="abc">', $html);
    }

    /**
     * 연결 테스트 결과 화면.
     *
     * @scenario page=test_result
     *
     * @effects standalone_page_has_no_inline_style, standalone_page_links_stylesheet_in_head
     *
     * @return void 반환값 없음
     */
    public function test_test_result_page_links_stylesheet_only(): void
    {
        $response = $this->invokePage(app(LoginController::class), 'page', ['결과', '<p class="ok">성공</p><dl><dt>a</dt><dd>b</dd></dl>']);

        $this->assertStylesheetOnly((string) $response->getContent(), 'test-result.css');
        // 지시어로 잰다 — HttpFoundation 은 public·private 이 없으면 `, private` 를 덧붙여 내보낸다
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 모의 OIDC·SAML IdP 승인 화면 — 두 화면이 같은 파일을 쓴다.
     *
     * @scenario page=mock_idp_consent
     *
     * @effects standalone_page_has_no_inline_style, standalone_page_links_stylesheet_in_head
     *
     * @return void 반환값 없음
     */
    public function test_mock_idp_consent_pages_link_stylesheet_only(): void
    {
        $oidc = $this->invokePage(app(MockOidcController::class), 'consentPage', [Request::create('/mock/oidc/authorize', 'GET', ['client_id' => 'c1'])]);
        $this->assertStylesheetOnly((string) $oidc->getContent(), 'mock-idp.css');
        $this->assertStringContainsString('<h1>Mock OIDC IdP</h1>', (string) $oidc->getContent());

        $saml = $this->invokePage(app(MockSamlController::class), 'consentPage', [['SAMLRequest' => 'x']]);
        $this->assertStylesheetOnly((string) $saml->getContent(), 'mock-idp.css');
        $this->assertStringContainsString('<h1>Mock SAML IdP</h1>', (string) $saml->getContent());
    }

    /**
     * 옮긴 CSS 파일에 종전 `<style>` 블록의 규칙이 그대로 있다.
     *
     * @scenario page=stylesheet_files
     *
     * @effects stylesheet_files_keep_original_rules
     *
     * @return void 반환값 없음
     */
    public function test_stylesheet_files_keep_original_rules(): void
    {
        $dir = dirname(__DIR__, 3).'/assets/bridge/';
        $expect = [
            'saml-autosubmit.css' => ['max-width:28rem;margin:3rem auto', 'text-align:center', 'button{margin-top:1rem;padding:.6rem 1.2rem}'],
            'test-result.css' => [':root{color-scheme:light dark}', 'max-width:40rem', 'dl{display:grid;', '.ok{color:#15803d}', '.bad{color:#b91c1c}', '.note{'],
            'mock-idp.css' => ['max-width:28rem;margin:2rem auto', 'label{display:block;', 'input,select{width:100%;', 'button{margin-top:1rem;padding:.6rem 1rem}'],
        ];
        foreach ($expect as $file => $needles) {
            $this->assertFileExists($dir.$file);
            $css = (string) file_get_contents($dir.$file);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $css, "{$file} 에 {$needle} 규칙이 없다");
            }
        }
    }
}
