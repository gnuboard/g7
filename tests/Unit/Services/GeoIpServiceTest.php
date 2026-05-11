<?php

namespace Tests\Unit\Services;

use App\Services\GeoIpService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GeoIpServiceTest extends TestCase
{
    private GeoIpService $geoIpService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoIpService = app(GeoIpService::class);
    }

    /**
     * GeoIP가 비활성화된 경우 null을 반환하는지 테스트합니다.
     */
    public function test_returns_null_when_disabled(): void
    {
        config(['geoip.enabled' => false]);

        $result = $this->geoIpService->getTimezoneByIp('8.8.8.8', ['America/New_York', 'UTC']);

        $this->assertNull($result);
    }

    /**
     * mmdb 파일이 없는 경우 null을 반환하는지 테스트합니다.
     */
    public function test_returns_null_when_database_file_missing(): void
    {
        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => storage_path('app/geoip/nonexistent.mmdb')]);

        $result = $this->geoIpService->getTimezoneByIp('8.8.8.8', ['America/New_York', 'UTC']);

        $this->assertNull($result);
    }

    /**
     * isAvailable()이 GeoIP 비활성화 시 false를 반환하는지 테스트합니다.
     */
    public function test_is_available_returns_false_when_disabled(): void
    {
        config(['geoip.enabled' => false]);

        $this->assertFalse($this->geoIpService->isAvailable());
    }

    /**
     * isAvailable()이 파일이 없을 때 false를 반환하는지 테스트합니다.
     */
    public function test_is_available_returns_false_when_file_missing(): void
    {
        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => storage_path('app/geoip/nonexistent.mmdb')]);

        $this->assertFalse($this->geoIpService->isAvailable());
    }

    /**
     * 실제 GeoIP 조회가 작동하는지 테스트합니다.
     * (GeoLite2-City.mmdb 파일이 있어야 함)
     */
    public function test_lookup_timezone_with_real_database(): void
    {
        $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');

        if (! file_exists($dbPath)) {
            $this->markTestSkipped('GeoLite2-City.mmdb 파일이 없습니다.');
        }

        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => $dbPath]);
        config(['geoip.cache.enabled' => false]);

        // 새로운 서비스 인스턴스 생성 (설정 변경 반영)
        $service = app(GeoIpService::class);

        // Google DNS (미국) - America/Chicago 또는 다른 미국 타임존
        $supportedTimezones = [
            'America/New_York',
            'America/Chicago',
            'America/Los_Angeles',
            'America/Denver',
            'UTC',
        ];

        $result = $service->getTimezoneByIp('8.8.8.8', $supportedTimezones);

        // 미국 IP이므로 America/* 타임존이 반환되어야 함
        $this->assertNotNull($result);
        $this->assertStringStartsWith('America/', $result);
    }

    /**
     * 지원하지 않는 타임존은 null을 반환하는지 테스트합니다.
     */
    public function test_returns_null_when_timezone_not_in_supported_list(): void
    {
        $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');

        if (! file_exists($dbPath)) {
            $this->markTestSkipped('GeoLite2-City.mmdb 파일이 없습니다.');
        }

        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => $dbPath]);
        config(['geoip.cache.enabled' => false]);

        $service = app(GeoIpService::class);

        // 미국 IP인데 한국 타임존만 지원하는 경우
        $result = $service->getTimezoneByIp('8.8.8.8', ['Asia/Seoul', 'Asia/Tokyo']);

        $this->assertNull($result);
    }

    /**
     * 캐싱이 작동하는지 테스트합니다.
     */
    public function test_caching_works_correctly(): void
    {
        $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');

        if (! file_exists($dbPath)) {
            $this->markTestSkipped('GeoLite2-City.mmdb 파일이 없습니다.');
        }

        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => $dbPath]);
        config(['geoip.cache.enabled' => true]);
        config(['geoip.cache.ttl' => 3600]);

        // CacheInterface 경유 — 실제 사용 키는 'geoip.timezone.'.{ip}
        $cache = app(\App\Contracts\Extension\CacheInterface::class);
        $cacheKey = 'geoip.timezone.8.8.8.8';
        $cache->forget($cacheKey);

        $service = app(GeoIpService::class);

        $supportedTimezones = [
            'America/New_York',
            'America/Chicago',
            'America/Los_Angeles',
            'America/Denver',
            'UTC',
        ];

        // 첫 번째 조회 (캐시 미스)
        $result1 = $service->getTimezoneByIp('8.8.8.8', $supportedTimezones);

        // 캐시에 저장되었는지 확인 (빈 문자열이어도 저장됨 — 실패 반복 방지 패턴)
        $cached = $cache->get($cacheKey);
        $this->assertNotNull($cached);

        // 두 번째 조회 (캐시 히트)
        $result2 = $service->getTimezoneByIp('8.8.8.8', $supportedTimezones);

        $this->assertEquals($result1, $result2);

        // 정리
        $cache->forget($cacheKey);
    }

    /**
     * 한국 IP로 타임존 조회 테스트
     */
    public function test_korean_ip_returns_asia_seoul(): void
    {
        $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');

        if (! file_exists($dbPath)) {
            $this->markTestSkipped('GeoLite2-City.mmdb 파일이 없습니다.');
        }

        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => $dbPath]);
        config(['geoip.cache.enabled' => false]);

        $service = app(GeoIpService::class);

        // KT DNS (한국)
        $supportedTimezones = ['Asia/Seoul', 'Asia/Tokyo', 'UTC'];

        $result = $service->getTimezoneByIp('168.126.63.1', $supportedTimezones);

        $this->assertEquals('Asia/Seoul', $result);
    }

    /**
     * 일본 IP로 타임존 조회 테스트
     */
    public function test_japanese_ip_returns_asia_tokyo(): void
    {
        $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');

        if (! file_exists($dbPath)) {
            $this->markTestSkipped('GeoLite2-City.mmdb 파일이 없습니다.');
        }

        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => $dbPath]);
        config(['geoip.cache.enabled' => false]);

        $service = app(GeoIpService::class);

        // NTT (일본)
        $supportedTimezones = ['Asia/Seoul', 'Asia/Tokyo', 'UTC'];

        $result = $service->getTimezoneByIp('203.178.136.1', $supportedTimezones);

        $this->assertEquals('Asia/Tokyo', $result);
    }

    /**
     * 프라이빗 IP는 null을 반환하는지 테스트합니다.
     */
    public function test_private_ip_returns_null(): void
    {
        $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');

        if (! file_exists($dbPath)) {
            $this->markTestSkipped('GeoLite2-City.mmdb 파일이 없습니다.');
        }

        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => $dbPath]);
        config(['geoip.cache.enabled' => false]);

        $service = app(GeoIpService::class);

        // 프라이빗 IP (192.168.x.x)
        $result = $service->getTimezoneByIp('192.168.1.1', ['Asia/Seoul', 'UTC']);

        $this->assertNull($result);
    }

    /**
     * localhost IP는 null을 반환하는지 테스트합니다.
     */
    public function test_localhost_ip_returns_null(): void
    {
        $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');

        if (! file_exists($dbPath)) {
            $this->markTestSkipped('GeoLite2-City.mmdb 파일이 없습니다.');
        }

        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => $dbPath]);
        config(['geoip.cache.enabled' => false]);

        $service = app(GeoIpService::class);

        $result = $service->getTimezoneByIp('127.0.0.1', ['Asia/Seoul', 'UTC']);

        $this->assertNull($result);
    }
}
