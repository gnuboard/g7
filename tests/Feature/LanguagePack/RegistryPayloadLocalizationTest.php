<?php

namespace Tests\Feature\LanguagePack;

use App\Services\NotificationChannelService;
use Tests\TestCase;

/**
 * Provider/Registry 페이로드의 name_key 라벨이 활성 locale 로 해석되는지 검증.
 *
 * 검증 시나리오:
 *   1. NotificationChannelService::getAvailableChannels() 가 ko 활성 시 한국어 라벨 반환
 *   2. 동일 메서드가 en 활성 시 영어 라벨 반환
 *   3. name_key 미존재 lang key 의 경우 안전한 fallback (빈 문자열 / 키 그대로) 처리
 *   4. config/notification.php default_channels 가 다국어 JSON 직접 보유 안 함 (회귀 가드)
 */
class RegistryPayloadLocalizationTest extends TestCase
{
    public function test_notification_channels_resolve_to_korean_when_locale_ko(): void
    {
        app()->setLocale('ko');
        $service = app(NotificationChannelService::class);

        $channels = $service->getAvailableChannels();
        $byId = collect($channels)->keyBy('id');

        $this->assertSame('메일', $byId['mail']['name'] ?? null);
        $this->assertSame('이메일로 알림 발송', $byId['mail']['description'] ?? null);
        $this->assertSame('코어 기본 채널', $byId['mail']['source_label'] ?? null);
        $this->assertSame('사이트내 알림', $byId['database']['name'] ?? null);
    }

    public function test_notification_channels_resolve_to_english_when_locale_en(): void
    {
        app()->setLocale('en');
        $service = app(NotificationChannelService::class);

        $channels = $service->getAvailableChannels();
        $byId = collect($channels)->keyBy('id');

        $this->assertSame('Email', $byId['mail']['name'] ?? null);
        $this->assertSame('Send notification via email', $byId['mail']['description'] ?? null);
        $this->assertSame('Core default channel', $byId['mail']['source_label'] ?? null);
        $this->assertSame('Site Notification', $byId['database']['name'] ?? null);
    }

    public function test_notification_channels_preserve_name_key_for_lang_pack_resolution(): void
    {
        $service = app(NotificationChannelService::class);
        $channels = $service->getAvailableChannels();
        $mail = collect($channels)->firstWhere('id', 'mail');

        // name_key 는 그대로 유지되어 lang pack(ja 등) 활성 시 보강 가능
        $this->assertSame('notification.channels.mail.name', $mail['name_key'] ?? null);
        $this->assertSame('notification.channels.mail.description', $mail['description_key'] ?? null);
        $this->assertSame('notification.channels.core_default', $mail['source_label_key'] ?? null);
    }

    public function test_default_channels_config_does_not_carry_multilingual_json_directly(): void
    {
        $defaults = config('notification.default_channels', []);

        foreach ($defaults as $channel) {
            $this->assertArrayNotHasKey(
                'name',
                $channel,
                "default_channels[{$channel['id']}] 가 다국어 JSON 'name' 직접 보유 — name_key 패턴 위반 회귀",
            );
            $this->assertArrayHasKey(
                'name_key',
                $channel,
                "default_channels[{$channel['id']}] 에 name_key 누락",
            );
        }
    }

    /**
     * ja 활성 + 활성 언어팩(g7-core-ja) 의 notification.channels 키가 보강된 시나리오.
     *
     * lang pack 빌드로 채워진 ja 라벨이 표시되는지 end-to-end 검증.
     * lang pack 미설치 환경에서는 skip — 회귀 본체(현장 보고)는 lang pack 활성화 후 발생.
     */
    public function test_notification_channels_resolve_to_japanese_when_lang_pack_active(): void
    {
        $jaValue = __('notification.channels.mail.name', [], 'ja');
        $koValue = __('notification.channels.mail.name', [], 'ko');

        // ja 키가 lang pack 으로 로드되지 않은 환경(=ja 결과 = key 또는 ko fallback)에서는 skip.
        // 운영자가 build-language-pack 실행 후 + 테스트 환경에서 LanguagePackServiceProvider 가
        // ja namespace 를 로드한 환경에서만 통합 시나리오 검증 가능.
        if ($jaValue === 'notification.channels.mail.name' || $jaValue === $koValue) {
            $this->markTestSkipped('g7-core-ja lang pack 의 notification.channels.* 키가 테스트 환경에 로드되지 않음');
        }

        app()->setLocale('ja');
        $service = app(NotificationChannelService::class);
        $channels = $service->getAvailableChannels();
        $byId = collect($channels)->keyBy('id');

        $this->assertSame('メール', $byId['mail']['name'] ?? null);
        $this->assertSame('サイト内通知', $byId['database']['name'] ?? null);
    }
}
