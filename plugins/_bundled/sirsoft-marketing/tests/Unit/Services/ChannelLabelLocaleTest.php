<?php

namespace Plugins\Sirsoft\Marketing\Tests\Unit\Services;

use Plugins\Sirsoft\Marketing\Listeners\MarketingConsentListener;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

/**
 * 동의 채널 라벨의 로케일 대응 회귀 테스트
 *
 * 채널 라벨은 `label_key` 가 SSoT 이고 `label` 맵은 언어팩 미설치 환경 보호용
 * 폴백이다. 그 폴백을 ko/en 두 개로 고정하면, 언어팩이 추가한 로케일(ja 등)에서
 * 같은 목록의 다른 항목은 번역되는데 이 항목만 한국어로 남는다.
 */
class ChannelLabelLocaleTest extends PluginTestCase
{
    /**
     * 폴백 라벨 맵은 런타임 지원 로케일 전체를 덮는다.
     */
    public function test_default_channel_label_map_covers_every_supported_locale(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        /** @var MarketingConsentService $service */
        $service = app(MarketingConsentService::class);

        foreach ($service->getDefaultSystemChannels() as $channel) {
            $this->assertArrayHasKey('label_key', $channel, '채널에 label_key 가 없다');

            foreach (['ko', 'en', 'ja'] as $locale) {
                $this->assertArrayHasKey(
                    $locale,
                    $channel['label'],
                    "채널 '{$channel['key']}' 의 폴백 라벨에 '{$locale}' 이 없다 — 그 로케일은 ko 로 떨어진다"
                );
            }
        }
    }

    /**
     * 구형 저장값(ko 만 담긴 맵)이어도 `label_key` 번역이 이긴다.
     *
     * 이 축이 없으면 생산부만 고쳐도 **이미 저장된 설정**은 계속 ko 로 떨어진다.
     */
    public function test_stored_legacy_label_map_is_overridden_by_label_key(): void
    {
        $listener = app(MarketingConsentListener::class);

        $method = new \ReflectionMethod($listener, 'resolveChannelLabel');
        $method->setAccessible(true);

        $legacyChannel = [
            'key'       => 'email_subscription',
            'label_key' => 'sirsoft-marketing::channels.email_subscription.label',
            // 저장 시점에 ko 만 담긴 구형 값
            'label'     => ['ko' => '광고성 이메일 수신'],
        ];

        $resolved = $method->invoke($listener, $legacyChannel, 'en');

        $this->assertSame(
            __('sirsoft-marketing::channels.email_subscription.label', [], 'en'),
            $resolved,
            '구형 저장 맵이 label_key 번역을 눌렀다 — 이미 저장된 설정은 계속 ko 로 떨어진다'
        );
        $this->assertNotSame('광고성 이메일 수신', $resolved);
    }

    /**
     * 번역이 없는 로케일에서는 저장된 맵으로 내려간다 (언어팩 미설치 환경 보호).
     */
    public function test_missing_translation_falls_back_to_stored_map(): void
    {
        $listener = app(MarketingConsentListener::class);

        $method = new \ReflectionMethod($listener, 'resolveChannelLabel');
        $method->setAccessible(true);

        $channel = [
            'key'       => 'custom_channel',
            'label_key' => 'sirsoft-marketing::channels.no_such_channel.label',
            'label'     => ['ko' => '저장된 라벨'],
        ];

        $this->assertSame(
            '저장된 라벨',
            $method->invoke($listener, $channel, 'ja'),
            '번역 부재 시 저장된 폴백 라벨이 쓰이지 않았다'
        );
    }

    /**
     * `label_key` 가 빠진 구형 저장분은 읽는 시점에 코드 정의로 다시 채워진다.
     *
     * 이 축이 없으면 생산부·소비부를 다 고쳐도 **이미 저장된 설정**은 계속 ko 로 떨어진다 —
     * 저장값에 `label_key` 가 없으면 소비부가 참조할 SSoT 자체가 없기 때문이다.
     */
    public function test_stored_channel_without_label_key_is_self_healed(): void
    {
        /** @var MarketingConsentService $service */
        $service = app(MarketingConsentService::class);

        // label_key 없이 저장된 구형 형태
        app(\App\Services\PluginSettingsService::class)->save('sirsoft-marketing', [
            'channels' => json_encode([
                [
                    'key' => 'email_subscription',
                    'label' => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
                    'page_slug' => '',
                    'enabled' => true,
                    'is_system' => true,
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $channels = $service->getAllChannels();

        $this->assertNotEmpty($channels);
        $this->assertSame(
            'sirsoft-marketing::channels.email_subscription.label',
            $channels[0]['label_key'] ?? null,
            'label_key 가 보강되지 않았다 — 구형 저장분은 계속 ko 로 떨어진다'
        );
    }

    /**
     * 지원 로케일이 ko/en 뿐이면 맵도 그 둘만 담는다 (불필요한 확장 금지).
     */
    public function test_label_map_follows_supported_locales_exactly(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        /** @var MarketingConsentService $service */
        $service = app(MarketingConsentService::class);

        foreach ($service->getDefaultSystemChannels() as $channel) {
            $this->assertSame(
                ['ko', 'en'],
                array_keys($channel['label']),
                '폴백 라벨 맵이 지원 로케일 집합과 어긋난다'
            );
        }
    }
}
