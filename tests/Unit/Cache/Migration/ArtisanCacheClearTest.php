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
 * CacheInterface 의 다건 put/forget 왕복 동작을 키 차원에서 검증합니다.
 *
 * 주의: 아래 키 문자열은 이관 당시의 픽스처다. 현행 커맨드는 유령 키 forget 을
 * 제거하고 상태 키 무효화 + 레이아웃 캐시 + 버전 bump 로 동작한다 (#588) —
 * 커맨드의 실동작 검증은 {Module,Plugin,Template}ArtisanCommandsTest 가 담당한다.
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
     * G-1-1: 모듈 키 픽스처 다건 put→forget 왕복 (드라이버 동작 검증 — 커맨드 미호출).
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

        // CacheInterface.forget 다건 호출 (드라이버 동작 검증)
        foreach ($keys as $k) {
            $this->cache->forget($k);
        }

        foreach ($keys as $k) {
            $this->assertFalse($this->cache->has($k), "Key {$k} should be cleared");
        }
    }

    /**
     * G-1-2: 플러그인 키 픽스처 다건 put→forget 왕복 (드라이버 동작 검증 — 커맨드 미호출).
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
     * G-1-3: 템플릿/레이아웃 키 픽스처 다건 put→forget 왕복 (드라이버 동작 검증 — 커맨드 미호출).
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
     * G-1-4: 전역 + 개별 키 혼합 다건 put→forget 왕복 (드라이버 동작 검증 — 커맨드 미호출).
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

        // 전역 + 개별 모두 forget (드라이버 동작 검증)
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
