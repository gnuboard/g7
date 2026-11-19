<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use App\Support\AssetUrl;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterPgProviderListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * KG 이니시스 PG 등록 리스너가 화면에 내려 주는 클라이언트 설정을 검증한다.
 */
class RegisterPgProviderListenerTest extends PluginTestCase
{
    /**
     * 운영 모드에서 운영 MID 가 비면 서명 키가 있어도 표준결제와 모바일결제를 미설정으로 알리는지 확인한다.
     *
     * @scenario mode=live
     *
     * @effects live_mode_without_live_mid_reports_unconfigured
     *
     * @return void 반환값 없음
     */
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

    /**
     * 테스트 모드 설정이 갖춰지면 준비 완료로 알리고, 간편결제는 켜 둔 네이버페이와 카카오페이만 싣는지 확인한다.
     *
     * @scenario mode=test
     *
     * @effects client_config_lists_only_enabled_easy_pay_methods
     *
     * @return void 반환값 없음
     */
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
     * 전달되는 통보 주소가 사이트 밖이 되고, 통보는 오류 없이 도착하지 않는다.
     *
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects pg_return_and_notify_addresses_follow_the_configured_site_url
     *
     * @return void 반환값 없음
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

    /**
     * 플러그인 설정 서비스를 주어진 설정을 돌려주는 목으로 바꿔 컨테이너에 넣는다.
     *
     * @param  array<string, mixed>  $settings  목이 돌려줄 플러그인 설정
     * @return void 반환값 없음
     */
    private function mockSettings(array $settings): void
    {
        $settingsService = $this->createMock(PluginSettingsService::class);
        $settingsService->method('get')->willReturn($settings);

        $this->app->instance(PluginSettingsService::class, $settingsService);
    }
}
