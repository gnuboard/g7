<?php

namespace Tests\Feature\Seo;

use App\Extension\HookManager;
use App\Models\SitemapUrl;
use App\Seo\SitemapIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SitemapIndexer 통합 테스트 (S4 리스너 경유 색인기)
 *
 * 검증 목적:
 * - indexResource 로 공개 리소스 색인(upsert)
 * - deindexResource 로 비공개/삭제 리소스 색인 해제
 * - url(상대) → loc(절대) 정규화
 * - collect_for_resource filter 훅으로 제3자 항목 가공
 * - 필터가 항목을 모두 제거하면 색인도 제거됨
 */
class SitemapIndexerTest extends TestCase
{
    use RefreshDatabase;

    private SitemapIndexer $indexer;

    /**
     * 테스트 초기화
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->indexer = $this->app->make(SitemapIndexer::class);
        HookManager::clearFilter(SitemapIndexer::COLLECT_FILTER);
    }

    /**
     * 테스트 정리 - filter 훅 누출 방지
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(SitemapIndexer::COLLECT_FILTER);

        parent::tearDown();
    }

    /**
     * indexResource: 공개 리소스를 색인하며 url 을 loc 으로 정규화한다.
     */
    public function test_index_resource_upserts_and_normalizes_url(): void
    {
        $this->indexer->indexResource('product', 7, 'sirsoft-ecommerce', [[
            'url' => '/shop/products/7',
            'changefreq' => 'weekly',
            'priority' => 0.8,
        ]]);

        $row = SitemapUrl::where('resource_type', 'product')->where('resource_id', '7')->first();

        $this->assertNotNull($row);
        $this->assertSame(url('/shop/products/7'), $row->loc, 'url 은 절대 loc 으로 정규화돼야 합니다.');
        $this->assertSame('sirsoft-ecommerce', $row->contributor);
    }

    /**
     * deindexResource: 색인을 제거한다.
     */
    public function test_deindex_resource_removes_index(): void
    {
        $this->indexer->indexResource('page', 3, 'sirsoft-page', [['url' => '/page/about']]);
        $this->assertSame(1, SitemapUrl::where('resource_type', 'page')->where('resource_id', '3')->count());

        $this->indexer->deindexResource('page', 3);

        $this->assertSame(0, SitemapUrl::where('resource_type', 'page')->where('resource_id', '3')->count());
    }

    /**
     * collect_for_resource filter 훅으로 제3자가 항목을 가공할 수 있다.
     */
    public function test_collect_filter_hook_can_transform_entries(): void
    {
        HookManager::addFilter(SitemapIndexer::COLLECT_FILTER, function (array $entries) {
            $entries[] = ['url' => '/extra'];

            return $entries;
        });

        $this->indexer->indexResource('product', 8, 'sirsoft-ecommerce', [['url' => '/shop/products/8']]);

        $locs = SitemapUrl::where('resource_type', 'product')->where('resource_id', '8')->pluck('loc')->all();

        $this->assertContains(url('/shop/products/8'), $locs);
        $this->assertContains(url('/extra'), $locs, 'filter 훅이 추가한 항목이 색인돼야 합니다.');
    }

    /**
     * 필터가 항목을 모두 제거하면 색인도 제거된다(정합 유지).
     */
    public function test_filter_removing_all_entries_deindexes(): void
    {
        $this->indexer->indexResource('product', 9, 'sirsoft-ecommerce', [['url' => '/shop/products/9']]);
        $this->assertSame(1, SitemapUrl::where('resource_id', '9')->count());

        HookManager::addFilter(SitemapIndexer::COLLECT_FILTER, fn () => []);

        $this->indexer->indexResource('product', 9, 'sirsoft-ecommerce', [['url' => '/shop/products/9']]);

        $this->assertSame(0, SitemapUrl::where('resource_id', '9')->count(), '항목이 모두 제거되면 색인이 제거돼야 합니다.');
    }
}
