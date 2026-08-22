<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 사용자 댓글 API 상위 게시글 스코프 검증 테스트
 *
 * 검증 목적:
 * - 게시글 A 의 댓글을 게시글 B 의 URL 로 수정/삭제할 수 없다 (교차 경로 차단)
 * - 정상 경로(자기 게시글 URL)로는 기존과 동일하게 수정/삭제된다
 *
 * @group board
 * @group comment
 * @group scope
 */
class CommentScopeTest extends BoardTestCase
{
    private User $memberUser;

    protected function getTestBoardSlug(): string
    {
        return 'comment-scope';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 스코프 테스트 게시판', 'en' => 'Comment Scope Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'max_comment_depth' => 10,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);

        $this->memberUser = User::factory()->create(['email' => 'comment-scope-member@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
        }
    }

    /**
     * 게시글 B 의 URL 로 게시글 A 의 댓글을 수정할 수 없다 → 404
     *
     * @scenario resource=board_comment_user, parent_scope=mismatched, actor=member
     *
     * @effects cross_scope_write_rejected, target_resource_unchanged_on_rejection
     */
    public function test_cannot_update_comment_through_other_post_url(): void
    {
        $postA = $this->createTestPost(['title' => '게시글 A']);
        $postB = $this->createTestPost(['title' => '게시글 B']);

        $commentId = $this->createTestComment($postA, [
            'user_id' => $this->memberUser->id,
            'content' => '원래 내용',
        ]);

        $this->actingAs($this->memberUser)
            ->putJson($this->url($postB, $commentId), ['content' => '교차 경로 수정 시도'])
            ->assertStatus(404);

        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'content' => '원래 내용',
        ]);
    }

    /**
     * 게시글 B 의 URL 로 게시글 A 의 댓글을 삭제할 수 없다 → 404
     *
     * @scenario resource=board_comment_user, parent_scope=mismatched, actor=member
     *
     * @effects cross_scope_write_rejected, target_resource_unchanged_on_rejection
     */
    public function test_cannot_delete_comment_through_other_post_url(): void
    {
        $postA = $this->createTestPost(['title' => '게시글 A']);
        $postB = $this->createTestPost(['title' => '게시글 B']);

        $commentId = $this->createTestComment($postA, [
            'user_id' => $this->memberUser->id,
        ]);

        $this->actingAs($this->memberUser)
            ->deleteJson($this->url($postB, $commentId))
            ->assertStatus(404);

        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'deleted_at' => null,
        ]);
    }

    /**
     * 정상 경로(자기 게시글 URL)로는 댓글이 수정된다 → 200
     *
     * @scenario resource=board_comment_user, parent_scope=matching, actor=member
     *
     * @effects matching_scope_still_succeeds
     */
    public function test_can_update_comment_through_own_post_url(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $this->memberUser->id,
            'content' => '원래 내용',
        ]);

        $this->actingAs($this->memberUser)
            ->putJson($this->url($postId, $commentId), ['content' => '정상 수정'])
            ->assertStatus(200);

        $this->assertDatabaseHas('board_comments', [
            'id' => $commentId,
            'content' => '정상 수정',
        ]);
    }

    /**
     * 정상 경로(자기 게시글 URL)로는 댓글이 삭제된다 → 200
     *
     * @scenario resource=board_comment_user, parent_scope=matching, actor=member
     *
     * @effects matching_scope_still_succeeds
     */
    public function test_can_delete_comment_through_own_post_url(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $this->memberUser->id,
        ]);

        $this->actingAs($this->memberUser)
            ->deleteJson($this->url($postId, $commentId))
            ->assertStatus(200);

        $this->assertSoftDeleted('board_comments', ['id' => $commentId]);
    }

    /**
     * 사용자 댓글 API URL 을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  int  $commentId  댓글 ID
     * @return string 댓글 수정/삭제 엔드포인트 URL
     */
    private function url(int $postId, int $commentId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}";
    }
}
