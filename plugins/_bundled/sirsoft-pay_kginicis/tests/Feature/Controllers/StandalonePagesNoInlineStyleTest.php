<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Extension\PluginManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 이 플러그인이 직접 내는 독립 HTML 은 인라인 스타일을 쓰지 않는다 — 스타일 보안 정책 호환
 *
 * 사이트가 `style-src 'self'; style-src-attr 'none'` 을 걸면 `<style>` 요소와 `style="…"` 속성이 막힌다. 에스크로
 * 구매확인 창(PC·모바일)은 「준비 중」 안내를 화면 가운데에 두는 규칙을 `<style>` 로 실어, 정책을 건 사이트에서는
 * 안내가 왼쪽 위 구석에 맨 글자로 떴다. 규칙은 같은 출처 정적 파일(`assets/bridge/escrow-confirm.css`)로 옮겼다.
 */
class StandalonePagesNoInlineStyleTest extends PluginTestCase
{
    private const BASE = '/plugins/sirsoft-pay_kginicis/payment/escrow-confirm';

    private const TID = 'StdpayESCRWINIpayTest20261111000001';

    private const ORDER_NUMBER = 'ORD-STYLE-1';

    /**
     * PG API 서비스를 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $api = $this->createMock(KgInicisApiService::class);
        $api->method('isTestMode')->willReturn(true);
        $api->method('getMid')->willReturn('iniescrow0');
        $api->method('getEscrowConfirmMKey')->willReturn('mkey');
        $this->app->instance(KgInicisApiService::class, $api);
    }

    /**
     * 로그인 회원의 에스크로 결제 주문을 만듭니다.
     *
     * @return User 주문한 회원
     */
    private function makeEscrowOrder(): User
    {
        $user = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $user->id, 'order_number' => self::ORDER_NUMBER, 'currency' => 'KRW']);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'is_escrow' => true,
            'transaction_id' => self::TID,
            'payment_meta' => [],
        ]);

        return $user;
    }

    /**
     * 응답 HTML 에 `<style>`·`style` 속성이 없고 `<head>` 에서 지정한 CSS 파일을 싣는지 검사합니다.
     *
     * @param  string  $html  응답 HTML
     * @param  string  $stylesheet  기대하는 `assets/bridge/` 아래 파일명
     * @return void 반환값 없음
     */
    private function assertStylesheetOnly(string $html, string $stylesheet): void
    {
        $this->assertSame(0, preg_match('/<style\b/i', $html), '<style> 요소가 남아 있다');
        $this->assertSame(0, preg_match('/<[a-z][^>]*\sstyle\s*=/i', $html), 'style 속성이 남아 있다');
        $this->assertSame(
            1,
            preg_match_all('#<link rel="stylesheet" href="[^"]*assets/bridge/'.preg_quote($stylesheet, '#').'[^"]*">#', $html),
            'CSS 링크가 정확히 한 번 실려야 한다'
        );

        $headEnd = strpos($html, '</head>');
        $linkAt = strpos($html, 'assets/bridge/'.$stylesheet);
        $this->assertNotFalse($headEnd);
        $this->assertNotFalse($linkAt);
        $this->assertLessThan($headEnd, $linkAt, 'CSS 는 <head> 에서 실려야 무스타일로 깜빡이지 않는다');
    }

    /**
     * PC 구매확인 창 — 안내 문구 배치는 CSS 파일이 맡는다.
     *
     * @scenario page=escrow_confirm_pc
     *
     * @effects standalone_page_has_no_inline_style, standalone_page_links_stylesheet_in_head
     *
     * @return void 반환값 없음
     */
    public function test_pc_구매확인_창은_인라인_스타일_없이_css_파일로_꾸민다(): void
    {
        $user = $this->makeEscrowOrder();

        $html = (string) $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0)')
            ->get(self::BASE.'/'.self::ORDER_NUMBER)->assertOk()->getContent();

        $this->assertStringContainsString('data-mode="pc-open"', $html);
        $this->assertStylesheetOnly($html, 'escrow-confirm.css');
    }

    /**
     * 모바일 구매확인 창 — PC 와 같은 CSS 파일을 쓴다.
     *
     * @scenario page=escrow_confirm_mobile
     *
     * @effects standalone_page_has_no_inline_style, standalone_page_links_stylesheet_in_head
     *
     * @return void 반환값 없음
     */
    public function test_모바일_구매확인_창은_인라인_스타일_없이_css_파일로_꾸민다(): void
    {
        $user = $this->makeEscrowOrder();

        $html = (string) $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148')
            ->get(self::BASE.'/'.self::ORDER_NUMBER)->assertOk()->getContent();

        $this->assertStringContainsString('data-mode="mobile-submit"', $html);
        $this->assertStylesheetOnly($html, 'escrow-confirm.css');
    }

    /**
     * 결제창 CSS 를 싣는 결제사 호스트를 스타일 정책 자동 허용 대상으로 선언한다.
     *
     * 이니시스 결제창·구매확인 창은 결제사 스크립트가 자기 호스트의 CSS 파일을 스스로 싣는다. 선언이 빠지면 스타일
     * 정책을 강제한 사이트에서 결제창이 화면을 덮지 못하고 페이지 아래 띠로 밀려 결제할 수 없다(2026-11-11 실측).
     * 스크립트 선언과 같은 호스트 집합이어야 하고(결제사가 SDK 와 CSS 를 같은 호스트에서 내준다) 사유가 있어야 한다.
     *
     * @scenario page=payment_window
     *
     * @effects payment_window_style_hosts_declared_for_csp
     *
     * @return void 반환값 없음
     */
    public function test_결제창_css_호스트를_스타일_정책에_선언한다(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/plugin.json'), true);
        $styleHosts = $manifest['trusted_style_hosts'] ?? [];
        $reasons = $manifest['trusted_style_hosts_reason'] ?? [];

        $this->assertEqualsCanonicalizing(['stdpay.inicis.com', 'stgstdpay.inicis.com'], $styleHosts);
        $this->assertEqualsCanonicalizing($manifest['trusted_script_hosts'] ?? [], $styleHosts, '스크립트 선언과 스타일 선언의 호스트가 어긋났다');
        foreach ($styleHosts as $host) {
            $this->assertNotEmpty($reasons[$host] ?? null, "{$host} 사유가 없다");
        }

        // 켜진 플러그인의 선언이 실제로 읽힌다
        $plugin = app(PluginManager::class)->getPlugin('sirsoft-pay_kginicis');
        $this->assertNotNull($plugin);
        $this->assertEqualsCanonicalizing($styleHosts, $plugin->getTrustedStyleHosts());
    }

    /**
     * 옮긴 CSS 파일에 종전 `<style>` 의 규칙이 그대로 있다.
     *
     * @scenario page=stylesheet_files
     *
     * @effects stylesheet_files_keep_original_rules
     *
     * @return void 반환값 없음
     */
    public function test_css_파일에_종전_규칙이_있다(): void
    {
        $dir = dirname(__DIR__, 3).'/assets/bridge/';

        $escrow = (string) file_get_contents($dir.'escrow-confirm.css');
        $this->assertMatchesRegularExpression(
            '/body\s*\{[^}]*font-family:\s*sans-serif;[^}]*display:\s*flex;[^}]*align-items:\s*center;[^}]*justify-content:\s*center;[^}]*height:\s*100vh;[^}]*margin:\s*0;[^}]*background:\s*#f5f5f5;/',
            $escrow
        );
        $this->assertMatchesRegularExpression('/\bp\s*\{[^}]*color:\s*#666;[^}]*font-size:\s*14px;/', $escrow);

        $receipt = (string) file_get_contents($dir.'receipt-popup.css');
        foreach (['body', 'main', 'h1', '.notice', 'dl', '.row', 'dt', 'dd', '.actions', 'button'] as $selector) {
            $this->assertMatchesRegularExpression('/(^|\})\s*'.preg_quote($selector, '/').'\s*\{/m', $receipt, "{$selector} 규칙이 없다");
        }
        $this->assertMatchesRegularExpression('/main\s*\{[^}]*max-width:\s*720px;[^}]*border:\s*1px solid #e5e7eb;/', $receipt);
        $this->assertMatchesRegularExpression('/@media \(max-width: 520px\)\s*\{[^@]*\.row\s*\{[^}]*grid-template-columns:\s*1fr;/', $receipt);
    }
}
