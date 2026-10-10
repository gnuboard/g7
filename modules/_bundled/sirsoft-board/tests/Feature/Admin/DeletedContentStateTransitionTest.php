<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 삭제된 글·댓글의 상태 전이 테스트 (블라인드·신고 처리가 삭제를 풀지 않는다)
 *
 * 검증 목적 (2026-10-07 브라우저 실측 O-K3T-2):
 * - 삭제된 글을 단건 블라인드하면 종전에는 `updateStatus` 가 deleted_at 을 지워 삭제가 풀리고
 *   blinded 로 되살아났다. 이제 409 와 「먼저 복구」 안내로 거절하고 삭제 상태를 그대로 둔다.
 * - 댓글 단건 블라인드도 같은 판정이다.
 * - 신고 처리(게시 중단·반려)가 작성자나 관리자가 이미 지운 글을 되살리지 않는다 —
 *   게시 중단은 이미 가려진 글이라 아무것도 하지 않고, 반려는 신고가 가린 것만 되돌린다.
 * - 명시적인 복구(삭제 → 게시)는 그대로 된다.
 *
 * @scenario transition=blind_deleted, target=post
 *
 * @effects admin_post_blind_rejects_deleted_post, admin_comment_blind_rejects_deleted_comment
 */
#[Group('board')]
#[Group('admin')]
#[Group('post')]
class DeletedContentStateTransitionTest extends BoardTestCase
{
    private User $admin;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'deleted-state-transition';
    }

    /**
     * 댓글과 신고를 켠 테스트 게시판 속성을 돌려줍니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '삭제 상태 전이 테스트 게시판', 'en' => 'Deleted State Transition Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'use_report' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
            'notify_admin_on_post' => false,
            'notify_author' => false,
        ];
    }

    /**
     * 게시판 관리 권한과 신고 관리 권한을 가진 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        $this->admin = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.manage",
            'sirsoft-board.reports.view',
            'sirsoft-board.reports.manage',
        ]);
    }

    /**
     * 삭제된 글을 단건 블라인드하면 409 로 거절하고 삭제 상태를 그대로 둔다 (ko 문구).
     *
     * @scenario transition=blind_deleted, target=post
     *
     * @effects admin_post_blind_rejects_deleted_post
     *
     * @return void 반환값 없음
     */
    public function test_single_blind_of_deleted_post_is_rejected_and_stays_deleted(): void
    {
        $postId = $this->createDeletedPost();

        $response = $this->actingAs($this->admin)
            ->withHeader('Accept-Language', 'ko')
            ->patchJson($this->postUrl("/{$postId}/blind"), ['reason' => '정책 위반']);

        $response->assertStatus(409);
        $response->assertJsonPath('message', '삭제된 게시글은 블라인드할 수 없습니다. 먼저 복구한 뒤 블라인드해 주세요.');

        $row = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertSame('deleted', $row->status);
        $this->assertNotNull($row->deleted_at);
    }

    /**
     * 같은 거절의 영어 문구.
     *
     * @scenario transition=blind_deleted, target=post
     *
     * @effects admin_post_blind_rejects_deleted_post
     *
     * @return void 반환값 없음
     */
    public function test_single_blind_of_deleted_post_message_in_english(): void
    {
        $postId = $this->createDeletedPost();

        $this->actingAs($this->admin)
            ->withHeader('Accept-Language', 'en')
            ->patchJson($this->postUrl("/{$postId}/blind"), [])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A deleted post cannot be blinded. Restore it first, then blind it.');
    }

    /**
     * 삭제된 댓글을 단건 블라인드하면 409 로 거절하고 삭제 상태를 그대로 둔다.
     *
     * @scenario transition=blind_deleted, target=comment
     *
     * @effects admin_comment_blind_rejects_deleted_comment
     *
     * @return void 반환값 없음
     */
    public function test_single_blind_of_deleted_comment_is_rejected_and_stays_deleted(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeader('Accept-Language', 'ko')
            ->patchJson($this->postUrl("/{$postId}/comments/{$commentId}/blind"), ['reason' => '정책 위반']);

        $response->assertStatus(409);
        $response->assertJsonPath('message', '삭제된 댓글은 블라인드할 수 없습니다. 먼저 복구한 뒤 블라인드해 주세요.');

        $row = DB::table('board_comments')->where('id', $commentId)->first();
        $this->assertSame('deleted', $row->status);
        $this->assertNotNull($row->deleted_at);
    }

    /**
     * 원글이 삭제된 채로 댓글만 단건 복구하면 409 로 거절하고, 원글을 먼저 복구하면 그 댓글도 복구된다.
     *
     * 2026-10-07 실측(O-KF3-2): 원글 삭제로 함께 지워진 댓글을 단건 복구하면 지워진 글 아래 댓글만
     * published 로 살아났다(사용자 화면에는 글이 없어 보이지 않는 고아 댓글).
     *
     * @scenario transition=restore_deleted, target=comment
     *
     * @effects admin_comment_restore_under_deleted_post_rejected
     *
     * @return void 반환값 없음
     */
    public function test_comment_restore_under_deleted_post_is_rejected_until_post_restored(): void
    {
        $postId = $this->createTestPost(['status' => 'deleted', 'deleted_at' => now()]);
        $commentId = $this->createTestComment($postId, [
            'status' => 'deleted',
            'trigger_type' => 'cascade',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeader('Accept-Language', 'ko')
            ->patchJson($this->postUrl("/{$postId}/comments/{$commentId}/restore"), []);
        $response->assertStatus(409);
        $response->assertJsonPath('message', '원글이 삭제된 상태라 댓글만 복구할 수 없습니다. 원글을 먼저 복구해 주세요.');
        $this->assertSame('deleted', DB::table('board_comments')->where('id', $commentId)->value('status'));

        $this->actingAs($this->admin)
            ->withHeader('Accept-Language', 'en')
            ->patchJson($this->postUrl("/{$postId}/comments/{$commentId}/restore"), [])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The post this comment belongs to is deleted. Restore the post first.');

        // 원글 삭제로 함께 지워진 댓글은 상태가 게시인 채 deleted_at 만 찍힌다(연쇄 삭제) — 같은 거절이어야 한다
        $cascadedId = $this->createTestComment($postId, [
            'status' => 'published',
            'trigger_type' => 'cascade',
            'deleted_at' => now(),
        ]);
        $this->actingAs($this->admin)
            ->patchJson($this->postUrl("/{$postId}/comments/{$cascadedId}/restore"), [])
            ->assertStatus(409);
        $this->assertNotNull(DB::table('board_comments')->where('id', $cascadedId)->value('deleted_at'));

        DB::table('board_posts')->where('id', $postId)->update(['status' => 'published', 'deleted_at' => null]);
        $this->actingAs($this->admin)
            ->patchJson($this->postUrl("/{$postId}/comments/{$commentId}/restore"), [])
            ->assertOk();
        $this->assertSame('published', DB::table('board_comments')->where('id', $commentId)->value('status'));
        $this->assertNull(DB::table('board_comments')->where('id', $commentId)->value('deleted_at'));
    }

    /**
     * 신고를 「게시 중단」으로 바꿔도 작성자가 이미 지운 글은 되살아나지 않는다.
     *
     * @scenario transition=report_suspend, target=post
     *
     * @effects report_suspend_keeps_deleted_post_deleted
     *
     * @return void 반환값 없음
     */
    public function test_report_suspend_keeps_already_deleted_post_deleted(): void
    {
        $postId = $this->createDeletedPost();
        $report = $this->createReport($postId, ReportStatus::Pending);

        $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'suspended',
                'process_note' => '게시 중단',
            ])
            ->assertStatus(200);

        $row = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertSame('deleted', $row->status);
        $this->assertNotNull($row->deleted_at);
    }

    /**
     * 게시 중단했던 신고를 반려해도, 그 사이 지워진 글은 되살아나지 않는다.
     *
     * 반려가 되돌리는 것은 신고가 가린 것(블라인드)뿐이다 — 다른 경로(작성자·관리자 삭제)로
     * 지워진 글을 게시 상태로 되살리면 삭제를 우회하게 된다.
     *
     * @scenario transition=report_reject, target=post
     *
     * @effects report_reject_keeps_deleted_post_deleted
     *
     * @return void 반환값 없음
     */
    public function test_report_rejection_does_not_resurrect_post_deleted_elsewhere(): void
    {
        $postId = $this->createDeletedPost();
        $report = $this->createReport($postId, ReportStatus::Suspended);

        $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-board/admin/reports/{$report->id}/status", [
                'status' => 'rejected',
                'process_note' => '반려',
            ])
            ->assertStatus(200);

        $row = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertSame('deleted', $row->status);
        $this->assertNotNull($row->deleted_at);
    }

    /**
     * 명시적인 복구(삭제 → 게시)는 그대로 된다 — 막는 것은 블라인드·반려가 삭제를 푸는 우회뿐이다.
     *
     * @scenario transition=restore_deleted, target=post
     *
     * @effects admin_post_restore_of_deleted_still_publishes
     *
     * @return void 반환값 없음
     */
    public function test_explicit_restore_of_deleted_post_still_publishes(): void
    {
        $postId = $this->createDeletedPost();

        $this->actingAs($this->admin)
            ->patchJson($this->postUrl("/{$postId}/restore"), [])
            ->assertStatus(200);

        $row = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertSame('published', $row->status);
        $this->assertNull($row->deleted_at);
    }

    /**
     * 작성자가 지운 상태의 게시글을 만듭니다.
     *
     * @return int 게시글 ID
     */
    private function createDeletedPost(): int
    {
        return $this->createTestPost([
            'title' => '작성자가 지운 글',
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => now(),
        ]);
    }

    /**
     * 게시글 대상 신고 케이스를 만듭니다.
     *
     * @param  int  $postId  신고 대상 게시글 ID
     * @param  ReportStatus  $status  신고 상태
     * @return Report 생성된 신고
     */
    private function createReport(int $postId, ReportStatus $status): Report
    {
        return Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => $status,
        ]);
    }

    /**
     * 관리자 게시글 API 주소를 만듭니다.
     *
     * @param  string  $suffix  게시글 경로 뒤에 붙일 부분
     * @return string 요청 주소
     */
    private function postUrl(string $suffix = ''): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts{$suffix}";
    }
}
