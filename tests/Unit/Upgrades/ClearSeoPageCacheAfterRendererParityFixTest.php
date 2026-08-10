<?php

namespace Tests\Unit\Upgrades;

require_once __DIR__.'/../../../upgrades/data/7.0.6/migrations/02_ClearSeoPageCacheAfterRendererParityFix.php';

use App\Extension\Cache\CoreCacheDriver;
use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_0_6\Migrations\ClearSeoPageCacheAfterRendererParityFix;
use Tests\TestCase;

/**
 * 7.0.6 업그레이드 스텝 — 봇 페이지 캐시 1회 무효화
 *
 * 7.0.6 이전 렌더러가 빈 본문으로 저장한 캐시가 남아 있으면, 렌더러를 고쳐도
 * 캐시 수명 동안 검색 봇이 계속 빈 페이지를 받는다. 업그레이드 시 한 번 비운다.
 */
class ClearSeoPageCacheAfterRendererParityFixTest extends TestCase
{
    private ClearSeoPageCacheAfterRendererParityFix $migration;

    private CoreCacheDriver $cache;

    /**
     * 테스트 초기화 - 배열 캐시 스토어와 마이그레이션을 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->cache = new CoreCacheDriver('array');
        $this->cache->flush();
        $this->migration = new ClearSeoPageCacheAfterRendererParityFix;
    }

    /**
     * 업그레이드 컨텍스트를 만듭니다.
     *
     * @return UpgradeContext 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext('7.0.5', '7.0.6', '7.0.6');
    }

    /**
     * 인덱스에 등록된 캐시 항목과 인덱스 자체가 모두 삭제됩니다.
     *
     * @effects stale_page_cache_is_cleared_on_upgrade, cache_index_is_cleared_on_upgrade
     */
    public function test_clears_indexed_page_cache_entries_and_index(): void
    {
        $this->cache->put('seo.page.abc', '<html>빈 본문</html>', 3600);
        $this->cache->put('seo.page.def', '<html>빈 본문2</html>', 3600);
        $this->cache->put('seo.cached_urls', [
            ['key' => 'seo.page.abc', 'url' => '/board/free/1'],
            ['key' => 'seo.page.def', 'url' => '/page/about'],
        ], 3600);

        $this->migration->run($this->context());

        $this->assertNull($this->cache->get('seo.page.abc'));
        $this->assertNull($this->cache->get('seo.page.def'));
        $this->assertNull($this->cache->get('seo.cached_urls'));
    }

    /**
     * 인덱스가 없으면 아무 것도 하지 않습니다 (no-op).
     *
     * @effects invalidation_is_idempotent, unrelated_cache_namespaces_are_preserved
     */
    public function test_no_index_is_noop(): void
    {
        $this->cache->put('unrelated.key', 'keep', 3600);

        $this->migration->run($this->context());

        $this->assertSame('keep', $this->cache->get('unrelated.key'));
    }

    /**
     * 재실행해도 부작용이 없습니다 (멱등).
     *
     * @effects invalidation_is_idempotent
     */
    public function test_is_idempotent(): void
    {
        $this->cache->put('seo.page.abc', '<html></html>', 3600);
        $this->cache->put('seo.cached_urls', [['key' => 'seo.page.abc', 'url' => '/']], 3600);

        $this->migration->run($this->context());
        $this->migration->run($this->context());

        $this->assertNull($this->cache->get('seo.page.abc'));
        $this->assertNull($this->cache->get('seo.cached_urls'));
    }

    /**
     * 인덱스 항목이 손상되어 있어도 나머지를 삭제하고 인덱스를 비웁니다.
     *
     * @effects stale_page_cache_is_cleared_on_upgrade, cache_index_is_cleared_on_upgrade
     */
    public function test_malformed_index_entries_are_skipped(): void
    {
        $this->cache->put('seo.page.ok', '<html></html>', 3600);
        $this->cache->put('seo.cached_urls', [
            'not-an-array',
            ['url' => '/키없음'],
            ['key' => '', 'url' => '/빈키'],
            ['key' => 'seo.page.ok', 'url' => '/정상'],
        ], 3600);

        $this->migration->run($this->context());

        $this->assertNull($this->cache->get('seo.page.ok'));
        $this->assertNull($this->cache->get('seo.cached_urls'));
    }

    /**
     * 봇 페이지 캐시 외의 캐시는 건드리지 않습니다.
     *
     * @effects unrelated_cache_namespaces_are_preserved
     */
    public function test_unrelated_cache_keys_are_preserved(): void
    {
        $this->cache->put('extension.middleware_index', ['a'], 3600);
        $this->cache->put('seo.page.abc', '<html></html>', 3600);
        $this->cache->put('seo.cached_urls', [['key' => 'seo.page.abc', 'url' => '/']], 3600);

        $this->migration->run($this->context());

        $this->assertSame(['a'], $this->cache->get('extension.middleware_index'));
    }

    /**
     * 인덱스가 손상되어 다른 네임스페이스 키를 가리켜도 그 키는 지우지 않습니다.
     *
     * 삭제 대상을 봇 페이지 캐시 프리픽스(`seo.page.`)로 한정해, 오염된 인덱스가
     * 무관한 캐시를 함께 날리는 사고를 막습니다.
     *
     * @effects unrelated_cache_namespaces_are_preserved, stale_page_cache_is_cleared_on_upgrade
     */
    public function test_index_entries_outside_page_cache_namespace_are_not_forgotten(): void
    {
        $this->cache->put('extension.middleware_index', ['a'], 3600);
        $this->cache->put('seo.page.abc', '<html></html>', 3600);
        $this->cache->put('seo.cached_urls', [
            ['key' => 'seo.page.abc', 'url' => '/'],
            // 오염된 엔트리 — 봇 페이지 캐시가 아닌 키를 가리킨다
            ['key' => 'extension.middleware_index', 'url' => '/'],
        ], 3600);

        $this->migration->run($this->context());

        $this->assertSame(['a'], $this->cache->get('extension.middleware_index'), '무관한 캐시가 삭제되었습니다.');
        $this->assertNull($this->cache->get('seo.page.abc'), '봇 페이지 캐시는 삭제되어야 합니다.');
    }
}
