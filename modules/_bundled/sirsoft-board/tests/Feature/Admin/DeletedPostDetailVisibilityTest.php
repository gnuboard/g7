<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 삭제된 게시글 상세에서 cascade 하위 데이터 노출 검증 (#413-69-②)
 *
 * 그룹2(연쇄 soft delete)는 DB 마킹까지만 검증했고, 조회/표시 계층에는
 * 다음 두 회귀가 있었다:
 *   증상1 — 삭제된 게시글 상세에서 cascade 첨부가 0건으로 보임
 *           (findWithCounts 의 attachments eager load 에 withTrashed 누락 →
 *            댓글은 권한 기반 withTrashed 로 보이는데 첨부만 비대칭으로 안 보임)
 *   증상2 — cascade 로 함께 숨겨진 댓글이 user 선삭제 댓글과 동일하게
 *           "삭제된 댓글입니다" 로 마스킹됨 (cascade/user 구분 신호 부재)
 *
 * 정책(확정):
 *   - 첨부: 관리 권한자에게는 댓글과 동일하게 cascade 삭제분도 노출
 *   - cascade 댓글: 관리 권한자에게는 마스킹 없이 원문 노출 (is_cascade_deleted 신호 제공)
 *   - user 선삭제분: 기존대로 마스킹 유지
 *
 * @group board
 * @group admin
 */
class DeletedPostDetailVisibilityTest extends BoardTestCase
{
    private PostService $postService;

    private User $manager;

    private User $userManager;

    protected function getTestBoardSlug(): string
    {
        return 'deleted-post-detail-visibility';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '삭제글 상세 노출 테스트', 'en' => 'Deleted Post Detail Visibility'],
            'is_active' => true,
            'use_comment' => true,
            'use_file_upload' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->postService = app(PostService::class);

        // 상세 조회 스코프(admin.posts.read) + 삭제/원문 열람 권한(admin.manage)
        $this->manager = $this->createAdminUser([
            "sirsoft-board.{$this->board->slug}.admin.posts.read",
            "sirsoft-board.{$this->board->slug}.admin.manage",
        ]);

        // 유저(프론트) 화면용 manager 권한자 — 삭제된 글 접근 + 하위 데이터 열람 가능
        $this->grantUserRolePermissions(['posts.read', 'comments.read', 'attachments.download', 'manager']);
        $this->userManager = User::factory()->create();
        $this->userManager->roles()->attach(Role::where('identifier', 'user')->first()->id);
    }

    /**
     * 유저(프론트) 화면 상세 조회 API 경로.
     */
    private function userShowUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}";
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
            'hash' => 'v'.str_pad((string) $seq, 11, '0', STR_PAD_LEFT),
            'original_filename' => 'visible-'.$seq.'.png',
            'stored_filename' => 'stored-v-'.$seq.'.png',
            'disk' => 'local',
            'path' => 'attachments/visible-'.$seq.'.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'collection' => 'default',
            'order' => $seq,
            'trigger_type' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_attachments')->insertGetId(array_merge($defaults, $attributes));
    }

    /**
     * 관리자 상세 조회 API 경로.
     */
    private function showUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}";
    }

    /**
     * 증상1: 관리 권한자가 삭제된 게시글 상세를 조회하면 cascade 첨부가 응답에 포함된다.
     *
     * @effects cascade_attachment_visible_to_manager
     */
    public function test_manager_sees_only_cascaded_attachments_on_deleted_post(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);
        $liveAttachmentId = $this->createTestAttachment($postId);
        $this->createTestAttachment($postId);

        // 사용자가 글 삭제 전에 직접 지운 첨부 (cascade 와 구분되어야 함)
        $userDeletedAttachmentId = $this->createTestAttachment($postId, [
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);

        // 게시글 삭제 → 살아있던 첨부만 cascade soft delete
        $this->postService->deletePost($this->board->slug, $postId, 'admin');

        $attachment = DB::table('board_attachments')->where('id', $liveAttachmentId)->first();
        $this->assertNotNull($attachment->deleted_at, '사전조건: 첨부가 cascade soft delete 되어야 합니다.');

        $response = $this->actingAs($this->manager)->getJson($this->showUrl($postId));

        $response->assertOk();
        $attachments = $response->json('data.attachments');
        $this->assertIsArray($attachments, '관리 권한자에게 attachments 가 배열로 노출되어야 합니다.');
        $this->assertCount(2, $attachments, '삭제된 게시글에서 cascade 첨부 2건만 보여야 합니다 (user 선삭제분 제외).');

        $ids = array_column($attachments, 'id');
        $this->assertNotContains($userDeletedAttachmentId, $ids, '사용자가 직접 지운 첨부는 노출되지 않아야 합니다.');
    }

    /**
     * 증상2: cascade 로 함께 삭제된 댓글은 관리 권한자에게 원문이 노출되고 is_cascade_deleted=true 로 표시된다.
     *
     * @scenario comment_trigger=cascade
     *
     * @effects cascade_comment_content_visible,cascade_comment_flag_exposed
     */
    public function test_manager_sees_cascaded_comment_content_with_flag(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);
        $liveCommentId = $this->createTestComment($postId, [
            'content' => 'cascade 로 함께 숨겨질 살아있던 댓글',
        ]);

        $this->postService->deletePost($this->board->slug, $postId, 'admin');

        $comment = DB::table('board_comments')->where('id', $liveCommentId)->first();
        $this->assertSame('cascade', $comment->trigger_type, '사전조건: 댓글이 cascade 마킹되어야 합니다.');

        $response = $this->actingAs($this->manager)->getJson($this->showUrl($postId));
        $response->assertOk();

        $comments = collect($response->json('data.comments'));
        $cascaded = $comments->firstWhere('id', $liveCommentId);

        $this->assertNotNull($cascaded, 'cascade 댓글이 응답에 포함되어야 합니다.');
        $this->assertSame('cascade 로 함께 숨겨질 살아있던 댓글', $cascaded['content'], 'cascade 댓글은 마스킹 없이 원문이 노출되어야 합니다.');
        $this->assertTrue($cascaded['is_cascade_deleted'], 'cascade 댓글은 is_cascade_deleted=true 로 표시되어야 합니다.');
    }

    /**
     * 대조: 사용자가 직접 지운 댓글(user)은 is_cascade_deleted=false 이며 마스킹된다.
     *
     * @scenario comment_trigger=user
     *
     * @effects user_deleted_comment_not_flagged_cascade
     */
    public function test_user_deleted_comment_is_not_flagged_as_cascade(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);
        $userDeletedCommentId = $this->createTestComment($postId, [
            'content' => '사용자가 직접 지운 댓글',
            'trigger_type' => 'user',
            'status' => 'deleted',
            'deleted_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->manager)->getJson($this->showUrl($postId));
        $response->assertOk();

        $comments = collect($response->json('data.comments'));
        $userDeleted = $comments->firstWhere('id', $userDeletedCommentId);

        $this->assertNotNull($userDeleted, 'user 선삭제 댓글도 관리 권한자 응답에는 포함되어야 합니다.');
        $this->assertFalse($userDeleted['is_cascade_deleted'], 'user 선삭제 댓글은 is_cascade_deleted=false 여야 합니다.');
    }

    /**
     * 유저(프론트) 화면: manager 권한자가 삭제된 글을 열면 cascade 댓글 원문 + cascade 첨부가 노출되고,
     * 사용자 직접 삭제분은 제외된다 (del_cmt 토글 없이도 cascade 는 항상 노출).
     *
     * @scenario comment_trigger=cascade
     *
     * @effects cascade_comment_content_visible,cascade_comment_flag_exposed,cascade_attachment_visible_to_manager
     */
    public function test_user_manager_sees_cascaded_comment_and_attachment_on_deleted_post(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);
        $liveCommentId = $this->createTestComment($postId, [
            'content' => '유저 화면에서 보여야 할 cascade 댓글',
        ]);
        $this->createTestAttachment($postId);
        $this->createTestAttachment($postId);
        $userDeletedAttachmentId = $this->createTestAttachment($postId, [
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);

        $this->postService->deletePost($this->board->slug, $postId, 'admin');

        // 유저 엔드포인트, del_cmt 토글 없이 조회
        $response = $this->actingAs($this->userManager)->getJson($this->userShowUrl($postId));
        $response->assertOk();

        // cascade 댓글 원문 노출 + 플래그
        $comments = collect($response->json('data.comments'));
        $cascaded = $comments->firstWhere('id', $liveCommentId);
        $this->assertNotNull($cascaded, '유저 manager 에게 cascade 댓글이 노출되어야 합니다.');
        $this->assertSame('유저 화면에서 보여야 할 cascade 댓글', $cascaded['content'], 'cascade 댓글은 마스킹 없이 원문이 노출되어야 합니다.');
        $this->assertTrue($cascaded['is_cascade_deleted'], 'cascade 댓글 플래그가 노출되어야 합니다.');

        // cascade 첨부만 노출 (user 선삭제분 제외)
        $attachments = $response->json('data.attachments');
        $this->assertCount(2, $attachments, '유저 manager 에게 cascade 첨부 2건만 보여야 합니다.');
        $ids = array_column($attachments, 'id');
        $this->assertNotContains($userDeletedAttachmentId, $ids, '사용자가 직접 지운 첨부는 유저 화면에도 노출되지 않아야 합니다.');
    }
}
