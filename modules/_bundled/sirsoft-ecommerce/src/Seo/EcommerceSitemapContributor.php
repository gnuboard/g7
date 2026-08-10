<?php

namespace Modules\Sirsoft\Ecommerce\Seo;

use App\Enums\SitemapChangeFreq;
use App\Seo\AbstractSitemapContributor;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * Ecommerce 모듈 Sitemap 기여자
 *
 * 상품 및 카테고리 URL을 sitemap에 제공합니다.
 * 데이터 접근은 Repository 인터페이스에 위임하며, URL 은 배열로 모으지 않고
 * 한 건씩 지연 yield 하여 대용량 상품(수만~수십만 건)에서도 메모리를 유계로 유지합니다.
 */
class EcommerceSitemapContributor extends AbstractSitemapContributor
{
    /**
     * EcommerceSitemapContributor 생성자
     *
     * @param  CategoryRepositoryInterface  $categoryRepository  카테고리 Repository
     * @param  ProductRepositoryInterface  $productRepository  상품 Repository
     */
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ProductRepositoryInterface $productRepository,
    ) {}

    /**
     * 확장 식별자를 반환합니다.
     *
     * @return string 확장 식별자
     */
    public function getIdentifier(): string
    {
        return 'sirsoft-ecommerce';
    }

    /**
     * Sitemap URL 항목을 한 건씩 지연 순회합니다.
     *
     * 상품 목록, 카테고리별 페이지, 개별 상품 페이지의 URL을 순서대로 yield 합니다.
     * 기여자당 안전 상한(seo.sitemap_max_urls_per_contributor)을 초과하면 잘라내고
     * 경고를 남깁니다. 상한은 이 기여자가 내보내는 모든 URL 유형에 함께 적용됩니다.
     *
     * @return iterable<int, array{url: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getUrlsLazy(): iterable
    {
        $maxUrls = (int) g7_core_settings('seo.sitemap_max_urls_per_contributor', 0);
        $emitted = 0;

        foreach ($this->collectUrls() as $entry) {
            if ($maxUrls > 0 && $emitted >= $maxUrls) {
                Log::warning('[SEO] Sitemap 기여자 URL 상한 초과로 잘렸습니다.', [
                    'contributor' => $this->getIdentifier(),
                    'max_urls' => $maxUrls,
                ]);

                return;
            }

            yield $entry;
            $emitted++;
        }
    }

    /**
     * 상한 적용 이전의 URL 후보를 순서대로 지연 생성합니다.
     *
     * @return iterable<int, array{url: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    private function collectUrls(): iterable
    {
        // 상점 주소 설정(route_path / no_route)을 반영한다 — 주소를 바꾼 상점의 사이트맵이
        // 기본값 주소를 싣고 나가면 검색엔진이 존재하지 않는 URL 을 수집한다 (공개 #85).
        // 상품 목록 페이지 — 'SEO 제공 페이지' 토글 OFF 시 제외
        if ((bool) g7_module_settings('sirsoft-ecommerce', 'seo.seo_shop_index', true)) {
            yield [
                'url' => ShopPathResolver::path('products'),
                'changefreq' => SitemapChangeFreq::Daily->value,
                'priority' => 0.7,
                'resource_type' => 'shop_index',
                'resource_id' => null,
            ];
        }

        // 카테고리별 페이지 — 토글 OFF 시 제외
        if ((bool) g7_module_settings('sirsoft-ecommerce', 'seo.seo_category', true)) {
            foreach ($this->categoryRepository->streamActiveForSitemap() as $category) {
                yield [
                    'url' => ShopPathResolver::path("category/{$category->slug}"),
                    'lastmod' => $category->updated_at?->toW3cString(),
                    'changefreq' => SitemapChangeFreq::Weekly->value,
                    'priority' => 0.6,
                    'resource_type' => 'category',
                    'resource_id' => (string) $category->id,
                ];
            }
        }

        // 개별 상품 페이지 (전시 상태가 '전시'인 상품만) — 토글 OFF 시 제외
        if ((bool) g7_module_settings('sirsoft-ecommerce', 'seo.seo_product_detail', true)) {
            foreach ($this->productRepository->streamVisibleForSitemap() as $product) {
                yield [
                    'url' => ShopPathResolver::path("products/{$product->id}"),
                    'lastmod' => $product->updated_at?->toW3cString(),
                    'changefreq' => SitemapChangeFreq::Weekly->value,
                    'priority' => 0.8,
                    'resource_type' => 'product',
                    'resource_id' => (string) $product->id,
                ];
            }
        }
    }
}
