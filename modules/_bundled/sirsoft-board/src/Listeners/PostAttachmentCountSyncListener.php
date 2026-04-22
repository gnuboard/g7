<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Attachment;

/**
 * 첨부파일 업로드/삭제 시 게시글의 attachments_count를 재카운팅합니다.
 */
class PostAttachmentCountSyncListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.attachment.after_upload' => ['method' => 'syncAttachmentsCount', 'priority' => 10, 'sync' => true],
            'sirsoft-board.attachment.after_link' => ['method' => 'syncAttachmentsCount', 'priority' => 10, 'sync' => true],
            'sirsoft-board.attachment.after_delete' => ['method' => 'syncAttachmentsCount', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void {}

    /**
     * 게시글의 attachments_count를 재카운팅합니다.
     *
     * @param Attachment $attachment 첨부파일 모델
     * @return void
     */
    public function syncAttachmentsCount(Attachment $attachment): void
    {
        if (! $attachment->post_id) {
            return;
        }

        $count = DB::table('board_attachments')
            ->where('post_id', $attachment->post_id)
            ->whereNull('deleted_at')
            ->count();

        DB::table('board_posts')
            ->where('id', $attachment->post_id)
            ->update(['attachments_count' => $count]);
    }
}
