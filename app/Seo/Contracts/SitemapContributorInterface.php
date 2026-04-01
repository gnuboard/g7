<?php

namespace App\Seo\Contracts;

/**
 * 확장 모듈별 Sitemap URL 기여 인터페이스
 *
 * 각 확장 모듈이 자체적으로 sitemap URL을 제공하기 위해 구현합니다.
 */
interface SitemapContributorInterface
{
    /**
     * 확장 식별자를 반환합니다.
     *
     * @return string 확장 식별자 (예: 'sirsoft-ecommerce')
     */
    public function getIdentifier(): string;

    /**
     * Sitemap URL 항목 배열을 반환합니다.
     *
     * @return array<int, array{loc: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getUrls(): array;
}
