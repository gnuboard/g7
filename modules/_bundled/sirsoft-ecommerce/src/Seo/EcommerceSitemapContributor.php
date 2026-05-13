<?php

namespace Modules\Sirsoft\Ecommerce\Seo;

use App\Seo\Contracts\SitemapContributorInterface;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * Ecommerce 모듈 Sitemap 기여자
 *
 * 상품 및 카테고리 URL을 sitemap에 제공합니다.
 */
class EcommerceSitemapContributor implements SitemapContributorInterface
{
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
     * Sitemap URL 항목 배열을 반환합니다.
     *
     * 상품 목록, 카테고리별 페이지, 개별 상품 페이지의 URL을 생성합니다.
     *
     * @return array<int, array{url: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getUrls(): array
    {
        $urls = [];
        $routePath = g7_module_settings('sirsoft-ecommerce', 'basic_info.route_path') ?? 'shop';

        // 상품 목록 페이지
        $urls[] = [
            'url' => "/{$routePath}/products",
            'changefreq' => 'daily',
            'priority' => 0.7,
        ];

        // 카테고리별 페이지
        $categories = Category::where('is_active', true)->get(['id', 'slug', 'updated_at']);
        foreach ($categories as $category) {
            $urls[] = [
                'url' => "/{$routePath}/category/{$category->slug}",
                'lastmod' => $category->updated_at?->toW3cString(),
                'changefreq' => 'weekly',
                'priority' => 0.6,
            ];
        }

        // 개별 상품 페이지 (전시 상태가 '전시'인 상품만)
        $products = Product::where('display_status', ProductDisplayStatus::VISIBLE)
            ->get(['id', 'updated_at']);
        foreach ($products as $product) {
            $urls[] = [
                'url' => "/{$routePath}/products/{$product->id}",
                'lastmod' => $product->updated_at?->toW3cString(),
                'changefreq' => 'weekly',
                'priority' => 0.8,
            ];
        }

        return $urls;
    }
}
