<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Notification;

use App\Extension\HookManager;
use App\Services\ChannelReadinessService;
use App\Services\NotificationChannelService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use Plugins\Sirsoft\MessageBizppurio\Listeners\RegisterNotificationChannelsListener;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 채널 등록 리스너를 실제 훅 체인으로 검증한다.
 *
 * 리스너 메서드를 직접 부르지 않고, HookManager 에 필터를 등록한 뒤 코어 서비스가
 * 발화하는 훅을 통해 sms/alimtalk 채널이 노출되고 readiness 가 해석되는지 관찰한다.
 * (Hook/Event 도메인 규정: 실제 훅 체인으로 관찰 가능한 상태 변화 검증)
 */
class ChannelRegistrationHookTest extends PluginTestCase
{
    /**
     * 리스너를 실제 훅 파이프라인에 등록합니다.
     *
     * @param  array<string, string>  $settings  플러그인 설정 stub
     */
    private function registerListener(array $settings = []): void
    {
        $stub = new class($settings) extends PluginSettingsService
        {
            /** @param array<string, string> $map */
            public function __construct(private array $map) {}

            public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
            {
                return $this->map[$key] ?? $default;
            }
        };

        $listener = new RegisterNotificationChannelsListener($stub);

        HookManager::addFilter(
            'core.notification.filter_available_channels',
            fn ($channels) => $listener->addChannels($channels),
            20,
        );
        HookManager::addFilter(
            'core.notification.channel_readiness',
            fn ($result, $channelId) => $listener->checkReadiness($result, $channelId),
            20,
        );
        HookManager::addFilter(
            'core.notification.channel_enabled',
            fn ($enabled, $extType, $extId, $channelId) => $listener->gateChannelEnabled($enabled, $extType, $extId, $channelId),
            20,
        );
    }

    /**
     * 코어 SettingsService 의 notifications.channels 저장값을 지정합니다.
     *
     * @param  array<int, array<string, mixed>>  $channels
     */
    private function setCoreChannels(array $channels): void
    {
        $settings = \Mockery::mock(SettingsService::class);
        $settings->shouldReceive('getSetting')
            ->with('notifications.channels', [])
            ->andReturn($channels);
        $this->app->instance(SettingsService::class, $settings);
        app(NotificationChannelService::class)->clearChannelEnabledCache();
    }

    public function test_코어_채널서비스가_sms_alimtalk을_노출한다(): void
    {
        $this->registerListener();

        // 실제 코어 서비스가 filter_available_channels 훅을 발화한다
        $channels = app(NotificationChannelService::class)->getAvailableChannels();
        $ids = array_column($channels, 'id');

        $this->assertContains('sms', $ids);
        $this->assertContains('alimtalk', $ids);
    }

    public function test_코어_channelservice가_비회원_허용을_인식한다(): void
    {
        $this->registerListener();

        $service = app(NotificationChannelService::class);

        $this->assertTrue($service->isChannelGuestAllowed('sms'), 'sms 는 allow_guest:true 여야 한다.');
        $this->assertTrue($service->isChannelGuestAllowed('alimtalk'));
    }

    public function test_코어_readiness서비스가_미설정_sms를_not_ready로_판정한다(): void
    {
        $this->registerListener([]); // 설정 비어 있음

        $result = app(ChannelReadinessService::class)->check('sms');

        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('readiness.sms_credentials_missing', (string) $result['reason']);
    }

    public function test_코어_readiness서비스가_완비_sms를_ready로_판정한다(): void
    {
        $this->registerListener([
            'bizppurio_id' => 'acme',
            'password' => 'secret',
            'sender_number' => '025550000',
        ]);

        $result = app(ChannelReadinessService::class)->check('sms');

        $this->assertTrue($result['ready']);
    }

    public function test_channel_enabled_미저장_sms는_off로_덮인다(): void
    {
        $this->registerListener();
        // mail/database 만 저장, sms/alimtalk 엔트리 없음(미저장)
        $this->setCoreChannels([
            ['id' => 'mail', 'is_active' => true],
            ['id' => 'database', 'is_active' => true],
        ]);

        $service = app(NotificationChannelService::class);

        // 우리 채널: 미저장 → OFF (opt-in)
        $this->assertFalse($service->isChannelEnabledForExtension('core', 'core', 'sms'));
        $service->clearChannelEnabledCache();
        $this->assertFalse($service->isChannelEnabledForExtension('core', 'core', 'alimtalk'));
        // 코어 기본 채널: 미저장이어도 ON 유지(하위호환)
        $service->clearChannelEnabledCache();
        $this->assertTrue($service->isChannelEnabledForExtension('core', 'core', 'mail'));
    }

    public function test_channel_enabled_저장된_on_sms는_on이다(): void
    {
        $this->registerListener();
        $this->setCoreChannels([
            ['id' => 'mail', 'is_active' => true],
            ['id' => 'sms', 'is_active' => true],
        ]);

        $this->assertTrue(
            app(NotificationChannelService::class)->isChannelEnabledForExtension('core', 'core', 'sms')
        );
    }

    public function test_channel_enabled_저장된_off_sms는_off이다(): void
    {
        $this->registerListener();
        $this->setCoreChannels([
            ['id' => 'mail', 'is_active' => true],
            ['id' => 'sms', 'is_active' => false],
        ]);

        $this->assertFalse(
            app(NotificationChannelService::class)->isChannelEnabledForExtension('core', 'core', 'sms')
        );
    }
}
