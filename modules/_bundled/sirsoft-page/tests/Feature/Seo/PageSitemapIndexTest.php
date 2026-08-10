<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Seo;

use App\Jobs\GenerateSitemapJob;
use App\Models\SitemapUrl;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Modules\Sirsoft\Page\Listeners\SeoPageCacheListener;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * Page 리스너 사이트맵 증분 색인 테스트 (S4 ⑲)
 *
 * 검증 목적 (DoD: 공개→append / 비공개→remove):
 * - 발행(published=true) 페이지 → 색인, 미발행 → 색인 제거
 * - 페이지 삭제 → 색인 제거
 */
class PageSitemapIndexTest extends ModuleTestCase
{
    private SeoPageCacheListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        // 캐시 무효화는 이 테스트 범위 밖 — spy 로 대체
        $this->app->instance(SeoCacheManagerInterface::class, Mockery::spy(SeoCacheManagerInterface::class));
        Bus::fake([GenerateSitemapJob::class]);

        $this->listener = new SeoPageCacheListener;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 발행된 페이지 생성/수정 시 색인(append)된다.
     */
    public function test_published_page_is_indexed(): void
    {
        $page = (object) ['id' => 200, 'slug' => 'about', 'published' => true, 'updated_at' => now()];

        $this->listener->onPageChange($page);

        $row = SitemapUrl::where('resource_type', 'page')->where('resource_id', '200')->first();
        $this->assertNotNull($row, '발행 페이지는 색인돼야 합니다.');
        $this->assertStringEndsWith('/page/about', $row->loc);
        Bus::assertDispatched(GenerateSitemapJob::class);
    }

    /**
     * 미발행 페이지는 색인되지 않고, 발행→미발행 전환 시 색인이 제거된다.
     */
    public function test_unpublished_page_removes_index(): void
    {
        $this->listener->onPageChange((object) ['id' => 201, 'slug' => 'draft', 'published' => true, 'updated_at' => now()]);
        $this->assertSame(1, SitemapUrl::where('resource_id', '201')->count());

        $this->listener->onPageChange((object) ['id' => 201, 'slug' => 'draft', 'published' => false, 'updated_at' => now()]);
        $this->assertSame(0, SitemapUrl::where('resource_id', '201')->count(), '미발행 전환 시 색인이 제거돼야 합니다.');
    }

    /**
     * 페이지 삭제 시 색인이 제거된다.
     */
    public function test_page_delete_removes_index(): void
    {
        $this->listener->onPageChange((object) ['id' => 202, 'slug' => 'gone', 'published' => true, 'updated_at' => now()]);
        $this->assertSame(1, SitemapUrl::where('resource_id', '202')->count());

        $this->listener->onPageDelete((object) ['id' => 202, 'slug' => 'gone', 'published' => true]);

        $this->assertSame(0, SitemapUrl::where('resource_type', 'page')->where('resource_id', '202')->count());
    }
}
