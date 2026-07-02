<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시글 삭제 연쇄 처리 테스트 (#413-69-②)
 *
 * 방법1(연쇄 soft delete + cascade 플래그 기반 선별 복원) 검증:
 * - 게시글 삭제 시 살아있던 댓글·첨부가 cascade soft delete + trigger_type='cascade' 마킹
 * - 삭제 전 사용자가 따로 지운 댓글/첨부(trigger_type='user')는 영향 없음
 * - 게시글 복원 시 cascade 항목만 restore, 사용자가 먼저 지운 것은 trashed 유지
 * - 삭제 경로(관리자/사용자/신고) 무관하게 cascade 동작
 *
 * @group board
 * @group admin
 */
class PostDeleteCascadeTest extends BoardTestCase
{
    private PostService $postService;

    protected function getTestBoardSlug(): string
    {
        return 'post-delete-cascade';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '삭제 연쇄 테스트 게시판', 'en' => 'Delete Cascade Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->postService = app(PostService::class);
    }

    /**
     * 첨부파일을 직접 DB 에 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  array  $attributes  덮어쓸 속성
     * @return int 생성된 첨부 ID
     */
    private function createTestAttachment(int $postId, array $attributes = []): int
    {
        static $seq = 0;
        $seq++;

        $defaults = [
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'hash' => 'h'.str_pad((string) $seq, 11, '0', STR_PAD_LEFT),
            'original_filename' => 'test.png',
            'stored_filename' => 'stored-'.$seq.'.png',
            'disk' => 'local',
            'path' => 'attachments/test-'.$seq.'.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'collection' => 'default',
            'order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_attachments')->insertGetId(array_merge($defaults, $attributes));
    }

    /**
     * 게시글 삭제 시 살아있던 댓글·첨부가 cascade 로 soft delete 되고 trigger_type='cascade' 로 마킹된다.
     *
     * @scenario delete_trigger=admin
     *
     * @effects live_comment_cascade_soft_deleted,live_attachment_cascade_soft_deleted,cascade_trigger_type_marked,delete_and_restore_wrapped_in_transaction
     */
    public function test_deleting_post_cascades_to_comments_and_attachments(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);
        $commentId = $this->createTestComment($postId);
        $attachmentId = $this->createTestAttachment($postId);

        $this->postService->deletePost($this->board->slug, $postId, 'admin');

        $comment = DB::table('board_comments')->where('id', $commentId)->first();
        $attachment = DB::table('board_attachments')->where('id', $attachmentId)->first();

        $this->assertNotNull($comment->deleted_at, '댓글이 soft delete 되어야 합니다.');
        $this->assertSame('cascade', $comment->trigger_type, '댓글이 cascade 로 마킹되어야 합니다.');

        $this->assertNotNull($attachment->deleted_at, '첨부가 soft delete 되어야 합니다.');
        $this->assertSame('cascade', $attachment->trigger_type, '첨부가 cascade 로 마킹되어야 합니다.');
    }

    /**
     * 게시글 삭제 전 사용자가 따로 지운 댓글/첨부(trigger_type='user')는 cascade 의 영향을 받지 않는다.
     *
     * @effects user_pre_deleted_comment_untouched,user_pre_deleted_attachment_untouched
     */
    public function test_pre_deleted_items_are_not_overwritten_by_cascade(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);

        // 사용자가 먼저 지운 댓글/첨부 (trigger_type='user', 이미 trashed)
        $userDeletedCommentId = $this->createTestComment($postId, [
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);
        $userDeletedAttachmentId = $this->createTestAttachment($postId, [
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);

        $this->postService->deletePost($this->board->slug, $postId, 'admin');

        $comment = DB::table('board_comments')->where('id', $userDeletedCommentId)->first();
        $attachment = DB::table('board_attachments')->where('id', $userDeletedAttachmentId)->first();

        $this->assertSame('user', $comment->trigger_type, '사용자가 먼저 지운 댓글의 trigger_type 은 user 로 유지되어야 합니다.');
        $this->assertSame('user', $attachment->trigger_type, '사용자가 먼저 지운 첨부의 trigger_type 은 user 로 유지되어야 합니다.');
    }

    /**
     * 게시글 복원 시 cascade 항목만 복원되고, 사용자가 먼저 지운 것은 trashed 로 유지된다 (우려 차단).
     *
     * @effects cascaded_comment_restored_on_post_restore,cascaded_attachment_restored_on_post_restore,user_pre_deleted_comment_stays_trashed,user_pre_deleted_attachment_stays_trashed
     */
    public function test_restoring_post_only_restores_cascaded_items(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);

        $liveCommentId = $this->createTestComment($postId);
        $liveAttachmentId = $this->createTestAttachment($postId);

        $userDeletedCommentId = $this->createTestComment($postId, [
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);
        $userDeletedAttachmentId = $this->createTestAttachment($postId, [
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);

        $this->postService->deletePost($this->board->slug, $postId, 'admin');
        $this->postService->restorePost($this->board->slug, $postId, '복원', 'admin');

        // cascade 로 지워졌던 것은 복원
        $liveComment = DB::table('board_comments')->where('id', $liveCommentId)->first();
        $liveAttachment = DB::table('board_attachments')->where('id', $liveAttachmentId)->first();
        $this->assertNull($liveComment->deleted_at, 'cascade 댓글은 복원되어야 합니다.');
        $this->assertNull($liveAttachment->deleted_at, 'cascade 첨부는 복원되어야 합니다.');

        // 사용자가 먼저 지운 것은 여전히 trashed
        $userComment = DB::table('board_comments')->where('id', $userDeletedCommentId)->first();
        $userAttachment = DB::table('board_attachments')->where('id', $userDeletedAttachmentId)->first();
        $this->assertNotNull($userComment->deleted_at, '사용자가 먼저 지운 댓글은 복원되면 안 됩니다.');
        $this->assertNotNull($userAttachment->deleted_at, '사용자가 먼저 지운 첨부는 복원되면 안 됩니다.');
    }

    /**
     * 사용자 본인 삭제 경로(trigger_type='user')에서도 게시글의 댓글·첨부가 cascade 처리된다.
     *
     * @scenario delete_trigger=user
     *
     * @effects live_comment_cascade_soft_deleted,live_attachment_cascade_soft_deleted,cascade_trigger_type_marked
     */
    public function test_cascade_works_for_user_delete_path(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);
        $commentId = $this->createTestComment($postId);
        $attachmentId = $this->createTestAttachment($postId);

        $this->postService->deletePost($this->board->slug, $postId, 'user');

        $comment = DB::table('board_comments')->where('id', $commentId)->first();
        $attachment = DB::table('board_attachments')->where('id', $attachmentId)->first();

        $this->assertNotNull($comment->deleted_at, '사용자 삭제 경로에서도 댓글이 cascade soft delete 되어야 합니다.');
        $this->assertSame('cascade', $comment->trigger_type);
        $this->assertNotNull($attachment->deleted_at, '사용자 삭제 경로에서도 첨부가 cascade soft delete 되어야 합니다.');
        $this->assertSame('cascade', $attachment->trigger_type);
    }
}
