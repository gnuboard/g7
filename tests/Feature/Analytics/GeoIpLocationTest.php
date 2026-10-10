<?php

namespace Tests\Feature\Analytics;

use App\Analytics\GeoLocator;
use App\Contracts\Extension\CacheInterface;
use App\Services\GeoIpService;
use GeoIp2\Database\Reader;
use GeoIp2\Model\City;
use Mockery;
use Tests\TestCase;

/**
 * 지역 조회 — DB 부재·조회 결과·캐시 키.
 *
 * GeoIP 는 기본 꺼짐이라 대부분의 사이트가 「국가는 언어 추정, 지역·도시 없음」 상태다.
 * 그 폴백과 실제 조회 결과의 모양이 같아야 지역 화면이 두 경우를 같은 코드로 그린다.
 */
class GeoIpLocationTest extends TestCase
{
    /**
     * 테스트 준비 — GeoIP 켜고 캐시 끔.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['geoip.enabled' => true]);
        config(['g7_settings.core.cache.geoip_enabled' => false]);
    }

    /**
     * DB 파일이 없으면 null 이고 언어 추정 폴백으로 국가만 채운다.
     *
     * @scenario geo_before_mask=true
     *
     * @effects geo_location_lookup_returns_null_without_database
     *
     * @return void 없음
     */
    public function test_missing_database_returns_null_and_locator_falls_back(): void
    {
        config(['geoip.database_path' => storage_path('app/geoip/does-not-exist-'.uniqid().'.mmdb')]);

        $service = app(GeoIpService::class);

        $this->assertNull($service->getLocationByIp('203.0.113.50'));

        $location = (new GeoLocator($service))->locate('203.0.113.50', '203.0.113.50', 'ko-KR', true);

        $this->assertSame(['country_code' => 'KR', 'region_code' => null, 'city' => null], $location);
        $this->assertNull((new GeoLocator($service))->locate('203.0.113.50', null, 'ko', true)['country_code'], '언어만 있으면 국가를 추정하지 않는다');
    }

    /**
     * DB 가 있으면 국가·지역·도시·도시명 사전을 돌려준다.
     *
     * @scenario geo_before_mask=true
     *
     * @effects geo_lookup_runs_before_masking_when_configured
     *
     * @return void 없음
     */
    public function test_database_lookup_returns_location_array(): void
    {
        $service = app(GeoIpService::class);

        $reader = Mockery::mock(Reader::class);
        // 캐시를 껐으므로 서비스 직접 조회 1회 + 판정기 경유 1회 = 2회 읽는다.
        $reader->shouldReceive('city')->with('203.0.113.50')->twice()->andReturn(new City([
            'country' => ['iso_code' => 'kr', 'names' => ['en' => 'South Korea']],
            'subdivisions' => [['iso_code' => '11', 'names' => ['en' => 'Seoul']]],
            'city' => ['names' => ['en' => 'Seoul', 'ja' => 'ソウル']],
        ], ['en']));

        $property = new \ReflectionProperty(GeoIpService::class, 'reader');
        $property->setValue($service, $reader);

        $location = $service->getLocationByIp('203.0.113.50');

        $this->assertSame('KR', $location['country'], '국가 코드는 대문자로 정규화한다');
        $this->assertSame('11', $location['region_code']);
        $this->assertSame('Seoul', $location['region']);
        $this->assertSame('Seoul', $location['city']);
        $this->assertSame(['en' => 'Seoul', 'ja' => 'ソウル'], $location['city_names']);

        // 마스킹 전 IP 로 조회한다 — 조회에 쓴 IP 가 원본이어야 도시가 정확하다.
        $located = (new GeoLocator($service))->locate('203.0.113.50', '203.0.113.0', 'ko-KR', true);
        $this->assertSame('Seoul', $located['city']);
    }

    /**
     * 캐시가 켜져 있으면 `geoip.location.{ip}` 키를 먼저 읽고 빈 배열은 실패로 해석한다.
     *
     * @scenario geo_before_mask=true
     *
     * @effects geo_location_lookup_returns_null_without_database
     *
     * @return void 없음
     */
    public function test_cache_key_is_read_before_the_database(): void
    {
        config(['g7_settings.core.cache.geoip_enabled' => true]);
        config(['geoip.database_path' => storage_path('app/geoip/does-not-exist-'.uniqid().'.mmdb')]);

        $cache = app(CacheInterface::class);
        $cached = ['country' => 'JP', 'region_code' => '13', 'region' => 'Tokyo', 'city' => 'Tokyo', 'city_names' => []];
        $cache->put('geoip.location.198.51.100.1', $cached, 60);
        $cache->put('geoip.location.198.51.100.2', [], 60);

        $service = app(GeoIpService::class);

        $this->assertSame($cached, $service->getLocationByIp('198.51.100.1'), 'DB 가 없어도 캐시 값은 그대로 온다');
        $this->assertNull($service->getLocationByIp('198.51.100.2'), '빈 배열은 이전 조회 실패(음성 캐시)다');

        $cache->forget('geoip.location.198.51.100.1');
        $cache->forget('geoip.location.198.51.100.2');
    }
}
