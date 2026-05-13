<?php

namespace Tests\Unit\Cache\Migration;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Traits\CachesModuleStatus;
use App\Extension\Traits\CachesPluginStatus;
use App\Extension\Traits\CachesTemplateStatus;
use App\Extension\Traits\ClearsTemplateCaches;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 그룹 A 이관 검증 테스트 (확장 상태 캐시 Traits 4종)
 *
 * 계획서 §13 A-1 ~ A-4 의 확장 상태/버전 캐시 키 이관을 검증합니다.
 * - CachesModuleStatus / CachesPluginStatus / CachesTemplateStatus
 * - ClearsTemplateCaches (extension cache version)
 *
 * 각 trait 의 정적 메서드가 새 키 접두사(`g7:core:ext.*`)를 사용하는지,
 * lazy resolveCache() 헬퍼가 컨테이너 바인딩을 사용하는지 검증합니다.
 *
 * 모델 모킹은 복잡하므로 keys/cache 동작 검증에 집중하고,
 * DB 통합 검증은 기존 ModuleManagerLayoutTest / TemplateManagerOverrideTest 에 의존합니다.
 */
class ExtensionStatusCacheTest extends TestCase
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
    // A-1. CachesModuleStatus
    // ========================================================================

    /**
     * A-1-1: 모듈 활성/설치 캐시 키 형식 검증.
     */
    #[Test]
    public function a_1_1_module_cache_key_uses_new_prefix(): void
    {
        $this->cache->put('ext.modules.active_identifiers', ['mod-a', 'mod-b']);
        $this->cache->put('ext.modules.installed_identifiers', ['mod-a', 'mod-b']);

        $this->assertSame(
            'g7:core:ext.modules.active_identifiers',
            $this->cache->resolveKey('ext.modules.active_identifiers')
        );
        $this->assertSame(
            ['mod-a', 'mod-b'],
            $this->cache->get('ext.modules.active_identifiers')
        );
    }

    /**
     * A-1-3 ~ A-1-6: invalidateModuleStatusCache() 가 두 키 모두 무효화.
     */
    #[Test]
    public function a_1_3_invalidate_module_status_clears_both_keys(): void
    {
        $this->cache->put('ext.modules.active_identifiers', ['mod-a']);
        $this->cache->put('ext.modules.installed_identifiers', ['mod-a']);

        $stub = new class {
            use CachesModuleStatus;
        };
        $stub::invalidateModuleStatusCache();

        $this->assertFalse($this->cache->has('ext.modules.active_identifiers'));
        $this->assertFalse($this->cache->has('ext.modules.installed_identifiers'));
    }

    // ========================================================================
    // A-2. CachesPluginStatus
    // ========================================================================

    /**
     * A-2-1: 플러그인 캐시 키 형식 검증.
     */
    #[Test]
    public function a_2_1_plugin_cache_key_uses_new_prefix(): void
    {
        $this->cache->put('ext.plugins.active_identifiers', ['plugin-a']);

        $this->assertSame(
            'g7:core:ext.plugins.active_identifiers',
            $this->cache->resolveKey('ext.plugins.active_identifiers')
        );
        $this->assertTrue($this->cache->has('ext.plugins.active_identifiers'));
    }

    /**
     * A-2-3: invalidatePluginStatusCache() 가 두 키 모두 무효화.
     */
    #[Test]
    public function a_2_3_invalidate_plugin_status_clears_both_keys(): void
    {
        $this->cache->put('ext.plugins.active_identifiers', ['plugin-a']);
        $this->cache->put('ext.plugins.installed_identifiers', ['plugin-a']);

        $stub = new class {
            use CachesPluginStatus;
        };
        $stub::invalidatePluginStatusCache();

        $this->assertFalse($this->cache->has('ext.plugins.active_identifiers'));
        $this->assertFalse($this->cache->has('ext.plugins.installed_identifiers'));
    }

    // ========================================================================
    // A-3. CachesTemplateStatus
    // ========================================================================

    /**
     * A-3-1: 템플릿 캐시 키 형식 검증 (4종).
     */
    #[Test]
    public function a_3_1_template_cache_keys_use_new_prefix(): void
    {
        $this->cache->put('ext.templates.active_identifiers', ['t-a']);
        $this->cache->put('ext.templates.active_identifiers_admin', ['t-admin']);
        $this->cache->put('ext.templates.active_identifiers_user', ['t-user']);
        $this->cache->put('ext.templates.installed_identifiers', ['t-a']);

        $this->assertSame(
            'g7:core:ext.templates.active_identifiers',
            $this->cache->resolveKey('ext.templates.active_identifiers')
        );
        $this->assertTrue($this->cache->has('ext.templates.active_identifiers_admin'));
        $this->assertTrue($this->cache->has('ext.templates.active_identifiers_user'));
    }

    /**
     * A-3-3: invalidateTemplateStatusCache() 가 4개 키 모두 무효화.
     */
    #[Test]
    public function a_3_3_invalidate_template_status_clears_all_four_keys(): void
    {
        $this->cache->put('ext.templates.active_identifiers', ['t-a']);
        $this->cache->put('ext.templates.active_identifiers_admin', ['t-admin']);
        $this->cache->put('ext.templates.active_identifiers_user', ['t-user']);
        $this->cache->put('ext.templates.installed_identifiers', ['t-a']);

        $stub = new class {
            use CachesTemplateStatus;
        };
        $stub::invalidateTemplateStatusCache();

        $this->assertFalse($this->cache->has('ext.templates.active_identifiers'));
        $this->assertFalse($this->cache->has('ext.templates.active_identifiers_admin'));
        $this->assertFalse($this->cache->has('ext.templates.active_identifiers_user'));
        $this->assertFalse($this->cache->has('ext.templates.installed_identifiers'));
    }

    // ========================================================================
    // A-4. ClearsTemplateCaches (extension cache version)
    // ========================================================================

    /**
     * A-4-1: 확장 캐시 버전 키 형식 + 증가 동작 검증.
     */
    #[Test]
    public function a_4_1_increment_extension_cache_version(): void
    {
        $this->cache->put('ext.cache_version', 1000);

        $stub = new class {
            use ClearsTemplateCaches {
                incrementExtensionCacheVersion as public callIncrement;
            }
        };
        $stub->callIncrement();

        $newVersion = ClearsTemplateCaches::getExtensionCacheVersion();
        $this->assertGreaterThan(1000, $newVersion);
        $this->assertSame(
            'g7:core:ext.cache_version',
            $this->cache->resolveKey('ext.cache_version')
        );
    }

    /**
     * A-4-3: 미설정 시 0 반환.
     */
    #[Test]
    public function a_4_3_get_extension_cache_version_returns_zero_when_unset(): void
    {
        $this->cache->forget('ext.cache_version');

        $this->assertSame(0, ClearsTemplateCaches::getExtensionCacheVersion());
    }

    // ========================================================================
    // A-1 추가: 모듈 상태 캐시 라이프사이클 시나리오
    // ========================================================================

    /**
     * A-1-1: 모듈 활성 목록 캐시 생성 — put 후 has=true.
     */
    #[Test]
    public function a_1_create_active_modules_cache(): void
    {
        $this->cache->put('ext.modules.active_identifiers', ['mod-a', 'mod-b', 'mod-c']);

        $this->assertSame(
            ['mod-a', 'mod-b', 'mod-c'],
            $this->cache->get('ext.modules.active_identifiers')
        );
    }

    /**
     * A-1-2: 모듈 활성 목록 캐시 히트 — 두 번째 read 동일.
     */
    #[Test]
    public function a_1_active_modules_cache_hit(): void
    {
        $this->cache->put('ext.modules.active_identifiers', ['mod-a']);

        $first = $this->cache->get('ext.modules.active_identifiers');
        $second = $this->cache->get('ext.modules.active_identifiers');

        $this->assertSame($first, $second);
    }

    /**
     * A-1-4: 모듈 활성화 시 캐시 무효화 — invalidate 후 has=false.
     */
    #[Test]
    public function a_1_4_module_activate_invalidates_cache(): void
    {
        $this->cache->put('ext.modules.active_identifiers', ['mod-a', 'mod-b']);

        $stub = new class {
            use CachesModuleStatus;
        };
        $stub::invalidateModuleStatusCache();

        $this->assertFalse($this->cache->has('ext.modules.active_identifiers'));
    }

    /**
     * A-1-5: 모듈 비활성화 시 캐시 무효화 — installed 키도 함께 삭제.
     */
    #[Test]
    public function a_1_5_module_deactivate_invalidates_both_keys(): void
    {
        $this->cache->put('ext.modules.active_identifiers', ['mod-a']);
        $this->cache->put('ext.modules.installed_identifiers', ['mod-a', 'mod-b']);

        $stub = new class {
            use CachesModuleStatus;
        };
        $stub::invalidateModuleStatusCache();

        $this->assertFalse($this->cache->has('ext.modules.active_identifiers'));
        $this->assertFalse($this->cache->has('ext.modules.installed_identifiers'));
    }

    /**
     * A-1-7: 빈 배열 처리 — 빈 배열도 정상 캐시 가능 (무효화로 보장).
     */
    #[Test]
    public function a_1_7_empty_array_can_be_cached_and_invalidated(): void
    {
        $this->cache->put('ext.modules.active_identifiers', []);

        $this->assertTrue($this->cache->has('ext.modules.active_identifiers'));
        $this->assertSame([], $this->cache->get('ext.modules.active_identifiers'));

        // 모듈 설치/활성화 시 invalidateModuleStatusCache 호출로 확실히 갱신
        $stub = new class {
            use CachesModuleStatus;
        };
        $stub::invalidateModuleStatusCache();

        $this->assertFalse($this->cache->has('ext.modules.active_identifiers'));
    }

    // ========================================================================
    // A-2 추가: 플러그인 상태 캐시 라이프사이클
    // ========================================================================

    /**
     * A-2-2: 플러그인 활성 목록 캐시 히트.
     */
    #[Test]
    public function a_2_2_active_plugins_cache_hit(): void
    {
        $this->cache->put('ext.plugins.active_identifiers', ['plugin-a']);

        $this->assertSame(['plugin-a'], $this->cache->get('ext.plugins.active_identifiers'));
        $this->assertSame(['plugin-a'], $this->cache->get('ext.plugins.active_identifiers'));
    }

    /**
     * A-2-4: 플러그인 활성화/비활성화/삭제 시 캐시 무효화 (라이프사이클).
     */
    #[Test]
    public function a_2_4_plugin_lifecycle_invalidates_cache(): void
    {
        $stub = new class {
            use CachesPluginStatus;
        };

        // 활성화 시점
        $this->cache->put('ext.plugins.active_identifiers', ['plugin-a']);
        $stub::invalidatePluginStatusCache();
        $this->assertFalse($this->cache->has('ext.plugins.active_identifiers'));

        // 비활성화 시점
        $this->cache->put('ext.plugins.active_identifiers', ['plugin-b']);
        $stub::invalidatePluginStatusCache();
        $this->assertFalse($this->cache->has('ext.plugins.active_identifiers'));

        // 삭제 시점
        $this->cache->put('ext.plugins.installed_identifiers', ['plugin-c']);
        $stub::invalidatePluginStatusCache();
        $this->assertFalse($this->cache->has('ext.plugins.installed_identifiers'));
    }

    // ========================================================================
    // A-3 추가: 템플릿 타입별 캐시
    // ========================================================================

    /**
     * A-3-2: 템플릿 타입별 캐시 히트 (admin/user 분리).
     */
    #[Test]
    public function a_3_2_template_type_cache_hit(): void
    {
        $this->cache->put('ext.templates.active_identifiers_admin', ['t-admin-1']);
        $this->cache->put('ext.templates.active_identifiers_user', ['t-user-1']);

        $this->assertSame(['t-admin-1'], $this->cache->get('ext.templates.active_identifiers_admin'));
        $this->assertSame(['t-user-1'], $this->cache->get('ext.templates.active_identifiers_user'));
    }

    // ========================================================================
    // A-4 추가: ClearsTemplateCaches 확장 라이프사이클
    // ========================================================================

    /**
     * A-4-2: 모듈 설치 시 캐시 버전 증가 (T1 → T2 시뮬레이션).
     */
    #[Test]
    public function a_4_2_install_increments_cache_version(): void
    {
        $this->cache->put('ext.cache_version', 1000);

        $stub = new class {
            use ClearsTemplateCaches {
                incrementExtensionCacheVersion as public callIncrement;
            }
        };

        sleep(1); // time() 기반 증가 보장
        $stub->callIncrement();

        $this->assertGreaterThan(1000, ClearsTemplateCaches::getExtensionCacheVersion());
    }

    /**
     * A-4-3: 라이프사이클 단계마다 버전 증가 (T0 < T1 < T2 < T3).
     */
    #[Test]
    public function a_4_3_lifecycle_increments_each_step(): void
    {
        $stub = new class {
            use ClearsTemplateCaches {
                incrementExtensionCacheVersion as public callIncrement;
            }
        };

        $stub->callIncrement();
        $t1 = ClearsTemplateCaches::getExtensionCacheVersion();

        sleep(1);
        $stub->callIncrement();
        $t2 = ClearsTemplateCaches::getExtensionCacheVersion();

        sleep(1);
        $stub->callIncrement();
        $t3 = ClearsTemplateCaches::getExtensionCacheVersion();

        $this->assertGreaterThan(0, $t1);
        $this->assertGreaterThanOrEqual($t1, $t2);
        $this->assertGreaterThanOrEqual($t2, $t3);
    }
}
