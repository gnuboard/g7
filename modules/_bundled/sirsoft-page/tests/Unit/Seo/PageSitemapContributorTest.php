<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Seo;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Seo\AbstractSitemapContributor;
use App\Seo\Contracts\SitemapContributorInterface;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Seo\PageSitemapContributor;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * PageSitemapContributor 단위 테스트
 *
 * 검증 목적:
 * - getIdentifier: 'sirsoft-page' 반환
 * - getUrls: 발행된 페이지 URL 포함
 * - getUrls: 미발행 페이지 URL 미포함
 * - getUrls: URL 항목 구조 (url/lastmod/changefreq/priority)
 * - getUrls: 기여자당 URL 안전 상한 초과 시 절단
 *
 * @group page
 * @group unit
 * @group seo
 */
class PageSitemapContributorTest extends ModuleTestCase
{
    private PageSitemapContributor $contributor;

    /**
     * 테스트 초기화 - 기여자를 컨테이너로 해석합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Repository 주입이 필요하므로 컨테이너로 해석합니다.
        $this->contributor = $this->app->make(PageSitemapContributor::class);
    }

    /**
     * SitemapContributorInterface 를 구현한다
     */
    public function test_implements_sitemap_contributor_interface(): void
    {
        $this->assertInstanceOf(SitemapContributorInterface::class, $this->contributor);
    }

    /**
     * getIdentifier: 'sirsoft-page' 반환
     */
    public function test_get_identifier_returns_sirsoft_page(): void
    {
        $this->assertSame('sirsoft-page', $this->contributor->getIdentifier());
    }

    /**
     * getUrls: 발행된 페이지 URL이 포함된다
     */
    public function test_get_urls_includes_published_page(): void
    {
        $page = Page::factory()->published()->create();

        $urlPaths = array_column($this->contributor->getUrls(), 'url');

        $this->assertContains("/page/{$page->slug}", $urlPaths);
    }

    /**
     * getUrls: 미발행 페이지는 포함되지 않는다
     */
    public function test_get_urls_excludes_unpublished_page(): void
    {
        $page = Page::factory()->create(['published' => false]);

        $urlPaths = array_column($this->contributor->getUrls(), 'url');

        $this->assertNotContains("/page/{$page->slug}", $urlPaths);
    }

    /**
     * getUrls: URL 항목이 올바른 키 구조를 가진다
     */
    public function test_url_entries_have_correct_structure(): void
    {
        $page = Page::factory()->published()->create();

        $urls = $this->contributor->getUrls();
        $entry = collect($urls)->firstWhere('url', "/page/{$page->slug}");

        $this->assertNotNull($entry);
        $this->assertArrayHasKey('lastmod', $entry);
        $this->assertSame('monthly', $entry['changefreq']);
        $this->assertSame(0.5, $entry['priority']);
    }

    /**
     * getUrls: 발행된 페이지가 없으면 빈 배열을 반환한다
     */
    public function test_get_urls_returns_empty_array_when_no_published_pages(): void
    {
        Page::factory()->count(2)->create(['published' => false]);

        $this->assertSame([], $this->contributor->getUrls());
    }

    /**
     * getUrls: 기여자당 URL 안전 상한을 초과하면 잘린다
     */
    public function test_get_urls_truncates_at_max_urls_per_contributor(): void
    {
        Page::factory()->count(3)->published()->create();

        // g7_core_settings 는 Config 파사드 기반이므로 Config::set 으로 주입합니다.
        Config::set('g7_settings.core.seo.sitemap_max_urls_per_contributor', 2);

        $this->assertCount(2, $this->contributor->getUrls());
    }

    /**
     * getUrlsLazy: 배열을 실체화하지 않는 지연 제너레이터로 URL 을 흘려보낸다 (⑭ 스트리밍)
     */
    public function test_get_urls_lazy_streams_entries(): void
    {
        $page = Page::factory()->published()->create();

        $this->assertInstanceOf(AbstractSitemapContributor::class, $this->contributor);

        $lazy = $this->contributor->getUrlsLazy();
        $this->assertInstanceOf(\Traversable::class, $lazy);

        $urlPaths = array_column(iterator_to_array($lazy, false), 'url');
        $this->assertContains("/page/{$page->slug}", $urlPaths);
    }
}
