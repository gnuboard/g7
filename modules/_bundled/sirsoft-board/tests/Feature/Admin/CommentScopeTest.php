<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 관리자 댓글 API 상위 게시글 스코프 검증 테스트
 *
 * 검증 목적:
 * - update / destroy / blind / restore 4종 모두 교차 게시글 URL 로 접근할 수 없다
 * - 정상 경로로는 기존과 동일하게 동작한다
 *
 * @group board
 * @group admin
 * @group comment
 * @group scope
 */
class CommentScopeTest extends BoardTestCase
{
    private User $adminWithManage;

    protected function getTestBoardSlug(): string
    {
        return 'admin-comment-scope';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '관리자 댓글 스코프 게시판', 'en' => 'Admin Comment Scope Board'],
            'is_active' => true,
            'use_comment' => true,
            'max_comment_depth' => 10,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        $this->adminWithManage = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.manage",
            "sirsoft-board.{$slug}.admin.comments.write",
        ]);
    }

    /**
     * 교차 게시글 URL 로 댓글 수정 → 404
     *
     * @scenario resource=board_comment_admin, parent_scope=mismatched, actor=admin
     *
     * @effects cross_scope_write_rejected, target_resource_unchanged_on_rejection
     */
    public function test_cannot_update_comment_through_other_post_url(): void
    {
        [$postA, $postB] = $this->createTwoPosts();
        $commentId = $this->createTestComment($postA, ['content' => '원래 내용']);

        $this->actingAs($this->adminWithManage)
            ->putJson($this->url($postB, $commentId), ['content' => '교차 수정 시도'])
            ->assertStatus(404);

        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'content' => '원래 내용',
        ]);
    }

    /**
     * 교차 게시글 URL 로 댓글 삭제 → 404
     *
     * @scenario resource=board_comment_admin, parent_scope=mismatched, actor=admin
     *
     * @effects cross_scope_write_rejected, target_resource_unchanged_on_rejection
     */
    public function test_cannot_delete_comment_through_other_post_url(): void
    {
        [$postA, $postB] = $this->createTwoPosts();
        $commentId = $this->createTestComment($postA);

        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url($postB, $commentId))
            ->assertStatus(404);

        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'deleted_at' => null,
        ]);
    }

    /**
     * 교차 게시글 URL 로 댓글 블라인드 → 404
     *
     * @scenario resource=board_comment_admin, parent_scope=mismatched, actor=admin
     *
     * @effects cross_scope_write_rejected, target_resource_unchanged_on_rejection
     */
    public function test_cannot_blind_comment_through_other_post_url(): void
    {
        [$postA, $postB] = $this->createTwoPosts();
        $commentId = $this->createTestComment($postA, ['status' => 'published']);

        $this->actingAs($this->adminWithManage)
            ->patchJson($this->url($postB, $commentId).'/blind', ['reason' => '교차 블라인드 시도'])
            ->assertStatus(404);

        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'status' => 'published',
        ]);
    }

    /**
     * 교차 게시글 URL 로 댓글 복원 → 404
     *
     * @scenario resource=board_comment_admin, parent_scope=mismatched, actor=admin
     *
     * @effects cross_scope_write_rejected, target_resource_unchanged_on_rejection
     */
    public function test_cannot_restore_comment_through_other_post_url(): void
    {
        [$postA, $postB] = $this->createTwoPosts();
        $commentId = $this->createTestComment($postA, ['status' => 'blinded']);

        $this->actingAs($this->adminWithManage)
            ->patchJson($this->url($postB, $commentId).'/restore', [])
            ->assertStatus(404);

        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'status' => 'blinded',
        ]);
    }

    /**
     * 정상 경로로는 4종 모두 동작한다 (회귀 방지)
     *
     * @scenario resource=board_comment_admin, parent_scope=matching, actor=admin
     *
     * @effects matching_scope_still_succeeds
     */
    public function test_own_post_url_still_works_for_all_operations(): void
    {
        $postId = $this->createTestPost();

        $updateTarget = $this->createTestComment($postId, ['content' => '원래 내용']);
        $this->actingAs($this->adminWithManage)
            ->putJson($this->url($postId, $updateTarget), ['content' => '정상 수정'])
            ->assertStatus(200);

        $blindTarget = $this->createTestComment($postId, ['status' => 'published']);
        $this->actingAs($this->adminWithManage)
            ->patchJson($this->url($postId, $blindTarget).'/blind', ['reason' => '정상 블라인드'])
            ->assertStatus(200);

        $restoreTarget = $this->createTestComment($postId, ['status' => 'blinded']);
        $this->actingAs($this->adminWithManage)
            ->patchJson($this->url($postId, $restoreTarget).'/restore', [])
            ->assertStatus(200);

        $deleteTarget = $this->createTestComment($postId);
        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url($postId, $deleteTarget))
            ->assertStatus(200);

        $this->assertDatabaseHas('board_comments', ['id' => $updateTarget, 'content' => '정상 수정']);
        $this->assertDatabaseHas('board_comments', ['id' => $blindTarget, 'status' => 'blinded']);
        $this->assertDatabaseHas('board_comments', ['id' => $restoreTarget, 'status' => 'published']);
        $this->assertSoftDeleted('board_comments', ['id' => $deleteTarget]);
    }

    /**
     * 서로 다른 두 게시글을 생성합니다.
     *
     * @return array{0: int, 1: int} [게시글 A ID, 게시글 B ID]
     */
    private function createTwoPosts(): array
    {
        return [
            $this->createTestPost(['title' => '게시글 A']),
            $this->createTestPost(['title' => '게시글 B']),
        ];
    }

    /**
     * 관리자 댓글 API URL 을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  int  $commentId  댓글 ID
     * @return string 댓글 엔드포인트 URL
     */
    private function url(int $postId, int $commentId): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/admin/board/{$slug}/posts/{$postId}/comments/{$commentId}";
    }
}
