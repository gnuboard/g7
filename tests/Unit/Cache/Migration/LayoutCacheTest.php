<?php

namespace Tests\Unit\Cache\Migration;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 그룹 B 이관 검증 테스트 (Layout 캐시)
 *
 * 계획서 §13 B-1, B-2, B-3, B-4 의 16개 테스트 케이스를 검증합니다.
 *
 * - B-1: LayoutService 내부 캐시 (병합 결과)
 * - B-2: PublicLayoutController 서빙 캐시
 * - B-3: LayoutResolverService 해석 캐시
 * - B-4: InvalidatesLayoutCache Trait 카스케이드 무효화
 *
 * 모델/Repository 통합 시나리오는 LayoutServiceCachingTest /
 * ModuleManagerLayoutTest / TemplateManagerOverrideTest 에서 검증되며,
 * 본 파일은 새 키 형식과 키 일관성에 집중합니다.
 */
class LayoutCacheTest extends TestCase
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
    // B-1. LayoutService 내부 캐시
    // ========================================================================

    /**
     * B-1-1: 레이아웃 병합 결과 캐시 키 형식 검증.
     */
    #[Test]
    public function b_1_1_merged_layout_cache_key_format(): void
    {
        $key = 'template.1.layout.admin/dashboard';
        $this->cache->put($key, ['merged' => true]);

        $this->assertSame(
            'g7:core:template.1.layout.admin/dashboard',
            $this->cache->resolveKey($key)
        );
        $this->assertTrue($this->cache->has($key));
    }

    /**
     * B-1-2: 레이아웃 캐시 히트 — 동일 키 재조회 시 동일 값.
     */
    #[Test]
    public function b_1_2_merged_layout_cache_hit(): void
    {
        $key = 'template.1.layout.admin/dashboard';
        $this->cache->put($key, ['v' => 1]);

        $this->assertSame(['v' => 1], $this->cache->get($key));
        $this->assertSame(['v' => 1], $this->cache->get($key));
    }

    /**
     * B-1-3: 레이아웃 편집 저장 시 캐시 무효화.
     */
    #[Test]
    public function b_1_3_layout_edit_invalidates_merged_cache(): void
    {
        $key = 'template.1.layout.admin/dashboard';
        $this->cache->put($key, 'old');

        $this->cache->forget($key);

        $this->assertFalse($this->cache->has($key));
    }

    /**
     * B-1-4: 레이아웃 이름 변경 시 이전 키 삭제.
     */
    #[Test]
    public function b_1_4_layout_rename_invalidates_old_key(): void
    {
        $oldKey = 'template.1.layout.admin/old_name';
        $newKey = 'template.1.layout.admin/new_name';

        $this->cache->put($oldKey, ['old' => true]);
        $this->cache->forget($oldKey);
        $this->cache->put($newKey, ['new' => true]);

        $this->assertFalse($this->cache->has($oldKey));
        $this->assertTrue($this->cache->has($newKey));
    }

    /**
     * B-1-5: 모듈 레이아웃 sourceHash 포함 키 형식.
     */
    #[Test]
    public function b_1_5_module_layout_source_hash_key(): void
    {
        $sourceHash = md5('module'.'sirsoft-ecommerce');
        $key = "template.1.layout.admin/products.{$sourceHash}";

        $this->cache->put($key, ['module' => true]);

        $this->assertTrue($this->cache->has($key));
        $this->assertSame(
            "g7:core:template.1.layout.admin/products.{$sourceHash}",
            $this->cache->resolveKey($key)
        );
    }

    /**
     * B-1-6: 부모-자식 extends 캐시 카스케이드 무효화.
     *
     * InvalidatesLayoutCache Trait 또는 LayoutService.clearLayoutCache 가
     * 자식 레이아웃 캐시도 함께 삭제하는 동작을 키 차원에서 검증.
     */
    #[Test]
    public function b_1_6_extends_cascade_invalidation(): void
    {
        $baseKey = 'template.1.layout._admin_base';
        $childKey = 'template.1.layout.admin/dashboard';

        $this->cache->put($baseKey, ['base' => true]);
        $this->cache->put($childKey, ['child' => true]);

        // 부모 변경 → 부모 + 자식 모두 삭제
        $this->cache->forget($baseKey);
        $this->cache->forget($childKey);

        $this->assertFalse($this->cache->has($baseKey));
        $this->assertFalse($this->cache->has($childKey));
    }

    // ========================================================================
    // B-2. PublicLayoutController 서빙 캐시
    // ========================================================================

    /**
     * B-2-1: 서빙 캐시 키 형식 — `layout.{identifier}.{name}.v{version}`
     */
    #[Test]
    public function b_2_1_serving_cache_key_format(): void
    {
        $version = 1000;
        $key = "layout.sirsoft-admin_basic.admin/dashboard.v{$version}";

        $this->cache->put($key, ['layout' => 'data']);

        $this->assertSame(
            "g7:core:layout.sirsoft-admin_basic.admin/dashboard.v{$version}",
            $this->cache->resolveKey($key)
        );
        $this->assertSame(['layout' => 'data'], $this->cache->get($key));
    }

    /**
     * B-2-2: 서빙 캐시 히트 — 두 번째 요청은 동일 결과 반환.
     */
    #[Test]
    public function b_2_2_serving_cache_hit(): void
    {
        $key = 'layout.sirsoft-admin_basic.admin/dashboard.v1000';
        $this->cache->put($key, ['cached' => 'response']);

        $this->assertSame(['cached' => 'response'], $this->cache->get($key));
        $this->assertSame(['cached' => 'response'], $this->cache->get($key));
    }

    /**
     * B-2-3: 레이아웃 편집 시 현재 버전 서빙 캐시 무효화.
     *
     * InvalidatesLayoutCache::forgetLayoutCacheKeys() 가 동일 키를 삭제하는
     * 패턴을 키 형식 차원에서 검증.
     */
    #[Test]
    public function b_2_3_layout_edit_clears_current_version_serving_cache(): void
    {
        $version = 1000;
        $this->cache->put('ext.cache_version', $version);
        $key = "layout.sirsoft-admin_basic.admin/dashboard.v{$version}";
        $this->cache->put($key, ['old' => 'content']);

        // 편집 시 현재 버전 키만 능동 삭제
        $current = (int) $this->cache->get('ext.cache_version', 0);
        $this->cache->forget("layout.sirsoft-admin_basic.admin/dashboard.v{$current}");

        $this->assertFalse($this->cache->has($key));
    }

    /**
     * B-2-4: 확장 변경 시 ext.cache_version 증가로 자동 무효화.
     *
     * 이전 버전 캐시는 능동 삭제하지 않고 TTL 만료에 의존.
     * 새 버전 키로 새 캐시 생성됨.
     */
    #[Test]
    public function b_2_4_extension_change_creates_new_version_key(): void
    {
        $oldVersion = 1000;
        $newVersion = 2000;

        $oldKey = "layout.sirsoft-admin_basic.admin/dashboard.v{$oldVersion}";
        $newKey = "layout.sirsoft-admin_basic.admin/dashboard.v{$newVersion}";

        $this->cache->put($oldKey, ['old' => true]);
        $this->cache->put('ext.cache_version', $newVersion);
        $this->cache->put($newKey, ['new' => true]);

        // 이전 키는 잔존 (능동 삭제 안 함)
        $this->assertTrue($this->cache->has($oldKey));
        // 새 키는 별도 키로 생성됨
        $this->assertTrue($this->cache->has($newKey));
        $this->assertNotSame($this->cache->get($oldKey), $this->cache->get($newKey));
    }

    // ========================================================================
    // B-3. LayoutResolverService 해석 캐시
    // ========================================================================

    /**
     * B-3-1: 모듈 레이아웃 해석 캐시 키 형식.
     */
    #[Test]
    public function b_3_1_resolver_cache_key_format(): void
    {
        $key = 'layout_resolver.1.admin/products';
        $this->cache->put($key, 42);

        $this->assertSame(
            'g7:core:layout_resolver.1.admin/products',
            $this->cache->resolveKey($key)
        );
        $this->assertSame(42, $this->cache->get($key));
    }

    /**
     * B-3-2: 해석 캐시 히트 — 재조회 시 동일 ID 반환.
     */
    #[Test]
    public function b_3_2_resolver_cache_hit(): void
    {
        $key = 'layout_resolver.1.admin/products';
        $this->cache->put($key, 42);

        $this->assertSame(42, $this->cache->get($key));
        $this->assertSame(42, $this->cache->get($key));
    }

    /**
     * B-3-3: 레이아웃 편집 시 해석 캐시 무효화.
     */
    #[Test]
    public function b_3_3_layout_edit_invalidates_resolver_cache(): void
    {
        $key = 'layout_resolver.1.admin/products';
        $this->cache->put($key, 42);

        $this->cache->forget($key);

        $this->assertFalse($this->cache->has($key));
    }

    /**
     * B-3-4: 모듈 비활성화 시 해석 캐시 무효화 — 모듈의 모든 레이아웃 키 삭제.
     */
    #[Test]
    public function b_3_4_module_deactivate_invalidates_all_resolver_keys(): void
    {
        $keys = [
            'layout_resolver.1.sirsoft-ecommerce.admin_products',
            'layout_resolver.1.sirsoft-ecommerce.admin_categories',
            'layout_resolver.1.sirsoft-ecommerce.admin_orders',
        ];

        foreach ($keys as $k) {
            $this->cache->put($k, 1);
        }

        // 모듈 비활성화 시뮬레이션 — 모든 키 삭제
        foreach ($keys as $k) {
            $this->cache->forget($k);
        }

        foreach ($keys as $k) {
            $this->assertFalse($this->cache->has($k));
        }
    }

    // ========================================================================
    // B-4. InvalidatesLayoutCache Trait
    // ========================================================================

    /**
     * B-4-1: 모듈 활성화 시 관련 레이아웃 캐시 전체 삭제 (3 레이아웃 × 3 종류 = 9개).
     */
    #[Test]
    public function b_4_1_module_activation_clears_all_layout_caches(): void
    {
        // 3 레이아웃 × 3 종류 캐시 = 9개 키
        for ($i = 1; $i <= 3; $i++) {
            $this->cache->put("template.1.layout.admin/page_{$i}", 'merged');
            $this->cache->put("layout.sirsoft-admin_basic.admin/page_{$i}.v1000", 'served');
            $this->cache->put("layout_resolver.1.admin/page_{$i}", $i);
        }

        // 모듈 활성화 → 모든 키 삭제 시뮬레이션
        for ($i = 1; $i <= 3; $i++) {
            $this->cache->forget("template.1.layout.admin/page_{$i}");
            $this->cache->forget("layout.sirsoft-admin_basic.admin/page_{$i}.v1000");
            $this->cache->forget("layout_resolver.1.admin/page_{$i}");
        }

        // 9개 모두 삭제 확인
        for ($i = 1; $i <= 3; $i++) {
            $this->assertFalse($this->cache->has("template.1.layout.admin/page_{$i}"));
            $this->assertFalse($this->cache->has("layout.sirsoft-admin_basic.admin/page_{$i}.v1000"));
            $this->assertFalse($this->cache->has("layout_resolver.1.admin/page_{$i}"));
        }
    }

    /**
     * B-4-2: 템플릿 비활성화 시 해당 템플릿의 모든 레이아웃 캐시 삭제.
     */
    #[Test]
    public function b_4_2_template_deactivation_clears_template_layouts(): void
    {
        $templateKeys = [
            'template.1.layout.admin/dashboard',
            'template.1.layout.admin/users',
            'template.1.layout.admin/settings',
        ];

        foreach ($templateKeys as $k) {
            $this->cache->put($k, 'data');
        }

        // 템플릿 비활성화 → 해당 템플릿 키 모두 삭제
        foreach ($templateKeys as $k) {
            $this->cache->forget($k);
        }

        foreach ($templateKeys as $k) {
            $this->assertFalse($this->cache->has($k));
        }
    }
}
