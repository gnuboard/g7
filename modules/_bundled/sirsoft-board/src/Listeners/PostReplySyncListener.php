<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Post;

/**
 * 답글(게시글) 생성/삭제/복원 시 부모 게시글의 replies_count를 재카운팅합니다.
 */
class PostReplySyncListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.after_create' => ['method' => 'syncRepliesCount', 'priority' => 10],
            'sirsoft-board.post.after_delete' => ['method' => 'syncRepliesCount', 'priority' => 10],
            'sirsoft-board.post.after_restore' => ['method' => 'syncRepliesCount', 'priority' => 10],
        ];
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void {}

    /**
     * 부모 게시글의 replies_count를 재카운팅합니다.
     *
     * @param Post $post 게시글 모델
     * @param string $slug 게시판 slug
     * @param array $options 옵션 (after_create/after_delete만 전달)
     * @return void
     */
    public function syncRepliesCount(Post $post, string $slug, array $options = []): void
    {
        if (! $post->parent_id) {
            return;
        }

        $count = DB::table('board_posts')
            ->where('parent_id', $post->parent_id)
            ->whereNull('deleted_at')
            ->count();

        DB::table('board_posts')
            ->where('id', $post->parent_id)
            ->update(['replies_count' => $count]);
    }
}
