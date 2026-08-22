<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 대댓글 부모-자식 무결성 테스트
 *
 * 검증 목적:
 * - 다른 게시글의 댓글을 parent_id 로 지정할 수 없다 (HTTP 경로 422)
 * - Service 직접 호출 경로에서도 타 게시글 부모로 depth 가 계산되지 않는다
 *
 * @group board
 * @group comment
 * @group integrity
 */
class CommentParentIntegrityTest extends BoardTestCase
{
    private User $memberUser;

    protected function getTestBoardSlug(): string
    {
        return 'comment-parent-integrity';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 부모 무결성 게시판', 'en' => 'Comment Parent Integrity Board'],
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

        $this->memberUser = User::factory()->create(['email' => 'parent-integrity@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
        }
    }

    /**
     * 다른 게시글의 댓글을 parent_id 로 지정하면 422
     *
     * @scenario entity=board_comment, parent_target=foreign
     *
     * @effects foreign_parent_rejected_422, parent_unchanged_on_rejection
     */
    public function test_cannot_reply_to_comment_of_other_post(): void
    {
        $postA = $this->createTestPost(['title' => '게시글 A']);
        $postB = $this->createTestPost(['title' => '게시글 B']);

        $parentInPostA = $this->createTestComment($postA, ['depth' => 0]);

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postB), [
                'content' => '교차 부모 답글 시도',
                'parent_id' => $parentInPostA,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertDatabaseMissing('board_comments', [
            'post_id' => $postB,
            'parent_id' => $parentInPostA,
        ]);
    }

    /**
     * 같은 게시글의 댓글을 parent_id 로 지정하면 201 (회귀 방지)
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects unrelated_parent_move_succeeds, depth_equals_parent_depth_plus_one
     */
    public function test_can_reply_to_comment_of_same_post(): void
    {
        $postId = $this->createTestPost();
        $parentId = $this->createTestComment($postId, ['depth' => 0]);

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), [
                'content' => '정상 답글',
                'parent_id' => $parentId,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('board_comments', [
            'post_id' => $postId,
            'parent_id' => $parentId,
            'depth' => 1,
        ]);
    }

    /**
     * Service 직접 호출 시에도 타 게시글 부모로 depth 가 계산되지 않는다
     *
     * FormRequest 를 우회하는 훅/내부 호출 경로에 대한 2차 방어를 검증합니다.
     *
     * @scenario entity=board_comment, parent_target=foreign
     *
     * @effects foreign_parent_rejected_422
     */
    public function test_service_does_not_inherit_depth_from_other_post_parent(): void
    {
        $postA = $this->createTestPost(['title' => '게시글 A']);
        $postB = $this->createTestPost(['title' => '게시글 B']);

        // 게시글 A 에 depth 3 인 부모 댓글
        $parentInPostA = $this->createTestComment($postA, ['depth' => 3]);

        /** @var CommentService $service */
        $service = app(CommentService::class);

        $comment = $service->createComment($this->board->slug, [
            'post_id' => $postB,
            'parent_id' => $parentInPostA,
            'content' => 'Service 직접 호출 교차 부모',
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertSame(
            0,
            (int) $comment->depth,
            '타 게시글 부모의 depth(3)를 상속해서는 안 됩니다.'
        );
    }

    /**
     * 댓글 생성 엔드포인트 URL 을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return string 댓글 생성 URL
     */
    private function storeUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments";
    }
}
