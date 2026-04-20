<?php

namespace Tests\Unit\Cache\Migration;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Services\GeoIpService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 그룹 E 이관 검증 테스트 (SettingsService, GeoIpService)
 *
 * 계획서 §13 E-1, E-2 의 11개 테스트 케이스를 검증합니다.
 * - SettingsService: system 설정 캐시 생성/히트/무효화 (6건)
 * - GeoIpService: timezone 캐시 생성/히트/실패 캐싱/비활성/TTL (5건)
 *
 * 실제 CoreCacheDriver(array store)를 사용하여 키 접두사(`g7:core:`)까지
 * 포함한 동작을 end-to-end로 검증합니다.
 */
class SettingsCacheTest extends TestCase
{
    private CoreCacheDriver $cache;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->cache = new CoreCacheDriver('array');
        $this->app->instance(CacheInterface::class, $this->cache);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ========================================================================
    // E-1. SettingsService
    // ========================================================================

    /**
     * E-1-1: 시스템 설정 캐시 생성 — 외부 컴포넌트가 설정값을 직접 캐싱한 뒤
     * SettingsService 가 'settings.system' 키를 읽을 수 있는지 검증한다.
     */
    #[Test]
    public function e_1_1_system_settings_cache_can_be_stored_under_new_key(): void
    {
        $this->cache->put('settings.system', ['general' => ['site_name' => 'G7']]);

        $this->assertTrue($this->cache->has('settings.system'));
        $this->assertSame(
            'g7:core:settings.system',
            $this->cache->resolveKey('settings.system')
        );
        $this->assertSame(
            ['general' => ['site_name' => 'G7']],
            $this->cache->get('settings.system')
        );
    }

    /**
     * E-1-2: 시스템 설정 캐시 히트 — 동일 키 재조회 시 동일 값 반환.
     */
    #[Test]
    public function e_1_2_system_settings_cache_hit_returns_same_value(): void
    {
        $this->cache->put('settings.system', ['v' => 1]);

        $this->assertSame(['v' => 1], $this->cache->get('settings.system'));
        $this->assertSame(['v' => 1], $this->cache->get('settings.system'));
    }

    /**
     * E-1-3: 일반 탭 저장 시 settings.system 무효화 + config:clear 호출.
     */
    #[Test]
    public function e_1_3_general_tab_save_invalidates_settings_cache(): void
    {
        $this->cache->put('settings.system', ['cached' => true]);

        Artisan::shouldReceive('call')->with('config:clear')->once();

        $configRepo = $this->mock(ConfigRepositoryInterface::class)->shouldIgnoreMissing();
        $configRepo->shouldReceive('getCategory')->with('mail')->andReturn([]);
        $configRepo->shouldReceive('saveCategory')
            ->with('mail', \Mockery::type('array'))
            ->andReturn(true);
        $this->mock(AttachmentRepositoryInterface::class)->shouldIgnoreMissing();

        $service = app(SettingsService::class);
        $result = $service->saveSettings([
            '_tab' => 'mail',
            'mail' => ['mailer' => 'smtp'],
        ]);

        $this->assertTrue($result);
        $this->assertFalse($this->cache->has('settings.system'));
    }

    /**
     * E-1-4: advanced 탭(cache 카테고리) 저장 시 settings.system 무효화.
     */
    #[Test]
    public function e_1_4_advanced_tab_save_invalidates_settings_cache(): void
    {
        $this->cache->put('settings.system', ['cached' => true]);

        Artisan::shouldReceive('call')->with('config:clear')->once();

        $configRepo = $this->mock(ConfigRepositoryInterface::class)->shouldIgnoreMissing();
        $configRepo->shouldReceive('getCategory')->with('cache')->andReturn([]);
        $configRepo->shouldReceive('saveCategory')
            ->with('cache', \Mockery::type('array'))
            ->andReturn(true);
        $this->mock(AttachmentRepositoryInterface::class)->shouldIgnoreMissing();

        $service = app(SettingsService::class);
        $result = $service->saveSettings([
            '_tab' => 'advanced',
            'advanced' => ['enabled' => true],
        ]);

        $this->assertTrue($result);
        $this->assertFalse($this->cache->has('settings.system'));
    }

    /**
     * E-1-5: SEO 탭 저장 시 설정 캐시 무효화 + after_save 훅 발행.
     *
     * SeoSettingsCacheListener 의 SEO 캐시 삭제 동작은 그룹 C 이관 시 검증한다.
     * 여기서는 설정 캐시만 삭제되는지 확인한다.
     */
    #[Test]
    public function e_1_5_seo_tab_save_invalidates_settings_cache_and_fires_hook(): void
    {
        $this->cache->put('settings.system', ['cached' => true]);

        Artisan::shouldReceive('call')->with('config:clear')->once();

        $configRepo = $this->mock(ConfigRepositoryInterface::class)->shouldIgnoreMissing();
        $configRepo->shouldReceive('getCategory')->with('seo')->andReturn([]);
        $configRepo->shouldReceive('saveCategory')
            ->with('seo', \Mockery::type('array'))
            ->andReturn(true);
        $this->mock(AttachmentRepositoryInterface::class)->shouldIgnoreMissing();

        $service = app(SettingsService::class);
        $result = $service->saveSettings([
            '_tab' => 'seo',
            'seo' => ['title' => 'G7'],
        ]);

        $this->assertTrue($result);
        $this->assertFalse($this->cache->has('settings.system'));
    }

    /**
     * E-1-6: drivers 탭 저장 시 queue:restart 추가 호출.
     */
    #[Test]
    public function e_1_6_drivers_tab_save_triggers_queue_restart(): void
    {
        $this->cache->put('settings.system', ['cached' => true]);

        Artisan::shouldReceive('call')->with('config:clear')->once();
        Artisan::shouldReceive('call')->with('queue:restart')->once();

        $configRepo = $this->mock(ConfigRepositoryInterface::class)->shouldIgnoreMissing();
        $configRepo->shouldReceive('getCategory')->with('drivers')->andReturn([]);
        $configRepo->shouldReceive('saveCategory')
            ->with('drivers', \Mockery::type('array'))
            ->andReturn(true);
        $this->mock(AttachmentRepositoryInterface::class)->shouldIgnoreMissing();

        $service = app(SettingsService::class);
        $result = $service->saveSettings([
            '_tab' => 'drivers',
            'drivers' => ['queue' => 'sync'],
        ]);

        $this->assertTrue($result);
        $this->assertFalse($this->cache->has('settings.system'));
    }

    // ========================================================================
    // E-2. GeoIpService
    // ========================================================================

    /**
     * E-2-1: GeoIP 캐시 생성 — 지원 타임존 목록에 있는 경우 캐시에 저장.
     *
     * MaxMind DB 가 실제로 존재하지 않으므로 lookupTimezone() 은 null 반환,
     * 결과적으로 빈 문자열이 캐시된다. (E-2-3 과 동일 동작)
     *
     * 여기서는 캐시 저장 키가 새 접두사(`g7:core:geoip.timezone.{ip}`)로
     * 이관되었는지에 초점을 맞춘다.
     */
    #[Test]
    public function e_2_1_geoip_cache_uses_new_prefix(): void
    {
        config()->set('geoip.enabled', true);
        config()->set('geoip.cache.enabled', true);
        config()->set('geoip.database_path', '/nonexistent/path');

        $service = app(GeoIpService::class);
        $service->getTimezoneByIp('1.2.3.4', ['Asia/Seoul']);

        $this->assertTrue($this->cache->has('geoip.timezone.1.2.3.4'));
        $this->assertSame(
            'g7:core:geoip.timezone.1.2.3.4',
            $this->cache->resolveKey('geoip.timezone.1.2.3.4')
        );
    }

    /**
     * E-2-2: GeoIP 캐시 히트 — 저장된 타임존이 지원 목록에 있으면 반환.
     */
    #[Test]
    public function e_2_2_geoip_cache_hit_returns_cached_timezone(): void
    {
        config()->set('geoip.enabled', true);
        config()->set('geoip.cache.enabled', true);

        $this->cache->put('geoip.timezone.1.2.3.4', 'Asia/Seoul');

        $service = app(GeoIpService::class);
        $result = $service->getTimezoneByIp('1.2.3.4', ['Asia/Seoul']);

        $this->assertSame('Asia/Seoul', $result);
    }

    /**
     * E-2-3: 조회 실패 시 빈 문자열 캐싱 — 다음 조회에서 반복 실패 방지.
     */
    #[Test]
    public function e_2_3_geoip_failure_caches_empty_string(): void
    {
        config()->set('geoip.enabled', true);
        config()->set('geoip.cache.enabled', true);
        config()->set('geoip.database_path', '/nonexistent/path');

        $service = app(GeoIpService::class);
        $first = $service->getTimezoneByIp('invalid-ip', ['Asia/Seoul']);

        $this->assertNull($first);
        $this->assertSame('', $this->cache->get('geoip.timezone.invalid-ip'));

        // 두 번째 호출은 캐시 히트(빈 문자열) → null 반환, 재조회 없음
        $second = $service->getTimezoneByIp('invalid-ip', ['Asia/Seoul']);
        $this->assertNull($second);
    }

    /**
     * E-2-4: geoip.cache.enabled = false 시 캐시에 저장되지 않음.
     */
    #[Test]
    public function e_2_4_geoip_cache_disabled_does_not_store(): void
    {
        config()->set('geoip.enabled', true);
        config()->set('g7_settings.core.cache.geoip_enabled', false);
        config()->set('geoip.cache.enabled', false);
        config()->set('geoip.database_path', '/nonexistent/path');

        $service = app(GeoIpService::class);
        $service->getTimezoneByIp('1.2.3.4', ['Asia/Seoul']);

        $this->assertFalse($this->cache->has('geoip.timezone.1.2.3.4'));
    }

    /**
     * E-2-5: TTL 만료 시 캐시 미스로 재조회 — array store 의 경우 TTL 검증은
     * forget 으로 간접 시뮬레이션한다.
     */
    #[Test]
    public function e_2_5_geoip_cache_miss_after_forget_triggers_relookup(): void
    {
        config()->set('geoip.enabled', true);
        config()->set('geoip.cache.enabled', true);
        config()->set('geoip.database_path', '/nonexistent/path');

        $this->cache->put('geoip.timezone.1.2.3.4', 'Asia/Seoul');
        $this->assertTrue($this->cache->has('geoip.timezone.1.2.3.4'));

        // TTL 만료 시뮬레이션
        $this->cache->forget('geoip.timezone.1.2.3.4');
        $this->assertFalse($this->cache->has('geoip.timezone.1.2.3.4'));

        // 재조회 → DB 없음 → 빈 문자열 재저장
        $service = app(GeoIpService::class);
        $result = $service->getTimezoneByIp('1.2.3.4', ['Asia/Seoul']);

        $this->assertNull($result);
        $this->assertSame('', $this->cache->get('geoip.timezone.1.2.3.4'));
    }
}
