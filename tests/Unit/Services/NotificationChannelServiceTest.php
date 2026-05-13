<?php

namespace Tests\Unit\Services;

use App\Services\ModuleSettingsService;
use App\Services\NotificationChannelService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use Mockery;
use Tests\TestCase;

/**
 * NotificationChannelService 단위 테스트
 *
 * 확장(core/module/plugin) 단위 채널 전역 활성 여부 조회를 검증합니다.
 */
class NotificationChannelServiceTest extends TestCase
{
    private NotificationChannelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationChannelService;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 코어 설정에서 채널이 활성화된 경우 true를 반환한다.
     */
    public function test_core_channel_enabled_when_is_active_true(): void
    {
        $this->mockCoreSettings([
            ['id' => 'mail', 'is_active' => true],
            ['id' => 'database', 'is_active' => true],
        ]);

        $this->assertTrue(
            $this->service->isChannelEnabledForExtension('core', 'core', 'mail')
        );
    }

    /**
     * 코어 설정에서 채널이 비활성화된 경우 false를 반환한다.
     */
    public function test_core_channel_disabled_when_is_active_false(): void
    {
        $this->mockCoreSettings([
            ['id' => 'mail', 'is_active' => false],
            ['id' => 'database', 'is_active' => true],
        ]);

        $this->assertFalse(
            $this->service->isChannelEnabledForExtension('core', 'core', 'mail')
        );
        $this->assertTrue(
            $this->service->isChannelEnabledForExtension('core', 'core', 'database')
        );
    }

    /**
     * 설정에 엔트리가 없으면 기본 활성(true) — 하위호환 + 신규 플러그인 채널 대응.
     */
    public function test_core_channel_defaults_to_enabled_when_entry_missing(): void
    {
        $this->mockCoreSettings([
            ['id' => 'mail', 'is_active' => true],
        ]);

        // slack은 설정에 없음 → 기본 true
        $this->assertTrue(
            $this->service->isChannelEnabledForExtension('core', 'core', 'slack')
        );
    }

    /**
     * notifications.channels 키 자체가 없으면(빈 배열) 모든 채널 활성.
     */
    public function test_core_channel_defaults_to_enabled_when_settings_empty(): void
    {
        $this->mockCoreSettings([]);

        $this->assertTrue(
            $this->service->isChannelEnabledForExtension('core', 'core', 'mail')
        );
    }

    /**
     * 모듈 경로: ModuleSettingsService를 통해 조회한다.
     */
    public function test_module_channel_resolved_via_module_settings(): void
    {
        $moduleSettings = Mockery::mock(ModuleSettingsService::class);
        $moduleSettings->shouldReceive('get')
            ->with('sirsoft-ecommerce', 'notifications.channels', [])
            ->andReturn([
                ['id' => 'mail', 'is_active' => false],
                ['id' => 'database', 'is_active' => true],
            ]);
        $this->app->instance(ModuleSettingsService::class, $moduleSettings);

        $this->assertFalse(
            $this->service->isChannelEnabledForExtension('module', 'sirsoft-ecommerce', 'mail')
        );
        $this->assertTrue(
            $this->service->isChannelEnabledForExtension('module', 'sirsoft-ecommerce', 'database')
        );
    }

    /**
     * 플러그인 경로: PluginSettingsService를 통해 조회한다.
     */
    public function test_plugin_channel_resolved_via_plugin_settings(): void
    {
        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')
            ->with('sirsoft-slack', 'notifications.channels', [])
            ->andReturn([
                ['id' => 'slack', 'is_active' => true],
            ]);
        $this->app->instance(PluginSettingsService::class, $pluginSettings);

        $this->assertTrue(
            $this->service->isChannelEnabledForExtension('plugin', 'sirsoft-slack', 'slack')
        );
    }

    /**
     * 모듈 식별자가 비어 있으면 기본 활성 반환.
     */
    public function test_module_without_identifier_returns_default_enabled(): void
    {
        $this->assertTrue(
            $this->service->isChannelEnabledForExtension('module', null, 'mail')
        );
        $this->assertTrue(
            $this->service->isChannelEnabledForExtension('module', '', 'mail')
        );
    }

    /**
     * 동일 조합 반복 호출 시 캐시를 사용한다 (SettingsService 1회만 호출).
     */
    public function test_memoizes_channel_enabled_lookup(): void
    {
        $settingsService = Mockery::mock(SettingsService::class);
        $settingsService->shouldReceive('getSetting')
            ->once() // 캐시 검증
            ->with('notifications.channels', [])
            ->andReturn([
                ['id' => 'mail', 'is_active' => false],
            ]);
        $this->app->instance(SettingsService::class, $settingsService);

        $this->service->isChannelEnabledForExtension('core', 'core', 'mail');
        $this->service->isChannelEnabledForExtension('core', 'core', 'mail');
        $this->service->isChannelEnabledForExtension('core', 'core', 'mail');

        $this->assertFalse(
            $this->service->isChannelEnabledForExtension('core', 'core', 'mail')
        );
    }

    /**
     * clearChannelEnabledCache() 호출 시 캐시가 초기화된다.
     */
    public function test_clear_channel_enabled_cache(): void
    {
        $settingsService = Mockery::mock(SettingsService::class);
        $settingsService->shouldReceive('getSetting')
            ->twice()
            ->with('notifications.channels', [])
            ->andReturn([['id' => 'mail', 'is_active' => true]]);
        $this->app->instance(SettingsService::class, $settingsService);

        $this->service->isChannelEnabledForExtension('core', 'core', 'mail');
        $this->service->clearChannelEnabledCache();
        $this->service->isChannelEnabledForExtension('core', 'core', 'mail');

        // 2회 호출 검증은 Mockery가 처리
        $this->assertTrue(true);
    }

    /**
     * 설정 조회 중 예외 발생 시 기본 활성(true) 반환.
     */
    public function test_returns_default_enabled_on_settings_service_exception(): void
    {
        $settingsService = Mockery::mock(SettingsService::class);
        $settingsService->shouldReceive('getSetting')
            ->andThrow(new \RuntimeException('DB unavailable'));
        $this->app->instance(SettingsService::class, $settingsService);

        $this->assertTrue(
            $this->service->isChannelEnabledForExtension('core', 'core', 'mail')
        );
    }

    /**
     * 코어 SettingsService 응답을 mocking 하는 헬퍼.
     *
     * @param array $channels
     */
    private function mockCoreSettings(array $channels): void
    {
        $settingsService = Mockery::mock(SettingsService::class);
        $settingsService->shouldReceive('getSetting')
            ->with('notifications.channels', [])
            ->andReturn($channels);
        $this->app->instance(SettingsService::class, $settingsService);
    }
}
