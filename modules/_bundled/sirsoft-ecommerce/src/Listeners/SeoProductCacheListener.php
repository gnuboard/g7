<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Enums\SitemapChangeFreq;
use App\Jobs\GenerateSitemapJob;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use App\Seo\SitemapIndexer;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * 상품 변경 시 SEO 캐시 무효화 리스너
 *
 * 상품의 생성, 수정, 삭제 시 관련 SEO 캐시를 자동으로 무효화합니다.
 * 상품 상세, 쇼핑몰 메인, 카테고리 목록, 검색, 홈 페이지 등의 캐시가 대상입니다.
 * 생성/수정 시에는 해당 상품 상세 페이지의 캐시를 즉시 재생성합니다.
 */
class SeoProductCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.product.after_create' => [
                'method' => 'onProductCreate',
                'priority' => 20,
            ],
            'sirsoft-ecommerce.product.after_update' => [
                'method' => 'onProductUpdate',
                'priority' => 20,
            ],
            'sirsoft-ecommerce.product.after_delete' => [
                'method' => 'onProductDelete',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 상품 생성 시 SEO 캐시를 무효화하고 상세 페이지를 즉시 재생성합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Product 모델)
     */
    public function onProductCreate(...$args): void
    {
        $this->invalidateRelatedCaches($args);
        $this->regenerateDetailCache($args);
        $this->syncSitemapIndex($args, false);
    }

    /**
     * 상품 수정 시 SEO 캐시를 무효화하고 상세 페이지를 즉시 재생성합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Product 모델)
     */
    public function onProductUpdate(...$args): void
    {
        $this->invalidateRelatedCaches($args);
        $this->regenerateDetailCache($args);
        $this->syncSitemapIndex($args, false);
    }

    /**
     * 상품 삭제 시 SEO 캐시를 무효화합니다.
     *
     * 삭제 시에는 재생성 없이 무효화만 수행합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Product 모델)
     */
    public function onProductDelete(...$args): void
    {
        $this->invalidateRelatedCaches($args);
        $this->syncSitemapIndex($args, true);
    }

    /**
     * 상품 변경과 관련된 모든 SEO 캐시를 무효화합니다.
     *
     * @param  array  $args  훅 인자 배열
     */
    private function invalidateRelatedCaches(array $args): void
    {
        $product = $args[0] ?? null;

        if (! $product) {
            return;
        }

        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 상품 상세 페이지 캐시 무효화
            $cache->invalidateByUrl("*/products/{$product->id}");

            // 쇼핑몰 목록/카테고리 페이지 캐시 무효화
            $cache->invalidateByLayout('shop/index');
            $cache->invalidateByLayout('shop/category');

            // 홈 페이지 캐시 무효화 (신상품/인기상품 등이 표시될 수 있음)
            $cache->invalidateByLayout('home');

            // 검색 결과 페이지 캐시 무효화
            $cache->invalidateByLayout('search/index');

            // Sitemap 캐시 무효화
            app(CacheInterface::class)->forget('seo.sitemap');

            Log::debug('[SEO] Product cache invalidated', [
                'product_id' => $product->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Product cache invalidation failed', [
                'error' => $e->getMessage(),
                'product_id' => $product->id ?? null,
            ]);
        }
    }

    /**
     * 상품의 사이트맵 색인을 증분 갱신합니다.
     *
     * 전시(display_status=VISIBLE)이며 'SEO 제공 페이지(상품 상세)' 토글이 켜져 있으면 색인(upsert),
     * 비공개/토글 OFF/삭제이면 색인 해제(remove)한 뒤, 사이트맵 재생성 잡을 디바운스 디스패치합니다.
     * 색인 규칙은 EcommerceSitemapContributor 의 상품 URL 규칙과 일치해야 합니다.
     *
     * @param  array  $args  훅 인자 배열 (첫 번째: Product 모델)
     * @param  bool  $deleted  삭제 이벤트 여부
     */
    private function syncSitemapIndex(array $args, bool $deleted): void
    {
        $product = $args[0] ?? null;

        if (! $product || ! isset($product->id)) {
            return;
        }

        try {
            $indexer = app(SitemapIndexer::class);

            $visible = ! $deleted
                && ($product->display_status ?? null) === ProductDisplayStatus::VISIBLE
                && (bool) g7_module_settings('sirsoft-ecommerce', 'seo.seo_product_detail', true);

            if ($visible) {
                $indexer->indexResource('product', $product->id, 'sirsoft-ecommerce', [[
                    'url' => ShopPathResolver::path("products/{$product->id}"),
                    'lastmod' => $product->updated_at?->toW3cString(),
                    'changefreq' => SitemapChangeFreq::Weekly->value,
                    'priority' => 0.8,
                ]]);
            } else {
                $indexer->deindexResource('product', $product->id);
            }

            // 파일 재작성은 잡에 위임 (ShouldBeUnique 로 디바운스). auto 모드 → 저장소 기반 증분.
            GenerateSitemapJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Product sitemap index sync failed', [
                'error' => $e->getMessage(),
                'product_id' => $product->id ?? null,
            ]);
        }
    }

    /**
     * 상품 상세 페이지의 SEO 캐시를 즉시 재생성합니다.
     *
     * URL 구성: {상점 기준 경로}/products/{id}
     * 기준 경로는 상점 주소 설정(route_path / no_route)을 ShopPathResolver 가 해석한 값입니다.
     *
     * @param  array  $args  훅 인자 배열
     */
    private function regenerateDetailCache(array $args): void
    {
        $product = $args[0] ?? null;

        if (! $product || ! isset($product->id)) {
            return;
        }

        try {
            $regenerator = app(SeoCacheRegenerator::class);
            $url = ShopPathResolver::path("products/{$product->id}");
            $regenerator->renderAndCache($url);

            Log::debug('[SEO] Product detail cache regenerated', [
                'product_id' => $product->id,
                'url' => $url,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Product detail cache regeneration failed', [
                'error' => $e->getMessage(),
                'product_id' => $product->id ?? null,
            ]);
        }
    }
}
