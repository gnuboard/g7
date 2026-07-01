<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterPgProviderListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class RegisterPgProviderListenerTest extends PluginTestCase
{
    public function test_live_standard_config_reports_unconfigured_when_live_mid_is_missing(): void
    {
        $this->mockSettings([
            'is_test_mode' => false,
            'live_mid' => '',
            'live_sign_key' => 'live-sign-key',
            'live_mobile_hash_key' => 'live-mobile-hash-key',
        ]);

        $config = (new RegisterPgProviderListener())->getClientConfig([], 'kginicis');

        $this->assertSame('', $config['mid']);
        $this->assertFalse($config['standard_configured']);
        $this->assertFalse($config['mobile_configured']);
    }

    public function test_client_config_exposes_enabled_easy_pay_methods_only(): void
    {
        $this->mockSettings([
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'test-sign-key',
            'test_mobile_hash_key' => 'test-mobile-hash-key',
            'easy_pay_samsung_pay' => false,
            'easy_pay_naverpay' => true,
            'easy_pay_lpay' => false,
            'easy_pay_kakaopay' => true,
        ]);

        $config = (new RegisterPgProviderListener())->getClientConfig([], 'kginicis');

        $this->assertTrue($config['standard_configured']);
        $this->assertTrue($config['mobile_configured']);
        $this->assertSame(['kginicis_naverpay', 'kginicis_kakaopay'], $config['easy_pay_enabled_methods']);
    }

    private function mockSettings(array $settings): void
    {
        $settingsService = $this->createMock(PluginSettingsService::class);
        $settingsService->method('get')->willReturn($settings);

        $this->app->instance(PluginSettingsService::class, $settingsService);
    }
}
