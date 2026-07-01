<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Log;

/**
 * 게시글 변경 시 SEO 캐시 무효화 리스너
 *
 * 게시글의 생성, 수정, 삭제 시 관련 SEO 캐시를 자동으로 무효화합니다.
 * 게시글 상세, 게시판 목록, 검색, 홈 페이지 등의 캐시가 대상입니다.
 * 생성/수정 시에는 해당 게시글 상세 페이지의 캐시를 즉시 재생성합니다.
 */
class SeoBoardCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.after_create' => [
                'method' => 'onPostCreate',
                'priority' => 20,
            ],
            'sirsoft-board.post.after_update' => [
                'method' => 'onPostUpdate',
                'priority' => 20,
            ],
            'sirsoft-board.post.after_delete' => [
                'method' => 'onPostDelete',
                'priority' => 20,
            ],
            'sirsoft-board.board.after_update' => [
                'method' => 'onBoardUpdate',
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
     * 게시글 생성 시 SEO 캐시를 무효화하고 상세 페이지를 즉시 재생성합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Post 모델, 두 번째: 게시판 slug)
     */
    public function onPostCreate(...$args): void
    {
        $this->invalidateRelatedCaches($args);

        // 게시글 상세 페이지 캐시 즉시 재생성
        $this->regenerateDetailCache($args);
    }

    /**
     * 게시글 수정 시 SEO 캐시를 무효화하고 상세 페이지를 즉시 재생성합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Post 모델, 두 번째: 게시판 slug)
     */
    public function onPostUpdate(...$args): void
    {
        $this->invalidateRelatedCaches($args);

        // 게시글 상세 페이지 캐시 즉시 재생성
        $this->regenerateDetailCache($args);
    }

    /**
     * 게시글 삭제 시 SEO 캐시를 무효화합니다.
     *
     * 삭제 시에는 재생성 없이 무효화만 수행합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Post 모델, 두 번째: 게시판 slug)
     */
    public function onPostDelete(...$args): void
    {
        $this->invalidateRelatedCaches($args);
    }

    /**
     * 게시판 정보 수정 시 해당 게시판의 SEO 캐시를 무효화합니다.
     *
     * 게시판 이름/설명 변경은 게시판 목록(/board/{slug})과 인덱스(/boards)의
     * title/description 메타에 노출되므로, 수정 후 이전 메타가 봇에게 계속
     * 반환되지 않도록 관련 캐시를 무효화합니다. 게시글 상세(/board/{slug}/{id})는
     * 게시판명을 메타에 노출하지 않으므로 광역 무효화 대상에서 제외합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Board 모델, 두 번째: 변경 데이터, 세 번째: 스냅샷)
     */
    public function onBoardUpdate(...$args): void
    {
        $board = $args[0] ?? null;
        $slug = is_object($board) ? ($board->slug ?? null) : null;

        if (! $slug) {
            return;
        }

        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 해당 게시판 목록 페이지 (title/description 직접 노출).
            // 봇 캐시 키는 쿼리스트링 변종(`?`, `?page=2`, `?_escaped_fragment_=` 등)을
            // 포함해 저장되므로(SeoMiddleware::buildCacheUrl), 와일드카드로 변종까지 무효화한다.
            // matchesPattern 이 ^...$ 정확 매칭이라 와일드카드 없이는 `/board/{slug}?` 가 누락된다.
            $cache->invalidateByUrl("/board/{$slug}");
            $cache->invalidateByUrl("/board/{$slug}?*");

            // 게시판 인덱스 페이지 (게시판명 목록 노출 가능)
            $cache->invalidateByUrl('/boards');
            $cache->invalidateByUrl('/boards?*');

            // 홈 / 검색 페이지 (게시판명이 함께 노출될 수 있음)
            $cache->invalidateByLayout('home');
            $cache->invalidateByLayout('search/index');

            // Sitemap 캐시 무효화 (게시판 lastmod 갱신 반영)
            app(CacheInterface::class)->forget('seo.sitemap');

            Log::debug('[SEO] Board cache invalidated on board update', [
                'board_slug' => $slug,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board cache invalidation on board update failed', [
                'error' => $e->getMessage(),
                'board_slug' => $slug,
            ]);
        }
    }

    /**
     * 게시글 변경과 관련된 SEO 캐시를 무효화합니다.
     *
     * 해당 게시판의 캐시만 선별 무효화합니다 (다른 게시판 캐시는 유지).
     *
     * @param  array  $args  훅 인자 배열
     */
    private function invalidateRelatedCaches(array $args): void
    {
        $post = $args[0] ?? null;
        $slug = $args[1] ?? null;

        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 해당 게시글 상세 페이지 캐시 무효화 (정확한 URL)
            if ($post && isset($post->id) && $slug) {
                $cache->invalidateByUrl("/board/{$slug}/{$post->id}");
            }

            // 해당 게시판의 목록 페이지만 무효화 (다른 게시판은 유지)
            if ($slug) {
                $cache->invalidateByUrl("/board/{$slug}");
            }

            // 홈 페이지 캐시 무효화 (최근 게시글 등이 표시될 수 있음)
            $cache->invalidateByLayout('home');

            // 검색 결과 페이지 캐시 무효화
            $cache->invalidateByLayout('search/index');

            // Sitemap 캐시 무효화
            app(CacheInterface::class)->forget('seo.sitemap');

            Log::debug('[SEO] Board post cache invalidated', [
                'post_id' => $post->id ?? null,
                'board_slug' => $slug,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board post cache invalidation failed', [
                'error' => $e->getMessage(),
                'post_id' => is_object($post) ? ($post->id ?? null) : null,
            ]);
        }
    }

    /**
     * 게시글 상세 페이지의 SEO 캐시를 즉시 재생성합니다.
     *
     * URL 구성: /board/{slug}/{id}
     *
     * @param  array  $args  훅 인자 배열
     */
    private function regenerateDetailCache(array $args): void
    {
        $post = $args[0] ?? null;
        $slug = $args[1] ?? null;

        if (! $post || ! isset($post->id) || ! $slug) {
            return;
        }

        try {
            $regenerator = app(SeoCacheRegenerator::class);
            $url = "/board/{$slug}/{$post->id}";
            $regenerator->renderAndCache($url);

            Log::debug('[SEO] Board post detail cache regenerated', [
                'post_id' => $post->id,
                'url' => $url,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board post detail cache regeneration failed', [
                'error' => $e->getMessage(),
                'post_id' => $post->id ?? null,
            ]);
        }
    }
}
