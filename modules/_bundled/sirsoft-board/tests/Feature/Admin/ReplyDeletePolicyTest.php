<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Exceptions\PostHasRepliesException;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 답글 삭제 정책(reply_delete_policy) + 답글 트리 연쇄 삭제/복원 테스트
 *
 * 검증 목적:
 * - cascade 정책(기본): 부모 삭제 시 자손 답글 전체 + 그 댓글/첨부가 cascade soft delete
 * - 복원 시 cascade 로 지워진 자손 + 그 댓글/첨부만 top-down 선택 복원
 * - 사용자 직접 삭제(trigger 'user') 서브트리는 부모 복원 후에도 trashed 유지
 * - block 정책: 살아있는 직계 답글 존재 시 관리자/사용자 삭제 경로 모두 422 차단
 * - block 정책이라도 답글이 전부 trashed 면 삭제 허용
 * - 훅 경유 삭제(options cascade_replies=true)는 block 정책 우회 + cascade 고정
 * - 이미 trashed 인 자손은 cascade 가 변조하지 않음 (trigger_type/deleted_at 불변)
 * - 신고 조치 삭제(trigger 'report')에도 block 정책 동일 적용 (cascade_replies 옵션만 우회)
 * - 끊긴 체인 밑 고아 답글은 block 통과 삭제에서도 cascade 스윕으로 함께 정리
 *
 * @group board
 * @group admin
 */
class ReplyDeletePolicyTest extends BoardTestCase
{
    private PostService $postService;

    private User $adminWithManage;

    protected function getTestBoardSlug(): string
    {
        return 'reply-delete-policy';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '답글 삭제 정책 테스트 게시판', 'en' => 'Reply Delete Policy Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
            // 이전 테스트가 block 으로 바꾼 값이 잔류하지 않도록 매 테스트 기본값으로 리셋
            'reply_delete_policy' => 'cascade',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->postService = app(PostService::class);

        $slug = $this->board->slug;

        $this->adminWithManage = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.manage",
        ]);

        // 사용자 삭제 경로(회원 본인 글) 검증용
        $this->grantUserRolePermissions(['posts.read', 'posts.write']);
    }

    // ==========================================
    // Helper
    // ==========================================

    /**
     * 관리자 게시글 API URL 을 반환합니다.
     *
     * @param  string  $suffix  URL 접미사
     * @return string 관리자 게시글 API URL
     */
    private function adminUrl(string $suffix): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts{$suffix}";
    }

    /**
     * 사용자 게시글 API URL 을 반환합니다.
     *
     * @param  string  $suffix  URL 접미사
     * @return string 사용자 게시글 API URL
     */
    private function userUrl(string $suffix): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts{$suffix}";
    }

    /**
     * 답글(자식 게시글)을 직접 DB 에 생성합니다.
     *
     * @param  int  $parentId  부모 게시글 ID
     * @param  int  $depth  답글 깊이
     * @param  array  $attributes  덮어쓸 속성
     * @return int 생성된 답글 ID
     */
    private function createReply(int $parentId, int $depth = 1, array $attributes = []): int
    {
        return $this->createTestPost(array_merge([
            'title' => "답글 (depth {$depth})",
            'parent_id' => $parentId,
            'depth' => $depth,
        ], $attributes));
    }

    /**
     * 첨부파일을 직접 DB 에 생성합니다 (PostDeleteCascadeTest 와 동일 패턴).
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
            'hash' => 'r'.str_pad((string) $seq, 11, '0', STR_PAD_LEFT),
            'original_filename' => 'test.png',
            'stored_filename' => 'stored-'.$seq.'.png',
            'disk' => 'local',
            'path' => 'attachments/reply-'.$seq.'.png',
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
     * 게시글 행을 조회합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return object|null 게시글 행
     */
    private function postRow(int $postId): ?object
    {
        return DB::table('board_posts')->where('id', $postId)->first();
    }

    // ==========================================
    // cascade 정책 (기본)
    // ==========================================

    /**
     * cascade 정책: 부모 삭제 시 깊이 3 답글 트리 전체 + 자손별 댓글/첨부가
     * cascade soft delete + trigger_type='cascade' + status='deleted' 로 마킹된다.
     */
    public function test_cascade_policy_soft_deletes_all_descendant_replies(): void
    {
        $parentId = $this->createTestPost(['status' => 'published']);
        $reply1 = $this->createReply($parentId, 1);
        $reply2 = $this->createReply($reply1, 2);
        $reply3 = $this->createReply($reply2, 3);

        $commentIds = [];
        $attachmentIds = [];
        foreach ([$reply1, $reply2, $reply3] as $replyId) {
            $commentIds[$replyId] = $this->createTestComment($replyId);
            $attachmentIds[$replyId] = $this->createTestAttachment($replyId);
        }

        $this->postService->deletePost($this->board->slug, $parentId, 'admin');

        foreach ([$reply1, $reply2, $reply3] as $replyId) {
            $row = $this->postRow($replyId);
            $this->assertNotNull($row->deleted_at, "자손 답글 {$replyId} 이 soft delete 되어야 합니다.");
            $this->assertSame('cascade', $row->trigger_type, "자손 답글 {$replyId} 이 cascade 로 마킹되어야 합니다.");
            $this->assertSame('deleted', $row->status, "자손 답글 {$replyId} 의 status 가 deleted 여야 합니다.");

            $comment = DB::table('board_comments')->where('id', $commentIds[$replyId])->first();
            $this->assertNotNull($comment->deleted_at, "자손 답글 {$replyId} 의 댓글이 soft delete 되어야 합니다.");
            $this->assertSame('cascade', $comment->trigger_type);

            $attachment = DB::table('board_attachments')->where('id', $attachmentIds[$replyId])->first();
            $this->assertNotNull($attachment->deleted_at, "자손 답글 {$replyId} 의 첨부가 soft delete 되어야 합니다.");
            $this->assertSame('cascade', $attachment->trigger_type);
        }
    }

    /**
     * 복원 시 cascade 로 지워졌던 자손 답글 + 그 댓글/첨부가 함께 복원된다.
     */
    public function test_cascade_restore_restores_descendants_and_their_children(): void
    {
        $parentId = $this->createTestPost(['status' => 'published']);
        $reply1 = $this->createReply($parentId, 1);
        $reply2 = $this->createReply($reply1, 2);

        $commentId = $this->createTestComment($reply1);
        $attachmentId = $this->createTestAttachment($reply2);

        $this->postService->deletePost($this->board->slug, $parentId, 'admin');
        $this->postService->restorePost($this->board->slug, $parentId, '복원', 'admin');

        foreach ([$reply1, $reply2] as $replyId) {
            $row = $this->postRow($replyId);
            $this->assertNull($row->deleted_at, "cascade 자손 답글 {$replyId} 은 복원되어야 합니다.");
            $this->assertSame('published', $row->status, "복원된 자손 답글 {$replyId} 의 status 가 published 여야 합니다.");
        }

        $comment = DB::table('board_comments')->where('id', $commentId)->first();
        $this->assertNull($comment->deleted_at, 'cascade 자손 답글의 댓글도 복원되어야 합니다.');

        $attachment = DB::table('board_attachments')->where('id', $attachmentId)->first();
        $this->assertNull($attachment->deleted_at, 'cascade 자손 답글의 첨부도 복원되어야 합니다.');
    }

    /**
     * 사용자 직접 삭제(trigger 'user') 로 먼저 지워진 중간 자손의 서브트리는
     * 부모 삭제 → 복원 후에도 trashed 로 유지된다 (고아 재생성 금지).
     */
    public function test_user_predeleted_reply_subtree_stays_trashed_after_restore(): void
    {
        $parentId = $this->createTestPost(['status' => 'published']);
        $reply1 = $this->createReply($parentId, 1);
        $reply2 = $this->createReply($reply1, 2);

        // 중간 자손(reply1)을 사용자 삭제 경로로 먼저 삭제 → reply1 trigger 'user',
        // 그 자식 reply2 는 reply1 삭제의 cascade 로 trashed
        $this->postService->deletePost($this->board->slug, $reply1, 'user');

        $this->assertSame('user', $this->postRow($reply1)->trigger_type, '테스트 전제: reply1 은 user 삭제여야 합니다.');
        $this->assertNotNull($this->postRow($reply2)->deleted_at, '테스트 전제: reply2 는 reply1 삭제로 trashed 여야 합니다.');

        // 부모 삭제 → 복원
        $this->postService->deletePost($this->board->slug, $parentId, 'admin');
        $this->postService->restorePost($this->board->slug, $parentId, '복원', 'admin');

        $this->assertNull($this->postRow($parentId)->deleted_at, '부모 글은 복원되어야 합니다.');

        $reply1Row = $this->postRow($reply1);
        $this->assertNotNull($reply1Row->deleted_at, '사용자가 직접 지운 답글은 복원되면 안 됩니다.');
        $this->assertSame('user', $reply1Row->trigger_type, '사용자 삭제 trigger_type 은 유지되어야 합니다.');

        $this->assertNotNull(
            $this->postRow($reply2)->deleted_at,
            '사용자 삭제 답글의 서브트리도 trashed 로 유지되어야 합니다 (고아 재생성 금지).'
        );
    }

    /**
     * cascade 는 이미 trashed 인 자손(trigger 'user')을 변조하지 않는다
     * (trigger_type 유지 + deleted_at 불변).
     */
    public function test_cascade_skips_already_trashed_descendants(): void
    {
        $parentId = $this->createTestPost(['status' => 'published']);
        $this->createReply($parentId, 1); // 살아있는 답글 — cascade 가 실제로 수행되는 전제

        $preDeletedAt = now()->subHour();
        $preDeletedReplyId = $this->createReply($parentId, 1, [
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => $preDeletedAt,
        ]);

        $before = $this->postRow($preDeletedReplyId);

        $this->postService->deletePost($this->board->slug, $parentId, 'admin');

        $after = $this->postRow($preDeletedReplyId);
        $this->assertSame('user', $after->trigger_type, '이미 trashed 인 자손의 trigger_type 은 유지되어야 합니다.');
        $this->assertSame($before->deleted_at, $after->deleted_at, '이미 trashed 인 자손의 deleted_at 은 불변이어야 합니다.');
    }

    // ==========================================
    // block 정책
    // ==========================================

    /**
     * block 정책: 살아있는 답글이 있는 글의 관리자 삭제 → 422 + 정책 메시지, 부수효과 0.
     */
    public function test_block_policy_returns_422_with_message_when_alive_reply_exists(): void
    {
        $this->updateBoardSettings(['reply_delete_policy' => 'block']);

        $parentId = $this->createTestPost(['status' => 'published']);
        $replyId = $this->createReply($parentId, 1);

        $response = $this->actingAs($this->adminWithManage)
            ->deleteJson($this->adminUrl("/{$parentId}"));

        $response->assertStatus(422);
        $this->assertSame(
            __('sirsoft-board::validation.post.delete.has_replies'),
            $response->json('message'),
            '답글 삭제 정책 차단 메시지가 응답되어야 합니다.'
        );

        // 차단 시 부수효과 0: 부모/답글 모두 미삭제
        $this->assertNull($this->postRow($parentId)->deleted_at, '차단된 부모 글은 삭제되면 안 됩니다.');
        $this->assertNull($this->postRow($replyId)->deleted_at, '차단 시 답글도 영향받지 않아야 합니다.');
    }

    /**
     * block 정책: 답글이 전부 trashed 면 삭제가 허용된다.
     */
    public function test_block_policy_allows_delete_when_replies_all_trashed(): void
    {
        $this->updateBoardSettings(['reply_delete_policy' => 'block']);

        $parentId = $this->createTestPost(['status' => 'published']);
        $this->createReply($parentId, 1, [
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);

        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->adminUrl("/{$parentId}"))
            ->assertStatus(200);

        $this->assertNotNull($this->postRow($parentId)->deleted_at, '답글이 전부 trashed 면 부모 글 삭제가 허용되어야 합니다.');
    }

    /**
     * block 정책은 사용자 삭제 경로(회원 본인 글)에도 동일하게 적용된다 → 422.
     */
    public function test_user_delete_path_applies_policy(): void
    {
        $this->updateBoardSettings(['reply_delete_policy' => 'block']);

        $owner = $this->createUser();
        $parentId = $this->createTestPost([
            'status' => 'published',
            'user_id' => $owner->id,
            'author_name' => $owner->name,
        ]);
        $this->createReply($parentId, 1);

        $response = $this->actingAs($owner)
            ->deleteJson($this->userUrl("/{$parentId}"));

        $response->assertStatus(422);
        $this->assertSame(
            __('sirsoft-board::validation.post.delete.has_replies'),
            $response->json('message')
        );
        $this->assertNull($this->postRow($parentId)->deleted_at, '차단된 글은 삭제되면 안 됩니다.');
    }

    /**
     * 신고 조치 삭제(trigger 'report')에도 block 정책이 동일하게 적용된다.
     * 정책 우회는 오직 cascade_replies 옵션(훅 경유)뿐 — trigger 문자열은 우회 수단이 아니다.
     */
    public function test_report_trigger_delete_applies_block_policy(): void
    {
        $this->updateBoardSettings(['reply_delete_policy' => 'block']);

        $parentId = $this->createTestPost(['status' => 'published']);
        $replyId = $this->createReply($parentId, 1);

        try {
            $this->postService->deletePost($this->board->slug, $parentId, 'report');
            $this->fail('block 정책에서는 신고 조치 삭제도 PostHasRepliesException 으로 차단되어야 합니다.');
        } catch (PostHasRepliesException $e) {
            // 기대 경로: ReportService::deleteContent 의 기존 generic catch 가 이 예외를
            // 조치 실패 사유로 로그 변환한다 (계획서 WP2 §차단 예외 — 정책 적용 유지).
        }

        $this->assertNull($this->postRow($parentId)->deleted_at, '차단된 부모 글은 삭제되면 안 됩니다.');
        $this->assertNull($this->postRow($replyId)->deleted_at, '차단 시 답글도 영향받지 않아야 합니다.');
    }

    /**
     * 끊긴 체인 밑 고아 답글: 직계 답글이 전부 trashed 라 block 정책이 삭제를 허용하는
     * 경우에도 cascade 스윕이 실행되어, trashed 자손 밑에 살아남아 있던 고아 답글을
     * 함께 정리한다 (계획서 WP2 §PostService — "block 통과 시에도 실행").
     */
    public function test_orphan_reply_under_trashed_chain_is_swept_on_delete(): void
    {
        $this->updateBoardSettings(['reply_delete_policy' => 'block']);

        $parentId = $this->createTestPost(['status' => 'published']);
        $trashedChildId = $this->createReply($parentId, 1, [
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);
        $orphanGrandchildId = $this->createReply($trashedChildId, 2); // 끊긴 체인 밑에 살아있는 고아

        // 직계 답글이 전부 trashed → block 정책이 삭제를 허용
        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->adminUrl("/{$parentId}"))
            ->assertStatus(200);

        $orphanRow = $this->postRow($orphanGrandchildId);
        $this->assertNotNull($orphanRow->deleted_at, '끊긴 체인 밑 고아 답글도 cascade 스윕으로 정리되어야 합니다.');
        $this->assertSame('cascade', $orphanRow->trigger_type, '고아 답글은 cascade 로 마킹되어야 합니다.');
        $this->assertSame('deleted', $orphanRow->status);

        // 사용자 선삭제 중간 노드는 불변 (trigger_type/deleted_at 유지)
        $this->assertSame('user', $this->postRow($trashedChildId)->trigger_type, '기존 trashed 중간 노드는 변조되면 안 됩니다.');
    }

    /**
     * 훅 경유 삭제(options cascade_replies=true)는 block 정책을 우회하고
     * 자손 답글까지 cascade 로 함께 정리한다 (시스템 생성 답변 정리 보장).
     */
    public function test_ecommerce_hook_delete_bypasses_block_policy(): void
    {
        $this->updateBoardSettings(['reply_delete_policy' => 'block']);

        $parentId = $this->createTestPost(['status' => 'published']);
        $replyId = $this->createReply($parentId, 1);

        $this->postService->deletePost($this->board->slug, $parentId, options: ['cascade_replies' => true]);

        $this->assertNotNull($this->postRow($parentId)->deleted_at, 'cascade_replies 옵션은 block 정책을 우회해야 합니다.');

        $replyRow = $this->postRow($replyId);
        $this->assertNotNull($replyRow->deleted_at, '자손 답글도 함께 cascade 삭제되어야 합니다.');
        $this->assertSame('cascade', $replyRow->trigger_type);
    }
}
