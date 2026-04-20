<?php

namespace Tests\Unit\Extension;

use App\Extension\HookManager;
use App\Repositories\JsonConfigRepository;
use App\Services\DriverRegistryService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PluginManager 드라이버 사용 중 경고 로직 테스트
 *
 * 플러그인 비활성화 시 해당 플러그인이 제공하는 드라이버가
 * 현재 사용 중인지 확인하는 DriverRegistryService의 연동을 검증합니다.
 */
class PluginManagerDriverWarningTest extends TestCase
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
     * 플러그인이 제공하는 드라이버가 없으면 빈 배열을 반환하는지 검증합니다.
     */
    #[Test]
    public function it_returns_empty_when_plugin_provides_no_drivers(): void
    {
        $result = $this->driverRegistry->getPluginProvidedDriversInUse('sirsoft-nonexistent');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 플러그인 드라이버가 등록되었지만 현재 선택되지 않은 경우 빈 배열을 반환하는지 검증합니다.
     */
    #[Test]
    public function it_returns_empty_when_plugin_driver_not_selected(): void
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

        // 현재 설정에서는 코어 드라이버(smtp)가 선택된 상태 → 플러그인 드라이버 미사용
        $result = $this->driverRegistry->getPluginProvidedDriversInUse('sirsoft-custom_mail');

        $this->assertEmpty($result);
    }

    /**
     * 플러그인 드라이버가 등록되고 현재 선택된 경우 사용 중으로 반환하는지 검증합니다.
     */
    #[Test]
    public function it_detects_plugin_driver_in_use(): void
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

        // JsonConfigRepository에서 custom_api가 선택된 상태로 mock
        $mockConfigRepo = $this->createMock(JsonConfigRepository::class);
        $mockConfigRepo->method('getCategory')
            ->willReturnCallback(function (string $category) {
                if ($category === 'mail') {
                    return ['mailer' => 'custom_api'];
                }

                return [];
            });

        $this->app->instance(JsonConfigRepository::class, $mockConfigRepo);

        $result = $this->driverRegistry->getPluginProvidedDriversInUse('sirsoft-custom_mail');

        $this->assertCount(1, $result);
        $this->assertEquals('mail', $result[0]['category']);
        $this->assertEquals('custom_api', $result[0]['driver_id']);
    }

    /**
     * 다른 플러그인의 드라이버는 감지하지 않는지 검증합니다.
     */
    #[Test]
    public function it_only_detects_drivers_from_specified_plugin(): void
    {
        // 두 개의 플러그인이 각각 드라이버 등록
        HookManager::addFilter('core.settings.available_mail_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'plugin_a_mailer',
                'label' => ['ko' => 'A 메일러', 'en' => 'A Mailer'],
                'provider' => 'sirsoft-plugin_a',
            ];
            $drivers[] = [
                'id' => 'plugin_b_mailer',
                'label' => ['ko' => 'B 메일러', 'en' => 'B Mailer'],
                'provider' => 'sirsoft-plugin_b',
            ];

            return $drivers;
        });

        // plugin_a_mailer가 선택된 상태
        $mockConfigRepo = $this->createMock(JsonConfigRepository::class);
        $mockConfigRepo->method('getCategory')
            ->willReturnCallback(function (string $category) {
                if ($category === 'mail') {
                    return ['mailer' => 'plugin_a_mailer'];
                }

                return [];
            });

        $this->app->instance(JsonConfigRepository::class, $mockConfigRepo);

        // plugin_a는 감지, plugin_b는 미감지
        $resultA = $this->driverRegistry->getPluginProvidedDriversInUse('sirsoft-plugin_a');
        $this->assertCount(1, $resultA);

        $resultB = $this->driverRegistry->getPluginProvidedDriversInUse('sirsoft-plugin_b');
        $this->assertEmpty($resultB);
    }
}
