<?php

namespace Modules\Sirsoft\Page\Seo;

use App\Enums\SitemapChangeFreq;
use App\Seo\AbstractSitemapContributor;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;

/**
 * Page 모듈 Sitemap 기여자
 *
 * 발행된 페이지 URL을 sitemap에 제공합니다.
 * 데이터 접근은 Repository 인터페이스에 위임하며, URL 은 배열로 모으지 않고
 * 한 건씩 지연 yield 하여 페이지가 많아도 메모리를 유계로 유지합니다.
 */
class PageSitemapContributor extends AbstractSitemapContributor
{
    /**
     * PageSitemapContributor 생성자
     *
     * @param  PageRepositoryInterface  $pageRepository  페이지 Repository
     */
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
    ) {}

    /**
     * 확장 식별자를 반환합니다.
     *
     * @return string 확장 식별자
     */
    public function getIdentifier(): string
    {
        return 'sirsoft-page';
    }

    /**
     * Sitemap URL 항목을 한 건씩 지연 순회합니다.
     *
     * 발행된 페이지의 URL을 순서대로 yield 합니다. 기여자당 안전 상한
     * (seo.sitemap_max_urls_per_contributor)을 초과하면 잘라내고 경고를 남깁니다.
     *
     * @return iterable<int, array{url: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getUrlsLazy(): iterable
    {
        $maxUrls = (int) g7_core_settings('seo.sitemap_max_urls_per_contributor', 0);
        $emitted = 0;

        foreach ($this->pageRepository->streamPublishedForSitemap() as $page) {
            if ($maxUrls > 0 && $emitted >= $maxUrls) {
                Log::warning('[SEO] Sitemap 기여자 URL 상한 초과로 잘렸습니다.', [
                    'contributor' => $this->getIdentifier(),
                    'max_urls' => $maxUrls,
                ]);

                return;
            }

            yield [
                'url' => "/page/{$page->slug}",
                'lastmod' => $page->updated_at?->toW3cString(),
                'changefreq' => SitemapChangeFreq::Monthly->value,
                'priority' => 0.5,
                'resource_type' => 'page',
                'resource_id' => (string) $page->id,
            ];
            $emitted++;
        }
    }
}
