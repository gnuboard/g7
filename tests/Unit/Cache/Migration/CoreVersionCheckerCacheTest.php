<?php

namespace Tests\Unit\Cache\Migration;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\CoreVersionChecker;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 그룹 F 이관 검증 테스트 (CoreVersionChecker)
 *
 * 계획서 §13 F-1 의 4개 테스트 케이스를 검증합니다.
 * - 호환성 검사 캐시 키가 새 접두사(`g7:core:ext.version_check.{type}.{version}`)로 이관됨
 * - clearCache() 가 3종 타입(modules/plugins/templates) 키를 모두 무효화함
 * - 코어 버전 변경 시 캐시 키가 자동 분리됨
 */
class CoreVersionCheckerCacheTest extends TestCase
{
    private CoreCacheDriver $cache;

    private ?string $originalEnvVersion = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->cache = new CoreCacheDriver('array');
        $this->app->instance(CacheInterface::class, $this->cache);

        // CoreVersionChecker::getCoreVersion() 은 env APP_VERSION 을 우선 읽으므로,
        // config('app.version') 로만 버전을 시뮬레이션하려면 env 를 먼저 비워야 한다.
        $this->originalEnvVersion = $_ENV['APP_VERSION'] ?? null;
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        putenv('APP_VERSION');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        if ($this->originalEnvVersion !== null) {
            $_ENV['APP_VERSION'] = $this->originalEnvVersion;
            $_SERVER['APP_VERSION'] = $this->originalEnvVersion;
            putenv('APP_VERSION='.$this->originalEnvVersion);
        }
        parent::tearDown();
    }

    /**
     * F-1-1: 호환성 검사 캐시 키 형식 검증.
     */
    #[Test]
    public function f_1_1_cache_key_uses_new_prefix(): void
    {
        config()->set('app.version', '7.0.1');

        $key = CoreVersionChecker::getCacheKey('modules');
        $this->assertSame('ext.version_check.modules.7.0.1', $key);

        $resolved = $this->cache->resolveKey($key);
        $this->assertSame('g7:core:ext.version_check.modules.7.0.1', $resolved);
    }

    /**
     * F-1-2: 호환성 검사 결과 저장 → 같은 키로 조회 시 캐시 히트.
     */
    #[Test]
    public function f_1_2_compatibility_cache_hit(): void
    {
        config()->set('app.version', '7.0.1');

        $key = CoreVersionChecker::getCacheKey('modules');
        $this->cache->put($key, true, CoreVersionChecker::getCacheTtl());

        $this->assertTrue($this->cache->has($key));
        $this->assertTrue($this->cache->get($key));
    }

    /**
     * F-1-3: clearCache() 호출 시 modules/plugins/templates 3개 키 모두 무효화.
     */
    #[Test]
    public function f_1_3_clear_cache_invalidates_all_three_types(): void
    {
        config()->set('app.version', '7.0.1');

        $this->cache->put(CoreVersionChecker::getCacheKey('modules'), true);
        $this->cache->put(CoreVersionChecker::getCacheKey('plugins'), true);
        $this->cache->put(CoreVersionChecker::getCacheKey('templates'), true);

        CoreVersionChecker::clearCache();

        $this->assertFalse($this->cache->has(CoreVersionChecker::getCacheKey('modules')));
        $this->assertFalse($this->cache->has(CoreVersionChecker::getCacheKey('plugins')));
        $this->assertFalse($this->cache->has(CoreVersionChecker::getCacheKey('templates')));
    }

    /**
     * F-1-4: 코어 버전 변경 시 새 키로 분리 — 기존 키와 충돌 없음.
     */
    #[Test]
    public function f_1_4_version_change_separates_keys(): void
    {
        config()->set('app.version', '7.0.0');
        $oldKey = CoreVersionChecker::getCacheKey('modules');
        $this->cache->put($oldKey, true);

        config()->set('app.version', '7.0.1');
        $newKey = CoreVersionChecker::getCacheKey('modules');

        $this->assertNotSame($oldKey, $newKey);
        $this->assertTrue($this->cache->has($oldKey));
        $this->assertFalse($this->cache->has($newKey));
    }
}
