<?php

namespace Modules\Sirsoft\Page\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;

/**
 * 페이지 변경 시 SEO 캐시 무효화 리스너
 *
 * 페이지의 생성, 수정, 삭제 시 관련 SEO 캐시를 자동으로 무효화합니다.
 * 페이지 상세, 홈 페이지 등의 캐시가 대상입니다.
 */
class SeoPageCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-page.page.after_create' => [
                'method' => 'onPageChange',
                'priority' => 20,
            ],
            'sirsoft-page.page.after_update' => [
                'method' => 'onPageChange',
                'priority' => 20,
            ],
            'sirsoft-page.page.after_delete' => [
                'method' => 'onPageChange',
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
     * 페이지 변경 시 SEO 캐시를 무효화합니다.
     *
     * 페이지 상세 URL과 홈 페이지 캐시를 무효화합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Page 모델, 두 번째: 데이터 배열)
     */
    public function onPageChange(...$args): void
    {
        $page = $args[0] ?? null;

        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 페이지 상세 URL 캐시 무효화
            if ($page && isset($page->slug)) {
                $cache->invalidateByUrl("*/pages/{$page->slug}");
            }

            // 페이지 상세 레이아웃 캐시 무효화
            $cache->invalidateByLayout('page/show');

            // 홈 페이지 캐시 무효화 (페이지 링크가 네비게이션에 포함될 수 있음)
            $cache->invalidateByLayout('home');

            // 검색 결과 페이지 캐시 무효화
            $cache->invalidateByLayout('search/index');

            // Sitemap 캐시 무효화
            app(CacheInterface::class)->forget('seo.sitemap');

            Log::debug('[SEO] Page change cache invalidated', [
                'page_id' => $page->id ?? null,
                'page_slug' => $page->slug ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Page cache invalidation failed', [
                'error' => $e->getMessage(),
                'page_id' => is_object($page) ? ($page->id ?? null) : null,
            ]);
        }
    }
}
