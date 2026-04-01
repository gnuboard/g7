<?php

namespace Modules\Sirsoft\Page\Seo;

use App\Seo\Contracts\SitemapContributorInterface;
use Modules\Sirsoft\Page\Models\Page;

/**
 * Page 모듈 Sitemap 기여자
 *
 * 발행된 페이지 URL을 sitemap에 제공합니다.
 */
class PageSitemapContributor implements SitemapContributorInterface
{
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
     * Sitemap URL 항목 배열을 반환합니다.
     *
     * 발행된 페이지의 URL을 생성합니다.
     *
     * @return array<int, array{url: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getUrls(): array
    {
        $urls = [];

        $pages = Page::where('published', true)->get(['slug', 'updated_at']);
        foreach ($pages as $page) {
            $urls[] = [
                'url' => "/page/{$page->slug}",
                'lastmod' => $page->updated_at?->toW3cString(),
                'changefreq' => 'monthly',
                'priority' => 0.5,
            ];
        }

        return $urls;
    }
}
