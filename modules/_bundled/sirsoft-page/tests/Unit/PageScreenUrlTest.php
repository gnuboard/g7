<?php

namespace Modules\Sirsoft\Page\Tests\Unit;

use App\Extension\HookManager;
use App\Jobs\GenerateSitemapJob;
use App\Models\SitemapUrl;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Support\ScreenUrl;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Modules\Sirsoft\Page\Listeners\SeoPageCacheListener;
use Modules\Sirsoft\Page\Support\PageScreenUrl;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 화면 주소 래퍼(PageScreenUrl) 테스트 (#792 WP-U3 F1-7)
 */
class PageScreenUrlTest extends ModuleTestCase
{
    /**
     * 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(ScreenUrl::FILTER);

        parent::tearDown();
    }

    /**
     * 페이지 주소는 헬퍼·필터를 거치고 사용자 화면 URL 로 절대화되며, 기본 경로는 필터를 거치지 않습니다.
     *
     * @scenario case=wrapper
     *
     * @effects page_urls_use_helper, page_default_path_unfiltered
     *
     * @return void 반환값 없음
     */
    public function test_wrapper(): void
    {
        config(['app.url' => 'https://site.example.test', 'g7_settings.core.general.user_screen_url' => 'https://front.example.test']);

        $this->assertSame('/page/about', PageScreenUrl::show('about'));
        $this->assertSame('https://front.example.test/page/about', PageScreenUrl::show('about', absolute: true));

        HookManager::addFilter(ScreenUrl::FILTER, fn (string $path, string $key, array $params) => $key === 'page.show' ? '/p/'.$params['slug'] : $path);
        $this->assertSame('/p/about', PageScreenUrl::show('about'));
        $this->assertSame('/page/about', PageScreenUrl::defaultPath('about'));
    }

    /**
     * 페이지 저장 때의 사이트맵 증분 색인도 주소 필터를 탄다 (#792 W17) — 전체 재생성과 같은 주소여야 한다.
     *
     * @scenario case=sitemap_incremental
     *
     * @effects page_incremental_index_follows_filter
     *
     * @return void 반환값 없음
     */
    public function test_incremental_sitemap_index_follows_filter(): void
    {
        $this->app->instance(SeoCacheManagerInterface::class, Mockery::spy(SeoCacheManagerInterface::class));
        Bus::fake([GenerateSitemapJob::class]);
        HookManager::addFilter(ScreenUrl::FILTER, fn (string $path, string $key, array $params) => $key === 'page.show' ? '/p/'.$params['slug'] : $path);

        (new SeoPageCacheListener)->onPageChange((object) ['id' => 792, 'slug' => 'about-792', 'published' => true, 'updated_at' => now()]);

        $row = SitemapUrl::where('resource_type', 'page')->where('resource_id', '792')->first();
        $this->assertNotNull($row);
        $this->assertSame(ScreenUrl::toAbsolute('/p/about-792'), $row->loc);
    }
}
