<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;

/**
 * 댓글 생성/삭제/복원 시 게시판의 comments_count를 재카운팅합니다.
 */
class BoardCommentsCountSyncListener implements HookListenerInterface
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
            'sirsoft-board.comment.after_create' => ['method' => 'syncCommentsCount', 'priority' => 10, 'sync' => true],
            'sirsoft-board.comment.after_delete' => ['method' => 'syncCommentsCount', 'priority' => 10, 'sync' => true],
            'sirsoft-board.comment.after_restore' => ['method' => 'syncCommentsCount', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void {}

    /**
     * 게시판의 comments_count를 재카운팅합니다.
     *
     * @param  Comment  $comment  댓글 모델
     * @param  string  $slug  게시판 slug
     * @return void
     */
    public function syncCommentsCount(Comment $comment, string $slug): void
    {
        $this->boardRepository->recalculateCommentsCount((int) $comment->board_id);
    }
}
