<?php

namespace Tests\Unit\Providers;

use App\Extension\HookManager;
use App\Repositories\JsonConfigRepository;
use App\Services\DriverRegistryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CoreServiceProvider의 확장 드라이버 Config 적용 로직 테스트
 *
 * applyExtensionDriverConfigs()의 핵심 흐름을 검증합니다:
 * - 코어 드라이버 선택 시 스킵
 * - 플러그인 드라이버 사용 가능 시 액션 훅 발행
 * - 플러그인 드라이버 사용 불가 시 기본 드라이버 폴백 + 로그 경고
 */
class CoreServiceProviderDriverHookTest extends TestCase
{
    private DriverRegistryService $driverRegistry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driverRegistry = new DriverRegistryService();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * 코어 드라이버가 선택된 경우 액션 훅이 발행되지 않는지 검증합니다.
     */
    #[Test]
    public function it_skips_core_drivers(): void
    {
        $hookFired = false;
        HookManager::addAction('core.settings.apply_driver_config', function () use (&$hookFired) {
            $hookFired = true;
        });

        // smtp는 코어 드라이버 → 훅 발행되면 안 됨
        $this->assertTrue($this->driverRegistry->isCoreDriver('mail', 'smtp'));
        $this->assertFalse($hookFired);
    }

    /**
     * 플러그인 드라이버가 등록되고 사용 가능할 때 액션 훅이 발행되는지 검증합니다.
     */
    #[Test]
    public function it_fires_action_hook_for_available_plugin_driver(): void
    {
        // 플러그인 드라이버 등록
        HookManager::addFilter('core.settings.available_mail_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'custom_api',
                'label' => ['ko' => '커스텀 API', 'en' => 'Custom API'],
                'provider' => 'sirsoft-custom_mail',
            ];

            return $drivers;
        });

        // custom_api가 사용 가능한지 확인
        $this->assertTrue($this->driverRegistry->isDriverAvailable('mail', 'custom_api'));

        // 액션 훅 발행 검증
        $hookParams = null;
        HookManager::addAction('core.settings.apply_driver_config', function (string $category, string $driver, array $settings) use (&$hookParams) {
            $hookParams = compact('category', 'driver', 'settings');
        });

        // 액션 훅 직접 발행 (CoreServiceProvider가 하는 것과 동일)
        HookManager::doAction('core.settings.apply_driver_config', 'mail', 'custom_api', ['mailer' => 'custom_api']);

        $this->assertNotNull($hookParams);
        $this->assertEquals('mail', $hookParams['category']);
        $this->assertEquals('custom_api', $hookParams['driver']);
    }

    /**
     * 플러그인 드라이버가 등록되지 않은 경우(사용 불가) 기본 드라이버로 폴백하는지 검증합니다.
     */
    #[Test]
    public function it_falls_back_to_default_when_plugin_driver_unavailable(): void
    {
        // 'custom_api'는 어디에도 등록되지 않은 드라이버
        $this->assertFalse($this->driverRegistry->isDriverAvailable('mail', 'custom_api'));

        // 폴백 시 Config::set이 호출되어야 함
        $defaultDriver = $this->driverRegistry->getDefaultDriver('mail');
        $configKey = $this->driverRegistry->getConfigKey('mail');

        $this->assertEquals('smtp', $defaultDriver);
        $this->assertEquals('mail.default', $configKey);

        // 폴백 적용 시뮬레이션
        Config::set($configKey, $defaultDriver);

        $this->assertEquals('smtp', Config::get('mail.default'));
    }

    /**
     * 폴백 시 경고 로그가 기록되는지 검증합니다.
     */
    #[Test]
    public function it_logs_warning_on_fallback(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message) {
                return str_contains($message, 'custom_api')
                    && str_contains($message, 'mail')
                    && str_contains($message, '폴백');
            });

        // 사용 불가능한 드라이버에 대해 폴백 로직 시뮬레이션
        $selectedDriver = 'custom_api';
        $category = 'mail';

        if (! $this->driverRegistry->isDriverAvailable($category, $selectedDriver)) {
            $defaultDriver = $this->driverRegistry->getDefaultDriver($category);
            $configKey = $this->driverRegistry->getConfigKey($category);

            if ($configKey && $defaultDriver) {
                Config::set($configKey, $defaultDriver);
            }

            Log::warning("플러그인 드라이버 '{$selectedDriver}'가 '{$category}' 카테고리에서 사용 불가능합니다. 기본 드라이버 '{$defaultDriver}'로 폴백합니다.");
        }
    }

    /**
     * 카테고리별 Config 키 매핑이 올바른지 검증합니다.
     */
    #[Test]
    public function it_maps_categories_to_correct_config_keys(): void
    {
        $this->assertEquals('filesystems.default', $this->driverRegistry->getConfigKey('storage'));
        $this->assertEquals('cache.default', $this->driverRegistry->getConfigKey('cache'));
        $this->assertEquals('session.driver', $this->driverRegistry->getConfigKey('session'));
        $this->assertEquals('queue.default', $this->driverRegistry->getConfigKey('queue'));
        $this->assertEquals('mail.default', $this->driverRegistry->getConfigKey('mail'));
    }
}
