<?php

namespace Tests\Unit\Seo;

use App\Seo\AbstractSitemapContributor;
use App\Seo\Contracts\SitemapContributorInterface;
use Tests\TestCase;

/**
 * AbstractSitemapContributor 단위 테스트
 *
 * getUrls()(배열)와 getUrlsLazy()(지연 제너레이터) 사이의 브리지를 검증합니다.
 * 구현체가 둘 중 하나만 오버라이드해도 나머지 한쪽이 자동으로 제공되어야 합니다.
 */
class AbstractSitemapContributorTest extends TestCase
{
    /**
     * getUrls() 만 구현한 구현체는 getUrlsLazy() 가 그 배열을 지연 순회한다
     */
    public function test_lazy_bridges_to_array_when_only_get_urls_is_implemented(): void
    {
        $contributor = new class extends AbstractSitemapContributor
        {
            public function getIdentifier(): string
            {
                return 'array-only';
            }

            public function getUrls(): array
            {
                return [
                    ['loc' => 'https://example.test/a'],
                    ['loc' => 'https://example.test/b'],
                ];
            }
        };

        $lazy = $contributor->getUrlsLazy();

        $this->assertInstanceOf(\Traversable::class, $lazy);
        $this->assertSame(
            ['https://example.test/a', 'https://example.test/b'],
            array_column(iterator_to_array($lazy, false), 'loc'),
        );
    }

    /**
     * getUrlsLazy() 만 구현한 구현체는 getUrls() 가 그 스트림을 배열로 실체화한다
     */
    public function test_array_bridges_to_lazy_when_only_get_urls_lazy_is_implemented(): void
    {
        $contributor = new class extends AbstractSitemapContributor
        {
            public function getIdentifier(): string
            {
                return 'lazy-only';
            }

            public function getUrlsLazy(): iterable
            {
                yield ['loc' => 'https://example.test/1'];
                yield ['loc' => 'https://example.test/2'];
                yield ['loc' => 'https://example.test/3'];
            }
        };

        $urls = $contributor->getUrls();

        $this->assertIsArray($urls);
        $this->assertSame(
            ['https://example.test/1', 'https://example.test/2', 'https://example.test/3'],
            array_column($urls, 'loc'),
        );
    }

    /**
     * getUrlsLazy() 는 실제 지연 제너레이터여야 한다 (전량 실체화 금지)
     *
     * 소비를 시작하기 전에는 본문이 실행되지 않아야 대용량에서 메모리가 유계로 유지된다.
     */
    public function test_get_urls_lazy_is_a_generator_that_defers_execution(): void
    {
        $contributor = new class extends AbstractSitemapContributor
        {
            public bool $executed = false;

            public function getIdentifier(): string
            {
                return 'deferred';
            }

            public function getUrlsLazy(): iterable
            {
                $this->executed = true;
                yield ['loc' => 'https://example.test/x'];
            }
        };

        $lazy = $contributor->getUrlsLazy();

        // 아직 순회하지 않았으므로 제너레이터 본문이 실행되지 않았다.
        $this->assertFalse($contributor->executed, '순회 전에는 제너레이터 본문이 실행되지 않아야 합니다.');

        iterator_to_array($lazy, false);

        $this->assertTrue($contributor->executed, '순회하면 제너레이터 본문이 실행되어야 합니다.');
    }

    /**
     * base 는 SitemapContributorInterface 를 구현한다 (기존 등록 경로 호환)
     */
    public function test_is_a_sitemap_contributor(): void
    {
        $contributor = new class extends AbstractSitemapContributor
        {
            public function getIdentifier(): string
            {
                return 'iface';
            }

            public function getUrlsLazy(): iterable
            {
                yield from [];
            }
        };

        $this->assertInstanceOf(SitemapContributorInterface::class, $contributor);
    }
}
