<?php

namespace Tests\Unit\Services\GeoIp;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\GeoIpDatabaseService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * GeoIpDatabaseService 단위 테스트.
 *
 * HTTP 호출은 Http::fake로 모킹하고, 파일시스템 조작은 실제
 * storage/framework/testing/ 하위 임시 디렉토리에서 수행합니다.
 */
class GeoIpDatabaseServiceTest extends TestCase
{
    private string $testGeoipDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testGeoipDir = storage_path('framework/testing/geoip-test-'.uniqid());
        config([
            'geoip.database_path' => $this->testGeoipDir.'/GeoLite2-City.mmdb',
            // 테스트 시 retry 지연 최소화 (실제 운영은 config/geoip.php 기본값 사용)
            'geoip.download.retry_attempts' => 1,
            'geoip.download.retry_delay_ms' => 1,
        ]);

        if (File::isDirectory($this->testGeoipDir)) {
            File::deleteDirectory($this->testGeoipDir);
        }
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testGeoipDir)) {
            File::deleteDirectory($this->testGeoipDir);
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * 서비스 인스턴스를 생성합니다 (ConfigRepository는 mock).
     */
    private function makeService(?ConfigRepositoryInterface $configRepo = null): GeoIpDatabaseService
    {
        $configRepo ??= Mockery::mock(ConfigRepositoryInterface::class)
            ->shouldReceive('getCategory')->with('cache')->andReturn([])->byDefault()
            ->shouldReceive('saveCategory')->andReturn(true)->byDefault()
            ->getMock();

        return new GeoIpDatabaseService($configRepo);
    }

    public function test_missing_license_key_returns_error(): void
    {
        config(['geoip.license_key' => '']);

        $service = $this->makeService();
        $result = $service->updateDatabase();

        $this->assertFalse($result['success']);
        $this->assertEquals('missing_license_key', $result['status']);
    }

    public function test_is_license_key_configured_reflects_config(): void
    {
        $service = $this->makeService();

        config(['geoip.license_key' => '']);
        $this->assertFalse($service->isLicenseKeyConfigured());

        config(['geoip.license_key' => 'abcd1234']);
        $this->assertTrue($service->isLicenseKeyConfigured());
    }

    public function test_build_download_url_masks_license_key(): void
    {
        config(['geoip.license_key' => 'secretKey1234567890']);
        $service = $this->makeService();

        $masked = $service->buildDownloadUrlForDisplay(true);

        $this->assertStringContainsString('secr', $masked);
        $this->assertStringNotContainsString('secretKey1234567890', $masked);
        $this->assertStringContainsString('****', $masked);
    }

    public function test_mask_key_keeps_first_four_chars(): void
    {
        $service = $this->makeService();
        $this->assertEquals('abcd*****', $service->maskKey('abcd12345'));
        $this->assertEquals('****', $service->maskKey('abcd'));
        $this->assertEquals('**', $service->maskKey('ab'));
    }

    public function test_update_skipped_when_recently_updated_without_force(): void
    {
        config(['geoip.license_key' => 'abcd1234']);

        File::makeDirectory($this->testGeoipDir, 0755, true);
        $mmdbPath = $this->testGeoipDir.'/GeoLite2-City.mmdb';
        File::put($mmdbPath, 'dummy-mmdb-content');

        $service = $this->makeService();
        $result = $service->updateDatabase(false);

        $this->assertTrue($result['success']);
        $this->assertEquals('skipped', $result['status']);
        $this->assertArrayHasKey('data', $result);
    }

    public function test_update_unauthorized_on_http_401(): void
    {
        config(['geoip.license_key' => 'invalid_key']);

        Http::fake([
            'download.maxmind.com/*' => Http::response('', 401),
        ]);

        $service = $this->makeService();
        $result = $service->updateDatabase(true);

        $this->assertFalse($result['success']);
        $this->assertEquals('unauthorized', $result['status']);
        $this->assertStringContainsString('401', $result['message']);
    }

    public function test_update_download_failed_on_http_500(): void
    {
        config(['geoip.license_key' => 'validkey123']);

        Http::fake([
            'download.maxmind.com/*' => Http::response('', 500),
        ]);

        $service = $this->makeService();
        $result = $service->updateDatabase(true);

        $this->assertFalse($result['success']);
        $this->assertEquals('download_failed', $result['status']);
    }

    public function test_get_database_status_returns_missing_when_file_absent(): void
    {
        $service = $this->makeService();
        $status = $service->getDatabaseStatus();

        $this->assertFalse($status['exists']);
        $this->assertEquals(0, $status['file_size_bytes']);
        $this->assertNull($status['last_updated_at']);
    }

    public function test_get_database_status_returns_metadata_when_file_exists(): void
    {
        File::makeDirectory($this->testGeoipDir, 0755, true);
        $mmdbPath = $this->testGeoipDir.'/GeoLite2-City.mmdb';
        File::put($mmdbPath, str_repeat('x', 1024));

        $service = $this->makeService();
        $status = $service->getDatabaseStatus();

        $this->assertTrue($status['exists']);
        $this->assertEquals(1024, $status['file_size_bytes']);
        $this->assertNotNull($status['last_updated_at']);
    }
}
