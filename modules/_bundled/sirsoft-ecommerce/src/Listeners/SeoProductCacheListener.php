<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
            Cache::forget('seo:sitemap');

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
     * 상품 상세 페이지의 SEO 캐시를 즉시 재생성합니다.
     *
     * URL 구성: /{route_path}/products/{id}
     * route_path는 이커머스 모듈 설정에서 조회합니다.
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
            $routePath = g7_module_settings('sirsoft-ecommerce', 'basic_info.route_path', 'shop');
            $url = "/{$routePath}/products/{$product->id}";
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
