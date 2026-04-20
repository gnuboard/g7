<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Comment;

/**
 * 대댓글 생성/삭제/복원 시 부모 댓글의 replies_count를 재카운팅합니다.
 */
class CommentReplySyncListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.comment.after_create' => ['method' => 'syncRepliesCount', 'priority' => 10],
            'sirsoft-board.comment.after_delete' => ['method' => 'syncRepliesCount', 'priority' => 10],
            'sirsoft-board.comment.after_restore' => ['method' => 'syncRepliesCount', 'priority' => 10],
        ];
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void {}

    /**
     * 부모 댓글의 replies_count를 재카운팅합니다.
     *
     * @param Comment $comment 댓글 모델
     * @param string $slug 게시판 slug
     * @return void
     */
    public function syncRepliesCount(Comment $comment, string $slug): void
    {
        if (! $comment->parent_id) {
            return;
        }

        $count = DB::table('board_comments')
            ->where('parent_id', $comment->parent_id)
            ->whereNull('deleted_at')
            ->count();

        DB::table('board_comments')
            ->where('id', $comment->parent_id)
            ->update(['replies_count' => $count]);
    }
}
