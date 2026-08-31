<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use App\Support\AssetUrl;
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

        $config = (new RegisterPgProviderListener)->getClientConfig([], 'kginicis');

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

        $config = (new RegisterPgProviderListener)->getClientConfig([], 'kginicis');

        $this->assertTrue($config['standard_configured']);
        $this->assertTrue($config['mobile_configured']);
        $this->assertSame(['kginicis_naverpay', 'kginicis_kakaopay'], $config['easy_pay_enabled_methods']);
    }

    /**
     * CBT 편의점 결제의 입금통보 주소(`notiUrl`)는 요청이 아니라 **설정된 사이트 주소**를 따른다.
     *
     * `url()` 은 요청 호스트·접두사를 싣는다 — 앞단 프록시가 접두사를 벗기는 배치에서 PG 에
     * 전달되는 통보 주소가 사이트 밖이 되고, 통보는 오류 없이 도착하지 않는다 (16차 G3 라운드 B-5).
     *
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects pg_return_and_notify_addresses_follow_the_configured_site_url
     */
    public function test_cbt_cvs_noti_url_follows_the_configured_site_url(): void
    {
        config(['app.url' => 'https://example.com/g7']);
        $this->mockSettings(['is_test_mode' => true]);

        try {
            $config = (new RegisterPgProviderListener)->getClientConfig([], 'kginicis');
        } finally {
            AssetUrl::forceBasePrefix(null);
        }

        $this->assertSame(
            'https://example.com/g7/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify',
            $config['cbt_extra_data']['payment']['cvs']['notiUrl'],
        );
    }

    private function mockSettings(array $settings): void
    {
        $settingsService = $this->createMock(PluginSettingsService::class);
        $settingsService->method('get')->willReturn($settings);

        $this->app->instance(PluginSettingsService::class, $settingsService);
    }
}
