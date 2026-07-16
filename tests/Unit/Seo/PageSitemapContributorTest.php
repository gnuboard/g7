<?php

namespace Tests\Unit\Seo;

use App\Seo\Contracts\SitemapContributorInterface;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Seo\PageSitemapContributor;
use Tests\TestCase;

/**
 * PageSitemapContributor 단위 테스트
 *
 * Page 모듈의 Sitemap 기여자가 올바른 식별자를 반환하고,
 * 발행된 페이지 URL을 올바르게 생성하는지 검증합니다.
 */
class PageSitemapContributorTest extends TestCase
{
    private PageSitemapContributor $contributor;

    /**
     * 테스트 초기화 - PageSitemapContributor 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->contributor = new PageSitemapContributor;
    }

    /**
     * 테스트 종료 - Mockery 리소스를 정리합니다.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * SitemapContributorInterface를 구현하는지 확인합니다.
     */
    public function test_implements_sitemap_contributor_interface(): void
    {
        $this->assertInstanceOf(SitemapContributorInterface::class, $this->contributor);
    }

    /**
     * getIdentifier()가 'sirsoft-page'를 반환하는지 확인합니다.
     */
    public function test_get_identifier_returns_correct_value(): void
    {
        $this->assertSame('sirsoft-page', $this->contributor->getIdentifier());
    }

    /**
     * getUrls()가 발행된 페이지 URL을 반환하는지 확인합니다.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_get_urls_returns_published_page_urls(): void
    {
        $pageCollection = collect([
            (object) ['slug' => 'about', 'updated_at' => now()],
            (object) ['slug' => 'contact', 'updated_at' => now()],
            (object) ['slug' => 'terms', 'updated_at' => now()],
        ]);

        $pageQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $pageQuery->shouldReceive('get')
            ->with(['slug', 'updated_at'])
            ->andReturn($pageCollection);

        $pageMock = Mockery::mock('alias:'.Page::class);
        $pageMock->shouldReceive('where')
            ->with('published', true)
            ->andReturn($pageQuery);

        $contributor = new PageSitemapContributor;
        $urls = $contributor->getUrls();

        $this->assertIsArray($urls);
        $this->assertCount(3, $urls);

        // 각 페이지 URL 확인
        $this->assertSame('/page/about', $urls[0]['url']);
        $this->assertSame('monthly', $urls[0]['changefreq']);
        $this->assertSame(0.5, $urls[0]['priority']);
        $this->assertArrayHasKey('lastmod', $urls[0]);

        $this->assertSame('/page/contact', $urls[1]['url']);
        $this->assertSame('/page/terms', $urls[2]['url']);
    }

    /**
     * 발행된 페이지가 없을 때 빈 배열을 반환하는지 확인합니다.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_get_urls_returns_empty_array_when_no_published_pages(): void
    {
        $pageQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $pageQuery->shouldReceive('get')
            ->with(['slug', 'updated_at'])
            ->andReturn(collect([]));

        $pageMock = Mockery::mock('alias:'.Page::class);
        $pageMock->shouldReceive('where')
            ->with('published', true)
            ->andReturn($pageQuery);

        $contributor = new PageSitemapContributor;
        $urls = $contributor->getUrls();

        $this->assertIsArray($urls);
        $this->assertEmpty($urls);
    }

    /**
     * URL 항목이 올바른 키 구조를 가지는지 확인합니다.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_url_entries_have_correct_structure(): void
    {
        $pageCollection = collect([
            (object) ['slug' => 'test-page', 'updated_at' => now()],
        ]);

        $pageQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $pageQuery->shouldReceive('get')
            ->with(['slug', 'updated_at'])
            ->andReturn($pageCollection);

        $pageMock = Mockery::mock('alias:'.Page::class);
        $pageMock->shouldReceive('where')
            ->with('published', true)
            ->andReturn($pageQuery);

        $contributor = new PageSitemapContributor;
        $urls = $contributor->getUrls();

        $this->assertCount(1, $urls);

        $entry = $urls[0];
        $this->assertArrayHasKey('url', $entry);
        $this->assertArrayHasKey('lastmod', $entry);
        $this->assertArrayHasKey('changefreq', $entry);
        $this->assertArrayHasKey('priority', $entry);

        $this->assertSame('/page/test-page', $entry['url']);
        $this->assertSame('monthly', $entry['changefreq']);
        $this->assertSame(0.5, $entry['priority']);
    }
}
