<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Listeners\CommentReplySyncListener;
use Modules\Sirsoft\Board\Listeners\PostAttachmentCountSyncListener;
use Modules\Sirsoft\Board\Listeners\PostCountSyncListener;
use Modules\Sirsoft\Board\Listeners\PostReplySyncListener;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * STEP 4: 카운팅 Listener 테스트
 *
 * 게시글/댓글/첨부파일 생성·삭제·복원 시 카운팅 컬럼이 올바르게 동기화되는지 검증합니다.
 */
class CountSyncListenerTest extends ModuleTestCase
{
    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        $this->board = Board::firstOrCreate(
            ['slug' => 'count-sync-test'],
            [
                'name' => ['ko' => '카운트 동기화 테스트', 'en' => 'Count Sync Test'],
                'is_active' => true,
            ]
        );
    }

    // ── PostCountSyncListener (comments_count) ──

    /**
     * 훅 구독이 올바르게 등록되어 있는지 확인합니다.
     */
    public function test_post_count_sync_listener_subscribes_to_comment_hooks(): void
    {
        $hooks = PostCountSyncListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-board.comment.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-board.comment.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-board.comment.after_restore', $hooks);
    }

    /**
     * 댓글 생성 시 게시글의 comments_count가 증가하는지 확인합니다.
     */
    public function test_comments_count_increases_on_comment_create(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        $comment = Comment::find($commentId);
        $listener = new PostCountSyncListener();
        $listener->syncCommentsCount($comment, $this->board->slug);

        $post = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertEquals(1, $post->comments_count);
    }

    /**
     * 댓글 삭제 시 게시글의 comments_count가 감소하는지 확인합니다.
     */
    public function test_comments_count_decreases_on_comment_delete(): void
    {
        $postId = $this->createTestPost();
        $commentId1 = $this->createTestComment($postId);
        $commentId2 = $this->createTestComment($postId);

        // 초기 동기화
        $listener = new PostCountSyncListener();
        $listener->syncCommentsCount(Comment::find($commentId1), $this->board->slug);
        $this->assertEquals(2, DB::table('board_posts')->where('id', $postId)->value('comments_count'));

        // 댓글 1개 soft delete
        DB::table('board_comments')->where('id', $commentId2)->update(['deleted_at' => now()]);
        $listener->syncCommentsCount(Comment::withTrashed()->find($commentId2), $this->board->slug);

        $this->assertEquals(1, DB::table('board_posts')->where('id', $postId)->value('comments_count'));
    }

    // ── PostAttachmentCountSyncListener (attachments_count) ──

    /**
     * 훅 구독이 올바르게 등록되어 있는지 확인합니다.
     */
    public function test_attachment_count_sync_listener_subscribes_to_attachment_hooks(): void
    {
        $hooks = PostAttachmentCountSyncListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-board.attachment.after_upload', $hooks);
        $this->assertArrayHasKey('sirsoft-board.attachment.after_delete', $hooks);
    }

    /**
     * 첨부파일 업로드 시 게시글의 attachments_count가 증가하는지 확인합니다.
     */
    public function test_attachments_count_increases_on_upload(): void
    {
        $postId = $this->createTestPost();
        $attachmentId = $this->createTestAttachment($postId);

        $attachment = Attachment::find($attachmentId);
        $listener = new PostAttachmentCountSyncListener();
        $listener->syncAttachmentsCount($attachment);

        $post = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertEquals(1, $post->attachments_count);
    }

    /**
     * post_id가 없는 첨부파일은 무시되는지 확인합니다.
     */
    public function test_attachments_count_skips_when_no_post_id(): void
    {
        $attachment = new Attachment();
        $attachment->post_id = null;

        $listener = new PostAttachmentCountSyncListener();
        $listener->syncAttachmentsCount($attachment);

        // 예외 없이 정상 종료
        $this->assertTrue(true);
    }

    // ── PostReplySyncListener (replies_count) ──

    /**
     * 훅 구독이 올바르게 등록되어 있는지 확인합니다.
     */
    public function test_post_reply_sync_listener_subscribes_to_post_hooks(): void
    {
        $hooks = PostReplySyncListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-board.post.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-board.post.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-board.post.after_restore', $hooks);
    }

    /**
     * 답글 생성 시 부모 게시글의 replies_count가 증가하는지 확인합니다.
     */
    public function test_replies_count_increases_on_reply_create(): void
    {
        $parentId = $this->createTestPost(['title' => '원글']);
        $replyId = $this->createTestPost(['title' => '답글', 'parent_id' => $parentId, 'depth' => 1]);

        $reply = Post::find($replyId);
        $listener = new PostReplySyncListener();
        $listener->syncRepliesCount($reply, $this->board->slug);

        $parent = DB::table('board_posts')->where('id', $parentId)->first();
        $this->assertEquals(1, $parent->replies_count);
    }

    /**
     * parent_id가 없는 게시글(원글)은 무시되는지 확인합니다.
     */
    public function test_replies_count_skips_when_no_parent(): void
    {
        $postId = $this->createTestPost();

        $post = Post::find($postId);
        $listener = new PostReplySyncListener();
        $listener->syncRepliesCount($post, $this->board->slug);

        // 예외 없이 정상 종료
        $this->assertTrue(true);
    }

    // ── CommentReplySyncListener (board_comments.replies_count) ──

    /**
     * 훅 구독이 올바르게 등록되어 있는지 확인합니다.
     */
    public function test_comment_reply_sync_listener_subscribes_to_comment_hooks(): void
    {
        $hooks = CommentReplySyncListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-board.comment.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-board.comment.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-board.comment.after_restore', $hooks);
    }

    /**
     * 대댓글 생성 시 부모 댓글의 replies_count가 증가하는지 확인합니다.
     */
    public function test_comment_replies_count_increases_on_reply_create(): void
    {
        $postId = $this->createTestPost();
        $parentCommentId = $this->createTestComment($postId, ['content' => '부모 댓글']);
        $replyCommentId = $this->createTestComment($postId, [
            'content' => '대댓글',
            'parent_id' => $parentCommentId,
        ]);

        $replyComment = Comment::find($replyCommentId);
        $listener = new CommentReplySyncListener();
        $listener->syncRepliesCount($replyComment, $this->board->slug);

        $parentComment = DB::table('board_comments')->where('id', $parentCommentId)->first();
        $this->assertEquals(1, $parentComment->replies_count);
    }

    /**
     * parent_id가 없는 댓글(최상위)은 무시되는지 확인합니다.
     */
    public function test_comment_replies_count_skips_when_no_parent(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);

        $comment = Comment::find($commentId);
        $listener = new CommentReplySyncListener();
        $listener->syncRepliesCount($comment, $this->board->slug);

        // 예외 없이 정상 종료
        $this->assertTrue(true);
    }

    // ── 헬퍼 메서드 ──

    /**
     * 테스트 게시글을 직접 DB에 생성합니다.
     *
     * @param  array  $attributes  게시글 속성
     * @return int 생성된 게시글 ID
     */
    private function createTestPost(array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'title' => '테스트 게시글',
            'content' => '테스트 내용입니다.',
            'user_id' => null,
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'replies_count' => 0,
            'comments_count' => 0,
            'attachments_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_posts')->insertGetId(array_merge($defaults, $attributes));
    }

    /**
     * 테스트 댓글을 직접 DB에 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  array  $attributes  댓글 속성
     * @return int 생성된 댓글 ID
     */
    private function createTestComment(int $postId, array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'user_id' => null,
            'author_name' => '테스트',
            'content' => '테스트 댓글입니다.',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'replies_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_comments')->insertGetId(array_merge($defaults, $attributes));
    }

    /**
     * 테스트 첨부파일을 직접 DB에 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  array  $attributes  첨부파일 속성
     * @return int 생성된 첨부파일 ID
     */
    private function createTestAttachment(int $postId, array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'hash' => substr(md5(uniqid()), 0, 12),
            'original_filename' => 'test.txt',
            'stored_filename' => 'test_' . uniqid() . '.txt',
            'disk' => 'local',
            'path' => 'attachments',
            'mime_type' => 'text/plain',
            'size' => 100,
            'collection' => 'default',
            'order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_attachments')->insertGetId(array_merge($defaults, $attributes));
    }
}
