<?php

namespace Plugins\Sirsoft\Marketing\Tests\Feature\Http\Controllers;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

/**
 * 마케팅 공개 설정 API 컨트롤러 테스트
 *
 * GET /api/plugins/sirsoft-marketing/settings
 */
class MarketingSettingsControllerTest extends PluginTestCase
{
    /**
     * marketing_consent_enabled가 true이면 응답에 포함되는지 검증합니다.
     */
    public function test_settings_returns_marketing_consent_enabled(): void
    {
        $this->mockSettings(marketingConsentEnabled: true, channels: []);

        $response = $this->getJson('/api/plugins/sirsoft-marketing/settings');

        $response->assertOk()
            ->assertJsonPath('data.marketing_consent_enabled', true);
    }

    /**
     * marketing_consent_enabled가 false이면 false로 반환되는지 검증합니다.
     */
    public function test_settings_returns_marketing_consent_disabled(): void
    {
        $this->mockSettings(marketingConsentEnabled: false, channels: []);

        $response = $this->getJson('/api/plugins/sirsoft-marketing/settings');

        $response->assertOk()
            ->assertJsonPath('data.marketing_consent_enabled', false);
    }

    /**
     * channels 배열이 응답에 포함되는지 검증합니다.
     */
    public function test_settings_returns_channels_array(): void
    {
        $channels = [
            [
                'key'       => 'email_subscription',
                'label'     => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
                'page_slug' => '',
                'enabled'   => true,
                'is_system' => true,
            ],
        ];

        $this->mockSettings(marketingConsentEnabled: true, channels: $channels);

        $response = $this->getJson('/api/plugins/sirsoft-marketing/settings');

        $response->assertOk()
            ->assertJsonPath('data.channels.0.key', 'email_subscription')
            ->assertJsonPath('data.channels.0.enabled', true);
    }

    /**
     * 비활성화된 채널은 channels 배열에서 제외되는지 검증합니다.
     */
    public function test_settings_excludes_disabled_channels(): void
    {
        $channels = [
            [
                'key'       => 'email_subscription',
                'label'     => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
                'page_slug' => '',
                'enabled'   => false,
                'is_system' => true,
            ],
            [
                'key'       => 'sms_subscription',
                'label'     => ['ko' => 'SMS', 'en' => 'SMS'],
                'page_slug' => '',
                'enabled'   => true,
                'is_system' => false,
            ],
        ];

        $this->mockSettings(marketingConsentEnabled: true, channels: $channels);

        $response = $this->getJson('/api/plugins/sirsoft-marketing/settings');

        $response->assertOk();

        $responseChannels = $response->json('data.channels');
        $keys = array_column($responseChannels, 'key');

        $this->assertNotContains('email_subscription', $keys);
        $this->assertContains('sms_subscription', $keys);
    }

    /**
     * channels가 비어있으면 빈 배열을 반환하는지 검증합니다.
     */
    public function test_settings_returns_empty_channels_when_none_configured(): void
    {
        $this->mockSettings(marketingConsentEnabled: true, channels: []);

        $response = $this->getJson('/api/plugins/sirsoft-marketing/settings');

        $response->assertOk()
            ->assertJsonPath('data.channels', []);
    }

    /**
     * marketing_consent_terms_slug가 설정된 경우 slug_set이 true로 반환되는지 검증합니다.
     */
    public function test_settings_returns_terms_slug_set_true_when_slug_is_set(): void
    {
        $this->mockSettings(
            marketingConsentEnabled: true,
            channels: [],
            marketingConsentTermsSlug: 'marketing-terms'
        );

        $response = $this->getJson('/api/plugins/sirsoft-marketing/settings');

        $response->assertOk()
            ->assertJsonPath('data.marketing_consent_terms_slug', 'marketing-terms')
            ->assertJsonPath('data.marketing_consent_terms_slug_set', true);
    }

    /**
     * marketing_consent_terms_slug가 비어있는 경우 slug_set이 false로 반환되는지 검증합니다.
     */
    public function test_settings_returns_terms_slug_set_false_when_slug_is_empty(): void
    {
        $this->mockSettings(marketingConsentEnabled: true, channels: [], marketingConsentTermsSlug: '');

        $response = $this->getJson('/api/plugins/sirsoft-marketing/settings');

        $response->assertOk()
            ->assertJsonPath('data.marketing_consent_terms_slug', null)
            ->assertJsonPath('data.marketing_consent_terms_slug_set', false);
    }

    /**
     * 채널에 page_slug가 설정된 경우 terms_slug_set이 true로 반환되는지 검증합니다.
     */
    public function test_settings_channel_terms_slug_set_true_when_slug_set(): void
    {
        $channels = [
            [
                'key'       => 'email_subscription',
                'label'     => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
                'page_slug' => 'email-terms',
                'enabled'   => true,
                'is_system' => true,
            ],
        ];

        $this->mockSettings(marketingConsentEnabled: true, channels: $channels);

        $response = $this->getJson('/api/plugins/sirsoft-marketing/settings');

        $response->assertOk()
            ->assertJsonPath('data.channels.0.terms_slug', 'email-terms')
            ->assertJsonPath('data.channels.0.terms_slug_set', true);
    }

    /**
     * 인증 없이도 접근 가능한지 검증합니다 (공개 API).
     */
    public function test_settings_is_publicly_accessible_without_auth(): void
    {
        $this->mockSettings(marketingConsentEnabled: true, channels: []);

        $response = $this->getJson('/api/plugins/sirsoft-marketing/settings');

        $response->assertOk();
    }

    /**
     * PluginSettingsService mock을 설정합니다.
     *
     * @param bool $marketingConsentEnabled 마케팅 동의 활성화 여부
     * @param array<int, array> $channels 채널 목록
     * @param string $marketingConsentTermsSlug 마케팅 동의 약관 slug
     */
    private function mockSettings(
        bool $marketingConsentEnabled,
        array $channels,
        string $marketingConsentTermsSlug = ''
    ): void {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            function (string $id, string $key, mixed $default = null) use (
                $marketingConsentEnabled,
                $channels,
                $marketingConsentTermsSlug
            ) {
                return match ($key) {
                    'marketing_consent_enabled'    => $marketingConsentEnabled,
                    'marketing_consent_terms_slug' => $marketingConsentTermsSlug,
                    'channels'                     => json_encode($channels),
                    default                        => $default,
                };
            }
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }
}
