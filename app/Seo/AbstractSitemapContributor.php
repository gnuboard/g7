<?php

namespace App\Seo;

use App\Seo\Contracts\SitemapContributorInterface;

/**
 * Sitemap 기여자 지연 스트리밍 브리지 base
 *
 * SitemapContributorInterface 는 손대지 않은 채(제3자 raw 구현체 무파손),
 * 구현체가 getUrls()(배열) 또는 getUrlsLazy()(제너레이터) 중 하나만 구현하면
 * 나머지 한쪽을 자동으로 제공하는 브리지를 놓습니다.
 *
 * - 대용량 기여자는 getUrlsLazy() 를 오버라이드해 URL 을 한 건씩 yield 하여
 *   전체 배열을 메모리에 적재하지 않습니다(1.4M OOM 방지).
 * - getUrls() 만 오버라이드한 소량 기여자는 getUrlsLazy() 가 그 배열을 순회합니다.
 *
 * SitemapGenerator 는 이 base 를 상속했거나 getUrlsLazy() 를 가진 기여자를
 * 지연 경로로 소진합니다(capability 감지). base 를 상속하지 않은 raw 구현체는
 * 기존 getUrls() 경로로 그대로 동작합니다.
 */
abstract class AbstractSitemapContributor implements SitemapContributorInterface
{
    /**
     * 확장 식별자를 반환합니다.
     *
     * @return string 확장 식별자 (예: 'sirsoft-ecommerce')
     */
    abstract public function getIdentifier(): string;

    /**
     * Sitemap URL 항목 배열을 반환합니다.
     *
     * 지연 스트림을 배열로 실체화합니다. 대용량 기여자는 이 메서드 대신
     * SitemapGenerator 가 소비하는 getUrlsLazy() 로 스트리밍되므로,
     * 이 경로는 소규모/하위호환 용도입니다.
     *
     * @return array<int, array{loc?: string, url?: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getUrls(): array
    {
        return iterator_to_array($this->getUrlsLazy(), false);
    }

    /**
     * Sitemap URL 항목을 한 건씩 지연 순회합니다.
     *
     * 기본 구현은 getUrls() 배열을 순회합니다. 대용량 기여자는 이 메서드를
     * 오버라이드해 Repository 스트림을 직접 yield 하십시오.
     *
     * @return iterable<int, array{loc?: string, url?: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getUrlsLazy(): iterable
    {
        yield from $this->getUrls();
    }
}
