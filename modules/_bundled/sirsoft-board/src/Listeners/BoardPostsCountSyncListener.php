<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;

/**
 * 게시글 생성/삭제/복원 시 게시판의 posts_count를 재카운팅합니다.
 */
class BoardPostsCountSyncListener implements HookListenerInterface
{
    /**
     * @param  BoardRepositoryInterface  $boardRepository  게시판 Repository
     */
    public function __construct(
        protected BoardRepositoryInterface $boardRepository,
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.after_create' => ['method' => 'syncPostsCount', 'priority' => 10, 'sync' => true],
            'sirsoft-board.post.after_delete' => ['method' => 'syncPostsCount', 'priority' => 10, 'sync' => true],
            'sirsoft-board.post.after_restore' => ['method' => 'syncPostsCount', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void {}

    /**
     * 게시판의 posts_count를 재카운팅합니다.
     *
     * @param  Post  $post  게시글 모델
     * @param  string  $slug  게시판 slug
     * @param  array  $options  옵션 (after_create/after_delete만 전달)
     * @return void
     */
    public function syncPostsCount(Post $post, string $slug, array $options = []): void
    {
        $this->boardRepository->recalculatePostsCount((int) $post->board_id);
    }
}
