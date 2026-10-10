<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Enums\SitemapChangeFreq;
use App\Jobs\GenerateSitemapJob;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use App\Seo\SitemapIndexer;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;

/**
 * 게시글 변경 시 SEO 캐시 무효화 리스너
 *
 * 게시글의 생성, 수정, 삭제 시 관련 SEO 캐시를 자동으로 무효화합니다.
 * 게시글 상세, 게시판 목록, 검색, 홈 페이지 등의 캐시가 대상입니다.
 * 생성/수정 시에는 해당 게시글 상세 페이지의 캐시를 즉시 재생성합니다.
 *
 * 사이트맵 색인은 부모(게시판)와 자식(게시글)의 생명주기를 함께 따른다 (KVE-2026-2133).
 * 게시판이 비활성화·삭제되면 그 게시판의 게시글 URL 도 색인에서 빠지고, 재활성화되면
 * 공개 가능한 게시글만 다시 색인된다 — 전체 재생성 기여자(BoardSitemapContributor)와 같은 규칙.
 */
class SeoBoardCacheListener implements HookListenerInterface
{
    /**
     * 게시판 재활성화 시 게시글 사이트맵을 한 번에 색인하는 묶음 크기
     */
    private const REINDEX_BATCH_SIZE = 1000;

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
            // 관리자 블라인드·복구는 after_update 가 아니라 이 두 훅을 발화한다 — 구독하지 않으면 블라인드한 글이
            // 사이트맵에 남고, 검색엔진·SNS 용으로 캐시해 둔 상세 화면이 원문을 계속 내보낸다(2026-10-07 실측).
            'sirsoft-board.post.after_blind' => [
                'method' => 'onPostUpdate',
                'priority' => 20,
            ],
            'sirsoft-board.post.after_restore' => [
                'method' => 'onPostUpdate',
                'priority' => 20,
            ],
            // 댓글은 게시글 상세의 검색엔진용 화면에 함께 실린다 — 댓글이 바뀌면 그 글의 상세 캐시를 지운다.
            'sirsoft-board.comment.after_create' => [
                'method' => 'onCommentChange',
                'priority' => 20,
            ],
            'sirsoft-board.comment.after_update' => [
                'method' => 'onCommentChange',
                'priority' => 20,
            ],
            'sirsoft-board.comment.after_delete' => [
                'method' => 'onCommentChange',
                'priority' => 20,
            ],
            'sirsoft-board.comment.after_blind' => [
                'method' => 'onCommentChange',
                'priority' => 20,
            ],
            'sirsoft-board.comment.after_restore' => [
                'method' => 'onCommentChange',
                'priority' => 20,
            ],
            'sirsoft-board.board.after_update' => [
                'method' => 'onBoardUpdate',
                'priority' => 20,
            ],
            // 게시판 삭제 트랜잭션 안에서 1,000건 청크마다 발화 — 같은 트랜잭션에서 색인을 걷어내야
            // 삭제가 롤백될 때 색인도 함께 남는다. 저비용 whereIn 삭제라 동기 실행한다.
            'sirsoft-board.board.posts.before_force_delete' => [
                'method' => 'onBoardPostsForceDelete',
                'priority' => 20,
                'sync' => true,
            ],
            'sirsoft-board.board.after_delete' => [
                'method' => 'onBoardDelete',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 게시글 생성 시 SEO 캐시를 무효화하고 상세 페이지를 즉시 재생성합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Post 모델, 두 번째: 게시판 slug)
     * @return void
     */
    public function onPostCreate(...$args): void
    {
        $this->invalidateRelatedCaches($args);

        // 게시글 상세 페이지 캐시 즉시 재생성
        $this->regenerateDetailCache($args);

        $this->syncPostSitemapIndex($args, false);
    }

    /**
     * 게시글 수정 시 SEO 캐시를 무효화하고 상세 페이지를 즉시 재생성합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Post 모델, 두 번째: 게시판 slug)
     * @return void
     */
    public function onPostUpdate(...$args): void
    {
        $this->invalidateRelatedCaches($args);

        // 게시글 상세 페이지 캐시 즉시 재생성
        $this->regenerateDetailCache($args);

        $this->syncPostSitemapIndex($args, false);
    }

    /**
     * 게시글 삭제 시 SEO 캐시를 무효화합니다.
     *
     * 삭제 시에는 재생성 없이 무효화만 수행합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Post 모델, 두 번째: 게시판 slug)
     * @return void
     */
    public function onPostDelete(...$args): void
    {
        $this->invalidateRelatedCaches($args);

        $this->syncPostSitemapIndex($args, true);
    }

    /**
     * 댓글이 바뀌면 그 글의 검색엔진용 상세 화면 캐시를 지웁니다.
     *
     * 상세 화면 캐시에는 댓글 목록이 함께 실린다 — 지우지 않으면 블라인드·삭제한 댓글이나 고친 댓글의
     * 옛 내용이 검색엔진·SNS 에 계속 나간다. 다시 그리는 것은 다음 봇 방문이 맡는다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Comment 모델, 두 번째: 게시판 slug)
     * @return void 반환값 없음
     */
    public function onCommentChange(...$args): void
    {
        $comment = $args[0] ?? null;
        $slug = $args[1] ?? null;
        $postId = is_object($comment) ? ($comment->post_id ?? null) : null;

        if (! $postId || ! $slug) {
            return;
        }

        try {
            app(SeoCacheManagerInterface::class)->invalidateByUrl("/board/{$slug}/{$postId}");
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board post cache invalidation on comment change failed', [
                'error' => $e->getMessage(),
                'post_id' => $postId,
            ]);
        }
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
     * @return void
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

        $this->syncBoardSitemapIndex($board);
        $this->syncBoardPostsSitemapIndex($board, $args[2] ?? null);
    }

    /**
     * 게시판 삭제로 일괄 영구 삭제되는 게시글의 사이트맵 색인을 제거합니다.
     *
     * 게시판 삭제는 게시글별 after_delete 훅을 발화하지 않으므로(글마다 모델 로드 N회 회피),
     * 이 훅이 넘기는 ID 청크로 자식 색인을 정리합니다. 청크마다 여러 번 발화되므로 멱등입니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Board 모델, 두 번째: 삭제될 게시글 ID 배열)
     * @return void
     */
    public function onBoardPostsForceDelete(...$args): void
    {
        $postIds = $args[1] ?? [];

        if (! is_array($postIds) || $postIds === []) {
            return;
        }

        try {
            app(SitemapIndexer::class)->deindexResources('board_post', $postIds);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board posts sitemap deindex on board delete failed', [
                'error' => $e->getMessage(),
                'board_id' => is_object($args[0] ?? null) ? ($args[0]->id ?? null) : null,
            ]);
        }
    }

    /**
     * 게시판 삭제 후 게시판 자신의 사이트맵 색인을 제거하고 사이트맵을 다시 씁니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: 삭제된 Board 모델)
     * @return void
     */
    public function onBoardDelete(...$args): void
    {
        $board = $args[0] ?? null;

        if (! is_object($board) || ! isset($board->id)) {
            return;
        }

        try {
            app(SitemapIndexer::class)->deindexResource('board', $board->id);
            app(CacheInterface::class)->forget('seo.sitemap');

            GenerateSitemapJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board sitemap deindex on board delete failed', [
                'error' => $e->getMessage(),
                'board_id' => $board->id,
            ]);
        }
    }

    /**
     * 게시글의 사이트맵 색인을 증분 갱신합니다.
     *
     * 게시 상태(Published)이고 비밀글이 아니며 'SEO 제공 페이지(게시글 상세)' 토글이 켜져 있으면
     * 색인(upsert), 아니면 색인 해제(remove)한 뒤 사이트맵 재생성 잡을 디바운스 디스패치합니다.
     * 색인 규칙은 BoardSitemapContributor 의 게시글 URL 규칙과 일치해야 합니다.
     *
     * @param  array  $args  훅 인자 배열 (첫 번째: Post 모델, 두 번째: 게시판 slug)
     * @param  bool  $deleted  삭제 이벤트 여부
     * @return void
     */
    private function syncPostSitemapIndex(array $args, bool $deleted): void
    {
        $post = $args[0] ?? null;
        $slug = $args[1] ?? null;

        if (! $post || ! isset($post->id) || ! $slug) {
            return;
        }

        try {
            $indexer = app(SitemapIndexer::class);

            // 게시판이 사라졌거나 비활성이면 게시글도 공개 대상이 아니다 — 게시판 삭제·비활성화 뒤에
            // 실행되는 대기 중 잡이 사라진 게시판의 글을 다시 색인하지 않도록 매번 부모를 확인한다.
            $visible = ! $deleted
                && ($post->status ?? null) === PostStatus::Published
                && ! (bool) ($post->is_secret ?? false)
                && (bool) g7_module_settings('sirsoft-board', 'seo.seo_post_detail', true)
                && $this->isBoardActive((string) $slug);

            if ($visible) {
                $indexer->indexResource('board_post', $post->id, 'sirsoft-board', [[
                    'url' => "/board/{$slug}/{$post->id}",
                    'lastmod' => $post->updated_at?->toW3cString(),
                    'changefreq' => SitemapChangeFreq::Monthly->value,
                    'priority' => 0.5,
                ]]);
            } else {
                $indexer->deindexResource('board_post', $post->id);
            }

            GenerateSitemapJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board post sitemap index sync failed', [
                'error' => $e->getMessage(),
                'post_id' => is_object($post) ? ($post->id ?? null) : null,
            ]);
        }
    }

    /**
     * 게시판의 사이트맵 색인을 증분 갱신합니다.
     *
     * 활성(is_active) 이며 'SEO 제공 페이지(게시판)' 토글이 켜져 있으면 색인(upsert),
     * 아니면 색인 해제(remove)한 뒤 사이트맵 재생성 잡을 디바운스 디스패치합니다.
     * 색인 규칙은 BoardSitemapContributor 의 게시판 URL 규칙과 일치해야 합니다.
     *
     * @param  mixed  $board  Board 모델
     * @return void
     */
    private function syncBoardSitemapIndex(mixed $board): void
    {
        if (! is_object($board) || ! isset($board->id) || ! isset($board->slug)) {
            return;
        }

        try {
            $indexer = app(SitemapIndexer::class);

            $visible = (bool) ($board->is_active ?? false)
                && (bool) g7_module_settings('sirsoft-board', 'seo.seo_board', true);

            if ($visible) {
                $indexer->indexResource('board', $board->id, 'sirsoft-board', [[
                    'url' => "/board/{$board->slug}",
                    'lastmod' => $board->updated_at?->toW3cString(),
                    'changefreq' => SitemapChangeFreq::Daily->value,
                    'priority' => 0.6,
                ]]);
            } else {
                $indexer->deindexResource('board', $board->id);
            }

            GenerateSitemapJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board sitemap index sync failed', [
                'error' => $e->getMessage(),
                'board_id' => is_object($board) ? ($board->id ?? null) : null,
            ]);
        }
    }

    /**
     * 게시판 활성 상태 변화에 맞춰 그 게시판 게시글들의 사이트맵 색인을 맞춥니다.
     *
     * - 비활성: 게시글 색인을 모두 제거 (삭제글 포함 전체 ID 를 청크로 순회)
     * - 비활성 → 활성 전환: 공개 게시글(게시 상태·비밀글 아님)만 다시 색인
     * 활성 상태가 그대로면 게시글 색인은 건드리지 않는다(게시판 정보 수정마다 전량 재색인 방지).
     *
     * @param  mixed  $board  Board 모델 (현재 상태)
     * @param  mixed  $snapshot  수정 전 게시판 스냅샷 배열 (없으면 null)
     * @return void
     */
    private function syncBoardPostsSitemapIndex(mixed $board, mixed $snapshot): void
    {
        if (! is_object($board) || ! isset($board->id) || ! isset($board->slug)) {
            return;
        }

        $isActive = (bool) ($board->is_active ?? false);
        $wasActive = is_array($snapshot) && array_key_exists('is_active', $snapshot)
            ? (bool) $snapshot['is_active']
            : null;

        try {
            $indexer = app(SitemapIndexer::class);
            $postRepository = app(PostRepositoryInterface::class);

            if (! $isActive) {
                $postRepository->eachIdChunkByBoardId((int) $board->id, 1000, function (array $postIds) use ($indexer) {
                    $indexer->deindexResources('board_post', $postIds);
                });

                GenerateSitemapJob::dispatch();

                return;
            }

            // 활성 유지(또는 스냅샷 없음)는 게시글 색인 변화 없음
            if ($wasActive !== false) {
                return;
            }

            if ((bool) g7_module_settings('sirsoft-board', 'seo.seo_post_detail', true)) {
                // 1,000건씩 묶어 색인한다 — 글마다 색인하면 수만 건 게시판에서 큐 작업 시간 제한(60초)을
                // 넘겨 앞쪽 글만 색인된 채 실패한다(공개 글 27,000건 실측: 건별 255초 / 묶음 3초).
                $batch = [];
                foreach ($postRepository->streamPublishedForSitemap((int) $board->id) as $post) {
                    $batch[$post->id] = [[
                        'url' => "/board/{$board->slug}/{$post->id}",
                        'lastmod' => $post->updated_at?->toW3cString(),
                        'changefreq' => SitemapChangeFreq::Monthly->value,
                        'priority' => 0.5,
                    ]];

                    if (count($batch) >= self::REINDEX_BATCH_SIZE) {
                        $indexer->indexResources('board_post', 'sirsoft-board', $batch);
                        $batch = [];
                    }
                }

                $indexer->indexResources('board_post', 'sirsoft-board', $batch);
            }

            GenerateSitemapJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board posts sitemap index sync failed', [
                'error' => $e->getMessage(),
                'board_id' => $board->id,
            ]);
        }
    }

    /**
     * 게시판이 존재하고 활성 상태인지 확인합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return bool 활성 게시판이면 true
     */
    private function isBoardActive(string $slug): bool
    {
        if ($slug === '') {
            return false;
        }

        $board = app(BoardRepositoryInterface::class)->findBySlug($slug);

        return $board !== null && (bool) $board->is_active;
    }

    /**
     * 게시글 변경과 관련된 SEO 캐시를 무효화합니다.
     *
     * 해당 게시판의 캐시만 선별 무효화합니다 (다른 게시판 캐시는 유지).
     *
     * @param  array  $args  훅 인자 배열
     * @return void
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
     * @return void
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
