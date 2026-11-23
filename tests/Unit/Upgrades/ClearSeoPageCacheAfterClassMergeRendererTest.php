<?php

namespace Tests\Unit\Upgrades;

require_once __DIR__.'/../../../upgrades/data/7.2.1/migrations/01_ClearSeoPageCacheAfterClassMergeRenderer.php';

use App\Extension\Cache\CoreCacheDriver;
use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_2_1\Migrations\ClearSeoPageCacheAfterClassMergeRenderer;
use Tests\TestCase;

/**
 * 7.2.1 업그레이드 스텝 — 봇 페이지 캐시 1회 무효화
 *
 * 7.2.1 봇 렌더러는 레이아웃 폭별 덮어쓰기의 classMerge·classMap 을 적용한다. 7.2.0 렌더러가 저장한 캐시가 남아
 * 있으면 캐시 수명 동안 봇이 예전 클래스를 받으므로 업그레이드 시 한 번 비운다.
 */
class ClearSeoPageCacheAfterClassMergeRendererTest extends TestCase
{
    private ClearSeoPageCacheAfterClassMergeRenderer $migration;

    private CoreCacheDriver $cache;

    /**
     * 테스트 초기화 - 배열 캐시 스토어와 마이그레이션을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->cache = new CoreCacheDriver('array');
        $this->cache->flush();
        $this->migration = new ClearSeoPageCacheAfterClassMergeRenderer;
    }

    /**
     * 업그레이드 컨텍스트를 만듭니다.
     *
     * @return UpgradeContext 7.2.0 → 7.2.1 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext('7.2.0', '7.2.1', '7.2.1');
    }

    /**
     * 인덱스에 등록된 캐시 항목과 인덱스 자체가 모두 삭제됩니다.
     *
     * @effects stale_page_cache_is_cleared_on_upgrade, cache_index_is_cleared_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_clears_indexed_page_cache_entries_and_index(): void
    {
        $this->cache->put('seo.page.abc', '<div class="flex p-8">7.2.0 결과</div>', 3600);
        $this->cache->put('seo.cached_urls', [['key' => 'seo.page.abc', 'url' => '/shop']], 3600);

        $this->migration->run($this->context());

        $this->assertNull($this->cache->get('seo.page.abc'));
        $this->assertNull($this->cache->get('seo.cached_urls'));
    }

    /**
     * 인덱스가 없거나 다시 돌려도 부작용이 없습니다(멱등).
     *
     * @effects invalidation_is_idempotent, unrelated_cache_namespaces_are_preserved
     *
     * @return void 반환값 없음
     */
    public function test_no_index_and_rerun_are_noop(): void
    {
        $this->cache->put('unrelated.key', 'keep', 3600);

        $this->migration->run($this->context());
        $this->migration->run($this->context());

        $this->assertSame('keep', $this->cache->get('unrelated.key'));
    }

    /**
     * 인덱스가 다른 네임스페이스 키를 가리켜도 그 키는 지우지 않습니다.
     *
     * @effects unrelated_cache_namespaces_are_preserved, stale_page_cache_is_cleared_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_index_entries_outside_page_cache_namespace_are_not_forgotten(): void
    {
        $this->cache->put('extension.middleware_index', ['a'], 3600);
        $this->cache->put('seo.page.abc', '<html></html>', 3600);
        $this->cache->put('seo.cached_urls', [
            ['key' => 'seo.page.abc', 'url' => '/'],
            ['key' => 'extension.middleware_index', 'url' => '/'],
            'not-an-array',
        ], 3600);

        $this->migration->run($this->context());

        $this->assertSame(['a'], $this->cache->get('extension.middleware_index'));
        $this->assertNull($this->cache->get('seo.page.abc'));
    }
}
