<?php

namespace Tests\Unit\Seo;

use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Extension\HookManager;
use App\Seo\SitemapGenerator;
use App\Seo\SitemapIndexer;
use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * SitemapIndexer 의 `url` → `loc` 정규화가 사이트맵 파일 생성기와 **같은 단일 지점**을 쓴다.
 *
 * 증분 색인(리스너 경유)이 `url()` 로 절대화하면 요청 호스트를 싣고, 접두사를 이미 품은
 * 값(`/g7/shop/1`)에 접두사를 **한 번 더** 실어 `/g7/g7/…` 이 된다. 전체 재생성
 * (`SitemapGenerator::normalizeEntry`)은 `AssetUrl::siteAbsolute()` 를 쓰므로 같은 리소스의
 * `<loc>` 이 생성 경로에 따라 갈리고, 크롤러는 그것을 오류 없이 다른 주소로 색인한다
 * (16차 G3 라운드 B-3).
 */
class SitemapIndexerLocTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::clearFilter(SitemapIndexer::COLLECT_FILTER);
        Config::set('app.url', 'https://example.com/g7');
        app()->instance('request', Request::create('http://localhost/api/x'));
    }

    protected function tearDown(): void
    {
        HookManager::clearFilter(SitemapIndexer::COLLECT_FILTER);
        AssetUrl::forceBasePrefix(null);
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects sitemap_incremental_index_uses_the_same_loc_builder_as_full_generation
     */
    public function test_index_resource_builds_loc_with_the_configured_site_url_idempotently(): void
    {
        $repository = Mockery::mock(SitemapUrlRepositoryInterface::class);
        $captured = null;
        $repository->shouldReceive('upsertForResource')
            ->once()
            ->withArgs(function (string $type, string $id, array $rows) use (&$captured): bool {
                $captured = $rows;

                return $type === 'product' && $id === '7';
            });

        $indexer = new SitemapIndexer($repository);
        $indexer->indexResource('product', 7, 'sirsoft-ecommerce', [
            ['url' => '/shop/products/7'],
            // 생산자가 접두사를 이미 실은 값 — 한 번 더 실리면 `/g7/g7/…` 이다.
            ['url' => '/g7/shop/products/7?variant=1'],
            ['loc' => 'https://cdn.example.com/already-absolute'],
        ]);

        $this->assertSame([
            'https://example.com/g7/shop/products/7',
            'https://example.com/g7/shop/products/7?variant=1',
            'https://cdn.example.com/already-absolute',
        ], array_column($captured, 'loc'));
    }

    /**
     * 두 생성 경로(증분 색인 · 전체 재생성)가 같은 입력에 같은 `loc` 을 낸다.
     *
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects sitemap_incremental_index_uses_the_same_loc_builder_as_full_generation
     */
    public function test_indexer_and_generator_agree_on_loc_for_the_same_entry(): void
    {
        $entry = ['url' => '/board/free/1', 'priority' => 0.5];

        $repository = Mockery::mock(SitemapUrlRepositoryInterface::class);
        $captured = null;
        $repository->shouldReceive('upsertForResource')
            ->once()
            ->withArgs(function (string $type, string $id, array $rows) use (&$captured): bool {
                $captured = $rows;

                return true;
            });

        (new SitemapIndexer($repository))->indexResource('board_post', 1, 'sirsoft-board', [$entry]);

        $this->assertSame(SitemapGenerator::entryLoc($entry), $captured[0]['loc']);
        $this->assertSame('https://example.com/g7/board/free/1', $captured[0]['loc']);
    }
}
