<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 결제창 닫기·에스크로 구매결정 페이지에 실행형 인라인 코드가 없다 (#158 strict CSP)
 *
 * 이 플러그인이 직접 내는 독립 HTML(결제창 닫기 closeUrl · 에스크로 확인창·결과·닫기)은 종전에 인라인
 * `<script>` 로 동작했다. 사이트가 `script-src 'self'` 를 걸면 실행되지 않아 결제창을 닫아도 주문 화면이
 * '결제 중' 에 머물고, 구매결정 창이 뜨지 않거나 닫히지 않았다. 동작은 `assets/bridge/*.js` 정적 파일로 옮겼다.
 */
class BridgeNoInlineScriptTest extends PluginTestCase
{
    private const BASE = '/plugins/sirsoft-pay_kginicis/payment/escrow-confirm';

    private const TID = 'StdpayESCRWINIpayTest20261031000001';

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
     * 응답 HTML 에 실행형 인라인 `<script>`·on* 속성이 없고 지정한 브리지 파일을 싣는지 검사합니다.
     *
     * @param  string  $html  응답 HTML
     * @param  string  $bridge  기대하는 `assets/bridge/` 아래 파일명
     * @return void 반환값 없음
     */
    private function assertBridgeOnly(string $html, string $bridge): void
    {
        $this->assertSame(0, preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html), 'on* 인라인 핸들러가 남아 있다');

        preg_match_all('#<script\b([^>]*)>(.*?)</script>#s', $html, $scripts, PREG_SET_ORDER);
        foreach ($scripts as $script) {
            if (str_contains($script[1], 'type="application/json"')) {
                continue;
            }
            $this->assertSame('', trim($script[2]), '실행형 인라인 <script> 본문이 남아 있다: '.substr(trim($script[2]), 0, 60));
        }

        $this->assertStringContainsString('assets/bridge/'.$bridge, $html);
    }

    /**
     * 로그인 회원의 에스크로 결제 주문을 만듭니다.
     *
     * @return User 주문한 회원
     */
    private function makeEscrowOrder(): User
    {
        $user = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $user->id, 'order_number' => 'ORD-CSP-1', 'currency' => 'KRW']);
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
     * 결제창 닫기 페이지 — 브리지(알림)가 이니시스 닫기 스크립트보다 먼저 온다.
     *
     * @scenario page=payment_close
     *
     * @effects bridge_page_has_no_inline_executable
     *
     * @return void 반환값 없음
     */
    public function test_결제창_닫기_페이지(): void
    {
        $html = (string) $this->get('/plugins/sirsoft-pay_kginicis/payment/close')->assertOk()->getContent();

        $this->assertBridgeOnly($html, 'payment-close.js');
        $this->assertLessThan(strpos($html, 'INIStdPay_close.js'), strpos($html, 'payment-close.js'), '알림 브리지가 닫기 스크립트보다 뒤에 있다');

        $bridge = (string) file_get_contents(dirname(__DIR__, 3).'/assets/bridge/payment-close.js');
        $this->assertStringContainsString("type: 'payment-window-closed'", $bridge);
        $this->assertStringContainsString('postMessage(payload, targetOrigin)', $bridge);
    }

    /**
     * 에스크로 결과·닫기 페이지 — data-mode 로 동작을 고른다.
     *
     * @scenario page=escrow_return
     *
     * @effects bridge_page_has_no_inline_executable
     *
     * @return void 반환값 없음
     */
    public function test_에스크로_결과와_닫기_페이지(): void
    {
        $return = (string) $this->post(self::BASE.'/pc/return', ['tid' => self::TID, 'ResultCode' => '00'])->assertOk()->getContent();
        $this->assertBridgeOnly($return, 'escrow-confirm.js');
        $this->assertStringContainsString('data-mode="pc-return"', $return);

        $close = (string) $this->get(self::BASE.'/close')->assertOk()->getContent();
        $this->assertBridgeOnly($close, 'escrow-confirm.js');
        $this->assertStringContainsString('data-mode="close"', $close);
    }

    /**
     * 에스크로 확인창 — PC 는 이니시스 스크립트 다음에 pc-open, 모바일은 폼 자동 제출(mobile-submit).
     *
     * @scenario page=escrow_confirm
     *
     * @effects bridge_page_has_no_inline_executable
     *
     * @return void 반환값 없음
     */
    public function test_에스크로_확인창(): void
    {
        $user = $this->makeEscrowOrder();

        $pc = (string) $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0)')
            ->get(self::BASE.'/ORD-CSP-1')->assertOk()->getContent();
        $this->assertBridgeOnly($pc, 'escrow-confirm.js');
        $this->assertStringContainsString('data-mode="pc-open"', $pc);
        $this->assertStringContainsString('data-form="escrow_confirm_form"', $pc);
        $this->assertLessThan(strpos($pc, 'escrow-confirm.js'), strpos($pc, 'INIStdPay_escrow_conf.js'), '이니시스 스크립트가 브리지보다 먼저여야 한다');

        $mobile = (string) $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148')
            ->get(self::BASE.'/ORD-CSP-1')->assertOk()->getContent();
        $this->assertBridgeOnly($mobile, 'escrow-confirm.js');
        $this->assertStringContainsString('data-mode="mobile-submit"', $mobile);
        $this->assertStringContainsString('data-form="mobile_confirm_form"', $mobile);
    }
}
