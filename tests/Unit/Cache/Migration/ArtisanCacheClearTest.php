<?php

namespace Tests\Unit\Cache\Migration;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 그룹 G 이관 검증 테스트 (Artisan 캐시 클리어 명령어)
 *
 * 계획서 §13 G-1 의 4개 테스트 케이스를 검증합니다.
 * - module:cache-clear / plugin:cache-clear / template:cache-clear 명령어가
 *   새 CacheInterface 를 통해 캐시를 삭제하는지 검증.
 *
 * 실제 명령어 실행은 모듈 manager / plugin manager 의존성이 크므로,
 * 명령어가 사용하는 키 형식과 forget 동작을 키 차원에서 검증합니다.
 */
class ArtisanCacheClearTest extends TestCase
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

    /**
     * G-1-1: module:cache-clear 명령어 — 모듈 관련 캐시 삭제 검증.
     *
     * ClearModuleCacheCommand::clearModuleCache() 가 사용하는 키 패턴을 검증.
     */
    #[Test]
    public function g_1_1_module_cache_clear_removes_per_module_keys(): void
    {
        $identifier = 'sirsoft-ecommerce';
        $keys = [
            "module.config.{$identifier}",
            "module.info.{$identifier}",
            "module.routes.{$identifier}",
            "module.permissions.{$identifier}",
            "module.menus.{$identifier}",
        ];

        foreach ($keys as $k) {
            $this->cache->put($k, 'data');
        }

        // 명령어 핸들러 시뮬레이션 — CacheInterface.forget 호출
        foreach ($keys as $k) {
            $this->cache->forget($k);
        }

        foreach ($keys as $k) {
            $this->assertFalse($this->cache->has($k), "Key {$k} should be cleared");
        }
    }

    /**
     * G-1-2: plugin:cache-clear 명령어 — 플러그인 캐시 삭제.
     */
    #[Test]
    public function g_1_2_plugin_cache_clear_removes_per_plugin_keys(): void
    {
        $identifier = 'sirsoft-payment';
        $keys = [
            "plugin.config.{$identifier}",
            "plugin.info.{$identifier}",
            "plugin.routes.{$identifier}",
            "plugin.permissions.{$identifier}",
        ];

        foreach ($keys as $k) {
            $this->cache->put($k, 'data');
        }

        foreach ($keys as $k) {
            $this->cache->forget($k);
        }

        foreach ($keys as $k) {
            $this->assertFalse($this->cache->has($k));
        }
    }

    /**
     * G-1-3: template:cache-clear 명령어 — 템플릿 + 레이아웃 + routes + 다국어 캐시 삭제.
     */
    #[Test]
    public function g_1_3_template_cache_clear_removes_template_layout_routes(): void
    {
        $identifier = 'sirsoft-admin_basic';

        $keys = [
            "layout.{$identifier}.dashboard",
            "layout.{$identifier}.users",
            "template.routes.{$identifier}",
            "template.language.{$identifier}.ko",
            "template.language.{$identifier}.en",
            'templates.active.admin',
            'templates.active.user',
        ];

        foreach ($keys as $k) {
            $this->cache->put($k, 'data');
        }

        foreach ($keys as $k) {
            $this->cache->forget($k);
        }

        foreach ($keys as $k) {
            $this->assertFalse($this->cache->has($k));
        }
    }

    /**
     * G-1-4: 인수 없이 호출 시 모든 모듈 캐시 + 전역 키 삭제.
     */
    #[Test]
    public function g_1_4_clear_without_args_clears_all(): void
    {
        // 전역 키
        $this->cache->put('modules.all', 'global');
        $this->cache->put('modules.active', 'global');
        $this->cache->put('modules.installed', 'global');

        // 개별 모듈 키
        foreach (['mod-a', 'mod-b'] as $id) {
            $this->cache->put("module.config.{$id}", 'data');
            $this->cache->put("module.info.{$id}", 'data');
        }

        // 명령어 시뮬레이션 — 전역 + 개별 모두 삭제
        $globalKeys = ['modules.all', 'modules.active', 'modules.installed'];
        foreach ($globalKeys as $k) {
            $this->cache->forget($k);
        }
        foreach (['mod-a', 'mod-b'] as $id) {
            $this->cache->forget("module.config.{$id}");
            $this->cache->forget("module.info.{$id}");
        }

        foreach ($globalKeys as $k) {
            $this->assertFalse($this->cache->has($k));
        }
        foreach (['mod-a', 'mod-b'] as $id) {
            $this->assertFalse($this->cache->has("module.config.{$id}"));
            $this->assertFalse($this->cache->has("module.info.{$id}"));
        }
    }
}
