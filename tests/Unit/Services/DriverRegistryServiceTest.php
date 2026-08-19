<?php

namespace Tests\Unit\Services;

use App\Extension\HookManager;
use App\Services\DriverRegistryService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DriverRegistryService 단위 테스트
 *
 * 코어 드라이버 레지스트리의 핵심 기능을 검증합니다:
 * - 코어 드라이버 반환
 * - 필터 훅 통합
 * - 코어 드라이버 판별
 * - 사용 가능 여부 확인
 * - 기본 폴백 드라이버
 */
class DriverRegistryServiceTest extends TestCase
{
    private DriverRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DriverRegistryService;
    }

    protected function tearDown(): void
    {
        // 테스트에서 등록한 필터 훅 정리
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * 코어 드라이버가 9개 카테고리를 모두 포함하는지 검증합니다.
     *
     * search 는 폴백 가드 편입(A5b)으로 추가되었다 — 이 목록에서 빠지면 검색엔진 플러그인
     * 제거 시 죽은 `scout.driver` 가 그대로 남아 공개 검색이 500 이 된다.
     *
     * @scenario engine_source=core
     *
     * @effects search_category_registered_in_driver_registry
     */
    #[Test]
    public function it_returns_all_nine_categories(): void
    {
        $categories = $this->service->getCategories();

        $this->assertCount(9, $categories);
        $this->assertEquals(
            ['storage', 'public_asset', 'cache', 'session', 'queue', 'log', 'websocket', 'mail', 'search'],
            $categories
        );
    }

    /**
     * 각 카테고리별 코어 드라이버가 올바르게 반환되는지 검증합니다.
     */
    #[Test]
    public function it_returns_core_drivers_for_each_category(): void
    {
        $storageDrivers = $this->service->getAvailableDrivers('storage');
        $driverIds = array_column($storageDrivers, 'id');

        $this->assertContains('local', $driverIds);
        $this->assertContains('s3', $driverIds);
    }

    /**
     * 메일 카테고리 코어 드라이버를 검증합니다.
     */
    #[Test]
    public function it_returns_mail_core_drivers(): void
    {
        $mailDrivers = $this->service->getAvailableDrivers('mail');
        $driverIds = array_column($mailDrivers, 'id');

        $this->assertContains('smtp', $driverIds);
        $this->assertContains('mailgun', $driverIds);
        $this->assertContains('ses', $driverIds);
    }

    /**
     * 공개 자산 카테고리 코어 드라이버를 검증합니다.
     *
     * 화면 Select 가 그대로 그리는 목록이므로 순서까지 고정합니다
     * ('none' 이 선두가 아니면 기본 선택값이 바뀐다).
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     */
    #[Test]
    public function it_returns_public_asset_core_drivers_in_order(): void
    {
        $drivers = $this->service->getAvailableDrivers('public_asset');

        $this->assertSame(['none', 'public', 's3'], array_column($drivers, 'id'));
    }

    /**
     * 공개 자산 디스크의 사용 가능 여부 판별을 검증합니다.
     *
     * SaveSettingsRequest 의 closure rule 이 이 판정에 의존하므로,
     * 미등록 값이 true 로 새면 422 게이트가 통째로 무력화됩니다.
     *
     * @effects invalid_disk_rejected_with_422
     */
    #[Test]
    public function it_reports_public_asset_driver_availability(): void
    {
        $this->assertTrue($this->service->isDriverAvailable('public_asset', 'none'));
        $this->assertTrue($this->service->isDriverAvailable('public_asset', 'public'));
        $this->assertTrue($this->service->isDriverAvailable('public_asset', 's3'));

        $this->assertFalse($this->service->isDriverAvailable('public_asset', 'nonexistent_disk'));
    }

    /**
     * 모든 카테고리의 드라이버를 한 번에 반환하는지 검증합니다.
     */
    #[Test]
    public function it_returns_all_available_drivers_at_once(): void
    {
        $all = $this->service->getAllAvailableDrivers();

        $this->assertArrayHasKey('storage', $all);
        $this->assertArrayHasKey('public_asset', $all);
        $this->assertArrayHasKey('cache', $all);
        $this->assertArrayHasKey('session', $all);
        $this->assertArrayHasKey('queue', $all);
        $this->assertArrayHasKey('log', $all);
        $this->assertArrayHasKey('websocket', $all);
        $this->assertArrayHasKey('mail', $all);
        $this->assertArrayHasKey('search', $all);
        $this->assertCount(9, $all);
    }

    /**
     * 코어 드라이버 판별이 올바르게 동작하는지 검증합니다.
     */
    #[Test]
    public function it_identifies_core_drivers_correctly(): void
    {
        $this->assertTrue($this->service->isCoreDriver('storage', 'local'));
        $this->assertTrue($this->service->isCoreDriver('storage', 's3'));
        $this->assertTrue($this->service->isCoreDriver('mail', 'smtp'));

        $this->assertFalse($this->service->isCoreDriver('storage', 'custom_storage'));
        $this->assertFalse($this->service->isCoreDriver('mail', 'custom_api'));
        $this->assertFalse($this->service->isCoreDriver('nonexistent', 'local'));
    }

    /**
     * 코어 드라이버의 사용 가능 여부가 true로 반환되는지 검증합니다.
     */
    #[Test]
    public function it_reports_core_drivers_as_available(): void
    {
        $this->assertTrue($this->service->isDriverAvailable('cache', 'file'));
        $this->assertTrue($this->service->isDriverAvailable('cache', 'redis'));
        $this->assertTrue($this->service->isDriverAvailable('session', 'database'));
    }

    /**
     * 등록되지 않은 드라이버가 사용 불가로 판별되는지 검증합니다.
     */
    #[Test]
    public function it_reports_unregistered_drivers_as_unavailable(): void
    {
        $this->assertFalse($this->service->isDriverAvailable('cache', 'custom_cache'));
        $this->assertFalse($this->service->isDriverAvailable('mail', 'custom_api'));
    }

    /**
     * 카테고리별 기본 폴백 드라이버가 올바르게 반환되는지 검증합니다.
     */
    #[Test]
    public function it_returns_correct_default_drivers(): void
    {
        $this->assertEquals('local', $this->service->getDefaultDriver('storage'));
        $this->assertEquals('none', $this->service->getDefaultDriver('public_asset'));
        $this->assertEquals('file', $this->service->getDefaultDriver('cache'));
        $this->assertEquals('database', $this->service->getDefaultDriver('session'));
        $this->assertEquals('database', $this->service->getDefaultDriver('queue'));
        $this->assertEquals('daily', $this->service->getDefaultDriver('log'));
        $this->assertEquals('', $this->service->getDefaultDriver('websocket'));
        $this->assertEquals('smtp', $this->service->getDefaultDriver('mail'));
    }

    /**
     * 존재하지 않는 카테고리의 기본 드라이버가 빈 문자열인지 검증합니다.
     */
    #[Test]
    public function it_returns_empty_string_for_unknown_category_default(): void
    {
        $this->assertEquals('', $this->service->getDefaultDriver('nonexistent'));
    }

    /**
     * 필터 훅으로 플러그인 드라이버가 추가되는지 검증합니다.
     */
    #[Test]
    public function it_includes_plugin_drivers_via_filter_hook(): void
    {
        // 플러그인이 메일 드라이버를 추가하는 필터 훅 등록
        HookManager::addFilter('core.settings.available_mail_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'custom_api',
                'label' => ['ko' => '커스텀 API', 'en' => 'Custom API'],
                'provider' => 'sirsoft-custom_mail',
            ];

            return $drivers;
        });

        $mailDrivers = $this->service->getAvailableDrivers('mail');
        $driverIds = array_column($mailDrivers, 'id');

        // 코어 드라이버 + 플러그인 드라이버
        $this->assertContains('smtp', $driverIds);
        $this->assertContains('mailgun', $driverIds);
        $this->assertContains('ses', $driverIds);
        $this->assertContains('custom_api', $driverIds);
    }

    /**
     * 필터 훅으로 추가된 플러그인 드라이버가 사용 가능으로 판별되는지 검증합니다.
     */
    #[Test]
    public function it_reports_plugin_drivers_as_available_after_hook_registration(): void
    {
        $this->assertFalse($this->service->isDriverAvailable('cache', 'custom_cache'));

        HookManager::addFilter('core.settings.available_cache_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'custom_cache',
                'label' => ['ko' => '커스텀 캐시', 'en' => 'Custom Cache'],
                'provider' => 'sirsoft-custom_cache',
            ];

            return $drivers;
        });

        $this->assertTrue($this->service->isDriverAvailable('cache', 'custom_cache'));
    }

    /**
     * 드라이버 데이터에 다국어 label이 포함되는지 검증합니다.
     */
    #[Test]
    public function it_includes_localized_labels_in_driver_data(): void
    {
        $storageDrivers = $this->service->getAvailableDrivers('storage');

        foreach ($storageDrivers as $driver) {
            $this->assertArrayHasKey('id', $driver);
            $this->assertArrayHasKey('label', $driver);
            $this->assertArrayHasKey('ko', $driver['label']);
            $this->assertArrayHasKey('en', $driver['label']);
        }
    }

    /**
     * 카테고리별 설정 키 정보가 올바르게 반환되는지 검증합니다.
     */
    #[Test]
    public function it_returns_settings_key_for_each_category(): void
    {
        $storageKey = $this->service->getSettingsKey('storage');
        $this->assertEquals(['category' => 'drivers', 'key' => 'storage_driver'], $storageKey);

        $mailKey = $this->service->getSettingsKey('mail');
        $this->assertEquals(['category' => 'mail', 'key' => 'mailer'], $mailKey);

        $this->assertNull($this->service->getSettingsKey('nonexistent'));
    }

    /**
     * 카테고리별 Laravel Config 키가 올바르게 반환되는지 검증합니다.
     */
    #[Test]
    public function it_returns_config_key_for_each_category(): void
    {
        $this->assertEquals('filesystems.default', $this->service->getConfigKey('storage'));
        $this->assertEquals('cache.default', $this->service->getConfigKey('cache'));
        $this->assertEquals('session.driver', $this->service->getConfigKey('session'));
        $this->assertEquals('queue.default', $this->service->getConfigKey('queue'));
        $this->assertEquals('mail.default', $this->service->getConfigKey('mail'));
        $this->assertNull($this->service->getConfigKey('nonexistent'));
    }
}
