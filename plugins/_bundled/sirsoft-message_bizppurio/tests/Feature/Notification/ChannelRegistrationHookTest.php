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
     * @return void 반환값 없음
     */
    private function registerListener(array $settings = []): void
    {
        $stub = new class($settings) extends PluginSettingsService
        {
            /**
             * 설정 stub 의 키-값 표를 받습니다.
             *
             * @param  array<string, string>  $map  설정 키 => 값 표
             */
            public function __construct(private array $map) {}

            /**
             * 설정 stub 에서 키 값을 돌려줍니다.
             *
             * @param  string  $identifier  확장 식별자
             * @param  ?string  $key  설정 키
             * @param  mixed  $default  기본값
             * @return mixed 설정 값
             */
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
        HookManager::addFilter(
            'core.notification.channels',
            fn ($channels, $type = '', $notifiable = null, $hookPrefix = '') => $listener->addChannelCandidates($channels, $type, $notifiable, $hookPrefix),
            20,
        );
    }

    /**
     * 코어 채널 후보 진입점이 어느 도메인 접두사로 불려도 sms·alimtalk 후보가 더해진다.
     *
     * 종전에는 플러그인이 core.auth·게시판·이커머스 접두사를 직접 적어 구독했다. 이제 코어가
     * 모든 접두사에서 core.notification.channels 를 발화하므로, 목록에 없던 도메인에도 후보가
     * 붙고 도메인 훅은 그 뒤에 자기 설정으로 좁힐 수 있다.
     *
     * @scenario channel=sms
     *
     * @effects channel_candidates_answered_via_single_core_hook
     *
     * @return void 반환값 없음
     */
    public function test_코어_채널후보_진입점이_모든_도메인에_sms_alimtalk을_더한다(): void
    {
        HookManager::clearFilter('core.notification.channels');
        $this->registerListener();

        $service = app(NotificationChannelService::class);

        foreach (['core.auth', 'core.privacy', 'vendor-future-domain'] as $prefix) {
            $channels = $service->resolveChannelCandidates($prefix, ['mail'], 'welcome');

            $this->assertContains('sms', $channels, "{$prefix} 후보에 sms 가 있어야 합니다.");
            $this->assertContains('alimtalk', $channels, "{$prefix} 후보에 alimtalk 이 있어야 합니다.");
        }
    }

    /**
     * 코어 SettingsService 의 notifications.channels 저장값을 지정합니다.
     *
     * @param  array<int, array<string, mixed>>  $channels  채널 설정 목록
     * @return void 반환값 없음
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

    /**
     * 코어 채널서비스가 sms alimtalk을 노출한다.
     *
     * @effects sms_channel_exposed_in_available_channels
     *
     * @return void 반환값 없음
     */
    public function test_코어_채널서비스가_sms_alimtalk을_노출한다(): void
    {
        $this->registerListener();

        // 실제 코어 서비스가 filter_available_channels 훅을 발화한다
        $channels = app(NotificationChannelService::class)->getAvailableChannels();
        $ids = array_column($channels, 'id');

        $this->assertContains('sms', $ids);
        $this->assertContains('alimtalk', $ids);
    }

    /**
     * 코어 channelservice가 비회원 허용을 인식한다.
     *
     * @effects both_channels_allow_guest_true
     *
     * @return void 반환값 없음
     */
    public function test_코어_channelservice가_비회원_허용을_인식한다(): void
    {
        $this->registerListener();

        $service = app(NotificationChannelService::class);

        $this->assertTrue($service->isChannelGuestAllowed('sms'), 'sms 는 allow_guest:true 여야 한다.');
        $this->assertTrue($service->isChannelGuestAllowed('alimtalk'));
    }

    /**
     * 코어 readiness서비스가 미설정 sms를 not ready로 판정한다.
     *
     * @effects sms_not_ready_reports_credentials_missing_key
     *
     * @return void 반환값 없음
     */
    public function test_코어_readiness서비스가_미설정_sms를_not_ready로_판정한다(): void
    {
        $this->registerListener([]); // 설정 비어 있음

        $result = app(ChannelReadinessService::class)->check('sms');

        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('readiness.sms_credentials_missing', (string) $result['reason']);
    }

    /**
     * 코어 readiness서비스가 완비 sms를 ready로 판정한다.
     *
     * @effects sms_ready_when_id_password_sender_number_set
     *
     * @return void 반환값 없음
     */
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

    /**
     * channel enabled 미저장 sms는 off로 덮인다.
     *
     * @effects unsaved_owned_channel_defaults_off
     *
     * @return void 반환값 없음
     */
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

    /**
     * channel enabled 저장된 on sms는 on이다.
     *
     * @effects saved_owned_channel_state_respected
     *
     * @return void 반환값 없음
     */
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

    /**
     * channel enabled 저장된 off sms는 off이다.
     *
     * @effects saved_owned_channel_state_respected
     *
     * @return void 반환값 없음
     */
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
