<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Enums\SitemapChangeFreq;
use App\Jobs\GenerateSitemapJob;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SitemapIndexer;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * 카테고리 변경 시 SEO 캐시 무효화 리스너
 *
 * 카테고리의 생성, 수정, 삭제 시 관련 SEO 캐시를 자동으로 무효화합니다.
 * 카테고리 목록, 쇼핑몰 메인 등의 캐시가 대상입니다.
 */
class SeoCategoryCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.category.after_create' => [
                'method' => 'onCategoryChange',
                'priority' => 20,
            ],
            'sirsoft-ecommerce.category.after_update' => [
                'method' => 'onCategoryChange',
                'priority' => 20,
            ],
            'sirsoft-ecommerce.category.after_delete' => [
                'method' => 'onCategoryDelete',
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
     * 카테고리 변경 시 SEO 캐시를 무효화합니다.
     *
     * 카테고리 관련 레이아웃 및 쇼핑몰 인덱스 캐시를 무효화합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Category 모델 또는 카테고리 ID)
     */
    public function onCategoryChange(...$args): void
    {
        $category = $args[0] ?? null;

        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 카테고리별 상품 목록 페이지 캐시 무효화
            $cache->invalidateByLayout('shop/category');

            // 쇼핑몰 인덱스 페이지 캐시 무효화
            $cache->invalidateByLayout('shop/index');

            // 홈 페이지 캐시 무효화 (카테고리 네비게이션이 변경될 수 있음)
            $cache->invalidateByLayout('home');

            // 검색 결과 페이지 캐시 무효화
            $cache->invalidateByLayout('search/index');

            // Sitemap 캐시 무효화
            app(CacheInterface::class)->forget('seo.sitemap');

            $categoryId = is_object($category) ? ($category->id ?? null) : $category;

            Log::debug('[SEO] Category change cache invalidated', [
                'category_id' => $categoryId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Category cache invalidation failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->syncSitemapIndex($category);
    }

    /**
     * 카테고리 삭제 시 캐시를 무효화하고 사이트맵 색인을 제거합니다.
     *
     * after_delete 훅은 모델이 아닌 카테고리 ID(int)를 전달합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: 카테고리 ID)
     */
    public function onCategoryDelete(...$args): void
    {
        $this->onCategoryChange(...$args);

        $categoryId = $args[0] ?? null;
        if ($categoryId === null) {
            return;
        }

        try {
            app(SitemapIndexer::class)->deindexResource('category', is_object($categoryId) ? $categoryId->id : $categoryId);
            GenerateSitemapJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Category sitemap index removal failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 카테고리의 사이트맵 색인을 증분 갱신합니다.
     *
     * 활성(is_active) 이며 'SEO 제공 페이지(카테고리)' 토글이 켜져 있으면 색인(upsert),
     * 아니면 색인 해제(remove)한 뒤, 사이트맵 재생성 잡을 디바운스 디스패치합니다.
     * 색인 규칙은 EcommerceSitemapContributor 의 카테고리 URL 규칙과 일치해야 합니다.
     *
     * @param  mixed  $category  Category 모델 (create/update)
     */
    private function syncSitemapIndex(mixed $category): void
    {
        if (! is_object($category) || ! isset($category->id) || ! isset($category->slug)) {
            return;
        }

        try {
            $indexer = app(SitemapIndexer::class);

            $visible = (bool) ($category->is_active ?? false)
                && (bool) g7_module_settings('sirsoft-ecommerce', 'seo.seo_category', true);

            if ($visible) {
                $indexer->indexResource('category', $category->id, 'sirsoft-ecommerce', [[
                    'url' => ShopPathResolver::path("category/{$category->slug}"),
                    'lastmod' => $category->updated_at?->toW3cString(),
                    'changefreq' => SitemapChangeFreq::Weekly->value,
                    'priority' => 0.6,
                ]]);
            } else {
                $indexer->deindexResource('category', $category->id);
            }

            GenerateSitemapJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Category sitemap index sync failed', [
                'error' => $e->getMessage(),
                'category_id' => $category->id ?? null,
            ]);
        }
    }
}
