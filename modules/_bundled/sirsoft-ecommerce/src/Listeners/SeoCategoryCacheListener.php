<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;

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
                'method' => 'onCategoryChange',
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
    }
}
