<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\SitemapChangeFreq;
use App\Jobs\GenerateSitemapJob;
use App\Seo\SeoCacheRegenerator;
use App\Seo\SitemapIndexer;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;

/**
 * 게시판 SEO 의 무거운 후속 작업 리스너 (큐)
 *
 * 캐시 비우기와 게시판 행 하나의 색인 갱신은 {@see SeoBoardCacheListener} 가 저장한 요청 안에서 sync 로 하고,
 * 이 리스너는 시간이 걸리는 두 가지만 큐에서 맡는다(#724).
 *
 * - 게시글 생성·수정·블라인드·복구 후 상세 페이지 SEO 캐시를 미리 다시 그린다.
 * - 게시판 활성 상태가 바뀌면 그 게시판 게시글 수천 건의 사이트맵 색인을 맞춘다.
 *
 * 큐 작업은 커밋 뒤에 돌기 때문에 같은 훅에서 비우기가 먼저 끝난 뒤에 실행된다. 재활성 재색인은 대기 중에
 * 게시판이 다시 비활성·삭제될 수 있으므로 매번 현재 게시판 상태로 판정한다.
 */
class SeoBoardRegenerateListener implements HookListenerInterface
{
    /**
     * 게시판 재활성화 시 게시글 사이트맵을 한 번에 색인하는 묶음 크기
     */
    private const REINDEX_BATCH_SIZE = 1000;

    /**
     * 구독할 훅 목록 반환 (sync 미선언 — 큐로 실행)
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.after_create' => [
                'method' => 'onPostChange',
                'priority' => 20,
            ],
            'sirsoft-board.post.after_update' => [
                'method' => 'onPostChange',
                'priority' => 20,
            ],
            // 관리자 블라인드·복구는 after_update 가 아니라 이 두 훅을 발화한다 — 상세 캐시를 바뀐 상태로 다시 그린다.
            'sirsoft-board.post.after_blind' => [
                'method' => 'onPostChange',
                'priority' => 20,
            ],
            'sirsoft-board.post.after_restore' => [
                'method' => 'onPostChange',
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
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 게시글이 바뀐 뒤 상세 페이지 SEO 캐시를 다시 그립니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Post 모델, 두 번째: 게시판 slug)
     * @return void 반환값 없음
     */
    public function onPostChange(...$args): void
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

    /**
     * 게시판 활성 상태 변화에 맞춰 그 게시판 게시글들의 사이트맵 색인을 맞춥니다.
     *
     * - 비활성: 게시글 색인을 모두 제거 (삭제글 포함 전체 ID 를 청크로 순회)
     * - 비활성 → 활성 전환: 공개 게시글(게시 상태·비밀글 아님)만 다시 색인
     * 활성 상태가 그대로면 게시글 색인은 건드리지 않는다(게시판 정보 수정마다 전량 재색인 방지).
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Board 모델, 두 번째: 변경 데이터, 세 번째: 수정 전 스냅샷)
     * @return void 반환값 없음
     */
    public function onBoardUpdate(...$args): void
    {
        $board = $args[0] ?? null;
        $snapshot = $args[2] ?? null;

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
}
