<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 댓글 수정 mass-assignment 방어 테스트
 *
 * 검증 목적:
 * - UpdateCommentRequest 가 정의하지 않은 필드(post_id, board_id, user_id, parent_id,
 *   author_name, trigger_type, action_logs, depth, ip_address, replies_count)를 요청에
 *   동봉해도 저장되지 않는다 — Comment::$fillable 의 초과 10개 전수
 * - 정의된 필드(content, is_secret, status)는 정상 반영된다
 *
 * @group board
 * @group comment
 * @group security
 */
class CommentMassAssignmentTest extends BoardTestCase
{
    private User $memberUser;

    private User $otherUser;

    protected function getTestBoardSlug(): string
    {
        return 'comment-mass-assignment';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 대량할당 게시판', 'en' => 'Comment Mass Assignment Board'],
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

        $this->memberUser = User::factory()->create(['email' => 'mass-assign-owner@test.com']);
        $this->otherUser = User::factory()->create(['email' => 'mass-assign-other@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
            $this->otherUser->roles()->attach($userRole->id);
        }
    }

    /**
     * 검증되지 않은 필드는 저장되지 않는다
     *
     * @scenario resource=board_comment_user, parent_scope=matching, actor=member
     *
     * @effects unvalidated_fields_not_persisted, sibling_resource_state_unchanged
     */
    public function test_update_ignores_fields_outside_form_request(): void
    {
        $postA = $this->createTestPost(['title' => '게시글 A']);
        $postB = $this->createTestPost(['title' => '게시글 B']);
        $siblingComment = $this->createTestComment($postA, ['depth' => 0]);

        $commentId = $this->createTestComment($postA, [
            'user_id' => $this->memberUser->id,
            'author_name' => '원래 작성자',
            'content' => '원래 내용',
            'depth' => 0,
            'ip_address' => '127.0.0.1',
        ]);

        $before = DB::table('board_comments')->where('id', $commentId)->first();

        $this->actingAs($this->memberUser)
            ->putJson($this->url($postA, $commentId), [
                'content' => '수정된 내용',
                // 아래는 UpdateCommentRequest 에 정의되지 않은 필드 — 전부 무시되어야 함
                'post_id' => $postB,
                'board_id' => $this->board->id + 999,
                'user_id' => $this->otherUser->id,
                'parent_id' => $siblingComment,
                'author_name' => '탈취된 작성자',
                'trigger_type' => 'admin',
                'action_logs' => [['action' => 'forged']],
                'depth' => 9,
                'ip_address' => '10.0.0.1',
                'replies_count' => 100,
            ])
            ->assertStatus(200);

        $after = DB::table('board_comments')->where('id', $commentId)->first();

        $this->assertSame('수정된 내용', $after->content, '검증된 필드는 반영되어야 합니다.');

        $this->assertEquals($before->post_id, $after->post_id, 'post_id 가 변경되면 안 됩니다.');
        $this->assertEquals($before->board_id, $after->board_id, 'board_id 가 변경되면 안 됩니다.');
        $this->assertEquals($before->user_id, $after->user_id, 'user_id 가 변경되면 안 됩니다.');
        $this->assertEquals($before->parent_id, $after->parent_id, 'parent_id 가 변경되면 안 됩니다.');
        $this->assertEquals($before->author_name, $after->author_name, 'author_name 이 변경되면 안 됩니다.');
        $this->assertEquals($before->trigger_type, $after->trigger_type, 'trigger_type 이 변경되면 안 됩니다.');
        $this->assertEquals($before->action_logs, $after->action_logs, 'action_logs 가 변경되면 안 됩니다.');
        $this->assertEquals($before->depth, $after->depth, 'depth 가 변경되면 안 됩니다.');
        $this->assertEquals($before->ip_address, $after->ip_address, 'ip_address 가 변경되면 안 됩니다.');
        $this->assertEquals($before->replies_count, $after->replies_count, 'replies_count 가 변경되면 안 됩니다.');
    }

    /**
     * 사용자 댓글 API URL 을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  int  $commentId  댓글 ID
     * @return string 댓글 수정 URL
     */
    private function url(int $postId, int $commentId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}";
    }
}
