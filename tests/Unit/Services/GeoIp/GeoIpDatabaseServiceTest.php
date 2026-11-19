<?php

namespace Tests\Unit\Services\GeoIp;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\GeoIpDatabaseService;
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

    /**
     * 시험마다 고유한 임시 GeoIP 폴더를 설정에 걸고 내려받기 재시도 지연을 최소로 줄인다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 임시 GeoIP 폴더를 지우고 Mockery 기대를 닫은 뒤 부모 정리를 실행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->testGeoipDir)) {
            File::deleteDirectory($this->testGeoipDir);
        }

        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * 서비스 인스턴스를 생성합니다 (ConfigRepository는 mock).
     *
     * @param  ConfigRepositoryInterface|null  $configRepo  넘기지 않으면 빈 cache 설정을 답하는 목을 쓴다
     * @return GeoIpDatabaseService 시험 대상 서비스
     */
    private function makeService(?ConfigRepositoryInterface $configRepo = null): GeoIpDatabaseService
    {
        $configRepo ??= Mockery::mock(ConfigRepositoryInterface::class)
            ->shouldReceive('getCategory')->with('cache')->andReturn([])->byDefault()
            ->shouldReceive('saveCategory')->andReturn(true)->byDefault()
            ->getMock();

        return new GeoIpDatabaseService($configRepo);
    }

    /**
     * 라이선스 키가 비어 있으면 갱신이 실패하고 상태가 missing_license_key 다.
     *
     * @scenario case=license_key
     *
     * @effects geoip_update_without_license_key_reports_missing_license_key
     *
     * @return void 반환값 없음
     */
    public function test_missing_license_key_returns_error(): void
    {
        config(['geoip.license_key' => '']);

        $service = $this->makeService();
        $result = $service->updateDatabase();

        $this->assertFalse($result['success']);
        $this->assertEquals('missing_license_key', $result['status']);
    }

    /**
     * 라이선스 키 설정 여부 판정이 설정값을 따라 바뀐다.
     *
     * @scenario case=license_key
     *
     * @effects geoip_license_key_configured_reflects_config
     *
     * @return void 반환값 없음
     */
    public function test_is_license_key_configured_reflects_config(): void
    {
        $service = $this->makeService();

        config(['geoip.license_key' => '']);
        $this->assertFalse($service->isLicenseKeyConfigured());

        config(['geoip.license_key' => 'abcd1234']);
        $this->assertTrue($service->isLicenseKeyConfigured());
    }

    /**
     * 표시용 내려받기 주소는 라이선스 키 앞 4자만 남기고 나머지를 별표로 가린다.
     *
     * @scenario case=display_masking
     *
     * @effects geoip_display_download_url_masks_license_key
     *
     * @return void 반환값 없음
     */
    public function test_build_download_url_masks_license_key(): void
    {
        config(['geoip.license_key' => 'secretKey1234567890']);
        $service = $this->makeService();

        $masked = $service->buildDownloadUrlForDisplay(true);

        $this->assertStringContainsString('secr', $masked);
        $this->assertStringNotContainsString('secretKey1234567890', $masked);
        $this->assertStringContainsString('****', $masked);
    }

    /**
     * 키 가리기는 앞 4자를 남기고, 4자 이하 키는 전부 별표로 바꾼다.
     *
     * @scenario case=display_masking
     *
     * @effects geoip_mask_key_keeps_first_four_chars
     *
     * @return void 반환값 없음
     */
    public function test_mask_key_keeps_first_four_chars(): void
    {
        $service = $this->makeService();
        $this->assertEquals('abcd*****', $service->maskKey('abcd12345'));
        $this->assertEquals('****', $service->maskKey('abcd'));
        $this->assertEquals('**', $service->maskKey('ab'));
    }

    /**
     * 방금 만든 DB 파일이 있으면 강제가 아닌 갱신은 skipped 상태로 성공 처리된다.
     *
     * @scenario case=update_skip
     *
     * @effects geoip_update_skipped_when_recent_without_force
     *
     * @return void 반환값 없음
     */
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

    /**
     * 내려받기 응답이 401 이면 갱신이 실패하고 상태가 unauthorized, 메시지에 401 이 담긴다.
     *
     * @scenario case=update_http_failure
     *
     * @effects geoip_update_reports_unauthorized_on_401
     *
     * @return void 반환값 없음
     */
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

    /**
     * 내려받기 응답이 500 이면 갱신이 실패하고 상태가 download_failed 다.
     *
     * @scenario case=update_http_failure
     *
     * @effects geoip_update_reports_download_failed_on_500
     *
     * @return void 반환값 없음
     */
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

    /**
     * DB 파일이 없으면 상태 조회가 없음, 크기 0, 갱신 시각 null 을 답한다.
     *
     * @scenario case=database_status
     *
     * @effects geoip_database_status_reports_missing_file
     *
     * @return void 반환값 없음
     */
    public function test_get_database_status_returns_missing_when_file_absent(): void
    {
        $service = $this->makeService();
        $status = $service->getDatabaseStatus();

        $this->assertFalse($status['exists']);
        $this->assertEquals(0, $status['file_size_bytes']);
        $this->assertNull($status['last_updated_at']);
    }

    /**
     * DB 파일이 있으면 상태 조회가 있음, 파일 크기, 갱신 시각을 답한다.
     *
     * @scenario case=database_status
     *
     * @effects geoip_database_status_reports_file_metadata
     *
     * @return void 반환값 없음
     */
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
