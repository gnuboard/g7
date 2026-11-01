<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * PC 표준결제창 닫기 페이지(closeUrl)가 모드별 이니시스 닫기 스크립트를 싣고, 주문 화면 알림은 정적 브리지가 한다.
 *
 * 알림(postMessage)은 #158 에서 인라인 `<script>` 에서 `assets/bridge/payment-close.js` 로 옮겼다 — 브라우저
 * 저장소를 쓰지 않는다는 계약은 브리지 파일을 직접 읽어 확인한다.
 */
class PaymentCloseControllerTest extends PluginTestCase
{
    /**
     * 테스트 모드면 테스트 닫기 스크립트를 싣고, 브리지는 브라우저 저장소를 쓰지 않는다.
     *
     * @scenario page=payment_close
     *
     * @effects payment_close_uses_mode_script_and_bridge
     *
     * @return void 반환값 없음
     */
    public function test_close_page_uses_test_close_script_without_browser_storage(): void
    {
        $this->mockSettings(['is_test_mode' => true]);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/close');

        $response->assertOk();
        $response->assertHeaderMissing('Set-Cookie');
        $response->assertSee('https://stgstdpay.inicis.com/stdjs/INIStdPay_close.js', false);
        $response->assertSee('assets/bridge/payment-close.js', false);
        $response->assertDontSee('localStorage', false);
        $response->assertDontSee('document.cookie', false);

        $bridge = (string) file_get_contents(dirname(__DIR__, 3).'/assets/bridge/payment-close.js');
        $this->assertStringContainsString('postMessage', $bridge);
        $this->assertStringContainsString('payment-window-closed', $bridge);
        $this->assertStringNotContainsString('localStorage', $bridge);
        $this->assertStringNotContainsString('document.cookie', $bridge);
    }

    /**
     * 운영 모드면 운영 닫기 스크립트만 싣는다.
     *
     * @scenario page=payment_close
     *
     * @effects payment_close_uses_mode_script_and_bridge
     *
     * @return void 반환값 없음
     */
    public function test_close_page_uses_live_close_script_in_live_mode(): void
    {
        $this->mockSettings(['is_test_mode' => false]);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/close');

        $response->assertOk();
        $response->assertSee('https://stdpay.inicis.com/stdjs/INIStdPay_close.js', false);
        $response->assertDontSee('stgstdpay.inicis.com', false);
    }

    /**
     * 플러그인 설정 조회를 가짜로 바꿉니다.
     *
     * @param  array<string, mixed>  $settings  돌려줄 설정
     * @return void 반환값 없음
     */
    private function mockSettings(array $settings): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->with('sirsoft-pay_kginicis')
            ->willReturn($settings);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }
}
