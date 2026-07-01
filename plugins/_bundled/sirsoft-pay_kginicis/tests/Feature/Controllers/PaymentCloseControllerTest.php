<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class PaymentCloseControllerTest extends PluginTestCase
{
    public function test_close_page_uses_test_close_script_without_browser_storage(): void
    {
        $this->mockSettings(['is_test_mode' => true]);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/close');

        $response->assertOk();
        $response->assertHeaderMissing('Set-Cookie');
        $response->assertSee('https://stgstdpay.inicis.com/stdjs/INIStdPay_close.js', false);
        $response->assertSee('postMessage', false);
        $response->assertSee('payment-window-closed', false);
        $response->assertDontSee('localStorage', false);
        $response->assertDontSee('document.cookie', false);
    }

    public function test_close_page_uses_live_close_script_in_live_mode(): void
    {
        $this->mockSettings(['is_test_mode' => false]);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/close');

        $response->assertOk();
        $response->assertSee('https://stdpay.inicis.com/stdjs/INIStdPay_close.js', false);
        $response->assertDontSee('stgstdpay.inicis.com', false);
    }

    private function mockSettings(array $settings): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->with('sirsoft-pay_kginicis')
            ->willReturn($settings);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }
}
